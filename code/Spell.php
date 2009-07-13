<?php

/**
 * Spell gives a rudimentary wrapper to hunspell, an ispell-a-like spell checker. It provides just enough of an interface for use by Sphinx.
 * This solution is far from optimal, since it reads the entire dictionary on every request, and the Sphinx system could be sped up significantly 
 * by replacing this with a daemon of some kind.
 */
class Spell extends Object {
	static $max_suggestions = 2;
	static $hunspell_binary = null;
	static $custom_dictionary_path = null;
	
	function __construct(){
		parent::__construct();
		$this->bin = defined('SS_HUNSPELL_BINARY') ? SS_HUNSPELL_BINARY : (self::$hunspell_binary ? self::$hunspell_binary : 'hunspell');
		$this->dictpath = self::$custom_dictionary_path ? self::$custom_dictionary_path : TEMP_FOLDER . '/spell';   
	}
	
	function check($words, $dict=null) {
		$reps = array();
		
		$descriptorspec = array(
		   0 => array("pipe", "r"), // stdin
		   1 => array("pipe", "w"), // stdout
		   2 => array("pipe", "w")  // stderr 
		);		
		
		$dict = $dict ? "-d {$this->dictpath}/$dict" : '';
		$proc = proc_open("$this->bin -a {$dict}", $descriptorspec, $pipes, null, null, array('binary_pipes' => true));
		
		if (is_resource($proc)) {
			fwrite($pipes[0], $words . "\n"); fflush($pipes[0]); fclose($pipes[0]); 
			
			while (!feof($pipes[1])) {
				$line = fgets($pipes[1], 8192); 
				if (!$line) break;
				
				if (preg_match('/^& ([^\s]+) [0-9]+ [0-9]+:\s*(.*)$/', trim($line), $match)) {
					$word = $match[1];
					$replacements = preg_split('/\s*,\s*/', $match[2]);
					$reps[$word] = array_slice($replacements, 0, self::$max_suggestions);
				}
			}
		}
		
		proc_close($proc);
		return $reps;
	}
	
	function dictionary_from_wordfiles($name, $files) {
		$files = implode(' ', $files);
		$uniq = `cat $files | uniq`;
		$count = substr_count($uniq, "\n");
		
		if (!file_exists($this->dictpath)) mkdir($this->dictpath);
		
		file_put_contents($this->dictpath."/".$name.'.dic', $count."\n".$uniq);
		file_put_contents($this->dictpath."/".$name.'.aff', "KEY qwertyuiop|asdfghjkl|zxcvbnm\nTRY esianrtolcdugmphbyfvkwzESIANRTOLCDUGMPHBYFVKWZ'");
	}	
}