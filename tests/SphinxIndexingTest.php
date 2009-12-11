<?php

class SphinxIndexingTest extends SapphireTest {
	static $fixture_file = 'sphinx/tests/SphinxIndexingTest.yml';

	static $sphinx;

	static function set_up_once() {
		self::$sphinx = new Sphinx();
		self::$sphinx->configure();
		self::$sphinx->reindex();
		parent::set_up_once();
	}

	/**
	 * @TODO XMLPipes/command line
	 * @TODO XMLPipes/command generates parsable XML
	 * @TODO XMLPipes/command/xml has necessary components
	 * @TODO Update to object re-indexes delta
	 * @TODO re-index where there are deltas result in empty delta index, items then in primary index
	 * @TODO indexer generates non-empty files where there is indexable content
	 * @TODO indexer doesn't return errors
	 * @TODO test combination of related classes into indexes
	 * @TODO test order by string generates right attribute
	 */

	/**
	 * Check basic sanity of the configuration file.
	 * @return unknown_type
	 */
	function testConfigurationCreation() {
		$sphinx = self::$sphinx;

		// test conf , psdic and idxs exist
		$conf = "{$sphinx->VARPath}/sphinx.conf";
		$this->assertTrue(file_exists($conf), "Configuration file exists");
		$this->assertTrue(file_exists("{$sphinx->VARPath}/sphinx.psdic"), "pspell dictionary exists");
		$this->assertTrue(file_exists("{$sphinx->VARPath}/idxs"), "indexes directory exists");

		// Check for basic existance of things in the config file
		$this->assertTrue(`grep "source BaseSrc" "$conf"` != "", "Config file has base source");
		$this->assertTrue(`grep "index BaseIdx" "$conf"` != "", "Config file has base index");
	}

	// A few checks that we see variant info that we expect to see.
	function testCheckVariants() {
		$conf = self::$sphinx->VARPath . "/sphinx.conf";

		// SphinxTestBase is decorated with SphinxSearchable and Versioned, and we expect to see all the variants
		$this->assertTrue(`grep "source SphinxTestBaseSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase index");
		$this->assertTrue(`grep "source SphinxTestBaseLiveSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase live index");
		$this->assertTrue(`grep "source SphinxTestBaseDeltaSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase delta index");
		$this->assertTrue(`grep "source SphinxTestBaseLiveDeltaSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase delta live index");
	}

	function testSourceSQL() {
		$section = $this->getConfigSection("source SphinxTestBaseSrc");
		$this->assertTrue(count($section)>0, "SQL source defined");
		if (count($section) == 0) return;  // avoid php errors

		// Iterate over what's in there and flag what we expect to find
		$query_runs_ok = false;
		$ansi_mode_set = false;
		$update_spi = false;
		$filter_prop_ok = false;
		$int_prop_ok = false;
		$test_extra_ok = false;
		$string_packed_ok = false;
		$filter_prop_wrong_type = false;
		$non_filter_prop_present = false;
		foreach ($section as $item) {
			list($key, $value) = $item;

			if ($key == "sql_query") {
				// Run the query
				if (DB::query($value)) $query_runs_ok = true; // will probably throw an error anyway
			}

			// Checks for goodness.
			if ($key == "sql_query_pre" && preg_match('/SET sql_mode = \'ansi\'/', $value)) $ansi_mode_set = true;
			if ($key == "sql_query_pre" && preg_match('/^UPDATE .* SET SphinxPrimaryIndexed = true/', $value)) $update_spi = true;
			if ($key == "sql_attr_uint" && $value == "FilterProp") $filter_prop_ok = true;
			if ($key == "sql_attr_uint" && $value == "IntProp") $int_prop_ok = true;
			if ($key == "sql_attr_uint" && $value == "_testextra") $test_extra_ok = true;
			if ($key == "sql_attr_uint" && $value == "_packed_StringProp") $string_packed_ok = true;

			// Checks for badness. Naughty config file.
			if ($value == "FilterProp" && $key != "sql_attr_uint") $filter_prop_wrong_type = true;
			if ($value == "NonFilterProp") $non_filter_prop_present = true;
		}
		$this->assertTrue($query_runs_ok, "Query runs OK");
		$this->assertTrue($ansi_mode_set, "SQL Pre ansi setting");
		$this->assertTrue($update_spi, "SQL Pre reset SphinxPrimaryIndexed on base");
		$this->assertTrue($filter_prop_ok, "FilterProp defined OK");
		$this->assertTrue($int_prop_ok, "IntProp defined OK");
		$this->assertTrue($test_extra_ok, "textextra field defined OK");
		$this->assertTrue($string_packed_ok, "packed field for string sort OK");
		$this->assertTrue(!$filter_prop_wrong_type, "FilterProp is the right type");
		$this->assertTrue(!$non_filter_prop_present, "non-filtered property not defined as attribute");	
	}

	/**
	 * When a class A is SphinxSearchable, and a derived class B does NOT define different sdearch/filter/order fields,
	 * both classes are combined in the same index. However, if another derived class C defines different fields for sphinx search,
	 * it will have an index of its own. SphinxTestBase is the base clase, SphinxTestDescendantA should be in the same index,
	 * and SphinxTestDescendantB should be in a different index.
	 * @return unknown_type
	 */
	function testIndexCombination() {
		$sectionBase = $this->getConfigSection("source SphinxTestBaseSrc");
		// check that the query ClassName selection includes both the base and descendant A
		if (count($sectionBase) > 0) {
			$sql_ok = false;
			foreach ($sectionBase as $item) {
				list($key, $value) = $item;
				if ($key == "sql_query" && preg_match('/^SELECT.*\"ClassName\" in \(.*SphinxTestBase.*SphinxTestDescendantA.*\)/', $value)) $sql_ok = true;
			}
			$this->assertTrue($sql_ok, "Base class includes itself and descendant B");
		}
		
		$sectionDescA = $this->getConfigSection("source SphinxTestDescendantASrc");
		// check that descendant A doesn't have a section
		$this->assertTrue(count($sectionDescA) == 0, "DescendantA doesn't have its own index");

		$sectionDescB = $this->getConfigSection("source SphinxTestDescendantBSrc");
		// check that descendant B has its own section, and the variances are correct.
	}

	function testSourceXML() {
		
	}

	/**
	 * Return a section from the sphinx config file. Returns array of arrays of $key=$value assignments from the section. The same key
	 * can occur more than once, hence the double array
	 * @param $what				A string of the form "index SomeName" or "source SomeNameSrc"
	 * @return unknown_type
	 */
	function getConfigSection($what) {
		$conf = self::$sphinx->VARPath . "/sphinx.conf";
		$section = explode("\n", `awk '/^{$what} :/ {domatch=1} /}/ {if(domatch==1){domatch=0;print $0}} /^[^}]/ {if(domatch==1) print $0}' $conf`);
		array_shift($section);
		array_pop($section); array_pop($section); // drop off stuff at start and finish
		foreach ($section as $key => $value) {
			$parts = explode("=", $value, 2);
			if (count($parts)==2) $section[$key] = array(trim($parts[0]), trim($parts[1])); // excludes } and header line
		}
		return $section;
	}
}

?>