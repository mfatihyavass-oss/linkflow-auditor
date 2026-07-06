<?php
/**
 * HTTP status checker for link reports.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Http_Link_Checker' ) ) {
	/**
	 * Requests URLs and preserves the first reportable redirect status.
	 */
	final class LinkFlow_Auditor_Http_Link_Checker {
		private const VERSION               = '1.10.4';
		private const REDIRECT_STATUS_CODES = array( 301, 302, 307, 308 );

		/**
		 * URL normalizer.
		 *
		 * @var LinkFlow_Auditor_Url_Normalizer
		 */
		private $url_normalizer;

		/**
		 * Constructor.
		 *
		 * @param LinkFlow_Auditor_Url_Normalizer $url_normalizer URL normalizer.
		 */
		public function __construct( LinkFlow_Auditor_Url_Normalizer $url_normalizer ) {
			$this->url_normalizer = $url_normalizer;
		}

		/**
		 * Request a URL and follow redirects manually so the first 3XX code is preserved.
		 *
		 * @param string $url URL.
		 * @return array<string,mixed>
		 */
		public function request_http_status( string $url ): array {
			$current_url = $url;
			$visited     = array();
			$result      = array(
				'status_code'          => 0,
				'final_status_code'    => 0,
				'redirect_status_code' => 0,
				'final_url'            => $url,
				'error'                => '',
			);

			for ( $i = 0; $i < 6; ++$i ) {
				$visited[ md5( $current_url ) ] = true;
				$response = $this->request_url_once( $current_url );

				if ( is_wp_error( $response ) ) {
					$result['error']     = $response->get_error_message();
					$result['final_url'] = $current_url;
					break;
				}

				$status_code = (int) wp_remote_retrieve_response_code( $response );

				if ( 0 === (int) $result['status_code'] ) {
					$result['status_code'] = $status_code;
				}

				$result['final_status_code'] = $status_code;
				$result['final_url']         = $current_url;

				if ( $this->is_reportable_redirect_status( $status_code ) && 0 === (int) $result['redirect_status_code'] ) {
					$result['redirect_status_code'] = $status_code;
				}

				if ( ! $this->is_any_redirect_status( $status_code ) ) {
					break;
				}

				$location = $this->get_response_location( $response );
				if ( '' === $location ) {
					break;
				}

				$next_url = $this->resolve_redirect_url( $location, $current_url );
				if ( '' === $next_url || isset( $visited[ md5( $next_url ) ] ) ) {
					break;
				}

				$current_url = $next_url;
			}

			return $result;
		}

		/**
		 * Request one URL without following redirects.
		 *
		 * @param string $url URL.
		 * @return array<string,mixed>|WP_Error
		 */
		private function request_url_once( string $url ) {
			$args = (array) apply_filters(
				'linkflow_auditor_http_request_args',
				array(
					'timeout'             => 8,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'user-agent'          => 'LinkFlow Auditor/' . self::VERSION . '; ' . home_url( '/' ),
					'limit_response_size' => 1024,
				),
				$url
			);

			$response = wp_safe_remote_head( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );

			if ( in_array( $status_code, array( 0, 403, 405, 501 ), true ) ) {
				$get_response = wp_safe_remote_get( $url, $args );

				if ( ! is_wp_error( $get_response ) ) {
					$response = $get_response;
				}
			}

			return $response;
		}

		/**
		 * Get a response Location header.
		 *
		 * @param array<string,mixed> $response HTTP response.
		 */
		private function get_response_location( array $response ): string {
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( is_array( $location ) ) {
				$location = end( $location );
			}

			if ( ! is_scalar( $location ) ) {
				return '';
			}

			return trim( html_entity_decode( (string) $location, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
		}

		/**
		 * Resolve a redirect Location value against the previous URL.
		 *
		 * @param string $location Location header value.
		 * @param string $base_url Previous URL.
		 */
		private function resolve_redirect_url( string $location, string $base_url ): string {
			$parts = $this->url_normalizer->parse_href( $location, $base_url );

			return empty( $parts ) ? '' : $this->url_normalizer->build_url_from_parts( $parts );
		}

		/**
		 * Whether a status code should appear in the redirect report.
		 *
		 * @param int $status_code HTTP status code.
		 */
		private function is_reportable_redirect_status( int $status_code ): bool {
			return in_array( $status_code, self::REDIRECT_STATUS_CODES, true );
		}

		/**
		 * Whether a status code is any redirect that should be followed.
		 *
		 * @param int $status_code HTTP status code.
		 */
		private function is_any_redirect_status( int $status_code ): bool {
			return $status_code >= 300 && $status_code < 400;
		}
	}
}
