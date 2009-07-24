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

	/** What stop words list to use. null => default list. array('word1','word2') => those words. path as string => that file of words. false => no stopwords list */
	static $stop_words = null;
	
	/** Generate configuration from either static variables or default values, and preps $this to contain configuration values, to be used in templates */
	function __construct() {
		global $databaseConfig;

		$this->Database = new ArrayData($databaseConfig);
		// If server is localhost, sphinx tries connecting using a socket instead. Lets avoid that
		if ($this->Database->server == 'localhost') $this->Database->server = '127.0.0.1';
		
		$this->CNFPath = Director::baseFolder() . '/sphinx/conf'; 
		
		$this->VARPath = self::$var_path ? self::$var_path : TEMP_FOLDER . '/sphinx';
		$this->IDXPath = self::$idx_path ? self::$idx_path : $this->VARPath . '/idxs';
		$this->PIDFile = self::$pid_file ? self::$pid_file : $this->VARPath . '/searchd.pid';
		
		// Binary path
		if     (defined('SS_SPHINX_BINARY_LOCATION'))  $this->BINPath = SS_SPHINX_BINARY_LOCATION;       // By constant from _ss_environment.php
		elseif ($this->stat('binary_location'))        $this->BINPath =  $this->stat('binary_location'); // By static from _config.php
		elseif (file_exists('/usr/bin/indexer'))       $this->BINPath = '/usr/bin';                      // By searching common directories
		elseif (file_exists('/usr/local/bin/indexer')) $this->BINPath = '/usr/local/bin';
		else                                           $this->BINPath = '.';                             // Hope it's in path
		
		// An array of class => indexes-as-array-of-strings. Actually filled in as requested by #indexes
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
	
	/** Accessor to get the current set of indexes. Built once on first request */
	function indexes($classes=null) {
		if (!$classes) $classes = SphinxSearchable::decorated_classes();
		if (!is_array($classes)) $classes = array($classes);
		
		$res = array();
		
		foreach ($classes as $class) {
			if (!isset($this->indexes[$class])) {
				$indexes = array(new Sphinx_Index($class));
				SphinxVariants::alterIndexes($class, $indexes);
				
				$this->indexes[$class] = $indexes;
			}
			
			$res = array_merge($res, $this->indexes[$class]);
		}

		return $res;
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
	}	
	
	/**
	 * Re-build sphinx's indexes
	 * @param $idxs array - A list of indexes to rebuild as strings, or null to rebuild all indexes
	 */
	function reindex($idxs=null) {
		// If we're being called as a controller, or we're called with no indexes specified, rebuild all indexes 
		if ($idxs instanceof HTTPRequest || $idxs === null) $idxs = $this->indexes();
		elseif (!is_array($idxs)) $idxs = array($idxs);
		
		// If we were passed an array of Sphinx_Index's, get the list of just the names
		if (isset($idxs[0]) && $idxs[0] instanceof Sphinx_Index) $idxs = array_map(create_function('$idx', 'return $idx->Name;'), $idxs);

		// If searchd is running, we want to rotate the indexes
		$rotate = $this->status() == 'Running' ? '--rotate' : ''; 
		
		// Generate Sphinx index
		$idxlist = implode(' ', $idxs);
		`{$this->bin('indexer')} --config {$this->VARPath}/sphinx.conf $rotate $idxlist`;
		
		// Generate word lists
		$p = new PureSpell();
		$p->load_dictionary("{$this->VARPath}/sphinx.psdic");
		
		foreach ($idxs as $idx) {
			`{$this->bin('indexer')} --config {$this->VARPath}/sphinx.conf $rotate $idx --buildstops {$this->IDXPath}/$idx.words 100000`;
			$p->load_wordfile("{$this->IDXPath}/$idx.words");
		}
		
		$p->save_dictionary("{$this->VARPath}/sphinx.psdic");
				
		return 'OK';
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
		`{$this->bin('searchd')} --config {$this->VARPath}/sphinx.conf`;
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
	
	/**
	 * Returns a SphinxClient API connection. Starts server if not running.
	 */
	function connection() {
		$this->start();

		require_once(Director::baseFolder() . '/sphinx/thirdparty/sphinxapi.php');

		$co = new SphinxClient;
		$co->setServer($this->VARPath.'/searchd.sock');
		return $co;
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
	
}

class Sphinx_Source extends ViewableData {
	function __construct($class) {
		$this->SearchClass = $class;
		$this->Name = $class;
		$this->Searchable = singleton($class);
		
		/* Build the select schema & attributes available */
		$res = $this->Searchable->sphinxFieldConfig();
		$this->select = $res['select'];
		$this->attributes = $res['attributes'];
		
		$this->manyManys = $this->Searchable->sphinxManyManyAttributes();
		
		/* Build the actual query */
		$baseTable = ClassInfo::baseDataClass($class);
		
		$this->qry = $this->Searchable->buildSQL(null, null, null, null, true);
		$this->qry->select($this->select);
		$this->qry->where = array("`$baseTable`.`ClassName` = '$class'");
		$this->qry->orderby = null;
		
		$this->Searchable->extend('augmentSQL', $this->qry);
	}
	
	function config() {
		$conf = array();
		$conf[] = "source {$this->Name}Src : BaseSrc {";
		$conf[] = "sql_query = {$this->qry}";
		$conf[] = implode("\n\t", $this->attributes);
		$conf[] = implode("\n\t", $this->manyManys);
		return implode("\n\t", $conf) . "\n}\n";
	}
}

/*
 * Handles introspecting a DataObject to generate a source and an index configuration for that DataObject
 */
class Sphinx_Index extends ViewableData {
	function __construct($class) {
		$this->data = singleton('Sphinx');
		
		$this->SearchClass = $class;
		$this->Name = $class;
		
		$this->Sources = array();
		$this->Sources[] = new Sphinx_Source($class);
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

