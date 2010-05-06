<?php

/**
 * Houses the primary end-developer interface
 */
class SphinxSearch extends Object {

	/**
	 * Trigger a user warning on a search engine warning? If false just returns the warning in SphinxSearch::search's result object 
	 * Note that although this generates E_USER_WARNING, this will still cause SilverStripe to stop processing and display an error message
	 */
	static $search_warning_generates_user_warning = false;
	
	/**
	 * Trigger a user error on a search engine error? If false just returns the error in SphinxSearch::search's result object 
	 */
	static $search_error_generates_user_error = true;
	
	/**
	 * Get the CRC as a positive integer-string 
	 */
	static function unsignedcrc($what) {
		$val = crc32($what);
		if (PHP_INT_SIZE>=8) $val = ($val<0) ? $val+(1<<32) : $val;
		else                 $val = sprintf("%u", $val);
		
		return ''.$val;
	}
	
	/**
	 * Takes two 32 bit integers, and returns the string that is the BCD representation of the 64 number that is equal to $hi << 32 + $lo
	 */
	static function combinedwords($hi, $lo) {
		// x32, no-bcmath
		$hi = (float)$hi;
		$lo = (float)$lo;
		
		$q = floor($hi/10000000.0);
		$r = $hi - $q*10000000.0;
		$m = $lo + $r*4967296.0;
		$mq = floor($m/10000000.0);
		$l = $m - $mq*10000000.0;
		$h = $q*4294967296.0 + $r*429.0 + $mq;
	
		$h = sprintf ( "%.0f", $h );
		$l = sprintf ( "%07.0f", $l );
		return $h == "0" ? sprintf("%.0f", (float)$l) : ($h . $l) ;
	}

