<?php namespace davestewart\tokenstring;

/**
 * Class TokenString
 *
 * @package davestewart\tokenstring
 *
 * @property string $source
 * @property string $value
 */
class TokenString
{
	// ------------------------------------------------------------------------------------------------
	// STATIC PROPERTIES

		/**
		 * The global regex to match a {token} and (capture) its name
		 *
		 * The pattern should have start and ending delimiters
		 *
		 * @var string $_pattern
		 */
		public static $regex = '!{([\.\w]+)}!';

	
	// ------------------------------------------------------------------------------------------------
	// PROPERTIES

		/**
		 * The source string comprising of text and {tokens}
		 *
		 * @var string $source
		 */
		protected $source;

		/**
		 * The name => value replacement hash with which to interpolate the string
		 *
		 * @var mixed[]
		 */
		protected $data;

		/**
		 * A name => match hash of tokens matches
		 *
		 * @var string[]
		 */
		protected $matches;

		/**
		 * A name => regex hash of token content filters
		 *
		 * @var string[]
		 */
		protected $filters;

		/**
		 * The global regex to match a {token} and (capture) its name
		 *
		 * The pattern should have start and ending delimiters
		 *
		 * @var string $tokenRegex
		 */
		protected $tokenRegex;

		/**
		 * The full regex to match the source string, including tokens, and capture passed content
		 *
		 * This has to be cached as the process to create it is expensive
		 *
		 * @var string
		 */
		protected $sourceRegex;


	// ------------------------------------------------------------------------------------------------
	// INSTANTIATION

		/**
		 * StringTemplate constructor
		 *
		 * @param   string          $source         An optional source string
		 * @param   string|null     $localRegex     An optional token-matching regex, defaults to the global token regex {token}
		 */
		public function __construct($source = '', $localRegex = null)
		{
			// parameters
			if($localRegex == null)
			{
				$localRegex = self::$regex;
			}

			// properties
			$this->data             = [];
			$this->matches          = [];
			$this->filters          = [];
			$this->tokenRegex       = $localRegex;
			$this->sourceRegex      = '';
			$this->setSource($source);
		}

		/**
		 * Chainable TokenString constructor
		 *
		 * Passing a regex updates the global regex pattern
		 *
		 * @param   string          $source         An optional source string
		 * @param   string|null     $globalRegex    An optional token-matching regex, defaults to the global token regex {token}
		 * @return  TokenString
		 */
		public static function make($source = '', $globalRegex = null)
		{
			if($globalRegex)
			{
				self::$regex = $globalRegex;
			}
			return new TokenString($source, $globalRegex);
		}


	// ------------------------------------------------------------------------------------------------
	// ACCESSORS

		/**
		 * Set the source string
		 *
		 * Also sets tokens arrays
		 *
		 * @param   string      $source
		 * @return  TokenString
		 */
		public function setSource($source)
		{
			// properties
			$this->source       = $source;
			$this->sourceRegex  = '';

			//matches
			preg_match_all($this->tokenRegex, $source, $matches);
			$this->matches      = array_combine($matches[1], $matches[0]);

			// return
			return $this;
		}

		/**
		 * Sets the token replacement data
		 *
		 * Pass in:
		 *
		 *  - a name and value
		 *  - a single token => value hash, with an optional true to merge data
		 *
		 * Values can be:
		 *
		 *  - strings, numbers, or any stringable value
		 *  - a TokenString instance, for nested replacements
		 *  - a function that returns a string, of the form: function($name, $source, $instance) { }
		 *
		 * @param   string|array $name
		 * @param   array|null   $data
		 * @return  TokenString
		 */
		public function setData($name, $data = null)
		{
			if(is_array($name))
			{
				$name = $this->makeAssociative($name);
				$this->data = $data === true
					? $this->data + $name
					: $name;
			}
			else
			{
				if($data)
				{
					$this->data[$name] = $data;
				}
				else
				{
					unset($this->data[$name]);
				}
			}
			return $this;
		}

