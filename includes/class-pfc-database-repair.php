<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Explicit database remediation. Nothing in this class runs automatically.
 * Web requests are intentionally size-gated; WP-CLI can opt in with --force-large.
 */
final class PFC_Database_Repair {
    const WEB_ROW_LIMIT  = 500000;
    const WEB_SIZE_LIMIT = 256000000; // ~244 MiB; conservative on shared/production DBs.
    const MAX_BATCH      = 1000;
    const MAX_BACKUP_BYTES = 2097152;

    public static function plans( array $health ) {
        $plans = array();
        $schema = (array) ( $health['schema'] ?? array() );
        $options = (array) ( $health['options'] ?? array() );
        $orphans = (array) ( $health['orphans'] ?? array() );

        $missing_indexes = (array) ( $schema['missing_core_indexes'] ?? array() );
        $missing_columns = (array) ( $schema['missing_core_columns'] ?? array() );
        if ( $missing_indexes || $missing_columns ) {
            $large = self::schema_repair_has_large_table( $health, array_merge( $missing_indexes, $missing_columns ) );
            $plans[] = array(
                'id'          => 'core-schema',
                'title'       => 'Repair missing WordPress core columns/indexes',
                'action'      => 'repair_core_schema',
                'safety'      => $large ? 'cli-review' : 'review',
                'available'   => ! $large,
                'detail'      => count( $missing_columns ) . ' missing column(s), ' . count( $missing_indexes ) . ' missing index(es).',
                'reason'      => $large ? 'At least one affected table exceeds the normal wp-admin safety threshold. Use the guarded Maintenance Fix workflow with a verified backup, or WP-CLI during a maintenance window.' : 'Adds only WordPress core definitions that the scanner has confirmed are missing from existing tables.',
                'requires_backup' => true,
                'requires_danger_ack' => true,
                'button_label' => $large ? 'Run Core Schema Maintenance Fix' : 'Repair Core Schema',
                'args'        => array(),
            );
        }

        foreach ( array_slice( (array) ( $schema['mismatched_core_columns'] ?? array() ), 0, 20 ) as $item ) {
            $meta = self::table_meta( $health, (string) ( $item['table'] ?? '' ) );
            $large = self::is_large_meta( $meta );
            $preview_plan = self::core_column_alter_plan(
                (string) ( $item['table'] ?? '' ),
                (string) ( $item['column'] ?? '' ),
                (string) ( $item['definition'] ?? '' ),
                $health,
                false
            );
            $preview_sql = is_wp_error( $preview_plan ) ? '' : (string) ( $preview_plan['sql'] ?? '' );
            if ( $preview_sql && 0 === strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) {
                $preview_sql .= ', ALGORITHM=INPLACE, LOCK=NONE';
            }
            $primary_dependency = ! is_wp_error( $preview_plan ) && ! empty( $preview_plan['primary_repair'] );
            $drift_parts = array();
            foreach ( (array) ( $item['drift'] ?? array() ) as $drift_name => $drift_values ) {
                $label = ucwords( str_replace( '_', ' ', (string) $drift_name ) );
                if ( is_array( $drift_values ) && ( isset( $drift_values['actual'] ) || isset( $drift_values['expected'] ) ) ) {
                    $drift_parts[] = $label . ': ' . (string) ( $drift_values['actual'] ?? '?' ) . ' → ' . (string) ( $drift_values['expected'] ?? '?' );
                }
            }
            $detail = 'Expected: ' . (string) $item['definition'] . '.';
            if ( $drift_parts ) { $detail .= ' ' . implode( '; ', $drift_parts ) . '.'; }
            if ( $primary_dependency ) {
                $live_key = implode( ', ', (array) ( $preview_plan['live_primary_columns'] ?? array() ) );
                $expected_key = implode( ', ', (array) ( $preview_plan['expected_primary_columns'] ?? array() ) );
                $detail .= ' Live PRIMARY KEY: (' . $live_key . ') → WordPress expected: (' . $expected_key . ').';
            }
            $plans[] = array(
                'id' => 'core-column-drift-' . md5( wp_json_encode( $item ) ),
                'title' => 'Correct WordPress core column drift: ' . (string) $item['table'] . '.' . (string) $item['column'],
                'action' => 'repair_core_column_drift',
                'safety' => $large ? 'cli-review' : 'high-review',
                'available' => ! $large,
                'detail' => $detail,
                'reason' => $large ? 'The affected table exceeds the normal browser ALTER threshold. Use the guarded Maintenance Fix workflow with a verified backup, or the equivalent WP-CLI repair.' : ( $primary_dependency ? 'This column participates in a malformed live PRIMARY KEY. Performance Console will replace that live key and apply the WordPress column definition in one coordinated ALTER, then verify both structures.' : 'Performance Console re-checks the installed WordPress schema immediately before ALTER, requires a verified backup plus an explicit schema-change acknowledgement, requests non-blocking InnoDB DDL where supported, and verifies the column afterward.' ),
                'requires_backup' => true,
                'requires_danger_ack' => true,
                'button_label' => 'Apply Schema Correction',
                'args' => array( 'table' => (string) $item['table'], 'column' => (string) $item['column'] ),
                'sql_preview' => $preview_sql,
            );
        }
        foreach ( array_slice( (array) ( $schema['mismatched_core_indexes'] ?? array() ), 0, 20 ) as $item ) {
            $meta = self::table_meta( $health, (string) ( $item['table'] ?? '' ) );
            $large = self::is_large_meta( $meta );
            $plans[] = array(
                'id' => 'core-index-drift-' . md5( wp_json_encode( $item ) ),
                'title' => 'Correct WordPress core index drift: ' . (string) $item['table'] . '.' . (string) $item['index'],
                'action' => 'repair_core_index_drift',
                'safety' => $large ? 'cli-review' : 'high-review',
                'available' => ! $large,
                'detail' => 'Expected: ' . (string) $item['definition'] . '. Live: ' . (string) $item['actual'] . '.',
                'reason' => $large ? 'The affected table exceeds the normal browser DDL threshold. Use the guarded Maintenance Fix workflow or WP-CLI during a maintenance window.' : 'Performance Console revalidates the live and expected definitions, preflights unique keys for duplicate values, replaces only the named drifted core index, and verifies it afterward.',
                'requires_backup' => true,
                'requires_danger_ack' => true,
                'button_label' => 'Rebuild Core Index',
                'args' => array( 'table' => (string) $item['table'], 'index' => (string) $item['index'] ),
                'sql_preview' => 'ALTER TABLE `' . (string) $item['table'] . '` DROP ' . ( 'PRIMARY' === strtoupper( (string) $item['index'] ) ? 'PRIMARY KEY' : 'INDEX `' . (string) $item['index'] . '`' ) . ', ADD ' . (string) $item['definition'],
            );
        }

        if ( ! empty( $options['expired_transients'] ) ) {
            $plans[] = array(
                'id'        => 'expired-transients',
                'title'     => 'Delete expired transients',
                'action'    => 'cleanup_expired_transients',
                'safety'    => 'safe',
                'available' => true,
                'detail'    => number_format_i18n( (int) $options['expired_transients'] ) . ' expired transient timeout row(s) detected.',
                'reason'    => 'Deletes only transient values whose timeout is already in the past, in bounded batches.',
                'args'      => array( 'limit' => 500 ),
            );
        }

        foreach ( (array) ( $orphans['counts'] ?? array() ) as $type => $count ) {
            if ( (int) $count <= 0 || ! self::orphan_type_supported( $type ) ) { continue; }
            $plans[] = array(
                'id'        => 'orphan-' . sanitize_key( $type ),
                'title'     => 'Clean orphaned ' . str_replace( '_', ' ', sanitize_key( $type ) ),
                'action'    => 'cleanup_orphans',
                'safety'    => 'review',
                'available' => true,
                'detail'    => number_format_i18n( (int) $count ) . ' verified orphan row(s) detected.',
                'reason'    => 'Deletes only child records whose referenced parent no longer exists, in a bounded batch. A fresh check is performed before deletion.',
                'args'      => array( 'type' => sanitize_key( $type ), 'limit' => 500 ),
            );
        }

        $observed_days = ! empty( $options['usage_started_at'] ) ? ( time() - (int) $options['usage_started_at'] ) / DAY_IN_SECONDS : 0;
        if ( PFC_Utils::autoload_review_ready( (array) ( $options['usage_coverage'] ?? array() ), $observed_days ) ) {
            foreach ( array_slice( (array) ( $options['largest_autoload'] ?? array() ), 0, 30 ) as $row ) {
                $name = (string) ( $row['option_name'] ?? '' );
                if ( (int) ( $row['bytes'] ?? 0 ) < 128 * KB_IN_BYTES || ! empty( $row['last_seen'] ) || self::protected_option( $name ) ) { continue; }
                $plans[] = array(
                    'id'        => 'autoload-' . md5( $name ),
                    'title'     => 'Stop autoloading ' . $name,
                    'action'    => 'set_autoload_off',
                    'safety'    => 'review',
                    'available' => true,
                    'detail'    => size_format( (int) $row['bytes'] ) . ' and not observed through get_option() during the sampling window.',
                    'reason'    => 'Sample coverage includes at least 100 requests, including 20 frontend and 20 admin requests. This is not proof of disuse: verify rare saves, checkout, cron and indirect option reads. Changes only the autoload flag; the value is retained and the change is logged.',
                    'args'      => array( 'option' => $name ),
                );
            }
        }

        foreach ( (array) ( $health['integrity']['problems'] ?? array() ) as $problem ) {
            $table = (string) ( $problem['table'] ?? '' );
            $meta = self::table_meta( $health, $table );
            $engine = strtolower( (string) ( $meta['engine'] ?? $problem['engine'] ?? '' ) );
            $caps = self::engine_capabilities_for_health( $health, $engine );
            $large = self::is_large_meta( $meta );
            $detail = sanitize_text_field( (string) ( $problem['message'] ?? 'Integrity problem reported.' ) );

            if ( 'innodb' === $engine ) {
                $plans[] = array(
                    'id'        => 'innodb-recovery-' . md5( $table ),
                    'title'     => 'Build guided InnoDB recovery plan: ' . $table,
                    'action'    => 'innodb_recovery_preflight',
                    'safety'    => 'safe',
                    'available' => true,
                    'detail'    => $detail,
                    'reason'    => 'Runs read-only preflights: CHECK TABLE evidence, index inventory, lock/transaction state, online-DDL capability and disk-space estimates. It does not mutate the table.',
                    'button_label' => 'Build Recovery Plan',
                    'args'      => array( 'table' => $table ),
                );
                $suspect_index = self::suspect_innodb_index( $table, $detail );
                if ( $suspect_index ) {
                    $plans[] = array(
                        'id'        => 'innodb-index-' . md5( $table . ':' . $suspect_index ),
                        'title'     => 'Rebuild suspected InnoDB secondary index: ' . $table . '.' . $suspect_index,
                        'action'    => 'rebuild_innodb_index',
                        'safety'    => $large ? 'cli-review' : 'review',
                        'available' => ! $large,
                        'detail'    => 'Integrity evidence appears to reference secondary index ' . $suspect_index . '.',
                        'reason'    => $large ? 'The table exceeds the normal wp-admin DDL threshold. Use the guarded Maintenance Fix workflow after verifying a current backup, or WP-CLI for the largest tables.' : 'Recreates only the named secondary BTREE index using ALGORITHM=INPLACE, LOCK=NONE. Performance Console fails closed if the server cannot perform that online operation.',
                        'requires_backup' => true,
                        'args'      => array( 'table' => $table, 'index' => $suspect_index ),
                    );
                }
                $ddl = (array) ( $health['server']['innodb']['online_ddl'] ?? array() );
                if ( ! empty( $ddl['inplace'] ) ) {
                    $plans[] = array(
                        'id'        => 'innodb-table-rebuild-' . md5( $table ),
                        'title'     => 'Advanced InnoDB table rebuild: ' . $table,
                        'action'    => 'rebuild_innodb_table',
                        'safety'    => 'cli-review',
                        'available' => false,
                        'detail'    => 'Rebuilds the InnoDB table and all indexes in place when the server permits concurrent DML.',
                        'reason'    => 'This is not a generic corruption repair. Run the recovery preflight first, verify a backup/snapshot and use this only when the table remains readable and a controlled rebuild is appropriate. Performance Console never falls back to ALGORITHM=COPY.',
                        'requires_backup' => true,
                        'args'      => array( 'table' => $table ),
                    );
                }
                continue;
            }

            if ( 'csv' === $engine ) {
                $plans[] = array(
                    'id'        => 'csv-recovery-' . md5( $table ),
                    'title'     => 'Repair damaged CSV table: ' . $table,
                    'action'    => 'repair_csv_table',
                    'safety'    => $large ? 'cli-review' : 'high-review',
                    'available' => ! $large,
                    'detail'    => $detail,
                    'reason'    => $large ? 'CSV repair is potentially destructive and the table exceeds the normal browser threshold. Use the guarded Maintenance Fix workflow or WP-CLI only after exporting/verifying the table and acknowledging possible data loss.' : 'CSV REPAIR TABLE may discard rows after the first damaged record. Performance Console therefore requires both a verified backup and an explicit data-loss acknowledgement before running it, then re-checks the table.',
                    'requires_backup' => true,
                    'requires_data_loss_ack' => true,
                    'button_label' => 'Repair CSV Table',
                    'args'      => array( 'table' => $table ),
                    'sql_preview' => 'REPAIR TABLE `' . $table . '`',
                );
                continue;
            }

            if ( ! empty( $caps['repair'] ) ) {
                $plans[] = array(
                    'id'        => 'repair-' . md5( $table ),
                    'title'     => 'Repair damaged ' . $table . ' (' . ( $meta['engine'] ?? $engine ) . ')',
                    'action'    => 'repair_table',
                    'safety'    => $large ? 'cli-review' : 'review',
                    'available' => ! $large,
                    'detail'    => $detail,
                    'reason'    => $large ? 'Table exceeds the normal wp-admin repair threshold. Use the guarded Maintenance Fix workflow or WP-CLI during a maintenance window.' : 'The detected storage engine supports REPAIR TABLE. Performance Console still treats this as explicit maintenance and records the operation.',
                    'requires_backup' => true,
                    'args'      => array( 'table' => $table ),
                );
            } else {
                $plans[] = array(
                    'id'        => 'engine-recovery-' . md5( $table ),
                    'title'     => 'Review integrity problem on ' . $table . ' (' . ( $meta['engine'] ?? $engine ) . ')',
                    'action'    => '',
                    'safety'    => 'manual',
                    'available' => false,
                    'detail'    => $detail,
                    'reason'    => (string) ( $caps['notes'] ?? 'This storage engine does not have a repair path Performance Console can safely automate.' ),
                    'manual_steps' => array(
                        'Verify a current database backup or storage snapshot before changing this table.',
                        'Review SHOW TABLE STATUS and SHOW CREATE TABLE for the affected table and confirm the storage engine and owning plugin/application.',
                        ! empty( $caps['check'] ) ? 'Run CHECK TABLE for fresh integrity evidence before attempting engine-specific maintenance.' : 'Use the storage engine vendor/server tooling to obtain a fresh integrity report; SQL CHECK TABLE is not advertised as supported for this engine.',
                        'Follow the engine-specific rebuild/restore procedure. Do not substitute REPAIR TABLE unless the live server reports that operation as supported for this engine.',
                        'After recovery, rerun Performance Console Deep Database Scan and compare schema, integrity and query performance before returning the site to normal traffic.',
                    ),
                    'sql_preview' => ! empty( $caps['check'] ) ? 'CHECK TABLE `' . $table . '`' : '',
                    'args'      => array(),
                );
            }
        }

        foreach ( array_slice( (array) ( $schema['tables'] ?? array() ), 0, 75 ) as $table ) {
            $size = (int) ( $table['size'] ?? 0 );
            $free = (int) ( $table['data_free'] ?? 0 );
            if ( $size < 100 * PFC_Utils::MB_IN_BYTES || $free < max( 100 * PFC_Utils::MB_IN_BYTES, (int) ( $size * 0.25 ) ) ) { continue; }
            $engine = strtolower( (string) ( $table['engine'] ?? '' ) );
            $caps = self::engine_capabilities_for_health( $health, $engine );
            if ( empty( $caps['optimize'] ) ) { continue; }
            $large = self::is_large_meta( $table );
            $engine_note = 'innodb' === $engine ? 'For InnoDB this can rebuild the table/indexes and reclaim file-per-table space; it is not a corruption repair.' : 'This engine supports OPTIMIZE TABLE, but the operation may lock or rebuild data.';
            $plans[] = array(
                'id'        => 'optimize-' . md5( $table['name'] ),
                'title'     => 'Rebuild/optimize ' . $table['name'] . ' (' . (string) ( $table['engine'] ?? '' ) . ')',
                'action'    => 'optimize_table',
                'safety'    => $large ? 'cli-review' : 'review',
                'available' => ! $large,
                'detail'    => size_format( $free ) . ' reported free/fragmented space.',
                'reason'    => $large ? 'OPTIMIZE can rebuild and lock a large table. Schedule it with host/DBA tooling instead of wp-admin.' : $engine_note . ' Run during low traffic and with a current backup.',
                'requires_backup' => true,
                'args'      => array( 'table' => $table['name'] ),
            );
        }

        foreach ( array_slice( (array) ( $schema['duplicate_indexes'] ?? array() ), 0, 20 ) as $duplicate ) {
            $names = array_values( array_filter( array_map( 'strval', (array) ( $duplicate['indexes'] ?? array() ) ) ) );
            if ( count( $names ) < 2 ) { continue; }
            $table_name = (string) ( $duplicate['table'] ?? '' );
            $managed = self::managed_index_context( $table_name, $names );
            $keep = $names[0];
            if ( $managed && ! empty( $managed['preferred_keep'] ) ) { $keep = (string) $managed['preferred_keep']; }
            $drop_candidates = array_values( array_diff( $names, array( $keep ) ) );
            if ( ! $drop_candidates ) { continue; }
            $drop = $drop_candidates[ count( $drop_candidates ) - 1 ];
            if ( $managed ) {
                $managed_drop = array_values( array_intersect( $drop_candidates, (array) ( $managed['managed_names'] ?? array() ) ) );
                if ( $managed_drop ) { $drop = $managed_drop[ count( $managed_drop ) - 1 ]; }
            }
            $meta = self::table_meta( $health, $table_name );
            $large = self::is_large_meta( $meta );
            $detail = 'Equivalent indexes: ' . implode( ', ', $names ) . '. Proposed keep: ' . $keep . '; proposed drop: ' . $drop . '.';
            $reason = $large ? 'The table exceeds the normal browser DDL threshold. Performance Console provides both a guarded Maintenance Fix workflow and the equivalent CLI repair.' : 'Performance Console performs a fresh exact-signature comparison immediately before dropping the redundant non-primary index. Because plugins can refer to index names in migrations, this requires explicit acknowledgement and a current backup.';
            if ( $managed ) {
                $owner = (string) ( $managed['owner'] ?? 'Active plugin' );
                $canonical = (string) ( $managed['canonical'] ?? '' );
                $detail .= ' Owner: ' . $owner . '.';
                if ( $canonical ) { $detail .= ' Canonical index name: ' . $canonical . ( in_array( $canonical, $names, true ) ? ' (present).' : ' (not currently present).' ); }
                $reason = $owner . ' registered these index names with Performance Console. Performance Console keeps the canonical name when present; otherwise it keeps the plugin\'s highest-priority recognized legacy alias. The plugin should continue working because the retained index has the same verified definition.';
                if ( $large ) { $reason .= ' Because this is a large table, use the guarded Maintenance Fix workflow or CLI during a maintenance window.'; }
            }
            $plan = array(
                'id'        => 'duplicate-index-' . md5( wp_json_encode( $duplicate ) ),
                'title'     => $managed ? ( (string) ( $managed['owner'] ?? 'Plugin' ) . ' duplicate index cleanup' ) : ( 'Remove one exact duplicate index on ' . $table_name ),
                'action'    => 'drop_duplicate_index',
                'safety'    => $large ? 'cli-review' : 'high-review',
                'available' => ! $large,
                'detail'    => $detail,
                'reason'    => $reason,
                'requires_backup' => true,
                'requires_danger_ack' => true,
                'button_label' => 'Remove Duplicate Index',
                'args'      => array( 'table' => $table_name, 'index' => $drop, 'keep_index' => $keep ),
                'sql_preview' => 'ALTER TABLE `' . $table_name . '` DROP INDEX `' . $drop . '`',
            );
            if ( $managed ) {
                $canonical = (string) ( $managed['canonical'] ?? '' );
                $canonical_present = $canonical && in_array( $canonical, $names, true );
                $plan['presentation'] = 'managed_duplicate_index';
                $plan['managed_index'] = array(
                    'owner' => (string) ( $managed['owner'] ?? 'Active plugin' ),
                    'plugin' => (string) ( $managed['plugin'] ?? '' ),
                    'indexes' => $names,
                    'keep' => $keep,
                    'drop' => $drop,
                    'canonical' => $canonical,
                    'canonical_present' => (bool) $canonical_present,
                    'create_canonical_recommended' => false,
                    'functional_impact' => 'None expected',
                    'storage_write_impact' => 'Positive',
                    'confidence' => 'High',
                    'risk' => 'Low',
                    'large_table' => (bool) $large,
                );
            }
            $plans[] = $plan;
        }

        foreach ( (array) ( $schema['missing_core_tables'] ?? array() ) as $missing_table ) {
            $plans[] = array(
                'id'        => 'missing-core-table-' . md5( (string) $missing_table ),
                'title'     => 'Create missing WordPress core table schema: ' . (string) $missing_table,
                'action'    => 'create_missing_core_table',
                'safety'    => 'high-review',
                'available' => true,
                'detail'    => 'The table is absent. Performance Console can recreate the empty table using the installed WordPress version\'s own schema.',
                'reason'    => 'This restores schema only, not lost records. If the table previously contained data, restore that data from backup. Performance Console requires a verified backup and an explicit acknowledgement that an empty table may not recover missing content.',
                'requires_backup' => true,
                'requires_data_loss_ack' => true,
                'button_label' => 'Create Empty Core Table',
                'args'      => array( 'table' => (string) $missing_table ),
            );
        }

        return $plans;
    }

