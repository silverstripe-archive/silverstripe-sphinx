<?php

/**
 * A base class that is marked as sphinx searchable. We expect this will generate an index with various properties
 * set and not set.
 * @author mstephens
 *
 */
class SphinxTestBase extends DataObject implements TestOnly {
	static $db = array(
		"IntProp" => "Int",
		"StringProp" => "Varchar",
		"FilterProp" => "Int",
		"NonFilterProp" => "Int"
	);

	static $extensions = array(
		"SphinxSearchable(true)",
		"Versioned('Stage', 'Live')"
	);

	static $sphinx = array(
		"filter_fields" => array("FilterProp"),
		"extra_fields" => array("_testextra" => "SphinxTestBase::getTestExtraValue"),
		"sort_fields" => array("IntProp", "StringProp"),
		"mode" => "sql"
	);

	// Gets a value for _testextra field.
	static function getTestExtraValue() {
		return 0xDEADBEEF;
	}
}

?>