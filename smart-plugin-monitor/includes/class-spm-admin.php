<?php
/**
 * Admin UI for Smart Plugin Monitor.
 *
 * Registers a top-level admin menu page and delegates rendering
 * to the modular SPM_Dashboard class. Enqueues CSS and JS assets.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Admin {

    private SPM_Data_Service $data_service;
    private SPM_Detail_Provider $detail_provider;
    private ?SPM_Dashboard $dashboard = null;
    private ?SPM_Details_Controller $details_controller = null;

    public function __construct( SPM_Data_Service $data_service, SPM_Detail_Provider $detail_provider ) {
        $this->data_service   = $data_service;
        $this->detail_provider = $detail_provider;
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        
        // Explicitly set the global title for the hidden page to prevent strip_tags(null) warning in admin-header.php
        add_action( 'load-admin_page_plugin-monitor-details', function() {
            global $title;
            $title = __( 'Plugin Details', 'smart-plugin-monitor' );
        });
    }

    /**
     * Register a top-level admin menu page.
     */
    public function register_menu(): void {
        add_menu_page(
            __( 'Plugin Monitor', 'smart-plugin-monitor' ),
            __( 'Plugin Monitor', 'smart-plugin-monitor' ),
            'manage_options',
            'spm-dashboard',
            [ $this, 'render_page' ],
            'dashicons-chart-bar',
            80
        );

        // Hidden Details Page
        add_submenu_page(
            'spm-hidden',
            __( 'Plugin Details', 'smart-plugin-monitor' ),
            __( 'Plugin Details', 'smart-plugin-monitor' ),
            'manage_options',
            'plugin-monitor-details',
            [ $this, 'render_details_page' ]
        );
    }

    /**
     * Enqueue admin CSS and JS on our dashboard page only.
     */
    public function enqueue_assets( string $hook ): void {
        $allowed_hooks = [
            'toplevel_page_spm-dashboard',
            'admin_page_plugin-monitor-details'
        ];

        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        wp_enqueue_style(
            'spm-admin',
            SPM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SPM_VERSION
        );

        wp_enqueue_script(
            'spm-dashboard',
            SPM_PLUGIN_URL . 'assets/js/dashboard.js',
            [],
            SPM_VERSION,
            true
        );

        wp_localize_script( 'spm-dashboard', 'spmConfig', [
            'restUrl' => esc_url_raw( rest_url( 'spm/v1' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    /**
     * Render the dashboard page via the dashboard class.
     */
    public function render_page(): void {
        // Handle Print View
        if ( isset( $_GET['spm_view'] ) && $_GET['spm_view'] === 'print' ) {
            $this->render_print_view();
            return;
        }

        if ( null === $this->dashboard ) {
            $this->dashboard = new SPM_Dashboard( $this->data_service );
        }

        $this->dashboard->render();
    }

    /**
     * Render a clean, printable report view.
     */
    private function render_print_view(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'smart-plugin-monitor' ) );
        }

        $snap = $this->data_service->get_dashboard_snapshot( 30 );
        
        include SPM_PLUGIN_DIR . 'templates/print-report.php';
        exit;
    }

    /**
     * Render the hidden details page.
     */
    public function render_details_page(): void {
        if ( null === $this->details_controller ) {
            $this->details_controller = new SPM_Details_Controller( $this->detail_provider );
        }

        $this->details_controller->render_page();
    }
}
