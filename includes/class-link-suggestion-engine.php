<?php
/**
 * Automatic internal-link suggestion engine.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Link_Suggestion_Engine' ) ) {
	/**
	 * Builds automatic internal-link suggestions from scan candidates.
	 */
	final class LinkFlow_Auditor_Link_Suggestion_Engine {
		private const SUGGESTION_LIST_CAP                  = 1000;
		private const SUGGESTION_CANDIDATES_PER_SOURCE     = 30;
		private const SUGGESTIONS_PER_SOURCE               = 3;
		private const SUGGESTIONS_PER_TARGET               = 10;

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
		 * Constructor.
		 *
		 * @param LinkFlow_Auditor_Report_Store   $store Report store.
		 * @param LinkFlow_Auditor_Url_Normalizer $url_normalizer URL/text normalizer.
		 * @param LinkFlow_Auditor_Link_Editor    $link_editor Link editor.
		 */
		public function __construct( LinkFlow_Auditor_Report_Store $store, LinkFlow_Auditor_Url_Normalizer $url_normalizer, LinkFlow_Auditor_Link_Editor $link_editor ) {
			$this->store          = $store;
			$this->url_normalizer = $url_normalizer;
			$this->link_editor    = $link_editor;
		}

		/**
		 * Build safe anchor phrases from a target's title and slug.
		 *
		 * @param int    $post_id Target post ID.
		 * @param string $url     Target permalink.
		 * @return string[]
		 */
		public function build_suggestion_phrases( int $post_id, string $url ): array {
			$phrases = array();
			$title   = html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post_id ) ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );

			$this->add_suggestion_phrase( $phrases, $title );
			$this->add_suggestion_phrase( $phrases, $this->build_core_suggestion_phrase( $title ) );
			$this->add_suggestion_phrase( $phrases, $this->build_slug_suggestion_phrase( $url ) );

			$values = array_values( $phrases );
			usort(
				$values,
				function ( string $a, string $b ): int {
					$word_cmp = $this->suggestion_word_count( $b ) <=> $this->suggestion_word_count( $a );
					if ( 0 !== $word_cmp ) {
						return $word_cmp;
					}

					return $this->url_normalizer->mb_strlen( $b ) <=> $this->url_normalizer->mb_strlen( $a );
				}
			);

			return array_slice( $values, 0, 5 );
		}

		/**
		 * Normalize titles/slugs into a phrase that can be searched in text.
		 *
		 * @param string $phrase Raw phrase.
		 */
		public function normalize_suggestion_phrase( string $phrase ): string {
			$phrase = html_entity_decode( wp_strip_all_tags( $phrase ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
			$phrase = str_replace( array( 'İ', "i\u{0307}" ), 'i', $phrase );
			$phrase = str_replace( array( '-', '_', '/', '\\' ), ' ', $phrase );
			$phrase = preg_replace( '/[^\p{L}\p{N}\s&#+.]+/u', ' ', $phrase );
			$phrase = preg_replace( '/\s+/u', ' ', (string) $phrase );

			return trim( (string) $phrase );
		}

		/**
		 * Count words in a suggestion phrase.
		 *
		 * @param string $phrase Phrase.
		 */
		public function suggestion_word_count( string $phrase ): int {
			return count( $this->split_suggestion_words( $phrase ) );
		}

		/**
		 * Generic/low-value anchor phrases (already lowercased) that hurt SEO.
		 *
		 * @return string[]
		 */
		public function generic_anchor_texts(): array {
			return array(
				'buraya', 'buraya tıklayın', 'buraya tıklayınız', 'buraya tıkla', 'buradan',
				'buradan ulaşın', 'buradan bakın', 'tıkla', 'tıklayın', 'tıklayınız', 'tıklayarak',
				'link', 'linke tıkla', 'linke tıklayın', 'bağlantı', 'bu bağlantı', 'bu link',
				'devamı', 'devamı için', 'devamını oku', 'devamını okuyun', 'devamını okumak için',
				'oku', 'okuyun', 'daha fazla', 'daha fazlası', 'daha fazla bilgi', 'detay', 'detaylar',
				'detaylı bilgi', 'detaylı bilgi için', 'incele', 'inceleyin', 'göz atın', 'bkz', 'bkz.',
				'here', 'click here', 'click', 'read more', 'more', 'this', 'link here',
			);
		}

		/**
		 * Collect internal-link suggestion candidates for one source post.
		 *
		 * @param array<string,mixed> $state          Scan state, passed by reference.
		 * @param WP_Post             $post           Source post.
		 * @param string              $source_url     Source permalink.
		 * @param array<int,bool>     $linked_targets Targets already linked from this source.
		 */
		public function collect_internal_link_suggestion_candidates( array &$state, WP_Post $post, string $source_url, array $linked_targets ): void {
			if ( (int) ( $state['suggestion_total'] ?? 0 ) >= self::SUGGESTION_LIST_CAP ) {
				return;
			}

			$content = (string) $post->post_content;
			if ( '' === trim( $content ) || ! class_exists( 'DOMDocument' ) ) {
				return;
			}

			$segments = $this->link_editor->extract_linkable_text_segments( $content );
			if ( empty( $segments ) ) {
				return;
			}

			$haystack  = $this->url_normalizer->mb_lower( implode( ' ', $segments ) );
			$source_id = (int) $post->ID;
			$targets   = (array) ( $state['targets'] ?? array() );
			$source    = isset( $targets[ $source_id ] ) && is_array( $targets[ $source_id ] ) ? $targets[ $source_id ] : array();
			$added     = 0;

			foreach ( $targets as $target_id => $target ) {
				$target_id = (int) $target_id;

				if ( $target_id <= 0 || $target_id === $source_id || isset( $linked_targets[ $target_id ] ) || ! is_array( $target ) ) {
					continue;
				}

				foreach ( (array) ( $target['suggestion_phrases'] ?? array() ) as $phrase ) {
					$phrase = (string) $phrase;

					if ( '' === $phrase || false === strpos( $haystack, $this->url_normalizer->mb_lower( $phrase ) ) ) {
						continue;
					}

					$match = $this->link_editor->find_phrase_in_segments( $segments, $phrase );
					if ( empty( $match ) ) {
						continue;
					}

					$state['suggestion_candidates'][] = array(
						'source_id'       => $source_id,
						'source_title'    => (string) ( $source['title'] ?? $this->get_source_title( $post ) ),
						'source_url'      => $source_url,
						'source_edit_url' => (string) ( $source['edit_url'] ?? get_edit_post_link( $source_id, '' ) ),
						'source_type'     => (string) ( $source['type'] ?? $post->post_type ),
						'target_id'       => $target_id,
						'target_title'    => (string) ( $target['title'] ?? '' ),
						'target_url'      => (string) ( $target['url'] ?? '' ),
						'target_edit_url' => (string) ( $target['edit_url'] ?? '' ),
						'target_type'     => (string) ( $target['type'] ?? '' ),
						'anchor'          => (string) ( $match['match'] ?? $phrase ),
						'context_before'  => (string) ( $match['before'] ?? '' ),
						'context_match'   => (string) ( $match['match'] ?? $phrase ),
						'context_after'   => (string) ( $match['after'] ?? '' ),
						'phrase_words'    => $this->suggestion_word_count( $phrase ),
					);

					$state['suggestion_total'] = (int) ( $state['suggestion_total'] ?? 0 ) + 1;
					++$added;
					break;
				}

				if ( $added >= self::SUGGESTION_CANDIDATES_PER_SOURCE || (int) ( $state['suggestion_total'] ?? 0 ) >= self::SUGGESTION_LIST_CAP ) {
					return;
				}
			}
		}

		/**
		 * Build prioritized internal link suggestions from collected candidates.
		 *
		 * @param array<string,mixed>             $state Scan state.
		 * @param array<int,array<string,mixed>> $rows  Finalized internal rows.
		 * @return array<int,array<string,mixed>>
		 */
		public function build_suggestion_report( array $state, array $rows ): array {
			$candidates = array_values( array_filter( (array) ( $state['suggestion_candidates'] ?? array() ), 'is_array' ) );

			if ( empty( $candidates ) ) {
				return array();
			}

			$row_map      = array();
			$max_incoming = 0;
			$ignored      = $this->store->get_ignored_suggestion_ids();

			foreach ( $rows as $row ) {
				$target_id = (int) ( $row['id'] ?? 0 );
				if ( $target_id <= 0 ) {
					continue;
				}

				$incoming_sources     = (int) ( $row['incoming_sources'] ?? 0 );
				$row_map[ $target_id ] = $row;
				$max_incoming         = max( $max_incoming, $incoming_sources );
			}

			$deduped = array();

			foreach ( $candidates as $candidate ) {
				$source_id = (int) ( $candidate['source_id'] ?? 0 );
				$target_id = (int) ( $candidate['target_id'] ?? 0 );
				$anchor    = trim( (string) ( $candidate['anchor'] ?? '' ) );

				if ( $source_id <= 0 || $target_id <= 0 || $source_id === $target_id || '' === $anchor || empty( $row_map[ $target_id ] ) ) {
					continue;
				}

				$key           = $source_id . '|' . $target_id . '|' . $this->url_normalizer->mb_lower( $anchor );
				$suggestion_id = substr( hash( 'sha256', $key ), 0, 18 );

				if ( isset( $ignored[ $suggestion_id ] ) ) {
					continue;
				}

				if ( isset( $deduped[ $key ] ) ) {
					continue;
				}

				$target_row = $row_map[ $target_id ];
				$source_row = (array) ( $row_map[ $source_id ] ?? array() );
				$incoming   = (int) ( $target_row['incoming_sources'] ?? 0 );
				$words      = max( 1, (int) ( $candidate['phrase_words'] ?? $this->suggestion_word_count( $anchor ) ) );
				$score      = max( 1, $max_incoming + 1 - $incoming ) * 20 + min( 45, $words * 7 );

				if ( (string) ( $candidate['source_type'] ?? '' ) === (string) ( $candidate['target_type'] ?? '' ) ) {
					$score += 5;
				}

				$deduped[ $key ] = array_merge(
					$candidate,
					array(
						'id'                      => $suggestion_id,
						'source_outgoing_links'   => (int) ( $source_row['outgoing_links'] ?? 0 ),
						'source_incoming_sources' => (int) ( $source_row['incoming_sources'] ?? 0 ),
						'source_published_at'     => (int) ( $source_row['published_at'] ?? 0 ),
						'target_incoming_sources' => $incoming,
						'target_incoming_links'   => (int) ( $target_row['incoming_links'] ?? 0 ),
						'score'                   => $score,
						'reason'                  => 0 === $incoming
							? __( 'Hedef sayfa henüz iç link almıyor; ifade kaynak içerikte geçiyor.', 'linkflow-auditor' )
							: __( 'Hedef sayfa daha az iç link alıyor; ifade kaynak içerikte geçiyor.', 'linkflow-auditor' ),
					)
				);
			}

			$suggestions = array_values( $deduped );

			usort(
				$suggestions,
				static function ( array $a, array $b ): int {
					$incoming_cmp = ( $a['target_incoming_sources'] ?? 0 ) <=> ( $b['target_incoming_sources'] ?? 0 );
					if ( 0 !== $incoming_cmp ) {
						return $incoming_cmp;
					}

					$score_cmp = ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
					if ( 0 !== $score_cmp ) {
						return $score_cmp;
					}

					$source_cmp = strcasecmp( (string) ( $a['source_title'] ?? '' ), (string) ( $b['source_title'] ?? '' ) );
					if ( 0 !== $source_cmp ) {
						return $source_cmp;
					}

					return strcasecmp( (string) ( $a['target_title'] ?? '' ), (string) ( $b['target_title'] ?? '' ) );
				}
			);

			$limited    = array();
			$per_source = array();
			$per_target = array();

			foreach ( $suggestions as $suggestion ) {
				$source_id = (int) ( $suggestion['source_id'] ?? 0 );
				$target_id = (int) ( $suggestion['target_id'] ?? 0 );

				if ( $source_id <= 0 || $target_id <= 0 ) {
					continue;
				}

				if ( (int) ( $per_source[ $source_id ] ?? 0 ) >= self::SUGGESTIONS_PER_SOURCE ) {
					continue;
				}

				if ( (int) ( $per_target[ $target_id ] ?? 0 ) >= self::SUGGESTIONS_PER_TARGET ) {
					continue;
				}

				$limited[]                = $suggestion;
				$per_source[ $source_id ] = (int) ( $per_source[ $source_id ] ?? 0 ) + 1;
				$per_target[ $target_id ] = (int) ( $per_target[ $target_id ] ?? 0 ) + 1;

				if ( count( $limited ) >= self::SUGGESTION_LIST_CAP ) {
					break;
				}
			}

			return $limited;
		}

		/**
		 * Add a normalized suggestion phrase when it is specific enough.
		 *
		 * @param array<string,string> $phrases Phrase map keyed by lowercase value.
		 * @param string               $phrase  Candidate phrase.
		 */
		private function add_suggestion_phrase( array &$phrases, string $phrase ): void {
			$phrase = $this->normalize_suggestion_phrase( $phrase );

			if ( '' === $phrase || ! $this->is_valid_suggestion_phrase( $phrase ) ) {
				return;
			}

			$phrases[ $this->url_normalizer->mb_lower( $phrase ) ] = $phrase;
		}

		/**
		 * Whether an anchor suggestion is specific enough to avoid noisy one-word links.
		 *
		 * @param string $phrase Normalized phrase.
		 */
		private function is_valid_suggestion_phrase( string $phrase ): bool {
			$length = $this->url_normalizer->mb_strlen( $phrase );
			$words  = $this->suggestion_word_count( $phrase );

			if ( $length < 4 || $words > 8 ) {
				return false;
			}

			if ( $words < 2 && $length < 8 ) {
				return false;
			}

			return ! in_array( $this->url_normalizer->mb_lower( $phrase ), $this->generic_anchor_texts(), true );
		}

		/**
		 * Build a shorter title phrase by dropping question/helper words.
		 *
		 * @param string $title Target title.
		 */
		private function build_core_suggestion_phrase( string $title ): string {
			$normalized = $this->normalize_suggestion_phrase( $title );
			$words      = $this->split_suggestion_words( $normalized );

			if ( count( $words ) < 3 ) {
				return '';
			}

			$noise = array(
				'nasıl', 'nasil', 'yapılır', 'yapilir', 'nedir', 'ne', 'demek',
				'rehberi', 'kilavuzu', 'kılavuzu', 'icin', 'için', 've', 'ile',
				'bir', 'en', 'iyi', 'pratikleri', 'örnekleri', 'ornekleri',
			);

			$kept = array();
			foreach ( $words as $word ) {
				if ( in_array( $this->url_normalizer->mb_lower( $word ), $noise, true ) ) {
					continue;
				}

				$kept[] = $word;
			}

			if ( count( $kept ) < 2 ) {
				return '';
			}

			return implode( ' ', array_slice( $kept, 0, 5 ) );
		}

		/**
		 * Build an anchor phrase from the target URL slug.
		 *
		 * @param string $url Target URL.
		 */
		private function build_slug_suggestion_phrase( string $url ): string {
			$parts = wp_parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
				return '';
			}

			$path = trim( (string) $parts['path'], '/' );
			if ( '' === $path ) {
				return '';
			}

			$segments = explode( '/', $path );
			$slug     = rawurldecode( (string) end( $segments ) );

			return str_replace( array( '-', '_' ), ' ', $slug );
		}

		/**
		 * Split a suggestion phrase into words.
		 *
		 * @param string $phrase Phrase.
		 * @return string[]
		 */
		private function split_suggestion_words( string $phrase ): array {
			$words = preg_split( '/\s+/u', trim( $phrase ) );

			return array_values( array_filter( is_array( $words ) ? $words : array(), 'strlen' ) );
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
