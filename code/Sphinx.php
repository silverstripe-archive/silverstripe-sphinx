<?php

/**
 * Handles managing the various sphinx binary processes - generating configuration, indexing and starting up / shutting down searchd.
 *
 * Needs shell command access to function
 *
 * @author Hamish Friedlander <hamish@silverstripe.com>
 */
class Sphinx extends Controller {

	/** Only allow access to certain actions. Also need to pass the permission check in Sphinx#init */
	static $allowed_actions = array(
		'configure',
		'reindex',
		'install',
		'start',
		'stop',
		'status',
		'diagnose'
	);
	
	/** Override where the indexes and other run-time data is stored. By default, uses subfolders of TEMP_FOLDER/sphinx (normally /tmp/silverstripe-cache-sitepath/sphinx) */
	static $var_path = null;
	static $idx_path = null;
	static $pid_file = null;

	/** Set a tcp port. By default, searchd uses a unix socket in var_path.
	 * Set a port here or as SS_SPHINX_TCP_PORT to use tcp socket listening on this
	 * port on localhost instead. On Windows, this must be set, as it doesn't
	 * support unix sockets.
	 */
	static $tcp_port = null;

	/** Backend instance to use. Autodetected if left blank. Backend determines how to start & stop searchd and trigger reindexing */
	static $backend = null;

	/** What client to use. Can override for testing */
	static $client_class = "SphinxClient";
	static $client_class_param = null;	// used to pass SapphireTest object into the controller without coupling

	/** What stop words list to use. null => default list. array('word1','word2') => those words. path as string => that file of words. false => no stopwords list */
	static $stop_words = null;

	// Default options for indexer app
	protected static $indexer_options = array(
		"mem_limit" => "256M"
	);

	/**
	 * Setter for indexer options. This is merged with (and overrides)
	 * the defaults.
     */
	static function set_indexer_options($options) {
		self::$indexer_options = array_merge(self::$indexer_options, $options);
	}

	/**
	 * Get the indexer options currently in effect
	 */
	static function get_indexer_options($options) {
		return self::$indexer_options;
	}

	/**
	 * Default settings for searchd.
	 */
	protected static $searchd_options = array(
		"max_children" => "30"
	);

	/**
	 * Used as a max_matches argument to searchd. This is set if max_matches is set
	 * in set_searchd_options, and is made available via a getter so that
	 * Search::search() can push the same value to the API.
	 * @var int   0 for no value (use sphinx defaults), non-zero for a specific
	 * 			  limit
	 */
	private static $max_matches = 0;

	/**
	 * Getter for max_matches.
	 * @static
	 * @return int
	 */
	static function get_max_matches() {
		return self::$max_matches;
	}
	
	/**
	 * Sets options for searchd, which get written to the sphinx configuration
	 * file. If the following properties are provided, they override
	 * what sphinx generates (so only set these if you really know what
	 * you're doing):
	 *  - listen
	 *  - pid
	 *  - log
	 *  - query_log
	 */
	static function set_searchd_options($options) {
		self::$searchd_options = array_merge(self::$searchd_options, $options);
		if (isset($options["max_matches"])) self::$max_matches = $options["max_matches"];
	}

	static function get_searchd_options() {
		return self::$searchd_options;
	}

	/** Generate configuration from either static variables or default values, and preps $this to contain configuration values, to be used in templates */
	function __construct() {

		$runningTests = class_exists('SapphireTest', false) && SapphireTest::is_running_test();

		/* -- Set up backend if not yet set -- */

		if (!self::$backend) {
			if ($runningTests) self::$backend = new Sphinx_NullBackend();
			else if (strpos(PHP_OS, "WIN") !== false) self::$backend = new Sphinx_WindowsServiceBackend();
			else self::$backend = new Sphinx_UnixishBackend();
		}

		self::$backend->setSphinxInstance($this);

		/* -- Set up client if not yet set -- */

		if (!self::$client_class) {
			if ($runningTests) self::$client_class = "SphinxClientFaker";
			else self::$client_class = "SphinxClient";
		}

		/* -- Set up database connection info -- */

		global $databaseConfig;

		$this->Database = new ArrayData($databaseConfig);
		$this->SupportedDatabase = true;

		switch ($this->Database->type) {
			case "MySQLDatabase":
				$this->Database->type = "mysql";
				break;
			case "PostgreSQLDatabase":
				$this->Database->type = "pgsql";
				break;
			case "MSSQLDatabase":
				$this->Database->type = "mssql";
				break;
			default:
				// Other databases are not supported by sphinx itself, so this
				// will prevent generation of database connection settings to
				// sphinx.conf. However, we don't throw an error, because
				// xmlpipes can still be used in this mode.
				$this->SupportedDatabase = false;
		}

		// If there is a custom port, its on the end of Database->server, so take it off and stick it in the port property (which is not in $databaseConfig)
		if (strpos($this->Database->server, ":") !== FALSE) {
			$a = explode(":", $this->Database->server);
			$this->Database->server = $a[0];
			$this->Database->port = $a[1];
		}

		// @todo This is specific to MySQL. Default port should come from the DB layer.
		if (!is_numeric($this->Database->port)) $this->Database->port = 3306;

		// If server is localhost, sphinx tries connecting using a socket instead. Lets avoid that
		if ($this->Database->server == 'localhost') $this->Database->server = '127.0.0.1';

		/* -- Set up path & searchd connection info -- */

		$this->CNFPath = Director::baseFolder() . '/sphinx/conf';

		$this->VARPath = self::$var_path ? self::$var_path : TEMP_FOLDER . '/sphinx';
		$this->IDXPath = self::$idx_path ? self::$idx_path : $this->VARPath . '/idxs';
		$this->PIDFile = self::$pid_file ? self::$pid_file : $this->VARPath . '/searchd.pid';

		$port = defined('SS_SPHINX_TCP_PORT') ? SS_SPHINX_TCP_PORT : self::$tcp_port;
		$this->Listen = $port ? "127.0.0.1:$port" : "{$this->VARPath}/searchd.sock";

		/* -- Random stuff -- */

		// An array that maps class names to arrays of Sphinx_Index objects.
		$this->indexes = array();

		parent::__construct();
	}

	function StopWords() {
		if (self::$stop_words === null) return "{$this->CNFPath}/stopwords.txt";

		if (is_array(self::$stop_words)) {
			$words = implode(' ', self::$stop_words);
			self::$stop_words = "$this->VARPath/stopwords.".sha1($words).'.txt';

			if (!file_exists(self::$stop_words)) file_put_contents(self::$stop_words, $words);
		}

		return self::$stop_words ? Director::getAbsFile(self::$stop_words) : false;
	}

