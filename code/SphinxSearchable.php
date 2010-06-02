<?php

/**
 * A decorator, which needs to be attached to every DataObject that you wish to search with Sphinx.
 * 
 * Provides a few end developer useful methods, like search, but mostly is here to provide two internal facilities
 *  - Provides introspection methods, for getting the fields and relationships of an object in Sphinx-compatible form
 *  - Provides hooks into writing, deleting and schema rebuilds, so we can reindex & rebuild the Sphinx configuration automatically 
 * 
 * @author Hamish Friedlander <hamish@silverstripe.com>
 */
class SphinxSearchable extends DataObjectDecorator {

	/**
	 * Determines when indexes are updated. Possible values are:
	 *   - "endrequest"	- (default) Reindexing is done only once at the end of
	 *					the PHP request, and only if a write() or delete()
	 *					have been done (any op which flags the record dirty).
	 *					This eliminates unnecessary reindexing when decorators
	 *					perform additional writes on a data object.
	 *					If the messagequeue module is installed and
	 *					$reindex_queue is specified, a message is sent to
	 *					do the refresh to keep it out of the user process.
	 *					Otherwise it done in this process but at the end of
	 *					the PHP request (this will be noticable to the user)
	 *   - "write"		-	(old behaviour) Reindexing is done on write or delete.
	 *   - "disabled"	-	Reindexing is disabled, which is useful when writing many SphinxSearchable items (such as during a migration import)
	 *						where the burden of keeping the Sphinx index updated in realtime is both unneccesary and prohibitive.
	 * @var unknown_type
	 */
	static $reindex_mode = "endrequest";

	/**
	 * If $reindex_queue is "endrequest", and messagequeue module is installed,
	 * and this string is set, then the reindexing is performed by sending
	 * the reindex request as a message to this queue. This does not guarantee
	 * immediate execution, but it does ensure that the reindexing does not
	 * impact the users interaction. If the queue is configured to consume
	 * on shutdown, it will be done on php shutdown but in a separate process.
	 * @var String
	 */
	static $reindex_queue = "sphinx_indexing";

	static function set_indexing_mode($mode) {
		$old = self::$reindex_mode;

		self::$reindex_mode = $mode;
		if ($old == "disabled" && $mode != "disabled") singleton('Sphinx')->reindex(); // re-index now because we haven't been tracking dirty writes.
	}

	static function set_reindexing_queue($queue) {
		self::$reindex_queue = $queue;
	}

	/**
	 * When $reindex_mode is "endrequest", we build a list of deltas that require re-indexing when the request is shutdown.
	 * @var unknown_type
	 */
	static $reindex_deltas = array();

	/**
	 * When $reindex_mode is "endrequest", flags if we have registered the shutdown handler. Only want it once.
	 * @var unknown_type
	 */
	static $reindex_on_shutdown_flagged = false;

	/**
	 * When writing many SphinxSearchable items (such as during a migration import) the burden of keeping the Sphinx index updated in realtime is
	 * both unneccesary and prohibitive. You can temporarily disable indexed, and enable it again after the bulk write, using these two functions
	 */
	
//	static $reindex_on_write = true;
//	static function disable_indexing() {
//		self::$reindex_on_write = false;
//	}
	
//	static function reenable_indexing() {
//		self::$reindex_on_write = true;
//		// We haven't been tracking dirty writes, so the only way to ensure the results are up to date is a full reindex
//		singleton('Sphinx')->reindex();
//	}

	/**
	 * Returns a list of all classes that are SphinxSearchable
	 * @return array[string]
	 */
	static function decorated_classes() {
		return array_filter(ClassInfo::subclassesFor('DataObject'), create_function('$class', 'return Object::has_extension($class, "SphinxSearchable");'));
	}
	
	/**
	 * Add field to record whether this row is in primary index or delta index
	 */
	function extraStatics() {
		return array(
			'db' => array(
				'SphinxPrimaryIndexed' => 'Boolean'
			),
			'indexes' => array(
				'SphinxPrimaryIndexed' => true
			)
		);
	}

	protected $excludeByDefault;
	
	protected $searchedIndexes;

	function __construct($excludeByDefault = false) {
		parent::__construct();
		$this->excludeByDefault = $excludeByDefault;
	}
	
