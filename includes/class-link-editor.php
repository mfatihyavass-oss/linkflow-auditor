<?php
/**
 * Safe post-content link editing and linkable-text extraction.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Link_Editor' ) ) {
	/**
	 * Edits links with DOMDocument while avoiding unsafe text regions.
	 */
	final class LinkFlow_Auditor_Link_Editor {
		private const SUGGESTION_CONTEXT_RADIUS = 58;

		/**
		 * URL/text normalizer.
		 *
		 * @var LinkFlow_Auditor_Url_Normalizer
		 */
		private $url_normalizer;

		/**
		 * Constructor.
		 *
		 * @param LinkFlow_Auditor_Url_Normalizer $url_normalizer URL/text normalizer.
		 */
		public function __construct( LinkFlow_Auditor_Url_Normalizer $url_normalizer ) {
			$this->url_normalizer = $url_normalizer;
		}

		/**
		 * Insert a single internal link around the first safe phrase occurrence.
		 *
		 * @param WP_Post $post       Source post.
		 * @param string  $anchor     Anchor phrase to wrap.
		 * @param string  $target_url Target URL.
		 * @return int|\WP_Error Number of links added, or error.
		 */
		public function insert_internal_link_for_phrase( WP_Post $post, string $anchor, string $target_url ) {
			$content = (string) $post->post_content;

			if ( '' === trim( $content ) || ! class_exists( 'DOMDocument' ) ) {
				return new WP_Error( 'lfa_no_content', esc_html__( 'Yazı içeriği düzenlenemiyor.', 'linkflow-auditor' ) );
			}

			$document = $this->load_content_document( $content );

			if ( ! $document instanceof DOMDocument ) {
				return new WP_Error( 'lfa_parse', esc_html__( 'Yazı içeriği işlenemedi.', 'linkflow-auditor' ) );
			}

			$body = $document->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $body ) {
				return new WP_Error( 'lfa_parse', esc_html__( 'Yazı içeriği işlenemedi.', 'linkflow-auditor' ) );
			}

			$changed = 0;
			foreach ( $body->childNodes as $child ) {
				if ( $this->link_first_phrase_in_node( $document, $child, $anchor, $target_url, false ) ) {
					$changed = 1;
					break;
				}
			}

			if ( $changed < 1 ) {
				return 0;
			}

			$result = wp_update_post(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => $this->body_inner_html( $document ),
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $changed;
		}

		/**
		 * Replace or unwrap every anchor in a post whose href matches the given URL.
		 *
		 * @param int    $post_id Post to edit.
		 * @param string $raw_url Decoded href to match.
		 * @param string $mode    Either 'remove' or 'replace'.
		 * @param string $new_url Replacement URL (for 'replace').
		 * @return int|\WP_Error Number of anchors changed, or error.
		 */
		public function modify_post_links( int $post_id, string $raw_url, string $mode, string $new_url ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'lfa_no_post', esc_html__( 'Yazı bulunamadı.', 'linkflow-auditor' ) );
			}

			$content = (string) $post->post_content;

			if ( '' === trim( $content ) || ! class_exists( 'DOMDocument' ) ) {
				return new WP_Error( 'lfa_no_content', esc_html__( 'Yazı içeriği düzenlenemiyor.', 'linkflow-auditor' ) );
			}

			$document = $this->load_content_document( $content );

			if ( ! $document instanceof DOMDocument ) {
				return new WP_Error( 'lfa_parse', esc_html__( 'Yazı içeriği işlenemedi.', 'linkflow-auditor' ) );
			}

			$target  = $this->url_normalizer->normalize_match_url( $raw_url );
			$changed = 0;
			$nodes   = array();

			foreach ( $document->getElementsByTagName( 'a' ) as $node ) {
				$nodes[] = $node;
			}

			foreach ( $nodes as $node ) {
				$href = (string) $node->getAttribute( 'href' );

				if ( '' === $href || $this->url_normalizer->normalize_match_url( $href ) !== $target ) {
					continue;
				}

				if ( 'replace' === $mode ) {
					$node->setAttribute( 'href', $new_url );
				} else {
					// Unwrap: keep the visible text/children, drop the anchor tag.
					while ( $node->firstChild ) {
						$node->parentNode->insertBefore( $node->firstChild, $node );
					}
					$node->parentNode->removeChild( $node );
				}

				++$changed;
			}

			if ( $changed < 1 ) {
				return 0;
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $this->body_inner_html( $document ),
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $changed;
		}

		/**
		 * Extract text nodes that can be safely wrapped with an anchor.
		 *
		 * @param string $html Raw post content.
		 * @return string[]
		 */
		public function extract_linkable_text_segments( string $html ): array {
			if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
				return array();
			}

			$document = $this->load_content_document( $html );

			if ( ! $document instanceof DOMDocument ) {
				return array();
			}

			$body     = $document->getElementsByTagName( 'body' )->item( 0 );
			$segments = array();

			if ( $body ) {
				foreach ( $body->childNodes as $child ) {
					$this->collect_linkable_text_segments_from_node( $child, $segments, false );
				}
			}

			return $segments;
		}

		/**
		 * Whether a text node is safe enough for automated link wrapping.
		 *
		 * @param string $text Text node value.
		 */
		public function is_linkable_text_value( string $text ): bool {
			if ( '' === trim( $text ) ) {
				return false;
			}

			// Avoid editing shortcode text, where wrapping part of the string can
			// break attributes or shortcode parsing.
			if ( false !== strpos( $text, '[' ) && false !== strpos( $text, ']' ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Find a phrase inside linkable text segments.
		 *
		 * @param string[] $segments Text segments.
		 * @param string   $phrase   Candidate phrase.
		 * @return array{before:string,match:string,after:string}|array{}
		 */
		public function find_phrase_in_segments( array $segments, string $phrase ): array {
			$search_phrase = $this->url_normalizer->mb_lower( $phrase );
			$search_length = $this->url_normalizer->mb_strlen( $search_phrase );

			if ( $search_length <= 0 ) {
				return array();
			}

			foreach ( $segments as $segment ) {
				$offset       = 0;
				$search_index = $this->build_search_index( $segment );
				$search_text  = (string) ( $search_index['text'] ?? '' );
				$offset_map   = (array) ( $search_index['map'] ?? array() );

				while ( true ) {
					$position = $this->url_normalizer->mb_stripos_safe( $search_text, $search_phrase, $offset );
					if ( $position < 0 ) {
						break;
					}

					$match = $this->map_search_match_to_original( $offset_map, $position, $search_length );
					if ( ! empty( $match ) && $this->is_phrase_boundary_match( $segment, (int) $match['position'], (int) $match['length'] ) ) {
						return $this->build_suggestion_context( $segment, (int) $match['position'], (int) $match['length'] );
					}

					$offset = $position + max( 1, $search_length );
				}
			}

			return array();
		}

		/**
		 * Build a short before/match/after context around a matched phrase.
		 *
		 * @param string $text     Source text segment.
		 * @param int    $position Match position.
		 * @param int    $length   Match length.
		 * @return array{before:string,match:string,after:string}
		 */
		public function build_suggestion_context( string $text, int $position, int $length ): array {
			$total        = $this->url_normalizer->mb_strlen( $text );
			$before_start = max( 0, $position - self::SUGGESTION_CONTEXT_RADIUS );
			$after_start  = $position + $length;
			$after_len    = max( 0, min( self::SUGGESTION_CONTEXT_RADIUS, $total - $after_start ) );
			$before       = $this->url_normalizer->mb_substr( $text, $before_start, $position - $before_start );
			$match        = $this->url_normalizer->mb_substr( $text, $position, $length );
			$after        = $this->url_normalizer->mb_substr( $text, $after_start, $after_len );

			if ( $before_start > 0 ) {
				$before = '…' . ltrim( $before );
			}

			if ( $after_start + $after_len < $total ) {
				$after = rtrim( $after ) . '…';
			}

			return array(
				'before' => $before,
				'match'  => $match,
				'after'  => $after,
			);
		}

		/**
		 * Ensure a phrase does not match inside a larger word.
		 *
		 * @param string $text     Text segment.
		 * @param int    $position Match position.
		 * @param int    $length   Match length.
		 */
		public function is_phrase_boundary_match( string $text, int $position, int $length ): bool {
			$before = $position > 0 ? $this->url_normalizer->mb_substr( $text, $position - 1, 1 ) : '';
			$after  = $this->url_normalizer->mb_substr( $text, $position + $length, 1 );

			return ! $this->is_word_character( $before ) && ! $this->is_word_character( $after );
		}

		/**
		 * Whether one character is a word character.
		 *
		 * @param string $char Character.
		 */
		public function is_word_character( string $char ): bool {
			return '' !== $char && 1 === preg_match( '/^[\p{L}\p{N}_]$/u', $char );
		}

		/**
		 * Extract link href and anchor text values from HTML.
		 *
		 * @param string $html HTML content.
		 * @return array<int,array{href:string,text:string}>
		 */
		public function extract_links( string $html ): array {
			if ( '' === trim( $html ) ) {
				return array();
			}

			$links   = array();
			$charset = get_bloginfo( 'charset' ) ?: 'UTF-8';

			if ( class_exists( 'DOMDocument' ) ) {
				$document = new DOMDocument();
				$previous = libxml_use_internal_errors( true );
				$loaded   = $document->loadHTML(
					'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=' . htmlspecialchars( $charset, ENT_QUOTES, 'UTF-8' ) . '"></head><body>' . $html . '</body></html>',
					LIBXML_NOWARNING | LIBXML_NOERROR
				);

				libxml_clear_errors();
				libxml_use_internal_errors( $previous );

				if ( $loaded ) {
					foreach ( $document->getElementsByTagName( 'a' ) as $node ) {
						$href = $node->getAttribute( 'href' );

						if ( is_string( $href ) && '' !== trim( $href ) ) {
							$links[] = array(
								'href' => $href,
								'text' => $this->normalize_anchor_text( (string) $node->textContent ),
							);
						}
					}

					return $links;
				}
			}

			if ( preg_match_all( '/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/isu', $html, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$href = $match[2] ?? '';

					if ( is_string( $href ) && '' !== trim( $href ) ) {
						$links[] = array(
							'href' => $href,
							'text' => $this->normalize_anchor_text( wp_strip_all_tags( (string) ( $match[3] ?? '' ) ) ),
						);
					}
				}
			}

			if ( empty( $links ) && preg_match_all( '/<a\s[^>]*href\s*=\s*([^\s>"\']+)/iu', $html, $matches ) ) {
				foreach ( $matches[1] as $href ) {
					if ( is_string( $href ) && '' !== trim( $href ) ) {
						$links[] = array(
							'href' => $href,
							'text' => '',
						);
					}
				}
			}

			return $links;
		}

		/**
		 * Extract href values from HTML.
		 *
		 * @param string $html HTML content.
		 * @return string[]
		 */
		public function extract_hrefs( string $html ): array {
			$hrefs = array();

			foreach ( $this->extract_links( $html ) as $link ) {
				$hrefs[] = (string) ( $link['href'] ?? '' );
			}

			return $hrefs;
		}

		/**
		 * Normalize anchor text for compact reports.
		 *
		 * @param string $text Anchor text.
		 */
		public function normalize_anchor_text( string $text ): string {
			$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
			$text = preg_replace( '/\s+/u', ' ', $text );

			return trim( (string) $text );
		}

		/**
		 * Recursively link the first safe occurrence inside a DOM node.
		 *
		 * @param DOMDocument $document   DOM document.
		 * @param DOMNode     $node       Current node.
		 * @param string      $anchor     Anchor phrase.
		 * @param string      $target_url Target URL.
		 * @param bool        $blocked    Whether an ancestor is not linkable.
		 */
		private function link_first_phrase_in_node( DOMDocument $document, DOMNode $node, string $anchor, string $target_url, bool $blocked ): bool {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				return ! $blocked && $this->link_phrase_in_text_node( $document, $node, $anchor, $target_url );
			}

			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				$name = $this->url_normalizer->mb_lower( (string) $node->nodeName );
				if ( in_array( $name, array( 'a', 'script', 'style', 'textarea', 'code', 'pre', 'kbd', 'samp' ), true ) ) {
					$blocked = true;
				}
			}

			if ( ! $node->hasChildNodes() ) {
				return false;
			}

			$children = array();
			foreach ( $node->childNodes as $child ) {
				$children[] = $child;
			}

			foreach ( $children as $child ) {
				if ( $this->link_first_phrase_in_node( $document, $child, $anchor, $target_url, $blocked ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Wrap a matching phrase inside one text node.
		 *
		 * @param DOMDocument $document   DOM document.
		 * @param DOMNode     $node       Text node.
		 * @param string      $anchor     Anchor phrase.
		 * @param string      $target_url Target URL.
		 */
		private function link_phrase_in_text_node( DOMDocument $document, DOMNode $node, string $anchor, string $target_url ): bool {
			$text = (string) $node->nodeValue;

			if ( ! $this->is_linkable_text_value( $text ) ) {
				return false;
			}

			$offset        = 0;
			$search_anchor = $this->url_normalizer->mb_lower( $anchor );
			$search_length = $this->url_normalizer->mb_strlen( $search_anchor );
			$search_index  = $this->build_search_index( $text );
			$search_text   = (string) ( $search_index['text'] ?? '' );
			$offset_map    = (array) ( $search_index['map'] ?? array() );
			$match_info    = array();

			if ( $search_length <= 0 ) {
				return false;
			}

			while ( true ) {
				$position = $this->url_normalizer->mb_stripos_safe( $search_text, $search_anchor, $offset );
				if ( $position < 0 ) {
					return false;
				}

				$match_info = $this->map_search_match_to_original( $offset_map, $position, $search_length );
				if ( ! empty( $match_info ) && $this->is_phrase_boundary_match( $text, (int) $match_info['position'], (int) $match_info['length'] ) ) {
					break;
				}

				$offset = $position + max( 1, $search_length );
			}

			$parent = $node->parentNode;
			if ( ! $parent ) {
				return false;
			}

			$position = (int) $match_info['position'];
			$length   = (int) $match_info['length'];
			$before   = $this->url_normalizer->mb_substr( $text, 0, $position );
			$match    = $this->url_normalizer->mb_substr( $text, $position, $length );
			$after    = $this->url_normalizer->mb_substr( $text, $position + $length );

			if ( '' !== $before ) {
				$parent->insertBefore( $document->createTextNode( $before ), $node );
			}

			$link = $document->createElement( 'a' );
			$link->setAttribute( 'href', esc_url_raw( $target_url ) );
			$link->appendChild( $document->createTextNode( $match ) );
			$parent->insertBefore( $link, $node );

			if ( '' !== $after ) {
				$parent->insertBefore( $document->createTextNode( $after ), $node );
			}

			$parent->removeChild( $node );

			return true;
		}

		/**
		 * Build normalized search text and a map back to original character offsets.
		 *
		 * @param string $text Original text.
		 * @return array{text:string,map:array<int,int>}
		 */
		private function build_search_index( string $text ): array {
			$search = '';
			$map    = array();
			$length = $this->url_normalizer->mb_strlen( $text );

			for ( $i = 0; $i < $length; ++$i ) {
				$char       = $this->url_normalizer->mb_substr( $text, $i, 1 );
				$normalized = $this->url_normalizer->mb_lower( $char );
				$norm_len   = $this->url_normalizer->mb_strlen( $normalized );

				if ( $norm_len <= 0 ) {
					continue;
				}

				$search .= $normalized;

				for ( $j = 0; $j < $norm_len; ++$j ) {
					$map[] = $i;
				}
			}

			return array(
				'text' => $search,
				'map'  => $map,
			);
		}

		/**
		 * Convert a normalized search match to original text offsets.
		 *
		 * @param array<int,int> $offset_map Search-offset to original-offset map.
		 * @param int            $position Search text match position.
		 * @param int            $length Search text match length.
		 * @return array{position:int,length:int}|array{}
		 */
		private function map_search_match_to_original( array $offset_map, int $position, int $length ): array {
			if ( $position < 0 || $length <= 0 ) {
				return array();
			}

			$end_position = $position + $length - 1;

			if ( ! isset( $offset_map[ $position ], $offset_map[ $end_position ] ) ) {
				return array();
			}

			$original_start = (int) $offset_map[ $position ];
			$original_end   = (int) $offset_map[ $end_position ] + 1;

			if ( $original_end <= $original_start ) {
				return array();
			}

			return array(
				'position' => $original_start,
				'length'   => $original_end - $original_start,
			);
		}

		/**
		 * Walk a DOM node tree and collect linkable text.
		 *
		 * @param DOMNode  $node     Current node.
		 * @param string[] $segments Collected text segments.
		 * @param bool     $blocked  Whether an ancestor is not linkable.
		 */
		private function collect_linkable_text_segments_from_node( DOMNode $node, array &$segments, bool $blocked ): void {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$text = (string) $node->nodeValue;

				if ( ! $blocked && $this->is_linkable_text_value( $text ) ) {
					$segments[] = $text;
				}

				return;
			}

			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				$name = $this->url_normalizer->mb_lower( (string) $node->nodeName );
				if ( in_array( $name, array( 'a', 'script', 'style', 'textarea', 'code', 'pre', 'kbd', 'samp' ), true ) ) {
					$blocked = true;
				}
			}

			if ( ! $node->hasChildNodes() ) {
				return;
			}

			foreach ( $node->childNodes as $child ) {
				$this->collect_linkable_text_segments_from_node( $child, $segments, $blocked );
			}
		}

		/**
		 * Load post content inside a synthetic body.
		 *
		 * @param string $content Raw post content.
		 * @return DOMDocument|null
		 */
		private function load_content_document( string $content ): ?DOMDocument {
			$charset  = get_bloginfo( 'charset' ) ?: 'UTF-8';
			$document = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$loaded   = $document->loadHTML(
				'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=' . htmlspecialchars( $charset, ENT_QUOTES, 'UTF-8' ) . '"></head><body>' . $content . '</body></html>',
				LIBXML_NOWARNING | LIBXML_NOERROR
			);

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			return $loaded ? $document : null;
		}

		/**
		 * Return the synthetic body HTML.
		 *
		 * @param DOMDocument $document DOM document.
		 */
		private function body_inner_html( DOMDocument $document ): string {
			$body     = $document->getElementsByTagName( 'body' )->item( 0 );
			$new_html = '';

			if ( $body ) {
				foreach ( $body->childNodes as $child ) {
					$new_html .= $document->saveHTML( $child );
				}
			}

			return $new_html;
		}
	}
}
