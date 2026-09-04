<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPI_Admin {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_wpi_scan', array( __CLASS__, 'scan_post' ) );
        add_action( 'admin_post_wpi_bootstrap', array( __CLASS__, 'bootstrap_post' ) );
        add_action( 'admin_post_wpi_expired_transients', array( __CLASS__, 'expired_transients' ) );
        add_action( 'admin_post_wpi_db_fix', array( __CLASS__, 'database_fix_post' ) );
        add_action( 'admin_post_wpi_profile', array( __CLASS__, 'profile_post' ) );
        add_action( 'admin_post_wpi_plugin_impact', array( __CLASS__, 'plugin_impact_post' ) );
        add_action( 'admin_post_wpi_settings', array( __CLASS__, 'settings_post' ) );
        add_action( 'admin_post_wpi_incident_action', array( __CLASS__, 'incident_action_post' ) );
        add_action( 'admin_post_wpi_backup_download', array( __CLASS__, 'backup_download_post' ) );
        add_action( 'admin_post_wpi_backup_delete', array( __CLASS__, 'backup_delete_post' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    public static function menu() { add_menu_page( 'Performance Inspector', 'Performance', 'manage_options', 'wpi', array( __CLASS__, 'page' ), 'dashicons-performance', 80 ); }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_wpi' !== $hook ) { return; }

        $css_file = WPI_DIR . 'assets/css/admin.css';
        $ui_file  = WPI_DIR . 'assets/js/admin-ui.js';
        $backup_file = WPI_DIR . 'assets/js/admin-backups.js';

        wp_enqueue_style( 'wpi-admin', WPI_URL . 'assets/css/admin.css', array(), file_exists( $css_file ) ? (string) filemtime( $css_file ) : WPI_VERSION );
        wp_enqueue_script( 'wpi-admin-ui', WPI_URL . 'assets/js/admin-ui.js', array(), file_exists( $ui_file ) ? (string) filemtime( $ui_file ) : WPI_VERSION, true );
        wp_enqueue_script( 'wpi-admin-backups', WPI_URL . 'assets/js/admin-backups.js', array(), file_exists( $backup_file ) ? (string) filemtime( $backup_file ) : WPI_VERSION, true );
        wp_localize_script( 'wpi-admin-backups', 'wpiBackupAdmin', array(
            'restRoot' => esc_url_raw( rest_url( 'wpi/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ) );
    }

    public static function backup_download_post() {
        self::cap();
        $id = absint( $_GET['backup_id'] ?? 0 );
        check_admin_referer( 'wpi_backup_download_' . $id );
        $result = WPI_Database_Backup::download( $id );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        exit;
    }

    public static function backup_delete_post() {
        self::cap();
        $id = absint( $_POST['backup_id'] ?? 0 );
        check_admin_referer( 'wpi_backup_delete_' . $id );
        $result = WPI_Database_Backup::delete( $id );
        $message = is_wp_error( $result ) ? $result->get_error_message() : ( $result['message'] ?? 'Backup deleted.' );
        set_transient( 'wpi_backup_notice_' . get_current_user_id(), array( 'ok' => ! is_wp_error( $result ), 'message' => $message ), 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=database#database-backups' ) ); exit;
    }
    private static function cap() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'wp-performance-inspector' ) ); }
        self::load_diagnostics();
    }

    private static function load_diagnostics() { WPI_REST::load_diagnostics(); }

    public static function scan_post() {
        self::cap(); check_admin_referer( 'wpi_scan' );
        $deep = ! empty( $_POST['deep'] );
        WPI_Scanner::scan( $deep, false );
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=' . ( $deep ? 'database' : 'overview' ) . '&scanned=1&mode=' . ( $deep ? 'deep' : 'safe' ) . ( $deep ? '#database' : '#overview' ) ) ); exit;
    }

    public static function bootstrap_post() {
        self::cap(); check_admin_referer( 'wpi_bootstrap' );
        WPI_Bootstrap::install();
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=overview' ) ); exit;
    }

    public static function expired_transients() {
        self::cap(); check_admin_referer( 'wpi_transients' );
        $result = WPI_Database_Repair::execute( 'cleanup_expired_transients', array( 'limit' => 1000 ), false );
        self::store_repair_result( $result, 'cleanup_expired_transients' );
        WPI_Scanner::scan( false, false );
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=database&dbfixed=1#database-repair-centre' ) ); exit;
    }

    public static function database_fix_post() {
        self::cap(); check_admin_referer( 'wpi_db_fix' );
        $action = sanitize_key( wp_unslash( $_POST['repair_action'] ?? '' ) );
        $args = array();
        if ( isset( $_POST['type'] ) ) { $args['type'] = sanitize_key( wp_unslash( $_POST['type'] ) ); }
        if ( isset( $_POST['table'] ) ) { $args['table'] = sanitize_text_field( wp_unslash( $_POST['table'] ) ); }
        if ( isset( $_POST['index'] ) ) { $args['index'] = sanitize_text_field( wp_unslash( $_POST['index'] ) ); }
        if ( isset( $_POST['keep_index'] ) ) { $args['keep_index'] = sanitize_text_field( wp_unslash( $_POST['keep_index'] ) ); }
        if ( isset( $_POST['column'] ) ) { $args['column'] = sanitize_text_field( wp_unslash( $_POST['column'] ) ); }
        if ( isset( $_POST['option'] ) ) { $args['option'] = sanitize_text_field( wp_unslash( $_POST['option'] ) ); }
        if ( isset( $_POST['backup_confirmed'] ) ) { $args['backup_confirmed'] = '1'; }
        if ( isset( $_POST['backup_id'] ) ) { $args['backup_id'] = absint( $_POST['backup_id'] ); }
        if ( isset( $_POST['danger_confirmed'] ) ) { $args['danger_confirmed'] = '1'; }
        if ( isset( $_POST['data_loss_confirmed'] ) ) { $args['data_loss_confirmed'] = '1'; }
        if ( isset( $_POST['limit'] ) ) { $args['limit'] = max( 1, min( WPI_Database_Repair::MAX_BATCH, absint( $_POST['limit'] ) ) ); }
        if ( isset( $_POST['change_id'] ) ) { $args['change_id'] = absint( $_POST['change_id'] ); }
        if ( isset( $_POST['thread_id'] ) ) { $args['thread_id'] = absint( $_POST['thread_id'] ); }
        if ( isset( $_POST['transaction_id'] ) ) { $args['transaction_id'] = sanitize_text_field( wp_unslash( $_POST['transaction_id'] ) ); }
        if ( isset( $_POST['rollback_confirmed'] ) ) { $args['rollback_confirmed'] = '1'; }
        if ( isset( $_POST['high_rollback_confirmed'] ) ) { $args['high_rollback_confirmed'] = '1'; }
        $force_large = ! empty( $_POST['force_large'] ) && ! empty( $_POST['maintenance_confirmed'] );
        if ( $force_large ) {
            $args['maintenance_confirmed'] = '1';
            ignore_user_abort( true );
            @set_time_limit( 0 );
            if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'admin' ); }
        }
        $result = WPI_Database_Repair::execute( $action, $args, $force_large );
        self::store_repair_result( $result, $action );
        WPI_Scanner::scan( false, false );
        $target = is_wp_error( $result ) && 'wpi_innodb_busy' === $result->get_error_code() ? 'innodb-transaction-manager' : 'database-repair-centre';
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=database&dbfixed=1#' . $target ) ); exit;
    }

    private static function store_repair_result( $result, $action ) {
        if ( is_wp_error( $result ) ) {
            $payload = array( 'ok' => false, 'action' => $action, 'error_code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'at' => time() );
            $data = $result->get_error_data();
            if ( is_array( $data ) ) { $payload['error_data'] = $data; }
        } else {
            $payload = array_merge( array( 'ok' => true, 'action' => $action, 'at' => time() ), is_array( $result ) ? $result : array() );
        }
        set_transient( 'wpi_last_db_repair_' . get_current_user_id(), $payload, 10 * MINUTE_IN_SECONDS );
    }

    public static function profile_post() {
        self::cap(); check_admin_referer( 'wpi_profile' );
        $url = self::own_url( isset( $_POST['profile_url'] ) ? wp_unslash( $_POST['profile_url'] ) : home_url( '/' ) );
        if ( is_wp_error( $url ) ) { wp_die( esc_html( $url->get_error_message() ) ); }
        $runs = max( 1, min( 5, absint( $_POST['runs'] ?? 3 ) ) );
        $probe_meta = array();
        $times = self::timed_probe( $url, '', $runs, $probe_meta );
        set_transient( 'wpi_last_profile_' . get_current_user_id(), array( 'url' => $url, 'times' => $times, 'probe_meta' => $probe_meta, 'at' => time() ), 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=profiling&profiled=1#profiling' ) ); exit;
    }

    public static function plugin_impact_post() {
        self::cap(); check_admin_referer( 'wpi_plugin_impact' );
        $url = self::own_url( isset( $_POST['profile_url'] ) ? wp_unslash( $_POST['profile_url'] ) : home_url( '/' ) );
        if ( is_wp_error( $url ) ) { wp_die( esc_html( $url->get_error_message() ) ); }
        $plugin = sanitize_text_field( wp_unslash( $_POST['plugin'] ?? '' ) );
        $active = (array) get_option( 'active_plugins', array() );
        $network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
        if ( ! in_array( $plugin, $active, true ) && ! in_array( $plugin, $network, true ) ) { wp_die( esc_html__( 'Select an active plugin.', 'wp-performance-inspector' ) ); }
        if ( $plugin === WPI_BASENAME ) { wp_die( esc_html__( 'Performance Inspector cannot exclude itself from its own diagnostic request.', 'wp-performance-inspector' ) ); }
        $baseline_meta = array(); $without_meta = array();
        $warmup_meta = array();
        self::timed_probe( $url, '', 1, $warmup_meta );
        self::timed_probe( $url, $plugin, 1, $warmup_meta );
        $baseline = array(); $without = array();
        for ( $pair = 0; $pair < 5; $pair++ ) {
            $first = 0 === $pair % 2 ? '' : $plugin;
            $second = '' === $first ? $plugin : '';
            foreach ( array( $first, $second ) as $exclude ) {
                $probe_meta = array();
                $values = self::timed_probe( $url, $exclude, 1, $probe_meta );
                if ( '' === $exclude ) { $baseline = array_merge( $baseline, $values ); self::merge_probe_meta( $baseline_meta, $probe_meta ); }
                else { $without = array_merge( $without, $values ); self::merge_probe_meta( $without_meta, $probe_meta ); }
            }
        }
        $result = array(
            'url' => $url, 'plugin' => $plugin, 'baseline' => $baseline, 'without' => $without, 'baseline_meta' => $baseline_meta, 'without_meta' => $without_meta,
            'baseline_median' => self::median( $baseline ), 'without_median' => self::median( $without ), 'at' => time(),
        );
        $result['delta'] = round( $result['baseline_median'] - $result['without_median'], 1 );
        $result['comparable'] = self::comparable_probes( $baseline_meta, $without_meta );
        $result['confidence'] = self::impact_confidence( $baseline, $without, $result['comparable'] );
        set_transient( 'wpi_last_impact_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=profiling&impact=1#profiling' ) ); exit;
    }

    public static function settings_post() {
        self::cap(); check_admin_referer( 'wpi_settings' );
        $sample_percent = max( 0, min( 100, (float) ( $_POST['sample_percent'] ?? 0.02 ) ) );
        $rum_percent = max( 0, min( 100, (float) ( $_POST['rum_percent'] ?? 0.5 ) ) );
        $retention = max( 7, min( 365, absint( $_POST['retention_days'] ?? 30 ) ) );
        $runtime = get_option( 'wpi_runtime', array() );
        $route_lines = preg_split( '/\r?\n/', (string) wp_unslash( $_POST['route_urls'] ?? '' ) );
        $route_urls = array();
        foreach ( (array) $route_lines as $route_url ) {
            $route_url = esc_url_raw( trim( $route_url ), array( 'http', 'https' ) );
            if ( $route_url && WPI_Utils::same_origin_url( $route_url ) ) { $route_urls[] = $route_url; }
        }
        $runtime['sample_rate'] = $sample_percent / 100;
        $runtime['rum_rate'] = $rum_percent / 100;
        $runtime['retention_days'] = $retention;
        $runtime['route_urls'] = array_slice( array_values( array_unique( $route_urls ) ), 0, 8 );
        update_option( 'wpi_runtime', $runtime, true );
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=monitoring&settings=1#monitoring' ) ); exit;
    }

    public static function incident_action_post() {
        self::cap();
        $key = sanitize_text_field( wp_unslash( $_POST['incident_key'] ?? '' ) );
        check_admin_referer( 'wpi_incident_' . $key );
        $action = sanitize_key( wp_unslash( $_POST['incident_action'] ?? '' ) );
        $incident = WPI_Utils::incident( $key );
        if ( ! $incident ) { wp_die( esc_html__( 'That incident no longer exists.', 'wp-performance-inspector' ) ); }

        $message = __( 'Incident updated.', 'wp-performance-inspector' );
        if ( 'resolve' === $action ) { WPI_Utils::set_incident_status( $key, 'resolved' ); $message = __( 'Incident marked resolved.', 'wp-performance-inspector' ); }
        elseif ( 'snooze' === $action ) { WPI_Utils::set_incident_status( $key, 'snoozed', 7 ); $message = __( 'Incident snoozed for seven days.', 'wp-performance-inspector' ); }
        elseif ( 'accept' === $action ) { WPI_Utils::set_incident_status( $key, 'accepted' ); $message = __( 'Incident recorded as accepted risk.', 'wp-performance-inspector' ); }
        elseif ( 'reopen' === $action ) { WPI_Utils::set_incident_status( $key, 'open' ); $message = __( 'Incident reopened.', 'wp-performance-inspector' ); }
        elseif ( 'verify' === $action ) {
            WPI_Utils::set_incident_status( $key, 'verifying' );
            $route = '';
            foreach ( (array) ( $incident['routes'] ?? array() ) as $candidate ) {
                if ( filter_var( $candidate, FILTER_VALIDATE_URL ) ) { $route = $candidate; break; }
            }
            if ( 'scan' === (string) ( $incident['source'] ?? '' ) || 'legacy' === (string) ( $incident['source'] ?? '' ) ) {
                WPI_Scanner::scan( false, false );
                WPI_Utils::finish_verification( $key );
                $after = WPI_Utils::incident( $key );
                $message = $after && 'open' === (string) $after['status'] ? __( 'Recheck confirmed that the incident is still occurring.', 'wp-performance-inspector' ) : __( 'Recheck completed without reproducing the incident.', 'wp-performance-inspector' );
            } elseif ( $route ) {
                $url = self::own_url( $route );
                $meta = array();
                if ( ! is_wp_error( $url ) ) { self::timed_probe( $url, '', 3, $meta ); }
                else { WPI_Scanner::scan( false, false ); }
                WPI_Utils::finish_verification( $key );
                $after = WPI_Utils::incident( $key );
                $message = $after && 'open' === (string) $after['status'] ? __( 'Recheck confirmed that the incident is still occurring.', 'wp-performance-inspector' ) : __( 'Recheck completed without reproducing the incident.', 'wp-performance-inspector' );
            } else {
                WPI_Utils::set_incident_status( $key, 'observing' );
                $message = __( 'This incident needs a new matching production sample. It is now observing and will reopen only if it recurs.', 'wp-performance-inspector' );
            }
        } else { wp_die( esc_html__( 'Unknown incident action.', 'wp-performance-inspector' ) ); }

        set_transient( 'wpi_incident_notice_' . get_current_user_id(), $message, 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=wpi&view=findings#findings' ) ); exit;
    }

    private static function own_url( $url ) {
        $url = trim( (string) $url );
        if ( ! wp_parse_url( $url, PHP_URL_HOST ) ) { $url = home_url( '/' . ltrim( $url, '/' ) ); }
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( ! $url ) { return new WP_Error( 'wpi_url', 'Enter a valid URL on this WordPress site.' ); }
        if ( self::url_origin( $url ) !== self::url_origin( home_url() ) ) { return new WP_Error( 'wpi_url_host', 'Diagnostics are restricted to the configured WordPress origin, including its scheme and port.' ); }
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( 0 === strpos( trailingslashit( $path ), trailingslashit( (string) wp_parse_url( admin_url(), PHP_URL_PATH ) ) ) ) { return new WP_Error( 'wpi_url_admin', 'Profile a public route. Admin pages require an authenticated browser session and would produce misleading loopback results.' ); }
        return $url;
    }

    private static function url_origin( $url ) {
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $port = (int) wp_parse_url( $url, PHP_URL_PORT );
        if ( ! $port ) { $port = 'https' === $scheme ? 443 : 80; }
        return $scheme . '://' . $host . ':' . $port;
    }

    private static function timed_probe( $url, $exclude, $runs, &$meta = null ) {
        $times = array();
        $meta = array( 'requested' => (int) $runs, 'verified' => 0, 'http_errors' => 0, 'bad_status' => 0, 'redirects' => 0, 'statuses' => array(), 'content_types' => array(), 'body_bytes' => array(), 'cache_bypassed' => true );
        for ( $i = 0; $i < $runs; $i++ ) {
            $token = WPI_Bootstrap::token( $url, $exclude );
            $probe = add_query_arg( array_merge( $token, array( 'wpi_probe' => wp_generate_uuid4() ) ), $url );
            $start = microtime( true );
            $res = wp_remote_get( $probe, array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ) ) );
            $elapsed = round( ( microtime( true ) - $start ) * 1000, 1 );
            if ( is_wp_error( $res ) ) { $meta['http_errors']++; continue; }
            $verified = '1' === trim( (string) wp_remote_retrieve_header( $res, 'x-wpi-diagnostic' ) );
            if ( ! $verified ) { $meta['cache_bypassed'] = false; continue; }
            $meta['verified']++;
            $status = (int) wp_remote_retrieve_response_code( $res );
            $meta['statuses'][] = $status;
            if ( $status >= 300 && $status < 400 ) { $meta['redirects']++; $meta['bad_status']++; continue; }
            if ( $status < 200 || $status >= 400 ) { $meta['bad_status']++; continue; }
            $body = (string) wp_remote_retrieve_body( $res );
            $content_type = strtolower( trim( strtok( (string) wp_remote_retrieve_header( $res, 'content-type' ), ';' ) ) );
            if ( strlen( $body ) < 128 || '' === $content_type ) { $meta['bad_status']++; continue; }
            $meta['body_bytes'][] = strlen( $body );
            $meta['content_types'][] = $content_type;
            $times[] = $elapsed;
        }
        sort( $times ); return $times;
    }

    private static function median( array $values ) { if ( ! $values ) { return 0; } sort( $values ); return (float) $values[ (int) floor( ( count( $values ) - 1 ) / 2 ) ]; }

    private static function merge_probe_meta( array &$target, array $source ) {
        if ( ! $target ) { $target = array( 'requested' => 0, 'verified' => 0, 'http_errors' => 0, 'bad_status' => 0, 'redirects' => 0, 'statuses' => array(), 'content_types' => array(), 'body_bytes' => array(), 'cache_bypassed' => true ); }
        foreach ( array( 'requested', 'verified', 'http_errors', 'bad_status', 'redirects' ) as $key ) { $target[ $key ] += (int) ( $source[ $key ] ?? 0 ); }
        foreach ( array( 'statuses', 'content_types', 'body_bytes' ) as $key ) { $target[ $key ] = array_merge( $target[ $key ], (array) ( $source[ $key ] ?? array() ) ); }
        $target['cache_bypassed'] = $target['cache_bypassed'] && ! empty( $source['cache_bypassed'] );
    }

    private static function comparable_probes( array $baseline, array $without ) {
        $base_types = array_values( array_unique( (array) ( $baseline['content_types'] ?? array() ) ) );
        $without_types = array_values( array_unique( (array) ( $without['content_types'] ?? array() ) ) );
        $base_bytes = self::median( array_map( 'floatval', (array) ( $baseline['body_bytes'] ?? array() ) ) );
        $without_bytes = self::median( array_map( 'floatval', (array) ( $without['body_bytes'] ?? array() ) ) );
        $size_ratio = $base_bytes > 0 ? $without_bytes / $base_bytes : 0;
        return $base_types && $base_types === $without_types && $size_ratio >= 0.65 && $size_ratio <= 1.35;
    }

    private static function impact_confidence( array $baseline, array $without, $comparable ) {
        if ( ! $comparable || count( $baseline ) < 5 || count( $without ) < 5 ) { return 'low'; }
        $all = array_merge( $baseline, $without );
        $median = self::median( $all );
        $spread = $median > 0 ? ( max( $all ) - min( $all ) ) / $median : 1;
        return $spread <= 0.25 ? 'high' : ( $spread <= 0.6 ? 'medium' : 'low' );
    }

    public static function page() {
        self::cap(); global $wpdb;
        $allowed_views = array( 'overview', 'findings', 'database', 'profiling', 'monitoring', 'system' );
        $active_view = sanitize_key( wp_unslash( $_GET['view'] ?? 'overview' ) );
        if ( ! in_array( $active_view, $allowed_views, true ) ) { $active_view = 'overview'; }
        $last = get_option( 'wpi_last_scan', array() ); $scan = $last['result'] ?? array();
        $incident_filter = sanitize_key( wp_unslash( $_GET['incident_status'] ?? 'active' ) );
        $incident_statuses = array( 'active' => array( 'open', 'verifying' ), 'watching' => array( 'observing' ), 'snoozed' => array( 'snoozed' ), 'resolved' => array( 'resolved' ), 'accepted' => array( 'accepted' ), 'all' => array( 'open', 'verifying', 'observing', 'snoozed', 'resolved', 'accepted' ) );
        if ( ! isset( $incident_statuses[ $incident_filter ] ) ) { $incident_filter = 'active'; }
        $issues = in_array( $active_view, array( 'overview', 'findings' ), true ) ? WPI_Utils::incidents( 'overview' === $active_view ? $incident_statuses['active'] : $incident_statuses[ $incident_filter ] ) : array();
        $counts = WPI_Utils::incident_summary();
        $open_issue_total = array_sum( $counts );
        $runs = 'profiling' === $active_view ? $wpdb->get_results( 'SELECT * FROM ' . WPI_Utils::table( 'runs' ) . ' ORDER BY id DESC LIMIT 30' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $queries = 'profiling' === $active_view ? $wpdb->get_results( 'SELECT q.*,r.route,r.created_at FROM ' . WPI_Utils::table( 'queries' ) . ' q LEFT JOIN ' . WPI_Utils::table( 'runs' ) . ' r ON r.id=q.run_id ORDER BY q.id DESC LIMIT 80' ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $recent_run_count = 'overview' === $active_view ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WPI_Utils::table( 'runs' ) . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $query_pattern_count = 'overview' === $active_view ? (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT pattern_hash) FROM ' . WPI_Utils::table( 'queries' ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rum = 'monitoring' === $active_view ? $wpdb->get_results( $wpdb->prepare( 'SELECT metric,route_group,SUM(samples) samples,SUM(value_sum)/SUM(samples) average_value,MAX(value_max) value_max,SUM(bucket_0) bucket_0,SUM(bucket_1) bucket_1,SUM(bucket_2) bucket_2,SUM(bucket_3) bucket_3,SUM(bucket_4) bucket_4,SUM(bucket_5) bucket_5,SUM(bucket_6) bucket_6,SUM(bucket_7) bucket_7 FROM ' . WPI_Utils::table( 'metrics' ) . ' WHERE bucket >= %s GROUP BY metric,route_group ORDER BY metric, samples DESC', gmdate( 'Y-m-d H:00:00', time() - DAY_IN_SECONDS ) ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $changes = 'monitoring' === $active_view ? $wpdb->get_results( 'SELECT * FROM ' . WPI_Utils::table( 'changes' ) . ' ORDER BY id DESC LIMIT 20', ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $profile = get_transient( 'wpi_last_profile_' . get_current_user_id() );
        $impact = get_transient( 'wpi_last_impact_' . get_current_user_id() );
        $repair_notice_key = 'wpi_last_db_repair_' . get_current_user_id();
        $repair_result = get_transient( $repair_notice_key );
        // Repair results are flash notices. Keeping an old database error for ten minutes made
        // unrelated tools such as Deep Scan appear to be failing with the previous repair error.
        if ( false !== $repair_result ) { delete_transient( $repair_notice_key ); }
        $repairs = 'database' === $active_view ? ( ! empty( $scan['database_repairs'] ) ? (array) $scan['database_repairs'] : ( ! empty( $scan['database_health'] ) ? WPI_Database_Repair::plans( $scan['database_health'] ) : array() ) ) : array();
        $innodb_manager = 'database' === $active_view ? WPI_Database_Repair::transaction_manager_snapshot() : array();
        $backups = 'database' === $active_view ? WPI_Database_Backup::list_backups( 20 ) : array();
        $verified_backups = array_values( array_filter( $backups, static function ( $backup ) { return WPI_Database_Backup::is_verified_recent( (int) ( $backup['id'] ?? 0 ) ); } ) );
        $backup_storage = 'database' === $active_view ? WPI_Database_Backup::storage_summary() : array();
        $backup_notice = get_transient( 'wpi_backup_notice_' . get_current_user_id() );
        $runtime = get_option( 'wpi_runtime', array( 'sample_rate' => 0.0002, 'rum_rate' => 0.005, 'retention_days' => 30 ) );
        $active_plugins = 'profiling' === $active_view ? array_filter( WPI_Scanner::plugins(), static function ( $p ) { return ! empty( $p['active'] ); } ) : array();
        $bootstrap_status = 'overview' === $active_view ? WPI_Bootstrap::status() : array();
        $incident_notice = get_transient( 'wpi_incident_notice_' . get_current_user_id() );
        if ( false !== $incident_notice ) { delete_transient( 'wpi_incident_notice_' . get_current_user_id() ); }
        ?>
        <div class="wrap wpi-wrap cgm-wpi-root" data-active-view="<?php echo esc_attr( $active_view ); ?>">
        <header class="wpi-suite-header">
            <div class="wpi-suite-mark" aria-hidden="true"><span class="dashicons dashicons-performance"></span></div>
            <div>
                <h1 class="wpi-suite-title">Performance Inspector <span class="wpi-suite-version">v<?php echo esc_html( WPI_VERSION ); ?></span></h1>
                <p class="wpi-suite-subtitle">Production performance console</p>
            </div>
        </header>
        <nav class="wpi-suite-nav" aria-label="<?php esc_attr_e( 'Performance Inspector sections', 'wp-performance-inspector' ); ?>">
            <?php foreach ( array( 'overview' => array( 'dashicons-dashboard', __( 'Overview', 'wp-performance-inspector' ) ), 'findings' => array( 'dashicons-warning', __( 'Incidents', 'wp-performance-inspector' ) ), 'database' => array( 'dashicons-database', __( 'Database', 'wp-performance-inspector' ) ), 'profiling' => array( 'dashicons-chart-area', __( 'Profiling', 'wp-performance-inspector' ) ), 'monitoring' => array( 'dashicons-chart-line', __( 'Monitoring', 'wp-performance-inspector' ) ), 'system' => array( 'dashicons-admin-tools', __( 'System', 'wp-performance-inspector' ) ) ) as $view_slug => $view_meta ): ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpi&view=' . $view_slug ) . '#' . $view_slug ); ?>" data-wpi-tab="<?php echo esc_attr( $view_slug ); ?>"<?php echo $active_view === $view_slug ? ' aria-current="page"' : ''; ?>><span class="dashicons <?php echo esc_attr( $view_meta[0] ); ?>" aria-hidden="true"></span><?php echo esc_html( $view_meta[1] ); ?><?php if ( 'findings' === $view_slug ): ?> <span class="wpi-nav-count"><?php echo esc_html( $open_issue_total > 999 ? '999+' : (string) $open_issue_total ); ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ( 'overview' === $active_view ): ?>
        <section class="wpi-view" data-wpi-view="overview" id="wpi-view-overview">
        <div class="wpi-view-heading"><h2>Performance health</h2><p>Scan the site, work through confirmed root-cause incidents, and verify each change against the same evidence.</p></div>
        <div class="wpi-actions">
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="wpi_scan"><?php wp_nonce_field('wpi_scan'); ?><button class="button button-primary"><span class="dashicons dashicons-search" aria-hidden="true"></span>Run Production-Safe Scan</button></form>
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="wpi_scan"><input type="hidden" name="deep" value="1"><?php wp_nonce_field('wpi_scan'); ?><button class="button"><span class="dashicons dashicons-database" aria-hidden="true"></span>Run Deep Database Scan</button></form>
        <?php if ( empty( $bootstrap_status['installed'] ) || empty( $bootstrap_status['current'] ) ): ?><form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="wpi_bootstrap"><?php wp_nonce_field('wpi_bootstrap'); ?><button class="button"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>Repair diagnostic bootstrap</button></form><?php endif; ?>
        </div>
        <div class="wpi-cards">
            <div class="wpi-card wpi-card--critical"><span>Critical incidents</span><strong class="wpi-critical"><?php echo intval($counts['critical']); ?></strong></div>
            <div class="wpi-card wpi-card--high"><span>High incidents</span><strong class="wpi-high"><?php echo intval($counts['high']); ?></strong></div>
            <div class="wpi-card wpi-card--warning"><span>Warnings</span><strong class="wpi-warning"><?php echo intval($counts['warning']); ?></strong></div>
            <div class="wpi-card wpi-card--good"><span>Request samples, 24 hours</span><strong><?php echo intval( $recent_run_count ); ?></strong></div>
            <div class="wpi-card"><span>Captured query patterns</span><strong><?php echo intval( $query_pattern_count ); ?></strong></div>
        </div>
        <div class="wpi-overview-layout">
            <div class="wpi-box">
                <h3>Priority queue</h3>
                <p>Confirmed incidents ranked by severity, recurrence and freshness.</p>
                <?php if ( ! $issues ): ?>
                    <div class="wpi-empty">No open findings yet. Run a scan to establish a baseline.</div>
                <?php else: ?>
                    <div class="wpi-priority-list">
                    <?php foreach ( array_slice( $issues, 0, 6 ) as $priority ): ?>
                        <div class="wpi-priority-item">
                            <span class="wpi-status wpi-status--<?php echo esc_attr( $priority['severity'] ); ?>"><?php echo esc_html( strtoupper( $priority['severity'] ) ); ?></span>
                            <div class="wpi-priority-item__body"><strong><?php echo esc_html( $priority['title'] ); ?></strong><small><?php echo esc_html( wp_strip_all_tags( (string) $priority['message'] ) ); ?></small></div>
                            <span class="wpi-priority-item__impact"><?php echo esc_html( $priority['impact'] ?: $priority['area'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpi&view=findings#findings' ) ); ?>">Review all <?php echo esc_html( number_format_i18n( $open_issue_total ) ); ?> confirmed incidents</a></p>
                <?php endif; ?>
            </div>
            <div class="wpi-box">
                <h3>Latest scan snapshot</h3>
                <?php if ( ! $scan ): ?>
                    <div class="wpi-empty">No completed scan is cached yet.</div>
                <?php else: ?>
                    <p><small><?php echo esc_html( strtoupper( (string) ( $scan['mode'] ?? 'scan' ) ) ); ?> · <?php echo esc_html( (string) ( $scan['generated_at'] ?? '' ) ); ?> UTC</small></p>
                    <div class="wpi-snapshot">
                        <div><small>Database</small><strong><?php echo esc_html( size_format( (int) ( $scan['database']['total_size'] ?? 0 ) ) ); ?></strong></div>
                        <div><small>Autoload</small><strong><?php echo esc_html( size_format( (int) ( $scan['autoload']['total_bytes'] ?? 0 ) ) ); ?></strong></div>
                        <div><small>Active plugins</small><strong><?php echo intval( $scan['system']['plugins']['active_count'] ?? 0 ); ?></strong></div>
                        <div><small>Overdue cron</small><strong><?php echo intval( $scan['cron']['overdue'] ?? 0 ); ?></strong></div>
                        <div><small>Persistent cache</small><strong><?php echo ! empty( $scan['cache']['persistent'] ) ? 'Active' : 'No'; ?></strong></div>
                        <div><small>DB integrity</small><strong><?php echo intval( count( (array) ( $scan['database_health']['integrity']['problems'] ?? array() ) ) ); ?> issue(s)</strong></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'database' === $active_view ): ?>
        <section class="wpi-view" data-wpi-view="database" id="wpi-view-database">
        <div class="wpi-view-heading"><h2>Database integrity and repair</h2><p>Back up the database, resolve blocking InnoDB transactions, and apply guarded repairs from one place.</p></div>
        <?php if ( $repair_result ): ?><div class="notice <?php echo ! empty( $repair_result['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline"><p><strong>Database repair:</strong> <?php echo esc_html( $repair_result['message'] ?? ( ! empty( $repair_result['ok'] ) ? 'Completed.' : 'Failed.' ) ); ?><?php if('wpi_innodb_busy'===($repair_result['error_code']??'')): ?> <a href="#innodb-transaction-manager"><strong>Open InnoDB Transaction Manager</strong></a><?php endif; ?></p></div><?php endif; ?>
        <?php if ( $backup_notice ): ?><div class="notice <?php echo ! empty( $backup_notice['ok'] ) ? 'notice-success' : 'notice-error'; ?> inline"><p><strong>Database backup:</strong> <?php echo esc_html( $backup_notice['message'] ?? '' ); ?></p></div><?php delete_transient( 'wpi_backup_notice_' . get_current_user_id() ); endif; ?>
        <?php if ( $repair_result && 'innodb_recovery_preflight' === ( $repair_result['action'] ?? '' ) && ! empty( $repair_result['ok'] ) ): ?>
        <div class="wpi-box wpi-preflight"><h3>Latest InnoDB Recovery Preflight</h3>
        <p><strong>Table:</strong> <code><?php echo esc_html( $repair_result['table'] ?? '' ); ?></code><?php if(!empty($repair_result['meta']['size'])): ?>, <?php echo esc_html(size_format((int)$repair_result['meta']['size'])); ?><?php endif; ?></p>
        <p><strong>CHECK TABLE:</strong> <?php echo !empty($repair_result['check']['ok'])?'<span class="wpi-good">clean</span>':'<span class="wpi-critical">problem / skipped</span>'; ?> &nbsp; <strong>Online DDL:</strong> <?php echo !empty($repair_result['online_ddl']['inplace'])?'INPLACE + LOCK=NONE available':'not verified'; ?> &nbsp; <strong>innodb_force_recovery:</strong> <?php echo intval($repair_result['force_recovery']??0); ?></p>
        <?php foreach((array)($repair_result['warnings']??array()) as $warning): ?><p class="wpi-warning">⚠ <?php echo esc_html($warning); ?></p><?php endforeach; ?>
        <?php $ds=$repair_result['disk_space']??array(); ?><p><strong>Disk preflight:</strong> index rebuild <?php echo !empty($ds['index_rebuild']['available'])?( !empty($ds['index_rebuild']['ok'])?'PASS':'FAIL' ):'unknown'; ?>; table rebuild <?php echo !empty($ds['table_rebuild']['available'])?( !empty($ds['table_rebuild']['ok'])?'PASS':'FAIL' ):'unknown'; ?>.</p>
        <?php if(!empty($repair_result['runtime']['lock_graph']['edges'])): ?><p><strong>Active blocker/waiter edges:</strong> <?php echo intval(count($repair_result['runtime']['lock_graph']['edges'])); ?></p><?php endif; ?>
        <?php if(!empty($repair_result['suspect_secondary_index'])): ?><p><strong>Suspected secondary index:</strong> <code><?php echo esc_html($repair_result['suspect_secondary_index']); ?></code></p><?php endif; ?>
        <?php if(!empty($repair_result['recovery_steps'])): ?><h4>Guided recovery sequence</h4><ol><?php foreach($repair_result['recovery_steps'] as $step): ?><li><?php echo esc_html($step); ?></li><?php endforeach; ?></ol><?php endif; ?>
        <?php if(!empty($repair_result['commands'])): ?><details><summary>Reviewed commands / CLI</summary><div><?php foreach($repair_result['commands'] as $label=>$command): ?><p><strong><?php echo esc_html(str_replace('_',' ',$label)); ?>:</strong><br><code><?php echo esc_html($command); ?></code></p><?php endforeach; ?></div></details><?php endif; ?>
        <?php if(!empty($repair_result['indexes'])): ?><details><summary>Index inventory</summary><div><table class="widefat striped"><thead><tr><th>Index</th><th>Type</th><th>Unique</th><th>Columns</th></tr></thead><tbody><?php foreach($repair_result['indexes'] as $idx): ?><tr><td><code><?php echo esc_html($idx['name']); ?></code></td><td><?php echo esc_html($idx['type']); ?></td><td><?php echo !empty($idx['unique'])?'yes':'no'; ?></td><td><?php echo esc_html(implode(', ',(array)$idx['columns'])); ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
        </div><?php endif; ?>

        <div class="wpi-actions">
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="wpi_scan"><input type="hidden" name="deep" value="1"><?php wp_nonce_field('wpi_scan'); ?><button class="button button-primary"><span class="dashicons dashicons-database" aria-hidden="true"></span>Run Deep Database Scan</button></form>
        <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="wpi_expired_transients"><?php wp_nonce_field('wpi_transients'); ?><button class="button"><span class="dashicons dashicons-trash" aria-hidden="true"></span>Delete 1,000 Expired Transients</button></form>
        </div>
        <h2 id="database-backups">Database Backups</h2>
        <div class="wpi-box">
        <p>Create a private logical database backup before applying schema, index, repair or rebuild operations. Browser backups use adaptive high-throughput batches, automatically adjust to row width, and can be resumed if a request is interrupted.</p>
        <form id="wpi-backup-create-form" class="wpi-form-row">
            <label>Backup scope<select name="backup_scope"><option value="wordpress" selected>All WordPress tables</option><option value="full">All base tables in current database</option></select></label>
            <button type="submit" class="button button-primary">Create Database Backup</button>
        </form>
        <p class="description">The logical export contains base-table schema and row data. It does not include MySQL users/grants, views, routines, triggers/events, database-server configuration or a host/filesystem snapshot. Completeness verification checks the export file and checksum; for a transactionally consistent point-in-time backup on a heavily written site, use a host/database snapshot or run the CLI export in a controlled quiet window.</p>
        <div id="wpi-backup-progress" class="notice notice-info inline" hidden><p>Backup progress will appear here.</p></div>
        <?php if ( ! empty( $backup_storage['ok'] ) ): ?><p><small><strong>Private storage:</strong> <code><?php echo esc_html( $backup_storage['path'] ); ?></code><?php echo ! empty( $backup_storage['inside_webroot'] ) ? ' (inside the web root with deny rules; verify your web server honors them)' : ' (outside the detected web root)'; ?>.</small></p><?php else: ?><p class="wpi-critical"><?php echo esc_html( $backup_storage['message'] ?? 'No backup storage directory is available.' ); ?></p><?php endif; ?>
        <?php if ( ! $backups ): ?><p>No WPI database backups have been created yet.</p><?php else: ?>
        <table class="widefat striped"><thead><tr><th>Backup</th><th>Status</th><th>Progress</th><th>Size / checksum</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ( $backups as $backup ): $bid=(int)$backup['id']; $bstatus=(string)$backup['status']; ?>
        <tr><td><strong>#<?php echo $bid; ?></strong> <code><?php echo esc_html($backup['filename']); ?></code><br><small><?php echo esc_html($backup['created_at']); ?> UTC · <?php echo esc_html($backup['scope']); ?></small></td>
        <td><?php if('verified'===$bstatus): ?><span class="wpi-good"><strong>Verified</strong></span><?php elseif('failed'===$bstatus): ?><span class="wpi-critical"><strong>Failed</strong></span><?php else: ?><strong><?php echo esc_html(ucwords(str_replace('_',' ',$bstatus))); ?></strong><?php endif; ?><?php if(!empty($backup['verified_at'])): ?><br><small><?php echo esc_html($backup['verified_at']); ?> UTC</small><?php endif; ?></td>
        <td><?php echo intval($backup['tables_done']); ?>/<?php echo intval($backup['table_count']); ?> tables<br><small><?php echo esc_html(number_format_i18n((int)$backup['row_count'])); ?> rows<?php if($backup['current_table']): ?> · <code><?php echo esc_html($backup['current_table']); ?></code><?php endif; ?></small></td>
        <td><?php echo esc_html(size_format((int)$backup['size_bytes'])); ?><?php if($backup['sha256']): ?><br><small>SHA-256 <code><?php echo esc_html(substr($backup['sha256'],0,16)); ?>…</code></small><?php endif; ?><?php if($backup['error_text']): ?><br><span class="wpi-critical"><?php echo esc_html($backup['error_text']); ?></span><?php endif; ?></td>
        <td class="wpi-form-row"><?php if('running'===$bstatus): ?><button type="button" class="button" data-wpi-resume-backup="<?php echo $bid; ?>">Resume</button><?php elseif('ready_verify'===$bstatus): ?><button type="button" class="button button-primary" data-wpi-verify-backup="<?php echo $bid; ?>">Verify Backup</button><?php endif; ?>
        <?php if(in_array($bstatus,array('verified','ready_verify'),true)): $download=wp_nonce_url(admin_url('admin-post.php?action=wpi_backup_download&backup_id='.$bid),'wpi_backup_download_'.$bid); ?><a class="button" href="<?php echo esc_url($download); ?>">Download</a><?php endif; ?>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" onsubmit="return confirm('Delete this database backup file?');"><input type="hidden" name="action" value="wpi_backup_delete"><input type="hidden" name="backup_id" value="<?php echo $bid; ?>"><?php wp_nonce_field('wpi_backup_delete_'.$bid); ?><button class="button">Delete</button></form></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php endif; ?>
        <?php if($verified_backups): ?><p class="wpi-good"><strong><?php echo intval(count($verified_backups)); ?> recent verified WPI backup(s)</strong> can satisfy Repair Centre backup requirements for the next 24 hours.</p><?php else: ?><p class="wpi-warning"><strong>No recent verified WPI backup is available.</strong> Create and verify one above before running a database-changing repair, or explicitly confirm an external backup/snapshot in the repair form.</p><?php endif; ?>
        </div>

        <h2 id="innodb-transaction-manager">InnoDB Transaction Manager</h2>
        <div class="wpi-box">
        <p>Live transaction controls for DDL-blocking, stuck or idle InnoDB sessions. WPI never terminates a connection automatically. A termination interrupts the owning request/session and rolls back its uncommitted work.</p>
        <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wpi#innodb-transaction-manager')); ?>">Refresh Live Status</a> <small>The table below is read directly from InnoDB on each Performance page load.</small></p>
        <?php if ( empty( $innodb_manager['available'] ) ): ?>
            <p class="wpi-warning"><strong>Transaction details are unavailable.</strong> <?php echo esc_html($innodb_manager['message']??'The database account may not have enough visibility into INFORMATION_SCHEMA/Performance Schema.'); ?></p>
        <?php elseif ( empty( $innodb_manager['transactions'] ) ): ?>
            <p class="wpi-good"><strong>No active InnoDB transactions are currently visible.</strong> You can retry the blocked database repair.</p>
        <?php else: ?>
        <table class="widefat striped"><thead><tr><th>Thread / transaction</th><th>Age / state</th><th>Connection</th><th>Rows / locks</th><th>Query</th><th>Risk / action</th></tr></thead><tbody>
        <?php foreach((array)$innodb_manager['transactions'] as $trx): $tid=(int)($trx['thread_id']??0); $risk=(string)($trx['risk']??'medium'); ?>
        <tr>
            <td><strong>Thread <?php echo $tid; ?></strong><br><small>trx <code><?php echo esc_html($trx['id']??''); ?></code></small><?php if(!empty($trx['is_blocker'])): ?><br><span class="wpi-critical"><strong>BLOCKER</strong></span><?php endif; ?><?php if(!empty($trx['is_waiting'])): ?><br><span class="wpi-warning"><strong>WAITING</strong></span><?php endif; ?></td>
            <td><?php echo intval($trx['age_seconds']??0); ?> sec<br><small><?php echo esc_html($trx['state']??''); ?><?php if(!empty($trx['process_state'])): ?> · <?php echo esc_html($trx['process_state']); ?><?php endif; ?></small></td>
            <td><?php echo esc_html($trx['process_user']??'unknown'); ?><?php if(!empty($trx['process_host'])): ?><br><small><?php echo esc_html($trx['process_host']); ?></small><?php endif; ?><?php if(!empty($trx['process_db'])): ?><br><code><?php echo esc_html($trx['process_db']); ?></code><?php endif; ?><?php if(!empty($trx['process_command'])): ?><br><small><?php echo esc_html($trx['process_command']); ?></small><?php endif; ?></td>
            <td>modified <?php echo esc_html(number_format_i18n((int)($trx['rows_modified']??0))); ?><br>locked <?php echo esc_html(number_format_i18n((int)($trx['rows_locked']??0))); ?><br><small><?php echo intval($trx['tables_locked']??0); ?> table(s), <?php echo intval($trx['lock_structs']??0); ?> lock struct(s)</small></td>
            <td><code><?php echo esc_html(mb_substr((string)(($trx['query']??'') ?: ($trx['process_info']??'')),0,900)); ?></code><?php if(empty($trx['query'])&&empty($trx['process_info'])): ?><small>Idle/no current SQL visible.</small><?php endif; ?></td>
            <td><strong class="wpi-<?php echo 'high'===$risk?'critical':('medium'===$risk?'warning':'good'); ?>"><?php echo esc_html(strtoupper($risk)); ?></strong><br><small><?php echo esc_html($trx['risk_message']??''); ?></small>
            <?php if(!empty($trx['can_terminate'])): ?>
                <form style="margin-top:8px" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" onsubmit="return confirm('Terminate MySQL thread <?php echo $tid; ?>? Its uncommitted transaction will be rolled back.');">
                    <input type="hidden" name="action" value="wpi_db_fix"><input type="hidden" name="repair_action" value="terminate_innodb_transaction"><input type="hidden" name="thread_id" value="<?php echo $tid; ?>"><input type="hidden" name="transaction_id" value="<?php echo esc_attr($trx['id']??''); ?>">
                    <label style="display:block;margin:6px 0"><input type="checkbox" name="rollback_confirmed" value="1" required> I understand this interrupts the request/session and rolls back its uncommitted work</label>
                    <input type="hidden" name="danger_confirmed" value="1">
                    <?php if(!empty($trx['requires_high_rollback_ack'])): ?><label style="display:block;margin:6px 0" class="wpi-critical"><input type="checkbox" name="high_rollback_confirmed" value="1" required> I understand this may trigger a large/slow rollback and can increase database load until rollback completes</label><?php endif; ?>
                    <?php wp_nonce_field('wpi_db_fix'); ?><button class="button button-secondary">Terminate + Roll Back Transaction</button>
                </form>
            <?php else: ?><p><small><?php echo esc_html($trx['termination_reason']??'WPI is not offering termination for this transaction.'); ?></small></p><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php if(!empty($innodb_manager['lock_graph']['edges'])): ?><details><summary>Blocking relationships</summary><div><table class="widefat striped"><thead><tr><th>Waiting</th><th>Blocking</th><th>Table / index</th><th>Waiting query</th><th>Blocking query</th></tr></thead><tbody><?php foreach(array_slice((array)$innodb_manager['lock_graph']['edges'],0,30) as $edge): ?><tr><td><code><?php echo esc_html($edge['requesting_trx_id']??''); ?></code></td><td><code><?php echo esc_html($edge['blocking_trx_id']??''); ?></code></td><td><?php echo esc_html($edge['table']??''); ?><?php if(!empty($edge['index'])): ?><br><code><?php echo esc_html($edge['index']); ?></code><?php endif; ?></td><td><code><?php echo esc_html(mb_substr((string)($edge['requesting_query']??''),0,500)); ?></code></td><td><code><?php echo esc_html(mb_substr((string)($edge['blocking_query']??''),0,500)); ?></code></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
        <?php endif; ?>
        <p class="description">WPI refuses to terminate its own database connection, database/server system sessions, replication/daemon connections, or transactions that already appear to be rolling back. If the database user lacks permission to issue KILL CONNECTION, the server error is shown instead of bypassing host policy.</p>
        </div>

        <h2 id="database-repair-centre">Database Repair Centre</h2>
        <div class="wpi-box">
        <p>Repairs are explicit and evidence-driven. Normal fixes run directly in wp-admin. Large maintenance-window operations can also be run here after a verified backup, explicit maintenance acknowledgement and the existing DDL/lock/disk preflights; WP-CLI remains the preferred path for the largest tables.</p>
        <?php if ( ! $scan ): ?><p>Run a production-safe or deep scan first to build a repair plan.</p><?php elseif ( ! $repairs ): ?><p class="wpi-good"><strong>No database repair actions are currently recommended.</strong></p><?php else: ?>
        <table class="widefat striped"><thead><tr><th>Repair</th><th>Evidence</th><th>Safety</th><th>Action</th></tr></thead><tbody>
        <?php foreach ( $repairs as $plan ): $pargs=(array)($plan['args']??array()); $managed_index=(array)($plan['managed_index']??array()); ?><tr class="<?php echo !empty($managed_index)?'wpi-managed-index-row':''; ?>">
        <td>
            <strong><?php echo esc_html($plan['title']); ?></strong>
            <?php if(!empty($managed_index)): ?>
                <div class="wpi-managed-index-owner"><span class="wpi-managed-index-owner__label">Owner</span><strong><?php echo esc_html($managed_index['owner']??'Active plugin'); ?></strong></div>
                <p class="wpi-managed-index-note">Two database indexes have the same verified definition. WPI will preserve one working index and remove only the redundant copy.</p>
            <?php else: ?><br><small><?php echo esc_html($plan['reason']); ?></small><?php endif; ?>
        </td>
        <td>
            <?php if(!empty($managed_index)): ?>
                <div class="wpi-index-decision">
                    <div class="wpi-index-decision__pair"><span class="wpi-index-decision__label">KEEP</span><code><?php echo esc_html($managed_index['keep']??''); ?></code></div>
                    <div class="wpi-index-decision__pair is-remove"><span class="wpi-index-decision__label">REMOVE</span><code><?php echo esc_html($managed_index['drop']??''); ?></code></div>
                </div>
                <dl class="wpi-index-meta">
                    <div><dt>Canonical name</dt><dd><code><?php echo esc_html($managed_index['canonical']?:'Not registered'); ?></code></dd></div>
                    <div><dt>Canonical currently present</dt><dd><?php echo !empty($managed_index['canonical_present'])?'Yes':'No'; ?></dd></div>
                    <div><dt>Create canonical replacement</dt><dd><strong>Not recommended</strong><?php if(empty($managed_index['canonical_present']) && !empty($managed_index['canonical'])): ?> <span class="wpi-index-meta__why">The retained legacy index has the same verified definition, so building another index would add work without a query-performance benefit.</span><?php endif; ?></dd></div>
                </dl>
            <?php else: ?><?php echo esc_html($plan['detail']); ?><?php endif; ?>
        </td>
        <td>
            <?php if(!empty($managed_index)): ?>
                <div class="wpi-index-safety"><span><small>Risk</small><strong class="wpi-good"><?php echo esc_html($managed_index['risk']??'Low'); ?></strong></span><span><small>Confidence</small><strong><?php echo esc_html($managed_index['confidence']??'High'); ?></strong></span><span><small>Functional impact</small><strong><?php echo esc_html($managed_index['functional_impact']??'None expected'); ?></strong></span><span><small>Storage/write impact</small><strong class="wpi-good"><?php echo esc_html($managed_index['storage_write_impact']??'Positive'); ?></strong></span></div>
                <?php if(!empty($managed_index['large_table'])): ?><p class="wpi-warning-text">Large table: run during a maintenance window with a verified backup.</p><?php endif; ?>
            <?php else: ?><code><?php echo esc_html($plan['safety']); ?></code><?php endif; ?>
        </td>
        <td>
        <?php if ( ! empty($plan['available']) && ! empty($plan['action']) ): ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="wpi_db_fix"><input type="hidden" name="repair_action" value="<?php echo esc_attr($plan['action']); ?>"><?php foreach($pargs as $k=>$v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
            <?php if(!empty($plan['requires_backup'])): ?><label style="display:block;margin-bottom:6px">Verified WPI backup<select name="backup_id"><option value="">Select recent backup</option><?php foreach($verified_backups as $vb): ?><option value="<?php echo intval($vb['id']); ?>">#<?php echo intval($vb['id']); ?> · <?php echo esc_html(size_format((int)$vb['size_bytes'])); ?> · <?php echo esc_html($vb['verified_at']); ?> UTC</option><?php endforeach; ?></select></label><label style="display:block;margin-bottom:6px"><input type="checkbox" name="backup_confirmed" value="1"> Or I independently verified a current external DB backup/snapshot</label><?php endif; ?>
            <?php if(!empty($plan['requires_danger_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="danger_confirmed" value="1" required> I reviewed this schema/index change and its locking/data risks</label><?php endif; ?>
            <?php if(!empty($plan['requires_data_loss_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="data_loss_confirmed" value="1" required> I understand this may not restore lost data and may discard damaged rows</label><?php endif; ?>
            <?php wp_nonce_field('wpi_db_fix'); ?><button class="button<?php echo 'safe'===$plan['safety']?' button-primary':''; ?>"><?php echo esc_html($plan['button_label']??'Apply Fix'); ?></button></form>
            <?php if(!empty($plan['sql_preview'])): ?><details style="margin-top:8px"><summary>Preview SQL</summary><code><?php echo esc_html($plan['sql_preview']); ?></code></details><?php endif; ?>
        <?php elseif ( 'cli-review' === ($plan['safety']??'') && !empty($plan['action']) ): ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="wpi_db_fix"><input type="hidden" name="repair_action" value="<?php echo esc_attr($plan['action']); ?>"><input type="hidden" name="force_large" value="1"><?php foreach($pargs as $k=>$v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
            <?php if(!empty($plan['requires_backup'])): ?><label style="display:block;margin-bottom:6px">Verified WPI backup<select name="backup_id"><option value="">Select recent backup</option><?php foreach($verified_backups as $vb): ?><option value="<?php echo intval($vb['id']); ?>">#<?php echo intval($vb['id']); ?> · <?php echo esc_html(size_format((int)$vb['size_bytes'])); ?> · <?php echo esc_html($vb['verified_at']); ?> UTC</option><?php endforeach; ?></select></label><label style="display:block;margin-bottom:6px"><input type="checkbox" name="backup_confirmed" value="1"> Or I independently verified a current external DB backup/snapshot</label><?php endif; ?>
            <label style="display:block;margin-bottom:6px"><input type="checkbox" name="maintenance_confirmed" value="1" required> I am running this in a maintenance window and understand this request may take a long time</label>
            <?php if(!empty($plan['requires_danger_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="danger_confirmed" value="1" required> I reviewed this schema/index change and its locking/data risks</label><?php endif; ?>
            <?php if(!empty($plan['requires_data_loss_ack'])): ?><label style="display:block;margin-bottom:6px"><input type="checkbox" name="data_loss_confirmed" value="1" required> I understand this may not restore lost data and may discard damaged rows</label><?php endif; ?>
            <?php wp_nonce_field('wpi_db_fix'); ?><button class="button button-primary"><?php echo esc_html($plan['button_label']??'Run Maintenance Fix'); ?></button></form>
            <details style="margin-top:8px"><summary>CLI alternative (recommended for the largest tables)</summary><code>wp performance database-fix <?php echo esc_html($plan['action']); ?><?php foreach($pargs as $k=>$v): ?> --<?php echo esc_html(str_replace('_','-',$k)); ?>=<?php echo esc_html($v); ?><?php endforeach; ?><?php echo !empty($plan['requires_backup'])?' --backup-confirmed':''; ?><?php echo !empty($plan['requires_danger_ack'])?' --danger-confirmed':''; ?><?php echo !empty($plan['requires_data_loss_ack'])?' --data-loss-confirmed':''; ?> --force-large</code></details>
            <?php if(!empty($plan['sql_preview'])): ?><details style="margin-top:8px"><summary>Preview SQL</summary><code><?php echo esc_html($plan['sql_preview']); ?></code></details><?php endif; ?>
        <?php else: ?>
            <strong>Guided fix required.</strong><?php if(!empty($plan['manual_steps'])): ?><ol><?php foreach((array)$plan['manual_steps'] as $step): ?><li><?php echo esc_html($step); ?></li><?php endforeach; ?></ol><?php endif; ?><?php if(!empty($plan['sql_preview'])): ?><details><summary>Reviewed SQL</summary><code><?php echo esc_html($plan['sql_preview']); ?></code></details><?php endif; ?>
        <?php endif; ?>
        </td>
        </tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'profiling' === $active_view ): ?>
        <section class="wpi-view" data-wpi-view="profiling" id="wpi-view-profiling">
        <div class="wpi-view-heading"><h2>Request profiling</h2><p>Measure public routes with signed private requests, then isolate plugin, database and outbound HTTP cost.</p></div>
        <h2>Deep Route Profiler</h2>
        <div class="wpi-box"><form class="wpi-form-row" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="wpi_profile"><?php wp_nonce_field('wpi_profile'); ?>
        <?php $profile_url = isset( $_GET['profile_url'] ) && WPI_Utils::same_origin_url( wp_unslash( $_GET['profile_url'] ) ) ? esc_url_raw( wp_unslash( $_GET['profile_url'] ) ) : home_url('/'); ?>
        <label>Public WordPress URL<input type="url" name="profile_url" required value="<?php echo esc_attr( $profile_url ); ?>"></label><label>Measured runs<select name="runs"><option>3</option><option selected>5</option></select></label><button class="button button-primary">Profile this route</button></form>
        <p class="description">Runs a private no-cache request without following redirects. WPI verifies that WordPress handled the response before accepting the measurement.</p>
        <?php if ( $profile ): $pm=$profile['probe_meta']??array(); ?><p><strong>Last profile:</strong> <?php echo esc_html($profile['url']); ?> &mdash; <?php if($profile['times']): ?><?php echo esc_html(implode(', ', $profile['times'])); ?> ms; median <?php echo esc_html(self::median($profile['times'])); ?> ms<?php else: ?><span class="wpi-critical">No verified diagnostic request reached WordPress. A page cache/reverse proxy may be serving before the MU profiler.</span><?php endif; ?><?php if($pm): ?> <small>(verified <?php echo intval($pm['verified']??0); ?>/<?php echo intval($pm['requested']??0); ?>)</small><?php endif; ?></p><?php endif; ?>
        </div>

        <h2>Private Plugin Impact Test</h2>
        <div class="wpi-box"><form class="wpi-form-row" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="wpi_plugin_impact"><?php wp_nonce_field('wpi_plugin_impact'); ?>
        <label>Route<input type="url" name="profile_url" required value="<?php echo esc_attr(home_url('/')); ?>"></label><label>Plugin<select name="plugin" required><option value="">Select plugin</option><?php foreach($active_plugins as $p): if($p['file']===WPI_BASENAME)continue; ?><option value="<?php echo esc_attr($p['file']); ?>"><?php echo esc_html($p['name'].' '.$p['version']); ?></option><?php endforeach; ?></select></label><button class="button">Benchmark Plugin</button></form>
        <p class="description">Runs warm-ups followed by five alternating A/B pairs. The plugin remains active for visitors and is excluded only from signed private requests.</p>
        <?php if($impact): $bm=$impact['baseline_meta']??array(); $wm=$impact['without_meta']??array(); ?><p><strong><?php echo esc_html($impact['plugin']); ?>:</strong> <?php if($impact['baseline']&&$impact['without']&&!empty($impact['comparable'])): ?>baseline <?php echo esc_html($impact['baseline_median']); ?> ms; without plugin <?php echo esc_html($impact['without_median']); ?> ms; estimated impact <strong><?php echo esc_html(($impact['delta']>=0?'+':'').$impact['delta']); ?> ms</strong> · <?php echo esc_html( $impact['confidence'] ?? 'low' ); ?> confidence.<?php elseif(empty($impact['comparable'])): ?><span class="wpi-critical">The responses changed too much to compare safely. Check for missing templates, redirects or plugin dependencies.</span><?php else: ?><span class="wpi-critical">Benchmark could not be verified because one or more diagnostic paths were served before WordPress.</span><?php endif; ?> <small>(baseline <?php echo intval($bm['verified']??0); ?>/<?php echo intval($bm['requested']??0); ?>; exclusion <?php echo intval($wm['verified']??0); ?>/<?php echo intval($wm['requested']??0); ?> verified)</small></p><?php endif; ?>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'findings' === $active_view ): ?>
        <section class="wpi-view" data-wpi-view="findings" id="wpi-view-findings">
        <div class="wpi-view-heading"><h2>Performance incidents</h2><p>Repeated evidence is grouped into root-cause incidents. Diagnose the representative route, apply a guarded change, then recheck the same incident.</p></div>
        <?php if ( $incident_notice ): ?><div class="notice notice-success inline" role="status"><p><?php echo esc_html( $incident_notice ); ?></p></div><?php endif; ?>
        <form class="wpi-incident-toolbar" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="wpi"><input type="hidden" name="view" value="findings">
            <label for="wpi-incident-status">Lifecycle status<select id="wpi-incident-status" name="incident_status" data-wpi-submit-change><?php foreach ( array( 'active' => 'Confirmed', 'watching' => 'Watching', 'snoozed' => 'Snoozed', 'resolved' => 'Resolved', 'accepted' => 'Accepted risk', 'all' => 'All incidents' ) as $value => $label ): ?><option value="<?php echo esc_attr( $value ); ?>"<?php selected( $incident_filter, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
            <label for="wpi-incident-severity">Severity<select id="wpi-incident-severity" data-wpi-filter="severity"><option value="">All severities</option><option value="critical">Critical</option><option value="high">High</option><option value="warning">Warning</option><option value="info">Info</option></select></label>
            <label for="wpi-incident-search" class="wpi-incident-search">Search incidents<input id="wpi-incident-search" type="search" data-wpi-filter="search" placeholder="Component, route, or evidence"></label>
        </form>
        <p class="wpi-findings-note" aria-live="polite"><span data-wpi-result-count><?php echo esc_html( number_format_i18n( count( $issues ) ) ); ?></span> grouped incident(s) in this view.</p>
        <?php if ( ! $issues ): ?><div class="wpi-empty"><strong>No incidents match this lifecycle view.</strong><br>Run a production-safe scan or switch to another status.</div><?php endif; ?>
        <div class="wpi-incidents">
        <?php foreach ( $issues as $incident ): $routes=(array)($incident['routes']??array()); $representative=''; foreach($routes as $candidate){if(filter_var($candidate,FILTER_VALIDATE_URL)&&WPI_Utils::same_origin_url($candidate)){$representative=$candidate;break;}} ?>
            <article class="wpi-incident" data-severity="<?php echo esc_attr( $incident['severity'] ); ?>" data-search="<?php echo esc_attr( strtolower( wp_strip_all_tags( implode( ' ', array( $incident['title'], $incident['message'], $incident['area'], $incident['component'], implode( ' ', $routes ) ) ) ) ) ); ?>">
                <header class="wpi-incident__header">
                    <div><span class="wpi-status wpi-status--<?php echo esc_attr( $incident['severity'] ); ?>"><?php echo esc_html( strtoupper( $incident['severity'] ) ); ?></span> <span class="wpi-incident__area"><?php echo esc_html( $incident['area'] ); ?><?php echo !empty($incident['component'])?' · '.esc_html($incident['component']):''; ?></span></div>
                    <span class="wpi-incident__confidence"><?php echo esc_html( round( (float) $incident['confidence'] ) ); ?>% confidence</span>
                </header>
                <div class="wpi-incident__body">
                    <div class="wpi-incident__summary"><h3><?php echo esc_html( $incident['title'] ); ?></h3><p><?php echo wp_kses_post( $incident['message'] ); ?></p><p class="wpi-incident__recommendation"><strong>Recommended next step:</strong> <?php echo wp_kses_post( $incident['recommendation'] ); ?></p></div>
                    <dl class="wpi-incident__facts"><div><dt>Impact</dt><dd><?php echo esc_html( $incident['impact'] ?: 'Needs measurement' ); ?></dd></div><div><dt>Evidence</dt><dd><?php echo esc_html( number_format_i18n( (int) $incident['occurrences'] ) ); ?> occurrence(s) across <?php echo esc_html( number_format_i18n( count( $routes ) ) ); ?> route(s)</dd></div><div><dt>Last confirmed</dt><dd><?php echo esc_html( $incident['last_seen'] ); ?> UTC</dd></div></dl>
                </div>
                <?php if ( $routes ): ?><details class="wpi-incident__routes"><summary>View affected routes and evidence</summary><div><ul><?php foreach ( array_slice( $routes, 0, 25 ) as $route ): ?><li><code><?php echo esc_html( $route ); ?></code></li><?php endforeach; ?></ul><?php if(count($routes)>25): ?><p><?php echo esc_html( number_format_i18n( count($routes)-25 ) ); ?> additional route(s) are grouped into this incident.</p><?php endif; ?></div></details><?php endif; ?>
                <footer class="wpi-incident__actions">
                    <?php if ( $representative ): ?><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page'=>'wpi','view'=>'profiling','profile_url'=>$representative ), admin_url('admin.php') ) . '#profiling' ); ?>">Profile representative route</a><?php elseif ( 'database' === $incident['area'] ): ?><a class="button button-primary" href="<?php echo esc_url( admin_url('admin.php?page=wpi&view=database#database') ); ?>">Open database diagnostics</a><?php else: ?><a class="button button-primary" href="<?php echo esc_url( admin_url('admin.php?page=wpi&view=profiling#profiling') ); ?>">Open profiling tools</a><?php endif; ?>
                    <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="post"><input type="hidden" name="action" value="wpi_incident_action"><input type="hidden" name="incident_key" value="<?php echo esc_attr( $incident['incident_key'] ); ?>"><?php wp_nonce_field( 'wpi_incident_' . $incident['incident_key'] ); ?><button class="button" name="incident_action" value="verify">Recheck now</button><button class="button" name="incident_action" value="snooze">Snooze 7 days</button><button class="button" name="incident_action" value="resolve">Mark resolved</button><button class="button-link" name="incident_action" value="accept">Accept risk</button></form>
                </footer>
            </article>
        <?php endforeach; ?>
        </div>
        </section>
        <?php endif; ?>

        <?php if ( 'system' === $active_view ): ?>
        <section class="wpi-view" data-wpi-view="system" id="wpi-view-system">
        <div class="wpi-view-heading"><h2>System scan</h2><p>Inspect the complete database, plugin, cache, job, frontend and storage-engine snapshot from the latest scan.</p></div>
        <?php if($scan): self::render_scan_details($scan); else: ?><div class="wpi-empty">Run a Production-Safe Scan to populate the full system view.</div><?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ( 'profiling' === $active_view ): ?>
        <section class="wpi-view" data-wpi-view="profiling">
        <h2>Recent Request Samples</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Time</th><th>Mode</th><th>Route</th><th>PHP</th><th>DB</th><th>Queries</th><th>HTTP</th><th>Memory</th><th>Phases / hooks</th></tr></thead><tbody>
        <?php foreach($runs as $r): $payload=json_decode($r->payload,true); ?><tr><td><?php echo (int)$r->id; ?></td><td><?php echo esc_html($r->created_at); ?></td><td><?php echo esc_html($r->mode); ?></td><td><code><?php echo esc_html($r->route); ?></code></td><td><?php echo esc_html($r->php_ms.' ms'); ?></td><td><?php echo esc_html($r->db_ms.' ms'); ?></td><td><?php echo (int)$r->query_count; ?></td><td><?php echo esc_html($r->http_ms.' ms / '.(int)$r->http_count); ?></td><td><?php echo esc_html(size_format((int)$r->memory_peak)); ?></td><td><small><?php echo esc_html(self::top_timings($payload)); ?></small></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2>Captured Slow / Repeated Queries</h2><table class="widefat striped"><thead><tr><th>Run</th><th>Component</th><th>Calls</th><th>Total / max</th><th>Query</th><th>EXPLAIN analysis</th></tr></thead><tbody>
        <?php if(!$queries): ?><tr><td colspan="6">Profile a route to capture attributed query patterns.</td></tr><?php endif; ?>
        <?php foreach($queries as $q): $ex=json_decode($q->explain_json,true); $cand=$ex['analysis']['index_candidate']??array(); ?><tr><td>#<?php echo intval($q->run_id); ?><br><small><?php echo esc_html($q->created_at); ?></small></td><td><?php echo esc_html($q->component_type.':'.$q->component_slug); ?><br><small><?php echo esc_html($q->source_file); ?><?php echo $q->source_line?':'.intval($q->source_line):''; ?></small></td><td><?php echo intval($q->count); ?></td><td><?php echo esc_html($q->total_ms.' / '.$q->max_ms.' ms'); ?></td><td><code><?php echo esc_html(mb_substr($q->normalized_sql,0,1000)); ?></code></td><td><small><?php echo esc_html(implode('; ',(array)($ex['analysis']['reasons']??array()))); ?></small><?php if(!empty($cand['sql'])): ?><br><strong>Review-only index candidate:</strong><br><code><?php echo esc_html($cand['sql']); ?></code><br><small><?php echo esc_html($cand['warning']??''); ?></small><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        </section>
        <?php endif; ?>

        <?php if ( 'monitoring' === $active_view ): ?>
        <section class="wpi-view" data-wpi-view="monitoring" id="wpi-view-monitoring">
        <div class="wpi-view-heading"><h2>Real-user monitoring</h2><p>Compare p75 browser performance by route group and relate regressions to recorded WordPress changes.</p></div>
        <h2>Real User Monitoring, Last 24 Hours</h2><div class="wpi-grid">
        <div class="wpi-box"><table class="widefat striped wpi-responsive-table"><thead><tr><th>Metric</th><th>Route group</th><th>Samples</th><th>p75</th><th>Average</th><th>Worst observed</th></tr></thead><tbody><?php if(!$rum): ?><tr><td colspan="6">No browser metrics arrived in the last 24 hours. Confirm the sample rate, page-cache deployment and REST accessibility.</td></tr><?php endif; ?><?php foreach($rum as $m): $unit='cls'===$m['metric']?'':' ms'; ?><tr><td data-label="Metric"><?php echo esc_html(strtoupper($m['metric'])); ?></td><td data-label="Route group"><code><?php echo esc_html($m['route_group']?:'legacy/unknown'); ?></code></td><td data-label="Samples"><?php echo intval($m['samples']); ?><?php if((int)$m['samples']<30): ?><br><small>Low confidence</small><?php endif; ?></td><td data-label="p75"><strong><?php echo esc_html( WPI_Utils::metric_percentile( $m, .75 ) . $unit ); ?></strong></td><td data-label="Average"><?php echo esc_html(round((float)$m['average_value'],2).$unit); ?></td><td data-label="Worst observed"><?php echo esc_html(round((float)$m['value_max'],2).$unit); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="wpi-box"><h3>Recent WordPress Changes</h3><?php if(!$changes): ?><p>No tracked updates/activation changes yet.</p><?php else: ?><ul><?php foreach(array_slice($changes,0,12) as $c): ?><li><strong><?php echo esc_html($c['change_type']); ?></strong> <?php echo esc_html($c['object_name']); ?> <small><?php echo esc_html($c['created_at']); ?></small><?php if(empty($c['reverted_at']) && in_array($c['change_type'],array('autoload','db_cleanup_orphans'),true)): ?> <form style="display:inline" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="wpi_db_fix"><input type="hidden" name="repair_action" value="rollback_change"><input type="hidden" name="change_id" value="<?php echo intval($c['id']); ?>"><?php wp_nonce_field('wpi_db_fix'); ?><button class="button-link">Undo</button></form><?php elseif(!empty($c['reverted_at'])): ?> <small>(reverted <?php echo esc_html($c['reverted_at']); ?>)</small><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?><?php if(!empty($scan['regression']['regressions'])): ?><p><strong>Detected regressions:</strong> <?php echo intval(count($scan['regression']['regressions'])); ?></p><?php endif; ?></div>
        </div>

        <h2>Monitoring Settings</h2><div class="wpi-box"><form class="wpi-form-stack" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post"><input type="hidden" name="action" value="wpi_settings"><?php wp_nonce_field('wpi_settings'); ?>
        <div class="wpi-form-row"><label>Server sample rate (%)<input type="number" min="0" max="100" step="0.1" name="sample_percent" value="<?php echo esc_attr((float)$runtime['sample_rate']*100); ?>"></label><label>RUM sample rate (%)<input type="number" min="0" max="100" step="0.1" name="rum_percent" value="<?php echo esc_attr((float)$runtime['rum_rate']*100); ?>"></label><label>Retention (days)<input type="number" min="7" max="365" name="retention_days" value="<?php echo esc_attr((int)$runtime['retention_days']); ?>"></label></div>
        <label>Additional deep-scan routes, one URL per line<textarea name="route_urls" rows="5" placeholder="<?php echo esc_attr( home_url('/example-route/') ); ?>"><?php echo esc_textarea( implode( "\n", (array) ( $runtime['route_urls'] ?? array() ) ) ); ?></textarea></label><p class="description">Only URLs on the configured WordPress origin are saved. Deep scans test these routes in addition to representative public post types.</p><button class="button button-primary">Save monitoring settings</button></form>
        <p class="description">Keep passive server sampling low on high-traffic sites. Use signed profiles for full traces and paired experiments.</p></div>
        </section>
        <?php endif; ?>
        </div><?php
    }

    private static function top_timings( $payload ) {
        if ( ! is_array( $payload ) ) { return ''; }
        $items = array();
        foreach ( (array) ( $payload['hook_ms'] ?? array() ) as $name => $ms ) { if ( $ms >= 10 ) { $items[ 'hook:' . $name ] = (float) $ms; } }
        foreach ( (array) ( $payload['phases'] ?? array() ) as $name => $ms ) { if ( $ms >= 10 ) { $items[ 'phase:' . $name ] = (float) $ms; } }
        arsort( $items ); $text=array(); foreach(array_slice($items,0,4,true) as $name=>$ms){$text[]=$name.' '.round($ms).'ms';} return implode(', ',$text);
    }

    private static function render_scan_details( array $scan ) {
        $db = $scan['database_health']; $sys = $scan['system']; $jobs=$scan['jobs']; $cache=$scan['cache']; $front=$scan['frontend'];
        ?>
        <h2>Latest Full Scan</h2><div class="wpi-grid">
        <div class="wpi-box"><h3>Database</h3><p><strong><?php echo esc_html(size_format((int)$scan['database']['total_size'])); ?></strong> total; <?php echo intval(count($db['schema']['tables'])); ?> tables inspected.</p><ul><li>Server family: <?php echo esc_html(strtoupper((string)($db['server']['storage_engines']['family']??'unknown'))); ?></li><li>Table engines in use: <?php echo esc_html(implode(', ', array_map(static function($name,$count){return $name.' ('.$count.')';}, array_keys((array)$db['schema']['engines']), array_values((array)$db['schema']['engines'])))); ?></li><li>Missing core tables: <?php echo intval(count($db['schema']['missing_core_tables'])); ?></li><li>Missing core indexes: <?php echo intval(count($db['schema']['missing_core_indexes'])); ?></li><li>Missing core columns: <?php echo intval(count($db['schema']['missing_core_columns']??array())); ?></li><li>Core column definition drift: <?php echo intval(count($db['schema']['mismatched_core_columns']??array())); ?></li><li>Core index definition drift: <?php echo intval(count($db['schema']['mismatched_core_indexes']??array())); ?></li><li>Integrity problems: <?php echo intval(count($db['integrity']['problems']??array())); ?></li><li>Large tables without primary key: <?php echo intval(count($db['schema']['tables_without_primary_key'])); ?></li><li>Autoload: <?php echo esc_html(size_format((int)$db['options']['autoload_bytes'])); ?> / <?php echo intval($db['options']['autoload_count']); ?> options</li><li>Running DB processes visible: <?php echo intval($db['server']['processes']['running']??0); ?></li></ul></div>
        <div class="wpi-box"><h3>Plugins / PHP</h3><p><strong><?php echo intval($sys['plugins']['active_count']); ?></strong> active plugins.</p><ul><li>Paused by Recovery Mode: <?php echo intval(count($sys['plugins']['paused'])); ?></li><li>Recent error fingerprints: <?php echo intval(count($sys['errors']['findings'])); ?></li><li>Registered callbacks this admin request: <?php echo intval($sys['hooks']['total_callbacks']); ?></li><li>OPcache: <?php echo $sys['php']['opcache_configured']?'enabled':'disabled'; ?></li></ul></div>
        <div class="wpi-box"><h3>Jobs / Cache</h3><ul><li>Cron events: <?php echo intval($jobs['cron']['events']); ?></li><li>Overdue: <?php echo intval($jobs['cron']['overdue']); ?></li><li>Action Scheduler: <?php echo !empty($jobs['action_scheduler']['available'])?'detected':'not detected'; ?></li><li>Persistent object cache: <?php echo $cache['persistent']?'yes':'no'; ?></li><li>Cache round-trip: <?php echo !empty($cache['roundtrip']['ok'])?'pass':'fail'; ?> (<?php echo esc_html($cache['roundtrip']['get_ms']); ?> ms GET)</li></ul></div>
        <div class="wpi-box"><h3>Frontend probes</h3><?php foreach($front['pages'] as $p): ?><p><code><?php echo esc_html($p['url']); ?></code><br><?php if(!empty($p['ok'])): ?><?php echo esc_html($p['total_ms']); ?> ms; <?php echo esc_html(size_format((int)$p['html_bytes'])); ?> HTML; <?php echo intval($p['assets']['total']); ?> resources; <?php echo intval($p['assets']['third_party']); ?> third-party<?php else: ?>FAILED: <?php echo esc_html($p['error']??$p['status']); ?><?php endif; ?></p><?php endforeach; ?></div>
        </div>

        <details><summary>Database table health</summary><div><table class="widefat striped"><thead><tr><th>Table</th><th>Rows est.</th><th>Engine</th><th>Collation</th><th>Data</th><th>Index</th><th>Free</th></tr></thead><tbody><?php foreach(array_slice($db['schema']['tables'],0,40) as $t): ?><tr><td><code><?php echo esc_html($t['name']); ?></code></td><td><?php echo esc_html(number_format_i18n($t['rows_estimate'])); ?></td><td><?php echo esc_html($t['engine']); ?></td><td><?php echo esc_html($t['collation']); ?></td><td><?php echo esc_html(size_format($t['data'])); ?></td><td><?php echo esc_html(size_format($t['index'])); ?></td><td><?php echo esc_html(size_format($t['data_free'])); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Database runtime / integrity signals</summary><div><ul><li>Visible running processes: <?php echo intval($db['server']['processes']['running']??0); ?></li><li>Lock-related processes: <?php echo intval($db['server']['processes']['locked']??0); ?></li><li>Server slow-query counter: <?php echo esc_html(number_format_i18n((int)($db['server']['status']['Slow_queries']??0))); ?></li><li>Disk temporary tables: <?php echo esc_html(number_format_i18n((int)($db['server']['status']['Created_tmp_disk_tables']??0))); ?></li><li>Current InnoDB row lock waits: <?php echo intval($db['server']['status']['Innodb_row_lock_current_waits']??0); ?></li><?php if(!empty($db['server']['innodb']['available'])): ?><li>Recent InnoDB deadlock section: <?php echo !empty($db['server']['innodb']['deadlock_detected'])?'yes':'no'; ?></li><li>InnoDB history list length: <?php echo esc_html(number_format_i18n((int)($db['server']['innodb']['history_list_length']??0))); ?></li><?php endif; ?></ul><?php if(!empty($db['integrity']['checked'])): ?><p><strong>Deep CHECK TABLE results:</strong> <?php echo intval(count($db['integrity']['checked'])); ?> table(s) checked; <?php echo intval(count($db['integrity']['problems']??array())); ?> problem(s).</p><table class="widefat striped"><thead><tr><th>Table</th><th>Engine</th><th>Status</th><th>Check</th></tr></thead><tbody><?php foreach(array_slice($db['integrity']['checked'],0,75) as $check): ?><tr><td><code><?php echo esc_html($check['table']??''); ?></code></td><td><?php echo esc_html($check['engine']??''); ?></td><td><?php echo esc_html($check['status']??''); ?></td><td><?php echo esc_html($check['check']??''); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></details>
        <details><summary>InnoDB recovery / lock / configuration analysis</summary><div>
        <?php $inn=(array)($db['server']['innodb']??array()); $cfg=(array)($inn['configuration_advice']??array()); $ddl=(array)($inn['online_ddl']??array()); ?>
        <p><strong>Online DDL:</strong> <?php echo !empty($ddl['inplace'])?'INPLACE supported':'not verified'; ?>; <?php echo !empty($ddl['lock_none'])?'LOCK=NONE supported':'LOCK=NONE not verified'; ?>; <?php echo !empty($ddl['instant'])?'INSTANT supported':'INSTANT not assumed'; ?>. WPI fails closed rather than falling back to COPY.</p>
        <p><strong>InnoDB dataset:</strong> <?php echo esc_html(size_format((int)($cfg['dataset_bytes']??0))); ?>; <strong>buffer pool:</strong> <?php echo esc_html(size_format((int)($cfg['buffer_pool_bytes']??0))); ?><?php if(isset($cfg['buffer_pool_hit_ratio']) && null!==$cfg['buffer_pool_hit_ratio']): ?>; <strong>calculated hit rate:</strong> <?php echo esc_html(round(100*(float)$cfg['buffer_pool_hit_ratio'],3)); ?>%<?php endif; ?>.</p>
        <?php foreach((array)($cfg['recommendations']??array()) as $rec): ?><p class="wpi-<?php echo esc_attr($rec['severity']??'warning'); ?>"><strong><?php echo esc_html($rec['title']??''); ?>:</strong> <?php echo esc_html($rec['detail']??''); ?><br><small><?php echo esc_html($rec['recommendation']??''); ?></small></p><?php endforeach; ?>
        <?php if(!empty($inn['deadlock']['available'])): ?><h4>Latest parsed deadlock</h4><p>Tables: <code><?php echo esc_html(implode(', ',(array)($inn['deadlock']['tables']??array()))); ?></code></p><?php foreach((array)($inn['deadlock']['queries']??array()) as $dq): ?><p><code><?php echo esc_html($dq); ?></code></p><?php endforeach; ?><?php endif; ?>
        <?php if(!empty($inn['lock_graph']['edges'])): ?><h4>Active blocker/waiter graph</h4><table class="widefat striped"><thead><tr><th>Waiting trx</th><th>Blocking trx</th><th>Table / index</th><th>Waiting query</th><th>Blocking query</th></tr></thead><tbody><?php foreach(array_slice($inn['lock_graph']['edges'],0,25) as $edge): ?><tr><td><code><?php echo esc_html($edge['requesting_trx_id']??''); ?></code></td><td><code><?php echo esc_html($edge['blocking_trx_id']??''); ?></code></td><td><?php echo esc_html($edge['table']??''); ?><?php if(!empty($edge['index'])): ?><br><code><?php echo esc_html($edge['index']); ?></code><?php endif; ?></td><td><code><?php echo esc_html(mb_substr((string)($edge['requesting_query']??''),0,500)); ?></code></td><td><code><?php echo esc_html(mb_substr((string)($edge['blocking_query']??''),0,500)); ?></code></td></tr><?php endforeach; ?></tbody></table><?php elseif(!empty($inn['available'])): ?><p class="wpi-good">No active blocker/waiter edges were captured.</p><?php endif; ?>
        </div></details>
        <details><summary>Storage-engine capabilities</summary><div><p>Detected engine support is read from the live database server. WPI only exposes maintenance operations it recognizes as appropriate for that engine.</p><table class="widefat striped"><thead><tr><th>Engine</th><th>Server support</th><th>Transactions</th><th>Check</th><th>Repair</th><th>Optimize</th><th>Analyze</th><th>Notes</th></tr></thead><tbody><?php foreach((array)($db['server']['storage_engines']['engines']??array()) as $engine): if(empty($engine['available'])){continue;} $cap=(array)($engine['capabilities']??array()); ?><tr><td><strong><?php echo esc_html($engine['engine']); ?></strong><?php echo !empty($engine['default'])?' <small>(default)</small>':''; ?></td><td><?php echo esc_html($engine['support']); ?></td><td><?php echo esc_html($engine['transactions']); ?></td><td><?php echo !empty($cap['check'])?'yes':'no'; ?></td><td><?php echo !empty($cap['repair'])?'yes':'no'; ?></td><td><?php echo !empty($cap['optimize'])?'yes':'no'; ?></td><td><?php echo !empty($cap['analyze'])?'yes':'no'; ?></td><td><?php echo esc_html($cap['notes']??''); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Plugin loading signals</summary><div><table class="widefat striped"><thead><tr><th>Plugin</th><th>Version</th><th>Included PHP files</th><th>Callbacks</th><th>Hooks</th><th>Autoload estimate</th><th>DB table estimate</th></tr></thead><tbody><?php foreach(array_slice(array_filter($sys['plugins']['plugins'],static function($p){return $p['active'];}),0,80) as $p): ?><tr><td><?php echo esc_html($p['name']); ?><br><small><code><?php echo esc_html($p['file']); ?></code></small></td><td><?php echo esc_html($p['version']); ?></td><td><?php echo intval($p['included_php_files_current_request']); ?></td><td><?php echo intval($p['registered_callbacks_current_request']); ?></td><td><?php echo intval($p['registered_hooks_current_request']); ?></td><td><?php echo esc_html(size_format((int)($p['autoload_bytes_estimate']??0))); ?></td><td><?php echo esc_html(size_format((int)($p['database_bytes_estimate']??0))); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Recent PHP / WordPress / database error fingerprints</summary><div><table class="widefat striped"><thead><tr><th>Type</th><th>Component</th><th>Count</th><th>Fingerprint</th></tr></thead><tbody><?php foreach($sys['errors']['findings'] as $e): ?><tr><td><?php echo esc_html($e['type']); ?></td><td><?php echo esc_html($e['component']); ?></td><td><?php echo intval($e['count']); ?></td><td><code><?php echo esc_html($e['fingerprint']); ?></code></td></tr><?php endforeach; ?></tbody></table></div></details>
        <details><summary>Largest autoloaded options</summary><div><table class="widefat striped"><thead><tr><th>Option</th><th>Size</th><th>Autoload</th></tr></thead><tbody><?php foreach(array_slice($db['options']['largest_autoload'],0,30) as $o): ?><tr><td><code><?php echo esc_html($o['option_name']); ?></code></td><td><?php echo esc_html(size_format((int)$o['bytes'])); ?></td><td><?php echo esc_html($o['autoload']); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <?php
    }
}
