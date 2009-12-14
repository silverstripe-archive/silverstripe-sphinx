<?php

class SphinxTestXMLPiped extends DataObject implements TestOnly {
	static $db = array(
		"IntPropX" => "Int",
		"StringPropX" => "Varchar",
		"FilterPropX" => "Int",
		"NonFilterPropX" => "Int"
	);

	static $extensions = array(
		"SphinxSearchable(true)",
		"Versioned('Stage', 'Live')"
	);

	static $sphinx = array(
		"filter_fields" => array("FilterProp"),
		"sort_fields" => array("IntProp", "StringProp"),
		"mode" => "xmlpipe",
		"external_content" => array("file_content" => array("SphinxTestXMLPiped", "getExternalContent"))
	);

	// Gets a value for _testextra field.
	static function getExternalContent() {
		return "i can haz ur controlr";
	}
}

?>