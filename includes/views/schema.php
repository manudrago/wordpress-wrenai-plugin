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
	<h1><?php esc_html_e( 'Data & schema', 'datachat-ai' ); ?></h1>

	<?php if ( 'resync' === $updated ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'The shared tables changed. With a Wren AI service, deploy the schema again so it sees them.', 'datachat-ai' ); ?></p></div>
	<?php elseif ( $updated ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'datachat-ai' ); ?></p></div>
	<?php endif; ?>

	<p class="wwd-lede">
		<?php esc_html_e( 'The model can only write SQL against the tables you share here, and the plugin refuses to run a query that touches anything else. Share the tables that answer real questions and nothing more.', 'datachat-ai' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wwd_save_settings">
		<input type="hidden" name="wwd_redirect" value="wwd-schema">
		<input type="hidden" name="wwd[_fields][]" value="allowed_tables">
		<?php wp_nonce_field( 'wwd_save_settings' ); ?>

		<h2 class="title"><?php esc_html_e( 'Shared tables', 'datachat-ai' ); ?></h2>

		<p class="wwd-bulk">
			<button type="button" class="button-link" data-wwd-select="all"><?php esc_html_e( 'Select all', 'datachat-ai' ); ?></button> ·
			<button type="button" class="button-link" data-wwd-select="none"><?php esc_html_e( 'Select none', 'datachat-ai' ); ?></button> ·
			<button type="button" class="button-link" data-wwd-select="core"><?php esc_html_e( 'WordPress content tables', 'datachat-ai' ); ?></button>
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
							esc_html( _n( '%d column', '%d columns', count( $wwd_columns ), 'datachat-ai' ) ),
							count( $wwd_columns )
						);
						?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>

		<h2 class="title"><?php esc_html_e( 'Never expose these columns', 'datachat-ai' ); ?></h2>
		<p>
			<textarea name="wwd[blocked_columns]" rows="2" class="large-text code"><?php echo esc_textarea( implode( ', ', (array) $settings['blocked_columns'] ) ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Comma separated column names. They are stripped from the model, rejected in generated SQL and masked in results.', 'datachat-ai' ); ?>
		</p>

		<h2 class="title"><?php esc_html_e( 'Business context', 'datachat-ai' ); ?></h2>
		<p>
			<textarea name="wwd[custom_instruction]" rows="7" class="large-text code"><?php echo esc_textarea( $settings['custom_instruction'] ); ?></textarea>
		</p>
		<p class="description">
			<?php esc_html_e( 'Sent with every question. Explain what your data means: which post types matter, which meta keys hold prices, what "active customer" means for you. This is the single biggest lever on answer quality.', 'datachat-ai' ); ?>
		</p>

		<?php submit_button( __( 'Save', 'datachat-ai' ) ); ?>
	</form>

	<?php if ( 'wren' !== $settings['engine'] ) : ?>

	<h2 class="title"><?php esc_html_e( 'What the model is told', 'datachat-ai' ); ?></h2>
	<p>
		<?php esc_html_e( 'Nothing is deployed anywhere: this description travels with every question and is as current as your last save. It reads your table structure — never your content.', 'datachat-ai' ); ?>
	</p>

	<p>
		<button type="button" class="button" id="wwd-preview-mdl"><?php esc_html_e( 'Show what is sent', 'datachat-ai' ); ?></button>
	</p>

	<pre class="wwd-mdl" id="wwd-mdl" hidden><?php echo esc_html( WWD_Schema::prompt_text() ); ?></pre>

	<?php else : ?>

	<h2 class="title"><?php esc_html_e( 'Deploy to Wren AI', 'datachat-ai' ); ?></h2>
	<p><?php esc_html_e( 'Building the model reads your table structure — never your content — and sends it to Wren AI so it can plan queries against it.', 'datachat-ai' ); ?></p>

	<p>
		<button type="button" class="button button-primary" id="wwd-sync"><?php esc_html_e( 'Build & deploy schema', 'datachat-ai' ); ?></button>
		<button type="button" class="button" id="wwd-preview-mdl"><?php esc_html_e( 'Preview the model', 'datachat-ai' ); ?></button>
	</p>

	<p class="wwd-status" id="wwd-sync-status">
		<?php if ( $settings['mdl_hash'] ) : ?>
			<?php
			printf(
				/* translators: 1: model hash, 2: time difference. */
				esc_html__( 'Current model: %1$s, deployed %2$s ago.', 'datachat-ai' ),
				esc_html( substr( $settings['mdl_hash'], 0, 8 ) ),
				esc_html( human_time_diff( (int) $settings['mdl_deployed_at'], time() ) )
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'No model deployed yet.', 'datachat-ai' ); ?>
		<?php endif; ?>
	</p>

	<pre class="wwd-mdl" id="wwd-mdl" hidden><?php echo esc_html( wp_json_encode( WWD_Schema::build_mdl(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>

	<?php endif; ?>

	<h2 class="title"><?php esc_html_e( 'Hardening', 'datachat-ai' ); ?></h2>
	<p><?php esc_html_e( 'For the strongest setup, create a MySQL user with SELECT rights only on the shared tables and add its credentials to wp-config.php:', 'datachat-ai' ); ?></p>
	<pre class="wwd-code">define( 'WWD_DB_USER', 'wp_readonly' );
define( 'WWD_DB_PASSWORD', '…' );
// Optional, they default to DB_NAME / DB_HOST:
define( 'WWD_DB_NAME', '<?php echo esc_html( $wpdb->dbname ); ?>' );
define( 'WWD_DB_HOST', '<?php echo esc_html( DB_HOST ); ?>' );</pre>
	<p class="description"><?php esc_html_e( 'With those constants set, every analytics query runs on that connection, so even a query that somehow slipped past the SQL guard could not write anything.', 'datachat-ai' ); ?></p>
</div>
