<?php
/**
 * Scheduled email reports: a dashboard, delivered.
 *
 * Nobody opens wp-admin every Monday to look at a dashboard. An email that
 * arrives with last week's numbers already in it gets read. So a dashboard can
 * send itself - daily, weekly or monthly - to a list of addresses. The panels
 * are re-run from their saved SQL, which costs no model call at all; the email
 * shows each one as a figure, a bar list or a small table, because email
 * clients cannot be trusted with SVG.
 *
 * A paid feature: without a licence the settings are shown, locked.
 *
 * @package DataChat_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores report settings per dashboard, decides when one is due, and sends it.
 */
class WWD_Reports {

	const META = '_wwd_report';
	const HOOK = 'wwd_reports_tick';

	/**
	 * Rows an email table shows before saying how many more there are.
	 */
	const MAX_ROWS = 10;

	/**
	 * Hook the hourly check.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_wwd_report_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wwd_report_send', array( __CLASS__, 'handle_send_now' ) );
	}

	/**
	 * Make sure the hourly check is scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Stop the check, on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Whether reports can be sent on this site.
	 *
	 * @return bool
	 */
	public static function available() {
		return WWD_Dashboards::is_licensed();
	}

	/**
	 * Report settings of a dashboard, every key present.
	 *
	 * @param int $dashboard_id Dashboard.
	 * @return array
	 */
	public static function get( $dashboard_id ) {
		$stored = get_post_meta( (int) $dashboard_id, self::META, true );

		return self::normalise( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Settings with defaults filled in and bad values dropped.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function normalise( array $in ) {
		$frequency = isset( $in['frequency'] ) && in_array( $in['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $in['frequency'] : 'weekly';

		$recipients = isset( $in['recipients'] ) ? $in['recipients'] : array();

		if ( is_string( $recipients ) ) {
			$recipients = preg_split( '/[\s,;]+/', $recipients );
		}

		$recipients = array_values( array_unique( array_filter( array_map( 'sanitize_email', (array) $recipients ), 'is_email' ) ) );

		return array(
			'enabled'    => ! empty( $in['enabled'] ) ? 1 : 0,
			'frequency'  => $frequency,
			// 1 = Monday … 7 = Sunday, as date( 'N' ) counts.
			'weekday'    => isset( $in['weekday'] ) ? min( 7, max( 1, (int) $in['weekday'] ) ) : 1,
			'hour'       => isset( $in['hour'] ) ? min( 23, max( 0, (int) $in['hour'] ) ) : 8,
			'recipients' => array_slice( $recipients, 0, 20 ),
			'last_sent'  => isset( $in['last_sent'] ) ? (int) $in['last_sent'] : 0,
		);
	}

	/**
	 * Store report settings.
	 *
	 * @param int   $dashboard_id Dashboard.
	 * @param array $settings     Settings.
	 * @return array Stored.
	 */
	public static function save( $dashboard_id, array $settings ) {
		$current = self::get( $dashboard_id );
		$clean   = self::normalise( array_merge( $current, $settings ) );

		update_post_meta( (int) $dashboard_id, self::META, $clean );

		return $clean;
	}

	/**
	 * Whether a report is due at a moment, in the site's own clock.
	 *
	 * Due means: enabled, somebody to send to, the right hour of the right
	 * day, and not already sent in the last 20 hours - cron can run twice in
	 * an hour, or late, and nobody wants the same report twice.
	 *
	 * @param array $report Settings.
	 * @param int   $now    Timestamp.
	 * @param int   $offset Seconds the site's clock is ahead of UTC.
	 * @return bool
	 */
	public static function is_due( array $report, $now, $offset = 0 ) {
		$report = self::normalise( $report );

		if ( ! $report['enabled'] || empty( $report['recipients'] ) ) {
			return false;
		}

		if ( $report['last_sent'] && ( $now - $report['last_sent'] ) < 20 * HOUR_IN_SECONDS ) {
			return false;
		}

		$local = $now + (int) $offset;

		if ( (int) gmdate( 'G', $local ) < $report['hour'] ) {
			return false;
		}

		switch ( $report['frequency'] ) {
			case 'daily':
				return true;
			case 'weekly':
				return (int) gmdate( 'N', $local ) === $report['weekday'];
			case 'monthly':
				return 1 === (int) gmdate( 'j', $local );
		}

		return false;
	}

	/**
	 * Hourly: send whatever is due.
	 *
	 * @return int Reports sent.
	 */
	public static function tick() {
		if ( ! self::available() ) {
			return 0;
		}

		$sent   = 0;
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );

		foreach ( WWD_Dashboards::all() as $dashboard ) {
			$report = self::get( $dashboard->ID );

			if ( self::is_due( $report, time(), $offset ) && true === self::send( $dashboard->ID ) ) {
				$sent++;
			}
		}

		return $sent;
	}

	/**
	 * Build and send a dashboard's report now.
	 *
	 * @param int        $dashboard_id Dashboard.
	 * @param array|null $recipients   Override, for a test send.
	 * @return true|WP_Error
	 */
	public static function send( $dashboard_id, $recipients = null ) {
		$report     = self::get( $dashboard_id );
		$recipients = null === $recipients ? $report['recipients'] : $recipients;

		if ( empty( $recipients ) ) {
			return new WP_Error( 'wwd_report_nobody', __( 'Add at least one email address.', 'datachat-ai' ) );
		}

		$title   = get_the_title( $dashboard_id );
		$panels  = array();

		foreach ( WWD_Dashboards::panels( $dashboard_id ) as $panel ) {
			$data     = WWD_Dashboards::panel_data( $dashboard_id, $panel['id'] );
			$panels[] = is_wp_error( $data )
				? array( 'title' => $panel['title'], 'error' => $data->get_error_message() )
				: $data;
		}

		$html = self::render( $title, $panels, admin_url( 'admin.php?page=wwd-boards&board=' . (int) $dashboard_id ) );

		$subject = sprintf(
			/* translators: 1: dashboard title, 2: date. */
			__( '%1$s - %2$s', 'datachat-ai' ),
			$title,
			wp_date( get_option( 'date_format', 'j F Y' ) )
		);

		$sent = wp_mail( $recipients, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( ! $sent ) {
			return new WP_Error( 'wwd_report_mail', __( 'WordPress could not send the email. Check that this site can send mail (an SMTP plugin usually fixes it).', 'datachat-ai' ) );
		}

		// A test send to someone else does not count as the scheduled one.
		if ( $recipients === $report['recipients'] ) {
			self::save( $dashboard_id, array( 'last_sent' => time() ) );
		}

		return true;
	}

	/**
	 * The email.
	 *
	 * @param string $title  Dashboard title.
	 * @param array  $panels Panel data (columns, rows) or {title, error}.
	 * @param string $link   Where the dashboard lives.
	 * @return string HTML.
	 */
	public static function render( $title, array $panels, $link ) {
		$brand = WWD_Brand::name();
		$out   = '<div style="background:#f3f4f7;padding:24px 12px;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#1d2230">';
		$out  .= '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px;padding:28px">';
		$out  .= '<h1 style="margin:0 0 4px;font-size:22px">' . esc_html( $title ) . '</h1>';
		$out  .= '<p style="margin:0 0 20px;color:#6b7280;font-size:13px">' . esc_html( wp_date( get_option( 'date_format', 'j F Y' ) ) ) . '</p>';

		if ( empty( $panels ) ) {
			$out .= '<p>' . esc_html__( 'This dashboard has no panels yet.', 'datachat-ai' ) . '</p>';
		}

		foreach ( $panels as $panel ) {
			$out .= '<div style="border-top:1px solid #e6e8ee;padding:18px 0">';
			$out .= '<h2 style="margin:0 0 12px;font-size:16px">' . esc_html( isset( $panel['title'] ) ? $panel['title'] : '' ) . '</h2>';
			$out .= isset( $panel['error'] )
				? '<p style="color:#b42318;font-size:14px">' . esc_html( $panel['error'] ) . '</p>'
				: self::render_panel( $panel );
			$out .= '</div>';
		}

		$out .= '<p style="margin:20px 0 0"><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px">'
			. esc_html__( 'Open the dashboard', 'datachat-ai' ) . '</a></p>';
		$out .= '</div>';
		$out .= '<p style="text-align:center;color:#9aa0ad;font-size:12px;margin:14px 0 0">'
			/* translators: %s: product name. */
			. esc_html( sprintf( __( 'Sent by %s. Change or stop this report under Dashboards.', 'datachat-ai' ), $brand ) ) . '</p>';
		$out .= '</div>';

		return $out;
	}

	/**
	 * One panel, in the shape its data allows.
	 *
	 * One value: a big figure. A label and a number: a bar list, which reads
	 * the way the chart would. Anything else: a table.
	 *
	 * @param array $panel Columns and rows.
	 * @return string HTML.
	 */
	public static function render_panel( array $panel ) {
		$columns = isset( $panel['columns'] ) ? array_values( (array) $panel['columns'] ) : array();
		$rows    = isset( $panel['rows'] ) ? array_values( (array) $panel['rows'] ) : array();

		if ( empty( $rows ) ) {
			return '<p style="color:#6b7280;font-size:14px">' . esc_html__( 'No rows this time.', 'datachat-ai' ) . '</p>';
		}

		$names = array_map( array( __CLASS__, 'column_name' ), $columns );
		$first = (array) $rows[0];

		// A single number.
		if ( 1 === count( $rows ) && 1 === count( $first ) ) {
			$value = reset( $first );

			return '<p style="margin:0;font-size:34px;font-weight:700">' . esc_html( self::format( $value ) ) . '</p>';
		}

		// Label + number.
		if ( 2 === count( $first ) ) {
			$values = array_values( $first );

			if ( ! is_numeric( $values[0] ) && is_numeric( $values[1] ) ) {
				return self::render_bars( $rows );
			}
		}

		return self::render_table( $names, $rows );
	}

	/**
	 * A bar list, as email-safe table cells.
	 *
	 * @param array $rows Rows of {label, value}.
	 * @return string
	 */
	protected static function render_bars( array $rows ) {
		$shown = array_slice( $rows, 0, self::MAX_ROWS );
		$max   = 0;

		foreach ( $shown as $row ) {
			$values = array_values( (array) $row );
			$max    = max( $max, abs( (float) $values[1] ) );
		}

		$out = '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">';

		foreach ( $shown as $row ) {
			$values = array_values( (array) $row );
			$width  = $max > 0 ? max( 1, (int) round( abs( (float) $values[1] ) / $max * 100 ) ) : 1;

			$out .= '<tr>'
				. '<td style="padding:4px 8px 4px 0;width:38%;vertical-align:middle">' . esc_html( (string) $values[0] ) . '</td>'
				. '<td style="padding:4px 0;vertical-align:middle"><div style="background:#6366f1;height:14px;border-radius:3px;width:' . $width . '%"></div></td>'
				. '<td style="padding:4px 0 4px 8px;width:18%;text-align:right;vertical-align:middle;font-weight:600">' . esc_html( self::format( $values[1] ) ) . '</td>'
				. '</tr>';
		}

		$out .= '</table>';

		return $out . self::more( count( $rows ) );
	}

	/**
	 * A plain table.
	 *
	 * @param array $names Column names.
	 * @param array $rows  Rows.
	 * @return string
	 */
	protected static function render_table( array $names, array $rows ) {
		$names = array_slice( $names, 0, 6 );
		$out   = '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:13px"><tr>';

		foreach ( $names as $name ) {
			$out .= '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e6e8ee">' . esc_html( $name ) . '</th>';
		}

		$out .= '</tr>';

		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			$out .= '<tr>';

			foreach ( array_slice( array_values( (array) $row ), 0, 6 ) as $value ) {
				$out .= '<td style="padding:6px 8px;border-bottom:1px solid #f0f1f4">' . esc_html( self::format( $value ) ) . '</td>';
			}

			$out .= '</tr>';
		}

		return $out . '</table>' . self::more( count( $rows ) );
	}

	/**
	 * "And 12 more", when a list was cut.
	 *
	 * @param int $total Rows.
	 * @return string
	 */
	protected static function more( $total ) {
		if ( $total <= self::MAX_ROWS ) {
			return '';
		}

		return '<p style="color:#6b7280;font-size:12px;margin:6px 0 0">' . esc_html(
			sprintf(
				/* translators: %d: rows not shown. */
				__( 'And %d more in the dashboard.', 'datachat-ai' ),
				$total - self::MAX_ROWS
			)
		) . '</p>';
	}

	/**
	 * A column's name, whether the runner gave a string or a descriptor.
	 *
	 * @param mixed $column Column.
	 * @return string
	 */
	protected static function column_name( $column ) {
		if ( is_array( $column ) ) {
			$column = isset( $column['name'] ) ? $column['name'] : reset( $column );
		}

		return ucfirst( str_replace( '_', ' ', (string) $column ) );
	}

	/**
	 * A value as a person reads it.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected static function format( $value ) {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		if ( is_numeric( $value ) ) {
			$number   = (float) $value;
			$decimals = floor( $number ) == $number ? 0 : 2; // phpcs:ignore WordPress.PHP.StrictComparisons

			return number_format_i18n( $number, $decimals );
		}

		return (string) $value;
	}

	// -----------------------------------------------------------------------
	// Screen: a box under the dashboard.
	// -----------------------------------------------------------------------

	/**
	 * The report settings form for a dashboard.
	 *
	 * @param int $dashboard_id Dashboard.
	 * @return string HTML.
	 */
	public static function form( $dashboard_id ) {
		$report    = self::get( $dashboard_id );
		$available = self::available();
		$disabled  = $available ? '' : ' disabled';
		$days      = array( 1 => __( 'Monday', 'datachat-ai' ), 2 => __( 'Tuesday', 'datachat-ai' ), 3 => __( 'Wednesday', 'datachat-ai' ), 4 => __( 'Thursday', 'datachat-ai' ), 5 => __( 'Friday', 'datachat-ai' ), 6 => __( 'Saturday', 'datachat-ai' ), 7 => __( 'Sunday', 'datachat-ai' ) );

		$out  = '<div class="wwd-report-box" style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:24px 0;max-width:820px">';
		$out .= '<h2 style="margin-top:0">' . esc_html__( 'Email this dashboard', 'datachat-ai' ) . '</h2>';

		if ( ! $available ) {
			$out .= '<p>' . esc_html__( 'Scheduled email reports come with Pro and Agency: the numbers arrive in your inbox every day, week or month, without opening wp-admin.', 'datachat-ai' ) . '</p>';
		}

		$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$out .= wp_nonce_field( 'wwd_report_' . (int) $dashboard_id, '_wpnonce', true, false );
		$out .= '<input type="hidden" name="action" value="wwd_report_save"><input type="hidden" name="board" value="' . (int) $dashboard_id . '">';
		$out .= '<p><label><input type="checkbox" name="enabled" value="1"' . checked( $report['enabled'], 1, false ) . $disabled . '> ' . esc_html__( 'Send this dashboard by email', 'datachat-ai' ) . '</label></p>';

		$out .= '<p><select name="frequency"' . $disabled . '>';
		foreach ( array( 'daily' => __( 'Every day', 'datachat-ai' ), 'weekly' => __( 'Every week', 'datachat-ai' ), 'monthly' => __( 'Every month (on the 1st)', 'datachat-ai' ) ) as $value => $label ) {
			$out .= '<option value="' . esc_attr( $value ) . '"' . selected( $report['frequency'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select> ';

		$out .= esc_html__( 'on', 'datachat-ai' ) . ' <select name="weekday"' . $disabled . '>';
		foreach ( $days as $value => $label ) {
			$out .= '<option value="' . (int) $value . '"' . selected( $report['weekday'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$out .= '</select> ';

		$out .= esc_html__( 'at', 'datachat-ai' ) . ' <select name="hour"' . $disabled . '>';
		for ( $h = 0; $h < 24; $h++ ) {
			$out .= '<option value="' . $h . '"' . selected( $report['hour'], $h, false ) . '>' . sprintf( '%02d:00', $h ) . '</option>';
		}
		$out .= '</select></p>';

		$out .= '<p><label>' . esc_html__( 'Send to (one or more emails, comma-separated)', 'datachat-ai' ) . '<br>';
		$out .= '<input type="text" class="large-text" name="recipients" value="' . esc_attr( implode( ', ', $report['recipients'] ) ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '"' . $disabled . '></label></p>';

		$out .= '<p><button class="button button-primary"' . $disabled . '>' . esc_html__( 'Save', 'datachat-ai' ) . '</button> ';
		$out .= '<button class="button" name="send_now" value="1"' . $disabled . '>' . esc_html__( 'Send a test to me now', 'datachat-ai' ) . '</button></p>';

		if ( $report['last_sent'] ) {
			$out .= '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Last sent %s.', 'datachat-ai' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $report['last_sent'] )
				)
			) . '</p>';
		}

		$out .= '</form></div>';

		return $out;
	}

	/**
	 * Save the form.
	 *
	 * @return void
	 */
	public static function handle_save() {
		$board = isset( $_POST['board'] ) ? (int) $_POST['board'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'wwd_report_' . $board );

		if ( ! current_user_can( 'edit_post', $board ) || ! self::available() ) {
			wp_die( esc_html__( 'You cannot change this report.', 'datachat-ai' ) );
		}

		self::save(
			$board,
			array(
				'enabled'    => ! empty( $_POST['enabled'] ),
				'frequency'  => isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'weekly',
				'weekday'    => isset( $_POST['weekday'] ) ? (int) $_POST['weekday'] : 1,
				'hour'       => isset( $_POST['hour'] ) ? (int) $_POST['hour'] : 8,
				'recipients' => isset( $_POST['recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['recipients'] ) ) : '',
			)
		);

		$flag = 'saved';

		// "Send a test to me now" saves first, so the test shows what was just set.
		if ( ! empty( $_POST['send_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$user = wp_get_current_user();
			$flag = true === self::send( $board, array( $user->user_email ) ) ? 'sent' : 'failed';
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wwd-boards&board=' . $board . '&wwd_report=' . $flag ) );
		exit;
	}

	/**
	 * Send a test to the person pressing the button.
	 *
	 * @return void
	 */
	public static function handle_send_now() {
		$board = isset( $_POST['board'] ) ? (int) $_POST['board'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'wwd_report_' . $board );

		if ( ! current_user_can( 'edit_post', $board ) || ! self::available() ) {
			wp_die( esc_html__( 'You cannot send this report.', 'datachat-ai' ) );
		}

		$user   = wp_get_current_user();
		$result = self::send( $board, array( $user->user_email ) );

		wp_safe_redirect( admin_url( 'admin.php?page=wwd-boards&board=' . $board . '&wwd_report=' . ( true === $result ? 'sent' : 'failed' ) ) );
		exit;
	}
}