	/** Make sure that only administrators or CLI access are allowed to perform actions on object when used as a controller */
	function init() {
		parent::init();
		// Same rules as in DatabaseAdmin.php
		$canAccess = Director::isDev() || !Security::database_is_ready() || Director::is_cli() || Permission::check("ADMIN");
		if(!$canAccess) return Security::permissionFailure($this, "This page is secured and you need administrator rights to access it");
	}

	/**
	 * Return the list of indexes to built for a set of classes. If classes are not provided, uses the list
	 * of all decorated classes.
	 *
	 * Construction of indexes is two pass. First pass builds a list that maps a list of classes to a list of indices. The second
	 * pass uses this to construct the actual Sphinx_Index objects (which needs all classes in the index, hence first pass.)
	 *
	 * First pass works by building an array that maps a signature to an index descriptor. The signature is made up of the fields
	 * that are searchable and fields that are filterable for the class, in a canonical form for comparison. The structure is built
	 * by iterating over classes, looking for an item in the array with exactly matching descriptor. If one is found, the class
	 * being indexed CI must be a descendant of the base class in the descriptor CB, or vice versa. If this is true, the descriptor
	 * will hold the higher level of the two (making the order in which the classes are processed irrelevant). If there is
	 * not a direct ancestry, then a new descriptor is created.
	 *
	 * The second pass is just iterating over the descriptors, and creating the Sphinx_Index objects from it.
	 *
	 * @param $classes
	 * @return unknown_type
	 */
	function indexes($classes=null) {
		if (!$classes) $classes = SphinxSearchable::decorated_classes();
		if (!is_array($classes)) $classes = array($classes);

		$index_desc = array();

		// Work out what indexes we need to construct. We can't build the Sphinx_Index objects on first
		// pass because it needs the complete list of classes in the index, which we don't initially have,
		// which is what we're building here.
		foreach ($classes as $class) {
			// Skip classes for which no database tables are linked.
			// This can occur in test scenarios, especially with TestOnly
			// classes.
			if (!ClassInfo::dataClassesFor($class)) {
				continue;
			}

			$sig = $this->getSearchSignature($class);

			// Determine the configuration to use. If this class doesn't have a sphinx conf, its
			// ancestors may.
			$conf = singleton($class)->stat('sphinx');
			if (!$conf) {
				$ancestors = ClassInfo::ancestry($class, true);
				array_pop($ancestors); // drop off this class
				foreach (array_reverse($ancestors) as $c) {
					$conf = singleton($c)->stat('sphinx');
					if ($conf) break;
				}
			}

			$mode = ($conf && isset($conf['mode'])) ? $conf['mode'] : '';
			if ($mode == '') $mode = "sql";

			if (isset($index_desc[$sig])) {
				$base = $index_desc[$sig]["baseClass"];
				if (is_subclass_of($base, $class)) { // we have a better base class
					$index_desc[$sig]["baseClass"] = $class;
					$index_desc[$sig]["classes"][] = $class;
					$index_desc[$sig]["mode"] = $mode;
				}
				else if (is_subclass_of($class, $base)) $index_desc[$sig]["classes"][] = $class;
				else $index_desc[$sig] = array("classes" => array($class), "baseClass" => $class, "mode" => $mode);
			}
			else $index_desc[$sig] = array("classes" => array($class), "baseClass" => $class, "mode" => $mode);
		}

		$result = array();
		foreach ($index_desc as $sig => $desc) {
			$baseClass = $desc['baseClass'];
			if (!isset($this->indexes[$baseClass])) {
				$indexes = array(new Sphinx_Index($desc['classes'], $baseClass, $desc['mode']));
				SphinxVariants::alterIndexes($baseClass, $indexes);

				$this->indexes[$baseClass] = $indexes;
			}

			$result = array_merge($result, $this->indexes[$baseClass]);
		}
		return $result;
	}

	// Return a string signature for a class. This consists of the following info, which must all be present:
	// - the most base ancestor of the class that has the sphinx decorator
	// - all the fields, in alphabetic order, with filter and sort properties added.
	// If any two classes have identical signatures, they should be able to be combined.
	protected function getSearchSignature($class) {
		$sing = new $class();
		$fields = $sing->sphinxFields($class);

		// Determine the base decorated class
		$ancestors = ClassInfo::ancestry($class, true);
		$base = "";
		foreach ($ancestors as $c) {
			if (singleton($c)->hasExtension('SphinxSearchable')) {
				$base = $c;
				break;
			}

		}

		$result = ":$base:";

		ksort($fields);

		foreach ($fields as $name => $def) {
			list($class, $type, $filter, $sort, $isString) = $def;
			$result .= $name . "_" . $filter . $sort . ":";
		}

		return $result;
	}

	/**
	 * Check to make sure there aren't any CRC clashes.
	 * @todo - Run on all fields that are CRCEnumerable, not just class names
	 */
	function check() {
		$seenClassIDs = array();

		foreach (SphinxSearchable::decorated_classes() as $class) {
			$base = ClassInfo::baseDataClass($class);
			$classid = SphinxSearch::unsignedcrc($base);

			if (isset($seenClassIDs[$classid]) && $seenClassIDs[$classid] != $base) user_error("CRC32 clash on ClassName. SphinxSearch won't be reliable unless you rename either {$base} or {$seenClassIDs[$classid]}");
			$seenClassIDs[$classid] = $base;
		}
	}

	/**
	 * Build the sphinx configuration file. This consists of one (or more) sources, each bound to an index. We use a one to one (or more) ClassName to Index mapping, then search
	 * over multiple indexes to search children classes.
	 *
	 * Side-effects: Will stop searchd if currently running
	 */
	function configure() {
		$this->stop();

		if (!file_exists($this->VARPath)) mkdir($this->VARPath, 0770);
		if (!file_exists($this->IDXPath)) mkdir($this->IDXPath, 0770);

		file_put_contents("{$this->VARPath}/sphinx.conf", $this->generateConfiguration());

		self::$backend->configure();
	}

