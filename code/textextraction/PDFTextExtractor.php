<?php

/**
 * Text extractor that calls pdftotext to do the conversion.
 * @author mstephens
 *
 */
class PDFTextExtractor extends FileTextExtractor {
	function supportedExtensions() {
		return array("pdf");
	}

	/**
	 * Accessor to get the location of the binary
	 * @param $prog
	 * @return unknown_type
	 */
	function bin($prog='') {
		if ($this->stat('binary_location')) $path = $this->stat('binary_location'); // By static from _config.php
		elseif (file_exists('/usr/bin/pdftotext'))  $path = '/usr/bin';                      // By searching common directories
		elseif (file_exists('/usr/local/bin/pdftotext')) $path = '/usr/local/bin';
		else $path = '.'; // Hope it's in path

		return ( $path ? $path . '/' : '' ) . $prog;
	}
	
	function getContent($file) {
		$filename = Director::baseFolder() . "/" . $file->Filename;
		if (!$filename) return ""; // no file
		$content = `{$this->bin('pdftotext')} "$filename" -`;
		return $content;
	}
}

?>