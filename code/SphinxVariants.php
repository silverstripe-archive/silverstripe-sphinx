<?php

/**
 * There are several decorators in SilverStripe that alter SQL depending on various conditions. Because Sphinx's database is precalculated,
 * the returned values are not affected by these functions
 * 
 * In order to provide the correct results we need to alter the search indexes and terms in the same way as alterSQL alters the regular SQL.
 * 
 * To achieve this, an interface with two functions is provided, which any class can implement, allowing the altering of indexes and search
 * terms.
 * 
 * See the included SphinxVariant_Versioned and SphinxVariant_Subsite for examples of it's use
 * 
 * @author Hamish Friedlander <hamish@silverstripe.com>
 * 
 */
class SphinxVariants extends Object {
	
	static $variant_handlers = null;
	
	/**
	 * Gets all variant handlers as an array of shortName=>className pairs
	 * @return array
	 */
	static function variant_handlers() {
		if (!self::$variant_handlers) {
			self::$variant_handlers = array();
			foreach (ClassInfo::implementorsOf('SphinxVariant') as $variantclass) {
				self::$variant_handlers[Object::get_static($variantclass,'name')] = $variantclass;
			}
		}
		return self::$variant_handlers;
	}
	
	/**
	 * Gets a specific variant handler class name from a short name
	 * @param $name string - The short name of a variant handler
	 * @return string - The class name of a variant handler
	 */
	static function variant_handler($name) {
		self::variant_handlers();
		return self::$variant_handlers[$name];
	}
	
	/**
	 * Alter the indexes that Sphinx should build, based on some variant-specific logic
	 * @param $class string - The class name of the DataObject we're building indexes for
	 * @param $indexes array - An array of Sphinx_Index objects
	 * @return none
	 */
	static function alterIndexes($class, &$indexes) {
		foreach (self::variant_handlers() as $variantclass) singleton($variantclass)->alterIndexes($class, $indexes);
	}
	
	/**
	 * Alter the search terms that SphinxSearch will use, based on some variant-specific logic
	 * @param $indexes array - A set of className => indexName pairs
	 * @param $search - An array of options as passed to SphinxSearch#search
	 * @return none
	 */
	static function alterSearch(&$indexes, &$search) {
		foreach (self::variant_handlers() as $variantclass) singleton($variantclass)->alterSearch($indexes, $search);
	}
}

interface SphinxVariant {
	function alterIndexes($class, &$indexes);
	function alterSearch(&$indexes, &$search);
}

class SphinxVariant_Versioned extends Object implements SphinxVariant {
	static $name = 'Ver';
	
	function alterIndexes($class, &$indexes) {
		if (!singleton($class)->hasExtension('Versioned')) return;
		
		$old_stage = Versioned::current_stage();
		Versioned::reading_stage('Live');
		
		$idx = new Sphinx_Index($class);
		$idx->Name = $idx->Name . 'Live';
		$idx->Sources[0]->Name = $idx->Sources[0]->Name . 'Live';
		$idx->baseTable = $idx->baseTable . '_Live';
		
		$indexes[] = $idx;
		
		Versioned::reading_stage($old_stage);
	}
	
	function alterSearch(&$indexes, &$search) {
		$version = isset($search['version']) ? $search['version'] : Versioned::current_stage();
		if ($version == 'Live') {
			foreach ($indexes as $class => &$index) {
				if (singleton($class)->hasExtension('Versioned')) $index = $index . 'Live';
			}
			unset($index);
		}
	}
}

class SphinxVariant_Subsite extends Object implements SphinxVariant {
	static $name = 'Sub';
	
	function alterIndexes($class, &$indexes) {
		foreach ($indexes as $index) {
			$index->Sources[0]->qry->where = array_filter(
				$index->Sources[0]->qry->where, 
				create_function('$str', 'return strpos($str, "SubsiteID") === false;')
			);
		}
	}
	
	function alterSearch(&$indexes, &$search) {
		if (!class_exists('Subsite') || Subsite::$disable_subsite_filter || (@$search['subsite']) == 'all') return;
		
		if     (isset($search['subsite']))            $subsiteID = $search['subsite'];
		elseif ($context = DataObject::context_obj()) $subsiteID = $context->SubsiteID;
		else                                          $subsiteID = Subsite::currentSubsiteID();
		
		$seen = array();
		
		foreach ($indexes as $class => $index) {
			$base = ClassInfo::baseDataClass($class);
			if (isset($seen[$base])) continue;
			$seen[$base] = $base;
			
			if     (Object::has_extension($base, 'SiteTreeSubsites')) $test = "SubsiteID = $subsiteID";
			elseif (Object::has_extension($base, 'GroupSubsites')) $test = "SubsiteID = 0 OR SubsiteID = $subsiteID";
			elseif (Object::has_extension($base, 'FileSubsites')) $test = "SubsiteID = 0 OR SubsiteID = $subsiteID";
			else   continue;
			
			$baseid = SphinxSearch::unsignedcrc($base);
			
			$search['query'][] = "IF (_baseid = $baseid AND NOT($test), 1, 0) AS {$base}SubsiteDoesntMatch";
			$search['exclude']["{$base}SubsiteDoesntMatch"] = 1;
		}
	}
}

class SphinxVariant_Delta extends Object implements SphinxVariant {
	static $name = 'Delta';
	
	function alterIndexes($class, &$indexes) {
		foreach ($indexes as $index) {
			$flagTable = $index->baseTable;
		
			// Build the delta index
			$deltaIndex = clone $index;
			$deltaIndex->Name = $index->Name . 'Delta';
			$deltaIndex->isDelta = true;
			$deltaIndex->Sources[0] = clone $index->Sources[0];
			$deltaIndex->Sources[0]->Name = $index->Sources[0]->Name . 'Delta';
			$deltaIndex->Sources[0]->qry = clone $index->Sources[0]->qry;
			
			// Set main index's source to update the flag.
			$inst = singleton($class);
			$base = $inst->baseTable();

			$join = $flagTable == $base ? "" : "LEFT JOIN `$base` on $flagTable.ID=$base.ID";
			$index->Sources[0]->prequery = "UPDATE $flagTable $join SET SphinxPrimaryIndexed = true WHERE " . $index->Sources[0]->qry->getFilter();

			// Set delta index's source to only collect items not yet in main index
			$deltaIndex->Sources[0]->qry->where($flagTable . '.SphinxPrimaryIndexed = false');
			
			$deltas[] = $deltaIndex;
		}
		
		foreach ($deltas as $index) $indexes[] = $index;
	}
	
	function alterSearch(&$indexes, &$search) {
		foreach ($indexes as $index) $more[] = $index . 'Delta';
		foreach ($more as $index) $indexes[] = $index;
		$search['exclude']["_dirty"] = 1;
	}
}