	function generateConfiguration() {
		SSViewer::set_source_file_comments(false);
		$res = array();

		// base source
		$res[] = $this->renderWith(Director::baseFolder() . '/sphinx/conf/source.ss');

		// base index
		$this->addBaseIndexConfig(&$res);

		foreach ($this->indexes() as $index) $res[] = $index->config();

		// Add the indexer options
		$res[] = "indexer {";
		foreach (self::$indexer_options as $key => $value) $res[] = "\t$key = $value";
		$res[] = "}\n";

		// Add the searchd options. We need to set some dynamic properties
		// if not overridden.
		$res[] = "searchd {";
		if (!isset(self::$searchd_options["listen"]))
			self::$searchd_options["listen"] = $this->Listen;
		if (!isset(self::$searchd_options["pid_file"]))
			self::$searchd_options["pid_file"] = $this->PIDFile;
		if (!isset(self::$searchd_options["log"]))
			self::$searchd_options["log"] = $this->VARPath . "/searchd.log";
		if (!isset(self::$searchd_options["query_log"]))
			self::$searchd_options["query_log"] = $this->VARPath . "/query.log";
		foreach (self::$searchd_options as $key => $value) $res[] = "\t$key = $value";
		$res[] = "}";

		return implode("\n", $res);
	}

	/**
	 * Used by template for config to determine if the database credentials
	 * should be written. Returns true if the database is supported natively by
	 * sphinx, and at least one of the sources uses SQL-mode.
	 */
	function DatabaseConfigRequired() {
		if (!$this->SupportedDatabase) return false;
		foreach ($this->indexes() as $index)
			if ($index->requiredDirectDBConnection()) return true;
		return false;
	}

	/**
	 * Add lines the configuration array $res for the contents of the
	 * base index. This comes from defaults for the base index, with overrides.
	 * This is rendered by template, which contains the default charset_table
	 * if none is set. All other properties are rendered using the template
	 * engine, which gets the props out of the base_index_options static.
	 * $this->CharsetTable and $this->BaseIndexOptions are set here for
	 * rendering use only.
	 */
	protected function addBaseIndexConfig(&$res) {
		$this->CharsetTable = null;
		$this->BaseIndexOptions = new DataObjectSet();
		$ar = array();
		foreach (self::$base_index_options as $key => $value) {
			if ($key == "charset_table") $this->CharsetTable = $value;
			else if ($key == "stopwords") $this->StopWords = $value;
			else $this->BaseIndexOptions->push(new ArrayData(array("Key" => $key, "Value" => $value)));
		}

		$res[] = $this->renderWith(Director::baseFolder() . '/sphinx/conf/index.ss');
	}

	/**
	 * Default settings for the base index. charset_type,
	 * charset_table and stopwords are handled separately.
	 */
	protected static $base_index_options = array(
		"morphology" => "stem_en",
		"phrase_boundary" => "., ?, !, U+2026 # horizontal ellipsis",
		"html_strip" => 1,
		"html_index_attrs" => "img=alt,title; a=title;",
		"inplace_enable" => 1,
		"index_exact_words" => 1,
		"charset_type" => "utf-8"
	);

	static function set_base_index_options($options) {
		self::$base_index_options = array_merge(self::$base_index_options, $options);
	}

	static function get_base_index_options() {
		return self::$base_index_options;
	}

	/**
	 * Re-build sphinx's indexes
	 * @param $idxs array - A list of indexes to rebuild,
	 *		or null to rebuild all indexes. Array items can be either index
	 *		names as strings, or Sphinx_Index objects.
	 * @todo Implement a verbose option for debugging that dumps all output irrespective. Detect http/command line, and formats appropriately.
	 */
	function reindex($idxs=null) {
		$originalMaxExecution = ini_get('max_execution_time');
		ini_set('max_execution_time', '0');

		// If we're being called as a controller, or we're called with no indexes specified, rebuild all indexes
		if ($idxs instanceof SS_HTTPRequest || $idxs instanceof HTTPRequest || $idxs === null) $idxs = $this->indexes();
		elseif (!is_array($idxs)) $idxs = array($idxs);

		$verbose = isset($_GET["verbose"]) && $_GET["verbose"] == 1;

		// If we were passed an array of Sphinx_Index's, get the list of just the names
		foreach ($idxs as $idx) {
			if ($idx instanceof Sphinx_Index) $idxs = array_map(create_function('$idx', 'return $idx->Name;'), $idxs);
			break;
		}

		$indexingOutput = self::$backend->updateIndexes($idxs, $verbose);

		if ($this->detectIndexingError($indexingOutput)) {
			if($this->response) $this->response->addHeader("Content-type", "text/plain");

			ini_set('max_execution_time', $originalMaxExecution);
			return "ERROR\n\n$indexingOutput";
		}
		else
		{
			self::$backend->updateDictionary($idxs);

			if($this->response) $this->response->addHeader("Content-type", "text/plain");

			ini_set('max_execution_time', $originalMaxExecution);
			return ($verbose ? $indexingOutput . "\n" : "") . "OK";
		}
	}

	/**
	 * Examine $s for signs of indexing error. This is abstracted to a function so it can be unit tested.
	 * Detect indexing errors. An error is deemed to occur if any of the following occur in the indexing output:
	 *   ERROR:
	 *   FATAL:
	 *   WARNING:
	 *   Segmentation fault
	 * but this is ignored as it usually occurs and is innocuous:
	 *   ERROR: index 'BaseIdx': key 'path' not found.
	 *
	 * @return Boolean True if this has errors, false if it's OK.
	 */
	function detectIndexingError($s) {
		$lines = explode("\n", $s);
		$hasError = false;
		foreach ($lines as $line) {
			$hasError |= (preg_match("/^ERROR\:.*$/", $line) > 0 && $line != "ERROR: index 'BaseIdx': key 'path' not found.");
			$hasError |= (preg_match("/^FATAL\:/", $line) > 0);
			$hasError |= (preg_match("/^WARNING:/", $line) > 0);
			$hasError |= (preg_match("/Permission denied/", $line) > 0);
			$hasError |= (preg_match("/Segmentation fault/", $line) > 0);
		}

		return $hasError ? TRUE : FALSE;
	}

	function install() {
		if (!Director::is_cli()) {
			echo 'Must be run from command line, as root or administrator or equivilent';
			die;
		}
		
		self::$backend->install();
	}

	/**
	 * Check the status of searchd.
	 * @return string - One of:
	 *		- 'Running'
	 *		- 'Stopped( - .*)?'
	 */
	function status() {
		return self::$backend->status();
	}

	/**
	 * Start searchd. NOP if already running.
	 */
	function start() {
		return self::$backend->start();
	}

	/**
	 * Stop searchd. NOP if already stopped.
	 */
	function stop() {
		return self::$backend->stop();
	}

	/**
	 * By default, the constructor will determine whether to use SphinxClient or the fake client if running
	 * unit tests. This method allows the sphinx unit tests to override that if required, and to pass a
	 * test object through as well. Otherwise, this should generally not be called.
	 * @param  $class
	 * @param  $param
	 * @return void
	 */
	function setClientClass($class, $param = null) {
		self::$client_class = $class;
		self::$client_class_param = $param;
	}

