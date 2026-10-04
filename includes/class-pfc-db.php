<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_DB {
    const DB_VERSION = '2.2.1';

    public static function activate() {
        self::install();
        if ( false === get_option( 'pfc_runtime', false ) ) {
            add_option( 'pfc_runtime', array( 'sample_rate' => 0.0002, 'rum_rate' => 0.005, 'retention_days' => 30 ), '', true );
        }
        if ( false === get_option( 'pfc_secret', false ) ) {
            add_option( 'pfc_secret', wp_generate_password( 64, true, true ), '', false );
        }
        if ( false === get_option( 'pfc_usage_started_at', false ) ) {
            add_option( 'pfc_usage_started_at', time(), '', false );
        }
        if ( ! wp_next_scheduled( 'pfc_daily_maintenance' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pfc_daily_maintenance' );
        }
        PFC_Bootstrap::install();
    }

    public static function deactivate() {
        $ts = wp_next_scheduled( 'pfc_daily_maintenance' );
        if ( $ts ) { wp_unschedule_event( $ts, 'pfc_daily_maintenance' ); }
    }

    public static function maybe_upgrade() {
        $previous = (string) get_option( 'pfc_db_version', '' );
        if ( $previous !== self::DB_VERSION ) {
            // 1.0 shipped with intentionally temporary high sampling defaults and no settings UI.
            // Lower only that exact legacy default during upgrade; never overwrite customized values.
            if ( '1.0.0' === $previous ) {
                $runtime = get_option( 'pfc_runtime', array() );
                if ( is_array( $runtime ) && isset( $runtime['sample_rate'], $runtime['rum_rate'] ) && 0.01 === (float) $runtime['sample_rate'] && 0.01 === (float) $runtime['rum_rate'] ) {
                    $runtime['sample_rate'] = 0.0002;
                    $runtime['rum_rate'] = 0.005;
                    update_option( 'pfc_runtime', $runtime, true );
                }
            }
            self::install();
            if ( '' === $previous || version_compare( $previous, '2.0.0', '<' ) ) { self::migrate_incidents(); }

            // 1.5.0 and earlier could truncate WordPress CREATE TABLE definitions at
            // the first datatype parenthesis (for example bigint(20)). Resolve that
            // specific stale issue class and discard the cached report. A fresh scan
            // will immediately reopen any genuine schema drift using the balanced
            // parser, while healthy sites do not keep dangerous false repair cards.
            if ( '' === $previous || version_compare( $previous, '1.5.1', '<' ) ) {
                global $wpdb;
                $issues = PFC_Utils::table( 'issues' );
                $wpdb->update(
                    $issues,
                    array( 'status' => 'resolved' ),
                    array( 'title' => 'WordPress core database column definition does not match core' )
                );
                delete_option( 'pfc_last_scan' );
            }
        }
        add_action( 'pfc_daily_maintenance', array( __CLASS__, 'cleanup' ) );
    }

    private static function migrate_incidents() {
        global $wpdb;
        $table = PFC_Utils::table( 'issues' );
        $rows = $wpdb->get_results( "SELECT id,area,title,message,route,last_seen,status FROM {$table} ORDER BY id ASC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stale_cut = time() - 7 * DAY_IN_SECONDS;
        foreach ( (array) $rows as $row ) {
            $route = (string) ( $row['route'] ?? '' );
            $area = (string) ( $row['area'] ?? '' );
            $passive = $route && ( ! filter_var( $route, FILTER_VALIDATE_URL ) || ! in_array( $area, array( 'frontend', 'regression' ), true ) );
            $source = $passive ? 'passive' : 'legacy';
            $data = array(
                'incident_key' => PFC_Utils::incident_key( $area, (string) ( $row['title'] ?? '' ), (string) ( $row['message'] ?? '' ) ),
                'source' => $source,
            );
            if ( $passive && 'open' === (string) ( $row['status'] ?? '' ) && strtotime( (string) ( $row['last_seen'] ?? '' ) . ' UTC' ) < $stale_cut ) {
                $data['status'] = 'resolved';
                $data['resolved_at'] = self::now_for_migration();
            }
            $wpdb->update( $table, $data, array( 'id' => absint( $row['id'] ) ) );
        }
    }

    private static function now_for_migration() { return gmdate( 'Y-m-d H:i:s' ); }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $runs = PFC_Utils::table( 'runs' );
        $queries = PFC_Utils::table( 'queries' );
        $issues = PFC_Utils::table( 'issues' );
        $metrics = PFC_Utils::table( 'metrics' );
        $changes = PFC_Utils::table( 'changes' );
        $option_usage = PFC_Utils::table( 'option_usage' );
        $backups = PFC_Utils::table( 'backups' );

        dbDelta( "CREATE TABLE {$runs} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            route varchar(500) NOT NULL DEFAULT '',
            mode varchar(20) NOT NULL DEFAULT 'sample',
            php_ms decimal(12,3) NOT NULL DEFAULT 0,
            db_ms decimal(12,3) NOT NULL DEFAULT 0,
            query_count int unsigned NOT NULL DEFAULT 0,
            http_ms decimal(12,3) NOT NULL DEFAULT 0,
            http_count int unsigned NOT NULL DEFAULT 0,
            memory_peak bigint unsigned NOT NULL DEFAULT 0,
            probe_id char(36) NOT NULL DEFAULT '',
            excluded_plugin varchar(191) NOT NULL DEFAULT '',
            payload longtext NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY mode (mode),
            KEY route (route(191)),
            KEY probe_id (probe_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$queries} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint unsigned NOT NULL,
            pattern_hash char(32) NOT NULL,
            normalized_sql longtext NOT NULL,
            count int unsigned NOT NULL DEFAULT 1,
            total_ms decimal(12,3) NOT NULL DEFAULT 0,
            max_ms decimal(12,3) NOT NULL DEFAULT 0,
            component_type varchar(24) NOT NULL DEFAULT '',
            component_slug varchar(191) NOT NULL DEFAULT '',
            source_file text NULL,
            source_line int unsigned NOT NULL DEFAULT 0,
            explain_json longtext NULL,
            PRIMARY KEY  (id),
            KEY run_id (run_id),
            KEY pattern_hash (pattern_hash),
            KEY component_slug (component_slug)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$issues} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            issue_key char(32) NOT NULL,
            area varchar(50) NOT NULL,
            severity varchar(20) NOT NULL,
            title varchar(255) NOT NULL,
            message longtext NULL,
            impact varchar(255) NOT NULL DEFAULT '',
            recommendation longtext NULL,
            route varchar(500) NOT NULL DEFAULT '',
            incident_key char(32) NOT NULL DEFAULT '',
            component varchar(191) NOT NULL DEFAULT '',
            source varchar(20) NOT NULL DEFAULT 'legacy',
            occurrence_count bigint unsigned NOT NULL DEFAULT 1,
            confidence decimal(5,2) NOT NULL DEFAULT 50,
            last_run_id bigint unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'open',
            snoozed_until datetime NULL,
            resolved_at datetime NULL,
            first_seen datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY issue_key (issue_key),
            KEY incident_key (incident_key),
            KEY severity (severity),
            KEY status (status),
            KEY last_seen (last_seen)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$metrics} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            bucket datetime NOT NULL,
            metric varchar(80) NOT NULL,
            metric_version smallint unsigned NOT NULL DEFAULT 1,
            route_hash char(32) NOT NULL DEFAULT '',
            route_group varchar(80) NOT NULL DEFAULT '',
            samples bigint unsigned NOT NULL DEFAULT 0,
            value_sum decimal(20,4) NOT NULL DEFAULT 0,
            value_max decimal(20,4) NOT NULL DEFAULT 0,
            bucket_0 bigint unsigned NOT NULL DEFAULT 0,
            bucket_1 bigint unsigned NOT NULL DEFAULT 0,
            bucket_2 bigint unsigned NOT NULL DEFAULT 0,
            bucket_3 bigint unsigned NOT NULL DEFAULT 0,
            bucket_4 bigint unsigned NOT NULL DEFAULT 0,
            bucket_5 bigint unsigned NOT NULL DEFAULT 0,
            bucket_6 bigint unsigned NOT NULL DEFAULT 0,
            bucket_7 bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_metric (bucket,metric,route_hash),
            KEY bucket (bucket)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$changes} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            change_type varchar(80) NOT NULL,
            object_name varchar(191) NOT NULL DEFAULT '',
            before_value longtext NULL,
            after_value longtext NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            reverted_at datetime NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$option_usage} (
            option_name varchar(191) NOT NULL,
            last_seen datetime NOT NULL,
            hits bigint unsigned NOT NULL DEFAULT 0,
            sampled_requests bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (option_name),
            KEY last_seen (last_seen)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$backups} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(24) NOT NULL DEFAULT 'running',
            scope varchar(24) NOT NULL DEFAULT 'wordpress',
            filename varchar(191) NOT NULL DEFAULT '',
            file_path longtext NULL,
            size_bytes bigint unsigned NOT NULL DEFAULT 0,
            sha256 char(64) NOT NULL DEFAULT '',
            table_count int unsigned NOT NULL DEFAULT 0,
            tables_done int unsigned NOT NULL DEFAULT 0,
            row_count bigint unsigned NOT NULL DEFAULT 0,
            current_table varchar(191) NOT NULL DEFAULT '',
            state_json longtext NULL,
            error_text longtext NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            verified_at datetime NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at),
            KEY verified_at (verified_at)
        ) {$charset};" );
        if ( false === get_option( 'pfc_usage_started_at', false ) ) {
            add_option( 'pfc_usage_started_at', time(), '', false );
        }
        // Backfill stable incident identities without discarding existing evidence.
        $wpdb->query( "UPDATE {$issues} SET incident_key=issue_key WHERE incident_key=''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        update_option( 'pfc_db_version', self::DB_VERSION, true );
        // The version option is read on every request; keep it in the autoloaded set.
        if ( function_exists( 'wp_set_option_autoload' ) ) {
            wp_set_option_autoload( 'pfc_db_version', true );
        } else {
            $wpdb->update( $wpdb->options, array( 'autoload' => 'yes' ), array( 'option_name' => 'pfc_db_version' ) );
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'pfc_db_version', 'options' );
        }
    }

    public static function cleanup() {
        global $wpdb;
        $runtime = get_option( 'pfc_runtime', array() );
        $days = max( 7, min( 365, (int) ( $runtime['retention_days'] ?? 30 ) ) );
        $raw_days = min( $days, 14 );
        $runs_cut = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $raw_days );
        $metrics_cut = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * $days );
        $runs_table = PFC_Utils::table( 'runs' );
        $metrics_table = PFC_Utils::table( 'metrics' );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$runs_table} WHERE created_at < %s LIMIT 5000", $runs_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$metrics_table} WHERE bucket < %s LIMIT 5000", $metrics_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $qtable = PFC_Utils::table( 'queries' );
        $rtable = PFC_Utils::table( 'runs' );
        $ids = $wpdb->get_col( "SELECT q.id FROM {$qtable} q LEFT JOIN {$rtable} r ON r.id=q.run_id WHERE r.id IS NULL LIMIT 5000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $ids ) {
            $ids = array_map( 'absint', $ids );
            $wpdb->query( "DELETE FROM {$qtable} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $issues = PFC_Utils::table( 'issues' );
        $passive_cut = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( "UPDATE {$issues} SET status='resolved',resolved_at=UTC_TIMESTAMP() WHERE status IN ('open','observing','verifying') AND source='passive' AND last_seen < %s", $passive_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $resolved_cut = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$issues} WHERE status='resolved' AND last_seen < %s LIMIT 1000", $resolved_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
