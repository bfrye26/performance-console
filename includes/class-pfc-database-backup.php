<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Private, resumable logical database backups used by the Repair Centre.
 *
 * The browser exporter deliberately processes a bounded number of rows per
 * request. WP-CLI can drive the same state machine to completion without an
 * HTTP timeout. Backups contain table schema/data only; they do not include
 * database users, server configuration, routines, events or host snapshots.
 */
final class PFC_Database_Backup {
    const DEFAULT_BATCH_ROWS = 1000;
    const MIN_BATCH_ROWS = 50;
    const MAX_BATCH_ROWS = 5000;
    const STEP_TIME_BUDGET = 10.0;
    const STEP_TARGET_BYTES = 33554432; // 32 MiB of SQL output per browser step before yielding.
    const BATCH_TARGET_BYTES = 2097152; // Aim for ~2 MiB of SQL per SELECT/INSERT chunk.
    const VERIFIED_MAX_AGE = 86400; // 24 hours for satisfying a mutating repair.

    public static function table_name() { return PFC_Utils::table( 'backups' ); }

    public static function create( $scope = 'wordpress' ) {
        global $wpdb;
        $scope = 'full' === sanitize_key( $scope ) ? 'full' : 'wordpress';
        $dir = self::storage_dir();
        if ( is_wp_error( $dir ) ) { return $dir; }

        $tables = self::database_tables( $scope );
        if ( is_wp_error( $tables ) ) { return $tables; }
        if ( empty( $tables['tables'] ) ) { return new WP_Error( 'pfc_backup_tables', 'No database tables were found for the selected backup scope.' ); }

        $space = self::space_preflight( $dir, (array) $tables['tables'] );
        if ( ! empty( $space['available'] ) && empty( $space['ok'] ) ) {
            return new WP_Error( 'pfc_backup_space', 'The backup location does not appear to have enough free space for a conservative database export safety margin.' );
        }

        $token = wp_generate_password( 24, false, false );
        $filename = 'pfc-db-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 10, false, false ) ) . '.sql';
        $path = trailingslashit( $dir ) . $filename;
        $marker = 'PFC-BACKUP-COMPLETE-' . wp_generate_uuid4();
        $table_stats = self::table_stats( (array) $tables['tables'] );
        $row_estimates = (array) ( $table_stats['rows'] ?? array() );
        $avg_row_bytes = (array) ( $table_stats['avg_row_bytes'] ?? array() );
        $state = array(
            'tables' => array_values( (array) $tables['tables'] ),
            'views' => array_values( (array) $tables['views'] ),
            'row_estimates' => $row_estimates,
            'estimated_total_rows' => array_sum( $row_estimates ),
            'avg_row_bytes' => $avg_row_bytes,
            'table_index' => 0,
            'schema_written' => false,
            'offset' => 0,
            'cursor' => null,
            'cursor_column' => '',
            'cursor_numeric' => false,
            'cursor_columns' => array(),
            'cursor_values' => array(),
            'data_columns' => array(),
            'batch_rows' => self::DEFAULT_BATCH_ROWS,
            'table_rows_exported' => 0,
            'last_step_rows' => 0,
            'last_step_bytes' => 0,
            'last_step_ms' => 0,
            'rows_exported' => 0,
            'tables_done' => 0,
            'marker' => $marker,
            'token' => $token,
            'space_preflight' => $space,
        );

        $header = self::header_sql( $scope, $tables );
        if ( false === self::append_file( $path, $header, true ) ) {
            return new WP_Error( 'pfc_backup_write', 'Unable to create the database backup file.' );
        }
        @chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

        $now = PFC_Utils::now_mysql();
        $ok = $wpdb->insert(
            self::table_name(),
            array(
                'created_at' => $now,
                'updated_at' => $now,
                'status' => 'running',
                'scope' => $scope,
                'filename' => $filename,
                'file_path' => $path,
                'size_bytes' => (int) @filesize( $path ),
                'sha256' => '',
                'table_count' => count( $tables['tables'] ),
                'tables_done' => 0,
                'row_count' => 0,
                'current_table' => (string) ( $tables['tables'][0] ?? '' ),
                'state_json' => wp_json_encode( $state ),
                'error_text' => '',
                'user_id' => get_current_user_id(),
                'verified_at' => null,
            )
        );
        if ( false === $ok ) {
            @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            return new WP_Error( 'pfc_backup_record', 'Unable to record the database backup job.' );
        }
        return self::get( (int) $wpdb->insert_id );
    }

