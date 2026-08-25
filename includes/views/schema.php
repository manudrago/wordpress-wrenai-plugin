<?php
/**
 * Data & schema screen: choose what Wren AI is allowed to see, then deploy it.
 *
 * @package WP_Wren_Dashboards
 *
 * @var array  $settings Current settings.
 * @var array  $tables   All tables in the database.
 * @var string $updated  Update flag from the redirect.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$wwd_allowed = (array) $settings['allowed_tables'];
$wwd_core    = WWD_Settings::default_tables();
?>
<div class="wrap wwd-wrap">
	<h1><?php esc_html_e( 'Data & schema', 'wp-wren-dashboards' ); ?></h1>

	<?php if ( 'resync' === $updated ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The shared tables changed. Deploy the schema again so Wren AI sees the new model.', 'wp-wren-dashboards' ); ?></p></div>
	<?php elseif ( $updated ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'wp-wren-dashboards' ); ?></p></div>
	<?php endif; ?>

	<p class="wwd-lede">
		<?php esc_html_e( 'Wren AI can only write SQL against the tables you share here, and the plugin refuses to run a query that touches anything else. Share the tables that answer real questions and nothing more.', 'wp-wren-dashboards' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wwd_save_settings">
		<input type="hidden" name="wwd_redirect" value="wwd-schema">
		<input type="hidden" name="wwd[_fields][]" value="allowed_tables">
		<?php wp_nonce_field( 'wwd_save_settings' ); ?>

		<h2 class="title"><?php esc_html_e( 'Shared tables', 'wp-wren-dashboards' ); ?></h2>

		<p class="wwd-bulk">
			<button type="button" class="button-link" data-wwd-select="all"><?php esc_html_e( 'Select all', 'wp-wren-dashboards' ); ?></button> ·
			<button type="button" class="button-link" data-wwd-select="none"><?php esc_html_e( 'Select none', 'wp-wren-dashboards' ); ?></button> ·
			<button type="button" class="button-link" data-wwd-select="core"><?php esc_html_e( 'WordPress content tables', 'wp-wren-dashboards' ); ?></button>
		</p>

		<div class="wwd-tables">
			<?php foreach ( $tables as $wwd_table ) : ?>
				<?php $wwd_columns = WWD_Schema::columns( $wwd_table ); ?>
				<label class="wwd-table-pick<?php echo in_array( $wwd_table, $wwd_core, true ) ? ' is-core' : ''; ?>">
					<input type="checkbox" name="wwd[allowed_tables][]" value="<?php echo esc_attr( $wwd_table ); ?>"
						<?php checked( in_array( $wwd_table, $wwd_allowed, true ) ); ?>>
					<span class="wwd-table-pick__name"><?php echo esc_html( $wwd_table ); ?></span>
					<span class="wwd-table-pick__meta">
						<?php
						printf(
							/* translators: %d: number of columns. */
							esc_html( _n( '%d column', '%d columns', count( $wwd_columns ), 'wp-wren-dashboards' ) ),
							count( $wwd_columns )
						);
						?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>

		<h2 class="title"><?php esc_html_e( 'Never expose these columns', 'wp-wren-dashboards' ); ?></h2>
		<p>
			<textarea name="wwd[blocked_columns]" rows="2" class="large-text code"><?php echo esc_textarea( implode( ', ', (array) $settings['blocked_columns'] ) ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Comma separated column names. They are stripped from the model, rejected in generated SQL and masked in results.', 'wp-wren-dashboards' ); ?>
		</p>

		<h2 class="title"><?php esc_html_e( 'Business context', 'wp-wren-dashboards' ); ?></h2>
		<p>
			<textarea name="wwd[custom_instruction]" rows="7" class="large-text code"><?php echo esc_textarea( $settings['custom_instruction'] ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Sent with every question. Explain what your data means: which post types matter, which meta keys hold prices, what "active customer" means for you. This is the single biggest lever on answer quality.', 'wp-wren-dashboards' ); ?>
		</p>

		<?php submit_button( __( 'Save', 'wp-wren-dashboards' ) ); ?>
	</form>

	<h2 class="title"><?php esc_html_e( 'Deploy to Wren AI', 'wp-wren-dashboards' ); ?></h2>
	<p><?php esc_html_e( 'Building the model reads your table structure — never your content — and sends it to Wren AI so it can plan queries against it.', 'wp-wren-dashboards' ); ?></p>

	<p>
		<button type="button" class="button button-primary" id="wwd-sync"><?php esc_html_e( 'Build & deploy schema', 'wp-wren-dashboards' ); ?></button>
		<button type="button" class="button" id="wwd-preview-mdl"><?php esc_html_e( 'Preview the model', 'wp-wren-dashboards' ); ?></button>
	</p>

	<p class="wwd-status" id="wwd-sync-status">
		<?php if ( $settings['mdl_hash'] ) : ?>
			<?php
			printf(
				/* translators: 1: model hash, 2: time difference. */
				esc_html__( 'Current model: %1$s, deployed %2$s ago.', 'wp-wren-dashboards' ),
				esc_html( substr( $settings['mdl_hash'], 0, 8 ) ),
				esc_html( human_time_diff( (int) $settings['mdl_deployed_at'], time() ) )
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'No model deployed yet.', 'wp-wren-dashboards' ); ?>
		<?php endif; ?>
	</p>

	<pre class="wwd-mdl" id="wwd-mdl" hidden><?php echo esc_html( wp_json_encode( WWD_Schema::build_mdl(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>

	<h2 class="title"><?php esc_html_e( 'Hardening', 'wp-wren-dashboards' ); ?></h2>
	<p><?php esc_html_e( 'For the strongest setup, create a MySQL user with SELECT rights only on the shared tables and add its credentials to wp-config.php:', 'wp-wren-dashboards' ); ?></p>
	<pre class="wwd-code">define( 'WWD_DB_USER', 'wp_readonly' );
define( 'WWD_DB_PASSWORD', '…' );
// Optional, they default to DB_NAME / DB_HOST:
define( 'WWD_DB_NAME', '<?php echo esc_html( $wpdb->dbname ); ?>' );
define( 'WWD_DB_HOST', '<?php echo esc_html( DB_HOST ); ?>' );</pre>
	<p class="description"><?php esc_html_e( 'With those constants set, every analytics query runs on that connection, so even a query that somehow slipped past the SQL guard could not write anything.', 'wp-wren-dashboards' ); ?></p>
</div>
