<?php
/**
 * Scheduled email reports and white label: when a report is due, what the
 * email shows for each shape of data, and whose name it carries. Runs without
 * WordPress:
 *
 *     php tests/test-reports.php
 *
 * @package DataChat_AI
 */

define( 'ABSPATH', __DIR__ );
define( 'WWD_EDITION', 'agency' );

require __DIR__ . '/wp-stubs.php';

/** @return string */
function sanitize_email( $email ) {
	return strtolower( trim( (string) $email ) );
}
/** @return bool */
function is_email( $email ) {
	return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
}
/** @return string */
function esc_html__( $text, $domain = '' ) {
	return esc_html( $text );
}
/** @return string */
function wp_date( $format, $timestamp = null ) {
	return gmdate( 'j F Y', null === $timestamp ? time() : $timestamp );
}
/** @return string */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( $number, $decimals );
}
/** @return string */
function esc_url_raw( $url ) {
	return (string) $url;
}

$GLOBALS['dc_licensed'] = true;

/** Minimal dashboards, for the licence check. */
class WWD_Dashboards {
	/** @return bool */
	public static function is_licensed() {
		return $GLOBALS['dc_licensed'];
	}
}

require __DIR__ . '/../includes/class-wwd-license.php';
require __DIR__ . '/../includes/class-wwd-brand.php';
require __DIR__ . '/../includes/class-wwd-reports.php';

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

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------

$clean = WWD_Reports::normalise(
	array(
		'enabled'    => '1',
		'frequency'  => 'hourly',
		'weekday'    => 12,
		'hour'       => 30,
		'recipients' => 'Boss@Example.com, not-an-email; team@example.com  boss@example.com',
	)
);

check( 'an unknown frequency falls back to weekly', 'weekly' === $clean['frequency'] );
check( 'the weekday is kept within the week', 7 === $clean['weekday'] );
check( 'the hour is kept within the day', 23 === $clean['hour'] );
check( 'recipients are split, cleaned and deduplicated', array( 'boss@example.com', 'team@example.com' ) === $clean['recipients'], wp_json_encode( $clean['recipients'] ) );

// ---------------------------------------------------------------------------
// When a report is due. 2026-09-28 is a Monday.
// ---------------------------------------------------------------------------

$monday_9  = gmmktime( 9, 5, 0, 9, 28, 2026 );
$monday_7  = gmmktime( 7, 5, 0, 9, 28, 2026 );
$tuesday_9 = gmmktime( 9, 5, 0, 9, 29, 2026 );
$first_9   = gmmktime( 9, 5, 0, 10, 1, 2026 );

$weekly = array( 'enabled' => 1, 'frequency' => 'weekly', 'weekday' => 1, 'hour' => 8, 'recipients' => array( 'a@example.com' ) );

check( 'a weekly report is due on its day, after its hour', WWD_Reports::is_due( $weekly, $monday_9 ) );
check( 'but not before its hour', ! WWD_Reports::is_due( $weekly, $monday_7 ) );
check( 'nor on another day', ! WWD_Reports::is_due( $weekly, $tuesday_9 ) );
check( 'nor twice the same day', ! WWD_Reports::is_due( $weekly + array( 'last_sent' => $monday_9 - 3600 ), $monday_9 ) );
check( 'the site\'s clock decides the day', WWD_Reports::is_due( $weekly, gmmktime( 23, 30, 0, 9, 27, 2026 ), 10 * HOUR_IN_SECONDS ) );
check( 'a disabled report is never due', ! WWD_Reports::is_due( array( 'enabled' => 0 ) + $weekly, $monday_9 ) );
check( 'nor one with nobody to send to', ! WWD_Reports::is_due( array( 'recipients' => array() ) + $weekly, $monday_9 ) );

$daily = array( 'frequency' => 'daily' ) + $weekly;
check( 'a daily report is due every day', WWD_Reports::is_due( $daily, $tuesday_9 ) );
check( 'and again the next day', WWD_Reports::is_due( $daily + array( 'last_sent' => $monday_9 ), $tuesday_9 ) );

$monthly = array( 'frequency' => 'monthly' ) + $weekly;
check( 'a monthly report is due on the 1st', WWD_Reports::is_due( $monthly, $first_9 ) );
check( 'and not on another day', ! WWD_Reports::is_due( $monthly, $monday_9 ) );

