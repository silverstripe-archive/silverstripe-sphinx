<?php
/*
 * Array functions for Sphinx & Spell
 * 
 * @todo: Once salehoo is on trunk, merge this into regular ArrayLib
 */
class SphinxArrayLib {
	
	/* $in is an array of 'key' => array(values). This returns the crossproduct of that.
	 * 
	 * Example:
	 *   array( 
	 *     'a' => array( 'aa', 'ab' ), 
	 *     'b' => array( 'ba', 'bb' ) 
	 *   )
	 * 
	 * Becomes:
	 *   array(
	 *     array( 'a' => 'aa', 'b' => 'ba' )
	 *     array( 'a' => 'aa', 'b' => 'bb' )
	 *     array( 'a' => 'ab', 'b' => 'ba' )
	 *     array( 'a' => 'ab', 'b' => 'bb' )
	 *   )
	 */
	static function crossproduct($in) {
		$cross = array();
		
		foreach ($in as $k => $vals) {
			if (empty($cross)) {
				for ($i = 0; $i < count($vals); $i++) {
					$cross[$i] = array($k => $vals[$i]);
				}
			}
			else {
				$crossnxt = array();
				
				for ($i = 0 ; $i < count($cross); $i++) {
					for ($j = 0 ; $j < count($vals); $j++) {
						$crossnxt[] = array_merge($cross[$i], array($k => $vals[$j]));
					}
				}
				$cross = $crossnxt;
			}
		}
		
		return $cross;
	}
}