	/**
	 * Returns a SphinxClient API connection. Starts server if not running.
	 */
	function connection() {
		$this->start();

		require_once(Director::baseFolder() . '/sphinx/thirdparty/sphinxapi.php');

		$connClass = self::$client_class;
		$co = self::$client_class_param ? new $connClass(self::$client_class_param) : new $connClass;

		$co->setServer($this->Listen);
		return $co;
	}

	/**
	 * Trim indexes of 0 bytes if we're over the 100 limit
	 */
	function trimIndexes(&$indexes) {
		foreach ($indexes as $i => $indexName) {
			if (file_exists($this->IDXPath.'/'.$indexName.'.sph') && filesize($this->IDXPath.'/'.$indexName.'.sph') === 0) {
				unset($indexes[$i]);
			}
		}
	}

	/**
	 * Returns a PureSpell instance with the extracted wordlist already loaded
	 */
	function speller() {
		if (!$this->Speller) {
			$this->Speller = new PureSpell();
			$this->Speller->load_dictionary("{$this->VARPath}/sphinx.psdic");
		}
		return $this->Speller;
	}

	/**
	 * Generate the XML for a given source. We have to construct the indexes structure and find the source object,
	 * and delegate to it to generate the XML response.
	 * @param $source
	 * @return unknown_type
	 */
	function xmlIndexContents($sourceName) {
		foreach ($this->indexes() as $index) {
			foreach ($index->Sources as $source) if ($source->Name == $sourceName) return $source->xmlIndexContents();
		}
	}

	/**
	 * Return all index objects that index data for the specified class.
	 * @param $className		Class that is indexed.
	 * @return unknown_type
	 */
	function getIndexesForClass($className) {
		$result = array();
		foreach ($this->indexes() as $baseClass => $index) {
			if (in_array($className, $index->SearchClasses)) $result[] = $index;
		}
		return $result;
	}

	/**
	 * Function to check for issues in the Sphinx environment, to make it easier to figure out if there are things
	 * wrong. Displays a list of issues to the terminal, and also displays what Sphinx understands of what configuration
	 * it has.
	 * @todo Check that the same user runs apache, owns temp, runs daemon
	 * @todo Check that sake is executable
	 * @todo Check for corrupt sphinx.conf caused by "dev/build flush=all" on command line, which doesn't work (or avoid using template engine to generate sphinx.conf)
	 * @todo Check that index files have been generated in the last 24 hours to detect cron failure
	 * @todo Check that index files have been generated in the last 10 minutes to assist in development failures (i.e. tell the
	 *      developer somethign is wrong. Should onbly be a notice.
	 * @todo Possible action is to run reindexer manually, with verbose option.
	 * @todo Format output nicely for both http and command line.
	 * @return void
	 */
	function diagnose() {
		$errors = array();
		$warnings = array();
		$notices = array();

		// Check if there are decorated classes.
		$classes = SphinxSearchable::decorated_classes();
		if (!$classes || count($classes) == 0) $warnings[] = array("message" => "There are no decorated classes",
													  "solutions" => array("Add SphinxSearchable extension to classes to be searched"));

		$notices[] = "Database type: " . $this->Database->type;

		// Database configuration
		if ($this->SupportedDatabase) {
			$notices[] = "Database server: " . $this->Database->server;
			$notices[] = "Database port: " . $this->Database->port;
			$notices[] = "Database props: " . $this->Database->database;
		}
		else $notices[] = "Database type " . $this->Database->server . " is not natively supported by Sphinx. Only xmlpipe can be used";

		// Check that sphinx directories and the config file are present.
		$notices[] = "";
		$notices[] = "Sphinx listening to: " . $this->Listen;
		$notices[] = "Sphinx configuration location is " . $this->VARPath;
		if (!file_exists($this->CNFPath)) $errors[] = array("message" => "Cannot access sphinx directory $this->VARPath",
															"solutions" => array(
																"Ensure a dev/build has been run since classes were decorated",
																"Check that apache and/or cli has permissions to the directory"
															));
		else if (!file_exists("{$this->VARPath}/sphinx.conf")) $errors[] = array("message" => "Cannot access sphinx config file $this->VARPath",
															"solutions" => array(
																"Ensure a dev/build has been run since classes were decorated",
																"Check that apache and/or cli has permissions to the directory"
															));
		else if (!file_exists($this->IDXPath)) $errors[] = array("message" => "Cannot access sphinx idxs directory $this->IDXPath",
															"solutions" => array(
																"Ensure a dev/build has been run since classes were decorated",
																"Check that apache and/or cli has permissions to the directory"
															));

		// Run through the primary indexes and check them.
		// Check if index files are present, and check their size. Deltas can be zero, non deltas indicate
		// that indexing has never been run. We can probably determine by the gap between a delta and main index
		// whether its just because its new, or on production if the reindex is not scheduled.
		foreach ($this->indexes() as $index) {
			$name = $index->Name;

			// Check that the config file contains a definition for this index.
			if (`grep "source {$name}Src" {$this->VARPath}/sphinx.conf` == "" ||
				`grep "index {$name}" {$this->VARPath}/sphinx.conf` == "") $errors[] = array("message" => "Cannot find either the source or index def for $name in sphinx.conf",
															"solutions" => array(
																"Ensure a dev/build has been run since classes were decorated",
																"Check that indexing has been run (Sphinx/reindex)"
															));

			// The rest of these checks are comparing primary indexes to their deltas, so if we're a delta,
			// skip this bit.
			if ($index->isDelta) continue;

			// Find the words file in index directory, and the delta.
			$f1 = "$this->IDXPath/$name.words";
			$f2 = "$this->IDXPath/{$name}Delta.words";
			if (!file_exists($f1) || !file_exists($f2)) $errors[] = array("message" => "Cannot access words file for index $name or its delta",
															"solutions" => array(
																"Ensure a dev/build has been run since classes were decorated",
																"Check that indexing has been run (Sphinx/reindex)",
																"Check that apache and/or cli has permissions to the directory",
																"Ensure reindexing is not failing, issue Sphinx/reindex?verbose=1"
															));
			else if (@filesize($f1) == 0 && @filesize($f2) > 0) $warnings[] = array("message" => "Primary index for $name has not been generated, but delta has data",
															"solutions" => array(
																"Perform a reindex (Sphinx/reindex)",
																"Set up a cron job to run Sphinx/index",
																"Ensure reindexing is not failing, issue Sphinx/reindex?verbose=1"
															));

		}

		// Backend specific information
		$notices[] = "Sphinx backend: " . self::$backend->class;

		self::$backend->diagnose($errors, $warnings, $notices);

		// And output

		$sep = Director::is_cli() ? "\n" : "<br>";
		if (count($errors)) {
			echo $this->format("heading", "Errors:");
			echo $this->format("liststart");
			foreach ($errors as $error) {
				echo $this->format("listitemstart", $error["message"]);
				if (isset($error["solutions"])) {
					echo $this->format("liststart", "Possible solutions:");
					foreach ($error["solutions"] as $solution) echo $this->format("listitem", $solution);
					echo $this->format("listend");
				}
			}
			echo $this->format("listend");
		}

		if (count($warnings)) {
			echo $this->format("heading", "Warnings:");
			echo $this->format("liststart");
			foreach ($warnings as $warning) {
				echo $this->format("listitemstart", $warning["message"]);
				if (isset($warning["solutions"])) {
					echo $this->format("text", "Possible solutions:");
					echo $this->format("liststart");
					foreach ($warning["solutions"] as $solution) echo $this->format("listitem", $solution);
					echo $this->format("listend");
				}
				echo $this->format("listitemend");
			}
			echo $this->format("listend");
		}
		if (count($notices)) {
			echo $this->format("heading", "Notices:");
			echo $this->format("liststart");
			foreach ($notices as $notice) echo $this->format("listitem", $notice);
			echo $this->format("listend");
		}
	}

