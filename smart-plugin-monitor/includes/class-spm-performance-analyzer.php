<?php
/**
 * Specialized Performance Analyzer for a single plugin.
 *
 * Provides deep-dive metrics, health scoring, and historical trend analysis.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Performance_Analyzer {

    private SPM_Database $database;

    /**
     * Thresholds for scoring (ms).
     */
    private const SLOW_THRESHOLD    = 500;
    private const CRITICAL_THRESHOLD = 1000;

    public function __construct( SPM_Database $database ) {
        $this->database = $database;
    }

    /**
     * Get comprehensive performance report for a single plugin.
     */
    public function get_plugin_report( string $basename, int $days = 7 ): array {
        global $wpdb;
        $table = SPM_Database::table_name();

        // 1. Basic Stats
        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                AVG(load_time_ms) as avg_ms,
                MAX(load_time_ms) as max_ms,
                MIN(load_time_ms) as min_ms,
                COUNT(*) as sample_count,
                COUNT(CASE WHEN error_message IS NOT NULL AND error_message != '' THEN 1 END) as error_count
            FROM {$table}
            WHERE plugin_name = %s AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $basename,
            $days
        ), ARRAY_A );

        $avg_ms       = (float) ($stats['avg_ms'] ?? 0);
        $sample_count = (int) ($stats['sample_count'] ?? 0);
        $error_count  = (int) ($stats['error_count'] ?? 0);

        // 2. Health Score Calculation
        $score = $this->calculate_health_score( $avg_ms, $error_count, $sample_count );

        // 3. Trend Analysis
        $trends = $this->get_historical_trends( $basename );

        return [
            'avg_ms'       => round( $avg_ms, 2 ),
            'max_ms'       => round( (float) ($stats['max_ms'] ?? 0), 2 ),
            'min_ms'       => round( (float) ($stats['min_ms'] ?? 0), 2 ),
            'sample_count' => $sample_count,
            'error_count'  => $error_count,
            'score'        => $score,
            'grade'        => $this->get_grade( $score ),
            'trend'        => $trends['primary_trend'],
            'trends'       => $trends,
            'is_slow'      => $avg_ms > self::SLOW_THRESHOLD,
        ];
    }

    /**
     * Calculate Health Score (0-100).
     */
    private function calculate_health_score( float $avg_ms, int $error_count, int $sample_count ): int {
        if ( $sample_count === 0 ) return 0;
        $score = 100;
        if ( $avg_ms > 100 ) {
            $score -= min( 50, (int) ( ( $avg_ms - 100 ) / 10 ) );
        }
        $score -= min( 50, $error_count * 10 );
        if ( $sample_count < 10 ) $score -= 5;
        return (int) max( 0, $score );
    }

    /**
     * Build Historical Trends Engine.
     * Compares Today vs 7d and 7d vs 30d.
     */
    public function get_historical_trends( string $basename ): array {
        global $wpdb;
        $table = SPM_Database::table_name();

        // 1. Periods
        $avg_24h = (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(load_time_ms) FROM {$table} WHERE plugin_name = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)", $basename ) );
        $avg_7d  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(load_time_ms) FROM {$table} WHERE plugin_name = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", $basename ) );
        $avg_30d = (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(load_time_ms) FROM {$table} WHERE plugin_name = %s AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", $basename ) );

        // 2. Comparisons
        $today_vs_7d = $this->calculate_diff( $avg_24h, $avg_7d );
        $w_vs_month  = $this->calculate_diff( $avg_7d, $avg_30d );

        // 3. Version Correlation
        $versions = $wpdb->get_results( $wpdb->prepare( 
            "SELECT DISTINCT plugin_version, MIN(created_at) as first_seen 
             FROM {$table} WHERE plugin_name = %s 
             GROUP BY plugin_version ORDER BY first_seen DESC LIMIT 5", 
            $basename 
        ), ARRAY_A );

        return [
            'avg_24h'       => round( $avg_24h, 2 ),
            'avg_7d'        => round( $avg_7d, 2 ),
            'avg_30d'       => round( $avg_30d, 2 ),
            'today_vs_7d'   => $today_vs_7d, // [status, diff_pct]
            '7d_vs_30d'     => $w_vs_month,
            'primary_trend' => $today_vs_7d['status'],
            'versions'      => $versions,
        ];
    }

    private function calculate_diff( float $current, float $baseline ): array {
        if ( ! $baseline || ! $current ) return [ 'status' => 'stable', 'pct' => 0 ];

        $diff_pct = ( ( $current - $baseline ) / $baseline ) * 100;

        if ( $diff_pct < -10 ) return [ 'status' => 'improving', 'pct' => round( abs($diff_pct), 1 ) ];
        if ( $diff_pct > 10 )  return [ 'status' => 'degrading', 'pct' => round( $diff_pct, 1 ) ];
        
        return [ 'status' => 'stable', 'pct' => round( abs($diff_pct), 1 ) ];
    }

    private function get_grade( int $score ): string {
        if ( $score >= 90 ) return 'A';
        if ( $score >= 80 ) return 'B';
        if ( $score >= 70 ) return 'C';
        if ( $score >= 60 ) return 'D';
        return 'F';
    }
}
