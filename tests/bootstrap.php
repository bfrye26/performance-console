<?php
// Run in a fresh PHP process: the production bootstrap uses request-wide constants.
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
$scenario = $argv[1] ?? 'inactive';
$GLOBALS['pfc_option_reads'] = array();
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function get_option( $key, $default = false ) {
    global $scenario;
    $GLOBALS['pfc_option_reads'][] = (string) $key;
    if ( 'active_plugins' === $key ) { return in_array( $scenario, array( 'declined', 'sampled', 'diag-secret' ), true ) ? array( '{{PLUGIN_BASENAME}}' ) : array(); }
    if ( 'pfc_runtime' === $key ) { return array( 'sample_rate' => 'sampled' === $scenario ? 1 : 0 ); }
    if ( 'pfc_secret' === $key ) { return 'test-secret'; }
    return $default;
}
function is_multisite() { global $scenario; return 'network' === $scenario; }
function get_site_option( $key, $default ) { return array( '{{PLUGIN_BASENAME}}' => 1 ); }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
if ( 'diag-secret' === $scenario ) {
    $pfc_ts = time();
    $_GET = array( 'pfc_diag' => '1', 'pfc_ts' => (string) $pfc_ts, 'pfc_sig' => hash_hmac( 'sha256', $pfc_ts . '|/||', 'test-secret' ) );
    $_SERVER['REQUEST_URI'] = '/';
}
require dirname( __DIR__ ) . '/mu-plugin/performance-console-bootstrap.php';
if ( 'declined' === $scenario ) {
    // Even changing the fallback rate to 100% must not sample a declined request again.
    $scenario = 'sampled';
    require dirname( __DIR__ ) . '/includes/class-pfc-profiler.php';
    PFC_Profiler::init();
}
$secret_reads = count( array_filter( $GLOBALS['pfc_option_reads'], static function ( $key ) { return 'pfc_secret' === $key; } ) );
echo json_encode( array( 'decided' => defined( 'PFC_SAMPLING_DECIDED' ), 'queries' => defined( 'SAVEQUERIES' ), 'sampled' => defined( 'PFC_SAMPLED_REQUEST' ), 'secret_reads' => $secret_reads ) );