	private $listDepth = 0;

	/**
	 * Return a formatted variant of an element. Uses Director::is_cli() to determine
	 * if running command line or in browser, returning output in plain text or HTML
	 * respectively.
	 * @param  $s
	 * @param  $style
	 * @return void
	 */
	function format($style, $s = "") {
		switch ($style) {
			case "heading":
				return Director::is_cli() ? strtoupper($s) . "\n" : "<h1>$s</h1>";
			case "text":
				return Director::is_cli() ? substr("    ", 0, $this->listDepth * 2) . "$s\n" : "$s<br>";
			case "liststart":
				$this->listDepth++;
				return Director::is_cli() ? "$s\n" : "$s\n<ul>";
			case "listitem":
				if ($s == "") return Director::is_cli() ? "\n" : "</ul><ul>";
				return Director::is_cli() ? substr("    ", 0, ($this->listDepth-1) * 2) . "* $s\n" : "<li>$s</li>\n";
			case "listitemstart":
				return Director::is_cli() ? "* $s\n" : "<li>$s</br>\n";
			case "listitemend":
				return Director::is_cli() ? "  $s\n" : "</li>\n";
			case "listend":
				$this->listDepth--;
				return Director::is_cli() ? "\n" : "</ul>$s\n";
		}
	}

	static function isWindows() {
		return strpos(PHP_OS, "WIN") !== false;
	}
}

/**
 * Represents a source of data for an index. Two sources are currently supported, sql and xmlpipes, each subclassed.
 */
abstract class Sphinx_Source extends ViewableData {
	function __construct($classes, $baseClass) {
		$this->SearchClasses = $classes;
		$this->Name = $baseClass;
		$this->Searchable = singleton($baseClass);
		$this->BaseClass = $baseClass;

		$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";

		/* This is used for the Delta handling */
		$this->prequery = null;

		/* Build the select schema & attributes available */
		$res = $this->Searchable->sphinxFieldConfig();
		$this->select = $res['select'];
		$this->attributes = $res['attributes'];

		$this->manyManys = $this->Searchable->sphinxManyManyAttributes();

		/* Build the actual query */
		$baseTable = ClassInfo::baseDataClass($baseClass);

		$select = "";
		foreach ($this->select as $alias => $value) $select .= "$value as {$bt}$alias{$bt},";
		$select = substr($select, 0, -1);
		$this->qry = $this->Searchable->buildSQL(null, null, null, null, true);
		$this->qry->select($select);
		$this->qry->where = array("{$bt}$baseTable{$bt}.{$bt}ClassName{$bt} in ('" . implode("','", $this->SearchClasses) . "')");
		if($res['where']) $this->qry->where[] = $res['where'];
		$this->qry->orderby = null;

		$this->Searchable->extend('augmentSQL', $this->qry);
	}

	abstract function config();

	/**
	 * Factory method to create a source given the mode.
	 * @param $classes			Constructor parameter
	 * @param $baseClass		Constructor parameter
	 * @param $mode				One of "sql" or "xmlpipe"
	 * @return unknown_type		Derivative of Sphinx_Source
	 */
	static function source_from_mode($classes, $baseClass, $mode) {
		if ($mode == "xmlpipe") $class = "Sphinx_Source_XMLPipe";
		else $class = "Sphinx_Source_SQL";

		return new $class($classes, $baseClass);
	}
}

class Sphinx_Source_SQL extends Sphinx_Source {
	function config() {
		$conf = array();
		$conf[] = "source {$this->Name}Src : BaseSrc {";

		if (($db = DB::getConn()) instanceof MySQLDatabase) {
			if (defined('DB::USE_ANSI_SQL')) $conf[] = "sql_query_pre = SET sql_mode = 'ansi'";
			$conf[] = "sql_query_pre = SET NAMES utf8";
		}
		if ($this->prequery) $conf[] = "sql_query_pre = " . preg_replace("/\s+/", " ", $this->prequery);
		$conf[] = "sql_query = " . preg_replace("/\s+/"," ",$this->qry);
		foreach ($this->attributes as $name => $type) $conf[] = "sql_attr_$type = $name";
		foreach ($this->manyManys as $name => $query) $conf[] = "sql_attr_multi = uint $name from query; " . $query;

		return implode("\n\t", $conf) . "\n}\n";
	}
}

class Sphinx_Source_XMLPipe extends Sphinx_Source {
	function config() {
		$conf = array();
		$conf[] = "source {$this->Name}Src : BaseSrc {";
		$conf[] = "type = xmlpipe2";
		if (Sphinx::isWindows())
			$cmd =  "php " . Director::baseFolder() . "/sapphire/cli-script.php";
		else
			$cmd = Director::baseFolder() . "/sapphire/sake";
		$conf[] = "xmlpipe_command = $cmd sphinxxmlsource/" . $this->Name;

		return implode("\n\t", $conf) . "\n}\n";
	}