	/**
	 * Primary search interface for Sphinx. Pass in some arguments, get out a DataObject with the matches and maybe some search suggestions
	 * @param $classes array | string - A list of classes (must be SphinxSearchable) to search in. By default will also search children of these classes
	 * @param $qry string - A query as defined by Sphinx's EXTENDED2 syntax
	 * @param $args array - Keyed options as an array. Can include
	 * 
	 *   require array - A list of attribute => array( value ) to require
	 *   exclude array - A list of attribute => array( value ) to exclude
	 *   start int - A record idx (0 based) to start at. Takes priority over page if set - default 0
	 *   page int - A page (0 based) to return - default 0
	 *   pagesize int - The length of a page in records - default 10
	 *   include_child_classes bool - Search children of $classes as well as classes - default true 
	 *   query array - A list of select attributes include in the result - mostly internal, as not actually returned at this stage (see http://www.sphinxsearch.com/docs/current.html#api-func-setselect)
	 *   sortmode string - one of:
	 *   	"relevance" [default]		Uses the default sphinx sort by relevance
	 *      "fields"					Sort by a set of fields (like an order by clause) with fields supplied in sortarg. Internally
	 *      							uses sphinx extended sort.
	 *      "eval"						Pass an expression to sort on, corresponds directly to sphinx eval mode, so see the documentation.
	 *   sortarg mixed - sort arguments, value depends on sortmode:
	 *      - when sortmode is "relevance", this is ignored
	 *      - when sortmode is "fields", this can be a string containing a single field to sort ascending, or can be an array with
	 *        field names as keys and "asc" or "desc" as value.
	 *      - when sortmode is "eval" this should be a string, and is passed straight to sphinx.
	 * 
	 * Additionally, SphinxVariants may understand other options. These are understood out of the box (only apply to DataObjects with these decorators, of course):
	 *   
	 *   variant string - 'Staged' or 'Live' - Overrides Variant::$reading_stage if present (SphinxVariants_Versioned)
	 *   subsite string - 'all' or a SubsiteID - Overrides Subsite::currentSubsiteID() if preset (SphinxVariants_Subsite)
	 * 
	 * @return DataObject with these properties
	 * 
	 *   Matches - An iterator containing the records that matched
	 *   Suggestion - If less then 10 matches, a suggestion for an alternative search (Do you mean...?), with these properties
	 *     total - Number of results in the alternative search
	 *     scqry - The plain-text alternative query
	 *     html - An html version of the alternative query, with the changed terms bolded
	 * 
	 */
	static function search($classes, $qry, $args = array()) {
		if (!is_array($classes)) $classes = array($classes);
		
		$args = array_merge(array(
			'query' => array(),
			'require' => array(),
			'exclude' => array(),
			'start' => 0,
			'page' => 0,
			'pagesize' => 10,
			'suggestions' => true,
			'include_child_classes' => true,
			'sortmode' => 'relevance',
			'sortarg' => null
		),$args);

		/* If we want to search children, add the child classes to the list of classes to search */
		if ($args['include_child_classes']) {
			$children = array();
			foreach ($classes as $class) $children = array_merge($children, ClassInfo::subclassesFor($class));
			$classes = array_unique($children);
		}

		// OK, work out what the indexes are for this collection of classes.
		$sphinx = singleton('Sphinx');
		$indexes = array();
		foreach ($sphinx->indexes($indexes) as $i) if (in_array($i->BaseClass, $classes)) $indexes[] = $i->BaseClass;
		$indexes = array_unique($indexes);

		/* Build an array of $class => $index pairs */
		$indexes = array_combine($indexes, $indexes);
		
		/* Allow variants to alter search */
		SphinxVariants::alterSearch($indexes, $args);
		
		/* Make a map to convert CRC32 encoded class IDs back to class names */
		$classmap = array();
		foreach ($classes as $class) $classmap[self::unsignedcrc($class)] = $class;
		/* Get connection */
		$con = $sphinx->connection();
		$con->SetMatchMode(SPH_MATCH_EXTENDED2);

		$packedSort = false;
		$sortSelectFields = array(); // extra fields for the select field list, to support sort

		if ($args['sortmode'] != 'relevance') {
			if (!$args['sortarg']) user_error("Sort arguments must be provided");

			if ($args['sortmode'] == 'eval') $con->SetSortMode("eval", $args['sortarg']);
			else {
				// Must be fields. There are two things we need to ensure for each field:
				// 1. if the field has been marked as an orderable field, then we need to use the packed version of the field.
				// 2. we need to create aliases for the fields we sort on and ensure they are in the order by clause.

				// Normalise
				if (is_array($args['sortarg'])) $fields = $args['sortarg'];
				else if (is_string($args['sortarg'])) $args['sortarg'] = $fields = array($args['sortarg'] => "asc");
				else user_error("Invalid sort argument");

				$aliasCount = 0;
				$sortFields = array(); // aggregate of all sort details for packedSortRefineResults
				$sortClauses = array(); // build the order by clause fields

				foreach ($fields as $f => $dir) {
					$packed = false;
					$alias = "__sort_" . ++$aliasCount;
					$actualSortField = $f;

					// Determine if this field is to be packed or not. It will be packed if any of the classes define this
					// field as sortable and its a string type.
					// Also, if one class defines it as string and another a non-string, it will still end up packed. If the caller
					// doesn't like it, they should change their table structure.
					foreach ($classes as $class) {
						$instfields = singleton($class)->sphinxFields($class);
						if ($instfields && isset($instfields[$f])) {
							list($class, $type, $filter, $sortable, $stringType) = $instfields[$f];
							if ($sortable && $stringType) {
								$packed = true;
								break;
							}
						}
					}

					if ($packed) $actualSortField = "_packed_" . $actualSortField;
					if (substr($actualSortField, 0, 1) == '@')  $sortClauses[] = $actualSortField . " " . $dir; // sphinx builtin
					else {
						$sortSelectFields[] = $actualSortField . " as " . $alias;
						$sortClauses[] = $alias . " " . $dir;
					}
					$sortFields[$f] = array(
						"dir" => $dir,
						"alias" => $alias,
						"packed" => $packed,
						"packedfield" => $actualSortField
					);
					$packedSort |= $packed;
				}
				$con->SetSortMode(SPH_SORT_EXTENDED, implode(",", $sortClauses));
			}
		}

		//echo "Query: $qry, Indexes (".count($indexes).") ".join(',',$indexes)."<br />\n";
		if (count($indexes) > 99) singleton('Sphinx')->trimIndexes($indexes);
		if (count($indexes) > 75) $indexes = array_slice($indexes, 0, 75);
		//echo "Query: $qry, Indexes (".count($indexes).") ".join(',',$indexes)."<br />\n";

		/* Set filters */
		foreach (array('require' => false, 'exclude' => true) as $key => $exclude) {
			foreach($args[$key] as $attr => $values) {
				if (!is_array($values)) $values = array($values);
				
				foreach ($values as &$value) {
					if (!is_numeric($value)) $value = self::unsignedcrc($value);
				}
				unset($value);
				$con->SetFilter($attr, $values, $exclude);
			}
		}

		/* Set select */
		$query = array_merge(array('_id', '_baseid', '_classid'), $args['query']);
		$query = array_merge($query, $sortSelectFields);
		$con->SetSelect(implode(', ',$query));
		
		/* Set limits */
		$start = $args['start'] ? $args['start'] : $args['page'] * $args['pagesize'];
		$con->SetLimits($start, $args['pagesize']);
		
		/* Do the search */
		$res = $con->Query($qry, implode(';', $indexes));

		/* Build the return values */
		$results = array();
		if (isset($res['matches'])) foreach ($res['matches'] as $bigid => $info) {
			$id = $info['attrs']['_id'];
			$class = $classmap[$info['attrs']['_classid']];
			
			$result = DataObject::get_by_id($class, $id);
			if ($result) {
				$result->setSphinxSearchHints($indexes);
				$results[] = $result;
			}
		}

		if ($packedSort) self::packedSortRefineResult($results, $res, $classes, $qry, $sortFields, $classmap, $indexes);

		$ret = array();
		$ret['Matches'] = new DataObjectSet($results);
		$ret['Matches']->setPageLimits($start, $args['pagesize'], $res['total']);

		/* Pull any warnings or errors through to the returned data object */
		if ($err = $con->getLastWarning()) $ret['Warning'] = $err;
		if ($err && self::$search_warning_generates_user_warning) user_error($err, E_USER_WARNING);
		
		if ($err = $con->getLastError()) $ret['Error'] = $err;
		if ($err && self::$search_error_generates_user_error) user_error($err, E_USER_ERROR);
		
		/* If we didn't get that many matches, try looking through all possible spelling corrections to find the one that returns the most matches */
		if ($args['suggestions'] && $res['total'] < 10) {
			$ret['Suggestion'] = self::findSpellingSuggestions($indexes, $con, $qry, $res);
		}

		return new ArrayData($ret);
	}

