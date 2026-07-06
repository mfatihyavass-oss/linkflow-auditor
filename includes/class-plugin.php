<?php
/**
 * Main plugin class.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor' ) ) {
	/**
	 * Main plugin class.
	 */
	final class LinkFlow_Auditor {
			private const VERSION               = '1.10.3';
			private const REPORT_OPTION         = 'linkflow_auditor_report';
			private const SETTINGS_OPTION       = 'linkflow_auditor_settings';
			private const IGNORED_SUGGESTIONS_OPTION = 'linkflow_auditor_ignored_suggestions';
			private const CHECK_EXTERNAL_OPTION = 'linkflow_auditor_check_external_links';
			private const STATE_PREFIX          = 'linkflow_auditor_scan_';
			private const NONCE_ACTION          = 'linkflow_auditor_admin';
			private const PAGE_SLUG             = 'linkflow-auditor';
			private const CRON_HOOK             = 'linkflow_auditor_run_background_scan';
			private const CRON_SCHEDULE         = 'linkflow_auditor_custom_interval';
			private const LOCK_TRANSIENT        = 'linkflow_auditor_background_scan_lock';
			private const LEGACY_REPORT_OPTION  = 'maya_ils_report';
			private const LEGACY_SETTINGS_OPTION = 'maya_ils_settings';
			private const LEGACY_CHECK_EXTERNAL_OPTION = 'maya_ils_check_external_links';
			private const LEGACY_CRON_HOOK      = 'maya_ils_run_background_scan';
			private const LEGACY_LOCK_TRANSIENT = 'maya_ils_background_scan_lock';
			private const LEGACY_STATE_PREFIX   = 'maya_ils_scan_';
			private const BATCH_SIZE            = 25;
			private const DEFAULT_INTERVAL      = 2;
			private const MIN_INTERVAL          = 1;
			private const MAX_INTERVAL          = 168;
			private const SCAN_MODE_INTERNAL    = 'internal';
			private const SCAN_MODE_BROKEN      = 'broken';
			private const SCAN_MODE_REDIRECT    = 'redirect';
			private const REDIRECT_STATUS_CODES = array( 301, 302, 307, 308 );
			private const HEALTH_LIST_CAP       = 500;
			private const HEALTH_DISPLAY_CAP    = 100;
			private const EXTERNAL_LIST_CAP     = 2000;
			private const SUGGESTION_LIST_CAP   = 1000;
			private const SUGGESTION_BATCH_SIZE = 25;
			private const SUGGESTION_CANDIDATES_PER_SOURCE = 30;
			private const SUGGESTIONS_PER_SOURCE = 3;
			private const SUGGESTIONS_PER_TARGET = 10;
			private const MANUAL_SUGGESTION_LIMIT = 25;
			private const SUGGESTION_CONTEXT_RADIUS = 58;
			private const SCAN_MODE_EXTERNAL    = 'external';
			private const SCAN_MODE_INTERNAL_FIX = 'internal';

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Persistent report/settings store.
		 *
		 * @var LinkFlow_Auditor_Report_Store
		 */
		private $store;

		/**
		 * URL and multibyte string normalizer.
		 *
		 * @var LinkFlow_Auditor_Url_Normalizer
		 */
		private $url_normalizer;

		/**
		 * Safe content link editor.
		 *
		 * @var LinkFlow_Auditor_Link_Editor
		 */
		private $link_editor;

		/**
		 * Automatic internal-link suggestion engine.
		 *
		 * @var LinkFlow_Auditor_Link_Suggestion_Engine
		 */
		private $suggestion_engine;

		/**
		 * Manual internal-link suggestion engine.
		 *
		 * @var LinkFlow_Auditor_Manual_Suggestion_Engine
		 */
		private $manual_suggestion_engine;

		/**
		 * HTTP link status checker.
		 *
		 * @var LinkFlow_Auditor_Http_Link_Checker
		 */
		private $http_link_checker;

		/**
		 * Admin page renderer.
		 *
		 * @var LinkFlow_Auditor_Admin_Page
		 */
		private $admin_page;

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
				$this->store          = new LinkFlow_Auditor_Report_Store();
				$this->url_normalizer = new LinkFlow_Auditor_Url_Normalizer();
				$this->link_editor    = new LinkFlow_Auditor_Link_Editor( $this->url_normalizer );
				$this->suggestion_engine = new LinkFlow_Auditor_Link_Suggestion_Engine( $this->store, $this->url_normalizer, $this->link_editor );
				$this->http_link_checker = new LinkFlow_Auditor_Http_Link_Checker( $this->url_normalizer );
				$this->admin_page = new LinkFlow_Auditor_Admin_Page( $this->store, $this->url_normalizer );
				$this->manual_suggestion_engine = new LinkFlow_Auditor_Manual_Suggestion_Engine(
					$this->store,
					$this->url_normalizer,
					$this->link_editor,
					$this->suggestion_engine,
					function (): array {
						return $this->get_content_ids();
					}
				);

				add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
				add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
				add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
				add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
				add_action( 'admin_init', array( $this, 'ensure_schedule' ) );
				add_action( self::CRON_HOOK, array( $this, 'run_background_scan' ) );

				add_action( 'wp_ajax_linkflow_auditor_start_scan', array( $this, 'ajax_start_scan' ) );
				add_action( 'wp_ajax_linkflow_auditor_scan_batch', array( $this, 'ajax_scan_batch' ) );
				add_action( 'wp_ajax_linkflow_auditor_clear_report', array( $this, 'ajax_clear_report' ) );
				add_action( 'wp_ajax_linkflow_auditor_clear_all_records', array( $this, 'ajax_clear_all_records' ) );
			add_action( 'wp_ajax_linkflow_auditor_fix_link', array( $this, 'ajax_fix_link' ) );
			add_action( 'wp_ajax_linkflow_auditor_accept_suggestion', array( $this, 'ajax_accept_suggestion' ) );
			add_action( 'wp_ajax_linkflow_auditor_dismiss_suggestion', array( $this, 'ajax_dismiss_suggestion' ) );
			add_action( 'wp_ajax_linkflow_auditor_reset_dismissed_suggestions', array( $this, 'ajax_reset_dismissed_suggestions' ) );
			add_action( 'wp_ajax_linkflow_auditor_rotate_suggestions', array( $this, 'ajax_rotate_suggestions' ) );
			add_action( 'wp_ajax_linkflow_auditor_clear_suggestion_rotation', array( $this, 'ajax_clear_suggestion_rotation' ) );
			add_action( 'wp_ajax_linkflow_auditor_manual_suggestions', array( $this, 'ajax_manual_suggestions' ) );
			add_action( 'wp_ajax_linkflow_auditor_accept_manual_suggestion', array( $this, 'ajax_accept_manual_suggestion' ) );
				add_action( 'admin_post_linkflow_auditor_save_settings', array( $this, 'handle_save_settings' ) );
			}

			/**
			 * Initialize plugin options.
			 */
			public static function activate(): void {
				LinkFlow_Auditor_Report_Store::activate();

				wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
				delete_transient( self::LEGACY_LOCK_TRANSIENT );
			}

			

/**
			 * Stop scheduled checks when the plugin is disabled.
			 */
			public static function deactivate(): void {
				wp_clear_scheduled_hook( self::CRON_HOOK );
				delete_transient( self::LOCK_TRANSIENT );
			}

			/**
			 * Add the custom automatic check interval.
			 *
			 * @param array<string,array<string,mixed>> $schedules Registered schedules.
			 * @return array<string,array<string,mixed>>
			 */
			public function add_cron_schedule( array $schedules ): array {
				$settings = $this->get_settings();
				$hours    = $this->normalize_interval_hours( $settings['interval_hours'] );

				$schedules[ self::CRON_SCHEDULE ] = array(
					'interval' => $hours * HOUR_IN_SECONDS,
					'display'  => sprintf(
						/* translators: %d: interval hour count. */
						_n( 'Kırık link kontrolü: %d saatte bir', 'Kırık link kontrolü: %d saatte bir', $hours, 'linkflow-auditor' ),
						$hours
					),
				);

				return $schedules;
			}

			/**
			 * Keep WordPress Cron aligned with settings.
			 */
			public function ensure_schedule(): void {
				$settings = $this->get_settings();

				if ( empty( $settings['auto_enabled'] ) ) {
					wp_clear_scheduled_hook( self::CRON_HOOK );
					return;
				}

				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
				}
			}

			/**
			 * Run an automatic background scan.
			 */
			public function run_background_scan(): void {
				$settings = $this->get_settings();

				if ( empty( $settings['auto_enabled'] ) || get_transient( self::LOCK_TRANSIENT ) ) {
					return;
				}

				set_transient( self::LOCK_TRANSIENT, 1, 30 * MINUTE_IN_SECONDS );
				$this->raise_limits();
				$this->run_scan_sync( self::SCAN_MODE_BROKEN, ! empty( $settings['check_external_links'] ) );
				delete_transient( self::LOCK_TRANSIENT );
			}

		/**
		 * Add the dashboard widget to the admin start screen.
		 */
		public function register_dashboard_widget(): void {
			$this->admin_page->register_dashboard_widget();
		}

		/**
		 * Add a full report page under Tools.
		 */
		public function register_admin_page(): void {
			$this->admin_page->register_admin_page();
		}

		/**
		 * Load admin assets only where the tool is visible.
		 *
		 * @param string $hook Current admin screen hook.
		 */
		public function enqueue_admin_assets( string $hook ): void {
			$this->admin_page->enqueue_admin_assets( $hook );
		}

		/**
		 * Render dashboard widget.
		 */
		public function render_dashboard_widget(): void {
			$this->admin_page->render_dashboard_widget();
		}

		/**
		 * Render full admin page.
		 */
		public function render_admin_page(): void {
			$this->admin_page->render_admin_page();
		}

		



































