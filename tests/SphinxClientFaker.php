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

	public $testObject;

	function __construct($testObject) {
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
Debug::log("raw results are " . print_r($data, true));
Debug::log("start is $this->start and pagesize is $this->pagesize");
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
		Debug::log("fake query results are " . print_r($results, true));
		return $results;
	}

	function SetLimits($start, $pagesize) {
		$this->start = $start;
		$this->pagesize = $pagesize;
	}

	/**
	 * Return a fake dataset based on what mode we are testing.
	 * @param $qry
	 * @return unknown_type
	 */
	private function getData($qry) {
		$result = array();
		switch ($qry) {
			case "basic":
				$this->addFakeSearchResult($result, "fred");
				break;
			case "sort":
				$this->addFakeSearchResult($result, "fred");
				$this->addFakeSearchResult($result, "marvin");
				$this->addFakeSearchResult($result, "zaphod");
				break;

			//			case "paged":
				default:
				user_error("SphinxClientFaker is not too bright, and doesnt understand the qry term '" . $qry . "'");
				break;
		}
		return $result;
	}

	/**
	 * Create a mock search result from an object identified by its fixture_name.
	 * @param $arr
	 * @param $fixtureName
	 * @return unknown_type
	 */
	private function addFakeSearchResult(&$arr, $fixtureName) {
		$obj = $this->testObject->getTestObject("SphinxTestBase", $fixtureName);

		$info = array();
		$info['attrs'] = array();
		$info['attrs']['_id'] = $obj->ID;
		$info['attrs']['_classid'] = 2037589277; // class id of SphinxTestBase
		$info['attrs']['StringProp'] = $obj->StringProp; 

		$bigid = 2037589277 << 32 | $obj->ID;

		$arr[$bigid] = $info;
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