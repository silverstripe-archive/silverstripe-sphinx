<?php

class SphinxSearchTest extends SapphireTest {
	static $fixture_file = 'sphinx/tests/SphinxTest.yml';
	
	protected $extraDataObjects = array('SphinxTestBase', 'SphinxTestXMLPiped', 'SphinxTestDescendantA', 'SphinxTestDescendantB');
	
	static $sphinx = null;

	/**
	 * Public function so the SphinxClientFaker can get objects from the fixtures, since it will have a handle on the test object
	 * but objFromFixture is protected.
	 * @param $fixture
	 * @return unknown_type
	 */
	function getTestObject($class, $fixture) {
		return $this->objFromFixture($class, $fixture);
	}

	/**
	 * We only need to do this once, because its expensive. It's not done in set_up_once, because code called from there
	 * appears as unexecuted in the coverage reort.
	 * @return unknown_type
	 */
	function onceOnly() {
		if (self::$sphinx) return;

//		Sphinx::set_test_mode(true);
		self::$sphinx = new Sphinx();
		self::$sphinx->setClientClass("SphinxClientFaker", $this);
		self::$sphinx->configure();
	}

	/**
	 * @TODO basic paging
	 */
	function testSearchBasic() {
		$this->onceOnly();

		$res = SphinxSearch::search(array('SphinxTestBase'),
								"basic", array( 'require' => array(), 'page' => 0, 'pagesize' => 3));
		$this->assertTrue($res != null, "Basic search got result object");
		$this->assertTrue($res->Matches->Count() > 0, "Basic search got actual result records");
	}

	function testSearchTextSortCase1() {
		$this->onceOnly();

		$res = SphinxSearch::search(array('SphinxTestBase'),
								"sort1", array( 'require' => array(), 'page' => 0, 'pagesize' => 5, 'sortmode' => "fields", 'sortarg' => array("StringProp" => "asc")));
		$this->assertTrue($res->Matches->Count() == 5, "Sort case 1 has 5 results");
		$this->assertTrue($this->inStringPropOrder($res->Matches), "Sort case 1 are in correct order");
	}

	function testSearchTextSortCase2() {
		$this->onceOnly();

		$res = SphinxSearch::search(array('SphinxTestBase'),
								"sort2", array( 'require' => array(), 'page' => 0, 'pagesize' => 5, 'sortmode' => "fields", 'sortarg' => array("StringProp" => "asc")));
		$this->assertTrue($res->Matches->Count() == 5, "Sort case 2 has 5 results");
		$this->assertTrue($this->inStringPropOrder($res->Matches), "Sort case 2 are in correct order");
	}
	
	function testSearchTextSortCase3() {
		$this->onceOnly();

		$res = SphinxSearch::search(array('SphinxTestBase'),
								"sort3", array( 'require' => array(), 'page' => 0, 'pagesize' => 5, 'sortmode' => "fields", 'sortarg' => array("StringProp" => "asc")));
		$this->assertTrue($res->Matches->Count() == 5, "Sort case 3 has 5 results");
		$this->assertTrue($this->inStringPropOrder($res->Matches), "Sort case 3 are in correct order");
	}

	/**
	 * Check that a dataset with SphinxTestBase are in correct StringProp order.
	 */
	function inStringPropOrder($ds) {
		$lastValue = null;
		foreach ($ds as $d) {
			if ($lastValue && $lastValue > $d->StringProp) return false;
			$lastValue = $d->StringProp;
		}
		return true;
	}
}

?>