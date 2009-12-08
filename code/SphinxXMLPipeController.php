<?php

class SphinxXMLPipeController extends Controller {
		static $url_handlers = array(
		'$Action!'	=> 'produceSourceData'
	);

	// Given a source as a url param, get the source and return an XML document that contains all the indexable material in the
	// the source.
	function produceSourceData() {
		$params = $this->getURLParams();
		$sourceName = $params['Action'];

//		Debug::log("Indexing source " . $sourceName);

		$xmldata = singleton("Sphinx")->xmlIndexContents($sourceName);

//	Debug::log("xml is: " . $xmldata);
		echo $xmldata;
	}
}

?>