	/**
	 * Provide information about the Sphinx search that was used to query this object.
	 * 
	 * @param $searchedIndexes An array of the indexes that were searched in generating this search
	 * result.
	 */
	function setSphinxSearchHints($searchedIndexes) {
		$this->searchedIndexes = $searchedIndexes;
	}

	/**
	 * Find the 'Base ID' for this DataObject. The base id is a numeric ID that is unique to the group of DataObject classes that share a common base class (the
	 * class that immediately inherits from DataObject). This is used often in SphinxSearch as part of the globally unique document ID
	 */
	function sphinxBaseID() {
		return SphinxSearch::unsignedcrc(ClassInfo::baseDataClass($this->owner->class));
	}
	
	/**
	 * Find the 'Document ID' for this DataObject. The base id is a 64 bit numeric ID (represented by a BCD string in PHP) that is globally unique to this document
	 * across the entire database. It is formed from BaseID << 32 + DataObjectID, since DataObject IDs are unique within all subclasses of a common base class
	 */
	function sphinxDocumentID() {
		return SphinxSearch::combinedwords($this->sphinxBaseID(), $this->owner->ID);
	}
	
	/**
	 * Passes search through to SphinxSearch.
	 */
	function search() {
		$args = func_get_args();
		array_unshift($args, $this->owner->class);
		return call_user_func_array(array('SphinxSearch','search'), $args);
	}
	
	/**
	 * Mark this document as dirty in the main indexes by setting (overloaded) SphinxPrimaryIndexed to false
	 */
	function sphinxDirty() {
		$sing = singleton('Sphinx');
		$mains = array_filter($sing->getIndexesForClass($this->owner->class), create_function('$i', 'return !$i->isDelta;'));
		$names = array_map(create_function('$idx', 'return $idx->Name;'), $mains);
		
		$sing->connection()->UpdateAttributes(implode(';', $names), array("_dirty"), array($this->sphinxDocumentID() => array(1)));
	}
	
	/**
	 * Rebuild the sphinx indexes for all indexes that apply to this class (usually the ClassName + and variants)
	 */
	function reindex() {
		$sing = singleton('Sphinx');
		$deltas = array_filter($sing->getIndexesForClass($this->owner->class), create_function('$i', 'return $i->isDelta;'));
		$sing->reindex($deltas);
	}

	/**
	 * Get a snippet highlighting the search terms
	 * 
	 * @todo This is not super fast because of round trip latency. Sphinx supports passing more than one document at a time, but because we use heaps of indexes we can't really take
	 * advantage of that. Maybe we can fix that somehow?
	 */
	function buildExcerpt($terms, $field = 'Content', $opts = array()) {
		$sphinx = singleton('Sphinx');
		
		// Find the index to use for this excerpt
		$index = null;
		foreach(ClassInfo::ancestry($this->owner) as $candidate) {
			if(isset($this->searchedIndexes[$candidate])) {
				$index = $this->searchedIndexes[$candidate];
				break;
			}
		}

		if($index) {
			$fullContent = $this->owner->$field;
			
			$con = $sphinx->connection();
			$res = $con->BuildExcerpts(array($fullContent), $index, $terms, $opts);
			if($res === false) {
				user_error("Sphinx error when requesting excerpt: " . $con->GetLastError(), E_USER_NOTICE);
				return null;
			} else {
				return array_pop($res);
			}
		}
	}
	
