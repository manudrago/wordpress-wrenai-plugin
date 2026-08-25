<?php
/**
 * Plugin Name:       WP Wren Dashboards
 * Plugin URI:        https://github.com/manudrago/wordpress-wrenai-plugin
 * Description:       Ask questions about your WordPress data in plain language and get instant, saveable dashboards. Powered by Wren AI (text-to-SQL + chart generation) over a read-only view of your database.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Emanuel Draghetti
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-wren-dashboards
 * Domain Path:       /languages
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

define( 'WWD_VERSION', '1.0.0' );
define( 'WWD_PLUGIN_FILE', __FILE__ );
define( 'WWD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WWD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WWD_PLUGIN_DIR . 'includes/class-wwd-settings.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-logger.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-schema.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-sql-guard.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-query-runner.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-wren-client.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-dashboards.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-ask-session.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-rest.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-shortcodes.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-admin.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-plugin.php';

/**
 * Main plugin instance.
 *
 * @return WWD_Plugin
 */
function wwd() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new WWD_Plugin();
	}

	return $instance;
}

wwd()->init();

register_activation_hook( __FILE__, array( 'WWD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WWD_Plugin', 'deactivate' ) );
