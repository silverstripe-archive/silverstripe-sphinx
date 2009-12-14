<?php

class SphinxSearchTest extends SapphireTest {
	static $fixture_file = 'sphinx/tests/SphinxTest.yml';
	
	static $sphinx;

	static function set_up_once() {
		self::$sphinx = new Sphinx();
		self::$sphinx->configure();
		parent::set_up_once();
	}

	/**
	 * Public function so the SphinxClientFaker can get objects from the fixtures, since it will have a handle on the test object
	 * but objFromFixture is protected.
	 * @param $fixture
	 * @return unknown_type
	 */
	function getTestObject($class, $fixture) {
		return $this->objFromFixture($class, $fixture);
	}

	/*
	 * @TODO Basic search
	 * @TODO Search on text field
	 * @TODO basic paging
	 * @TODO boundary cases on text sorting
	 */
	function testSearchBasic() {
		self::$sphinx->setClientClass("SphinxClientFaker", $this);

		$res = SphinxSearch::search(array('SphinxTestBase'),
								"basic", array( 'require' => array(), 'page' => 0, 'pagesize' => 3));
		$this->assertTrue($res != null, "Basic search got result object");
		$this->assertTrue($res->Matches->Count() > 0, "Basic search got actual result records");
	}

	function testSearchTextSort() {
		self::$sphinx->setClientClass("SphinxClientFaker", $this);

		$res = SphinxSearch::search(array('SphinxTestBase'),
								"sort", array( 'require' => array(), 'page' => 0, 'pagesize' => 3, 'sortmode' => "fields", 'sortarg' => array("StringProp" => "asc")));
		$this->assertTrue($res != null, "Basic search got result object");
		$this->assertTrue($res->Matches->Count() > 0, "Basic search got actual result records");
	}
}

?>