	/*
	 * INTROSPECTION FUNCTIONS
	 * 
	 * Helper functions to allow SphinxSearch to introspect a DataObject to get the fields it should inject into sphinx.conf
	 *
	 * Returns an array mapping field name to (class [string], type [string], filterable [boolean], sortable [boolean], stringType[boolean]).
	 * If excludeByDefault is false, returns all fields in the dataobject and its ancestors.
	 * if excludeByDefault is true, returns all indexable fields in the ancestors, and fields explicitly defined
	 * in the class itself.
	 * @param string $class The class being introspected
	 * @param array $childconf The configuration array of the context that is wanting our fields, usually child classes because we start
	 *    at a child and recurse to the parents.
	 */
	function sphinxFields($class, $childconf = null) {
		if ($class == "DataObject") return array();

		$sing = singleton($class);
		$conf = $sing->stat('sphinx');
		if (!$conf) $conf = array();
		if (!isset($conf['search_fields'])) $conf['search_fields'] = array();
		if (!isset($conf['filter_fields']))	$conf['filter_fields'] = array();
		if (!isset($conf['sort_fields'])) $conf['sort_fields'] = array();
		if (!isset($conf['extra_fields'])) $conf['extra_fields'] = array();
		
		// merge our conf with what's passed in, being careful to explicitly merge the field lists
		if ($childconf) {
			if (isset($childconf["search_fields"])) $conf["search_fields"] = array_merge($conf["search_fields"], $childconf["search_fields"]);
			if (isset($childconf["filter_fields"]))$conf["filter_fields"] = array_merge($conf["filter_fields"], $childconf["filter_fields"]);
			if (isset($childconf["sort_fields"]))$conf["sort_fields"] = array_merge($conf["sort_fields"], $childconf["sort_fields"]);
			if (isset($childconf["extra_fields"]))$conf["extra_fields"] = array_merge($conf["extra_fields"], $childconf["extra_fields"]);
		}
		
		$ret = $this->sphinxFields($sing->parentClass(), $conf);

		// Grab fields. If the class descends DataObject, we want ClassName et al, otherwise just fields added by this class.
		$fields = (get_parent_class($class) == 'DataObject') ? DataObject::database_fields($class) : DataObject::custom_database_fields($class);

		if ($fields) foreach($fields as $name => $type) {
			if (preg_match('/^(\w+)\(/', $type, $match)) $type = $match[1];
			$stringType = ($type == 'Enum' || $type == 'Varchar' || $type == 'Text' || $type == 'HTMLVarchar' || $type == 'HTMLText');
			if ($this->excludeByDefault && $name != 'SphinxPrimaryIndexed') {
				$select = false;
				$filter = false;
				$sort = false;
				if (in_array($name, $conf['search_fields'])) $select = true;
				if (in_array($name, $conf['filter_fields'])) $filter = true;
				if (in_array($name, $conf['sort_fields'])) $sort = true;

				if ($sort) $filter = true;
				if ($filter) $select = true;

				if ($select) $ret[$name] = array($class, $type, $filter, $sort, $stringType, null);
			}
			else	
				$ret[$name] = array($class, $type, true, in_array($name, $conf['sort_fields']), $stringType, null);
		}

		// Add in any extra fields.
		// @TODO: because attributes must be ints, we assume the value is an int.
		foreach ($conf['extra_fields'] as $fieldName => $value) {
			if (strpos($value, "::") !== FALSE) $value = call_user_func($value);
			$ret[$fieldName] = array($class, 'Custom', true, true, false, $value);
		}
		SphinxVariants::alterSphinxFields($class, $ret);
		
		return $ret;
	}