    public static function execute( $action, array $args = array(), $force_large = false ) {
        $action = sanitize_key( $action );
        switch ( $action ) {
            case 'cleanup_expired_transients':
                return self::cleanup_expired_transients( $args );
            case 'cleanup_orphans':
                return self::cleanup_orphans( $args );
            case 'set_autoload_off':
                return self::set_autoload( $args, false );
            case 'repair_core_schema':
                return self::repair_core_schema( $args, $force_large );
            case 'repair_core_column_drift':
                return self::repair_core_column_drift( $args, $force_large );
            case 'repair_core_index_drift':
                return self::repair_core_index_drift( $args, $force_large );
            case 'drop_duplicate_index':
                return self::drop_duplicate_index( $args, $force_large );
            case 'create_missing_core_table':
                return self::create_missing_core_table( $args );
            case 'repair_csv_table':
                return self::repair_csv_table( $args, $force_large );
            case 'check_table':
                return self::check_table( $args, $force_large );
            case 'repair_table':
                return self::repair_table( $args, $force_large );
            case 'optimize_table':
                return self::optimize_table( $args, $force_large );
            case 'analyze_table':
                return self::analyze_table( $args, $force_large );
            case 'innodb_recovery_preflight':
                return self::innodb_recovery_preflight( $args, $force_large );
            case 'rebuild_innodb_index':
                return self::rebuild_innodb_index( $args, $force_large );
            case 'rebuild_innodb_table':
                return self::rebuild_innodb_table( $args, $force_large );
            case 'terminate_innodb_transaction':
                return self::terminate_innodb_transaction( $args );
            case 'rollback_change':
                return self::rollback_change( $args );
            default:
                return new WP_Error( 'pfc_unknown_repair', 'Unknown database repair action.' );
        }
    }

    /**
     * Live InnoDB transaction inventory used by the Repair Centre. This deliberately
     * does not cache results: an administrator needs the current state before killing
     * a connection or retrying DDL.
     */
    public static function transaction_manager_snapshot() {
        global $wpdb;
        $runtime = PFC_Database_Health::innodb_runtime();
        $current_thread = 0;
        $current_database = '';
        $old = $wpdb->suppress_errors( true );
        $current_thread = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
        $current_database = sanitize_text_field( (string) $wpdb->get_var( 'SELECT DATABASE()' ) );
        $wpdb->suppress_errors( $old );

        $blocking = array();
        $waiting = array();
        foreach ( (array) ( $runtime['lock_graph']['edges'] ?? array() ) as $edge ) {
            $bid = (string) ( $edge['blocking_trx_id'] ?? '' );
            $rid = (string) ( $edge['requesting_trx_id'] ?? '' );
            if ( '' !== $bid ) { $blocking[ $bid ] = true; }
            if ( '' !== $rid ) { $waiting[ $rid ] = true; }
        }

        $transactions = array();
        foreach ( (array) ( $runtime['transactions'] ?? array() ) as $trx ) {
            $trx['is_current_connection'] = $current_thread > 0 && $current_thread === (int) ( $trx['thread_id'] ?? 0 );
            $process_db = trim( (string) ( $trx['process_db'] ?? '' ) );
            $trx['database_match'] = '' === $process_db || '' === $current_database ? null : 0 === strcasecmp( $process_db, $current_database );
            $trx['is_blocker'] = ! empty( $blocking[ (string) ( $trx['id'] ?? '' ) ] );
            $trx['is_waiting'] = ! empty( $waiting[ (string) ( $trx['id'] ?? '' ) ] ) || false !== stripos( (string) ( $trx['state'] ?? '' ), 'LOCK WAIT' );
            $risk = self::transaction_termination_risk( $trx );
            $trx['risk'] = $risk['level'];
            $trx['risk_message'] = $risk['message'];
            $trx['can_terminate'] = $risk['can_terminate'];
            $trx['requires_high_rollback_ack'] = $risk['requires_high_rollback_ack'];
            $trx['termination_reason'] = $risk['reason'];
            $transactions[] = $trx;
        }

        usort( $transactions, static function ( $a, $b ) {
            if ( ! empty( $a['is_blocker'] ) !== ! empty( $b['is_blocker'] ) ) { return ! empty( $a['is_blocker'] ) ? -1 : 1; }
            return (int) ( $b['age_seconds'] ?? 0 ) <=> (int) ( $a['age_seconds'] ?? 0 );
        } );

        return array(
            'available' => ! empty( $runtime['transactions_available'] ) || ! empty( $transactions ),
            'current_thread_id' => $current_thread,
            'current_database' => $current_database,
            'transactions' => $transactions,
            'lock_graph' => (array) ( $runtime['lock_graph'] ?? array( 'available' => false, 'edges' => array() ) ),
            'runtime' => $runtime,
            'message' => ! empty( $runtime['transactions_available'] ) ? 'Live InnoDB transactions are visible.' : 'The database account could not expose the InnoDB transaction list. PROCESS/Performance Schema privileges may be restricted by the host.',
        );
    }

