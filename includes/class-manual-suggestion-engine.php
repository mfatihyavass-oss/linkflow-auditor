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
		private const MANUAL_SUGGESTION_POOL_LIMIT = 1000;

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
		 * Automatic suggestion phrase helper.
		 *
		 * @var LinkFlow_Auditor_Link_Suggestion_Engine
		 */
		private $suggestion_engine;

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
		 * @param LinkFlow_Auditor_Link_Suggestion_Engine $suggestion_engine Suggestion phrase helper.
		 * @param callable                        $content_ids_provider Content ID provider.
		 */
		public function __construct( LinkFlow_Auditor_Report_Store $store, LinkFlow_Auditor_Url_Normalizer $url_normalizer, LinkFlow_Auditor_Link_Editor $link_editor, LinkFlow_Auditor_Link_Suggestion_Engine $suggestion_engine, callable $content_ids_provider ) {
			$this->store                = $store;
			$this->url_normalizer       = $url_normalizer;
			$this->link_editor          = $link_editor;
			$this->suggestion_engine    = $suggestion_engine;
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
		 * @param array<string,bool> $excluded_ids Suggestion IDs to skip.
		 * @param int    $limit      Maximum suggestions to return.
		 * @return array<int,array<string,mixed>>
		 */
		public function build_manual_link_suggestions( string $anchor, string $target_url, string $sort, array $excluded_ids = array(), int $limit = self::MANUAL_SUGGESTION_LIMIT ): array {
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

			return $this->limit_suggestions( $suggestions, $excluded_ids, $limit );
		}

		/**
		 * Build suggestions for links that can be added from one source URL.
		 *
		 * @param string             $source_url   Source content URL.
		 * @param string             $sort         Sort mode.
		 * @param array<string,bool> $excluded_ids Suggestion IDs to skip.
		 * @param int                $limit        Maximum suggestions to return.
		 * @return array<int,array<string,mixed>>|\WP_Error
		 */
		public function build_source_url_link_suggestions( string $source_url, string $sort, array $excluded_ids = array(), int $limit = self::MANUAL_SUGGESTION_LIMIT ) {
			$normalized_url = $this->normalize_manual_target_url( $source_url );
			if ( is_wp_error( $normalized_url ) ) {
				return new WP_Error( 'lfa_source_url_bad', esc_html__( 'Kaynak URL sitenizin yayınlanmış iç URL’lerinden biri olmalı.', 'linkflow-auditor' ) );
			}

			$source = $this->find_post_by_url( (string) $normalized_url );
			if ( is_wp_error( $source ) ) {
				return $source;
			}

			return $this->build_link_suggestions_from_post( $source, $sort, $excluded_ids, $limit );
		}

		/**
		 * Build outgoing internal-link suggestions for one published post by its ID.
		 *
		 * Used by the post/page editor metabox so suggestions are generated for the
		 * content the user is currently editing without a URL round-trip.
		 *
		 * @param int                $source_id    Source post ID.
		 * @param string             $sort         Sort mode.
		 * @param array<string,bool> $excluded_ids Suggestion IDs to skip.
		 * @param int                $limit        Maximum suggestions to return.
		 * @return array<int,array<string,mixed>>|\WP_Error
		 */
		public function build_link_suggestions_for_post( int $source_id, string $sort, array $excluded_ids = array(), int $limit = self::MANUAL_SUGGESTION_LIMIT ) {
			$source = get_post( $source_id );

			// Suggestions are offered for drafts too, so the target links can be added
			// while the content is still being written. Only trashed/auto-draft (empty)
			// content is rejected. Link targets are still limited to published content.
			if ( ! $source instanceof WP_Post || in_array( (string) $source->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				return new WP_Error( 'lfa_source_unavailable', esc_html__( 'Bu içerik taranamıyor. Önce içeriği kaydedin.', 'linkflow-auditor' ) );
			}

			return $this->build_link_suggestions_from_post( $source, $sort, $excluded_ids, $limit );
		}

		/**
		 * Build outgoing internal-link suggestions for a resolved source post.
		 *
		 * @param WP_Post            $source       Source post.
		 * @param string             $sort         Sort mode.
		 * @param array<string,bool> $excluded_ids Suggestion IDs to skip.
		 * @param int                $limit        Maximum suggestions to return.
		 * @return array<int,array<string,mixed>>|\WP_Error
		 */
		private function build_link_suggestions_from_post( WP_Post $source, string $sort, array $excluded_ids = array(), int $limit = self::MANUAL_SUGGESTION_LIMIT ) {
			if ( '' === trim( (string) $source->post_content ) || ! class_exists( 'DOMDocument' ) ) {
				return array();
			}

			$segments = $this->link_editor->extract_linkable_text_segments( (string) $source->post_content );
			if ( empty( $segments ) ) {
				return array();
			}

			$ids_provider  = $this->content_ids_provider;
			$ids           = $ids_provider();
			if ( empty( $ids ) ) {
				return array();
			}

			$row_map       = $this->store->get_report_row_map();
			$source_id     = (int) $source->ID;
			$source_row    = (array) ( $row_map[ $source_id ] ?? array() );
			$source_url    = (string) get_permalink( $source_id );
			$existing_urls = $this->get_existing_internal_url_map( $source, $source_url );
			$haystack      = $this->url_normalizer->mb_lower( implode( ' ', $segments ) );
			$suggestions   = array();

			$targets = get_posts(
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

			foreach ( $targets as $target ) {
				if ( ! $target instanceof WP_Post ) {
					continue;
				}

				$target_id = (int) $target->ID;
				if ( $target_id <= 0 || $target_id === $source_id ) {
					continue;
				}

				$target_url = get_permalink( $target_id );
				if ( ! $target_url ) {
					continue;
				}

				$target_compare = $this->url_normalizer->normalize_link_url_for_compare( (string) $target_url, $source_url );
				if ( '' !== $target_compare && isset( $existing_urls[ $target_compare ] ) ) {
					continue;
				}

				foreach ( $this->suggestion_engine->build_suggestion_phrases( $target_id, (string) $target_url ) as $phrase ) {
					$phrase = (string) $phrase;
					if ( '' === $phrase || false === strpos( $haystack, $this->url_normalizer->mb_lower( $phrase ) ) ) {
						continue;
					}

					$match = $this->link_editor->find_phrase_in_segments( $segments, $phrase );
					if ( empty( $match ) ) {
						continue;
					}

					$target_row   = (array) ( $row_map[ $target_id ] ?? array() );
					$published_at = isset( $target_row['published_at'] ) ? (int) $target_row['published_at'] : (int) get_post_time( 'U', false, $target_id );
					$anchor       = (string) ( $match['match'] ?? $phrase );

					$suggestions[] = array(
						'id'                       => substr( hash( 'sha256', 'manual_url|' . $source_id . '|' . $target_id . '|' . $this->url_normalizer->mb_lower( $anchor ) ), 0, 18 ),
						'source_id'                => $source_id,
						'source_title'             => $this->get_source_title( $source ),
						'source_url'               => $source_url,
						'source_outgoing_links'    => isset( $source_row['outgoing_links'] ) ? (int) $source_row['outgoing_links'] : count( $this->link_editor->extract_links( (string) $source->post_content ) ),
						'source_incoming_sources'  => isset( $source_row['incoming_sources'] ) ? (int) $source_row['incoming_sources'] : 0,
						'source_published_at'      => isset( $source_row['published_at'] ) ? (int) $source_row['published_at'] : (int) get_post_time( 'U', false, $source_id ),
						'target_id'                => $target_id,
						'target_title'             => $this->get_source_title( $target ),
						'target_url'               => (string) $target_url,
						'target_edit_url'          => (string) get_edit_post_link( $target_id, '' ),
						'target_type'              => (string) get_post_type( $target_id ),
						'target_incoming_sources'  => isset( $target_row['incoming_sources'] ) ? (int) $target_row['incoming_sources'] : 0,
						'target_incoming_links'    => isset( $target_row['incoming_links'] ) ? (int) $target_row['incoming_links'] : 0,
						'target_published_at'      => $published_at,
						'target_outgoing_links'    => isset( $target_row['outgoing_links'] ) ? (int) $target_row['outgoing_links'] : 0,
						'anchor'                   => $anchor,
						'context_before'           => (string) ( $match['before'] ?? '' ),
						'context_match'            => (string) ( $match['match'] ?? $phrase ),
						'context_after'            => (string) ( $match['after'] ?? '' ),
						'reason'                   => __( 'Kaynak URL’de hedef sayfayı anlatan güvenli bir ifade bulundu.', 'linkflow-auditor' ),
					);

					break;
				}

				if ( count( $suggestions ) >= self::MANUAL_SUGGESTION_POOL_LIMIT ) {
					break;
				}
			}

			usort(
				$suggestions,
				static function ( array $a, array $b ) use ( $sort ): int {
					if ( 'oldest' === $sort ) {
						return ( $a['target_published_at'] ?? 0 ) <=> ( $b['target_published_at'] ?? 0 );
					}

					if ( 'newest' === $sort ) {
						return ( $b['target_published_at'] ?? 0 ) <=> ( $a['target_published_at'] ?? 0 );
					}

					$link_cmp = ( $a['target_incoming_sources'] ?? 0 ) <=> ( $b['target_incoming_sources'] ?? 0 );
					if ( 0 !== $link_cmp ) {
						return $link_cmp;
					}

					$target_cmp = ( $a['target_outgoing_links'] ?? 0 ) <=> ( $b['target_outgoing_links'] ?? 0 );
					if ( 0 !== $target_cmp ) {
						return $target_cmp;
					}

					return strcasecmp( (string) ( $a['target_title'] ?? '' ), (string) ( $b['target_title'] ?? '' ) );
				}
			);

			return $this->limit_suggestions( $suggestions, $excluded_ids, $limit );
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
		 * Find a published content item by its permalink.
		 *
		 * @param string $url Source URL.
		 * @return WP_Post|\WP_Error
		 */
		private function find_post_by_url( string $url ) {
			$ids_provider = $this->content_ids_provider;
			$ids          = $ids_provider();
			$needle       = $this->url_normalizer->normalize_link_url_for_compare( $url, home_url( '/' ) );

			if ( '' === $needle || empty( $ids ) ) {
				return new WP_Error( 'lfa_source_url_not_found', esc_html__( 'Bu URL’ye ait yayınlanmış içerik bulunamadı.', 'linkflow-auditor' ) );
			}

			foreach ( $ids as $id ) {
				$id        = (int) $id;
				$permalink = get_permalink( $id );
				if ( ! $permalink ) {
					continue;
				}

				$compare = $this->url_normalizer->normalize_link_url_for_compare( (string) $permalink, (string) $permalink );
				if ( '' !== $compare && $compare === $needle ) {
					$post = get_post( $id );
					if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
						return $post;
					}
				}
			}

			return new WP_Error( 'lfa_source_url_not_found', esc_html__( 'Bu URL’ye ait yayınlanmış içerik bulunamadı.', 'linkflow-auditor' ) );
		}

		/**
		 * Build a lookup of internal URLs already linked from the source content.
		 *
		 * @param WP_Post $source Source post.
		 * @param string  $source_url Source permalink.
		 * @return array<string,bool>
		 */
		private function get_existing_internal_url_map( WP_Post $source, string $source_url ): array {
			$existing = array();

			foreach ( $this->link_editor->extract_links( (string) $source->post_content ) as $link ) {
				$href = $this->url_normalizer->normalize_link_url_for_compare( (string) ( $link['href'] ?? '' ), $source_url );

				if ( '' !== $href ) {
					$existing[ $href ] = true;
				}
			}

			return $existing;
		}

		/**
		 * Exclude already-shown suggestions and return a capped batch.
		 *
		 * @param array<int,array<string,mixed>> $suggestions Suggestions.
		 * @param array<string,bool>             $excluded_ids IDs to skip.
		 * @param int                            $limit Maximum suggestions to return.
		 * @return array<int,array<string,mixed>>
		 */
		private function limit_suggestions( array $suggestions, array $excluded_ids, int $limit ): array {
			$limit = max( 1, min( self::MANUAL_SUGGESTION_POOL_LIMIT, $limit ) );
			$batch = array();

			foreach ( $suggestions as $suggestion ) {
				$id = sanitize_key( (string) ( $suggestion['id'] ?? '' ) );
				if ( '' !== $id && isset( $excluded_ids[ $id ] ) ) {
					continue;
				}

				$batch[] = $suggestion;

				if ( count( $batch ) >= $limit ) {
					break;
				}
			}

			return $batch;
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