	/**
	 * When running in xmlpipe mode, this should return the full XML response.
	 * @return unknown_type
	 */
	function xmlIndexContents() {
		$result = array();
		$result[] = '<?xml version="1.0" encoding="utf-8"?>';
		$result[] = '<sphinx:docset>';

		$conf = singleton($this->BaseClass)->stat("sphinx");
		$externalFields = ($conf && isset($conf['external_content'])) ? $conf['external_content'] : null;

		$result[] = '  <sphinx:schema>';
		foreach ($this->select as $alias => $value) {
			if (!isset($this->attributes[$alias]) && $alias != "id") $result[] = '    <sphinx:field name="' . strtolower($alias) . '"/>';
		}
		if ($externalFields) foreach ($externalFields as $alias => $function) $result[] = '    <sphinx:field name="' . strtolower($alias) . '"/>';

		foreach ($this->attributes as $name => $type) $result[] = '    <sphinx:attr name="' . strtolower($name) . '" type="' . (($type == "uint") ? "int" : $type) . '" />';

		// Many to many relationships
		foreach ($this->manyManys as $name => $query) $result[] = '    <sphinx:attr name="' . strtolower($name) . '" type="multi" />';
		$result[] = '  </sphinx:schema>';

		if ($this->prequery) $query = DB::query($this->prequery);

		// Fetch many-many data. Each item in $this->manyManys is a map from an attribute name, to be used
		// as the multi-value attribute name, and maps to either a string SQL query that gets all the
		// attributes values, or to an array which identifies a callback that gets the attribute values for
		// each doc. If it's a query, we execute it which selects all M-M for this type of object.
		// Note this produces an array of values for each M-M, which maps id=>array of maps values,
		// where id is the 64-bit class + ID.
		// If the value is a callback, we add it to the callback, because that's executed during iteration over
		// the objects.
		$manyManyData = array();
		$manyManyCallbacks = array();
		foreach ($this->manyManys as $name => $query) {
			if (is_string($query)) {
				$q = DB::query($query);
				$values = array();
				foreach ($q as $row) $values[$row["id"]][] = $row[$name];
				$manyManyData[$name] = $values;
			}
			else if (is_array($query))
				$manyManyCallbacks[$name] = $query;
		}

		$query = $this->qry->execute();

		foreach ($query as $row) {
			$result[] = '  <sphinx:document id="' . $row["id"] . '">';

			// Single fields and regular attributes
			foreach ($this->select as $alias => $value) {
				if ($alias == "id") continue;
				$out = $this->cleanForXML($row[$alias]);

				// If its not an attribute it must be a search field, so quote it.
				if (!isset($this->attributes[$alias]) && $out) $out = "\n<![CDATA[\n$out\n]]>\n";
				if ($alias != "index") $result[] = '    <' . strtolower($alias) . '>' . $out . '</' . strtolower($alias) . '>';
			}

			// External sources
			if ($externalFields) foreach ($externalFields as $alias => $function) {
				$out = $this->cleanForXML(call_user_func($function, $row["_id"]));
				if ($out) $out = "\n<![CDATA[\n$out\n]]>\n";
				$result[] = '    <' . strtolower($alias) . '>' . $out . '    </' . strtolower($alias) . '>';
			}

			// Many-to-many relationships - write the tag with the values in a comma delimited list in the element.
			foreach ($manyManyData as $name => $values) {
				$result[] = '    <' . strtolower($name) . '>';

				if (isset($values[$row["id"]])) $result[] = implode(",", $values[$row["id"]]);
				$result[] = '    </' . strtolower($name) . '>';
			}

			// Many-to-manys defined by callbacks
			foreach ($manyManyCallbacks as $name => $callback) {
				$result[] = '    <' . strtolower($name) . '>';
				$val = call_user_func($callback, $row["_id"]);
				if ($val && is_array($val)) $result[] = implode(",", $val);
				$result[] = '    </' . strtolower($name) . '>';
			}

			$result[] = '  </sphinx:document>';
		}

		$result[] = '</sphinx:docset>';

		return implode("\n", $result);
	}

	/**
	 * Clean up the given text so it can be put in the XML output stream. This
	 * involves getting rid of HTML elements that can be put there by
	 * rich text editor. Also ensures that there are no ]]> in the result,
	 * which will trip up the parsing of the XML stream.
	 * @param String $val
	 * @return String
	 */
	function cleanForXML($val) {
		$val = preg_replace("/[\x01-\x08\x0B\x0C\x0E-\x1F]/", "", $val);
		$val = preg_replace("/\]\]\>/", "", $val);					// no ]]>
		$val = strip_tags($val);									// no html elements
		if (strlen($val) >= (1048 * 1024)) $val = substr($val, 0, (1048 * 1024)-1);
		return $val;
	}
}

/*
 * Handles introspecting a DataObject to generate a source and an index configuration for that DataObject
 */
class Sphinx_Index extends ViewableData {
	function __construct($classes, $baseClass, $mode) {
		$this->data = singleton('Sphinx');

		$this->SearchClasses = $classes;
		$this->Name = $baseClass;
		$this->BaseClass = $baseClass;
		$this->Mode = $mode;

		$this->isDelta = false;

		$this->Sources = array();
		$this->Sources[] = Sphinx_Source::source_from_mode($classes, $baseClass, $mode);

		// Base table is the root table for the DataObject. spiTable is the
		// table that contains the SphinxPrimaryIndexed column, which is not
		// necessarily in the base table if the decorator is not applied to the
		// base class.
		$inst = singleton($baseClass);
		$this->baseTable = $inst->baseTable();

		$this->spiTable = null;

		if (!defined('DB::USE_ANSI_SQL')) $pattern = '/^`([^`]+)`.`SphinxPrimaryIndexed`/';
		else $pattern = '/^"([^"]+)"."SphinxPrimaryIndexed"/';

		foreach ($this->Sources[0]->select as $alias => $value) {
			if (preg_match($pattern, $value, $m)) {
				$this->spiTable = $m[1];
				break;
			}
		}
	}

	/**
	 * Return true if any of the sources for this index require a direct
	 * connection to the database inside sphinx. This can be used to determine
	 * if the database connection properties are required in the config
	 * file.
	 */
	function requiredDirectDBConnection() {
		$required = false;
		foreach ($this->Sources as $source)
			if ($source instanceof Sphinx_Source_SQL) $required = true;
		return $required;
	}

	function config() {
		$out = array();
		foreach ($this->Sources as $source) $out[] = $source->config();

		$idx = array();
		$idx[] = "index {$this->Name} : BaseIdx {";
		foreach ($this->Sources as $source) $idx[] = "source = {$source->Name}Src";
		$idx[] = "path = {$this->data->IDXPath}/{$this->Name}";

		return implode("\n", $out).implode("\n\t", $idx)."\n}\n";
	}
}

