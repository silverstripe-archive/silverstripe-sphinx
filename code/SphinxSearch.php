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
			'include_child_classes' => true
		),$args);
		
		/* If we want to search children, add the child classes to the list of classes to search */
		if ($args['include_child_classes']) {
			$children = array();
			foreach ($classes as $class) $children = array_merge($children, ClassInfo::subclassesFor($class));
			$classes = array_unique($children);
		}
		
		/* Build an array of $class => $index pairs */
		$indexes = array_combine($classes, $classes);
		
		/* Allow variants to alter search */
		SphinxVariants::alterSearch($indexes, $args);
		
		/* Make a map to convert CRC32 encoded class IDs back to class names */
		$classmap = array();
		foreach ($classes as $class) $classmap[self::unsignedcrc($class)] = $class;

		/* Get connection */
		$con = singleton('Sphinx')->connection();
		$con->SetMatchMode(SPH_MATCH_EXTENDED2);
		
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
		$con->SetSelect(implode(', ',$query));
		
		/* Set limits */
		$start = $args['start'] ? $args['start'] : $args['page'] * $args['pagesize'];
		$con->SetLimits($start, $args['pagesize']);
		
		/* Do the search */
		$res = $con->Query($qry, implode(';', $indexes));

		/* Build the return values */
		$ret = array();
		
		$ret['Matches'] = new DataObjectSet();
		if (isset($res['matches'])) foreach ($res['matches'] as $bigid => $info) {
			$id = $info['attrs']['_id'];
			$class = $classmap[$info['attrs']['_classid']];
			
			$ret['Matches']->push(DataObject::get_by_id($class, $id));
		}
		$ret['Matches']->setPageLimits($start, $args['pagesize'], $res['total']);

		/* Pull any warnings or errors through to the returned data object */
		if ($err = $con->getLastWarning()) $ret['Warning'] = $err;
		if ($err && self::$search_warning_generates_user_warning) user_error($err, E_USER_WARNING);
		
		if ($err = $con->getLastError()) $ret['Error'] = $err;
		if ($err && self::$search_error_generates_user_error) user_error($err, E_USER_ERROR);
		
		/* If we didn't get that many matches, try looking through all possible spelling corrections to find the one that returns the most matches */
		if ($res['total'] < 10) {
			$ret['Suggestion'] = self::findSpellingSuggestions($indexes, $con, $qry, $res);
		}
		
		return new ArrayData($ret);
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
