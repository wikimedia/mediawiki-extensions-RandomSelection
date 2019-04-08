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
	 * @param Parser $parser
	 * @return bool
	 */
	public static function register( $parser ) {
		$parser->setHook( 'choose', [ __CLASS__, 'render' ] );
		$parser->setFunctionHook( 'choose', [ __CLASS__, 'renderParserFunction' ], Parser::SFH_OBJECT_ARGS );
	}

	/**
	 * Callback for register() which actually does all the processing.
	 *
	 * @param string $input User-supplied input
	 * @param array $argv User-supplied arguments to the tag, e.g. <choose uncached>...</choose>
	 * @param Parser $parser
	 * @param PPFrame $frame
	 */
	public static function render( $input, array $argv, Parser $parser, PPFrame $frame ) {
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
		$normalizingFactor = 0.0;
		for ( $i = 0; $i < $len; $i++ ) {
			$curWeight = 1;
			if ( strlen( $out[1][$i] ) > 0 ) {
				$curWeight = abs( floatval( $out[1][$i] ) );
			}
			$normalizingFactor += $curWeight;
			$weights[$i] = $normalizingFactor;
		}

		if ( $normalizingFactor === 0.0 || !is_finite( $normalizingFactor ) ) {
			// I guess we have 0 choices or one of the weights is
			// more than 1.7*10^308
			return '<strong class="error">'
				. wfMessage( 'randomselection-invalidweights' )
					->inContentLanguage()
					->escaped()
				. '</strong>';
		}

		foreach ( $weights as $index => &$weight ) {
			$weight = $weight / $normalizingFactor;
		}

		$content = [];
		for ( $i = 0; $i < count( $weights ); $i++ ) {
			$curContent = $out[2][$i];
			# Surround by template if applicable
			if ( isset( $outTemplate[2][0] ) ) {
				$curContent = '{{' . $outTemplate[2][0] . '|' . $curContent . '}}';
			}

			# Parse tags and return
			if ( isset( $argv['before'] ) ) {
				$curContent = $argv['before'] . $curContent;
			}
			if ( isset( $argv['after'] ) ) {
				$curContent .= $argv['after'];
			}

			$content[$i] = [
				$weights[$i],
				Parser::stripOuterParagraph(
					$parser->recursiveTagParseFully( $curContent, $frame )
				)
			];
		}
		return self::setRandChoices( $content, $parser->getOutput() );
	}

	/**
	 * Given the choices, do the magic to put it in the parser
	 *
	 * @param array $content An array consisting of [weights,html] entries
	 *   Important: Array items should be in ascending order by weights, and
	 *   weights should be monotonically increasing. To choose an item, a random
	 *   float between 0 and 1 is chosen uniformly. If the float is between the
	 *   previous item's weight and the current item's weight, than that item
	 *   is chosen. The HTML content is inserted after parsing, so should be
	 *   fully parsed, not halfParsed (so not recursiveTagParse()).
	 * @param ParserOutput $pout
	 * @return string A marker.
	 */
	private static function setRandChoices( array $content, ParserOutput $pout ) {
		$id = htmlspecialchars( mt_rand() . mt_rand() );
		$pout->setExtensionData( 'RandomSelection-' . $id , $content );
		$pout->setExtensionData( 'RandomSelection', true );
		$pout->setProperty( 'RandomSelection', '' );
		// Use both types of quotes for security, so we know that
		// it is illegal in HTML5 to put this value into an attribute without
		// replacing something with an entity
		// Originally, this was modeled after <mw:toc> pseudo-tag. However,
		// this seemed to cause a new block level to be inserted which we
		// did not want. Instead rely on the fact that data-mw is blacklisted
		// by the parser.
		return "<span class=\"mw-randomselection\" data-mw-randomselection-id=\"'\x7f" . $id . "\x7f'\"></span>";
	}

	/**
	 * Callback for the {{#choose:}} magic word magic (see register() in this file)
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args User-supplied arguments
	 */
	public static function renderParserFunction( &$parser, $frame, $args ) {
		$options = [];
		$r = 0;

		// First one is not an object
		$arg = array_shift( $args );
		$parts = explode( '=', $arg, 2 );
		if ( count( $parts ) == 2 ) {
			$r += abs( floatval( trim( $parts[0] ) ) );
			$options[] = [ $r, $parts[1] ];
		} elseif ( count( $parts ) == 1 ) {
			$r += 1;
			$options[] = [ $r, $parts[0] ];
		}

		foreach ( $args as $arg ) {
			$bits = $arg->splitArg();
			$nameNode = $bits['name'];
			$index = $bits['index'];
			$valueNode = $bits['value'];
			if ( $index === '' ) {
				$name = trim( $frame->expand( $nameNode ) );
				$r += abs( floatval( $name ) );
				$options[] = [ $r, $valueNode ];
			} else {
				$r += 1;
				$options[] = [ $r, $valueNode ];
			}
		}

		if ( $r <= 0 || !is_finite( $r ) ) {
			return '<strong class="error">'
				. wfMessage( 'randomselection-invalidweights' )
					->inContentLanguage()
					->text()
				. '</strong>';
		}

		$content = [];
		// Normalize option weights to range (0,1].
		foreach( $options as $opt ) {
			$text = is_string( $opt[1] ) ? $opt[1] : $frame->expand( $opt[1] );
			$parsedText = $parser->recursiveTagParseFully( $text, $frame );
			$content[] = [
				$opt[0]/$r,
				Parser::stripOuterParagraph( $parsedText )
			];
		}
		return $parser->insertStripItem(
			self::setRandChoices( $content, $parser->getOutput() )
		);
	}

	/**
	 * Hook that runs after parsing
	 *
	 * Used so we randomize views without killing parser cache
	 *
	 * @param ParserOutput $pout
	 * @param string &$text The html of the page
	 * @param array $options
	 */
	public static function onParserOutputPostCacheTransform( ParserOutput $pout, &$text, $options ) {
		if ( !$pout->getExtensionData( 'RandomSelection' ) ) {
			return;
		}

		$replace = function ( array $match ) use ( $pout ) {
			$id = $match[1];
			$choices = $pout->getExtensionData( 'RandomSelection-' . $id );
			return self::getChoice( $choices );
		};
		$cnt = 0;
		do {
			$text = preg_replace_callback(
				"/<span class=\"mw-randomselection\" data-mw-randomselection-id=\"'\x7f([0-9]+)\x7f'\"><\\/span>/",
				$replace,
				$text,
				-1,
				$cnt
			);
		} while ( $cnt !== 0 );
	}

	/**
	 * Choose a content to show
	 *
	 * @param array $choices Array of [weight, content] entries
	 *   where weight is cumulative, e.g. 0.25, 0.5, 0.75, 1
	 *   and array is sorted by weight.
	 * @return One of the contents chosen randomly.
	 */
	private static function getChoice( array $choices ) {
		$r = mt_rand() / mt_getrandmax();
		$selectedContent = '';
		for ( $i = 0; $i < count( $choices ); $i++ ) {
			if ( $choices[$i][0] >= $r ) {
				$selectedContent = $choices[$i][1];
				break;
			}
		}
		return $selectedContent;
	}

	/**
	 * Adjust how long to cache in varnish/similar systems
	 *
	 * Generally speaking, if the parser cache is in operation, varnish
	 * cache will matter much less as a parser cache hit is cheap (relatively).
	 * Its still important of course to take the brunt of the traffic or if there
	 * is a sudden spike, but hopefully render time will be very short so when
	 * it falls out of cache there won't be a large pile up of requests.
	 * However, if something else has disabled cache, then that's not true and
	 * varnish is much more important, so set a different timeout.
	 *
	 * @param OutputPage $out
	 * @param ParserOutput $pout
	 */
	public static function adjustSMaxage( OutputPage $out, ParserOutput $pout ) {
		$cache304 = $out->getProperty( 'RandomSelection304Cache' );
		$extSet = (bool)$pout->getExtensionData( 'RandomSelection' );
		if ( $cache304 !== $extSet && $cache304 !== null ) {
			// The 304 if-last-modified cache is incorrect. Reset it.
			// Note: Technically this could be wrong if the RandomSelection
			// is inside something like a #ifeq that does dead code elimination
			// and this is a non-canonical parse which differs from the
			// canonical parse in terms of if the extension is present.
			// This seems really unlikely though, and the only consequence
			// is lower cache efficiency.
			$cache = self::getCache();
			$key = $cache->makeKey( 'RandomSelection', $out->getTitle()->getArticleId() );
			$cache->delete( $key );
		}
		if ( !$extSet || !$out->isArticle() ) {
			return;
		}
		// Add a header if extension is used. Potentially a front end cache
		// could see it and do something like cache 5 different versions that
		// it returns randomly.
		$out->getRequest()->response()->header( 'X-RandomSelection: 1' );

		// Originally my idea here was to output a longer time if the parser
		// cache was disabled, as in that case varnish cache is much more
		// important. However, in that case, varnish is totally disabled by
		// line 1765 of OutputPage.php.
		// Setting a small cache time here should help against sudden spikes
		// in traffic, as well as take much of the brunt of a very heavy
		// page. With parser cache working, response time should be fast, so
		// the small s-maxage shouldn't matter as requests will have only
		// limited time to pile up during a recache. Thus this forms a
		// compromise where its not totally random for logged out users
		// but the period is so short that it will probably seem random.
		$sMaxage = $out->getConfig()->get( 'RandomSelectionSMaxage' );
		if ( $sMaxage !== false ) {
			$out->lowerCdnMaxage( $sMaxage );
		}
	}

	/**
	 * Disable checking if-modified-since header if extension in use
	 *
	 * @param array &$times Array of modification times
	 * @param OutputPage $out
	 */
	public static function onOutputPageCheckLastModified( array &$times, OutputPage $out ) {
		if ( !$out->getConfig()->get( 'RandomSelectionDisableIfModifiedSince' ) ) {
			return;
		}
		$req = $out->getRequest();
		if (
			$req->getVal( 'action', 'view' ) !== 'view' ||
			$req->getVal( 'oldid', null ) !== null
		) {
			// If this is not a view action, or we are looking at
			// an old version, our caching scheme below will be wrong.
			return;
		}
		// Note: Our ParserOutput isn't populated yet, so we can't rely
		// on getExtensionData() type things. So we use local APC cache and page_props.
		$cache = self::getCache();
		$id = $out->getTitle()->getArticleId();
		$key = $cache->makeKey( 'RandomSelection', $id );
		$res = $cache->getWithSetCallback( $key, 6 * 60 * 60, function () use ( $id ) {
			$dbr = wfGetDB( DB_REPLICA );
			$res = $dbr->selectField(
				'page_props',
				1,
				[ 'pp_page' => $id, 'pp_propname' => 'RandomSelection' ],
				__METHOD__
			);
			// Some types of caches will store ints more efficiently.
			return (int)$res;
		} );

		// We don't have any invalidation for this cache key. Which is ok,
		// since its not that bad if it is wrong. However, keep track of it,
		// so that on a 304 cache miss we can double check if it is right,
		// and delete it if not.
		$out->setProperty( 'RandomSelection304Cache', (bool)$res );
		if ( $res ) {
			// Its difficult to fully disable, so we just say that the
			// page was last modified right now. In a sense its even true!
			$times['randomselection'] = wfTimestampNow();
		}
	}

	private static function getCache() {
		global $wgMainCacheType;
		return ObjectCache::getLocalServerInstance( $wgMainCacheType );
	}
}
