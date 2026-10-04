<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'test-fixture-only' );
define( 'ARRAY_A', 'ARRAY_A' );
function absint( $v ) { return abs( (int) $v ); }
function trailingslashit( $v ) { return rtrim( $v, '/\\' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( $v, '/\\' ); }
function apply_filters( $name, $value ) { return $value; }
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class PFC_Utils {
    public static function table( $name ) { return $name; }
    public static function now_mysql() { return gmdate( 'Y-m-d H:i:s' ); }
}
require dirname( __DIR__ ) . '/includes/class-pfc-database-backup.php';

$fixture_root = sys_get_temp_dir() . '/pfc-legacy-backup-test-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture_root, 0700 );
define( 'WP_CONTENT_DIR', $fixture_root );
$hash = substr( hash( 'sha256', ABSPATH . DB_NAME ), 0, 16 );
$legacy_dir = $fixture_root . '/wpi-private-backups-' . $hash;
mkdir( $legacy_dir, 0700 );
$fixture_path = $legacy_dir . '/wpi-db-20260908-120000-abcdef.sql';
$contents = str_repeat( '-', 150 ) . "\n-- legacy-migration-marker\n";
file_put_contents( $fixture_path, $contents );
$wpdb = new class {
    public $backup;
    public function prepare( ...$args ) { return ''; }
    public function get_row( ...$args ) { return $this->backup; }
    public function update( ...$args ) { return true; }
};
$wpdb->backup = array(
    'id' => 1,
    'status' => 'ready_verify',
    'verified_at' => '',
    'sha256' => '',
    'filename' => basename( $fixture_path ),
    'file_path' => $fixture_path,
    'size_bytes' => strlen( $contents ),
    'tables_done' => 1,
    'table_count' => 1,
    'state_json' => json_encode( array( 'marker' => 'legacy-migration-marker' ) ),
);
try {
    $result = PFC_Database_Backup::verify( 1 );
    echo $result instanceof WP_Error ? $result->code : 'accepted';
} finally {
    if ( is_file( $fixture_path ) ) { unlink( $fixture_path ); }
    rmdir( $legacy_dir );
    rmdir( $fixture_root );
}
