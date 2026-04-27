<?php
/**
 * REST API controller for Smart Plugin Monitor.
 *
 * Provides AJAX endpoints for the interactive dashboard:
 *   - GET  /spm/v1/plugin/<basename>          → Plugin detail
 *   - POST /spm/v1/scan                       → Quick re-scan
 *   - POST /spm/v1/deep-scan                  → Force-refresh license scan
 *   - POST /spm/v1/security-scan/<basename>   → Deep security file scan
 *   - POST /spm/v1/disable/<basename>         → Deactivate a plugin
 *   - GET  /spm/v1/export                     → Export full report as JSON
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_REST_Controller {

    private const REST_NAMESPACE = 'spm/v1';

    private SPM_Detail_Provider $detail_provider;
    private SPM_Data_Service $data_service;
    private SPM_Action_Controller $action_controller;
    private SPM_Export_Service $export_service;
    private SPM_Analyzer $analyzer;
    private SPM_License_Detector $license_detector;
    private SPM_Security_Scanner $security_scanner;

    public function __construct(
        SPM_Detail_Provider $detail_provider,
        SPM_Data_Service $data_service,
        SPM_Action_Controller $action_controller,
        SPM_Export_Service $export_service,
        SPM_Analyzer $analyzer,
        SPM_License_Detector $license_detector,
        SPM_Security_Scanner $security_scanner
    ) {
        $this->detail_provider   = $detail_provider;
        $this->data_service      = $data_service;
        $this->action_controller = $action_controller;
        $this->export_service    = $export_service;
        $this->analyzer          = $analyzer;
        $this->license_detector  = $license_detector;
        $this->security_scanner  = $security_scanner;

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register all REST routes.
     */
    public function register_routes(): void {
        // Plugin detail.
        register_rest_route( self::REST_NAMESPACE, '/plugin/(?P<basename>.+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_plugin_detail' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'basename' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // Quick scan (re-run analyzer).
        register_rest_route( self::REST_NAMESPACE, '/scan', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_scan' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Deep scan (force-refresh license detection).
        register_rest_route( self::REST_NAMESPACE, '/deep-scan', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_deep_scan' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Deep security file scan.
        register_rest_route( self::REST_NAMESPACE, '/security-scan', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'run_security_scan' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'basename' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // Wipe error logs.
        register_rest_route( self::REST_NAMESPACE, '/wipe-logs', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'wipe_logs' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'basename' => [
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // Enable plugin.
        register_rest_route( self::REST_NAMESPACE, '/enable', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'enable_plugin' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'basename' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // Isolate plugin.
        register_rest_route( self::REST_NAMESPACE, '/isolate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'isolate_plugin' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'basename' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // Restore isolation.
        register_rest_route( self::REST_NAMESPACE, '/restore', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'restore_isolation' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Disable plugin.
        register_rest_route( self::REST_NAMESPACE, '/disable', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'disable_plugin' ],
            'permission_callback' => [ $this, 'check_admin' ],
            'args'                => [
                'basename' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        // Export report (JSON).
        register_rest_route( self::REST_NAMESPACE, '/export', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_report' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Export report (CSV).
        register_rest_route( self::REST_NAMESPACE, '/export/csv', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_csv' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );

        // Export report (PDF/Print View).
        register_rest_route( self::REST_NAMESPACE, '/export/pdf', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'export_pdf' ],
            'permission_callback' => [ $this, 'check_admin' ],
        ] );
    }

    /**
     * Permission check: must be an admin.
     */
    public function check_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * GET /spm/v1/plugin/{basename}
     */
    public function get_plugin_detail( \WP_REST_Request $request ): \WP_REST_Response {
        $basename = $request->get_param( 'basename' );
        $detail   = $this->detail_provider->get_detail( $basename );

        if ( null === $detail ) {
            return new \WP_REST_Response( [ 'error' => 'Plugin not found.' ], 404 );
        }

        return new \WP_REST_Response( $detail, 200 );
    }

    /**
     * POST /spm/v1/scan
     */
    public function run_scan(): \WP_REST_Response {
        $report = $this->analyzer->run( 7 );
        $this->action_controller->log_scan( 'all', 'quick' );

        return new \WP_REST_Response( [
            'success' => true,
            'summary' => $report['summary'],
            'count'   => count( $report['plugins'] ),
        ], 200 );
    }

    /**
     * POST /spm/v1/deep-scan
     */
    public function run_deep_scan(): \WP_REST_Response {
        $report = $this->license_detector->scan( true );
        $this->action_controller->log_scan( 'all', 'deep' );

        return new \WP_REST_Response( [
            'success' => true,
            'summary' => $report['summary'],
            'count'   => $report['total'],
        ], 200 );
    }

    /**
     * POST /spm/v1/security-scan/{basename}
     */
    public function run_security_scan( \WP_REST_Request $request ): \WP_REST_Response {
        $basename = $request->get_param( 'basename' );

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if ( ! isset( $all_plugins[ $basename ] ) ) {
            return new \WP_REST_Response( [ 'error' => 'Plugin not found.' ], 404 );
        }

        $report = $this->security_scanner->scan_plugin( $basename, true );
        $this->action_controller->log_scan( $basename, 'security' );

        return new \WP_REST_Response( [
            'success'  => true,
            'security' => $report,
        ], 200 );
    }

    /**
     * POST /spm/v1/wipe-logs
     */
    public function wipe_logs( \WP_REST_Request $request ): \WP_REST_Response {
        $basename = $request->get_param( 'basename' );
        
        $db = new SPM_Database();
        $db->clear_error_logs( $basename ? $basename : '' );
        
        return new \WP_REST_Response( [
            'success' => true,
            'message' => $basename ? 'Plugin error logs cleared.' : 'All error logs cleared.',
        ], 200 );
    }

    /**
     * POST /spm/v1/disable/{basename}
     */
    public function enable_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        $basename = $request->get_param( 'basename' );
        $result   = $this->action_controller->enable_plugin( $basename );
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    /**
     * POST /spm/v1/disable/{basename}
     */
    public function disable_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        $basename = $request->get_param( 'basename' );
        $result   = $this->action_controller->disable_plugin( $basename );
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    /**
     * POST /spm/v1/isolate/{basename}
     */
    public function isolate_plugin( \WP_REST_Request $request ): \WP_REST_Response {
        $basename = $request->get_param( 'basename' );
        $result   = $this->action_controller->isolate_plugin( $basename );
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    /**
     * POST /spm/v1/restore
     */
    public function restore_isolation(): \WP_REST_Response {
        $result = $this->action_controller->restore_state();
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    /**
     * GET /spm/v1/export
     */
    public function export_report(): \WP_REST_Response {
        $snap    = $this->data_service->get_dashboard_snapshot( 7 );
        $license = $this->license_detector->scan();

        // Run security scan on all plugins for the export.
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $security_reports = [];
        foreach ( array_keys( get_plugins() ) as $basename ) {
            $security_reports[ $basename ] = $this->security_scanner->scan_plugin( $basename );
        }

        $export = [
            'generated_at'   => current_time( 'mysql' ),
            'site_url'       => get_site_url(),
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => phpversion(),
            'performance'    => $snap['performance'] ?? [], // Backward compat for export structure
            'dashboard'      => $snap,
            'license_audit'  => $license,
            'security_audit' => $security_reports,
        ];

        return new \WP_REST_Response( $export, 200 );
    }

    /**
     * GET /spm/v1/export/csv
     */
    public function export_csv(): void {
        $csv = $this->export_service->generate_csv_report();
        $filename = 'spm-diagnostic-report-' . date('Y-m-d') . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * GET /spm/v1/export/pdf
     */
    public function export_pdf(): void {
        // PDF is handled via a printable HTML view.
        // We redirect to a special admin URL that renders the print layout.
        wp_safe_redirect( admin_url( 'admin.php?page=spm-dashboard&spm_view=print' ) );
        exit;
    }
}
