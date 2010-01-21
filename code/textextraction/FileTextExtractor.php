<?php

/**
 * A decorator for File or a subclass that provides a method for extracting full-text from the file's external contents.
 * @author mstephens
 *
 */
abstract class FileTextExtractor extends Object {
	/**
	 * Set priority from 0-100.
	 * The highest priority extractor for a given content type will be selected.
	 *
	 * @var int
	 */
	public static $priority = 50;

	protected static $sorted_extractor_classes = null;

	static function for_file($file) {
		$extension = strtolower($file->getExtension());

		if (!self::$sorted_extractor_classes) {
			// Generate the sorted list of extractors on demand.
			$classes = ClassInfo::subclassesFor("FileTextExtractor");
			array_shift($classes);
			$sortedClasses = array();
			foreach($classes as $class) $sortedClasses[$class] = Object::get_static($class, 'priority');
			arsort($sortedClasses);

			self::$sorted_extractor_classes = $sortedClasses;
		}
		foreach(self::$sorted_extractor_classes as $className => $priority) {
			$formatter = new $className();
			if(in_array($extension, $formatter->supportedExtensions())) {
				return $formatter;
			}
		}
	}

	/**
	 * Return an array of content types that the extractor can handle.
	 * @return unknown_type
	 */
	abstract function supportedExtensions();

	/**
	 * Given a file object, extract the contents as text
	 * @param $file
	 * @return unknown_type
	 */
	abstract function getContent($file);
}

?>