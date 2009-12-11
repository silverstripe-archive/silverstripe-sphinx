<?php

/**
 * Class that descends from decorated class, and does certain alterations that would cause it to exist in a different index
 * @author mstephens
 *
 */
class SphinxTestDescendantB extends SphinxTestBase {
	static $db = array(
		"DescBProp" => "Int"
	);
	
	static $sphinx = array(
		"filter_fields" => array("FilterProp", "DescBProp"),
		"extra_fields" => array("_testextra" => "SphinxTestBase::getTestExtraValue"),
		"sort_fields" => array("IntProp", "StringProp"),
	);
}

?>