<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WPI_Cache_Health {
    public static function inspect() {
        global $wp_object_cache;
        $supports = array();
        foreach ( array( 'add_multiple','set_multiple','get_multiple','delete_multiple','flush_runtime','flush_group' ) as $feature ) {
            $supports[ $feature ] = function_exists( 'wp_cache_supports' ) ? wp_cache_supports( $feature ) : false;
        }
        $stats = array();
        if ( is_object( $wp_object_cache ) ) {
            foreach ( array( 'cache_hits','cache_misses','hits','misses' ) as $property ) {
                if ( isset( $wp_object_cache->$property ) && is_numeric( $wp_object_cache->$property ) ) { $stats[ $property ] = (int) $wp_object_cache->$property; }
            }
        }
        $roundtrip = self::roundtrip();
        $page_cache = self::page_cache_health();
        return array(
            'persistent' => wp_using_ext_object_cache(),
            'class' => is_object( $wp_object_cache ) ? get_class( $wp_object_cache ) : '',
            'dropin' => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
            'advanced_cache' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
            'wp_cache_constant' => defined( 'WP_CACHE' ) && WP_CACHE,
            'supports' => $supports,
            'stats_current_request' => $stats,
            'roundtrip' => $roundtrip,
            'dropin_metadata' => self::dropin_metadata(),
            'page_cache' => $page_cache,
        );
    }

    private static function roundtrip() {
        $key = 'probe-' . wp_generate_uuid4();
        $group = 'wpi-diagnostics';
        $value = wp_generate_uuid4();
        $start = microtime( true );
        $set = wp_cache_set( $key, $value, $group, 30 );
        $set_ms = ( microtime( true ) - $start ) * 1000;
        $found = null;
        $start = microtime( true );
        $got = wp_cache_get( $key, $group, false, $found );
        $get_ms = ( microtime( true ) - $start ) * 1000;
        wp_cache_delete( $key, $group );
        return array( 'ok' => (bool) $set && true === $found && $got === $value, 'set_ms' => round( $set_ms, 3 ), 'get_ms' => round( $get_ms, 3 ) );
    }

    private static function dropin_metadata() {
        $file = WP_CONTENT_DIR . '/object-cache.php';
        if ( ! is_readable( $file ) ) { return array(); }
        $data = get_file_data( $file, array( 'plugin' => 'Plugin Name', 'version' => 'Version', 'author' => 'Author' ) );
        return array_map( 'sanitize_text_field', $data );
    }


    private static function page_cache_health() {
        $file = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        if ( file_exists( $file ) ) { require_once $file; }
        if ( ! class_exists( 'WP_Site_Health' ) || ! method_exists( 'WP_Site_Health', 'get_instance' ) ) { return array( 'available' => false ); }
        try {
            $instance = WP_Site_Health::get_instance();
            if ( ! is_object( $instance ) || ! method_exists( $instance, 'get_test_page_cache' ) ) { return array( 'available' => false ); }
            $result = $instance->get_test_page_cache();
            if ( ! is_array( $result ) ) { return array( 'available' => false ); }
            return array(
                'available' => true,
                'status' => sanitize_key( $result['status'] ?? '' ),
                'label' => sanitize_text_field( wp_strip_all_tags( $result['label'] ?? '' ) ),
            );
        } catch ( Throwable $e ) {
            return array( 'available' => false, 'error' => sanitize_text_field( $e->getMessage() ) );
        }
    }

    public static function generate_issues( array $cache ) {
        if ( ! $cache['persistent'] ) {
            WPI_Utils::issue( 'cache', 'info', 'No persistent object cache is active', 'WordPress reports that an external persistent object cache is not in use. This is an opportunity to evaluate, not evidence of a fault or slowdown.', 'Benefit depends on dynamic traffic and database work', 'Use representative dynamic-request measurements to decide whether Redis or Memcached is worthwhile. A lightly used, page-cached site may not benefit.' );
        }
        if ( $cache['dropin'] && ! $cache['persistent'] ) {
            WPI_Utils::issue( 'cache', 'critical', 'object-cache.php exists but WordPress is not using an external object cache', 'A cache drop-in is installed but wp_using_ext_object_cache() is false.', 'Possible broken or disabled cache integration', 'Inspect the object-cache.php provider configuration and connection errors. A broken drop-in can add latency without providing persistence.' );
        }
        if ( $cache['persistent'] && empty( $cache['roundtrip']['ok'] ) ) {
            WPI_Utils::issue( 'cache', 'critical', 'Persistent object cache failed a set/get round-trip', 'The diagnostic key could not be read back in the same request. This test does not verify persistence between requests.', 'Object cache may be unreliable', 'Check Redis/Memcached connectivity, authentication, database selection, eviction policy and the object-cache drop-in logs.' );
        }
        if ( ! empty( $cache['roundtrip']['ok'] ) && $cache['roundtrip']['get_ms'] > 10 ) {
            WPI_Utils::issue( 'cache', 'high', 'Object cache round-trip is slow', 'A single cache GET took approximately ' . esc_html( $cache['roundtrip']['get_ms'] ) . ' ms from PHP.', $cache['roundtrip']['get_ms'] . ' ms GET', 'Check network distance, Redis/Memcached CPU saturation, TLS/proxy overhead and connection reuse.' );
        }
        $stats = $cache['stats_current_request'];
        $hits = isset( $stats['cache_hits'] ) ? $stats['cache_hits'] : ( $stats['hits'] ?? null );
        $misses = isset( $stats['cache_misses'] ) ? $stats['cache_misses'] : ( $stats['misses'] ?? null );
        if ( null !== $hits && null !== $misses && ( $hits + $misses ) > 100 ) {
            $ratio = $hits / max( 1, $hits + $misses );
            if ( $ratio < 0.70 ) {
                WPI_Utils::issue( 'cache', 'warning', 'Object-cache hit rate is low on the scan request', 'The current request reported an approximate hit ratio of ' . esc_html( round( $ratio * 100, 1 ) ) . '%.', round( $ratio * 100, 1 ) . '% hit rate', 'Compare representative frontend requests and investigate cache churn, short TTLs, non-persistent groups and frequently invalidated keys.' );
            }
        }
        if ( defined( 'WP_CACHE' ) && WP_CACHE && ! $cache['advanced_cache'] ) {
            WPI_Utils::issue( 'cache', 'warning', 'WP_CACHE is enabled without an advanced-cache.php drop-in', 'WP_CACHE is true but no advanced-cache.php file exists.', 'Page-cache configuration may be incomplete', 'Confirm whether caching is handled entirely upstream (Nginx/Varnish/CDN). If not, repair the page-cache integration.' );
        }
        if ( ! empty( $cache['page_cache']['available'] ) && ! empty( $cache['page_cache']['status'] ) && 'good' !== $cache['page_cache']['status'] ) {
            WPI_Utils::issue( 'cache', 'high', 'WordPress Site Health could not confirm effective page caching', esc_html( $cache['page_cache']['label'] ?: 'The built-in page-cache test did not return a good result.' ), 'Dynamic PHP may be reached more often than necessary', 'Confirm the production cache path at the CDN/reverse proxy/page-cache layer. Test both anonymous cache HITs and intentional bypasses before adding another cache plugin.' );
        }
    }
}
