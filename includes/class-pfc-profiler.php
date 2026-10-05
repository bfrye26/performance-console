<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_Profiler {
    private const MAX_SAVE_CALLBACK_TIMING_ROWS = 200;
    private static $start = 0.0;
    private static $http = array();
    private static $http_starts = array();
    private static $sample = false;
    private static $hook_starts = array();
    private static $hook_totals = array();
    private static $option_hits = array();
    private static $saved_posts = array();
    private static $save_callback_timings = array();
    private static $save_callback_origins = array();
    private static $instrumenting_save_callbacks = false;
    private static $save_callback_timing_dropped = 0;
    private static $save_callback_skipped_reference = array();
    private static $save_callback_skipped_reference_dropped = 0;

    public static function init() {
        global $wpdb;
        self::$start = isset( $GLOBALS['pfc_diag_start'] ) ? (float) $GLOBALS['pfc_diag_start'] : microtime( true );
        $deep = defined( 'PFC_DEEP_DIAGNOSTIC' ) && PFC_DEEP_DIAGNOSTIC;
        $early_sample = defined( 'PFC_SAMPLED_REQUEST' ) && PFC_SAMPLED_REQUEST;
        self::$sample = $deep || $early_sample;

        // Fallback if the MU bootstrap is not installed yet. This misses early plugin-load SQL but still provides useful request data.
        if ( ! self::$sample && ! defined( 'PFC_SAMPLING_DECIDED' ) ) {
            $runtime = get_option( 'pfc_runtime', array( 'sample_rate' => 0.0002 ) );
            $rate = max( 0, min( 1, (float) ( $runtime['sample_rate'] ?? 0.0002 ) ) );
            self::$sample = $rate > 0 && mt_rand() / mt_getrandmax() <= $rate;
            if ( self::$sample && ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); }
        }
        if ( ! self::$sample ) { return; }

        // SAVEQUERIES is checked on each query by wpdb. Signed/sample requests only.
        if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); }

        add_filter( 'http_request_args', array( __CLASS__, 'http_start' ), 10, 2 );
        add_action( 'http_api_debug', array( __CLASS__, 'http_debug' ), 10, 5 );
        add_action( 'shutdown', array( __CLASS__, 'shutdown' ), PHP_INT_MAX );
        add_filter( 'site_status_tests', array( __CLASS__, 'site_health' ) );
        add_filter( 'pre_option', array( __CLASS__, 'track_option' ), 9999, 3 );
        self::register_hook_timers();
    }


    public static function track_option( $pre_option, $option, $default_value ) {
        $option = sanitize_key( (string) $option );
        if ( $option && count( self::$option_hits ) < 750 ) { self::$option_hits[ $option ] = ( self::$option_hits[ $option ] ?? 0 ) + 1; }
        return $pre_option;
    }

    private static function register_hook_timers() {
        foreach ( array( 'init','wp_loaded','parse_request','send_headers','parse_query','pre_get_posts','wp','template_redirect','wp_head','wp_footer' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'hook_start' ), PHP_INT_MIN );
            add_action( $hook, array( __CLASS__, 'hook_stop' ), PHP_INT_MAX );
        }
        if ( defined( 'PFC_SAVE_DIAGNOSTIC' ) && PFC_SAVE_DIAGNOSTIC ) {
            add_action( 'wp_after_insert_post', array( __CLASS__, 'record_saved_post' ), PHP_INT_MAX, 2 );
            foreach ( array( 'save_post','wp_insert_post','wp_after_insert_post','post_updated','transition_post_status','added_post_meta','updated_post_meta','deleted_post_meta' ) as $hook ) {
                add_action( $hook, array( __CLASS__, 'hook_start' ), PHP_INT_MIN );
                add_action( $hook, array( __CLASS__, 'hook_stop' ), PHP_INT_MAX );
            }
            foreach ( array( 'wp_insert_post_data','content_save_pre' ) as $hook ) {
                add_filter( $hook, array( __CLASS__, 'filter_start' ), PHP_INT_MIN );
                add_filter( $hook, array( __CLASS__, 'filter_stop' ), PHP_INT_MAX );
            }
            add_action( 'init', array( __CLASS__, 'register_dynamic_save_timers' ), PHP_INT_MAX - 1 );
            if ( self::is_metabox_capture() ) {
                add_action( 'all', array( __CLASS__, 'instrument_save_callbacks' ), PHP_INT_MAX, 1 );
            }
        }
    }

    /**
     * Wrap selected callback entries only during the signed, one-shot metabox capture.
     * The original WP_Hook key, priority and accepted-argument count remain intact.
     *
     * @param mixed $hook_name Name passed as the first argument to the `all` hook.
     */
    public static function instrument_save_callbacks( $hook_name ) {
        // Label and component helpers can dispatch filters that re-enter `all`.
        if ( self::$instrumenting_save_callbacks ) {
            return;
        }
        if ( ! self::is_metabox_capture() || ! self::is_save_callback_hook( (string) $hook_name ) ) {
            return;
        }

        global $wp_filter;
        $hook_name = (string) $hook_name;
        if ( empty( $wp_filter[ $hook_name ] ) || ! ( $wp_filter[ $hook_name ] instanceof WP_Hook ) ) {
            return;
        }

        self::$instrumenting_save_callbacks = true;
        try {
            foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => &$priority_callbacks ) {
                foreach ( $priority_callbacks as $callback_id => &$registered ) {
                    $callback = $registered['function'] ?? null;
                    if ( ! is_callable( $callback ) || self::is_profiler_callback( $callback ) ) {
                        continue;
                    }

                    $existing_origin = self::save_callback_origin( $hook_name, $priority, $callback_id, $callback );
                    if ( null !== $existing_origin ) {
                        continue;
                    }

                    $label = self::callback_label( $callback, $has_reference_parameter );
                    if ( $has_reference_parameter ) {
                        $skip_key = md5( $hook_name . "\0" . (int) $priority . "\0" . (string) $callback_id );
                        if ( ! isset( self::$save_callback_skipped_reference[ $skip_key ] ) ) {
                            if ( count( self::$save_callback_skipped_reference ) < self::MAX_SAVE_CALLBACK_TIMING_ROWS ) {
                                self::$save_callback_skipped_reference[ $skip_key ] = true;
                            } else {
                                ++self::$save_callback_skipped_reference_dropped;
                            }
                        }
                        continue;
                    }

                    $component = self::callback_component( $callback );
                    $component_key = sanitize_key( (string) ( $component['type'] ?? 'core' ) ) . ':' . sanitize_key( (string) ( $component['slug'] ?? 'wordpress' ) );
                    $row_key = md5( $hook_name . "\0" . (int) $priority . "\0" . $label . "\0" . $component_key );
                    $wrapper = static function ( ...$args ) use ( $callback, $hook_name, $priority, $label, $component_key, $row_key ) {
                        $started = microtime( true );
                        try {
                            return call_user_func_array( $callback, $args );
                        } finally {
                            try {
                                PFC_Profiler::record_save_callback_timing( $row_key, $hook_name, $label, $component_key, (int) $priority, ( microtime( true ) - $started ) * 1000 );
                            } catch ( Throwable $ignored ) {
                                // Diagnostic recording must never alter the save callback result or exception.
                            }
                        }
                    };

                    self::$save_callback_origins[ $hook_name ][ $priority ][ $callback_id ] = array(
                        'wrapper' => $wrapper,
                        'callback' => $callback,
                        'component' => $component,
                    );
                    $registered['function'] = $wrapper;
                }
                unset( $registered );
            }
            unset( $priority_callbacks );
        } finally {
            self::$instrumenting_save_callbacks = false;
        }
    }

    private static function is_metabox_capture() {
        if ( ! defined( 'PFC_SAVE_DIAGNOSTIC' ) || ! PFC_SAVE_DIAGNOSTIC ) {
            return false;
        }
        $context = (array) ( $GLOBALS['pfc_save_capture'] ?? array() );
        return 'metabox' === ( $context['requested_kind'] ?? '' ) && 'classic-metabox-save' === ( $context['kind'] ?? '' );
    }

    private static function is_save_callback_hook( string $hook_name ) {
        $fixed = array( 'wp_after_insert_post', 'save_post', 'save_post_revision', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'clean_post_cache' );
        if ( in_array( $hook_name, $fixed, true ) ) {
            return true;
        }
        $context = (array) ( $GLOBALS['pfc_save_capture'] ?? array() );
        // MU request matching already sanitized this value; avoid sanitize_key()
        // here because its filter would recursively dispatch the `all` hook.
        $post_type = (string) ( $context['post_type'] ?? '' );
        return '' !== $post_type && 1 === preg_match( '/^[a-z0-9_-]+$/D', $post_type ) && 'save_post_' . $post_type === $hook_name;
    }

    private static function is_profiler_callback( $callback ) {
        if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
            return __CLASS__ === explode( '::', $callback, 2 )[0];
        }
        return is_array( $callback ) && isset( $callback[0] ) && ( __CLASS__ === $callback[0] || ( is_object( $callback[0] ) && __CLASS__ === get_class( $callback[0] ) ) );
    }

    /** @return string Safe callback label; parameters and source paths are never included. */
    private static function callback_label( $callback, &$has_reference_parameter ) {
        $has_reference_parameter = true;
        try {
            if ( is_array( $callback ) && 2 === count( $callback ) ) {
                $reflection = new ReflectionMethod( $callback[0], $callback[1] );
                $name = ( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1];
            } elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
                list( $class, $method ) = explode( '::', $callback, 2 );
                $reflection = new ReflectionMethod( $class, $method );
                $name = $class . '::' . $method;
            } elseif ( is_object( $callback ) && ! $callback instanceof Closure ) {
                $reflection = new ReflectionMethod( $callback, '__invoke' );
                $name = get_class( $callback ) . '::__invoke';
            } else {
                $reflection = new ReflectionFunction( $callback );
                $name = $reflection->isClosure() ? 'closure@' . (int) $reflection->getStartLine() : $reflection->getName();
            }
            foreach ( $reflection->getParameters() as $parameter ) {
                if ( $parameter->isPassedByReference() ) {
                    return sanitize_text_field( substr( $name, 0, 160 ) );
                }
            }
            $has_reference_parameter = false;
            return sanitize_text_field( substr( $name, 0, 160 ) );
        } catch ( Throwable $error ) {
            return 'unresolved callable';
        }
    }

    private static function save_callback_origin( string $hook, $priority, $callback_id, $callback ) {
        $origin = self::$save_callback_origins[ $hook ][ $priority ][ $callback_id ] ?? null;
        return is_array( $origin ) && isset( $origin['wrapper'] ) && $origin['wrapper'] === $callback ? $origin : null;
    }

    private static function record_save_callback_timing( string $key, string $hook, string $label, string $component, int $priority, float $elapsed ): void {
        if ( ! isset( self::$save_callback_timings[ $key ] ) ) {
            if ( count( self::$save_callback_timings ) >= self::MAX_SAVE_CALLBACK_TIMING_ROWS ) {
                ++self::$save_callback_timing_dropped;
                return;
            }
            self::$save_callback_timings[ $key ] = array( 'hook' => $hook, 'callback' => $label, 'component' => $component, 'priority' => $priority, 'calls' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0 );
        }
        ++self::$save_callback_timings[ $key ]['calls'];
        self::$save_callback_timings[ $key ]['total_ms'] += max( 0.0, $elapsed );
        self::$save_callback_timings[ $key ]['max_ms'] = max( self::$save_callback_timings[ $key ]['max_ms'], $elapsed );
    }

    public static function record_saved_post( $post_id, $post ) {
        $parent = wp_is_post_revision( $post_id );
        $id = $parent ? (int) $parent : (int) $post_id;
        self::$saved_posts[ $id ] = $parent ? (string) get_post_type( $id ) : (string) $post->post_type;
    }

    public static function save_outcome( array $context, array $saved_posts, $status, $fatal = false ) {
        $id = absint( $context['post_id'] ?? 0 );
        $written = $id > 0 && isset( $saved_posts[ $id ] );
        return array( 'write_observed' => $written, 'response_status' => (int) $status,
            'successful' => $written && ! $fatal && $status >= 200 && $status < 400 );
    }

    public static function comparable_save( array $before, array $after ) {
        foreach ( array( 'post_id', 'post_type', 'requested_kind', 'kind', 'user_id' ) as $key ) {
            if ( empty( $before[ $key ] ) || (string) $before[ $key ] !== (string) ( $after[ $key ] ?? '' ) ) { return false; }
        }
        return true;
    }

    public static function hook_start() {
        $hook = current_filter();
        if ( ! isset( self::$hook_starts[ $hook ] ) ) { self::$hook_starts[ $hook ] = array(); }
        self::$hook_starts[ $hook ][] = microtime( true );
    }

    public static function hook_stop() {
        $hook = current_filter();
        if ( empty( self::$hook_starts[ $hook ] ) ) { return; }
        $start = array_pop( self::$hook_starts[ $hook ] );
        self::$hook_totals[ $hook ] = ( self::$hook_totals[ $hook ] ?? 0 ) + ( microtime( true ) - $start ) * 1000;
    }

    public static function filter_start( $value ) {
        self::hook_start();
        return $value;
    }

    public static function filter_stop( $value ) {
        self::hook_stop();
        return $value;
    }

    public static function register_dynamic_save_timers() {
        $context = (array) ( $GLOBALS['pfc_save_capture'] ?? array() );
        $post_type = sanitize_key( (string) ( $context['post_type'] ?? '' ) );
        if ( ! empty( $context['post_id'] ) ) { $post_type = sanitize_key( (string) get_post_type( (int) $context['post_id'] ) ); }
        if ( ! $post_type ) { return; }
        foreach ( (array) get_post_types( array(), 'objects' ) as $object ) {
            if ( $post_type === sanitize_key( (string) ( $object->rest_base ?? '' ) ) ) { $post_type = $object->name; break; }
        }
        $GLOBALS['pfc_save_capture']['post_type'] = $post_type;
        foreach ( array( 'save_post_' . $post_type, 'rest_after_insert_' . $post_type ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'hook_start' ), PHP_INT_MIN );
            add_action( $hook, array( __CLASS__, 'hook_stop' ), PHP_INT_MAX );
        }
        add_filter( 'rest_pre_insert_' . $post_type, array( __CLASS__, 'filter_start' ), PHP_INT_MIN );
        add_filter( 'rest_pre_insert_' . $post_type, array( __CLASS__, 'filter_stop' ), PHP_INT_MAX );
    }

    public static function http_start( $args, $url ) {
        $key = md5( $url . '|' . microtime( true ) . '|' . mt_rand() );
        self::$http_starts[ $url ][] = array(
            'key' => $key, 'start' => microtime( true ),
            'method' => sanitize_text_field( $args['method'] ?? 'GET' ),
            'timeout' => (float) ( $args['timeout'] ?? 5 ),
            'blocking' => ! isset( $args['blocking'] ) || (bool) $args['blocking'],
            'hook' => current_filter(),
        );
        return $args;
    }

    public static function http_debug( $response, $context, $class, $parsed_args, $url ) {
        if ( 'response' !== $context ) { return; }
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 );
        $component = PFC_Utils::component_from_trace( $trace );
        $started = array();
        if ( ! empty( self::$http_starts[ $url ] ) ) { $started = array_shift( self::$http_starts[ $url ] ); }
        $elapsed = ! empty( $started['start'] ) ? ( microtime( true ) - $started['start'] ) * 1000 : 0;
        $error = is_wp_error( $response ) ? sanitize_text_field( $response->get_error_message() ) : '';
        $status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        self::$http[] = array(
            'host' => sanitize_text_field( wp_parse_url( $url, PHP_URL_HOST ) ),
            'path' => sanitize_text_field( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' ),
            'ms' => round( $elapsed, 3 ),
            'component' => $component,
            'method' => sanitize_text_field( $started['method'] ?? ( $parsed_args['method'] ?? 'GET' ) ),
            'timeout' => (float) ( $started['timeout'] ?? ( $parsed_args['timeout'] ?? 0 ) ),
            'blocking' => isset( $started['blocking'] ) ? (bool) $started['blocking'] : true,
            'status' => $status,
            'error' => $error,
        );
    }

    public static function shutdown() {
        global $wpdb, $EZSQL_ERROR;
        $php_ms = ( microtime( true ) - self::$start ) * 1000;
        $db_ms = 0.0;
        $query_count = (int) $wpdb->num_queries;
        $run_payload = array();
        $queries_by_pattern = array();
        $deep = defined( 'PFC_DEEP_DIAGNOSTIC' ) && PFC_DEEP_DIAGNOSTIC;
        $save = defined( 'PFC_SAVE_DIAGNOSTIC' ) && PFC_SAVE_DIAGNOSTIC;
        $save_context = (array) ( $GLOBALS['pfc_save_capture'] ?? array() );
        if ( $save && (int) ( $save_context['user_id'] ?? 0 ) !== get_current_user_id() ) { return; }
        $trace_map = self::trace_map();

        if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
            foreach ( $wpdb->queries as $query_index => $q ) {
                $sql = (string) ( $q[0] ?? '' );
                $sec = (float) ( $q[1] ?? 0 );
                $db_ms += $sec * 1000;
                if ( ! $deep || $query_index >= 7500 ) { continue; }
                $norm = PFC_Utils::normalize_sql( $sql );
                $rawhash = md5( $sql );
                $trace = array();
                if ( ! empty( $trace_map[ $rawhash ] ) ) { $trace = array_shift( $trace_map[ $rawhash ] ); }
                $comp = PFC_Utils::component_from_trace( $trace );
                $hash = md5( $norm . '|' . $comp['type'] . '|' . $comp['slug'] );
                if ( ! isset( $queries_by_pattern[ $hash ] ) ) {
                    $queries_by_pattern[ $hash ] = array( 'sql' => $norm, 'count' => 0, 'total' => 0, 'max' => 0, 'component' => $comp, 'raw' => $sql );
                }
                $queries_by_pattern[ $hash ]['count']++;
                $queries_by_pattern[ $hash ]['total'] += $sec * 1000;
                $queries_by_pattern[ $hash ]['max'] = max( $queries_by_pattern[ $hash ]['max'], $sec * 1000 );
            }
        }

        $http_ms = array_sum( array_map( static function ( $r ) { return (float) $r['ms']; }, self::$http ) );
        $route = $deep ? PFC_Utils::route() : PFC_Utils::route_group();
        $phases = self::phase_durations();
        $run_payload['http'] = array_slice( self::$http, 0, 100 );
        $run_payload['deep'] = $deep;
        $run_payload['phases'] = $phases;
        $run_payload['hook_ms'] = array_map( static function ( $v ) { return round( $v, 3 ); }, self::$hook_totals );
        $run_payload['database_errors'] = self::database_errors( (array) $EZSQL_ERROR, $trace_map );
        $run_payload['included_files'] = count( get_included_files() );
        $run_payload['query_timing_available'] = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
        $run_payload['measurement_scope'] = isset( $GLOBALS['pfc_diag_start'] ) ? 'mu-bootstrap-to-shutdown' : 'plugins-loaded-to-shutdown';
        $run_payload['query_details_truncated'] = count( (array) ( $wpdb->queries ?? array() ) ) > 7500;
        $run_payload['query_traces_at_limit'] = count( (array) ( $GLOBALS['pfc_query_traces'] ?? array() ) ) >= 5000;
        $run_payload['probe_id'] = sanitize_text_field( (string) ( $GLOBALS['pfc_probe_id'] ?? '' ) );
        $run_payload['excluded_plugin'] = sanitize_text_field( (string) ( $GLOBALS['pfc_excluded_plugin'] ?? '' ) );
        if ( $save ) {
            $last_error = error_get_last();
            $fatal = $last_error && in_array( $last_error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
            $run_payload['save_outcome'] = self::save_outcome( $save_context, self::$saved_posts, http_response_code() ?: 200, $fatal );
            $run_payload['save_context'] = array(
                'kind' => sanitize_key( (string) ( $save_context['kind'] ?? '' ) ),
                'requested_kind' => sanitize_key( (string) ( $save_context['requested_kind'] ?? '' ) ),
                'post_id' => absint( $save_context['post_id'] ?? 0 ),
                'post_type' => sanitize_key( (string) ( $save_context['post_type'] ?? '' ) ),
                'method' => sanitize_key( (string) ( $save_context['method'] ?? '' ) ),
                'user_id' => get_current_user_id(),
            );
            $run_payload['save_components'] = self::save_component_totals( $queries_by_pattern, self::$http );
            $run_payload['save_hook_components'] = self::save_hook_components();
            if ( self::is_metabox_capture() ) {
                $callback_rows = array_values( self::$save_callback_timings );
                usort( $callback_rows, static function ( $a, $b ) { return $b['total_ms'] <=> $a['total_ms']; } );
                $run_payload['save_callback_timings'] = array_map( static function ( $row ) {
                    $row['total_ms'] = round( (float) $row['total_ms'], 3 );
                    $row['max_ms'] = round( (float) $row['max_ms'], 3 );
                    return $row;
                }, $callback_rows );
                $run_payload['save_callback_timings_truncated'] = self::$save_callback_timing_dropped > 0;
                $run_payload['save_callback_timing_rows_dropped'] = self::$save_callback_timing_dropped;
                $run_payload['save_callback_reference_callbacks_skipped'] = count( self::$save_callback_skipped_reference );
                $run_payload['save_callback_reference_callbacks_skipped_truncated'] = self::$save_callback_skipped_reference_dropped > 0;
            }
        }

        $wpdb->insert( PFC_Utils::table( 'runs' ), array(
            'created_at' => PFC_Utils::now_mysql(), 'route' => $route, 'mode' => $save ? 'save' : ( $deep ? 'deep' : 'sample' ),
            'php_ms' => round( $php_ms, 3 ), 'db_ms' => round( $db_ms, 3 ), 'query_count' => $query_count,
            'http_ms' => round( $http_ms, 3 ), 'http_count' => count( self::$http ), 'memory_peak' => memory_get_peak_usage( true ),
            'probe_id' => $run_payload['probe_id'], 'excluded_plugin' => $run_payload['excluded_plugin'],
            'payload' => wp_json_encode( $run_payload ),
        ) );
        $run_id = (int) $wpdb->insert_id;
        self::persist_option_usage();

        PFC_Utils::begin_issue_collection( $deep ? 'manual' : 'passive', $run_id );
        if ( $deep ) { self::persist_queries( $run_id, $queries_by_pattern, $route ); }
        self::request_issues( $route, $php_ms, $db_ms, $query_count, $http_ms, $run_payload );
        if ( $save ) {
            $verification = get_transient( 'pfc_save_verify_' . sanitize_key( (string) ( $save_context['capture_id'] ?? '' ) ) );
            if ( $verification ) {
                $matched = is_array( $verification ) && self::comparable_save( (array) ( $verification['context'] ?? array() ), $run_payload['save_context'] );
                $improved = $matched && ! empty( $run_payload['save_outcome']['successful'] ) && $php_ms < 750;
                $verification_key = is_array( $verification ) ? (string) ( $verification['key'] ?? '' ) : (string) $verification;
                PFC_Utils::set_incident_status( $verification_key, $improved ? 'observing' : 'open' );
                set_transient( 'pfc_save_capture_notice_' . get_current_user_id(), array( 'ok' => $improved, 'message' => $improved ? 'A matching successful save was below the slow-save threshold. The incident is observing, not resolved; repeat representative saves before marking it resolved.' : 'Recheck inconclusive or still slow. The incident remains open: use the same content and save type, and confirm the editor reports success.' ), 10 * MINUTE_IN_SECONDS );
                delete_transient( 'pfc_save_verify_' . sanitize_key( (string) $save_context['capture_id'] ) );
            }
            self::save_issue( $route, $php_ms, $db_ms, $http_ms, $run_payload );
        }
        self::http_issues( $route );
        self::fatal_issue( $route );
        PFC_Utils::end_issue_collection();
    }


    private static function persist_option_usage() {
        if ( empty( self::$option_hits ) ) { return; }
        global $wpdb;
        $table = PFC_Utils::table( 'option_usage' );
        $now = PFC_Utils::now_mysql();
        $chunks = array(); $args = array();
        foreach ( array_slice( self::$option_hits, 0, 750, true ) as $name => $hits ) {
            $chunks[] = '(%s,%s,%d,1)';
            array_push( $args, $name, $now, (int) $hits );
        }
        if ( ! $chunks ) { return; }
        $sql = "INSERT INTO {$table} (option_name,last_seen,hits,sampled_requests) VALUES " . implode( ',', $chunks ) . ' ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen),hits=hits+VALUES(hits),sampled_requests=sampled_requests+1';
        $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function trace_map() {
        $trace_map = array();
        foreach ( (array) ( $GLOBALS['pfc_query_traces'] ?? array() ) as $t ) {
            if ( isset( $t['sql_hash'], $t['trace'] ) ) { $trace_map[ $t['sql_hash'] ][] = $t['trace']; }
        }
        return $trace_map;
    }

    private static function database_errors( array $errors, array $trace_map ) {
        $out = array();
        foreach ( array_slice( $errors, -50 ) as $error ) {
            $sql = (string) ( $error['query'] ?? '' );
            if ( self::is_expected_diagnostic_error( $sql, (string) ( $error['error_str'] ?? '' ) ) ) { continue; }
            $trace = array();
            $hash = md5( $sql );
            if ( ! empty( $trace_map[ $hash ] ) ) { $trace = array_shift( $trace_map[ $hash ] ); }
            $component = PFC_Utils::component_from_trace( $trace );
            $item = array(
                'error' => sanitize_text_field( PFC_Utils::truncate( (string) ( $error['error_str'] ?? 'Database error' ), 0, 500 ) ),
                'query' => PFC_Utils::normalize_sql( $sql ),
                'component' => $component,
            );
            $out[] = $item;
            PFC_Utils::issue( 'database', 'critical', 'Database query error from ' . $component['slug'], esc_html( $item['error'] ) . '<br><code>' . esc_html( PFC_Utils::truncate( $item['query'], 900 ) ) . '</code>', 'Query failed', 'Fix the SQL/schema/plugin error before performance tuning. Repeated failed queries can create retries and expensive fallback behaviour.', PFC_Utils::route() );
        }
        return $out;
    }

    public static function is_expected_diagnostic_error( $sql, $error = '' ) {
        $haystack = strtolower( (string) $sql . ' ' . (string) $error );
        foreach ( array( 'information_schema.innodb_lock_waits', 'information_schema.innodb_trx', 'performance_schema.data_lock_waits', 'performance_schema.data_locks' ) as $optional_table ) {
            if ( false !== strpos( $haystack, $optional_table ) && ( false !== strpos( $haystack, 'doesn\'t exist' ) || false !== strpos( $haystack, 'unknown table' ) || false !== strpos( $haystack, 'denied' ) ) ) { return true; }
        }
        return false;
    }

    private static function phase_durations() {
        $marks = (array) ( $GLOBALS['pfc_phase_marks'] ?? array() );
        $order = array( 'mu_plugin_bootstrap','muplugins_loaded','plugins_loaded','setup_theme','after_setup_theme','init','wp_loaded','wp','template_redirect','wp_head','wp_footer' );
        $out = array();
        $previous = null;
        $previous_name = '';
        foreach ( $order as $name ) {
            if ( ! isset( $marks[ $name ] ) ) { continue; }
            if ( null !== $previous ) { $out[ $previous_name . '_to_' . $name ] = round( ( $marks[ $name ] - $previous ) * 1000, 3 ); }
            $previous = (float) $marks[ $name ];
            $previous_name = $name;
        }
        return $out;
    }

    private static function persist_queries( $run_id, $patterns, $route ) {
        global $wpdb;
        uasort( $patterns, static function ( $a, $b ) { return $b['total'] <=> $a['total']; } );
        foreach ( array_slice( $patterns, 0, 350, true ) as $hash => $p ) {
            $explain = array();
            if ( $p['max'] >= 25 && preg_match( '/^\s*(SELECT|WITH)\b/i', $p['raw'] ) ) { $explain = self::safe_explain( $p['raw'] ); }
            $analysis = self::analyze_query( $p, $explain );
            $c = $p['component'];
            $wpdb->insert( PFC_Utils::table( 'queries' ), array(
                'run_id' => $run_id, 'pattern_hash' => $hash, 'normalized_sql' => $p['sql'], 'count' => $p['count'],
                'total_ms' => round( $p['total'], 3 ), 'max_ms' => round( $p['max'], 3 ), 'component_type' => $c['type'],
                'component_slug' => $c['slug'], 'source_file' => $c['file'], 'source_line' => $c['line'], 'explain_json' => wp_json_encode( array( 'plan' => $explain, 'analysis' => $analysis ) ),
            ) );
            if ( $analysis['problem'] ) {
                $severity = ( $p['total'] >= 500 || $p['max'] >= 500 || $analysis['rows_examined_estimate'] >= 1000000 ) ? 'critical' : 'high';
                $details = array_filter( $analysis['reasons'] );
                PFC_Utils::issue( 'database', $severity, 'Expensive query from ' . $c['slug'], '<code>' . esc_html( PFC_Utils::truncate( $p['sql'], 1200 ) ) . '</code><br>' . esc_html( implode( '; ', $details ) ), round( $p['total'] ) . ' ms total / ' . intval( $p['count'] ) . ' calls', self::query_recommendation( $analysis ), $route );
            }
        }
    }

    private static function safe_explain( $sql ) {
        global $wpdb;
        $old = $wpdb->suppress_errors( true );
        $rows = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->suppress_errors( $old );
        return is_array( $rows ) ? array_slice( $rows, 0, 25 ) : array();
    }

    private static function analyze_query( array $p, array $explain ) {
        $reasons = array();
        $rows = 0;
        $full_scan = false;
        $filesort = false;
        $temporary = false;
        $no_key = false;
        foreach ( $explain as $row ) {
            $rows += (int) ( $row['rows'] ?? 0 );
            if ( 'ALL' === strtoupper( (string) ( $row['type'] ?? '' ) ) ) { $full_scan = true; }
            $extra = strtolower( (string) ( $row['Extra'] ?? '' ) );
            if ( false !== strpos( $extra, 'filesort' ) ) { $filesort = true; }
            if ( false !== strpos( $extra, 'temporary' ) ) { $temporary = true; }
            if ( empty( $row['key'] ) && ! empty( $row['possible_keys'] ) ) { $no_key = true; }
        }
        if ( $p['count'] >= 20 ) { $reasons[] = intval( $p['count'] ) . ' repeated executions (possible N+1/duplicate query)'; }
        if ( $p['max'] >= 100 ) { $reasons[] = round( $p['max'] ) . ' ms maximum execution'; }
        if ( $p['total'] >= 100 ) { $reasons[] = round( $p['total'] ) . ' ms cumulative database time'; }
        if ( $full_scan ) { $reasons[] = 'EXPLAIN reports a full table scan'; }
        if ( $rows >= 100000 ) { $reasons[] = 'EXPLAIN estimates about ' . number_format_i18n( $rows ) . ' rows examined'; }
        if ( $filesort ) { $reasons[] = 'EXPLAIN reports filesort'; }
        if ( $temporary ) { $reasons[] = 'EXPLAIN reports a temporary table'; }
        if ( $no_key ) { $reasons[] = 'possible indexes exist but no key was selected'; }
        if ( preg_match( '/ORDER BY\s+RAND\s*\(/i', $p['raw'] ) ) { $reasons[] = 'ORDER BY RAND() scales poorly'; }
        if ( preg_match( '/SQL_CALC_FOUND_ROWS/i', $p['raw'] ) ) { $reasons[] = 'SQL_CALC_FOUND_ROWS adds counting work'; }
        if ( preg_match( '/LIKE\s+[\'\"]%/i', $p['raw'] ) ) { $reasons[] = 'leading-wildcard LIKE can prevent index use'; }
        if ( preg_match( '/\bSELECT\s+\*/i', $p['raw'] ) && $rows >= 10000 ) { $reasons[] = 'SELECT * over a large estimated result'; }
        if ( substr_count( strtolower( $p['raw'] ), 'postmeta' ) >= 2 ) { $reasons[] = 'multiple postmeta joins can multiply row work'; }
        $candidate = ( $full_scan || $no_key ) ? self::candidate_index( $p['raw'], $explain ) : array();
        if ( ! empty( $candidate['sql'] ) ) { $reasons[] = 'review-only index candidate: ' . $candidate['columns_text']; }
        return array(
            'problem' => ! empty( $reasons ), 'reasons' => $reasons, 'rows_examined_estimate' => $rows,
            'full_scan' => $full_scan, 'filesort' => $filesort, 'temporary' => $temporary, 'no_key_selected' => $no_key,
            'index_candidate' => $candidate,
        );
    }

    /**
     * Generate a conservative, review-only candidate for simple single-table SELECTs.
     * It never executes DDL and refuses joins, text/blob columns and cases where an
     * existing index already covers the candidate left-prefix.
     */
    private static function candidate_index( $sql, array $explain ) {
        global $wpdb;
        if ( count( $explain ) !== 1 || preg_match( '/\b(JOIN|UNION)\b/i', (string) $sql ) ) { return array(); }
        if ( ! preg_match( '/\bFROM\s+`?([A-Za-z0-9_]+)`?/i', (string) $sql, $m ) ) { return array(); }
        $table = (string) $m[1];
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) { return array(); }
        $old = $wpdb->suppress_errors( true );
        $columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->suppress_errors( $old );
        if ( ! is_array( $columns ) || ! $columns ) { return array(); }
        $allowed = array();
        foreach ( $columns as $column ) {
            $name = (string) ( $column['Field'] ?? '' );
            $type = strtolower( (string) ( $column['Type'] ?? '' ) );
            if ( ! $name || preg_match( '/\b(text|blob|json|geometry)\b/', $type ) ) { continue; }
            $allowed[ strtolower( $name ) ] = $name;
        }
        $where = '';
        if ( preg_match( '/\bWHERE\b(.+?)(?:\bGROUP\s+BY\b|\bORDER\s+BY\b|\bLIMIT\b|$)/is', (string) $sql, $m ) ) { $where = $m[1]; }
        if ( ! $where ) { return array(); }
        $equality = array(); $range = array();
        if ( preg_match_all( '/(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?\s*(=|<=>|IN\s*\(|IS\s+(?:NULL|NOT\s+NULL)|>=|<=|>|<|BETWEEN\b)/i', $where, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $key = strtolower( (string) $match[1] );
                if ( ! isset( $allowed[ $key ] ) ) { continue; }
                $name = $allowed[ $key ];
                $op = strtoupper( preg_replace( '/\s+/', ' ', trim( (string) $match[2] ) ) );
                if ( in_array( $op, array( '=','<=>','IS NULL','IS NOT NULL' ), true ) || 0 === strpos( $op, 'IN' ) ) { $equality[ $name ] = true; }
                else { $range[ $name ] = true; }
            }
        }
        $order = array();
        if ( preg_match( '/\bORDER\s+BY\b(.+?)(?:\bLIMIT\b|$)/is', (string) $sql, $m ) ) {
            if ( preg_match_all( '/(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?(?:\s+(?:ASC|DESC))?/i', $m[1], $om ) ) {
                foreach ( (array) ( $om[1] ?? array() ) as $name ) { $key = strtolower( $name ); if ( isset( $allowed[ $key ] ) ) { $order[ $allowed[ $key ] ] = true; } }
            }
        }
        $candidate = array_keys( $equality );
        if ( $range ) { $candidate[] = array_key_first( $range ); }
        foreach ( array_keys( $order ) as $name ) { if ( ! in_array( $name, $candidate, true ) ) { $candidate[] = $name; } }
        $candidate = array_slice( array_values( array_unique( $candidate ) ), 0, 4 );
        if ( ! $candidate ) { return array(); }

        $grouped = array();
        foreach ( (array) $indexes as $row ) {
            $name = (string) ( $row['Key_name'] ?? '' );
            $seq = max( 1, (int) ( $row['Seq_in_index'] ?? 1 ) );
            $column = (string) ( $row['Column_name'] ?? '' );
            if ( $name && $column ) { $grouped[ $name ][ $seq ] = $column; }
        }
        foreach ( $grouped as $cols ) {
            ksort( $cols ); $cols = array_values( $cols );
            $covered = true;
            foreach ( $candidate as $i => $column ) { if ( ! isset( $cols[ $i ] ) || 0 !== strcasecmp( $cols[ $i ], $column ) ) { $covered = false; break; } }
            if ( $covered ) { return array(); }
        }
        $safe_parts = array();
        foreach ( $candidate as $column ) { $safe_parts[] = '`' . $column . '`'; }
        $name = 'pfc_candidate_' . substr( md5( $table . ':' . implode( ',', $candidate ) ), 0, 10 );
        $rows_estimate = (int) ( $explain[0]['rows'] ?? 0 );
        return array(
            'table' => $table,
            'columns' => $candidate,
            'columns_text' => $table . '(' . implode( ', ', $candidate ) . ')',
            'sql' => 'ALTER TABLE `' . $table . '` ADD INDEX `' . $name . '` (' . implode( ',', $safe_parts ) . ')',
            'rows_examined_estimate' => $rows_estimate,
            'automatic' => false,
            'warning' => 'Candidate only. Verify selectivity, EXPLAIN improvement, index size and write amplification before creating it.',
        );
    }

    private static function query_recommendation( array $analysis ) {
        $parts = array();
        if ( $analysis['full_scan'] || $analysis['no_key_selected'] ) { $parts[] = 'Review predicates and the EXPLAIN plan for a selective composite index rather than adding indexes blindly.'; }
        if ( $analysis['filesort'] || $analysis['temporary'] ) { $parts[] = 'Reduce/sort a smaller result set and check whether an index can satisfy WHERE + ORDER BY/GROUP BY.'; }
        if ( ! empty( $analysis['index_candidate']['sql'] ) ) { $parts[] = 'Performance Console generated a review-only candidate index: <code>' . esc_html( $analysis['index_candidate']['sql'] ) . '</code> Test EXPLAIN and write/storage impact on staging before adding it.'; }
        $parts[] = 'If the same result repeats within one request, cache/prime it or batch the lookup.';
        return implode( ' ', $parts );
    }

    private static function save_component_totals( array $patterns, array $http ) {
        $totals = array();
        foreach ( $patterns as $pattern ) {
            $component = (array) ( $pattern['component'] ?? array() );
            $key = sanitize_key( (string) ( $component['type'] ?? 'core' ) ) . ':' . sanitize_key( (string) ( $component['slug'] ?? 'wordpress' ) );
            if ( ! isset( $totals[ $key ] ) ) { $totals[ $key ] = array( 'component' => $key, 'query_ms' => 0, 'query_count' => 0, 'http_ms' => 0, 'http_count' => 0, 'total_ms' => 0 ); }
            $totals[ $key ]['query_ms'] += (float) ( $pattern['total'] ?? 0 );
            $totals[ $key ]['query_count'] += (int) ( $pattern['count'] ?? 0 );
        }
        foreach ( $http as $request ) {
            $component = (array) ( $request['component'] ?? array() );
            $key = sanitize_key( (string) ( $component['type'] ?? 'core' ) ) . ':' . sanitize_key( (string) ( $component['slug'] ?? 'wordpress' ) );
            if ( ! isset( $totals[ $key ] ) ) { $totals[ $key ] = array( 'component' => $key, 'query_ms' => 0, 'query_count' => 0, 'http_ms' => 0, 'http_count' => 0, 'total_ms' => 0 ); }
            $totals[ $key ]['http_ms'] += (float) ( $request['ms'] ?? 0 );
            $totals[ $key ]['http_count']++;
        }
        foreach ( $totals as &$total ) {
            $total['query_ms'] = round( $total['query_ms'], 3 );
            $total['http_ms'] = round( $total['http_ms'], 3 );
            $total['total_ms'] = round( $total['query_ms'] + $total['http_ms'], 3 );
        }
        unset( $total );
        uasort( $totals, static function ( $a, $b ) { return $b['total_ms'] <=> $a['total_ms']; } );
        return array_slice( array_values( $totals ), 0, 30 );
    }

    private static function callback_component( $callback ) {
        try {
            if ( is_array( $callback ) && 2 === count( $callback ) ) { $reflection = new ReflectionMethod( $callback[0], $callback[1] ); }
            elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) { list( $class, $method ) = explode( '::', $callback, 2 ); $reflection = new ReflectionMethod( $class, $method ); }
            elseif ( is_object( $callback ) && ! $callback instanceof Closure ) { $reflection = new ReflectionMethod( $callback, '__invoke' ); }
            else { $reflection = new ReflectionFunction( $callback ); }
            $file = (string) $reflection->getFileName();
            return $file ? PFC_Utils::component_from_file( $file ) : array( 'type' => 'core', 'slug' => 'wordpress' );
        } catch ( Throwable $e ) {
            return array( 'type' => 'core', 'slug' => 'wordpress' );
        }
    }

    private static function save_hook_components() {
        global $wp_filter;
        $out = array();
        $fixed = array( 'save_post','wp_insert_post','wp_after_insert_post','post_updated','transition_post_status','added_post_meta','updated_post_meta','deleted_post_meta','wp_insert_post_data','content_save_pre' );
        foreach ( self::$hook_totals as $hook => $ms ) {
            if ( ! in_array( $hook, $fixed, true ) && 0 !== strpos( $hook, 'save_post_' ) && 0 !== strpos( $hook, 'rest_after_insert_' ) && 0 !== strpos( $hook, 'rest_pre_insert_' ) ) { continue; }
            if ( empty( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) { continue; }
            $components = array();
            $callbacks = 0;
            foreach ( $wp_filter[ $hook ]->callbacks as $priority => $priority_callbacks ) {
                foreach ( $priority_callbacks as $callback_id => $registered ) {
                    $callback = $registered['function'] ?? '';
                    $origin = self::save_callback_origin( $hook, $priority, $callback_id, $callback );
                    if ( null !== $origin ) {
                        $callback = $origin['callback'];
                    }
                    if ( is_array( $callback ) && ( ( is_string( $callback[0] ) && __CLASS__ === $callback[0] ) || ( is_object( $callback[0] ) && __CLASS__ === get_class( $callback[0] ) ) ) ) { continue; }
                    $component = null !== $origin ? $origin['component'] : self::callback_component( $callback );
                    $key = sanitize_key( (string) ( $component['type'] ?? 'core' ) ) . ':' . sanitize_key( (string) ( $component['slug'] ?? 'wordpress' ) );
                    $components[ $key ] = ( $components[ $key ] ?? 0 ) + 1;
                    $callbacks++;
                }
            }
            arsort( $components );
            $out[] = array( 'hook' => sanitize_key( $hook ), 'ms' => round( $ms, 3 ), 'callbacks' => $callbacks, 'components' => $components );
        }
        usort( $out, static function ( $a, $b ) { return $b['ms'] <=> $a['ms']; } );
        return $out;
    }

    private static function save_issue( $route, $php_ms, $db_ms, $http_ms, array $payload ) {
        if ( $php_ms < 750 || empty( $payload['save_outcome']['successful'] ) ) { return; }
        $components = (array) ( $payload['save_components'] ?? array() );
        $dominant = (array) ( $components[0] ?? array() );
        $component = sanitize_text_field( (string) ( $dominant['component'] ?? '' ) );
        $measured_ms = (float) ( $dominant['total_ms'] ?? 0 );
        $context = (array) ( $payload['save_context'] ?? array() );
        $label = trim( (string) ( $context['post_type'] ?? '' ) . ' ' . (string) ( $context['kind'] ?? '' ) );
        $message = 'A captured ' . esc_html( $label ?: 'WordPress' ) . ' save required approximately ' . round( $php_ms ) . ' ms of PHP time.';
        $recommendation = 'Review the captured slow-hook suspects and exact query/HTTP evidence before changing plugins.';
        $confidence = 65;
        if ( $component && $measured_ms >= 50 ) {
            $message .= ' The largest directly attributed database/HTTP contributor was <code>' . esc_html( $component ) . '</code> at approximately ' . round( $measured_ms ) . ' ms.';
            $recommendation = $http_ms >= $db_ms ? 'Move non-essential remote synchronization out of the save request, or cache/batch it.' : 'Reduce or batch the attributed save-time queries and check their EXPLAIN evidence.';
            $confidence = 88;
        }
        PFC_Utils::issue( 'save', $php_ms >= 1500 ? 'critical' : 'high', 'Slow WordPress save', $message, round( $php_ms ) . ' ms PHP time', $recommendation, $route, array( 'component' => $component, 'confidence' => $confidence ) );
    }

    private static function request_issues( $route, $php_ms, $db_ms, $query_count, $http_ms, array $payload ) {
        if ( defined( 'PFC_QUERY_TIMING_BLOCKED' ) && PFC_QUERY_TIMING_BLOCKED ) {
            PFC_Utils::issue( 'database', 'warning', 'Deep query timing is blocked by configuration', 'SAVEQUERIES is explicitly defined as false, so WordPress will not retain per-query timing even for this private diagnostic request.', 'Slow-query timing unavailable', 'Remove the explicit false SAVEQUERIES definition while running a signed diagnostic, or use database slow-query/performance-schema tooling at the server layer.', $route );
        }
        $known_dominant = ( $db_ms >= 250 && $db_ms >= $php_ms * 0.45 ) || ( self::has_actionable_http() && $http_ms >= $php_ms * 0.35 );
        if ( $php_ms > 1500 && ! $known_dominant ) {
            PFC_Utils::issue( 'request', 'critical', 'Very slow PHP request', 'This sampled request required more than 1.5 seconds of PHP execution.', round( $php_ms ) . ' ms', 'Inspect database, external HTTP, hook/phase and plugin evidence for the dominant contributor.', $route );
        } elseif ( $php_ms > 750 && ! $known_dominant ) {
            PFC_Utils::issue( 'request', 'high', 'Slow PHP request', 'This sampled request required more than 750 ms of PHP execution.', round( $php_ms ) . ' ms', 'Inspect database, external HTTP, hook/phase and plugin evidence for the dominant contributor.', $route );
        }
        if ( $db_ms > 500 ) {
            PFC_Utils::issue( 'database', 'critical', 'Database time dominates a request', 'SQL execution consumed approximately ' . round( $db_ms ) . ' ms in this sampled request.', round( $db_ms ) . ' ms DB time', 'Run a signed deep profile to identify the query patterns responsible.', $route );
        } elseif ( $db_ms > 250 ) {
            PFC_Utils::issue( 'database', 'high', 'Database time is elevated on a request', 'SQL execution consumed approximately ' . round( $db_ms ) . ' ms.', round( $db_ms ) . ' ms DB time', 'Run a signed deep profile and prioritize queries by cumulative time, not query count alone.', $route );
        }
        if ( $query_count > 1000 ) {
            PFC_Utils::issue( 'database', 'critical', 'Extremely high query count on a request', number_format_i18n( $query_count ) . ' SQL queries executed.', number_format_i18n( $query_count ) . ' queries', 'Look for duplicate/N+1 query patterns and plugins repeatedly loading metadata/options.', $route );
        } elseif ( $query_count > 400 ) {
            PFC_Utils::issue( 'database', 'high', 'High query count on a request', number_format_i18n( $query_count ) . ' SQL queries executed.', number_format_i18n( $query_count ) . ' queries', 'Run a deep profile and group queries by normalized pattern and component.', $route );
        }
        if ( $http_ms > 750 && ! self::has_actionable_http() ) {
            PFC_Utils::issue( 'http', 'high', 'Outbound HTTP calls add substantial request latency', 'Blocking HTTP activity consumed approximately ' . round( $http_ms ) . ' ms.', round( $http_ms ) . ' ms HTTP time', 'Cache remote data and move non-essential API synchronization out of page requests.', $route );
        }
        $limit = PFC_Utils::ini_bytes( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ) );
        $peak = memory_get_peak_usage( true );
        if ( $limit > 0 && $peak / $limit > 0.85 ) {
            PFC_Utils::issue( 'server', 'high', 'Request is close to its PHP memory limit', 'Peak memory was ' . esc_html( size_format( $peak ) ) . ' of approximately ' . esc_html( size_format( $limit ) ) . '.', round( 100 * $peak / $limit, 1 ) . '% of limit', 'Find large result sets, unbounded object hydration, image operations or plugins retaining large arrays before raising the memory limit.', $route );
        }
        foreach ( $payload['hook_ms'] as $hook => $ms ) {
            if ( $ms >= 200 ) {
                PFC_Utils::issue( 'hooks', 'high', 'Slow WordPress hook: ' . $hook, 'Callbacks on <code>' . esc_html( $hook ) . '</code> consumed approximately ' . round( $ms ) . ' ms in total.', round( $ms ) . ' ms', 'Inspect callbacks registered to this hook and correlate them with plugin exclusion/query evidence.', $route );
            }
        }
    }

    private static function has_actionable_http() {
        foreach ( self::$http as $item ) {
            if ( ! empty( $item['error'] ) || (int) ( $item['status'] ?? 0 ) >= 400 || ( ! empty( $item['blocking'] ) && (float) ( $item['ms'] ?? 0 ) >= 300 ) ) { return true; }
        }
        return false;
    }

    private static function http_issues( $route ) {
        foreach ( self::$http as $item ) {
            $component = $item['component']['slug'] ?? 'unknown';
            if ( $item['error'] ) {
                PFC_Utils::issue( 'http', 'critical', 'Outbound HTTP request failed from ' . $component, esc_html( $item['host'] . $item['path'] . ': ' . $item['error'] ), round( $item['ms'] ) . ' ms', 'Handle the failure without blocking normal page generation, and verify DNS/TLS/API availability and timeouts.', $route );
            } elseif ( $item['status'] >= 400 ) {
                PFC_Utils::issue( 'http', 'high', 'Outbound HTTP request returned an error status from ' . $component, esc_html( $item['host'] . $item['path'] . ' returned HTTP ' . $item['status'] ), round( $item['ms'] ) . ' ms', 'Fix the integration endpoint/authentication and avoid retrying failed requests on every page load.', $route );
            } elseif ( $item['blocking'] && $item['ms'] >= 300 ) {
                PFC_Utils::issue( 'http', $item['ms'] >= 1000 ? 'critical' : 'high', 'Slow blocking HTTP request from ' . $component, esc_html( $item['host'] . $item['path'] ) . ' blocked PHP for approximately ' . round( $item['ms'] ) . ' ms.', round( $item['ms'] ) . ' ms', 'Cache the response or move synchronization to cron/Action Scheduler/background processing.', $route );
            }
        }
    }

    private static function fatal_issue( $route ) {
        $last = error_get_last();
        if ( ! is_array( $last ) || ! in_array( (int) $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) { return; }
        $component = PFC_Utils::component_from_file( $last['file'] ?? '' );
        PFC_Utils::issue( 'errors', 'critical', 'Fatal PHP error during sampled request', '<code>' . esc_html( $component['type'] . ':' . $component['slug'] ) . '</code><br>' . esc_html( PFC_Utils::truncate( (string) $last['message'], 700 ) ), 'Request may fail completely', 'Fix the fatal error before performance tuning. Repeated fatals can also prevent cron, cache warming and background jobs from completing.', $route );
    }

    public static function site_health( $tests ) {
        $tests['direct']['pfc_bootstrap'] = array( 'label' => 'Performance Console bootstrap', 'test' => static function () {
            $s = PFC_Bootstrap::status();
            return array( 'label' => $s['installed'] ? 'Performance Console early bootstrap is installed' : 'Performance Console early bootstrap is missing', 'status' => $s['installed'] ? 'good' : 'recommended', 'badge' => array( 'label' => 'Performance' ), 'description' => '<p>' . ( $s['installed'] ? 'Deep diagnostics and sampled query timing can begin before normal plugins load.' : 'Install the MU bootstrap to enable early plugin/query diagnostics.' ) . '</p>', 'actions' => '' );
        } );
        return $tests;
    }
}
