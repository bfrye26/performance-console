<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WordPress, PHP, filesystem, error-log, plugin and hook diagnostics.
 */
final class PFC_System_Health {
    public static function inspect( $deep = false ) {
        return array(
            'wordpress' => self::wordpress(),
            'php'       => self::php(),
            'filesystem'=> self::filesystem(),
            'plugins'   => self::plugins(),
            'hooks'     => self::hooks(),
            'errors'    => self::errors( $deep ),
            'dropins'   => self::dropins(),
        );
    }

    private static function wordpress() {
        global $wp_version;
        return array(
            'version' => (string) $wp_version,
            'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
            'multisite' => is_multisite(),
            'wp_cache' => defined( 'WP_CACHE' ) && WP_CACHE,
            'wp_debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
            'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false,
            'savequeries' => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
            'script_debug' => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
            'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'alternate_wp_cron' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
            'memory_limit' => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '',
            'max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '',
            'object_cache' => wp_using_ext_object_cache(),
        );
    }

    private static function php() {
        $op = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;
        $opcache = null;
        if ( is_array( $op ) ) {
            $opcache = array(
                'enabled' => ! empty( $op['opcache_enabled'] ),
                'used_memory' => (int) ( $op['memory_usage']['used_memory'] ?? 0 ),
                'free_memory' => (int) ( $op['memory_usage']['free_memory'] ?? 0 ),
                'wasted_memory' => (int) ( $op['memory_usage']['wasted_memory'] ?? 0 ),
                'wasted_percentage' => (float) ( $op['memory_usage']['current_wasted_percentage'] ?? 0 ),
                'hit_rate' => (float) ( $op['opcache_statistics']['opcache_hit_rate'] ?? 0 ),
                'cached_scripts' => (int) ( $op['opcache_statistics']['num_cached_scripts'] ?? 0 ),
                'max_keys' => (int) ( $op['opcache_statistics']['max_cached_keys'] ?? 0 ),
                'oom_restarts' => (int) ( $op['opcache_statistics']['oom_restarts'] ?? 0 ),
                'hash_restarts' => (int) ( $op['opcache_statistics']['hash_restarts'] ?? 0 ),
                'manual_restarts' => (int) ( $op['opcache_statistics']['manual_restarts'] ?? 0 ),
                'restart_pending' => ! empty( $op['restart_pending'] ),
            );
        }
        return array(
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get( 'memory_limit' ),
            'max_execution_time' => (int) ini_get( 'max_execution_time' ),
            'max_input_vars' => (int) ini_get( 'max_input_vars' ),
            'realpath_cache_size' => ini_get( 'realpath_cache_size' ),
            'realpath_cache_ttl' => ini_get( 'realpath_cache_ttl' ),
            'realpath_cache_used' => function_exists( 'realpath_cache_size' ) ? (int) realpath_cache_size() : null,
            'output_buffering' => ini_get( 'output_buffering' ),
            'zlib_output_compression' => (bool) ini_get( 'zlib.output_compression' ),
            'opcache_configured' => (bool) ini_get( 'opcache.enable' ),
            'opcache_memory' => ini_get( 'opcache.memory_consumption' ),
            'opcache_max_files' => (int) ini_get( 'opcache.max_accelerated_files' ),
            'opcache_validate_timestamps' => (bool) ini_get( 'opcache.validate_timestamps' ),
            'opcache' => $opcache,
            'extensions' => array_values( array_intersect( array( 'redis','memcached','apcu','imagick','mysqli','curl','mbstring','intl','zip' ), get_loaded_extensions() ) ),
        );
    }