	/**
	 * Used transiently when sorting to pass the column information into compare(), since everything
	 * is static and we can't pass it in as a parameter.
	 * @var String
	 */
	static $sortFields = null;

	/**
	 * Handle sorting on a packed string column. $res will contain the paged search result ordered on the packed column,
	 * but this may not be very precise the packed column has limited precision. So we use it to give a rough sort, and in
	 * this function refine the results.
	 * The approach is to:
	 * - fetch all records that have the packed string value of the first result in $res, and order that in memory. Then
	 *   we replace $res records with that packed value with the appropriate records from the properly sorted set.
	 * - likewise we do this for last record in $res.
	 * This results in an extra two searches, so there is an overhead.
	 * This is required because sphinx indexes don't hold string values, everything is held in ints. String ordinals in
	 * sphinx could be used, but they are only unique in an index, not globally across many indexes which we need. 
	 * @param $results The search result, an array of objects that we'll work with, and replace with actual objects as we need
	 * @param $res Raw response from sphinx search API
	 * @param $classes
	 * @param $qry
	 * @param $sortFields
	 * @return unknown_type
	 */
	static function packedSortRefineResult(&$results, $res, $classes, $qry, $sortFields, $classmap, $indexes) {
		self::$sortFields = $sortFields;

		if (!$results || count($results) == 0) return;

//		self::dump("Raw page", $results);

		$packedKeysFirst = self::getPackedKeys($results[0], $sortFields);
		$packedKeysLast = self::getPackedKeys($results[count($results)-1], $sortFields);
// 		Debug::show("Packed keys are " . print_r($packedKeysFirst, true) . " .. " . print_r($packedKeysLast, true));

		if (count($results) == $res['total_found']) {
			// If the we got all the results in this one results set, then we just have to sort the objects on the real key,
			// without fetching anything else.
			usort($results, array("SphinxSearch", "compare"));
		}
		else if (self::packedKeysEqual($packedKeysFirst, $packedKeysLast)) {
			// All items in this page have the same values. We have to retrieve all of this key, sort them and take the
			// right slice.
			$all = self::getAllByKey($qry, $sortFields, $packedKeysFirst, $classmap, $indexes);

			// Determine the relative position of the first record with $keyFirst in the approximately ordered set. We have to do this,
			// because there are an indeterminate number of records with lower packed key values before this, so we based the actual
			// snapshot on the relative position through this partial set.
			$offset = 0;
			$firstID = $results[0]->ID;
			foreach ($all as $item) {
				if ($item->ID == $firstID) break;
				$offset++;
			}

			// Re-order the whole set on the proper key value.
			usort($all, array("SphinxSearch", "compare"));

			// Take objects from the re-ordered set starting at the relative position above.
			array_splice($results, 0, count($results), array_slice($all, $offset, count($results)));
		}
		else {
			// There are items with different packed keys in this set.
			$before = self::getAllByKey($qry, $sortFields, $packedKeysFirst, $classmap, $indexes);
			$after = self::getAllByKey($qry, $sortFields, $packedKeysLast, $classmap, $indexes);
//			self::dump("all objects with first key", $before);
//			self::dump("all objects with last key", $after);

			// Do the real sort on both sets
			usort($before, array("SphinxSearch", "compare"));
			usort($after, array("SphinxSearch", "compare"));

			$countFirst = self::countMatching($results, $sortFields, $packedKeysFirst, 1);
			$countLast = self::countMatching($results, $sortFields, $packedKeysLast, -1);
//			Debug::show("counts: $countFirst off the top and $countLast off the bottom");
			
			// Add $countFirst objects from the end of $before to the start of $res['Matches'].
			array_splice($results, 0, $countFirst, array_slice($before, 0 - $countFirst));

			// Add $countLast objects from the start of$after to the end of $res['Matches'].
			array_splice($results, 0 - $countLast, $countLast, array_slice($after, 0, $countLast));

//			self::dump("results after substitutions", $results);
		}
	}