		/**
		 * Sets match patterns for individual tokens
		 *
		 * Pass in:
		 *
		 *  - a name and regex
		 *  - a single name => value hash, with an optional boolean true to merge patterns
		 *
		 * Values should be regular expressions that match the expected token content
		 *
		 * DO NOT (!) include:
		 *
		 *  - regex delimiters
		 *  - capturing parentheses
		 *
		 * These will be added for you
		 *
		 * @param   string|array    $name        The name of the token to match
		 * @param   array|null      $regex      The regex pattern to match potential token content
		 * @return  TokenString
		 */
		public function setMatch($name, array $regex = null)
		{
			// clear existing match
			$this->sourceRegex = '';

			if(is_array($name))
			{
				$name = $this->makeAssociative($name);
				$this->filters = $regex === true
					? $this->filters + $name
					: $name;
			}
			else
			{
				if($regex)
				{
					$this->filters[$name] = $regex;
				}
				else
				{
					unset($this->filters[$name]);
				}
			}
			return $this;
		}

		public function __get($name)
		{
			if($name === 'value')
			{
				return (string) $this->render();
			}
			if(property_exists($this, $name))
			{
				return $this->$name;
			}
			throw new \Exception("Unknown property '$name'");
		}

	// ------------------------------------------------------------------------------------------------
	// PUBLIC METHODS

		/**
		 * Render and return the string by expanding source tokens, without updating the source
		 *
		 * Use this when you want to keep the source template tokens in place for further
		 * processing, for example you're running in a loop and only need the returned
		 * value
		 *
		 * Note that you can also pass in multiple parameters which will match the source tokens
		 * as they are found
		 *
		 * @param   array   $data       Optional data to populate the string with; is merged with the existing data
		 * @return  string
		 */
		public function render($data = null)
		{
			if(func_num_args() == 0)
			{
				$data = $this->data;
			}
			else if(is_array($data))
			{
				$data = array_merge($this->data, $this->makeAssociative($data));
			}
			else
			{
				$data = array_merge($this->data, $this->makeAssociative(func_get_args()));
			}
			return $this->replace($this->source, $data);
		}

		/**
		 * Expand source tokens, update the source, and chain the original object
		 *
		 * Use this when you want to replace source tokens with the current data, so they're
		 * not processed in further loops. Usually you'll leave some tokens unresolved
		 * (by not supplying data for them) for later processing via render() or match()
		 *
		 * @param   bool    $filter     Optional flag to remove expanded data keys
		 * @return  TokenString
		 */
		public function resolve($filter = false)
		{
			// cache old matches;
			$matches = [] + $this->matches;

			// update string
			$this->setSource($this->replace($this->source, $this->data));

			// filter out old data
			if($filter)
			{
				$this->data = array_intersect_key($this->data, $matches);
			}

			// return
			return $this;
		}

		/**
		 * Special method to expand variables, and return the updated of the
		 * original TokenString instance
		 *
		 * Use this when replacements themselves return further tokens you need to
		 * populate via render() but you don't want to update the original source string
		 *
		 * @param   array|null      $data
		 * @return  TokenString
		 */
		public function chain($data = null)
		{
			return $this->setSource($this->render($data ?: $this->data));
		}

		/**
		 * Attempts to match an arbitrary string against the current template
		 *
		 * Use this when you need to check if an incoming string matches the constraints
		 *
		 * Internally, this method
		 *
		 * @param   string  $input      An input string to match
		 * @param   string  $format     An optional regex format to surround the source-matching pattern;
		 *                                - start and end delimiter characters are mandatory; defaults to back-ticks (`)
		 *                                - add mode modifiers as required; defaults to case-insensitive (i)
		 *                                - include add start or end anchors; defaults to both (^ and $)
		 *                                - use $0 as the placeholder for the source regex
		 * @return  array|null
		 */
		public function match($input, $format = '`^$0$`i')
		{
			// get regex
			if($this->sourceRegex === '')
			{
				$this->sourceRegex = $this->getSourceRegex($format);
			}

			// match
			preg_match($this->sourceRegex, $input, $matches);

			// convert matches to named capture array
			if(count($matches))
			{
				array_shift($matches);
				return array_combine(array_keys($this->matches), $matches);
			}
			return false;
		}