/**
 * A helper class for helping with database-specific query construction.
 * The intention is that these functions will be rationalised and added to the abstract database
 * classes in sapphire at a point when we can alter the API.
 */
class SphinxDBHelper {
	static function update_multi_requires_prefix() {
		if (($conn = DB::getConn()) instanceof PostgreSQLDatabase) return false;
		return true;
	}
}

abstract class Sphinx_Backend extends Object {

	protected $sphinx;

	function setSphinxInstance($sphinx) {
		$this->sphinx = $sphinx;
	}

	// Called by index action to do once only backend specific initialisation of sphinx that needs admin access to do */
	function install() { /* NOP */ }
	// Called by configure action to do per schema change backend specific configuration of sphinx */
	function configure() { /* NOP */ }

	// Next three are obvious
	abstract function status();
	abstract function start();
	abstract function stop();

	// Called during reindex
	abstract function updateIndexes($idxs, $verbose=false);
	abstract function updateDictionary($idxs);

	// Called during diagnose, to do backend specific diagnosis
	function diagnose(&$errors, &$warnings, &$notices) { /* NOP */ }
}

class Sphinx_NullBackend extends Sphinx_Backend {

	protected $status = 'Stopped';

	function status() {
		return $this->status();
	}

	function start() {
		$this->status = 'Running';
	}

	function stop() {
		$this->status = 'Stopped';
	}

	function updateIndexes($idxs, $verbose=false) { /* NOP */ }
	function updateDictionary($idxs) { /* NOP */ }
}

abstract class Sphinx_BinaryBackend extends Sphinx_Backend {
	/** Common paths under Linux & FreeBSD & Windows */
	static $common_paths = '';

	/** Directory or ,-separated directories that the sphinx binaries (searchd, indexer, etc.) are in. Add override to mysite/_config.php if they are in a custom location */
	static $binary_location = '';

	/** Binary extension - some OSes (cough windows cough) need an extension to know if something's a binary */
	static $binary_extension = '';

	/** Names of the sphinx binaries we care about - you can set the absolute location explicity to avoid autodetection */
	static $binaries = array('indexer', 'search', 'searchd');

	protected $BinaryLocations = array();

	function __construct() {
		parent::__construct();
		
		$paths = $this->searchPaths();
		$extension = $this->stat('binary_extension');

		// Find all the binary paths and populate binary_locations. If not found, hope it's in path
		foreach (Object::combined_static($this->class, 'binaries') as $command) {
			foreach ($paths as $path) {
				$absPath =  "$path/$command" . ($extension ? ".$extension" : '');
				if (file_exists($absPath)) {
					$this->BinaryLocations[$command] = $absPath;
					break;
				}
			}
		}
	}

	function searchPaths() {
		// Look in common paths..
		$paths = explode(',', $this->stat('common_paths'));
		// Paths set in static (from _config.php)
		if ($this->stat('binary_location')) $paths = array_merge(explode(',', $this->stat('binary_location')), $paths);
		// Paths set in global (from _ss_environment.php)
		if (defined('SS_SPHINX_BINARY_LOCATION')) $paths = array_merge(explode(',', SS_SPHINX_BINARY_LOCATION), $paths);

		return $paths;
	}

	function bin($command) {
		// If we found an explict binary, use that
		if (isset($this->BinaryLocations[$command])) return $this->BinaryLocations[$command];
		// Otherwise, hope it's in the path
		$extension = $this->stat('binary_extension');
		return $command . ($extension ? ".$extension" : '');
	}

	function diagnose(&$errors, &$warnings, &$notices) {
		// Check if the sphinx binaries are present on the host
		$notices[] = "Sphinx binary locations: " . implode(', ', array_values($this->BinaryLocations));

		foreach (Object::combined_static($this->class, 'binaries') as $command) {
			if (!isset($this->BinaryLocations[$command])) {
				$errors[] = array(
					"message"	=> "Cannot find the sphinx '$command' binary",
					"solutions" => array(
						"Set Sphinx_BinaryBackend::\$binary_location or SS_SPHINX_BINARY_LOCATION to include the path that contains the sphinx binaries,".
						" or ensure that sphinx binaries are installed in one of these directories: ".implode(', ', $this->searchPaths())
					)
				);
			}
		}
	}

	function updateDictionary($idxs) {
		$p = new PureSpell();
		$p->load_dictionary("{$this->sphinx->VARPath}/sphinx.psdic");

		foreach ($idxs as $idx) {
			`{$this->bin('indexer')} --config {$this->sphinx->VARPath}/sphinx.conf $idx --buildstops {$this->sphinx->IDXPath}/$idx.words 100000`;
			$p->load_wordfile("{$this->sphinx->IDXPath}/$idx.words");
		}

		$p->save_dictionary("{$this->sphinx->VARPath}/sphinx.psdic");
	}
}

/**
 * A sphinx control backend for Linux, OS X and anything else unix-y
 */
class Sphinx_UnixishBackend extends Sphinx_BinaryBackend {
	
	static $common_paths = '/usr/bin,/usr/local/bin,/usr/local/sbin,/opt/local/bin';

	/**
	 * Get status of searchd
	 */
	function status() {
		if (file_exists($this->sphinx->PIDFile)) {
			$pid = (int) trim(file_get_contents($this->sphinx->PIDFile));
			if (!$pid) return 'Stopped - No PID';
			if (preg_match("/(^|\\s)$pid\\s/m", `ps ax`)) return 'Running';
			return 'Stopped - Stale PID';
		}
		return 'Stopped';
	}

	/**
	 * Start searchd. NOP if already running.
	 */
	function start() {
		if ($this->status() == 'Running') return;
		$result = `{$this->bin('searchd')} --config {$this->sphinx->VARPath}/sphinx.conf &> /dev/stdout`;
		
		if ($this->status() != 'Running') {
			user_error("Couldn't start Sphinx, searchd output follows:\n$result", E_USER_WARNING);
		}
	}

	/**
	 * Stop searchd. NOP if already stopped.
	 */
	function stop() {
		if ($this->status() != 'Running') return;
		`{$this->bin('searchd')} --config {$this->sphinx->VARPath}/sphinx.conf --stop`;

		$time = time();
		while (time() - $time < 10 && $this->status() == 'Running') sleep(1);

		if ($this->status() == 'Running') user_error('Could not stop sphinx searchd');
	}

