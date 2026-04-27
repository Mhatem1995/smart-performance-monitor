<?php
/**
 * Historical Trends Tab Template for Plugin Details.
 *
 * @var array $meta     Plugin metadata.
 * @var array $perf     Performance report with trends.
 * @var array $rollback Rollback readiness data.
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$trends   = $perf['trends'];
$rollback = $data['rollback'] ?? [];
?>

<div class="spm-tab-content" id="spm-tab-history">

    <!-- ── Rollback Readiness Engine ── -->
    <div class="spm-panel spm-panel--readiness">
        <div class="spm-panel__header">
            <h2 class="spm-panel__title"><?php esc_html_e( 'Rollback Readiness', 'smart-plugin-monitor' ); ?></h2>
            <span class="spm-badge spm-badge--<?php echo esc_attr( $rollback['status'] ?? '' ); ?>"><?php echo esc_html( $rollback['label'] ?? '' ); ?></span>
        </div>
        <div class="spm-panel__body">
            <div class="spm-readiness-hero">
                <div class="spm-readiness-score">
                    <div class="spm-ring spm-ring--<?php echo esc_attr( $rollback['status'] ); ?>">
                        <span class="spm-ring__value"><?php echo (int) $rollback['score']; ?></span>
                    </div>
                </div>
                <div class="spm-readiness-content">
                    <p class="spm-readiness-msg"><?php echo esc_html( $rollback['message'] ?? '' ); ?></p>
                    <?php if ( $rollback['status'] === 'recommended' ) : ?>
                        <div class="spm-alert spm-alert--danger">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>Performance degradation detected since update.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ── Comparison Engine ── -->
    <div class="spm-details-stats-grid">
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Today vs 7-Day Avg', 'smart-plugin-monitor' ); ?></span>
            <div class="spm-trend-compare">
                <span class="spm-trend-compare__val"><?php echo (float) $trends['avg_24h']; ?> <small>ms</small></span>
                <span class="spm-trend-badge spm-trend-badge--<?php echo esc_attr( $trends['today_vs_7d']['status'] ?? '' ); ?>">
                    <?php if ( $trends['today_vs_7d']['status'] === 'improving' ) : ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>
                    <?php elseif ( $trends['today_vs_7d']['status'] === 'degrading' ) : ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    <?php endif; ?>
                    <?php echo (float) $trends['today_vs_7d']['pct']; ?>%
                </span>
            </div>
            <div class="spm-detail-card__footer">
                <span>Baseline: <?php echo (float) $trends['avg_7d']; ?> ms</span>
            </div>
        </div>
        
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( '7-Day vs 30-Day Avg', 'smart-plugin-monitor' ); ?></span>
            <div class="spm-trend-compare">
                <span class="spm-trend-compare__val"><?php echo (float) $trends['avg_7d']; ?> <small>ms</small></span>
                <span class="spm-trend-badge spm-trend-badge--<?php echo esc_attr( $trends['7d_vs_30d']['status'] ?? '' ); ?>">
                    <?php echo (float) $trends['7d_vs_30d']['pct']; ?>%
                </span>
            </div>
            <div class="spm-detail-card__footer">
                <span>Baseline: <?php echo (float) $trends['avg_30d']; ?> ms</span>
            </div>
        </div>

        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Trend Sentiment', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value">
                <span class="spm-sentiment spm-sentiment--<?php echo esc_attr( $trends['primary_trend'] ?? '' ); ?>">
                    <?php echo ucfirst( $trends['primary_trend'] ); ?>
                </span>
            </span>
            <div class="spm-detail-card__footer">
                <span>Based on last 24h activity</span>
            </div>
        </div>
    </div>

    <!-- ── Version Correlation ── -->
    <div class="spm-panel">
        <div class="spm-panel__header">
            <h2 class="spm-panel__title"><?php esc_html_e( 'Version Correlation', 'smart-plugin-monitor' ); ?></h2>
            <p class="spm-panel__desc"><?php esc_html_e( 'Performance tracked across different plugin releases.', 'smart-plugin-monitor' ); ?></p>
        </div>
        <div class="spm-panel__body">
            <?php if ( empty( $trends['versions'] ) ) : ?>
                <p class="spm-detail-empty"><?php esc_html_e( 'No version history data yet.', 'smart-plugin-monitor' ); ?></p>
            <?php else : ?>
                <div class="spm-version-history">
                    <table class="spm-version-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Version', 'smart-plugin-monitor' ); ?></th>
                                <th><?php esc_html_e( 'First Seen', 'smart-plugin-monitor' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'smart-plugin-monitor' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $trends['versions'] as $v ) : 
                                $is_current = ($v['plugin_version'] === $meta['version']);
                            ?>
                            <tr class="<?php echo $is_current ? 'spm-version--current' : ''; ?>">
                                <td>
                                    <strong>v<?php echo esc_html( $v['plugin_version'] ?? '' ); ?></strong>
                                    <?php if ( $is_current ) : ?>
                                        <span class="spm-badge spm-badge--success"><?php esc_html_e( 'Active', 'smart-plugin-monitor' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($v['first_seen']) ) ); ?></td>
                                <td>
                                    <span class="spm-dot spm-dot--success"></span> <?php esc_html_e( 'Logged', 'smart-plugin-monitor' ); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── 30-Day Activity Sparkline (Conceptual Placeholder) ── -->
    <div class="spm-panel">
        <div class="spm-panel__header">
            <h2 class="spm-panel__title"><?php esc_html_e( '30-Day Performance Trajectory', 'smart-plugin-monitor' ); ?></h2>
        </div>
        <div class="spm-panel__body">
             <div class="spm-sparkline-wrap">
                 <!-- In a real scenario, we'd loop through daily averages here -->
                 <p class="spm-detail-empty"><?php esc_html_e( 'Historical charts are building as data is collected...', 'smart-plugin-monitor' ); ?></p>
             </div>
        </div>
    </div>

</div>
