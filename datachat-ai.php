<?php
/**
 * Plugin Name:       DataChat AI
 * Plugin URI:        https://github.com/manudrago/wordpress-wrenai-plugin
 * Description:       Ask your WordPress or WooCommerce data anything in plain language and get instant, saveable dashboards. The model writes the SQL, a strict guard checks it, your database answers - and the rows never leave your site.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Emanuel Draghetti
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       datachat-ai
 * Domain Path:       /languages
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

define( 'WWD_VERSION', '2.0.0' );
define( 'WWD_PLUGIN_FILE', __FILE__ );
define( 'WWD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WWD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WWD_PLUGIN_DIR . 'includes/class-wwd-settings.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-logger.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-schema.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-sql-guard.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-query-runner.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-wren-client.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-model-client.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-engine.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-engine-direct.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-engine-wren.php';
require_once WWD_PLUGIN_DIR . 'includes/class-wwd-pairing.php';
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