/**
			 * Start a scan and create a temporary state option.
			 */
			public function handle_save_settings(): void {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'linkflow-auditor' ) );
				}

				check_admin_referer( 'linkflow_auditor_save_settings' );

				$settings = $this->get_settings();
				$settings['check_external_links'] = ! empty( $_POST['linkflow_auditor_check_external_links'] );
				$settings['auto_enabled']         = ! empty( $_POST['linkflow_auditor_auto_enabled'] );
				$settings['interval_hours']       = $this->posted_interval_hours();

				$this->save_settings( $settings );
				$this->update_nonautoload_option( self::CHECK_EXTERNAL_OPTION, $settings['check_external_links'] ? '1' : '0' );
				wp_clear_scheduled_hook( self::CRON_HOOK );
				$this->ensure_schedule();

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'            => self::PAGE_SLUG,
							'linkflow_auditor_notice' => 'settings_saved',
						),
						admin_url( 'tools.php' )
					)
				);
				exit;
			}

			/**
			 * Start a scan and create a temporary state option.
			 */
				public function ajax_start_scan(): void {
				$this->verify_ajax_request();
				$this->raise_limits();

					$scan_mode       = $this->posted_scan_mode();
					$posted_external = isset( $_POST['check_external_links'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['check_external_links'] ) );
					$check_external  = self::SCAN_MODE_BROKEN === $scan_mode && $posted_external;

				if ( self::SCAN_MODE_BROKEN === $scan_mode ) {
					$this->update_nonautoload_option( self::CHECK_EXTERNAL_OPTION, $check_external ? '1' : '0' );
					$settings                         = $this->get_settings();
					$settings['check_external_links'] = $check_external;
					$this->save_settings( $settings );
				}

				$ids = $this->get_content_ids();
				if ( empty( $ids ) ) {
					$report = $this->finalize_report(
						array(
							'scan_mode'            => $scan_mode,
							'started_at'           => time(),
							'source_ids'           => array(),
							'target_ids'           => array(),
							'targets'              => array(),
							'url_index'            => array(),
							'incoming_links'       => array(),
							'incoming_sources'     => array(),
							'outgoing_links'       => array(),
							'outgoing_targets'     => array(),
							'check_external_links' => $check_external,
							'found_links'          => 0,
							'checked_links'        => 0,
							'broken_link_count'    => 0,
							'warning_link_count'   => 0,
							'redirect_link_count'  => 0,
							'broken_links'         => array(),
							'redirect_links'       => array(),
							'suggestion_candidates' => array(),
							'suggestion_total'     => 0,
						)
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
					'user_id'          => get_current_user_id(),
					'started_at'       => time(),
					'scan_mode'        => $scan_mode,
					'source_ids'       => array_values( $ids ),
					'target_ids'       => array_values( array_map( 'intval', array_keys( $target_data['targets'] ) ) ),
					'targets'          => $target_data['targets'],
					'url_index'        => $target_data['url_index'],
					'incoming_links'   => array(),
					'incoming_sources' => array(),
					'incoming_details' => array(),
					'outgoing_links'   => array(),
					'outgoing_targets' => array(),
					'health_insecure'       => array(),
					'health_insecure_total' => 0,
					'health_weak_anchor'    => array(),
					'health_weak_total'     => 0,
					'external_links'        => array(),
					'external_total'        => 0,
					'check_external_links' => $check_external,
					'found_links'          => 0,
					'checked_links'        => 0,
					'broken_link_count'    => 0,
					'warning_link_count'   => 0,
					'redirect_link_count'  => 0,
					'broken_links'         => array(),
					'redirect_links'       => array(),
					'suggestion_candidates' => array(),
					'suggestion_total'     => 0,
					'http_cache'           => array(),
					'offset'               => 0,
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
					array( 'message' => esc_html__( 'Tarama oturumu bulunamadı.', 'linkflow-auditor' ) ),
					404
				);
			}

			if ( (int) ( $state['user_id'] ?? 0 ) !== get_current_user_id() ) {
				wp_send_json_error(
					array( 'message' => esc_html__( 'Bu tarama oturumuna erişim yetkiniz yok.', 'linkflow-auditor' ) ),
					403
				);
			}

			$source_ids = array_map( 'intval', (array) ( $state['source_ids'] ?? array() ) );
			$total      = count( $source_ids );
			$offset     = isset( $state['offset'] ) ? (int) $state['offset'] : 0;
			$batch_size = (int) apply_filters( 'linkflow_auditor_scan_batch_size', self::BATCH_SIZE );
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
			 * Run a full scan without AJAX for scheduled checks.
			 *
			 * @param string $scan_mode Scan mode.
			 * @param bool $check_external Whether to check external links too.
			 * @return array<string,mixed>
			 */
			private function run_scan_sync( string $scan_mode, bool $check_external = false ): array {
				$scan_mode      = $this->sanitize_scan_mode( $scan_mode );
				$check_external = self::SCAN_MODE_BROKEN === $scan_mode && $check_external;
				$ids = $this->get_content_ids();

				if ( empty( $ids ) ) {
					$report = $this->finalize_report(
						array(
							'scan_mode'            => $scan_mode,
							'started_at'           => time(),
							'source_ids'           => array(),
							'target_ids'           => array(),
							'targets'              => array(),
							'url_index'            => array(),
							'incoming_links'       => array(),
							'incoming_sources'     => array(),
							'outgoing_links'       => array(),
							'outgoing_targets'     => array(),
							'check_external_links' => $check_external,
							'found_links'          => 0,
							'checked_links'        => 0,
							'broken_link_count'    => 0,
							'warning_link_count'   => 0,
							'redirect_link_count'  => 0,
							'broken_links'         => array(),
							'redirect_links'       => array(),
							'suggestion_candidates' => array(),
							'suggestion_total'     => 0,
						)
					);

					$this->update_nonautoload_option( self::REPORT_OPTION, $report );
					return $report;
				}

				$target_data = $this->build_target_index( $ids );
				$state       = array(
					'user_id'              => 0,
					'started_at'           => time(),
					'scan_mode'            => $scan_mode,
					'source_ids'           => array_values( $ids ),
					'target_ids'           => array_values( array_map( 'intval', array_keys( $target_data['targets'] ) ) ),
					'targets'              => $target_data['targets'],
					'url_index'            => $target_data['url_index'],
					'incoming_links'       => array(),
					'incoming_sources'     => array(),
					'incoming_details'     => array(),
					'outgoing_links'       => array(),
					'outgoing_targets'     => array(),
					'health_insecure'       => array(),
					'health_insecure_total' => 0,
					'health_weak_anchor'    => array(),
					'health_weak_total'     => 0,
					'external_links'        => array(),
					'external_total'        => 0,
					'check_external_links' => $check_external,
					'found_links'          => 0,
					'checked_links'        => 0,
					'broken_link_count'    => 0,
					'warning_link_count'   => 0,
					'redirect_link_count'  => 0,
					'broken_links'         => array(),
					'redirect_links'       => array(),
					'suggestion_candidates' => array(),
					'suggestion_total'     => 0,
					'http_cache'           => array(),
					'offset'               => 0,
				);

				foreach ( $state['target_ids'] as $target_id ) {
					$state['incoming_links'][ $target_id ]   = 0;
					$state['incoming_sources'][ $target_id ] = 0;
					$state['outgoing_links'][ $target_id ]   = 0;
					$state['outgoing_targets'][ $target_id ] = 0;
				}

				$batch_size = (int) apply_filters( 'linkflow_auditor_background_scan_batch_size', self::BATCH_SIZE );
				$batch_size = max( 5, min( 100, $batch_size ) );

				foreach ( array_chunk( $ids, $batch_size ) as $batch_ids ) {
					$this->scan_posts( array_map( 'intval', $batch_ids ), $state );
				}

				$report = $this->finalize_report( $state );
				$this->update_nonautoload_option( self::REPORT_OPTION, $report );

				return $report;
			}

			/**
			 * Clear the saved report.
			 */
		public function ajax_clear_report(): void {
			$this->verify_ajax_request();
			delete_option( self::REPORT_OPTION );

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Rapor silindi.', 'linkflow-auditor' ),
				)
			);
		}

		/**
		 * Clear reports, temporary scan states, and suggestion records.
		 */
		public function ajax_clear_all_records(): void {
			$this->verify_ajax_request();

			$deleted = $this->store->clear_runtime_records();

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Uygulamanın tuttuğu rapor ve öneri kayıtları silindi.', 'linkflow-auditor' ),
					'deleted' => $deleted,
				)
			);
		}

		/**
		 * Remove or replace a single link inside a post and refresh the saved report.
		 */
		public function ajax_fix_link(): void {
			$this->verify_ajax_request();

			$scope     = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
			$source_id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
			$mode      = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
			$raw_url   = isset( $_POST['raw_url'] ) ? trim( (string) wp_unslash( $_POST['raw_url'] ) ) : '';
			$new_url   = isset( $_POST['new_url'] ) ? trim( esc_url_raw( (string) wp_unslash( $_POST['new_url'] ) ) ) : '';

			if ( ! in_array( $scope, array( self::SCAN_MODE_BROKEN, self::SCAN_MODE_REDIRECT, self::SCAN_MODE_EXTERNAL, self::SCAN_MODE_INTERNAL_FIX ), true ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Geçersiz istek.', 'linkflow-auditor' ) ), 400 );
			}

			if ( ! in_array( $mode, array( 'remove', 'replace' ), true ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Geçersiz işlem.', 'linkflow-auditor' ) ), 400 );
			}

			if ( $source_id <= 0 || '' === $raw_url ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Link bilgisi eksik.', 'linkflow-auditor' ) ), 400 );
			}

			if ( ! current_user_can( 'edit_post', $source_id ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Bu yazıyı düzenleme yetkiniz yok.', 'linkflow-auditor' ) ), 403 );
			}

			if ( 'replace' === $mode ) {
				if ( '' === $new_url ) {
					wp_send_json_error( array( 'message' => esc_html__( 'Lütfen geçerli bir URL girin.', 'linkflow-auditor' ) ), 400 );
				}

				$check = $this->request_http_status( $new_url );
				$code  = isset( $check['status_code'] ) ? (int) $check['status_code'] : 0;

				if ( '' !== (string) ( $check['error'] ?? '' ) || $code >= 400 || 0 === $code ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %s: HTTP status or error detail. */
								esc_html__( 'Yeni URL doğrulanamadı (%s). Lütfen kontrol edip tekrar deneyin.', 'linkflow-auditor' ),
								'' !== (string) ( $check['error'] ?? '' ) ? esc_html( (string) $check['error'] ) : (string) $code
							),
						),
						400
					);
				}
			}

			$changed = $this->modify_post_links( $source_id, $raw_url, $mode, $new_url );

			if ( is_wp_error( $changed ) ) {
				wp_send_json_error( array( 'message' => $changed->get_error_message() ), 400 );
			}

			if ( $changed < 1 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Link yazı içinde bulunamadı. Rapor güncel olmayabilir.', 'linkflow-auditor' ) ), 404 );
			}

			$counts = $this->update_report_after_fix( $scope, $source_id, $raw_url );

			wp_send_json_success(
				array(
					'message'        => 'remove' === $mode
						? esc_html__( 'Link kaldırıldı.', 'linkflow-auditor' )
						: esc_html__( 'Link güncellendi.', 'linkflow-auditor' ),
					'broken_count'   => $counts['broken_count'],
					'redirect_count' => $counts['redirect_count'],
					'external_count' => $counts['external_count'],
				)
			);
		}

		/**
		 * Accept one internal link suggestion and add the link to the source post.
		 */
		public function ajax_accept_suggestion(): void {
			$this->verify_ajax_request();

			$suggestion_id = isset( $_POST['suggestion_id'] ) ? sanitize_text_field( wp_unslash( $_POST['suggestion_id'] ) ) : '';

			if ( '' === $suggestion_id ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Öneri bilgisi eksik.', 'linkflow-auditor' ) ), 400 );
			}

			$suggestion = $this->find_suggestion_by_id( $suggestion_id );
			if ( empty( $suggestion ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Öneri bulunamadı. Raporu yenileyin.', 'linkflow-auditor' ) ), 404 );
			}

			$source_id = (int) ( $suggestion['source_id'] ?? 0 );
			$target_id = (int) ( $suggestion['target_id'] ?? 0 );

			if ( $source_id <= 0 || $target_id <= 0 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Öneri bilgisi geçersiz.', 'linkflow-auditor' ) ), 400 );
			}

			if ( ! current_user_can( 'edit_post', $source_id ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Bu yazıyı düzenleme yetkiniz yok.', 'linkflow-auditor' ) ), 403 );
			}

			$changed = $this->apply_internal_link_suggestion( $suggestion );

			if ( is_wp_error( $changed ) ) {
				wp_send_json_error( array( 'message' => $changed->get_error_message() ), 400 );
			}

			if ( $changed < 1 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Linklenecek ifade kaynak içerikte bulunamadı. Rapor güncel olmayabilir.', 'linkflow-auditor' ) ), 404 );
			}

			$suggestion_count = $this->update_report_after_suggestion_accept( $suggestion_id, $source_id, $target_id );

			wp_send_json_success(
				array(
					'message'          => esc_html__( 'Öneri kabul edildi. Link eklendi; sayılar bir sonraki taramada güncellenir.', 'linkflow-auditor' ),
					'suggestion_count' => $suggestion_count,
				)
			);
		}

		/**
		 * Dismiss one saved suggestion and suppress it for future scans.
		 */
		public function ajax_dismiss_suggestion(): void {
			$this->verify_ajax_request();

			$suggestion_id = isset( $_POST['suggestion_id'] ) ? sanitize_key( wp_unslash( $_POST['suggestion_id'] ) ) : '';

			if ( '' === $suggestion_id ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Öneri bilgisi eksik.', 'linkflow-auditor' ) ), 400 );
			}

			$ignored                   = $this->get_ignored_suggestion_ids();
			$ignored[ $suggestion_id ] = true;
			$this->save_ignored_suggestion_ids( $ignored );

			$suggestion_count = $this->update_report_after_suggestion_dismiss( $suggestion_id );

			wp_send_json_success(
				array(
					'message'          => esc_html__( 'Öneri kaldırıldı ve tekrar önerilmeyecek.', 'linkflow-auditor' ),
					'suggestion_count' => $suggestion_count,
				)
			);
		}

		/**
		 * Clear all dismissed automatic suggestion IDs.
		 */
		public function ajax_reset_dismissed_suggestions(): void {
			$this->verify_ajax_request();

			$this->save_ignored_suggestion_ids( array() );

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Kaldırılan öneriler sıfırlandı. Tekrar görmek için önerileri yenileyin.', 'linkflow-auditor' ),
					'count'   => 0,
				)
			);
		}

		/**
		 * Return a different batch of saved automatic suggestions.
		 */
		public function ajax_rotate_suggestions(): void {
			$this->verify_ajax_request();

			$report      = $this->get_report();
			$suggestions = array_values( array_filter( (array) ( $report['suggestions'] ?? array() ), 'is_array' ) );
			$total       = isset( $report['suggestion_count'] ) ? (int) $report['suggestion_count'] : count( $suggestions );

			if ( empty( $suggestions ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Değiştirilecek öneri bulunamadı.', 'linkflow-auditor' ) ), 404 );
			}

			$current_ids = $this->posted_suggestion_ids( 'current_ids' );
			$seen        = $this->get_suggestion_seen_ids( 'normal' );

			foreach ( $current_ids as $id ) {
				$seen[ $id ] = true;
			}

			$batch    = $this->select_suggestion_batch( $suggestions, $seen, self::SUGGESTION_BATCH_SIZE + 1 );
			$has_more = count( $batch ) > self::SUGGESTION_BATCH_SIZE;
			$batch    = array_slice( $batch, 0, self::SUGGESTION_BATCH_SIZE );

			foreach ( $this->get_suggestion_ids_from_rows( $batch ) as $id ) {
				$seen[ $id ] = true;
			}
			$this->save_suggestion_seen_ids( 'normal', '', $seen );

			$html = $this->admin_page->render_saved_suggestions_results( $batch, $total, $has_more );

			wp_send_json_success(
				array(
					'message' => empty( $batch )
						? esc_html__( 'Gösterilecek farklı öneri kalmadı. Seçim kaydını silerseniz baştan gösterebilirsiniz.', 'linkflow-auditor' )
						: esc_html__( 'Farklı öneriler hazır.', 'linkflow-auditor' ),
					'count'   => count( $batch ),
					'html'    => $html,
				)
			);
		}

		/**
		 * Clear automatic or manual suggestion rotation records.
		 */
		public function ajax_clear_suggestion_rotation(): void {
			$this->verify_ajax_request();

			$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
			if ( ! in_array( $scope, array( 'normal', 'manual' ), true ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Geçersiz kayıt türü.', 'linkflow-auditor' ) ), 400 );
			}

			$this->store->clear_suggestion_rotation( $scope );

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Öneri seçim kaydı silindi.', 'linkflow-auditor' ),
				)
			);
		}

		/**
		 * Search editable content for a manual internal link opportunity.
		 */
		public function ajax_manual_suggestions(): void {
			$this->verify_ajax_request();

			$mode          = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'phrase';
			$mode          = 'source_url' === $mode ? 'source_url' : 'phrase';
			$anchor        = isset( $_POST['anchor'] ) ? trim( (string) wp_unslash( $_POST['anchor'] ) ) : '';
			$target        = isset( $_POST['target_url'] ) ? trim( (string) wp_unslash( $_POST['target_url'] ) ) : '';
			$source_url    = isset( $_POST['source_url'] ) ? trim( (string) wp_unslash( $_POST['source_url'] ) ) : '';
			$sort          = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'least_links';
			$reset_context = ! empty( $_POST['reset_context'] );
			$current_ids   = $this->posted_suggestion_ids( 'current_ids' );

			if ( ! in_array( $sort, array( 'least_links', 'oldest', 'newest' ), true ) ) {
				$sort = 'least_links';
			}

			if ( 'source_url' === $mode ) {
				if ( '' === $source_url ) {
					wp_send_json_error( array( 'message' => esc_html__( 'Lütfen kaynak URL girin.', 'linkflow-auditor' ) ), 400 );
				}

				$context_key = $this->manual_suggestion_context_key( $mode, $source_url, '', $sort );
				$excluded    = $reset_context ? array() : $this->get_suggestion_seen_ids( 'manual', $context_key );
				if ( ! $reset_context ) {
					foreach ( $current_ids as $id ) {
						$excluded[ $id ] = true;
					}
				} else {
					$this->clear_manual_suggestion_context( $context_key );
				}

				$result = $this->manual_suggestion_engine->build_source_url_link_suggestions( $source_url, $sort, $excluded, self::SUGGESTION_BATCH_SIZE + 1 );
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
				}

				$suggestions = $result;
				$target_url  = '';
			} else {
				if ( '' === $anchor || $this->mb_strlen( $this->normalize_suggestion_phrase( $anchor ) ) < 2 ) {
					wp_send_json_error( array( 'message' => esc_html__( 'Linklenecek ifade çok kısa.', 'linkflow-auditor' ) ), 400 );
				}

				$target_url = $this->normalize_manual_target_url( $target );
				if ( is_wp_error( $target_url ) ) {
					wp_send_json_error( array( 'message' => $target_url->get_error_message() ), 400 );
				}

				$context_key = $this->manual_suggestion_context_key( $mode, $anchor, (string) $target_url, $sort );
				$excluded    = $reset_context ? array() : $this->get_suggestion_seen_ids( 'manual', $context_key );
				if ( ! $reset_context ) {
					foreach ( $current_ids as $id ) {
						$excluded[ $id ] = true;
					}
				} else {
					$this->clear_manual_suggestion_context( $context_key );
				}

				$suggestions = $this->build_manual_link_suggestions( $anchor, (string) $target_url, $sort, $excluded, self::SUGGESTION_BATCH_SIZE + 1 );
			}

			$has_more    = count( $suggestions ) > self::SUGGESTION_BATCH_SIZE;
			$suggestions = array_slice( $suggestions, 0, self::SUGGESTION_BATCH_SIZE );

			if ( ! $reset_context ) {
				foreach ( $this->get_suggestion_ids_from_rows( $suggestions ) as $id ) {
					$excluded[ $id ] = true;
				}
				$this->save_suggestion_seen_ids( 'manual', $context_key, $excluded );
			}

			$empty_message = ! $reset_context
				? esc_html__( 'Gösterilecek farklı manuel öneri kalmadı. Seçim kaydını silerseniz baştan gösterebilirsiniz.', 'linkflow-auditor' )
				: esc_html__( 'Uygun düz metin eşleşmesi bulunamadı.', 'linkflow-auditor' );

			$html = $this->render_manual_suggestions_results( $suggestions, (string) $target_url, $mode, $source_url, $has_more, $empty_message );

			wp_send_json_success(
				array(
					'message' => empty( $suggestions )
						? $empty_message
						: esc_html__( 'Manuel öneriler hazır.', 'linkflow-auditor' ),
					'count'   => count( $suggestions ),
					'html'    => $html,
				)
			);
		}

		/**
		 * Accept one manually searched suggestion.
		 */
		public function ajax_accept_manual_suggestion(): void {
			$this->verify_ajax_request();

			$source_id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
			$anchor    = isset( $_POST['anchor'] ) ? trim( (string) wp_unslash( $_POST['anchor'] ) ) : '';
			$target    = isset( $_POST['target_url'] ) ? trim( (string) wp_unslash( $_POST['target_url'] ) ) : '';

			if ( $source_id <= 0 || '' === $anchor ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Manuel öneri bilgisi eksik.', 'linkflow-auditor' ) ), 400 );
			}

			if ( ! current_user_can( 'edit_post', $source_id ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Bu yazıyı düzenleme yetkiniz yok.', 'linkflow-auditor' ) ), 403 );
			}

			$target_url = $this->normalize_manual_target_url( $target );
			if ( is_wp_error( $target_url ) ) {
				wp_send_json_error( array( 'message' => $target_url->get_error_message() ), 400 );
			}

			$post = get_post( $source_id );
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Kaynak içerik bulunamadı veya yayında değil.', 'linkflow-auditor' ) ), 404 );
			}

			if ( $this->source_already_links_to_url( $source_id, (string) $target_url ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Kaynak içerik bu hedef URL’ye zaten link veriyor.', 'linkflow-auditor' ) ), 400 );
			}

			$changed = $this->insert_internal_link_for_phrase( $post, $anchor, (string) $target_url );

			if ( is_wp_error( $changed ) ) {
				wp_send_json_error( array( 'message' => $changed->get_error_message() ), 400 );
			}

			if ( $changed < 1 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Linklenecek ifade kaynak içerikte bulunamadı. Listeyi yenileyin.', 'linkflow-auditor' ) ), 404 );
			}

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Manuel öneri uygulandı. Link eklendi.', 'linkflow-auditor' ),
				)
			);
		}

		/**
		 * Find a saved suggestion by ID.
		 *
		 * @param string $suggestion_id Suggestion ID.
		 * @return array<string,mixed>|null
		 */
		private function find_suggestion_by_id( string $suggestion_id ): ?array {
			$report = $this->get_report();

			foreach ( (array) ( $report['suggestions'] ?? array() ) as $suggestion ) {
				if ( ! is_array( $suggestion ) ) {
					continue;
				}

				if ( hash_equals( (string) ( $suggestion['id'] ?? '' ), $suggestion_id ) ) {
					return $suggestion;
				}
			}

			return null;
		}

		/**
		 * Add the suggested internal link to the source post.
		 *
		 * @param array<string,mixed> $suggestion Suggestion row.
		 * @return int|\WP_Error Number of links added, or error.
		 */
		private function apply_internal_link_suggestion( array $suggestion ) {
			$source_id = (int) ( $suggestion['source_id'] ?? 0 );
			$target_id = (int) ( $suggestion['target_id'] ?? 0 );
			$anchor    = trim( (string) ( $suggestion['anchor'] ?? '' ) );

			if ( $source_id <= 0 || $target_id <= 0 || '' === $anchor ) {
				return new WP_Error( 'lfa_bad_suggestion', esc_html__( 'Öneri bilgisi geçersiz.', 'linkflow-auditor' ) );
			}

			$source = get_post( $source_id );
			$target = get_post( $target_id );

			if ( ! $source instanceof WP_Post || 'publish' !== $source->post_status ) {
				return new WP_Error( 'lfa_no_source', esc_html__( 'Kaynak içerik bulunamadı veya yayında değil.', 'linkflow-auditor' ) );
			}

			if ( ! $target instanceof WP_Post || 'publish' !== $target->post_status ) {
				return new WP_Error( 'lfa_no_target', esc_html__( 'Hedef içerik bulunamadı veya yayında değil.', 'linkflow-auditor' ) );
			}

			if ( $this->source_already_links_to_target( $source_id, $target_id ) ) {
				return new WP_Error( 'lfa_already_linked', esc_html__( 'Kaynak içerik bu hedefe zaten link veriyor.', 'linkflow-auditor' ) );
			}

			$target_url = get_permalink( $target_id );
			if ( ! $target_url ) {
				return new WP_Error( 'lfa_no_target_url', esc_html__( 'Hedef URL alınamadı.', 'linkflow-auditor' ) );
			}

			return $this->insert_internal_link_for_phrase( $source, $anchor, (string) $target_url );
		}

		/**
		 * Check whether a source post already links to the target post in stored content.
		 *
		 * @param int $source_id Source post ID.
		 * @param int $target_id Target post ID.
		 */
		private function source_already_links_to_target( int $source_id, int $target_id ): bool {
			$source = get_post( $source_id );
			if ( ! $source instanceof WP_Post ) {
				return false;
			}

			$source_url  = get_permalink( $source_id );
			$target_data = $this->build_target_index( array( $target_id ) );
			$url_index   = (array) ( $target_data['url_index'] ?? array() );

			foreach ( $this->extract_links( (string) $source->post_content ) as $link ) {
				$href       = (string) ( $link['href'] ?? '' );
				$target_ids = $this->resolve_internal_href( $href, $url_index, (string) $source_url );

				if ( in_array( $target_id, $target_ids, true ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Insert a single internal link around the first safe phrase occurrence.
		 *
		 * @param WP_Post $post       Source post.
		 * @param string  $anchor     Anchor phrase to wrap.
		 * @param string  $target_url Target URL.
		 * @return int|\WP_Error Number of links added, or error.
		 */
		private function insert_internal_link_for_phrase( WP_Post $post, string $anchor, string $target_url ) {
			return $this->link_editor->insert_internal_link_for_phrase( $post, $anchor, $target_url );
		}

		

/**
		 * Remove accepted suggestions from the saved report.
		 *
		 * @param string $suggestion_id Suggestion ID.
		 * @param int    $source_id     Source post ID.
		 * @param int    $target_id     Target post ID.
		 */
		private function update_report_after_suggestion_accept( string $suggestion_id, int $source_id, int $target_id ): int {
			$report = $this->get_report();
			$kept   = array();

			foreach ( (array) ( $report['suggestions'] ?? array() ) as $suggestion ) {
				if ( ! is_array( $suggestion ) ) {
					continue;
				}

				$same_id     = hash_equals( (string) ( $suggestion['id'] ?? '' ), $suggestion_id );
				$same_pair   = (int) ( $suggestion['source_id'] ?? 0 ) === $source_id && (int) ( $suggestion['target_id'] ?? 0 ) === $target_id;

				if ( $same_id || $same_pair ) {
					continue;
				}

				$kept[] = $suggestion;
			}

			$report['suggestions']      = array_values( $kept );
			$report['suggestion_count'] = count( $report['suggestions'] );

			$this->update_nonautoload_option( self::REPORT_OPTION, $report );

			return (int) $report['suggestion_count'];
		}

		/**
		 * Remove a dismissed suggestion from the saved report.
		 *
		 * @param string $suggestion_id Suggestion ID.
		 */
		private function update_report_after_suggestion_dismiss( string $suggestion_id ): int {
			$report = $this->get_report();
			$kept   = array();

			foreach ( (array) ( $report['suggestions'] ?? array() ) as $suggestion ) {
				if ( ! is_array( $suggestion ) ) {
					continue;
				}

				if ( hash_equals( (string) ( $suggestion['id'] ?? '' ), $suggestion_id ) ) {
					continue;
				}

				$kept[] = $suggestion;
			}

			$report['suggestions']      = array_values( $kept );
			$report['suggestion_count'] = count( $report['suggestions'] );

			$this->update_nonautoload_option( self::REPORT_OPTION, $report );

			return (int) $report['suggestion_count'];
		}

		/**
		 * Normalize and validate a manual target URL.
		 *
		 * @param string $target Raw target input.
		 * @return string|\WP_Error
		 */
		private function normalize_manual_target_url( string $target ) {
			return $this->manual_suggestion_engine->normalize_manual_target_url( $target );
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
		private function build_manual_link_suggestions( string $anchor, string $target_url, string $sort, array $excluded_ids = array(), int $limit = self::MANUAL_SUGGESTION_LIMIT ): array {
			return $this->manual_suggestion_engine->build_manual_link_suggestions( $anchor, $target_url, $sort, $excluded_ids, $limit );
		}

		/**
		 * Render manual search results as safe HTML.
		 *
		 * @param array<int,array<string,mixed>> $suggestions Suggestions.
		 * @param string                         $target_url  Target URL.
		 * @param string                         $mode        Manual suggestion mode.
		 * @param string                         $source_url  Source URL for source mode.
		 * @param bool                           $has_more    Whether another batch exists.
		 * @param string                         $empty_message Empty result message.
		 */
		private function render_manual_suggestions_results( array $suggestions, string $target_url, string $mode = 'phrase', string $source_url = '', bool $has_more = false, string $empty_message = '' ): string {
			return $this->admin_page->render_manual_suggestions_results( $suggestions, $target_url, $mode, $source_url, $has_more, $empty_message );
		}

		

/**
		 * Check whether a source post already links to a normalized URL.
		 *
		 * @param int    $source_id  Source post ID.
		 * @param string $target_url Target URL.
		 */
		private function source_already_links_to_url( int $source_id, string $target_url ): bool {
			return $this->manual_suggestion_engine->source_already_links_to_url( $source_id, $target_url );
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
		private function modify_post_links( int $post_id, string $raw_url, string $mode, string $new_url ) {
			return $this->link_editor->modify_post_links( $post_id, $raw_url, $mode, $new_url );
		}

		/**
		 * Normalize a URL for loose comparison (entity-decode + trim).
		 */
		private function normalize_match_url( string $url ): string {
			return $this->url_normalizer->normalize_match_url( $url );
		}

		/**
		 * Drop fixed occurrences from the saved report and return refreshed counts.
		 *
		 * @param string $scope     Either 'broken' or 'redirect'.
		 * @param int    $source_id Source post ID.
		 * @param string $raw_url   Matched href.
		 * @return array{broken_count:int,redirect_count:int,external_count:int}
		 */
		private function update_report_after_fix( string $scope, int $source_id, string $raw_url ): array {
			$report = $this->get_report();
			$target = $this->normalize_match_url( $raw_url );

			if ( self::SCAN_MODE_EXTERNAL === $scope ) {
				$kept    = array();
				$removed = 0;

				foreach ( (array) ( $report['external_links'] ?? array() ) as $link ) {
					if ( ! is_array( $link ) ) {
						continue;
					}

					$match = (int) ( $link['source_id'] ?? 0 ) === $source_id
						&& $this->normalize_match_url( (string) ( $link['href'] ?? '' ) ) === $target;

					if ( $match ) {
						++$removed;
						continue;
					}

					$kept[] = $link;
				}

				$report['external_links'] = array_values( $kept );
				$report['external_total'] = max( 0, (int) ( $report['external_total'] ?? 0 ) - $removed );

				$this->update_nonautoload_option( self::REPORT_OPTION, $report );

				return $this->fix_result_counts( $report );
			}

			if ( self::SCAN_MODE_INTERNAL_FIX === $scope ) {
				// The internal count report is recomputed on the next scan; nothing to
				// prune here. Just report the current counts back to the UI.
				return $this->fix_result_counts( $report );
			}

			if ( self::SCAN_MODE_BROKEN === $scope ) {
				$kept            = array();
				$removed_broken  = 0;
				$removed_warning = 0;

				foreach ( (array) ( $report['broken_links'] ?? array() ) as $link ) {
					if ( ! is_array( $link ) ) {
						continue;
					}

					$match = (int) ( $link['source_id'] ?? 0 ) === $source_id
						&& $this->normalize_match_url( (string) ( $link['raw_url'] ?? '' ) ) === $target;

					if ( $match ) {
						if ( 'warning' === (string) ( $link['status'] ?? 'broken' ) ) {
							++$removed_warning;
						} else {
							++$removed_broken;
						}
						continue;
					}

					$kept[] = $link;
				}

				$report['broken_links']       = array_values( $kept );
				$report['broken_link_count']  = max( 0, (int) ( $report['broken_link_count'] ?? 0 ) - $removed_broken );
				$report['warning_link_count'] = max( 0, (int) ( $report['warning_link_count'] ?? 0 ) - $removed_warning );
			} else {
				$groups          = array();
				$removed_usage   = 0;

				foreach ( (array) ( $report['redirect_links'] ?? array() ) as $group ) {
					if ( ! is_array( $group ) ) {
						continue;
					}

					$occurrences = array();

					foreach ( (array) ( $group['occurrences'] ?? array() ) as $occurrence ) {
						if ( ! is_array( $occurrence ) ) {
							continue;
						}

						$match = (int) ( $occurrence['source_id'] ?? 0 ) === $source_id
							&& $this->normalize_match_url( (string) ( $occurrence['raw_url'] ?? '' ) ) === $target;

						if ( $match ) {
							++$removed_usage;
							continue;
						}

						$occurrences[] = $occurrence;
					}

					if ( empty( $occurrences ) ) {
						continue;
					}

					$source_ids            = array();
					foreach ( $occurrences as $occurrence ) {
						$oid = (int) ( $occurrence['source_id'] ?? 0 );
						if ( $oid > 0 ) {
							$source_ids[ $oid ] = true;
						}
					}

					$group['occurrences']  = array_values( $occurrences );
					$group['usage_count']  = count( $occurrences );
					$group['source_count'] = count( $source_ids );
					$groups[]              = $group;
				}

				$report['redirect_links']      = array_values( $groups );
				$report['redirect_link_count'] = max( 0, (int) ( $report['redirect_link_count'] ?? 0 ) - $removed_usage );
			}

			$this->update_nonautoload_option( self::REPORT_OPTION, $report );

			return $this->fix_result_counts( $report );
		}

		/**
		 * Collect the counts returned to the UI after a link fix.
		 *
		 * @param array<string,mixed> $report Current report.
		 * @return array{broken_count:int,redirect_count:int,external_count:int}
		 */
		private function fix_result_counts( array $report ): array {
			$broken_count   = $this->count_broken_issues( $report );
			$redirect_count = isset( $report['redirect_link_count'] )
				? (int) $report['redirect_link_count']
				: $this->count_redirect_usage( (array) ( $report['redirect_links'] ?? array() ) );
			$external_count = isset( $report['external_total'] )
				? (int) $report['external_total']
				: count( (array) ( $report['external_links'] ?? array() ) );

			return array(
				'broken_count'   => $broken_count,
				'redirect_count' => $redirect_count,
				'external_count' => $external_count,
			);
		}

		/**
		 * Count every issue shown in the broken-link tab, including warning rows.
		 *
		 * @param array<string,mixed> $report Current report.
		 */
		private function count_broken_issues( array $report ): int {
			if ( isset( $report['broken_link_count'] ) || isset( $report['warning_link_count'] ) ) {
				return max( 0, (int) ( $report['broken_link_count'] ?? 0 ) )
					+ max( 0, (int) ( $report['warning_link_count'] ?? 0 ) );
			}

			return count( (array) ( $report['broken_links'] ?? array() ) );
		}

		/**
		 * Verify AJAX nonce and capability.
		 */
		private function verify_ajax_request(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array( 'message' => esc_html__( 'Bu işlem için yetkiniz yok.', 'linkflow-auditor' ) ),
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
				return $this->store->get_report();
			}

			/**
			 * Get dismissed suggestion IDs as a lookup map.
			 *
			 * @return array<string,bool>
			 */
			private function get_ignored_suggestion_ids(): array {
				return $this->store->get_ignored_suggestion_ids();
			}

			/**
			 * Persist dismissed suggestion IDs.
			 *
			 * @param array<string,bool> $ids Suggestion ID map.
			 */
			private function save_ignored_suggestion_ids( array $ids ): void {
				$this->store->save_ignored_suggestion_ids( $ids );
			}

			/**
			 * Get seen suggestion IDs for a rotation scope/context.
			 *
			 * @param string $scope Scope: normal or manual.
			 * @param string $context_key Optional manual context key.
			 * @return array<string,bool>
			 */
			private function get_suggestion_seen_ids( string $scope, string $context_key = '' ): array {
				$rotation = $this->store->get_suggestion_rotation();

				if ( 'manual' === $scope ) {
					$context = sanitize_key( $context_key );
					$seen    = (array) ( $rotation['manual'][ $context ]['seen'] ?? array() );
				} else {
					$seen = (array) ( $rotation['normal']['seen'] ?? array() );
				}

				$clean = array();
				foreach ( $seen as $id => $value ) {
					$id = sanitize_key( is_int( $id ) ? (string) $value : (string) $id );
					if ( '' !== $id ) {
						$clean[ $id ] = true;
					}
				}

				return $clean;
			}

			/**
			 * Save seen suggestion IDs for a rotation scope/context.
			 *
			 * @param string             $scope Scope: normal or manual.
			 * @param string             $context_key Optional manual context key.
			 * @param array<string,bool> $seen Seen ID map.
			 */
			private function save_suggestion_seen_ids( string $scope, string $context_key, array $seen ): void {
				$rotation = $this->store->get_suggestion_rotation();

				if ( 'manual' === $scope ) {
					$context = sanitize_key( $context_key );
					if ( '' === $context ) {
						return;
					}

					if ( ! isset( $rotation['manual'] ) || ! is_array( $rotation['manual'] ) ) {
						$rotation['manual'] = array();
					}

					$rotation['manual'][ $context ] = array(
						'seen'       => $seen,
						'updated_at' => time(),
					);
				} else {
					$rotation['normal'] = array(
						'seen'       => $seen,
						'updated_at' => time(),
					);
				}

				$this->store->save_suggestion_rotation( $rotation );
			}

			/**
			 * Clear one manual suggestion context from rotation records.
			 *
			 * @param string $context_key Context key.
			 */
			private function clear_manual_suggestion_context( string $context_key ): void {
				$context_key = sanitize_key( $context_key );
				if ( '' === $context_key ) {
					return;
				}

				$rotation = $this->store->get_suggestion_rotation();
				if ( isset( $rotation['manual'] ) && is_array( $rotation['manual'] ) ) {
					unset( $rotation['manual'][ $context_key ] );
					$this->store->save_suggestion_rotation( $rotation );
				}
			}

			/**
			 * Build a stable context key for manual suggestion rotation.
			 *
			 * @param string $mode Mode.
			 * @param string $primary Primary input.
			 * @param string $secondary Secondary input.
			 * @param string $sort Sort mode.
			 */
			private function manual_suggestion_context_key( string $mode, string $primary, string $secondary, string $sort ): string {
				return substr( hash( 'sha256', $mode . '|' . $this->mb_lower( trim( $primary ) ) . '|' . $this->mb_lower( trim( $secondary ) ) . '|' . $sort ), 0, 24 );
			}

			/**
			 * Read suggestion IDs posted by the current result table.
			 *
			 * @param string $field POST field name.
			 * @return string[]
			 */
			private function posted_suggestion_ids( string $field ): array {
				$value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
				$ids   = is_array( $value ) ? $value : explode( ',', (string) $value );
				$clean = array();

				foreach ( $ids as $id ) {
					$id = sanitize_key( (string) $id );
					if ( '' !== $id ) {
						$clean[] = $id;
					}
				}

				return array_values( array_unique( $clean ) );
			}

			/**
			 * Get IDs from rendered suggestion rows.
			 *
			 * @param array<int,array<string,mixed>> $suggestions Suggestions.
			 * @return string[]
			 */
			private function get_suggestion_ids_from_rows( array $suggestions ): array {
				$ids = array();

				foreach ( $suggestions as $suggestion ) {
					$id = sanitize_key( (string) ( $suggestion['id'] ?? '' ) );
					if ( '' !== $id ) {
						$ids[] = $id;
					}
				}

				return array_values( array_unique( $ids ) );
			}

			/**
			 * Select a suggestion batch excluding already-seen IDs.
			 *
			 * @param array<int,array<string,mixed>> $suggestions Suggestions.
			 * @param array<string,bool>             $seen Seen ID map.
			 * @param int                            $limit Batch size.
			 * @return array<int,array<string,mixed>>
			 */
			private function select_suggestion_batch( array $suggestions, array $seen, int $limit ): array {
				$batch = array();

				foreach ( $suggestions as $suggestion ) {
					$id = sanitize_key( (string) ( $suggestion['id'] ?? '' ) );
					if ( '' === $id || isset( $seen[ $id ] ) ) {
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
			 * Get persistent settings.
			 *
			 * @return array<string,mixed>
			 */
			private function get_settings(): array {
				return $this->store->get_settings();
			}

			/**
			 * Save persistent settings.
			 *
			 * @param array<string,mixed> $settings Settings.
			 */
			private function save_settings( array $settings ): void {
				$this->store->save_settings( $settings );
			}

			/**
			 * Get interval from the settings form.
			 */
			private function posted_interval_hours(): int {
				$value = isset( $_POST['linkflow_auditor_interval_hours'] ) ? wp_unslash( $_POST['linkflow_auditor_interval_hours'] ) : self::DEFAULT_INTERVAL;
				return $this->normalize_interval_hours( $value );
			}

			/**
			 * Get scan mode from the AJAX request.
			 */
			private function posted_scan_mode(): string {
				$value = isset( $_POST['scan_mode'] ) ? sanitize_key( wp_unslash( $_POST['scan_mode'] ) ) : self::SCAN_MODE_INTERNAL;

				return $this->sanitize_scan_mode( $value );
			}

			/**
			 * Normalize scan mode.
			 *
			 * @param string $scan_mode Raw scan mode.
			 */
			private function sanitize_scan_mode( string $scan_mode ): string {
				if ( in_array( $scan_mode, array( self::SCAN_MODE_INTERNAL, self::SCAN_MODE_BROKEN, self::SCAN_MODE_REDIRECT ), true ) ) {
					return $scan_mode;
				}

				return self::SCAN_MODE_INTERNAL;
			}

			/**
			 * Normalize an hour interval.
			 *
			 * @param mixed $value Raw interval.
			 */
			private function normalize_interval_hours( $value ): int {
				return $this->store->normalize_interval_hours( $value );
			}

		/**
		 * Get IDs for published posts and pages.
		 *
		 * @return int[]
		 */
			private function get_content_ids(): array {
				$default_post_types = get_post_types( array( 'public' => true ), 'names' );
				unset( $default_post_types['attachment'] );

				if ( empty( $default_post_types ) ) {
					$default_post_types = array( 'post', 'page' );
				}

				$post_types = (array) apply_filters( 'linkflow_auditor_post_types', array_values( $default_post_types ) );
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

			foreach ( $ids as $id ) {
				$url = get_permalink( $id );
					if ( ! $url ) {
						continue;
					}

					$post = get_post( $id );
					$edit_url = get_edit_post_link( $id, '' );
					if ( ! $edit_url ) {
						$edit_url = add_query_arg(
							array(
								'post'   => $id,
								'action' => 'edit',
							),
							admin_url( 'post.php' )
						);
					}

					$targets[ $id ] = array(
						'id'                 => $id,
						'title'              => get_the_title( $id ),
						'type'               => get_post_type( $id ),
						'url'                => $url,
						'edit_url'           => $edit_url,
						'published_at'       => $post instanceof WP_Post ? (int) get_post_time( 'U', false, $id ) : 0,
						'suggestion_phrases' => $this->build_suggestion_phrases( $id, $url ),
					);

				foreach ( $this->get_url_index_keys( $url, $id ) as $key ) {
					if ( ! isset( $url_index[ $key ] ) ) {
						$url_index[ $key ] = array();
					}

					// Keep every target that maps to a key. When two published
					// items share the same permalink (e.g. a post and a page with
					// the same slug) the old code deleted the key entirely, which
					// silently zeroed out all incoming links to that URL. Now the
					// link is attributed to every content item sharing the URL.
					if ( ! in_array( $id, $url_index[ $key ], true ) ) {
						$url_index[ $key ][] = $id;
					}
				}
			}

			// Flag content items whose pretty-permalink path is shared by more than
			// one published item so the report can warn about the duplicate URL.
			foreach ( $url_index as $key => $id_list ) {
				if ( 0 !== strpos( $key, 'path:' ) || count( $id_list ) < 2 ) {
					continue;
				}

				foreach ( $id_list as $shared_id ) {
					if ( isset( $targets[ $shared_id ] ) ) {
						$targets[ $shared_id ]['shared_url'] = true;
					}
				}
			}

			return array(
				'targets'   => $targets,
				'url_index' => $url_index,
			);
		}

		/**
		 * Build safe anchor phrases from a target's title and slug.
		 *
		 * @param int    $post_id Target post ID.
		 * @param string $url     Target permalink.
		 * @return string[]
		 */
		private function build_suggestion_phrases( int $post_id, string $url ): array {
			return $this->suggestion_engine->build_suggestion_phrases( $post_id, $url );
		}

		
/**
		 * Normalize titles/slugs into a phrase that can be searched in text.
		 *
		 * @param string $phrase Raw phrase.
		 */
		private function normalize_suggestion_phrase( string $phrase ): string {
			return $this->suggestion_engine->normalize_suggestion_phrase( $phrase );
		}

		



/**
		 * Count words in a suggestion phrase.
		 *
		 * @param string $phrase Phrase.
		 */
		private function suggestion_word_count( string $phrase ): int {
			return $this->suggestion_engine->suggestion_word_count( $phrase );
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

			$scan_mode             = $this->sanitize_scan_mode( (string) ( $state['scan_mode'] ?? self::SCAN_MODE_INTERNAL ) );
			$should_check_status   = in_array( $scan_mode, array( self::SCAN_MODE_BROKEN, self::SCAN_MODE_REDIRECT ), true );
			$should_count_internal = self::SCAN_MODE_INTERNAL === $scan_mode;
			$home_host             = $should_count_internal ? $this->normalize_host( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '' ) ) : '';
			$home_is_https         = $should_count_internal && 'https' === strtolower( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ?: '' ) );

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
					$content_for_links    = $should_count_internal
						? $this->render_content_for_counting( $post )
						: (string) $post->post_content;
					$links                = $this->extract_links( $content_for_links );

					foreach ( $links as $link ) {
						$href        = (string) ( $link['href'] ?? '' );
						$anchor_text = (string) ( $link['text'] ?? '' );

						if ( $should_check_status ) {
							$this->inspect_link_status( $href, $anchor_text, $post, (string) $source_url, $state );
						}

						if ( ! $should_count_internal ) {
							continue;
						}

						$target_ids = $this->resolve_internal_href( $href, $url_index, (string) $source_url );

						$this->collect_link_health( $state, $post, (string) $source_url, $href, $anchor_text, $target_ids, $home_host, $home_is_https );

						if ( empty( $target_ids ) ) {
							continue;
						}

						$counted_outgoing = false;

						foreach ( $target_ids as $target_id ) {
							if ( $target_id <= 0 || $target_id === $source_id ) {
								continue;
							}

							if ( ! isset( $state['incoming_links'][ $target_id ] ) ) {
								$state['incoming_links'][ $target_id ] = 0;
							}

							++$state['incoming_links'][ $target_id ];
							$this->record_incoming_detail( $state, $target_id, $source_id, $anchor_text, $href );
							$linked_targets[ $target_id ] = true;

							// One anchor is one outgoing link, even if the URL is shared
							// by several targets. Attribute it to the first real target.
							if ( ! $counted_outgoing ) {
								++$outgoing_link_count;
								$outgoing_target_ids[ $target_id ] = true;
								$counted_outgoing                  = true;
							}
						}
					}

					if ( $should_count_internal ) {
						$this->collect_internal_link_suggestion_candidates( $state, $post, (string) $source_url, $linked_targets );
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
			 * Render a post's content the way the front end would before counting links.
			 *
			 * Running the block and shortcode processors means links produced by
			 * Gutenberg blocks, reusable blocks, page builders and shortcodes are
			 * counted, not just raw <a> tags typed into the editor. This is only used
			 * for internal link counting; broken/redirect scans keep using raw content
			 * so the manual remove/replace actions still match the stored href.
			 *
			 * @param WP_Post $post Source post.
			 */
			private function render_content_for_counting( WP_Post $post ): string {
				$content = (string) $post->post_content;

				if ( ! apply_filters( 'linkflow_auditor_render_content', true, $post ) ) {
					return $content;
				}

				if ( function_exists( 'do_blocks' ) ) {
					$content = do_blocks( $content );
				}

				$content = do_shortcode( $content );

				return (string) $content;
			}

			/**
			 * Record which source post linked to a target, plus a few anchor samples.
			 *
			 * This backs the auditable "incoming links" detail list so the reported
			 * count can be verified against the actual linking posts.
			 *
			 * @param array<string,mixed> $state       Scan state, passed by reference.
			 * @param int                 $target_id   Linked target post ID.
			 * @param int                 $source_id   Linking source post ID.
			 * @param string              $anchor_text Anchor text of the link.
			 * @param string              $href        Raw href as written in the source content.
			 */
			private function record_incoming_detail( array &$state, int $target_id, int $source_id, string $anchor_text, string $href ): void {
				if ( ! isset( $state['incoming_details'][ $target_id ] ) ) {
					$state['incoming_details'][ $target_id ] = array();
				}

				if ( ! isset( $state['incoming_details'][ $target_id ][ $source_id ] ) ) {
					$state['incoming_details'][ $target_id ][ $source_id ] = array(
						'count'   => 0,
						'anchors' => array(),
						'raw_url' => '',
					);
				}

				++$state['incoming_details'][ $target_id ][ $source_id ]['count'];

				// Remember the first real href so the remove/replace action can match it.
				if ( '' === (string) ( $state['incoming_details'][ $target_id ][ $source_id ]['raw_url'] ?? '' ) && '' !== trim( $href ) ) {
					$state['incoming_details'][ $target_id ][ $source_id ]['raw_url'] = trim( $href );
				}

				$anchor_text = trim( $anchor_text );
				if (
					'' !== $anchor_text
					&& count( $state['incoming_details'][ $target_id ][ $source_id ]['anchors'] ) < 5
					&& ! in_array( $anchor_text, $state['incoming_details'][ $target_id ][ $source_id ]['anchors'], true )
				) {
					$state['incoming_details'][ $target_id ][ $source_id ]['anchors'][] = $anchor_text;
				}
			}

			/**
			 * Collect internal-link suggestion candidates for one source post.
			 *
			 * @param array<string,mixed> $state          Scan state, passed by reference.
			 * @param WP_Post             $post           Source post.
			 * @param string              $source_url     Source permalink.
			 * @param array<int,bool>     $linked_targets Targets already linked from this source.
			 */
			private function collect_internal_link_suggestion_candidates( array &$state, WP_Post $post, string $source_url, array $linked_targets ): void {
				$this->suggestion_engine->collect_internal_link_suggestion_candidates( $state, $post, $source_url, $linked_targets );
			}

			/**
			 * Extract text nodes that can be safely wrapped with an anchor.
			 *
			 * @param string $html Raw post content.
			 * @return string[]
			 */
			private function extract_linkable_text_segments( string $html ): array {
				return $this->link_editor->extract_linkable_text_segments( $html );
			}

			
/**
			 * Whether a text node is safe enough for automated link wrapping.
			 *
			 * @param string $text Text node value.
			 */
			private function is_linkable_text_value( string $text ): bool {
				return $this->link_editor->is_linkable_text_value( $text );
			}

			/**
			 * Find a phrase inside linkable text segments.
			 *
			 * @param string[] $segments Text segments.
			 * @param string   $phrase   Candidate phrase.
			 * @return array{before:string,match:string,after:string}|array{}
			 */
			private function find_phrase_in_segments( array $segments, string $phrase ): array {
				return $this->link_editor->find_phrase_in_segments( $segments, $phrase );
			}

			/**
			 * Build a short before/match/after context around a matched phrase.
			 *
			 * @param string $text     Source text segment.
			 * @param int    $position Match position.
			 * @param int    $length   Match length.
			 * @return array{before:string,match:string,after:string}
			 */
			private function build_suggestion_context( string $text, int $position, int $length ): array {
				return $this->link_editor->build_suggestion_context( $text, $position, $length );
			}

			/**
			 * Ensure a phrase does not match inside a larger word.
			 *
			 * @param string $text     Text segment.
			 * @param int    $position Match position.
			 * @param int    $length   Match length.
			 */
			private function is_phrase_boundary_match( string $text, int $position, int $length ): bool {
				return $this->link_editor->is_phrase_boundary_match( $text, $position, $length );
			}

			/**
			 * Whether one character is a word character.
			 *
			 * @param string $char Character.
			 */
			private function is_word_character( string $char ): bool {
				return $this->link_editor->is_word_character( $char );
			}

			/**
			 * Collect Link Health issues for a single internal-scan link.
			 *
			 * Runs only during the internal scan and makes no HTTP requests. Detects
			 * insecure (mixed-content http://) links to the site's own host and weak
			 * or empty anchor text on resolved internal content links.
			 *
			 * @param array<string,mixed> $state         Scan state, passed by reference.
			 * @param WP_Post             $post          Source post.
			 * @param string              $source_url    Source permalink.
			 * @param string              $href          Raw href.
			 * @param string              $anchor_text   Anchor text.
			 * @param int[]               $target_ids    Resolved internal target IDs.
			 * @param string              $home_host     Normalized site host.
			 * @param bool                $home_is_https Whether the site runs on https.
			 */
			private function collect_link_health( array &$state, WP_Post $post, string $source_url, string $href, string $anchor_text, array $target_ids, string $home_host, bool $home_is_https ): void {
				$trimmed_href = trim( $href );

				// External link: an absolute http/https link to a different host. Kept
				// for the "Dış Linkler" tab with its anchor text and source.
				$scheme = strtolower( (string) ( wp_parse_url( $trimmed_href, PHP_URL_SCHEME ) ?: '' ) );
				if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
					$link_host = $this->normalize_host( (string) ( wp_parse_url( $trimmed_href, PHP_URL_HOST ) ?: '' ) );

					if ( '' !== $link_host && '' !== $home_host && $link_host !== $home_host ) {
						if ( (int) ( $state['external_total'] ?? 0 ) < self::EXTERNAL_LIST_CAP ) {
							$state['external_links'][] = array(
								'source_id'    => (int) $post->ID,
								'source_title' => $this->get_source_title( $post ),
								'source_url'   => $source_url,
								'href'         => $trimmed_href,
								'anchor'       => $anchor_text,
							);
						}

						$state['external_total'] = (int) ( $state['external_total'] ?? 0 ) + 1;
					}
				}

				// Insecure internal link: an absolute http:// URL on the site's own host
				// while the site itself is served over https (mixed content).
				if ( $home_is_https && 0 === stripos( $trimmed_href, 'http://' ) ) {
					$link_host = $this->normalize_host( (string) ( wp_parse_url( $trimmed_href, PHP_URL_HOST ) ?: '' ) );

					if ( '' !== $link_host && $link_host === $home_host ) {
						if ( (int) ( $state['health_insecure_total'] ?? 0 ) < self::HEALTH_LIST_CAP ) {
							$state['health_insecure'][] = array(
								'source_id'    => (int) $post->ID,
								'source_title' => $this->get_source_title( $post ),
								'source_url'   => $source_url,
								'href'         => $trimmed_href,
								'anchor'       => $anchor_text,
							);
						}

						$state['health_insecure_total'] = (int) ( $state['health_insecure_total'] ?? 0 ) + 1;
					}
				}

				// Weak or empty anchor text on a resolved internal content link.
				if ( ! empty( $target_ids ) ) {
					$normalized = $this->mb_lower( trim( $anchor_text ) );

					if ( '' === $normalized || in_array( $normalized, $this->generic_anchor_texts(), true ) ) {
						if ( (int) ( $state['health_weak_total'] ?? 0 ) < self::HEALTH_LIST_CAP ) {
							$target_id = (int) ( $target_ids[0] ?? 0 );
							$targets   = (array) ( $state['targets'] ?? array() );

							$state['health_weak_anchor'][] = array(
								'source_id'    => (int) $post->ID,
								'source_title' => $this->get_source_title( $post ),
								'source_url'   => $source_url,
								'target_id'    => $target_id,
								'target_title' => (string) ( $targets[ $target_id ]['title'] ?? '' ),
								'target_url'   => (string) ( $targets[ $target_id ]['url'] ?? '' ),
								'anchor'       => $anchor_text,
							);
						}

						$state['health_weak_total'] = (int) ( $state['health_weak_total'] ?? 0 ) + 1;
					}
				}
			}

			/**
			 * Generic/low-value anchor phrases (already lowercased) that hurt SEO.
			 *
			 * @return string[]
			 */
			private function generic_anchor_texts(): array {
				return $this->suggestion_engine->generic_anchor_texts();
			}

			/**
			 * Extract link href and anchor text values from HTML.
			 *
			 * @param string $html HTML content.
			 * @return array<int,array{href:string,text:string}>
			 */
			private function extract_links( string $html ): array {
				return $this->link_editor->extract_links( $html );
			}

			

/**
		 * Resolve a href to the target post IDs it points to on this site.
		 *
		 * Returns a list because two published items can share the same permalink
		 * (e.g. a post and a page with the same slug); the incoming link then belongs
		 * to every content item at that URL.
		 *
		 * @param string                    $href Raw href.
		 * @param array<string,array<int>>  $url_index URL lookup table.
		 * @param string                    $source_url Source permalink for relative URLs.
		 * @return int[]
		 */
			private function resolve_internal_href( string $href, array $url_index, string $source_url ): array {
				$href = trim( html_entity_decode( $href, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );

			if ( '' === $href || '#' === $href ) {
				return array();
			}

			if ( preg_match( '/^(mailto|tel|sms|javascript|data|blob):/i', $href ) ) {
				return array();
			}

			$parts = $this->parse_href( $href, $source_url );
			if ( empty( $parts ) ) {
				return array();
			}

			$home_host = $this->normalize_host( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '' ) );
			$link_host = $this->normalize_host( (string) ( $parts['host'] ?? $home_host ) );

			if ( '' !== $link_host && '' !== $home_host && $link_host !== $home_host ) {
				return array();
			}

			if ( ! empty( $parts['query'] ) ) {
				parse_str( (string) $parts['query'], $query_args );

				foreach ( array( 'p', 'page_id' ) as $query_key ) {
					if ( isset( $query_args[ $query_key ] ) && is_scalar( $query_args[ $query_key ] ) ) {
						$key = 'query:' . $query_key . '=' . absint( $query_args[ $query_key ] );

						if ( isset( $url_index[ $key ] ) ) {
							return array_map( 'intval', (array) $url_index[ $key ] );
						}
					}
				}
			}

			$path = isset( $parts['path'] ) ? $this->normalize_path( (string) $parts['path'] ) : '/';
			$key  = 'path:' . $path;

				return isset( $url_index[ $key ] ) ? array_map( 'intval', (array) $url_index[ $key ] ) : array();
			}

			/**
			 * Check one link's HTTP status and add it to broken/redirect reports when needed.
			 *
			 * @param string              $href Raw href.
			 * @param string              $anchor_text Anchor text.
			 * @param WP_Post             $post Source post.
			 * @param string              $source_url Source permalink.
			 * @param array<string,mixed> $state Scan state, passed by reference.
			 */
			private function inspect_link_status( string $href, string $anchor_text, WP_Post $post, string $source_url, array &$state ): void {
				$scan_mode = $this->sanitize_scan_mode( (string) ( $state['scan_mode'] ?? self::SCAN_MODE_BROKEN ) );
				$context = $this->get_status_check_context( $href, $anchor_text, $post, $source_url, $state );

				if ( empty( $context ) ) {
					return;
				}

				$status = $this->get_cached_http_status( (string) $context['checked_url'], $state );

				if ( ! isset( $state['found_links'] ) ) {
					$state['found_links'] = 0;
				}

				if ( ! isset( $state['checked_links'] ) ) {
					$state['checked_links'] = 0;
				}

				++$state['found_links'];
				++$state['checked_links'];

				$status_code       = isset( $status['status_code'] ) ? (int) $status['status_code'] : 0;
				$final_status_code = isset( $status['final_status_code'] ) ? (int) $status['final_status_code'] : $status_code;
				$final_url         = (string) ( $status['final_url'] ?? $context['checked_url'] );
				$redirect_status   = isset( $status['redirect_status_code'] ) ? (int) $status['redirect_status_code'] : 0;

				if ( self::SCAN_MODE_BROKEN === $scan_mode ) {
					$issue = $this->classify_link_issue( $status_code, $final_status_code, (string) ( $status['error'] ?? '' ) );

					if ( ! empty( $issue ) ) {
						$this->add_broken_link(
							$state,
							$context,
							(int) $issue['status_code'],
							$final_url,
							(string) $issue['status'],
							(string) $issue['message']
						);
					}
				}

				if ( 0 === $redirect_status && $this->is_reportable_redirect_status( $status_code ) ) {
					$redirect_status = $status_code;
				}

				if ( self::SCAN_MODE_REDIRECT === $scan_mode && $this->is_reportable_redirect_status( $redirect_status ) ) {
					$this->add_redirect_link( $state, $context, $redirect_status, $final_url );
				}
			}

			/**
			 * Classify a checked link as broken or warning when it should be reported.
			 *
			 * @param int    $status_code First response status code.
			 * @param int    $final_status_code Final response status code.
			 * @param string $error Request error message.
			 * @return array<string,mixed>
			 */
			private function classify_link_issue( int $status_code, int $final_status_code, string $error ): array {
				$code = $final_status_code > 0 ? $final_status_code : $status_code;

				if ( '' !== $error ) {
					return array(
						'status'      => 'broken',
						'status_code' => 0,
						'message'     => $error,
					);
				}

				if ( in_array( $code, array( 401, 403 ), true ) ) {
					return array(
						'status'      => 'warning',
						'status_code' => $code,
						'message'     => __( 'Erişim kısıtlı. Bağlantıyı tarayıcıda doğrulayın.', 'linkflow-auditor' ),
					);
				}

				if ( 404 === $code ) {
					return array(
						'status'      => 'broken',
						'status_code' => $code,
						'message'     => __( 'Bulunamadı (404).', 'linkflow-auditor' ),
					);
				}

				if ( 410 === $code ) {
					return array(
						'status'      => 'broken',
						'status_code' => $code,
						'message'     => __( 'Yok olmuş/Gone (410).', 'linkflow-auditor' ),
					);
				}

				if ( $code >= 400 ) {
					return array(
						'status'      => 'broken',
						'status_code' => $code,
						'message'     => sprintf(
							/* translators: %d: HTTP response code. */
							__( 'HTTP %d yanıtı alındı.', 'linkflow-auditor' ),
							$code
						),
					);
				}

				if ( 0 === $code && 0 === $status_code ) {
					return array(
						'status'      => 'broken',
						'status_code' => 0,
						'message'     => __( 'Geçerli bir yanıt alınamadı.', 'linkflow-auditor' ),
					);
				}

				return array();
			}

			/**
			 * Build scan/report context for one link if it should be checked.
			 *
			 * @param string              $href Raw href.
			 * @param string              $anchor_text Anchor text.
			 * @param WP_Post             $post Source post.
			 * @param string              $source_url Source permalink.
			 * @param array<string,mixed> $state Scan state.
			 * @return array<string,mixed>
			 */
			private function get_status_check_context( string $href, string $anchor_text, WP_Post $post, string $source_url, array $state ): array {
				$raw_url = trim( html_entity_decode( $href, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );

				if ( '' === $raw_url || '#' === $raw_url || 0 === strpos( $raw_url, '#' ) ) {
					return array();
				}

				if ( preg_match( '/^(mailto|tel|sms|javascript|data|blob):/i', $raw_url ) ) {
					return array();
				}

				$parts = $this->parse_href( $raw_url, $source_url );
				if ( empty( $parts ) ) {
					return array();
				}

				$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					return array();
				}

				$is_internal    = $this->is_internal_url_parts( $parts );
				$check_external = ! empty( $state['check_external_links'] );

				if ( ! $is_internal && ! $check_external ) {
					return array();
				}

				$checked_url = $this->build_url_from_parts( $parts );
				if ( '' === $checked_url ) {
					return array();
				}

				return array(
					'source_id'    => (int) $post->ID,
					'source_title' => $this->get_source_title( $post ),
					'source_url'   => $source_url,
					'anchor_text'  => $anchor_text,
					'raw_url'      => $raw_url,
					'checked_url'  => $checked_url,
					'is_internal'  => $is_internal,
				);
			}

			/**
			 * Return cached HTTP status data for a URL.
			 *
			 * @param string              $url URL.
			 * @param array<string,mixed> $state Scan state, passed by reference.
			 * @return array<string,mixed>
			 */
			private function get_cached_http_status( string $url, array &$state ): array {
				if ( ! isset( $state['http_cache'] ) || ! is_array( $state['http_cache'] ) ) {
					$state['http_cache'] = array();
				}

				$key = md5( $url );

				if ( isset( $state['http_cache'][ $key ] ) && is_array( $state['http_cache'][ $key ] ) ) {
					return $state['http_cache'][ $key ];
				}

				$status                      = $this->request_http_status( $url );
				$state['http_cache'][ $key ] = $status;

				return $status;
			}

			/**
			 * Request a URL and follow redirects manually so the first 3XX code is preserved.
			 *
			 * @param string $url URL.
			 * @return array<string,mixed>
			 */
			private function request_http_status( string $url ): array {
				return $this->http_link_checker->request_http_status( $url );
			}

			


/**
			 * Record a broken link occurrence.
			 *
			 * @param array<string,mixed> $state Scan state, passed by reference.
			 * @param array<string,mixed> $context Link context.
			 * @param int                 $status_code Status code.
			 * @param string              $final_url Final URL.
			 * @param string              $status Issue status.
			 * @param string              $message Issue message.
			 */
			private function add_broken_link( array &$state, array $context, int $status_code, string $final_url, string $status = 'broken', string $message = '' ): void {
				if ( ! isset( $state['broken_links'] ) || ! is_array( $state['broken_links'] ) ) {
					$state['broken_links'] = array();
				}

				if ( ! isset( $state['broken_link_count'] ) ) {
					$state['broken_link_count'] = 0;
				}

				if ( ! isset( $state['warning_link_count'] ) ) {
					$state['warning_link_count'] = 0;
				}

				if ( 'warning' === $status ) {
					++$state['warning_link_count'];
				} else {
					$status = 'broken';
					++$state['broken_link_count'];
				}

				$state['broken_links'][] = array(
					'source_id'    => (int) ( $context['source_id'] ?? 0 ),
					'source_title' => (string) ( $context['source_title'] ?? '' ),
					'source_url'   => (string) ( $context['source_url'] ?? '' ),
					'anchor_text'  => (string) ( $context['anchor_text'] ?? '' ),
					'raw_url'      => (string) ( $context['raw_url'] ?? '' ),
					'checked_url'  => (string) ( $context['checked_url'] ?? '' ),
					'final_url'    => $final_url,
					'status'       => $status,
					'status_code'  => $status_code,
					'message'      => $message,
					'last_checked' => time(),
				);
			}

			/**
			 * Record a redirected link occurrence grouped by URL/status/final URL.
			 *
			 * @param array<string,mixed> $state Scan state, passed by reference.
			 * @param array<string,mixed> $context Link context.
			 * @param int                 $status_code Redirect status code.
			 * @param string              $final_url Final URL.
			 */
			private function add_redirect_link( array &$state, array $context, int $status_code, string $final_url ): void {
				if ( ! isset( $state['redirect_links'] ) || ! is_array( $state['redirect_links'] ) ) {
					$state['redirect_links'] = array();
				}

				if ( ! isset( $state['redirect_link_count'] ) ) {
					$state['redirect_link_count'] = 0;
				}

				$checked_url = (string) ( $context['checked_url'] ?? '' );
				$key         = md5( $checked_url . '|' . $status_code . '|' . $final_url );

				if ( ! isset( $state['redirect_links'][ $key ] ) || ! is_array( $state['redirect_links'][ $key ] ) ) {
					$state['redirect_links'][ $key ] = array(
						'url'         => $checked_url,
						'final_url'   => $final_url,
						'status_code' => $status_code,
						'usage_count' => 0,
						'source_ids'  => array(),
						'occurrences' => array(),
						'last_checked' => time(),
					);
				}

				++$state['redirect_link_count'];
				++$state['redirect_links'][ $key ]['usage_count'];

				$source_id = (int) ( $context['source_id'] ?? 0 );
				if ( $source_id > 0 ) {
					$state['redirect_links'][ $key ]['source_ids'][ $source_id ] = true;
				}

				$state['redirect_links'][ $key ]['occurrences'][] = array(
					'source_id'    => $source_id,
					'source_title' => (string) ( $context['source_title'] ?? '' ),
					'source_url'   => (string) ( $context['source_url'] ?? '' ),
					'anchor_text'  => (string) ( $context['anchor_text'] ?? '' ),
					'raw_url'      => (string) ( $context['raw_url'] ?? '' ),
					'checked_url'  => $checked_url,
					'last_checked' => time(),
				);
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
			 * Count grouped redirect usages.
			 *
			 * @param array<int,array<string,mixed>> $redirect_links Redirect groups.
			 */
			private function count_redirect_usage( array $redirect_links ): int {
				$count = 0;

				foreach ( $redirect_links as $link ) {
					if ( ! is_array( $link ) ) {
						continue;
					}

					$count += isset( $link['usage_count'] ) ? (int) $link['usage_count'] : count( (array) ( $link['occurrences'] ?? array() ) );
				}

				return $count;
			}

			/**
			 * Check whether parsed URL parts point to this site's host.
			 *
			 * @param array<string,string> $parts URL parts.
			 */
			private function is_internal_url_parts( array $parts ): bool {
				return $this->url_normalizer->is_internal_url_parts( $parts );
			}

			/**
			 * Build a URL string from parsed parts without the fragment.
			 *
			 * @param array<string,string> $parts URL parts.
			 */
			private function build_url_from_parts( array $parts ): string {
				return $this->url_normalizer->build_url_from_parts( $parts );
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

			/**
			 * Parse absolute, root-relative, and source-relative href values.
		 *
		 * @param string $href Raw href.
		 * @param string $source_url Source permalink.
		 * @return array<string,string>
		 */
		private function parse_href( string $href, string $source_url ): array {
			return $this->url_normalizer->parse_href( $href, $source_url );
		}

		
/**
		 * Build lookup keys for a target URL.
		 *
		 * @param string $url Target URL.
		 * @param int    $post_id Target ID.
		 * @return string[]
		 */
		private function get_url_index_keys( string $url, int $post_id ): array {
			return $this->url_normalizer->get_url_index_keys( $url, $post_id );
		}

		/**
		 * Normalize a URL host for internal-domain comparison.
		 *
		 * @param string $host Host.
		 */
		private function normalize_host( string $host ): string {
			return $this->url_normalizer->normalize_host( $host );
		}

		/**
		 * Multibyte-safe lowercasing so Turkish/UTF-8 slugs match consistently.
		 *
		 * strtolower() is byte-based and leaves characters such as İ, Ğ, Ş, Ü, Ö, Ç
		 * untouched, which caused internal links with accented slugs to be missed.
		 *
		 * @param string $value Value to lowercase.
		 */
		private function mb_lower( string $value ): string {
			return $this->url_normalizer->mb_lower( $value );
		}

		/**
		 * Multibyte-safe string length.
		 *
		 * @param string $value Value.
		 */
		private function mb_strlen( string $value ): int {
			return $this->url_normalizer->mb_strlen( $value );
		}

		/**
		 * Multibyte-safe substring.
		 *
		 * @param string   $value  Value.
		 * @param int      $start  Start offset.
		 * @param int|null $length Optional length.
		 */
		private function mb_substr( string $value, int $start, ?int $length = null ): string {
			return $this->url_normalizer->mb_substr( $value, $start, $length );
		}

		/**
		 * Multibyte-safe case-insensitive substring search.
		 *
		 * @param string $haystack Text to search.
		 * @param string $needle   Phrase to find.
		 * @param int    $offset   Search offset.
		 */
		private function mb_stripos_safe( string $haystack, string $needle, int $offset = 0 ): int {
			return $this->url_normalizer->mb_stripos_safe( $haystack, $needle, $offset );
		}

		/**
		 * Normalize URL path for lookups.
		 *
		 * @param string $path Path.
		 */
		private function normalize_path( string $path ): string {
			return $this->url_normalizer->normalize_path( $path );
		}

		
/**
		 * Build the auditable list of posts that link to a target.
		 *
		 * @param array<string,mixed> $state     Scan state.
		 * @param int                 $target_id Target post ID.
		 * @return array<int,array<string,mixed>>
		 */
		private function build_incoming_detail_rows( array $state, int $target_id ): array {
			$sources = (array) ( $state['incoming_details'][ $target_id ] ?? array() );
			$targets = (array) ( $state['targets'] ?? array() );
			$rows    = array();

			foreach ( $sources as $source_id => $info ) {
				$source_id = (int) $source_id;
				$meta      = isset( $targets[ $source_id ] ) && is_array( $targets[ $source_id ] ) ? $targets[ $source_id ] : array();

				$rows[] = array(
					'id'       => $source_id,
					'title'    => (string) ( $meta['title'] ?? get_the_title( $source_id ) ),
					'url'      => (string) ( $meta['url'] ?? get_permalink( $source_id ) ),
					'edit_url' => (string) ( $meta['edit_url'] ?? '' ),
					'count'    => isset( $info['count'] ) ? (int) $info['count'] : 0,
					'anchors'  => array_values( array_filter( (array) ( $info['anchors'] ?? array() ), 'is_string' ) ),
					'raw_url'  => (string) ( $info['raw_url'] ?? '' ),
				);
			}

			usort(
				$rows,
				static function ( array $a, array $b ): int {
					$count_cmp = ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 );
					if ( 0 !== $count_cmp ) {
						return $count_cmp;
					}

					return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
				}
			);

			return $rows;
		}

		/**
		 * Build the Link Health summary from the finished internal scan.
		 *
		 * @param array<string,mixed>           $state Scan state.
		 * @param array<int,array<string,mixed>> $rows  Finalized target rows.
		 * @return array<string,mixed>
		 */
			private function build_health_report( array $state, array $rows ): array {
				$orphans    = array();
				$dead_ends  = array();
				$duplicates = array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$item = array(
					'id'       => (int) ( $row['id'] ?? 0 ),
					'title'    => (string) ( $row['title'] ?? '' ),
					'type'     => (string) ( $row['type'] ?? '' ),
					'url'      => (string) ( $row['url'] ?? '' ),
					'edit_url' => (string) ( $row['edit_url'] ?? '' ),
				);

				if ( 0 === (int) ( $row['incoming_sources'] ?? 0 ) ) {
					$orphans[] = $item;
				}

				if ( 0 === (int) ( $row['outgoing_targets'] ?? 0 ) ) {
					$dead_ends[] = $item;
				}

				if ( ! empty( $row['shared_url'] ) ) {
					$duplicates[ $item['url'] ][] = $item;
				}
			}

			$duplicate_groups = array();
			foreach ( $duplicates as $url => $items ) {
				if ( count( $items ) >= 2 ) {
					$duplicate_groups[] = array(
						'url'   => (string) $url,
						'items' => array_values( $items ),
					);
				}
			}

			return array(
				'created_at'     => time(),
				'duplicates'     => $duplicate_groups,
				'orphans'        => $orphans,
				'dead_ends'      => $dead_ends,
				'insecure'       => array_values( array_filter( (array) ( $state['health_insecure'] ?? array() ), 'is_array' ) ),
				'insecure_total' => (int) ( $state['health_insecure_total'] ?? 0 ),
				'weak_anchor'    => array_values( array_filter( (array) ( $state['health_weak_anchor'] ?? array() ), 'is_array' ) ),
					'weak_total'     => (int) ( $state['health_weak_total'] ?? 0 ),
				);
			}

		/**
		 * Build prioritized internal link suggestions from collected candidates.
		 *
		 * @param array<string,mixed>            $state Scan state.
		 * @param array<int,array<string,mixed>> $rows  Finalized internal rows.
		 * @return array<int,array<string,mixed>>
		 */
		private function build_suggestion_report( array $state, array $rows ): array {
			return $this->suggestion_engine->build_suggestion_report( $state, $rows );
		}

		
/**
		 * Finalize scan state into a saved report.
		 *
		 * @param array<string,mixed> $state Scan state.
		 * @return array<string,mixed>
		 */
			private function finalize_report( array $state ): array {
				$report    = $this->get_report();
				$scan_mode = $this->sanitize_scan_mode( (string) ( $state['scan_mode'] ?? self::SCAN_MODE_INTERNAL ) );
				$now       = time();

				$report['created_at'] = $now;
				$report['site_url']   = home_url( '/' );

				if ( self::SCAN_MODE_INTERNAL === $scan_mode ) {
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
							'published_at'     => isset( $target['published_at'] ) ? (int) $target['published_at'] : 0,
							'incoming_links'   => isset( $state['incoming_links'][ $target_id ] ) ? (int) $state['incoming_links'][ $target_id ] : 0,
							'incoming_sources' => isset( $state['incoming_sources'][ $target_id ] ) ? (int) $state['incoming_sources'][ $target_id ] : 0,
							'incoming_detail'  => $this->build_incoming_detail_rows( $state, $target_id ),
							'outgoing_links'   => isset( $state['outgoing_links'][ $target_id ] ) ? (int) $state['outgoing_links'][ $target_id ] : 0,
							'outgoing_targets' => isset( $state['outgoing_targets'][ $target_id ] ) ? (int) $state['outgoing_targets'][ $target_id ] : 0,
							'shared_url'       => ! empty( $target['shared_url'] ),
						);
					}

					usort(
						$rows,
						static function ( array $a, array $b ): int {
							// Underlinked content first: sort by unique linking pages, the trusted metric.
							$source_cmp = ( $a['incoming_sources'] ?? 0 ) <=> ( $b['incoming_sources'] ?? 0 );
							if ( 0 !== $source_cmp ) {
								return $source_cmp;
							}

							$total_cmp = ( $a['incoming_links'] ?? 0 ) <=> ( $b['incoming_links'] ?? 0 );
							if ( 0 !== $total_cmp ) {
								return $total_cmp;
							}

							$outgoing_cmp = ( $a['outgoing_links'] ?? 0 ) <=> ( $b['outgoing_links'] ?? 0 );
							if ( 0 !== $outgoing_cmp ) {
								return $outgoing_cmp;
							}

							return strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
						}
					);

					$report['internal_created_at'] = $now;
					$report['total_targets']       = count( $rows );
					$report['total_sources']       = count( (array) ( $state['source_ids'] ?? array() ) );
					$report['rows']                = $rows;
					$report['health']              = $this->build_health_report( $state, $rows );
					$report['suggestions']         = $this->build_suggestion_report( $state, $rows );
					$report['suggestion_count']    = count( $report['suggestions'] );
					$report['external_links']      = array_values( array_filter( (array) ( $state['external_links'] ?? array() ), 'is_array' ) );
					$report['external_total']      = (int) ( $state['external_total'] ?? 0 );
					$this->store->clear_suggestion_rotation( 'normal' );

					return $report;
				}

				if ( self::SCAN_MODE_BROKEN === $scan_mode ) {
					$broken_links = array_values( array_filter( (array) ( $state['broken_links'] ?? array() ), 'is_array' ) );

					$report['broken_created_at']    = $now;
					$report['broken_total_sources'] = count( (array) ( $state['source_ids'] ?? array() ) );
					$report['broken_found_links']   = isset( $state['found_links'] ) ? (int) $state['found_links'] : 0;
					$report['broken_checked_links'] = isset( $state['checked_links'] ) ? (int) $state['checked_links'] : 0;
					$report['found_links']          = $report['broken_found_links'];
					$report['checked_links']        = $report['broken_checked_links'];
					$report['broken_link_count']    = isset( $state['broken_link_count'] ) ? (int) $state['broken_link_count'] : count( $broken_links );
					$report['warning_link_count']   = isset( $state['warning_link_count'] ) ? (int) $state['warning_link_count'] : 0;
					$report['check_external_links'] = ! empty( $state['check_external_links'] );
					$report['broken_links']         = $broken_links;

					return $report;
				}

				$redirect_links = $this->finalize_redirect_links( (array) ( $state['redirect_links'] ?? array() ) );

				$report['redirect_created_at']    = $now;
				$report['redirect_total_sources'] = count( (array) ( $state['source_ids'] ?? array() ) );
				$report['redirect_found_links']   = isset( $state['found_links'] ) ? (int) $state['found_links'] : 0;
				$report['redirect_checked_links'] = isset( $state['checked_links'] ) ? (int) $state['checked_links'] : 0;
				$report['redirect_link_count']    = isset( $state['redirect_link_count'] ) ? (int) $state['redirect_link_count'] : $this->count_redirect_usage( $redirect_links );
				$report['redirect_links']         = $redirect_links;

				return $report;
			}

			/**
			 * Prepare grouped redirect links for saving/rendering.
			 *
			 * @param array<string,array<string,mixed>> $redirect_links Redirect groups.
			 * @return array<int,array<string,mixed>>
			 */
			private function finalize_redirect_links( array $redirect_links ): array {
				$groups = array();

				foreach ( $redirect_links as $link ) {
					if ( ! is_array( $link ) ) {
						continue;
					}

					$source_ids = array_filter( array_keys( (array) ( $link['source_ids'] ?? array() ) ) );
					$group      = array(
						'url'          => (string) ( $link['url'] ?? '' ),
						'final_url'    => (string) ( $link['final_url'] ?? '' ),
						'status_code'  => isset( $link['status_code'] ) ? (int) $link['status_code'] : 0,
						'usage_count'  => isset( $link['usage_count'] ) ? (int) $link['usage_count'] : count( (array) ( $link['occurrences'] ?? array() ) ),
						'source_count' => count( $source_ids ),
						'last_checked' => isset( $link['last_checked'] ) ? (int) $link['last_checked'] : 0,
						'occurrences'  => array_values( array_filter( (array) ( $link['occurrences'] ?? array() ), 'is_array' ) ),
					);

					$groups[] = $group;
				}

				usort(
					$groups,
					static function ( array $a, array $b ): int {
						$usage_cmp = ( $b['usage_count'] ?? 0 ) <=> ( $a['usage_count'] ?? 0 );
						if ( 0 !== $usage_cmp ) {
							return $usage_cmp;
						}

						$status_cmp = ( $a['status_code'] ?? 0 ) <=> ( $b['status_code'] ?? 0 );
						if ( 0 !== $status_cmp ) {
							return $status_cmp;
						}

						return strcasecmp( (string) ( $a['url'] ?? '' ), (string) ( $b['url'] ?? '' ) );
					}
				);

				return $groups;
			}

		
/**
		 * Save scan state without autoloading it on every request.
		 *
		 * @param string              $token Scan token.
		 * @param array<string,mixed> $state Scan state.
		 */
		private function save_scan_state( string $token, array $state ): void {
			$this->store->save_scan_state( $token, $state );
		}

		/**
		 * Get scan state.
		 *
		 * @param string $token Scan token.
		 * @return array<string,mixed>
		 */
		private function get_scan_state( string $token ): array {
			return $this->store->get_scan_state( $token );
		}

		/**
		 * Delete scan state.
		 *
		 * @param string $token Scan token.
		 */
		private function delete_scan_state( string $token ): void {
			$this->store->delete_scan_state( $token );
		}

		
/**
		 * Add or update an option while keeping autoload disabled.
		 *
		 * @param string $name Option name.
		 * @param mixed  $value Option value.
		 */
		private function update_nonautoload_option( string $name, $value ): void {
			$this->store->update_nonautoload_option( $name, $value );
		}
	}
}
