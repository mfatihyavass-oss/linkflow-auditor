<?php
/**
 * Uninstall cleanup for LinkFlow Auditor.
 *
 * @package LinkFlow_Auditor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'linkflow_auditor_report' );
delete_option( 'linkflow_auditor_settings' );
delete_option( 'linkflow_auditor_check_external_links' );
delete_option( 'linkflow_auditor_ignored_suggestions' );
delete_option( 'linkflow_auditor_suggestion_rotation' );
delete_transient( 'linkflow_auditor_background_scan_lock' );
wp_clear_scheduled_hook( 'linkflow_auditor_run_background_scan' );

delete_option( 'maya_ils_report' );
delete_option( 'maya_ils_settings' );
delete_option( 'maya_ils_check_external_links' );
delete_transient( 'maya_ils_background_scan_lock' );
wp_clear_scheduled_hook( 'maya_ils_run_background_scan' );

foreach ( array( 'linkflow_auditor_scan_', 'maya_ils_scan_' ) as $prefix ) {
	$like = $wpdb->esc_like( $prefix ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		)
	);
}
