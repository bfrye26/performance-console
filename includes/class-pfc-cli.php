<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_CLI {
    public static function register() { WP_CLI::add_command( 'performance', __CLASS__ ); }

    /** Run the full diagnostic scanner. ## OPTIONS [--deep] [--force-large] */
    public function scan( $args, $assoc ) {
        $r = PFC_Scanner::scan( ! empty( $assoc['deep'] ), ! empty( $assoc['force-large'] ) );
        WP_CLI::log( wp_json_encode( $r, JSON_PRETTY_PRINT ) );
    }

    /** Analyze database schema, integrity, runtime state and scaling risks. ## OPTIONS [--deep] [--force-large] */
    public function database( $args, $assoc ) {
        WP_CLI::log( wp_json_encode( PFC_Scanner::database( ! empty( $assoc['deep'] ), ! empty( $assoc['force-large'] ) ), JSON_PRETTY_PRINT ) );
    }

    /** Create and verify a private logical database backup. ## OPTIONS [--scope=<wordpress|full>] */
    public function database_backup( $args, $assoc ) {
        $scope = isset( $assoc['scope'] ) && 'full' === sanitize_key( $assoc['scope'] ) ? 'full' : 'wordpress';
        $result = PFC_Database_Backup::cli_create( $scope );
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::log( 'Backup #' . (int) $result['id'] . ': ' . (string) $result['filename'] );
        WP_CLI::log( 'Size: ' . size_format( (int) $result['size_bytes'] ) . '; rows: ' . number_format_i18n( (int) $result['row_count'] ) );
        WP_CLI::log( 'SHA-256: ' . (string) $result['sha256'] );
        WP_CLI::success( 'Database backup completed and verified.' );
    }

    /** List Performance Console-managed database backups. */
    public function database_backups() {
        $rows = PFC_Database_Backup::list_backups( 50 );
        if ( ! $rows ) { WP_CLI::log( 'No Performance Console database backups exist.' ); return; }
        $formatted = array();
        foreach ( $rows as $row ) {
            $formatted[] = array(
                'id' => $row['id'],
                'created_at' => $row['created_at'],
                'status' => $row['status'],
                'scope' => $row['scope'],
                'tables' => $row['tables_done'] . '/' . $row['table_count'],
                'rows' => $row['row_count'],
                'size' => size_format( (int) $row['size_bytes'] ),
                'verified_at' => $row['verified_at'],
                'file' => $row['filename'],
            );
        }
        WP_CLI\Utils\format_items( 'table', $formatted, array( 'id','created_at','status','scope','tables','rows','size','verified_at','file' ) );
    }

    /** List live database storage engines and Performance Console maintenance capabilities. */
    public function database_engines() {
        $info = PFC_Database_Health::storage_engine_support();
        $rows = array();
        foreach ( (array) ( $info['engines'] ?? array() ) as $engine ) {
            if ( empty( $engine['available'] ) ) { continue; }
            $cap = (array) ( $engine['capabilities'] ?? array() );
            $rows[] = array(
                'engine' => $engine['engine'] ?? '',
                'support' => $engine['support'] ?? '',
                'default' => ! empty( $engine['default'] ) ? 'yes' : 'no',
                'transactions' => $engine['transactions'] ?? '',
                'check' => ! empty( $cap['check'] ) ? 'yes' : 'no',
                'repair' => ! empty( $cap['repair'] ) ? 'yes' : 'no',
                'optimize' => ! empty( $cap['optimize'] ) ? 'yes' : 'no',
                'analyze' => ! empty( $cap['analyze'] ) ? 'yes' : 'no',
                'recovery' => $cap['recovery'] ?? '',
            );
        }
        WP_CLI::log( 'Database family: ' . strtoupper( (string) ( $info['family'] ?? 'unknown' ) ) );
        WP_CLI\Utils\format_items( 'table', $rows, array( 'engine','support','default','transactions','check','repair','optimize','analyze','recovery' ) );
    }

    /** List database fixes supported by the current scan. */
    public function database_repairs() {
        $health = PFC_Database_Health::inspect( true, false );
        $plans = PFC_Database_Repair::plans( $health );
        if ( ! $plans ) { WP_CLI::success( 'No database repairs are currently recommended.' ); return; }
        WP_CLI\Utils\format_items( 'table', $plans, array( 'safety','title','detail','action','available' ) );
    }

    /** Run an explicit database fix. ## OPTIONS <action> [--type=<type>] [--table=<table>] [--index=<index>] [--option=<option>] [--limit=<rows>] [--change-id=<id>] [--backup-id=<id>] [--thread-id=<id>] [--transaction-id=<id>] [--backup-confirmed] [--danger-confirmed] [--data-loss-confirmed] [--rollback-confirmed] [--high-rollback-confirmed] [--force-large] */
    public function database_fix( $args, $assoc ) {
        $action = sanitize_key( (string) ( $args[0] ?? '' ) );
        if ( ! $action ) { WP_CLI::error( 'Provide a database repair action. Run wp performance database-repairs to see current recommendations.' ); }
        $repair_args = array();
        foreach ( array( 'type','table','index','option','limit','change-id','backup-id','thread-id','transaction-id' ) as $key ) { if ( isset( $assoc[ $key ] ) ) { $repair_args[ str_replace( '-', '_', $key ) ] = $assoc[ $key ]; } }
        if ( isset( $assoc['backup-confirmed'] ) ) { $repair_args['backup_confirmed'] = '1'; }
        if ( isset( $assoc['danger-confirmed'] ) ) { $repair_args['danger_confirmed'] = '1'; }
        if ( isset( $assoc['data-loss-confirmed'] ) ) { $repair_args['data_loss_confirmed'] = '1'; }
        if ( isset( $assoc['rollback-confirmed'] ) ) { $repair_args['rollback_confirmed'] = '1'; }
        if ( isset( $assoc['high-rollback-confirmed'] ) ) { $repair_args['high_rollback_confirmed'] = '1'; }
        $result = PFC_Database_Repair::execute( $action, $repair_args, ! empty( $assoc['force-large'] ) );
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
        WP_CLI::success( $result['message'] ?? 'Database repair completed.' );
    }

    /** List live InnoDB transactions with termination risk classification. */
    public function innodb_transactions() {
        $snapshot = PFC_Database_Repair::transaction_manager_snapshot();
        if ( empty( $snapshot['available'] ) ) { WP_CLI::warning( $snapshot['message'] ?? 'InnoDB transaction details are unavailable.' ); return; }
        $rows = array();
        foreach ( (array) ( $snapshot['transactions'] ?? array() ) as $trx ) {
            $rows[] = array(
                'thread' => (int) ( $trx['thread_id'] ?? 0 ),
                'trx' => (string) ( $trx['id'] ?? '' ),
                'age' => (int) ( $trx['age_seconds'] ?? 0 ),
                'state' => (string) ( $trx['state'] ?? '' ),
                'user' => (string) ( $trx['process_user'] ?? '' ),
                'command' => (string) ( $trx['process_command'] ?? '' ),
                'modified' => (int) ( $trx['rows_modified'] ?? 0 ),
                'locked' => (int) ( $trx['rows_locked'] ?? 0 ),
                'blocker' => ! empty( $trx['is_blocker'] ) ? 'yes' : 'no',
                'waiting' => ! empty( $trx['is_waiting'] ) ? 'yes' : 'no',
                'risk' => (string) ( $trx['risk'] ?? '' ),
                'killable' => ! empty( $trx['can_terminate'] ) ? 'yes' : 'no',
                'query' => mb_substr( (string) ( ( $trx['query'] ?? '' ) ?: ( $trx['process_info'] ?? '' ) ), 0, 180 ),
            );
        }
        if ( ! $rows ) { WP_CLI::success( 'No active InnoDB transactions are visible.' ); return; }
        WP_CLI\Utils\format_items( 'table', $rows, array( 'thread','trx','age','state','user','command','modified','locked','blocker','waiting','risk','killable','query' ) );
    }

    /** Terminate an eligible InnoDB connection and roll back its uncommitted transaction. ## OPTIONS <thread-id> --rollback-confirmed [--transaction-id=<id>] [--high-rollback-confirmed] */
    public function innodb_terminate( $args, $assoc ) {
        $thread = absint( $args[0] ?? 0 );
        if ( ! $thread ) { WP_CLI::error( 'Provide a valid MySQL thread ID.' ); }
        if ( empty( $assoc['rollback-confirmed'] ) ) { WP_CLI::error( 'Pass --rollback-confirmed only after reviewing the transaction. Termination interrupts its session and rolls back uncommitted work.' ); }
        $repair = array( 'thread_id' => $thread, 'danger_confirmed' => '1', 'rollback_confirmed' => '1' );
        if ( isset( $assoc['transaction-id'] ) ) { $repair['transaction_id'] = sanitize_text_field( (string) $assoc['transaction-id'] ); }
        if ( ! empty( $assoc['high-rollback-confirmed'] ) ) { $repair['high_rollback_confirmed'] = '1'; }
        $result = PFC_Database_Repair::execute( 'terminate_innodb_transaction', $repair, false );
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
        WP_CLI::success( $result['message'] ?? 'InnoDB transaction termination requested.' );
    }

    /** Build a read-only guided InnoDB recovery preflight. ## OPTIONS <table> [--force-large] */
    public function innodb_preflight( $args, $assoc ) {
        $table = sanitize_text_field( (string) ( $args[0] ?? '' ) );
        $result = PFC_Database_Repair::execute( 'innodb_recovery_preflight', array( 'table' => $table ), ! empty( $assoc['force-large'] ) );
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
        WP_CLI::success( 'InnoDB recovery preflight completed.' );
    }

    /** Rebuild a verified InnoDB secondary index online. ## OPTIONS <table> <index> --backup-confirmed [--force-large] */
    public function innodb_rebuild_index( $args, $assoc ) {
        if ( empty( $assoc['backup-confirmed'] ) ) { WP_CLI::error( 'Pass --backup-confirmed only after verifying a current database backup/snapshot.' ); }
        $repair = array( 'table' => sanitize_text_field( (string) ( $args[0] ?? '' ) ), 'index' => sanitize_text_field( (string) ( $args[1] ?? '' ) ), 'backup_confirmed' => '1' );
        $result = PFC_Database_Repair::execute( 'rebuild_innodb_index', $repair, ! empty( $assoc['force-large'] ) );
        if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
        WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
        WP_CLI::success( $result['message'] ?? 'InnoDB index rebuild completed.' );
    }

    /** Inspect PHP/WordPress/plugins/hooks/error logs. ## OPTIONS [--deep] */
    public function system( $args, $assoc ) { WP_CLI::log( wp_json_encode( PFC_System_Health::inspect( ! empty( $assoc['deep'] ) ), JSON_PRETTY_PRINT ) ); }

    /** Inspect WP-Cron and Action Scheduler queues. ## OPTIONS [--deep] [--force-large] */
    public function jobs( $args, $assoc ) { WP_CLI::log( wp_json_encode( PFC_Job_Health::inspect( ! empty( $assoc['deep'] ), ! empty( $assoc['force-large'] ) ), JSON_PRETTY_PRINT ) ); }

    /** Inspect object/page cache integration. */
    public function cache() { WP_CLI::log( wp_json_encode( PFC_Cache_Health::inspect(), JSON_PRETTY_PRINT ) ); }

    /** Probe representative frontend routes and HTML/resource pressure. ## OPTIONS [--deep] */
    public function frontend( $args, $assoc ) { WP_CLI::log( wp_json_encode( PFC_Frontend_Health::inspect( ! empty( $assoc['deep'] ) ), JSON_PRETTY_PRINT ) ); }

    /** Inspect recent performance regressions and recorded changes. */
    public function regressions() { WP_CLI::log( wp_json_encode( PFC_Regression::inspect(), JSON_PRETTY_PRINT ) ); }

    /** List active findings. ## OPTIONS [--severity=<severity>] [--area=<area>] */
    public function issues( $args, $assoc ) {
        $rows = array_filter( PFC_Utils::incidents( array( 'open', 'verifying' ) ), static function ( $incident ) use ( $assoc ) {
            return ( empty( $assoc['severity'] ) || sanitize_key( $assoc['severity'] ) === $incident['severity'] ) && ( empty( $assoc['area'] ) || sanitize_key( $assoc['area'] ) === $incident['area'] );
        } );
        $rows = array_map( static function ( $incident ) { return array( 'severity' => $incident['severity'], 'area' => $incident['area'], 'title' => $incident['title'], 'impact' => $incident['impact'], 'occurrences' => $incident['occurrences'], 'routes' => count( $incident['routes'] ), 'last_seen' => $incident['last_seen'], 'recommendation' => $incident['recommendation'] ); }, $rows );
        WP_CLI\Utils\format_items( 'table', $rows, array( 'severity','area','title','impact','occurrences','routes','last_seen','recommendation' ) );
    }

    /** Install or repair the MU bootstrap. */
    public function bootstrap() {
        $r = PFC_Bootstrap::install();
        is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'MU bootstrap installed.' );
    }

    /** Run static/runtime self-tests. */
    public function self_test() {
        global $wpdb;
        $status = PFC_Bootstrap::status();
        $checks = array(
            'secret' => (bool) get_option( 'pfc_secret' ),
            'bootstrap_installed' => $status['installed'],
            'bootstrap_current' => $status['current'],
            'normalize_sql' => PFC_Utils::normalize_sql( "SELECT * FROM t WHERE id=123 AND email='a@b.com'" ) === 'SELECT * FROM t WHERE id=? AND email=?',
            'runs_table' => false, 'queries_table' => false, 'issues_table' => false, 'metrics_table' => false, 'option_usage_table' => false, 'backups_table' => false,
        );
        foreach ( array( 'runs','queries','issues','metrics','option_usage','backups' ) as $table ) {
            $checks[ $table . '_table' ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', PFC_Utils::table( $table ) ) );
        }
        foreach ( $checks as $k => $v ) { WP_CLI::log( ( $v ? 'PASS ' : 'FAIL ' ) . $k ); }
        if ( in_array( false, $checks, true ) ) { WP_CLI::error( 'One or more checks failed.' ); }
        WP_CLI::success( 'All self-tests passed.' );
    }

    /** Profile a URL with signed deep requests. ## OPTIONS <url> [--runs=<runs>] */
    public function profile( $args, $assoc ) {
        $url = $this->own_url( $args[0] );
        $runs = max( 1, min( 10, (int) ( $assoc['runs'] ?? 3 ) ) );
        $times = $this->timed( $url, '', $runs );
        if ( ! $times ) { WP_CLI::error( 'No verified diagnostic runs reached WordPress. A page cache/reverse proxy may be serving the URL before the MU profiler.' ); }
        $median = PFC_Utils::median( $times );
        WP_CLI::success( 'Server PHP runs: ' . implode( ', ', array_map( static function ( $v ) { return round( $v, 1 ); }, $times ) ) . ' ms; median ' . round( $median, 1 ) . ' ms. Deep SQL/HTTP/hook details are stored in Performance Console.' );
    }

    /** Estimate a plugin route impact using private signed exclusion requests. ## OPTIONS <url> <plugin-file> [--runs=<runs>] */
    public function plugin_impact( $args, $assoc ) {
        $url = $this->own_url( $args[0] );
        $plugin = sanitize_text_field( $args[1] );
        if ( $plugin === PFC_BASENAME ) { WP_CLI::error( 'Performance Console cannot exclude itself.' ); }
        $runs = max( 5, min( 10, (int) ( $assoc['runs'] ?? 5 ) ) );
        $active = array_merge( (array) get_option( 'active_plugins', array() ), is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array() );
        if ( ! in_array( $plugin, $active, true ) ) { WP_CLI::error( 'Plugin must be active.' ); }
        $this->timed( $url, '', 1 ); $this->timed( $url, $plugin, 1 );
        $base = array(); $without = array(); $deltas = array();
        for ( $pair = 0; $pair < $runs; $pair++ ) {
            $order = 0 === $pair % 2 ? array( '', $plugin ) : array( $plugin, '' );
            $pair_values = array( 'baseline' => null, 'without' => null );
            foreach ( $order as $exclude ) {
                $value = $this->timed( $url, $exclude, 1 );
                if ( '' === $exclude ) { $base = array_merge( $base, $value ); $pair_values['baseline'] = $value ? (float) $value[0] : null; }
                else { $without = array_merge( $without, $value ); $pair_values['without'] = $value ? (float) $value[0] : null; }
            }
            if ( null !== $pair_values['baseline'] && null !== $pair_values['without'] ) { $deltas[] = round( $pair_values['baseline'] - $pair_values['without'], 3 ); }
        }
        if ( count( $deltas ) < $runs ) { WP_CLI::error( 'Benchmark could not match every request to a verified server-side measurement. Check the MU profiler and page-cache bypass.' ); }
        $base_m = PFC_Utils::median( $base ); $without_m = PFC_Utils::median( $without );
        $analysis = PFC_Utils::analyze_paired_impact( $deltas, true );
        WP_CLI::log( 'Baseline PHP median: ' . round( $base_m, 1 ) . ' ms' );
        WP_CLI::log( 'Without ' . $plugin . ': ' . round( $without_m, 1 ) . ' ms' );
        WP_CLI::log( 'Pair deltas: ' . implode( ', ', array_map( static function ( $value ) { return ( $value >= 0 ? '+' : '' ) . round( $value, 1 ); }, $deltas ) ) . ' ms' );
        if ( $analysis['repeatable'] ) { WP_CLI::success( 'Paired route impact: ' . ( $analysis['delta'] >= 0 ? '+' : '' ) . $analysis['delta'] . ' ms; ' . $analysis['confidence'] . ' confidence.' ); }
        else { WP_CLI::warning( 'No repeatable plugin cost detected. The paired median ' . ( $analysis['delta'] >= 0 ? '+' : '' ) . $analysis['delta'] . ' ms is within the ±' . $analysis['noise_floor'] . ' ms noise floor.' ); }
    }

    private function own_url( $url ) {
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        if ( ! $url || ! PFC_Utils::same_origin_url( $url ) ) { WP_CLI::error( 'URL must use the configured WordPress scheme, host and port.' ); }
        $admin_path = trailingslashit( (string) wp_parse_url( admin_url(), PHP_URL_PATH ) );
        if ( 0 === strpos( trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ), $admin_path ) ) { WP_CLI::error( 'Profile a public route; wp-admin loopback requests are not authenticated.' ); }
        return $url;
    }

    private function timed( $url, $exclude, $runs ) {
        $a = array();
        for ( $i = 0; $i < $runs; $i++ ) {
            $probe_id = wp_generate_uuid4();
            $tok = PFC_Bootstrap::token( $url, $exclude, $probe_id );
            $u = add_query_arg( $tok, $url );
            $r = wp_remote_get( $u, array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ), 'headers' => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ) ) );
            $headers_match = ! is_wp_error( $r ) && '1' === trim( (string) wp_remote_retrieve_header( $r, 'x-pfc-diagnostic' ) ) && hash_equals( $probe_id, trim( (string) wp_remote_retrieve_header( $r, 'x-pfc-probe-id' ) ) ) && hash_equals( $exclude ?: 'none', trim( (string) wp_remote_retrieve_header( $r, 'x-pfc-excluded-plugin' ) ) );
            if ( $headers_match ) {
                $status = (int) wp_remote_retrieve_response_code( $r );
                $body = (string) wp_remote_retrieve_body( $r );
                $run = PFC_Utils::probe_run( $probe_id, $exclude );
                if ( $status >= 200 && $status < 300 && strlen( $body ) >= 128 && $run ) { $a[] = round( (float) $run['php_ms'], 1 ); }
            }
        }
        return $a;
    }

    /** List active plugins and loading/callback signals. */
    public function plugins() { WP_CLI\Utils\format_items( 'table', PFC_Scanner::plugins(), array( 'active','name','version','included_php_files_current_request','registered_callbacks_current_request','registered_hooks_current_request','file' ) ); }

    /** Compare two stored run IDs. ## OPTIONS <run-a> <run-b> */
    public function compare( $args ) {
        global $wpdb; $t=PFC_Utils::table('runs');
        $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",(int)$args[0]),ARRAY_A); $b=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",(int)$args[1]),ARRAY_A);
        if(!$a||!$b){WP_CLI::error('Run not found.');}
        $rows=array(); foreach(array('php_ms','db_ms','query_count','http_ms','http_count','memory_peak') as $k){$rows[]=array('metric'=>$k,'a'=>$a[$k],'b'=>$b[$k],'delta'=>(float)$b[$k]-(float)$a[$k]);}
        WP_CLI\Utils\format_items('table',$rows,array('metric','a','b','delta'));
    }
}