    private static function transaction_termination_risk( array $trx ) {
        $age = max( 0, (int) ( $trx['age_seconds'] ?? 0 ) );
        $rows_modified = max( 0, (int) ( $trx['rows_modified'] ?? 0 ) );
        $rows_locked = max( 0, (int) ( $trx['rows_locked'] ?? 0 ) );
        $user = strtolower( trim( (string) ( $trx['process_user'] ?? '' ) ) );
        $command = strtolower( trim( (string) ( $trx['process_command'] ?? '' ) ) );
        $state = strtolower( trim( (string) ( $trx['state'] ?? '' ) ) );
        $is_blocker = ! empty( $trx['is_blocker'] );
        $is_waiting = ! empty( $trx['is_waiting'] );
        $is_current = ! empty( $trx['is_current_connection'] );
        $database_mismatch = isset( $trx['database_match'] ) && false === $trx['database_match'];
        $idle = 'sleep' === $command && '' === trim( (string) ( $trx['query'] ?? '' ) ) && '' === trim( (string) ( $trx['process_info'] ?? '' ) );
        $system_users = array( 'system user', 'event_scheduler', 'rdsadmin', 'mysql.session', 'mysql.sys' );
        $system_commands = array( 'daemon', 'binlog dump', 'binlog dump gtid', 'connect out' );
        $system = in_array( $user, $system_users, true ) || in_array( $command, $system_commands, true );
        $rolling_back = false !== strpos( $state, 'rolling back' ) || false !== strpos( strtolower( (string) ( $trx['process_state'] ?? '' ) ), 'rollback' );
        $problem_candidate = $age >= 30 || $is_blocker || $is_waiting;

        $can = $problem_candidate && ! $is_current && ! $database_mismatch && ! $system && ! $rolling_back && (int) ( $trx['thread_id'] ?? 0 ) > 0;
        $high = $rows_modified >= 10000 || $rows_locked >= 100000 || $age >= 1800;
        $level = $high ? 'high' : ( $rows_modified > 0 || $is_blocker || $is_waiting || ! $idle ? 'medium' : 'low' );

        if ( $is_current ) {
            $reason = 'Performance Console will never terminate the database connection that is rendering the Repair Centre.';
        } elseif ( $database_mismatch ) {
            $reason = 'This connection is attached to a different database. Performance Console will not terminate transactions outside the current WordPress database.';
        } elseif ( $system ) {
            $reason = 'This appears to be a database/server system session. Performance Console will not terminate system, replication or scheduler connections.';
        } elseif ( $rolling_back ) {
            $reason = 'This transaction already appears to be rolling back. Let rollback complete and refresh the status.';
        } elseif ( ! $problem_candidate ) {
            $reason = 'The transaction is below Performance Console\'s 30-second DDL-blocking threshold and is not currently a visible blocker/waiter.';
        } elseif ( ! (int) ( $trx['thread_id'] ?? 0 ) ) {
            $reason = 'No killable MySQL thread ID is visible for this transaction.';
        } else {
            $reason = 'Termination is available after explicit rollback acknowledgement.';
        }

        if ( $idle && 0 === $rows_modified ) {
            $message = 'Idle transaction with no modified rows visible. Terminating the connection should close the abandoned transaction without a large data rollback.';
        } elseif ( $rows_modified > 0 ) {
            $message = 'This transaction has modified approximately ' . number_format_i18n( $rows_modified ) . ' row(s). Killing the connection rolls back its uncommitted work, and a large rollback can take significant time.';
        } elseif ( $is_blocker ) {
            $message = 'This transaction is blocking at least one other InnoDB transaction. Terminating it can release the lock, but any uncommitted work will be rolled back.';
        } elseif ( $is_waiting ) {
            $message = 'This transaction is waiting on an InnoDB lock. Termination cancels its current transaction and rolls back uncommitted work.';
        } else {
            $message = 'This long-running transaction can retain metadata locks or an old read view. Termination interrupts the owning request/session.';
        }

        return array(
            'level' => $level,
            'message' => $message,
            'can_terminate' => $can,
            'requires_high_rollback_ack' => $high,
            'reason' => $reason,
        );
    }

