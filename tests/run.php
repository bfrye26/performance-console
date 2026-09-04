<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function esc_url_raw( $url, $protocols = null ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? (string) $url : ''; }
function home_url( $path = '' ) { return 'http://example.test:8080' . $path; }

require_once dirname( __DIR__ ) . '/includes/class-wpi-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-wpi-database-health.php';
require_once dirname( __DIR__ ) . '/includes/class-wpi-profiler.php';

$tests = array();
function test_case( string $name, callable $callback ): void { global $tests; $tests[] = array( $name, $callback ); }
function assert_same( $expected, $actual, string $message = '' ): void {
    if ( $expected !== $actual ) { throw new RuntimeException( $message ?: 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}

test_case( 'idle database daemons are not actionable', static function (): void {
    assert_same( false, WPI_Database_Health::is_actionable_process( 'Daemon', 'Waiting on empty queue', '' ) );
    assert_same( false, WPI_Database_Health::is_actionable_process( 'Sleep', '', '' ) );
    assert_same( true, WPI_Database_Health::is_actionable_process( 'Query', 'Sending data', 'SELECT * FROM wp_posts' ) );
} );

test_case( 'optional database capability failures are ignored', static function (): void {
    assert_same( true, WPI_Profiler::is_expected_diagnostic_error( 'SELECT * FROM information_schema.INNODB_LOCK_WAITS', "Unknown table 'INNODB_LOCK_WAITS'" ) );
    assert_same( false, WPI_Profiler::is_expected_diagnostic_error( 'SELECT * FROM wp_posts', 'Table is marked as crashed' ) );
} );

test_case( 'root-cause incidents group routes but preserve remote hosts', static function (): void {
    $first = WPI_Utils::incident_key( 'http', 'Outbound HTTP request failed from aawp', 'tools.keycdn.com/geo.json timed out' );
    $second = WPI_Utils::incident_key( 'http', 'Outbound HTTP request failed from aawp', 'tools.keycdn.com/geo.json timed out again' );
    $other = WPI_Utils::incident_key( 'http', 'Outbound HTTP request failed from aawp', 'api.example.org/geo failed' );
    assert_same( $first, $second );
    assert_same( false, $first === $other );
} );

test_case( 'RUM p75 uses histogram buckets', static function (): void {
    $row = array( 'metric' => 'lcp', 'samples' => 100, 'bucket_0' => 10, 'bucket_1' => 20, 'bucket_2' => 30, 'bucket_3' => 20, 'bucket_4' => 20 );
    assert_same( 1000, WPI_Utils::metric_percentile( $row, 0.75 ) );
} );

test_case( 'same-origin checks include scheme and port', static function (): void {
    assert_same( true, WPI_Utils::same_origin_url( 'http://example.test:8080/review/example/' ) );
    assert_same( false, WPI_Utils::same_origin_url( 'http://example.test/review/example/' ) );
    assert_same( false, WPI_Utils::same_origin_url( 'https://example.test:8080/review/example/' ) );
} );

test_case( 'backup primary-key discovery uses portable SHOW INDEX syntax', static function (): void {
    $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wpi-database-backup.php' );
    assert_same( false, false !== strpos( $source, "WHERE Key_name='PRIMARY' ORDER BY" ) );
} );

$failures = 0;
foreach ( $tests as $test ) {
    list( $name, $callback ) = $test;
    try { $callback(); echo "PASS: {$name}\n"; }
    catch ( Throwable $error ) { $failures++; fwrite( STDERR, "FAIL: {$name}: {$error->getMessage()}\n" ); }
}
echo count( $tests ) . " tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
