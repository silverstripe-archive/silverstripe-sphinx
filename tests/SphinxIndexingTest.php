<?php

class SphinxIndexingTest extends SapphireTest {
	static $fixture_file = 'sphinx/tests/SphinxTest.yml';

	protected $extraDataObjects = array('SphinxTestBase', 'SphinxTestXMLPiped', 'SphinxTestDescendantA', 'SphinxTestDescendantB');

	static $sphinx;

	/**
	 * We only need to do this once, because its expensive. It's not done in set_up_once, because code called from there
	 * appears as unexecuted in the coverage reort.
	 * @return unknown_type
	 */
	function onceOnly() {
		if (self::$sphinx) return;

		self::$sphinx = new Sphinx();
		self::$sphinx->setClientClass("SphinxClientFaker", $this);
		self::$sphinx->configure();
		self::$sphinx->reindex(); // required to test xmlpipe xml-generation
	}

	/**
	 * @TODO XMLPipes/command line
	 * @TODO XMLPipes/command generates parsable XML
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
		$this->onceOnly();

		$sphinx = self::$sphinx;

		// test conf , psdic and idxs exist
		$conf = "{$sphinx->VARPath}/sphinx.conf";
		$this->assertTrue(file_exists($conf), "Configuration file exists");

// removed this test, not sure this can be tested in test-runner environments where sphinx is not actually present,
// because the pspell dictionary is generated from results from sphinx indexer.
//		$this->assertTrue(file_exists("{$sphinx->VARPath}/sphinx.psdic"), "pspell dictionary exists");

		$this->assertTrue(file_exists("{$sphinx->VARPath}/idxs"), "indexes directory exists");

		// Check for basic existance of things in the config file
		$this->assertTrue(`grep "source BaseSrc" "$conf"` != "", "Config file has base source");
		$this->assertTrue(`grep "index BaseIdx" "$conf"` != "", "Config file has base index");

		// Check for defaults for indexer and searchd
		$this->assertTrue(`grep "^indexer [{]\$" "$conf"` != "", "Config file has indexer statement");
		$this->assertTrue(`grep "^searchd [{]\$" "$conf"` != "", "Config file has indexer statement");
		$this->assertTrue(`grep "^\tmax_children = 30$" "$conf"` != "", "Config file has max_children");
		$this->assertTrue(`grep "^\tlog = .*" "$conf"` != "", "Config file has log");
		$this->assertTrue(`grep "^\tmem_limit = .*" "$conf"` != "", "Config file has mem limit clause");
	}

	// A few checks that we see variant info that we expect to see.
	function testCheckVariants() {
		$this->onceOnly();

		$conf = self::$sphinx->VARPath . "/sphinx.conf";

		// SphinxTestBase is decorated with SphinxSearchable and Versioned, and we expect to see all the variants
		$this->assertTrue(`grep "source SphinxTestBaseSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase index");
		$this->assertTrue(`grep "source SphinxTestBaseLiveSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase live index");
		$this->assertTrue(`grep "source SphinxTestBaseDeltaSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase delta index");
		$this->assertTrue(`grep "source SphinxTestBaseLiveDeltaSrc : BaseSrc" "$conf"` != "", "Config file has SphinxTestBase delta live index");
	}

	// Test CRC32 generation. For SQL-server, top bit is stripped. Checks
	// crcs for names that have high bit set and cleared.
	function testCRC32() {
		// low bit clear, same crc on any db
		$this->assertEquals(SphinxSearch::unsignedcrc("Test"), "2018365746",
			"Check CRC for class name with low bit clear");
		$db = DB::getConn();
		if ($db instanceof MSSQLDatabase ||
			$db instanceof MSSQLAzureDatabase)
			$this->assertEquals(SphinxSearch::unsignedcrc("Foo"), "876487617",
				"Check CRC for class name with high bit set, MSSQL");
		else
			$this->assertEquals(
				SphinxSearch::unsignedcrc("Foo"), "3023971265",
				"Check CRC for class name with high bit set, non-MSSQL"
			);
	}

	function testSourceSQL() {
		$this->onceOnly();

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
			if ($key == "sql_query_pre" && preg_match('/^UPDATE .* SET .*\"SphinxPrimaryIndexed\" = 1/', $value)) $update_spi = true;
			if ($key == "sql_attr_uint" && $value == "FilterProp") $filter_prop_ok = true;
			if ($key == "sql_attr_uint" && $value == "IntProp") $int_prop_ok = true;
			if ($key == "sql_attr_uint" && $value == "_testextra") $test_extra_ok = true;
			if ($key == "sql_attr_uint" && $value == "_packed_StringProp") $string_packed_ok = true;

			// Checks for badness. Naughty config file.
			if ($value == "FilterProp" && $key != "sql_attr_uint") $filter_prop_wrong_type = true;
			if ($value == "NonFilterProp") $non_filter_prop_present = true;
		}
		$this->assertTrue($query_runs_ok, "Query runs OK");
		if (DB::getConn() instanceof MySQLDatabase) $this->assertTrue($ansi_mode_set, "SQL Pre ansi setting");
		if (self::$sphinx->SupportedDatabase) $this->assertTrue($update_spi, "SQL Pre reset SphinxPrimaryIndexed on base");
		$this->assertTrue($filter_prop_ok, "FilterProp defined OK");
		$this->assertTrue($int_prop_ok, "IntProp defined OK");
		$this->assertTrue($test_extra_ok, "textextra field defined OK");
		if (!(DB::getConn() instanceof SQLite3Database || DB::getConn() instanceof SQLitePDODatabase)) $this->assertTrue($string_packed_ok, "packed field for string sort OK");
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
		$this->onceOnly();

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
		$this->assertTrue(count($sectionDescB) > 0, "SphinxTestDescendantBSrc has its own index");
		if (count($sectionDescB) > 0) {
			$extra_prop_ok = false;
			foreach ($sectionDescB as $item) {
				list($key, $value) = $item;
				if ($key == "sql_attr_uint" && $value == "DescBProp") $extra_prop_ok = true;
			}
			$this->assertTrue($extra_prop_ok, "Descendant B includes an attribute for its own property");
		}
	}

	function testSourceXML() {
		$this->onceOnly();

		$item = $this->objFromFixture("SphinxTestXMLPiped", "joe");
		$this->assertTrue($item != null, "Test XML item is present");

		// check that the source and index are present for SphinxTestXMLPiped
		// Get the source structure
		$section = $this->getConfigSection("source SphinxTestXMLPipedSrc");

		$this->assertTrue(count($section) != 0, "SphinxTestXMLPipedSrc exists");
		if (count($section) > 0) {
			$type = "";
			$command = "";
			$extras = false;
			foreach ($section as $item) {
				list($key,$value) = $item;
				if ($key == "type") $type = $value;
				else if ($key == "xmlpipe_command") $command = $value;
				else $extras = true;
			}

			$this->assertTrue($type != "", "XML pipe type defined");
			$this->assertTrue($command != "", "XML pipe command defined");
			$this->assertTrue(!$extras, "XML pipe config has no extra stuff");

			// Validate the command. We can't execute it directly, because it will run sake but won't be in the test environment. So
			// we syntax check the command, extract the source out of it, and run the controller directly to get the XML, then
			// we can validate it.
			$this->assertTrue(preg_match('/sapphire\/sake sphinxxmlsource\/(\w+)/', $command, $matches) > 0, "XML pipe command runs sake");
			$c = new SphinxXMLPipeController();
			$xml = $c->produceSourceDataInternal($matches[1]);
			$xml = preg_replace("/<sphinx\:/", "<", $xml); // strip namespace prefix because simplexml doesn't understand it
			$xml = preg_replace("/<\/sphinx\:/", "</", $xml);
			$data = simplexml_load_string($xml);
			$this->assertTrue($data != null, "XML Pipe XML data parsed OK");
		}
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