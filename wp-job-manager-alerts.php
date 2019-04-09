<?php
/**
 * Plugin Name: WP Job Manager - Alerts
 * Plugin URI: https://wpjobmanager.com/add-ons/job-alerts/
 * Description: Allow users to subscribe to job alerts for their searches. Once registered, users can access a 'My Alerts' page which you can create with the shortcode [job_alerts].
 * Version: 1.5.2
 * Author: Automattic
 * Author URI: https://wpjobmanager.com
 * Requires at least: 4.1
 * Tested up to: 5.1
 *
 * WPJM-Product: wp-job-manager-alerts
 *
 * Copyright: 2017 Automattic
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_Job_Manager_Alerts class.
 */
class WP_Job_Manager_Alerts {
	const JOB_MANAGER_CORE_MIN_VERSION = '1.29.0';

	/**
	 * __construct function.
	 */
	public function __construct() {
		// Define constants
		define( 'JOB_MANAGER_ALERTS_VERSION', '1.5.2' );
		define( 'JOB_MANAGER_ALERTS_PLUGIN_DIR', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
		define( 'JOB_MANAGER_ALERTS_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );

		// Set up startup actions
		add_action( 'plugins_loaded', array( $this, 'load_text_domain' ), 12 );
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ), 13 );
		add_action( 'admin_notices', array( $this, 'version_check' ) );
	}

	/**
	 * Initializes plugin.
	 */
	public function init_plugin() {
		if ( ! class_exists( 'WP_Job_Manager' ) ) {
			return;
		}

		// Includes
		include( 'includes/class-wp-job-manager-alerts-shortcodes.php' );
		include( 'includes/class-wp-job-manager-alerts-post-types.php' );
		include( 'includes/class-wp-job-manager-alerts-notifier.php' );

		// Init classes
		$this->post_types = new WP_Job_Manager_Alerts_Post_Types();

		// Add actions
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );
		add_filter( 'job_manager_enhanced_select_enabled', array( $this, 'use_enhanced_select' ) );
		add_filter( 'job_manager_enqueue_frontend_style', array( $this, 'use_wpjm_core_frontend_style' ) );
		add_filter( 'job_manager_settings', array( $this, 'settings' ) );
		add_filter( 'job_manager_job_filters_showing_jobs_links', array( $this, 'alert_link' ), 10, 2 );
		add_action( 'single_job_listing_end', array( $this, 'single_alert_link' ) );
		add_action( 'job-manager-alert-check-reschedule', array( $this, 'check_reschedule_events' ) );
		if ( false === wp_next_scheduled( 'job-manager-alert-check-reschedule' ) ) {
			wp_schedule_event( time(), 'daily', 'job-manager-alert-check-reschedule' );
		}

