<?php
/**
 * Plugin Name:       DataChat AI
 * Plugin URI:        https://github.com/manudrago/wordpress-wrenai-plugin
 * Description:       Ask your WordPress or WooCommerce data anything in plain language and get instant, saveable dashboards. The model writes the SQL, a strict guard checks it, your database answers - and the rows never leave your site.
 * Version:           2.3.0
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

/*
 * Declared by whichever copy loads first, and shared with any other: a second
 * declaration would be the very fatal error this file exists to avoid.
 */
if ( ! function_exists( 'datachat_edition_label' ) ) :

/**
 * The edition a copy of this plugin is, read without running its marker file.
 *
 * @param string $dir Plugin directory, with a trailing slash.
 * @return string Free, Pro or Agency.
 */
function datachat_edition_label( $dir ) {
	$marker = $dir . 'edition.php';

	if ( ! file_exists( $marker ) ) {
		return 'Free';
	}

	$source = (string) file_get_contents( $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	if ( preg_match( "/WWD_EDITION_LABEL',\s*'([A-Za-z]+)'/", $source, $found ) ) {
		return $found[1];
	}

	return 'Pro';
}

endif;

/*
 * Two editions of this plugin declare the same classes, so the second one to
 * load would be a fatal error and a white screen - which is how somebody
 * upgrading from the free plugin to a paid one would first meet it. Rather
 * than that, the second copy loads nothing at all and says so.
 */
if ( defined( 'WWD_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			$running  = defined( 'WWD_EDITION_LABEL' ) ? WWD_EDITION_LABEL : 'Free';
			$standing = datachat_edition_label( plugin_dir_path( __FILE__ ) );

			// Upgrading is the common case, and the one where a silent
			// nothing-happened is most confusing: say which to switch off.
			if ( 'Free' === $running && 'Free' !== $standing ) {
				$message = sprintf(
					/* translators: %s: the paid edition's name. */
					__( 'DataChat AI %s is installed but not running: the free edition is active and the two cannot run together. Deactivate "DataChat AI" on the Plugins screen, and this one takes over.', 'datachat-ai' ),
					$standing
				);
			} else {
				$message = sprintf(
					/* translators: 1: running edition, 2: the edition that stood down. */
					__( 'Two editions of DataChat AI are active. %1$s is running; %2$s is doing nothing, because both contain the same plugin. Deactivate the one you do not want on the Plugins screen.', 'datachat-ai' ),
					$running,
					$standing
				);
			}

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);

	return;
}

define( 'WWD_VERSION', '2.3.0' );
define( 'WWD_PLUGIN_FILE', __FILE__ );
define( 'WWD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WWD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Which edition this copy is. The build script writes edition.php into the
 * paid archives; without it this is the free plugin, and the licence screen
 * does not exist.
 */
if ( file_exists( WWD_PLUGIN_DIR . 'edition.php' ) ) {
	require_once WWD_PLUGIN_DIR . 'edition.php';
}

require_once WWD_PLUGIN_DIR . 'includes/class-wwd-license.php';
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

/*
 * PHP binds a top-level function when it compiles the file, before the guard
 * above has had a chance to return, so this one needs the same protection.
 */
if ( ! function_exists( 'wwd' ) ) :

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

endif;

wwd()->init();

register_activation_hook( __FILE__, array( 'WWD_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WWD_Plugin', 'deactivate' ) );
