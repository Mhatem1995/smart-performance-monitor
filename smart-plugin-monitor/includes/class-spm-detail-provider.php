<?php
/**
 * Detail data provider for a single plugin.
 *
 * Aggregates performance, error, license, security, and metadata
 * from all subsystems into a single structured response for the
 * detail drawer.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Detail_Provider {

    private SPM_Data_Service $data_service;
    private SPM_License_Detector $license_detector;
    private SPM_Security_Scanner $security_scanner;
    private SPM_Rollback_Analyzer $rollback_analyzer;

    public function __construct(
        SPM_Data_Service $data_service,
        SPM_License_Detector $license_detector,
        SPM_Security_Scanner $security_scanner,
        SPM_Rollback_Analyzer $rollback_analyzer
    ) {
        $this->data_service      = $data_service;
        $this->license_detector  = $license_detector;
        $this->security_scanner  = $security_scanner;
        $this->rollback_analyzer = $rollback_analyzer;
    }

    /**
     * Get complete detail data for a plugin basename.
     *
     * @param string $basename Plugin basename (e.g. "akismet/akismet.php").
     *
     * @return array|null Null if the plugin doesn't exist.
     */
    public function get_detail( string $basename ): ?array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if ( ! isset( $all_plugins[ $basename ] ) ) {
            return null;
        }

        $header = $all_plugins[ $basename ];

        // ── Metadata ──
        $meta = [
            'basename'    => $basename,
            'name'        => $header['Name'] ?? $basename,
            'version'     => $header['Version'] ?? '',
            'author'      => wp_strip_all_tags( $header['Author'] ?? '' ),
            'author_uri'  => $header['AuthorURI'] ?? '',
            'plugin_uri'  => $header['PluginURI'] ?? '',
            'description' => $header['Description'] ?? '',
            'text_domain' => $header['TextDomain'] ?? '',
            'requires_wp' => $header['RequiresWP'] ?? '',
            'requires_php'=> $header['RequiresPHP'] ?? '',
            'full_path'   => wp_normalize_path( WP_PLUGIN_DIR . '/' . $basename ),
            'is_active'   => is_plugin_active( $basename ),
        ];

        // ── Performance ──
        $performance = $this->data_service->get_plugin_performance_summary( $basename, 7 );

        // ── Historical logs ──
        $error_logs = $this->data_service->get_plugin_logs( $basename, 20, 7 );

        // ── License ──
        $license_report = $this->license_detector->scan();
        $license_data   = null;
        foreach ( $license_report['plugins'] as $lp ) {
            if ( $lp['basename'] === $basename ) {
                $license_data = $lp;
                break;
            }
        }

        // ── Security (from cache or quick scan) ──
        $security = $this->security_scanner->scan_plugin( $basename );

        // ── Performance History (7 days) ──
        $history = $this->data_service->get_plugin_history( $basename, 7 );

        // ── Rollback Readiness ──
        $rollback = $this->rollback_analyzer->get_readiness_report( $basename );

        return [
            'meta'                => $meta,
            'performance'         => $performance,
            'performance_history' => $history,
            'error_logs'          => $error_logs,
            'trust'               => $license_data,
            'security'            => $security,
            'rollback'            => $rollback,
        ];
    }

    /**
     * Get security report for a plugin.
     */
    private function get_security_report( string $basename ): array {
        return $this->security_scanner->scan_plugin( $basename );
    }
}
