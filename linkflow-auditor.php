<?php
/**
 * Plugin Name: LinkFlow Auditor
 * Plugin URI: https://github.com/mfatihyavass-oss/linkflow-auditor
 * Description: Audits internal links, broken links and redirecting links from the WordPress admin.
 * Version: 1.6.0
 * Author: mfatihyavass-oss
 * Author URI: https://github.com/mfatihyavass-oss
 * Requires at least: 6.4
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: linkflow-auditor
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor' ) ) {
	/**
	 * Main plugin class.
	 */
	final class LinkFlow_Auditor {
			private const VERSION               = '1.6.0';
			private const REPORT_OPTION         = 'linkflow_auditor_report';
			private const SETTINGS_OPTION       = 'linkflow_auditor_settings';
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
				add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
				add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
				add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
				add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
				add_action( 'admin_init', array( $this, 'ensure_schedule' ) );
				add_action( self::CRON_HOOK, array( $this, 'run_background_scan' ) );

				add_action( 'wp_ajax_linkflow_auditor_start_scan', array( $this, 'ajax_start_scan' ) );
				add_action( 'wp_ajax_linkflow_auditor_scan_batch', array( $this, 'ajax_scan_batch' ) );
				add_action( 'wp_ajax_linkflow_auditor_clear_report', array( $this, 'ajax_clear_report' ) );
			add_action( 'wp_ajax_linkflow_auditor_fix_link', array( $this, 'ajax_fix_link' ) );
				add_action( 'admin_post_linkflow_auditor_save_settings', array( $this, 'handle_save_settings' ) );
			}

			/**
			 * Initialize plugin options.
			 */
			public static function activate(): void {
				self::migrate_legacy_options();

				if ( false === get_option( self::REPORT_OPTION, false ) ) {
					add_option( self::REPORT_OPTION, array(), '', false );
				}

				if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
					add_option( self::SETTINGS_OPTION, self::default_settings(), '', false );
				}

				wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
				delete_transient( self::LEGACY_LOCK_TRANSIENT );
			}

			/**
			 * Copy data from the former İç Link Sayıcı option names when present.
			 */
			private static function migrate_legacy_options(): void {
				self::copy_legacy_option( self::LEGACY_REPORT_OPTION, self::REPORT_OPTION );
				self::copy_legacy_option( self::LEGACY_SETTINGS_OPTION, self::SETTINGS_OPTION );
				self::copy_legacy_option( self::LEGACY_CHECK_EXTERNAL_OPTION, self::CHECK_EXTERNAL_OPTION );
			}

			/**
			 * Copy a legacy option without autoloading the new value.
			 *
			 * @param string $legacy_name Legacy option name.
			 * @param string $current_name Current option name.
			 */
			private static function copy_legacy_option( string $legacy_name, string $current_name ): void {
				if ( false !== get_option( $current_name, false ) ) {
					return;
				}

				$value = get_option( $legacy_name, false );
				if ( false === $value ) {
					return;
				}

				add_option( $current_name, $value, '', false );
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
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			wp_add_dashboard_widget(
				'linkflow-auditor',
				esc_html__( 'LinkFlow Auditor', 'linkflow-auditor' ),
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
				esc_html__( 'LinkFlow Auditor', 'linkflow-auditor' ),
				esc_html__( 'LinkFlow Auditor', 'linkflow-auditor' ),
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
				'lfa-admin',
				plugins_url( 'assets/admin.css', __FILE__ ),
				array(),
				self::VERSION
			);

			wp_enqueue_script(
				'lfa-admin',
				plugins_url( 'assets/admin.js', __FILE__ ),
				array( 'jquery' ),
				self::VERSION,
				true
			);

			wp_localize_script(
				'lfa-admin',
				'LinkFlowAuditor',
				array(
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
					'messages' => array(
						'starting' => esc_html__( 'Tarama hazırlanıyor...', 'linkflow-auditor' ),
						'scanning' => esc_html__( 'Taranıyor...', 'linkflow-auditor' ),
						'done'     => esc_html__( 'Rapor hazır. Sayfa yenileniyor...', 'linkflow-auditor' ),
						'clearing' => esc_html__( 'Rapor siliniyor...', 'linkflow-auditor' ),
						'error'    => esc_html__( 'İşlem tamamlanamadı. Lütfen tekrar deneyin.', 'linkflow-auditor' ),
						'fixing'   => esc_html__( 'Link güncelleniyor...', 'linkflow-auditor' ),
						'removing' => esc_html__( 'Link kaldırılıyor...', 'linkflow-auditor' ),
						'confirmRemove'  => esc_html__( 'Bu link kaldırılsın mı? (Bağlantı silinir, metin yazıda kalır.)', 'linkflow-auditor' ),
						'confirmReplace' => esc_html__( 'Bu link yönlendirilen adresle değiştirilsin mi?', 'linkflow-auditor' ),
						'emptyUrl'       => esc_html__( 'Lütfen yeni bir URL girin.', 'linkflow-auditor' ),
					),
				)
			);
		}

		/**
		 * Render dashboard widget.
		 */
		public function render_dashboard_widget(): void {
			$report = $this->get_report();

			echo '<div class="lfa-widget">';
			$this->render_status( $report, true );

			if ( ! empty( $report['rows'] ) ) {
				$this->render_report_table( $report, 20 );
			}

			printf(
				'<p><a class="button button-secondary" href="%s">%s</a></p>',
				esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Tam raporu aç', 'linkflow-auditor' )
			);

			echo '</div>';
		}

		/**
		 * Render full admin page.
		 */
		public function render_admin_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'linkflow-auditor' ) );
			}

				$report = $this->get_report();

				echo '<div class="wrap lfa-page">';
				echo '<div class="lfa-hero">';
				echo '<div class="lfa-hero-brand"><span class="lfa-hero-logo" aria-hidden="true">🔗</span><div>';
				echo '<h1 class="lfa-hero-title">' . esc_html__( 'LinkFlow Auditor', 'linkflow-auditor' ) . '</h1>';
				echo '<p class="lfa-hero-sub">' . esc_html__( 'İç linkleri, kırık linkleri ve yönlendirmeleri tek panelden denetleyin.', 'linkflow-auditor' ) . '</p>';
				echo '</div></div>';
				echo '<span class="lfa-hero-version">v' . esc_html( self::VERSION ) . '</span>';
				echo '</div>';
				$this->render_notice();
				$this->render_settings_form();
				$this->render_status( $report, false );
				$this->render_report_tabs( $report );

				echo '</div>';
			}

		/**
		 * Render scan and clear controls for one report section.
		 *
		 * @param array<string,mixed> $report Existing report.
		 * @param string              $scan_mode Scan mode.
		 * @param string              $button_label Scan button label.
		 * @param bool                $show_external Whether to show the external link option.
		 */
			private function render_scan_controls( array $report, string $scan_mode, string $button_label, bool $show_external = false ): void {
				$scan_mode      = $this->sanitize_scan_mode( $scan_mode );
				$clear_disabled = empty( $report['rows'] ) && empty( $report['broken_links'] ) && empty( $report['redirect_links'] ) && empty( $report['created_at'] );
				$settings       = $this->get_settings();
				$check_external = ! empty( $settings['check_external_links'] );

				echo '<div class="lfa-controls">';
				printf(
					'<button type="button" class="button button-primary lfa-start" data-scan-mode="%s">%s</button> ',
					esc_attr( $scan_mode ),
					esc_html( $button_label )
				);
				printf(
					'<button type="button" class="button lfa-clear"%s>%s</button>',
					disabled( $clear_disabled, true, false ),
					esc_html__( 'Raporu sil', 'linkflow-auditor' )
				);
				echo '<span class="spinner lfa-spinner" aria-hidden="true"></span>';

				if ( $show_external ) {
					printf(
						'<label class="lfa-check-external"><input type="checkbox" value="1"%s> %s</label>',
						checked( $check_external, true, false ),
						esc_html__( 'Bu taramada dış site linklerini de kontrol et', 'linkflow-auditor' )
					);
				}

				echo '</div>';
				echo '<div class="lfa-progress" hidden><div class="lfa-progress-bar"><span></span></div><strong>0%</strong></div>';
				echo '<div class="lfa-message" aria-live="polite"></div>';
			}

			/**
			 * Render persistent scan settings.
			 */
			private function render_settings_form(): void {
				$settings = $this->get_settings();
				$next_run = wp_next_scheduled( self::CRON_HOOK );

				echo '<form class="lfa-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="linkflow_auditor_save_settings">';
				wp_nonce_field( 'linkflow_auditor_save_settings' );
				echo '<h2>' . esc_html__( 'Kırık Link Kontrol Ayarları', 'linkflow-auditor' ) . '</h2>';
				echo '<table class="form-table" role="presentation">';
				echo '<tr><th scope="row">' . esc_html__( 'Dış site linkleri', 'linkflow-auditor' ) . '</th><td>';
				printf(
					'<label><input type="checkbox" name="linkflow_auditor_check_external_links" value="1"%s> %s</label>',
					checked( ! empty( $settings['check_external_links'] ), true, false ),
					esc_html__( 'Kırık link kontrolünde dış sitelere verilen linkleri de kontrol et', 'linkflow-auditor' )
				);
				echo '<p class="description">' . esc_html__( 'Varsayılan kapalıdır. Kapalıyken yalnızca sitenizin kendi alan adına giden linkler kontrol edilir.', 'linkflow-auditor' ) . '</p>';
				echo '</td></tr>';
				echo '<tr><th scope="row">' . esc_html__( 'Otomatik kontrol', 'linkflow-auditor' ) . '</th><td>';
				printf(
					'<label><input type="checkbox" name="linkflow_auditor_auto_enabled" value="1"%s> %s</label>',
					checked( ! empty( $settings['auto_enabled'] ), true, false ),
					esc_html__( 'Belirlenen aralıkla kırık linkleri otomatik kontrol et', 'linkflow-auditor' )
				);
				printf(
					'<p><label>%s <input type="number" name="linkflow_auditor_interval_hours" value="%d" min="%d" max="%d" step="1" class="small-text"> %s</label></p>',
					esc_html__( 'Kontrol aralığı:', 'linkflow-auditor' ),
					(int) $settings['interval_hours'],
					self::MIN_INTERVAL,
					self::MAX_INTERVAL,
					esc_html__( 'saat', 'linkflow-auditor' )
				);
				echo '<p class="description">' . esc_html__( 'Varsayılan kapalıdır. Açıldığında yalnızca kırık link raporu WordPress Cron ile çalışır; gerçek çalışma zamanı site trafiğine bağlıdır.', 'linkflow-auditor' ) . '</p>';

				if ( ! empty( $settings['auto_enabled'] ) && $next_run ) {
					printf(
						'<p class="description">%s <strong>%s</strong></p>',
						esc_html__( 'Sonraki otomatik kontrol:', 'linkflow-auditor' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_run ) )
					);
				}

				echo '</td></tr>';
				echo '</table>';
				submit_button( __( 'Ayarları Kaydet', 'linkflow-auditor' ), 'secondary', 'submit', false );
				echo '</form>';
			}

			/**
			 * Render admin notices.
			 */
			private function render_notice(): void {
				$notice = isset( $_GET['linkflow_auditor_notice'] ) ? sanitize_key( wp_unslash( $_GET['linkflow_auditor_notice'] ) ) : '';

				if ( 'settings_saved' !== $notice ) {
					return;
				}

				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ayarlar kaydedildi.', 'linkflow-auditor' ) . '</p></div>';
			}

			/**
			 * Return the latest saved report timestamp across all sections.
			 *
			 * @param array<string,mixed> $report Existing report.
			 */
			private function get_report_timestamp( array $report ): int {
				return max(
					isset( $report['created_at'] ) ? (int) $report['created_at'] : 0,
					isset( $report['internal_created_at'] ) ? (int) $report['internal_created_at'] : 0,
					isset( $report['broken_created_at'] ) ? (int) $report['broken_created_at'] : 0,
					isset( $report['redirect_created_at'] ) ? (int) $report['redirect_created_at'] : 0
				);
			}

		/**
		 * Render report status.
		 *
		 * @param array<string,mixed> $report Existing report.
		 * @param bool                $compact Whether to render compact dashboard copy.
		 */
		private function render_status( array $report, bool $compact ): void {
			$created_timestamp = $this->get_report_timestamp( $report );

			if ( $created_timestamp <= 0 ) {
				echo '<p class="lfa-empty">' . esc_html__( 'Henüz rapor yok.', 'linkflow-auditor' ) . '</p>';
				return;
			}

			$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
				$created_at  = wp_date( $date_format, $created_timestamp );
				$total_rows  = isset( $report['total_targets'] ) ? (int) $report['total_targets'] : count( $report['rows'] ?? array() );
				$total_src   = max(
					isset( $report['total_sources'] ) ? (int) $report['total_sources'] : 0,
					isset( $report['broken_total_sources'] ) ? (int) $report['broken_total_sources'] : 0,
					isset( $report['redirect_total_sources'] ) ? (int) $report['redirect_total_sources'] : 0
				);
				$checked     = max(
					isset( $report['checked_links'] ) ? (int) $report['checked_links'] : 0,
					isset( $report['broken_checked_links'] ) ? (int) $report['broken_checked_links'] : 0,
					isset( $report['redirect_checked_links'] ) ? (int) $report['redirect_checked_links'] : 0
				);
				$broken      = isset( $report['broken_link_count'] ) ? (int) $report['broken_link_count'] : count( (array) ( $report['broken_links'] ?? array() ) );
				$redirected  = isset( $report['redirect_link_count'] ) ? (int) $report['redirect_link_count'] : $this->count_redirect_usage( (array) ( $report['redirect_links'] ?? array() ) );

				if ( $compact ) {
					printf(
						'<p class="lfa-summary">%s <strong>%s</strong> | %s <strong>%d</strong> | %s <strong>%d</strong> | %s <strong>%d</strong> | %s <strong>%d</strong></p>',
						esc_html__( 'Son rapor:', 'linkflow-auditor' ),
						esc_html( $created_at ),
						esc_html__( 'Taranan:', 'linkflow-auditor' ),
						$total_src,
						esc_html__( 'Link:', 'linkflow-auditor' ),
						$checked,
						esc_html__( 'Kırık:', 'linkflow-auditor' ),
						$broken,
						esc_html__( 'Yönlendirmeli:', 'linkflow-auditor' ),
						$redirected
					);
					return;
				}

				printf(
					'<p class="lfa-summary">%s <strong>%s</strong> | %s <strong>%d</strong></p>',
					esc_html__( 'Son rapor:', 'linkflow-auditor' ),
					esc_html( $created_at ),
					esc_html__( 'Raporlanan içerik:', 'linkflow-auditor' ),
					$total_rows
				);

				echo '<div class="lfa-stats" aria-label="' . esc_attr__( 'Rapor özeti', 'linkflow-auditor' ) . '">';
				$this->render_stat_card( $total_src, __( 'Taranan sayfa/yazı', 'linkflow-auditor' ) );
				$this->render_stat_card( $checked, __( 'Kontrol edilen link', 'linkflow-auditor' ) );
				$this->render_stat_card( $broken, __( 'Kırık link', 'linkflow-auditor' ) );
				$this->render_stat_card( $redirected, __( 'Yönlendirmeli link', 'linkflow-auditor' ) );
				echo '</div>';
			}

			/**
			 * Render one summary card.
			 *
			 * @param int    $value Card value.
			 * @param string $label Card label.
			 */
			private function render_stat_card( int $value, string $label ): void {
				printf(
					'<div class="lfa-stat-card"><strong>%s</strong><span>%s</span></div>',
					esc_html( number_format_i18n( $value ) ),
					esc_html( $label )
				);
			}

		/**
		 * Render report table.
		 *
		 * @param array<string,mixed> $report Existing report.
		 * @param int                 $limit  Row limit. Zero means no limit.
		 */
			/**
			 * Render the report/filter toolbar for the internal link table.
			 *
			 * Filtering and CSV export run client-side against the already-rendered
			 * rows, so "0–3 link alan yazılar" style reports are instant.
			 *
			 * @param array<int,array<string,mixed>> $rows Internal link rows.
			 */
			private function render_internal_filter_bar( array $rows ): void {
				$total = count( $rows );
				$zero  = 0;
				$low   = 0;

				foreach ( $rows as $row ) {
					$sources = isset( $row['incoming_sources'] ) ? (int) $row['incoming_sources'] : 0;
					if ( 0 === $sources ) {
						++$zero;
					}
					if ( $sources <= 3 ) {
						++$low;
					}
				}

				echo '<div class="lfa-filterbar" data-lfa-filter>';

				echo '<div class="lfa-filter-presets" role="group" aria-label="' . esc_attr__( 'Hızlı raporlar', 'linkflow-auditor' ) . '">';
				$presets = array(
					array( '', '', __( 'Tümü', 'linkflow-auditor' ), $total ),
					array( '0', '0', __( '0 link (hiç link almayan)', 'linkflow-auditor' ), $zero ),
					array( '0', '3', __( '0–3 link', 'linkflow-auditor' ), $low ),
					array( '1', '3', __( '1–3 link', 'linkflow-auditor' ), max( 0, $low - $zero ) ),
					array( '4', '', __( '4+ link', 'linkflow-auditor' ), max( 0, $total - $low ) ),
				);

				$first = true;
				foreach ( $presets as $preset ) {
					printf(
						'<button type="button" class="lfa-preset%1$s" data-min="%2$s" data-max="%3$s">%4$s <span class="lfa-preset-count">%5$s</span></button>',
						$first ? ' is-active' : '',
						esc_attr( $preset[0] ),
						esc_attr( $preset[1] ),
						esc_html( $preset[2] ),
						esc_html( number_format_i18n( (int) $preset[3] ) )
					);
					$first = false;
				}
				echo '</div>';

				echo '<div class="lfa-filter-fields">';
				printf(
					'<label class="lfa-filter-range">%s <input type="number" class="lfa-filter-min small-text" min="0" step="1" placeholder="min" inputmode="numeric"> <span>–</span> <input type="number" class="lfa-filter-max small-text" min="0" step="1" placeholder="max" inputmode="numeric"></label>',
					esc_html__( 'Gelen link (yazı):', 'linkflow-auditor' )
				);
				printf(
					'<input type="search" class="lfa-filter-search regular-text" placeholder="%s">',
					esc_attr__( 'Başlıkta ara…', 'linkflow-auditor' )
				);
				printf(
					'<button type="button" class="button lfa-filter-reset">%s</button>',
					esc_html__( 'Sıfırla', 'linkflow-auditor' )
				);
				printf(
					'<button type="button" class="button button-primary lfa-export-csv">%s</button>',
					esc_html__( 'CSV indir', 'linkflow-auditor' )
				);
				echo '</div>';

				printf(
					'<div class="lfa-filter-summary" aria-live="polite">%s <strong class="lfa-filter-shown">%s</strong> / %s</div>',
					esc_html__( 'Gösterilen:', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $total ) ),
					esc_html( number_format_i18n( $total ) )
				);

				echo '</div>';
			}

			private function render_report_table( array $report, int $limit ): void {
				$rows = $report['rows'] ?? array();

				if ( empty( $rows ) || ! is_array( $rows ) ) {
					return;
			}

			if ( $limit > 0 ) {
				$rows = array_slice( $rows, 0, $limit );
			}

			$show_detail = 0 === $limit;

			echo '<div class="lfa-table-wrap">';
			echo '<table class="widefat striped lfa-report-table' . ( $show_detail ? ' lfa-report-table--full' : '' ) . '">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'İçerik', 'linkflow-auditor' ) . '</th>';
			echo '<th>' . esc_html__( 'Tür', 'linkflow-auditor' ) . '</th>';
			echo '<th title="' . esc_attr__( 'Bu içeriğe link veren benzersiz yazı sayısı. Güvenilir ölçü budur.', 'linkflow-auditor' ) . '">' . esc_html__( 'Gelen link (yazı)', 'linkflow-auditor' ) . '</th>';
			echo '<th title="' . esc_attr__( 'Toplam link geçişi. Aynı yazı birden çok kez link verirse hepsi sayılır.', 'linkflow-auditor' ) . '">' . esc_html__( 'Gelen (toplam)', 'linkflow-auditor' ) . '</th>';
			echo '<th>' . esc_html__( 'Çıkan link (hedef)', 'linkflow-auditor' ) . '</th>';
			echo '<th>' . esc_html__( 'Çıkan (toplam)', 'linkflow-auditor' ) . '</th>';
			echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
			echo '</tr></thead><tbody>';

			$detail_index = 0;

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$title = (string) ( $row['title'] ?? '' );
				if ( '' === trim( $title ) ) {
					$title = __( '(Başlıksız)', 'linkflow-auditor' );
				}

				$type_label = $this->get_post_type_label( (string) ( $row['type'] ?? '' ) );
				$url        = (string) ( $row['url'] ?? '' );
				$edit_url   = (string) ( $row['edit_url'] ?? '' );

				$incoming_total   = isset( $row['incoming_links'] ) ? (int) $row['incoming_links'] : (int) ( $row['total_links'] ?? 0 );
				$incoming_sources = isset( $row['incoming_sources'] ) ? (int) $row['incoming_sources'] : (int) ( $row['unique_sources'] ?? 0 );
				$outgoing_total   = isset( $row['outgoing_links'] ) ? (int) $row['outgoing_links'] : 0;
				$outgoing_targets = isset( $row['outgoing_targets'] ) ? (int) $row['outgoing_targets'] : 0;
				$detail_rows      = $show_detail ? array_values( array_filter( (array) ( $row['incoming_detail'] ?? array() ), 'is_array' ) ) : array();
				$has_detail       = ! empty( $detail_rows );
				$detail_id        = 'lfa-detail-' . $detail_index;
				$row_class        = 0 === $incoming_sources ? ' lfa-zero' : '';

				printf(
					'<tr class="lfa-row%1$s" data-incoming-sources="%2$d" data-incoming-links="%3$d" data-title="%4$s">',
					esc_attr( $row_class ),
					$incoming_sources,
					$incoming_total,
					esc_attr( $this->mb_lower( $title ) )
				);
				echo '<td>';

				if ( '' !== $url ) {
					printf(
						'<a href="%s" target="_blank" rel="noopener noreferrer"><strong>%s</strong></a>',
						esc_url( $url ),
						esc_html( $title )
					);
					printf(
						'<div class="lfa-url"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></div>',
						esc_url( $url ),
						esc_html( $url )
					);
				} else {
					echo '<strong>' . esc_html( $title ) . '</strong>';
				}

				echo '</td>';
				echo '<td><span class="lfa-type-badge">' . esc_html( $type_label ) . '</span></td>';
				echo '<td class="lfa-num lfa-num--primary">';

				if ( $has_detail ) {
					printf(
						'<button type="button" class="lfa-count-toggle" aria-expanded="false" aria-controls="%1$s" data-detail-target="%1$s"><span class="lfa-count-badge">%2$s</span><span class="lfa-toggle-caret" aria-hidden="true">▾</span></button>',
						esc_attr( $detail_id ),
						esc_html( number_format_i18n( $incoming_sources ) )
					);
				} else {
					echo '<span class="lfa-count-badge lfa-count-badge--empty">' . esc_html( number_format_i18n( $incoming_sources ) ) . '</span>';
				}

				echo '</td>';
				echo '<td class="lfa-num lfa-num--muted">' . esc_html( number_format_i18n( $incoming_total ) ) . '</td>';
				echo '<td class="lfa-num">' . esc_html( number_format_i18n( $outgoing_targets ) ) . '</td>';
				echo '<td class="lfa-num lfa-num--muted">' . esc_html( number_format_i18n( $outgoing_total ) ) . '</td>';
				echo '<td>';

				if ( '' !== $edit_url ) {
					printf(
						'<a class="lfa-edit-link" href="%s">%s</a>',
						esc_url( $edit_url ),
						esc_html__( 'Düzenle', 'linkflow-auditor' )
					);
				} else {
					echo '&mdash;';
				}

				echo '</td>';
				echo '</tr>';

				if ( $has_detail ) {
					$this->render_incoming_detail_row( $detail_id, $detail_rows );
				}

				++$detail_index;
			}

				echo '</tbody></table></div>';
			}

			/**
			 * Render the expandable "who links here" detail row for one target.
			 *
			 * @param string                         $detail_id   DOM id linking the toggle to this row.
			 * @param array<int,array<string,mixed>> $detail_rows Linking source rows.
			 */
			private function render_incoming_detail_row( string $detail_id, array $detail_rows ): void {
				echo '<tr class="lfa-detail-row" id="' . esc_attr( $detail_id ) . '" hidden>';
				echo '<td colspan="7">';
				echo '<div class="lfa-detail">';
				echo '<div class="lfa-detail-head">' . esc_html__( 'Bu içeriğe link veren yazılar', 'linkflow-auditor' ) . '</div>';
				echo '<table class="lfa-detail-table"><thead><tr>';
				echo '<th>' . esc_html__( 'Kaynak yazı', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Anchor text', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Link', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $detail_rows as $detail ) {
					$src_title = (string) ( $detail['title'] ?? '' );
					if ( '' === trim( $src_title ) ) {
						$src_title = __( '(Başlıksız)', 'linkflow-auditor' );
					}

					$src_url   = (string) ( $detail['url'] ?? '' );
					$edit_url  = (string) ( $detail['edit_url'] ?? '' );
					$count     = isset( $detail['count'] ) ? (int) $detail['count'] : 0;
					$anchors   = array_values( array_filter( (array) ( $detail['anchors'] ?? array() ), 'is_string' ) );

					echo '<tr>';
					echo '<td>';
					if ( '' !== $src_url ) {
						printf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( $src_url ),
							esc_html( $src_title )
						);
					} else {
						echo esc_html( $src_title );
					}
					echo '</td>';

					echo '<td>';
					if ( empty( $anchors ) ) {
						echo '<em class="lfa-anchor-empty">' . esc_html__( '(metin yok / görsel link)', 'linkflow-auditor' ) . '</em>';
					} else {
						$chips = array();
						foreach ( $anchors as $anchor ) {
							$chips[] = '<span class="lfa-anchor-chip">' . esc_html( $anchor ) . '</span>';
						}
						echo implode( ' ', $chips );
					}
					echo '</td>';

					printf(
						'<td class="lfa-num">%s&times;</td>',
						esc_html( number_format_i18n( $count ) )
					);

					echo '<td>';
					if ( '' !== $edit_url ) {
						printf(
							'<a class="lfa-edit-link" href="%s">%s</a>',
							esc_url( $edit_url ),
							esc_html__( 'Düzenle', 'linkflow-auditor' )
						);
					} else {
						echo '&mdash;';
					}
					echo '</td>';
					echo '</tr>';
				}

				echo '</tbody></table>';
				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}

			/**
			 * Render separate report sections.
			 *
			 * @param array<string,mixed> $report Existing report.
			 */
			private function render_report_tabs( array $report ): void {
				$rows           = array_values( array_filter( (array) ( $report['rows'] ?? array() ), 'is_array' ) );
				$broken_links   = array_values( array_filter( (array) ( $report['broken_links'] ?? array() ), 'is_array' ) );
				$redirect_links = array_values( array_filter( (array) ( $report['redirect_links'] ?? array() ), 'is_array' ) );
				$broken_count   = isset( $report['broken_link_count'] ) ? (int) $report['broken_link_count'] : count( $broken_links );
				$redirect_count = isset( $report['redirect_link_count'] ) ? (int) $report['redirect_link_count'] : $this->count_redirect_usage( $redirect_links );
				$has_internal   = ! empty( $report['internal_created_at'] ) || isset( $report['total_targets'] ) || ! empty( $rows );
				$has_broken     = ! empty( $report['broken_created_at'] ) || isset( $report['broken_link_count'] ) || ! empty( $broken_links );
				$has_redirect   = ! empty( $report['redirect_created_at'] ) || isset( $report['redirect_link_count'] ) || ! empty( $redirect_links );

				echo '<div class="lfa-tabs" data-lfa-tabs>';
				echo '<nav class="nav-tab-wrapper lfa-tab-nav" aria-label="' . esc_attr__( 'Rapor sekmeleri', 'linkflow-auditor' ) . '">';
				printf(
					'<a href="#lfa-internal-links" class="nav-tab nav-tab-active" data-lfa-tab="internal">%s <span class="lfa-tab-count">%s</span></a>',
					esc_html__( 'İç Link Sayımı', 'linkflow-auditor' ),
					esc_html( number_format_i18n( count( $rows ) ) )
				);
				printf(
					'<a href="#lfa-broken-links" class="nav-tab" data-lfa-tab="broken">%s <span class="lfa-tab-count">%s</span></a>',
					esc_html__( 'Kırık Linkler', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $broken_count ) )
				);
				printf(
					'<a href="#lfa-redirect-links" class="nav-tab" data-lfa-tab="redirects">%s <span class="lfa-tab-count">%s</span></a>',
					esc_html__( 'Yönlendirmeli Linkler', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $redirect_count ) )
				);
				echo '</nav>';

				echo '<div id="lfa-internal-links" class="lfa-tab-panel" data-lfa-panel="internal">';
				$this->render_scan_controls( $report, self::SCAN_MODE_INTERNAL, __( 'İç link sayılarını kontrol et', 'linkflow-auditor' ) );
				if ( $has_internal ) {
					if ( empty( $rows ) ) {
						echo '<p class="lfa-empty">' . esc_html__( 'Raporlanacak içerik bulunmadı.', 'linkflow-auditor' ) . '</p>';
					} else {
						$this->render_internal_filter_bar( $rows );
						$this->render_report_table( $report, 0 );
					}
				} else {
					echo '<p class="lfa-empty">' . esc_html__( 'Henüz iç link sayımı yapılmadı.', 'linkflow-auditor' ) . '</p>';
				}
				echo '</div>';

				echo '<div id="lfa-broken-links" class="lfa-tab-panel" data-lfa-panel="broken" hidden>';
				$this->render_scan_controls( $report, self::SCAN_MODE_BROKEN, __( 'Kırık linkleri kontrol et', 'linkflow-auditor' ), true );
				if ( $has_broken ) {
					$this->render_broken_links_table( $broken_links );
				} else {
					echo '<p class="lfa-empty">' . esc_html__( 'Henüz kırık link kontrolü yapılmadı.', 'linkflow-auditor' ) . '</p>';
				}
				echo '</div>';

				echo '<div id="lfa-redirect-links" class="lfa-tab-panel" data-lfa-panel="redirects" hidden>';
				$this->render_scan_controls( $report, self::SCAN_MODE_REDIRECT, __( 'Yönlendirmeli linkleri kontrol et', 'linkflow-auditor' ) );
				if ( $has_redirect ) {
					$this->render_redirect_links_table( $redirect_links );
				} else {
					echo '<p class="lfa-empty">' . esc_html__( 'Henüz yönlendirmeli link kontrolü yapılmadı.', 'linkflow-auditor' ) . '</p>';
				}
				echo '</div>';
				echo '</div>';
			}

			/**
			 * Render broken link rows.
			 *
			 * @param array<int,array<string,mixed>> $links Broken links.
			 */
			private function render_broken_links_table( array $links ): void {
				if ( empty( $links ) ) {
					echo '<p class="lfa-empty">' . esc_html__( 'Kırık link bulunmadı.', 'linkflow-auditor' ) . '</p>';
					return;
				}

				echo '<div class="lfa-table-wrap">';
				echo '<table class="widefat striped lfa-status-table lfa-broken-table">';
				echo '<thead><tr>';
				echo '<th>' . esc_html__( 'Kaynak Yazı/Sayfa', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Kaynak URL', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Anchor Text', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Verilen URL', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Durum', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Son Kontrol', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $links as $link ) {
					$source_id    = isset( $link['source_id'] ) ? (int) $link['source_id'] : 0;
					$source_title = (string) ( $link['source_title'] ?? '' );
					$source_url   = (string) ( $link['source_url'] ?? '' );
					$anchor_text  = (string) ( $link['anchor_text'] ?? '' );
					$raw_url      = (string) ( $link['raw_url'] ?? '' );
					$checked_url  = (string) ( $link['checked_url'] ?? '' );
					$status_code  = isset( $link['status_code'] ) ? (int) $link['status_code'] : 404;
					$status       = (string) ( $link['status'] ?? 'broken' );
					$message      = (string) ( $link['message'] ?? '' );
					$last_checked = isset( $link['last_checked'] ) ? (int) $link['last_checked'] : 0;

					if ( '' === trim( $source_title ) ) {
						$source_title = __( '(Başlıksız)', 'linkflow-auditor' );
					}

					echo '<tr>';
					echo '<td><strong>' . esc_html( $source_title ) . '</strong></td>';
					echo '<td>' . $this->render_url_cell( $source_url ) . '</td>';
					echo '<td>' . ( '' !== $anchor_text ? esc_html( $anchor_text ) : '&mdash;' ) . '</td>';
					echo '<td>' . $this->render_url_cell( $checked_url, $raw_url ) . '</td>';
					echo '<td><span class="' . esc_attr( $this->get_issue_status_class( $status ) ) . '">' . esc_html( $this->get_issue_status_label( $status, $status_code ) ) . '</span>';
					if ( '' !== $message ) {
						echo '<br><small>' . esc_html( $message ) . '</small>';
					}
					echo '</td>';
					echo '<td>' . esc_html( $this->get_last_checked_label( $last_checked ) ) . '</td>';
					echo '<td>' . $this->render_link_actions( 'broken', $source_id, $raw_url, '' ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table></div>';
			}

			/**
			 * Render redirected link groups.
			 *
			 * @param array<int,array<string,mixed>> $links Redirect groups.
			 */
			private function render_redirect_links_table( array $links ): void {
				if ( empty( $links ) ) {
					echo '<p class="lfa-empty">' . esc_html__( 'Yönlendirmeli link bulunmadı.', 'linkflow-auditor' ) . '</p>';
					return;
				}

				echo '<div class="lfa-table-wrap">';
				echo '<table class="widefat striped lfa-status-table lfa-redirect-table">';
				echo '<thead><tr>';
				echo '<th>' . esc_html__( 'Kaynak Yazı/Sayfa', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Kaynak URL', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Anchor Text', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Verilen URL', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Durum', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Yönlendirilen (Son) URL', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $links as $link ) {
					$url         = (string) ( $link['url'] ?? '' );
					$final_url   = (string) ( $link['final_url'] ?? '' );
					$status_code = isset( $link['status_code'] ) ? (int) $link['status_code'] : 0;
					$occurrences = array_values( array_filter( (array) ( $link['occurrences'] ?? array() ), 'is_array' ) );

					if ( empty( $occurrences ) ) {
						continue;
					}

					foreach ( $occurrences as $occurrence ) {
						$source_id    = isset( $occurrence['source_id'] ) ? (int) $occurrence['source_id'] : 0;
						$source_title = (string) ( $occurrence['source_title'] ?? '' );
						$source_url   = (string) ( $occurrence['source_url'] ?? '' );
						$anchor_text  = (string) ( $occurrence['anchor_text'] ?? '' );
						$raw_url      = (string) ( $occurrence['raw_url'] ?? '' );
						$checked_url  = (string) ( $occurrence['checked_url'] ?? $url );

						if ( '' === trim( $source_title ) ) {
							$source_title = __( '(Başlıksız)', 'linkflow-auditor' );
						}

						echo '<tr>';
						echo '<td><strong>' . esc_html( $source_title ) . '</strong></td>';
						echo '<td>' . $this->render_url_cell( $source_url ) . '</td>';
						echo '<td>' . ( '' !== $anchor_text ? esc_html( $anchor_text ) : '&mdash;' ) . '</td>';
						echo '<td>' . $this->render_url_cell( $checked_url, $raw_url ) . '</td>';
						echo '<td><strong>' . esc_html( (string) $status_code ) . '</strong></td>';
						echo '<td>' . $this->render_url_cell( $final_url ) . '</td>';
						echo '<td>' . $this->render_link_actions( 'redirect', $source_id, $raw_url, $final_url ) . '</td>';
						echo '</tr>';
					}
				}

				echo '</tbody></table></div>';
			}

			/**
			 * Render the remove/replace action controls for a link row.
			 *
			 * @param string $scope     Either 'broken' or 'redirect'.
			 * @param int    $source_id Source post ID.
			 * @param string $raw_url   Exact href as it appears in the post content.
			 * @param string $final_url Redirect destination (used for the direct redirect replace).
			 */
			private function render_link_actions( string $scope, int $source_id, string $raw_url, string $final_url ): string {
				if ( $source_id <= 0 || '' === $raw_url ) {
					return '&mdash;';
				}

				$data_attrs = sprintf(
					' data-scope="%s" data-source-id="%d" data-raw-url="%s"',
					esc_attr( $scope ),
					$source_id,
					esc_attr( $raw_url )
				);

				$html  = '<div class="lfa-actions">';
				$html .= sprintf(
					'<button type="button" class="button button-small lfa-fix-remove"%s>%s</button> ',
					$data_attrs,
					esc_html__( 'Kaldır', 'linkflow-auditor' )
				);

				if ( 'redirect' === $scope && '' !== $final_url ) {
					// Redirect rows replace the old URL directly with the resolved final URL.
					$html .= sprintf(
						'<button type="button" class="button button-small button-primary lfa-fix-replace"%s data-new-url="%s" data-direct="1">%s</button>',
						$data_attrs,
						esc_attr( $final_url ),
						esc_html__( 'Değiştir', 'linkflow-auditor' )
					);
				} else {
					// Broken rows open an inline box so a new URL can be pasted.
					$html .= sprintf(
						'<button type="button" class="button button-small button-primary lfa-fix-replace-toggle">%s</button>',
						esc_html__( 'Değiştir', 'linkflow-auditor' )
					);
					$html .= '<div class="lfa-replace-box" hidden>';
					$html .= sprintf(
						'<input type="url" class="lfa-replace-input regular-text" placeholder="%s"> ',
						esc_attr__( 'https://yeni-link-adresi', 'linkflow-auditor' )
					);
					$html .= sprintf(
						'<button type="button" class="button button-small button-primary lfa-fix-replace"%s>%s</button> ',
						$data_attrs,
						esc_html__( 'Değiştir', 'linkflow-auditor' )
					);
					$html .= sprintf(
						'<button type="button" class="button button-small lfa-fix-cancel">%s</button>',
						esc_html__( 'İptal', 'linkflow-auditor' )
					);
					$html .= '</div>';
				}

				$html .= '</div>';

				return $html;
			}

			/**
			 * Render a URL as an escaped table-cell link.
			 *
			 * @param string $url   Link target.
			 * @param string $label Optional display label.
			 */
			private function render_url_cell( string $url, string $label = '' ): string {
				$label = '' !== $label ? $label : $url;

				if ( '' === $label ) {
					return '&mdash;';
				}

				if ( '' === $url ) {
					return esc_html( $label );
				}

				return sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $url ),
					esc_html( $label )
				);
			}

			/**
			 * Return a CSS class for an issue status.
			 *
			 * @param string $status Issue status.
			 */
			private function get_issue_status_class( string $status ): string {
				$status = 'warning' === $status ? 'warning' : 'broken';

				return 'lfa-status lfa-status--' . $status;
			}

			/**
			 * Return a readable status label.
			 *
			 * @param string $status Issue status.
			 * @param int    $code HTTP status code.
			 */
			private function get_issue_status_label( string $status, int $code ): string {
				$suffix = $code > 0 ? ' ' . $code : '';

				if ( 'warning' === $status ) {
					return __( 'Kontrol gerekli', 'linkflow-auditor' ) . $suffix;
				}

				return __( 'Kırık', 'linkflow-auditor' ) . $suffix;
			}

			/**
			 * Return a readable last checked date.
			 *
			 * @param int $timestamp Last checked timestamp.
			 */
			private function get_last_checked_label( int $timestamp ): string {
				if ( $timestamp <= 0 ) {
					return __( 'Henüz kontrol edilmedi', 'linkflow-auditor' );
				}

				return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
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
					'check_external_links' => $check_external,
					'found_links'          => 0,
					'checked_links'        => 0,
					'broken_link_count'    => 0,
					'warning_link_count'   => 0,
					'redirect_link_count'  => 0,
					'broken_links'         => array(),
					'redirect_links'       => array(),
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
					'check_external_links' => $check_external,
					'found_links'          => 0,
					'checked_links'        => 0,
					'broken_link_count'    => 0,
					'warning_link_count'   => 0,
					'redirect_link_count'  => 0,
					'broken_links'         => array(),
					'redirect_links'       => array(),
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
		 * Remove or replace a single link inside a post and refresh the saved report.
		 */
		public function ajax_fix_link(): void {
			$this->verify_ajax_request();

			$scope     = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
			$source_id = isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0;
			$mode      = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
			$raw_url   = isset( $_POST['raw_url'] ) ? trim( (string) wp_unslash( $_POST['raw_url'] ) ) : '';
			$new_url   = isset( $_POST['new_url'] ) ? trim( esc_url_raw( (string) wp_unslash( $_POST['new_url'] ) ) ) : '';

			if ( ! in_array( $scope, array( self::SCAN_MODE_BROKEN, self::SCAN_MODE_REDIRECT ), true ) ) {
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
				)
			);
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
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				return new WP_Error( 'lfa_no_post', esc_html__( 'Yazı bulunamadı.', 'linkflow-auditor' ) );
			}

			$content = (string) $post->post_content;

			if ( '' === trim( $content ) || ! class_exists( 'DOMDocument' ) ) {
				return new WP_Error( 'lfa_no_content', esc_html__( 'Yazı içeriği düzenlenemiyor.', 'linkflow-auditor' ) );
			}

			$charset  = get_bloginfo( 'charset' ) ?: 'UTF-8';
			$document = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$loaded   = $document->loadHTML(
				'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=' . htmlspecialchars( $charset, ENT_QUOTES, 'UTF-8' ) . '"></head><body>' . $content . '</body></html>',
				LIBXML_NOWARNING | LIBXML_NOERROR
			);

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( ! $loaded ) {
				return new WP_Error( 'lfa_parse', esc_html__( 'Yazı içeriği işlenemedi.', 'linkflow-auditor' ) );
			}

			$target  = $this->normalize_match_url( $raw_url );
			$changed = 0;
			$nodes   = array();

			foreach ( $document->getElementsByTagName( 'a' ) as $node ) {
				$nodes[] = $node;
			}

			foreach ( $nodes as $node ) {
				$href = (string) $node->getAttribute( 'href' );

				if ( '' === $href || $this->normalize_match_url( $href ) !== $target ) {
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

			$body     = $document->getElementsByTagName( 'body' )->item( 0 );
			$new_html = '';

			if ( $body ) {
				foreach ( $body->childNodes as $child ) {
					$new_html .= $document->saveHTML( $child );
				}
			}

			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new_html,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $changed;
		}

		/**
		 * Normalize a URL for loose comparison (entity-decode + trim).
		 */
		private function normalize_match_url( string $url ): string {
			$url = html_entity_decode( $url, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );

			return trim( $url );
		}

		/**
		 * Drop fixed occurrences from the saved report and return refreshed counts.
		 *
		 * @param string $scope     Either 'broken' or 'redirect'.
		 * @param int    $source_id Source post ID.
		 * @param string $raw_url   Matched href.
		 * @return array{broken_count:int,redirect_count:int}
		 */
		private function update_report_after_fix( string $scope, int $source_id, string $raw_url ): array {
			$report = $this->get_report();
			$target = $this->normalize_match_url( $raw_url );

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

			$broken_count   = isset( $report['broken_link_count'] )
				? (int) $report['broken_link_count']
				: count( (array) ( $report['broken_links'] ?? array() ) );
			$redirect_count = isset( $report['redirect_link_count'] )
				? (int) $report['redirect_link_count']
				: $this->count_redirect_usage( (array) ( $report['redirect_links'] ?? array() ) );

			return array(
				'broken_count'   => $broken_count,
				'redirect_count' => $redirect_count,
			);
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
				$report = get_option( self::REPORT_OPTION, array() );

				return is_array( $report ) ? $report : array();
			}

			/**
			 * Default persistent settings.
			 *
			 * @return array<string,mixed>
			 */
			private static function default_settings(): array {
				return array(
					'check_external_links' => false,
					'auto_enabled'         => false,
					'interval_hours'       => self::DEFAULT_INTERVAL,
				);
			}

			/**
			 * Get persistent settings.
			 *
			 * @return array<string,mixed>
			 */
			private function get_settings(): array {
				$stored = get_option( self::SETTINGS_OPTION, array() );
				if ( ! is_array( $stored ) ) {
					$stored = array();
				}

				$legacy_external = (bool) get_option( self::CHECK_EXTERNAL_OPTION, false );
				$settings        = array_merge( self::default_settings(), $stored );

				return array(
					'check_external_links' => ! empty( $settings['check_external_links'] ) || $legacy_external,
					'auto_enabled'         => ! empty( $settings['auto_enabled'] ),
					'interval_hours'       => $this->normalize_interval_hours( $settings['interval_hours'] ?? self::DEFAULT_INTERVAL ),
				);
			}

			/**
			 * Save persistent settings.
			 *
			 * @param array<string,mixed> $settings Settings.
			 */
			private function save_settings( array $settings ): void {
				$this->update_nonautoload_option(
					self::SETTINGS_OPTION,
					array(
						'check_external_links' => ! empty( $settings['check_external_links'] ),
						'auto_enabled'         => ! empty( $settings['auto_enabled'] ),
						'interval_hours'       => $this->normalize_interval_hours( $settings['interval_hours'] ?? self::DEFAULT_INTERVAL ),
					)
				);
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
				$hours = absint( $value );

				if ( $hours < self::MIN_INTERVAL ) {
					return self::MIN_INTERVAL;
				}

				if ( $hours > self::MAX_INTERVAL ) {
					return self::MAX_INTERVAL;
				}

				return $hours;
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
			$key_count = array();

			foreach ( $ids as $id ) {
				$url = get_permalink( $id );
					if ( ! $url ) {
						continue;
					}

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
						'id'       => $id,
						'title'    => get_the_title( $id ),
						'type'     => get_post_type( $id ),
						'url'      => $url,
						'edit_url' => $edit_url,
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

			$scan_mode             = $this->sanitize_scan_mode( (string) ( $state['scan_mode'] ?? self::SCAN_MODE_INTERNAL ) );
			$should_check_status   = in_array( $scan_mode, array( self::SCAN_MODE_BROKEN, self::SCAN_MODE_REDIRECT ), true );
			$should_count_internal = self::SCAN_MODE_INTERNAL === $scan_mode;

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

						$target_id = $this->resolve_internal_href( $href, $url_index, (string) $source_url );

						if ( $target_id <= 0 || $target_id === $source_id ) {
						continue;
					}

					if ( ! isset( $state['incoming_links'][ $target_id ] ) ) {
						$state['incoming_links'][ $target_id ] = 0;
					}

					++$state['incoming_links'][ $target_id ];
					++$outgoing_link_count;

					$this->record_incoming_detail( $state, $target_id, $source_id, $anchor_text );

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
			 */
			private function record_incoming_detail( array &$state, int $target_id, int $source_id, string $anchor_text ): void {
				if ( ! isset( $state['incoming_details'][ $target_id ] ) ) {
					$state['incoming_details'][ $target_id ] = array();
				}

				if ( ! isset( $state['incoming_details'][ $target_id ][ $source_id ] ) ) {
					$state['incoming_details'][ $target_id ][ $source_id ] = array(
						'count'   => 0,
						'anchors' => array(),
					);
				}

				++$state['incoming_details'][ $target_id ][ $source_id ]['count'];

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
			 * Extract link href and anchor text values from HTML.
			 *
			 * @param string $html HTML content.
			 * @return array<int,array{href:string,text:string}>
			 */
			private function extract_links( string $html ): array {
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
			private function extract_hrefs( string $html ): array {
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
			private function normalize_anchor_text( string $text ): string {
				$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
				$text = preg_replace( '/\s+/u', ' ', $text );

				return trim( (string) $text );
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
				$parts = $this->parse_href( $location, $base_url );

				return empty( $parts ) ? '' : $this->build_url_from_parts( $parts );
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
			 * Whether a status code is any redirect that should be followed.
			 *
			 * @param int $status_code HTTP status code.
			 */
			private function is_any_redirect_status( int $status_code ): bool {
				return $status_code >= 300 && $status_code < 400;
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
				$home_host = $this->normalize_host( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: '' ) );
				$link_host = $this->normalize_host( (string) ( $parts['host'] ?? '' ) );

				return '' !== $home_host && $home_host === $link_host;
			}

			/**
			 * Build a URL string from parsed parts without the fragment.
			 *
			 * @param array<string,string> $parts URL parts.
			 */
			private function build_url_from_parts( array $parts ): string {
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
			$host = $this->mb_lower( trim( $host ) );

			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}

			return $host;
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
			if ( function_exists( 'mb_strtolower' ) ) {
				return mb_strtolower( $value, 'UTF-8' );
			}

			return strtolower( $value );
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

			return $this->mb_lower( $path );
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
							'incoming_links'   => isset( $state['incoming_links'][ $target_id ] ) ? (int) $state['incoming_links'][ $target_id ] : 0,
							'incoming_sources' => isset( $state['incoming_sources'][ $target_id ] ) ? (int) $state['incoming_sources'][ $target_id ] : 0,
							'incoming_detail'  => $this->build_incoming_detail_rows( $state, $target_id ),
							'outgoing_links'   => isset( $state['outgoing_links'][ $target_id ] ) ? (int) $state['outgoing_links'][ $target_id ] : 0,
							'outgoing_targets' => isset( $state['outgoing_targets'][ $target_id ] ) ? (int) $state['outgoing_targets'][ $target_id ] : 0,
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
		 * Return a readable post type label.
		 *
		 * @param string $post_type Post type.
		 */
		private function get_post_type_label( string $post_type ): string {
			if ( 'page' === $post_type ) {
				return __( 'Sayfa', 'linkflow-auditor' );
			}

			if ( 'post' === $post_type ) {
				return __( 'Yazı', 'linkflow-auditor' );
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

	register_activation_hook( __FILE__, array( 'LinkFlow_Auditor', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'LinkFlow_Auditor', 'deactivate' ) );

	LinkFlow_Auditor::instance();
