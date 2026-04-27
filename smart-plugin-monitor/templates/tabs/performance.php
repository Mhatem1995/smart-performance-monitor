<?php
/**
 * Performance Tab Template for Plugin Details.
 *
 * @var array $meta Plugin metadata.
 * @var array $perf Performance metrics from SPM_Performance_Analyzer.
 * @var array $history Daily history data.
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trend_labels = [
    'improving' => [ 'label' => __( 'Improving', 'smart-plugin-monitor' ), 'class' => 'success', 'icon' => 'arrow-down' ],
    'stable'    => [ 'label' => __( 'Stable', 'smart-plugin-monitor' ),    'class' => 'neutral', 'icon' => 'minus' ],
    'degrading' => [ 'label' => __( 'Degrading', 'smart-plugin-monitor' ), 'class' => 'danger',  'icon' => 'arrow-up' ],
];

$trend = $trend_labels[ $perf['trend'] ?? 'stable' ] ?? $trend_labels['stable'];
?>

<div class="spm-tab-content spm-tab-content--active" id="spm-tab-performance">
    
    <!-- ── Trend & Health Header ── -->
    <div class="spm-performance-summary-card">
        <div class="spm-perf-summary-left">
            <div class="spm-big-score">
                <span class="spm-big-score__val"><?php echo (int) ( $perf['score'] ?? 0 ); ?></span>
                <span class="spm-big-score__label"><?php esc_html_e( 'Health Score', 'smart-plugin-monitor' ); ?></span>
            </div>
            <div class="spm-perf-trend-indicator spm-perf-trend-indicator--<?php echo esc_attr( $trend['class'] ); ?>">
                <span class="spm-trend-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <?php if ( ( $perf['trend'] ?? '' ) === 'improving' ) : ?>
                            <path d="M7 7l10 10M17 7v10H7"/>
                        <?php elseif ( ( $perf['trend'] ?? '' ) === 'degrading' ) : ?>
                            <path d="M7 17L17 7M7 7h10v10"/>
                        <?php else : ?>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        <?php endif; ?>
                    </svg>
                    <?php echo esc_html( $trend['label'] ); ?>
                </span>
                <span class="spm-trend-desc"><?php esc_html_e( 'Performance trend over 7 days', 'smart-plugin-monitor' ); ?></span>
            </div>
        </div>
        <div class="spm-perf-summary-right">
             <div class="spm-grade-box spm-grade-box--<?php echo esc_attr( strtolower( $perf['grade'] ?? 'n/a' ) ); ?>">
                <span class="spm-grade-box__letter"><?php echo esc_html( $perf['grade'] ?? '-' ); ?></span>
                <span class="spm-grade-box__label"><?php esc_html_e( 'Performance Grade', 'smart-plugin-monitor' ); ?></span>
             </div>
        </div>
    </div>

    <!-- ── Visual KPI Cards ── -->
    <div class="spm-details-stats-grid">
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Avg Load Time', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value"><?php echo esc_html( $perf['avg_ms'] ?? 0 ); ?> <small>ms</small></span>
            <div class="spm-detail-card__footer">
                <span class="<?php echo ( $perf['is_slow'] ?? false ) ? 'spm-text--danger' : 'spm-text--success'; ?>">
                    <?php echo ( $perf['is_slow'] ?? false ) ? esc_html__( 'Attention required', 'smart-plugin-monitor' ) : esc_html__( 'Optimal speed', 'smart-plugin-monitor' ); ?>
                </span>
            </div>
        </div>
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Peak Load Time', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value"><?php echo esc_html( $perf['max_ms'] ?? 0 ); ?> <small>ms</small></span>
            <div class="spm-detail-card__footer">
                <span><?php esc_html_e( 'Slowest sample recorded', 'smart-plugin-monitor' ); ?></span>
            </div>
        </div>
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Total Samples', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value"><?php echo number_format_i18n( $perf['sample_count'] ?? 0 ); ?></span>
            <div class="spm-detail-card__footer">
                <span><?php esc_html_e( 'Monitoring data points', 'smart-plugin-monitor' ); ?></span>
            </div>
        </div>
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Errors Found', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value <?php echo $perf['error_count'] > 0 ? 'spm-text--danger' : ''; ?>">
                <?php echo number_format_i18n( $perf['error_count'] ?? 0 ); ?>
            </span>
            <div class="spm-detail-card__footer">
                <span><?php esc_html_e( 'Captured PHP issues', 'smart-plugin-monitor' ); ?></span>
            </div>
        </div>
    </div>

    <!-- ── 7-Day History Chart ── -->
    <div class="spm-panel">
        <div class="spm-panel__header">
            <h2 class="spm-panel__title"><?php esc_html_e( '7-Day Load Time Trend', 'smart-plugin-monitor' ); ?></h2>
        </div>
        <div class="spm-panel__body">
            <?php if ( empty( $history ) ) : ?>
                <p class="spm-detail-empty"><?php esc_html_e( 'Insufficient data for trend visualization.', 'smart-plugin-monitor' ); ?></p>
            <?php else : 
                $max_ms = 100;
                foreach ( $history as $day ) {
                    $day_avg = (float) ( $day['avg_ms'] ?? 0 );
                    if ( $day_avg > $max_ms ) {
                        $max_ms = $day_avg;
                    }
                }
            ?>
                <div class="spm-history-chart">
                    <div class="spm-history-chart__bars">
                        <?php foreach ( $history as $day ) : 
                            $avg_ms   = (float) ( $day['avg_ms'] ?? 0 );
                            $date_str = (string) ( $day['date'] ?? '' );
                            
                            $height      = ( $max_ms > 0 ) ? ( $avg_ms / $max_ms ) * 100 : 0;
                            $day_label   = $date_str ? date( 'D', strtotime( $date_str ) ) : '??';
                            $color_class = $avg_ms > 500 ? 'danger' : ( $avg_ms > 200 ? 'warning' : 'success' );
                        ?>
                            <div class="spm-history-bar-wrap">
                                <div class="spm-history-bar spm-history-bar--<?php echo $color_class; ?>" 
                                     style="height: <?php echo esc_attr( $height ); ?>%;"
                                     title="<?php printf( esc_attr__( '%s: %.2f ms', 'smart-plugin-monitor' ), esc_attr( $date_str ), $avg_ms ); ?>">
                                </div>
                                <span class="spm-history-bar__label"><?php echo esc_html( $day_label ); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="spm-history-chart__legend">
                        <span>0ms</span>
                        <span><?php echo number_format($max_ms, 0); ?>ms</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>
