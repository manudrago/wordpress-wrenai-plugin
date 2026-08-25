<?php
/**
 * Settings screen.
 *
 * @package WP_Wren_Dashboards
 *
 * @var array  $settings Current settings.
 * @var string $updated  Update flag from the redirect.
 */

defined( 'ABSPATH' ) || exit;

$wwd_capabilities = array( 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'edit_pages', 'manage_options' );
?>
<div class="wrap wwd-wrap">
	<h1><?php esc_html_e( 'Wren AI Dashboards', 'wp-wren-dashboards' ); ?></h1>

	<?php if ( 'cache' === $updated ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cached results cleared.', 'wp-wren-dashboards' ); ?></p></div>
	<?php elseif ( $updated ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wp-wren-dashboards' ); ?></p></div>
	<?php endif; ?>

	<div class="wwd-status-card">
		<div>
			<strong><?php esc_html_e( 'Connection', 'wp-wren-dashboards' ); ?></strong>
			<p class="wwd-status" id="wwd-health"><?php esc_html_e( 'Not checked yet.', 'wp-wren-dashboards' ); ?></p>
		</div>
		<div>
			<strong><?php esc_html_e( 'Semantic model', 'wp-wren-dashboards' ); ?></strong>
			<p class="wwd-status">
				<?php if ( $settings['mdl_hash'] ) : ?>
					<?php
					printf(
						/* translators: 1: model hash, 2: human readable time difference. */
						esc_html__( 'Deployed (%1$s), %2$s ago', 'wp-wren-dashboards' ),
						esc_html( substr( $settings['mdl_hash'], 0, 8 ) ),
						esc_html( human_time_diff( (int) $settings['mdl_deployed_at'], time() ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Not deployed yet.', 'wp-wren-dashboards' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<div>
			<strong><?php esc_html_e( 'Database access', 'wp-wren-dashboards' ); ?></strong>
			<p class="wwd-status">
				<?php if ( WWD_Query_Runner::has_dedicated_connection() ) : ?>
					<?php esc_html_e( 'Dedicated connection (WWD_DB_USER)', 'wp-wren-dashboards' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'WordPress connection — a read-only MySQL user is recommended', 'wp-wren-dashboards' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<div class="wwd-status-card__actions">
			<button type="button" class="button" id="wwd-check-health"><?php esc_html_e( 'Test connection', 'wp-wren-dashboards' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wwd-schema' ) ); ?>"><?php esc_html_e( 'Data & schema', 'wp-wren-dashboards' ); ?></a>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wwd_save_settings">
		<input type="hidden" name="wwd_redirect" value="wwd">
		<input type="hidden" name="wwd[_fields][]" value="allow_public">
		<input type="hidden" name="wwd[_fields][]" value="show_sql">
		<input type="hidden" name="wwd[_fields][]" value="log_queries">
		<?php wp_nonce_field( 'wwd_save_settings' ); ?>

		<h2 class="title"><?php esc_html_e( 'Wren AI service', 'wp-wren-dashboards' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wwd-endpoint"><?php esc_html_e( 'Endpoint', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[endpoint]" id="wwd-endpoint" type="url" class="regular-text code"
						value="<?php echo esc_attr( $settings['endpoint'] ); ?>" placeholder="http://localhost:5555">
					<p class="description">
						<?php esc_html_e( 'Base URL of the Wren AI service (wren-ai-service). Self-hosted default: http://localhost:5555.', 'wp-wren-dashboards' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-prefix"><?php esc_html_e( 'API prefix', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[api_prefix]" id="wwd-prefix" type="text" class="small-text code"
						value="<?php echo esc_attr( $settings['api_prefix'] ); ?>">
					<p class="description"><?php esc_html_e( 'Usually /v1. Use /api/v1 for Wren AI Cloud.', 'wp-wren-dashboards' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-key"><?php esc_html_e( 'API key', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[api_key]" id="wwd-key" type="password" class="regular-text code" autocomplete="off"
						value="<?php echo esc_attr( $settings['api_key'] ); ?>">
					<p class="description"><?php esc_html_e( 'Sent as a Bearer token. Leave empty for a local service without authentication.', 'wp-wren-dashboards' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-project"><?php esc_html_e( 'Project id', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[project_id]" id="wwd-project" type="text" class="regular-text code"
						value="<?php echo esc_attr( $settings['project_id'] ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. Keeps this site\'s model separate when several projects share one Wren AI instance.', 'wp-wren-dashboards' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-timeout"><?php esc_html_e( 'Request timeout', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[request_timeout]" id="wwd-timeout" type="number" min="5" max="120" class="small-text"
						value="<?php echo esc_attr( $settings['request_timeout'] ); ?>">
					<span><?php esc_html_e( 'seconds', 'wp-wren-dashboards' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-language"><?php esc_html_e( 'Answer language', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[language]" id="wwd-language" type="text" class="regular-text"
						value="<?php echo esc_attr( $settings['language'] ); ?>" placeholder="<?php echo esc_attr( WWD_Settings::language() ); ?>">
					<p class="description"><?php esc_html_e( 'Language of chart titles and explanations. Empty follows the site locale.', 'wp-wren-dashboards' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Who can ask', 'wp-wren-dashboards' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wwd-ask-cap"><?php esc_html_e( 'Capability to ask questions', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<select name="wwd[ask_capability]" id="wwd-ask-cap">
						<?php foreach ( $wwd_capabilities as $wwd_cap ) : ?>
							<option value="<?php echo esc_attr( $wwd_cap ); ?>" <?php selected( $settings['ask_capability'], $wwd_cap ); ?>><?php echo esc_html( $wwd_cap ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-save-cap"><?php esc_html_e( 'Capability to save panels', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<select name="wwd[save_capability]" id="wwd-save-cap">
						<?php foreach ( $wwd_capabilities as $wwd_cap ) : ?>
							<option value="<?php echo esc_attr( $wwd_cap ); ?>" <?php selected( $settings['save_capability'], $wwd_cap ); ?>><?php echo esc_html( $wwd_cap ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Public access', 'wp-wren-dashboards' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="wwd[allow_public]" value="1" <?php checked( $settings['allow_public'] ); ?>>
						<?php esc_html_e( 'Let logged-out visitors ask questions', 'wp-wren-dashboards' ); ?>
					</label>
					<p class="description wwd-warning">
						<?php esc_html_e( 'Only enable this if every shared table is safe to expose publicly: visitors will be able to query them in aggregate.', 'wp-wren-dashboards' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show SQL', 'wp-wren-dashboards' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="wwd[show_sql]" value="1" <?php checked( $settings['show_sql'] ); ?>>
						<?php esc_html_e( 'Show the generated SQL under each answer', 'wp-wren-dashboards' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Limits', 'wp-wren-dashboards' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wwd-max-rows"><?php esc_html_e( 'Maximum rows per query', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[max_rows]" id="wwd-max-rows" type="number" min="10" max="20000" class="small-text"
						value="<?php echo esc_attr( $settings['max_rows'] ); ?>">
					<p class="description"><?php esc_html_e( 'A LIMIT is appended to every generated query.', 'wp-wren-dashboards' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-cache"><?php esc_html_e( 'Cache results for', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[cache_ttl]" id="wwd-cache" type="number" min="0" max="86400" class="small-text"
						value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>">
					<span><?php esc_html_e( 'seconds (0 disables caching)', 'wp-wren-dashboards' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wwd-rate"><?php esc_html_e( 'Questions per minute per user', 'wp-wren-dashboards' ); ?></label></th>
				<td>
					<input name="wwd[rate_limit]" id="wwd-rate" type="number" min="1" max="500" class="small-text"
						value="<?php echo esc_attr( $settings['rate_limit'] ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Query log', 'wp-wren-dashboards' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="wwd[log_queries]" value="1" <?php checked( $settings['log_queries'] ); ?>>
						<?php esc_html_e( 'Record every question and statement', 'wp-wren-dashboards' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2 class="title"><?php esc_html_e( 'Shortcodes', 'wp-wren-dashboards' ); ?></h2>
	<p><?php esc_html_e( 'Put the ask form on any page:', 'wp-wren-dashboards' ); ?></p>
	<p><code>[wren_ai_dashboard]</code></p>
	<p><?php esc_html_e( 'With a target dashboard, a title and your own example questions:', 'wp-wren-dashboards' ); ?></p>
	<p><code>[wren_ai_dashboard dashboard="12" title="Ask the data" examples="Sales this month|Top authors"]</code></p>
	<p><?php esc_html_e( 'Render a saved dashboard:', 'wp-wren-dashboards' ); ?></p>
	<p><code>[wren_dashboard id="12" refresh="120"]</code></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwd-inline-form">
		<input type="hidden" name="action" value="wwd_flush_cache">
		<?php wp_nonce_field( 'wwd_flush_cache' ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Clear cached results', 'wp-wren-dashboards' ); ?></button>
	</form>
</div>
