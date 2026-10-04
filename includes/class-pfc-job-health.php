<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_Job_Health {
    public static function inspect( $deep = false, $force_large = false ) {
        return array(
            'cron' => self::cron(),
            'action_scheduler' => self::action_scheduler( $deep, $force_large ),
        );
    }

    private static function cron() {
        $cron = _get_cron_array();
        $schedules = wp_get_schedules();
        $now = time();
        $events = 0;
        $overdue = 0;
        $severely_overdue = 0;
        $by_hook = array();
        $by_schedule = array();
        $short_intervals = array();
        $duplicate_timestamps = array();

        foreach ( (array) $cron as $ts => $group ) {
            foreach ( $group as $hook => $instances ) {
                foreach ( (array) $instances as $instance ) {
                    $events++;
                    $by_hook[ $hook ] = ( $by_hook[ $hook ] ?? 0 ) + 1;
                    $schedule = (string) ( $instance['schedule'] ?? 'single' );
                    $by_schedule[ $schedule ] = ( $by_schedule[ $schedule ] ?? 0 ) + 1;
                    if ( $ts < $now - 300 ) { $overdue++; }
                    if ( $ts < $now - HOUR_IN_SECONDS ) { $severely_overdue++; }
                    if ( 'single' !== $schedule && isset( $schedules[ $schedule ]['interval'] ) && (int) $schedules[ $schedule ]['interval'] < 300 ) {
                        $short_intervals[ $hook ] = (int) $schedules[ $schedule ]['interval'];
                    }
                    $dupe_key = $hook . '|' . md5( serialize( $instance['args'] ?? array() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
                    if ( ! isset( $duplicate_timestamps[ $dupe_key ] ) ) { $duplicate_timestamps[ $dupe_key ] = 0; }
                    $duplicate_timestamps[ $dupe_key ]++;
                }
            }
        }
        arsort( $by_hook );
        $duplicates = array();
        foreach ( $duplicate_timestamps as $key => $count ) {
            if ( $count >= 20 ) { $duplicates[] = array( 'key' => substr( $key, 0, strpos( $key, '|' ) ), 'count' => $count ); }
        }
        usort( $duplicates, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

        $cron_option_size = strlen( maybe_serialize( get_option( 'cron', array() ) ) );

        return array(
            'events' => $events,
            'overdue' => $overdue,
            'severely_overdue' => $severely_overdue,
            'top_hooks' => array_slice( $by_hook, 0, 40, true ),
            'schedules' => $by_schedule,
            'short_intervals' => $short_intervals,
            'duplicate_patterns' => array_slice( $duplicates, 0, 20 ),
            'cron_option_size' => $cron_option_size,
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'alternate_wp_cron' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
        );
    }

    private static function action_scheduler( $deep, $force_large ) {
        global $wpdb;
        $actions = $wpdb->prefix . 'actionscheduler_actions';
        $logs = $wpdb->prefix . 'actionscheduler_logs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) );
        if ( $exists !== $actions ) { return array( 'available' => false ); }

        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $actions ), ARRAY_A );
        $estimate = (int) ( $status['Rows'] ?? 0 );
        $out = array( 'available' => true, 'rows_estimate' => $estimate, 'bounded' => false );

        if ( ( $estimate > 2000000 && ! ( $deep && $force_large ) ) || ( $estimate > 250000 && ! $deep ) ) {
            $out['bounded'] = true;
            $out['sample_pending'] = self::bounded_action_count( $actions, 'pending', 10001 );
            $out['sample_failed'] = self::bounded_action_count( $actions, 'failed', 10001 );
            return $out;
        }

        $out['pending'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$actions}` WHERE status='pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out['failed'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$actions}` WHERE status='failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out['in_progress'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$actions}` WHERE status='in-progress'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out['past_due'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$actions}` WHERE status='pending' AND scheduled_date_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - 300 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out['oldest_pending'] = $wpdb->get_var( "SELECT MIN(scheduled_date_gmt) FROM `{$actions}` WHERE status='pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out['top_pending_hooks'] = $wpdb->get_results( "SELECT hook,COUNT(*) total FROM `{$actions}` WHERE status='pending' GROUP BY hook ORDER BY total DESC LIMIT 30", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $out['top_failed_hooks'] = $wpdb->get_results( "SELECT hook,COUNT(*) total FROM `{$actions}` WHERE status='failed' GROUP BY hook ORDER BY total DESC LIMIT 30", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $log_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs ) );
        if ( $log_exists === $logs ) {
            $log_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $logs ), ARRAY_A );
            $out['log_rows_estimate'] = (int) ( $log_status['Rows'] ?? 0 );
            $out['log_size'] = (int) ( $log_status['Data_length'] ?? 0 ) + (int) ( $log_status['Index_length'] ?? 0 );
        }
        return $out;
    }

    private static function bounded_action_count( $table, $status, $limit ) {
        global $wpdb;
        $limit = max( 1, min( 10001, (int) $limit ) );
        $sql = $wpdb->prepare( "SELECT action_id FROM `{$table}` WHERE status=%s LIMIT %d", $status, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return count( (array) $wpdb->get_col( $sql ) );
    }

    public static function generate_issues( array $health ) {
        $cron = $health['cron'];
        if ( $cron['overdue'] > 100 ) {
            PFC_Utils::issue( 'cron', $cron['overdue'] > 1000 ? 'critical' : 'high', 'Large WP-Cron backlog', number_format_i18n( $cron['overdue'] ) . ' events are more than five minutes overdue.', number_format_i18n( $cron['overdue'] ) . ' overdue jobs', 'Confirm cron spawning is working, then identify hooks that take longer than their recurrence interval or repeatedly fail.' );
        }
        if ( $cron['severely_overdue'] > 25 ) {
            PFC_Utils::issue( 'cron', 'high', 'WP-Cron contains severely overdue events', number_format_i18n( $cron['severely_overdue'] ) . ' events are more than one hour overdue.', number_format_i18n( $cron['severely_overdue'] ), 'Test the top overdue hooks with WP-CLI and verify that a real server cron is configured if DISABLE_WP_CRON is enabled.' );
        }
        if ( $cron['cron_option_size'] > MB_IN_BYTES ) {
            PFC_Utils::issue( 'cron', 'high', 'Cron schedule option is abnormally large', 'The serialized cron option is approximately ' . esc_html( size_format( $cron['cron_option_size'] ) ) . '.', size_format( $cron['cron_option_size'] ), 'Look for plugins creating duplicate single events or failing to unschedule old events. Do not manually edit the cron option.' );
        }
        foreach ( $cron['short_intervals'] as $hook => $seconds ) {
            PFC_Utils::issue( 'cron', 'warning', 'Very frequent WP-Cron recurrence', '<code>' . esc_html( $hook ) . '</code> is scheduled every ' . intval( $seconds ) . ' seconds.', $seconds . ' second interval', 'Confirm the task is lightweight and necessary. For heavy workloads, use a queue/worker or less frequent server-side schedule.' );
        }
        foreach ( $cron['duplicate_patterns'] as $dupe ) {
            PFC_Utils::issue( 'cron', 'warning', 'Cron hook has many repeated scheduled instances', '<code>' . esc_html( $dupe['key'] ) . '</code> appears ' . intval( $dupe['count'] ) . ' times with the same argument fingerprint.', $dupe['count'] . ' scheduled instances', 'Check whether the owning plugin schedules a new event without verifying wp_next_scheduled()/wp_get_scheduled_event().' );
        }

        $as = $health['action_scheduler'];
        if ( empty( $as['available'] ) ) { return; }
        $failed = isset( $as['failed'] ) ? (int) $as['failed'] : (int) ( $as['sample_failed'] ?? 0 );
        // Future scheduled work is not a backlog. Only the due/failed checks below
        // establish an actionable queue problem; bounded scans may not know due counts.
        if ( isset( $as['past_due'] ) && $as['past_due'] > 1000 ) {
            PFC_Utils::issue( 'jobs', 'high', 'Action Scheduler has many past-due actions', number_format_i18n( $as['past_due'] ) . ' pending actions are already past due.', number_format_i18n( $as['past_due'] ) . ' past due', 'Check queue runner concurrency, cron health, failed hooks and long-running jobs.' );
        }
        if ( $failed > 100 ) {
            PFC_Utils::issue( 'jobs', 'high', 'Action Scheduler has a large failed queue', number_format_i18n( $failed ) . ' failed actions were found.', number_format_i18n( $failed ) . ' failed', 'Inspect the top failed hooks and their logs. Repeated failures can continuously consume cron/DB resources.' );
        }
        if ( isset( $as['log_size'] ) && $as['log_size'] > 2 * GB_IN_BYTES ) {
            PFC_Utils::issue( 'jobs', 'warning', 'Action Scheduler log table is very large', 'The Action Scheduler logs use approximately ' . esc_html( size_format( $as['log_size'] ) ) . '.', size_format( $as['log_size'] ), 'Use the owning plugin/WooCommerce retention mechanisms to prune old completed action logs in controlled batches.' );
        }
    }
}
