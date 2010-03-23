<?php

class SphinxXMLPipeController extends Controller {
	static $url_handlers = array(
		'$Action!'	=> 'produceSourceData'
	);

	/** How much memory should we make sure is available - often the php.ini used with cli php & sake sets this too low, and the index build fails */
	static $memory_requirement = '512M';

	// Given a source as a url param, get the source and return an XML document that contains all the indexable material in the
	// the source.
	function produceSourceData($sourceName = null) {
		increase_memory_limit_to(self::$memory_requirement);

		if (!$sourceName || is_object($sourceName)) {  // controller can be passed in
			$params = $this->getURLParams();
			$sourceName = $params['Action'];
		}

		$xmldata = $this->produceSourceDataInternal($sourceName);
		echo $xmldata;
	}

	/**
	 * This function is separated from the above because this can be called directly from unit test, and returns
	 * a string, whereas above is called in the wild, and echoes the XML to output stream.
	 * @param $sourceName
	 * @return unknown_type
	 */
	function produceSourceDataInternal($sourceName) {
		return singleton("Sphinx")->xmlIndexContents($sourceName);
	}
}

?>