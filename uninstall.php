<?php
/**
 * Remove plugin data when the plugin is deleted from the Plugins screen.
 *
 * Feeds and add-on settings stored by Gravity Forms are removed through the
 * add-on's own Uninstall button under Forms > Settings > GoTo Webinar.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tts_gtw_auth' );
delete_transient( 'tts_gtw_webinars' );
delete_transient( 'tts_gtw_fields' );
delete_site_transient( 'tts_gtw_github_release' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tts_gtw_oauth_state_%' OR option_name LIKE '_transient_timeout_tts_gtw_oauth_state_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
