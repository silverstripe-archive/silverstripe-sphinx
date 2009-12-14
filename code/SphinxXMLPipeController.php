<?php

class SphinxXMLPipeController extends Controller {
		static $url_handlers = array(
		'$Action!'	=> 'produceSourceData'
	);

	// Given a source as a url param, get the source and return an XML document that contains all the indexable material in the
	// the source.
	function produceSourceData($sourceName = null) {
		if (!$sourceName) {
			$params = $this->getURLParams();
			$sourceName = $params['Action'];
		}

//		Debug::log("Indexing source " . $sourceName);

//	Debug::log("xml is: " . $xmldata);
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