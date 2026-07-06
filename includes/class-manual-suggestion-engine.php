<?php
/**
 * Manual internal-link suggestion engine.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Manual_Suggestion_Engine' ) ) {
	/**
	 * Finds manual internal-link opportunities for a user-entered phrase.
	 */
	final class LinkFlow_Auditor_Manual_Suggestion_Engine {
		private const MANUAL_SUGGESTION_LIMIT = 25;

		/**
		 * Persistent report/settings store.
		 *
		 * @var LinkFlow_Auditor_Report_Store
		 */
		private $store;

		/**
		 * URL/text normalizer.
		 *
		 * @var LinkFlow_Auditor_Url_Normalizer
		 */
		private $url_normalizer;

		/**
		 * Linkable text extractor.
		 *
		 * @var LinkFlow_Auditor_Link_Editor
		 */
		private $link_editor;

		/**
		 * Callback returning editable content IDs.
		 *
		 * @var callable
		 */
		private $content_ids_provider;

		/**
		 * Constructor.
		 *
		 * @param LinkFlow_Auditor_Report_Store   $store Report store.
		 * @param LinkFlow_Auditor_Url_Normalizer $url_normalizer URL/text normalizer.
		 * @param LinkFlow_Auditor_Link_Editor    $link_editor Link editor.
		 * @param callable                        $content_ids_provider Content ID provider.
		 */
		public function __construct( LinkFlow_Auditor_Report_Store $store, LinkFlow_Auditor_Url_Normalizer $url_normalizer, LinkFlow_Auditor_Link_Editor $link_editor, callable $content_ids_provider ) {
			$this->store                = $store;
			$this->url_normalizer       = $url_normalizer;
			$this->link_editor          = $link_editor;
			$this->content_ids_provider = $content_ids_provider;
		}

		/**
		 * Normalize and validate a manual target URL.
		 *
		 * @param string $target Raw target input.
		 * @return string|\WP_Error
		 */
		public function normalize_manual_target_url( string $target ) {
			$target = trim( $target );

			if ( '' === $target ) {
				return new WP_Error( 'lfa_manual_target_empty', esc_html__( 'Lütfen hedef URL girin.', 'linkflow-auditor' ) );
			}

			$target_key = $this->url_normalizer->mb_lower( $target );
			if ( in_array( $target_key, array( 'ana sayfa', 'anasayfa', 'home', 'homepage' ), true ) ) {
				return home_url( '/' );
			}

			if ( false !== strpos( $target, ' ' ) ) {
				return new WP_Error( 'lfa_manual_target_bad', esc_html__( 'Hedef alanına geçerli bir URL girin.', 'linkflow-auditor' ) );
			}

			$parts = $this->url_normalizer->parse_href( $target, home_url( '/' ) );
			if ( empty( $parts ) || ! $this->url_normalizer->is_internal_url_parts( $parts ) ) {
				return new WP_Error( 'lfa_manual_target_external', esc_html__( 'Manuel öneriler yalnızca sitenin kendi iç URL’leri için oluşturulur.', 'linkflow-auditor' ) );
			}

			$url = $this->url_normalizer->build_url_from_parts( $parts );
			if ( '' === $url ) {
				return new WP_Error( 'lfa_manual_target_bad', esc_html__( 'Hedef alanına geçerli bir URL girin.', 'linkflow-auditor' ) );
			}

			return $url;
		}

		/**
		 * Build up to 25 manual suggestions for a phrase and internal target URL.
		 *
		 * @param string $anchor     Phrase to find.
		 * @param string $target_url Internal target URL.
		 * @param string $sort       Sort mode.
		 * @return array<int,array<string,mixed>>
		 */
		public function build_manual_link_suggestions( string $anchor, string $target_url, string $sort ): array {
			$ids_provider = $this->content_ids_provider;
			$ids          = $ids_provider();
			if ( empty( $ids ) ) {
				return array();
			}

			$row_map = $this->store->get_report_row_map();
			$posts   = get_posts(
				array(
					'post__in'               => $ids,
					'post_type'              => 'any',
					'post_status'            => 'publish',
					'posts_per_page'         => count( $ids ),
					'orderby'                => 'post__in',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			);

			$suggestions = array();
			foreach ( $posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$source_id  = (int) $post->ID;
				$source_url = get_permalink( $source_id );
				if ( ! $source_url || $this->url_normalizer->normalize_link_url_for_compare( (string) $source_url, (string) $source_url ) === $this->url_normalizer->normalize_link_url_for_compare( $target_url, (string) $source_url ) ) {
					continue;
				}

				if ( $this->source_already_links_to_url( $source_id, $target_url ) ) {
					continue;
				}

				$segments = $this->link_editor->extract_linkable_text_segments( (string) $post->post_content );
				$match    = $this->link_editor->find_phrase_in_segments( $segments, $anchor );
				if ( empty( $match ) ) {
					continue;
				}

				$row          = (array) ( $row_map[ $source_id ] ?? array() );
				$published_at = isset( $row['published_at'] ) ? (int) $row['published_at'] : (int) get_post_time( 'U', false, $source_id );

				$suggestions[] = array(
					'id'                       => substr( hash( 'sha256', 'manual|' . $source_id . '|' . $target_url . '|' . $this->url_normalizer->mb_lower( $anchor ) ), 0, 18 ),
					'source_id'                => $source_id,
					'source_title'             => $this->get_source_title( $post ),
					'source_url'               => (string) $source_url,
					'source_outgoing_links'    => isset( $row['outgoing_links'] ) ? (int) $row['outgoing_links'] : count( $this->link_editor->extract_links( (string) $post->post_content ) ),
					'source_incoming_sources'  => isset( $row['incoming_sources'] ) ? (int) $row['incoming_sources'] : 0,
					'source_published_at'      => $published_at,
					'target_url'               => $target_url,
					'anchor'                   => (string) ( $match['match'] ?? $anchor ),
					'context_before'           => (string) ( $match['before'] ?? '' ),
					'context_match'            => (string) ( $match['match'] ?? $anchor ),
					'context_after'            => (string) ( $match['after'] ?? '' ),
				);
			}

			usort(
				$suggestions,
				static function ( array $a, array $b ) use ( $sort ): int {
					if ( 'oldest' === $sort ) {
						return ( $a['source_published_at'] ?? 0 ) <=> ( $b['source_published_at'] ?? 0 );
					}

					if ( 'newest' === $sort ) {
						return ( $b['source_published_at'] ?? 0 ) <=> ( $a['source_published_at'] ?? 0 );
					}

					$link_cmp = ( $a['source_incoming_sources'] ?? 0 ) <=> ( $b['source_incoming_sources'] ?? 0 );
					if ( 0 !== $link_cmp ) {
						return $link_cmp;
					}

					return ( $a['source_published_at'] ?? 0 ) <=> ( $b['source_published_at'] ?? 0 );
				}
			);

			return array_slice( $suggestions, 0, self::MANUAL_SUGGESTION_LIMIT );
		}

		/**
		 * Check whether a source post already links to a normalized URL.
		 *
		 * @param int    $source_id  Source post ID.
		 * @param string $target_url Target URL.
		 */
		public function source_already_links_to_url( int $source_id, string $target_url ): bool {
			$post = get_post( $source_id );
			if ( ! $post instanceof WP_Post ) {
				return false;
			}

			$source_url = (string) get_permalink( $source_id );
			$target     = $this->url_normalizer->normalize_link_url_for_compare( $target_url, $source_url );

			if ( '' === $target ) {
				return false;
			}

			foreach ( $this->link_editor->extract_links( (string) $post->post_content ) as $link ) {
				$href = $this->url_normalizer->normalize_link_url_for_compare( (string) ( $link['href'] ?? '' ), $source_url );

				if ( '' !== $href && $href === $target ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Return a readable source title.
		 *
		 * @param WP_Post $post Source post.
		 */
		private function get_source_title( WP_Post $post ): string {
			$title = get_the_title( $post );

			if ( '' === trim( (string) $title ) ) {
				$title = $post->post_title;
			}

			return (string) $title;
		}
	}
}
