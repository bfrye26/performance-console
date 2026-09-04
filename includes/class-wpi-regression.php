<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPI_Regression {
    public static function init() {
        add_action( 'upgrader_process_complete', array( __CLASS__, 'upgrade_complete' ), 10, 2 );
        add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ), 10, 2 );
        add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ), 10, 2 );
        add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 3 );
    }

    public static function upgrade_complete( $upgrader, $options ) {
        if ( empty( $options['action'] ) || 'update' !== $options['action'] ) { return; }
        $type = sanitize_key( $options['type'] ?? 'unknown' );
        $objects = array();
        if ( 'plugin' === $type ) {
            $objects = isset( $options['plugins'] ) ? (array) $options['plugins'] : array_filter( array( $options['plugin'] ?? '' ) );
        } elseif ( 'theme' === $type ) {
            $objects = isset( $options['themes'] ) ? (array) $options['themes'] : array_filter( array( $options['theme'] ?? '' ) );
        } elseif ( 'core' === $type ) {
            global $wp_version; $objects = array( 'wordpress-' . $wp_version );
        }
        foreach ( $objects as $object ) { self::record( 'update_' . $type, (string) $object, '', self::version_for( $type, (string) $object ) ); }
    }

    public static function plugin_activated( $plugin, $network_wide ) { self::record( 'plugin_activated', (string) $plugin, '', self::version_for( 'plugin', (string) $plugin ) ); }
    public static function plugin_deactivated( $plugin, $network_wide ) { self::record( 'plugin_deactivated', (string) $plugin, self::version_for( 'plugin', (string) $plugin ), '' ); }
    public static function theme_switched( $new_name, $new_theme, $old_theme ) {
        $before = is_object( $old_theme ) && method_exists( $old_theme, 'get_stylesheet' ) ? $old_theme->get_stylesheet() : '';
        $after = is_object( $new_theme ) && method_exists( $new_theme, 'get_stylesheet' ) ? $new_theme->get_stylesheet() : sanitize_text_field( $new_name );
        self::record( 'theme_switched', $after, $before, $after );
    }

    private static function version_for( $type, $object ) {
        if ( 'plugin' === $type ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all = get_plugins(); return sanitize_text_field( $all[ $object ]['Version'] ?? '' );
        }
        if ( 'theme' === $type ) {
            $theme = wp_get_theme( $object ); return $theme->exists() ? sanitize_text_field( $theme->get( 'Version' ) ) : '';
        }
        return '';
    }

    private static function record( $type, $object, $before, $after ) {
        global $wpdb;
        $wpdb->insert( WPI_Utils::table( 'changes' ), array(
            'created_at' => WPI_Utils::now_mysql(), 'change_type' => sanitize_key( $type ), 'object_name' => sanitize_text_field( $object ),
            'before_value' => (string) $before, 'after_value' => (string) $after, 'user_id' => get_current_user_id(),
        ) );
    }

    public static function inspect() {
        global $wpdb;
        $runs = WPI_Utils::table( 'runs' );
        $now = time();
        $recent_cut = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
        $baseline_cut = gmdate( 'Y-m-d H:i:s', $now - 8 * DAY_IN_SECONDS );
        $routes = $wpdb->get_results( $wpdb->prepare( "SELECT route,COUNT(*) samples FROM {$runs} WHERE mode='sample' AND created_at >= %s GROUP BY route ORDER BY samples DESC LIMIT 20", $baseline_cut ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $regressions = array();
        foreach ( (array) $routes as $row ) {
            $route = (string) $row['route'];
            if ( ! $route ) { continue; }
            $recent = $wpdb->get_col( $wpdb->prepare( "SELECT php_ms FROM {$runs} WHERE mode='sample' AND route=%s AND created_at >= %s ORDER BY id DESC LIMIT 200", $route, $recent_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $base = $wpdb->get_col( $wpdb->prepare( "SELECT php_ms FROM {$runs} WHERE mode='sample' AND route=%s AND created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT 300", $route, $baseline_cut, $recent_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( count( $recent ) < 5 || count( $base ) < 10 ) { continue; }
            $rm = self::median( array_map( 'floatval', $recent ) );
            $bm = self::median( array_map( 'floatval', $base ) );
            $delta = $rm - $bm;
            if ( $bm > 0 && $rm > $bm * 1.30 && $delta >= 100 ) {
                $first_recent = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$runs} WHERE mode='sample' AND route=%s AND created_at >= %s", $route, $recent_cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $candidate_change = $first_recent ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPI_Utils::table( 'changes' ) . ' WHERE created_at <= %s AND created_at >= %s ORDER BY created_at DESC LIMIT 1', $first_recent, gmdate( 'Y-m-d H:i:s', strtotime( $first_recent . ' UTC' ) - 3 * DAY_IN_SECONDS ) ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $regressions[] = array( 'route' => $route, 'recent_median' => round( $rm, 1 ), 'baseline_median' => round( $bm, 1 ), 'delta' => round( $delta, 1 ), 'percent' => round( 100 * $delta / $bm, 1 ), 'recent_samples' => count( $recent ), 'baseline_samples' => count( $base ), 'candidate_change' => $candidate_change );
            }
        }
        $changes = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WPI_Utils::table( 'changes' ) . ' WHERE created_at >= %s ORDER BY id DESC LIMIT 50', gmdate( 'Y-m-d H:i:s', $now - 14 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array( 'regressions' => $regressions, 'recent_changes' => $changes );
    }

    private static function median( array $values ) { if ( ! $values ) { return 0; } sort( $values, SORT_NUMERIC ); $n=count($values); $m=(int)floor($n/2); return $n%2?$values[$m]:(($values[$m-1]+$values[$m])/2); }

    public static function generate_issues( array $health ) {
        foreach ( $health['regressions'] as $r ) {
            $change_note = '';
            if ( ! empty( $r['candidate_change'] ) ) {
                $c = $r['candidate_change'];
                $change_note = ' A potentially related preceding change is ' . sanitize_text_field( $c['change_type'] . ' ' . $c['object_name'] ) . ' at ' . sanitize_text_field( $c['created_at'] ) . '; timing alone does not prove causation.';
            }
            WPI_Utils::issue( 'regression', 'high', 'Request performance regression detected', 'Median PHP time for this route increased from approximately ' . esc_html( $r['baseline_median'] ) . ' ms to ' . esc_html( $r['recent_median'] ) . ' ms (' . esc_html( $r['percent'] ) . '%).' . esc_html( $change_note ), '+' . $r['delta'] . ' ms', 'Compare a deep profile with the baseline and run a paired plugin-impact test before attributing the change.', $r['route'], array( 'confidence' => ! empty( $r['candidate_change'] ) ? 65 : 55 ) );
        }
    }
}
