<?php
/**
 * RandomSelection -- randomly displays one of the given options.
 * Usage: <choose><option>A</option><option>B</option></choose>
 * Optional parameter: <option weight="3"> == 3x weight given
 *
 * @file
 * @ingroup Extensions
 * @version 2.2
 * @date 23 June 2015
 * @author Ross McClure <http://www.mediawiki.org/wiki/User:Algorithm>
 * @link https://www.mediawiki.org/wiki/Extension:RandomSelection Documentation
 */
class RandomSelection {
	/**
	 * Register the <choose> tag with the Parser.
	 *
	 * @param Parser $parser
	 * @return bool
	 */
	public static function register( &$parser ) {
		$parser->setHook( 'choose', array( __CLASS__, 'render' ) );
		return true;
	}

	/**
	 * Callback for register() which actually does all the processing.
	 *
	 * @param string $input User-supplied input
	 * @param array $argv [unused]
	 * @param Parser $parser
	 */
	public static function render( $input, $argv, $parser ) {
		# Prevent caching
		$parser->disableCache();

		# Parse the options and calculate total weight
		$len = preg_match_all(
			"/<option(?:(?:\\s[^>]*?)?\\sweight=[\"']?([^\\s>]+))?"
				. "(?:\\s[^>]*)?>([\\s\\S]*?)<\\/option>/",
			$input,
			$out
		);
		$r = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			if ( strlen( $out[1][$i] ) == 0 ) {
				$out[1][$i] = 1;
			} else {
				$out[1][$i] = intval( $out[1][$i] );
			}
			$r += $out[1][$i];
		}

		# Choose an option at random
		if ( $r <= 0 ) {
			return '';
		}
		$r = mt_rand( 1, $r );
		for ( $i = 0; $i < $len; $i++ ) {
			$r -= $out[1][$i];
			if ( $r <= 0 ) {
				$input = $out[2][$i];
				break;
			}
		}

		return $parser->recursiveTagParse( $input );
	}
}