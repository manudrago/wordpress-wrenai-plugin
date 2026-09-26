<?php
/**
 * Two editions on one site. The free plugin and a paid one contain the same
 * classes, so loading both is a fatal error unless the second one stands down.
 * This loads two real copies, in a row, exactly as WordPress would:
 *
 *     php tests/test-bootstrap.php
 *
 * @package DataChat_AI
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';

/**
 * Hooks and shortcodes the plugin registers while loading.
 */
class WWD_Test_Hooks {

	/**
	 * Registered actions and filters, by hook name.
	 *
	 * @var array
	 */
	public static $hooks = array();

	/**
	 * Registered shortcodes.
	 *
	 * @var array
	 */
	public static $shortcodes = array();
}

/**
 * Action registrar.
 *
 * @param string $hook     Hook name.
 * @param mixed  $callback Callback.
 * @return bool
 */
function add_action( $hook, $callback = null ) {
	WWD_Test_Hooks::$hooks[ $hook ][] = $callback;

	return true;
}

/**
 * Filter registrar.
 *
 * @param string $hook     Hook name.
 * @param mixed  $callback Callback.
 * @return bool
 */
function add_filter( $hook, $callback = null ) {
	WWD_Test_Hooks::$hooks[ $hook ][] = $callback;

	return true;
}

/**
 * Shortcode registrar.
 *
 * @param string $tag      Shortcode.
 * @param mixed  $callback Callback.
 * @return bool
 */
function add_shortcode( $tag, $callback = null ) {
	WWD_Test_Hooks::$shortcodes[] = $tag;

	return true;
}

/**
 * Directory of a plugin file.
 *
 * @param string $file File.
 * @return string
 */
function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}

/**
 * URL of a plugin directory.
 *
 * @param string $file File.
 * @return string
 */
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}

/**
 * Plugin identifier.
 *
 * @param string $file File.
 * @return string
 */
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

/**
 * Activation hook registrar.
 *
 * @return void
 */
function register_activation_hook() {}

/**
 * Deactivation hook registrar.
 *
 * @return void
 */
function register_deactivation_hook() {}

/**
 * Admin context.
 *
 * @return bool
 */
function is_admin() {
	return true;
}

/**
 * Capability check for the notice.
 *
 * @return bool
 */
function current_user_can_activate_plugins() {
	return true;
}

$failures = 0;
$checks   = 0;

/**
 * Assert a condition.
 *
 * @param string $label   Test label.
 * @param bool   $passed  Result.
 * @param string $details Extra output on failure.
 * @return void
 */
function check( $label, $passed, $details = '' ) {
	global $failures, $checks;

	$checks++;

	if ( $passed ) {
		echo "ok    {$label}\n";

		return;
	}

	$failures++;
	echo "FAIL  {$label}\n";

	if ( '' !== $details ) {
		echo "      {$details}\n";
	}
}

/**
 * Copy the plugin into a directory, optionally as a paid edition.
 *
 * @param string $slug    Folder name.
 * @param string $edition free, pro or agency.
 * @return string Path of the main file.
 */
function stage_copy( $slug, $edition = 'free' ) {
	$root = sys_get_temp_dir() . '/wwd-bootstrap-' . getmypid() . '/' . $slug;
	$from = dirname( __DIR__ );

	@mkdir( $root . '/includes/views', 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	copy( $from . '/datachat-ai.php', $root . '/datachat-ai.php' );

	foreach ( glob( $from . '/includes/*.php' ) as $file ) {
		copy( $file, $root . '/includes/' . basename( $file ) );
	}

	foreach ( glob( $from . '/includes/views/*.php' ) as $file ) {
		copy( $file, $root . '/includes/views/' . basename( $file ) );
	}

	if ( 'free' !== $edition ) {
		$label = ucfirst( $edition );

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$root . '/edition.php',
			"<?php\ndefine( 'WWD_EDITION', '{$edition}' );\ndefine( 'WWD_EDITION_LABEL', '{$label}' );\n"
		);
	}

	return $root . '/datachat-ai.php';
}

// ---------------------------------------------------------------------------
// One copy: the plugin loads
// ---------------------------------------------------------------------------

$first = stage_copy( 'datachat-ai', 'free' );

require $first;

check( 'a single copy loads', defined( 'WWD_VERSION' ) );
check( 'and registers its shortcodes', in_array( 'datachat', WWD_Test_Hooks::$shortcodes, true ) );
check( 'and reads as the free edition', 'free' === WWD_License::edition() );

$notices_before = isset( WWD_Test_Hooks::$hooks['admin_notices'] ) ? count( WWD_Test_Hooks::$hooks['admin_notices'] ) : 0;
$shortcodes_before = count( WWD_Test_Hooks::$shortcodes );

// ---------------------------------------------------------------------------
// A second copy, as WordPress would load it: same request, same process
// ---------------------------------------------------------------------------

$second = stage_copy( 'datachat-ai-pro', 'pro' );

require $second;

check( 'a second edition does not redeclare anything', true );
check( 'it registers nothing of its own', count( WWD_Test_Hooks::$shortcodes ) === $shortcodes_before );

$notices_after = isset( WWD_Test_Hooks::$hooks['admin_notices'] ) ? count( WWD_Test_Hooks::$hooks['admin_notices'] ) : 0;

check( 'but it does say something', $notices_after === $notices_before + 1 );

// The notice has to name the right one to switch off.
$notice = end( WWD_Test_Hooks::$hooks['admin_notices'] );

ob_start();
$notice();
$printed = ob_get_clean();

check( 'the notice names the paid edition that is idle', false !== strpos( $printed, 'Pro' ), $printed );
check( 'and says to deactivate the free one', false !== strpos( $printed, 'Deactivate' ), $printed );

check( 'the edition label is read without running the marker', 'Pro' === datachat_edition_label( dirname( $second ) . '/' ) );
check( 'and a copy without one is free', 'Free' === datachat_edition_label( dirname( $first ) . '/' ) );

// Clean up the staged copies.
exec( 'rm -rf ' . escapeshellarg( sys_get_temp_dir() . '/wwd-bootstrap-' . getmypid() ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );
