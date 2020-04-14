<?php
/**
 * RandomSelection -- randomly displays one of the given options.
 * Usage: <choose><option>A</option><option>B</option></choose>
 * Optional parameter: <option weight="3"> == 3x weight given
 *
 * @file
 * @ingroup Extensions
 * @author Ross McClure <https://www.mediawiki.org/wiki/User:Algorithm>
 * @link https://www.mediawiki.org/wiki/Extension:RandomSelection Documentation
 */
class RandomSelection {
	/**
	 * Register the <choose> tag and {{#choose:option 1|...|option N}} function
	 * with the Parser.
	 *
	 * @param Parser &$parser
	 * @return bool
	 */
	public static function register( &$parser ) {
		$parser->setHook( 'choose', [ __CLASS__, 'render' ] );
		$parser->setFunctionHook( 'choose', [ __CLASS__, 'renderParserFunction' ],
			Parser::SFH_OBJECT_ARGS );
		return true;
	}

	/**
	 * Register the magic word ID.
	 *
	 * @param array &$variableIds
	 * @return bool
	 */
	public static function variableIds( &$variableIds ) {
		$variableIds[] = 'choose';
		return true;
	}

	/**
	 * Callback for register() which actually does all the processing.
	 *
	 * @param string $input User-supplied input
	 * @param array $argv User-supplied arguments to the tag, e.g. <choose uncached>...</choose>
	 * @param Parser $parser
	 * @return string
	 */
	public static function render( $input, $argv, $parser ) {
		# Prevent caching if specified so by the user
		if ( isset( $argv['uncached'] ) ) {
			$parser->getOutput()->updateCacheExpiry( 0 );
		}

		# Parse the options and calculate total weight
		$len = preg_match_all(
			"/<option(?:(?:\\s[^>]*?)?\\sweight=[\"']?([^\\s>]+))?"
				. "(?:\\s[^>]*)?>([\\s\\S]*?)<\\/option>/",
			$input,
			$out
		);

		# Find any references to a surrounding template
		preg_match_all(
			"/<choicetemplate(?:(?:\\s[^>]*?)?\\sweight=[\"']?([^\\s>]+))?"
				. "(?:\\s[^>]*)?>([\\s\\S]*?)<\\/choicetemplate>/",
			$input,
			$outTemplate
		);

		// weights is cumulative in ascending order.
		// So if we have 2 options equally weighted, by the end
		// of all this, the weights should be 0.5 and 1.0
		$weights = [];
		$normalizingFactor = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$curWeight = 1;
			if ( strlen( $out[1][$i] ) > 0 ) {
				$curWeight = abs( floatval( $out[1][$i] ) );
			}
			$normalizingFactor += $curWeight;
			$weights[$i] = $normalizingFactor;
		}

		if ( $normalizingFactor === 0 ) {
			// I guess we have 0 choices
			return '';
		}

		foreach ( $weights as $index => &$weight ) {
			$weight = $weight / $normalizingFactor;
		}

		// Ok, so now we have an array of ascending weights
		// that are cumulative. e.g. [0.25, 0.5, 0.75, 1].
		// We get a random float, and pick the item with the
		// smallest weight that is greater than our float.
		$r = mt_rand() / mt_getrandmax();

		$selectedContent = '';
		for ( $i = 0; $i < count( $weights ); $i++ ) {
			if ( $weights[$i] >= $r ) {
				$selectedContent = $out[2][$i];
				break;
			}
		}

		# Surround by template if applicable
		if ( isset( $outTemplate[2][0] ) ) {
			$selectedContent = '{{' . $outTemplate[2][0] . '|' . $selectedContent . '}}';
		}

		# Parse tags and return
		if ( isset( $argv['before'] ) ) {
			$selectedContent = $argv['before'] . $selectedContent;
		}
		if ( isset( $argv['after'] ) ) {
			$selectedContent .= $argv['after'];
		}

		return $parser->recursiveTagParse( $selectedContent );
	}

	/**
	 * Callback for the {{#choose:}} magic word magic (see register() in this file)
	 *
	 * @param Parser &$parser
	 * @param PPFrame $frame
	 * @param array $args User-supplied arguments
	 * @return string
	 */
	public static function renderParserFunction( &$parser, $frame, $args ) {
		$options = [];
		$r = 0;

		// First one is not an object
		$arg = array_shift( $args );
		$parts = explode( '=', $arg, 2 );
		if ( count( $parts ) == 2 ) {
			$options[] = [ intval( trim( $parts[0] ) ), $parts[1] ];
			$r += intval( trim( $parts[0] ) );
		} elseif ( count( $parts ) == 1 ) {
			$options[] = [ 1, $parts[0] ];
			$r += 1;
		}

		foreach ( $args as $arg ) {
			$bits = $arg->splitArg();
			$nameNode = $bits['name'];
			$index = $bits['index'];
			$valueNode = $bits['value'];
			if ( $index === '' ) {
				$name = trim( $frame->expand( $nameNode ) );
				$options[] = [ intval( $name ), $valueNode ];
				$r += intval( $name );
			} else {
				$options[] = [ 1, $valueNode ];
				$r += 1;
			}
		}

		# Choose an option at random
		if ( $r <= 0 ) {
			return '';
		}
		$r = mt_rand( 1, $r );
		for ( $i = 0; $i < count( $options ); $i++ ) {
			$r -= $options[$i][0];
			if ( $r <= 0 ) {
				$output = $options[$i][1];
				break;
			}
		}

		return trim( $frame->expand( $output ) );
	}

}
