<?php
/**
 * Plugin Name: Smart Plugin Monitor
 * Plugin URI:  www.linkedin.com/in/marwan-hatem-713269211
 * Description: Tracks load time and PHP errors for each active plugin, storing logs in a custom database table.
 * Version:     1.0.0
 * Author:      Marwan hatem
 * Author URI:  www.linkedin.com/in/marwan-hatem-713269211
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-plugin-monitor
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

// Prevent direct file access.
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('SPM_VERSION', '1.0.0');
define('SPM_PLUGIN_FILE', __FILE__);
define('SPM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPM_DB_TABLE', 'spm_plugin_logs');

// Autoload plugin classes.
require_once SPM_PLUGIN_DIR . 'includes/class-spm-database.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-error-handler.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-monitor.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-analyzer.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-performance-analyzer.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-license-detector.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-security-scanner.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-data-service.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-action-controller.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-rollback-analyzer.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-export-service.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-detail-provider.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-rest-controller.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-admin.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-dashboard.php';
require_once SPM_PLUGIN_DIR . 'includes/class-spm-details-controller.php';

/**
 * Run on plugin activation — create the database table.
 */
function spm_activate()
{
    SPM_Database::create_table();
}
register_activation_hook(__FILE__, 'spm_activate');

/**
 * Bootstrap the plugin after all plugins have loaded.
 *
 * We use a singleton pattern so the monitor only initialises once.
 */
function spm_init()
{
    static $initialised = false;
    if ($initialised) {
        return;
    }
    $initialised = true;

    $database = new SPM_Database();
    $database->maybe_update_schema();
    $error_handler = new SPM_Error_Handler();
    $monitor = new SPM_Monitor($database, $error_handler);
    $monitor->start();

    // Shared services.
    $analyzer = new SPM_Analyzer($database);
    $perf_analyzer = new SPM_Performance_Analyzer($database);
    $license_detector = new SPM_License_Detector();
    $security_scanner = new SPM_Security_Scanner();
    $data_service = new SPM_Data_Service($database, $perf_analyzer);
    $action_controller = new SPM_Action_Controller($database);
    $rollback_analyzer = new SPM_Rollback_Analyzer($database);
    $export_service = new SPM_Export_Service($data_service);
    $detail_provider = new SPM_Detail_Provider($data_service, $license_detector, $security_scanner, $rollback_analyzer);

    // REST API (must register outside is_admin for REST requests).
    new SPM_REST_Controller($detail_provider, $data_service, $action_controller, $export_service, $analyzer, $license_detector, $security_scanner);

    // Admin UI (only loaded in wp-admin).
    if (is_admin()) {
        new SPM_Admin($data_service, $detail_provider, $analyzer, $license_detector, $security_scanner);
    }
}
add_action('plugins_loaded', 'spm_init', PHP_INT_MAX);
