<?php
// Run in a fresh PHP process: the production bootstrap uses request-wide constants.
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
$scenario = $argv[1] ?? 'inactive';
function get_option( $key, $default = false ) {
    global $scenario;
    if ( 'active_plugins' === $key ) { return in_array( $scenario, array( 'declined', 'sampled' ), true ) ? array( '{{PLUGIN_BASENAME}}' ) : array(); }
    if ( 'wpi_runtime' === $key ) { return array( 'sample_rate' => 'sampled' === $scenario ? 1 : 0 ); }
    return $default;
}
function is_multisite() { global $scenario; return 'network' === $scenario; }
function get_site_option( $key, $default ) { return array( '{{PLUGIN_BASENAME}}' => 1 ); }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
require dirname( __DIR__ ) . '/mu-plugin/wp-performance-inspector-bootstrap.php';
if ( 'declined' === $scenario ) {
    // Even changing the fallback rate to 100% must not sample a declined request again.
    $scenario = 'sampled';
    require dirname( __DIR__ ) . '/includes/class-wpi-profiler.php';
    WPI_Profiler::init();
}
echo json_encode( array( 'decided' => defined( 'WPI_SAMPLING_DECIDED' ), 'queries' => defined( 'SAVEQUERIES' ), 'sampled' => defined( 'WPI_SAMPLED_REQUEST' ) ) );
