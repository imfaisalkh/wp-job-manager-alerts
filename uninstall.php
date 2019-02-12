<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

$options = array(
	'job_manager_alerts_email_template',
	'job_manager_alerts_auto_disable',
	'job_manager_alerts_matches_only',
	'job_manager_alerts_page_slug',
	'job_manager_alerts_page_id'
);

foreach ( $options as $option ) {
	delete_option( $option );
}