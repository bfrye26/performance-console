<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_Bootstrap {
    const VERSION = '1.3.2';
    const SAVE_COOKIE = 'pfc_capture_save';

    public static function path() { return trailingslashit( WPMU_PLUGIN_DIR ) . '000-performance-console-bootstrap.php'; }

    private static function legacy_paths() {
        return array(
            trailingslashit( WPMU_PLUGIN_DIR ) . '000-wp-performance-inspector-bootstrap.php',
            trailingslashit( WPMU_PLUGIN_DIR ) . 'wp-performance-inspector-bootstrap.php',
        );
    }

    private static function rendered_template() {
        $template = file_get_contents( PFC_DIR . 'mu-plugin/performance-console-bootstrap.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $template ) { return false; }
        return str_replace( '{{PLUGIN_BASENAME}}', PFC_BASENAME, $template );
    }

    public static function maybe_install() {
        if ( self::VERSION === get_transient( 'pfc_bootstrap_checked' ) ) { return; }
        $status = self::status();
        if ( ! $status['installed'] || ! $status['current'] ) {
            self::install();
        }
        set_transient( 'pfc_bootstrap_checked', self::VERSION, DAY_IN_SECONDS );
    }

    public static function install() {
        if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) { return new WP_Error( 'pfc_mu_dir', 'Unable to create mu-plugins directory.' ); }
        $template = self::rendered_template();
        if ( false === $template ) { return new WP_Error( 'pfc_mu_template', 'MU bootstrap template missing.' ); }
        $target = self::path();
        $staging = $target . '.tmp';
        $ok = @file_put_contents( $staging, $template, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === $ok || ! @rename( $staging, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            @unlink( $staging ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            return new WP_Error( 'pfc_mu_write', 'Unable to write MU bootstrap.' );
        }
        @chmod( $target, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        foreach ( self::legacy_paths() as $legacy ) {
            if ( $legacy !== $target && file_exists( $legacy ) ) { @unlink( $legacy ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        }
        set_transient( 'pfc_bootstrap_checked', self::VERSION, DAY_IN_SECONDS );
        return true;
    }

    public static function status() {
        $path = self::path();
        $installed = file_exists( $path );
        $current = false;
        $version = '';
        if ( $installed && is_readable( $path ) ) {
            $head = file_get_contents( $path, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( is_string( $head ) && preg_match( '/PFC Bootstrap Version:\s*([0-9.]+)/', $head, $m ) ) { $version = $m[1]; }
            $current = self::VERSION === $version;
        }
        return array( 'path' => $path, 'installed' => $installed, 'current' => $current, 'version' => $version, 'writable' => is_writable( WPMU_PLUGIN_DIR ) );
    }

    public static function token( $url, $exclude = '', $probe_id = '' ) {
        $ts = time();
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $secret = (string) get_option( 'pfc_secret' );
        $sig = hash_hmac( 'sha256', $ts . '|' . $path . '|' . $exclude . '|' . $probe_id, $secret );
        return array( 'pfc_diag' => 1, 'pfc_ts' => $ts, 'pfc_sig' => $sig, 'pfc_exclude' => $exclude, 'pfc_probe' => $probe_id );
    }

    public static function save_capture_value( $user_id, $kind, $expires, $capture_id, $secret = '' ) {
        $kind = in_array( $kind, array( 'manual', 'autosave' ), true ) ? $kind : 'manual';
        $payload = absint( $user_id ) . '|' . absint( $expires ) . '|' . $kind . '|' . sanitize_text_field( (string) $capture_id );
        $secret = '' !== $secret ? (string) $secret : (string) get_option( 'pfc_secret' );
        return $payload . '|' . hash_hmac( 'sha256', $payload, $secret );
    }

    public static function parse_save_capture_value( $value, $secret = '', $now = null ) {
        $parts = explode( '|', (string) $value );
        if ( 5 !== count( $parts ) ) { return false; }
        list( $user_id, $expires, $kind, $capture_id, $signature ) = $parts;
        $now = null === $now ? time() : absint( $now );
        if ( ! ctype_digit( $user_id ) || ! ctype_digit( $expires ) || ! in_array( $kind, array( 'manual', 'autosave' ), true ) || ! preg_match( '/^[a-f0-9-]{36}$/i', $capture_id ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) { return false; }
        if ( (int) $expires < $now || (int) $expires > $now + 15 * MINUTE_IN_SECONDS ) { return false; }
        $payload = $user_id . '|' . $expires . '|' . $kind . '|' . $capture_id;
        $secret = '' !== $secret ? (string) $secret : (string) get_option( 'pfc_secret' );
        if ( ! $secret || ! hash_equals( hash_hmac( 'sha256', $payload, $secret ), $signature ) ) { return false; }
        return array( 'user_id' => absint( $user_id ), 'expires' => absint( $expires ), 'kind' => $kind, 'capture_id' => $capture_id );
    }

    public static function save_capture_status() {
        $value = isset( $_COOKIE[ self::SAVE_COOKIE ] ) ? wp_unslash( $_COOKIE[ self::SAVE_COOKIE ] ) : '';
        $capture = self::parse_save_capture_value( $value );
        return $capture && (int) $capture['user_id'] === get_current_user_id() ? $capture : false;
    }

    public static function arm_save_capture( $user_id, $kind = 'manual' ) {
        $expires = time() + 10 * MINUTE_IN_SECONDS;
        $capture_id = wp_generate_uuid4();
        $value = self::save_capture_value( $user_id, $kind, $expires, $capture_id );
        if ( ! self::set_save_cookie( $value, $expires ) ) { return new WP_Error( 'pfc_save_cookie', 'The browser capture cookie could not be set. Check that response headers have not already been sent.' ); }
        $_COOKIE[ self::SAVE_COOKIE ] = $value;
        return self::parse_save_capture_value( $value );
    }

    public static function clear_save_capture() {
        self::set_save_cookie( '', time() - HOUR_IN_SECONDS );
        unset( $_COOKIE[ self::SAVE_COOKIE ] );
    }

    private static function set_save_cookie( $value, $expires ) {
        $options = array( 'expires' => (int) $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' );
        if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) { $options['domain'] = COOKIE_DOMAIN; }
        return setcookie( self::SAVE_COOKIE, (string) $value, $options );
    }
}
