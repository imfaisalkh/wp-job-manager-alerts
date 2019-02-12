<?php
/**
 * Lists job listing alerts for the `[job_alerts]` shortcode.
 *
 * This template can be overridden by copying it to yourtheme/wp-job-manager-alerts/my-alerts.php.
 *
 * @see         https://wpjobmanager.com/document/template-overrides/
 * @author      Automattic
 * @package     WP Job Manager - Alerts
 * @category    Template
 * @version     1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="job-manager-alerts">
	<p><?php printf( __( 'Your job alerts are shown in the table below and will be emailed to %s.', 'wp-job-manager-alerts' ), $user->user_email ); ?></p>
	<table class="job-manager-alerts">
		<thead>
			<tr>
				<th><?php _e( 'Alert Name', 'wp-job-manager-alerts' ); ?></th>
				<th><?php _e( 'Keywords', 'wp-job-manager-alerts' ); ?></th>
				<?php if ( get_option( 'job_manager_enable_categories' ) && wp_count_terms( 'job_listing_category' ) > 0 ) : ?>
					<th><?php _e( 'Categories', 'wp-job-manager-alerts' ); ?></th>
				<?php endif; ?>
				<?php if ( taxonomy_exists( 'job_listing_tag' ) ) : ?>
					<th><?php _e( 'Tags', 'wp-job-manager-alerts' ); ?></th>
				<?php endif; ?>
				<th><?php _e( 'Location', 'wp-job-manager-alerts' ); ?></th>
				<th><?php _e( 'Frequency', 'wp-job-manager-alerts' ); ?></th>
			</tr>
		</thead>
		<tfoot>
			<tr>
				<td colspan="6">
					<a href="<?php echo remove_query_arg( 'updated', add_query_arg( 'action', 'add_alert' ) ); ?>"><?php _e( 'Add alert', 'wp-job-manager-alerts' ); ?></a>
				</td>
			</tr>
		</tfoot>
		<tbody>
			<?php foreach ( $alerts as $alert ) : ?>
				<?php
				$search_terms = WP_Job_Manager_Alerts_Post_Types::get_alert_search_terms( $alert->ID );
				?>
				<tr class="alert-<?php echo $alert->post_status === 'draft' ? 'disabled' : 'enabled'; ?>">
					<td>
						<?php echo esc_html( $alert->post_title ); ?>
						<ul class="job-alert-actions">
							<?php
								$actions = apply_filters( 'job_manager_alert_actions', array(
									'view' => array(
										'label' => __( 'Results', 'wp-job-manager-alerts' ),
										'nonce' => false
									),
									'email' => array(
										'label' => __( 'Send&nbsp;Now', 'wp-job-manager-alerts' ),
										'nonce' => true
									),
									'edit' => array(
										'label' => __( 'Edit', 'wp-job-manager-alerts' ),
										'nonce' => false
									),
									'toggle_status' => array(
										'label' => $alert->post_status == 'draft' ? __( 'Enable', 'wp-job-manager-alerts' ) : __( 'Disable', 'wp-job-manager-alerts' ),
										'nonce' => true
									),
									'delete' => array(
										'label' => __( 'Delete', 'wp-job-manager-alerts' ),
										'nonce' => true
									)
								), $alert );

								foreach ( $actions as $action => $value ) {
									$action_url = remove_query_arg( 'updated', add_query_arg( array( 'action' => $action, 'alert_id' => $alert->ID ) ) );

									if ( $value['nonce'] )
										$action_url = wp_nonce_url( $action_url, 'job_manager_alert_actions' );

									echo '<li><a href="' . $action_url . '" class="job-alerts-action-' . $action . '">' . $value['label'] . '</a></li>';
								}
							?>
						</ul>
					</td>
					<td class="alert_keyword"><?php
						if ( $value = get_post_meta( $alert->ID, 'alert_keyword', true ) )
							echo esc_html( $value );
						else
							echo '&ndash;';
					?></td>
					<?php if ( get_option( 'job_manager_enable_categories' ) && wp_count_terms( 'job_listing_category' ) > 0 ) : ?>
						<td class="alert_category"><?php
							$term_ids = ! empty( $search_terms['categories'] ) ? $search_terms['categories'] : array();
							$terms = array();
							if ( ! empty( $term_ids ) ) {
								$terms = get_terms( array(
									'taxonomy'         => 'job_listing_category',
									'fields'           => 'names',
									'include'          => $term_ids,
									'hide_empty'       => false,
								) );
							}
							echo $terms ? esc_html( implode( ', ', $terms ) ) : '&ndash;';
						?></td>
					<?php endif; ?>
					<?php if ( taxonomy_exists( 'job_listing_tag' ) ) : ?>
						<td class="alert_tag"><?php
							$term_ids = ! empty( $search_terms['tags'] ) ? $search_terms['tags'] : array();
							$terms = array();
							if ( ! empty( $term_ids ) ) {
								$terms = get_terms( array(
									'taxonomy'         => 'job_listing_tag',
									'fields'           => 'names',
									'include'          => $term_ids,
									'hide_empty'       => false,
								) );
							}
							echo $terms ? esc_html( implode( ', ', $terms ) ) : '&ndash;';
						?></td>
					<?php endif; ?>
					<td class="alert_location"><?php
						if ( taxonomy_exists( 'job_listing_region' ) && wp_count_terms( 'job_listing_region' ) > 0 ) {
							$term_ids = ! empty( $search_terms['regions'] ) ? $search_terms['regions'] : array();
							$terms = array();
							if ( ! empty( $term_ids ) ) {
								$terms = get_terms( array(
									'taxonomy'         => 'job_listing_region',
									'fields'           => 'names',
									'include'          => $term_ids,
									'hide_empty'       => false,
								) );
							}
							echo $terms ? esc_html( implode( ', ', $terms ) ) : '&ndash;';
						} else {
							$value = get_post_meta( $alert->ID, 'alert_location', true );
							echo $value ? esc_html( $value ) : '&ndash;';
						}
					?></td>
					<td class="alert_frequency"><?php
						$schedules = WP_Job_Manager_Alerts_Notifier::get_alert_schedules();
						$freq      = get_post_meta( $alert->ID, 'alert_frequency', true );

						if ( ! empty( $schedules[ $freq ] ) ) {
							echo esc_html( $schedules[ $freq ]['display'] );
						}

						echo '<small>' . sprintf( __( 'Next: %s at %s', 'wp-job-manager-alerts' ), date_i18n( get_option( 'date_format' ), wp_next_scheduled( 'job-manager-alert', array( $alert->ID ) ) ),  date_i18n( get_option( 'time_format' ), wp_next_scheduled( 'job-manager-alert', array( $alert->ID ) ) ) ) . '</small>';
					?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
