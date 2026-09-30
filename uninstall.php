<?php
/**
 * WP Performance Inspector uninstall cleanup.
 *
 * Removes plugin tables, options/transients, the maintenance cron event, the
 * generated MU bootstrap, and plugin-created backup artifacts. Backup
 * directories are only removed when they contain nothing but the plugin's own
 * protection files and exports of the current site.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

$wpi_backups_table = $wpdb->prefix . 'wpi_backups';
$wpi_backup_paths = array();
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpi_backups_table ) ) ) === $wpi_backups_table ) {
    $wpi_backup_paths = (array) $wpdb->get_col( "SELECT file_path FROM `{$wpi_backups_table}` WHERE file_path <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ) as $wpi_suffix ) {
    $wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wpi_{$wpi_suffix}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$wpi_options_like = $wpdb->esc_like( 'wpi_' ) . '%';
$wpi_transient_like = $wpdb->esc_like( '_transient_wpi_' ) . '%';
$wpi_transient_timeout_like = $wpdb->esc_like( '_transient_timeout_wpi_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpi_options_like, $wpi_transient_like, $wpi_transient_timeout_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

wp_clear_scheduled_hook( 'wpi_daily_maintenance' );

if ( $wpi_backup_paths ) {
    foreach ( $wpi_backup_paths as $wpi_path ) {
        if ( is_string( $wpi_path ) && is_file( $wpi_path ) ) { @unlink( $wpi_path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }
}

if ( defined( 'WP_CONTENT_DIR' ) ) {
    $wpi_hash = substr( hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH ) . DB_NAME ), 0, 16 );
    $wpi_dirs = array(
        trailingslashit( WP_CONTENT_DIR ) . 'wpi-private-backups-' . $wpi_hash,
        trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . '.wpi-private-backups-' . $wpi_hash,
    );
    $wpi_temp = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
    if ( $wpi_temp ) { $wpi_dirs[] = trailingslashit( $wpi_temp ) . 'wpi-private-backups-' . $wpi_hash; }
    foreach ( array_unique( $wpi_dirs ) as $wpi_dir ) {
        if ( ! is_dir( $wpi_dir ) ) { continue; }
        foreach ( (array) glob( trailingslashit( $wpi_dir ) . 'wpi-db-*.sql' ) as $wpi_file ) { @unlink( $wpi_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $wpi_file ) { @unlink( trailingslashit( $wpi_dir ) . $wpi_file ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        @rmdir( $wpi_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }
}

if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
    foreach ( array( '000-wp-performance-inspector-bootstrap.php', 'wp-performance-inspector-bootstrap.php' ) as $wpi_mu ) {
        $wpi_mu_path = trailingslashit( WPMU_PLUGIN_DIR ) . $wpi_mu;
        if ( is_file( $wpi_mu_path ) ) { @unlink( $wpi_mu_path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
    }
}