	/**
	 * Debugging function for dumping only ID and Title of a list of objects
	 * @param $label
	 * @param $arr
	 * @return unknown_type
	 */
	static function dump($label, $arr) {
		$s = $label . ":\n";
		foreach ($arr as $obj) {
			$s .= "ID: " . $obj->ID . ", Title:" . $obj->Title . "\n";
		}
		Debug::show($s);
	}

	/**
	 * Return an array of field=>key mappings where field is the actual field to sort on and value is either the real value,
	 * or if that is a packed field, its packed value.
	 * @param $object
	 * @param $sortFields
	 * @return array
	 */
	static function getPackedKeys($object, $sortFields) {
		$result = array();
		foreach ($sortFields as $f => $def) $result[$f] = $def['packed'] ? self::packedKey($object->$f) : $object->$f;
		return $result;
	}

	/** Given a string compute the packed key and return as a uint. This must be identical to the way the packed keys are
	 * constructed in the index.
	 * 
	 * @param $value
	 * @return unknown_type
	 */
	static function packedKey($value) {
		$val = ord(substr($value, 0, 1)) << 24 |
			   ord(substr($value, 1, 1)) << 16 |
			   ord(substr($value, 2, 1)) << 8 |
			   ord(substr($value, 3, 1));
		return "" . $val;
		
	}