		// Update legacy options
		if ( false === get_option( 'job_manager_alerts_page_id', false ) && get_option( 'job_manager_alerts_page_slug' ) ) {
			$page_id = get_page_by_path( get_option( 'job_manager_alerts_page_slug' ) )->ID;
			update_option( 'job_manager_alerts_page_id', $page_id );
		}
	}

	/**
	 * Checks alerts for their corresponding scheduled event and reschedules if missing.
	 */
	public function check_reschedule_events() {
		$alert_posts = new WP_Query( array(
			'post_type' => 'job_alert',
			'posts_per_page' => -1,
			'post_status' => 'publish'
		) );

		$schedules = WP_Job_Manager_Alerts_Notifier::get_alert_schedules();
		foreach ( $alert_posts->posts as $post ) {
			if ( false === wp_next_scheduled( 'job-manager-alert', array( $post->ID ) ) ) {
				$alert_frequency = get_post_meta( $post->ID, 'alert_frequency', true );

				$next = strtotime( '+1 day' );
				if ( ! empty( $schedules[ $alert_frequency ] ) ) {
					$next = strtotime( '+' . $schedules[ $alert_frequency ]['interval'] . ' seconds' );
				}

				// Use the created time to distribute the events again, starting tomorrow.
				$created = strtotime( $post->post_date );
				$next = strtotime( date( 'Y-m-d', strtotime( '+1 day' ) ) . ' ' . date( 'G:i:s', $created ) );

				wp_schedule_event( $next, $alert_frequency, 'job-manager-alert', array( $post->ID ) );
			}
		}
	}

	/**
	 * Localisation
	 *
	 * @access private
	 * @return void
	 */
	public function load_text_domain() {
		$locale = apply_filters( 'plugin_locale', get_locale(), 'wp-job-manager-alerts' );
		load_textdomain( 'wp-job-manager-alerts', WP_LANG_DIR . "/wp-job-manager-alerts/wp-job-manager-alerts-$locale.mo" );
		load_plugin_textdomain( 'wp-job-manager-alerts', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Checks WPJM core version.
	 */
	public function version_check() {
		if ( ! class_exists( 'WP_Job_Manager' ) || ! defined( 'JOB_MANAGER_VERSION' ) ) {
			$screen = get_current_screen();
			if ( null !== $screen && 'plugins' === $screen->id ) {
				$this->display_error( __( '<em>WP Job Manager - Alerts</em> requires WP Job Manager to be installed and activated.', 'wp-job-manager-alerts' ) );
			}
		} elseif (
			/**
			 * Filters if WPJM core's version should be checked.
			 *
			 * @since 1.5.0
			 *
			 * @param bool   $do_check                       True if the add-on should do a core version check.
			 * @param string $minimum_required_core_version  Minimum version the plugin is reporting it requires.
			 */
			apply_filters( 'job_manager_addon_core_version_check', true, self::JOB_MANAGER_CORE_MIN_VERSION )
			&& version_compare( JOB_MANAGER_VERSION, self::JOB_MANAGER_CORE_MIN_VERSION, '<' )
		) {
			$this->display_error( sprintf( __( '<em>WP Job Manager - Alerts</em> requires WP Job Manager %s (you are using %s).', 'wp-job-manager-alerts' ), self::JOB_MANAGER_CORE_MIN_VERSION, JOB_MANAGER_VERSION ) );
		}
	}

	/**
	 * Display error message notice in the admin.
	 *
	 * @param string $message
	 */
	private function display_error( $message ) {
		echo '<div class="error">';
		echo '<p>' . $message . '</p>';
		echo '</div>';
	}

	/**
	 * frontend_scripts function.
	 *
	 * @access public
	 * @return void
	 */
	public function frontend_scripts() {
		wp_register_script( 'job-alerts', JOB_MANAGER_ALERTS_PLUGIN_URL . '/assets/js/job-alerts.min.js', array( 'jquery', 'select2' ), JOB_MANAGER_ALERTS_VERSION, true );

		wp_localize_script( 'job-alerts', 'job_manager_alerts', array(
			'i18n_confirm_delete' => __( 'Are you sure you want to delete this alert?', 'wp-job-manager-alerts' ),
			'is_rtl' => is_rtl(),
		) );

		wp_enqueue_style( 'job-alerts-frontend', JOB_MANAGER_ALERTS_PLUGIN_URL . '/assets/css/frontend.css' );
	}

	/**
	 * Check if we should have WPJM core enqueue enhanced select.
	 *
	 * @param bool $use_enhanced_select True if we should have WPJM core use enhanced select.
	 * @return bool
	 */
	public function use_enhanced_select( $use_enhanced_select ) {
		if ( $this->is_shortcode_page() ) {
			return true;
		}
		return $use_enhanced_select;
	}

	/**
	 * Check if we should have WPJM core enqueue its frontend styles.
	 *
	 * @param bool $use_frontend_style True if we should have WPJM core enqueue frontend styles.
	 * @return bool
	 */
	public function use_wpjm_core_frontend_style( $use_frontend_style ) {
		if ( $this->is_shortcode_page() ) {
			return true;
		}
		return $use_frontend_style;
	}

	/**
	 * Checks if the current page is the `[job_alerts]` page.
	 *
	 * @return bool
	 */
	private function is_shortcode_page() {
		global $post;

		$content = null;
		if ( is_singular() && is_a( $post, 'WP_Post' ) ) {
			$content = $post->post_content;
		}

		return has_shortcode( $content, 'job_alerts' );
	}

	/**
	 * Return the default email content for alerts
	 */
	public function get_default_email() {
		return "Hello {display_name},

The following jobs were found matching your \"{alert_name}\" job alert.

================================================================================
{jobs}
Your next alert for this search will be sent {alert_next_date}. To manage your alerts please login and visit your alerts page here: {alert_page_url}.

{alert_expirey}";
	}

	/**
	 * Add Settings
	 * @param  array $settings
	 * @return array
	 */
	public function settings( $settings = array() ) {
		if ( ! get_option( 'job_manager_alerts_email_template' ) ) {
			delete_option( 'job_manager_alerts_email_template' );
		}

		$settings['job_alerts'] = array(
			__( 'Job Alerts', 'wp-job-manager-alerts' ),
			apply_filters(
				'wp_job_manager_alerts_settings',
				array(
					array(
						'name' 		=> 'job_manager_alerts_email_template',
						'std' 		=> $this->get_default_email(),
						'label' 	=> __( 'Alert Email Content', 'wp-job-manager-alerts' ),
						'desc'		=> __( 'Enter the content for your email alerts. Leave blank to use the default message. The following tags can be used to insert data dynamically:', 'wp-job-manager-alerts' ) . '<br/>' .
							'<code>{display_name}</code>' . ' - ' . __( 'The users display name in WP', 'wp-job-manager-alerts' ) . '<br/>' .
							'<code>{alert_name}</code>' . ' - ' . __( 'The name of the alert being sent', 'wp-job-manager-alerts' ) . '<br/>' .
							'<code>{alert_expirey}</code>' . ' - ' . __( 'A sentance explaining if an alert will be stopped automatically', 'wp-job-manager-alerts' ) . '<br/>' .
							'<code>{alert_next_date}</code>' . ' - ' . __( 'The date this alert will next be sent', 'wp-job-manager-alerts' ) . '<br/>' .
							'<code>{alert_page_url}</code>' . ' - ' . __( 'The url to your alerts page', 'wp-job-manager-alerts' ) . '<br/>' .
							'<code>{jobs}</code>' . ' - ' . __( 'The name of the alert being sent', 'wp-job-manager-alerts' ) . '<br/>' .
							'',
						'type'      => 'textarea',
						'required'  => true
					),
					array(
						'name' 		=> 'job_manager_alerts_auto_disable',
						'std' 		=> '90',
						'label' 	=> __( 'Alert Duration', 'wp-job-manager-alerts' ),
						'desc'		=> __( 'Enter the number of days before alerts are automatically disabled, or leave blank to disable this feature. By default, alerts will be turned off for a search after 90 days.', 'wp-job-manager-alerts' ),
						'type'      => 'input'
					),
					array(
						'name' 		=> 'job_manager_alerts_matches_only',
						'std' 		=> '0',
						'label' 	=> __( 'Alert Matches', 'wp-job-manager-alerts' ),
						'cb_label' 	=> __( 'Send alerts with matches only', 'wp-job-manager-alerts' ),
						'desc'		=> __( 'Only send an alert when jobs are found matching its criteria. When disabled, an alert is sent regardless.', 'wp-job-manager-alerts' ),
						'type'      => 'checkbox'
					),
					array(
						'name' 		=> 'job_manager_alerts_page_id',
						'std' 		=> '',
						'label' 	=> __( 'Alerts Page ID', 'wp-job-manager-alerts' ),
						'desc'		=> __( 'So that the plugin knows where to link users to view their alerts, you must select the page where you have placed the [job_alerts] shortcode.', 'wp-job-manager-alerts' ),
						'type'      => 'page'
					)
				)
			)
		);
		return $settings;
	}

	/**
	 * Add the alert link
	 */
	public function alert_link( $links, $args ) {
		if ( is_user_logged_in() && get_option( 'job_manager_alerts_page_id' ) ) {
			if ( isset( $_POST[ 'form_data' ] ) ) {
				parse_str( $_POST[ 'form_data' ], $params );
				$alert_regions = isset( $params[ 'search_region' ] ) ? absint( $params[ 'search_region' ] ) : '';
			} else {
				$alert_regions = '';
			}

			$links['alert'] = array(
				'name' => __( 'Add alert', 'wp-job-manager-alerts' ),
				'url'  => add_query_arg( array(
					'action'         => 'add_alert',
					'alert_job_type' => $args['filter_job_types'],
					'alert_location' => urlencode( $args['search_location'] ),
					'alert_cats'     => $args['search_categories'],
					'alert_keyword'  => urlencode( $args['search_keywords'] ),
					'alert_regions'  => $alert_regions,
				), get_permalink( get_option( 'job_manager_alerts_page_id' ) ) )
			);
		}

		return $links;
	}

	/**
	 * Single listing alert link
	 */
	public function single_alert_link() {
		global $post, $job_preview;

		if ( ! empty( $job_preview ) ) {
			return;
		}

		if ( is_user_logged_in() && get_option( 'job_manager_alerts_page_id' ) ) {
			$job_types = wpjm_get_the_job_types( $post );
			$args = array(
				'action'         => 'add_alert',
				'alert_name'     => urlencode( $post->post_title ),
				'alert_job_type' => wp_list_pluck( $job_types, 'slug' ),
				'alert_location' => urlencode( strip_tags( get_the_job_location( $post ) ) ),
				'alert_cats'     => taxonomy_exists( 'job_listing_category' ) ? wp_get_post_terms( $post->ID, 'job_listing_category', array( 'fields' => 'ids' ) ) : '',
				'alert_keyword'  => urlencode( $post->post_title ),
				'alert_regions'  => taxonomy_exists( 'job_listing_region' ) ? current( wp_get_post_terms( $post->ID, 'job_listing_region', array( 'fields' => 'ids' ) ) ) : '',
			);
			/**
			 * Filter the link arguments for creating an alert based on a single listing.
			 *
			 * @since 1.5.0
			 *
			 * @param array $args Arguments for alert
			 */
			$args = apply_filters( 'job_manager_alerts_single_listing_link', $args );
			$link     =  add_query_arg( $args, get_permalink( get_option( 'job_manager_alerts_page_id' ) ) );
			echo '<p class="job-manager-single-alert-link"><a href="' . esc_url( $link ) . '">' . __( 'Alert me to jobs like this', 'wp-job-manager-alerts' ) . '</a></p>';
		}
	}
}

$GLOBALS['job_manager_alerts'] = new WP_Job_Manager_Alerts();
