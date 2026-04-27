<?php
/**
 * Central Data Integrity Layer for Smart Plugin Monitor.
 *
 * Provides a standardized interface for all data queries, ensuring
 * consistency across dashboard widgets and plugin detail tabs.
 *
 * Implements centralized time filtering and caching.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Data_Service {

    /**
     * Time period constants.
     */
    public const PERIOD_24H  = 1;
    public const PERIOD_7D   = 7;
    public const PERIOD_30D  = 30;
    public const PERIOD_ALL  = 0;

    /**
     * Map of allowed periods to display labels.
     */
    public const PERIOD_LABELS = [
        self::PERIOD_24H => '24 hours',
        self::PERIOD_7D  => '7 days',
        self::PERIOD_30D => '30 days',
        self::PERIOD_ALL => 'All time',
    ];

    private SPM_Database $database;
    private SPM_Performance_Analyzer $perf_analyzer;

    public function __construct( SPM_Database $database, SPM_Performance_Analyzer $perf_analyzer ) {
        $this->database      = $database;
        $this->perf_analyzer = $perf_analyzer;
    }

    /**
     * Get a standardized summary for the dashboard.
     *
     * @param int $days Time window.
     *
     * @return array
     */
    public function get_dashboard_snapshot( int $days = self::PERIOD_7D ): array {
        global $wpdb;
        $table = SPM_Database::table_name();

        // 1. Core performance metrics (all plugins)
        $plugin_averages = $this->database->get_plugin_averages( $days );
        $error_counts    = $this->database->get_error_counts_by_plugin( $days );

        // 2. Process plugins data
        $plugins = [];
        $total_score = 0;
        $slow_count = 0;

        foreach ( $plugin_averages as $avg ) {
            $basename = $avg['plugin_name'];
            $errors   = 0;
            foreach ( $error_counts as $ec ) {
                if ( $ec['plugin_name'] === $basename ) {
                    $errors = (int) $ec['error_count'];
                    break;
                }
            }

            $report = $this->perf_analyzer->get_plugin_report( $basename, $days );
            $plugins[] = [
                'plugin_name'  => $basename,
                'avg_ms'       => $report['avg_ms'],
                'min_ms'       => $report['min_ms'],
                'max_ms'       => $report['max_ms'],
                'sample_count' => $report['sample_count'],
                'error_count'  => $errors,
                'score'        => $report['score'],
                'grade'        => $report['grade'],
                'is_slow'      => $report['is_slow'],
            ];

            $total_score += $report['score'];
            if ( $report['is_slow'] ) $slow_count++;
        }

        $total_plugins = count( $plugins );
        $avg_score     = $total_plugins > 0 ? $total_score / $total_plugins : 100;

        // 3. Global Stats
        $total_logs   = $this->database->count_logs_since( $days );
        $total_errors = $this->database->count_errors_since( $days );

        return [
            'period_days'  => $days,
            'period_label' => self::PERIOD_LABELS[ $days ] ?? "{$days} days",
            'summary'      => [
                'total_plugins' => $total_plugins,
                'slow_plugins'  => $slow_count,
                'avg_score'     => round( $avg_score, 1 ),
                'total_logs'    => $total_logs,
                'total_errors'  => $total_errors,
            ],
            'plugins'       => $plugins,
            'slow_plugins'  => array_values( array_filter( $plugins, fn($p) => $p['is_slow'] ) ),
            'recent_errors' => $this->database->get_recent_errors( $days, 10 ),
            'generated_at'  => current_time( 'mysql' ),
        ];
    }

    /**
     * Get performance diagnostics for a single plugin.
     *
     * @param string $basename Plugin basename.
     * @param int    $days     Time window.
     *
     * @return array
     */
    public function get_plugin_performance_summary( string $basename, int $days = self::PERIOD_7D ): array {
        return $this->perf_analyzer->get_plugin_report( $basename, $days );
    }

    /**
     * Get recent logs for a single plugin.
     */
    public function get_plugin_logs( string $basename, int $limit = 20, int $days = self::PERIOD_7D ): array {
        global $wpdb;
        $table = SPM_Database::table_name();
        
        $where = "WHERE plugin_name = %s";
        $params = [ $basename ];
        
        if ( $days > 0 ) {
            $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params[] = $days;
        }
        
        $params[] = $limit;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d",
            $params
        ), ARRAY_A ) ?: [];
    }

    /**
     * Get error-only logs for a single plugin.
     */
    public function get_plugin_errors( string $basename, int $limit = 20, int $days = self::PERIOD_7D ): array {
        global $wpdb;
        $table = SPM_Database::table_name();
        
        $where = "WHERE plugin_name = %s AND error_message IS NOT NULL AND error_message != ''";
        $params = [ $basename ];
        
        if ( $days > 0 ) {
            $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params[] = $days;
        }
        
        $params[] = $limit;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d",
            $params
        ), ARRAY_A ) ?: [];
    }

    /**
     * Get daily averages for a single plugin.
     */
    public function get_plugin_history( string $basename, int $days = self::PERIOD_7D ): array {
        return $this->database->get_daily_averages_for_plugin( $basename, $days );
    }

    /**
     * Standardized method to resolve period from request.
     */
    public static function resolve_period(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $val = isset( $_GET['spm_period'] ) ? (int) $_GET['spm_period'] : self::PERIOD_7D;
        
        $allowed = [ self::PERIOD_24H, self::PERIOD_7D, self::PERIOD_30D, self::PERIOD_ALL ];
        return in_array( $val, $allowed, true ) ? $val : self::PERIOD_7D;
    }
}