    public static function step( $id ) {
        global $wpdb;
        $backup = self::get( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        if ( ! in_array( $backup['status'], array( 'running','ready_verify' ), true ) ) { return $backup; }
        if ( 'ready_verify' === $backup['status'] ) { return $backup; }
        $path = self::validated_path( $backup );
        if ( is_wp_error( $path ) ) { return self::fail( $id, $path->get_error_message() ); }

        $state = json_decode( (string) $backup['state_json'], true );
        if ( ! is_array( $state ) || empty( $state['tables'] ) || empty( $state['marker'] ) ) {
            return self::fail( $id, 'Backup state is missing or invalid.' );
        }

        $started = microtime( true );
        $step_rows = 0;
        $step_bytes = 0;
        $time_budget = max( 1.5, min( 20.0, (float) apply_filters( 'pfc_backup_step_time_budget', self::STEP_TIME_BUDGET ) ) );
        $php_limit = (int) ini_get( 'max_execution_time' );
        if ( $php_limit > 0 ) { $time_budget = min( $time_budget, max( 1.0, $php_limit - 2.0 ) ); }
        $byte_budget = max( MB_IN_BYTES, min( 64 * MB_IN_BYTES, (int) apply_filters( 'pfc_backup_step_target_bytes', self::STEP_TARGET_BYTES ) ) );

        while ( true ) {
            $idx = (int) ( $state['table_index'] ?? 0 );
            if ( $idx >= count( $state['tables'] ) ) {
                $state['last_step_rows'] = $step_rows;
                $state['last_step_bytes'] = $step_bytes;
                $state['last_step_ms'] = round( ( microtime( true ) - $started ) * 1000, 1 );
                return self::finish_export( $backup, $state );
            }
            $table = self::safe_table_from_list( (string) $state['tables'][ $idx ], (array) $state['tables'] );
            if ( ! $table ) { return self::fail( $id, 'Unsafe table identifier encountered while exporting.' ); }

            if ( empty( $state['schema_written'] ) ) {
                $schema = self::table_schema_sql( $table );
                if ( is_wp_error( $schema ) ) { return self::fail( $id, $schema->get_error_message() ); }
                if ( false === self::append_file( $path, $schema ) ) { return self::fail( $id, 'Unable to write table schema to the backup file.' ); }
                $step_bytes += strlen( $schema );
                $cursor = self::cursor_strategy( $table );
                $state['schema_written'] = true;
                $state['offset'] = 0;
                $state['cursor'] = null;
                $state['cursor_column'] = (string) ( $cursor['column'] ?? '' );
                $state['cursor_numeric'] = ! empty( $cursor['numeric'] );
                $state['cursor_columns'] = array_values( (array) ( $cursor['columns'] ?? array() ) );
                $state['cursor_values'] = array();
                $state['data_columns'] = self::insertable_columns( $table );
                $avg_map = (array) ( $state['avg_row_bytes'] ?? array() );
                $avg_row = max( 0, (int) ( $avg_map[ $table ] ?? 0 ) );
                $initial_batch = $avg_row > 0 ? (int) floor( self::BATCH_TARGET_BYTES / max( 1, $avg_row ) ) : self::DEFAULT_BATCH_ROWS;
                $state['batch_rows'] = max( self::MIN_BATCH_ROWS, min( self::MAX_BATCH_ROWS, $initial_batch ) );
                $state['table_rows_exported'] = 0;
                if ( is_wp_error( $state['data_columns'] ) ) { return self::fail( $id, $state['data_columns']->get_error_message() ); }
            }

            $batch_rows = max( self::MIN_BATCH_ROWS, min( self::MAX_BATCH_ROWS, (int) ( $state['batch_rows'] ?? self::DEFAULT_BATCH_ROWS ) ) );
            $rows = self::fetch_rows( $table, $state, $batch_rows );
            if ( is_wp_error( $rows ) ) { return self::fail( $id, $rows->get_error_message() ); }
            $row_count = count( $rows );
            if ( $rows ) {
                $sql = self::rows_sql( $table, (array) $state['data_columns'], $rows );
                if ( is_wp_error( $sql ) ) { return self::fail( $id, $sql->get_error_message() ); }
                if ( false === self::append_file( $path, $sql ) ) { return self::fail( $id, 'Unable to append table rows to the backup file.' ); }
                $sql_bytes = strlen( $sql );
                $step_bytes += $sql_bytes;
                $step_rows += $row_count;
                $state['rows_exported'] = (int) $state['rows_exported'] + $row_count;
                $state['table_rows_exported'] = (int) ( $state['table_rows_exported'] ?? 0 ) + $row_count;

                $cursor_columns = array_values( (array) ( $state['cursor_columns'] ?? array() ) );
                if ( $cursor_columns ) {
                    $last = end( $rows );
                    $values = array();
                    foreach ( $cursor_columns as $column ) { $values[] = array_key_exists( $column, $last ) ? (string) $last[ $column ] : ''; }
                    $state['cursor_values'] = $values;
                    if ( 1 === count( $cursor_columns ) ) { $state['cursor'] = $values[0]; }
                } elseif ( ! empty( $state['cursor_numeric'] ) && ! empty( $state['cursor_column'] ) ) {
                    $last = end( $rows );
                    $state['cursor'] = isset( $last[ $state['cursor_column'] ] ) ? (string) $last[ $state['cursor_column'] ] : $state['cursor'];
                } else {
                    $state['offset'] = (int) $state['offset'] + $row_count;
                }

                // Adapt the next SELECT to row width. Narrow option/meta tables can move
                // thousands of rows at once; wide post/content tables automatically shrink.
                $avg_bytes = max( 1, (int) ceil( $sql_bytes / max( 1, $row_count ) ) );
                $target = (int) floor( self::BATCH_TARGET_BYTES / $avg_bytes );
                $state['batch_rows'] = max( self::MIN_BATCH_ROWS, min( self::MAX_BATCH_ROWS, $target ) );
            }

            if ( $row_count < $batch_rows ) {
                $end_sql = "\n-- End table `{$table}`\n\n";
                self::append_file( $path, $end_sql );
                $step_bytes += strlen( $end_sql );
                $state['table_index'] = $idx + 1;
                $state['tables_done'] = (int) $state['tables_done'] + 1;
                $state['schema_written'] = false;
                $state['offset'] = 0;
                $state['cursor'] = null;
                $state['cursor_column'] = '';
                $state['cursor_numeric'] = false;
                $state['cursor_columns'] = array();
                $state['cursor_values'] = array();
                $state['data_columns'] = array();
                $state['batch_rows'] = self::DEFAULT_BATCH_ROWS;
                $state['table_rows_exported'] = 0;
            }

            // Yield before common FastCGI/proxy request timeouts. One REST request now
            // performs many database chunks instead of booting WordPress every 100 rows.
            if ( $step_bytes >= $byte_budget || ( microtime( true ) - $started ) >= $time_budget ) { break; }
        }

        $state['last_step_rows'] = $step_rows;
        $state['last_step_bytes'] = $step_bytes;
        $state['last_step_ms'] = round( ( microtime( true ) - $started ) * 1000, 1 );
        $next_idx = (int) $state['table_index'];
        $current = $next_idx < count( $state['tables'] ) ? (string) $state['tables'][ $next_idx ] : '';
        if ( $next_idx >= count( $state['tables'] ) ) { return self::finish_export( $backup, $state ); }

        $wpdb->update(
            self::table_name(),
            array(
                'updated_at' => PFC_Utils::now_mysql(),
                'status' => 'running',
                'size_bytes' => (int) @filesize( $path ),
                'tables_done' => (int) $state['tables_done'],
                'row_count' => (int) $state['rows_exported'],
                'current_table' => $current,
                'state_json' => wp_json_encode( $state ),
            ),
            array( 'id' => (int) $id )
        );
        return self::get( $id );
    }

    private static function finish_export( array $backup, array $state ) {
        global $wpdb;
        $path = self::validated_path( $backup );
        if ( is_wp_error( $path ) ) { return self::fail( (int) $backup['id'], $path->get_error_message() ); }
        if ( empty( $state['footer_written'] ) ) {
            $footer = "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- " . $state['marker'] . "\n";
            if ( false === self::append_file( $path, $footer ) ) { return self::fail( (int) $backup['id'], 'Unable to finalize the backup file.' ); }
            $state['footer_written'] = true;
        }
        $wpdb->update(
            self::table_name(),
            array(
                'updated_at' => PFC_Utils::now_mysql(),
                'status' => 'ready_verify',
                'size_bytes' => (int) @filesize( $path ),
                'tables_done' => count( (array) $state['tables'] ),
                'row_count' => (int) ( $state['rows_exported'] ?? 0 ),
                'current_table' => '',
                'state_json' => wp_json_encode( $state ),
            ),
            array( 'id' => (int) $backup['id'] )
        );
        return self::get( (int) $backup['id'] );
    }

    public static function verify( $id ) {
        global $wpdb;
        $backup = self::get( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        if ( ! in_array( $backup['status'], array( 'ready_verify','verified' ), true ) ) { return new WP_Error( 'pfc_backup_not_ready', 'The backup export has not finished yet.' ); }
        $path = self::validated_path( $backup );
        if ( is_wp_error( $path ) ) { return $path; }
        $state = json_decode( (string) $backup['state_json'], true );
        if ( ! is_array( $state ) || empty( $state['marker'] ) ) { return new WP_Error( 'pfc_backup_state', 'Backup verification state is unavailable.' ); }
        $size = (int) @filesize( $path );
        if ( $size < 128 ) { return new WP_Error( 'pfc_backup_short', 'The backup file is unexpectedly small.' ); }
        $tail = self::file_tail( $path, 8192 );
        if ( false === $tail || false === strpos( $tail, '-- ' . $state['marker'] ) ) { return new WP_Error( 'pfc_backup_incomplete', 'The backup completion marker was not found. Resume or recreate the backup.' ); }
        if ( (int) ( $backup['tables_done'] ?? 0 ) !== (int) ( $backup['table_count'] ?? 0 ) ) { return new WP_Error( 'pfc_backup_tables_incomplete', 'Not every table in the backup manifest was completed.' ); }
        $hash = @hash_file( 'sha256', $path );
        if ( ! is_string( $hash ) || 64 !== strlen( $hash ) ) { return new WP_Error( 'pfc_backup_hash', 'The backup file could not be checksummed.' ); }
        if ( 'verified' === $backup['status'] && ! hash_equals( (string) $backup['sha256'], $hash ) ) {
            return new WP_Error( 'pfc_backup_changed', 'The export changed after its integrity check. Create a new export; the previous checksum will not be replaced.' );
        }
        $now = PFC_Utils::now_mysql();
        $wpdb->update(
            self::table_name(),
            array( 'updated_at' => $now, 'status' => 'verified', 'size_bytes' => $size, 'sha256' => $hash, 'verified_at' => $now, 'error_text' => '' ),
            array( 'id' => (int) $id )
        );
        return self::get( $id );
    }

    public static function is_verified_recent( $id, $max_age = self::VERIFIED_MAX_AGE, $check_integrity = true ) {
        $backup = self::get( $id );
        if ( is_wp_error( $backup ) || 'verified' !== (string) $backup['status'] || empty( $backup['verified_at'] ) || empty( $backup['sha256'] ) ) { return false; }
        $verified = strtotime( (string) $backup['verified_at'] . ' UTC' );
        if ( ! $verified || time() - $verified > max( 300, (int) $max_age ) ) { return false; }
        $path = self::validated_path( $backup );
        if ( is_wp_error( $path ) ) { return false; }
        clearstatcache( true, $path );
        if ( (int) @filesize( $path ) !== (int) $backup['size_bytes'] ) { return false; }
        // Listing candidates is cheap; repair authorization always rehashes the chosen export.
        if ( ! $check_integrity ) { return true; }
        $hash = @hash_file( 'sha256', $path );
        return is_string( $hash ) && hash_equals( (string) $backup['sha256'], $hash );
    }

    public static function get( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id=%d', absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $row ? $row : new WP_Error( 'pfc_backup_missing', 'Database backup not found.' );
    }

    public static function list_backups( $limit = 20 ) {
        global $wpdb;
        $limit = max( 1, min( 100, absint( $limit ) ) );
        return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function delete( $id ) {
        global $wpdb;
        $backup = self::get( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $path = self::validated_path( $backup, false );
        if ( ! is_wp_error( $path ) && file_exists( $path ) ) { @unlink( $path ); } // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
        return array( 'ok' => true, 'message' => 'Database backup deleted.' );
    }

    public static function download( $id ) {
        $backup = self::get( $id );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $path = self::validated_path( $backup );
        if ( is_wp_error( $path ) ) { return $path; }
        nocache_headers();
        header( 'Content-Type: application/sql' );
        header( 'Content-Disposition: attachment; filename="' . rawurlencode( basename( $path ) ) . '"' );
        header( 'Content-Length: ' . (string) filesize( $path ) );
        header( 'X-Content-Type-Options: nosniff' );
        @set_time_limit( 0 );
        $fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( false === $fh ) { return new WP_Error( 'pfc_backup_open', 'Unable to open the backup file for download.' ); }
        while ( ! feof( $fh ) ) {
            echo fread( $fh, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.file_system_operations_fread
            if ( function_exists( 'fastcgi_finish_request' ) ) { /* Do not call; it would terminate the stream. */ }
            flush();
        }
        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    public static function storage_summary() {
        $dir = self::storage_dir();
        if ( is_wp_error( $dir ) ) { return array( 'ok' => false, 'message' => $dir->get_error_message() ); }
        $docroot = self::document_root();
        $inside_webroot = $docroot && 0 === strpos( self::normalize_path( $dir ), trailingslashit( self::normalize_path( $docroot ) ) );
        return array( 'ok' => true, 'path' => $dir, 'inside_webroot' => (bool) $inside_webroot, 'protected' => file_exists( trailingslashit( $dir ) . '.htaccess' ) && file_exists( trailingslashit( $dir ) . 'web.config' ) );
    }

    public static function cli_create( $scope = 'wordpress' ) {
        $backup = self::create( $scope );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $guard = 0;
        while ( in_array( $backup['status'], array( 'running','ready_verify' ), true ) && $guard < 10000000 ) {
            if ( 'ready_verify' === $backup['status'] ) { return self::verify( (int) $backup['id'] ); }
            $backup = self::step( (int) $backup['id'] );
            if ( is_wp_error( $backup ) ) { return $backup; }
            $guard++;
        }
        return $backup;
    }

    private static function database_tables( $scope ) {
        global $wpdb;
        $rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
        if ( null === $rows && $wpdb->last_error ) { return new WP_Error( 'pfc_backup_table_list', sanitize_text_field( $wpdb->last_error ) ); }
        $tables = array(); $views = array();
        $prefix = (string) $wpdb->base_prefix;
        foreach ( (array) $rows as $row ) {
            $name = (string) ( $row[0] ?? '' );
            $type = strtoupper( (string) ( $row[1] ?? 'BASE TABLE' ) );
            if ( 'wordpress' === $scope && 0 !== strpos( $name, $prefix ) ) { continue; }
            if ( 'VIEW' === $type ) { $views[] = $name; continue; }
            if ( 'BASE TABLE' === $type || '' === $type ) { $tables[] = $name; }
        }
        sort( $tables, SORT_STRING ); sort( $views, SORT_STRING );
        return array( 'tables' => $tables, 'views' => $views );
    }

    private static function table_stats( array $tables ) {
        global $wpdb;
        $rows_out = array_fill_keys( $tables, 0 );
        $avg_out = array_fill_keys( $tables, 0 );
        if ( ! $tables ) { return array( 'rows' => $rows_out, 'avg_row_bytes' => $avg_out ); }
        foreach ( array_chunk( $tables, 100 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $sql = "SELECT TABLE_NAME,TABLE_ROWS,AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})";
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ( (array) $rows as $row ) {
                $name = (string) ( $row['TABLE_NAME'] ?? '' );
                if ( isset( $rows_out[ $name ] ) ) {
                    $rows_out[ $name ] = max( 0, (int) ( $row['TABLE_ROWS'] ?? 0 ) );
                    $avg_out[ $name ] = max( 0, (int) ( $row['AVG_ROW_LENGTH'] ?? 0 ) );
                }
            }
        }
        return array( 'rows' => $rows_out, 'avg_row_bytes' => $avg_out );
    }

    private static function header_sql( $scope, array $tables ) {
        global $wpdb;
        $server = sanitize_text_field( (string) $wpdb->db_version() );
        return "-- Performance Console database backup\n"
            . '-- Created UTC: ' . gmdate( 'c' ) . "\n"
            . '-- Performance Console version: ' . PFC_VERSION . "\n"
            . '-- WordPress version: ' . get_bloginfo( 'version' ) . "\n"
            . '-- Database server: ' . $server . "\n"
            . '-- Scope: ' . $scope . "\n"
            . '-- Base tables: ' . count( (array) $tables['tables'] ) . "\n"
            . '-- Views skipped: ' . count( (array) $tables['views'] ) . "\n"
            . "-- This logical backup contains table schema/data only. It excludes DB users, grants, routines, triggers/events and server configuration.\n\n"
            . "SET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET NAMES utf8mb4;\n\n";
    }

    private static function table_schema_sql( $table ) {
        global $wpdb;
        $row = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! $row || empty( $row[1] ) ) { return new WP_Error( 'pfc_backup_schema', 'Unable to read CREATE TABLE for ' . $table . '.' ); }
        return "-- Table `{$table}`\nDROP TABLE IF EXISTS `{$table}`;\n" . rtrim( (string) $row[1], "; \t\r\n" ) . ";\n\n";
    }

    private static function insertable_columns( $table ) {
        global $wpdb;
        $rows = $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! is_array( $rows ) ) { return new WP_Error( 'pfc_backup_columns', 'Unable to inspect columns for ' . $table . '.' ); }
        $columns = array();
        foreach ( $rows as $row ) {
            $extra = strtolower( (string) ( $row['Extra'] ?? '' ) );
            if ( false !== strpos( $extra, 'generated' ) ) { continue; }
            $name = (string) ( $row['Field'] ?? '' );
            if ( preg_match( '/^[A-Za-z0-9_$]+$/', $name ) ) { $columns[] = $name; }
        }
        return $columns;
    }

    private static function cursor_strategy( $table ) {
        global $wpdb;
        $keys = (array) $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name='PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        usort( $keys, static function ( $left, $right ) {
            return (int) ( $left['Seq_in_index'] ?? 0 ) <=> (int) ( $right['Seq_in_index'] ?? 0 );
        } );
        $columns = array();
        foreach ( (array) $keys as $key ) {
            $column = (string) ( $key['Column_name'] ?? '' );
            if ( ! preg_match( '/^[A-Za-z0-9_$]+$/', $column ) ) { return array( 'column' => '', 'numeric' => false, 'columns' => array() ); }
            $columns[] = $column;
        }
        if ( ! $columns ) { return array( 'column' => '', 'numeric' => false, 'columns' => array() ); }
        $numeric = false;
        if ( 1 === count( $columns ) ) {
            $type = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s", $table, $columns[0] ) );
            $numeric = (bool) preg_match( '/^(?:tinyint|smallint|mediumint|int|bigint)\b/i', $type );
        }
        return array( 'column' => 1 === count( $columns ) ? $columns[0] : '', 'numeric' => $numeric, 'columns' => $columns );
    }

    private static function fetch_rows( $table, array $state, $limit ) {
        global $wpdb;
        $columns = array_values( array_filter( (array) ( $state['data_columns'] ?? array() ), static function ( $column ) { return (bool) preg_match( '/^[A-Za-z0-9_$]+$/', (string) $column ); } ) );
        if ( ! $columns ) { return array(); }
        $select = implode( ',', array_map( static function ( $column ) { return '`' . $column . '`'; }, $columns ) );
        $limit = max( self::MIN_BATCH_ROWS, min( self::MAX_BATCH_ROWS, (int) $limit ) );

        $cursor_columns = array_values( array_filter( (array) ( $state['cursor_columns'] ?? array() ), static function ( $column ) { return (bool) preg_match( '/^[A-Za-z0-9_$]+$/', (string) $column ); } ) );
        if ( $cursor_columns ) {
            $order = implode( ',', array_map( static function ( $column ) { return '`' . $column . '` ASC'; }, $cursor_columns ) );
            $cursor_values = array_values( (array) ( $state['cursor_values'] ?? array() ) );
            if ( count( $cursor_values ) !== count( $cursor_columns ) ) {
                $sql = $wpdb->prepare( "SELECT {$select} FROM `{$table}` ORDER BY {$order} LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            } else {
                $lhs = '(' . implode( ',', array_map( static function ( $column ) { return '`' . $column . '`'; }, $cursor_columns ) ) . ')';
                $rhs = '(' . implode( ',', array_fill( 0, count( $cursor_columns ), '%s' ) ) . ')';
                $args = $cursor_values;
                $args[] = $limit;
                $sql = $wpdb->prepare( "SELECT {$select} FROM `{$table}` WHERE {$lhs} > {$rhs} ORDER BY {$order} LIMIT %d", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        } elseif ( ! empty( $state['cursor_numeric'] ) && ! empty( $state['cursor_column'] ) && preg_match( '/^[A-Za-z0-9_$]+$/', (string) $state['cursor_column'] ) ) {
            // Backward-compatible resume path for backups created by Performance Console 1.6.0,
            // which stored a single numeric cursor instead of cursor_columns.
            $column = (string) $state['cursor_column'];
            if ( null === ( $state['cursor'] ?? null ) ) {
                $sql = $wpdb->prepare( "SELECT {$select} FROM `{$table}` ORDER BY `{$column}` ASC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            } else {
                $cursor = preg_replace( '/[^0-9-]/', '', (string) $state['cursor'] );
                $sql = $wpdb->prepare( "SELECT {$select} FROM `{$table}` WHERE `{$column}` > %s ORDER BY `{$column}` ASC LIMIT %d", $cursor, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        } else {
            $offset = max( 0, (int) ( $state['offset'] ?? 0 ) );
            $sql = $wpdb->prepare( "SELECT {$select} FROM `{$table}` LIMIT %d OFFSET %d", $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( null === $rows && $wpdb->last_error ) { return new WP_Error( 'pfc_backup_rows', sanitize_text_field( $wpdb->last_error ) ); }
        return (array) $rows;
    }

    private static function rows_sql( $table, array $columns, array $rows ) {
        global $wpdb;
        if ( ! $rows || ! $columns ) { return ''; }
        $quoted_columns = implode( ',', array_map( static function ( $column ) { return '`' . $column . '`'; }, $columns ) );
        $prefix = 'INSERT INTO `' . $table . '` (' . $quoted_columns . ') VALUES\n';
        $target = max( 256 * 1024, min( 4 * MB_IN_BYTES, (int) apply_filters( 'pfc_backup_insert_target_bytes', self::BATCH_TARGET_BYTES ) ) );
        $out = '';
        $values = array();
        $bytes = strlen( $prefix );
        foreach ( $rows as $row ) {
            $parts = array();
            foreach ( $columns as $column ) {
                if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) { $parts[] = 'NULL'; continue; }
                $parts[] = "'" . $wpdb->_real_escape( (string) $row[ $column ] ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql__real_escape
            }
            $value = '(' . implode( ',', $parts ) . ')';
            $value_bytes = strlen( $value ) + 2;
            if ( $values && $bytes + $value_bytes > $target ) {
                $out .= $prefix . implode( ",\n", $values ) . ";\n";
                $values = array();
                $bytes = strlen( $prefix );
            }
            $values[] = $value;
            $bytes += $value_bytes;
        }
        if ( $values ) { $out .= $prefix . implode( ",\n", $values ) . ";\n"; }
        return $out;
    }

    private static function space_preflight( $dir, array $tables ) {
        global $wpdb;
        $estimated = 0;
        if ( $tables ) {
            $placeholders = implode( ',', array_fill( 0, count( $tables ), '%s' ) );
            $sql = "SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})";
            $estimated = (int) $wpdb->get_var( $wpdb->prepare( $sql, $tables ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        $free = @disk_free_space( $dir );
        if ( false === $free ) { return array( 'available' => false, 'estimated_db_bytes' => $estimated, 'free_bytes' => 0, 'required_bytes' => 0, 'ok' => true ); }
        $required = max( 50 * MB_IN_BYTES, (int) ( $estimated * 1.25 ) );
        return array( 'available' => true, 'estimated_db_bytes' => $estimated, 'free_bytes' => (int) $free, 'required_bytes' => $required, 'ok' => (int) $free >= $required );
    }

    private static function storage_dir() {
        foreach ( self::storage_candidates() as $candidate ) {
            if ( ! $candidate ) { continue; }
            if ( ! is_dir( $candidate ) && ! wp_mkdir_p( $candidate ) ) { continue; }
            if ( ! is_writable( $candidate ) ) { continue; }
            @chmod( $candidate, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            self::protect_directory( $candidate );
            return untrailingslashit( $candidate );
        }
        return new WP_Error( 'pfc_backup_dir', 'No private writable directory is available for database backups. Configure the pfc_backup_storage_candidates filter or create a writable private path.' );
    }

    private static function protect_directory( $dir ) {
        $rules = "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
        @file_put_contents( trailingslashit( $dir ) . '.htaccess', $rules, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $webconfig = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*"/></authorization></system.webServer></configuration>';
        @file_put_contents( trailingslashit( $dir ) . 'web.config', $webconfig, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php http_response_code(404); exit;\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }

    private static function validated_path( array $backup, $must_exist = true ) {
        $path = (string) ( $backup['file_path'] ?? '' );
        $filename = (string) ( $backup['filename'] ?? '' );
        if ( ! preg_match( '/^(?:pfc|wpi)-db-[0-9]{8}-[0-9]{6}-[a-z0-9]{6,20}\.sql$/i', $filename ) || basename( $path ) !== $filename ) { return new WP_Error( 'pfc_backup_path', 'Backup file path failed validation.' ); }
        $dir = self::normalize_path( dirname( $path ) );
        $allowed = array_map( array( __CLASS__, 'normalize_path' ), self::existing_storage_roots() );
        if ( ! in_array( $dir, $allowed, true ) ) { return new WP_Error( 'pfc_backup_path', 'Backup file is outside the configured private backup directories.' ); }
        if ( $must_exist && ! is_file( $path ) ) { return new WP_Error( 'pfc_backup_file_missing', 'The backup file is missing.' ); }
        return $path;
    }

    private static function append_file( $path, $contents, $truncate = false ) {
        $flags = LOCK_EX | ( $truncate ? 0 : FILE_APPEND );
        return false !== @file_put_contents( $path, $contents, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }

    private static function file_tail( $path, $bytes ) {
        $size = @filesize( $path );
        if ( false === $size ) { return false; }
        $fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if ( false === $fh ) { return false; }
        $offset = max( 0, $size - max( 1024, (int) $bytes ) );
        fseek( $fh, $offset );
        $data = stream_get_contents( $fh );
        fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $data;
    }

    private static function fail( $id, $message ) {
        global $wpdb;
        $wpdb->update( self::table_name(), array( 'updated_at' => PFC_Utils::now_mysql(), 'status' => 'failed', 'error_text' => sanitize_text_field( (string) $message ) ), array( 'id' => (int) $id ) );
        return new WP_Error( 'pfc_backup_failed', $message );
    }

    private static function safe_table_from_list( $table, array $allowed ) {
        if ( ! in_array( $table, $allowed, true ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $table ) ) { return ''; }
        return $table;
    }

    private static function storage_candidates() {
        $hash = substr( hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH ) . DB_NAME ), 0, 16 );
        $candidates = array();
        $parent = dirname( untrailingslashit( ABSPATH ) );
        $parent_candidate = trailingslashit( $parent ) . '.pfc-private-backups-' . $hash;
        if ( is_dir( $parent_candidate ) || ( is_dir( $parent ) && is_writable( $parent ) ) ) { $candidates[] = $parent_candidate; }
        $temp = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
        if ( $temp ) { $candidates[] = trailingslashit( $temp ) . 'pfc-private-backups-' . $hash; }
        $candidates[] = trailingslashit( WP_CONTENT_DIR ) . 'pfc-private-backups-' . $hash;
        return array_values( array_unique( (array) apply_filters( 'pfc_backup_storage_candidates', $candidates ) ) );
    }

    private static function legacy_storage_candidates() {
        $hash = substr( hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH ) . DB_NAME ), 0, 16 );
        $candidates = array();
        $parent = dirname( untrailingslashit( ABSPATH ) );
        $candidates[] = trailingslashit( $parent ) . '.wpi-private-backups-' . $hash;
        $temp = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
        if ( $temp ) { $candidates[] = trailingslashit( $temp ) . 'wpi-private-backups-' . $hash; }
        $candidates[] = trailingslashit( WP_CONTENT_DIR ) . 'wpi-private-backups-' . $hash;
        return array_values( array_unique( $candidates ) );
    }

    private static function existing_storage_roots() {
        $roots = array();
        foreach ( array_merge( self::storage_candidates(), self::legacy_storage_candidates() ) as $candidate ) { if ( $candidate && is_dir( $candidate ) ) { $roots[] = $candidate; } }
        return $roots;
    }

    private static function document_root() {
        $root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
        return $root && is_dir( $root ) ? $root : '';
    }

    private static function normalize_path( $path ) {
        $real = realpath( $path );
        $path = false !== $real ? $real : $path;
        return rtrim( str_replace( '\\', '/', $path ), '/' );
    }
}
