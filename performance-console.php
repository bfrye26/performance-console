<?php
/**
 * Plugin Name: Performance Console
 * Description: Production-oriented WordPress performance diagnostics, database/plugin fault detection, slow-query attribution, server/cache/job checks, RUM and safe remediation.
 * Version:     2.2.1
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author:      CGMagazine
 * License:     GPL-2.0-or-later
 * Text Domain: performance-console
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'PFC_VERSION', '2.2.1' );
define( 'PFC_FILE', __FILE__ );
define( 'PFC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PFC_URL', plugin_dir_url( __FILE__ ) );
define( 'PFC_BASENAME', plugin_basename( __FILE__ ) );

require_once PFC_DIR . 'includes/class-pfc-utils.php';
require_once PFC_DIR . 'includes/class-pfc-db.php';
require_once PFC_DIR . 'includes/class-pfc-bootstrap.php';
require_once PFC_DIR . 'includes/class-pfc-profiler.php';
require_once PFC_DIR . 'includes/class-pfc-rest.php';

register_activation_hook( __FILE__, array( 'PFC_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PFC_DB', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
    PFC_DB::maybe_upgrade();
    PFC_Profiler::init();
    PFC_REST::init();

    if ( is_admin() ) {
        require_once PFC_DIR . 'includes/class-pfc-regression.php';
        require_once PFC_DIR . 'includes/class-pfc-admin.php';
        PFC_Admin::init();
        PFC_Regression::init();
        PFC_Bootstrap::maybe_install();
    }

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        PFC_REST::load_diagnostics();
        require_once PFC_DIR . 'includes/class-pfc-cli.php';
        PFC_CLI::register();
    }
} );
