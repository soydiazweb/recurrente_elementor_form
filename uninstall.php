<?php
/**
 * Limpieza al eliminar el plugin (solo si se activó en Ajustes).
 *
 * @package Recurrente_Elementor_Form
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ref_settings = get_option( 'ref_settings', array() );

wp_clear_scheduled_hook( 'ref_cron_reconcile' );

if ( is_array( $ref_settings ) && ! empty( $ref_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ref_logs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'ref_settings' );
	delete_option( 'ref_db_version' );
}
