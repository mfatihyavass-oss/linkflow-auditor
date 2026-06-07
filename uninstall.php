<?php
/**
 * Uninstall cleanup for İç Link Sayıcı.
 *
 * @package Ic_Link_Sayici
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'maya_ils_report' );

$like = $wpdb->esc_like( 'maya_ils_scan_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$like
	)
);
