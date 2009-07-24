<?php

/**
 * A simple Pure PHP spell checker. Does well enough for 'Did you mean..' style usage
 * @author Hamish Friedlander <hamish@silverstripe.com>
 */
class PureSpell {
	
	public $dictionary = array();
	
	function __construct($words=null) {
		if ($words) $this->add_words($words);
	}
	
	function add_words($words) {
		foreach ($words as $word) {
			$simp = metaphone($word);
			if (array_key_exists($simp, $this->dictionary)) {
				if (!in_array($word, $this->dictionary[$simp])) $this->dictionary[$simp][] = $word;	
			}
			else $this->dictionary[$simp] = array($word);	
		}
	}

	function load_wordfile($file) {
		if (!file_exists($file)) return;
		
		$words = preg_split('/(\r\n|\r|\n)+/', file_get_contents($file));
		$words = array_unique($words);
		$this->add_words($words);
	}
	
	function load_dictionary($file) {
		if (file_exists($file)) $this->dictionary = unserialize(file_get_contents($file));
	}
	
	function save_dictionary($file) {
		file_put_contents($file, serialize($this->dictionary));
	}
	
	function check_word($check, $limit = 10) {
		$simpcheck = metaphone($check);
		
		$scores = array();
		foreach ($this->dictionary as $simp => $words) {
			if ($simpcheck == $simp || levenshtein($simpcheck, $simp) == 1) {
				foreach ($words as $word) {
					similar_text($word, $check, $p);
					if ($p > 66) $scores[$word] = $p;
				}
			}
		}
		
		asort($scores, SORT_NUMERIC);
		
		return array_keys(array_reverse(array_slice($scores, -$limit)));
	}
	
	function check($words, $limit = 10) {
		$res = array();
		foreach (preg_split('/[^A-Za-z]+/', $words, -1, PREG_SPLIT_NO_EMPTY) as $word) $res[$word] = $this->check_word($word, $limit);
		return $res;
	}
	
}