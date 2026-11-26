<?php
/**
 * Minimal WP_HTML_Tag_Processor double for changelog tests.
 *
 * The WordPress class calls helper functions this suite does not load.
 *
 * @package updatebee
 */

// phpcs:disable

if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {

	/**
	 * Applies attribute updates to opening tags.
	 */
	class WP_HTML_Tag_Processor {

		/**
		 * Document being edited.
		 *
		 * @var string
		 */
		private $html = '';

		/**
		 * Offset of the next search.
		 *
		 * @var int
		 */
		private $cursor = 0;

		/**
		 * Opening tag currently matched.
		 *
		 * @var array{start: int, length: int, tag: string}|null
		 */
		private $current = null;

		/**
		 * @param string $html HTML document.
		 */
		public function __construct( $html ) {
			$this->html = (string) $html;
		}

		/**
		 * Move to the next opening tag.
		 *
		 * @param string|array<string, string>|null $query Tag name or query.
		 * @return bool
		 */
		public function next_tag( $query = null ) {
			$tag = 'a';
			if ( is_string( $query ) && '' !== $query ) {
				$tag = $query;
			} elseif ( is_array( $query ) && isset( $query['tag_name'] ) && is_string( $query['tag_name'] ) ) {
				$tag = $query['tag_name'];
			}

			$pattern = '/<' . preg_quote( $tag, '/' ) . '\b[^>]*>/i';
			if ( ! preg_match( $pattern, $this->html, $matches, PREG_OFFSET_CAPTURE, $this->cursor ) ) {
				$this->current = null;
				return false;
			}

			$this->current = array(
				'start'  => (int) $matches[0][1],
				'length' => strlen( $matches[0][0] ),
				'tag'    => $matches[0][0],
			);
			$this->cursor  = $this->current['start'] + $this->current['length'];

			return true;
		}

		/**
		 * Read an attribute from the current tag.
		 *
		 * @param string $name Attribute name.
		 * @return string|null
		 */
		public function get_attribute( $name ) {
			if ( null === $this->current ) {
				return null;
			}

			$pattern = '/\s' . preg_quote( (string) $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i';
			if ( ! preg_match( $pattern, $this->current['tag'], $matches ) ) {
				return null;
			}

			return $matches[2];
		}

		/**
		 * Set an attribute on the current tag.
		 *
		 * @param string $name  Attribute name.
		 * @param string $value Attribute value.
		 * @return bool
		 */
		public function set_attribute( $name, $value ) {
			if ( null === $this->current ) {
				return false;
			}

			$name      = (string) $name;
			$attribute = ' ' . $name . '="' . htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ) . '"';
			$pattern   = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\']).*?\1/i';
			if ( preg_match( $pattern, $this->current['tag'] ) ) {
				$new_tag = preg_replace( $pattern, $attribute, $this->current['tag'], 1 );
			} else {
				$new_tag = preg_replace( '/>$/', $attribute . '>', $this->current['tag'], 1 );
			}
			if ( ! is_string( $new_tag ) ) {
				return false;
			}

			$start        = $this->current['start'];
			$length       = $this->current['length'];
			$this->html   = substr( $this->html, 0, $start ) . $new_tag . substr( $this->html, $start + $length );
			$this->cursor += strlen( $new_tag ) - $length;
			$this->current = array(
				'start'  => $start,
				'length' => strlen( $new_tag ),
				'tag'    => $new_tag,
			);

			return true;
		}

		/**
		 * HTML after attribute updates.
		 *
		 * @return string
		 */
		public function get_updated_html() {
			return $this->html;
		}
	}
}
