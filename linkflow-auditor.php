<?php
/**
 * Plugin Name: LinkFlow Auditor
 * Plugin URI: https://github.com/mfatihyavass-oss/linkflow-auditor
 * Description: Audits internal links, broken links and redirecting links from the WordPress admin.
 * Version: 1.10.4
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

if ( ! defined( 'LINKFLOW_AUDITOR_FILE' ) ) {
	define( 'LINKFLOW_AUDITOR_FILE', __FILE__ );
}

if ( ! defined( 'LINKFLOW_AUDITOR_PATH' ) ) {
	define( 'LINKFLOW_AUDITOR_PATH', plugin_dir_path( __FILE__ ) );
}

require_once LINKFLOW_AUDITOR_PATH . 'includes/class-report-store.php';
require_once LINKFLOW_AUDITOR_PATH . 'includes/class-url-normalizer.php';
require_once LINKFLOW_AUDITOR_PATH . 'includes/class-link-editor.php';
require_once LINKFLOW_AUDITOR_PATH . 'includes/class-link-suggestion-engine.php';
require_once LINKFLOW_AUDITOR_PATH . 'includes/class-manual-suggestion-engine.php';
require_once LINKFLOW_AUDITOR_PATH . 'includes/class-http-link-checker.php';
require_once LINKFLOW_AUDITOR_PATH . 'includes/class-admin-page.php';
require_once LINKFLOW_AUDITOR_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'LinkFlow_Auditor', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LinkFlow_Auditor', 'deactivate' ) );

LinkFlow_Auditor::instance();
