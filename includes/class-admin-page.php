<?php
/**
 * Admin page rendering for LinkFlow Auditor.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Admin_Page' ) ) {
	/**
	 * Renders the dashboard widget and Tools admin page.
	 */
	final class LinkFlow_Auditor_Admin_Page {
		private const VERSION                 = '1.11.6';
		private const NONCE_ACTION            = 'linkflow_auditor_admin';
		private const PAGE_SLUG               = 'linkflow-auditor';
		private const CRON_HOOK               = 'linkflow_auditor_run_background_scan';
		private const MIN_INTERVAL            = 1;
		private const MAX_INTERVAL            = 168;
		private const SCAN_MODE_INTERNAL      = 'internal';
		private const SCAN_MODE_BROKEN        = 'broken';
		private const SCAN_MODE_REDIRECT      = 'redirect';
		private const SCAN_MODE_EXTERNAL      = 'external';
		private const SCAN_MODE_INTERNAL_FIX  = 'internal';
		private const HEALTH_DISPLAY_CAP      = 100;
		private const SUGGESTION_BATCH_SIZE   = 25;

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
		 * Constructor.
		 *
		 * @param LinkFlow_Auditor_Report_Store   $store Report store.
		 * @param LinkFlow_Auditor_Url_Normalizer $url_normalizer URL/text normalizer.
		 */
		public function __construct( LinkFlow_Auditor_Report_Store $store, LinkFlow_Auditor_Url_Normalizer $url_normalizer ) {
			$this->store          = $store;
			$this->url_normalizer = $url_normalizer;
		}

	private function get_report(): array {
		return $this->store->get_report();
	}

	private function get_settings(): array {
		return $this->store->get_settings();
	}

	private function get_ignored_suggestion_ids(): array {
		return $this->store->get_ignored_suggestion_ids();
	}

	private function sanitize_scan_mode( string $scan_mode ): string {
		if ( in_array( $scan_mode, array( self::SCAN_MODE_INTERNAL, self::SCAN_MODE_BROKEN, self::SCAN_MODE_REDIRECT ), true ) ) {
			return $scan_mode;
		}

		return self::SCAN_MODE_INTERNAL;
	}

	private function mb_lower( string $value ): string {
		return $this->url_normalizer->mb_lower( $value );
	}

	/**
	 * Build an asset cache-busting version from the file modification time so any
	 * JS/CSS change busts the browser/CDN cache even if the plugin version is same.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	private function asset_version( string $relative ): string {
		$path  = LINKFLOW_AUDITOR_PATH . $relative;
		$mtime = file_exists( $path ) ? (int) filemtime( $path ) : 0;

		return $mtime > 0 ? (string) $mtime : self::VERSION;
	}

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

		public function enqueue_admin_assets( string $hook ): void {
			if ( 'index.php' !== $hook && 'tools_page_' . self::PAGE_SLUG !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'lfa-admin',
				plugins_url( 'assets/admin.css', LINKFLOW_AUDITOR_FILE ),
				array(),
				$this->asset_version( 'assets/admin.css' )
			);

			wp_enqueue_script(
				'lfa-admin',
				plugins_url( 'assets/admin.js', LINKFLOW_AUDITOR_FILE ),
				array( 'jquery' ),
				$this->asset_version( 'assets/admin.js' ),
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
						'clearingAll' => esc_html__( 'Uygulama kayıtları siliniyor...', 'linkflow-auditor' ),
						'error'    => esc_html__( 'İşlem tamamlanamadı. Lütfen tekrar deneyin.', 'linkflow-auditor' ),
						'fixing'   => esc_html__( 'Link güncelleniyor...', 'linkflow-auditor' ),
						'removing' => esc_html__( 'Link kaldırılıyor...', 'linkflow-auditor' ),
						'accepting' => esc_html__( 'Öneri uygulanıyor...', 'linkflow-auditor' ),
						'dismissing' => esc_html__( 'Öneri kaldırılıyor...', 'linkflow-auditor' ),
						'resetting' => esc_html__( 'Kaldırılan öneriler sıfırlanıyor...', 'linkflow-auditor' ),
						'searching' => esc_html__( 'Öneriler aranıyor...', 'linkflow-auditor' ),
						'changingSuggestions' => esc_html__( 'Farklı öneriler hazırlanıyor...', 'linkflow-auditor' ),
						'clearingSuggestionRecords' => esc_html__( 'Öneri seçim kaydı siliniyor...', 'linkflow-auditor' ),
						'confirmRemove'  => esc_html__( 'Bu link kaldırılsın mı? (Bağlantı silinir, metin yazıda kalır.)', 'linkflow-auditor' ),
						'confirmReplace' => esc_html__( 'Bu link yönlendirilen adresle değiştirilsin mi?', 'linkflow-auditor' ),
						'confirmAccept'  => esc_html__( 'Bu öneri kabul edilsin ve kaynak içerikteki ifade hedef sayfaya linklensin mi?', 'linkflow-auditor' ),
						'confirmDismiss' => esc_html__( 'Bu öneri kaldırılsın ve tekrar önerilmesin mi?', 'linkflow-auditor' ),
						'confirmResetDismissed' => esc_html__( 'Kaldırılan öneriler sıfırlansın mı? Sonra önerileri yenilerseniz tekrar görünebilirler.', 'linkflow-auditor' ),
						'confirmClearSuggestionRecords' => esc_html__( 'Öneri seçim kaydı silinsin mi? Sonrasında öneriler ilk sıradan tekrar gösterilebilir.', 'linkflow-auditor' ),
						'confirmClearAllRecords' => esc_html__( 'Tüm raporlar, geçici tarama kayıtları ve öneri kayıtları silinsin mi? Ayarlar korunur.', 'linkflow-auditor' ),
						'emptyUrl'       => esc_html__( 'Lütfen yeni bir URL girin.', 'linkflow-auditor' ),
						'emptyAnchor'    => esc_html__( 'Lütfen linklenecek ifadeyi girin.', 'linkflow-auditor' ),
						'emptySourceUrl' => esc_html__( 'Lütfen kaynak URL girin.', 'linkflow-auditor' ),
					),
				)
			);
		}

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
				$this->render_data_cleanup_bar();
				$this->render_notice();
				$this->render_settings_form();
				$this->render_status( $report, false );
				$this->render_report_tabs( $report );

				echo '</div>';
			}

			private function render_data_cleanup_bar(): void {
				echo '<div class="lfa-cleanup-bar">';
				echo '<div>';
				echo '<strong>' . esc_html__( 'Kayıt temizliği', 'linkflow-auditor' ) . '</strong>';
				echo '<span>' . esc_html__( 'Son rapor, geçici tarama oturumları ve öneri kayıtlarını siler; ayarlar korunur.', 'linkflow-auditor' ) . '</span>';
				echo '</div>';
				printf(
					'<button type="button" class="button lfa-clear-all-records">%s</button>',
					esc_html__( 'Tüm kayıtları sil', 'linkflow-auditor' )
				);
				echo '<div class="lfa-message" aria-live="polite"></div>';
				echo '</div>';
			}

			private function render_scan_controls( array $report, string $scan_mode, string $button_label, bool $show_external = false ): void {
				$scan_mode      = $this->sanitize_scan_mode( $scan_mode );
				$clear_disabled = empty( $report['rows'] ) && empty( $report['suggestions'] ) && empty( $report['broken_links'] ) && empty( $report['redirect_links'] ) && empty( $report['created_at'] );
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

			private function render_notice(): void {
				$notice = isset( $_GET['linkflow_auditor_notice'] ) ? sanitize_key( wp_unslash( $_GET['linkflow_auditor_notice'] ) ) : '';

				if ( 'settings_saved' !== $notice ) {
					return;
				}

				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ayarlar kaydedildi.', 'linkflow-auditor' ) . '</p></div>';
			}

			private function get_report_timestamp( array $report ): int {
				return max(
					isset( $report['created_at'] ) ? (int) $report['created_at'] : 0,
					isset( $report['internal_created_at'] ) ? (int) $report['internal_created_at'] : 0,
					isset( $report['broken_created_at'] ) ? (int) $report['broken_created_at'] : 0,
					isset( $report['redirect_created_at'] ) ? (int) $report['redirect_created_at'] : 0
				);
			}

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
				$broken      = $this->get_broken_issue_count( $report );
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
						esc_html__( 'Sorunlu:', 'linkflow-auditor' ),
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
				$this->render_stat_card( $broken, __( 'Sorunlu link', 'linkflow-auditor' ) );
				$this->render_stat_card( $redirected, __( 'Yönlendirmeli link', 'linkflow-auditor' ) );
				echo '</div>';
			}

			private function render_stat_card( int $value, string $label ): void {
				printf(
					'<div class="lfa-stat-card"><strong>%s</strong><span>%s</span></div>',
					esc_html( number_format_i18n( $value ) ),
					esc_html( $label )
				);
			}

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
				$shared_url       = ! empty( $row['shared_url'] );
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

				if ( $shared_url ) {
					printf(
						'<div class="lfa-dupe-badge" title="%s">⚠ %s</div>',
						esc_attr__( 'Aynı adresi birden fazla yayınlanmış içerik paylaşıyor. Gelen linkler her ikisine de sayılır. SEO için birini silip diğerine 301 yönlendirmesi yapın.', 'linkflow-auditor' ),
						esc_html__( 'Aynı URL’yi paylaşan içerik', 'linkflow-auditor' )
					);
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

			private function render_incoming_detail_row( string $detail_id, array $detail_rows ): void {
				echo '<tr class="lfa-detail-row" id="' . esc_attr( $detail_id ) . '" hidden>';
				echo '<td colspan="7">';
				echo '<div class="lfa-detail">';
				echo '<div class="lfa-detail-head">';
				echo '<span>' . esc_html__( 'Bu içeriğe link veren yazılar', 'linkflow-auditor' ) . '</span>';
				printf(
					'<button type="button" class="lfa-detail-close" data-detail-target="%s">%s</button>',
					esc_attr( $detail_id ),
					esc_html__( '✕ Kapat', 'linkflow-auditor' )
				);
				echo '</div>';
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

					$src_id    = isset( $detail['id'] ) ? (int) $detail['id'] : 0;
					$src_url   = (string) ( $detail['url'] ?? '' );
					$edit_url  = (string) ( $detail['edit_url'] ?? '' );
					$raw_url   = (string) ( $detail['raw_url'] ?? '' );
					$count     = isset( $detail['count'] ) ? (int) $detail['count'] : 0;
					$anchors   = array_values( array_filter( (array) ( $detail['anchors'] ?? array() ), 'is_string' ) );
					$editable  = array_key_exists( 'editable', $detail ) ? ! empty( $detail['editable'] ) : true;

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
					}
					if ( $editable ) {
						// Remove/replace the link inside the source post.
						echo $this->render_link_actions( self::SCAN_MODE_INTERNAL_FIX, $src_id, $raw_url, '' );
					} else {
						echo '<span class="lfa-uneditable-note">' . esc_html__( 'Bu link bir shortcode/blok tarafından üretiliyor, buradan düzenlenemez.', 'linkflow-auditor' ) . '</span>';
					}
					echo '</td>';
					echo '</tr>';
				}

				echo '</tbody></table>';
				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}

			private function render_report_tabs( array $report ): void {
				$rows           = array_values( array_filter( (array) ( $report['rows'] ?? array() ), 'is_array' ) );
				$suggestions    = array_values( array_filter( (array) ( $report['suggestions'] ?? array() ), 'is_array' ) );
				$broken_links   = array_values( array_filter( (array) ( $report['broken_links'] ?? array() ), 'is_array' ) );
				$redirect_links = array_values( array_filter( (array) ( $report['redirect_links'] ?? array() ), 'is_array' ) );
				$suggestion_count = isset( $report['suggestion_count'] ) ? (int) $report['suggestion_count'] : count( $suggestions );
				$broken_count   = $this->get_broken_issue_count( $report, $broken_links );
				$redirect_count = isset( $report['redirect_link_count'] ) ? (int) $report['redirect_link_count'] : $this->count_redirect_usage( $redirect_links );
				$has_internal   = ! empty( $report['internal_created_at'] ) || isset( $report['total_targets'] ) || ! empty( $rows );
				$has_broken     = ! empty( $report['broken_created_at'] ) || isset( $report['broken_link_count'] ) || ! empty( $broken_links );
				$has_redirect   = ! empty( $report['redirect_created_at'] ) || isset( $report['redirect_link_count'] ) || ! empty( $redirect_links );

				echo '<div class="lfa-tabs" data-lfa-tabs>';
				echo '<nav class="nav-tab-wrapper lfa-tab-nav" aria-label="' . esc_attr__( 'Rapor sekmeleri', 'linkflow-auditor' ) . '">';
				$health_count = $this->get_health_issue_count( $report );
				printf(
					'<a href="#lfa-internal-links" class="nav-tab nav-tab-active" data-lfa-tab="internal">%s <span class="lfa-tab-count">%s</span></a>',
					esc_html__( 'İç Link Sayımı', 'linkflow-auditor' ),
					esc_html( number_format_i18n( count( $rows ) ) )
				);
				printf(
					'<a href="#lfa-health" class="nav-tab" data-lfa-tab="health">%s <span class="lfa-tab-count lfa-tab-count--alert">%s</span></a>',
					esc_html__( 'Link Sağlığı', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $health_count ) )
				);
				printf(
					'<a href="#lfa-suggestions" class="nav-tab" data-lfa-tab="suggestions">%s <span class="lfa-tab-count">%s</span></a>',
					esc_html__( 'İç Link Önerileri', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $suggestion_count ) )
				);
				printf(
					'<a href="#lfa-manual-suggestions" class="nav-tab" data-lfa-tab="manual-suggestions">%s</a>',
					esc_html__( 'Manuel Öneri', 'linkflow-auditor' )
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
				$external_total = isset( $report['external_total'] ) ? (int) $report['external_total'] : count( (array) ( $report['external_links'] ?? array() ) );
				printf(
					'<a href="#lfa-external-links" class="nav-tab" data-lfa-tab="external">%s <span class="lfa-tab-count">%s</span></a>',
					esc_html__( 'Dış Linkler', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $external_total ) )
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

				echo '<div id="lfa-health" class="lfa-tab-panel" data-lfa-panel="health" hidden>';
				$this->render_health_tab( $report, $has_internal );
				echo '</div>';

				echo '<div id="lfa-suggestions" class="lfa-tab-panel" data-lfa-panel="suggestions" hidden>';
				$this->render_suggestions_tab( $report, $has_internal );
				echo '</div>';

				echo '<div id="lfa-manual-suggestions" class="lfa-tab-panel" data-lfa-panel="manual-suggestions" hidden>';
				$this->render_manual_suggestions_tab( $report, $has_internal );
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

				echo '<div id="lfa-external-links" class="lfa-tab-panel" data-lfa-panel="external" hidden>';
				$this->render_external_links_tab( $report, $has_internal );
				echo '</div>';
				echo '</div>';
			}

			private function render_health_tab( array $report, bool $has_internal ): void {
				echo '<div class="lfa-controls">';
				printf(
					'<button type="button" class="button button-primary lfa-start" data-scan-mode="%s" data-result-tab="health">%s</button>',
					esc_attr( self::SCAN_MODE_INTERNAL ),
					esc_html__( 'Link sağlığını kontrol et', 'linkflow-auditor' )
				);
				echo '<span class="spinner lfa-spinner" aria-hidden="true"></span>';
				echo '</div>';
				echo '<div class="lfa-progress" hidden><div class="lfa-progress-bar"><span></span></div><strong>0%</strong></div>';
				echo '<div class="lfa-message" aria-live="polite"></div>';
				echo '<p class="description">' . esc_html__( 'Link sağlığı verisi iç link taramasıyla birlikte üretilir; ayrı bir HTTP isteği yapmaz.', 'linkflow-auditor' ) . '</p>';

				$health = (array) ( $report['health'] ?? array() );

				if ( ! $has_internal || empty( $health ) ) {
					echo '<p class="lfa-empty">' . esc_html__( 'Link sağlığı raporu için önce iç link taramasını çalıştırın.', 'linkflow-auditor' ) . '</p>';
					return;
				}

				$total = $this->get_health_issue_count( $report );

				if ( 0 === $total ) {
					echo '<div class="lfa-health-allgood">✅ ' . esc_html__( 'Harika! Taranan içerikte bir link sağlığı sorunu bulunamadı.', 'linkflow-auditor' ) . '</div>';
					return;
				}

				$duplicates = array_values( array_filter( (array) ( $health['duplicates'] ?? array() ), 'is_array' ) );
				$orphans    = array_values( array_filter( (array) ( $health['orphans'] ?? array() ), 'is_array' ) );
				$dead_ends  = array_values( array_filter( (array) ( $health['dead_ends'] ?? array() ), 'is_array' ) );
				$insecure   = array_values( array_filter( (array) ( $health['insecure'] ?? array() ), 'is_array' ) );
				$weak       = array_values( array_filter( (array) ( $health['weak_anchor'] ?? array() ), 'is_array' ) );

				// Duplicate permalinks (critical).
				$this->open_health_section( 'critical', '🔁', __( 'Aynı URL’yi paylaşan içerik', 'linkflow-auditor' ), count( $duplicates ), __( 'Aynı adreste birden fazla yayınlanmış içerik. Google hangisini sıralayacağını bilemez; birini silip diğerine 301 yönlendirmesi yapın.', 'linkflow-auditor' ) );
				if ( empty( $duplicates ) ) {
					$this->render_health_ok();
				} else {
					foreach ( $duplicates as $group ) {
						$this->render_health_duplicate_group( $group );
					}
				}
				$this->close_health_section();

				// Orphan content (critical).
				$this->open_health_section( 'critical', '🕳️', __( 'Öksüz içerik (0 gelen link)', 'linkflow-auditor' ), count( $orphans ), __( 'Sitenizde hiçbir içerikten iç link almayan yayınlar. Keşfedilmesi ve sıralanması zordur; ilgili yazılardan bunlara link verin.', 'linkflow-auditor' ) );
				$this->render_health_item_table( $orphans );
				$this->close_health_section();

				// Dead-end content (warning).
				$this->open_health_section( 'warning', '🚧', __( 'Çıkışsız içerik (0 çıkan link)', 'linkflow-auditor' ), count( $dead_ends ), __( 'Hiç iç link vermeyen “çıkmaz sokak” sayfalar. Link gücünü dağıtmaz; ilgili içeriklere bağlantı ekleyin.', 'linkflow-auditor' ) );
				$this->render_health_item_table( $dead_ends );
				$this->close_health_section();

				// Insecure (mixed content) links (warning).
				$this->open_health_section( 'warning', '🔓', __( 'Güvensiz (http) iç linkler', 'linkflow-auditor' ), (int) ( $health['insecure_total'] ?? 0 ), __( 'Siteniz https iken http:// ile yazılmış iç linkler. Gereksiz yönlendirme ve karışık içerik (mixed content) uyarısı yaratır; https:// ile değiştirin.', 'linkflow-auditor' ) );
				$this->render_health_insecure_table( $insecure, (int) ( $health['insecure_total'] ?? 0 ) );
				$this->close_health_section();

				// Weak/empty anchor text (info).
				$this->open_health_section( 'info', '🏷️', __( 'Zayıf/eksik anchor text', 'linkflow-auditor' ), (int) ( $health['weak_total'] ?? 0 ), __( '“Tıklayın”, “buraya”, “devamı” gibi genel ya da tamamen boş bağlantı metinleri. Hedefi anlatan açıklayıcı metinler kullanın.', 'linkflow-auditor' ) );
				$this->render_health_weak_table( $weak, (int) ( $health['weak_total'] ?? 0 ) );
				$this->close_health_section();
			}

			private function render_suggestions_tab( array $report, bool $has_internal ): void {
				echo '<div class="lfa-controls">';
				printf(
					'<button type="button" class="button button-primary lfa-start" data-scan-mode="%s" data-result-tab="suggestions">%s</button>',
					esc_attr( self::SCAN_MODE_INTERNAL ),
					esc_html__( 'Önerileri yenile', 'linkflow-auditor' )
				);
				echo '<span class="spinner lfa-spinner" aria-hidden="true"></span>';
				echo '</div>';
				echo '<div class="lfa-progress" hidden><div class="lfa-progress-bar"><span></span></div><strong>0%</strong></div>';
				echo '<div class="lfa-message" aria-live="polite"></div>';
				echo '<p class="description">' . esc_html__( 'Öneriler, daha az iç link alan hedeflere öncelik verir ve sadece kaynak içerikte güvenle linklenebilecek ifade bulunduğunda gösterilir.', 'linkflow-auditor' ) . '</p>';
				$this->render_internal_scan_note( $report, $has_internal, 'suggestions' );
				$this->render_dismissed_suggestions_reset_box();
				$this->render_suggestion_rotation_reset_box( 'normal' );

				if ( ! $has_internal ) {
					echo '<p class="lfa-empty">' . esc_html__( 'İç link önerileri için önce taramayı çalıştırın.', 'linkflow-auditor' ) . '</p>';
					return;
				}

				$suggestions = array_values( array_filter( (array) ( $report['suggestions'] ?? array() ), 'is_array' ) );
				$total       = isset( $report['suggestion_count'] ) ? (int) $report['suggestion_count'] : count( $suggestions );

				if ( empty( $suggestions ) ) {
					echo '<p class="lfa-empty">' . esc_html__( 'Uygulanabilir iç link önerisi bulunamadı.', 'linkflow-auditor' ) . '</p>';
					return;
				}

				$shown    = array_slice( $suggestions, 0, self::SUGGESTION_BATCH_SIZE );
				$has_more = count( $suggestions ) > self::SUGGESTION_BATCH_SIZE;

				echo '<div class="lfa-suggestion-results" aria-live="polite">';
				echo $this->render_saved_suggestions_results( $shown, $total, $has_more );
				echo '</div>';
			}

			public function render_saved_suggestions_results( array $suggestions, int $total, bool $has_more ): string {
				if ( empty( $suggestions ) ) {
					return '<p class="lfa-empty">' . esc_html__( 'Gösterilecek farklı öneri kalmadı. Seçim kaydını silerseniz öneriler baştan listelenir.', 'linkflow-auditor' ) . '</p>';
				}

				$current_ids = array();
				foreach ( $suggestions as $suggestion ) {
					$id = sanitize_key( (string) ( $suggestion['id'] ?? '' ) );
					if ( '' !== $id ) {
						$current_ids[] = $id;
					}
				}

				ob_start();
				printf(
					'<input type="hidden" class="lfa-current-suggestion-ids" value="%s">',
					esc_attr( implode( ',', $current_ids ) )
				);
				echo '<div class="lfa-suggestion-toolbar">';
				printf(
					'<input type="search" class="lfa-suggestion-search regular-text" placeholder="%s">',
					esc_attr__( 'Kaynak, hedef veya anchor metninde ara…', 'linkflow-auditor' )
				);
				echo '<div class="lfa-suggestion-toolbar-actions">';
				printf(
					'<span class="lfa-external-summary">%s <strong>%s</strong></span>',
					esc_html__( 'Toplam öneri:', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $total ) )
				);
				printf(
					'<button type="button" class="button lfa-change-suggestions"%s>%s</button>',
					disabled( ! $has_more, true, false ),
					esc_html__( 'Önerileri değiştir', 'linkflow-auditor' )
				);
				echo '</div>';
				echo '</div>';

				echo '<div class="lfa-table-wrap"><table class="widefat striped lfa-status-table lfa-suggestion-table"><thead><tr>';
				echo '<th>' . esc_html__( 'Kaynak içerik', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Linklenecek ifade', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Hedef sayfa', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Neden önerildi?', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $suggestions as $suggestion ) {
					$source_title = (string) ( $suggestion['source_title'] ?? '' );
					$source_url   = (string) ( $suggestion['source_url'] ?? '' );
					$target_title = (string) ( $suggestion['target_title'] ?? '' );
					$target_url   = (string) ( $suggestion['target_url'] ?? '' );
					$anchor       = (string) ( $suggestion['anchor'] ?? '' );
					$reason       = (string) ( $suggestion['reason'] ?? '' );
					$incoming     = isset( $suggestion['target_incoming_sources'] ) ? (int) $suggestion['target_incoming_sources'] : 0;
					$search_key   = $this->mb_lower( $source_title . ' ' . $target_title . ' ' . $anchor . ' ' . $reason );

					printf( '<tr class="lfa-suggestion-row" data-search="%s">', esc_attr( $search_key ) );
					echo '<td>' . $this->health_title_cell( $source_title, $source_url ) . $this->render_suggestion_source_meta( $suggestion ) . '</td>';
					echo '<td>' . $this->render_suggestion_context( $suggestion ) . '</td>';
					echo '<td>';
					echo $this->health_title_cell( $target_title, $target_url );
					printf(
						'<div class="lfa-suggestion-metric">%s <strong>%s</strong></div>',
						esc_html__( 'Gelen link veren yazı:', 'linkflow-auditor' ),
						esc_html( number_format_i18n( $incoming ) )
					);
					echo '</td>';
					echo '<td>' . esc_html( $reason ) . '</td>';
					echo '<td>' . $this->render_suggestion_action( $suggestion ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table></div>';

				printf(
					'<p class="lfa-health-more">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: shown count. */
							__( 'Bu partide en fazla %s öneri gösterilir.', 'linkflow-auditor' ),
							number_format_i18n( self::SUGGESTION_BATCH_SIZE )
						)
					)
				);

				return (string) ob_get_clean();
			}

			private function render_suggestion_context( array $suggestion ): string {
				$before = (string) ( $suggestion['context_before'] ?? '' );
				$match  = (string) ( $suggestion['context_match'] ?? ( $suggestion['anchor'] ?? '' ) );
				$after  = (string) ( $suggestion['context_after'] ?? '' );

				if ( '' === trim( $match ) ) {
					return '&mdash;';
				}

				return sprintf(
					'<span class="lfa-suggestion-context">%s<mark>%s</mark>%s</span>',
					esc_html( $before ),
					esc_html( $match ),
					esc_html( $after )
				);
			}

			private function render_suggestion_source_meta( array $suggestion ): string {
				$outgoing = isset( $suggestion['source_outgoing_links'] ) ? (int) $suggestion['source_outgoing_links'] : 0;
				$date     = isset( $suggestion['source_published_at'] ) ? (int) $suggestion['source_published_at'] : 0;

				$parts = array(
					sprintf(
						'%s <strong>%s</strong>',
						esc_html__( 'Çıkan iç link:', 'linkflow-auditor' ),
						esc_html( number_format_i18n( $outgoing ) )
					),
				);

				if ( $date > 0 ) {
					$parts[] = sprintf(
						'%s <strong>%s</strong>',
						esc_html__( 'Yayın:', 'linkflow-auditor' ),
						esc_html( wp_date( get_option( 'date_format' ), $date ) )
					);
				}

				return '<div class="lfa-suggestion-source-meta">' . implode( ' <span aria-hidden="true">|</span> ', $parts ) . '</div>';
			}

			private function render_internal_scan_note( array $report, bool $has_internal, string $context ): void {
				if ( ! $has_internal ) {
					if ( 'manual' === $context ) {
						echo '<p class="lfa-scan-note lfa-scan-note--warning">' . esc_html__( 'En az iç link alanlar sıralamasını doğru kullanmak için önce İç Link Sayımı sekmesinden iç link taraması yapın.', 'linkflow-auditor' ) . '</p>';
					}
					return;
				}

				$timestamp = isset( $report['internal_created_at'] ) ? (int) $report['internal_created_at'] : 0;
				if ( $timestamp <= 0 ) {
					return;
				}

				$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );

				if ( 'manual' === $context ) {
					printf(
						'<p class="lfa-scan-note">%s</p>',
						esc_html(
							sprintf(
								/* translators: %s: scan date. */
								__( 'En az iç link alanlar sıralaması %s tarihli iç link taramasına göre yapılır.', 'linkflow-auditor' ),
								$date
							)
						)
					);
					return;
				}

				printf(
					'<p class="lfa-scan-note">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: scan date. */
							__( 'Bu öneri verisi %s tarihli iç link taramasına aittir.', 'linkflow-auditor' ),
							$date
						)
					)
				);
			}

			private function render_dismissed_suggestions_reset_box(): void {
				$count = count( $this->get_ignored_suggestion_ids() );

				echo '<div class="lfa-dismissed-reset">';
				printf(
					'<span>%s <strong class="lfa-dismissed-count">%s</strong></span>',
					esc_html__( 'Kaldırılmış öneri:', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $count ) )
				);
				printf(
					'<button type="button" class="button button-small lfa-reset-dismissed-suggestions"%s>%s</button>',
					disabled( $count <= 0, true, false ),
					esc_html__( 'Kaldırılan önerileri sıfırla', 'linkflow-auditor' )
				);
				echo '<span class="description">' . esc_html__( 'Sıfırladıktan sonra önerileri yenilerseniz kaldırılan öneriler tekrar listelenebilir.', 'linkflow-auditor' ) . '</span>';
				echo '</div>';
			}

			private function render_suggestion_rotation_reset_box( string $scope ): void {
				$scope = 'manual' === $scope ? 'manual' : 'normal';

				echo '<div class="lfa-dismissed-reset lfa-rotation-reset">';
				echo '<span>' . esc_html__( 'Öneri seçim kaydı', 'linkflow-auditor' ) . '</span>';
				printf(
					'<button type="button" class="button button-small lfa-clear-suggestion-rotation" data-scope="%s">%s</button>',
					esc_attr( $scope ),
					esc_html__( 'Seçim kaydını sil', 'linkflow-auditor' )
				);
				echo '<span class="description">' . esc_html__( 'Önerileri değiştirirken daha önce gösterilen partileri hatırlayan kayıt temizlenir.', 'linkflow-auditor' ) . '</span>';
				echo '</div>';
			}

			private function render_suggestion_action( array $suggestion ): string {
				$suggestion_id = (string) ( $suggestion['id'] ?? '' );

				if ( '' === $suggestion_id ) {
					return '&mdash;';
				}

				return sprintf(
					'<div class="lfa-actions"><button type="button" class="button button-small button-primary lfa-accept-suggestion" data-suggestion-id="%1$s">%2$s</button> <button type="button" class="button button-small lfa-dismiss-suggestion" data-suggestion-id="%1$s">%3$s</button></div>',
					esc_attr( $suggestion_id ),
					esc_html__( 'Kabul et', 'linkflow-auditor' ),
					esc_html__( 'Öneriyi kaldır', 'linkflow-auditor' )
				);
			}

			private function render_manual_suggestions_tab( array $report, bool $has_internal ): void {
				echo '<div class="lfa-manual-builder" data-lfa-manual-builder>';
				echo '<div class="lfa-manual-mode" role="radiogroup" aria-label="' . esc_attr__( 'Manuel öneri modu', 'linkflow-auditor' ) . '">';
				printf(
					'<label><input type="radio" name="lfa-manual-mode" value="phrase" checked> %s</label>',
					esc_html__( 'İfade + hedef URL', 'linkflow-auditor' )
				);
				printf(
					'<label><input type="radio" name="lfa-manual-mode" value="source_url"> %s</label>',
					esc_html__( 'Kaynak URL’den bul', 'linkflow-auditor' )
				);
				echo '</div>';
				echo '<div class="lfa-manual-mode-fields lfa-manual-mode-fields--phrase">';
				echo '<div class="lfa-manual-field">';
				echo '<label for="lfa-manual-anchor">' . esc_html__( 'Linklenecek ifade', 'linkflow-auditor' ) . '</label>';
				printf(
					'<input type="text" id="lfa-manual-anchor" class="regular-text lfa-manual-anchor" placeholder="%s">',
					esc_attr__( 'Örn: velayet hakkı', 'linkflow-auditor' )
				);
				echo '</div>';
				echo '<div class="lfa-manual-field">';
				echo '<label for="lfa-manual-target">' . esc_html__( 'Hedef URL', 'linkflow-auditor' ) . '</label>';
				printf(
					'<input type="text" id="lfa-manual-target" class="regular-text lfa-manual-target" placeholder="%s">',
					esc_attr__( 'https://site.com/hedef-sayfa/ veya ana sayfa', 'linkflow-auditor' )
				);
				echo '</div>';
				echo '</div>';
				echo '<div class="lfa-manual-mode-fields lfa-manual-mode-fields--source" hidden>';
				echo '<div class="lfa-manual-field">';
				echo '<label for="lfa-manual-source-url">' . esc_html__( 'Kaynak URL', 'linkflow-auditor' ) . '</label>';
				printf(
					'<input type="text" id="lfa-manual-source-url" class="regular-text lfa-manual-source-url" placeholder="%s">',
					esc_attr__( 'https://site.com/kaynak-yazi/', 'linkflow-auditor' )
				);
				echo '</div>';
				echo '</div>';
				echo '<div class="lfa-manual-field">';
				echo '<label for="lfa-manual-sort">' . esc_html__( 'Sıralama', 'linkflow-auditor' ) . '</label>';
				echo '<select id="lfa-manual-sort" class="lfa-manual-sort">';
				echo '<option value="least_links">' . esc_html__( 'En az iç link alanlar', 'linkflow-auditor' ) . '</option>';
				echo '<option value="oldest">' . esc_html__( 'En eski yazılar önce', 'linkflow-auditor' ) . '</option>';
				echo '<option value="newest">' . esc_html__( 'En yeni yazılar önce', 'linkflow-auditor' ) . '</option>';
				echo '</select>';
				echo '</div>';
				printf(
					'<button type="button" class="button button-primary lfa-manual-search">%s</button>',
					esc_html__( 'Ara', 'linkflow-auditor' )
				);
				echo '<span class="spinner lfa-spinner" aria-hidden="true"></span>';
				echo '</div>';
				$this->render_internal_scan_note( $report, $has_internal, 'manual' );
				$this->render_suggestion_rotation_reset_box( 'manual' );
				echo '<p class="description">' . esc_html__( 'Her partide en fazla 25 öneri listelenir. Sonuçlar yalnızca düz metinde, mevcut bir linkin dışında bulunan güvenli ifadelerden oluşturulur.', 'linkflow-auditor' ) . '</p>';
				echo '<div class="lfa-message" aria-live="polite"></div>';
				echo '<div class="lfa-manual-results" aria-live="polite"></div>';
			}

			private function open_health_section( string $severity, string $icon, string $title, int $count, string $desc ): void {
				printf( '<section class="lfa-health-card lfa-health-card--%s">', esc_attr( $severity ) );
				echo '<details class="lfa-health-details">';
				printf(
					'<summary class="lfa-health-head"><span class="lfa-health-icon" aria-hidden="true">%s</span><h3>%s</h3><span class="lfa-health-badge">%s</span><span class="lfa-health-caret" aria-hidden="true">▾</span></summary>',
					$icon,
					esc_html( $title ),
					esc_html( number_format_i18n( $count ) )
				);
				printf( '<p class="lfa-health-desc">%s</p>', esc_html( $desc ) );
			}

			private function close_health_section(): void {
				echo '</details></section>';
			}

			private function render_health_ok(): void {
				echo '<p class="lfa-health-clean">✓ ' . esc_html__( 'Bu kontrolde sorun bulunamadı.', 'linkflow-auditor' ) . '</p>';
			}

			private function render_health_item_table( array $items ): void {
				if ( empty( $items ) ) {
					$this->render_health_ok();
					return;
				}

				$shown = array_slice( $items, 0, self::HEALTH_DISPLAY_CAP );

				echo '<div class="lfa-table-wrap"><table class="widefat striped lfa-health-table"><thead><tr>';
				echo '<th>' . esc_html__( 'İçerik', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Tür', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $shown as $item ) {
					echo '<tr>';
					echo '<td>' . $this->health_title_cell( (string) ( $item['title'] ?? '' ), (string) ( $item['url'] ?? '' ) ) . '</td>';
					echo '<td><span class="lfa-type-badge">' . esc_html( $this->get_post_type_label( (string) ( $item['type'] ?? '' ) ) ) . '</span></td>';
					echo '<td>' . $this->health_edit_cell( (string) ( $item['edit_url'] ?? '' ) ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table></div>';
				$this->render_health_more_note( count( $items ) );
			}

			private function render_health_duplicate_group( array $group ): void {
				$url   = (string) ( $group['url'] ?? '' );
				$items = array_values( array_filter( (array) ( $group['items'] ?? array() ), 'is_array' ) );

				echo '<div class="lfa-dupe-group">';
				printf(
					'<div class="lfa-dupe-url">%s %s</div>',
					esc_html__( 'Adres:', 'linkflow-auditor' ),
					$this->render_url_cell( $url )
				);
				echo '<table class="widefat striped lfa-health-table"><thead><tr>';
				echo '<th>' . esc_html__( 'İçerik', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Tür', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $items as $item ) {
					$title = (string) ( $item['title'] ?? '' );
					if ( '' === trim( $title ) ) {
						$title = __( '(Başlıksız)', 'linkflow-auditor' );
					}

					echo '<tr>';
					echo '<td><strong>' . esc_html( $title ) . '</strong></td>';
					echo '<td><span class="lfa-type-badge">' . esc_html( $this->get_post_type_label( (string) ( $item['type'] ?? '' ) ) ) . '</span></td>';
					echo '<td>' . $this->health_edit_cell( (string) ( $item['edit_url'] ?? '' ) ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table></div>';
			}

			private function render_health_insecure_table( array $links, int $total ): void {
				if ( empty( $links ) ) {
					$this->render_health_ok();
					return;
				}

				echo '<div class="lfa-table-wrap"><table class="widefat striped lfa-health-table"><thead><tr>';
				echo '<th>' . esc_html__( 'Kaynak içerik', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'http:// linki', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Anchor text', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $links as $link ) {
					echo '<tr>';
					echo '<td>' . $this->health_title_cell( (string) ( $link['source_title'] ?? '' ), (string) ( $link['source_url'] ?? '' ) ) . '</td>';
					echo '<td>' . $this->render_url_cell( (string) ( $link['href'] ?? '' ) ) . '</td>';
					echo '<td>' . $this->health_anchor_cell( (string) ( $link['anchor'] ?? '' ) ) . '</td>';
					echo '<td>' . $this->health_edit_cell_for_post( (int) ( $link['source_id'] ?? 0 ) ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table></div>';
				$this->render_health_more_note( $total );
			}

			private function render_health_weak_table( array $links, int $total ): void {
				if ( empty( $links ) ) {
					$this->render_health_ok();
					return;
				}

				echo '<div class="lfa-table-wrap"><table class="widefat striped lfa-health-table"><thead><tr>';
				echo '<th>' . esc_html__( 'Kaynak içerik', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Bağlantı metni', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Hedef', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $links as $link ) {
					echo '<tr>';
					echo '<td>' . $this->health_title_cell( (string) ( $link['source_title'] ?? '' ), (string) ( $link['source_url'] ?? '' ) ) . '</td>';
					echo '<td>' . $this->health_anchor_cell( (string) ( $link['anchor'] ?? '' ) ) . '</td>';
					echo '<td>' . $this->health_title_cell( (string) ( $link['target_title'] ?? '' ), (string) ( $link['target_url'] ?? '' ) ) . '</td>';
					echo '<td>' . $this->health_edit_cell_for_post( (int) ( $link['source_id'] ?? 0 ) ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table></div>';
				$this->render_health_more_note( $total );
			}

			private function render_health_more_note( int $total ): void {
				if ( $total <= self::HEALTH_DISPLAY_CAP ) {
					return;
				}

				printf(
					'<p class="lfa-health-more">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: remaining item count. */
							__( '… ve %s tane daha (ilk 100 gösteriliyor).', 'linkflow-auditor' ),
							number_format_i18n( $total - self::HEALTH_DISPLAY_CAP )
						)
					)
				);
			}

			private function health_title_cell( string $title, string $url ): string {
				if ( '' === trim( $title ) ) {
					$title = __( '(Başlıksız)', 'linkflow-auditor' );
				}

				if ( '' === $url ) {
					return '<strong>' . esc_html( $title ) . '</strong>';
				}

				return sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer"><strong>%s</strong></a><div class="lfa-url">%s</div>',
					esc_url( $url ),
					esc_html( $title ),
					esc_html( $url )
				);
			}

			private function health_anchor_cell( string $anchor ): string {
				if ( '' === trim( $anchor ) ) {
					return '<em class="lfa-anchor-empty">' . esc_html__( '(boş / görsel link)', 'linkflow-auditor' ) . '</em>';
				}

				return '<span class="lfa-anchor-chip">' . esc_html( $anchor ) . '</span>';
			}

			private function health_edit_cell( string $edit_url ): string {
				if ( '' === $edit_url ) {
					return '&mdash;';
				}

				return sprintf( '<a class="lfa-edit-link" href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Düzenle', 'linkflow-auditor' ) );
			}

			private function health_edit_cell_for_post( int $post_id ): string {
				if ( $post_id <= 0 ) {
					return '&mdash;';
				}

				$edit_url = get_edit_post_link( $post_id, '' );

				return $this->health_edit_cell( (string) ( $edit_url ?: '' ) );
			}

			private function render_external_links_tab( array $report, bool $has_internal ): void {
				echo '<div class="lfa-controls">';
				printf(
					'<button type="button" class="button button-primary lfa-start" data-scan-mode="%s" data-result-tab="external">%s</button>',
					esc_attr( self::SCAN_MODE_INTERNAL ),
					esc_html__( 'Dış linkleri tara', 'linkflow-auditor' )
				);
				echo '<span class="spinner lfa-spinner" aria-hidden="true"></span>';
				echo '</div>';
				echo '<div class="lfa-progress" hidden><div class="lfa-progress-bar"><span></span></div><strong>0%</strong></div>';
				echo '<div class="lfa-message" aria-live="polite"></div>';
				echo '<p class="description">' . esc_html__( 'Dış link listesi iç link taramasıyla birlikte üretilir. Sayfalarınızda ve yazılarınızda başka sitelere verilen linkleri gösterir.', 'linkflow-auditor' ) . '</p>';

				$links = array_values( array_filter( (array) ( $report['external_links'] ?? array() ), 'is_array' ) );
				$total = isset( $report['external_total'] ) ? (int) $report['external_total'] : count( $links );

				if ( ! $has_internal ) {
					echo '<p class="lfa-empty">' . esc_html__( 'Dış link listesi için önce taramayı çalıştırın.', 'linkflow-auditor' ) . '</p>';
					return;
				}

				if ( empty( $links ) ) {
					echo '<p class="lfa-empty">' . esc_html__( 'Dış link bulunamadı.', 'linkflow-auditor' ) . '</p>';
					return;
				}

				printf(
					'<div class="lfa-external-toolbar"><input type="search" class="lfa-external-search regular-text" placeholder="%s"><span class="lfa-external-summary">%s <strong>%s</strong></span></div>',
					esc_attr__( 'URL veya bağlantı metninde ara…', 'linkflow-auditor' ),
					esc_html__( 'Toplam dış link:', 'linkflow-auditor' ),
					esc_html( number_format_i18n( $total ) )
				);

				$display_cap = self::HEALTH_DISPLAY_CAP * 5;
				$shown       = array_slice( $links, 0, $display_cap );

				echo '<div class="lfa-table-wrap"><table class="widefat striped lfa-status-table lfa-external-table"><thead><tr>';
				echo '<th>' . esc_html__( 'Kaynak sayfa/yazı', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Bağlantı metni', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'Dış URL', 'linkflow-auditor' ) . '</th>';
				echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $shown as $link ) {
					$source_id    = isset( $link['source_id'] ) ? (int) $link['source_id'] : 0;
					$source_title = (string) ( $link['source_title'] ?? '' );
					$source_url   = (string) ( $link['source_url'] ?? '' );
					$href         = (string) ( $link['href'] ?? '' );
					$anchor       = (string) ( $link['anchor'] ?? '' );
					$search_key   = $this->mb_lower( $anchor . ' ' . $href . ' ' . $source_title );

					printf( '<tr class="lfa-external-row" data-search="%s">', esc_attr( $search_key ) );
					echo '<td>' . $this->health_title_cell( $source_title, $source_url ) . '</td>';
					echo '<td>' . $this->health_anchor_cell( $anchor ) . '</td>';
					echo '<td>' . $this->render_url_cell( $href ) . '</td>';
					echo '<td>' . $this->render_link_actions( self::SCAN_MODE_EXTERNAL, $source_id, $href, '' ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table></div>';

				if ( count( $links ) > $display_cap ) {
					printf(
						'<p class="lfa-health-more">%s</p>',
						esc_html(
							sprintf(
								/* translators: 1: shown count, 2: remaining count. */
								__( 'İlk %1$s dış link gösteriliyor; %2$s tane daha var.', 'linkflow-auditor' ),
								number_format_i18n( $display_cap ),
								number_format_i18n( count( $links ) - $display_cap )
							)
						)
					);
				}
			}

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

			private function get_issue_status_class( string $status ): string {
				$status = 'warning' === $status ? 'warning' : 'broken';

				return 'lfa-status lfa-status--' . $status;
			}

			private function get_issue_status_label( string $status, int $code ): string {
				$suffix = $code > 0 ? ' ' . $code : '';

				if ( 'warning' === $status ) {
					return __( 'Kontrol gerekli', 'linkflow-auditor' ) . $suffix;
				}

				return __( 'Kırık', 'linkflow-auditor' ) . $suffix;
			}

			private function get_last_checked_label( int $timestamp ): string {
				if ( $timestamp <= 0 ) {
					return __( 'Henüz kontrol edilmedi', 'linkflow-auditor' );
				}

				return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
			}

		public function render_manual_suggestions_results( array $suggestions, string $target_url, string $mode = 'phrase', string $source_url = '', bool $has_more = false, string $empty_message = '', bool $enable_bulk = false ): string {
			if ( empty( $suggestions ) ) {
				$message = '' !== $empty_message ? $empty_message : esc_html__( 'Uygun düz metin eşleşmesi bulunamadı.', 'linkflow-auditor' );
				return '<p class="lfa-empty">' . esc_html( $message ) . '</p>';
			}

			$is_source_mode    = 'source_url' === $mode;
			$source_summary_url = $is_source_mode ? (string) ( $suggestions[0]['source_url'] ?? $source_url ) : $source_url;
			$current_ids       = array();
			foreach ( $suggestions as $suggestion ) {
				$id = sanitize_key( (string) ( $suggestion['id'] ?? '' ) );
				if ( '' !== $id ) {
					$current_ids[] = $id;
				}
			}

			ob_start();
			printf(
				'<input type="hidden" class="lfa-current-suggestion-ids" value="%s">',
				esc_attr( implode( ',', $current_ids ) )
			);
			echo '<div class="lfa-manual-result-head">';
			if ( $is_source_mode ) {
				printf(
					'<div class="lfa-manual-result-summary">%s <strong>%s</strong></div>',
					esc_html__( 'Kaynak:', 'linkflow-auditor' ),
					$this->render_url_cell( $source_summary_url )
				);
			} else {
				printf(
					'<div class="lfa-manual-result-summary">%s <strong>%s</strong></div>',
					esc_html__( 'Hedef:', 'linkflow-auditor' ),
					$this->render_url_cell( $target_url )
				);
			}
			printf(
				'<button type="button" class="button lfa-manual-change-suggestions"%s>%s</button>',
				disabled( ! $has_more, true, false ),
				esc_html__( 'Önerileri değiştir', 'linkflow-auditor' )
			);
			if ( $enable_bulk ) {
				printf(
					'<button type="button" class="button button-primary button-hero lfa-manual-apply-selected">%s (<span class="lfa-selected-count">0</span>)</button>',
					esc_html__( 'Seçilenleri uygula', 'linkflow-auditor' )
				);
			}
			echo '</div>';
			echo '<div class="lfa-table-wrap"><table class="widefat striped lfa-status-table lfa-suggestion-table"><thead><tr>';
			if ( $enable_bulk ) {
				printf(
					'<th class="lfa-select-col"><input type="checkbox" class="lfa-suggestion-select-all" title="%s"></th>',
					esc_attr__( 'Tümünü seç', 'linkflow-auditor' )
				);
			}
			echo '<th>' . esc_html__( 'Kaynak içerik', 'linkflow-auditor' ) . '</th>';
			echo '<th>' . esc_html__( 'Linklenecek ifade', 'linkflow-auditor' ) . '</th>';
			if ( $is_source_mode ) {
				echo '<th>' . esc_html__( 'Link verilecek URL', 'linkflow-auditor' ) . '</th>';
			}
			echo '<th>' . esc_html__( 'İşlem', 'linkflow-auditor' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $suggestions as $suggestion ) {
				echo '<tr class="lfa-manual-suggestion-row">';
				if ( $enable_bulk ) {
					echo '<td class="lfa-select-col">' . $this->render_suggestion_select( $suggestion, $target_url ) . '</td>';
				}
				echo '<td>' . $this->health_title_cell( (string) ( $suggestion['source_title'] ?? '' ), (string) ( $suggestion['source_url'] ?? '' ) ) . $this->render_suggestion_source_meta( $suggestion ) . '</td>';
				echo '<td>' . $this->render_suggestion_context( $suggestion ) . '</td>';
				if ( $is_source_mode ) {
					echo '<td>' . $this->health_title_cell( (string) ( $suggestion['target_title'] ?? '' ), (string) ( $suggestion['target_url'] ?? '' ) ) . '</td>';
				}
				echo '<td>' . $this->render_manual_suggestion_action( $suggestion, $target_url ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></div>';

			printf(
				'<p class="lfa-health-more">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: shown count. */
						__( 'Bu partide en fazla %s öneri gösterilir.', 'linkflow-auditor' ),
						number_format_i18n( self::SUGGESTION_BATCH_SIZE )
					)
				)
			);

			return (string) ob_get_clean();
		}

		private function render_manual_suggestion_action( array $suggestion, string $target_url ): string {
			$source_id = isset( $suggestion['source_id'] ) ? (int) $suggestion['source_id'] : 0;
			$anchor    = (string) ( $suggestion['anchor'] ?? '' );
			$target    = '' !== $target_url ? $target_url : (string) ( $suggestion['target_url'] ?? '' );

			if ( $source_id <= 0 || '' === trim( $anchor ) || '' === trim( $target ) ) {
				return '&mdash;';
			}

			return sprintf(
				'<button type="button" class="button button-small button-primary lfa-accept-manual-suggestion" data-source-id="%d" data-anchor="%s" data-target-url="%s">%s</button>',
				$source_id,
				esc_attr( $anchor ),
				esc_attr( $target ),
				esc_html__( 'Kabul et', 'linkflow-auditor' )
			);
		}

		/**
		 * Render a bulk-select checkbox carrying the accept payload for a suggestion.
		 *
		 * @param array<string,mixed> $suggestion Suggestion row.
		 * @param string              $target_url Target URL override (empty for source-URL mode).
		 */
		private function render_suggestion_select( array $suggestion, string $target_url ): string {
			$source_id = isset( $suggestion['source_id'] ) ? (int) $suggestion['source_id'] : 0;
			$anchor    = (string) ( $suggestion['anchor'] ?? '' );
			$target    = '' !== $target_url ? $target_url : (string) ( $suggestion['target_url'] ?? '' );

			if ( $source_id <= 0 || '' === trim( $anchor ) || '' === trim( $target ) ) {
				return '';
			}

			return sprintf(
				'<input type="checkbox" class="lfa-suggestion-select" data-source-id="%d" data-anchor="%s" data-target-url="%s" aria-label="%s">',
				$source_id,
				esc_attr( $anchor ),
				esc_attr( $target ),
				esc_attr__( 'Bu öneriyi seç', 'linkflow-auditor' )
			);
		}

		private function get_health_issue_count( array $report ): int {
			$health = (array) ( $report['health'] ?? array() );

			if ( empty( $health ) ) {
				return 0;
			}

			return count( (array) ( $health['duplicates'] ?? array() ) )
				+ count( (array) ( $health['orphans'] ?? array() ) )
				+ count( (array) ( $health['dead_ends'] ?? array() ) )
				+ (int) ( $health['insecure_total'] ?? 0 )
				+ (int) ( $health['weak_total'] ?? 0 );
		}

		/**
		 * Count every row shown in the broken-link table, including 401/403 warnings.
		 *
		 * @param array<string,mixed>            $report Saved report.
		 * @param array<int,array<string,mixed>> $broken_links Optional prepared rows.
		 */
		private function get_broken_issue_count( array $report, array $broken_links = array() ): int {
			if ( isset( $report['broken_link_count'] ) || isset( $report['warning_link_count'] ) ) {
				return max( 0, (int) ( $report['broken_link_count'] ?? 0 ) )
					+ max( 0, (int) ( $report['warning_link_count'] ?? 0 ) );
			}

			if ( empty( $broken_links ) ) {
				$broken_links = array_values( array_filter( (array) ( $report['broken_links'] ?? array() ), 'is_array' ) );
			}

			return count( $broken_links );
		}

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
	}
}