	/**
	 * Return the field configuration required to produced indices.
	 * Returns an array:
	 *   'select' => array of $alias => $value pairs (in sql, written as 'value as alias')
	 *   'attributes' => array of $field => $type, where $type is the sphinx type name (e.g. boolean, uint)
	 *   'where' => a SQL where clause for filtering the index
	 * @return unknown_type
	 */
	function sphinxFieldConfig() {
		$base = ClassInfo::baseDataClass($this->owner->class);
		$baseid = SphinxSearch::unsignedcrc($base);
		$classid = SphinxSearch::unsignedcrc($this->owner->class);
		
		$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";
		
		$select = array(
			// Select the 64 bit combination baseid << 32 | itemid as the document ID
			"id" => "($baseid<<32)|{$bt}$base{$bt}.{$bt}ID{$bt}", 
			// And select each value individually for filtering and easy access 
			"_id" => "{$bt}$base{$bt}.{$bt}ID{$bt}",
			"_baseid" => $baseid,
			"_classid" => $classid,
			"_dirty" => "0"
		);
		$attributes = array(
			"_id" => "uint",
			"_baseid" => "uint",
			"_classid" => "uint",
			"_dirty" => "bool"
		);

		foreach($this->sphinxFields($this->owner->class) as $name => $info) {
			list($class, $type, $filter, $sortable, $stringType, $value) = $info;
			
			switch ($type) {
				case 'Enum':
				case 'Varchar':
				case 'Text':
				case 'HTMLVarchar':
				case 'HTMLText':
					$db = DB::getConn();
					$select[$name] = "{$bt}$class{$bt}.{$bt}$name{$bt}";

					// If the field is sortable, we generate an extra column of the 1st four chars packed to assist in
					// sorting, since sphinx doesn't directly allow sorting by strings.
					if ($sortable && !($db instanceof SQLite3Database || $db instanceof SQLitePDODatabase)) {
						$select["_packed_$name"] = "(ascii(substr({$bt}$class{$bt}.{$bt}$name{$bt},1,1)) << 24) | (ascii(substr({$bt}$class{$bt}.{$bt}$name{$bt},2,1)) << 16) | (ascii(substr({$bt}$class{$bt}.{$bt}$name{$bt},3,1)) << 8) | ascii(substr({$bt}$class{$bt}.{$bt}$name{$bt},4,1))";
						$attributes["_packed_$name"] = "uint";
					}
					break;

				case 'Boolean':
					$select[$name] = "{$bt}$class{$bt}.{$bt}$name{$bt}";
					if ($filter) $attributes[$name] = "bool";
					break;

				case 'Date':
				case 'SSDatetime':
				case 'SS_Datetime':
					$db = DB::getConn();
					if ($db instanceof MySQLDatabase) $select[$name] = "UNIX_TIMESTAMP({$bt}$class{$bt}.{$bt}$name{$bt})";
					else if ($db instanceof PostgreSQLDatabase) $select[$name] = "date_part('epoch', \"timestamp\"({$bt}$class{$bt}.{$bt}$name{$bt}))";
					else if ($db instanceof SQLite3Database) $select[$name] = "strftime(\"%s\", {$bt}$class{$bt}.{$bt}$name{$bt})";
					else user_error("Sphinx module does not currently support timestamps for this database platform");
					if ($filter) $attributes[$name] = "timestamp";
					break;

				case 'ForeignKey':
				case 'Int':
					$select[$name] = "{$bt}$class{$bt}.{$bt}$name{$bt}";
					if ($filter) $attributes[$name] = "uint";
					break;
					
				case 'CRCOrdinal':
					$select[$name] = "CRC32({$bt}$class{$bt}.{$bt}$name{$bt})";
					if ($filter) $attributes[$name] = "uint";
					break;	

				case 'Custom':
					$select[$name] = "$value";
					if ($filter) $attributes[$name] = "uint";
				default:
			}
		}

		// Extra index_filter and pass as the where clause of the filter
		$conf = $this->owner->stat('sphinx');
		$indexFilter = empty($conf['index_filter']) ? null : $conf['index_filter'];

		return array(
			'select' => $select, 
			'attributes' => $attributes, 
			'where' => $indexFilter
		);
	}

	/**
	 * Return an array of has-many relationship. It's an array of $field => $query.
	 * @return unknown_type
	 */
	function sphinxHasManyAttributes() {
		$attributes = array();
		$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";
		
		foreach (ClassInfo::ancestry($this->owner->class) as $class) {
			$has_many = Object::uninherited_static($class, 'has_many');
			if ($has_many) foreach($has_many as $name => $refclass) {
				
				$qry = $this->owner->getComponentsQuery($name);
				$cid = $this->owner->getComponentJoinField($name);
				
				$reftables = ClassInfo::ancestry($refclass,true); $reftable = array_pop($reftables);

				$qry->select(array("{$bt}$reftable{$bt}.{$bt}$cid{$bt} AS id", "{$bt}$reftable{$bt}.{$bt}ID{$bt} AS $name"));
				$qry->where = array();
				singleton($refclass)->extend('augmentSQL', $qry);
				
				$attributes[] = "sql_attr_multi = uint $name from query; " . $qry;
			}
		}
		
		return $attributes;
	}

