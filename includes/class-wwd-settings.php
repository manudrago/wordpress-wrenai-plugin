<?php
/**
 * Plugin settings storage and defaults.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin option blob.
 */
class WWD_Settings {

	const OPTION_KEY = 'wwd_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Connection to the Wren AI service (wren-ai-service REST API).
			'endpoint'           => 'http://localhost:5555',
			'api_prefix'         => '/v1',
			'api_key'            => '',
			'project_id'         => '',
			'request_timeout'    => 20,

			// Semantic layer.
			'allowed_tables'     => self::default_tables(),
			'blocked_columns'    => array( 'user_pass', 'user_activation_key', 'user_email', 'session_tokens' ),
			'mdl_hash'           => '',
			'mdl_deployed_at'    => 0,
			'mdl_ready'          => 0,
			'custom_instruction' => self::default_instruction(),

			// Behaviour / limits.
			'max_rows'           => 1000,
			'cache_ttl'          => 300,
			'rate_limit'         => 15,
			'language'           => '',
			'ask_capability'     => 'edit_posts',
			'save_capability'    => 'edit_posts',
			'allow_public'       => 0,
			'show_sql'           => 1,
			'log_queries'        => 1,
		);
	}

	/**
	 * WordPress core tables that are safe and useful to expose by default.
	 *
	 * @return array
	 */
	public static function default_tables() {
		global $wpdb;

		$tables = array( 'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments' );
		$out    = array();

		foreach ( $tables as $table ) {
			$out[] = $wpdb->prefix . $table;
		}

		return $out;
	}

	/**
	 * Instruction handed to Wren AI with every question, so the generated SQL
	 * can run on the WordPress database without a translation layer.
	 *
	 * @return string
	 */
	public static function default_instruction() {
		return "Generate SQL that runs on MySQL 8 / MariaDB. Use only the tables provided in the semantic model. " .
			"Prefer DATE_FORMAT() over DATE_TRUNC(), CAST(x AS SIGNED) over CAST(x AS BIGINT), and CONCAT() over the || operator. " .
			"WordPress stores post status in posts.post_status ('publish', 'draft', 'trash'), post type in posts.post_type ('post', 'page', 'product'), " .
			"and custom fields as key/value rows in postmeta joined on postmeta.post_id = posts.ID. " .
			"Unless the question says otherwise, ignore revisions and auto-drafts, and only count published content.";
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Persist a partial set of settings.
	 *
	 * @param array $values Values to merge in.
	 * @return array The stored settings.
	 */
	public static function update( array $values ) {
		$settings = array_merge( self::all(), $values );

		update_option( self::OPTION_KEY, $settings, false );

		return $settings;
	}

	/**
	 * Sanitize a raw settings payload coming from the admin form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$clean = array();

		if ( isset( $input['endpoint'] ) ) {
			$clean['endpoint'] = untrailingslashit( esc_url_raw( trim( $input['endpoint'] ) ) );
		}

		if ( isset( $input['api_prefix'] ) ) {
			$prefix = trim( sanitize_text_field( $input['api_prefix'] ) );
			$prefix = '/' . trim( $prefix, '/' );

			$clean['api_prefix'] = '/' === $prefix ? '/v1' : $prefix;
		}

		if ( isset( $input['api_key'] ) ) {
			$clean['api_key'] = trim( sanitize_text_field( $input['api_key'] ) );
		}

		if ( isset( $input['project_id'] ) ) {
			$clean['project_id'] = sanitize_text_field( $input['project_id'] );
		}

		if ( isset( $input['request_timeout'] ) ) {
			$clean['request_timeout'] = max( 5, min( 120, (int) $input['request_timeout'] ) );
		}

		if ( isset( $input['custom_instruction'] ) ) {
			$clean['custom_instruction'] = sanitize_textarea_field( $input['custom_instruction'] );
		}

		if ( isset( $input['max_rows'] ) ) {
			$clean['max_rows'] = max( 10, min( 20000, (int) $input['max_rows'] ) );
		}

		if ( isset( $input['cache_ttl'] ) ) {
			$clean['cache_ttl'] = max( 0, min( DAY_IN_SECONDS, (int) $input['cache_ttl'] ) );
		}

		if ( isset( $input['rate_limit'] ) ) {
			$clean['rate_limit'] = max( 1, min( 500, (int) $input['rate_limit'] ) );
		}

		if ( isset( $input['language'] ) ) {
			$clean['language'] = sanitize_text_field( $input['language'] );
		}

		foreach ( array( 'ask_capability', 'save_capability' ) as $cap_key ) {
			if ( isset( $input[ $cap_key ] ) ) {
				$cap = sanitize_key( $input[ $cap_key ] );

				$clean[ $cap_key ] = $cap ? $cap : 'edit_posts';
			}
		}

		/*
		 * Checkboxes are absent from $_POST when nothing is ticked, and each
		 * admin screen submits only part of the settings. The hidden _fields
		 * list says which controls this form owns, so an unticked box is stored
		 * as 0 without another screen wiping it.
		 */
		$present = isset( $input['_fields'] ) ? array_map( 'sanitize_key', (array) $input['_fields'] ) : array();

		foreach ( array( 'allow_public', 'show_sql', 'log_queries' ) as $bool_key ) {
			if ( ! in_array( $bool_key, $present, true ) ) {
				continue;
			}

			$clean[ $bool_key ] = empty( $input[ $bool_key ] ) ? 0 : 1;
		}

		if ( in_array( 'allowed_tables', $present, true ) ) {
			$submitted = isset( $input['allowed_tables'] ) ? (array) $input['allowed_tables'] : array();
			$known     = WWD_Schema::list_tables();

			$clean['allowed_tables'] = array_values(
				array_intersect( $known, array_map( 'sanitize_text_field', $submitted ) )
			);
		}

		if ( isset( $input['blocked_columns'] ) ) {
			$columns = is_array( $input['blocked_columns'] )
				? $input['blocked_columns']
				: preg_split( '/[\s,]+/', (string) $input['blocked_columns'] );

			$columns = array_filter( array_map( 'sanitize_key', (array) $columns ) );

			$clean['blocked_columns'] = array_values( array_unique( $columns ) );
		}

		return $clean;
	}

	/**
	 * Language passed to Wren AI, defaulting to the site locale.
	 *
	 * @return string
	 */
	public static function language() {
		$configured = trim( (string) self::get( 'language' ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		$locale = get_locale();
		$map    = array(
			'it' => 'Italian',
			'en' => 'English',
			'es' => 'Spanish',
			'fr' => 'French',
			'de' => 'German',
			'pt' => 'Portuguese',
			'nl' => 'Dutch',
			'ja' => 'Japanese',
			'zh' => 'Chinese',
		);

		$prefix = strtolower( substr( $locale, 0, 2 ) );

		return isset( $map[ $prefix ] ) ? $map[ $prefix ] : 'English';
	}

	/**
	 * Whether the plugin has everything it needs to answer questions.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) self::get( 'endpoint' ) ) && '' !== (string) self::get( 'mdl_hash' );
	}
}
