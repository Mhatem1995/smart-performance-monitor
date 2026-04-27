<?php
/**
 * Performance Analyzer for Smart Plugin Monitor.
 *
 * Analyzes stored logs to produce per-plugin performance reports:
 *   - Average / min / max load times
 *   - Slow-plugin detection (configurable threshold)
 *   - Per-plugin performance score (0–100)
 *   - Error frequency
 *
 * This class is UI-independent — it returns structured arrays
 * that any renderer (dashboard, REST API, CLI) can consume.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Analyzer {

    /**
     * Default threshold in milliseconds. Plugins averaging above
     * this value are flagged as "slow".
     */
    public const DEFAULT_SLOW_THRESHOLD_MS = 500.0;

    /**
     * Score weights (must sum to 1.0).
     *
     * The score formula rewards low load times and penalises errors:
     *   score = (speed_weight × speed_score) + (error_weight × error_score)
     */
    private const WEIGHT_SPEED = 0.7;
    private const WEIGHT_ERROR = 0.3;

    /**
     * @var SPM_Database
     */
    private SPM_Database $database;

    /**
     * Slow-plugin threshold in milliseconds.
     *
     * @var float
     */
    private float $slow_threshold_ms;

    /**
     * @param SPM_Database $database
     * @param float        $slow_threshold_ms Override the default 500 ms threshold.
     */
    public function __construct( SPM_Database $database, float $slow_threshold_ms = self::DEFAULT_SLOW_THRESHOLD_MS ) {
        $this->database          = $database;
        $this->slow_threshold_ms = $slow_threshold_ms;
    }

    /**
     * Run a full analysis and return a structured report.
     *
     * @param int $days Analyse logs from the last N days (default 7).
     *
     * @return array{
     *     generated_at:     string,
     *     period_days:      int,
     *     slow_threshold_ms: float,
     *     summary:          array{total_plugins: int, slow_plugins: int, avg_score: float},
     *     plugins:          array<int, array{
     *         plugin_name:   string,
     *         avg_ms:        float,
     *         min_ms:        float,
     *         max_ms:        float,
     *         sample_count:  int,
     *         error_count:   int,
     *         is_slow:       bool,
     *         score:         int,
     *         grade:         string
     *     }>
     * }
     */
    public function run( int $days = 7 ): array {
        $averages     = $this->database->get_plugin_averages( $days );
        $error_counts = $this->database->get_error_counts_by_plugin( $days );

        // Index errors by plugin name for O(1) lookups.
        $error_map = [];
        foreach ( $error_counts as $row ) {
            $error_map[ $row['plugin_name'] ] = (int) $row['error_count'];
        }

        $plugins      = [];
        $total_score  = 0;
        $slow_count   = 0;

        foreach ( $averages as $row ) {
            $avg_ms       = (float) $row['avg_ms'];
            $sample_count = (int) $row['sample_count'];
            $errors       = $error_map[ $row['plugin_name'] ] ?? 0;
            $is_slow      = $avg_ms > $this->slow_threshold_ms;

            $score = $this->calculate_score( $avg_ms, $errors, $sample_count );

            if ( $is_slow ) {
                $slow_count++;
            }

            $total_score += $score;

            $plugins[] = [
                'plugin_name'  => $row['plugin_name'],
                'avg_ms'       => round( $avg_ms, 2 ),
                'min_ms'       => round( (float) $row['min_ms'], 2 ),
                'max_ms'       => round( (float) $row['max_ms'], 2 ),
                'sample_count' => $sample_count,
                'error_count'  => $errors,
                'is_slow'      => $is_slow,
                'score'        => $score,
                'grade'        => self::score_to_grade( $score ),
            ];
        }

        $total_plugins = count( $plugins );

        return [
            'generated_at'      => current_time( 'mysql' ),
            'period_days'       => $days,
            'slow_threshold_ms' => $this->slow_threshold_ms,
            'summary'           => [
                'total_plugins' => $total_plugins,
                'slow_plugins'  => $slow_count,
                'avg_score'     => $total_plugins > 0
                    ? round( $total_score / $total_plugins, 1 )
                    : 0.0,
            ],
            'plugins' => $plugins,
        ];
    }

    /**
     * Convenience method: return only the slow plugins.
     *
     * @param int $days Analysis window.
     *
     * @return array List of plugin report entries where is_slow === true.
     */
    public function get_slow_plugins( int $days = 7 ): array {
        $report = $this->run( $days );

        return array_values(
            array_filter( $report['plugins'], fn( $p ) => $p['is_slow'] )
        );
    }

    /**
     * Calculate a performance score (0–100) for a single plugin.
     *
     * Speed score:  inversely proportional to avg load time.
     *               0 ms → 100, ≥ threshold → 0, linear between.
     *
     * Error score:  100 if zero errors; decays as error_ratio increases.
     *               error_ratio = errors / samples (clamped to 1.0).
     *
     * Final score:  weighted blend of speed + error scores.
     *
     * @param float $avg_ms       Average load time in milliseconds.
     * @param int   $error_count  Number of logged errors.
     * @param int   $sample_count Number of log entries (to normalise error rate).
     *
     * @return int Score clamped to [0, 100].
     */
    private function calculate_score( float $avg_ms, int $error_count, int $sample_count ): int {
        // Speed component (linear scale, 0 ms = 100, threshold = 0).
        $speed_score = max( 0.0, 1.0 - ( $avg_ms / $this->slow_threshold_ms ) ) * 100;

        // Error component.
        if ( $sample_count > 0 && $error_count > 0 ) {
            $error_ratio = min( 1.0, $error_count / $sample_count );
            $error_score = ( 1.0 - $error_ratio ) * 100;
        } else {
            $error_score = 100.0;
        }

        $final = ( self::WEIGHT_SPEED * $speed_score ) + ( self::WEIGHT_ERROR * $error_score );

        return (int) round( max( 0, min( 100, $final ) ) );
    }

    /**
     * Map a numeric score to a letter grade.
     *
     * @param int $score 0–100.
     *
     * @return string One of A, B, C, D, F.
     */
    public static function score_to_grade( int $score ): string {
        if ( $score >= 90 ) {
            return 'A';
        }
        if ( $score >= 75 ) {
            return 'B';
        }
        if ( $score >= 60 ) {
            return 'C';
        }
        if ( $score >= 40 ) {
            return 'D';
        }
        return 'F';
    }
}
