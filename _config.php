<?php

Director::addRules(100, array(
	    'sphinxxmlsource' => 'SphinxXMLPipeController'));

// Exclude the sphinx API from coverage
TestRunner::$coverage_filter_dirs[] = "sphinx/thirdparty";

