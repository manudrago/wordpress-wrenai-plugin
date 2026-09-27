<?php
/**
 * White label: the name the plugin goes by.
 *
 * An agency installs this on its clients' sites and would rather the menu
 * said "Acme Insights" than somebody else's product name. The Agency edition,
 * licensed, can rename the menu, the screen titles and the email reports, and
 * point the menu icon at the agency's own. Everything else keeps saying
 * DataChat.
 *
 * @package DataChat_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and stores the brand.
 */
class WWD_Brand {

	const OPTION  = 'wwd_brand';
	const DEFAULT = 'DataChat';

	/**
	 * Whether white label is available here.
	 *
	 * @return bool
	 */
	public static function available() {
		return 'agency' === WWD_License::edition() && WWD_Dashboards::is_licensed();
	}

	/**
	 * Stored brand, every key present.
	 *
	 * @return array
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, array() );

		return array_merge(
			array(
				'name' => '',
				'icon' => '',
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	/**
	 * The name to show.
	 *
	 * @return string
	 */
	public static function name() {
		$stored = self::stored();

		if ( self::available() && '' !== trim( $stored['name'] ) ) {
			return trim( $stored['name'] );
		}

		return self::DEFAULT;
	}

	/**
	 * The menu icon: an image URL the agency chose, or the chart dashicon.
	 *
	 * @return string
	 */
	public static function icon() {
		$stored = self::stored();

		if ( self::available() && '' !== trim( $stored['icon'] ) ) {
			return esc_url_raw( trim( $stored['icon'] ) );
		}

		return 'dashicons-chart-area';
	}

	/**
	 * Store the brand.
	 *
	 * @param array $input Name and icon.
	 * @return void
	 */
	public static function save( array $input ) {
		update_option(
			self::OPTION,
			array(
				'name' => isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '',
				'icon' => isset( $input['icon'] ) ? esc_url_raw( $input['icon'] ) : '',
			),
			false
		);
	}
}
