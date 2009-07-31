<?php

/**
 * A decorator, which needs to be attached to every DataObject that you wish to search with Sphinx.
 * 
 * Provides a few end developer useful methods, like search, but mostly is here to provide two internal facilities
 *  - Provides introspection methods, for getting the fields and relationships of an object in Sphinx-compatible form
 *  - Provides hooks into writing, deleting and schema rebuilds, so we can reindex & rebuild the Sphinx configuration automatically 
 * 
 * Note: Children DataObject's inherit their Parent's Decorators - there is no need to at this extension to BlogEntry if SiteTree already has it, and doing so will probably cause issues.
 * 
 * @author Hamish Friedlander <hamish@silverstripe.com>
 */
class SphinxSearchable extends DataObjectDecorator {
	
	static function decorated_classes() {
		return array_filter(ClassInfo::subclassesFor('DataObject'), create_function('$class', 'return Object::has_extension($class, "SphinxSearchable");'));
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
	 * Rebuild the sphinx indexes for all indexes that apply to this class (usually the ClassName + and variants)
	 */
	function reindex() {
		$sing = singleton('Sphinx');
		$sing->reindex($sing->indexes($this->owner->class));
	}
	
	/**
	 * Get a snippet highlighting the search terms
	 * 
	 * @todo This is not super fast because of round trip latency. Sphinx supports passing more than one document at a time, but because we use heaps of indexes we can't really take
	 * advantage of that. Maybe we can fix that somehow?
	 */
	function buildExcerpt($terms, $field = 'Content', $opts = array()) {
		$con = singleton('Sphinx')->connection();
		$res = $con->BuildExcerpts(array($this->owner->$field), $this->owner->class, $terms, $opts);
		return array_pop($res);
	}
	
	/*
	 * INTROSPECTION FUNCTIONS
	 * 
	 * Helper functions to allow SphinxSearch to introspect a DataObject to get the fields it should inject into sphinx.conf
	 */
	
	function sphinxFields() {
		$ret = array();
		
		foreach (ClassInfo::ancestry($this->owner->class, true) as $class) {
			$fields = DataObject::database_fields($class);
			$conf = $this->owner->stat('sphinx');
			
			$fieldOverrides = ($conf && isset($conf['fields'])) ? $conf['fields'] : array();
			
			if ($fields) foreach($fields as $name => $type) {
				if     (isset($fieldOverrides[$name]))           $type = $fieldOverrides[$name];
				elseif (preg_match('/^(\w+)\(/', $type, $match)) $type = $match[1];
				
				$ret[$name] = array($class, $type);
			}
		}
		
		return $ret;
	}
	
	function sphinxFieldConfig() {
		$base = ClassInfo::baseDataClass($this->owner->class);
		$baseid = SphinxSearch::unsignedcrc($base);
		$classid = SphinxSearch::unsignedcrc($this->owner->class);
		
		$select = array(
			// Select the 64 bit combination baseid << 32 | itemid as the document ID
			"($baseid<<32)|`$base`.`ID` AS id", 
			// And select each value individually for filtering and easy access 
			"`$base`.ID AS _id",
			"$baseid AS _baseid",
			"$classid AS _classid"
		); 
		$attributes = array('sql_attr_uint = _id', 'sql_attr_uint = _baseid', 'sql_attr_uint = _classid');
				
		foreach($this->sphinxFields() as $name => $info) {
			list($class, $type) = $info;
			
			switch ($type) {
				case 'Varchar':
				case 'Text':
				case 'HTMLVarchar':
				case 'HTMLText':
					$select[] = "`$class`.`$name` AS $name";
					break;
					
				case 'Boolean':
					$select[] = "`$class`.`$name` AS $name";
					$attributes[] = "sql_attr_bool = $name";
					break;

				case 'Date':
				case 'SSDatetime':
					$select[] = "UNIX_TIMESTAMP(`$class`.`$name`) AS $name";
					$attributes[] = "sql_attr_timestamp = $name";
					break;

				case 'ForeignKey':
					$select[] = "`$class`.`$name` AS $name";
					$attributes[] = "sql_attr_uint = $name";
					break;
					
				case 'CRCOrdinal':
					$select[] = "CRC32(`$class`.`$name`) AS $name";
					$attributes[] = "sql_attr_uint = $name";
					break;						
			}
		}
		
		return array('select' => $select, 'attributes' => $attributes);
	}
	
	function sphinxHasManyAttributes() {
		$attributes = array();
		
		foreach (ClassInfo::ancestry($this->owner->class) as $class) {
			$has_many = Object::uninherited_static($class, 'has_many');
			if ($has_many) foreach($has_many as $name => $refclass) {
				
				$qry = $this->owner->getComponentsQuery($name);
				$cid = $this->owner->getComponentJoinField($name);
				
				$reftables = ClassInfo::ancestry($refclass,true); $reftable = array_pop($reftables);

				$qry->select(array("`$reftable`.`$cid` AS id", "`$reftable`.`ID` AS $name"));
				$qry->where = array();
				singleton($refclass)->extend('augmentSQL', $qry);
				
				$attributes[] = "sql_attr_multi = uint $name from query; " . $qry;
			}
		}
		
		return $attributes;
	}
	
	function sphinxManyManyAttributes() {
		$attributes = array();
		
		$base = ClassInfo::baseDataClass($this->owner->class);
		$baseid = SphinxSearch::unsignedcrc($base);
		
		$conf = $this->owner->stat('sphinx');
		if (!isset($conf['filterable_many_many'])) return $attributes;

		// Build an array with the keys being the many_manys to include as attributes
		$many_manys = $conf['filterable_many_many'];
		if     (is_string($many_manys) && $many_manys != '*') $many_manys = array($many_manys => $many_manys);
		elseif (is_array($many_manys))                        $many_manys = array_combine($many_manys, $many_manys);
		
		foreach (ClassInfo::ancestry($this->owner->class) as $class) {
			$many_many = (array) Object::uninherited_static($class, 'many_many');
			if ($many_manys != '*') $many_many = array_intersect_key($many_many, $many_manys); // Filter to only include specified many_manys
			
			if ($many_many) foreach ($many_many as $name => $refclass) {
				list($parentClass, $componentClass, $parentField, $componentField, $table) = $this->owner->many_many($name);
				$componentBaseClass = ClassInfo::baseDataClass($componentClass);
		
				$qry = singleton($componentClass)->extendedSQL(array('true'), null, null, "INNER JOIN `$table` ON `$table`.$componentField = `$componentBaseClass`.ID" );
				$qry->select(array("($baseid<<32)|`$table`.`$parentField` AS id", "`$table`.`$componentField` AS $name"));
				$qry->groupby = array();
				
				$attributes[] = "sql_attr_multi = uint $name from query; " . $qry;
			}
		}
		
		return $attributes;
	}
	
	/*
	 * HOOK FUNCTIONS
	 * 
	 * Functions to connect regular silverstripe operations with sphinx operations, to maintain syncronisation
	 */
	
	
	function onAfterWrite() {
		$this->reindex();
	}
	
	function onAfterDelete() {
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