		/**
		 * Returns a regex that matches the source string and replacement pattern filters
		 *
		 * @param   string  $format     An optional regex format to surround the source-matching pattern;
		 *                                - start and end delimiter characters are mandatory; defaults to back-ticks (`)
		 *                                - add mode modifiers as required; defaults to case-insensitive (i)
		 *                                - include add start or end anchors; defaults to both (^ and $)
		 *                                - use $0 as the placeholder for the source regex
		 * @return  string
		 */
		public function getSourceRegex($format = '`^$0$`i')
		{
			// the process of building a suitable match string is complicated due to the
			// fact that we need to escape the source string - but don't want to escape
			// either the original tokens, or the the regex matches that will match the
			// target string. as such, we need to employ some substitution trickery, and
			// build the final regex in 4 phases.
			//
			// the 1st phase sets up the data we will need for the following phases,
			// including adding capturing brackets around the regexps. the 2nd phase swaps
			// the original tokens for placeholders which are formatted in a way be immune
			// to escaping; this will allow us to escape the entire string in the 3rd phase,
			// then in 4th phase, swap out the placeholders for the individual regexes.

			// variables
			$delimiter      = substr($format, 0, 1);
			$source         = $this->source;
			$placeholders   = [];
			$filters        = [];

			// phase 1: build arrays
			foreach ($this->matches as $name => $match)
			{
				// variables
				$placeholder            = '%%' . strtoupper($name) . '%%';
				$filter                 = isset($this->filters[$name])
											? $this->filters[$name]
											: '.*';

				// update arrays
				$placeholders[$match]   = $placeholder;
				$filters[$placeholder]  = "($filter)";
			}

			// phase 2: replace tokens with placeholders
			foreach ($placeholders as $match => $placeholder)
			{
				$source = str_replace($match, $placeholder, $source);
			}

			// phase 3: quote source
			$source = preg_quote($source, $delimiter);

			// phase 4: replace placeholders with filters
			foreach ($filters as $placeholder => $filter)
			{
				$source = str_replace($placeholder, str_replace($delimiter, '\\' . $delimiter, $filter), $source);
			}

			// debug
			//pd($source);

			// return
			return str_replace('$0', $source, $format);
		}

	// ------------------------------------------------------------------------------------------------
	// PROTECTED METHODS

		protected function replace($source, array $data)
		{
			foreach($this->matches as $name => $match)
			{
				// ignore unset keys
				if(isset($data[$name]))
				{
					// get the replacement
					$replace = $data[$name];

					// if not a string, resolve
					if( ! is_string($replace) )
					{
						if($replace instanceof TokenString)
						{
							$replace = $replace->render($data);
						}
						else if (is_callable($replace) )
						{
							$replace = call_user_func($replace, $name);
						}
					}

					// replace the original token
					$source = str_replace($match, (string) $replace, $source);
				}
			}

			// return
			return $source;
		}

		/**
		 * Checks if passed array is numeric, and if so, converts to associative,
		 * using the current match values. Output array is clipped to the shorter
		 * of the two array lengths
		 *
		 * @param   array   $values
		 * @return  array
		 */
		protected function makeAssociative($values)
		{
			// return if already associative
			if($this->isAssociative($values))
			{
				return $values;
			}

			// get existing keys
			$keys       = array_keys($this->matches);
			$numKeys    = count($keys);
			$numVals    = count($values);

			// make arrays the same length
			if($numKeys < $numVals)
			{
				$values = array_slice($values, 0, $numKeys);
			}
			else if($numKeys > $numVals)
			{
				$keys = array_slice($keys, 0, $numVals);
			}

			// return
			return array_combine($keys, $values);
		}

		protected function isAssociative($arr)
		{
			foreach ($arr as $key => $value)
			{
				if (is_string($key)) return true;
			}
			return false;
		}


	// ------------------------------------------------------------------------------------------------
	// UTILITIES

		public function __toString()
		{
			return (string) $this->render();
		}

}
