<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function esc_url_raw( $url, $protocols = null ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? (string) $url : ''; }
function home_url( $path = '' ) { return 'http://example.test:8080' . $path; }

require_once dirname( __DIR__ ) . '/includes/class-wpi-utils.php';
require_once dirname( __DIR__ ) . '/includes/class-wpi-bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-wpi-database-health.php';
require_once dirname( __DIR__ ) . '/includes/class-wpi-database-repair.php';
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

test_case( 'median handles even and odd sample counts', static function (): void {
    assert_same( 3.0, WPI_Utils::median( array( 9, 1, 3 ) ) );
    assert_same( 2.5, WPI_Utils::median( array( 4, 1, 3, 2 ) ) );
} );

test_case( 'paired impact rejects timing noise', static function (): void {
    $analysis = WPI_Utils::analyze_paired_impact( array( 72, -70, 60, -65, 3 ), true );
    assert_same( false, $analysis['repeatable'] );
    assert_same( 'low', $analysis['confidence'] );
} );

test_case( 'paired impact recognizes a stable plugin cost', static function (): void {
    $analysis = WPI_Utils::analyze_paired_impact( array( 11, 10, 12, 9, 11 ), true );
    assert_same( 11.0, $analysis['delta'] );
    assert_same( true, $analysis['repeatable'] );
    assert_same( 'high', $analysis['confidence'] );
} );

test_case( 'save capture tokens are signed, scoped and expiring', static function (): void {
    $id = '12345678-1234-1234-1234-123456789abc';
    $value = WPI_Bootstrap::save_capture_value( 7, 'manual', 1600, $id, 'test-secret' );
    $capture = WPI_Bootstrap::parse_save_capture_value( $value, 'test-secret', 1000 );
    assert_same( 7, $capture['user_id'] );
    assert_same( 'manual', $capture['kind'] );
    assert_same( false, WPI_Bootstrap::parse_save_capture_value( $value . 'x', 'test-secret', 1000 ) );
    assert_same( false, WPI_Bootstrap::parse_save_capture_value( $value, 'test-secret', 1700 ) );
} );

test_case( 'save requests distinguish manual, REST and autosave traffic', static function (): void {
    $classic = WPI_Utils::save_request_context( 'POST', '/wp-admin/post.php', array( 'action' => 'editpost', 'post_ID' => 42, 'post_type' => 'post' ) );
    $rest = WPI_Utils::save_request_context( 'PUT', '/wp-json/wp/v2/posts/42', array() );
    $autosave = WPI_Utils::save_request_context( 'POST', '/wp-json/wp/v2/posts/42/autosaves', array() );
    assert_same( 'classic-editor', $classic['kind'] );
    assert_same( 'block-editor-rest', $rest['kind'] );
    assert_same( 'block-editor-autosave', $autosave['kind'] );
    assert_same( true, WPI_Utils::save_context_matches_capture( $classic, 'manual' ) );
    assert_same( false, WPI_Utils::save_context_matches_capture( $autosave, 'manual' ) );
    assert_same( false, WPI_Utils::save_request_context( 'GET', '/wp-json/wp/v2/posts/42', array() ) );
    assert_same( false, WPI_Utils::save_request_context( 'POST', '/wp-json/wp/v2/settings', array() ) );
} );

test_case( 'backup primary-key discovery uses portable SHOW INDEX syntax', static function (): void {
    $source = file_get_contents( dirname( __DIR__ ) . '/includes/class-wpi-database-backup.php' );
    assert_same( false, false !== strpos( $source, "WHERE Key_name='PRIMARY' ORDER BY" ) );
} );

test_case( 'save success requires a matching write and successful response', static function (): void {
    $context = array( 'post_id' => 42 );
    assert_same( true, WPI_Profiler::save_outcome( $context, array( 42 => 'post' ), 302 )['successful'] );
    assert_same( false, WPI_Profiler::save_outcome( $context, array(), 200 )['successful'] );
    assert_same( false, WPI_Profiler::save_outcome( $context, array( 43 => 'post' ), 200 )['successful'] );
    assert_same( false, WPI_Profiler::save_outcome( $context, array( 42 => 'post' ), 403 )['successful'] );
    assert_same( false, WPI_Profiler::save_outcome( $context, array( 42 => 'post' ), 200, true )['successful'] );
} );

test_case( 'save comparisons reject different editors, posts, users and kinds', static function (): void {
    $context = array( 'post_id' => 42, 'post_type' => 'post', 'requested_kind' => 'manual', 'kind' => 'classic-editor', 'user_id' => 7 );
    assert_same( true, WPI_Profiler::comparable_save( $context, $context ) );
    foreach ( array_keys( $context ) as $key ) {
        $different = $context; $different[ $key ] = 'different';
        assert_same( false, WPI_Profiler::comparable_save( $context, $different ) );
    }
    assert_same( false, WPI_Profiler::comparable_save( array(), $context ) );
} );

test_case( 'save parser rejects taxonomy and nested non-save REST endpoints', static function (): void {
    foreach ( array( '/wp-json/wp/v2/categories/42', '/wp-json/wp/v2/tags/42', '/wp-json/wp/v2/posts/42/revisions', '/wp-json/wp/v2/posts/42/arbitrary' ) as $uri ) {
        assert_same( false, WPI_Utils::save_request_context( 'POST', $uri, array() ) );
    }
} );

