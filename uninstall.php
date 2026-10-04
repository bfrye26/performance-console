<?php
/**
 * Performance Console uninstall cleanup.
 *
 * Removes plugin tables, options/transients, the maintenance cron event, the
 * generated MU bootstrap, and plugin-created backup artifacts. Backup
 * directories are only removed when they contain nothing but the plugin's own
 * protection files and exports of the current site.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

$pfc_backups_table = $wpdb->prefix . 'pfc_backups';
$pfc_backup_paths = array();
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $pfc_backups_table ) ) ) === $pfc_backups_table ) {
    $pfc_backup_paths = (array) $wpdb->get_col( "SELECT file_path FROM `{$pfc_backups_table}` WHERE file_path <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ) as $pfc_suffix ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}pfc_{$pfc_suffix}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$pfc_options_like = $wpdb->esc_like( 'pfc_' ) . '%';
$pfc_transient_like = $wpdb->esc_like( '_transient_pfc_' ) . '%';
$pfc_transient_timeout_like = $wpdb->esc_like( '_transient_timeout_pfc_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $pfc_options_like, $pfc_transient_like, $pfc_transient_timeout_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

wp_clear_scheduled_hook( 'pfc_daily_maintenance' );

if ( $pfc_backup_paths ) {
    foreach ( $pfc_backup_paths as $pfc_path ) {
        if ( is_string( $pfc_path ) && is_file( $pfc_path ) ) { @unlink( $pfc_path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }
}

if ( defined( 'WP_CONTENT_DIR' ) ) {
    $pfc_hash = substr( hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH ) . DB_NAME ), 0, 16 );
    $pfc_dirs = array(
        trailingslashit( WP_CONTENT_DIR ) . 'pfc-private-backups-' . $pfc_hash,
        trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . '.pfc-private-backups-' . $pfc_hash,
    );
    $pfc_temp = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
    if ( $pfc_temp ) { $pfc_dirs[] = trailingslashit( $pfc_temp ) . 'pfc-private-backups-' . $pfc_hash; }
    foreach ( array_unique( $pfc_dirs ) as $pfc_dir ) {
        if ( ! is_dir( $pfc_dir ) ) { continue; }
        foreach ( (array) glob( trailingslashit( $pfc_dir ) . 'pfc-db-*.sql' ) as $pfc_file ) { @unlink( $pfc_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $pfc_file ) { @unlink( trailingslashit( $pfc_dir ) . $pfc_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @rmdir( $pfc_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }
}

if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
    foreach ( array( '000-performance-console-bootstrap.php', 'performance-console-bootstrap.php' ) as $pfc_mu ) {
        $pfc_mu_path = trailingslashit( WPMU_PLUGIN_DIR ) . $pfc_mu;
        if ( is_file( $pfc_mu_path ) ) { @unlink( $pfc_mu_path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }
}
