<?php
/**
 * Export and Reporting Service for Smart Plugin Monitor.
 *
 * Handles the generation of diagnostic reports in multiple formats
 * (CSV, JSON, and printable HTML).
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Export_Service {

    private SPM_Data_Service $data_service;

    public function __construct( SPM_Data_Service $data_service ) {
        $this->data_service = $data_service;
    }

    /**
     * Generate a CSV report of all plugins.
     */
    public function generate_csv_report(): string {
        $snap = $this->data_service->get_dashboard_snapshot( 30 ); // 30-day baseline
        $plugins = $snap['plugins'];

        $output = fopen( 'php://temp', 'r+' );
        
        // Header
        fputcsv( $output, [
            'Plugin Name',
            'Avg Load Time (ms)',
            'Error Count',
            'Health Score',
            'Grade',
            'Trend',
            'Security Risk',
            'Trust Level'
        ] );

        foreach ( $plugins as $p ) {
            fputcsv( $output, [
                $p['plugin_name'],
                $p['avg_ms'],
                $p['error_count'],
                $p['score'],
                $p['grade'],
                $p['trend'],
                'N/A', // Security/Trust would need per-plugin scan here or from cache
                'N/A'
            ] );
        }

        rewind( $output );
        $csv = stream_get_contents( $output );
        fclose( $output );

        return $csv;
    }

    /**
     * Prepare a full data structure for the Print/PDF view.
     */
    public function get_print_data(): array {
        return $this->data_service->get_dashboard_snapshot( 30 );
    }
}
