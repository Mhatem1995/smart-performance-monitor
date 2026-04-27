<?php
/**
 * Centralised Query Service for Smart Plugin Monitor.
 *
 * Provides a single-pass data snapshot for a given time period.
 * All dashboard widgets consume from this snapshot, eliminating
 * inconsistencies between KPI cards, error feeds, and plugin lists.
 *
 * The snapshot is lazily loaded and cached per request — calling
 * get_snapshot() multiple times with the same period returns the
 * same result without re-querying.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Query_Service {

    /**
     * Allowed period presets.
     */
    public const PERIOD_24H = 1;
    public const PERIOD_7D  = 7;
    public const PERIOD_30D = 30;

    /**
     * Map of allowed periods to display labels.
     *
     * @var array<int, string>
     */
    public const PERIOD_LABELS = [
        self::PERIOD_24H => '24 hours',
        self::PERIOD_7D  => '7 days',
        self::PERIOD_30D => '30 days',
    ];

    private SPM_Database $database;
    private SPM_Analyzer $analyzer;
    private SPM_License_Detector $license_detector;

    /**
     * In-memory cache: keyed by period days.
     *
     * @var array<int, array>
     */
    private array $snapshots = [];

    public function __construct(
        SPM_Database $database,
        SPM_Analyzer $analyzer,
        SPM_License_Detector $license_detector
    ) {
        $this->database         = $database;
        $this->analyzer         = $analyzer;
        $this->license_detector = $license_detector;
    }

    /**
     * Resolve the active period from the query string.
     *
     * Reads ?spm_period=1|7|30, validates against allowed values,
     * defaults to 7 days.
     *
     * @return int Period in days.
     */
    public static function resolve_period(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw = isset( $_GET['spm_period'] ) ? absint( $_GET['spm_period'] ) : self::PERIOD_7D;

        if ( ! array_key_exists( $raw, self::PERIOD_LABELS ) ) {
            return self::PERIOD_7D;
        }

        return $raw;
    }

    /**
     * Get the complete data snapshot for a given period.
     *
     * All dashboard widgets should consume from this single return value.
     *
     * @param int $days Period in days (1, 7, or 30).
     *
     * @return array{
     *     period_days:      int,
     *     period_label:     string,
     *     performance:      array,
     *     plugins:          array,
     *     summary:          array,
     *     recent_errors:    array,
     *     total_errors:     int,
     *     total_logs:       int,
     *     slow_plugins:     array,
     *     license_report:   array
     * }
     */
    public function get_snapshot( int $days = self::PERIOD_7D ): array {
        // Return cached result if available.
        if ( isset( $this->snapshots[ $days ] ) ) {
            return $this->snapshots[ $days ];
        }

        // ── Performance analysis (uses the same $days window) ──
        $perf_report   = $this->analyzer->run( $days );
        $plugins       = $perf_report['plugins'];
        $summary       = $perf_report['summary'];

        // ── Slow plugins (from the same analysis) ──
        $slow_plugins  = array_values(
            array_filter( $plugins, fn( $p ) => $p['is_slow'] )
        );

        // ── Errors (same $days window — fixes the mismatch) ──
        $recent_errors = $this->database->get_recent_errors( $days, 10 );
        $total_errors  = $this->database->count_errors_since( $days );

        // ── Log totals (same window) ──
        $total_logs    = $this->database->count_logs_since( $days );

        // ── License (cached via transient, period-independent) ──
        $license_report = $this->license_detector->scan();

        $snapshot = [
            'period_days'      => $days,
            'period_label'     => self::PERIOD_LABELS[ $days ] ?? "{$days} days",
            'performance'      => $perf_report,
            'plugins'          => $plugins,
            'summary'          => $summary,
            'recent_errors'    => $recent_errors,
            'total_errors'     => $total_errors,
            'total_logs'       => $total_logs,
            'slow_plugins'     => $slow_plugins,
            'license_report'   => $license_report,
            'generated_at'     => $perf_report['generated_at'],
            'slow_threshold_ms'=> $perf_report['slow_threshold_ms'],
        ];

        $this->snapshots[ $days ] = $snapshot;

        return $snapshot;
    }

    /**
     * Get the raw database instance (for advanced consumers).
     */
    public function get_database(): SPM_Database {
        return $this->database;
    }

    /**
     * Get the analyzer instance.
     */
    public function get_analyzer(): SPM_Analyzer {
        return $this->analyzer;
    }

    /**
     * Get the license detector instance.
     */
    public function get_license_detector(): SPM_License_Detector {
        return $this->license_detector;
    }
}
