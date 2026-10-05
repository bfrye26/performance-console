<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_Utils {
    private static $issue_source = 'passive';
    private static $issue_run_id = 0;
    private static $collected_incidents = array();

    public static function table( $suffix ) {
        global $wpdb;
        return $wpdb->prefix . 'pfc_' . preg_replace( '/[^a-z0-9_]/', '', strtolower( $suffix ) );
    }

    public static function now_mysql() { return current_time( 'mysql', true ); }

    /**
     * Multibyte-safe truncation that degrades to the byte-safe core function.
     *
     * mbstring is optional in PHP and is not installed on every host, while SQL
     * text, file paths and error strings are overwhelmingly ASCII. Calling
     * mb_substr() unconditionally turned an optional extension into a fatal
     * error on those hosts, so every truncation goes through here.
     *
     * The middle $start argument is accepted because the call sites were ported
     * from mb_substr( $value, 0, $length ). Passing it is a no-op for a start of
     * 0, and an explicit non-zero start is honoured, so a lingering
     * mb_substr-shaped call can never silently truncate to zero bytes.
     */
    public static function truncate( $value, $start = 0, $length = null ) {
        $value = (string) $value;
        if ( null === $length ) { $length = $start; $start = 0; }
        $start = max( 0, (int) $start );
        $length = max( 0, (int) $length );
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $value, $start, $length ); }
        return substr( $value, $start, $length );
    }

    /**
     * Remove control characters before text is printed to a terminal or a log.
     *
     * SQL text echoed from other MySQL sessions can contain escape sequences, and
     * WP-CLI prints it raw, so an operator's console is the injection target.
     */
    public static function strip_control_chars( $value ) {
        return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value );
    }

    /**
     * Byte-scale constants that do not depend on WordPress having loaded them.
     * WordPress defines MB_IN_BYTES/GB_IN_BYTES in wp-includes/compat.php, but the
     * schema, backup and health code also runs from WP-CLI and MU-plugin contexts
     * where relying on that is unnecessary coupling.
     */
    const MB_IN_BYTES = 1048576;
    const GB_IN_BYTES = 1073741824;

    public static function normalize_sql( $sql ) {
        $sql = (string) $sql;
        $sql = preg_replace( "/'(?:''|\\\\.|[^'])*'/s", '?', $sql );
        $sql = preg_replace( '/"(?:""|\\\\.|[^"])*"/s', '?', $sql );
        $sql = preg_replace( '/\b0x[0-9a-f]+\b/i', '?', $sql );
        $sql = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );
        $sql = preg_replace( '/\s+/', ' ', trim( $sql ) );
        return self::truncate( $sql, 12000 );
    }

    public static function component_from_trace( array $trace ) {
        foreach ( $trace as $frame ) {
            $file = isset( $frame['file'] ) ? wp_normalize_path( $frame['file'] ) : '';
            if ( ! $file ) { continue; }
            $component = self::component_from_file( $file );
            if ( 'core' !== $component['type'] ) {
                $component['line'] = (int) ( $frame['line'] ?? 0 );
                return $component;
            }
        }
        return array( 'type' => 'core', 'slug' => 'wordpress', 'file' => '', 'line' => 0 );
    }

    public static function component_from_file( $file ) {
        $file = wp_normalize_path( (string) $file );
        $plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
        $themes  = wp_normalize_path( get_theme_root() ) . '/';
        $mu      = defined( 'WPMU_PLUGIN_DIR' ) ? wp_normalize_path( WPMU_PLUGIN_DIR ) . '/' : '';
        if ( $mu && 0 === strpos( $file, $mu ) ) {
            $rel = substr( $file, strlen( $mu ) );
            $parts = explode( '/', $rel );
            $slug = $parts[0];
            if ( false !== strpos( $slug, '.php' ) ) { $slug = pathinfo( $slug, PATHINFO_FILENAME ); }
            return array( 'type' => 'mu-plugin', 'slug' => sanitize_key( $slug ), 'file' => $file, 'line' => 0 );
        }
        if ( 0 === strpos( $file, $plugins ) ) {
            $rel = substr( $file, strlen( $plugins ) );
            $parts = explode( '/', $rel );
            $slug = $parts[0];
            if ( false !== strpos( $slug, '.php' ) ) { $slug = pathinfo( $slug, PATHINFO_FILENAME ); }
            return array( 'type' => 'plugin', 'slug' => sanitize_key( $slug ), 'file' => $file, 'line' => 0 );
        }
        if ( 0 === strpos( $file, $themes ) ) {
            $rel = substr( $file, strlen( $themes ) );
            $slug = explode( '/', $rel )[0];
            return array( 'type' => 'theme', 'slug' => sanitize_key( $slug ), 'file' => $file, 'line' => 0 );
        }
        return array( 'type' => 'core', 'slug' => 'wordpress', 'file' => $file, 'line' => 0 );
    }

    public static function begin_issue_collection( $source, $run_id = 0 ) {
        self::$issue_source = in_array( $source, array( 'scan', 'passive', 'manual' ), true ) ? $source : 'passive';
        self::$issue_run_id = absint( $run_id );
        self::$collected_incidents = array();
    }

    public static function collected_incidents() { return array_keys( self::$collected_incidents ); }

    public static function autoload_usage_coverage() {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT route FROM ' . self::table( 'runs' ) . ' WHERE mode=%s AND created_at >= %s ORDER BY id DESC LIMIT 5000', 'sample', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), ARRAY_A );
        $coverage = array( 'total' => 0, 'frontend' => 0, 'admin' => 0 );
        foreach ( (array) $rows as $row ) {
            $coverage['total']++;
            $route = (string) $row['route'];
            if ( 0 === strpos( $route, 'admin:' ) ) { $coverage['admin']++; }
            elseif ( preg_match( '/^(front-page|posts-home|search|404|singular:|taxonomy:|archive:|frontend:)/', $route ) ) { $coverage['frontend']++; }
        }
        return $coverage;
    }

    public static function autoload_review_ready( array $coverage, $days ) {
        // A conservative review threshold, never proof that an option is unused.
        return $days >= 7 && (int) ( $coverage['total'] ?? 0 ) >= 100
            && (int) ( $coverage['frontend'] ?? 0 ) >= 20 && (int) ( $coverage['admin'] ?? 0 ) >= 20;
    }

    public static function response_contains_text( $html, $expected ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', $text );
        $expected = trim( preg_replace( '/\s+/u', ' ', (string) $expected ) );
        return '' === $expected || false !== strpos( $text, $expected );
    }

    public static function end_issue_collection() { self::$issue_source = 'passive'; self::$issue_run_id = 0; }

    /** Resolved once per request: the atomic issue upsert depends on this key. */
    private static $issue_key_is_unique = null;

    /**
     * Drop the cached unique-key decision.
     *
     * Only needed by the regression suite, which must be able to exercise the
     * non-atomic fallback inside a single PHP process.
     */
    public static function reset_issue_key_cache() { self::$issue_key_is_unique = null; }

    /**
     * Confirm the issues table still carries the UNIQUE key on issue_key.
     *
     * The atomic upsert below relies on ON DUPLICATE KEY UPDATE firing. If a site
     * somehow lost that key, every write would insert a new row and occurrence
     * counts would silently stop accumulating, so the write path falls back to the
     * unambiguous read-then-write when the key is absent.
     */
    private static function issue_key_is_unique() {
        global $wpdb;
        if ( null !== self::$issue_key_is_unique ) { return self::$issue_key_is_unique; }
        self::$issue_key_is_unique = false;
        $indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . self::table( 'issues' ) . " WHERE Key_name = 'issue_key'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( (array) $indexes as $index ) {
            if ( 0 === (int) ( $index['Non_unique'] ?? 1 ) ) { self::$issue_key_is_unique = true; break; }
        }
        return self::$issue_key_is_unique;
    }

    /**
     * Decide the stored status for a repeated finding.
     *
     * A re-observation must not silently undo an operator decision: accepted risk
     * and unexpired snoozes are preserved, an expired snooze lapses back to the
     * observing state, and passive single observations stay "observing" until a
     * second sighting confirms them.
     */
    public static function next_issue_status( $existing_status, $source, $snooze_expired_until = null, $now = null ) {
        $now = null === $now ? time() : (int) $now;
        $existing_status = (string) $existing_status;
        if ( 'accepted' === $existing_status ) { return 'accepted'; }
        if ( 'snoozed' === $existing_status ) {
            $until = $snooze_expired_until ? strtotime( (string) $snooze_expired_until . ' UTC' ) : false;
            if ( $until && $until > $now ) { return 'snoozed'; }
        }
        return 'passive' === $source ? 'observing' : 'open';
    }

    /**
     * Record or refresh one finding.
     *
     * This runs once per finding per scan, so it is the hottest write in the
     * plugin. A single INSERT ... ON DUPLICATE KEY UPDATE replaces the previous
     * SELECT-then-INSERT/UPDATE pair, which halved the statement count and removed
     * the race in which two concurrent requests both decide a finding is new.
     */
    public static function issue( $area, $severity, $title, $message, $impact = '', $recommendation = '', $route = '', array $context = array() ) {
        global $wpdb;
        $table = self::table( 'issues' );
        $key = md5( implode( '|', array( $area, $title, $route ) ) );
        $incident_key = self::incident_key( $area, $title, $message, $context );
        self::$collected_incidents[ $incident_key ] = true;
        $source = self::$issue_source;
        $now = self::now_mysql();
        $area = sanitize_key( $area );
        $severity = sanitize_key( $severity );
        $title = sanitize_text_field( $title );
        $message = wp_kses_post( $message );
        $impact = sanitize_text_field( $impact );
        $recommendation = wp_kses_post( $recommendation );
        $route = sanitize_text_field( $route );
        $component = sanitize_text_field( (string) ( $context['component'] ?? self::component_from_issue( $title, $message ) ) );
        $confidence = max( 0, min( 100, (float) ( $context['confidence'] ?? ( 'scan' === $source ? 80 : 60 ) ) ) );
        $run_id = absint( $context['run_id'] ?? self::$issue_run_id );

        if ( self::issue_key_is_unique() ) {
            // A first sighting follows the observing/open rule; a repeat is resolved
            // by the CASE below, which preserves accepted risk and live snoozes.
            $new_status = self::next_issue_status( '', $source );
            $sql = "INSERT INTO {$table} (issue_key,area,severity,title,message,impact,recommendation,route,incident_key,component,source,occurrence_count,confidence,last_run_id,status,snoozed_until,resolved_at,first_seen,last_seen)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,%f,%d,%s,NULL,NULL,%s,%s)
                ON DUPLICATE KEY UPDATE
                    area=VALUES(area),severity=VALUES(severity),title=VALUES(title),message=VALUES(message),
                    impact=VALUES(impact),recommendation=VALUES(recommendation),route=VALUES(route),
                    incident_key=VALUES(incident_key),component=VALUES(component),source=VALUES(source),
                    confidence=VALUES(confidence),last_run_id=VALUES(last_run_id),
                    occurrence_count=occurrence_count+1,
                    status=IF(status='accepted','accepted',IF(status='snoozed' AND snoozed_until IS NOT NULL AND snoozed_until>UTC_TIMESTAMP(),'snoozed',%s)),
                    resolved_at=IF(status='accepted' OR (status='snoozed' AND snoozed_until IS NOT NULL AND snoozed_until>UTC_TIMESTAMP()),resolved_at,NULL),
                    last_seen=VALUES(last_seen)";
            // Argument order is positional and load-bearing. The CASE expressions
            // reference the source invariant rather than VALUES(status), because
            // MySQL evaluates ON DUPLICATE KEY UPDATE assignments left to right.
            $prepared = $wpdb->prepare( $sql, $key, $area, $severity, $title, $message, $impact, $recommendation, $route, $incident_key, $component, $source, $confidence, $run_id, $new_status, $new_status, $now, $now ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ( false !== $wpdb->query( $prepared ) ) { return; }
            // The upsert is unavailable (unexpected schema or server rejection);
            // fall through to the explicit path so a finding is never dropped.
        }

        $exists = $wpdb->get_row( $wpdb->prepare( "SELECT id,occurrence_count,status,snoozed_until FROM {$table} WHERE issue_key=%s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $status = null === $exists ? self::next_issue_status( '', $source ) : self::next_issue_status( $exists['status'] ?? '', $source, $exists['snoozed_until'] ?? null );
        $data = array(
            'issue_key'      => $key,
            'area'           => $area,
            'severity'       => $severity,
            'title'          => $title,
            'message'        => $message,
            'impact'         => $impact,
            'recommendation' => $recommendation,
            'route'          => $route,
            'incident_key'   => $incident_key,
            'component'      => $component,
            'source'         => $source,
            'confidence'     => $confidence,
            'last_run_id'    => $run_id,
            'status'         => $status,
            'snoozed_until'  => 'snoozed' === $status ? ( $exists['snoozed_until'] ?? null ) : null,
            'last_seen'      => $now,
        );
        if ( $exists ) {
            $data['occurrence_count'] = max( 1, (int) ( $exists['occurrence_count'] ?? 0 ) + 1 );
            if ( ! in_array( $status, array( 'accepted', 'snoozed' ), true ) ) { $data['resolved_at'] = null; }
            $wpdb->update( $table, $data, array( 'id' => (int) $exists['id'] ) );
        } else {
            $data['first_seen'] = $now;
            $data['occurrence_count'] = 1;
            $data['resolved_at'] = null;
            $wpdb->insert( $table, $data );
        }
    }

    public static function incident_key( $area, $title, $message = '', array $context = array() ) {
        if ( ! empty( $context['incident'] ) ) { return md5( sanitize_text_field( (string) $context['incident'] ) ); }
        $component = (string) ( $context['component'] ?? self::component_from_issue( $title, $message ) );
        $host = '';
        if ( preg_match( '/\b(?:https?:\/\/)?([a-z0-9.-]+\.[a-z]{2,})(?:[\/:]|\b)/i', wp_strip_all_tags( (string) $message ), $matches ) ) { $host = strtolower( $matches[1] ); }
        return md5( implode( '|', array( sanitize_key( $area ), sanitize_text_field( $title ), sanitize_key( $component ), $host ) ) );
    }

    private static function component_from_issue( $title, $message ) {
        if ( preg_match( '/\bfrom\s+([a-z0-9._-]+)/i', (string) $title, $matches ) ) { return sanitize_key( $matches[1] ); }
        if ( preg_match( '/<(?:code|strong)>(?:plugin:|theme:|mu-plugin:)?([a-z0-9._-]+)<\//i', (string) $message, $matches ) ) { return sanitize_key( $matches[1] ); }
        return '';
    }

    public static function resolve_areas( array $areas ) {
        global $wpdb;
        $areas = array_values( array_filter( array_map( 'sanitize_key', $areas ) ) );
        if ( empty( $areas ) ) { return; }
        $placeholders = implode( ',', array_fill( 0, count( $areas ), '%s' ) );
        $sql = "UPDATE " . self::table( 'issues' ) . " SET status='resolved',resolved_at=UTC_TIMESTAMP() WHERE source IN ('scan','legacy') AND status IN ('open','verifying') AND area IN ({$placeholders})";
        $wpdb->query( $wpdb->prepare( $sql, $areas ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function set_incident_status( $incident_key, $status, $days = 0 ) {
        global $wpdb;
        $allowed = array( 'open', 'observing', 'resolved', 'snoozed', 'accepted', 'verifying' );
        if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $incident_key ) || ! in_array( $status, $allowed, true ) ) { return false; }
        $data = array( 'status' => $status );
        if ( 'snoozed' === $status ) { $data['snoozed_until'] = gmdate( 'Y-m-d H:i:s', time() + max( 1, min( 90, (int) $days ) ) * DAY_IN_SECONDS ); }
        else { $data['snoozed_until'] = null; }
        $data['resolved_at'] = in_array( $status, array( 'resolved', 'accepted' ), true ) ? self::now_mysql() : null;
        return false !== $wpdb->update( self::table( 'issues' ), $data, array( 'incident_key' => $incident_key ) );
    }

    public static function finish_verification( $incident_key ) {
        global $wpdb;
        return false !== $wpdb->query( $wpdb->prepare( "UPDATE " . self::table( 'issues' ) . " SET status='resolved',resolved_at=UTC_TIMESTAMP() WHERE incident_key=%s AND status='verifying'", $incident_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function incidents( array $statuses = array( 'open' ), $limit = 2000 ) {
        global $wpdb;
        $statuses = array_values( array_intersect( array_map( 'sanitize_key', $statuses ), array( 'open', 'observing', 'resolved', 'snoozed', 'accepted', 'verifying' ) ) );
        if ( ! $statuses ) { return array(); }
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
        $limit = max( 1, min( 5000, (int) $limit ) );
        $sql = $wpdb->prepare( "SELECT * FROM " . self::table( 'issues' ) . " WHERE status IN ({$placeholders}) ORDER BY last_seen DESC LIMIT {$limit}", $statuses ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $incidents = array();
        foreach ( (array) $rows as $row ) {
            $key = preg_match( '/^[a-f0-9]{32}$/', (string) ( $row['incident_key'] ?? '' ) ) ? $row['incident_key'] : $row['issue_key'];
            if ( ! isset( $incidents[ $key ] ) ) {
                $incidents[ $key ] = $row + array( 'incident_key' => $key, 'routes' => array(), 'evidence_count' => 0, 'occurrences' => 0, 'severity_rank' => 0 );
            }
            $incident =& $incidents[ $key ];
            $rank = self::severity_rank( $row['severity'] ?? '' );
            if ( $rank > (int) $incident['severity_rank'] ) {
                foreach ( array( 'severity', 'title', 'message', 'impact', 'recommendation', 'area', 'component', 'route', 'last_run_id', 'confidence' ) as $field ) { $incident[ $field ] = $row[ $field ] ?? ''; }
                $incident['severity_rank'] = $rank;
            }
            $incident['evidence_count']++;
            $incident['occurrences'] += max( 1, (int) ( $row['occurrence_count'] ?? 1 ) );
            if ( ! empty( $row['route'] ) ) { $incident['routes'][ $row['route'] ] = true; }
            if ( (string) $row['first_seen'] < (string) $incident['first_seen'] ) { $incident['first_seen'] = $row['first_seen']; }
            if ( (string) $row['last_seen'] > (string) $incident['last_seen'] ) { $incident['last_seen'] = $row['last_seen']; }
            unset( $incident );
        }
        foreach ( $incidents as &$incident ) { $incident['routes'] = array_keys( $incident['routes'] ); }
        unset( $incident );
        uasort( $incidents, static function ( $a, $b ) {
            $rank = (int) $b['severity_rank'] <=> (int) $a['severity_rank'];
            if ( 0 !== $rank ) { return $rank; }
            $occurrences = (int) $b['occurrences'] <=> (int) $a['occurrences'];
            return 0 !== $occurrences ? $occurrences : strcmp( (string) $b['last_seen'], (string) $a['last_seen'] );
        } );
        return array_values( $incidents );
    }

    public static function incident_summary() {
        global $wpdb;
        $counts = array( 'critical' => 0, 'high' => 0, 'warning' => 0, 'info' => 0 );
        $table = self::table( 'issues' );
        $rows = $wpdb->get_results( "SELECT severity_rank,COUNT(*) total FROM (SELECT COALESCE(NULLIF(incident_key,''),issue_key) grouped_key,MIN(FIELD(severity,'critical','high','warning','info')) severity_rank FROM {$table} WHERE status IN ('open','verifying') GROUP BY grouped_key) grouped_incidents GROUP BY severity_rank", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $by_rank = array( 1 => 'critical', 2 => 'high', 3 => 'warning', 4 => 'info' );
        foreach ( (array) $rows as $row ) { $severity = $by_rank[ (int) ( $row['severity_rank'] ?? 0 ) ] ?? ''; if ( $severity ) { $counts[ $severity ] = (int) $row['total']; } }
        return $counts;
    }

    public static function incident( $incident_key ) {
        foreach ( self::incidents( array( 'open', 'observing', 'resolved', 'snoozed', 'accepted', 'verifying' ), 5000 ) as $incident ) {
            if ( hash_equals( (string) $incident['incident_key'], (string) $incident_key ) ) { return $incident; }
        }
        return null;
    }

    /**
     * Estimate a percentile from a bucketed histogram.
     *
     * The stored buckets are coarse (100/200/500/1000/2500/4000/10000 ms), so the
     * result is the ceiling of the bucket the percentile falls into rather than an
     * interpolated value. For the final bucket the ceiling is meaningless -- it is
     * the clamp applied on ingest, so every sample above 10 s reports 600000 ms --
     * so the observed maximum is used instead when value_max is available.
     */
    public static function metric_percentile( array $row, $percentile = 0.75 ) {
        $total = max( 0, (int) ( $row['samples'] ?? 0 ) );
        if ( ! $total ) { return 0; }
        $value_max = (float) ( $row['value_max'] ?? 0 );
        $target = max( 1, (int) ceil( $total * max( 0, min( 1, (float) $percentile ) ) ) );
        $limits = 'cls' === (string) ( $row['metric'] ?? '' ) ? array( 0.05, 0.1, 0.15, 0.25, 0.5, 1, 2, 10 ) : array( 100, 200, 500, 1000, 2500, 4000, 10000, 600000 );
        $last = count( $limits ) - 1;
        $seen = 0;
        foreach ( $limits as $index => $limit ) {
            $seen += (int) ( $row[ 'bucket_' . $index ] ?? 0 );
            if ( $seen < $target ) { continue; }
            if ( $index === $last && $value_max > 0 ) { return $value_max; }
            return $limit;
        }
        return $value_max;
    }

    public static function route() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : parse_url( home_url(), PHP_URL_HOST );
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $path = strtok( $uri, '?' );
        return esc_url_raw( $scheme . $host . $path );
    }

    public static function same_origin_url( $url ) {
        $url = esc_url_raw( (string) $url, array( 'http', 'https' ) );
        if ( ! $url ) { return false; }
        $origin = static function ( $value ) {
            $scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
            $host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
            $port = (int) wp_parse_url( $value, PHP_URL_PORT );
            if ( ! $port ) { $port = 'https' === $scheme ? 443 : 80; }
            return $scheme . '://' . $host . ':' . $port;
        };
        return $origin( $url ) === $origin( home_url() );
    }

    public static function median( array $values ) {
        $values = array_values( array_filter( array_map( 'floatval', $values ), 'is_finite' ) );
        if ( ! $values ) { return 0.0; }
        sort( $values, SORT_NUMERIC );
        $count = count( $values );
        $middle = (int) floor( $count / 2 );
        return $count % 2 ? (float) $values[ $middle ] : ( (float) $values[ $middle - 1 ] + (float) $values[ $middle ] ) / 2;
    }

    public static function probe_run( $probe_id, $expected_exclude = '' ) {
        global $wpdb;
        $probe_id = sanitize_text_field( (string) $probe_id );
        if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $probe_id ) ) { return false; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT php_ms,db_ms,query_count,http_ms,http_count,memory_peak,probe_id,excluded_plugin FROM ' . self::table( 'runs' ) . ' WHERE probe_id=%s ORDER BY id DESC LIMIT 1', $probe_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! $row || (string) $expected_exclude !== (string) ( $row['excluded_plugin'] ?? '' ) ) { return false; }
        foreach ( array( 'php_ms', 'db_ms', 'http_ms' ) as $field ) { $row[ $field ] = (float) $row[ $field ]; }
        foreach ( array( 'query_count', 'http_count', 'memory_peak' ) as $field ) { $row[ $field ] = (int) $row[ $field ]; }
        return $row;
    }

    public static function analyze_paired_impact( array $deltas, $comparable = true ) {
        $deltas = array_values( array_filter( array_map( 'floatval', $deltas ), 'is_finite' ) );
        $delta = round( self::median( $deltas ), 1 );
        $deviations = array_map( static function ( $value ) use ( $delta ) { return abs( $value - $delta ); }, $deltas );
        $mad = round( self::median( $deviations ), 1 );
        $noise_floor = round( max( 5.0, 2.5 * $mad ), 1 );
        $sign_agreement = 0;
        foreach ( $deltas as $value ) {
            if ( ( $delta >= 0 && $value > 0 ) || ( $delta < 0 && $value < 0 ) ) { $sign_agreement++; }
        }
        $repeatable = (bool) $comparable && count( $deltas ) >= 5 && abs( $delta ) > $noise_floor && $sign_agreement >= 4;
        $confidence = 'low';
        if ( $repeatable ) {
            $confidence = $sign_agreement === count( $deltas ) && $mad <= max( 2.5, abs( $delta ) * 0.25 ) ? 'high' : 'medium';
        }
        return array(
            'delta' => $delta,
            'mad' => $mad,
            'noise_floor' => $noise_floor,
            'sign_agreement' => $sign_agreement,
            'repeatable' => $repeatable,
            'confidence' => $confidence,
            'pairs' => count( $deltas ),
        );
    }

    public static function save_request_context( $method = '', $uri = '', array $request = array(), array $post = array() ) {
        $method = strtoupper( (string) ( $method ?: ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) );
        if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) { return false; }
        $uri = (string) ( $uri ?: ( $_SERVER['REQUEST_URI'] ?? '' ) );
        $path = (string) strtok( $uri, '?' );
        $request = $request ?: $_REQUEST;
        $action = sanitize_key( (string) ( $request['action'] ?? '' ) );
        $post_id = absint( $request['post_ID'] ?? ( $request['post_id'] ?? 0 ) );
        $post_type = sanitize_key( (string) ( $request['post_type'] ?? '' ) );

        if ( 'POST' === $method && preg_match( '#/wp-admin/post\.php$#i', $path ) ) {
            $query = array();
            parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $query );
            $body_action = sanitize_key( (string) ( $post['action'] ?? '' ) );
            $body_post_id = absint( $post['post_ID'] ?? 0 );
            $query_post_id = absint( $query['post'] ?? 0 );
            if ( '1' === (string) ( $query['meta-box-loader'] ?? '' ) && 'edit' === (string) ( $query['action'] ?? '' ) && 'editpost' === $body_action && $body_post_id > 0 && $body_post_id === $query_post_id ) {
                return array( 'kind' => 'classic-metabox-save', 'autosave' => false, 'post_id' => $body_post_id, 'post_type' => sanitize_key( (string) ( $post['post_type'] ?? '' ) ), 'method' => $method );
            }
        }

        if ( preg_match( '#/wp-admin/post\.php$#i', $path ) && in_array( $action, array( 'editpost', 'post' ), true ) ) {
            return array( 'kind' => 'classic-editor', 'autosave' => false, 'post_id' => $post_id, 'post_type' => $post_type, 'method' => $method );
        }
        if ( preg_match( '#/wp-admin/admin-ajax\.php$#i', $path ) && 'inline-save' === $action ) {
            return array( 'kind' => 'quick-edit', 'autosave' => false, 'post_id' => $post_id, 'post_type' => $post_type, 'method' => $method );
        }
        if ( preg_match( '#/wp-admin/admin-ajax\.php$#i', $path ) && 'autosave' === $action ) {
            return array( 'kind' => 'classic-autosave', 'autosave' => true, 'post_id' => $post_id, 'post_type' => $post_type, 'method' => $method );
        }

        $rest_path = '';
        if ( ! empty( $request['rest_route'] ) ) { $rest_path = '/' . ltrim( (string) $request['rest_route'], '/' ); }
        elseif ( false !== strpos( $path, '/wp-json/' ) ) { $rest_path = substr( $path, strpos( $path, '/wp-json/' ) + 8 ); }
        $rest_path = '/' . ltrim( rawurldecode( $rest_path ), '/' );
        if ( preg_match( '#^/(wp/v2|wc/v3)/([a-z0-9_-]+)(?:/(\d+))?(?:/(autosaves))?/?$#i', $rest_path, $matches ) ) {
            $resource = sanitize_key( $matches[2] );
            if ( in_array( $resource, array( 'media', 'comments', 'users', 'settings', 'search', 'types', 'statuses', 'taxonomies', 'categories', 'tags' ), true ) ) { return false; }
            $autosave = ! empty( $matches[4] );
            return array(
                'kind' => $autosave ? 'block-editor-autosave' : ( 'wc/v3' === strtolower( $matches[1] ) ? 'woocommerce-rest' : 'block-editor-rest' ),
                'autosave' => $autosave,
                'post_id' => absint( $matches[3] ?? 0 ),
                'post_type' => $resource,
                'method' => $method,
            );
        }
        return false;
    }

    public static function save_context_matches_capture( $context, $capture_kind ) {
        if ( ! is_array( $context ) || ! in_array( $capture_kind, array( 'manual', 'autosave', 'metabox' ), true ) ) { return false; }
        if ( 'metabox' === $capture_kind ) { return 'classic-metabox-save' === ( $context['kind'] ?? '' ); }
        return 'autosave' === $capture_kind ? ! empty( $context['autosave'] ) : empty( $context['autosave'] );
    }


    /** Low-cardinality identity for passive production sampling. */
    public static function route_group() {
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) { return 'cron'; }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) { return 'ajax'; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return 'rest'; }
        if ( is_admin() ) {
            global $pagenow;
            return 'admin:' . sanitize_key( (string) ( $pagenow ?: 'other' ) );
        }
        if ( function_exists( 'is_front_page' ) && is_front_page() ) { return 'front-page'; }
        if ( function_exists( 'is_home' ) && is_home() ) { return 'posts-home'; }
        if ( function_exists( 'is_search' ) && is_search() ) { return 'search'; }
        if ( function_exists( 'is_404' ) && is_404() ) { return '404'; }
        if ( function_exists( 'is_singular' ) && is_singular() ) {
            $type = function_exists( 'get_post_type' ) ? get_post_type() : '';
            return 'singular:' . sanitize_key( $type ?: 'unknown' );
        }
        if ( function_exists( 'is_category' ) && is_category() ) { return 'taxonomy:category'; }
        if ( function_exists( 'is_tag' ) && is_tag() ) { return 'taxonomy:post_tag'; }
        if ( function_exists( 'is_tax' ) && is_tax() ) {
            $obj = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
            return 'taxonomy:' . sanitize_key( is_object( $obj ) && ! empty( $obj->taxonomy ) ? $obj->taxonomy : 'custom' );
        }
        if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive() ) {
            $type = function_exists( 'get_query_var' ) ? get_query_var( 'post_type' ) : '';
            if ( is_array( $type ) ) { $type = reset( $type ); }
            return 'archive:' . sanitize_key( $type ?: 'post-type' );
        }
        if ( function_exists( 'is_archive' ) && is_archive() ) { return 'archive:other'; }
        return 'frontend:other';
    }

    public static function severity_rank( $s ) {
        $m = array( 'critical' => 4, 'high' => 3, 'warning' => 2, 'info' => 1, 'good' => 0 );
        return $m[ $s ] ?? 0;
    }

    public static function ini_bytes( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value || '-1' === $value ) { return -1; }
        $last = strtolower( substr( $value, -1 ) );
        $num = (float) $value;
        if ( 'g' === $last ) { $num *= 1024; $last = 'm'; }
        if ( 'm' === $last ) { $num *= 1024; $last = 'k'; }
        if ( 'k' === $last ) { $num *= 1024; }
        return (int) $num;
    }
}