	/**
	 * Return an array of many to many relationships. It's an array of $field => $query
	 * @return unknown_type
	 */
	function sphinxManyManyAttributes() {
		$attributes = array();
		$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";
		
		$base = ClassInfo::baseDataClass($this->owner->class);
		$baseid = SphinxSearch::unsignedcrc($base);
		
		$conf = $this->owner->stat('sphinx');
		if (isset($conf['filterable_many_many'])) {
			// Build an array with the keys being the many_manys to include as attributes
			$many_manys = $conf['filterable_many_many'];
			if     (is_string($many_manys) && $many_manys != '*') $many_manys = array($many_manys => $many_manys);
			elseif (is_array($many_manys))                        $many_manys = array_combine($many_manys, $many_manys);

			// grab many_many and belongs_many_many
			$many_many = $this->owner->many_many();
			if ($many_manys != '*') $many_many = array_intersect_key($many_many, $many_manys); // Filter to only include specified many_manys
			if ($many_many) foreach ($many_many as $name => $refclass) {
				list($parentClass, $componentClass, $parentField, $componentField, $table) = $this->owner->many_many($name);
				$componentBaseClass = ClassInfo::baseDataClass($componentClass);
	
				$qry = singleton($componentClass)->extendedSQL(array('true'), null, null, "INNER JOIN {$bt}$table{$bt} ON {$bt}$table{$bt}.$componentField = {$bt}$componentBaseClass{$bt}.ID" );
				$qry->select(array("($baseid<<32)|{$bt}$table{$bt}.{$bt}$parentField{$bt} AS id", "{$bt}$table{$bt}.{$bt}$componentField{$bt} AS $name"));
				$qry->groupby = array();

				$attributes[$name] = $qry;
			}
		}

		if (isset($conf['extra_many_many'])) {
			foreach ($conf['extra_many_many'] as $key => $value) $attributes[$key] = $value;
		}
		
		return $attributes;
	}
	
	/*
	 * HOOK FUNCTIONS
	 * 
	 * Functions to connect regular silverstripe operations with sphinx operations, to maintain syncronisation
	 */

	/**
	 * Return the base class that has the SphinxPrimaryIndexed field. This
	 * assumes a single reading stage, so shouldn't be used in augmentWrite
	 * even though the code is very similar
	 * @return String
	 */
	private function getSPITable() {
		$fields = null;
		foreach (ClassInfo::ancestry($this->owner->class, true) as $class) {
			$fields = DataObject::database_fields($class);
			if (isset($fields['SphinxPrimaryIndexed'])) break;
		}

		if ( !isset($fields['SphinxPrimaryIndexed']) ) {
			// this should not happen, as the SphinxPrimaryIndexed is included in this decorator
			user_error("Fatal: Could not establish host ancestor for class {$this->owner->class}", E_USER_ERROR);
		}

		$live = Versioned::current_stage() == 'Live';
		// If we are versioned, choose the correct table (draft or live)
		if (singleton($class)->hasExtension('Versioned')) $class = $class . ($live ? '_'.Versioned::get_live_stage() : '');

		return $class;
	}

	// Make sure that SphinxPrimaryIndexed gets set to false, so this record is picked up on delta reindex. This gets called after
	// versioned manipulates the write, so tables may be live or stage.
	// @TODO: Generalise augmentWrite to call augment method on the enabled
	//        variants, move all this logic into the delta variant.
	public function augmentWrite(&$manipulation) {
		$live = false;
		
		foreach (ClassInfo::ancestry($this->owner->class, true) as $class) {
			if (isset($manipulation[$class.'_'.Versioned::get_live_stage()])) $live = true;
			$fields = DataObject::database_fields($class);
			if (isset($fields['SphinxPrimaryIndexed'])) break;
		}

		// If we are versioned, choose the correct table (draft or live)
		if (singleton($class)->hasExtension('Versioned')) $class = $class . ($live ? '_'.Versioned::get_live_stage() : '');
		
		$manipulation[$class]['fields']['SphinxPrimaryIndexed'] = 0;
	}

	// Re-index the data object. This can be a result of a DataObject->write, but
	// it can be called in other circumstances. In particular, manually managed
	// many-to-many relationships require that both related DataObjects are
	// re-indexed when the relationship changes, so this must be called on both.
	function forceReindex() {
		if (self::$reindex_mode == "disabled") return;
		$this->sphinxDirty();

		// We have to do this explicitly. For an indexed DO, this will happen in
		// augment write, but if a relationship changes this doesn't otherwise
		// get done.
		$class = $this->getSPITable();
		$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";
		DB::query("UPDATE {$bt}$class{$bt} SET {$bt}$class{$bt}.{$bt}SphinxPrimaryIndexed{$bt} = false WHERE {$bt}$class{$bt}.{$bt}ID{$bt}={$this->owner->ID}");

		if (self::$reindex_mode == "write") $this->reindex();
		else $this->reindexOnEndRequest();
	}

