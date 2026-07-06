<?php
/**
 * Post/page editor metabox for on-the-fly internal link suggestions.
 *
 * Adds a Yoast-style panel to the post and page editor that scans the current
 * content for internal-link opportunities, shows up to 25 suggestions with a
 * chosen priority (least linked, oldest or newest targets) and links the
 * accepted suggestion directly into the content being edited.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Editor_Metabox' ) ) {
	/**
	 * Registers and serves the editor internal-link suggestion metabox.
	 */
	final class LinkFlow_Auditor_Editor_Metabox {
		private const VERSION              = '1.11.6';
		private const NONCE_ACTION         = 'linkflow_auditor_editor';
		private const METABOX_ID           = 'linkflow-auditor-editor';
		private const SUGGESTION_BATCH_SIZE = 25;

		/**
		 * Manual internal-link suggestion engine.
		 *
		 * @var LinkFlow_Auditor_Manual_Suggestion_Engine
		 */
		private $manual_engine;

		/**
		 * Admin page renderer (reused for suggestion result markup).
		 *
		 * @var LinkFlow_Auditor_Admin_Page
		 */
		private $admin_page;

		/**
		 * Safe content link editor.
		 *
		 * @var LinkFlow_Auditor_Link_Editor
		 */
		private $link_editor;

		/**
		 * URL and multibyte string normalizer.
		 *
		 * @var LinkFlow_Auditor_Url_Normalizer
		 */
		private $url_normalizer;

		/**
		 * Constructor.
		 *
		 * @param LinkFlow_Auditor_Manual_Suggestion_Engine $manual_engine Manual suggestion engine.
		 * @param LinkFlow_Auditor_Admin_Page               $admin_page    Admin page renderer.
		 * @param LinkFlow_Auditor_Link_Editor              $link_editor   Link editor.
		 * @param LinkFlow_Auditor_Url_Normalizer           $url_normalizer URL/text normalizer.
		 */
		public function __construct( LinkFlow_Auditor_Manual_Suggestion_Engine $manual_engine, LinkFlow_Auditor_Admin_Page $admin_page, LinkFlow_Auditor_Link_Editor $link_editor, LinkFlow_Auditor_Url_Normalizer $url_normalizer ) {
			$this->manual_engine  = $manual_engine;
			$this->admin_page     = $admin_page;
			$this->link_editor    = $link_editor;
			$this->url_normalizer = $url_normalizer;

			add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'wp_ajax_linkflow_auditor_editor_suggestions', array( $this, 'ajax_suggestions' ) );
			add_action( 'wp_ajax_linkflow_auditor_editor_accept', array( $this, 'ajax_accept' ) );
		}

		/**
		 * Return the editable post types the plugin works with.
		 *
		 * @return string[]
		 */
		private function get_editable_post_types(): array {
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

			return $post_types;
		}

		/**
		 * Register the metabox on every editable post type.
		 *
		 * @param string $post_type Current edit screen post type.
		 */
		public function register_meta_box( string $post_type ): void {
			if ( ! in_array( $post_type, $this->get_editable_post_types(), true ) ) {
				return;
			}

			add_meta_box(
				self::METABOX_ID,
				esc_html__( 'LinkFlow Auditor — İç Link Önerileri', 'linkflow-auditor' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default'
			);
		}

		/**
		 * Render the metabox controls.
		 *
		 * @param WP_Post $post Current post.
		 */
		public function render_meta_box( WP_Post $post ): void {
			$is_saved = ! in_array( (string) $post->post_status, array( 'auto-draft', 'trash' ), true );
			$can_edit = current_user_can( 'edit_post', (int) $post->ID );

			echo '<div class="lfa-editor-box" data-lfa-editor-box data-post-id="' . esc_attr( (string) (int) $post->ID ) . '">';
			echo '<p class="lfa-editor-intro">' . esc_html__( 'Bu içerikten diğer yayınlarınıza verilebilecek iç link fırsatlarını tarar. Kabul ettiğinizde bağlantı doğrudan bu içeriğe eklenir.', 'linkflow-auditor' ) . '</p>';

			if ( ! $can_edit ) {
				echo '<p class="lfa-editor-note lfa-editor-note--warning">' . esc_html__( 'Bu içeriği düzenleme yetkiniz yok.', 'linkflow-auditor' ) . '</p>';
				echo '</div>';
				return;
			}

			echo '<div class="lfa-editor-controls">';
			echo '<label class="lfa-editor-sort-label" for="lfa-editor-sort-' . esc_attr( (string) (int) $post->ID ) . '">' . esc_html__( 'Öncelik', 'linkflow-auditor' ) . '</label>';
			echo '<select class="lfa-editor-sort" id="lfa-editor-sort-' . esc_attr( (string) (int) $post->ID ) . '">';
			echo '<option value="least_links">' . esc_html__( 'En az iç link alan hedefler', 'linkflow-auditor' ) . '</option>';
			echo '<option value="oldest">' . esc_html__( 'En eski yazı/sayfalar önce', 'linkflow-auditor' ) . '</option>';
			echo '<option value="newest">' . esc_html__( 'En yeni yazı/sayfalar önce', 'linkflow-auditor' ) . '</option>';
			echo '</select>';
			printf(
				'<button type="button" class="button button-primary lfa-editor-scan"%s>%s</button>',
				disabled( ! $is_saved, true, false ),
				esc_html__( 'Tara ve öneri getir', 'linkflow-auditor' )
			);
			echo '<span class="spinner lfa-editor-spinner" aria-hidden="true"></span>';
			echo '</div>';

			echo '<div class="lfa-editor-field">';
			echo '<label class="lfa-editor-exclude-label" for="lfa-editor-exclude-' . esc_attr( (string) (int) $post->ID ) . '">' . esc_html__( 'Negatif kelimeler', 'linkflow-auditor' ) . '</label>';
			printf(
				'<input type="text" class="regular-text lfa-editor-exclude" id="lfa-editor-exclude-%s" placeholder="%s">',
				esc_attr( (string) (int) $post->ID ),
				esc_attr__( 'örn: hukuk, dava, ceza', 'linkflow-auditor' )
			);
			echo '<span class="lfa-editor-field-hint">' . esc_html__( 'Virgül veya boşlukla ayırın. Bu kelimeler bu taramada linkleme ifadesi (anchor) olarak kullanılmaz.', 'linkflow-auditor' ) . '</span>';
			echo '</div>';

			if ( ! $is_saved ) {
				echo '<p class="lfa-editor-note lfa-editor-note--warning">' . esc_html__( 'Tarama için önce içeriği kaydedin (taslak da olur).', 'linkflow-auditor' ) . '</p>';
			}

			echo '<p class="lfa-editor-note">' . esc_html__( 'Yayınlanmış ve taslak içerikte çalışır. Tarama son kaydedilen içeriği kullanır; bu yüzden değişikliklerinizi kaydettikten sonra tarayın. Her taramada en fazla 25 öneri listelenir. Öneriler tek tek "Kabul et" ile veya soldaki kutulardan birden fazlasını seçip "Seçilenleri uygula" ile toplu uygulanabilir; link(ler) doğrudan içeriğe kaydedilir ve işlem sonunda düzenleyici bir kez yenilenir. Bu yüzden kabul etmeden önce içeriğinizi kaydedin.', 'linkflow-auditor' ) . '</p>';
			echo '<div class="lfa-editor-message" aria-live="polite"></div>';
			echo '<div class="lfa-editor-results" aria-live="polite"></div>';
			echo '</div>';
		}

		/**
		 * Load metabox assets on the post editor screens.
		 *
		 * @param string $hook Current admin screen hook.
		 */
		public function enqueue_assets( string $hook ): void {
			if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && ! in_array( (string) $screen->post_type, $this->get_editable_post_types(), true ) ) {
				return;
			}

			// Reuse the report table/suggestion styling from the admin stylesheet.
			wp_enqueue_style(
				'lfa-admin',
				plugins_url( 'assets/admin.css', LINKFLOW_AUDITOR_FILE ),
				array(),
				$this->asset_version( 'assets/admin.css' )
			);

			wp_enqueue_style(
				'lfa-editor',
				plugins_url( 'assets/editor.css', LINKFLOW_AUDITOR_FILE ),
				array( 'lfa-admin' ),
				$this->asset_version( 'assets/editor.css' )
			);

			wp_enqueue_script(
				'lfa-editor',
				plugins_url( 'assets/editor.js', LINKFLOW_AUDITOR_FILE ),
				array( 'jquery' ),
				$this->asset_version( 'assets/editor.js' ),
				true
			);

			wp_localize_script(
				'lfa-editor',
				'LinkFlowAuditorEditor',
				array(
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
					'messages' => array(
						'scanning'      => esc_html__( 'Öneriler taranıyor...', 'linkflow-auditor' ),
						'changing'      => esc_html__( 'Farklı öneriler hazırlanıyor...', 'linkflow-auditor' ),
						'accepting'     => esc_html__( 'Öneri uygulanıyor...', 'linkflow-auditor' ),
						'accepted'      => esc_html__( 'Link kaydedildi. Düzenleyici yenileniyor...', 'linkflow-auditor' ),
						'applying'      => esc_html__( 'Seçilen öneriler uygulanıyor...', 'linkflow-auditor' ),
						'appliedBulk'   => esc_html__( 'Seçilen öneriler uygulandı. Düzenleyici yenileniyor...', 'linkflow-auditor' ),
						'noSelection'   => esc_html__( 'Lütfen önce en az bir öneri seçin.', 'linkflow-auditor' ),
						'error'         => esc_html__( 'İşlem tamamlanamadı. Lütfen tekrar deneyin.', 'linkflow-auditor' ),
						'confirmAccept' => esc_html__( 'Bu öneri kabul edilsin ve bu içerikteki ifade hedef sayfaya linklensin mi? Bağlantı doğrudan içeriğe kaydedilir ve düzenleyici yenilenir. Kaydedilmemiş değişiklikleriniz varsa önce kaydedin.', 'linkflow-auditor' ),
						'confirmApplySelected' => esc_html__( 'Seçilen öneriler uygulansın ve linkler bu içeriğe eklensin mi? Linkler doğrudan içeriğe kaydedilir ve işlem sonunda düzenleyici bir kez yenilenir. Kaydedilmemiş değişiklikleriniz varsa önce kaydedin.', 'linkflow-auditor' ),
					),
				)
			);
		}

		/**
		 * Build an asset cache-busting version from the file modification time.
		 *
		 * Using filemtime() means any change to the JS/CSS files busts the browser
		 * and CDN cache even when the plugin version constant is unchanged.
		 *
		 * @param string $relative Path relative to the plugin root.
		 */
		private function asset_version( string $relative ): string {
			$path  = LINKFLOW_AUDITOR_PATH . $relative;
			$mtime = file_exists( $path ) ? (int) filemtime( $path ) : 0;

			return $mtime > 0 ? (string) $mtime : self::VERSION;
		}

		/**
		 * Return up to 25 internal-link suggestions for the edited post.
		 */
		public function ajax_suggestions(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

			if ( $post_id <= 0 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'İçerik bilgisi eksik.', 'linkflow-auditor' ) ), 400 );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Bu içeriği düzenleme yetkiniz yok.', 'linkflow-auditor' ) ), 403 );
			}

			$sort = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : 'least_links';
			if ( ! in_array( $sort, array( 'least_links', 'oldest', 'newest' ), true ) ) {
				$sort = 'least_links';
			}

			$excluded = array();
			foreach ( $this->posted_suggestion_ids( 'current_ids' ) as $id ) {
				$excluded[ $id ] = true;
			}

			$negative_words = $this->posted_negative_words();

			// Feed the negative words into the shared stop-word list for this request
			// so single-word and two-word keyword anchors skip them during building.
			$stopword_filter = null;
			if ( ! empty( $negative_words ) ) {
				$stopword_filter = static function ( array $words ) use ( $negative_words ): array {
					return array_merge( $words, array_values( $negative_words ) );
				};
				add_filter( 'linkflow_auditor_suggestion_stopwords', $stopword_filter );
			}

			// When filtering, pull a larger pool so 25 rows can still be filled after
			// dropping anchors that contain a negative word.
			$fetch_limit = empty( $negative_words ) ? self::SUGGESTION_BATCH_SIZE + 1 : self::SUGGESTION_BATCH_SIZE * 4;
			$result      = $this->manual_engine->build_link_suggestions_for_post( $post_id, $sort, $excluded, $fetch_limit );

			if ( $stopword_filter ) {
				remove_filter( 'linkflow_auditor_suggestion_stopwords', $stopword_filter );
			}

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			// Post-filter: drop any suggestion whose anchor still contains a negative
			// word (e.g. a full title phrase that includes it).
			if ( ! empty( $negative_words ) ) {
				$result = array_values(
					array_filter(
						$result,
						function ( $suggestion ) use ( $negative_words ): bool {
							return is_array( $suggestion ) && ! $this->anchor_has_negative_word( (string) ( $suggestion['anchor'] ?? '' ), $negative_words );
						}
					)
				);
			}

			$has_more    = count( $result ) > self::SUGGESTION_BATCH_SIZE;
			$suggestions = array_slice( $result, 0, self::SUGGESTION_BATCH_SIZE );
			$source_url  = (string) get_permalink( $post_id );

			$empty_message = ! empty( $excluded )
				? esc_html__( 'Gösterilecek farklı öneri kalmadı.', 'linkflow-auditor' )
				: esc_html__( 'Bu içerik için uygulanabilir iç link önerisi bulunamadı.', 'linkflow-auditor' );

			$html = $this->admin_page->render_manual_suggestions_results( $suggestions, '', 'source_url', $source_url, $has_more, $empty_message, true );

			wp_send_json_success(
				array(
					'message' => empty( $suggestions ) ? $empty_message : '',
					'count'   => count( $suggestions ),
					'html'    => $html,
				)
			);
		}

		/**
		 * Accept one editor suggestion and link the phrase directly into the post.
		 */
		public function ajax_accept(): void {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
			$anchor  = isset( $_POST['anchor'] ) ? trim( (string) wp_unslash( $_POST['anchor'] ) ) : '';
			$target  = isset( $_POST['target_url'] ) ? trim( (string) wp_unslash( $_POST['target_url'] ) ) : '';

			if ( $post_id <= 0 || '' === $anchor ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Öneri bilgisi eksik.', 'linkflow-auditor' ) ), 400 );
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Bu içeriği düzenleme yetkiniz yok.', 'linkflow-auditor' ) ), 403 );
			}

			$target_url = $this->manual_engine->normalize_manual_target_url( $target );
			if ( is_wp_error( $target_url ) ) {
				wp_send_json_error( array( 'message' => $target_url->get_error_message() ), 400 );
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || in_array( (string) $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Kaynak içerik bulunamadı. Önce içeriği kaydedin.', 'linkflow-auditor' ) ), 404 );
			}

			if ( $this->manual_engine->source_already_links_to_url( $post_id, (string) $target_url ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Bu içerik hedef URL’ye zaten link veriyor.', 'linkflow-auditor' ) ), 400 );
			}

			$changed = $this->link_editor->insert_internal_link_for_phrase( $post, $anchor, (string) $target_url );

			if ( is_wp_error( $changed ) ) {
				wp_send_json_error( array( 'message' => $changed->get_error_message() ), 400 );
			}

			if ( $changed < 1 ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Linklenecek ifade içerikte bulunamadı. Listeyi yenileyin.', 'linkflow-auditor' ) ), 404 );
			}

			wp_send_json_success(
				array(
					'message' => esc_html__( 'Öneri uygulandı. Link içeriğe kaydedildi; düzenleyici yenileniyor.', 'linkflow-auditor' ),
				)
			);
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
		 * Read and normalize the negative (excluded) words posted from the metabox.
		 *
		 * @return array<string,string> Map keyed by normalized word.
		 */
		private function posted_negative_words(): array {
			$raw = isset( $_POST['exclude_words'] ) ? (string) wp_unslash( $_POST['exclude_words'] ) : '';
			$raw = sanitize_text_field( $raw );

			if ( '' === trim( $raw ) ) {
				return array();
			}

			$parts = preg_split( '/[,\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
			$words = array();

			foreach ( (array) $parts as $part ) {
				$word = $this->url_normalizer->mb_lower( trim( (string) $part ) );

				if ( '' !== $word && $this->url_normalizer->mb_strlen( $word ) >= 2 ) {
					$words[ $word ] = $word;
				}
			}

			return $words;
		}

		/**
		 * Whether an anchor contains any negative word as a whole word.
		 *
		 * @param string                $anchor         Anchor text.
		 * @param array<string,string>  $negative_words Normalized negative words.
		 */
		private function anchor_has_negative_word( string $anchor, array $negative_words ): bool {
			if ( '' === trim( $anchor ) || empty( $negative_words ) ) {
				return false;
			}

			$lower = $this->url_normalizer->mb_lower( $anchor );
			$words = preg_split( '/[^\p{L}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );

			foreach ( (array) $words as $word ) {
				if ( isset( $negative_words[ (string) $word ] ) ) {
					return true;
				}
			}

			return false;
		}
	}
}
