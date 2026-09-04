<?php
/** WP Performance Inspector early diagnostic/bootstrap sampler.
 * WPI Bootstrap Version: 1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) { return; }

$wpi_basename = '{{PLUGIN_BASENAME}}';
$wpi_deep = false;
$wpi_exclude = '';
$wpi_diag = isset( $_GET['wpi_diag'], $_GET['wpi_ts'], $_GET['wpi_sig'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( $wpi_diag ) {
    $wpi_secret = get_option( 'wpi_secret', '' );
    $wpi_ts = absint( $_GET['wpi_ts'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wpi_sig = sanitize_text_field( wp_unslash( $_GET['wpi_sig'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wpi_exclude = isset( $_GET['wpi_exclude'] ) ? sanitize_text_field( wp_unslash( $_GET['wpi_exclude'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $wpi_path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) : '/';
    $wpi_expected = hash_hmac( 'sha256', $wpi_ts . '|' . $wpi_path . '|' . $wpi_exclude, (string) $wpi_secret );
    $wpi_deep = $wpi_secret && abs( time() - $wpi_ts ) <= 120 && hash_equals( $wpi_expected, $wpi_sig );
}

$wpi_sample = false;
if ( ! $wpi_deep ) {
    $wpi_runtime = get_option( 'wpi_runtime', array() );
    $wpi_rate = max( 0, min( 1, (float) ( $wpi_runtime['sample_rate'] ?? 0 ) ) );
    if ( $wpi_rate > 0 ) { $wpi_sample = mt_rand() / mt_getrandmax() <= $wpi_rate; }
}

if ( ! $wpi_deep && ! $wpi_sample ) { return; }

if ( $wpi_deep && ! defined( 'WPI_DEEP_DIAGNOSTIC' ) ) { define( 'WPI_DEEP_DIAGNOSTIC', true ); }
if ( $wpi_sample && ! defined( 'WPI_SAMPLED_REQUEST' ) ) { define( 'WPI_SAMPLED_REQUEST', true ); }
if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); } elseif ( ! SAVEQUERIES && ! defined( 'WPI_QUERY_TIMING_BLOCKED' ) ) { define( 'WPI_QUERY_TIMING_BLOCKED', true ); }
if ( ! defined( 'DONOTCACHEPAGE' ) && $wpi_deep ) { define( 'DONOTCACHEPAGE', true ); }
if ( $wpi_deep ) {
    add_action( 'send_headers', static function () {
        if ( ! headers_sent() ) {
            header( 'X-WPI-Diagnostic: 1' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        }
    }, PHP_INT_MAX );
}

$GLOBALS['wpi_diag_start'] = microtime( true );
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
