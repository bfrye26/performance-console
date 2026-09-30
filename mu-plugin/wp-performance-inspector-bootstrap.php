<?php
/** WP Performance Inspector early diagnostic/bootstrap sampler.
 * WPI Bootstrap Version: 1.3.1
 */
if ( ! defined( 'ABSPATH' ) ) { return; }

$wpi_basename = '{{PLUGIN_BASENAME}}';
// A leftover MU file must not trace or alter requests after WPI is deactivated.
$wpi_active = (array) get_option( 'active_plugins', array() );
$wpi_network_active = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
if ( ! in_array( $wpi_basename, $wpi_active, true ) && ! isset( $wpi_network_active[ $wpi_basename ] ) ) { return; }
$wpi_deep = false;
$wpi_signed_diag = false;
$wpi_exclude = '';
$wpi_probe_id = '';
$wpi_save_capture = array();
$wpi_secret = (string) get_option( 'wpi_secret', '' );
$wpi_diag = isset( $_GET['wpi_diag'], $_GET['wpi_ts'], $_GET['wpi_sig'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( $wpi_diag ) {
    $wpi_ts = absint( $_GET['wpi_ts'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wpi_sig = sanitize_text_field( wp_unslash( $_GET['wpi_sig'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wpi_exclude = isset( $_GET['wpi_exclude'] ) ? sanitize_text_field( wp_unslash( $_GET['wpi_exclude'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wpi_probe_id = isset( $_GET['wpi_probe'] ) ? sanitize_text_field( wp_unslash( $_GET['wpi_probe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wpi_path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : '/';
    $wpi_expected = hash_hmac( 'sha256', $wpi_ts . '|' . $wpi_path . '|' . $wpi_exclude . '|' . $wpi_probe_id, (string) $wpi_secret );
    $wpi_signed_diag = $wpi_secret && abs( time() - $wpi_ts ) <= 120 && hash_equals( $wpi_expected, $wpi_sig );
    $wpi_deep = $wpi_signed_diag;
}

// A save capture observes one real editor request. It never replays or alters the write.
if ( ! $wpi_deep && $wpi_secret && ! empty( $_COOKIE['wpi_capture_save'] ) ) {
    $wpi_cookie = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE['wpi_capture_save'] ) ) );
    if ( 5 === count( $wpi_cookie ) ) {
        list( $wpi_user_id, $wpi_expires, $wpi_capture_kind, $wpi_capture_id, $wpi_cookie_sig ) = $wpi_cookie;
        $wpi_cookie_data = $wpi_user_id . '|' . $wpi_expires . '|' . $wpi_capture_kind . '|' . $wpi_capture_id;
        $wpi_valid_cookie = ctype_digit( $wpi_user_id ) && ctype_digit( $wpi_expires )
            && in_array( $wpi_capture_kind, array( 'manual', 'autosave' ), true )
            && 1 === preg_match( '/^[a-f0-9-]{36}$/i', $wpi_capture_id )
            && 1 === preg_match( '/^[a-f0-9]{64}$/i', $wpi_cookie_sig )
            && (int) $wpi_expires >= time() && (int) $wpi_expires <= time() + 15 * MINUTE_IN_SECONDS
            && hash_equals( hash_hmac( 'sha256', $wpi_cookie_data, $wpi_secret ), $wpi_cookie_sig );

        $wpi_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
        $wpi_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $wpi_context = array();
        $wpi_utils_file = dirname( WP_PLUGIN_DIR . '/' . $wpi_basename ) . '/includes/class-wpi-utils.php';
        if ( $wpi_valid_cookie && is_readable( $wpi_utils_file ) ) {
            require_once $wpi_utils_file;
            $wpi_context = WPI_Utils::save_request_context( $wpi_method, $wpi_uri, wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        $wpi_kind_matches = $wpi_context && ( ( 'autosave' === $wpi_capture_kind ) === ! empty( $wpi_context['autosave'] ) );
        if ( $wpi_kind_matches ) {
            $wpi_save_capture = $wpi_context + array( 'capture_id' => $wpi_capture_id, 'user_id' => (int) $wpi_user_id, 'requested_kind' => $wpi_capture_kind );
            $wpi_probe_id = $wpi_capture_id;
            $wpi_deep = true;
            $wpi_cookie_options = array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' );
            if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) { $wpi_cookie_options['domain'] = COOKIE_DOMAIN; }
            setcookie( 'wpi_capture_save', '', $wpi_cookie_options );
        }
    }
}

$wpi_sample = false;
if ( ! $wpi_deep ) {
    $wpi_runtime = get_option( 'wpi_runtime', array() );
    $wpi_rate = max( 0, min( 1, (float) ( $wpi_runtime['sample_rate'] ?? 0 ) ) );
    if ( $wpi_rate > 0 ) { $wpi_sample = mt_rand() / mt_getrandmax() <= $wpi_rate; }
}

if ( ! defined( 'WPI_SAMPLING_DECIDED' ) ) { define( 'WPI_SAMPLING_DECIDED', true ); }
if ( ! $wpi_deep && ! $wpi_sample ) { return; }

if ( $wpi_deep && ! defined( 'WPI_DEEP_DIAGNOSTIC' ) ) { define( 'WPI_DEEP_DIAGNOSTIC', true ); }
if ( $wpi_save_capture && ! defined( 'WPI_SAVE_DIAGNOSTIC' ) ) { define( 'WPI_SAVE_DIAGNOSTIC', true ); }
if ( $wpi_sample && ! defined( 'WPI_SAMPLED_REQUEST' ) ) { define( 'WPI_SAMPLED_REQUEST', true ); }
if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); } elseif ( ! SAVEQUERIES && ! defined( 'WPI_QUERY_TIMING_BLOCKED' ) ) { define( 'WPI_QUERY_TIMING_BLOCKED', true ); }
if ( ! defined( 'DONOTCACHEPAGE' ) && $wpi_deep ) { define( 'DONOTCACHEPAGE', true ); }
if ( $wpi_signed_diag ) {
    add_action( 'send_headers', static function () use ( $wpi_probe_id, $wpi_exclude ) {
        if ( ! headers_sent() ) {
            header( 'X-WPI-Diagnostic: 1' );
            header( 'X-WPI-Probe-ID: ' . $wpi_probe_id );
            header( 'X-WPI-Excluded-Plugin: ' . ( $wpi_exclude ?: 'none' ) );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }, PHP_INT_MAX );
}

$GLOBALS['wpi_diag_start'] = microtime( true );
$GLOBALS['wpi_probe_id'] = $wpi_probe_id;
$GLOBALS['wpi_excluded_plugin'] = $wpi_exclude;
$GLOBALS['wpi_save_capture'] = $wpi_save_capture;
$GLOBALS['wpi_phase_marks'] = array( 'mu_plugin_bootstrap' => $GLOBALS['wpi_diag_start'] );

$wpi_mark = static function ( $name ) { $GLOBALS['wpi_phase_marks'][ $name ] = microtime( true ); };
add_action( 'muplugins_loaded', static function () use ( $wpi_mark ) { $wpi_mark( 'muplugins_loaded' ); }, PHP_INT_MAX );
add_action( 'plugins_loaded', static function () use ( $wpi_mark ) { $wpi_mark( 'plugins_loaded' ); }, PHP_INT_MAX );
add_action( 'setup_theme', static function () use ( $wpi_mark ) { $wpi_mark( 'setup_theme' ); }, PHP_INT_MAX );
add_action( 'after_setup_theme', static function () use ( $wpi_mark ) { $wpi_mark( 'after_setup_theme' ); }, PHP_INT_MAX );
add_action( 'init', static function () use ( $wpi_mark ) { $wpi_mark( 'init' ); }, PHP_INT_MAX );
add_action( 'wp_loaded', static function () use ( $wpi_mark ) { $wpi_mark( 'wp_loaded' ); }, PHP_INT_MAX );
add_action( 'wp', static function () use ( $wpi_mark ) { $wpi_mark( 'wp' ); }, PHP_INT_MAX );
add_action( 'template_redirect', static function () use ( $wpi_mark ) { $wpi_mark( 'template_redirect' ); }, PHP_INT_MAX );
add_action( 'wp_head', static function () use ( $wpi_mark ) { $wpi_mark( 'wp_head' ); }, PHP_INT_MAX );
add_action( 'wp_footer', static function () use ( $wpi_mark ) { $wpi_mark( 'wp_footer' ); }, PHP_INT_MAX );

if ( $wpi_deep && $wpi_exclude ) {
    add_filter( 'option_active_plugins', static function ( $plugins ) use ( $wpi_exclude, $wpi_basename ) {
        if ( ! is_array( $plugins ) ) { return $plugins; }
        return array_values( array_filter( $plugins, static function ( $p ) use ( $wpi_exclude, $wpi_basename ) {
            if ( $p === $wpi_basename ) { return true; }
            return $p !== $wpi_exclude;
        } ) );
    }, -9999 );
    add_filter( 'site_option_active_sitewide_plugins', static function ( $plugins ) use ( $wpi_exclude ) {
        if ( is_array( $plugins ) ) { unset( $plugins[ $wpi_exclude ] ); }
        return $plugins;
    }, -9999 );
}

if ( $wpi_deep ) {
    $GLOBALS['wpi_query_traces'] = array();
    add_filter( 'query', static function ( $sql ) {
        if ( count( $GLOBALS['wpi_query_traces'] ) < 5000 ) {
            $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 18 );
            $GLOBALS['wpi_query_traces'][] = array( 'sql_hash' => md5( $sql ), 'trace' => $trace );
        }
        return $sql;
    }, PHP_INT_MAX );
}
