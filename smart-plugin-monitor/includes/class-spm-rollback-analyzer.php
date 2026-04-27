<?php
/**
 * Rollback Readiness Analyzer for Smart Plugin Monitor.
 *
 * Analyzes version history and performance deltas to determine if
 * a plugin is "Rollback Ready" and if a rollback is recommended.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Rollback_Analyzer {

    private SPM_Database $database;

    public function __construct( SPM_Database $database ) {
        $this->database = $database;
    }

    /**
     * Analyze rollback readiness for a plugin.
     *
     * @param string $basename Plugin basename.
     * @return array
     */
    public function get_readiness_report( string $basename ): array {
        global $wpdb;
        $table = SPM_Database::table_name();

        // 1. Get all unique versions logged
        $versions = $wpdb->get_results( $wpdb->prepare(
            "SELECT plugin_version, AVG(load_time_ms) as avg_ms, COUNT(*) as samples
             FROM {$table}
             WHERE plugin_name = %s
             GROUP BY plugin_version
             ORDER BY MIN(created_at) DESC",
            $basename
        ), ARRAY_A );

        if ( count( $versions ) < 2 ) {
            return [
                'status'  => 'pending',
                'label'   => __( 'Pending Data', 'smart-plugin-monitor' ),
                'score'   => 0,
                'message' => __( 'Insufficient version history to determine rollback readiness.', 'smart-plugin-monitor' ),
                'history' => $versions
            ];
        }

        // 2. Compare current vs previous
        $current  = $versions[0];
        $previous = $versions[1];

        $diff_pct = ( ( $current['avg_ms'] - $previous['avg_ms'] ) / $previous['avg_ms'] ) * 100;
        
        $is_degraded = $diff_pct > 20; // 20% slowdown is a warning
        
        return [
            'status'         => $is_degraded ? 'recommended' : 'ready',
            'label'          => $is_degraded ? __( 'Rollback Recommended', 'smart-plugin-monitor' ) : __( 'Rollback Ready', 'smart-plugin-monitor' ),
            'score'          => $is_degraded ? 40 : 100,
            'current_v'      => $current['plugin_version'],
            'previous_v'     => $previous['plugin_version'],
            'delta_pct'      => round( $diff_pct, 1 ),
            'message'        => $is_degraded 
                ? sprintf( __( 'Current version is %.1f%% slower than v%s. Rollback may improve site speed.', 'smart-plugin-monitor' ), $diff_pct, $previous['plugin_version'] )
                : sprintf( __( 'v%s remains stable compared to v%s.', 'smart-plugin-monitor' ), $current['plugin_version'], $previous['plugin_version'] ),
            'history'        => $versions
        ];
    }
}
