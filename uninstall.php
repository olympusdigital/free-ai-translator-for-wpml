<?php
/**
 * Uninstall cleanup for Free AI Translator for WPML.
 *
 * Removes the plugin's options and its log table. Translated posts are NOT
 * touched — they are the user's content, created and reviewed by them, and
 * deleting a plugin should never delete content.
 *
 * @package Free_AI_Translator_For_WPML
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'fatw_settings' );
delete_option( 'fatw_schema_version' );

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fatw_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
