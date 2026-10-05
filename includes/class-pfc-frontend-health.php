<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class PFC_Frontend_Health {
    public static function inspect( $deep = false ) {
        $targets = array( home_url( '/' ) );
        $types = get_post_types( array( 'publicly_queryable' => true ), 'names' );
        $preferred = array_values( array_unique( array_merge( array( 'post', 'review', 'newswire', 'page' ), (array) $types ) ) );
        foreach ( array_slice( $preferred, 0, $deep ? 6 : 2 ) as $post_type ) {
            if ( ! in_array( $post_type, $types, true ) && 'post' !== $post_type && 'page' !== $post_type ) { continue; }
            $recent = get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => false ) );
            if ( ! empty( $recent[0] ) ) { $targets[] = get_permalink( $recent[0] ); }
        }
        if ( $deep ) {
            $runtime = get_option( 'pfc_runtime', array() );
            foreach ( (array) ( $runtime['route_urls'] ?? array() ) as $custom_url ) {
                if ( PFC_Utils::same_origin_url( $custom_url ) ) { $targets[] = esc_url_raw( $custom_url ); }
            }
        }
        $targets = array_values( array_unique( array_filter( $targets ) ) );
        $results = array();
        foreach ( array_slice( $targets, 0, $deep ? 8 : 3 ) as $url ) { $results[] = self::probe( $url ); }
        return array( 'pages' => $results );
    }

    private static function probe( $url ) {
        $start = microtime( true );
        $response = wp_remote_get( $url, array(
            'timeout' => 20,
            'redirection' => 3,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'headers' => array( 'Cache-Control' => 'no-cache', 'X-PFC-Probe' => 'frontend-health' ),
            'user-agent' => 'Performance Console/' . PFC_VERSION . '; ' . home_url( '/' ),
        ) );
        $elapsed = ( microtime( true ) - $start ) * 1000;
        if ( is_wp_error( $response ) ) {
            return array( 'url' => esc_url_raw( $url ), 'ok' => false, 'error' => sanitize_text_field( $response->get_error_message() ), 'total_ms' => round( $elapsed, 1 ) );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        $headers_obj = wp_remote_retrieve_headers( $response );
        $headers = is_object( $headers_obj ) && method_exists( $headers_obj, 'getAll' ) ? $headers_obj->getAll() : (array) $headers_obj;
        $assets = self::assets( $body, $url );
        $markup = self::markup_signals( $body );
        return array(
            'url' => esc_url_raw( $url ), 'ok' => $code >= 200 && $code < 400, 'status' => $code,
            'total_ms' => round( $elapsed, 1 ), 'html_bytes' => strlen( $body ),
            'headers' => self::safe_headers( $headers ), 'assets' => $assets,
            'dom_nodes_estimate' => preg_match_all( '/<[a-z][^>]*>/i', $body ),
            'inline_script_bytes' => self::inline_bytes( $body, 'script' ),
            'inline_style_bytes' => self::inline_bytes( $body, 'style' ),
            'markup' => $markup,
        );
    }

    private static function safe_headers( array $headers ) {
        $allowed = array( 'cache-control','age','content-encoding','content-type','server-timing','x-cache','x-cache-status','cf-cache-status','x-litespeed-cache','x-rocket-cache','vary','link' );
        $out = array();
        foreach ( $allowed as $key ) {
            if ( isset( $headers[ $key ] ) ) { $out[ $key ] = sanitize_text_field( is_array( $headers[ $key ] ) ? implode( ', ', $headers[ $key ] ) : $headers[ $key ] ); }
        }
        return $out;
    }

    private static function assets( $html, $base_url ) {
        $urls = array();
        foreach ( array(
            '/<script\b[^>]*\bsrc=["\']([^"\']+)["\']/i',
            '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i',
            '/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']/i',
        ) as $pattern ) {
            if ( preg_match_all( $pattern, $html, $matches ) ) { foreach ( $matches[1] as $u ) { $urls[] = html_entity_decode( $u, ENT_QUOTES, 'UTF-8' ); } }
        }
        // <link> attributes are emitted in any order: WordPress core prints
        // rel before href, so requiring href first silently dropped every core
        // stylesheet from the asset inventory while the separate stylesheet
        // counter (which used a look-ahead) still counted them.
        if ( preg_match_all( '/<link\b[^>]*>/i', $html, $link_tags ) ) {
            foreach ( $link_tags[0] as $tag ) {
                if ( ! self::is_stylesheet_link( $tag ) ) { continue; }
                if ( preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href ) ) { $urls[] = html_entity_decode( $href[1], ENT_QUOTES, 'UTF-8' ); }
            }
        }
        $hosts = array(); $components = array(); $third_party = 0; $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $duplicates = array_count_values( $urls );
        foreach ( $urls as $u ) {
            $host = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
            if ( $host ) { $hosts[ $host ] = ( $hosts[ $host ] ?? 0 ) + 1; if ( $host !== $home_host ) { $third_party++; } }
            $comp = self::component_from_url( $u );
            if ( $comp ) { $components[ $comp ] = ( $components[ $comp ] ?? 0 ) + 1; }
        }
        arsort( $components ); arsort( $hosts );
        $dupes = array(); foreach ( $duplicates as $u => $count ) { if ( $count > 1 ) { $dupes[] = array( 'url' => esc_url_raw( $u ), 'count' => $count ); } }
        usort( $dupes, static function ( $a, $b ) { return $b['count'] <=> $a['count']; } );
        return array( 'total' => count( $urls ), 'third_party' => $third_party, 'hosts' => array_slice( $hosts, 0, 20, true ), 'components' => array_slice( $components, 0, 30, true ), 'duplicates' => array_slice( $dupes, 0, 20 ) );
    }

    private static function markup_signals( $html ) {
        $signals = array( 'images' => 0, 'images_missing_dimensions' => 0, 'images_lazy' => 0, 'head_blocking_scripts' => 0, 'stylesheets' => 0 );
        if ( preg_match_all( '/<img\b[^>]*>/i', $html, $images ) ) {
            $signals['images'] = count( $images[0] );
            foreach ( $images[0] as $tag ) {
                // Attribute values may be quoted or bare (minifiers strip quotes),
                // so requiring quotes reported correctly sized images as missing
                // width/height.
                if ( ! self::has_attribute( $tag, 'width' ) || ! self::has_attribute( $tag, 'height' ) ) { $signals['images_missing_dimensions']++; }
                if ( self::has_attribute( $tag, 'loading', 'lazy' ) ) { $signals['images_lazy']++; }
            }
        }
        $head = '';
        if ( preg_match( '/<head\b[^>]*>(.*?)<\/head>/is', $html, $hm ) ) { $head = $hm[1]; }
        if ( $head && preg_match_all( '/<script\b[^>]*\bsrc=["\'][^"\']+["\'][^>]*>/i', $head, $scripts ) ) {
            foreach ( $scripts[0] as $tag ) {
                if ( ! preg_match( '/\b(?:async|defer)(?:\s|=|>)/i', $tag ) && ! preg_match( '/\btype\s*=\s*["\']module["\']/i', $tag ) ) { $signals['head_blocking_scripts']++; }
            }
        }
        if ( preg_match_all( '/<link\b[^>]*>/i', $html, $styles ) ) {
            foreach ( $styles[0] as $tag ) { if ( self::is_stylesheet_link( $tag ) ) { $signals['stylesheets']++; } }
        }
        return $signals;
    }

    /**
     * True when a tag represents a stylesheet, regardless of attribute order.
     *
     * A plain rel="stylesheet" always counts. rel="preload" only counts when it
     * declares as="style" -- a font or script preload is not a stylesheet, and
     * counting it inflated the asset inventory and mis-attributed the font host.
     */
    private static function is_stylesheet_link( $tag ) {
        $tag = (string) $tag;
        if ( 1 !== preg_match( '/\bhref\s*=\s*["\'][^"\']+["\']/i', $tag ) ) { return false; }
        if ( 1 === preg_match( '/\brel\s*=\s*["\']?[^"\'\s>]*\bstylesheet\b/i', $tag ) ) { return true; }
        return 1 === preg_match( '/\brel\s*=\s*["\']?preload\b/i', $tag )
            && 1 === preg_match( '/\bas\s*=\s*["\']?style\b/i', $tag );
    }

    /**
     * True when a tag declares an attribute, optionally with an exact value.
     * Accepts href="x", href='x' and href=x, and an empty value when no value
     * is required, so bare minified attributes are not treated as absent.
     */
    private static function has_attribute( $tag, $name, $value = null ) {
        $tag = (string) $tag;
        $name = preg_quote( (string) $name, '/' );
        if ( null === $value ) {
            return 1 === preg_match( '/\b' . $name . '\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', $tag );
        }
        return 1 === preg_match( '/\b' . $name . '\s*=\s*(?:"' . preg_quote( (string) $value, '/' ) . '"|\'' . preg_quote( (string) $value, '/' ) . '\'|' . preg_quote( (string) $value, '/' ) . ')(?=[\s>]|$)/i', $tag );
    }

    private static function component_from_url( $url ) {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $path, $m ) ) { return 'plugin:' . sanitize_key( $m[1] ); }
        if ( preg_match( '#/wp-content/themes/([^/]+)/#', $path, $m ) ) { return 'theme:' . sanitize_key( $m[1] ); }
        if ( false !== strpos( $path, '/wp-includes/' ) ) { return 'wordpress-core'; }
        return '';
    }

    private static function inline_bytes( $html, $tag ) {
        $total = 0;
        if ( preg_match_all( '#<' . preg_quote( $tag, '#' ) . '\b[^>]*>(.*?)</' . preg_quote( $tag, '#' ) . '>#is', $html, $matches ) ) {
            foreach ( $matches[1] as $chunk ) { $total += strlen( $chunk ); }
        }
        return $total;
    }

    public static function generate_issues( array $health ) {
        foreach ( $health['pages'] as $page ) {
            $route = $page['url'] ?? '';
            if ( empty( $page['ok'] ) ) {
                PFC_Utils::issue( 'frontend', 'critical', 'Frontend diagnostic request failed', esc_html( $page['error'] ?? ( 'HTTP ' . ( $page['status'] ?? 0 ) ) ), ( $page['total_ms'] ?? 0 ) . ' ms', 'Resolve loopback/upstream errors before evaluating frontend optimization.', $route );
                continue;
            }
            if ( $page['total_ms'] > 1500 ) {
                PFC_Utils::issue( 'frontend', 'critical', 'Frontend route responds slowly to a local HTTP probe', 'The diagnostic GET completed in approximately ' . esc_html( $page['total_ms'] ) . ' ms.', $page['total_ms'] . ' ms', 'Compare with signed PHP profiling. If PHP is fast but this probe is slow, inspect reverse proxy, TLS, CDN, DNS and upstream network layers.', $route );
            } elseif ( $page['total_ms'] > 700 ) {
                PFC_Utils::issue( 'frontend', 'high', 'Frontend route has elevated response time', 'The diagnostic GET completed in approximately ' . esc_html( $page['total_ms'] ) . ' ms.', $page['total_ms'] . ' ms', 'Run a signed route profile and compare PHP/database/HTTP timings to the full HTTP response time.', $route );
            }
            if ( $page['html_bytes'] > 500 * KB_IN_BYTES ) {
                PFC_Utils::issue( 'frontend', 'high', 'HTML document is unusually large', 'The HTML response is approximately ' . esc_html( size_format( $page['html_bytes'] ) ) . '.', size_format( $page['html_bytes'] ), 'Inspect page-builder output, inline JSON/CSS/JS and repeated markup. Large HTML increases TTFB transfer and browser parse cost.', $route );
            }
            if ( $page['inline_script_bytes'] > 300 * KB_IN_BYTES ) {
                PFC_Utils::issue( 'frontend', 'warning', 'Large amount of inline JavaScript', 'Inline JavaScript totals approximately ' . esc_html( size_format( $page['inline_script_bytes'] ) ) . '.', size_format( $page['inline_script_bytes'] ), 'Identify plugins/themes injecting large inline state/configuration and move/cache data where appropriate.', $route );
            }
            if ( $page['inline_style_bytes'] > 250 * KB_IN_BYTES ) {
                PFC_Utils::issue( 'frontend', 'warning', 'Large amount of inline CSS', 'Inline styles total approximately ' . esc_html( size_format( $page['inline_style_bytes'] ) ) . '.', size_format( $page['inline_style_bytes'] ), 'Review builder/generated CSS and remove styles for components not present on the route.', $route );
            }
            if ( $page['assets']['total'] > 150 ) {
                PFC_Utils::issue( 'frontend', 'high', 'Very high frontend asset/request count', 'At least ' . intval( $page['assets']['total'] ) . ' script/style/image/iframe URLs were found in the HTML.', $page['assets']['total'] . ' resources', 'Use the component breakdown to identify plugins/themes loading assets globally. Conditionally enqueue route-specific assets.', $route );
            }
            if ( $page['assets']['third_party'] > 25 ) {
                PFC_Utils::issue( 'frontend', 'warning', 'Many third-party resources are loaded', intval( $page['assets']['third_party'] ) . ' resources point to other hosts.', $page['assets']['third_party'] . ' third-party resources', 'Reduce third-party tags, defer non-critical integrations and check which services block rendering or main-thread work.', $route );
            }
            if ( count( $page['assets']['duplicates'] ) > 3 ) {
                PFC_Utils::issue( 'frontend', 'warning', 'Duplicate frontend resource URLs detected', count( $page['assets']['duplicates'] ) . ' resource URLs appear more than once.', count( $page['assets']['duplicates'] ) . ' duplicate resources', 'Identify duplicate enqueue/injection paths in the listed plugin/theme components.' , $route );
            }
            $markup = $page['markup'] ?? array();
            if ( (int) ( $markup['head_blocking_scripts'] ?? 0 ) > 8 ) {
                PFC_Utils::issue( 'frontend', 'warning', 'Many potentially render-blocking scripts are in the document head', intval( $markup['head_blocking_scripts'] ) . ' external head scripts were found without async, defer or module semantics.', $markup['head_blocking_scripts'] . ' head scripts', 'Use the component/asset breakdown to defer non-critical scripts and remove plugins that enqueue code on routes where it is unused.', $route );
            }
            if ( (int) ( $markup['stylesheets'] ?? 0 ) > 20 ) {
                PFC_Utils::issue( 'frontend', 'warning', 'Large number of stylesheet requests', intval( $markup['stylesheets'] ) . ' stylesheet links were found in the HTML.', $markup['stylesheets'] . ' stylesheets', 'Consolidate or conditionally load plugin/theme CSS where practical. Prioritize removing unused route-wide styles over indiscriminate concatenation on HTTP/2/3.', $route );
            }
            if ( (int) ( $markup['images_missing_dimensions'] ?? 0 ) > 5 ) {
                PFC_Utils::issue( 'frontend', 'warning', 'Many images do not declare width and height', intval( $markup['images_missing_dimensions'] ) . ' image tags lack one or both intrinsic dimensions.', $markup['images_missing_dimensions'] . ' images', 'Ensure rendered image markup includes intrinsic dimensions or an equivalent reserved aspect ratio to reduce layout shifts.', $route );
            }
            $image_count = (int) ( $markup['images'] ?? 0 );
            $lazy_count = (int) ( $markup['images_lazy'] ?? 0 );
            if ( $image_count > 12 && $lazy_count < max( 1, (int) floor( ( $image_count - 3 ) * 0.5 ) ) ) {
                PFC_Utils::issue( 'frontend', 'info', 'Few below-the-fold images explicitly use lazy loading', $lazy_count . ' of ' . $image_count . ' image tags declare <code>loading="lazy"</code>.', $lazy_count . '/' . $image_count . ' lazy', 'Do not lazy-load the likely LCP/hero image. Verify below-the-fold images are lazy-loaded either by WordPress core or the rendering layer.', $route );
            }
            if ( $page['dom_nodes_estimate'] > 3000 ) {
                PFC_Utils::issue( 'frontend', 'warning', 'HTML has a very large DOM estimate', 'Approximately ' . number_format_i18n( $page['dom_nodes_estimate'] ) . ' elements were found in the response HTML.', number_format_i18n( $page['dom_nodes_estimate'] ) . ' nodes', 'Reduce deeply nested builder/layout markup and repeated off-screen elements.', $route );
            }
        }
    }
}
