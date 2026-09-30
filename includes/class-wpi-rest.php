<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPI_REST {
    public static function load_diagnostics() {
        foreach ( array(
            'class-wpi-database-health.php', 'class-wpi-database-backup.php', 'class-wpi-database-repair.php',
            'class-wpi-system-health.php', 'class-wpi-job-health.php', 'class-wpi-cache-health.php',
            'class-wpi-frontend-health.php', 'class-wpi-regression.php', 'class-wpi-scanner.php',
        ) as $file ) { require_once WPI_DIR . 'includes/' . $file; }
    }

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'rum_script' ) );
    }

    public static function routes() {
        register_rest_route( 'wpi/v1', '/scan', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'scan' ), 'permission_callback' => array( __CLASS__, 'manage' ) ) );
        register_rest_route( 'wpi/v1', '/rum', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rum' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'wpi/v1', '/rum-token', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rum_token' ), 'permission_callback' => '__return_true' ) );
        register_rest_route( 'wpi/v1', '/autoload', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'autoload' ), 'permission_callback' => array( __CLASS__, 'manage' ) ) );
        register_rest_route( 'wpi/v1', '/backups', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'backup_create' ), 'permission_callback' => array( __CLASS__, 'manage' ) ) );
        register_rest_route( 'wpi/v1', '/backups/(?P<id>\d+)/step', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'backup_step' ), 'permission_callback' => array( __CLASS__, 'manage' ) ) );
        register_rest_route( 'wpi/v1', '/backups/(?P<id>\d+)/verify', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'backup_verify' ), 'permission_callback' => array( __CLASS__, 'manage' ) ) );
    }

    public static function manage() { return current_user_can( 'manage_options' ); }
    public static function scan( WP_REST_Request $req ) { self::load_diagnostics(); return rest_ensure_response( WPI_Scanner::scan( (bool) $req->get_param( 'deep' ), false ) ); }


    public static function backup_create( WP_REST_Request $req ) {
        self::load_diagnostics();
        $scope = sanitize_key( (string) $req->get_param( 'scope' ) );
        $result = WPI_Database_Backup::create( $scope );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( self::backup_response( $result ) );
    }

    public static function backup_step( WP_REST_Request $req ) {
        self::load_diagnostics();
        $result = WPI_Database_Backup::step( absint( $req['id'] ) );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( self::backup_response( $result ) );
    }

    public static function backup_verify( WP_REST_Request $req ) {
        self::load_diagnostics();
        $result = WPI_Database_Backup::verify( absint( $req['id'] ) );
        if ( is_wp_error( $result ) ) { return $result; }
        return rest_ensure_response( self::backup_response( $result ) );
    }

    private static function backup_response( array $backup ) {
        $total = max( 1, (int) ( $backup['table_count'] ?? 0 ) );
        $done = min( $total, (int) ( $backup['tables_done'] ?? 0 ) );
        $state = json_decode( (string) ( $backup['state_json'] ?? '' ), true );
        $state = is_array( $state ) ? $state : array();
        $step_rows = max( 0, (int) ( $state['last_step_rows'] ?? 0 ) );
        $step_ms = max( 0, (float) ( $state['last_step_ms'] ?? 0 ) );
        $step_bytes = max( 0, (int) ( $state['last_step_bytes'] ?? 0 ) );
        $rate = $step_ms > 0 ? round( $step_rows / ( $step_ms / 1000 ), 1 ) : 0;
        $byte_rate = $step_ms > 0 ? (int) round( $step_bytes / ( $step_ms / 1000 ) ) : 0;
        $estimated_total = max( 0, (int) ( $state['estimated_total_rows'] ?? 0 ) );
        $estimated_done = 0;
        $estimates = (array) ( $state['row_estimates'] ?? array() );
        $tables = array_values( (array) ( $state['tables'] ?? array() ) );
        for ( $i = 0; $i < $done; $i++ ) { $estimated_done += max( 0, (int) ( $estimates[ $tables[ $i ] ?? '' ] ?? 0 ) ); }
        $current_estimate = max( 0, (int) ( $estimates[ $backup['current_table'] ?? '' ] ?? 0 ) );
        $current_rows = max( 0, (int) ( $state['table_rows_exported'] ?? 0 ) );
        if ( $current_estimate > 0 ) { $estimated_done += min( $current_estimate, $current_rows ); }
        $estimated_progress = $estimated_total > 0 ? round( min( 99.9, 100 * $estimated_done / $estimated_total ), 1 ) : round( 100 * $done / $total, 1 );
        if ( 'verified' === (string) ( $backup['status'] ?? '' ) || 'ready_verify' === (string) ( $backup['status'] ?? '' ) ) { $estimated_progress = 100.0; }
        return array(
            'id' => (int) ( $backup['id'] ?? 0 ),
            'status' => sanitize_key( (string) ( $backup['status'] ?? '' ) ),
            'scope' => sanitize_key( (string) ( $backup['scope'] ?? '' ) ),
            'filename' => sanitize_file_name( (string) ( $backup['filename'] ?? '' ) ),
            'size_bytes' => (int) ( $backup['size_bytes'] ?? 0 ),
            'table_count' => $total,
            'tables_done' => $done,
            'row_count' => (int) ( $backup['row_count'] ?? 0 ),
            'current_table' => sanitize_text_field( (string) ( $backup['current_table'] ?? '' ) ),
            'progress' => $estimated_progress,
            'table_progress' => $current_estimate > 0 ? round( min( 100, 100 * $current_rows / $current_estimate ), 1 ) : null,
            'current_table_rows' => $current_rows,
            'current_table_estimate' => $current_estimate,
            'batch_rows' => max( 0, (int) ( $state['batch_rows'] ?? 0 ) ),
            'last_step_rows' => $step_rows,
            'last_step_ms' => $step_ms,
            'last_step_bytes' => $step_bytes,
            'rows_per_second' => $rate,
            'bytes_per_second' => $byte_rate,
            'sha256' => sanitize_text_field( (string) ( $backup['sha256'] ?? '' ) ),
            'verified_at' => sanitize_text_field( (string) ( $backup['verified_at'] ?? '' ) ),
            'error' => sanitize_text_field( (string) ( $backup['error_text'] ?? '' ) ),
        );
    }

    public static function rum_script() {
        if ( is_user_logged_in() || is_admin() ) { return; }
        $r = get_option( 'wpi_runtime', array() );
        $rate = max( 0, min( 1, (float) ( $r['rum_rate'] ?? 0.005 ) ) );
        if ( $rate <= 0 ) { return; }
        wp_enqueue_script( 'wpi-web-vitals', WPI_URL . 'assets/vendor/web-vitals/web-vitals.iife.js', array(), '6.2.1', true );
        wp_enqueue_script( 'wpi-rum', WPI_URL . 'assets/js/rum.js', array( 'wpi-web-vitals' ), WPI_VERSION, true );
        wp_localize_script( 'wpi-rum', 'wpiRum', array(
            'endpoint' => rest_url( 'wpi/v1/rum' ),
            'tokenEndpoint' => rest_url( 'wpi/v1/rum-token' ),
            'rate' => $rate,
            'route_group' => WPI_Utils::route_group(),
        ) );
    }

    public static function rum_token( WP_REST_Request $req ) {
        if ( ! self::rate_limit( 'token', 30 ) ) { return new WP_Error( 'wpi_rum_rate', __( 'RUM token rate limit reached.', 'wp-performance-inspector' ), array( 'status' => 429 ) ); }
        $route = self::clean_route_group( $req->get_param( 'route_group' ) );
        $nonce = wp_generate_password( 24, false, false );
        $expires = time() + 10 * MINUTE_IN_SECONDS;
        $secret = (string) get_option( 'wpi_secret' );
        $token = hash_hmac( 'sha256', 'rum|' . $nonce . '|' . $route . '|' . $expires, $secret );
        set_transient( 'wpi_rum_' . md5( $nonce ), array( 'route' => $route, 'expires' => $expires ), 10 * MINUTE_IN_SECONDS );
        $response = new WP_REST_Response( array( 'nonce' => $nonce, 'token' => $token, 'expires' => $expires, 'route_group' => $route ) );
        $response->header( 'Cache-Control', 'no-store, private, max-age=0' );
        return $response;
    }

    public static function rum( WP_REST_Request $req ) {
        $m = $req->get_json_params();
        if ( ! self::rate_limit( 'ingest', 60 ) ) { return new WP_Error( 'wpi_rum_rate', __( 'RUM ingestion rate limit reached.', 'wp-performance-inspector' ), array( 'status' => 429 ) ); }
        if ( ! is_array( $m ) || ! self::valid_rum_token( $m ) ) { return new WP_Error( 'wpi_rum_token', __( 'Invalid or expired RUM token.', 'wp-performance-inspector' ), array( 'status' => 403 ) ); }
        $route_group = self::clean_route_group( $m['route_group'] ?? '' );
        $metric_version = 2 === (int) ( $m['metric_version'] ?? 1 ) ? 2 : 1;
        $hash = md5( $metric_version . '|' . $route_group );
        $bucket = gmdate( 'Y-m-d H:00:00' );
        $limits = array( 'ttfb' => 600000, 'fcp' => 600000, 'lcp' => 600000, 'inp' => 600000, 'cls' => 10 );
        $values = array();
        foreach ( $limits as $metric => $limit ) {
            if ( ! isset( $m[ $metric ] ) || ! is_numeric( $m[ $metric ] ) ) { continue; }
            $values[ $metric ] = max( 0, min( $limit, (float) $m[ $metric ] ) );
        }
        if ( ! $values ) { return rest_ensure_response( array( 'ok' => true, 'stored' => 0 ) ); }

        global $wpdb;
        $table = WPI_Utils::table( 'metrics' );
        foreach ( $values as $metric => $value ) {
            $bucket_index = self::metric_bucket( $metric, $value );
            $bucket_column = 'bucket_' . $bucket_index;
            $sql = "INSERT INTO {$table} (bucket,metric,route_hash,route_group,metric_version,samples,value_sum,value_max,{$bucket_column}) VALUES (%s,%s,%s,%s,%d,1,%f,%f,1) ON DUPLICATE KEY UPDATE route_group=VALUES(route_group),samples=samples+1,value_sum=value_sum+VALUES(value_sum),value_max=GREATEST(value_max,VALUES(value_max)),{$bucket_column}={$bucket_column}+1";
            $wpdb->query( $wpdb->prepare( $sql, $bucket, $metric, $hash, $route_group, $metric_version, $value, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        return rest_ensure_response( array( 'ok' => true, 'stored' => count( $values ) ) );
    }

    private static function valid_rum_token( array $payload ) {
        $secret = (string) get_option( 'wpi_secret' );
        $token = sanitize_text_field( (string) ( $payload['token'] ?? '' ) );
        $nonce = sanitize_text_field( (string) ( $payload['nonce'] ?? '' ) );
        $expires = absint( $payload['expires'] ?? 0 );
        $route = self::clean_route_group( $payload['route_group'] ?? '' );
        if ( ! $secret || ! preg_match( '/^[A-Za-z0-9]{12,40}$/', $nonce ) || ! $token || $expires < time() || $expires > time() + 11 * MINUTE_IN_SECONDS ) { return false; }
        $stored = get_transient( 'wpi_rum_' . md5( $nonce ) );
        if ( ! is_array( $stored ) || $route !== (string) ( $stored['route'] ?? '' ) || $expires !== (int) ( $stored['expires'] ?? 0 ) ) { return false; }
        if ( ! hash_equals( hash_hmac( 'sha256', 'rum|' . $nonce . '|' . $route . '|' . $expires, $secret ), $token ) ) { return false; }
        delete_transient( 'wpi_rum_' . md5( $nonce ) );
        return true;
    }

    private static function clean_route_group( $route ) {
        $route = sanitize_text_field( (string) $route );
        return preg_match( '/^[a-z0-9:_-]{1,80}$/', $route ) ? $route : 'frontend:other';
    }

    private static function metric_bucket( $metric, $value ) {
        $limits = 'cls' === $metric ? array( 0.05, 0.1, 0.15, 0.25, 0.5, 1, 2, 10 ) : array( 100, 200, 500, 1000, 2500, 4000, 10000, 600000 );
        foreach ( $limits as $index => $limit ) { if ( $value <= $limit ) { return $index; } }
        return 7;
    }

    private static function rate_limit( $scope, $limit ) {
        $ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
        $key = 'wpi_rl_' . sanitize_key( $scope ) . '_' . md5( $ip . '|' . (string) get_option( 'wpi_secret' ) );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) { return false; }
        set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
        return true;
    }

    public static function autoload( WP_REST_Request $req ) {
        $name = sanitize_text_field( $req->get_param( 'option' ) );
        $enable = (bool) $req->get_param( 'autoload' );
        $protected = array( 'siteurl','home','active_plugins','template','stylesheet','cron','rewrite_rules','wpi_runtime','wpi_secret' );
        if ( ! $name || in_array( $name, $protected, true ) ) { return new WP_Error( 'wpi_protected', 'This option is protected.', array( 'status' => 400 ) ); }
        global $wpdb;
        $before = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $name ) );
        if ( null === $before ) { return new WP_Error( 'wpi_missing', 'Option not found.', array( 'status' => 404 ) ); }
        if ( function_exists( 'wp_set_option_autoload' ) ) { $ok = wp_set_option_autoload( $name, $enable ); }
        else { $ok = false !== $wpdb->update( $wpdb->options, array( 'autoload' => $enable ? 'yes' : 'no' ), array( 'option_name' => $name ) ); }
        if ( $ok ) {
            $wpdb->insert( WPI_Utils::table( 'changes' ), array( 'created_at' => WPI_Utils::now_mysql(), 'change_type' => 'autoload', 'object_name' => $name, 'before_value' => (string) $before, 'after_value' => $enable ? 'yes' : 'no', 'user_id' => get_current_user_id() ) );
        }
        return rest_ensure_response( array( 'ok' => (bool) $ok ) );
    }
}
