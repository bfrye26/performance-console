<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Database health diagnostics. Expensive operations are gated behind deep mode.
 */
final class WPI_Database_Health {
    const LARGE_TABLE_ROWS = 2000000;

    public static function inspect( $deep = false, $force_large = false ) {
        global $wpdb;

        $tables = self::table_status();
        $status = self::mysql_status();
        $variables = self::mysql_variables();
        $processes = self::processlist();
        $storage_engines = self::storage_engine_support();
        $schema = self::schema_health( $tables );
        $integrity = self::integrity_checks( $tables, $deep, $force_large );
        $orphans = self::orphan_checks( $tables, $deep, $force_large );
        $options = self::options_health( $deep );
        $innodb = self::innodb_diagnostics( $deep );
        $innodb['online_ddl'] = self::online_ddl_capabilities( $wpdb->db_version(), (string) ( $storage_engines['family'] ?? 'mysql' ) );
        $innodb['configuration_advice'] = self::innodb_configuration_advice( $schema, $variables, $status, $innodb );

        return array(
            'server'        => array(
                'version'    => $wpdb->db_version(),
                'variables'  => $variables,
                'status'     => $status,
                'processes'  => $processes,
                'innodb'      => $innodb,
                'storage_engines' => $storage_engines,
            ),
            'schema'        => $schema,
            'integrity'     => $integrity,
            'orphans'       => $orphans,
            'options'       => $options,
        );
    }

