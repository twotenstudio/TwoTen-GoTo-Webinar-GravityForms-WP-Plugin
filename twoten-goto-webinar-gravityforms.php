<?php
/**
 * Plugin Name:       TwoTen GoTo Webinar for Gravity Forms
 * Plugin URI:        https://github.com/twotenstudio/TwoTen-GoTo-Webinar-GravityForms-WP-Plugin
 * Description:       Registers Gravity Forms submissions as GoTo Webinar registrants. Feed-based, with OAuth connection, field mapping, custom questions and conditional logic.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  gravityforms
 * Author:            TwoTen Studio
 * Author URI:        https://twotenstudio.co.uk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       twoten-goto-webinar-gravityforms
 * Update URI:        https://github.com/twotenstudio/TwoTen-GoTo-Webinar-GravityForms-WP-Plugin
 */

defined( 'ABSPATH' ) || exit;

define( 'TTS_GTW_VERSION', '1.0.0' );
define( 'TTS_GTW_FILE', __FILE__ );
define( 'TTS_GTW_DIR', plugin_dir_path( __FILE__ ) );
define( 'TTS_GTW_URL', plugin_dir_url( __FILE__ ) );
define( 'TTS_GTW_SLUG', 'twoten-goto-webinar-gravityforms' );
define( 'TTS_GTW_GITHUB_REPO', 'twotenstudio/TwoTen-GoTo-Webinar-GravityForms-WP-Plugin' );
define( 'TTS_GTW_MIN_GF_VERSION', '2.5' );

/*
 * GitHub release updater. Runs independently of Gravity Forms so the plugin
 * can still update itself even if Gravity Forms is inactive.
 */
require_once TTS_GTW_DIR . 'includes/class-tts-gtw-updater.php';
TTS_GTW_Updater::init();

/**
 * Bootstrap the Gravity Forms add-on once Gravity Forms has loaded.
 */
add_action( 'gform_loaded', 'tts_gtw_load_addon', 5 );
function tts_gtw_load_addon() {
	if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
		return;
	}

	GFForms::include_feed_addon_framework();

	require_once TTS_GTW_DIR . 'includes/class-tts-gtw-api.php';
	require_once TTS_GTW_DIR . 'includes/class-gf-goto-webinar.php';

	GFAddOn::register( 'GF_GoTo_Webinar' );
}

/**
 * Convenience accessor for the add-on instance.
 *
 * @return GF_GoTo_Webinar|null
 */
function tts_gtw() {
	return class_exists( 'GF_GoTo_Webinar' ) ? GF_GoTo_Webinar::get_instance() : null;
}

/**
 * Admin notice when Gravity Forms is missing or too old.
 */
add_action( 'admin_notices', 'tts_gtw_requirements_notice' );
function tts_gtw_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( ! class_exists( 'GFForms' ) ) {
		$message = __( 'TwoTen GoTo Webinar for Gravity Forms requires Gravity Forms to be installed and active.', 'twoten-goto-webinar-gravityforms' );
	} elseif ( version_compare( GFForms::$version, TTS_GTW_MIN_GF_VERSION, '<' ) ) {
		$message = sprintf(
			/* translators: %s: minimum Gravity Forms version */
			__( 'TwoTen GoTo Webinar for Gravity Forms requires Gravity Forms %s or newer.', 'twoten-goto-webinar-gravityforms' ),
			TTS_GTW_MIN_GF_VERSION
		);
	} else {
		return;
	}

	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
}
