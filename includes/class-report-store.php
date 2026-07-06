<?php
/**
 * Persistent option storage for LinkFlow Auditor.
 *
 * @package LinkFlow_Auditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'LinkFlow_Auditor_Report_Store' ) ) {
	/**
	 * Reads and writes plugin reports, settings and scan state.
	 */
	final class LinkFlow_Auditor_Report_Store {
		public const REPORT_OPTION              = 'linkflow_auditor_report';
		public const SETTINGS_OPTION            = 'linkflow_auditor_settings';
		public const IGNORED_SUGGESTIONS_OPTION = 'linkflow_auditor_ignored_suggestions';
		public const SUGGESTION_ROTATION_OPTION = 'linkflow_auditor_suggestion_rotation';
		public const CHECK_EXTERNAL_OPTION      = 'linkflow_auditor_check_external_links';
		public const STATE_PREFIX               = 'linkflow_auditor_scan_';

		private const LEGACY_REPORT_OPTION         = 'maya_ils_report';
		private const LEGACY_SETTINGS_OPTION       = 'maya_ils_settings';
		private const LEGACY_CHECK_EXTERNAL_OPTION = 'maya_ils_check_external_links';
		private const LEGACY_STATE_PREFIX          = 'maya_ils_scan_';

		private const DEFAULT_INTERVAL = 2;
		private const MIN_INTERVAL     = 1;
		private const MAX_INTERVAL     = 168;

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

			if ( false === get_option( self::IGNORED_SUGGESTIONS_OPTION, false ) ) {
				add_option( self::IGNORED_SUGGESTIONS_OPTION, array(), '', false );
			}

			if ( false === get_option( self::SUGGESTION_ROTATION_OPTION, false ) ) {
				add_option( self::SUGGESTION_ROTATION_OPTION, array(), '', false );
			}
		}

		/**
		 * Default persistent settings.
		 *
		 * @return array<string,mixed>
		 */
		public static function default_settings(): array {
			return array(
				'check_external_links' => false,
				'auto_enabled'         => false,
				'interval_hours'       => self::DEFAULT_INTERVAL,
			);
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
		 * Get saved report.
		 *
		 * @return array<string,mixed>
		 */
		public function get_report(): array {
			$report = get_option( self::REPORT_OPTION, array() );

			return is_array( $report ) ? $report : array();
		}

		/**
		 * Save report without autoloading it on every request.
		 *
		 * @param array<string,mixed> $report Report data.
		 */
		public function save_report( array $report ): void {
			$this->update_nonautoload_option( self::REPORT_OPTION, $report );
		}

		/**
		 * Clear saved report.
		 */
		public function clear_report(): void {
			delete_option( self::REPORT_OPTION );
		}

		/**
		 * Get dismissed suggestion IDs as a lookup map.
		 *
		 * @return array<string,bool>
		 */
		public function get_ignored_suggestion_ids(): array {
			$stored = get_option( self::IGNORED_SUGGESTIONS_OPTION, array() );
			$ids    = array();

			foreach ( (array) $stored as $id ) {
				$id = sanitize_key( (string) $id );
				if ( '' !== $id ) {
					$ids[ $id ] = true;
				}
			}

			return $ids;
		}

		/**
		 * Persist dismissed suggestion IDs.
		 *
		 * @param array<string,bool> $ids Suggestion ID map.
		 */
		public function save_ignored_suggestion_ids( array $ids ): void {
			$clean = array();

			foreach ( array_keys( $ids ) as $id ) {
				$id = sanitize_key( (string) $id );
				if ( '' !== $id ) {
					$clean[] = $id;
				}
			}

			$this->update_nonautoload_option( self::IGNORED_SUGGESTIONS_OPTION, array_values( array_unique( $clean ) ) );
		}

		/**
		 * Get persisted suggestion rotation state.
		 *
		 * @return array<string,mixed>
		 */
		public function get_suggestion_rotation(): array {
			$rotation = get_option( self::SUGGESTION_ROTATION_OPTION, array() );

			return is_array( $rotation ) ? $rotation : array();
		}

		/**
		 * Persist suggestion rotation state while bounding stored history.
		 *
		 * @param array<string,mixed> $rotation Rotation state.
		 */
		public function save_suggestion_rotation( array $rotation ): void {
			$this->update_nonautoload_option( self::SUGGESTION_ROTATION_OPTION, $this->sanitize_suggestion_rotation( $rotation ) );
		}

		/**
		 * Clear suggestion rotation state.
		 *
		 * @param string $scope Optional scope: normal, manual, or empty for all.
		 */
		public function clear_suggestion_rotation( string $scope = '' ): void {
			if ( '' === $scope ) {
				delete_option( self::SUGGESTION_ROTATION_OPTION );
				return;
			}

			$rotation = $this->get_suggestion_rotation();
			unset( $rotation[ $scope ] );
			$this->save_suggestion_rotation( $rotation );
		}

		/**
		 * Clear report, temporary scan state, and suggestion records without removing settings.
		 *
		 * @return int Number of deleted option rows attempted.
		 */
		public function clear_runtime_records(): int {
			global $wpdb;

			$deleted = 0;
			foreach ( array( self::REPORT_OPTION, self::IGNORED_SUGGESTIONS_OPTION, self::SUGGESTION_ROTATION_OPTION, self::LEGACY_REPORT_OPTION ) as $option ) {
				if ( delete_option( $option ) ) {
					++$deleted;
				}
			}

			foreach ( array( self::STATE_PREFIX, self::LEGACY_STATE_PREFIX ) as $prefix ) {
				$like    = $wpdb->esc_like( $prefix ) . '%';
				$options = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
						$like
					)
				);

				foreach ( (array) $options as $option_name ) {
					if ( delete_option( (string) $option_name ) ) {
						++$deleted;
					}
				}
			}

			$this->update_nonautoload_option( self::REPORT_OPTION, array() );
			$this->update_nonautoload_option( self::IGNORED_SUGGESTIONS_OPTION, array() );
			$this->update_nonautoload_option( self::SUGGESTION_ROTATION_OPTION, array() );

			return $deleted;
		}

		/**
		 * Get persistent settings.
		 *
		 * @return array<string,mixed>
		 */
		public function get_settings(): array {
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
		public function save_settings( array $settings ): void {
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
		 * Save the legacy external-link preference mirror.
		 *
		 * @param bool $enabled Whether external links are checked.
		 */
		public function save_check_external_option( bool $enabled ): void {
			$this->update_nonautoload_option( self::CHECK_EXTERNAL_OPTION, $enabled ? '1' : '0' );
		}

		/**
		 * Return saved internal report rows keyed by post ID.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public function get_report_row_map(): array {
			$report = $this->get_report();
			$map    = array();

			foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$id = (int) ( $row['id'] ?? 0 );
				if ( $id > 0 ) {
					$map[ $id ] = $row;
				}
			}

			return $map;
		}

		/**
		 * Save scan state without autoloading it on every request.
		 *
		 * @param string              $token Scan token.
		 * @param array<string,mixed> $state Scan state.
		 */
		public function save_scan_state( string $token, array $state ): void {
			$this->update_nonautoload_option( $this->get_scan_state_option( $token ), $state );
		}

		/**
		 * Get scan state.
		 *
		 * @param string $token Scan token.
		 * @return array<string,mixed>
		 */
		public function get_scan_state( string $token ): array {
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
		public function delete_scan_state( string $token ): void {
			if ( '' === $token ) {
				return;
			}

			delete_option( $this->get_scan_state_option( $token ) );
		}

		/**
		 * Add or update an option while keeping autoload disabled.
		 *
		 * @param string $name Option name.
		 * @param mixed  $value Option value.
		 */
		public function update_nonautoload_option( string $name, $value ): void {
			if ( false === get_option( $name, false ) ) {
				add_option( $name, $value, '', false );
				return;
			}

			update_option( $name, $value, false );
		}

		/**
		 * Keep rotation state small and predictable.
		 *
		 * @param array<string,mixed> $rotation Raw rotation state.
		 * @return array<string,mixed>
		 */
		private function sanitize_suggestion_rotation( array $rotation ): array {
			$clean = array();

			if ( isset( $rotation['normal'] ) && is_array( $rotation['normal'] ) ) {
				$clean['normal'] = array(
					'seen'       => $this->sanitize_seen_id_map( (array) ( $rotation['normal']['seen'] ?? array() ), 1000 ),
					'updated_at' => time(),
				);
			}

			if ( isset( $rotation['manual'] ) && is_array( $rotation['manual'] ) ) {
				$manual = array();

				foreach ( $rotation['manual'] as $context => $state ) {
					if ( ! is_array( $state ) ) {
						continue;
					}

					$context = sanitize_key( (string) $context );
					if ( '' === $context ) {
						continue;
					}

					$manual[ $context ] = array(
						'seen'       => $this->sanitize_seen_id_map( (array) ( $state['seen'] ?? array() ), 1000 ),
						'updated_at' => isset( $state['updated_at'] ) ? absint( $state['updated_at'] ) : time(),
					);
				}

				uasort(
					$manual,
					static function ( array $a, array $b ): int {
						return ( $b['updated_at'] ?? 0 ) <=> ( $a['updated_at'] ?? 0 );
					}
				);

				$clean['manual'] = array_slice( $manual, 0, 25, true );
			}

			return $clean;
		}

		/**
		 * Sanitize a seen-suggestion ID map and cap its size.
		 *
		 * @param array<string|int,mixed> $ids Raw IDs.
		 * @param int                     $limit Maximum IDs to keep.
		 * @return array<string,bool>
		 */
		private function sanitize_seen_id_map( array $ids, int $limit ): array {
			$clean = array();

			foreach ( $ids as $id => $value ) {
				$id = is_int( $id ) ? (string) $value : (string) $id;
				$id = sanitize_key( $id );

				if ( '' !== $id ) {
					$clean[ $id ] = true;
				}
			}

			return array_slice( $clean, 0, max( 1, $limit ), true );
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
		 * Normalize an hour interval.
		 *
		 * @param mixed $value Raw interval.
		 */
		public function normalize_interval_hours( $value ): int {
			$hours = absint( $value );

			if ( $hours < self::MIN_INTERVAL ) {
				return self::MIN_INTERVAL;
			}

			if ( $hours > self::MAX_INTERVAL ) {
				return self::MAX_INTERVAL;
			}

			return $hours;
		}
	}
}
