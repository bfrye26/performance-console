<?php
/** Performance Console early diagnostic/bootstrap sampler.
 * PFC Bootstrap Version: 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) { return; }

$pfc_basename = '{{PLUGIN_BASENAME}}';
// A leftover MU file must not trace or alter requests after Performance Console is deactivated.
$pfc_active = (array) get_option( 'active_plugins', array() );
$pfc_network_active = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
if ( ! in_array( $pfc_basename, $pfc_active, true ) && ! isset( $pfc_network_active[ $pfc_basename ] ) ) { return; }
$pfc_deep = false;
$pfc_signed_diag = false;
$pfc_exclude = '';
$pfc_probe_id = '';
$pfc_save_capture = array();
$pfc_secret = '';
$pfc_diag = isset( $_GET['pfc_diag'], $_GET['pfc_ts'], $_GET['pfc_sig'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( $pfc_diag ) {
    $pfc_secret = (string) get_option( 'pfc_secret', '' );
    $pfc_ts = absint( $_GET['pfc_ts'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $pfc_sig = sanitize_text_field( wp_unslash( $_GET['pfc_sig'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $pfc_exclude = isset( $_GET['pfc_exclude'] ) ? sanitize_text_field( wp_unslash( $_GET['pfc_exclude'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $pfc_probe_id = isset( $_GET['pfc_probe'] ) ? sanitize_text_field( wp_unslash( $_GET['pfc_probe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $pfc_path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : '/';
    $pfc_expected = hash_hmac( 'sha256', $pfc_ts . '|' . $pfc_path . '|' . $pfc_exclude . '|' . $pfc_probe_id, (string) $pfc_secret );
    $pfc_signed_diag = $pfc_secret && abs( time() - $pfc_ts ) <= 120 && hash_equals( $pfc_expected, $pfc_sig );
    $pfc_deep = $pfc_signed_diag;
}

// A save capture observes one real editor request. It never replays or alters the write.
if ( ! $pfc_deep && ! empty( $_COOKIE['pfc_capture_save'] ) ) {
    $pfc_secret = (string) get_option( 'pfc_secret', '' );
    $pfc_cookie = explode( '|', sanitize_text_field( wp_unslash( $_COOKIE['pfc_capture_save'] ) ) );
    if ( $pfc_secret && 5 === count( $pfc_cookie ) ) {
        list( $pfc_user_id, $pfc_expires, $pfc_capture_kind, $pfc_capture_id, $pfc_cookie_sig ) = $pfc_cookie;
        $pfc_cookie_data = $pfc_user_id . '|' . $pfc_expires . '|' . $pfc_capture_kind . '|' . $pfc_capture_id;
        $pfc_valid_cookie = ctype_digit( $pfc_user_id ) && ctype_digit( $pfc_expires )
            && in_array( $pfc_capture_kind, array( 'manual', 'autosave' ), true )
            && 1 === preg_match( '/^[a-f0-9-]{36}$/i', $pfc_capture_id )
            && 1 === preg_match( '/^[a-f0-9]{64}$/i', $pfc_cookie_sig )
            && (int) $pfc_expires >= time() && (int) $pfc_expires <= time() + 15 * MINUTE_IN_SECONDS
            && hash_equals( hash_hmac( 'sha256', $pfc_cookie_data, $pfc_secret ), $pfc_cookie_sig );

        $pfc_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
        $pfc_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $pfc_context = array();
        $pfc_utils_file = dirname( WP_PLUGIN_DIR . '/' . $pfc_basename ) . '/includes/class-pfc-utils.php';
        if ( $pfc_valid_cookie && is_readable( $pfc_utils_file ) ) {
            require_once $pfc_utils_file;
            $pfc_context = PFC_Utils::save_request_context( $pfc_method, $pfc_uri, wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        $pfc_kind_matches = $pfc_context && ( ( 'autosave' === $pfc_capture_kind ) === ! empty( $pfc_context['autosave'] ) );
        if ( $pfc_kind_matches ) {
            $pfc_save_capture = $pfc_context + array( 'capture_id' => $pfc_capture_id, 'user_id' => (int) $pfc_user_id, 'requested_kind' => $pfc_capture_kind );
            $pfc_probe_id = $pfc_capture_id;
            $pfc_deep = true;
            $pfc_cookie_options = array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' );
            if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) { $pfc_cookie_options['domain'] = COOKIE_DOMAIN; }
            setcookie( 'pfc_capture_save', '', $pfc_cookie_options );
        }
    }
}

$pfc_sample = false;
if ( ! $pfc_deep ) {
    $pfc_runtime = get_option( 'pfc_runtime', array() );
    $pfc_rate = max( 0, min( 1, (float) ( $pfc_runtime['sample_rate'] ?? 0 ) ) );
    if ( $pfc_rate > 0 ) { $pfc_sample = mt_rand() / mt_getrandmax() <= $pfc_rate; }
}

if ( ! defined( 'PFC_SAMPLING_DECIDED' ) ) { define( 'PFC_SAMPLING_DECIDED', true ); }
if ( ! $pfc_deep && ! $pfc_sample ) { return; }

if ( $pfc_deep && ! defined( 'PFC_DEEP_DIAGNOSTIC' ) ) { define( 'PFC_DEEP_DIAGNOSTIC', true ); }
if ( $pfc_save_capture && ! defined( 'PFC_SAVE_DIAGNOSTIC' ) ) { define( 'PFC_SAVE_DIAGNOSTIC', true ); }
if ( $pfc_sample && ! defined( 'PFC_SAMPLED_REQUEST' ) ) { define( 'PFC_SAMPLED_REQUEST', true ); }
if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); } elseif ( ! SAVEQUERIES && ! defined( 'PFC_QUERY_TIMING_BLOCKED' ) ) { define( 'PFC_QUERY_TIMING_BLOCKED', true ); }
if ( ! defined( 'DONOTCACHEPAGE' ) && $pfc_deep ) { define( 'DONOTCACHEPAGE', true ); }
if ( $pfc_signed_diag ) {
    add_action( 'send_headers', static function () use ( $pfc_probe_id, $pfc_exclude ) {
        if ( ! headers_sent() ) {
            header( 'X-PFC-Diagnostic: 1' );
            header( 'X-PFC-Probe-ID: ' . $pfc_probe_id );
            header( 'X-PFC-Excluded-Plugin: ' . ( $pfc_exclude ?: 'none' ) );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }, PHP_INT_MAX );
}

$GLOBALS['pfc_diag_start'] = microtime( true );
$GLOBALS['pfc_probe_id'] = $pfc_probe_id;
$GLOBALS['pfc_excluded_plugin'] = $pfc_exclude;
$GLOBALS['pfc_save_capture'] = $pfc_save_capture;
$GLOBALS['pfc_phase_marks'] = array( 'mu_plugin_bootstrap' => $GLOBALS['pfc_diag_start'] );

$pfc_mark = static function ( $name ) { $GLOBALS['pfc_phase_marks'][ $name ] = microtime( true ); };
add_action( 'muplugins_loaded', static function () use ( $pfc_mark ) { $pfc_mark( 'muplugins_loaded' ); }, PHP_INT_MAX );
add_action( 'plugins_loaded', static function () use ( $pfc_mark ) { $pfc_mark( 'plugins_loaded' ); }, PHP_INT_MAX );
add_action( 'setup_theme', static function () use ( $pfc_mark ) { $pfc_mark( 'setup_theme' ); }, PHP_INT_MAX );
add_action( 'after_setup_theme', static function () use ( $pfc_mark ) { $pfc_mark( 'after_setup_theme' ); }, PHP_INT_MAX );
add_action( 'init', static function () use ( $pfc_mark ) { $pfc_mark( 'init' ); }, PHP_INT_MAX );
add_action( 'wp_loaded', static function () use ( $pfc_mark ) { $pfc_mark( 'wp_loaded' ); }, PHP_INT_MAX );
add_action( 'wp', static function () use ( $pfc_mark ) { $pfc_mark( 'wp' ); }, PHP_INT_MAX );
add_action( 'template_redirect', static function () use ( $pfc_mark ) { $pfc_mark( 'template_redirect' ); }, PHP_INT_MAX );
add_action( 'wp_head', static function () use ( $pfc_mark ) { $pfc_mark( 'wp_head' ); }, PHP_INT_MAX );
add_action( 'wp_footer', static function () use ( $pfc_mark ) { $pfc_mark( 'wp_footer' ); }, PHP_INT_MAX );

if ( $pfc_deep && $pfc_exclude ) {
    add_filter( 'option_active_plugins', static function ( $plugins ) use ( $pfc_exclude, $pfc_basename ) {
        if ( ! is_array( $plugins ) ) { return $plugins; }
        return array_values( array_filter( $plugins, static function ( $p ) use ( $pfc_exclude, $pfc_basename ) {
            if ( $p === $pfc_basename ) { return true; }
            return $p !== $pfc_exclude;
        } ) );
    }, -9999 );
    add_filter( 'site_option_active_sitewide_plugins', static function ( $plugins ) use ( $pfc_exclude ) {
        if ( is_array( $plugins ) ) { unset( $plugins[ $pfc_exclude ] ); }
        return $plugins;
    }, -9999 );
}

if ( $pfc_deep ) {
    $GLOBALS['pfc_query_traces'] = array();
    add_filter( 'query', static function ( $sql ) {
        if ( count( $GLOBALS['pfc_query_traces'] ) < 5000 ) {
            $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 18 );
            $GLOBALS['pfc_query_traces'][] = array( 'sql_hash' => md5( $sql ), 'trace' => $trace );
        }
        return $sql;
    }, PHP_INT_MAX );
}
