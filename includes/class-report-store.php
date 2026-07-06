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
		public const CHECK_EXTERNAL_OPTION      = 'linkflow_auditor_check_external_links';
		public const STATE_PREFIX               = 'linkflow_auditor_scan_';

		private const LEGACY_REPORT_OPTION         = 'maya_ils_report';
		private const LEGACY_SETTINGS_OPTION       = 'maya_ils_settings';
		private const LEGACY_CHECK_EXTERNAL_OPTION = 'maya_ils_check_external_links';

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