    public static function table_status() {
        global $wpdb;
        $rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private static function table_belongs_to_site( $name ) {
        global $wpdb;
        $name = (string) $name;
        if ( '' === $name ) { return false; }
        if ( 0 === strpos( $name, (string) $wpdb->prefix ) ) { return true; }
        if ( ! is_multisite() ) { return 0 === strpos( $name, (string) $wpdb->base_prefix ); }
        $global_properties = array( 'users','usermeta','blogs','blogmeta','site','sitemeta','signups','registration_log' );
        foreach ( $global_properties as $property ) { if ( isset( $wpdb->$property ) && $name === (string) $wpdb->$property ) { return true; } }
        $base = (string) $wpdb->base_prefix;
        if ( $base && 0 === strpos( $name, $base ) ) {
            $rest = substr( $name, strlen( $base ) );
            // Include network/main-site custom tables, but avoid sweeping every numbered subsite table.
            return ! preg_match( '/^\d+_/', $rest );
        }
        return false;
    }



    /**
     * Return live storage-engine support plus conservative maintenance capabilities.
     * Capabilities describe what WPI is willing to offer, not every statement a server
     * might technically accept. Unknown/plugin engines remain diagnostic-only.
     */
    public static function storage_engine_support() {
        global $wpdb;
        $old = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results( 'SHOW ENGINES', ARRAY_A );
        $version_comment = (string) $wpdb->get_var( 'SELECT @@version_comment' );
        $wpdb->suppress_errors( $old );
        $family = false !== stripos( $version_comment . ' ' . $wpdb->db_version(), 'mariadb' ) ? 'mariadb' : 'mysql';
        $out = array(
            'family' => $family,
            'version_comment' => sanitize_text_field( $version_comment ),
            'engines' => array(),
        );
        foreach ( (array) $rows as $row ) {
            $engine = (string) ( $row['Engine'] ?? $row['ENGINE'] ?? '' );
            if ( '' === $engine ) { continue; }
            $support = strtoupper( (string) ( $row['Support'] ?? $row['SUPPORT'] ?? '' ) );
            $cap = self::engine_capabilities( $engine, $family );
            $out['engines'][ strtolower( $engine ) ] = array(
                'engine' => $engine,
                'support' => $support,
                'available' => in_array( $support, array( 'YES', 'DEFAULT' ), true ),
                'default' => 'DEFAULT' === $support,
                'transactions' => strtoupper( (string) ( $row['Transactions'] ?? $row['TRANSACTIONS'] ?? '' ) ),
                'xa' => strtoupper( (string) ( $row['XA'] ?? '' ) ),
                'savepoints' => strtoupper( (string) ( $row['Savepoints'] ?? $row['SAVEPOINTS'] ?? '' ) ),
                'comment' => sanitize_text_field( (string) ( $row['Comment'] ?? $row['COMMENT'] ?? '' ) ),
                'capabilities' => $cap,
            );
        }
        return $out;
    }

    /** Conservative engine-specific maintenance matrix for MySQL/MariaDB. */
    public static function engine_capabilities( $engine, $family = 'mysql' ) {
        $engine = strtolower( trim( (string) $engine ) );
        $family = 'mariadb' === strtolower( (string) $family ) ? 'mariadb' : 'mysql';
        $cap = array(
            'check' => false,
            'repair' => false,
            'optimize' => false,
            'analyze' => false,
            'transactional' => false,
            'automatic_repair' => false,
            'recovery' => 'manual',
            'notes' => 'Unknown or plugin-provided storage engine. WPI will inspect metadata but will not run maintenance statements automatically.',
        );
        switch ( $engine ) {
            case 'innodb':
                $cap['check'] = true;
                $cap['optimize'] = true;
                $cap['analyze'] = true;
                $cap['transactional'] = true;
                $cap['recovery'] = 'innodb';
                $cap['notes'] = 'InnoDB supports integrity checks, ANALYZE and OPTIMIZE/rebuild operations. REPAIR TABLE is not used; corruption recovery is backup/rebuild/engine-recovery work.';
                break;
            case 'myisam':
                $cap['check'] = true;
                $cap['repair'] = true;
                $cap['optimize'] = true;
                $cap['analyze'] = true;
                $cap['automatic_repair'] = true;
                $cap['recovery'] = 'repair-table';
                $cap['notes'] = 'MyISAM supports CHECK, REPAIR, ANALYZE and OPTIMIZE. Maintenance can lock the table and should be size-gated.';
                break;
            case 'aria':
                if ( 'mariadb' === $family ) {
                    $cap['check'] = true;
                    $cap['repair'] = true;
                    $cap['optimize'] = true;
                    $cap['analyze'] = true;
                    $cap['automatic_repair'] = true;
                    $cap['recovery'] = 'repair-table';
                    $cap['notes'] = 'Aria on MariaDB supports CHECK, REPAIR, ANALYZE and OPTIMIZE. Maintenance can lock/rebuild the table.';
                }
                break;
            case 'archive':
                $cap['check'] = true;
                $cap['repair'] = true;
                $cap['optimize'] = true;
                $cap['recovery'] = 'repair-table';
                $cap['notes'] = 'ARCHIVE supports CHECK/REPAIR and OPTIMIZE on common MySQL/MariaDB versions. WPI treats repair as reviewed maintenance.';
                break;
            case 'csv':
                $cap['check'] = true;
                $cap['repair'] = true;
                $cap['recovery'] = 'repair-table-data-loss-risk';
                $cap['notes'] = 'CSV supports CHECK/REPAIR, but repair may discard rows after the first damaged record. WPI will diagnose it but will not offer automatic web repair.';
                break;
            case 'memory':
            case 'heap':
                $cap['notes'] = 'MEMORY tables are non-durable and server-memory backed. WPI inspects size/schema but does not offer repair/rebuild actions.';
                break;
            case 'ndb':
            case 'ndbcluster':
                $cap['analyze'] = true;
                $cap['transactional'] = true;
                $cap['recovery'] = 'cluster-tooling';
                $cap['notes'] = 'NDB is cluster-managed. WPI can inspect metadata and ANALYZE support but leaves recovery and topology operations to NDB tooling.';
                break;
            case 'rocksdb':
            case 'myrocks':
                $cap['transactional'] = true;
                $cap['recovery'] = 'engine-tooling';
                $cap['notes'] = 'RocksDB/MyRocks is plugin/variant-specific. WPI remains diagnostic-only for repair/optimize unless the server reports a known safe path.';
                break;
        }
        return $cap;
    }

    private static function mysql_variables() {
        global $wpdb;
        $wanted = array(
            'innodb_buffer_pool_size', 'innodb_log_file_size', 'innodb_flush_log_at_trx_commit',
            'max_connections', 'max_allowed_packet', 'tmp_table_size', 'max_heap_table_size',
            'table_open_cache', 'thread_cache_size', 'wait_timeout', 'interactive_timeout',
            'slow_query_log', 'long_query_time', 'performance_schema', 'character_set_server',
            'collation_server', 'transaction_isolation', 'tx_isolation',
            'default_storage_engine', 'storage_engine', 'key_buffer_size', 'aria_pagecache_buffer_size',
            'innodb_file_per_table', 'innodb_stats_persistent', 'innodb_lock_wait_timeout',
            'innodb_io_capacity', 'innodb_io_capacity_max', 'innodb_redo_log_capacity', 'innodb_force_recovery',
            'innodb_page_size', 'innodb_log_buffer_size', 'innodb_flush_method', 'datadir',
        );
        $out = array();
        $old = $wpdb->suppress_errors( true );
        foreach ( $wanted as $name ) {
            $row = $wpdb->get_row( $wpdb->prepare( 'SHOW VARIABLES LIKE %s', $name ), ARRAY_N );
            if ( $row && isset( $row[1] ) ) { $out[ $name ] = $row[1]; }
        }
        $wpdb->suppress_errors( $old );
        return $out;
    }

    private static function mysql_status() {
        global $wpdb;
        $wanted = array(
            'Threads_connected', 'Threads_running', 'Max_used_connections', 'Slow_queries',
            'Questions', 'Queries', 'Created_tmp_tables', 'Created_tmp_disk_tables',
            'Opened_tables', 'Open_tables', 'Aborted_connects', 'Aborted_clients',
            'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads',
            'Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Innodb_row_lock_current_waits', 'Uptime',
            'Select_scan', 'Select_full_join', 'Com_select', 'Com_insert', 'Com_update', 'Com_delete',
            'Table_locks_immediate', 'Table_locks_waited', 'Key_read_requests', 'Key_reads',
            'Key_write_requests', 'Key_writes', 'Aria_pagecache_read_requests', 'Aria_pagecache_reads',
            'Aria_pagecache_write_requests', 'Aria_pagecache_writes',
        );
        $out = array();
        $old = $wpdb->suppress_errors( true );
        foreach ( $wanted as $name ) {
            $row = $wpdb->get_row( $wpdb->prepare( 'SHOW GLOBAL STATUS LIKE %s', $name ), ARRAY_N );
            if ( $row && isset( $row[1] ) ) { $out[ $name ] = is_numeric( $row[1] ) ? (float) $row[1] : $row[1]; }
        }
        $wpdb->suppress_errors( $old );
        return $out;
    }

    private static function innodb_diagnostics( $deep ) {
        global $wpdb;
        if ( ! $deep ) { return array( 'available' => false, 'deep_required' => true ); }
        $old = $wpdb->suppress_errors( true );
        $row = $wpdb->get_row( 'SHOW ENGINE INNODB STATUS', ARRAY_A );
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        $status_available = is_array( $row ) && ! empty( $row['Status'] );
        $text = $status_available ? (string) $row['Status'] : '';
        $history = null;
        if ( preg_match( '/History list length\s+(\d+)/i', $text, $m ) ) { $history = (int) $m[1]; }
        $pending_reads = null; $pending_writes = null;
        if ( preg_match( '/Pending reads\s+(\d+)/i', $text, $m ) ) { $pending_reads = (int) $m[1]; }
        if ( preg_match( '/Pending writes:\s*LRU\s+\d+,\s*flush list\s+(\d+)/i', $text, $m ) ) { $pending_writes = (int) $m[1]; }

        // INFORMATION_SCHEMA visibility depends on server/version/privileges. Treat it as optional evidence.
        $old = $wpdb->suppress_errors( true );
        // Prefer process metadata so the Repair Centre can distinguish an idle/stuck WordPress
        // session from active work. PROCESS visibility is host/privilege dependent, so fall back
        // to the transaction-only query rather than treating missing process metadata as failure.
        $transactions = $wpdb->get_results(
            'SELECT t.trx_id,t.trx_state,t.trx_started,t.trx_wait_started,t.trx_mysql_thread_id,t.trx_tables_locked,t.trx_lock_structs,t.trx_rows_locked,t.trx_rows_modified,t.trx_query,' .
            'TIMESTAMPDIFF(SECOND,t.trx_started,NOW()) trx_age_seconds,p.USER process_user,p.HOST process_host,p.DB process_db,p.COMMAND process_command,p.TIME process_time,p.STATE process_state,p.INFO process_info ' .
            'FROM information_schema.innodb_trx t LEFT JOIN information_schema.processlist p ON p.ID=t.trx_mysql_thread_id ORDER BY t.trx_started ASC LIMIT 50', ARRAY_A
        );
        $trx_error = $wpdb->last_error;
        if ( $trx_error || ! is_array( $transactions ) ) {
            $transactions = $wpdb->get_results( 'SELECT trx_id,trx_state,trx_started,trx_wait_started,trx_mysql_thread_id,trx_tables_locked,trx_lock_structs,trx_rows_locked,trx_rows_modified,trx_query,TIMESTAMPDIFF(SECOND,trx_started,NOW()) trx_age_seconds FROM information_schema.innodb_trx ORDER BY trx_started ASC LIMIT 50', ARRAY_A );
            $trx_error = $wpdb->last_error;
        }
        $legacy_lock_waits = $wpdb->get_var( 'SELECT COUNT(*) FROM information_schema.innodb_lock_waits' );
        $legacy_lock_error = $wpdb->last_error;
        $ps_lock_waits = $wpdb->get_var( 'SELECT COUNT(*) FROM performance_schema.data_lock_waits' );
        $ps_lock_error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        $trx_out = array();
        foreach ( (array) $transactions as $trx ) {
            $trx_out[] = array(
                'id' => sanitize_text_field( (string) ( $trx['trx_id'] ?? '' ) ),
                'state' => sanitize_text_field( (string) ( $trx['trx_state'] ?? '' ) ),
                'age_seconds' => isset( $trx['trx_age_seconds'] ) ? max( 0, (int) $trx['trx_age_seconds'] ) : null,
                'wait_started' => sanitize_text_field( (string) ( $trx['trx_wait_started'] ?? '' ) ),
                'thread_id' => absint( $trx['trx_mysql_thread_id'] ?? 0 ),
                'tables_locked' => absint( $trx['trx_tables_locked'] ?? 0 ),
                'lock_structs' => absint( $trx['trx_lock_structs'] ?? 0 ),
                'rows_locked' => absint( $trx['trx_rows_locked'] ?? 0 ),
                'rows_modified' => absint( $trx['trx_rows_modified'] ?? 0 ),
                'query' => WPI_Utils::normalize_sql( (string) ( $trx['trx_query'] ?? '' ) ),
                'process_user' => sanitize_text_field( (string) ( $trx['process_user'] ?? '' ) ),
                'process_host' => sanitize_text_field( (string) ( $trx['process_host'] ?? '' ) ),
                'process_db' => sanitize_text_field( (string) ( $trx['process_db'] ?? '' ) ),
                'process_command' => sanitize_text_field( (string) ( $trx['process_command'] ?? '' ) ),
                'process_time' => isset( $trx['process_time'] ) ? max( 0, (int) $trx['process_time'] ) : null,
                'process_state' => sanitize_text_field( (string) ( $trx['process_state'] ?? '' ) ),
                'process_info' => WPI_Utils::normalize_sql( (string) ( $trx['process_info'] ?? '' ) ),
            );
        }
        $lock_wait_count = null;
        $lock_wait_source = '';
        if ( '' === $legacy_lock_error && null !== $legacy_lock_waits ) { $lock_wait_count = (int) $legacy_lock_waits; $lock_wait_source = 'information_schema.innodb_lock_waits'; }
        elseif ( '' === $ps_lock_error && null !== $ps_lock_waits ) { $lock_wait_count = (int) $ps_lock_waits; $lock_wait_source = 'performance_schema.data_lock_waits'; }

        return array(
            'available' => $status_available || '' === $trx_error || null !== $lock_wait_count,
            'deep_required' => false,
            'status_available' => $status_available,
            'error' => $status_available ? '' : sanitize_text_field( $error ),
            'deadlock_detected' => false !== stripos( $text, 'LATEST DETECTED DEADLOCK' ),
            'deadlock' => self::parse_innodb_deadlock( $text ),
            'foreign_key_error_detected' => false !== stripos( $text, 'LATEST FOREIGN KEY ERROR' ),
            'history_list_length' => $history,
            'pending_reads' => $pending_reads,
            'pending_writes' => $pending_writes,
            'transactions_available' => '' === $trx_error,
            'transactions' => $trx_out,
            'lock_wait_count' => $lock_wait_count,
            'lock_wait_source' => $lock_wait_source,
            'lock_graph' => self::innodb_lock_graph( $trx_out ),
        );
    }

    /** Parse only structural deadlock evidence. Raw InnoDB status is intentionally not persisted. */
    private static function parse_innodb_deadlock( $text ) {
        $marker = stripos( (string) $text, 'LATEST DETECTED DEADLOCK' );
        if ( false === $marker ) { return array( 'available' => false, 'tables' => array(), 'queries' => array(), 'victim' => '' ); }
        $section = substr( (string) $text, $marker, 16000 );
        $tables = array();
        if ( preg_match_all( '/(?:table|of table)\s+`?([^`\s.]+)`?\.`?([^`\s,;]+)`?/i', $section, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $name = sanitize_text_field( (string) $match[1] . '.' . (string) $match[2] );
                if ( $name ) { $tables[ $name ] = true; }
            }
        }
        $queries = array();
        foreach ( preg_split( '/\r?\n/', $section ) as $line ) {
            $line = trim( (string) $line );
            if ( ! preg_match( '/^(SELECT|UPDATE|INSERT|DELETE|REPLACE)\b/i', $line ) ) { continue; }
            $normalized = WPI_Utils::normalize_sql( mb_substr( $line, 0, 2000 ) );
            if ( $normalized && ! in_array( $normalized, $queries, true ) ) { $queries[] = $normalized; }
            if ( count( $queries ) >= 6 ) { break; }
        }
        $victim = '';
        if ( preg_match( '/WE ROLL BACK TRANSACTION \((\d+)\)/i', $section, $m ) ) { $victim = sanitize_text_field( $m[1] ); }
        return array(
            'available' => true,
            'tables' => array_slice( array_keys( $tables ), 0, 12 ),
            'queries' => $queries,
            'victim' => $victim,
            'summary' => 'Deadlock evidence parsed without retaining the raw InnoDB status text.',
        );
    }

    /** Build a blocker -> waiter graph from Performance Schema, with an INFORMATION_SCHEMA fallback. */
    private static function innodb_lock_graph( array $transactions ) {
        global $wpdb;
        $trx = array();
        foreach ( $transactions as $row ) { if ( ! empty( $row['id'] ) ) { $trx[ (string) $row['id'] ] = $row; } }
        $old = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results(
            'SELECT w.REQUESTING_ENGINE_TRANSACTION_ID requesting_trx_id,w.BLOCKING_ENGINE_TRANSACTION_ID blocking_trx_id,' .
            'r.OBJECT_SCHEMA request_schema,r.OBJECT_NAME request_table,r.INDEX_NAME request_index,r.LOCK_TYPE request_lock_type,r.LOCK_MODE request_lock_mode,' .
            'b.OBJECT_SCHEMA blocking_schema,b.OBJECT_NAME blocking_table,b.INDEX_NAME blocking_index,b.LOCK_TYPE blocking_lock_type,b.LOCK_MODE blocking_lock_mode ' .
            'FROM performance_schema.data_lock_waits w ' .
            'LEFT JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID ' .
            'LEFT JOIN performance_schema.data_locks b ON b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID LIMIT 50', ARRAY_A
        );
        $ps_error = $wpdb->last_error;
        if ( $ps_error || ! is_array( $rows ) ) {
            $rows = $wpdb->get_results( 'SELECT requesting_trx_id,blocking_trx_id FROM information_schema.innodb_lock_waits LIMIT 50', ARRAY_A );
            $legacy_error = $wpdb->last_error;
        } else { $legacy_error = ''; }
        $wpdb->suppress_errors( $old );
        if ( ( $ps_error && $legacy_error ) || ! is_array( $rows ) ) { return array( 'available' => false, 'edges' => array() ); }
        $edges = array();
        foreach ( $rows as $row ) {
            $requesting = sanitize_text_field( (string) ( $row['requesting_trx_id'] ?? '' ) );
            $blocking = sanitize_text_field( (string) ( $row['blocking_trx_id'] ?? '' ) );
            $edges[] = array(
                'requesting_trx_id' => $requesting,
                'blocking_trx_id' => $blocking,
                'table' => sanitize_text_field( trim( (string) ( $row['request_schema'] ?? '' ) . '.' . (string) ( $row['request_table'] ?? '' ), '.' ) ),
                'index' => sanitize_text_field( (string) ( $row['request_index'] ?? '' ) ),
                'request_lock' => sanitize_text_field( trim( (string) ( $row['request_lock_type'] ?? '' ) . ' ' . (string) ( $row['request_lock_mode'] ?? '' ) ) ),
                'blocking_lock' => sanitize_text_field( trim( (string) ( $row['blocking_lock_type'] ?? '' ) . ' ' . (string) ( $row['blocking_lock_mode'] ?? '' ) ) ),
                'requesting_query' => isset( $trx[ $requesting ] ) ? (string) ( $trx[ $requesting ]['query'] ?? '' ) : '',
                'blocking_query' => isset( $trx[ $blocking ] ) ? (string) ( $trx[ $blocking ]['query'] ?? '' ) : '',
                'requesting_age_seconds' => isset( $trx[ $requesting ] ) ? (int) ( $trx[ $requesting ]['age_seconds'] ?? 0 ) : 0,
                'blocking_age_seconds' => isset( $trx[ $blocking ] ) ? (int) ( $trx[ $blocking ]['age_seconds'] ?? 0 ) : 0,
            );
        }
        return array( 'available' => true, 'source' => $ps_error ? 'information_schema.innodb_lock_waits' : 'performance_schema.data_lock_waits', 'edges' => $edges );
    }

    /** Selected InnoDB runtime diagnostics for explicit recovery/preflight workflows. */
    public static function innodb_runtime() {
        return self::innodb_diagnostics( true );
    }

    /** Conservative server/version feature map. Mutating code still explicitly requests the algorithm and fails closed. */
    public static function online_ddl_capabilities( $version, $family = 'mysql' ) {
        $family = 'mariadb' === strtolower( (string) $family ) ? 'mariadb' : 'mysql';
        $version = preg_replace( '/[^0-9.].*$/', '', (string) $version );
        if ( ! preg_match( '/^\d+\.\d+(?:\.\d+)?/', $version, $m ) ) { $version = '0.0.0'; } else { $version = $m[0]; }
        $inplace = 'mariadb' === $family ? version_compare( $version, '10.2.0', '>=' ) : version_compare( $version, '5.6.17', '>=' );
        $instant = 'mariadb' === $family ? version_compare( $version, '10.3.2', '>=' ) : version_compare( $version, '8.0.12', '>=' );
        return array(
            'family' => $family,
            'version' => $version,
            'inplace' => $inplace,
            'lock_none' => $inplace,
            'instant' => $instant,
            'fail_closed' => true,
            'notes' => $inplace ? 'WPI can request ALGORITHM=INPLACE, LOCK=NONE for supported InnoDB maintenance. It never silently falls back to ALGORITHM=COPY.' : 'Server version is too old or unrecognized for WPI to offer online InnoDB rebuild operations.',
        );
    }

    private static function innodb_configuration_advice( array $schema, array $variables, array $status, array $innodb ) {
        $dataset = 0;
        foreach ( (array) ( $schema['tables'] ?? array() ) as $table ) {
            if ( 0 === strcasecmp( (string) ( $table['engine'] ?? '' ), 'InnoDB' ) ) { $dataset += (int) ( $table['size'] ?? 0 ); }
        }
        $pool = (int) ( $variables['innodb_buffer_pool_size'] ?? 0 );
        $requests = (float) ( $status['Innodb_buffer_pool_read_requests'] ?? 0 );
        $reads = (float) ( $status['Innodb_buffer_pool_reads'] ?? 0 );
        $hit = $requests > 0 ? max( 0, min( 1, 1 - ( $reads / $requests ) ) ) : null;
        $redo = (int) ( $variables['innodb_redo_log_capacity'] ?? 0 );
        if ( $redo <= 0 && ! empty( $variables['innodb_log_file_size'] ) ) { $redo = 2 * (int) $variables['innodb_log_file_size']; }
        $advice = array();
        $force_recovery = (int) ( $variables['innodb_force_recovery'] ?? 0 );
        if ( $force_recovery > 0 ) {
            $advice[] = array( 'severity' => 'critical', 'title' => 'InnoDB force recovery is enabled', 'detail' => 'innodb_force_recovery=' . $force_recovery, 'recommendation' => 'Treat the server as being in emergency recovery mode. Extract/verify data and return to normal mode; recovery mode does not repair corruption.' );
        }
        if ( $pool > 0 && $dataset > 0 && $pool < min( $dataset, 512 * MB_IN_BYTES ) ) {
            $advice[] = array( 'severity' => 'warning', 'title' => 'InnoDB buffer pool is small relative to the dataset', 'detail' => size_format( $pool ) . ' buffer pool versus approximately ' . size_format( $dataset ) . ' of InnoDB tables/indexes.', 'recommendation' => 'Size the buffer pool with the database server memory budget and workload in mind. Do not allocate RAM solely from this ratio; first confirm the database host has headroom.' );
        }
        if ( null !== $hit && $requests > 10000 && $hit < 0.995 ) {
            $advice[] = array( 'severity' => $hit < 0.98 ? 'high' : 'warning', 'title' => 'InnoDB buffer-pool hit rate is lower than expected', 'detail' => round( 100 * $hit, 3 ) . '% calculated hit rate.', 'recommendation' => 'Prioritize scan-heavy/slow queries and working-set size before increasing memory. A larger buffer pool may help only when server RAM is available.' );
        }
        if ( $redo > 0 && $dataset > 4 * GB_IN_BYTES && $redo < 512 * MB_IN_BYTES ) {
            $advice[] = array( 'severity' => 'warning', 'title' => 'Redo capacity is small for a large InnoDB dataset', 'detail' => size_format( $redo ) . ' configured redo capacity for approximately ' . size_format( $dataset ) . ' of InnoDB data/indexes.', 'recommendation' => 'For write-heavy workloads, review redo sizing and checkpoint pressure with server metrics. This is a server configuration change and is never applied automatically.' );
        }
        if ( ! empty( $innodb['history_list_length'] ) && (int) $innodb['history_list_length'] > 100000 ) {
            $advice[] = array( 'severity' => 'high', 'title' => 'Purge history pressure is high', 'detail' => 'History list length is ' . number_format_i18n( (int) $innodb['history_list_length'] ) . '.', 'recommendation' => 'Resolve long-lived transactions/read views before tuning purge threads or I/O settings.' );
        }
        return array(
            'dataset_bytes' => $dataset,
            'buffer_pool_bytes' => $pool,
            'buffer_pool_hit_ratio' => null === $hit ? null : round( $hit, 6 ),
            'redo_capacity_bytes' => $redo,
            'recommendations' => $advice,
        );
    }

    private static function processlist() {
        global $wpdb;
        $old = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results( 'SHOW FULL PROCESSLIST', ARRAY_A );
        $wpdb->suppress_errors( $old );
        if ( ! is_array( $rows ) ) { return array( 'available' => false, 'running' => 0, 'long_running' => array() ); }
        $long = array();
        $running = 0;
        $locked = 0;
        foreach ( array_slice( $rows, 0, 250 ) as $row ) {
            $command = strtolower( (string) ( $row['Command'] ?? '' ) );
            $time = (int) ( $row['Time'] ?? 0 );
            $state = (string) ( $row['State'] ?? '' );
            $query = trim( (string) ( $row['Info'] ?? '' ) );
            $actionable = self::is_actionable_process( $command, $state, $query );
            if ( $actionable ) { $running++; }
            if ( $actionable && false !== stripos( $state, 'lock' ) ) { $locked++; }
            if ( $actionable && $time >= 5 ) {
                $long[] = array(
                    'id'      => absint( $row['Id'] ?? 0 ),
                    'user'    => sanitize_text_field( $row['User'] ?? '' ),
                    'host'    => sanitize_text_field( $row['Host'] ?? '' ),
                    'time'    => $time,
                    'command' => sanitize_text_field( $row['Command'] ?? '' ),
                    'state'   => sanitize_text_field( $state ),
                    'db'      => sanitize_text_field( $row['db'] ?? $row['Db'] ?? '' ),
                    'query'   => WPI_Utils::normalize_sql( $query ),
                );
            }
        }
        return array( 'available' => true, 'running' => $running, 'locked' => $locked, 'long_running' => array_slice( $long, 0, 20 ) );
    }

    public static function is_actionable_process( $command, $state, $query ) {
        $command = strtolower( trim( (string) $command ) );
        $state = strtolower( trim( (string) $state ) );
        $query = trim( (string) $query );
        if ( in_array( $command, array( '', 'sleep', 'daemon', 'binlog dump', 'binlog dump gtid', 'connect' ), true ) ) { return false; }
        if ( '' === $query && ( '' === $state || false !== strpos( $state, 'waiting on empty queue' ) || false !== strpos( $state, 'waiting for next activation' ) ) ) { return false; }
        return true;
    }

    private static function schema_health( array $tables ) {
        global $wpdb;
        $required = array(
            $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->users, $wpdb->usermeta,
            $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->comments, $wpdb->commentmeta,
        );
        if ( isset( $wpdb->termmeta ) ) { $required[] = $wpdb->termmeta; }
        $names = array();
        $table_details = array();
        $collations = array();
        $engines = array();
        foreach ( $tables as $table ) {
            $name = (string) ( $table['Name'] ?? '' );
            if ( '' === $name || ! self::table_belongs_to_site( $name ) ) { continue; }
            $names[ $name ] = true;
            $engine = (string) ( $table['Engine'] ?? '' );
            $collation = (string) ( $table['Collation'] ?? '' );
            $rows = (int) ( $table['Rows'] ?? 0 );
            $data = (int) ( $table['Data_length'] ?? 0 );
            $index = (int) ( $table['Index_length'] ?? 0 );
            $free = (int) ( $table['Data_free'] ?? 0 );
            $auto = isset( $table['Auto_increment'] ) ? (float) $table['Auto_increment'] : 0;
            $engines[ $engine ] = ( $engines[ $engine ] ?? 0 ) + 1;
            $collations[ $collation ] = ( $collations[ $collation ] ?? 0 ) + 1;
            $table_details[] = array(
                'name' => $name, 'engine' => $engine, 'collation' => $collation, 'rows_estimate' => $rows,
                'data' => $data, 'index' => $index, 'size' => $data + $index, 'data_free' => $free,
                'auto_increment' => $auto, 'comment' => sanitize_text_field( $table['Comment'] ?? '' ),
            );
        }
        usort( $table_details, static function ( $a, $b ) { return $b['size'] <=> $a['size']; } );
        $total_size = 0;
        foreach ( $table_details as $table_detail ) { $total_size += (int) $table_detail['size']; }

        $missing = array_values( array_filter( $required, static function ( $name ) use ( $names ) { return ! isset( $names[ $name ] ); } ) );
        $desired_schema = self::desired_core_schema();
        $index_health = self::core_index_checks( $missing, $required, $desired_schema );
        $column_health = self::core_column_checks( $missing, $required, $desired_schema );
        $no_primary = array();
        $duplicate_indexes = array();
        foreach ( array_slice( $table_details, 0, 100 ) as $table ) {
            if ( $table['rows_estimate'] < 10000 ) { continue; }
            $keys = self::show_indexes( $table['name'] );
            $has_primary = false;
            foreach ( $keys as $key ) { if ( 'PRIMARY' === ( $key['Key_name'] ?? '' ) ) { $has_primary = true; break; } }
            if ( ! $has_primary ) { $no_primary[] = $table['name']; }
            foreach ( self::duplicate_indexes( $keys ) as $duplicate ) {
                $duplicate_indexes[] = array( 'table' => $table['name'], 'indexes' => $duplicate );
            }
        }
        $auto_increment_risks = self::auto_increment_risks( $table_details );
        return array(
            'missing_core_tables' => $missing,
            'tables_without_primary_key' => $no_primary,
            'missing_core_indexes' => $index_health['missing'],
            'mismatched_core_indexes' => $index_health['mismatched'],
            'equivalent_core_index_aliases' => $index_health['aliases'],
            'missing_core_columns' => $column_health['missing'],
            'mismatched_core_columns' => $column_health['mismatched'],
            'duplicate_indexes' => array_slice( $duplicate_indexes, 0, 50 ),
            'auto_increment_risks' => $auto_increment_risks,
            'engines' => $engines,
            'collations' => $collations,
            'total_size' => $total_size,
            'table_count' => count( $table_details ),
            'tables' => array_slice( $table_details, 0, 75 ),
        );
    }

    /** Parse WordPress' own current schema so checks/fixes follow the installed core version. */
    private static function desired_core_schema() {
        require_once ABSPATH . 'wp-admin/includes/schema.php';
        $sql = function_exists( 'wp_get_db_schema' ) ? wp_get_db_schema( 'all' ) : '';
        $out = array();
        if ( ! is_string( $sql ) || '' === $sql ) { return $out; }

        // Do not parse CREATE TABLE bodies with a simple "(.*?)" regex. Core column
        // types such as bigint(20), varchar(255) and decimal(10,2) contain nested
        // parentheses and caused the old parser to stop at the first type width.
        // That produced false drift such as unsigned/NOT NULL/AUTO_INCREMENT being
        // reported as missing on completely healthy WordPress tables.
        foreach ( self::extract_create_table_bodies( $sql ) as $table => $body ) {
            $columns = array(); $indexes = array();
            foreach ( preg_split( '/\r?\n/', (string) $body ) as $line ) {
                $line = trim( rtrim( trim( $line ), ',' ) );
                if ( '' === $line ) { continue; }
                if ( preg_match( '/^PRIMARY\s+KEY\s+(.+)$/i', $line ) ) {
                    $indexes['PRIMARY'] = $line;
                    continue;
                }
                if ( preg_match( '/^(?:UNIQUE\s+)?KEY\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/i', $line, $im ) ) {
                    $indexes[ $im[1] ] = $line;
                    continue;
                }
                if ( preg_match( '/^`?([A-Za-z0-9_]+)`?\s+(.+)$/', $line, $cm ) ) {
                    $columns[ $cm[1] ] = $line;
                }
            }
            $out[ $table ] = array( 'columns' => $columns, 'indexes' => $indexes );
        }
        return $out;
    }

    /**
     * Extract balanced CREATE TABLE bodies without being confused by parentheses
     * inside MySQL type declarations, defaults, expressions or index definitions.
     *
     * @param string $sql SQL returned by wp_get_db_schema().
     * @return array<string,string> Table name => definition body.
     */
    private static function extract_create_table_bodies( $sql ) {
        $sql = (string) $sql;
        $length = strlen( $sql );
        $offset = 0;
        $tables = array();

        while ( $offset < $length ) {
            $start = stripos( $sql, 'CREATE TABLE', $offset );
            if ( false === $start ) { break; }

            $fragment = substr( $sql, $start );
            if ( ! preg_match( '/\ACREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $fragment, $tm ) ) {
                $offset = $start + 12;
                continue;
            }

            $table = (string) $tm[1];
            $open = strpos( $sql, '(', $start + strlen( $tm[0] ) );
            if ( false === $open ) {
                $offset = $start + strlen( $tm[0] );
                continue;
            }

            $depth = 1;
            $quote = '';
            $escaped = false;
            $closed = false;

            for ( $i = $open + 1; $i < $length; $i++ ) {
                $ch = $sql[ $i ];

                if ( '' !== $quote ) {
                    if ( $escaped ) {
                        $escaped = false;
                        continue;
                    }
                    if ( '\\' === $ch && '`' !== $quote ) {
                        $escaped = true;
                        continue;
                    }
                    if ( $ch === $quote ) {
                        // SQL strings/identifiers may escape a quote by doubling it.
                        if ( $i + 1 < $length && $sql[ $i + 1 ] === $quote ) {
                            $i++;
                            continue;
                        }
                        $quote = '';
                    }
                    continue;
                }

                if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
                    $quote = $ch;
                    continue;
                }
                if ( '(' === $ch ) {
                    $depth++;
                    continue;
                }
                if ( ')' === $ch ) {
                    $depth--;
                    if ( 0 === $depth ) {
                        $tables[ $table ] = substr( $sql, $open + 1, $i - $open - 1 );
                        $offset = $i + 1;
                        $closed = true;
                        break;
                    }
                }
            }

            if ( ! $closed ) {
                // Malformed/unexpected schema text: stop rather than inventing drift.
                break;
            }
        }

        return $tables;
    }

    /**
     * Return the exact core column definition expected by the installed WordPress version.
     * Used by the repair engine so PRIMARY KEY work can include prerequisite column
     * changes in the same ALTER instead of relying on repair ordering.
     */
    public static function expected_core_column_definition( $table, $column ) {
        $table = (string) $table;
        $column = (string) $column;
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) { return ''; }
        $desired = self::desired_core_schema();
        return isset( $desired[ $table ]['columns'][ $column ] ) ? (string) $desired[ $table ]['columns'][ $column ] : '';
    }

