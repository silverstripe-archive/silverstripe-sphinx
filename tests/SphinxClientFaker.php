<?php

/**
 * This is a mock class to replace SphinxSearch in unit tests. It implements a small subset of the SphinxClass API, and implements
 * __call, __get and __set as dummies to handle everything it doesn't otherwise implement.
 * 
 * @author mstephens
 *
 */
class SphinxClientFaker implements TestOnly {

	public $start = 0;
	public $pagesize = 10;

	public $filters = array();

	/**
	 * If we are running a sphinx unit test, a test object may be passed through. In non-unit test cases, and
	 * where a non-sphinx unit test is being run, this will be null.
	 */
	public $testObject;

	function __construct($testObject = null) {
		$this->testObject = $testObject;
	}

	/**
	 * Simulates execution of query. $qry should be one of the following values based on the test case:
	 * 	"basic"
	 * @param $qry
	 * @param $indexes
	 * @return unknown_type
	 */
	function Query($qry, $indexes) {
		$results = array();

		$data = $this->getData($qry);

		$results['matches'] = array();

		// Add data to results based on start and pagesize
		$recno = 0;
		foreach ($data as $bigid => $info) {
			if ($recno >= ($this->start * $this->pagesize) && $recno < (($this->start+1) * $this->pagesize))
				$results['matches'][$bigid] = $info;
			$recno++;
		}

		$results['total'] = count($data);
		$results['total_found'] = count($data);

		return $results;
	}

	function SetLimits($start, $pagesize) {
		$this->start = $start;
		$this->pagesize = $pagesize;
	}

	function SetFilter ( $attribute, $values, $exclude=false) {
		if (!is_array($values)) $valuesd = array($values);
		$this->filters[] = array($attribute, $values, $exclude);
	}

	function ResetFilters() {
		$this->filters = array();
	}

	/**
	 * Return a fake dataset based on what mode we are testing.
	 * The particular set of results is determined by $qry, and there are two sets of values:
	 * - for sphinx internal unit tests, it understands "basic", "sort1", "sort2" and "sort3", which return
	 *   specific sets of values that are present in the test yaml file.
	 * - for unit tests that are external to the module, it understands a more complex format for specifying
	 *   objects, of the form "class:filter" or "class:(items)"
	 *   e.g. if the query is Page::\"Property\"='xyz', it effectively returns DataObject::get("Page", \"Property\"='xyz').
	 *   if query is Page::(page1,page2,page4), it will return the named objects from the yaml file, of the given type.
	 * @param $qry
	 * @return unknown_type
	 */
	private function getData($qry) {
		$result = array();

		if (strpos($qry, ":") !== FALSE) return $this->getDataByQuery($qry);

		switch ($qry) {
			case "basic":
				// Just return something
				$this->addFakeSearchResult($result, "fred");
				break;

			case "sort1":
				// Return a set smaller than the page size but which requires text sorting, and
				// at least two where the packed attribute is the same (1st four characters the same)
				// These are in the order of _packed_StringProp
				$this->addFakeSearchResult($result, "longname1");
				$this->addFakeSearchResult($result, "longname2");
				$this->addFakeSearchResult($result, "longname9");
				$this->addFakeSearchResult($result, "fred");
				$this->addFakeSearchResult($result, "zaphod");
				break;

			case "sort2":
				// Return a set which is larger than page size (assumed here as 5), where all results have the same
				// packed key.
				// These are in the order of _packed_StringProp
				$this->addFakeSearchResult($result, "longname1");
				$this->addFakeSearchResult($result, "longname2");
				$this->addFakeSearchResult($result, "longname3");
				$this->addFakeSearchResult($result, "longname4");
				$this->addFakeSearchResult($result, "longname5");
				$this->addFakeSearchResult($result, "longname6"); // not included in 5, but needed to avoid case 1
				break;

			case "sort3":
				// Return a set which is larger than page size (assumed here as 5), where the first and last results
				// have different packed keys.
				// These are in the order of _packed_StringProp
				$this->addFakeSearchResult($result, "longname6");
				$this->addFakeSearchResult($result, "longname7");
				$this->addFakeSearchResult($result, "longname8");
				$this->addFakeSearchResult($result, "longname9");
				$this->addFakeSearchResult($result, "fred");
				$this->addFakeSearchResult($result, "zaphod"); // not included in 5, but needed to avoid case 1
				break;
				
			default:
				// Important: anything other than the above values should be ignored and return an empty set,
				// because Query is called after the main search in the test scenario under some circumstances in an
				// attempt to provide suggestions, with variations on the spellings of the words above.
				break;
		}
		return $result;
	}

	private function getDataByQuery($qry) {
		$i = strpos($qry, ":");
		$class = substr($qry, 0, $i);
		$params = trim(substr($qry, $i+1));
		if ($params[0] == "(") {
			// expecting a list of identifiers
			if ($params[strlen($params)-1] != ")") $params .= "(";
			$params = substr($params, 1, strlen($params)-2);
			$ids = explode(",", $params);
			$objects = new DataObjectSet();
			foreach ($ids as $id) $objects->push($this->testObject->getTestObject($class, $id));
		}
		else
			$objects = DataObject::get($class, $params);

		// Given the selected objects, build the fake result
		$set = array();
		if ($objects) foreach ($objects as $object) {
			$info = array();
			$info['attrs']['_id'] = $object->ID;
			$info['attrs']['_classid'] = SphinxSearch::unsignedcrc($object->ClassName);
			$bigid = SphinxSearch::combinedwords($info['attrs']['_classid'], $object->ID);
			$set[$bigid] = $info;
		}
		return $set;
	}

	/**
	 * Create a mock search result from an object identified by its fixture_name. If there is no testObject (i.e.
	 * we're not running a sphinx search test), doesn't do anything.
	 * @param $arr
	 * @param $fixtureName
	 * @return unknown_type
	 */
	private function addFakeSearchResult(&$arr, $fixtureName) {
		if (!$this->testObject) return;

		$obj = $this->testObject->getTestObject("SphinxTestBase", $fixtureName);
		$info = array();
		$info['attrs'] = array();
		$info['attrs']['_id'] = $obj->ID;
		$info['attrs']['_classid'] = 2037589277; // class id of SphinxTestBase
		$info['attrs']['StringProp'] = $obj->StringProp; 
		$info['attrs']['_packed_StringProp'] = SphinxSearch::packedKey($obj->StringProp);

		$bigid = SphinxSearch::combinedwords(2037589277, $obj->ID);

		// Apply filters
		$matches = true;
		foreach ($this->filters as $f) {
			list($attr, $values, $exclude) = $f;
			$in = isset($info['attrs'][$attr]) ? in_array($info['attrs'][$attr], $values) : false;
			if ((!$exclude && !$in) || ($exclude && $in)) $matches = false;
		}
		
		if ($matches) $arr[$bigid] = $info;
	}

	function __call($name, $args) {
		return null;
	}

	function __get($name) {
		return null;
	}

	function __set($name, $value) {	
	}
}

?>