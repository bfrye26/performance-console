<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'DB_NAME', 'test-fixture-only' );
define( 'ARRAY_A', 'ARRAY_A' );
function absint( $v ) { return abs( (int) $v ); }
function trailingslashit( $v ) { return rtrim( $v, '/\\' ) . '/'; }
function untrailingslashit( $v ) { return rtrim( $v, '/\\' ); }
function apply_filters( $name, $value ) { global $fixture_dir; return 'pfc_backup_storage_candidates' === $name ? array( $fixture_dir ) : $value; }
class WP_Error { public $code; public function __construct( $code, $message ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class PFC_Utils { public static function table( $name ) { return $name; } }
require dirname( __DIR__ ) . '/includes/class-pfc-database-backup.php';
$fixture_dir = sys_get_temp_dir() . '/pfc-integrity-test-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture_dir, 0700 );
$fixture_path = $fixture_dir . '/pfc-db-20260908-120000-abcdef.sql';
$contents = str_repeat( '-', 150 ) . "\n-- fixture-complete\n";
$wpdb = new class {
    public $backup;
    public function prepare( ...$args ) { return ''; }
    public function get_row( ...$args ) { return $this->backup; }
    public function update( ...$args ) { throw new RuntimeException( 'Must not replace the stored checksum' ); }
};
try {
    file_put_contents( $fixture_path, $contents );
    $wpdb->backup = array( 'id' => 1, 'status' => 'verified', 'verified_at' => gmdate( 'Y-m-d H:i:s' ), 'sha256' => hash( 'sha256', $contents ), 'filename' => basename( $fixture_path ), 'file_path' => $fixture_path, 'size_bytes' => strlen( $contents ), 'tables_done' => 1, 'table_count' => 1, 'state_json' => json_encode( array( 'marker' => 'fixture-complete' ) ) );
    $intact = PFC_Database_Backup::is_verified_recent( 1 );
    file_put_contents( $fixture_path, 'x' . substr( $contents, 1 ) );
    $changed = PFC_Database_Backup::is_verified_recent( 1 );
    $reverify = PFC_Database_Backup::verify( 1 );
    echo json_encode( array( 'intact' => $intact, 'changed' => $changed, 'reverify' => $reverify instanceof WP_Error ? $reverify->code : 'unexpected-success' ) );
} finally {
    if ( is_file( $fixture_path ) ) { unlink( $fixture_path ); }
    rmdir( $fixture_dir );
}