foreach ( array(
    'inactive' => array( 'decided' => false, 'queries' => false, 'sampled' => false, 'secret_reads' => 0 ),
    'declined' => array( 'decided' => true, 'queries' => false, 'sampled' => false, 'secret_reads' => 0 ),
    'network' => array( 'decided' => true, 'queries' => false, 'sampled' => false, 'secret_reads' => 0 ),
    'sampled' => array( 'decided' => true, 'queries' => true, 'sampled' => true, 'secret_reads' => 0 ),
) as $scenario => $expected ) {
    test_case( 'production MU bootstrap: ' . $scenario, static function () use ( $scenario, $expected ): void {
        $output = array(); $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/bootstrap.php' ) . ' ' . escapeshellarg( $scenario ), $output, $code );
        assert_same( 0, $code );
        assert_same( $expected, json_decode( implode( '', $output ), true ) );
    } );
}

test_case( 'deep diagnostics load the secret on demand only', static function (): void {
    $output = array(); $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/bootstrap.php' ) . ' diag-secret', $output, $code );
    assert_same( 0, $code );
    assert_same( array( 'decided' => true, 'queries' => true, 'sampled' => false, 'secret_reads' => 1 ), json_decode( implode( '', $output ), true ) );
} );

test_case( 'autoload reviews require real frontend and admin coverage', static function (): void {
    assert_same( false, WPI_Utils::autoload_review_ready( array(), 30 ) );
    assert_same( false, WPI_Utils::autoload_review_ready( array( 'total' => 100, 'frontend' => 100, 'admin' => 0 ), 30 ) );
    assert_same( false, WPI_Utils::autoload_review_ready( array( 'total' => 100, 'frontend' => 20, 'admin' => 20 ), 6 ) );
    assert_same( true, WPI_Utils::autoload_review_ready( array( 'total' => 100, 'frontend' => 20, 'admin' => 20 ), 7 ) );
} );

test_case( 'plugin impact content assertions reject missing output', static function (): void {
    assert_same( true, WPI_Utils::response_contains_text( '<h1>Review &amp; test</h1>', 'Review & test' ) );
    assert_same( true, WPI_Utils::response_contains_text( "<h1>Review\n  test</h1>", 'Review test' ) );
    assert_same( false, WPI_Utils::response_contains_text( '<h1>Error</h1>', 'Review' ) );
    assert_same( true, WPI_Utils::response_contains_text( '<h1>Anything</h1>', '' ) );
} );

test_case( 'backup checksum detects same-size changes and rejects re-verification', static function (): void {
    $output = array(); $code = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/backup-integrity.php' ), $output, $code );
    assert_same( 0, $code );
    assert_same( array( 'intact' => true, 'changed' => false, 'reverify' => 'wpi_backup_changed' ), json_decode( implode( '', $output ), true ) );
} );

foreach ( array( 'scan-absent' => 'observing', 'scan-present' => 'open', 'failed-probe' => 'open' ) as $scenario => $expected ) {
    test_case( 'incident recheck: ' . $scenario, static function () use ( $scenario, $expected ): void {
        $output = array(); $code = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/incident-actions.php' ) . ' ' . escapeshellarg( $scenario ), $output, $code );
        assert_same( 0, $code );
        assert_same( $expected, implode( '', $output ) );
    } );
}

test_case( 'autoload protection is shared between REST and the repair centre', static function (): void {
    assert_same( true, WPI_Database_Repair::protected_option( 'blogname' ) );
    assert_same( true, WPI_Database_Repair::protected_option( 'wpi_secret' ) );
    assert_same( true, WPI_Database_Repair::protected_option( 'widget_block' ) );
    assert_same( false, WPI_Database_Repair::protected_option( 'woocommerce_currency' ) );
    $rest = file_get_contents( dirname( __DIR__ ) . '/includes/class-wpi-rest.php' );
    assert_same( true, false !== strpos( (string) $rest, 'WPI_Database_Repair::protected_option' ) );
} );

test_case( 'MU bootstrap install writes atomically', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-wpi-bootstrap.php' );
    assert_same( false, false !== strpos( $source, 'file_put_contents( self::path()' ) );
    assert_same( true, false !== strpos( $source, 'rename(' ) );
} );

test_case( 'uninstall removes every plugin data store', static function (): void {
    $source = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
    foreach ( array( 'runs', 'queries', 'issues', 'metrics', 'changes', 'option_usage', 'backups' ) as $suffix ) {
        assert_same( true, false !== strpos( $source, "'" . $suffix . "'" ) );
    }
    assert_same( true, false !== strpos( $source, 'wp_clear_scheduled_hook' ) );
    assert_same( true, false !== strpos( $source, '000-wp-performance-inspector-bootstrap.php' ) );
} );

$failures = 0;
foreach ( $tests as $test ) {
    list( $name, $callback ) = $test;
    try { $callback(); echo "PASS: {$name}\n"; }
    catch ( Throwable $error ) { $failures++; fwrite( STDERR, "FAIL: {$name}: {$error->getMessage()}\n" ); }
}
echo count( $tests ) . " tests, {$failures} failures\n";
exit( $failures > 0 ? 1 : 0 );