	static function packedKeysEqual($a, $b) {
		foreach ($a as $f => $v) if ($a[$f] != $b[$f]) return false;
		return true;
	}

	/* Compare two objects, by sort, using the non-packed keys */
	static function compare($a, $b) {
		$fields = self::$sortFields;
		foreach ($fields as $f => $def) {
			$mult = $def['dir'] == "asc" ? 1 : -1;
			if ($a->$f < $b->$f) return -1 * $mult;
			else if ($a->$f > $b->$f) return 1 * $mult; 
		}
	    return 0;
	}

	// Perform a non-paged sphinx search for all records where the specified fields have the associated values in $packedKeys.
	// Translates the results to a DataObjectSet which it returns, but in the same order as the sphinx search. Also removes paging limits.
	static function getAllByKey($qry, $sortFields, $packedKeys, $classmap, $indexes) {
//		Debug::show("getAllByKey(" . print_r($sortFields, true) . ", " . print_r($packedKeys, true));
		$sphinx = singleton('Sphinx');
		$con = $sphinx->connection();

		// Remove limits and existing filters
		$con->SetLimits(0, 10000);
		$con->ResetFilters();

		// Add a filter for each field in the packed keys, using packed fields where they are being used.
		foreach($sortFields as $f => $def) if (substr($f, 0, 1) != '@') $con->SetFilter($def['packed'] ? $def['packedfield'] : $f, array($packedKeys[$f]), false);
		
		/* Do the search */
		$res = $con->Query($qry, implode(';', $indexes));

		$a = array();
		if (isset($res['matches'])) foreach ($res['matches'] as $bigid => $info) {
			$id = $info['attrs']['_id'];
			$class = $classmap[$info['attrs']['_classid']];

			$a[] = DataObject::get_by_id($class, $id);
		}

		return $a;		
	}

	// Count objects in $set where all properties match the values of $packedKeys, using packed fields where appropriate.
	// If $dir is 1, start the first item and go up. Otherwise start at the end and search backwards. Stops searching when
	// a non-match is encountered.
	static function countMatching(&$set, $sortFields, $packedKeys, $dir) {
		$count = 0;
		for ($i = ($dir > 0) ? 0 : count($set)-1;;) {
			$matched = true;
			foreach ($sortFields as $f => $def) {
				$v = $def['packed'] ? self::packedKey($set[$i]->$f) : $set[$i]->$f;
				if ($v != $packedKeys[$f]) $matched = false;
			}
			if (!$matched) return $count;
			$count++;
			$i += $dir;
			if (($dir > 0 && $i >= count($set)) || ($dir < 0 && $i < 0)) break; 
		}
		return $count;
	}

	/**
	 * Internal function which looks for a better search term by comparing the results of a variety of spelling correction combinations and finding the one with
	 * the most results
	 */
	static function findSpellingSuggestions($indexes, $con, $qry, $res) {
		$best = array(
			'total' => $res['total'],
			'qry' => $qry,
			'replacements' => array()
		);
		
		$spell = singleton('Sphinx')->speller();
		$reps = $spell->check($qry, 3);
		
		if (!empty($reps)) {
			$reps = SphinxArrayLib::crossproduct($reps);
			
			foreach($reps as $replacements) {
				$scqry = strtr($qry, $replacements);
				$res = $con->Query($scqry, implode(';', $indexes));

				if ($res['total'] > $best['total']) {
					$best = array(
						'total' => $res['total'],
						'qry' => $scqry,
						'replacements' => $replacements
					);
				}
			}
		}
		
		$reps = array();
		foreach ($best['replacements'] as $word => $replacement ) $reps[$word] = '<strong>'.$replacement.'</strong>';
		$best['html'] = strtr($qry, $reps); 

		return $best['qry'] == $qry ? null : $best ;
	}
}
