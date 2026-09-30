<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$scenario = $argv[1] ?? 'scan-absent';
function current_user_can( $cap ) { return true; }
function check_admin_referer( $action ) {}
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function wp_unslash( $value ) { return $value; }
function __( $value, $domain = '' ) { return $value; }
function wp_parse_url( $value, $component ) { return parse_url( $value, $component ); }
function home_url( $path = '' ) { return 'http://example.test:8080' . $path; }
function admin_url( $path = '' ) { return home_url( '/wp-admin/' . $path ); }
function esc_url_raw( $url, $protocols = array() ) { return $url; }
function trailingslashit( $v ) { return rtrim( $v, '/' ) . '/'; }
function get_current_user_id() { return 1; }
function set_transient( ...$args ) {}
class RedirectCaptured extends RuntimeException {}
function wp_safe_redirect( $url ) { throw new RedirectCaptured(); }
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_generate_uuid4() { return '12345678-1234-1234-1234-123456789abc'; }
function add_query_arg( $token, $url ) { return $url; }
function apply_filters( $name, $value ) { return $value; }
function wp_remote_get( ...$args ) { return new WP_Error(); }
class WPI_REST { public static function load_diagnostics() {} }
class WPI_Bootstrap { public static function token( ...$args ) { return array(); } }
class WPI_Utils {
    public static $status = 'open';
    public static function incident( $key ) {
        global $scenario;
        return array( 'status' => self::$status, 'source' => 0 === strpos( $scenario, 'scan-' ) ? 'scan' : 'manual', 'routes' => array( 'http://example.test:8080/' ) );
    }
    public static function set_incident_status( $key, $status ) { self::$status = $status; }
    public static function finish_verification( $key ) { throw new RuntimeException( 'Must not resolve an unproven recheck' ); }
}
class WPI_Scanner {
    public static function scan( ...$args ) {
        global $scenario;
        if ( 0 !== strpos( $scenario, 'scan-' ) ) { throw new RuntimeException( 'No unrelated scan fallback allowed' ); }
        return array( 'observed_incidents' => 'scan-present' === $scenario ? array( str_repeat( 'a', 32 ) ) : array() );
    }
}
require dirname( __DIR__ ) . '/includes/class-wpi-admin.php';
$_POST = array( 'incident_key' => str_repeat( 'a', 32 ), 'incident_action' => 'verify' );
try { WPI_Admin::incident_action_post(); }
catch ( RedirectCaptured $e ) { echo WPI_Utils::$status; }
