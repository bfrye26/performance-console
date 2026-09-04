<?php
/**
 * Plugin Name: WP Performance Inspector
 * Description: Production-oriented WordPress performance diagnostics, database/plugin fault detection, slow-query attribution, server/cache/job checks, RUM and safe remediation.
 * Version:     2.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author:      CGMagazine
 * License:     GPL-2.0-or-later
 * Text Domain: wp-performance-inspector
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WPI_VERSION', '2.1.0' );
define( 'WPI_FILE', __FILE__ );
define( 'WPI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPI_URL', plugin_dir_url( __FILE__ ) );
define( 'WPI_BASENAME', plugin_basename( __FILE__ ) );

require_once WPI_DIR . 'includes/class-wpi-utils.php';
require_once WPI_DIR . 'includes/class-wpi-db.php';
require_once WPI_DIR . 'includes/class-wpi-bootstrap.php';
require_once WPI_DIR . 'includes/class-wpi-profiler.php';
require_once WPI_DIR . 'includes/class-wpi-rest.php';

register_activation_hook( __FILE__, array( 'WPI_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPI_DB', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
    WPI_DB::maybe_upgrade();
    WPI_Profiler::init();
    WPI_REST::init();

    if ( is_admin() ) {
        require_once WPI_DIR . 'includes/class-wpi-regression.php';
        require_once WPI_DIR . 'includes/class-wpi-admin.php';
        WPI_Admin::init();
        WPI_Regression::init();
        WPI_Bootstrap::maybe_install();
    }

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        WPI_REST::load_diagnostics();
        require_once WPI_DIR . 'includes/class-wpi-cli.php';
        WPI_CLI::register();
    }
} );
