<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * WP_Job_Manager_Alerts_Notifier class.
 */
class WP_Job_Manager_Alerts_Notifier {

	/**
	 * Store current alert frequency for queries
	 * @var string
	 */
	private static $current_alert_frequency = 'daily';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'job-manager-alert', array( $this, 'job_manager_alert' ), 10, 2 );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
	}

	/**
	 * Get alert schedules
	 * @return array
	 */
	public static function get_alert_schedules() {
		$schedules = array();

		$schedules['daily'] = array(
			'interval' => 86400,
			'display'  => __( 'Daily', 'wp-job-manager-alerts' )
	 	);

		$schedules['weekly'] = array(
			'interval' => 604800,
			'display'  => __( 'Weekly', 'wp-job-manager-alerts' )
	 	);

	 	$schedules['fortnightly'] = array(
			'interval' => 604800 * 2,
			'display'  => __( 'Fortnightly', 'wp-job-manager-alerts' )
	 	);

		return apply_filters( 'job_manager_alerts_alert_schedules', $schedules );
	}

	/**
	 * Add custom cron schedules
	 * @param array $schedules
	 * @return array
	 */
	public function add_cron_schedules( array $schedules ) {
		return array_merge( $schedules, $this->get_alert_schedules() );
	}

	/**
	 * Send an alert
	 */
	public function job_manager_alert( $alert_id, $force = false ) {
		$alert = get_post( $alert_id );

		if ( ! $alert || $alert->post_type !== 'job_alert' ) {
			return;
		}

		if ( $alert->post_status !== 'publish' && ! $force ) {
			return;
		}

		$user  = get_user_by( 'id', $alert->post_author );
		$jobs  = $this->get_matching_jobs( $alert, $force );

		if ( $jobs->found_posts || ! get_option( 'job_manager_alerts_matches_only' ) ) {

			$email = $this->format_email( $alert, $user, $jobs );

			add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
			add_filter( 'wp_mail_from', array( $this, 'mail_from_email' ) );

			if ( $email ) {
				wp_mail( $user->user_email, apply_filters( 'job_manager_alerts_subject', sprintf( __( 'Job Alert Results Matching "%s"', 'wp-job-manager-alerts' ), $alert->post_title ), $alert ), $email );
			}

			remove_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
			remove_filter( 'wp_mail_from', array( $this, 'mail_from_email' ) );
		}

		if ( ( $days_to_disable = get_option( 'job_manager_alerts_auto_disable' ) ) > 0 ) {
			$days = ( strtotime( 'NOW' ) - strtotime( $alert->post_modified ) ) / ( 60 * 60 * 24 );

			if ( $days > $days_to_disable ) {
				$update_alert = array();
				$update_alert['ID'] = $alert->ID;
				$update_alert['post_status'] = 'draft';
				wp_update_post( $update_alert );
				wp_clear_scheduled_hook( 'job-manager-alert', array( $alert->ID ) );
				return;
			}
		}

		// Inc sent count
		update_post_meta( $alert->ID, 'send_count', 1 + absint( get_post_meta( $alert->ID, 'send_count', true ) ) );
	}

	/**
	 * Match jobs to an alert
	 */
	public static function get_matching_jobs( $alert, $force ) {
		self::$current_alert_frequency = $alert->alert_frequency;

		if ( ! $force ) {
			add_filter( 'posts_where', array( __CLASS__, 'filter_alert_frequency' ) );
		}
		$search_terms = WP_Job_Manager_Alerts_Post_Types::get_alert_search_terms( $alert->ID );
		$search_job_types = array();
		if ( ! empty( $search_terms['types'] ) ) {
			$search_job_types = get_terms( array(
				'taxonomy'         => 'job_listing_type',
				'fields'           => 'slugs',
				'include'          => array_map( 'absint', $search_terms['types'] ),
				'hide_empty'       => false,
			) );
		}
		$search_job_tags = array();
		if ( ! empty( $search_terms['tags'] ) ) {
			$search_job_tags = get_terms( array(
				'taxonomy'         => 'job_listing_tag',
				'fields'           => 'slugs',
				'include'          => array_map( 'absint', $search_terms['tags'] ),
				'hide_empty'       => false,
			) );
		}
		$jobs    = get_job_listings( apply_filters( 'job_manager_alerts_get_job_listings_args', array(
			'search_location'   => $alert->alert_location,
			'search_keywords'   => $alert->alert_keyword,
			'search_categories' => ! empty( $search_terms['categories'] ) ? array_map( 'absint', $search_terms['categories'] ) : '',
			'search_region'     => ! empty( $search_terms['regions'] )    ? array_map( 'absint', $search_terms['regions'] ) : array(),
			'search_tags'       => ! empty( $search_job_tags )            ? $search_job_tags : array(),
			'job_types'         => ! empty( $search_job_types )           ? $search_job_types : '',
			'orderby'           => 'date',
			'order'             => 'desc',
			'offset'            => 0,
			'posts_per_page'    => 50,
		), $alert ) );

		remove_filter( 'posts_where', array( __CLASS__, 'filter_alert_frequency' ) );

		return $jobs;
	}

	/**
	 * Filter posts from the last day
	 */
	public static function filter_alert_frequency( $where = '' ) {
		$schedules = WP_Job_Manager_Alerts_Notifier::get_alert_schedules();

		if ( ! empty( $schedules[ self::$current_alert_frequency ] ) ) {
			$interval = $schedules[ self::$current_alert_frequency ]['interval'];
		} else {
			$interval = 86400;
		}

		$where .= " AND post_date >= '" . date( 'Y-m-d', strtotime( '-' . absint( $interval ) . ' seconds' ) ) . "' ";
		return $where;
	}

	/**
	 * Format the email
	 */
	public function format_email( $alert, $user, $jobs ) {

		$template = get_option( 'job_manager_alerts_email_template' );

		if ( ! $template ) {
			$template = $GLOBALS['job_manager_alerts']->get_default_email();
		}

		if ( $jobs && $jobs->have_posts() ) {
			ob_start();

			while ( $jobs->have_posts() ) {
				$jobs->the_post();

				get_job_manager_template( 'content-email_job_listing.php', array(), 'wp-job-manager-alerts', JOB_MANAGER_ALERTS_PLUGIN_DIR . '/templates/' );
			}

			wp_reset_postdata();
			$job_content = ob_get_clean();
		} else {
			$job_content = __( 'No jobs were found matching your search. Login to your account to change your alert criteria', 'wp-job-manager-alerts' );
		}

		// Reschedule next alert
		$schedules = WP_Job_Manager_Alerts_Notifier::get_alert_schedules();

		if ( ! empty( $schedules[ $alert->alert_frequency ] ) ) {
			$next = strtotime( '+' . $schedules[ $alert->alert_frequency ]['interval'] . ' seconds' );
		} else {
			$next = strtotime( '+1 day' );
		}

		if ( get_option( 'job_manager_alerts_auto_disable' ) > 0 ) {
			$alert_expirey = sprintf( __( 'This job alert will automatically stop sending after %s.', 'wp-job-manager-alerts' ), date_i18n( get_option( 'date_format' ), strtotime( '+' . absint( get_option( 'job_manager_alerts_auto_disable' ) ) . ' days', strtotime( $alert->post_modified ) ) ) );
		} else {
			$alert_expirey = '';
		}

		$replacements = array(
			'{display_name}'    => $user->display_name,
			'{alert_name}'      => $alert->post_title,
			'{alert_expirey}'   => $alert_expirey,
			'{alert_next_date}' => date_i18n( get_option( 'date_format' ), $next ),
			'{alert_page_url}'  => get_permalink( get_option( 'job_manager_alerts_page_id' ) ),
			'{jobs}'            => $job_content
		);

		$template = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

		return apply_filters( 'job_manager_alerts_template', $template, $alert, $user, $jobs );
	}

	/**
	 * From name
	 */
	public function mail_from_name( $name ) {
	    return apply_filters( 'job_manager_alerts_mail_from_name', html_entity_decode( get_bloginfo( 'name' ) ) );
	}

	/**
	 * From Email
	 */
	public function mail_from_email( $email ) {
		return apply_filters( 'job_manager_alerts_mail_from_email', sanitize_email( 'noreply@' . str_replace( array( 'http://', 'https://', 'www.' ), '', site_url( '' ) ) ) );
	}
}

new WP_Job_Manager_Alerts_Notifier();
