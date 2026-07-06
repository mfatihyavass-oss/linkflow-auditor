<?php
/**
 * URL and UTF-8 string normalization helpers.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Url_Normalizer' ) ) {
	/**
	 * Normalizes URLs and multibyte strings used by scans and editors.
	 */
	final class LinkFlow_Auditor_Url_Normalizer {
		/**
		 * Parse absolute, root-relative, and source-relative href values.
		 *
		 * @param string $href Raw href.
		 * @param string $source_url Source permalink.
		 * @return array<string,string>
		 */
		public function parse_href( string $href, string $source_url ): array {
			$home_url    = home_url( '/' );
			$home_scheme = (string) ( wp_parse_url( $home_url, PHP_URL_SCHEME ) ?: 'https' );

			if ( 0 === strpos( $href, '//' ) ) {
				$href = $home_scheme . ':' . $href;
			}

			if ( 0 === stripos( $href, 'www.' ) ) {
				$href = $home_scheme . '://' . $href;
			}

			if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $href ) ) {
				$parts = wp_parse_url( $href );
				return is_array( $parts ) ? $this->stringify_url_parts( $parts ) : array();
			}

			if ( 0 === strpos( $href, '/' ) ) {
				$parts = wp_parse_url( $home_url );
				if ( ! is_array( $parts ) ) {
					return array();
				}

				$href_parts        = wp_parse_url( $href );
				$parts['path']     = is_array( $href_parts ) && isset( $href_parts['path'] ) ? $href_parts['path'] : '/';
				$parts['query']    = is_array( $href_parts ) && isset( $href_parts['query'] ) ? $href_parts['query'] : '';
				$parts['fragment'] = '';

				return $this->stringify_url_parts( $parts );
			}

			$source_parts = wp_parse_url( $source_url );
			$href_parts   = wp_parse_url( $href );

			if ( ! is_array( $source_parts ) || ! is_array( $href_parts ) ) {
				return array();
			}

			$source_path = isset( $source_parts['path'] ) ? (string) $source_parts['path'] : '/';
			$base_path   = '/' === substr( $source_path, -1 ) ? $source_path : trailingslashit( dirname( $source_path ) );
			$href_path   = isset( $href_parts['path'] ) ? (string) $href_parts['path'] : '';

			$source_parts['path']     = $this->remove_dot_segments( $base_path . $href_path );
			$source_parts['query']    = isset( $href_parts['query'] ) ? (string) $href_parts['query'] : '';
			$source_parts['fragment'] = '';

			return $this->stringify_url_parts( $source_parts );
		}

		/**
		 * Cast parse_url result values to strings.
		 *
		 * @param array<string,mixed> $parts URL parts.
		 * @return array<string,string>
		 */
		public function stringify_url_parts( array $parts ): array {
			$string_parts = array();

			foreach ( $parts as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$string_parts[ (string) $key ] = (string) $value;
				}
			}

			return $string_parts;
		}

		/**
		 * Build lookup keys for a target URL.
		 *
		 * @param string $url Target URL.
		 * @param int    $post_id Target ID.
		 * @return string[]
		 */
		public function get_url_index_keys( string $url, int $post_id ): array {
			$keys  = array(
				'query:p=' . $post_id,
				'query:page_id=' . $post_id,
			);
			$parts = wp_parse_url( $url );

			if ( is_array( $parts ) ) {
				$path   = isset( $parts['path'] ) ? $this->normalize_path( (string) $parts['path'] ) : '/';
				$keys[] = 'path:' . $path;
			}

			return array_values( array_unique( $keys ) );
		}

		/**
		 * Check whether parsed URL parts point to this site's host.
		 *
		 * @param array<string,string> $parts URL parts.
		 */
		public function is_internal_url_parts( array $parts ): bool {
			$home_host = $this->normalize_host( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '' ) );
			$link_host = $this->normalize_host( (string) ( $parts['host'] ?? '' ) );

			return '' !== $home_host && $home_host === $link_host;
		}

		/**
		 * Build a URL string from parsed parts without the fragment.
		 *
		 * @param array<string,string> $parts URL parts.
		 */
		public function build_url_from_parts( array $parts ): string {
			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			$host   = (string) ( $parts['host'] ?? '' );

			if ( '' === $scheme || '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return '';
			}

			$port  = isset( $parts['port'] ) && '' !== (string) $parts['port'] ? ':' . absint( $parts['port'] ) : '';
			$path  = isset( $parts['path'] ) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
			$query = array_key_exists( 'query', $parts ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';

			return esc_url_raw( $scheme . '://' . $host . $port . $path . $query );
		}

		/**
		 * Normalize a URL for internal link duplicate comparison.
		 *
		 * @param string $url        Raw URL/href.
		 * @param string $source_url Source permalink for relative hrefs.
		 */
		public function normalize_link_url_for_compare( string $url, string $source_url ): string {
			$parts = $this->parse_href( trim( $url ), '' !== $source_url ? $source_url : home_url( '/' ) );

			if ( empty( $parts ) || ! $this->is_internal_url_parts( $parts ) ) {
				return '';
			}

			$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
			$host   = $this->normalize_host( (string) ( $parts['host'] ?? '' ) );
			$path   = isset( $parts['path'] ) ? $this->normalize_path( (string) $parts['path'] ) : '/';
			$query  = isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';

			if ( '' === $host ) {
				return '';
			}

			return $scheme . '://' . $host . $path . $query;
		}

		/**
		 * Normalize a URL for loose comparison (entity-decode + trim).
		 */
		public function normalize_match_url( string $url ): string {
			$url = html_entity_decode( $url, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );

			return trim( $url );
		}

		/**
		 * Normalize a URL host for internal-domain comparison.
		 *
		 * @param string $host Host.
		 */
		public function normalize_host( string $host ): string {
			$host = $this->mb_lower( trim( $host ) );

			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			return $host;
		}

		/**
		 * Multibyte-safe lowercasing so Turkish/UTF-8 slugs match consistently.
		 *
		 * @param string $value Value to lowercase.
		 */
		public function mb_lower( string $value ): string {
			if ( function_exists( 'mb_strtolower' ) ) {
				return str_replace( "i\u{0307}", 'i', mb_strtolower( $value, 'UTF-8' ) );
			}

			return strtolower( $value );
		}

		/**
		 * Multibyte-safe string length.
		 *
		 * @param string $value Value.
		 */
		public function mb_strlen( string $value ): int {
			if ( function_exists( 'mb_strlen' ) ) {
				return (int) mb_strlen( $value, 'UTF-8' );
			}

			return strlen( $value );
		}

		/**
		 * Multibyte-safe substring.
		 *
		 * @param string   $value  Value.
		 * @param int      $start  Start offset.
		 * @param int|null $length Optional length.
		 */
		public function mb_substr( string $value, int $start, ?int $length = null ): string {
			if ( function_exists( 'mb_substr' ) ) {
				if ( null === $length ) {
					return mb_substr( $value, $start, null, 'UTF-8' );
				}

				return mb_substr( $value, $start, $length, 'UTF-8' );
			}

			return null === $length ? substr( $value, $start ) : substr( $value, $start, $length );
		}

		/**
		 * Multibyte-safe case-insensitive substring search.
		 *
		 * @param string $haystack Text to search.
		 * @param string $needle   Phrase to find.
		 * @param int    $offset   Search offset.
		 */
		public function mb_stripos_safe( string $haystack, string $needle, int $offset = 0 ): int {
			if ( '' === $needle ) {
				return -1;
			}

			$position = function_exists( 'mb_stripos' )
				? mb_stripos( $haystack, $needle, $offset, 'UTF-8' )
				: stripos( $haystack, $needle, $offset );

			return false === $position ? -1 : (int) $position;
		}

		/**
		 * Normalize URL path for lookups.
		 *
		 * @param string $path Path.
		 */
		public function normalize_path( string $path ): string {
			if ( '' === $path ) {
				$path = '/';
			}

			$path = rawurldecode( $path );
			$path = str_replace( '\\', '/', $path );

			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . $path;
			}

			$path = preg_replace( '#/+#', '/', $path );
			$path = $this->remove_dot_segments( (string) $path );
			$path = preg_replace( '#/index\.php$#i', '/', $path );
			$path = untrailingslashit( (string) $path );

			if ( '' === $path ) {
				$path = '/';
			}

			return $this->mb_lower( $path );
		}

		/**
		 * Remove ./ and ../ segments from a URL path.
		 *
		 * @param string $path Path.
		 */
		public function remove_dot_segments( string $path ): string {
			$segments = explode( '/', $path );
			$output   = array();

			foreach ( $segments as $segment ) {
				if ( '' === $segment || '.' === $segment ) {
					continue;
				}

				if ( '..' === $segment ) {
					array_pop( $output );
					continue;
				}

				$output[] = $segment;
			}

			return '/' . implode( '/', $output );
		}
	}
}
