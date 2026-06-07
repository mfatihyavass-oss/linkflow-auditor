<?php
/**
 * Plugin Name: İç Link Sayıcı
 * Plugin URI: https://bursa.mayahukuk.com
 * Description: Yazı ve sayfa içeriklerindeki iç linkleri manuel olarak tarar, en az iç link alan içerikleri üstte raporlar.
 * Version: 1.1.0
 * Author: Maya Hukuk
 * Author URI: https://bursa.mayahukuk.com
 * Requires at least: 6.4
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ic-link-sayici
 *
 * @package Ic_Link_Sayici
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Maya_Ic_Link_Sayici' ) ) {
	/**
	 * Main plugin class.
	 */
	final class Maya_Ic_Link_Sayici {
		private const VERSION       = '1.1.0';
		private const REPORT_OPTION = 'maya_ils_report';
		private const STATE_PREFIX  = 'maya_ils_scan_';
		private const NONCE_ACTION  = 'maya_ils_admin';
		private const PAGE_SLUG     = 'maya-ic-link-sayici';
		private const BATCH_SIZE    = 25;

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Return singleton instance.
		 */
		public static function instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register hooks.
		 */
		private function __construct() {
			add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
			add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

			add_action( 'wp_ajax_maya_ils_start_scan', array( $this, 'ajax_start_scan' ) );
			add_action( 'wp_ajax_maya_ils_scan_batch', array( $this, 'ajax_scan_batch' ) );
			add_action( 'wp_ajax_maya_ils_clear_report', array( $this, 'ajax_clear_report' ) );
		}

		/**
		 * Add the dashboard widget to the admin start screen.
		 */
		public function register_dashboard_widget(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			wp_add_dashboard_widget(
				'maya-ic-link-sayici',
				esc_html__( 'İç Link Sayıcı', 'ic-link-sayici' ),
				array( $this, 'render_dashboard_widget' )
			);
		}

		/**
		 * Add a full report page under Tools.
		 */
		public function register_admin_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			add_management_page(
				esc_html__( 'İç Link Sayıcı', 'ic-link-sayici' ),
				esc_html__( 'İç Link Sayıcı', 'ic-link-sayici' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_admin_page' )
			);
		}

		/**
		 * Load admin assets only where the tool is visible.
		 *
		 * @param string $hook Current admin screen hook.
		 */
		public function enqueue_admin_assets( string $hook ): void {
			if ( 'index.php' !== $hook && 'tools_page_' . self::PAGE_SLUG !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'maya-ils-admin',
				plugins_url( 'assets/admin.css', __FILE__ ),
				array(),
				self::VERSION
			);

			wp_enqueue_script(
				'maya-ils-admin',
				plugins_url( 'assets/admin.js', __FILE__ ),
				array( 'jquery' ),
				self::VERSION,
				true
			);

			wp_localize_script(
				'maya-ils-admin',
				'MayaILS',
				array(
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
					'messages' => array(
						'starting' => esc_html__( 'Tarama hazırlanıyor...', 'ic-link-sayici' ),
						'scanning' => esc_html__( 'Taranıyor...', 'ic-link-sayici' ),
						'done'     => esc_html__( 'Rapor hazır. Sayfa yenileniyor...', 'ic-link-sayici' ),
						'clearing' => esc_html__( 'Rapor siliniyor...', 'ic-link-sayici' ),
						'error'    => esc_html__( 'İşlem tamamlanamadı. Lütfen tekrar deneyin.', 'ic-link-sayici' ),
					),
				)
			);
		}

		/**
		 * Render dashboard widget.
		 */
		public function render_dashboard_widget(): void {
			$report = $this->get_report();

			echo '<div class="maya-ils-widget">';
			$this->render_controls( $report );
			$this->render_status( $report, true );

			if ( ! empty( $report['rows'] ) ) {
				$this->render_report_table( $report, 20 );

				if ( count( $report['rows'] ) > 20 ) {
					printf(
						'<p><a class="button button-secondary" href="%s">%s</a></p>',
						esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ),
						esc_html__( 'Tüm raporu aç', 'ic-link-sayici' )
					);
				}
			}

			echo '</div>';
		}

		/**
		 * Render full admin page.
		 */
		public function render_admin_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'ic-link-sayici' ) );
			}

			$report = $this->get_report();

			echo '<div class="wrap maya-ils-page">';
			echo '<h1>' . esc_html__( 'İç Link Sayıcı', 'ic-link-sayici' ) . '</h1>';
			$this->render_controls( $report );
			$this->render_status( $report, false );
			$this->render_report_table( $report, 0 );
			echo '</div>';
		}

		/**
		 * Render scan and clear controls.
		 *
		 * @param array<string,mixed> $report Existing report.
		 */
		private function render_controls( array $report ): void {
			$clear_disabled = empty( $report['rows'] ) && empty( $report['created_at'] );

			echo '<div class="maya-ils-controls">';
			echo '<button type="button" class="button button-primary maya-ils-start">' . esc_html__( 'Kontrol et', 'ic-link-sayici' ) . '</button> ';
			printf(
				'<button type="button" class="button maya-ils-clear"%s>%s</button>',
				disabled( $clear_disabled, true, false ),
				esc_html__( 'Raporu sil', 'ic-link-sayici' )
			);
			echo '<span class="spinner maya-ils-spinner" aria-hidden="true"></span>';
			echo '</div>';
			echo '<div class="maya-ils-progress" hidden><div class="maya-ils-progress-bar"><span></span></div><strong>0%</strong></div>';
			echo '<div class="maya-ils-message" aria-live="polite"></div>';
		}

		/**
		 * Render report status.
		 *
		 * @param array<string,mixed> $report Existing report.
		 * @param bool                $compact Whether to render compact dashboard copy.
		 */
		private function render_status( array $report, bool $compact ): void {
			if ( empty( $report['created_at'] ) ) {
				echo '<p class="maya-ils-empty">' . esc_html__( 'Henüz rapor yok.', 'ic-link-sayici' ) . '</p>';
				return;
			}

			$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$created_at  = wp_date( $date_format, (int) $report['created_at'] );
			$total_rows  = isset( $report['total_targets'] ) ? (int) $report['total_targets'] : count( $report['rows'] ?? array() );
			$total_src   = isset( $report['total_sources'] ) ? (int) $report['total_sources'] : 0;

			if ( $compact ) {
				printf(
					'<p class="maya-ils-summary">%s <strong>%s</strong> | %s <strong>%d</strong></p>',
					esc_html__( 'Son rapor:', 'ic-link-sayici' ),
					esc_html( $created_at ),
					esc_html__( 'İçerik:', 'ic-link-sayici' ),
					$total_rows
				);
				return;
			}

			printf(
				'<p class="maya-ils-summary">%s <strong>%s</strong> | %s <strong>%d</strong> | %s <strong>%d</strong></p>',
				esc_html__( 'Son rapor:', 'ic-link-sayici' ),
				esc_html( $created_at ),
				esc_html__( 'Raporlanan içerik:', 'ic-link-sayici' ),
				$total_rows,
				esc_html__( 'Taranan kaynak:', 'ic-link-sayici' ),
				$total_src
			);
		}

		/**
		 * Render report table.
		 *
		 * @param array<string,mixed> $report Existing report.
		 * @param int                 $limit  Row limit. Zero means no limit.
		 */
		private function render_report_table( array $report, int $limit ): void {
			$rows = $report['rows'] ?? array();

			if ( empty( $rows ) || ! is_array( $rows ) ) {
				return;
			}

			if ( $limit > 0 ) {
				$rows = array_slice( $rows, 0, $limit );
			}

			echo '<div class="maya-ils-table-wrap">';
			echo '<table class="widefat striped maya-ils-report-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'İçerik', 'ic-link-sayici' ) . '</th>';
			echo '<th>' . esc_html__( 'Tür', 'ic-link-sayici' ) . '</th>';
			echo '<th>' . esc_html__( 'Gelen iç link', 'ic-link-sayici' ) . '</th>';
			echo '<th>' . esc_html__( 'Link veren içerik', 'ic-link-sayici' ) . '</th>';
			echo '<th>' . esc_html__( 'Çıkan iç link', 'ic-link-sayici' ) . '</th>';
			echo '<th>' . esc_html__( 'Link verilen içerik', 'ic-link-sayici' ) . '</th>';
			echo '<th>' . esc_html__( 'İşlem', 'ic-link-sayici' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$title = (string) ( $row['title'] ?? '' );
				if ( '' === trim( $title ) ) {
					$title = __( '(Başlıksız)', 'ic-link-sayici' );
				}

				$type_label = $this->get_post_type_label( (string) ( $row['type'] ?? '' ) );
				$url        = (string) ( $row['url'] ?? '' );
				$edit_url   = (string) ( $row['edit_url'] ?? '' );

				$incoming_total   = isset( $row['incoming_links'] ) ? (int) $row['incoming_links'] : (int) ( $row['total_links'] ?? 0 );
				$incoming_sources = isset( $row['incoming_sources'] ) ? (int) $row['incoming_sources'] : (int) ( $row['unique_sources'] ?? 0 );
				$outgoing_total   = isset( $row['outgoing_links'] ) ? (int) $row['outgoing_links'] : 0;
				$outgoing_targets = isset( $row['outgoing_targets'] ) ? (int) $row['outgoing_targets'] : 0;
				$row_class        = 0 === $incoming_total ? ' class="maya-ils-zero"' : '';

				echo '<tr' . $row_class . '>';
				echo '<td>';

				if ( '' !== $url ) {
					printf(
						'<a href="%s" target="_blank" rel="noopener noreferrer"><strong>%s</strong></a>',
						esc_url( $url ),
						esc_html( $title )
					);
					printf(
						'<div class="maya-ils-url"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></div>',
						esc_url( $url ),
						esc_html( $url )
					);
				} else {
					echo '<strong>' . esc_html( $title ) . '</strong>';
				}

				echo '</td>';
				echo '<td>' . esc_html( $type_label ) . '</td>';
				echo '<td><strong>' . esc_html( number_format_i18n( $incoming_total ) ) . '</strong></td>';
				echo '<td>' . esc_html( number_format_i18n( $incoming_sources ) ) . '</td>';
				echo '<td><strong>' . esc_html( number_format_i18n( $outgoing_total ) ) . '</strong></td>';
				echo '<td>' . esc_html( number_format_i18n( $outgoing_targets ) ) . '</td>';
				echo '<td>';

				if ( '' !== $edit_url ) {
					printf(
						'<a href="%s">%s</a>',
						esc_url( $edit_url ),
						esc_html__( 'Düzenle', 'ic-link-sayici' )
					);
				} else {
					echo '&mdash;';
				}

				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></div>';
		}

		/**
		 * Start a scan and create a temporary state option.
		 */
		public function ajax_start_scan(): void {
			$this->verify_ajax_request();
			$this->raise_limits();

			$ids = $this->get_content_ids();
			if ( empty( $ids ) ) {
				$report = array(
					'created_at'    => time(),
					'site_url'      => home_url( '/' ),
					'total_targets' => 0,
					'total_sources' => 0,
					'rows'          => array(),
				);

				$this->update_nonautoload_option( self::REPORT_OPTION, $report );

				wp_send_json_success(
					array(
						'done'      => true,
						'processed' => 0,
						'total'     => 0,
						'percent'   => 100,
					)
				);
			}

			$target_data = $this->build_target_index( $ids );
			$token       = wp_generate_uuid4();
			$state       = array(
				'user_id'        => get_current_user_id(),
				'started_at'     => time(),
				'source_ids'     => array_values( $ids ),
				'target_ids'     => array_values( array_map( 'intval', array_keys( $target_data['targets'] ) ) ),
				'targets'        => $target_data['targets'],
				'url_index'      => $target_data['url_index'],
				'incoming_links'  => array(),
				'incoming_sources' => array(),
				'outgoing_links'  => array(),
				'outgoing_targets' => array(),
				'offset'         => 0,
			);

			foreach ( $state['target_ids'] as $target_id ) {
				$state['incoming_links'][ $target_id ]   = 0;
				$state['incoming_sources'][ $target_id ] = 0;
				$state['outgoing_links'][ $target_id ]   = 0;
				$state['outgoing_targets'][ $target_id ] = 0;
			}

			$this->save_scan_state( $token, $state );

			wp_send_json_success(
				array(
					'token'     => $token,
					'done'      => false,
					'processed' => 0,
					'total'     => count( $ids ),
					'percent'   => 0,
				)
			);
		}

		/**
		 * Process one scan batch.
		 */
		public function ajax_scan_batch(): void {
			$this->verify_ajax_request();
			$this->raise_limits();

			$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$state = $this->get_scan_state( $token );

			if ( empty( $state ) ) {
				wp_send_json_error(
					array( 'message' => esc_html__( 'Tarama oturumu bulunamadı.', 'ic-link-sayici' ) ),
					404
				);
			}

			if ( (int) ( $state['user_id'] ?? 0 ) !== get_current_user_id() ) {
				wp_send_json_error(
					array( 'message' => esc_html__( 'Bu tarama oturumuna erişim yetkiniz yok.', 'ic-link-sayici' ) ),
					403
				);
			}

			$source_ids = array_map( 'intval', (array) ( $state['source_ids'] ?? array() ) );
			$total      = count( $source_ids );
			$offset     = isset( $state['offset'] ) ? (int) $state['offset'] : 0;
			$batch_size = (int) apply_filters( 'maya_ils_scan_batch_size', self::BATCH_SIZE );
			$batch_size = max( 5, min( 100, $batch_size ) );
			$batch_ids  = array_slice( $source_ids, $offset, $batch_size );

			$this->scan_posts( $batch_ids, $state );

			$offset          += count( $batch_ids );
			$state['offset'] = $offset;
			$done            = $offset >= $total;
			$percent         = $total > 0 ? min( 100, (int) floor( ( $offset / $total ) * 100 ) ) : 100;

			if ( $done ) {
				$report = $this->finalize_report( $state );
				$this->update_nonautoload_option( self::REPORT_OPTION, $report );
				$this->delete_scan_state( $token );
			} else {
				$this->save_scan_state( $token, $state );
			}

			wp_send_json_success(
				array(
					'done'      => $done,
					'processed' => min( $offset, $total ),
					'total'     => $total,
					'percent'   => $done ? 100 : $percent,
				)
			);
		}

		/**
		 * Clear the saved report.
		 */
		public function ajax_clear_report(): void {
			$this->verify_ajax_request();
			delete_option( self::REPORT_OPTION );

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Rapor silindi.', 'ic-link-sayici' ),
				)
			);
		}

		/**
		 * Verify AJAX nonce and capability.
		 */
		private function verify_ajax_request(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => esc_html__( 'Bu işlem için yetkiniz yok.', 'ic-link-sayici' ) ),
					403
				);
			}
		}

		/**
		 * Raise admin memory/time limits where WordPress allows it.
		 */
		private function raise_limits(): void {
			if ( function_exists( 'wp_raise_memory_limit' ) ) {
				wp_raise_memory_limit( 'admin' );
			}

			if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
				@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		/**
		 * Get saved report.
		 *
		 * @return array<string,mixed>
		 */
		private function get_report(): array {
			$report = get_option( self::REPORT_OPTION, array() );

			return is_array( $report ) ? $report : array();
		}

		/**
		 * Get IDs for published posts and pages.
		 *
		 * @return int[]
		 */
		private function get_content_ids(): array {
			$post_types = (array) apply_filters( 'maya_ils_post_types', array( 'post', 'page' ) );
			$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );

			if ( empty( $post_types ) ) {
				$post_types = array( 'post', 'page' );
			}

			$ids = get_posts(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			);

			return array_values( array_map( 'intval', $ids ) );
		}

		/**
		 * Build target metadata and URL lookup table.
		 *
		 * @param int[] $ids Target IDs.
		 * @return array{targets:array<int,array<string,mixed>>,url_index:array<string,int>}
		 */
		private function build_target_index( array $ids ): array {
			$targets   = array();
			$url_index = array();
			$key_count = array();

			foreach ( $ids as $id ) {
				$url = get_permalink( $id );
				if ( ! $url ) {
					continue;
				}

				$targets[ $id ] = array(
					'id'       => $id,
					'title'    => get_the_title( $id ),
					'type'     => get_post_type( $id ),
					'url'      => $url,
					'edit_url' => get_edit_post_link( $id, '' ),
				);

				foreach ( $this->get_url_index_keys( $url, $id ) as $key ) {
					if ( ! isset( $key_count[ $key ] ) ) {
						$key_count[ $key ] = 0;
					}

					++$key_count[ $key ];
					$url_index[ $key ] = $id;
				}
			}

			foreach ( $key_count as $key => $count ) {
				if ( $count > 1 ) {
					unset( $url_index[ $key ] );
				}
			}

			return array(
				'targets'   => $targets,
				'url_index' => $url_index,
			);
		}

		/**
		 * Scan post content for links.
		 *
		 * @param int[]               $post_ids Batch IDs.
		 * @param array<string,mixed> $state Scan state, passed by reference.
		 */
		private function scan_posts( array $post_ids, array &$state ): void {
			if ( empty( $post_ids ) ) {
				return;
			}

			$posts = get_posts(
				array(
					'post__in'               => $post_ids,
					'post_type'              => 'any',
					'post_status'            => 'publish',
					'posts_per_page'         => count( $post_ids ),
					'orderby'                => 'post__in',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			);

			$url_index = (array) ( $state['url_index'] ?? array() );

			foreach ( $posts as $post ) {
				if ( ! $post instanceof WP_Post ) {
					continue;
				}

				$source_id            = (int) $post->ID;
				$source_url           = get_permalink( $source_id );
				$linked_targets       = array();
				$outgoing_link_count  = 0;
				$outgoing_target_ids  = array();
				$hrefs                = $this->extract_hrefs( (string) $post->post_content );

				foreach ( $hrefs as $href ) {
					$target_id = $this->resolve_internal_href( $href, $url_index, (string) $source_url );

					if ( $target_id <= 0 || $target_id === $source_id ) {
						continue;
					}

					if ( ! isset( $state['incoming_links'][ $target_id ] ) ) {
						$state['incoming_links'][ $target_id ] = 0;
					}

					++$state['incoming_links'][ $target_id ];
					++$outgoing_link_count;

					$linked_targets[ $target_id ]      = true;
					$outgoing_target_ids[ $target_id ] = true;
				}

				foreach ( array_keys( $linked_targets ) as $target_id ) {
					if ( ! isset( $state['incoming_sources'][ $target_id ] ) ) {
						$state['incoming_sources'][ $target_id ] = 0;
					}

					++$state['incoming_sources'][ $target_id ];
				}

				if ( isset( $state['outgoing_links'][ $source_id ] ) ) {
					$state['outgoing_links'][ $source_id ] += $outgoing_link_count;
				}

				if ( isset( $state['outgoing_targets'][ $source_id ] ) ) {
					$state['outgoing_targets'][ $source_id ] += count( $outgoing_target_ids );
				}
			}
		}

		/**
		 * Extract href values from HTML.
		 *
		 * @param string $html HTML content.
		 * @return string[]
		 */
		private function extract_hrefs( string $html ): array {
			if ( '' === trim( $html ) ) {
				return array();
			}

			$hrefs = array();

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$processor = new WP_HTML_Tag_Processor( $html );

				while ( $processor->next_tag( 'A' ) ) {
					$href = $processor->get_attribute( 'href' );

					if ( is_string( $href ) && '' !== trim( $href ) ) {
						$hrefs[] = $href;
					}
				}

				return $hrefs;
			}

			if ( preg_match_all( '/<a\s[^>]*href\s*=\s*(["\'])(.*?)\1/iu', $html, $matches ) ) {
				foreach ( $matches[2] as $href ) {
					if ( is_string( $href ) && '' !== trim( $href ) ) {
						$hrefs[] = $href;
					}
				}
			}

			if ( preg_match_all( '/<a\s[^>]*href\s*=\s*([^\s>"\']+)/iu', $html, $matches ) ) {
				foreach ( $matches[1] as $href ) {
					if ( is_string( $href ) && '' !== trim( $href ) ) {
						$hrefs[] = $href;
					}
				}
			}

			return $hrefs;
		}

		/**
		 * Resolve a href to a target post ID when it points to this site.
		 *
		 * @param string            $href Raw href.
		 * @param array<string,int> $url_index URL lookup table.
		 * @param string            $source_url Source permalink for relative URLs.
		 */
		private function resolve_internal_href( string $href, array $url_index, string $source_url ): int {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );

			if ( '' === $href || '#' === $href ) {
				return 0;
			}

			if ( preg_match( '/^(mailto|tel|sms|javascript|data|blob):/i', $href ) ) {
				return 0;
			}

			$parts = $this->parse_href( $href, $source_url );
			if ( empty( $parts ) ) {
				return 0;
			}

			$home_host = $this->normalize_host( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '' ) );
			$link_host = $this->normalize_host( (string) ( $parts['host'] ?? $home_host ) );

			if ( '' !== $link_host && '' !== $home_host && $link_host !== $home_host ) {
				return 0;
			}

			if ( ! empty( $parts['query'] ) ) {
				parse_str( (string) $parts['query'], $query_args );

				foreach ( array( 'p', 'page_id' ) as $query_key ) {
					if ( isset( $query_args[ $query_key ] ) && is_scalar( $query_args[ $query_key ] ) ) {
						$key = 'query:' . $query_key . '=' . absint( $query_args[ $query_key ] );

						if ( isset( $url_index[ $key ] ) ) {
							return (int) $url_index[ $key ];
						}
					}
				}
			}

			$path = isset( $parts['path'] ) ? $this->normalize_path( (string) $parts['path'] ) : '/';
			$key  = 'path:' . $path;

			return isset( $url_index[ $key ] ) ? (int) $url_index[ $key ] : 0;
		}

		/**
		 * Parse absolute, root-relative, and source-relative href values.
		 *
		 * @param string $href Raw href.
		 * @param string $source_url Source permalink.
		 * @return array<string,string>
		 */
		private function parse_href( string $href, string $source_url ): array {
			$home_url    = home_url( '/' );
			$home_scheme = (string) ( wp_parse_url( $home_url, PHP_URL_SCHEME ) ?: 'https' );

			if ( 0 === strpos( $href, '//' ) ) {
				$href = $home_scheme . ':' . $href;
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

				$href_parts       = wp_parse_url( $href );
				$parts['path']    = is_array( $href_parts ) && isset( $href_parts['path'] ) ? $href_parts['path'] : '/';
				$parts['query']   = is_array( $href_parts ) && isset( $href_parts['query'] ) ? $href_parts['query'] : '';
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
		private function stringify_url_parts( array $parts ): array {
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
		private function get_url_index_keys( string $url, int $post_id ): array {
			$keys  = array(
				'query:p=' . $post_id,
				'query:page_id=' . $post_id,
			);
			$parts = wp_parse_url( $url );

			if ( is_array( $parts ) ) {
				$path = isset( $parts['path'] ) ? $this->normalize_path( (string) $parts['path'] ) : '/';
				$keys[] = 'path:' . $path;
			}

			return array_values( array_unique( $keys ) );
		}

		/**
		 * Normalize a URL host for internal-domain comparison.
		 *
		 * @param string $host Host.
		 */
		private function normalize_host( string $host ): string {
			$host = strtolower( trim( $host ) );

			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			return $host;
		}

		/**
		 * Normalize URL path for lookups.
		 *
		 * @param string $path Path.
		 */
		private function normalize_path( string $path ): string {
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

			return strtolower( $path );
		}

		/**
		 * Remove ./ and ../ segments from a URL path.
		 *
		 * @param string $path Path.
		 */
		private function remove_dot_segments( string $path ): string {
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

		/**
		 * Finalize scan state into a saved report.
		 *
		 * @param array<string,mixed> $state Scan state.
		 * @return array<string,mixed>
		 */
		private function finalize_report( array $state ): array {
			$rows    = array();
			$targets = (array) ( $state['targets'] ?? array() );

			foreach ( (array) ( $state['target_ids'] ?? array() ) as $target_id ) {
				$target_id = (int) $target_id;

				if ( empty( $targets[ $target_id ] ) || ! is_array( $targets[ $target_id ] ) ) {
					continue;
				}

				$target = $targets[ $target_id ];
				$rows[] = array(
					'id'               => $target_id,
					'title'            => (string) ( $target['title'] ?? '' ),
					'type'             => (string) ( $target['type'] ?? '' ),
					'url'              => (string) ( $target['url'] ?? '' ),
					'edit_url'         => (string) ( $target['edit_url'] ?? '' ),
					'incoming_links'   => isset( $state['incoming_links'][ $target_id ] ) ? (int) $state['incoming_links'][ $target_id ] : 0,
					'incoming_sources' => isset( $state['incoming_sources'][ $target_id ] ) ? (int) $state['incoming_sources'][ $target_id ] : 0,
					'outgoing_links'   => isset( $state['outgoing_links'][ $target_id ] ) ? (int) $state['outgoing_links'][ $target_id ] : 0,
					'outgoing_targets' => isset( $state['outgoing_targets'][ $target_id ] ) ? (int) $state['outgoing_targets'][ $target_id ] : 0,
				);
			}

			usort(
				$rows,
				static function ( array $a, array $b ): int {
					$total_cmp = ( $a['incoming_links'] ?? 0 ) <=> ( $b['incoming_links'] ?? 0 );
					if ( 0 !== $total_cmp ) {
						return $total_cmp;
					}

					$source_cmp = ( $a['incoming_sources'] ?? 0 ) <=> ( $b['incoming_sources'] ?? 0 );
					if ( 0 !== $source_cmp ) {
						return $source_cmp;
					}

					$outgoing_cmp = ( $a['outgoing_links'] ?? 0 ) <=> ( $b['outgoing_links'] ?? 0 );
					if ( 0 !== $outgoing_cmp ) {
						return $outgoing_cmp;
					}

					return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
				}
			);

			return array(
				'created_at'    => time(),
				'site_url'      => home_url( '/' ),
				'total_targets' => count( $rows ),
				'total_sources' => count( (array) ( $state['source_ids'] ?? array() ) ),
				'rows'          => $rows,
			);
		}

		/**
		 * Return a readable post type label.
		 *
		 * @param string $post_type Post type.
		 */
		private function get_post_type_label( string $post_type ): string {
			if ( 'page' === $post_type ) {
				return __( 'Sayfa', 'ic-link-sayici' );
			}

			if ( 'post' === $post_type ) {
				return __( 'Yazı', 'ic-link-sayici' );
			}

			$type_object = get_post_type_object( $post_type );

			return $type_object && isset( $type_object->labels->singular_name )
				? (string) $type_object->labels->singular_name
				: $post_type;
		}

		/**
		 * Save scan state without autoloading it on every request.
		 *
		 * @param string              $token Scan token.
		 * @param array<string,mixed> $state Scan state.
		 */
		private function save_scan_state( string $token, array $state ): void {
			$this->update_nonautoload_option( $this->get_scan_state_option( $token ), $state );
		}

		/**
		 * Get scan state.
		 *
		 * @param string $token Scan token.
		 * @return array<string,mixed>
		 */
		private function get_scan_state( string $token ): array {
			if ( '' === $token ) {
				return array();
			}

			$state = get_option( $this->get_scan_state_option( $token ), array() );

			return is_array( $state ) ? $state : array();
		}

		/**
		 * Delete scan state.
		 *
		 * @param string $token Scan token.
		 */
		private function delete_scan_state( string $token ): void {
			if ( '' === $token ) {
				return;
			}

			delete_option( $this->get_scan_state_option( $token ) );
		}

		/**
		 * Build the option name for a scan token.
		 *
		 * @param string $token Scan token.
		 */
		private function get_scan_state_option( string $token ): string {
			return self::STATE_PREFIX . md5( $token );
		}

		/**
		 * Add or update an option while keeping autoload disabled.
		 *
		 * @param string $name Option name.
		 * @param mixed  $value Option value.
		 */
		private function update_nonautoload_option( string $name, $value ): void {
			if ( false === get_option( $name, false ) ) {
				add_option( $name, $value, '', false );
				return;
			}

			update_option( $name, $value, false );
		}
	}
}

Maya_Ic_Link_Sayici::instance();