	// After delete, mark as dirty in main index (so only results from delta
	// index will count), then update the delta index
	function onAfterWrite() {
		if (self::$reindex_mode == "disabled") return;
		$this->sphinxDirty();
		if (self::$reindex_mode == "write") $this->reindex();
		else $this->reindexOnEndRequest();
	}

	// After delete, mark as dirty in main index (so only results from delta
	// index will count), then update the delta index
	function onAfterDelete() {
		if (self::$reindex_mode == "disabled") return;
		$this->sphinxDirty();
		if (self::$reindex_mode == "write") $this->reindex();
		else $this->reindexOnEndRequest();
	}

	protected static $queued_reindexes = array();

	/**
	 * Flag that reindexing is required for delta indexes for the class of this
	 * object. Re-indexing on shutdown is flagged, and we ensure that the delta
	 * indexes for this object are in the list of what's to be reindexed.
	 * 
	 * @return unknown_type
	 */
	function reindexOnEndRequest() {
		// Work out the deltas that need to be reindexed. If multiple writes
		// have been done to objects in the same delta, we'll get the same
		// delta more than once. This logic ensures we only reindex each delta
		// once.
		$sing = singleton('Sphinx');
		$deltas = array_filter($sing->getIndexesForClass($this->owner->class), create_function('$i', 'return $i->isDelta;')); // just deltas
		$deltas = array_map(create_function('$idx', 'return $idx->Name;'), $deltas); // and just names please
		foreach ($deltas as $d) if (!in_array($d, self::$reindex_deltas)) self::$reindex_deltas[] = $d;

		if (class_exists("MessageQueue") && self::$reindex_queue) {
			// Hack alert. We can't send from the PHP shutdown function, but
			// we want to make sure we don't send the same request again.
			// So we record the unique re-index parameters we've sent so
			// far, and only re-issue them if they haven't been sent already.
			$hash = implode(",", self::$reindex_deltas);
			if (!in_array($hash, self::$queued_reindexes)) {
				self::$queued_reindexes[] = $hash;
				MessageQueue::send(self::$reindex_queue, new MethodInvocationMessage("SphinxSearchable", "reindexDeltas", self::$reindex_deltas));
			}
		}
		else {
			// Make sure we only do the shutdown call once
			if (!self::$reindex_on_shutdown_flagged) register_shutdown_function(array("SphinxSearchable", "reindexOnShutdown"));
			self::$reindex_on_shutdown_flagged = true;
		}
	}

	/**
	 * Do the reindexing, either via the message queue, or directly if we're not
	 * using it.
	 */
	static function reindexOnShutdown() {
		self::reindexDeltas(self::$reindex_deltas);
	}

	/**
	 * Reindex the specified $deltas.
	 * Note: this is used by reindexOnShutdown and is also called from the
	 * message queue when that is being used. So don't merge reindexOnShutdown
	 * and this function. Also note this function can be executed completely
	 * out of the normal execution path if executed by messaging that is
	 * using a cron job, for example.
	 */
	static function reindexDeltas($deltas) {
		$sing = singleton('Sphinx');
		$sing->reindex($deltas);
	}

	/**
	 * Helper method provided for callers that change a many-many relationship that is indexed, since the write() chain
	 * won't detect this case. Basically flag it dirty and re-index into the delta.
	 * @return unknown_type
	 */
	function sphinxComponentsChanged() {
		$this->sphinxDirty();
		$this->reindex();
	}

	/*
	 * This uses a function called only on dev/build construction to patch in also calling Sphinx::configure when dev/build is called
	 */
	static $sphinx_configure_called = false;
	function requireDefaultRecords() {
		if (self::$sphinx_configure_called) return;
		
		singleton('Sphinx')->check();
		singleton('Sphinx')->configure();
		singleton('Sphinx')->reindex();
		self::$sphinx_configure_called = true;
	}
}