    /** Return the exact core index definition expected by the installed WordPress version. */
    public static function expected_core_index_definition( $table, $index ) {
        $table = (string) $table;
        $index = (string) $index;
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $index ) ) { return ''; }
        $desired = self::desired_core_schema();
        if ( isset( $desired[ $table ]['indexes'][ $index ] ) ) { return (string) $desired[ $table ]['indexes'][ $index ]; }
        foreach ( (array) ( $desired[ $table ]['indexes'] ?? array() ) as $name => $definition ) {
            if ( 0 === strcasecmp( (string) $name, $index ) ) { return (string) $definition; }
        }
        return '';
    }

    /** Expose the semantic core-column comparison to the guarded repair engine. */
    public static function compare_core_column_definition( $definition, array $actual ) {
        return self::column_definition_drift( $definition, $actual );
    }

    private static function core_index_checks( array $missing_tables, array $required_tables, array $desired ) {
        $missing = array(); $mismatched = array(); $aliases = array();
        foreach ( $required_tables as $table ) {
            if ( in_array( $table, $missing_tables, true ) || empty( $desired[ $table ]['indexes'] ) ) { continue; }
            $rows = self::show_indexes( $table );
            $actual = self::actual_index_signatures( $rows );
            foreach ( $desired[ $table ]['indexes'] as $name => $definition ) {
                $desired_sig = self::desired_index_signature( $definition );
                if ( ! $desired_sig ) { continue; }
                if ( isset( $actual[ $name ] ) ) {
                    if ( $actual[ $name ]['signature'] !== $desired_sig ) {
                        $mismatched[] = array(
                            'table' => $table, 'index' => $name, 'definition' => $definition,
                            'actual' => $actual[ $name ]['human'],
                        );
                    }
                    continue;
                }
                $equivalent = '';
                foreach ( $actual as $actual_name => $actual_definition ) {
                    if ( $actual_definition['signature'] === $desired_sig ) { $equivalent = $actual_name; break; }
                }
                if ( $equivalent ) {
                    $aliases[] = array( 'table' => $table, 'index' => $name, 'equivalent_index' => $equivalent, 'definition' => $definition );
                } else {
                    $missing[] = array( 'table' => $table, 'index' => $name, 'definition' => $definition );
                }
            }
        }
        return array( 'missing' => $missing, 'mismatched' => $mismatched, 'aliases' => $aliases );
    }

    private static function desired_index_signature( $definition ) {
        $definition = trim( (string) $definition );
        $unique = 1;
        if ( 0 === stripos( $definition, 'PRIMARY KEY' ) || 0 === stripos( $definition, 'UNIQUE KEY' ) ) { $unique = 0; }
        if ( ! preg_match( '/\((.+)\)/', $definition, $m ) ) { return ''; }
        $parts = array();
        foreach ( explode( ',', $m[1] ) as $part ) {
            $part = trim( $part );
            if ( ! preg_match( '/^`?([A-Za-z0-9_]+)`?(?:\((\d+)\))?$/', $part, $pm ) ) { return ''; }
            $parts[] = strtolower( $pm[1] ) . ':' . ( isset( $pm[2] ) ? (int) $pm[2] : 0 );
        }
        return md5( $unique . '|' . implode( ',', $parts ) );
    }

    private static function actual_index_signatures( array $rows ) {
        $by_name = array();
        foreach ( $rows as $row ) {
            $name = (string) ( $row['Key_name'] ?? '' );
            if ( '' === $name ) { continue; }
            if ( ! isset( $by_name[ $name ] ) ) { $by_name[ $name ] = array( 'unique' => (int) ( $row['Non_unique'] ?? 1 ), 'parts' => array() ); }
            $seq = max( 1, (int) ( $row['Seq_in_index'] ?? 1 ) );
            $by_name[ $name ]['parts'][ $seq ] = array(
                'column' => strtolower( (string) ( $row['Column_name'] ?? '' ) ),
                'prefix' => (int) ( $row['Sub_part'] ?? 0 ),
            );
        }
        $out = array();
        foreach ( $by_name as $name => $definition ) {
            ksort( $definition['parts'] );
            $parts = array(); $human = array();
            foreach ( $definition['parts'] as $part ) {
                $parts[] = $part['column'] . ':' . $part['prefix'];
                $human[] = $part['column'] . ( $part['prefix'] ? '(' . $part['prefix'] . ')' : '' );
            }
            $out[ $name ] = array(
                'signature' => md5( (int) $definition['unique'] . '|' . implode( ',', $parts ) ),
                'human' => ( 0 === (int) $definition['unique'] ? 'UNIQUE ' : '' ) . '(' . implode( ',', $human ) . ')',
            );
        }
        return $out;
    }

    private static function core_column_checks( array $missing_tables, array $required_tables, array $desired ) {
        global $wpdb;
        $missing = array(); $mismatched = array();
        $old = $wpdb->suppress_errors( true );
        foreach ( $required_tables as $table ) {
            if ( in_array( $table, $missing_tables, true ) || empty( $desired[ $table ]['columns'] ) ) { continue; }
            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) { continue; }
            $actual_rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $actual = array();
            foreach ( (array) $actual_rows as $row ) { $actual[ (string) ( $row['Field'] ?? '' ) ] = $row; }
            foreach ( $desired[ $table ]['columns'] as $name => $definition ) {
                if ( ! isset( $actual[ $name ] ) ) {
                    $missing[] = array( 'table' => $table, 'column' => $name, 'definition' => $definition );
                    continue;
                }
                $drift = self::column_definition_drift( $definition, $actual[ $name ] );
                if ( $drift ) {
                    $mismatched[] = array( 'table' => $table, 'column' => $name, 'definition' => $definition, 'drift' => $drift );
                }
            }
        }
        $wpdb->suppress_errors( $old );
        return array( 'missing' => $missing, 'mismatched' => $mismatched );
    }

    private static function column_definition_drift( $definition, array $actual ) {
        $definition = trim( (string) $definition );
        if ( ! preg_match( '/^`?[A-Za-z0-9_]+`?\s+([a-zA-Z]+(?:\([^)]*\))?(?:\s+unsigned)?)(.*)$/i', $definition, $m ) ) { return array(); }
        $expected_type = self::normalize_column_type( $m[1] );
        $actual_type = self::normalize_column_type( (string) ( $actual['Type'] ?? '' ) );
        $tail = (string) $m[2];
        $drift = array();
        if ( $expected_type && $actual_type && $expected_type !== $actual_type ) { $drift['type'] = array( 'expected' => $expected_type, 'actual' => $actual_type ); }

        $expected_null = false === stripos( $tail, 'NOT NULL' );
        $actual_null = 'YES' === strtoupper( (string) ( $actual['Null'] ?? 'YES' ) );
        if ( $expected_null !== $actual_null ) { $drift['null'] = array( 'expected' => $expected_null ? 'NULL' : 'NOT NULL', 'actual' => $actual_null ? 'NULL' : 'NOT NULL' ); }

        if ( preg_match( '/\bdefault\s+(?:\'([^\']*)\'|"([^"]*)"|([^\s,]+))/i', $tail, $dm ) ) {
            $expected_default = isset( $dm[1] ) && '' !== $dm[1] ? $dm[1] : ( isset( $dm[2] ) && '' !== $dm[2] ? $dm[2] : (string) ( $dm[3] ?? '' ) );
            if ( "''" === trim( substr( $tail, stripos( $tail, 'default' ) + 7, 2 ) ) ) { $expected_default = ''; }
            $actual_default = $actual['Default'] ?? null;
            if ( 'NULL' === strtoupper( $expected_default ) ) { $expected_default = null; }
            if ( is_string( $expected_default ) && 0 === strcasecmp( $expected_default, 'CURRENT_TIMESTAMP' ) ) {
                if ( 0 !== strcasecmp( (string) $actual_default, 'CURRENT_TIMESTAMP' ) ) { $drift['default'] = array( 'expected' => 'CURRENT_TIMESTAMP', 'actual' => $actual_default ); }
            } elseif ( (string) $expected_default !== (string) $actual_default || ( null === $expected_default ) !== ( null === $actual_default ) ) {
                $drift['default'] = array( 'expected' => $expected_default, 'actual' => $actual_default );
            }
        }
        $expected_auto = false !== stripos( $tail, 'auto_increment' );
        $actual_auto = false !== stripos( (string) ( $actual['Extra'] ?? '' ), 'auto_increment' );
        if ( $expected_auto !== $actual_auto ) { $drift['auto_increment'] = array( 'expected' => $expected_auto, 'actual' => $actual_auto ); }
        return $drift;
    }

    private static function normalize_column_type( $type ) {
        $type = strtolower( preg_replace( '/\s+/', ' ', trim( (string) $type ) ) );
        // Integer display widths are cosmetic/deprecated and differ across MySQL/MariaDB versions.
        $type = preg_replace( '/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type );
        return trim( $type );
    }

    private static function auto_increment_risks( array $tables ) {
        global $wpdb;
        $out = array();
        $old = $wpdb->suppress_errors( true );
        foreach ( $tables as $table ) {
            $next = (float) ( $table['auto_increment'] ?? 0 );
            if ( $next < 1000000000 ) { continue; }
            $name = (string) ( $table['name'] ?? '' );
            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) { continue; }
            $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            foreach ( (array) $columns as $column ) {
                if ( false === stripos( (string) ( $column['Extra'] ?? '' ), 'auto_increment' ) ) { continue; }
                $type = strtolower( (string) ( $column['Type'] ?? '' ) );
                $unsigned = false !== strpos( $type, 'unsigned' );
                $max = null;
                if ( preg_match( '/\btinyint\b/', $type ) ) { $max = $unsigned ? 255 : 127; }
                elseif ( preg_match( '/\bsmallint\b/', $type ) ) { $max = $unsigned ? 65535 : 32767; }
                elseif ( preg_match( '/\bmediumint\b/', $type ) ) { $max = $unsigned ? 16777215 : 8388607; }
                elseif ( preg_match( '/\bint\b/', $type ) && false === strpos( $type, 'bigint' ) ) { $max = $unsigned ? 4294967295 : 2147483647; }
                elseif ( preg_match( '/\bbigint\b/', $type ) ) { $max = $unsigned ? 18446744073709551615.0 : 9223372036854775807.0; }
                if ( $max && $next / $max >= 0.70 ) {
                    $out[] = array( 'table' => $name, 'column' => (string) $column['Field'], 'type' => $type, 'next_value' => $next, 'max_value' => $max, 'percent' => round( 100 * $next / $max, 2 ) );
                }
                break;
            }
        }
        $wpdb->suppress_errors( $old );
        return $out;
    }

    private static function show_indexes( $table ) {
        global $wpdb;
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) { return array(); }
        $rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return is_array( $rows ) ? $rows : array();
    }


    /** Find exact duplicate index definitions. Prefix-subset indexes are intentionally not treated as redundant. */
    private static function duplicate_indexes( array $rows ) {
        $by_name = array();
        foreach ( $rows as $row ) {
            $name = (string) ( $row['Key_name'] ?? '' );
            if ( '' === $name || 'PRIMARY' === $name ) { continue; }
            if ( ! isset( $by_name[ $name ] ) ) { $by_name[ $name ] = array( 'unique' => (int) ( $row['Non_unique'] ?? 1 ), 'parts' => array() ); }
            $seq = max( 1, (int) ( $row['Seq_in_index'] ?? 1 ) );
            $by_name[ $name ]['parts'][ $seq ] = array(
                'column' => (string) ( $row['Column_name'] ?? '' ),
                'sub_part' => isset( $row['Sub_part'] ) ? (string) $row['Sub_part'] : '',
                'collation' => (string) ( $row['Collation'] ?? '' ),
            );
        }
        $signatures = array();
        foreach ( $by_name as $name => $definition ) {
            ksort( $definition['parts'] );
            $signature = md5( wp_json_encode( array( $definition['unique'], array_values( $definition['parts'] ) ) ) );
            $signatures[ $signature ][] = $name;
        }
        $duplicates = array();
        foreach ( $signatures as $names ) { if ( count( $names ) > 1 ) { $duplicates[] = $names; } }
        return $duplicates;
    }

    private static function integrity_checks( array $tables, $deep, $force_large ) {
        global $wpdb;
        $problems = array();
        $checked = array();
        $engine_support = self::storage_engine_support();
        $family = (string) ( $engine_support['family'] ?? 'mysql' );
        $meta = array();
        foreach ( $tables as $table ) {
            $name = (string) ( $table['Name'] ?? '' );
            $engine = (string) ( $table['Engine'] ?? '' );
            $comment_raw = (string) ( $table['Comment'] ?? '' );
            $comment = strtolower( $comment_raw );
            $meta[ $name ] = array(
                'rows' => (int) ( $table['Rows'] ?? 0 ),
                'engine' => $engine,
                'capabilities' => self::engine_capabilities( $engine, $family ),
            );
            if ( false !== strpos( $comment, 'crash' ) || false !== strpos( $comment, 'corrupt' ) ) {
                $problems[] = array( 'table' => $name, 'engine' => $engine, 'message' => sanitize_text_field( $comment_raw ), 'source' => 'table-status' );
            }
        }
        if ( ! $deep ) { return array( 'checked' => $checked, 'problems' => $problems, 'deep_required' => true ); }

        $candidates = array();
        foreach ( array_keys( $meta ) as $table ) {
            if ( self::table_belongs_to_site( $table ) ) { $candidates[] = $table; }
            if ( count( $candidates ) >= 150 ) { break; }
        }
        foreach ( $candidates as $table ) {
            $engine = (string) ( $meta[ $table ]['engine'] ?? '' );
            $caps = (array) ( $meta[ $table ]['capabilities'] ?? array() );
            if ( empty( $caps['check'] ) ) {
                $checked[] = array( 'table' => $table, 'engine' => $engine, 'status' => 'unsupported-engine', 'check' => 'none' );
                continue;
            }
            if ( (int) $meta[ $table ]['rows'] > self::LARGE_TABLE_ROWS && ! $force_large ) {
                $checked[] = array( 'table' => $table, 'engine' => $engine, 'status' => 'skipped-large', 'check' => 'none' );
                continue;
            }
            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) { continue; }

            // Deep mode uses the engine-supported default CHECK TABLE. EXTENDED is never automatic.
            $statement = "CHECK TABLE `{$table}`";
            $old = $wpdb->suppress_errors( true );
            $rows = $wpdb->get_results( $statement, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $error = $wpdb->last_error;
            $wpdb->suppress_errors( $old );
            if ( $rows ) {
                $last = end( $rows );
                $status = strtolower( (string) ( $last['Msg_text'] ?? $last['msg_text'] ?? '' ) );
                $msg_type = strtolower( (string) ( $last['Msg_type'] ?? $last['msg_type'] ?? '' ) );
                $checked[] = array( 'table' => $table, 'engine' => $engine, 'status' => sanitize_text_field( $status ), 'message_type' => sanitize_key( $msg_type ), 'check' => 'CHECK TABLE' );
                $unsupported = false !== strpos( $status, "doesn't support" ) || false !== strpos( $status, 'not supported' );
                if ( ! $unsupported && ( 'ok' !== $status || 'error' === $msg_type ) ) {
                    $problems[] = array( 'table' => $table, 'engine' => $engine, 'message' => sanitize_text_field( $last['Msg_text'] ?? $last['msg_text'] ?? 'Integrity check failed' ), 'source' => 'check-table' );
                }
            } elseif ( $error ) {
                $checked[] = array( 'table' => $table, 'engine' => $engine, 'status' => 'unavailable', 'error' => sanitize_text_field( $error ), 'check' => 'CHECK TABLE' );
            }
        }
        return array( 'checked' => $checked, 'problems' => $problems, 'deep_required' => false );
    }

    private static function orphan_checks( array $tables, $deep, $force_large ) {
        global $wpdb;
        if ( ! $deep ) { return array( 'skipped' => true, 'reason' => 'deep scan required' ); }
        $est = array();
        foreach ( $tables as $t ) { $est[ (string) ( $t['Name'] ?? '' ) ] = (int) ( $t['Rows'] ?? 0 ); }
        $checks = array(
            'postmeta' => array( $wpdb->postmeta, "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.ID IS NULL" ),
            'commentmeta' => array( $wpdb->commentmeta, "SELECT COUNT(*) FROM {$wpdb->commentmeta} m LEFT JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id WHERE c.comment_ID IS NULL" ),
            'usermeta' => array( $wpdb->usermeta, "SELECT COUNT(*) FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE u.ID IS NULL" ),
            'term_relationships' => array( $wpdb->term_relationships, "SELECT COUNT(*) FROM {$wpdb->term_relationships} r LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE tt.term_taxonomy_id IS NULL" ),
            'term_taxonomy' => array( $wpdb->term_taxonomy, "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt LEFT JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE t.term_id IS NULL" ),
        );
        if ( isset( $wpdb->termmeta ) ) { $checks['termmeta'] = array( $wpdb->termmeta, "SELECT COUNT(*) FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} t ON t.term_id=m.term_id WHERE t.term_id IS NULL" ); }
        $result = array( 'skipped' => false, 'counts' => array(), 'skipped_checks' => array() );
        foreach ( $checks as $name => $pair ) {
            if ( ( $est[ $pair[0] ] ?? 0 ) > self::LARGE_TABLE_ROWS && ! $force_large ) { $result['skipped_checks'][] = $name; continue; }
            $result['counts'][ $name ] = (int) $wpdb->get_var( $pair[1] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        return $result;
    }

    private static function options_health( $deep ) {
        global $wpdb;
        $autoload_where = "autoload IN ('yes','on','auto-on','auto')";
        $total = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE {$autoload_where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$autoload_where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $usage = WPI_Utils::table( 'option_usage' );
        $largest = $wpdb->get_results( "SELECT o.option_name,LENGTH(o.option_value) bytes,o.autoload,u.last_seen,u.hits,u.sampled_requests FROM {$wpdb->options} o LEFT JOIN {$usage} u ON u.option_name=o.option_name WHERE o.autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Production-safe bounded evidence. Deep mode may replace these caps with exact counts.
        $now = time();
        $expired_ids = $wpdb->get_col( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT 5001", $wpdb->esc_like( '_transient_timeout_' ) . '%', $now ) );
        $transient_ids = $wpdb->get_col( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 10001", $wpdb->esc_like( '_transient_' ) . '%' ) );
        $expired = count( (array) $expired_ids );
        $transients = count( (array) $transient_ids );
        $expired_capped = $expired >= 5001;
        $transients_capped = $transients >= 10001;
        if ( $deep ) {
            $expired = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $now ) );
            $transients = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%' ) );
            $expired_capped = false;
            $transients_capped = false;
        }
        return array(
            'autoload_bytes' => $total,
            'autoload_count' => $count,
            'largest_autoload' => $largest,
            'expired_transients' => $expired,
            'expired_transients_capped' => $expired_capped,
            'transient_rows' => $transients,
            'transient_rows_capped' => $transients_capped,
            'usage_started_at' => (int) get_option( 'wpi_usage_started_at', 0 ),
            'usage_coverage' => WPI_Utils::autoload_usage_coverage(),
        );
    }

    public static function generate_issues( array $health ) {
        $schema = $health['schema'];
        foreach ( $schema['missing_core_tables'] as $table ) {
            WPI_Utils::issue( 'database', 'critical', 'Required WordPress table is missing', '<code>' . esc_html( $table ) . '</code> was not found.', 'Database functionality can fail', 'Restore the missing table from a known-good backup or investigate a failed migration before making other performance changes.' );
        }
        foreach ( array_slice( $schema['missing_core_columns'] ?? array(), 0, 20 ) as $item ) {
            WPI_Utils::issue( 'database', 'critical', 'WordPress core database column is missing', '<code>' . esc_html( $item['table'] . '.' . $item['column'] ) . '</code> is not present.', 'Core queries or updates can fail', 'Use the Database Repair Centre to add the exact column definition from this installed WordPress version. Large tables use the guarded Maintenance Fix workflow or WP-CLI during a maintenance window.' );
        }
        foreach ( array_slice( $schema['missing_core_indexes'], 0, 20 ) as $item ) {
            WPI_Utils::issue( 'database', 'high', 'Core database index appears to be missing', '<code>' . esc_html( $item['table'] . '.' . $item['index'] ) . '</code> is not present.', 'Queries using this key can degrade sharply as the table grows', 'Use the Database Repair Centre to restore the exact index definition from this installed WordPress version. Large-table ALTER operations are size-gated.' );
        }
        foreach ( array_slice( $schema['mismatched_core_columns'] ?? array(), 0, 20 ) as $item ) {
            WPI_Utils::issue( 'database', 'critical', 'WordPress core database column definition does not match core', '<code>' . esc_html( $item['table'] . '.' . $item['column'] ) . '</code> differs from the definition expected by this WordPress version. Drift: <code>' . esc_html( wp_json_encode( $item['drift'] ) ) . '</code>.', 'Core reads/writes or upgrades may fail or behave incorrectly', 'Open Database Repair Centre to revalidate this against the installed WordPress schema. WPI can apply eligible corrections after a verified backup/risk acknowledgement and verify them afterward; large tables use the guarded Maintenance Fix workflow or WP-CLI.' );
        }
        foreach ( array_slice( $schema['mismatched_core_indexes'] ?? array(), 0, 20 ) as $item ) {
            WPI_Utils::issue( 'database', 'high', 'WordPress core index definition does not match core', '<code>' . esc_html( $item['table'] . '.' . $item['index'] ) . '</code> exists with a different key definition. Live: <code>' . esc_html( $item['actual'] ) . '</code>. Expected: <code>' . esc_html( $item['definition'] ) . '</code>.', 'Queries may use the wrong key shape or upgrades may not match expected schema', 'Open Database Repair Centre. WPI can revalidate the expected/live definitions, preflight primary/unique keys for duplicates, rebuild eligible core indexes, and verify the result. Large tables use the guarded Maintenance Fix workflow or WP-CLI during maintenance.' );
        }
        if ( ! empty( $schema['tables_without_primary_key'] ) ) {
            WPI_Utils::issue( 'database', 'high', 'Large database tables have no primary key', esc_html( implode( ', ', array_slice( $schema['tables_without_primary_key'], 0, 10 ) ) ), 'Replication, row lookup and maintenance may scale poorly', 'Identify the owning plugin and add an appropriate primary/unique key only after reviewing its schema and duplicate rows.' );
        }
        foreach ( array_slice( $schema['duplicate_indexes'] ?? array(), 0, 20 ) as $duplicate ) {
            WPI_Utils::issue( 'database', 'warning', 'Table has exact duplicate indexes', '<code>' . esc_html( $duplicate['table'] ) . '</code> contains equivalent indexes: <code>' . esc_html( implode( ', ', $duplicate['indexes'] ) ) . '</code>.', 'Unnecessary index storage and write amplification', 'Open Database Repair Centre. WPI rechecks the exact index signatures before dropping a redundant non-primary index, requires backup/risk acknowledgement, and verifies the retained index afterward.' );
        }
        foreach ( array_slice( $schema['auto_increment_risks'] ?? array(), 0, 20 ) as $risk ) {
            WPI_Utils::issue( 'database', $risk['percent'] >= 90 ? 'critical' : 'high', 'Auto-increment column is approaching its numeric limit', '<code>' . esc_html( $risk['table'] . '.' . $risk['column'] ) . '</code> is using approximately ' . esc_html( $risk['percent'] ) . '% of the range for <code>' . esc_html( $risk['type'] ) . '</code>.', $risk['percent'] . '% of ID range', 'Plan a controlled column-type migration before inserts begin failing. This plugin will not automatically widen identifier columns on a production database.' );
        }
        $nonempty_collations = array_filter( $schema['collations'] ?? array(), static function ( $count, $name ) { return '' !== (string) $name && (int) $count > 0; }, ARRAY_FILTER_USE_BOTH );
        if ( count( $nonempty_collations ) > 2 ) {
            WPI_Utils::issue( 'database', 'warning', 'Database tables use several collations', esc_html( implode( ', ', array_keys( array_slice( $nonempty_collations, 0, 6, true ) ) ) ), count( $nonempty_collations ) . ' collations detected', 'Mixed collations are not automatically wrong, but joins/comparisons across incompatible collations can add conversions or produce errors. Standardize plugin tables only after checking their requirements.' );
        }

        foreach ( $schema['tables'] as $table ) {
            if ( $table['rows_estimate'] > 100000 && $table['engine'] && 0 !== strcasecmp( $table['engine'], 'InnoDB' ) ) {
                WPI_Utils::issue( 'database', 'warning', 'Large table is not using InnoDB', '<code>' . esc_html( $table['name'] ) . '</code> uses ' . esc_html( $table['engine'] ) . '.', number_format_i18n( $table['rows_estimate'] ) . ' estimated rows', 'Review the plugin/application requirements and consider InnoDB on staging. Do not convert a large production table without a migration plan.' );
            }
            if ( $table['size'] > 100 * MB_IN_BYTES && $table['data_free'] > max( 100 * MB_IN_BYTES, $table['size'] * 0.25 ) ) {
                WPI_Utils::issue( 'database', 'warning', 'Table has substantial reclaimable/fragmented space', '<code>' . esc_html( $table['name'] ) . '</code> reports ' . esc_html( size_format( $table['data_free'] ) ) . ' of free space.', size_format( $table['data_free'] ), 'Investigate churn first. If reclaiming the space is justified, use the Repair Centre maintenance workflow after a verified backup, or WP-CLI/DBA tooling for the largest table.' );
            }
            $comment = strtolower( $table['comment'] );
            if ( false !== strpos( $comment, 'crash' ) || false !== strpos( $comment, 'corrupt' ) ) {
                WPI_Utils::issue( 'database', 'critical', 'Database reports a damaged table', '<code>' . esc_html( $table['name'] ) . '</code>: ' . esc_html( $table['comment'] ), 'Potential data/query failures', 'Open Database Repair Centre, create or select a verified backup, and use the engine-specific guided recovery plan. If WPI cannot safely automate the engine recovery, it will show the exact reviewed procedure instead of issuing a generic repair.' );
            }
        }
        foreach ( $health['integrity']['problems'] as $problem ) {
            WPI_Utils::issue( 'database', 'critical', 'Database integrity check reported a problem', '<code>' . esc_html( $problem['table'] ) . '</code>: ' . esc_html( $problem['message'] ), 'Potential data corruption', 'Open Database Repair Centre, create or select a verified backup, build the engine-specific recovery plan, and apply the guarded repair when WPI can verify a safe path.' );
        }

        $s = $health['server']['status'];
        $v = $health['server']['variables'];
        $tmp = (float) ( $s['Created_tmp_tables'] ?? 0 );
        $disk = (float) ( $s['Created_tmp_disk_tables'] ?? 0 );
        if ( $tmp > 100 && $disk / $tmp > 0.25 ) {
            WPI_Utils::issue( 'database', 'high', 'Many MySQL temporary tables are spilling to disk', round( 100 * $disk / $tmp, 1 ) . '% of created temporary tables have been disk-based since server start.', round( 100 * $disk / $tmp, 1 ) . '% disk temp tables', 'Find queries using large GROUP BY/ORDER BY/temp results before blindly increasing tmp_table_size/max_heap_table_size.' );
        }
        $requests = (float) ( $s['Innodb_buffer_pool_read_requests'] ?? 0 );
        $reads = (float) ( $s['Innodb_buffer_pool_reads'] ?? 0 );
        if ( $requests > 10000 ) {
            $hit = 1 - ( $reads / max( 1, $requests ) );
            if ( $hit < 0.99 ) {
                WPI_Utils::issue( 'database', 'high', 'InnoDB buffer pool hit rate is low', 'Calculated buffer pool hit rate is approximately ' . esc_html( round( $hit * 100, 2 ) ) . '%.', round( $hit * 100, 2 ) . '% hit rate', 'Confirm the working dataset and server memory pressure. A larger InnoDB buffer pool may help, but query/index problems should be addressed first.' );
            }
        }
        $max_conn = (float) ( $v['max_connections'] ?? 0 );
        $used_conn = (float) ( $s['Max_used_connections'] ?? 0 );
        if ( $max_conn > 0 && $used_conn / $max_conn > 0.8 ) {
            WPI_Utils::issue( 'database', 'high', 'Database connection capacity has been close to exhaustion', 'Max_used_connections is ' . intval( $used_conn ) . ' of ' . intval( $max_conn ) . '.', round( 100 * $used_conn / $max_conn, 1 ) . '% of capacity', 'Investigate PHP-FPM concurrency, stuck queries, persistent connections and connection leaks before raising the limit.' );
        }
        $process = $health['server']['processes'];
        if ( ! empty( $process['locked'] ) ) {
            WPI_Utils::issue( 'database', 'high', 'Database sessions are waiting on locks', intval( $process['locked'] ) . ' visible process(es) reported a lock-related state.', $process['locked'] . ' locked processes', 'Inspect the long-running process list and the transactions/queries holding locks.' );
        }
        $uptime = max( 1, (float) ( $s['Uptime'] ?? 1 ) );
        $days_up = max( 1 / 24, $uptime / DAY_IN_SECONDS );
        $slow_per_day = (float) ( $s['Slow_queries'] ?? 0 ) / $days_up;
        if ( $slow_per_day >= 100 ) {
            WPI_Utils::issue( 'database', $slow_per_day >= 1000 ? 'high' : 'warning', 'MySQL is recording many slow queries', 'The server has recorded approximately ' . esc_html( number_format_i18n( (int) $slow_per_day ) ) . ' slow queries per day at the current uptime rate.', number_format_i18n( (int) $slow_per_day ) . '/day', 'Use signed route profiling and the database slow-query log together to identify the highest cumulative-cost query patterns.' );
        }
        $full_join_per_day = (float) ( $s['Select_full_join'] ?? 0 ) / $days_up;
        if ( $full_join_per_day >= 100 ) {
            WPI_Utils::issue( 'database', 'warning', 'MySQL is performing joins without usable indexes', 'Select_full_join is accumulating at roughly ' . esc_html( number_format_i18n( (int) $full_join_per_day ) ) . ' per day.', number_format_i18n( (int) $full_join_per_day ) . '/day', 'Profile the slowest SELECTs and inspect EXPLAIN possible_keys/key output. Add indexes only when the query shape and write cost justify them.' );
        }
        $aborted_per_day = (float) ( $s['Aborted_connects'] ?? 0 ) / $days_up;
        if ( $aborted_per_day >= 50 ) {
            WPI_Utils::issue( 'database', 'warning', 'Database connections are being aborted frequently', 'Aborted_connects is accumulating at roughly ' . esc_html( number_format_i18n( (int) $aborted_per_day ) ) . ' per day.', number_format_i18n( (int) $aborted_per_day ) . '/day', 'Check credentials/network stability, max_connections pressure, PHP-FPM worker spikes and database connection timeouts.' );
        }
        if ( ! empty( $s['Innodb_row_lock_current_waits'] ) ) {
            WPI_Utils::issue( 'database', 'high', 'InnoDB currently has row-lock waits', intval( $s['Innodb_row_lock_current_waits'] ) . ' current row-lock wait(s) were reported.', intval( $s['Innodb_row_lock_current_waits'] ) . ' current waits', 'Use the process list and transaction diagnostics to identify the blocking transaction. Do not kill sessions blindly from WordPress.' );
        }
        $innodb = (array) ( $health['server']['innodb'] ?? array() );
        if ( ! empty( $innodb['deadlock_detected'] ) ) {
            $deadlock = (array) ( $innodb['deadlock'] ?? array() );
            $detail = 'SHOW ENGINE INNODB STATUS contains a latest-detected-deadlock section.';
            if ( ! empty( $deadlock['tables'] ) ) { $detail .= ' Tables: <code>' . esc_html( implode( ', ', array_slice( (array) $deadlock['tables'], 0, 8 ) ) ) . '</code>.'; }
            if ( ! empty( $deadlock['queries'][0] ) ) { $detail .= ' Example normalized query: <code>' . esc_html( mb_substr( (string) $deadlock['queries'][0], 0, 700 ) ) . '</code>.'; }
            WPI_Utils::issue( 'database', 'high', 'InnoDB reports a recent deadlock', $detail, 'Transaction rollback/retry risk', 'Use the parsed deadlock participants and lock graph to identify code paths that acquire the same rows in different orders. Shorten transactions and enforce a consistent write order.' );
        }
        if ( ! empty( $innodb['foreign_key_error_detected'] ) ) {
            WPI_Utils::issue( 'database', 'high', 'InnoDB reports a recent foreign-key error', 'SHOW ENGINE INNODB STATUS contains a latest-foreign-key-error section.', 'Write failures possible', 'Inspect the owning plugin/custom table schema and the failing relationship before retrying the write.' );
        }
        if ( isset( $innodb['history_list_length'] ) && null !== $innodb['history_list_length'] && $innodb['history_list_length'] > 100000 ) {
            WPI_Utils::issue( 'database', 'warning', 'InnoDB purge history is very large', 'History list length is ' . number_format_i18n( (int) $innodb['history_list_length'] ) . '.', number_format_i18n( (int) $innodb['history_list_length'] ), 'Look for long-running transactions or replicas/readers holding old snapshots open. Persistent growth can increase undo/purge work.' );
        }
        if ( isset( $innodb['lock_wait_count'] ) && null !== $innodb['lock_wait_count'] && (int) $innodb['lock_wait_count'] > 0 ) {
            WPI_Utils::issue( 'database', 'high', 'InnoDB lock waits are active', number_format_i18n( (int) $innodb['lock_wait_count'] ) . ' lock wait(s) are visible through ' . esc_html( (string) ( $innodb['lock_wait_source'] ?? 'InnoDB metadata' ) ) . '.', number_format_i18n( (int) $innodb['lock_wait_count'] ) . ' wait(s)', 'Open Performance → InnoDB Transaction Manager to inspect live blockers/waiters and terminate a stuck or abandoned connection when appropriate. Then fix the code path so transactions are shorter and do not remain open across slow PHP/API work.' );
        }
        foreach ( array_slice( (array) ( $innodb['configuration_advice']['recommendations'] ?? array() ), 0, 10 ) as $advice ) {
            WPI_Utils::issue( 'database', sanitize_key( $advice['severity'] ?? 'warning' ), sanitize_text_field( $advice['title'] ?? 'InnoDB configuration recommendation' ), esc_html( (string) ( $advice['detail'] ?? '' ) ), 'InnoDB configuration/workload', esc_html( (string) ( $advice['recommendation'] ?? '' ) ) );
        }
        foreach ( array_slice( (array) ( $innodb['lock_graph']['edges'] ?? array() ), 0, 5 ) as $edge ) {
            $detail = 'Transaction <code>' . esc_html( (string) ( $edge['requesting_trx_id'] ?? '' ) ) . '</code> is waiting on <code>' . esc_html( (string) ( $edge['blocking_trx_id'] ?? '' ) ) . '</code>';
            if ( ! empty( $edge['table'] ) ) { $detail .= ' for <code>' . esc_html( (string) $edge['table'] ) . '</code>'; }
            if ( ! empty( $edge['index'] ) ) { $detail .= ' index <code>' . esc_html( (string) $edge['index'] ) . '</code>'; }
            $detail .= '.';
            if ( ! empty( $edge['blocking_query'] ) ) { $detail .= ' Blocking query: <code>' . esc_html( mb_substr( (string) $edge['blocking_query'], 0, 600 ) ) . '</code>.'; }
            WPI_Utils::issue( 'database', 'high', 'InnoDB blocker/waiter relationship detected', $detail, 'Blocked transaction', 'Fix the blocking code path rather than automatically killing the connection. Reduce transaction duration, batch writes, and acquire rows in a consistent order.' );
        }
        foreach ( array_slice( (array) ( $innodb['transactions'] ?? array() ), 0, 10 ) as $trx ) {
            $age = isset( $trx['age_seconds'] ) ? (int) $trx['age_seconds'] : 0;
            if ( $age < 30 ) { continue; }
            $detail = 'Transaction has been open for ' . $age . ' seconds; ' . intval( $trx['rows_locked'] ?? 0 ) . ' row(s) locked and ' . intval( $trx['rows_modified'] ?? 0 ) . ' row(s) modified.';
            if ( ! empty( $trx['query'] ) ) { $detail .= ' Query: <code>' . esc_html( mb_substr( (string) $trx['query'], 0, 600 ) ) . '</code>'; }
            WPI_Utils::issue( 'database', $age >= 300 ? 'critical' : 'high', 'Long-running InnoDB transaction is open', $detail, $age . ' seconds', 'Open Performance → InnoDB Transaction Manager to inspect the owning connection. If it is stuck or abandoned, WPI can terminate the connection and roll back its uncommitted transaction after explicit acknowledgement. Then fix the request/job so it commits or rolls back sooner.' );
        }

        $engine_counts = array_change_key_case( (array) ( $schema['engines'] ?? array() ), CASE_LOWER );
        $table_locks = (float) ( $s['Table_locks_waited'] ?? 0 );
        $table_lock_immediate = (float) ( $s['Table_locks_immediate'] ?? 0 );
        $table_lock_total = $table_locks + $table_lock_immediate;
        if ( $table_lock_total > 1000 && $table_locks / max( 1, $table_lock_total ) > 0.01 && ( ! empty( $engine_counts['myisam'] ) || ! empty( $engine_counts['aria'] ) ) ) {
            WPI_Utils::issue( 'database', 'high', 'Table-level lock contention is elevated', round( 100 * $table_locks / $table_lock_total, 2 ) . '% of recorded table-lock requests waited.', round( 100 * $table_locks / $table_lock_total, 2 ) . '% waited', 'Identify write-heavy MyISAM/Aria tables and the plugins using them. Reduce long writes/bulk operations; consider an engine migration on staging when the application permits it.' );
        }
        $key_requests = (float) ( $s['Key_read_requests'] ?? 0 );
        $key_reads = (float) ( $s['Key_reads'] ?? 0 );
        if ( ! empty( $engine_counts['myisam'] ) && $key_requests > 10000 ) {
            $key_hit = 1 - ( $key_reads / max( 1, $key_requests ) );
            if ( $key_hit < 0.99 ) {
                WPI_Utils::issue( 'database', 'warning', 'MyISAM key-cache hit rate is low', 'Calculated MyISAM key-cache hit rate is approximately ' . esc_html( round( 100 * $key_hit, 2 ) ) . '%.', round( 100 * $key_hit, 2 ) . '% hit rate', 'Check which MyISAM tables are active and whether key_buffer_size is appropriate. For write-heavy WordPress/plugin tables, consider whether InnoDB is a better fit before simply increasing the cache.' );
            }
        }
        $aria_requests = (float) ( $s['Aria_pagecache_read_requests'] ?? 0 );
        $aria_reads = (float) ( $s['Aria_pagecache_reads'] ?? 0 );
        if ( ! empty( $engine_counts['aria'] ) && $aria_requests > 10000 ) {
            $aria_hit = 1 - ( $aria_reads / max( 1, $aria_requests ) );
            if ( $aria_hit < 0.99 ) {
                WPI_Utils::issue( 'database', 'warning', 'Aria page-cache hit rate is low', 'Calculated Aria page-cache hit rate is approximately ' . esc_html( round( 100 * $aria_hit, 2 ) ) . '%.', round( 100 * $aria_hit, 2 ) . '% hit rate', 'Review Aria table workload and aria_pagecache_buffer_size. Do not tune the cache until slow queries and oversized/scan-heavy tables have been identified.' );
            }
        }
        $memory_limit = (int) ( $v['max_heap_table_size'] ?? 0 );
        if ( $memory_limit > 0 ) {
            foreach ( (array) ( $schema['tables'] ?? array() ) as $table ) {
                if ( 0 !== strcasecmp( (string) ( $table['engine'] ?? '' ), 'MEMORY' ) ) { continue; }
                $size = (int) ( $table['size'] ?? 0 );
                if ( $size >= 0.75 * $memory_limit ) {
                    WPI_Utils::issue( 'database', 'high', 'MEMORY table is approaching max_heap_table_size', '<code>' . esc_html( $table['name'] ) . '</code> is approximately ' . esc_html( size_format( $size ) ) . ' versus max_heap_table_size ' . esc_html( size_format( $memory_limit ) ) . '.', round( 100 * $size / $memory_limit, 1 ) . '% of limit', 'Identify the owning plugin and its growth behavior. MEMORY tables are non-durable and can fail with table-full errors at their configured size ceiling.' );
                }
            }
        }
        foreach ( array_slice( $process['long_running'] ?? array(), 0, 5 ) as $p ) {
            WPI_Utils::issue( 'database', $p['time'] >= 30 ? 'critical' : 'high', 'Long-running database query is active', '<code>' . esc_html( mb_substr( $p['query'], 0, 800 ) ) . '</code><br>State: ' . esc_html( $p['state'] ), $p['time'] . ' seconds', 'Use EXPLAIN and the responsible request/plugin trace to reduce the query, add an appropriate index, or break the work into batches.' );
        }

        $opt = $health['options'];
        if ( $opt['autoload_bytes'] > 2 * MB_IN_BYTES ) {
            WPI_Utils::issue( 'database', 'critical', 'Autoloaded options are oversized', 'WordPress is loading approximately ' . esc_html( size_format( $opt['autoload_bytes'] ) ) . ' of option data into memory.', size_format( $opt['autoload_bytes'] ), 'Review the largest options, map them to their owning plugins, and disable autoload only when the option is not needed on most requests.' );
        } elseif ( $opt['autoload_bytes'] > 800 * KB_IN_BYTES ) {
            WPI_Utils::issue( 'database', 'warning', 'Autoloaded options are elevated', 'Autoloaded option data is ' . esc_html( size_format( $opt['autoload_bytes'] ) ) . '.', size_format( $opt['autoload_bytes'] ), 'Review the largest autoloaded options and remove stale plugin data.' );
        }
        $observed_days = ! empty( $opt['usage_started_at'] ) ? ( time() - (int) $opt['usage_started_at'] ) / DAY_IN_SECONDS : 0;
        if ( WPI_Utils::autoload_review_ready( (array) ( $opt['usage_coverage'] ?? array() ), $observed_days ) ) {
            foreach ( array_slice( $opt['largest_autoload'], 0, 30 ) as $row ) {
                if ( (int) $row['bytes'] < 128 * KB_IN_BYTES || ! empty( $row['last_seen'] ) ) { continue; }
                WPI_Utils::issue( 'database', 'warning', 'Large autoloaded option has not been observed in sampled requests', '<code>' . esc_html( $row['option_name'] ) . '</code> is ' . esc_html( size_format( (int) $row['bytes'] ) ) . ' and has not been observed through get_option() during approximately ' . intval( $observed_days ) . ' days of sampling.', size_format( (int) $row['bytes'] ), 'Verify the option is not read indirectly or required during unsampled/rare workflows. If confirmed, switch it to non-autoloaded and compare memory/request time before and after.' );
            }
        }
        foreach ( $opt['largest_autoload'] as $row ) {
            if ( 'rewrite_rules' === $row['option_name'] && (int) $row['bytes'] > MB_IN_BYTES ) {
                WPI_Utils::issue( 'database', 'high', 'Rewrite rules option is extremely large', '<code>rewrite_rules</code> is approximately ' . esc_html( size_format( (int) $row['bytes'] ) ) . '.', size_format( (int) $row['bytes'] ), 'Audit plugins/post types/taxonomies creating rewrite rules and remove stale rules. Flush rewrite rules once after the cause is fixed, not on every request.' );
            }
        }

        if ( null !== $opt['expired_transients'] && $opt['expired_transients'] > 5000 ) {
            WPI_Utils::issue( 'database', 'warning', 'Large number of expired transients', number_format_i18n( $opt['expired_transients'] ) . ' expired transient timeout rows were found.', number_format_i18n( $opt['expired_transients'] ), 'Delete expired transients in bounded batches and identify plugins that continuously create short-lived transients.' );
        }
        foreach ( (array) ( $health['orphans']['counts'] ?? array() ) as $type => $count ) {
            if ( $count > 10000 ) {
                WPI_Utils::issue( 'database', 'warning', 'Large orphaned ' . sanitize_key( $type ) . ' set detected', number_format_i18n( $count ) . ' orphaned rows were found.', number_format_i18n( $count ) . ' rows', 'Export/backup the affected rows, identify the source of the orphaning, and clean in bounded batches during a maintenance window.' );
            }
        }
    }
}
