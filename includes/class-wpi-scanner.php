<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPI_Scanner {
    public static function scan( $deep = false, $force_large = false ) {
        WPI_Utils::begin_issue_collection( 'scan' );

        $system = WPI_System_Health::inspect( $deep );
        $database_health = WPI_Database_Health::inspect( $deep, $force_large );
        $jobs = WPI_Job_Health::inspect( $deep, $force_large );
        $cache = WPI_Cache_Health::inspect();
        $frontend = WPI_Frontend_Health::inspect( $deep );
        $regression = WPI_Regression::inspect();

        WPI_System_Health::generate_issues( $system );
        WPI_Database_Health::generate_issues( $database_health );
        WPI_Job_Health::generate_issues( $jobs );
        WPI_Cache_Health::generate_issues( $cache );
        WPI_Frontend_Health::generate_issues( $frontend );
        WPI_Regression::generate_issues( $regression );
        $observed_incidents = WPI_Utils::collected_incidents();
        WPI_Utils::end_issue_collection();

        $database = self::database_compat( $database_health );
        $result = array(
            'scan_version' => WPI_VERSION,
            // Absence is not a pass: inspectors can skip checks or lack capabilities.
            'observed_incidents' => $observed_incidents,
            'mode' => $deep ? 'deep' : 'production-safe',
            'generated_at' => WPI_Utils::now_mysql(),
            'system' => $system,
            'database_health' => $database_health,
            'database_repairs' => WPI_Database_Repair::plans( $database_health ),
            'jobs' => $jobs,
            'frontend' => $frontend,
            'regression' => $regression,
            'server' => array_merge( $system['php'], array( 'db_version' => $database_health['server']['version'], 'db_variables' => $database_health['server']['variables'] ) ),
            'database' => $database,
            'autoload' => array( 'total_bytes' => $database_health['options']['autoload_bytes'], 'largest' => $database_health['options']['largest_autoload'] ),
            'cron' => $jobs['cron'],
            'cache' => $cache,
            'action_scheduler' => $jobs['action_scheduler'],
            'plugins' => $system['plugins']['plugins'],
            'bootstrap' => WPI_Bootstrap::status(),
            'summary' => self::summary(),
        );

        // Keep this bounded. It is a convenience cache for the latest admin report, not telemetry storage.
        update_option( 'wpi_last_scan', array( 'at' => time(), 'result' => $result ), false );
        return $result;
    }

    private static function database_compat( array $health ) {
        $tables = $health['schema']['tables'];
        $total = (int) ( $health['schema']['total_size'] ?? 0 );
        return array(
            'total_size' => $total,
            'tables' => $tables,
            'orphan' => array(
                'skipped' => ! empty( $health['orphans']['skipped'] ),
                'postmeta' => (int) ( $health['orphans']['counts']['postmeta'] ?? 0 ),
                'counts' => $health['orphans']['counts'] ?? array(),
                'skipped_checks' => $health['orphans']['skipped_checks'] ?? array(),
            ),
            'missing_core_tables' => $health['schema']['missing_core_tables'],
            'missing_core_indexes' => $health['schema']['missing_core_indexes'],
            'missing_core_columns' => $health['schema']['missing_core_columns'] ?? array(),
            'mismatched_core_columns' => $health['schema']['mismatched_core_columns'] ?? array(),
            'mismatched_core_indexes' => $health['schema']['mismatched_core_indexes'] ?? array(),
            'tables_without_primary_key' => $health['schema']['tables_without_primary_key'],
            'integrity' => $health['integrity'],
        );
    }

    public static function database( $deep = false, $force_large = false ) {
        $health = WPI_Database_Health::inspect( $deep, $force_large );
        return array_merge( self::database_compat( $health ), array( 'health' => $health ) );
    }

    public static function server() {
        $system = WPI_System_Health::inspect( false );
        $db = WPI_Database_Health::inspect( false, false );
        return array_merge( $system['php'], array( 'db_version' => $db['server']['version'], 'db_variables' => $db['server']['variables'], 'db_status' => $db['server']['status'] ) );
    }

    public static function autoload() {
        $db = WPI_Database_Health::inspect( false, false );
        return array( 'total_bytes' => $db['options']['autoload_bytes'], 'largest' => $db['options']['largest_autoload'] );
    }

    public static function cron() { return WPI_Job_Health::inspect( false, false )['cron']; }
    public static function cache() { return WPI_Cache_Health::inspect(); }
    public static function action_scheduler( $deep = false, $force_large = false ) { return WPI_Job_Health::inspect( $deep, $force_large )['action_scheduler']; }
    public static function plugins() { return WPI_System_Health::plugins()['plugins']; }

    public static function summary() {
        return WPI_Utils::incident_summary();
    }
}
