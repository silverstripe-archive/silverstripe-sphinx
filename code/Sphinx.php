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
		'start',
		'stop',
		'status'
	);
	
	/** Directory that the sphinx binaries (searchd, indexer, etc.) are in. Add override to mysite/_config.php if they are in a custom location */
	static $binary_location = '';
	
	/** Override where the indexes and other run-time data is stored. By default, uses subfolders of TEMP_FOLDER/sphinx (normally /tmp/silverstripe-cache-sitepath/sphinx) */
	static $var_path = null;
	static $idx_path = null;
	static $pid_file = null;

	/** Set a tcp port. By default, searchd uses a unix socket in var_path. Set a port here or as SS_SPHINX_TCP_PORT to use tcp socket listening on this port on localhost instead */ 
	static $tcp_port = null;

	static $client_class = "SphinxClient";
	static $client_class_param = null;	// used to pass SapphireTest object into the controller without coupling

	/** What stop words list to use. null => default list. array('word1','word2') => those words. path as string => that file of words. false => no stopwords list */
	static $stop_words = null;
	
	/** Generate configuration from either static variables or default values, and preps $this to contain configuration values, to be used in templates */
	function __construct() {
		global $databaseConfig;

		$this->Database = new ArrayData($databaseConfig);
		
		// If there is a custom port, its on the end of Database->server, so take it off and stick it in the port property (which is not in $databaseConfig)
		if (strpos($this->Database->server, ":") !== FALSE) {
			$a = explode(":", $this->Database->server);
			$this->Database->server = $a[0];
			$this->Database->port = $a[1];
		}

		if (!is_numeric($this->Database->port)) $this->Database->port = 3306;		
		
		// If server is localhost, sphinx tries connecting using a socket instead. Lets avoid that
		if ($this->Database->server == 'localhost') $this->Database->server = '127.0.0.1';
		
		$this->CNFPath = Director::baseFolder() . '/sphinx/conf'; 
		
		$this->VARPath = self::$var_path ? self::$var_path : TEMP_FOLDER . '/sphinx';
		$this->IDXPath = self::$idx_path ? self::$idx_path : $this->VARPath . '/idxs';
		$this->PIDFile = self::$pid_file ? self::$pid_file : $this->VARPath . '/searchd.pid';
		
		$port = defined('SS_SPHINX_TCP_PORT') ? SS_SPHINX_TCP_PORT : self::$tcp_port;
		$this->Listen = $port ? "127.0.0.1:$port" : "{$this->VARPath}/searchd.sock";
		
		// Binary path
		if     (defined('SS_SPHINX_BINARY_LOCATION'))  $this->BINPath = SS_SPHINX_BINARY_LOCATION;       // By constant from _ss_environment.php
		elseif ($this->stat('binary_location'))        $this->BINPath =  $this->stat('binary_location'); // By static from _config.php
		elseif (file_exists('/usr/bin/indexer'))       $this->BINPath = '/usr/bin';                      // By searching common directories
		elseif (file_exists('/usr/local/bin/indexer')) $this->BINPath = '/usr/local/bin';
		else                                           $this->BINPath = '.';                             // Hope it's in path
		
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
	
	/** Accessor to get the location of a sphinx binary */
	function bin($prog='') {
		return ( $this->BINPath ? $this->BINPath . '/' : '' ) . $prog;
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
			$sig = $this->getSearchSignature($class);

			$conf = singleton($class)->stat('sphinx');
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
			else
				$index_desc[$sig] = array("classes" => array($class), "baseClass" => $class, "mode" => $mode);
		}

//echo "indexes to build:"; print_r($index_desc);

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
	
	// Return a string signature for a class.
	protected function getSearchSignature($class) {
		$sing = new $class();
		$fields = $sing->sphinxFields($class);

		$result = ":";

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
		
		SSViewer::set_source_file_comments(false);
		$res = array();
		
		$res[] = $this->renderWith(Director::baseFolder() . '/sphinx/conf/source.ss');
		$res[] = $this->renderWith(Director::baseFolder() . '/sphinx/conf/index.ss');
			
		foreach ($this->indexes() as $index) $res[] = $index->config();
		
		$res[] = $this->renderWith(Director::baseFolder() . '/sphinx/conf/apps.ss');
		
		if (!file_exists($this->VARPath)) mkdir($this->VARPath, 0770);
		if (!file_exists($this->IDXPath)) mkdir($this->IDXPath, 0770);
		
		file_put_contents("{$this->VARPath}/sphinx.conf", implode("\n", $res));
		
		//echo nl2br(implode("\n", $res));
	}

	/**
	 * Re-build sphinx's indexes
	 * @param $idxs array - A list of indexes to rebuild,
	 *		or null to rebuild all indexes. Array items can be either index
	 *		names as strings, or Sphinx_Index objects.
	 */
	function reindex($idxs=null) {
		// If we're being called as a controller, or we're called with no indexes specified, rebuild all indexes 
		if ($idxs instanceof SS_HTTPRequest || $idxs instanceof HTTPRequest || $idxs === null) $idxs = $this->indexes();
		elseif (!is_array($idxs)) $idxs = array($idxs);

		// If we were passed an array of Sphinx_Index's, get the list of just the names
		foreach ($idxs as $idx) {
			if ($idx instanceof Sphinx_Index) $idxs = array_map(create_function('$idx', 'return $idx->Name;'), $idxs);
			break;
		}

		// If searchd is running, we want to rotate the indexes
		$rotate = $this->status() == 'Running' ? '--rotate' : ''; 
		
		// Generate Sphinx index
		$idxlist = implode(' ', $idxs);

		$indexingOutput = `{$this->bin('indexer')} --config {$this->VARPath}/sphinx.conf $rotate $idxlist &> /dev/stdout`;
		// We can't seem to be able to rely on exit status code, so we have to do this
		if(!preg_match("/\nERROR:/", $indexingOutput)) {
			// Generate word lists
			$p = new PureSpell();
			$p->load_dictionary("{$this->VARPath}/sphinx.psdic");

			foreach ($idxs as $idx) {
				`{$this->bin('indexer')} --config {$this->VARPath}/sphinx.conf $rotate $idx --buildstops {$this->IDXPath}/$idx.words 100000`;
				$p->load_wordfile("{$this->IDXPath}/$idx.words");
			}

			$p->save_dictionary("{$this->VARPath}/sphinx.psdic");
	
			if($this->response) $this->response->addHeader("Content-type", "text/plain");
			return "OK";

		} else {
			if($this->response) $this->response->addHeader("Content-type", "text/plain");
			return "ERROR\n\n$indexingOutput";
		}
	}
	
	/**
	 * Check the status of searchd.
	 * @return string - 'Running', 'Stopped - Stale PID' or 'Stopped'
	 */
	function status() {
		if (file_exists($this->PIDFile)) {
			$pid = trim(file_get_contents($this->PIDFile));
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
		$result = `{$this->bin('searchd')} --config {$this->VARPath}/sphinx.conf &> /dev/stdout`;
		if ($this->status() != 'Running') {
			user_error("Couldn't start Sphinx, searchd output follows:\n$result", E_USER_WARNING);
		}
	}
	
	/**
	 * Stop searchd. NOP if already stopped.
	 */
	function stop() {
		if ($this->status() != 'Running') return;
		`{$this->bin('searchd')} --config {$this->VARPath}/sphinx.conf --stop`;
		
		$time = time();
		while (time() - $time < 10 && $this->status() == 'Running') sleep(1);
		
		if ($this->status() == 'Running') user_error('Could not stop sphinx searchd');
	}
	
	
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

		if (defined('DB::USE_ANSI_SQL')) $conf[] = "sql_query_pre = SET sql_mode = 'ansi'";
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
		$conf[] = "xmlpipe_command = " . Director::baseFolder() . "/sapphire/sake sphinxxmlsource/" . $this->Name;

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
			if (!isset($this->attributes[$alias])) $result[] = '    <sphinx:field name="' . strtolower($alias) . '"/>';
		}
		if ($externalFields) foreach ($externalFields as $alias => $function) $result[] = '    <sphinx:field name="' . strtolower($alias) . '"/>';

		foreach ($this->attributes as $name => $type) $result[] = '    <sphinx:attr name="' . strtolower($name) . '" type="' . (($type == "uint") ? "int" : $type) . '" />';

		// Many to many relationships
		foreach ($this->manyManys as $name => $query) $result[] = '    <sphinx:attr name="' . strtolower($name) . '" type="multi" />';
		$result[] = '  </sphinx:schema>';

		if ($this->prequery) $query = DB::query($this->prequery);

		// Fetch many-many data. The query we are given selects all M-M for this type of object. Note this produces an array
		// of values for each M-M, which maps id=>array of mapps values, where id is the 64-bit class + ID.
		$manyManyData = array();
		foreach ($this->manyManys as $name => $query) {
			$q = DB::query($query);
			$values = array();
			foreach ($q as $row) $values[$row["id"]][] = $row[$name];
			$manyManyData[$name] = $values;	
		}

		$query = $this->qry->execute();

		foreach ($query as $row) {
			$result[] = '  <sphinx:document id="' . $row["id"] . '">';

			// Single fields and regular attributes
			foreach ($this->select as $alias => $value) {
				$out = $row[$alias];
				$out = preg_replace("/[^\x9\xA\xD\x20-\x7F]/", "", $out);
				// If its not an attribute it must be a search field, so quote it.
				if (!isset($this->attributes[$alias]) && $out) $out = "\n<![CDATA[\n$out\n]]>\n";
				if ($alias != "index") $result[] = '    <' . strtolower($alias) . '>' . $out . '</' . strtolower($alias) . '>';
			}

			// External sources
			if ($externalFields) foreach ($externalFields as $alias => $function) {
				$out = call_user_func($function, $row["_id"]);
				$out = preg_replace("/[^\x9\xA\xD\x20-\x7F]/", "", $out);
				if ($out) $out = "\n<![CDATA[\n$out\n]]>\n";
				$result[] = '    <' . strtolower($alias) . '>' . $out . '    </' . strtolower($alias) . '>';
			}

			// Many-to-many relationships - write the tag with the vaues in a comma delimited list in the element.
			foreach ($manyManyData as $name => $values) {
				$result[] = '    <' . strtolower($name) . '>';

				if (isset($values[$row["id"]])) $result[] = implode(",", $values[$row["id"]]);
				$result[] = '    </' . strtolower($name) . '>';
			}

			$result[] = '  </sphinx:document>';
		}

		$result[] = '</sphinx:docset>';

		return implode("\n", $result);
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

		$this->baseTable = null;
			
		if (!defined('DB::USE_ANSI_SQL')) $pattern = '/^`([^`]+)`.`SphinxPrimaryIndexed`/';
		else $pattern = '/^"([^"]+)"."SphinxPrimaryIndexed"/';

		foreach ($this->Sources[0]->select as $alias => $value) {
			if (preg_match($pattern, $value, $m)) { 
				$this->baseTable = $m[1];
				break; 
			} 
		}
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