    private static function filesystem() {
        $paths = array(
            'wp_content' => WP_CONTENT_DIR,
            'plugins' => WP_PLUGIN_DIR,
            'uploads' => function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false )['basedir'] : '',
            'mu_plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
        );
        $out = array();
        foreach ( $paths as $key => $path ) {
            if ( ! $path ) { continue; }
            $free = function_exists( 'disk_free_space' ) ? @disk_free_space( $path ) : false;
            $total = function_exists( 'disk_total_space' ) ? @disk_total_space( $path ) : false;
            $out[ $key ] = array(
                'path' => wp_normalize_path( $path ),
                'exists' => file_exists( $path ),
                'writable' => is_writable( $path ),
                'free' => false === $free ? null : (int) $free,
                'total' => false === $total ? null : (int) $total,
            );
        }
        return array(
            'method' => function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : '',
            'paths' => $out,
        );
    }

    public static function plugins() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $all = get_plugins();
        $active = (array) get_option( 'active_plugins', array() );
        $network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
        $included = array_map( 'wp_normalize_path', get_included_files() );
        $hook_map = self::hook_component_counts();
        $autoload_rows = $wpdb->get_results( "SELECT option_name,LENGTH(option_value) bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $table_rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
        $plugins = array();
        foreach ( $all as $file => $data ) {
            $slug = dirname( $file );
            if ( '.' === $slug ) { $slug = pathinfo( $file, PATHINFO_FILENAME ); }
            $base = wp_normalize_path( WP_PLUGIN_DIR . '/' . dirname( $file ) ) . '/';
            $included_count = 0;
            foreach ( $included as $inc ) {
                if ( 0 === strpos( $inc, $base ) || $inc === wp_normalize_path( WP_PLUGIN_DIR . '/' . $file ) ) { $included_count++; }
            }
            $tokens = self::plugin_tokens( $slug, $data );
            $autoload_bytes = 0; $autoload_names = array();
            foreach ( (array) $autoload_rows as $option ) {
                if ( self::name_matches_tokens( (string) $option['option_name'], $tokens ) ) { $autoload_bytes += (int) $option['bytes']; if ( count( $autoload_names ) < 10 ) { $autoload_names[] = (string) $option['option_name']; } }
            }
            $db_bytes = 0; $db_tables = array();
            foreach ( (array) $table_rows as $table ) {
                $table_name = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', (string) ( $table['Name'] ?? '' ) );
                if ( self::name_matches_tokens( $table_name, $tokens ) ) { $size = (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 ); $db_bytes += $size; if ( count( $db_tables ) < 10 ) { $db_tables[] = (string) $table['Name']; } }
            }
            $plugins[] = array(
                'file' => $file,
                'slug' => $slug,
                'name' => sanitize_text_field( $data['Name'] ?? $slug ),
                'version' => sanitize_text_field( $data['Version'] ?? '' ),
                'requires_php' => sanitize_text_field( $data['RequiresPHP'] ?? '' ),
                'requires_wp' => sanitize_text_field( $data['RequiresWP'] ?? '' ),
                'active' => in_array( $file, $active, true ) || in_array( $file, $network, true ),
                'network_active' => in_array( $file, $network, true ),
                'included_php_files_current_request' => $included_count,
                'registered_callbacks_current_request' => (int) ( $hook_map[ $slug ]['callbacks'] ?? 0 ),
                'registered_hooks_current_request' => (int) ( $hook_map[ $slug ]['hooks'] ?? 0 ),
                'autoload_bytes_estimate' => $autoload_bytes,
                'autoload_options_estimate' => $autoload_names,
                'database_bytes_estimate' => $db_bytes,
                'database_tables_estimate' => $db_tables,
            );
        }
        usort( $plugins, static function ( $a, $b ) {
            if ( $a['active'] !== $b['active'] ) { return $a['active'] ? -1 : 1; }
            return $b['registered_callbacks_current_request'] <=> $a['registered_callbacks_current_request'];
        } );

        $paused = array();
        if ( function_exists( 'wp_paused_plugins' ) ) {
            $storage = wp_paused_plugins();
            if ( is_object( $storage ) && method_exists( $storage, 'get_all' ) ) { $paused = (array) $storage->get_all(); }
        }

        return array(
            'active_count' => count( array_filter( $plugins, static function ( $p ) { return $p['active']; } ) ),
            'plugins' => array_slice( $plugins, 0, 250 ),
            'paused' => $paused,
            'suspected_overlap' => self::plugin_overlap( $plugins ),
        );
    }

    private static function plugin_tokens( $slug, array $data ) {
        $raw = array( $slug, $data['TextDomain'] ?? '', pathinfo( $data['Name'] ?? '', PATHINFO_FILENAME ) );
        $tokens = array();
        foreach ( $raw as $item ) {
            $item = strtolower( preg_replace( '/[^a-z0-9]+/', '_', (string) $item ) );
            $item = trim( $item, '_' );
            if ( strlen( $item ) >= 4 ) { $tokens[] = $item; }
            if ( false !== strpos( $item, '_' ) ) {
                $first = strtok( $item, '_' ); if ( strlen( $first ) >= 5 ) { $tokens[] = $first; }
            }
        }
        return array_values( array_unique( $tokens ) );
    }

    private static function name_matches_tokens( $name, array $tokens ) {
        $name = strtolower( preg_replace( '/[^a-z0-9]+/', '_', (string) $name ) );
        $name = trim( $name, '_' );
        foreach ( $tokens as $token ) {
            if ( $name === $token || 0 === strpos( $name, $token . '_' ) || false !== strpos( $name, '_' . $token . '_' ) ) { return true; }
        }
        return false;
    }

    private static function plugin_overlap( array $plugins ) {
        $active_slugs = array();
        foreach ( $plugins as $plugin ) { if ( $plugin['active'] ) { $active_slugs[] = strtolower( $plugin['slug'] ); } }
        $groups = array(
            'page/object caching' => array( 'wp-rocket','w3-total-cache','wp-super-cache','litespeed-cache','sg-cachepress','redis-cache','object-cache-pro','powered-cache','breeze' ),
            'SEO' => array( 'wordpress-seo','seo-by-rank-math','all-in-one-seo-pack','seopress','the-seo-framework' ),
            'image optimization' => array( 'imagify','shortpixel-image-optimiser','ewww-image-optimizer','optimole-wp','smush' ),
        );
        $out = array();
        foreach ( $groups as $label => $known ) {
            $matches = array_values( array_intersect( $active_slugs, $known ) );
            if ( count( $matches ) > 1 ) { $out[] = array( 'category' => $label, 'plugins' => $matches ); }
        }
        return $out;
    }

    private static function hooks() {
        global $wp_filter;
        $heaviest = array();
        $total_callbacks = 0;
        if ( ! is_array( $wp_filter ) ) { return array( 'total_callbacks' => 0, 'heaviest_hooks' => array(), 'components' => array() ); }
        foreach ( $wp_filter as $hook => $obj ) {
            if ( ! is_object( $obj ) || ! isset( $obj->callbacks ) || ! is_array( $obj->callbacks ) ) { continue; }
            $count = 0;
            foreach ( $obj->callbacks as $callbacks ) { $count += is_array( $callbacks ) ? count( $callbacks ) : 0; }
            if ( $count ) { $heaviest[ (string) $hook ] = $count; $total_callbacks += $count; }
        }
        arsort( $heaviest );
        return array(
            'total_callbacks' => $total_callbacks,
            'registered_hooks' => count( $heaviest ),
            'heaviest_hooks' => array_slice( $heaviest, 0, 40, true ),
            'components' => self::hook_component_counts(),
        );
    }

    private static function hook_component_counts() {
        global $wp_filter;
        $components = array();
        if ( ! is_array( $wp_filter ) ) { return $components; }
        foreach ( $wp_filter as $hook => $obj ) {
            if ( ! is_object( $obj ) || ! isset( $obj->callbacks ) || ! is_array( $obj->callbacks ) ) { continue; }
            $seen = array();
            foreach ( $obj->callbacks as $callbacks ) {
                foreach ( (array) $callbacks as $cb ) {
                    $file = self::callback_file( $cb['function'] ?? null );
                    if ( ! $file ) { continue; }
                    $component = PFC_Utils::component_from_file( $file );
                    if ( 'plugin' !== $component['type'] && 'mu-plugin' !== $component['type'] && 'theme' !== $component['type'] ) { continue; }
                    $key = $component['slug'];
                    if ( ! isset( $components[ $key ] ) ) { $components[ $key ] = array( 'type' => $component['type'], 'callbacks' => 0, 'hooks' => 0 ); }
                    $components[ $key ]['callbacks']++;
                    $seen[ $key ] = true;
                }
            }
            foreach ( array_keys( $seen ) as $key ) { $components[ $key ]['hooks']++; }
        }
        uasort( $components, static function ( $a, $b ) { return $b['callbacks'] <=> $a['callbacks']; } );
        return array_slice( $components, 0, 100, true );
    }

    private static function callback_file( $callback ) {
        try {
            if ( is_string( $callback ) && function_exists( $callback ) ) {
                return ( new ReflectionFunction( $callback ) )->getFileName();
            }
            if ( $callback instanceof Closure ) { return ( new ReflectionFunction( $callback ) )->getFileName(); }
            if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
                return ( new ReflectionMethod( $callback[0], $callback[1] ) )->getFileName();
            }
            if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) { return ( new ReflectionMethod( $callback, '__invoke' ) )->getFileName(); }
            if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
                list( $class, $method ) = explode( '::', $callback, 2 );
                if ( method_exists( $class, $method ) ) { return ( new ReflectionMethod( $class, $method ) )->getFileName(); }
            }
        } catch ( ReflectionException $e ) { return ''; }
        return '';
    }

    private static function dropins() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $dropins = get_dropins();
        $out = array();
        foreach ( (array) $dropins as $file => $data ) {
            $out[] = array( 'file' => $file, 'name' => sanitize_text_field( $data['Name'] ?? $file ) );
        }
        return $out;
    }

    private static function errors( $deep ) {
        $sources = array();
        $debug_path = self::debug_log_path();
        if ( $debug_path ) { $sources[] = $debug_path; }
        $ini_log = (string) ini_get( 'error_log' );
        if ( $ini_log && 'syslog' !== strtolower( $ini_log ) && is_readable( $ini_log ) ) {
            $normalized_log = wp_normalize_path( $ini_log );
            $root = wp_normalize_path( ABSPATH );
            $content = wp_normalize_path( WP_CONTENT_DIR );
            if ( 0 === strpos( $normalized_log, $root ) || 0 === strpos( $normalized_log, $content ) ) { $sources[] = $ini_log; }
        }
        $sources = array_values( array_unique( $sources ) );
        $source_details = array();
        foreach ( array_slice( $sources, 0, 2 ) as $path ) {
            $size = @filesize( $path );
            $source_details[] = array( 'path' => wp_normalize_path( $path ), 'bytes' => false === $size ? null : (int) $size );
        }
        $findings = array();
        foreach ( array_slice( $sources, 0, 2 ) as $path ) {
            foreach ( self::tail_errors( $path, $deep ? 4 * MB_IN_BYTES : MB_IN_BYTES ) as $item ) {
                $key = md5( $item['type'] . '|' . $item['component'] . '|' . $item['fingerprint'] );
                if ( ! isset( $findings[ $key ] ) ) { $findings[ $key ] = $item + array( 'count' => 0 ); }
                $findings[ $key ]['count']++;
            }
        }
        uasort( $findings, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
        return array( 'sources' => array_map( 'wp_normalize_path', $sources ), 'source_details' => $source_details, 'findings' => array_slice( array_values( $findings ), 0, 100 ) );
    }

    private static function debug_log_path() {
        if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) { return ''; }
        if ( is_string( WP_DEBUG_LOG ) ) { return is_readable( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : ''; }
        $path = WP_CONTENT_DIR . '/debug.log';
        return is_readable( $path ) ? $path : '';
    }

    private static function tail_errors( $path, $bytes ) {
        if ( ! is_readable( $path ) || ! is_file( $path ) ) { return array(); }
        $size = @filesize( $path );
        if ( false === $size || 0 === $size ) { return array(); }
        $bytes = max( 32768, min( 8 * MB_IN_BYTES, (int) $bytes ) );
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) { return array(); }
        $offset = max( 0, $size - $bytes );
        fseek( $handle, $offset );
        $data = stream_get_contents( $handle );
        fclose( $handle );
        if ( $offset > 0 ) { $first = strpos( $data, "\n" ); if ( false !== $first ) { $data = substr( $data, $first + 1 ); } }
        $lines = preg_split( '/\r?\n/', (string) $data );
        $out = array();
        foreach ( $lines as $line ) {
            if ( ! preg_match( '/(PHP (?:Fatal error|Warning|Notice|Deprecated|Parse error)|Uncaught |Allowed memory size|Maximum execution time|WordPress database error|MySQL server has gone away|Deadlock found|Lock wait timeout)/i', $line, $m ) ) { continue; }
            $type = strtolower( preg_replace( '/\s+/', '-', trim( $m[1] ) ) );
            $component = 'unknown';
            $file = '';
            if ( preg_match( '#(/[^\s:]+/wp-content/(?:plugins|mu-plugins|themes)/[^\s:]+\.php)#i', $line, $fm ) ) {
                $file = wp_normalize_path( $fm[1] );
                $comp = PFC_Utils::component_from_file( $file );
                $component = $comp['type'] . ':' . $comp['slug'];
            }
            $safe = preg_replace( '/\[[^\]]{0,100}\]/', '', $line );
            $safe = preg_replace( "/'(?:''|\\\\.|[^'])*'/s", '?', $safe );
            $safe = preg_replace( '/"(?:""|\\\\.|[^"])*"/s', '?', $safe );
            $safe = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $safe );
            $safe = preg_replace( '/https?:\/\/\S+/i', '[url]', $safe );
            $safe = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $safe );
            $safe = trim( mb_substr( $safe, 0, 600 ) );
            $out[] = array( 'type' => $type, 'component' => $component, 'file' => $file, 'fingerprint' => $safe );
        }
        return $out;
    }

    public static function generate_issues( array $health ) {
        $wp = $health['wordpress'];
        if ( $wp['savequeries'] && ! defined( 'PFC_DEEP_DIAGNOSTIC' ) ) {
            PFC_Utils::issue( 'wordpress', 'high', 'SAVEQUERIES is enabled globally', 'WordPress is storing every SQL query and timing/backtrace data on ordinary requests.', 'Adds memory and runtime overhead to every request', 'Disable SAVEQUERIES in production. Performance Console enables query collection only for signed diagnostic requests.' );
        }
        if ( 'production' === $wp['environment'] && $wp['wp_debug_display'] ) {
            PFC_Utils::issue( 'wordpress', 'warning', 'PHP/WordPress errors may be displayed in production', 'WP_DEBUG_DISPLAY is enabled in a production environment.', 'Can expose errors and add noisy output', 'Log errors privately and disable display_errors/WP_DEBUG_DISPLAY on production.' );
        }

        $php = $health['php'];
        if ( ! $php['opcache_configured'] ) {
            PFC_Utils::issue( 'server', 'critical', 'PHP OPcache is disabled', 'PHP scripts are not benefiting from a persistent bytecode cache.', 'Repeated PHP compilation', 'Enable OPcache for the PHP-FPM/web SAPI and size it for the application.' );
        }
        if ( is_array( $php['opcache'] ) ) {
            $op = $php['opcache'];
            $capacity = $op['used_memory'] + $op['free_memory'];
            if ( $capacity > 0 && $op['used_memory'] / $capacity > 0.95 ) {
                PFC_Utils::issue( 'server', 'high', 'OPcache memory is nearly full', 'OPcache memory utilization is above 95%.', round( 100 * $op['used_memory'] / $capacity, 1 ) . '% used', 'Increase opcache.memory_consumption after confirming PHP workers share this cache and checking total server memory.' );
            }
            if ( $op['max_keys'] > 0 && $op['cached_scripts'] / $op['max_keys'] > 0.9 ) {
                PFC_Utils::issue( 'server', 'high', 'OPcache script table is nearly full', number_format_i18n( $op['cached_scripts'] ) . ' of ' . number_format_i18n( $op['max_keys'] ) . ' script slots are in use.', round( 100 * $op['cached_scripts'] / $op['max_keys'], 1 ) . '% used', 'Increase opcache.max_accelerated_files to leave headroom for WordPress, themes and plugins.' );
            }
            if ( $op['restart_pending'] || $op['oom_restarts'] > 0 || $op['hash_restarts'] > 0 ) {
                PFC_Utils::issue( 'server', 'high', 'OPcache has restarted or is pending a restart', 'OOM restarts: ' . intval( $op['oom_restarts'] ) . '; hash restarts: ' . intval( $op['hash_restarts'] ) . '.', 'Cache churn can cause latency spikes', 'Increase OPcache capacity and inspect deployment/restart frequency.' );
            }
        }

        foreach ( $health['filesystem']['paths'] as $key => $path ) {
            if ( ! empty( $path['total'] ) && null !== $path['free'] && $path['free'] / $path['total'] < 0.10 ) {
                PFC_Utils::issue( 'filesystem', 'high', 'Filesystem is low on free space', esc_html( $key ) . ' has ' . esc_html( size_format( $path['free'] ) ) . ' free.', round( 100 * $path['free'] / $path['total'], 1 ) . '% free', 'Free disk space before database/log/cache writes start failing or slowing down.' );
            }
        }

        $plugins = $health['plugins'];
        if ( ! empty( $plugins['paused'] ) ) {
            foreach ( $plugins['paused'] as $plugin => $error ) {
                PFC_Utils::issue( 'plugin', 'critical', 'WordPress Recovery Mode has paused a plugin', '<code>' . esc_html( $plugin ) . '</code> has a stored fatal error.', 'Plugin has already caused a fatal error', 'Review the stored recovery error and plugin logs before resuming the extension.' );
            }
        }
        foreach ( $plugins['suspected_overlap'] as $overlap ) {
            PFC_Utils::issue( 'plugin', 'warning', 'Multiple active plugins appear to overlap in ' . $overlap['category'], esc_html( implode( ', ', $overlap['plugins'] ) ), 'Possible duplicate hooks, cache layers or processing', 'Confirm that each plugin has a distinct responsibility. Disable redundant modules rather than stacking equivalent optimization layers.' );
        }
        global $wp_version;
        foreach ( $plugins['plugins'] as $plugin ) {
            if ( ! $plugin['active'] ) { continue; }
            if ( ! empty( $plugin['requires_php'] ) && version_compare( PHP_VERSION, $plugin['requires_php'], '<' ) ) {
                PFC_Utils::issue( 'plugin', 'critical', 'Active plugin requires a newer PHP version', '<code>' . esc_html( $plugin['name'] ) . '</code> declares PHP ' . esc_html( $plugin['requires_php'] ) . '+ but the site is running ' . esc_html( PHP_VERSION ) . '.', 'Compatibility failures/fatals possible', 'Upgrade PHP using a staging test first, or use a plugin version that explicitly supports the current PHP runtime.' );
            }
            if ( ! empty( $plugin['requires_wp'] ) && version_compare( (string) $wp_version, $plugin['requires_wp'], '<' ) ) {
                PFC_Utils::issue( 'plugin', 'critical', 'Active plugin requires a newer WordPress version', '<code>' . esc_html( $plugin['name'] ) . '</code> declares WordPress ' . esc_html( $plugin['requires_wp'] ) . '+ but the site is running ' . esc_html( $wp_version ) . '.', 'Compatibility failures possible', 'Test and update WordPress, or use a plugin version compatible with the installed WordPress release.' );
            }
            if ( $plugin['registered_callbacks_current_request'] >= 250 ) {
                PFC_Utils::issue( 'plugin', 'warning', 'Plugin registers an unusually large number of callbacks', '<code>' . esc_html( $plugin['name'] ) . '</code> registered ' . intval( $plugin['registered_callbacks_current_request'] ) . ' callbacks on this wp-admin request.', $plugin['registered_callbacks_current_request'] . ' callbacks', 'Run a signed frontend profile and plugin-impact benchmark. High callback count alone is not proof of slowness, but it is a useful attribution signal.' );
            }
            if ( $plugin['included_php_files_current_request'] >= 150 ) {
                PFC_Utils::issue( 'plugin', 'warning', 'Plugin loads a very large PHP file graph', '<code>' . esc_html( $plugin['name'] ) . '</code> accounted for at least ' . intval( $plugin['included_php_files_current_request'] ) . ' included PHP files on the current request.', $plugin['included_php_files_current_request'] . ' PHP files', 'Profile the plugin on representative frontend/admin routes and verify OPcache capacity. Consider disabling unused modules if the plugin supports it.' );
            }
            if ( (int) $plugin['autoload_bytes_estimate'] >= MB_IN_BYTES ) {
                PFC_Utils::issue( 'plugin', 'high', 'Plugin appears to own a large autoload footprint', '<code>' . esc_html( $plugin['name'] ) . '</code> matches approximately ' . esc_html( size_format( (int) $plugin['autoload_bytes_estimate'] ) ) . ' of autoloaded option data by option-name prefix.', size_format( (int) $plugin['autoload_bytes_estimate'] ), 'Treat ownership as a prefix-based estimate. Verify the listed option names and sampled usage before changing autoload behaviour.' );
            }
            if ( (int) $plugin['database_bytes_estimate'] >= 5 * GB_IN_BYTES ) {
                PFC_Utils::issue( 'plugin', 'warning', 'Plugin appears to own a very large database footprint', '<code>' . esc_html( $plugin['name'] ) . '</code> matches approximately ' . esc_html( size_format( (int) $plugin['database_bytes_estimate'] ) ) . ' of custom tables by table-name prefix.', size_format( (int) $plugin['database_bytes_estimate'] ), 'Treat ownership as an estimate. Check retention, indexes, cleanup jobs and growth rate for the matching custom tables.' );
            }
        }

        foreach ( (array) ( $health['errors']['source_details'] ?? array() ) as $log ) {
            if ( null === $log['bytes'] || $log['bytes'] < 100 * MB_IN_BYTES ) { continue; }
            $severity = $log['bytes'] >= GB_IN_BYTES ? 'high' : 'warning';
            PFC_Utils::issue( 'errors', $severity, 'PHP/WordPress error log is very large', '<code>' . esc_html( $log['path'] ) . '</code> is approximately ' . esc_html( size_format( $log['bytes'] ) ) . '.', size_format( $log['bytes'] ), 'Repeated logging can consume disk and add I/O under load. Rotate/archive the log, then fix the highest-frequency errors instead of merely deleting the file.' );
        }

        foreach ( array_slice( $health['errors']['findings'], 0, 30 ) as $error ) {
            $sev = false !== strpos( $error['type'], 'fatal' ) || false !== strpos( $error['type'], 'memory' ) || false !== strpos( $error['type'], 'execution' ) || false !== strpos( $error['type'], 'database' ) ? 'critical' : 'warning';
            PFC_Utils::issue( 'errors', $sev, 'Recent runtime error: ' . $error['type'], '<code>' . esc_html( $error['component'] ) . '</code><br>' . esc_html( $error['fingerprint'] ), $error['count'] . ' occurrence(s) in scanned log tail', 'Fix repeated PHP/database errors before tuning caches. Errors can trigger retries, failed requests, missing caches and expensive fallback paths.' );
        }

        $hooks = $health['hooks'];
        foreach ( array_slice( $hooks['heaviest_hooks'], 0, 10, true ) as $hook => $count ) {
            if ( $count >= 100 ) {
                PFC_Utils::issue( 'hooks', 'warning', 'Hook has an unusually high callback count', '<code>' . esc_html( $hook ) . '</code> has ' . intval( $count ) . ' registered callbacks on this request.', $count . ' callbacks', 'Use a signed profile to determine which callbacks actually consume time. Callback count is a lead, not a performance verdict.' );
            }
        }
    }
}
