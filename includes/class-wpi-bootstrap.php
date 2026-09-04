<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPI_Bootstrap {
    const VERSION = '1.1.0';

    public static function path() { return trailingslashit( WPMU_PLUGIN_DIR ) . '000-wp-performance-inspector-bootstrap.php'; }

    private static function legacy_path() { return trailingslashit( WPMU_PLUGIN_DIR ) . 'wp-performance-inspector-bootstrap.php'; }

    private static function rendered_template() {
        $template = file_get_contents( WPI_DIR . 'mu-plugin/wp-performance-inspector-bootstrap.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $template ) { return false; }
        return str_replace( '{{PLUGIN_BASENAME}}', WPI_BASENAME, $template );
    }

    public static function maybe_install() {
        if ( get_transient( 'wpi_bootstrap_checked' ) ) { return; }
        $status = self::status();
        if ( ! $status['installed'] || ! $status['current'] ) {
            self::install();
        }
        set_transient( 'wpi_bootstrap_checked', 1, DAY_IN_SECONDS );
    }

    public static function install() {
        if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) { return new WP_Error( 'wpi_mu_dir', 'Unable to create mu-plugins directory.' ); }
        $template = self::rendered_template();
        if ( false === $template ) { return new WP_Error( 'wpi_mu_template', 'MU bootstrap template missing.' ); }
        $ok = @file_put_contents( self::path(), $template, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === $ok ) { return new WP_Error( 'wpi_mu_write', 'Unable to write MU bootstrap.' ); }
        @chmod( self::path(), 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        $legacy = self::legacy_path();
        if ( $legacy !== self::path() && file_exists( $legacy ) ) { @unlink( $legacy ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        set_transient( 'wpi_bootstrap_checked', 1, DAY_IN_SECONDS );
        return true;
    }

    public static function status() {
        $path = self::path();
        $installed = file_exists( $path );
        $current = false;
        $version = '';
        if ( $installed && is_readable( $path ) ) {
            $head = file_get_contents( $path, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( is_string( $head ) && preg_match( '/WPI Bootstrap Version:\s*([0-9.]+)/', $head, $m ) ) { $version = $m[1]; }
            $current = self::VERSION === $version;
        }
        return array( 'path' => $path, 'installed' => $installed, 'current' => $current, 'version' => $version, 'writable' => is_writable( WPMU_PLUGIN_DIR ) );
    }

    public static function token( $url, $exclude = '' ) {
        $ts = time();
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $secret = (string) get_option( 'wpi_secret' );
        $sig = hash_hmac( 'sha256', $ts . '|' . $path . '|' . $exclude, $secret );
        return array( 'wpi_diag' => 1, 'wpi_ts' => $ts, 'wpi_sig' => $sig, 'wpi_exclude' => $exclude );
    }
}
