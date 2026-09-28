<?php
/**
 * Saving panels has no cap. Runs without WordPress:
 *
 *     php tests/test-limits.php
 *
 * @package WP_Wren_Dashboards
 */

define( 'ABSPATH', __DIR__ );

require __DIR__ . '/wp-stubs.php';

/**
 * Posts and their meta, for the handful of calls the dashboards class makes.
 */
class WWD_Test_Posts {

	/**
	 * Dashboard ids.
	 *
	 * @var array
	 */
	public static $dashboards = array( 1 );

	/**
	 * Panels per dashboard id.
	 *
	 * @var array
	 */
	public static $panels = array();
}

/**
 * Dashboard posts.
 *
 * @param array $args Ignored.
 * @return array
 */
function get_posts( $args = array() ) {
	return array_map(
		static function ( $id ) {
			return (object) array( 'ID' => $id );
		},
		WWD_Test_Posts::$dashboards
	);
}

/**
 * Panel meta.
 *
 * @param int    $post_id Post id.
 * @param string $key     Ignored.
 * @param bool   $single  Ignored.
 * @return mixed
 */
function get_post_meta( $post_id, $key = '', $single = false ) {
	return isset( WWD_Test_Posts::$panels[ $post_id ] ) ? WWD_Test_Posts::$panels[ $post_id ] : '';
}

/**
 * Panel meta writer.
 *
 * @param int    $post_id Post id.
 * @param string $key     Ignored.
 * @param mixed  $value   Panels.
 * @return bool
 */
function update_post_meta( $post_id, $key, $value ) {
	WWD_Test_Posts::$panels[ $post_id ] = $value;

	return true;
}

/**
 * Post type of a dashboard.
 *
 * @param int $post_id Post id.
 * @return string
 */
function get_post_type( $post_id ) {
	return in_array( (int) $post_id, WWD_Test_Posts::$dashboards, true ) ? 'wwd_dashboard' : '';
}

/**
 * Plural picker.
 *
 * @param string $single Singular.
 * @param string $plural Plural.
 * @param int    $count  Count.
 * @return string
 */
function _n( $single, $plural, $count ) { // phpcs:ignore
	return 1 === (int) $count ? $single : $plural;
}

/**
 * Registers nothing here.
 *
 * @return void
 */
function register_post_type() {}

require __DIR__ . '/../includes/class-wwd-settings.php';
require __DIR__ . '/../includes/class-wwd-schema.php';
require __DIR__ . '/../includes/class-wwd-sql-guard.php';
require __DIR__ . '/../includes/class-wwd-dashboards.php';

WWD_Settings::update(
	array(
		'allowed_tables'  => array( 'wp_posts', 'wp_postmeta' ),
		'blocked_columns' => array( 'post_password' ),
		'max_rows'        => 1000,
	)
);

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
 * Save a panel on the test dashboard.
 *
 * @param string $title Panel title.
 * @return array|WP_Error
 */
function save_panel( $title ) {
	return WWD_Dashboards::add_panel(
		1,
		array(
			'title'    => $title,
			'question' => $title,
			'sql'      => 'SELECT COUNT(*) AS n FROM `wp_posts` LIMIT 10',
		)
	);
}

// ---------------------------------------------------------------------------
// No cap, licence or not: WordPress.org hosts only fully working plugins
// ---------------------------------------------------------------------------

check( 'a fresh site is not licensed', ! WWD_Dashboards::is_licensed() );

foreach ( array( 'one', 'two', 'three', 'four', 'five' ) as $wwd_title ) {
	check( "panel {$wwd_title} saves", ! is_wp_error( save_panel( $wwd_title ) ) );
}

check( 'every one is stored', 5 === count( WWD_Dashboards::panels( 1 ) ) );
check( 'there is no limit left to ask about', ! method_exists( 'WWD_Dashboards', 'panel_limit' ) );

WWD_Dashboards::delete_panel( 1, WWD_Dashboards::panels( 1 )[0]['id'] );

check( 'removing one works', 4 === count( WWD_Dashboards::panels( 1 ) ) );
check( 'and its data is still readable', null !== WWD_Dashboards::panel( 1, WWD_Dashboards::panels( 1 )[0]['id'] ) );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );
