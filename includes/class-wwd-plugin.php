<?php
/**
 * Plugin bootstrap.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the pieces together.
 */
class WWD_Plugin {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( 'WWD_Dashboards', 'register' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );

		$rest = new WWD_REST();
		$rest->init();

		$shortcodes = new WWD_Shortcodes();
		$shortcodes->init();

		if ( is_admin() ) {
			$admin = new WWD_Admin();
			$admin->init();
		}
	}

	/**
	 * Nudge administrators through the two setup steps.
	 *
	 * @return void
	 */
	public function setup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && false !== strpos( (string) $screen->id, 'wwd' ) ) {
			return;
		}

		if ( WWD_Settings::is_configured() ) {
			return;
		}

		$url = admin_url( 'admin.php?page=wwd' );

		echo '<div class="notice notice-info is-dismissible"><p>';
		printf(
			/* translators: %s: settings URL. */
			wp_kses_post( __( '<strong>Wren AI Dashboards</strong> needs two things before it can answer questions: a Wren AI endpoint and a deployed schema. <a href="%s">Finish the setup</a>.', 'wp-wren-dashboards' ) ),
			esc_url( $url )
		);
		echo '</p></div>';
	}

	/**
	 * Build the semantic model from the database and deploy it.
	 *
	 * @return array|WP_Error
	 */
	public static function sync_schema() {
		$mdl = WWD_Schema::build_mdl();

		if ( empty( $mdl['models'] ) ) {
			return new WP_Error( 'wwd_no_models', __( 'No tables are shared with Wren AI yet.', 'wp-wren-dashboards' ) );
		}

		$hash   = WWD_Schema::mdl_hash( $mdl );
		$client = new WWD_Wren_Client();
		$result = $client->deploy_semantics( $mdl, $hash );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		WWD_Settings::update(
			array(
				'mdl_hash'        => $hash,
				'mdl_deployed_at' => time(),
			)
		);

		WWD_Query_Runner::flush_cache();

		return array(
			'status'   => 'indexing',
			'mdl_hash' => $hash,
			'models'   => count( $mdl['models'] ),
			'columns'  => array_sum(
				array_map(
					static function ( $model ) {
						return count( $model['columns'] );
					},
					$mdl['models']
				)
			),
			'joins'    => count( $mdl['relationships'] ),
		);
	}

	/**
	 * Activation: create the log table and default settings.
	 *
	 * @return void
	 */
	public static function activate() {
		WWD_Logger::install();

		if ( ! get_option( WWD_Settings::OPTION_KEY ) ) {
			add_option( WWD_Settings::OPTION_KEY, WWD_Settings::defaults(), '', false );
		}

		WWD_Dashboards::register();

		flush_rewrite_rules();
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