// ---------------------------------------------------------------------------
// What each shape of data looks like in the email
// ---------------------------------------------------------------------------

$kpi = WWD_Reports::render_panel( array( 'columns' => array( 'orders_this_month' ), 'rows' => array( array( 1234 ) ) ) );
check( 'one value is a big figure', false !== strpos( $kpi, 'font-size:34px' ) && false !== strpos( $kpi, '1,234' ), $kpi );

$bars = WWD_Reports::render_panel(
	array(
		'columns' => array( 'product', 'sold' ),
		'rows'    => array( array( 'Whey', 120 ), array( 'Creatine', 60 ), array( 'Bars', 30 ) ),
	)
);
check( 'a label and a number are a bar list', 3 === substr_count( $bars, 'background:#6366f1' ), $bars );
check( 'the longest bar is full width', false !== strpos( $bars, 'width:100%"></div>' ) );
check( 'and the others are in proportion', false !== strpos( $bars, 'width:50%"></div>' ) && false !== strpos( $bars, 'width:25%"></div>' ) );

$many = array();
for ( $i = 1; $i <= 14; $i++ ) {
	$many[] = array( 'Item ' . $i, $i );
}
$long = WWD_Reports::render_panel( array( 'columns' => array( 'item', 'n' ), 'rows' => $many ) );
check( 'a long list stops at ten', 10 === substr_count( $long, '<tr>' ) );
check( 'and says how many more', false !== strpos( $long, 'And 4 more' ) );

$table = WWD_Reports::render_panel(
	array(
		'columns' => array( 'order_id', 'status', 'total' ),
		'rows'    => array( array( 101, 'completed', 49.5 ), array( 102, 'processing', null ) ),
	)
);
check( 'anything else is a table with headed columns', false !== strpos( $table, '>Order id</th>' ) && false !== strpos( $table, '>completed</td>' ), $table );
check( 'numbers are formatted and empty cells marked', false !== strpos( $table, '>49.50</td>' ) && false !== strpos( $table, '>—</td>' ), $table );

$empty = WWD_Reports::render_panel( array( 'columns' => array( 'x' ), 'rows' => array() ) );
check( 'no rows says so', false !== strpos( $empty, 'No rows this time' ) );

$hostile = WWD_Reports::render_panel( array( 'columns' => array( 'name', 'n' ), 'rows' => array( array( '<script>alert(1)</script>', 1 ) ) ) );
check( 'values are escaped', false === strpos( $hostile, '<script>' ) );

// ---------------------------------------------------------------------------
// The whole email, and whose name it carries
// ---------------------------------------------------------------------------

$email = WWD_Reports::render(
	'Weekly sales',
	array(
		array( 'title' => 'Orders', 'columns' => array( 'n' ), 'rows' => array( array( 42 ) ) ),
		array( 'title' => 'Broken', 'error' => 'The generated query touches tables that are not shared.' ),
	),
	'https://shop.example/wp-admin/admin.php?page=wwd-boards&board=7'
);

check( 'the email is titled with the dashboard', false !== strpos( $email, '>Weekly sales</h1>' ) );
check( 'a panel that fails says why, and the rest still arrive', false !== strpos( $email, 'not shared' ) && false !== strpos( $email, '>42</p>' ) );
check( 'it links back to the dashboard', false !== strpos( $email, 'board=7' ) );
check( 'it is signed DataChat by default', false !== strpos( $email, 'Sent by DataChat.' ) );

update_option( WWD_Brand::OPTION, array( 'name' => 'Acme Insights', 'icon' => 'https://acme.example/i.png' ) );

check( 'a licensed Agency site goes by its own name', 'Acme Insights' === WWD_Brand::name() );
check( 'and its own menu icon', 'https://acme.example/i.png' === WWD_Brand::icon() );
check( 'and signs its reports with it', false !== strpos( WWD_Reports::render( 'X', array(), '#' ), 'Sent by Acme Insights.' ) );

$GLOBALS['dc_licensed'] = false;

check( 'without a licence the name goes back to DataChat', 'DataChat' === WWD_Brand::name() );
check( 'and so does the icon', 'dashicons-chart-area' === WWD_Brand::icon() );
check( 'and reports are not available', ! WWD_Reports::available() );

echo "\n{$checks} checks, {$failures} failures\n";

exit( $failures > 0 ? 1 : 0 );
