<?php

/**
 * Decorate File or a File derivative to enable text extraction from the file content. Uses a set of subclasses of
 * FileTextExtractor to do the extraction based on the content type of the file.
 * 
 * Adds an additional property which is the cached contents, which is populated on demand.
 *
 * @author mstephens
 *
 */
class FileTextExtractable extends DataObjectDecorator {
	function extraStatics() {
		return array(
			'db' => array(
				'FileContentCache' => 'Text'
			)
		);
	}

	/**
	 * Tries to parse the file contents if a FileTextExtractor class exists to handle the file type, and returns the text.
	 * The value is also cached into the File record itself.
	 * @param $forceParse		If false, the file content is only parsed on demand. If true, the content parsing is forced, bypassing the
	 * 							cached  version
	 * @return unknown_type
	 */
	function extractFileAsText($forceParse = false) {
		if (!$forceParse && $this->owner->FileContentCache) return $this->owner->FileContentCache;

		// Determine which extractor can process this file.
		$extractor = FileTextExtractor::for_file($this->owner);
		if (!$extractor) return null;

		$text = $extractor->getContent($this->owner);
		if (!$text) return null;

		$this->owner->FileContentCache = $text;
		$this->owner->write();

		return $text;
	}
}

?>