    private static function terminate_innodb_transaction( array $args ) {
        global $wpdb;
        $thread_id = absint( $args['thread_id'] ?? 0 );
        $expected_transaction_id = sanitize_text_field( (string) ( $args['transaction_id'] ?? '' ) );
        if ( ! $thread_id ) { return new WP_Error( 'pfc_thread_id', 'A valid MySQL thread ID is required.' ); }
        if ( ! self::danger_confirmed( $args ) || empty( $args['rollback_confirmed'] ) ) {
            return new WP_Error( 'pfc_transaction_ack', 'Confirm that you understand terminating the connection interrupts its request and rolls back uncommitted work.' );
        }

        $snapshot = self::transaction_manager_snapshot();
        $target = null;
        foreach ( (array) ( $snapshot['transactions'] ?? array() ) as $trx ) {
            if ( $thread_id === (int) ( $trx['thread_id'] ?? 0 ) ) { $target = $trx; break; }
        }
        if ( ! $target ) {
            return array( 'ok' => true, 'message' => 'Thread ' . $thread_id . ' is no longer present in the InnoDB transaction list. No termination was needed.', 'thread_id' => $thread_id, 'already_gone' => true );
        }
        if ( $expected_transaction_id && 0 !== strcmp( $expected_transaction_id, (string) ( $target['id'] ?? '' ) ) ) {
            return new WP_Error( 'pfc_transaction_changed', 'The MySQL thread now belongs to a different InnoDB transaction. Performance Console refused to terminate it. Refresh the Transaction Manager and review the new transaction.', array( 'transaction' => $target ) );
        }
        if ( empty( $target['can_terminate'] ) ) {
            return new WP_Error( 'pfc_transaction_protected', (string) ( $target['termination_reason'] ?? 'Performance Console will not terminate this transaction.' ), array( 'transaction' => $target ) );
        }
        if ( ! empty( $target['requires_high_rollback_ack'] ) && empty( $args['high_rollback_confirmed'] ) ) {
            return new WP_Error( 'pfc_large_rollback_ack', 'This is a high-risk rollback because of its age or row count. Confirm the high-risk rollback acknowledgement before terminating it.', array( 'transaction' => $target ) );
        }

        $old = $wpdb->suppress_errors( true );
        $result = $wpdb->query( 'KILL CONNECTION ' . $thread_id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- absint thread ID.
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( false === $result ) {
            $message = $error ? sanitize_text_field( $error ) : 'The database server rejected KILL CONNECTION.';
            return new WP_Error( 'pfc_kill_failed', $message . ' The WordPress database user may not have permission to terminate this connection.', array( 'transaction' => $target ) );
        }

        $audit = array(
            'transaction_id' => (string) ( $target['id'] ?? '' ),
            'age_seconds' => (int) ( $target['age_seconds'] ?? 0 ),
            'rows_locked' => (int) ( $target['rows_locked'] ?? 0 ),
            'rows_modified' => (int) ( $target['rows_modified'] ?? 0 ),
            'risk' => (string) ( $target['risk'] ?? '' ),
            'was_blocker' => ! empty( $target['is_blocker'] ),
            'normalized_query' => (string) ( ( $target['query'] ?? '' ) ?: ( $target['process_info'] ?? '' ) ),
        );
        self::record_change( 'db_innodb_transaction_terminated', 'thread:' . $thread_id, wp_json_encode( $audit ), 'KILL CONNECTION requested' );
        usleep( 200000 );
        $after = self::transaction_manager_snapshot();
        $still_visible = null;
        foreach ( (array) ( $after['transactions'] ?? array() ) as $trx ) {
            if ( $thread_id === (int) ( $trx['thread_id'] ?? 0 ) ) { $still_visible = $trx; break; }
        }

        if ( $still_visible ) {
            return array(
                'ok' => true,
                'thread_id' => $thread_id,
                'rollback_pending' => true,
                'transaction' => $still_visible,
                'message' => 'Termination was requested for MySQL thread ' . $thread_id . ', but the InnoDB transaction is still visible. A rollback may be in progress; refresh the Transaction Manager before retrying DDL.',
            );
        }

        return array(
            'ok' => true,
            'thread_id' => $thread_id,
            'rollback_pending' => false,
            'message' => 'Terminated MySQL thread ' . $thread_id . '. Its InnoDB transaction is no longer visible. Re-run the blocked database repair after refreshing transaction status.',
        );
    }

    private static function cleanup_expired_transients( array $args ) {
        global $wpdb;
        $limit = max( 1, min( self::MAX_BATCH, absint( $args['limit'] ?? 500 ) ) );
        $like = $wpdb->esc_like( '_transient_timeout_' ) . '%';
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d", $like, time(), $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = 0;
        foreach ( (array) $rows as $timeout_name ) {
            $name = substr( (string) $timeout_name, strlen( '_transient_timeout_' ) );
            if ( '' === $name ) { continue; }
            delete_transient( $name );
            $deleted++;
        }
        self::record_change( 'db_cleanup_transients', 'transients', '', (string) $deleted );
        return array( 'ok' => true, 'deleted' => $deleted, 'message' => 'Deleted ' . $deleted . ' expired transient(s).' );
    }

    private static function cleanup_orphans( array $args ) {
        global $wpdb;
        $type = sanitize_key( $args['type'] ?? '' );
        $limit = max( 1, min( self::MAX_BATCH, absint( $args['limit'] ?? 500 ) ) );

        if ( 'term_relationships' === $type ) {
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.* FROM {$wpdb->term_relationships} r LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE tt.term_taxonomy_id IS NULL ORDER BY r.object_id ASC,r.term_taxonomy_id ASC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( ! $rows ) { return array( 'ok' => true, 'deleted' => 0, 'message' => 'No orphaned term relationships remain.' ); }
            $backup = self::encode_backup( $wpdb->term_relationships, $rows );
            if ( is_wp_error( $backup ) ) { return $backup; }
            $tuples = array(); $values = array();
            foreach ( $rows as $row ) {
                $tuples[] = '(%d,%d)';
                $values[] = absint( $row['object_id'] ?? 0 );
                $values[] = absint( $row['term_taxonomy_id'] ?? 0 );
            }
            $sql = "DELETE FROM {$wpdb->term_relationships} WHERE (object_id,term_taxonomy_id) IN (" . implode( ',', $tuples ) . ')';
            $deleted = (int) $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            self::record_change( 'db_cleanup_orphans', $type, $backup, wp_json_encode( array( 'deleted' => $deleted ) ) );
            return array( 'ok' => true, 'deleted' => $deleted, 'message' => 'Deleted ' . $deleted . ' orphaned term relationship(s). A bounded rollback snapshot was stored.' );
        }

        $map = array(
            'postmeta' => array( 'table' => $wpdb->postmeta, 'id' => 'meta_id', 'sql' => "SELECT m.* FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.ID IS NULL ORDER BY m.meta_id ASC LIMIT %d" ),
            'commentmeta' => array( 'table' => $wpdb->commentmeta, 'id' => 'meta_id', 'sql' => "SELECT m.* FROM {$wpdb->commentmeta} m LEFT JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id WHERE c.comment_ID IS NULL ORDER BY m.meta_id ASC LIMIT %d" ),
            'usermeta' => array( 'table' => $wpdb->usermeta, 'id' => 'umeta_id', 'sql' => "SELECT m.* FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE u.ID IS NULL ORDER BY m.umeta_id ASC LIMIT %d" ),
        );
        if ( isset( $wpdb->termmeta ) ) {
            $map['termmeta'] = array( 'table' => $wpdb->termmeta, 'id' => 'meta_id', 'sql' => "SELECT m.* FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} t ON t.term_id=m.term_id WHERE t.term_id IS NULL ORDER BY m.meta_id ASC LIMIT %d" );
        }
        if ( ! isset( $map[ $type ] ) ) { return new WP_Error( 'pfc_orphan_type', 'Unsupported orphan cleanup type.' ); }
        $spec = $map[ $type ];
        $rows = $wpdb->get_results( $wpdb->prepare( $spec['sql'], $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! $rows ) { return array( 'ok' => true, 'deleted' => 0, 'message' => 'No orphan rows remain for ' . $type . '.' ); }
        $backup = self::encode_backup( $spec['table'], $rows );
        if ( is_wp_error( $backup ) ) { return $backup; }
        $idcol = self::safe_identifier( $spec['id'] );
        $table = self::safe_table( $spec['table'] );
        if ( ! $table || ! $idcol ) { return new WP_Error( 'pfc_orphan_identifier', 'Unsafe database identifier.' ); }
        $ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, $idcol ) ) ) );
        if ( ! $ids ) { return new WP_Error( 'pfc_orphan_ids', 'No valid orphan identifiers were returned.' ); }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "DELETE FROM `{$table}` WHERE `{$idcol}` IN ({$placeholders})";
        $deleted = (int) $wpdb->query( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        self::record_change( 'db_cleanup_orphans', $type, $backup, wp_json_encode( array( 'deleted' => $deleted ) ) );
        return array( 'ok' => true, 'deleted' => $deleted, 'message' => 'Deleted ' . $deleted . ' orphaned ' . $type . ' row(s). A bounded rollback snapshot was stored.' );
    }

    private static function set_autoload( array $args, $autoload ) {
        $name = sanitize_text_field( (string) ( $args['option'] ?? '' ) );
        if ( '' === $name || self::protected_option( $name ) ) { return new WP_Error( 'pfc_protected_option', 'That option is protected from automatic autoload changes.' ); }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT autoload,LENGTH(option_value) bytes FROM {$wpdb->options} WHERE option_name=%s", $name ), ARRAY_A );
        if ( ! $row ) { return new WP_Error( 'pfc_option_missing', 'Option not found.' ); }
        $before = (string) $row['autoload'];
        if ( function_exists( 'wp_set_option_autoload' ) ) {
            $ok = wp_set_option_autoload( $name, (bool) $autoload );
        } else {
            $ok = false !== $wpdb->update( $wpdb->options, array( 'autoload' => $autoload ? 'yes' : 'no' ), array( 'option_name' => $name ) );
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( $name, 'options' );
        }
        if ( ! $ok ) { return new WP_Error( 'pfc_autoload_failed', 'WordPress did not modify the autoload flag.' ); }
        self::record_change( 'autoload', $name, $before, $autoload ? 'on' : 'off' );
        return array( 'ok' => true, 'message' => 'Updated autoload for ' . $name . '.', 'bytes' => (int) $row['bytes'] );
    }

    private static function repair_core_schema( array $args, $force_large ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm that a current database backup or snapshot has been verified before changing WordPress core schema.' ); }
        if ( ! self::danger_confirmed( $args ) ) { return new WP_Error( 'pfc_schema_ack', 'Confirm that you reviewed the WordPress core schema changes and understand that ALTER TABLE can rewrite data or indexes.' ); }

        $health = PFC_Database_Health::inspect( false, false );
        $missing_columns = (array) ( $health['schema']['missing_core_columns'] ?? array() );
        $missing_indexes = (array) ( $health['schema']['missing_core_indexes'] ?? array() );
        if ( ! $missing_columns && ! $missing_indexes ) { return array( 'ok' => true, 'changed' => 0, 'skipped' => array(), 'message' => 'No missing core columns or indexes were detected.' ); }

        $changed = 0; $skipped = array(); $errors = array();

        // Pass 1: create genuinely missing columns first. Index creation depends on them.
        foreach ( $missing_columns as $item ) {
            $table = self::safe_table( (string) ( $item['table'] ?? '' ) );
            $column = self::safe_identifier( (string) ( $item['column'] ?? '' ) );
            $definition = trim( (string) ( $item['definition'] ?? '' ) );
            if ( ! $table || ! $column || ! self::safe_core_definition( $definition ) ) { $skipped[] = array( 'item' => $item, 'reason' => 'Unsafe or unavailable column definition.' ); continue; }

            // Do not create a missing AUTO_INCREMENT primary-key column by itself. MySQL
            // requires an AUTO_INCREMENT column to be keyed, so add the column and missing
            // PRIMARY KEY atomically in pass 2 when that dependency exists.
            if ( false !== stripos( $definition, 'auto_increment' ) ) {
                $expected_primary = PFC_Database_Health::expected_core_index_definition( $table, 'PRIMARY' );
                $primary_columns = $expected_primary ? self::index_columns_from_definition( $expected_primary ) : array();
                $missing_primary = false;
                foreach ( $missing_indexes as $index_item ) {
                    if ( $table === (string) ( $index_item['table'] ?? '' ) && 'PRIMARY' === strtoupper( (string) ( $index_item['index'] ?? '' ) ) ) { $missing_primary = true; break; }
                }
                if ( $missing_primary && ! is_wp_error( $primary_columns ) && in_array( $column, $primary_columns, true ) ) { continue; }
            }

            $meta = self::table_meta( $health, $table );
            if ( self::is_large_meta( $meta ) && ! $force_large ) { $skipped[] = array( 'item' => $item, 'reason' => 'Table exceeds safety threshold.' ); continue; }
            if ( 0 === strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) {
                $busy = self::innodb_busy_preflight();
                if ( is_wp_error( $busy ) ) { return $busy; }
            }
            $sql = "ALTER TABLE `{$table}` ADD COLUMN {$definition}";
            $old = $wpdb->suppress_errors( true );
            $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $error = $wpdb->last_error;
            $wpdb->suppress_errors( $old );
            if ( false === $result ) { $errors[] = array( 'item' => $item, 'error' => sanitize_text_field( $error ) ); continue; }
            $changed++;
            self::record_change( 'db_core_schema_repair', $table, '', $definition );
        }

        // Refresh after adding columns so PRIMARY KEY dependency preflights use the live schema.
        $health = PFC_Database_Health::inspect( false, false );

        // Pass 2: add indexes. A missing PRIMARY KEY may depend on correcting a nullable or
        // otherwise drifted core column. Perform those dependent MODIFY clauses in the same
        // ALTER so AUTO_INCREMENT and PRIMARY KEY can become valid atomically.
        foreach ( $missing_indexes as $item ) {
            $table = self::safe_table( (string) ( $item['table'] ?? '' ) );
            $index = (string) ( $item['index'] ?? '' );
            $definition = trim( (string) ( $item['definition'] ?? '' ) );
            if ( ! $table || ! self::safe_core_definition( $definition ) ) { $skipped[] = array( 'item' => $item, 'reason' => 'Unsafe or unavailable index definition.' ); continue; }
            $meta = self::table_meta( $health, $table );
            if ( self::is_large_meta( $meta ) && ! $force_large ) { $skipped[] = array( 'item' => $item, 'reason' => 'Table exceeds safety threshold.' ); continue; }

            $is_primary = 'PRIMARY' === strtoupper( $index ) || 0 === stripos( $definition, 'PRIMARY KEY' );
            $is_unique = $is_primary || 0 === stripos( $definition, 'UNIQUE KEY' );

            if ( $is_primary ) {
                // PRIMARY KEY repair must include any prerequisite NOT NULL/type/AUTO_INCREMENT
                // corrections in this same ALTER. Treating the key and its columns as separate
                // repairs can produce MySQL error 1171 (PRIMARY KEY columns must be NOT NULL).
                $prepared = self::primary_key_repair_clauses( $table, $definition, false );
                if ( is_wp_error( $prepared ) ) {
                    $errors[] = array( 'item' => $item, 'error' => $prepared->get_error_message() );
                    continue;
                }
                $clauses = (array) ( $prepared['clauses'] ?? array() );
            } else {
                if ( $is_unique ) {
                    $dupes = self::index_has_duplicates( $table, $definition );
                    if ( is_wp_error( $dupes ) ) { $skipped[] = array( 'item' => $item, 'reason' => $dupes->get_error_message() ); continue; }
                    if ( $dupes ) { $skipped[] = array( 'item' => $item, 'reason' => 'Duplicate values prevent adding the unique key safely.' ); continue; }
                }
                $clauses = array( 'ADD ' . $definition );
            }

            $sql = 'ALTER TABLE `' . $table . '` ' . implode( ', ', $clauses );
            if ( 0 === strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) {
                $busy = self::innodb_busy_preflight();
                if ( is_wp_error( $busy ) ) { return $busy; }
            }
            $old = $wpdb->suppress_errors( true );
            $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $error = $wpdb->last_error;
            $wpdb->suppress_errors( $old );
            if ( false === $result ) { $errors[] = array( 'item' => $item, 'error' => sanitize_text_field( $error ) ); continue; }
            $changed++;
            self::record_change( 'db_core_schema_repair', $table, '', implode( '; ', $clauses ) );
        }

        $message = 'Applied ' . $changed . ' core schema repair(s); skipped ' . count( $skipped ) . '; errors ' . count( $errors ) . '.';
        if ( $errors ) { $message .= ' First error: ' . (string) ( $errors[0]['error'] ?? 'Unknown schema error.' ); }
        elseif ( $skipped ) { $message .= ' First skipped item: ' . (string) ( $skipped[0]['reason'] ?? 'Review required.' ); }
        return array( 'ok' => empty( $errors ), 'changed' => $changed, 'skipped' => $skipped, 'errors' => $errors, 'message' => $message );
    }

    private static function repair_core_column_drift( array $args, $force_large ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm a current database backup/snapshot before correcting a core column.' ); }
        if ( ! self::danger_confirmed( $args ) ) { return new WP_Error( 'pfc_schema_ack', 'Confirm that you reviewed the schema change and understand MODIFY COLUMN can rewrite or reject existing data.' ); }
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        $column = self::safe_identifier( (string) ( $args['column'] ?? '' ) );
        if ( ! $table || ! $column ) { return new WP_Error( 'pfc_schema_target', 'Invalid table or column.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $match = null;
        foreach ( (array) ( $health['schema']['mismatched_core_columns'] ?? array() ) as $item ) {
            if ( $table === (string) ( $item['table'] ?? '' ) && $column === (string) ( $item['column'] ?? '' ) ) { $match = $item; break; }
        }
        if ( ! $match ) { return array( 'ok' => true, 'message' => 'The selected core column no longer differs from the installed WordPress schema.' ); }
        $definition = trim( (string) ( $match['definition'] ?? '' ) );
        if ( ! self::safe_core_definition( $definition ) ) { return new WP_Error( 'pfc_schema_definition', 'The expected WordPress column definition could not be validated.' ); }
        $meta = self::table_meta( $health, $table );
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal browser schema-change threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large during a maintenance window.' ); }
        $engine = strtolower( (string) ( $meta['engine'] ?? '' ) );

        // Build the complete live-to-core ALTER plan before changing anything. A core column
        // can be part of a *malformed live* PRIMARY KEY even when WordPress does not expect that
        // column in the PRIMARY KEY. For example, a modified wp_usermeta table may have
        // PRIMARY KEY (umeta_id, meta_key). WordPress expects meta_key to allow NULL, and MySQL
        // will reject MODIFY meta_key ... NULL until that malformed PRIMARY KEY is replaced.
        // The planner therefore considers both the actual live PRIMARY KEY and WordPress' expected
        // PRIMARY KEY, and performs dependent changes atomically.
        $alter_plan = self::core_column_alter_plan( $table, $column, $definition, $health, true );
        if ( is_wp_error( $alter_plan ) ) { return $alter_plan; }
        $sql = (string) ( $alter_plan['sql'] ?? '' );
        if ( '' === $sql ) { return new WP_Error( 'pfc_schema_plan', 'Performance Console could not build a safe core-column repair plan.' ); }
        if ( 'innodb' === $engine ) {
            $busy = self::innodb_busy_preflight();
            if ( is_wp_error( $busy ) ) { return $busy; }
            $sql .= ', ALGORITHM=INPLACE, LOCK=NONE';
            $result = self::execute_online_ddl( $sql );
        } else {
            $old = $wpdb->suppress_errors( true );
            $result_raw = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $error = $wpdb->last_error;
            $wpdb->suppress_errors( $old );
            $result = false === $result_raw ? new WP_Error( 'pfc_schema_alter_failed', $error ? sanitize_text_field( $error ) : 'Database rejected the column correction.' ) : true;
        }
        if ( is_wp_error( $result ) ) { return $result; }
        $fresh = PFC_Database_Health::inspect( false, false );
        foreach ( (array) ( $fresh['schema']['mismatched_core_columns'] ?? array() ) as $item ) {
            if ( $table === (string) ( $item['table'] ?? '' ) && $column === (string) ( $item['column'] ?? '' ) ) { return new WP_Error( 'pfc_schema_verify', 'ALTER completed, but the column still differs from the installed WordPress schema.' ); }
        }
        self::record_change( 'db_core_column_correction', $table . '.' . $column, wp_json_encode( $match['drift'] ?? array() ), $definition );
        return array( 'ok' => true, 'message' => 'Corrected and verified core column ' . $table . '.' . $column . '.', 'ddl' => $sql );
    }

    private static function repair_core_index_drift( array $args, $force_large ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm a current database backup/snapshot before rebuilding a core index.' ); }
        if ( ! self::danger_confirmed( $args ) ) { return new WP_Error( 'pfc_schema_ack', 'Confirm that you reviewed the index replacement and understand it changes live table schema.' ); }
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        $index = self::safe_identifier( (string) ( $args['index'] ?? '' ) );
        if ( ! $table || ! $index ) { return new WP_Error( 'pfc_schema_target', 'Invalid table or index.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $match = null;
        foreach ( (array) ( $health['schema']['mismatched_core_indexes'] ?? array() ) as $item ) {
            if ( $table === (string) ( $item['table'] ?? '' ) && 0 === strcasecmp( $index, (string) ( $item['index'] ?? '' ) ) ) { $match = $item; break; }
        }
        if ( ! $match ) { return array( 'ok' => true, 'message' => 'The selected core index no longer differs from the installed WordPress schema.' ); }
        $definition = trim( (string) ( $match['definition'] ?? '' ) );
        if ( ! self::safe_core_definition( $definition ) ) { return new WP_Error( 'pfc_schema_definition', 'The expected WordPress index definition could not be validated.' ); }
        $is_primary = 'PRIMARY' === strtoupper( $index ) || 0 === stripos( $definition, 'PRIMARY KEY' );
        $is_unique = $is_primary || 0 === stripos( $definition, 'UNIQUE KEY' );
        if ( $is_unique && ! $is_primary ) {
            $dupes = self::index_has_duplicates( $table, $definition );
            if ( is_wp_error( $dupes ) ) { return $dupes; }
            if ( $dupes ) { return new WP_Error( 'pfc_unique_duplicates', 'Duplicate values prevent the expected unique index from being created safely.' ); }
        }
        $meta = self::table_meta( $health, $table );
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal browser DDL threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large.' ); }

        if ( $is_primary ) {
            // A PRIMARY KEY replacement must repair prerequisite key columns in the same ALTER.
            // Otherwise MySQL can reject a perfectly valid WordPress target schema because the
            // live key column is still nullable or lacks its expected AUTO_INCREMENT/type flags.
            $prepared = self::primary_key_repair_clauses( $table, $definition, true );
            if ( is_wp_error( $prepared ) ) { return $prepared; }
            $clauses = (array) ( $prepared['clauses'] ?? array() );
            $sql = 'ALTER TABLE `' . $table . '` ' . implode( ', ', $clauses );
        } else {
            $drop = 'DROP INDEX `' . $index . '`';
            $sql = 'ALTER TABLE `' . $table . '` ' . $drop . ', ADD ' . $definition;
        }
        if ( 0 === strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) {
            $busy = self::innodb_busy_preflight();
            if ( is_wp_error( $busy ) ) { return $busy; }
            $sql .= ', ALGORITHM=INPLACE, LOCK=NONE';
            $result = self::execute_online_ddl( $sql );
        } else {
            $old = $wpdb->suppress_errors( true );
            $raw = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $error = $wpdb->last_error;
            $wpdb->suppress_errors( $old );
            $result = false === $raw ? new WP_Error( 'pfc_index_alter_failed', $error ? sanitize_text_field( $error ) : 'Database rejected the index replacement.' ) : true;
        }
        if ( is_wp_error( $result ) ) { return $result; }
        $fresh = PFC_Database_Health::inspect( false, false );
        foreach ( (array) ( $fresh['schema']['mismatched_core_indexes'] ?? array() ) as $item ) {
            if ( $table === (string) ( $item['table'] ?? '' ) && 0 === strcasecmp( $index, (string) ( $item['index'] ?? '' ) ) ) { return new WP_Error( 'pfc_index_verify', 'ALTER completed, but the core index still differs from the expected definition.' ); }
        }
        self::record_change( 'db_core_index_correction', $table . '.' . $index, (string) ( $match['actual'] ?? '' ), $definition );
        return array( 'ok' => true, 'message' => 'Rebuilt and verified core index ' . $table . '.' . $index . '.', 'ddl' => $sql );
    }

    private static function drop_duplicate_index( array $args, $force_large ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm a current database backup/snapshot before dropping an index.' ); }
        if ( ! self::danger_confirmed( $args ) ) { return new WP_Error( 'pfc_index_ack', 'Confirm that you reviewed the redundant index names and understand plugin migrations may refer to them by name.' ); }
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        $drop = self::safe_identifier( (string) ( $args['index'] ?? '' ) );
        $keep = self::safe_identifier( (string) ( $args['keep_index'] ?? '' ) );
        if ( ! $table || ! $drop || ! $keep || 'PRIMARY' === strtoupper( $drop ) || 0 === strcasecmp( $drop, $keep ) ) { return new WP_Error( 'pfc_duplicate_index', 'Invalid duplicate-index selection.' ); }
        $drop_rows = self::index_rows( $table, $drop );
        $keep_rows = self::index_rows( $table, $keep );
        if ( ! $drop_rows || ! $keep_rows || self::index_signature_from_rows( $drop_rows ) !== self::index_signature_from_rows( $keep_rows ) ) { return new WP_Error( 'pfc_duplicate_changed', 'The indexes are no longer exact duplicates. Nothing was dropped.' ); }
        $managed = self::managed_index_context( $table, array( $drop, $keep ) );
        if ( $managed && ! empty( $managed['preferred_keep'] ) && 0 !== strcasecmp( $keep, (string) $managed['preferred_keep'] ) ) {
            return new WP_Error( 'pfc_managed_index_keep', 'The selected keep/drop direction no longer matches the active plugin\'s managed-index preference. Refresh the Database Repair Centre before continuing.' );
        }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal browser DDL threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large.' ); }
        $sql = 'ALTER TABLE `' . $table . '` DROP INDEX `' . $drop . '`';
        if ( 0 === strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) {
            $busy = self::innodb_busy_preflight(); if ( is_wp_error( $busy ) ) { return $busy; }
            $sql .= ', ALGORITHM=INPLACE, LOCK=NONE';
            $result = self::execute_online_ddl( $sql );
        } else {
            $old = $wpdb->suppress_errors( true ); $raw = $wpdb->query( $sql ); $error = $wpdb->last_error; $wpdb->suppress_errors( $old ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = false === $raw ? new WP_Error( 'pfc_drop_index_failed', $error ? sanitize_text_field( $error ) : 'Database rejected the index drop.' ) : true;
        }
        if ( is_wp_error( $result ) ) { return $result; }
        if ( self::index_rows( $table, $drop ) || ! self::index_rows( $table, $keep ) ) { return new WP_Error( 'pfc_drop_index_verify', 'Post-change verification failed for the duplicate-index cleanup.' ); }
        self::record_change( 'db_drop_duplicate_index', $table . '.' . $drop, 'duplicate of ' . $keep, $sql );
        return array( 'ok' => true, 'message' => 'Dropped redundant index ' . $table . '.' . $drop . ' and verified ' . $keep . ' remains.', 'ddl' => $sql );
    }

    private static function create_missing_core_table( array $args ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm a current database backup/snapshot before creating a missing core table.' ); }
        if ( ! self::data_loss_confirmed( $args ) ) { return new WP_Error( 'pfc_data_loss_ack', 'Confirm that you understand creating an empty table restores schema only and does not restore missing records.' ); }
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        if ( ! in_array( $table, (array) ( $health['schema']['missing_core_tables'] ?? array() ), true ) ) { return array( 'ok' => true, 'message' => 'The selected core table is no longer missing.' ); }
        $sql = self::core_create_table_sql( $table );
        if ( is_wp_error( $sql ) ) { return $sql; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) { return new WP_Error( 'pfc_create_core_table', 'WordPress dbDelta did not create the missing core table.' ); }
        self::record_change( 'db_create_core_table', $table, 'missing', 'created empty schema from installed WordPress core' );
        return array( 'ok' => true, 'message' => 'Created empty WordPress core table ' . $table . '. Restore any previously lost records from backup if applicable.' );
    }

    private static function repair_csv_table( array $args, $force_large ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm a current verified backup/export before CSV repair.' ); }
        if ( ! self::data_loss_confirmed( $args ) ) { return new WP_Error( 'pfc_data_loss_ack', 'Confirm that CSV repair may discard rows after the first damaged record.' ); }
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta || 0 !== strcasecmp( (string) ( $meta['engine'] ?? '' ), 'CSV' ) ) { return new WP_Error( 'pfc_not_csv', 'The selected table is not using the CSV engine.' ); }
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'CSV table exceeds the normal browser repair threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large.' ); }
        $rows = $wpdb->get_results( 'REPAIR TABLE `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! self::table_operation_ok( $rows ) ) { return new WP_Error( 'pfc_csv_repair_failed', 'CSV REPAIR TABLE did not report success.' ); }
        $check = $wpdb->get_results( 'CHECK TABLE `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! self::table_operation_ok( $check ) ) { return new WP_Error( 'pfc_csv_verify_failed', 'CSV repair completed but CHECK TABLE still reports a problem.' ); }
        self::record_change( 'db_repair_csv', $table, '', 'REPAIR TABLE' );
        return array( 'ok' => true, 'message' => 'CSV table repair completed and passed CHECK TABLE. Compare row counts with the verified export/backup.', 'rows' => $rows );
    }

    private static function check_table( array $args, $force_large ) {
        global $wpdb;
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta ) { return new WP_Error( 'pfc_table_missing', 'Table was not found.' ); }
        $engine = strtolower( (string) ( $meta['engine'] ?? '' ) );
        $caps = self::engine_capabilities_for_health( $health, $engine );
        if ( empty( $caps['check'] ) ) { return new WP_Error( 'pfc_check_engine', 'Performance Console does not have a verified CHECK TABLE path for the ' . ( $meta['engine'] ?? $engine ) . ' storage engine.' ); }
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the web integrity-check threshold. Use a deep CLI scan with --force-large if intentional.' ); }
        $old = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results( "CHECK TABLE `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( ! $rows ) { return new WP_Error( 'pfc_check_failed', $error ? sanitize_text_field( $error ) : 'Database did not return a CHECK TABLE result.' ); }
        $ok = self::table_operation_ok( $rows );
        return array( 'ok' => $ok, 'rows' => $rows, 'engine' => $meta['engine'], 'message' => $ok ? 'Integrity check completed for ' . $table . ' (' . $meta['engine'] . ').' : 'Integrity check reported a problem for ' . $table . ' (' . $meta['engine'] . ').' );
    }

    private static function repair_table( array $args, $force_large ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm that a current database backup or snapshot has been verified before repairing a table.' ); }
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta ) { return new WP_Error( 'pfc_table_missing', 'Table was not found.' ); }
        $engine = strtolower( (string) ( $meta['engine'] ?? '' ) );
        $caps = self::engine_capabilities_for_health( $health, $engine );
        if ( 'innodb' === $engine ) { return new WP_Error( 'pfc_repair_innodb', 'InnoDB does not support REPAIR TABLE. Use CHECK TABLE plus InnoDB status/error logs, then recover with backup/rebuild/engine recovery tooling as appropriate.' ); }
        if ( 'csv' === $engine ) { return new WP_Error( 'pfc_repair_csv', 'CSV repair can discard rows after the first damaged record, so Performance Console will not run it automatically. Export/backup the file/table and perform a deliberate recovery instead.' ); }
        if ( empty( $caps['repair'] ) ) { return new WP_Error( 'pfc_repair_engine', 'Performance Console does not have a verified automatic REPAIR TABLE path for the ' . ( $meta['engine'] ?? $engine ) . ' storage engine.' ); }
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal web repair safety threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large during maintenance.' ); }
        $suffix = in_array( $engine, array( 'myisam', 'aria' ), true ) ? ' QUICK' : '';
        $rows = $wpdb->get_results( "REPAIR TABLE `{$table}`{$suffix}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ok = self::table_operation_ok( $rows );
        if ( $ok ) { self::record_change( 'db_repair_table', $table, '', 'REPAIR TABLE' . $suffix . ' (' . $meta['engine'] . ')' ); }
        return $ok ? array( 'ok' => true, 'rows' => $rows, 'engine' => $meta['engine'], 'message' => 'Database repair completed for ' . $table . ' (' . $meta['engine'] . ').' ) : new WP_Error( 'pfc_repair_failed', 'Database did not report a successful ' . $meta['engine'] . ' table repair.' );
    }

    private static function optimize_table( array $args, $force_large ) {
        global $wpdb;
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm that a current database backup or snapshot has been verified before optimizing/rebuilding a table.' ); }
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta ) { return new WP_Error( 'pfc_table_missing', 'Table was not found.' ); }
        $engine = strtolower( (string) ( $meta['engine'] ?? '' ) );
        $caps = self::engine_capabilities_for_health( $health, $engine );
        if ( empty( $caps['optimize'] ) ) { return new WP_Error( 'pfc_optimize_engine', 'Performance Console does not have a verified OPTIMIZE TABLE path for the ' . ( $meta['engine'] ?? $engine ) . ' storage engine.' ); }
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal web optimize safety threshold. Use the guided Maintenance Fix workflow, host/DBA tooling, or WP-CLI with --force-large.' ); }
        $rows = $wpdb->get_results( "OPTIMIZE TABLE `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ok = self::table_operation_ok( $rows );
        if ( $ok ) { self::record_change( 'db_optimize_table', $table, '', 'OPTIMIZE TABLE (' . $meta['engine'] . ')' ); }
        return $ok ? array( 'ok' => true, 'rows' => $rows, 'engine' => $meta['engine'], 'message' => 'Database optimize/rebuild completed for ' . $table . ' (' . $meta['engine'] . ').' ) : new WP_Error( 'pfc_optimize_failed', 'Database did not report a successful optimize operation for ' . $meta['engine'] . '.' );
    }

    private static function analyze_table( array $args, $force_large ) {
        global $wpdb;
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta ) { return new WP_Error( 'pfc_table_missing', 'Table was not found.' ); }
        $engine = strtolower( (string) ( $meta['engine'] ?? '' ) );
        $caps = self::engine_capabilities_for_health( $health, $engine );
        if ( empty( $caps['analyze'] ) ) { return new WP_Error( 'pfc_analyze_engine', 'Performance Console does not have a verified ANALYZE TABLE path for the ' . ( $meta['engine'] ?? $engine ) . ' storage engine.' ); }
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal web analyze safety threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large if intentional.' ); }
        $rows = $wpdb->get_results( "ANALYZE TABLE `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ok = self::table_operation_ok( $rows );
        if ( $ok ) { self::record_change( 'db_analyze_table', $table, '', 'ANALYZE TABLE (' . $meta['engine'] . ')' ); }
        return $ok ? array( 'ok' => true, 'rows' => $rows, 'engine' => $meta['engine'], 'message' => 'Database statistics refreshed for ' . $table . ' (' . $meta['engine'] . ').' ) : new WP_Error( 'pfc_analyze_failed', 'Database did not report a successful analyze operation for ' . $meta['engine'] . '.' );
    }

    private static function innodb_recovery_preflight( array $args, $force_large = false ) {
        global $wpdb;
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta ) { return new WP_Error( 'pfc_table_missing', 'Table was not found.' ); }
        if ( 0 !== strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) { return new WP_Error( 'pfc_not_innodb', 'Guided InnoDB recovery is only available for InnoDB tables.' ); }

        $check = self::check_table( array( 'table' => $table ), $force_large );
        $indexes = self::index_inventory( $table );
        $runtime = PFC_Database_Health::innodb_runtime();
        $ddl = PFC_Database_Health::online_ddl_capabilities( $wpdb->db_version(), (string) ( $health['server']['storage_engines']['family'] ?? 'mysql' ) );
        $disk_index = self::disk_space_preflight( $meta, 'index' );
        $disk_table = self::disk_space_preflight( $meta, 'table' );

        $old = $wpdb->suppress_errors( true );
        $foreign_keys = $wpdb->get_results( $wpdb->prepare(
            'SELECT CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME=%s OR REFERENCED_TABLE_NAME=%s) AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 50',
            $table, $table
        ), ARRAY_A );
        $wpdb->suppress_errors( $old );

        $warnings = array();
        if ( self::is_large_meta( $meta ) && ! $force_large ) { $warnings[] = 'The table exceeds the normal wp-admin DDL threshold. Mutating rebuild operations require the guarded maintenance-window workflow or WP-CLI.'; }
        if ( empty( $ddl['inplace'] ) ) { $warnings[] = 'Performance Console cannot verify an online INPLACE/LOCK=NONE path for this database version, so it will not offer an automatic InnoDB rebuild.'; }
        if ( ! empty( $runtime['lock_graph']['edges'] ) ) { $warnings[] = 'Active InnoDB lock waits are visible. Resolve blockers before attempting DDL.'; }
        foreach ( (array) ( $runtime['transactions'] ?? array() ) as $trx ) {
            if ( (int) ( $trx['age_seconds'] ?? 0 ) >= 30 ) { $warnings[] = 'A transaction has been open for at least 30 seconds. Resolve long transactions before DDL to reduce metadata-lock risk.'; break; }
        }
        $force_recovery = (int) ( $health['server']['variables']['innodb_force_recovery'] ?? 0 );
        if ( $force_recovery > 0 ) { $warnings[] = 'innodb_force_recovery is enabled. Do not run normal repair/rebuild operations while the server is in emergency recovery mode.'; }

        $check_messages = array();
        if ( ! is_wp_error( $check ) ) {
            foreach ( (array) ( $check['rows'] ?? array() ) as $row ) { $check_messages[] = (string) ( $row['Msg_text'] ?? $row['msg_text'] ?? '' ); }
        }
        $suspect_index = self::suspect_innodb_index( $table, implode( ' ', $check_messages ) );
        $recovery_steps = array(
            'Stop or reduce writes to the affected table if integrity errors are present.',
            'Verify a current database or filesystem snapshot before any mutating DDL.',
            'Review the parsed InnoDB deadlock/lock graph and MySQL/MariaDB error log for the first corruption or crash signal.',
            'If the table remains readable, take a logical dump of critical data before attempting a rebuild.',
            'Prefer rebuilding an isolated secondary index when evidence points only to that index.',
            'For clustered/data corruption, restore or dump/reload into a clean table rather than treating OPTIMIZE/REPAIR as a universal fix.',
            'After recovery, run CHECK TABLE, ANALYZE TABLE, schema verification and a fresh performance profile.',
        );
        $commands = array(
            'check' => 'CHECK TABLE `' . $table . '`',
            'analyze' => 'ANALYZE TABLE `' . $table . '`',
        );
        if ( $suspect_index ) { $commands['index_rebuild_cli'] = 'wp performance innodb-rebuild-index ' . $table . ' ' . $suspect_index . ' --backup-confirmed' . ( self::is_large_meta( $meta ) ? ' --force-large' : '' ); }
        if ( ! empty( $ddl['inplace'] ) ) { $commands['table_rebuild'] = 'ALTER TABLE `' . $table . '` FORCE, ALGORITHM=INPLACE, LOCK=NONE'; }

        return array(
            'ok' => true,
            'message' => 'Built a guided InnoDB recovery preflight for ' . $table . '.',
            'table' => $table,
            'meta' => $meta,
            'check' => is_wp_error( $check ) ? array( 'ok' => false, 'error' => $check->get_error_message() ) : $check,
            'indexes' => $indexes,
            'foreign_keys' => is_array( $foreign_keys ) ? $foreign_keys : array(),
            'runtime' => array(
                'deadlock' => (array) ( $runtime['deadlock'] ?? array() ),
                'lock_graph' => (array) ( $runtime['lock_graph'] ?? array() ),
                'transactions' => array_slice( (array) ( $runtime['transactions'] ?? array() ), 0, 10 ),
            ),
            'online_ddl' => $ddl,
            'disk_space' => array( 'index_rebuild' => $disk_index, 'table_rebuild' => $disk_table ),
            'force_recovery' => $force_recovery,
            'suspect_secondary_index' => $suspect_index,
            'recovery_steps' => $recovery_steps,
            'warnings' => array_values( array_unique( $warnings ) ),
            'commands' => $commands,
        );
    }

    private static function rebuild_innodb_index( array $args, $force_large ) {
        global $wpdb;
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        $index = self::safe_identifier( (string) ( $args['index'] ?? '' ) );
        if ( ! $table || ! $index || 'PRIMARY' === strtoupper( $index ) ) { return new WP_Error( 'pfc_index', 'A valid non-primary index is required.' ); }
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm that a current database backup or snapshot has been verified before rebuilding an InnoDB index.' ); }

        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta || 0 !== strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) { return new WP_Error( 'pfc_not_innodb', 'The selected table is not an InnoDB table.' ); }
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal wp-admin DDL safety threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large during a maintenance window.' ); }
        $force_recovery = (int) ( $health['server']['variables']['innodb_force_recovery'] ?? 0 );
        if ( $force_recovery > 0 ) { return new WP_Error( 'pfc_force_recovery', 'The server is running with innodb_force_recovery enabled. Normal index rebuilds are blocked.' ); }
        $ddl = PFC_Database_Health::online_ddl_capabilities( $wpdb->db_version(), (string) ( $health['server']['storage_engines']['family'] ?? 'mysql' ) );
        if ( empty( $ddl['inplace'] ) || empty( $ddl['lock_none'] ) ) { return new WP_Error( 'pfc_online_ddl', 'Performance Console cannot verify ALGORITHM=INPLACE, LOCK=NONE support for this database version and will not fall back to a blocking copy.' ); }
        $definition = self::build_index_definition( $table, $index );
        if ( is_wp_error( $definition ) ) { return $definition; }
        $disk = self::disk_space_preflight( $meta, 'index' );
        if ( ! empty( $disk['available'] ) && empty( $disk['ok'] ) ) { return new WP_Error( 'pfc_disk_space', 'Estimated free disk space is insufficient for the index rebuild safety margin.' ); }
        $busy = self::innodb_busy_preflight();
        if ( is_wp_error( $busy ) ) { return $busy; }

        $sql = 'ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`, ADD ' . $definition . ', ALGORITHM=INPLACE, LOCK=NONE';
        $result = self::execute_online_ddl( $sql );
        if ( is_wp_error( $result ) ) { return $result; }
        $verification = self::post_repair_verify( $table, $index );
        if ( is_wp_error( $verification ) ) { return $verification; }
        self::record_change( 'db_innodb_index_rebuild', $table . '.' . $index, '', $sql );
        return array(
            'ok' => true,
            'message' => 'Rebuilt InnoDB secondary index ' . $table . '.' . $index . ' with an explicit INPLACE/LOCK=NONE operation and verified the table afterward.',
            'table' => $table,
            'index' => $index,
            'ddl' => $sql,
            'disk_space' => $disk,
            'verification' => $verification,
        );
    }

    private static function rebuild_innodb_table( array $args, $force_large ) {
        global $wpdb;
        $table = self::safe_table( (string) ( $args['table'] ?? '' ) );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        if ( ! self::backup_confirmed( $args ) ) { return new WP_Error( 'pfc_backup_required', 'Confirm that a current database backup or snapshot has been verified before rebuilding an InnoDB table.' ); }
        $health = PFC_Database_Health::inspect( false, false );
        $meta = self::table_meta( $health, $table );
        if ( ! $meta || 0 !== strcasecmp( (string) ( $meta['engine'] ?? '' ), 'InnoDB' ) ) { return new WP_Error( 'pfc_not_innodb', 'The selected table is not an InnoDB table.' ); }
        if ( self::is_large_meta( $meta ) && ! $force_large ) { return new WP_Error( 'pfc_large_table', 'Table exceeds the normal wp-admin DDL safety threshold. Use the guided Maintenance Fix workflow or WP-CLI with --force-large during a maintenance window.' ); }
        if ( (int) ( $health['server']['variables']['innodb_force_recovery'] ?? 0 ) > 0 ) { return new WP_Error( 'pfc_force_recovery', 'The server is running with innodb_force_recovery enabled. Normal table rebuilds are blocked.' ); }
        $ddl = PFC_Database_Health::online_ddl_capabilities( $wpdb->db_version(), (string) ( $health['server']['storage_engines']['family'] ?? 'mysql' ) );
        if ( empty( $ddl['inplace'] ) || empty( $ddl['lock_none'] ) ) { return new WP_Error( 'pfc_online_ddl', 'Performance Console cannot verify an online INPLACE/LOCK=NONE rebuild path for this database version.' ); }
        $disk = self::disk_space_preflight( $meta, 'table' );
        if ( ! empty( $disk['available'] ) && empty( $disk['ok'] ) ) { return new WP_Error( 'pfc_disk_space', 'Estimated free disk space is insufficient for the table rebuild safety margin.' ); }
        $busy = self::innodb_busy_preflight();
        if ( is_wp_error( $busy ) ) { return $busy; }

        $sql = 'ALTER TABLE `' . $table . '` FORCE, ALGORITHM=INPLACE, LOCK=NONE';
        $result = self::execute_online_ddl( $sql );
        if ( is_wp_error( $result ) ) { return $result; }
        $verification = self::post_repair_verify( $table, '' );
        if ( is_wp_error( $verification ) ) { return $verification; }
        self::record_change( 'db_innodb_table_rebuild', $table, '', $sql );
        return array(
            'ok' => true,
            'message' => 'Rebuilt InnoDB table ' . $table . ' using an explicit INPLACE/LOCK=NONE operation and completed post-repair verification.',
            'table' => $table,
            'ddl' => $sql,
            'disk_space' => $disk,
            'verification' => $verification,
        );
    }

    private static function build_index_definition( $table, $index ) {
        global $wpdb;
        $rows = self::index_rows( $table, $index );
        if ( ! $rows ) { return new WP_Error( 'pfc_index_missing', 'Index metadata could not be read.' ); }
        $type = strtoupper( (string) ( $rows[0]['Index_type'] ?? '' ) );
        if ( 'BTREE' !== $type ) { return new WP_Error( 'pfc_index_type', 'Performance Console only automatically rebuilds ordinary BTREE secondary indexes. FULLTEXT, SPATIAL, HASH and engine-specific indexes require manual review.' ); }
        if ( isset( $rows[0]['Visible'] ) && 'NO' === strtoupper( (string) $rows[0]['Visible'] ) ) { return new WP_Error( 'pfc_invisible_index', 'Invisible indexes require manual rebuild so visibility semantics are preserved deliberately.' ); }
        if ( isset( $rows[0]['Ignored'] ) && 'YES' === strtoupper( (string) $rows[0]['Ignored'] ) ) { return new WP_Error( 'pfc_ignored_index', 'Ignored MariaDB indexes require manual rebuild so ignored-index semantics are preserved deliberately.' ); }
        $parts = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $row['Expression'] ) || empty( $row['Column_name'] ) ) { return new WP_Error( 'pfc_functional_index', 'Functional/expression indexes require manual review.' ); }
            $column = self::safe_identifier( (string) $row['Column_name'] );
            if ( ! $column ) { return new WP_Error( 'pfc_index_column', 'Index contains an unsafe or unsupported column identifier.' ); }
            $part = '`' . $column . '`';
            if ( ! empty( $row['Sub_part'] ) ) { $part .= '(' . absint( $row['Sub_part'] ) . ')'; }
            if ( 'D' === strtoupper( (string) ( $row['Collation'] ?? '' ) ) ) { $part .= ' DESC'; }
            $parts[] = $part;
        }
        if ( ! $parts ) { return new WP_Error( 'pfc_index_columns', 'No index columns were found.' ); }
        if ( empty( $rows[0]['Non_unique'] ) ) {
            $dup = self::index_columns_have_duplicates( $table, $rows );
            if ( is_wp_error( $dup ) ) { return $dup; }
            if ( $dup ) { return new WP_Error( 'pfc_unique_duplicates', 'Duplicate values were detected for this unique index, so Performance Console will not rebuild it automatically.' ); }
        }
        return ( empty( $rows[0]['Non_unique'] ) ? 'UNIQUE ' : '' ) . 'INDEX `' . $index . '` (' . implode( ',', $parts ) . ')';
    }

    private static function index_rows( $table, $index = '' ) {
        global $wpdb;
        $table = self::safe_table( $table );
        if ( ! $table ) { return array(); }
        $old = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->suppress_errors( $old );
        $out = array();
        foreach ( (array) $rows as $row ) {
            if ( $index && 0 !== strcasecmp( (string) ( $row['Key_name'] ?? '' ), $index ) ) { continue; }
            $out[] = $row;
        }
        usort( $out, static function ( $a, $b ) { return (int) ( $a['Seq_in_index'] ?? 0 ) <=> (int) ( $b['Seq_in_index'] ?? 0 ); } );
        return $out;
    }

    private static function index_inventory( $table ) {
        $grouped = array();
        foreach ( self::index_rows( $table ) as $row ) {
            $name = (string) ( $row['Key_name'] ?? '' );
            if ( ! isset( $grouped[ $name ] ) ) { $grouped[ $name ] = array( 'name' => $name, 'unique' => empty( $row['Non_unique'] ), 'type' => (string) ( $row['Index_type'] ?? '' ), 'columns' => array() ); }
            $column = (string) ( $row['Column_name'] ?? $row['Expression'] ?? '' );
            if ( ! empty( $row['Sub_part'] ) ) { $column .= '(' . absint( $row['Sub_part'] ) . ')'; }
            if ( $column ) { $grouped[ $name ]['columns'][] = $column; }
        }
        return array_values( $grouped );
    }

    private static function index_columns_have_duplicates( $table, array $rows ) {
        global $wpdb;
        $columns = array();
        foreach ( $rows as $row ) {
            $column = self::safe_identifier( (string) ( $row['Column_name'] ?? '' ) );
            if ( ! $column ) { return new WP_Error( 'pfc_index_column', 'Unable to preflight unique index columns.' ); }
            if ( ! empty( $row['Sub_part'] ) ) { $columns[] = 'LEFT(`' . $column . '`,' . absint( $row['Sub_part'] ) . ')'; }
            else { $columns[] = '`' . $column . '`'; }
        }
        if ( ! $columns ) { return new WP_Error( 'pfc_index_column', 'Unable to preflight unique index columns.' ); }
        $old = $wpdb->suppress_errors( true );
        $duplicate = $wpdb->get_var( 'SELECT 1 FROM `' . $table . '` GROUP BY ' . implode( ',', $columns ) . ' HAVING COUNT(*)>1 LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( $error ) { return new WP_Error( 'pfc_unique_preflight', sanitize_text_field( $error ) ); }
        return null !== $duplicate;
    }

    private static function suspect_innodb_index( $table, $message ) {
        foreach ( self::index_inventory( $table ) as $index ) {
            $name = (string) ( $index['name'] ?? '' );
            if ( ! $name || 'PRIMARY' === strtoupper( $name ) || 'BTREE' !== strtoupper( (string) ( $index['type'] ?? '' ) ) ) { continue; }
            if ( false !== stripos( (string) $message, $name ) ) { return $name; }
        }
        if ( preg_match( '/(?:index|key)\s+[`\'\"]?([A-Za-z0-9_]+)[`\'\"]?/i', (string) $message, $m ) ) {
            $candidate = self::safe_identifier( $m[1] );
            if ( $candidate && 'PRIMARY' !== strtoupper( $candidate ) && self::index_rows( $table, $candidate ) ) { return $candidate; }
        }
        return '';
    }

    private static function disk_space_preflight( array $meta, $operation ) {
        global $wpdb;
        $size = max( 1, (int) ( $meta['size'] ?? 0 ) );
        $required = 'table' === $operation ? (int) ceil( $size * 2.2 ) : (int) ceil( max( $size * 0.75, 512 * PFC_Utils::MB_IN_BYTES ) );
        $old = $wpdb->suppress_errors( true );
        $datadir = (string) $wpdb->get_var( 'SELECT @@datadir' );
        $wpdb->suppress_errors( $old );
        $free = false;
        if ( $datadir && function_exists( 'disk_free_space' ) && @is_dir( $datadir ) ) { $free = @disk_free_space( $datadir ); }
        if ( false === $free ) {
            return array( 'available' => false, 'ok' => null, 'required_bytes' => $required, 'free_bytes' => null, 'datadir' => '', 'message' => 'Database-host free space could not be verified from PHP. Confirm free space with host/DBA tooling before DDL.' );
        }
        return array( 'available' => true, 'ok' => $free >= $required, 'required_bytes' => $required, 'free_bytes' => (int) $free, 'datadir' => sanitize_text_field( $datadir ), 'message' => $free >= $required ? 'Database filesystem has at least the conservative free-space margin.' : 'Database filesystem is below the conservative free-space margin.' );
    }

    private static function innodb_busy_preflight() {
        $snapshot = self::transaction_manager_snapshot();
        if ( ! empty( $snapshot['lock_graph']['edges'] ) ) {
            return new WP_Error(
                'pfc_innodb_busy',
                'Active InnoDB lock waits are visible. Use Performance → InnoDB Transaction Manager to inspect and, when appropriate, terminate the blocking connection before retrying DDL.',
                array( 'transaction_manager' => $snapshot )
            );
        }
        foreach ( (array) ( $snapshot['transactions'] ?? array() ) as $trx ) {
            if ( ! empty( $trx['is_current_connection'] ) ) { continue; }
            if ( (int) ( $trx['age_seconds'] ?? 0 ) >= 30 ) {
                return new WP_Error(
                    'pfc_innodb_busy',
                    'A long-running InnoDB transaction is open. Use Performance → InnoDB Transaction Manager to inspect it and safely terminate a stuck/idle connection before retrying DDL.',
                    array( 'transaction_manager' => $snapshot, 'blocking_transaction' => $trx )
                );
            }
        }
        return true;
    }

    private static function execute_online_ddl( $sql ) {
        global $wpdb;
        $old_errors = $wpdb->suppress_errors( true );
        $old_timeout = $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
        $wpdb->query( 'SET SESSION lock_wait_timeout=5' ); // Fail quickly instead of hanging wp-admin/CLI on a metadata lock.
        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = $wpdb->last_error;
        if ( null !== $old_timeout && is_numeric( $old_timeout ) ) { $wpdb->query( 'SET SESSION lock_wait_timeout=' . absint( $old_timeout ) ); }
        $wpdb->suppress_errors( $old_errors );
        if ( false === $result ) { return new WP_Error( 'pfc_online_ddl_failed', $error ? sanitize_text_field( $error ) : 'The database rejected the online DDL operation. Performance Console did not retry with a more blocking algorithm.' ); }
        return true;
    }

    private static function post_repair_verify( $table, $index = '' ) {
        global $wpdb;
        $check = $wpdb->get_results( 'CHECK TABLE `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! self::table_operation_ok( $check ) ) { return new WP_Error( 'pfc_post_check', 'The DDL completed, but CHECK TABLE did not report a clean result. Stop and investigate before further changes.' ); }
        $analyze = $wpdb->get_results( 'ANALYZE TABLE `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! self::table_operation_ok( $analyze ) ) { return new WP_Error( 'pfc_post_analyze', 'The table passed CHECK TABLE, but ANALYZE TABLE did not report success.' ); }
        $index_ok = true;
        if ( $index ) { $index_ok = ! empty( self::index_rows( $table, $index ) ); }
        if ( ! $index_ok ) { return new WP_Error( 'pfc_post_index', 'Post-repair verification could not find the rebuilt index.' ); }
        return array( 'ok' => true, 'check' => $check, 'analyze' => $analyze, 'index_present' => $index_ok );
    }

    private static function backup_confirmed( array $args ) {
        $backup_id = absint( $args['backup_id'] ?? 0 );
        if ( $backup_id && class_exists( 'PFC_Database_Backup' ) && PFC_Database_Backup::is_verified_recent( $backup_id ) ) { return true; }
        $value = strtolower( trim( (string) ( $args['backup_confirmed'] ?? '' ) ) );
        return in_array( $value, array( '1','yes','true','on' ), true );
    }
    private static function danger_confirmed( array $args ) {
        $value = strtolower( trim( (string) ( $args['danger_confirmed'] ?? '' ) ) );
        return in_array( $value, array( '1','yes','true','on' ), true );
    }

    private static function data_loss_confirmed( array $args ) {
        $value = strtolower( trim( (string) ( $args['data_loss_confirmed'] ?? '' ) ) );
        return in_array( $value, array( '1','yes','true','on' ), true );
    }

    private static function core_create_table_sql( $table ) {
        $table = self::safe_table( $table );
        if ( ! $table ) { return new WP_Error( 'pfc_table', 'Invalid table.' ); }
        require_once ABSPATH . 'wp-admin/includes/schema.php';
        $schema = function_exists( 'wp_get_db_schema' ) ? wp_get_db_schema( 'all' ) : '';
        if ( ! is_string( $schema ) || '' === $schema ) { return new WP_Error( 'pfc_core_schema', 'WordPress core schema could not be loaded.' ); }
        $pattern = '/CREATE TABLE\s+`?' . preg_quote( $table, '/' ) . '`?\s*\(.*?\)\s*[^;]*;/si';
        if ( ! preg_match( $pattern, $schema, $match ) ) { return new WP_Error( 'pfc_core_table_schema', 'The installed WordPress schema does not contain a CREATE TABLE statement for the requested table.' ); }
        $sql = trim( (string) $match[0] );
        if ( false !== strpos( $sql, '--' ) || false !== strpos( $sql, '/*' ) ) { return new WP_Error( 'pfc_core_table_schema', 'Core table schema failed safety validation.' ); }
        return $sql;
    }

    private static function index_signature_from_rows( array $rows ) {
        if ( ! $rows ) { return ''; }
        usort( $rows, static function ( $a, $b ) { return (int) ( $a['Seq_in_index'] ?? 0 ) <=> (int) ( $b['Seq_in_index'] ?? 0 ); } );
        $parts = array();
        foreach ( $rows as $row ) {
            $parts[] = array(
                'non_unique' => (int) ( $row['Non_unique'] ?? 1 ),
                'column' => strtolower( (string) ( $row['Column_name'] ?? '' ) ),
                'sub_part' => (int) ( $row['Sub_part'] ?? 0 ),
                'collation' => strtoupper( (string) ( $row['Collation'] ?? '' ) ),
                'type' => strtoupper( (string) ( $row['Index_type'] ?? '' ) ),
            );
        }
        return md5( wp_json_encode( $parts ) );
    }


    private static function rollback_change( array $args ) {
        global $wpdb;
        $id = absint( $args['change_id'] ?? 0 );
        if ( ! $id ) { return new WP_Error( 'pfc_change_id', 'A valid change ID is required.' ); }
        $table = PFC_Utils::table( 'changes' );
        $change = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $change ) { return new WP_Error( 'pfc_change_missing', 'Change record not found.' ); }
        if ( ! empty( $change['reverted_at'] ) ) { return new WP_Error( 'pfc_already_reverted', 'This change has already been reverted.' ); }

        if ( 'autoload' === $change['change_type'] ) {
            $allowed = array( 'yes','no','on','off','auto','auto-on','auto-off' );
            $before = (string) $change['before_value'];
            if ( ! in_array( $before, $allowed, true ) ) { return new WP_Error( 'pfc_autoload_rollback', 'Original autoload value is not recognized.' ); }
            $ok = false !== $wpdb->update( $wpdb->options, array( 'autoload' => $before ), array( 'option_name' => $change['object_name'] ) );
            if ( ! $ok ) { return new WP_Error( 'pfc_autoload_rollback', 'Unable to restore the original autoload value.' ); }
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( $change['object_name'], 'options' );
            $wpdb->update( $table, array( 'reverted_at' => PFC_Utils::now_mysql() ), array( 'id' => $id ) );
            return array( 'ok' => true, 'message' => 'Restored the original autoload value for ' . $change['object_name'] . '.' );
        }

        if ( 'db_cleanup_orphans' === $change['change_type'] ) {
            $backup = json_decode( (string) $change['before_value'], true );
            if ( ! is_array( $backup ) || empty( $backup['table'] ) || empty( $backup['rows'] ) || ! is_array( $backup['rows'] ) ) { return new WP_Error( 'pfc_backup_invalid', 'The rollback snapshot is missing or invalid.' ); }
            $target = self::safe_table( $backup['table'] );
            if ( ! $target ) { return new WP_Error( 'pfc_backup_table', 'The rollback table identifier is invalid.' ); }
            $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$target}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $allowed_columns = array_fill_keys( array_map( 'strval', (array) $columns ), true );
            $inserted = 0; $errors = array();
            foreach ( array_slice( $backup['rows'], 0, self::MAX_BATCH ) as $row ) {
                $clean = array();
                foreach ( (array) $row as $column => $value ) { if ( isset( $allowed_columns[ $column ] ) ) { $clean[ $column ] = $value; } }
                if ( ! $clean ) { continue; }
                $result = $wpdb->insert( $target, $clean );
                if ( false === $result ) { $errors[] = sanitize_text_field( $wpdb->last_error ); break; }
                $inserted++;
            }
            if ( $errors ) { return new WP_Error( 'pfc_rollback_partial', 'Rollback stopped after restoring ' . $inserted . ' row(s): ' . $errors[0] ); }
            $wpdb->update( $table, array( 'reverted_at' => PFC_Utils::now_mysql() ), array( 'id' => $id ) );
            return array( 'ok' => true, 'restored' => $inserted, 'message' => 'Restored ' . $inserted . ' row(s) from the bounded orphan-cleanup snapshot.' );
        }
        return new WP_Error( 'pfc_not_reversible', 'This change type is intentionally not reversible automatically.' );
    }

    private static function encode_backup( $table, array $rows ) {
        $payload = wp_json_encode( array( 'table' => (string) $table, 'rows' => $rows ) );
        if ( ! is_string( $payload ) ) { return new WP_Error( 'pfc_backup_encode', 'Unable to encode rollback data.' ); }
        if ( strlen( $payload ) > self::MAX_BACKUP_BYTES ) { return new WP_Error( 'pfc_backup_too_large', 'This cleanup batch would require a rollback snapshot larger than 2 MiB. Reduce the batch size or use an external database backup/maintenance workflow.' ); }
        return $payload;
    }

    private static function schema_repair_has_large_table( array $health, array $items ) {
        foreach ( $items as $item ) {
            $meta = self::table_meta( $health, (string) ( $item['table'] ?? '' ) );
            if ( self::is_large_meta( $meta ) ) { return true; }
        }
        return false;
    }

    private static function is_large_meta( $meta ) {
        if ( ! is_array( $meta ) ) { return false; }
        return (int) ( $meta['rows_estimate'] ?? 0 ) > self::WEB_ROW_LIMIT || (int) ( $meta['size'] ?? 0 ) > self::WEB_SIZE_LIMIT;
    }

    /**
     * Resolve ownership and keep priority for an exact duplicate-index group.
     * Plugins can register canonical names and legacy aliases without making Performance Console
     * depend on plugin-specific code.
     */
    private static function managed_index_context( $table, array $names ) {
        $registry = apply_filters( 'pfc_managed_database_indexes', array() );
        if ( ! is_array( $registry ) ) { return null; }
        foreach ( $registry as $item ) {
            if ( ! is_array( $item ) || 0 !== strcasecmp( (string) ( $item['table'] ?? '' ), (string) $table ) ) { continue; }
            $canonical = (string) ( $item['canonical'] ?? '' );
            $aliases = array_values( array_filter( array_map( 'strval', (array) ( $item['aliases'] ?? array() ) ) ) );
            $managed_names = array_values( array_unique( array_filter( array_merge( array( $canonical ), $aliases ) ) ) );
            $matches = array_values( array_intersect( $managed_names, $names ) );
            if ( ! $matches ) { continue; }
            $preferred = '';
            foreach ( $managed_names as $candidate ) {
                if ( in_array( $candidate, $names, true ) ) { $preferred = $candidate; break; }
            }
            return array(
                'owner' => sanitize_text_field( (string) ( $item['owner'] ?? 'Active plugin' ) ),
                'plugin' => sanitize_text_field( (string) ( $item['plugin'] ?? '' ) ),
                'canonical' => $canonical,
                'aliases' => $aliases,
                'managed_names' => $managed_names,
                'matching_names' => $matches,
                'preferred_keep' => $preferred,
            );
        }
        return null;
    }

    private static function table_meta( array $health, $table ) {
        foreach ( (array) ( $health['schema']['tables'] ?? array() ) as $row ) { if ( (string) ( $row['name'] ?? '' ) === $table ) { return $row; } }
        return array();
    }

    private static function engine_capabilities_for_health( array $health, $engine ) {
        $family = (string) ( $health['server']['storage_engines']['family'] ?? 'mysql' );
        $key = strtolower( (string) $engine );
        $live = (array) ( $health['server']['storage_engines']['engines'][ $key ]['capabilities'] ?? array() );
        return $live ? $live : PFC_Database_Health::engine_capabilities( $engine, $family );
    }

    private static function orphan_type_supported( $type ) {
        return in_array( sanitize_key( $type ), array( 'postmeta','commentmeta','usermeta','termmeta','term_relationships' ), true );
    }

    public static function protected_option( $name ) {
        $name = (string) $name;
        $protected = array(
            'siteurl','home','blogname','blogdescription','admin_email','users_can_register','default_role','start_of_week',
            'permalink_structure','rewrite_rules','active_plugins','template','stylesheet','current_theme','cron','sidebars_widgets',
            'wp_user_roles','pfc_runtime','pfc_secret','pfc_last_scan',
        );
        if ( in_array( $name, $protected, true ) ) { return true; }
        return 0 === strpos( $name, 'widget_' ) || 0 === strpos( $name, 'theme_mods_' );
    }

    private static function safe_table( $table ) { return preg_match( '/^[A-Za-z0-9_]+$/', (string) $table ) ? (string) $table : ''; }
    private static function safe_identifier( $name ) { return preg_match( '/^[A-Za-z0-9_]+$/', (string) $name ) ? (string) $name : ''; }

    private static function safe_core_definition( $definition ) {
        if ( '' === $definition || false !== strpos( $definition, ';' ) || false !== strpos( $definition, '--' ) || false !== strpos( $definition, '/*' ) ) { return false; }
        return (bool) preg_match( '/^(?:[A-Za-z0-9_]+\s+[a-zA-Z]+|PRIMARY\s+KEY|UNIQUE\s+KEY|KEY\s+)[A-Za-z0-9_(),:\s\'".\-+]*$/i', $definition );
    }

    private static function index_columns_from_definition( $definition ) {
        if ( ! preg_match( '/\((.+)\)/', (string) $definition, $m ) ) { return new WP_Error( 'pfc_index_parse', 'Could not parse index columns.' ); }
        $columns = array();
        foreach ( explode( ',', $m[1] ) as $part ) {
            $part = trim( $part );
            if ( ! preg_match( '/^`?([A-Za-z0-9_]+)`?(?:\(\d+\))?(?:\s+(?:ASC|DESC))?$/i', $part, $pm ) ) { return new WP_Error( 'pfc_index_parse', 'Unsupported or unsafe index column definition.' ); }
            $column = self::safe_identifier( $pm[1] );
            if ( ! $column ) { return new WP_Error( 'pfc_index_parse', 'Unsafe index column.' ); }
            $columns[] = $column;
        }
        return $columns ? $columns : new WP_Error( 'pfc_index_parse', 'No index columns found.' );
    }

    /**
     * Build the exact ALTER used for a core-column correction.
     *
     * The important case is a column that participates in a malformed *live* PRIMARY KEY but
     * is not part of WordPress' expected PRIMARY KEY. Making such a column nullable on its own
     * causes MySQL/MariaDB to reject the ALTER with "All parts of a PRIMARY KEY must be NOT NULL".
     * This planner replaces the live PRIMARY KEY and applies the column correction in one ALTER.
     *
     * @param string $table Table name.
     * @param string $column Column being corrected.
     * @param string $definition Exact expected WordPress column definition.
     * @param array  $health Current database health snapshot.
     * @param bool   $preflight_data Whether to run NULL/duplicate/unsigned data preflights.
     * @return array|WP_Error
     */
    private static function core_column_alter_plan( $table, $column, $definition, array $health, $preflight_data = true ) {
        $table = self::safe_table( $table );
        $column = self::safe_identifier( $column );
        $definition = trim( (string) $definition );
        if ( ! $table || ! $column || ! self::safe_core_definition( $definition ) ) {
            return new WP_Error( 'pfc_schema_plan', 'Invalid core-column repair target or definition.' );
        }

        $expected_primary = PFC_Database_Health::expected_core_index_definition( $table, 'PRIMARY' );
        $live_primary = self::index_rows( $table, 'PRIMARY' );
        $live_primary_columns = self::index_columns_from_rows( $live_primary );
        $expected_primary_columns = array();
        if ( $expected_primary && self::safe_core_definition( $expected_primary ) ) {
            $expected_primary_columns = self::index_columns_from_definition( $expected_primary );
            if ( is_wp_error( $expected_primary_columns ) ) { return $expected_primary_columns; }
        }

        $target_in_live_primary = in_array( $column, $live_primary_columns, true );
        $target_in_expected_primary = in_array( $column, $expected_primary_columns, true );
        $primary_missing = empty( $live_primary );
        $primary_mismatch = false;
        if ( $expected_primary_columns ) {
            $primary_mismatch = $live_primary_columns !== $expected_primary_columns;
            foreach ( (array) ( $health['schema']['mismatched_core_indexes'] ?? array() ) as $index_item ) {
                if ( $table === (string) ( $index_item['table'] ?? '' ) && 'PRIMARY' === strtoupper( (string) ( $index_item['index'] ?? '' ) ) ) {
                    $primary_mismatch = true;
                    break;
                }
            }
            foreach ( (array) ( $health['schema']['missing_core_indexes'] ?? array() ) as $index_item ) {
                if ( $table === (string) ( $index_item['table'] ?? '' ) && 'PRIMARY' === strtoupper( (string) ( $index_item['index'] ?? '' ) ) ) {
                    $primary_missing = true;
                    $primary_mismatch = true;
                    break;
                }
            }
        }

        // Rebuild the PRIMARY KEY in the same ALTER when the target column is involved in either
        // the actual or expected key and the live key is not the WordPress key. This covers both
        // normal AUTO_INCREMENT dependency repair and malformed custom/composite PRIMARY KEYs.
        if ( $expected_primary_columns && $primary_mismatch && ( $target_in_live_primary || $target_in_expected_primary ) ) {
            $prepared = self::primary_key_repair_clauses( $table, $expected_primary, ! $primary_missing, $preflight_data );
            if ( is_wp_error( $prepared ) ) { return $prepared; }
            $clauses = (array) ( $prepared['clauses'] ?? array() );
            $already_modified = in_array( $column, (array) ( $prepared['modified_columns'] ?? array() ), true ) || in_array( $column, (array) ( $prepared['added_columns'] ?? array() ), true );
            if ( ! $already_modified ) {
                $insert_at = count( $clauses );
                foreach ( $clauses as $i => $clause ) {
                    if ( 0 === stripos( ltrim( (string) $clause ), 'ADD PRIMARY KEY' ) ) { $insert_at = $i; break; }
                }
                array_splice( $clauses, $insert_at, 0, array( 'MODIFY COLUMN ' . $definition ) );
            }
            return array(
                'sql' => 'ALTER TABLE `' . $table . '` ' . implode( ', ', $clauses ),
                'clauses' => $clauses,
                'primary_repair' => true,
                'live_primary_columns' => $live_primary_columns,
                'expected_primary_columns' => $expected_primary_columns,
            );
        }

        return array(
            'sql' => 'ALTER TABLE `' . $table . '` MODIFY COLUMN ' . $definition,
            'clauses' => array( 'MODIFY COLUMN ' . $definition ),
            'primary_repair' => false,
            'live_primary_columns' => $live_primary_columns,
            'expected_primary_columns' => $expected_primary_columns,
        );
    }

    /** Return ordered column names from SHOW INDEX rows. */
    private static function index_columns_from_rows( array $rows ) {
        if ( ! $rows ) { return array(); }
        usort( $rows, static function ( $a, $b ) { return (int) ( $a['Seq_in_index'] ?? 0 ) <=> (int) ( $b['Seq_in_index'] ?? 0 ); } );
        $columns = array();
        foreach ( $rows as $row ) {
            $column = self::safe_identifier( (string) ( $row['Column_name'] ?? '' ) );
            if ( $column ) { $columns[] = $column; }
        }
        return $columns;
    }

    /**
     * Build the clauses required to establish the installed WordPress PRIMARY KEY safely.
     *
     * MySQL requires every PRIMARY KEY column to be NOT NULL and any AUTO_INCREMENT
     * column to be indexed. Core schema drift and index drift therefore cannot be repaired
     * as unrelated operations. This helper reads the live columns, compares them with the
     * installed WordPress schema, preflights unsafe data, and returns one coordinated ALTER.
     *
     * @param string $table Core table name.
     * @param string $definition Expected PRIMARY KEY definition.
     * @param bool   $drop_existing Whether the live PRIMARY KEY must be removed first.
     * @return array|WP_Error ALTER clauses and metadata, or an actionable preflight error.
     */
    private static function primary_key_repair_clauses( $table, $definition, $drop_existing = false, $preflight_data = true ) {
        global $wpdb;
        $table = self::safe_table( $table );
        $definition = trim( (string) $definition );
        if ( ! $table || 0 !== stripos( $definition, 'PRIMARY KEY' ) || ! self::safe_core_definition( $definition ) ) {
            return new WP_Error( 'pfc_primary_definition', 'The expected WordPress PRIMARY KEY definition could not be validated.' );
        }

        $key_columns = self::index_columns_from_definition( $definition );
        if ( is_wp_error( $key_columns ) ) { return $key_columns; }

        $old = $wpdb->suppress_errors( true );
        $column_rows = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $column_error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( $column_error ) { return new WP_Error( 'pfc_primary_columns', sanitize_text_field( $column_error ) ); }
        $actual = array();
        foreach ( (array) $column_rows as $row ) {
            $field = (string) ( $row['Field'] ?? '' );
            if ( $field ) { $actual[ $field ] = $row; }
        }

        $clauses = array();
        if ( $drop_existing ) { $clauses[] = 'DROP PRIMARY KEY'; }
        $changed_columns = array();
        $added_columns = array();

        foreach ( $key_columns as $column ) {
            $expected = PFC_Database_Health::expected_core_column_definition( $table, $column );
            if ( ! $expected || ! self::safe_core_definition( $expected ) ) {
                return new WP_Error( 'pfc_primary_column_definition', 'The installed WordPress schema did not provide a safe definition for PRIMARY KEY column ' . $table . '.' . $column . '.' );
            }

            if ( ! isset( $actual[ $column ] ) ) {
                // Performance Console cannot invent PRIMARY KEY values for an existing populated table. Even
                // AUTO_INCREMENT backfilling is a data-changing rebuild and should not be used
                // as an implicit schema repair. Empty tables can be restored atomically.
                $old = $wpdb->suppress_errors( true );
                $has_rows = $wpdb->get_var( 'SELECT 1 FROM `' . $table . '` LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $row_error = $wpdb->last_error;
                $wpdb->suppress_errors( $old );
                if ( $row_error ) { return new WP_Error( 'pfc_primary_missing_column', sanitize_text_field( $row_error ) ); }
                if ( null !== $has_rows ) {
                    return new WP_Error( 'pfc_primary_missing_column_data', 'Cannot automatically restore PRIMARY KEY because key column ' . $table . '.' . $column . ' is missing while the table contains data. Performance Console will not invent key values; use a guided data-recovery plan.' );
                }
                $clauses[] = 'ADD COLUMN ' . $expected;
                $added_columns[] = $column;
                continue;
            }

            $drift = PFC_Database_Health::compare_core_column_definition( $expected, $actual[ $column ] );
            $actual_nullable = 'YES' === strtoupper( (string) ( $actual[ $column ]['Null'] ?? 'YES' ) );
            $expected_not_null = false !== stripos( $expected, 'NOT NULL' );
            if ( $preflight_data && $expected_not_null && $actual_nullable ) {
                $has_nulls = self::column_has_nulls( $table, $column );
                if ( is_wp_error( $has_nulls ) ) { return $has_nulls; }
                if ( $has_nulls ) {
                    return new WP_Error(
                        'pfc_primary_null_values',
                        'Cannot restore PRIMARY KEY because ' . $table . '.' . $column . ' contains NULL values. Performance Console will not invent replacement IDs; review those rows first.'
                    );
                }
            }

            $expected_type = strtolower( (string) preg_replace( '/^`?' . preg_quote( $column, '/' ) . '`?\s+/i', '', $expected ) );
            $actual_type = strtolower( (string) ( $actual[ $column ]['Type'] ?? '' ) );
            if ( $preflight_data && false !== strpos( $expected_type, ' unsigned' ) && false === strpos( $actual_type, 'unsigned' ) ) {
                $has_negative = self::column_has_negative_values( $table, $column );
                if ( is_wp_error( $has_negative ) ) { return $has_negative; }
                if ( $has_negative ) {
                    return new WP_Error( 'pfc_primary_negative_values', 'Cannot convert ' . $table . '.' . $column . ' to the expected unsigned core type because negative values exist.' );
                }
            }

            if ( $drift ) {
                $clauses[] = 'MODIFY COLUMN ' . $expected;
                $changed_columns[] = $column;
            }
        }

        if ( $preflight_data && ! $added_columns ) {
            $dupes = self::index_has_duplicates( $table, $definition );
            if ( is_wp_error( $dupes ) ) { return $dupes; }
            if ( $dupes ) {
                return new WP_Error( 'pfc_primary_duplicates', 'Duplicate values prevent the expected PRIMARY KEY from being created safely.' );
            }
        }

        $clauses[] = 'ADD ' . $definition;
        return array(
            'clauses' => $clauses,
            'columns' => $key_columns,
            'modified_columns' => $changed_columns,
            'added_columns' => $added_columns,
        );
    }

    private static function column_has_nulls( $table, $column ) {
        global $wpdb;
        $table = self::safe_table( $table ); $column = self::safe_identifier( $column );
        if ( ! $table || ! $column ) { return new WP_Error( 'pfc_column_preflight', 'Invalid table or column for NULL preflight.' ); }
        $old = $wpdb->suppress_errors( true );
        $found = $wpdb->get_var( 'SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` IS NULL LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( $error ) { return new WP_Error( 'pfc_column_preflight', sanitize_text_field( $error ) ); }
        return null !== $found;
    }

    private static function column_has_negative_values( $table, $column ) {
        global $wpdb;
        $table = self::safe_table( $table ); $column = self::safe_identifier( $column );
        if ( ! $table || ! $column ) { return new WP_Error( 'pfc_column_preflight', 'Invalid table or column for unsigned-type preflight.' ); }
        $old = $wpdb->suppress_errors( true );
        $found = $wpdb->get_var( 'SELECT 1 FROM `' . $table . '` WHERE `' . $column . '` < 0 LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( $error ) { return new WP_Error( 'pfc_column_preflight', sanitize_text_field( $error ) ); }
        return null !== $found;
    }

    private static function index_has_duplicates( $table, $definition ) {
        global $wpdb;
        if ( ! preg_match( '/\((.+)\)/', $definition, $m ) ) { return new WP_Error( 'pfc_index_parse', 'Could not parse index columns.' ); }
        $parts = array();
        foreach ( explode( ',', $m[1] ) as $part ) {
            $part = trim( preg_replace( '/\(\d+\)/', '', $part ) );
            $part = trim( $part, " `\t\n\r\0\x0B" );
            if ( ! self::safe_identifier( $part ) ) { return new WP_Error( 'pfc_index_parse', 'Unsafe index column.' ); }
            $parts[] = '`' . $part . '`';
        }
        if ( ! $parts ) { return new WP_Error( 'pfc_index_parse', 'No index columns found.' ); }
        $cols = implode( ',', $parts );
        $old = $wpdb->suppress_errors( true );
        $duplicate = $wpdb->get_var( "SELECT 1 FROM `{$table}` GROUP BY {$cols} HAVING COUNT(*)>1 LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $error = $wpdb->last_error;
        $wpdb->suppress_errors( $old );
        if ( $error ) { return new WP_Error( 'pfc_index_preflight', sanitize_text_field( $error ) ); }
        return null !== $duplicate;
    }

    private static function table_operation_ok( $rows ) {
        if ( ! is_array( $rows ) || ! $rows ) { return false; }
        foreach ( $rows as $row ) {
            $type = strtolower( (string) ( $row['Msg_type'] ?? $row['msg_type'] ?? '' ) );
            $text = strtolower( (string) ( $row['Msg_text'] ?? $row['msg_text'] ?? '' ) );
            if ( 'error' === $type || false !== strpos( $text, 'error' ) || false !== strpos( $text, 'failed' ) ) { return false; }
        }
        return true;
    }

    private static function record_change( $type, $object, $before, $after ) {
        global $wpdb;
        $wpdb->insert( PFC_Utils::table( 'changes' ), array(
            'created_at' => PFC_Utils::now_mysql(),
            'change_type' => sanitize_key( $type ),
            'object_name' => sanitize_text_field( $object ),
            'before_value' => (string) $before,
            'after_value' => (string) $after,
            'user_id' => get_current_user_id(),
        ) );
    }
}