	function updateIndexes($idxs, $verbose=false) {
		// If searchd is running, we want to rotate the indexes
		$rotate = ($this->status() == 'Running') ? '--rotate' : '';
		$idxlist = implode(' ', $idxs);
		return `{$this->bin('indexer')} --config {$this->sphinx->VARPath}/sphinx.conf $rotate $idxlist &> /dev/stdout`;
	}
}

/**
 * A sphinx control backend for windows
 */
class Sphinx_WindowsServiceBackend extends Sphinx_BinaryBackend {
	static $common_paths = 'c:/sphinx/bin';

	static $binary_extension = 'exe';

	static $service_name = null;

	function __construct() {
		if (!self::$service_name) self::$service_name = 'SphinxSearch_'.sha1(Director::baseFolder());
		parent::__construct();
	}

	protected $locks = array();

	protected function obtainLock($lock) {
		// Don't attempt to relock if we're already holding the lock
		if (isset($this->locks[$lock])) return;
		
		// Grab the lock using flock
		$this->locks[$lock] = fopen($this->sphinx->VARPath."/sphinx_{$lock}.lock", "a");
		if (!flock($this->locks[$lock], LOCK_EX)) user_error('Couldn\'t obtain Sphinx lock', E_USER_ERROR);
	}
	
	protected function releaseLock($lock) {
		if (!isset($this->locks[$lock])) user_error('Wanted to release lock, but wasn\'t holding it', E_USER_ERROR);
		
		flock($this->locks[$lock], LOCK_UN);
		fclose($this->locks[$lock]);
		unset($this->locks[$lock]);
	}

	/**
	 * Calls the 'sc' binary to do service control. Hacky little parser to parse results
	 *
	 * You'll probably get permission errors to start - you need to allow IUSR to
	 * control this service
	 */
	function sc($command, $failureIsError = true) {
		$service_name = self::$service_name;
		$out = `sc $command $service_name`;
		
		if (preg_match('/FAILED (\d+)/', $out, $match)) {
			if ($failureIsError) user_error("Couldn't execute command $command for Sphinx service $service_name, error given was $out", E_USER_ERROR);
			else return array('ERROR' => $out, 'CODE' => $match[1]);
		}
		
		$lines = array();
		foreach (explode("\n", $out) as $line) {
			$line = trim($line); if (!$line) continue;
			if (strpos($line, ':')===false) $lines[] = array_pop($lines)." ".$line;
			else $lines[] = $line;
		}
		
		$res = array();
		foreach ($lines as $line) {
			$parts = explode(' : ', $line);
			$res[trim($parts[0])] = isset($parts[1]) ? preg_split('/(?<!,)\s+/', trim($parts[1])) : '';
		}
		
		return $res;
	}

	function install() {
		$servicename = self::$service_name;
		$res = $this->sc('qc', false);		
		$config = str_replace('/', '\\', $this->sphinx->VARPath . '/sphinx.conf');
		
		if (isset($res['ERROR'])) {
			if ($res['CODE'] == 1060) {
				// Create service
				echo "Creating service:\n";
				echo `{$this->bin('searchd')} --install --config {$config} --servicename $servicename`;
				echo "\n";
				
				echo "Setting permissions:\n";
				$current = trim(`sc sdshow $servicename`);
				$new = str_replace('D:', 'D:(A;;CCLCSWLORPWP;;;S-1-5-17)', $current);
				echo `sc sdset $servicename $new`;
				echo "\n";
				
				echo "Done\n";
			}
			if ($res['CODE'] == 5) {
				echo 'Permission denied - are you running this as Administrator?';
			}
		}
		else {
			$binpath = implode(' ', $res['BINARY_PATH_NAME']);
			if (!preg_match('/--config ((\'[^\']+)|("[^"]+)|([^\s]+))/', $binpath, $m)) {
				user_error('Couldn\'t parse config from binary path ' . $binpath, E_USER_ERROR);
			}
			else if ($m[1] !== $config) {
				user_error('Sphinx service '.self::$service_name.' is already set up for different site (config file: '.$m[1].')', E_USER_ERROR);
			}
			else {
				echo 'Already installed';
			}
		}
	}

	function configure() {
		// Remember original
		$idxPath = $this->sphinx->IDXPath;

		// Change IDXPath to point to rotate subdirectory, and create if missing
		$this->sphinx->IDXPath = $idxPath.'/rotate';
		if (!file_exists($this->sphinx->IDXPath)) mkdir($this->sphinx->IDXPath, 0770);

		// Regenerate the configuration
		file_put_contents("{$this->sphinx->VARPath}/sphinx-rotate.conf", $this->sphinx->generateConfiguration());

		// And restore to original
		$this->sphinx->IDXPath = $idxPath;
	}

	function status() {
		$res = $this->sc('query');

		if ($res['STATE'][1] == 'RUNNING') return 'Running';
		return 'Stopped';
	}

	function start() {
		if ($this->status() == 'Running') return;
		
		// Obtain lock before starting, since reindex might be holding it stopped
		$this->obtainLock('start');
		$this->sc('start');
		$this->releaseLock('start');
	}

	function stop() {
		if ($this->status() != 'Running') return;
		
		$res = $this->sc('stop');
		
		// Initially the state is 'STOP_PENDING'. We need to wait until
		// actually stopped
		$time = time();
		while (time() - $time < 10) {
			$res = $this->sc('query');
			if ($res['STATE'][1] == 'STOPPED') return;
			sleep(1);
		}
		
		user_error('Windows said it was shutting down sphinx, but after 10 seconds it\'s still running', E_USER_ERROR);
	}

	function updateIndexes($idxs, $verbose=false) {
		// Make sure no more than one reindex running at once
		$this->obtainLock('reindex');
			
		// Remove any old partially built indexes
		foreach (glob($this->sphinx->IDXPath.'/rotate/*') as $file) unlink($file);
			
		// Build in the rotate directory
		$idxlist = implode(' ', $idxs);
		$res = `{$this->bin('indexer')} --config {$this->sphinx->VARPath}/sphinx-rotate.conf $idxlist`;
		
		if (!$this->sphinx->detectIndexingError($res)) {
			// Make sure start doesn't get run
			$this->obtainLock('start');
			
			// Fake rotation
			if ($runningBeforeUpdate = ($this->status() == 'Running')) $this->stop();
			
			foreach (glob($this->sphinx->IDXPath.'/rotate/*') as $file) {
				$name = basename($file);
				rename($file, "{$this->sphinx->IDXPath}/$name");
			}

			if ($runningBeforeUpdate) $this->start();
			else $this->releaseLock('start');
		}
		
		$this->releaseLock('reindex');
		
		return $res;
	}
}



