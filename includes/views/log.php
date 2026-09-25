<?php
/**
 * Query log screen.
 *
 * @package WP_Wren_Dashboards
 *
 * @var array $entries Recent log rows.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wwd-wrap">
	<h1><?php esc_html_e( 'Query log', 'datachat-ai' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wwd-inline-form">
		<input type="hidden" name="action" value="wwd_clear_log">
		<?php wp_nonce_field( 'wwd_clear_log' ); ?>
		<button type="submit" class="button"><?php esc_html_e( 'Clear log', 'datachat-ai' ); ?></button>
	</form>

	<?php if ( empty( $entries ) ) : ?>
		<p><?php esc_html_e( 'Nothing logged yet.', 'datachat-ai' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'datachat-ai' ); ?></th>
					<th><?php esc_html_e( 'User', 'datachat-ai' ); ?></th>
					<th><?php esc_html_e( 'Question', 'datachat-ai' ); ?></th>
					<th><?php esc_html_e( 'Statement', 'datachat-ai' ); ?></th>
					<th><?php esc_html_e( 'Result', 'datachat-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $wwd_entry ) : ?>
					<?php $wwd_user = get_userdata( (int) $wwd_entry['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $wwd_entry['created_at'] ); ?></td>
						<td><?php echo esc_html( $wwd_user ? $wwd_user->user_login : __( 'guest', 'datachat-ai' ) ); ?></td>
						<td><?php echo esc_html( $wwd_entry['question'] ); ?></td>
						<td><code class="wwd-sql"><?php echo esc_html( $wwd_entry['sql_text'] ); ?></code></td>
						<td>
							<?php if ( 'ok' === $wwd_entry['status'] ) : ?>
								<span class="wwd-pill wwd-pill--ok">
									<?php
									printf(
										/* translators: 1: row count, 2: duration in milliseconds. */
										esc_html__( '%1$d rows · %2$d ms', 'datachat-ai' ),
										(int) $wwd_entry['rows_returned'],
										(int) $wwd_entry['duration_ms']
									);
									?>
								</span>
							<?php else : ?>
								<span class="wwd-pill wwd-pill--bad"><?php echo esc_html( $wwd_entry['error'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
