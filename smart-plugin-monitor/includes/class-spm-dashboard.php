<?php
/**
 * Dashboard renderer for Smart Plugin Monitor.
 *
 * Modern card-based UI inspired by Vercel / Stripe admin panels.
 * Renders a performance gauge, plugin health cards, slow plugin
 * alerts, and a recent error feed.
 *
 * All data comes from the centralised SPM_Query_Service snapshot,
 * ensuring every widget uses the same date window.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPM_Dashboard {

    private SPM_Data_Service $data_service;

    public function __construct( SPM_Data_Service $data_service ) {
        $this->data_service = $data_service;
    }

    /**
     * Render the full dashboard.
     */
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'smart-plugin-monitor' ) );
        }

        $period  = SPM_Data_Service::resolve_period();
        $snap    = $this->data_service->get_dashboard_snapshot( $period );

        // ── Unified data from the snapshot ──
        $summary        = $snap['summary'];
        $plugins        = $snap['plugins'];
        $total_logs     = $summary['total_logs'];
        $total_errors   = $summary['total_errors'];
        $slow_plugins   = $snap['slow_plugins'];
        $recent_errors  = $snap['recent_errors'];
        $period_label   = $snap['period_label'];

        // License report is still separate as it is global.
        $license_report = SPM_License_Detector::get_cached_scan();
        if ( ! $license_report ) {
            $license_detector = new SPM_License_Detector();
            $license_report = $license_detector->scan();
        }

        $isolation_target = get_option( 'spm_isolation_target' );
        ?>
        <div class="spm" id="spm-dashboard">

            <?php if ( $isolation_target ) : ?>
            <div class="spm-isolation-banner">
                <div class="spm-isolation-banner__icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                </div>
                <div class="spm-isolation-banner__text">
                    <strong><?php esc_html_e( 'Isolation Mode Active:', 'smart-plugin-monitor' ); ?></strong>
                    <?php printf( esc_html__( '"%s" is temporarily deactivated for testing.', 'smart-plugin-monitor' ), esc_html( $isolation_target ) ); ?>
                </div>
                <button class="spm-btn spm-btn--white spm-btn--sm" data-spm-action="restore">
                    <?php esc_html_e( 'End Isolation & Restore', 'smart-plugin-monitor' ); ?>
                </button>
            </div>
            <?php endif; ?>

            <!-- ── Header ── -->
            <header class="spm-header">
                <div class="spm-header__inner">
                    <div class="spm-header__left">
                        <div class="spm-header__icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20V10"/><path d="M18 20V4"/><path d="M6 20v-4"/></svg>
                        </div>
                        <div>
                            <h1 class="spm-header__title"><?php esc_html_e( 'Plugin Monitor', 'smart-plugin-monitor' ); ?></h1>
                            <p class="spm-header__sub">
                                <?php
                                printf(
                                    esc_html__( '%d plugins tracked · Last %s', 'smart-plugin-monitor' ),
                                    $summary['total_plugins'],
                                    esc_html( $period_label )
                                );
                                ?>
                            </p>
                        </div>
                    </div>
                    <div class="spm-header__right">
                        <div class="spm-header__actions">
                            <button class="spm-hdr-btn" data-spm-action="scan" title="<?php esc_attr_e( 'Re-run performance scan', 'smart-plugin-monitor' ); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-9-9"/><polyline points="21 3 21 9 15 9"/></svg>
                                <?php esc_html_e( 'Scan', 'smart-plugin-monitor' ); ?>
                            </button>
                            <button class="spm-hdr-btn" data-spm-action="deep-scan" title="<?php esc_attr_e( 'Force-refresh license verification', 'smart-plugin-monitor' ); ?>">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                <?php esc_html_e( 'Deep Scan', 'smart-plugin-monitor' ); ?>
                            </button>
                            <div class="spm-export-dropdown">
                                <button class="spm-hdr-btn spm-export-toggle" title="<?php esc_attr_e( 'Download diagnostic reports', 'smart-plugin-monitor' ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <?php esc_html_e( 'Export', 'smart-plugin-monitor' ); ?>
                                </button>
                                <div class="spm-export-menu">
                                    <button data-spm-action="export-json"><?php esc_html_e( 'JSON Report', 'smart-plugin-monitor' ); ?></button>
                                    <button data-spm-action="export-csv"><?php esc_html_e( 'CSV Spreadsheet', 'smart-plugin-monitor' ); ?></button>
                                    <button data-spm-action="export-pdf"><?php esc_html_e( 'PDF Diagnostic (Print)', 'smart-plugin-monitor' ); ?></button>
                                </div>
                            </div>
                        </div>
                        <?php $this->render_period_filter( $period ); ?>
                        <span class="spm-header__logcount">
                            <?php
                            printf(
                                esc_html__( '%s log entries', 'smart-plugin-monitor' ),
                                number_format_i18n( $total_logs )
                            );
                            ?>
                        </span>
                    </div>
                </div>
            </header>

            <!-- ── Score + KPI Row ── -->
            <div class="spm-kpi-row">

                <!-- Score Gauge -->
                <div class="spm-gauge-card" id="spm-score-card">
                    <?php $this->render_score_gauge( $summary['avg_score'] ); ?>
                </div>

                <!-- KPI Cards -->
                <div class="spm-kpi-grid">
                    <?php
                    $this->render_kpi_card(
                        esc_html__( 'Tracked', 'smart-plugin-monitor' ),
                        (string) $summary['total_plugins'],
                        'neutral',
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 18 22 12 16 6"/><path d="M8 6 2 12 8 18"/></svg>'
                    );
                    $this->render_kpi_card(
                        esc_html__( 'Slow Plugins', 'smart-plugin-monitor' ),
                        (string) $summary['slow_plugins'],
                        $summary['slow_plugins'] > 0 ? 'danger' : 'success',
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>'
                    );
                    $this->render_kpi_card(
                        sprintf( esc_html__( 'Errors (%s)', 'smart-plugin-monitor' ), esc_html( $period_label ) ),
                        (string) $total_errors,
                        $total_errors > 0 ? 'warning' : 'success',
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                    );
                    $this->render_kpi_card(
                        esc_html__( 'Avg Score', 'smart-plugin-monitor' ),
                        number_format( $summary['avg_score'], 1 ),
                        $summary['avg_score'] >= 75 ? 'success' : ( $summary['avg_score'] >= 50 ? 'warning' : 'danger' ),
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'
                    );
                    ?>
                </div>
            </div>

            <!-- ── Two-column body ── -->
            <div class="spm-body-grid">

                <!-- Left: Plugin Health Cards -->
                <div class="spm-panel" id="spm-plugins-panel">
                    <div class="spm-panel__header">
                        <h2 class="spm-panel__title"><?php esc_html_e( 'Plugin Health', 'smart-plugin-monitor' ); ?></h2>
                        <span class="spm-panel__badge"><?php echo esc_html( count( $plugins ) ); ?></span>
                    </div>
                    <div class="spm-panel__body spm-plugin-list">
                        <?php if ( empty( $plugins ) ) : ?>
                            <div class="spm-empty-state">
                                <p><?php esc_html_e( 'No analysis data yet. Data will appear after a few page loads.', 'smart-plugin-monitor' ); ?></p>
                            </div>
                        <?php else : ?>
                            <?php foreach ( $plugins as $p ) : ?>
                                <?php
                                $status = 'success';
                                if ( $p['is_slow'] || $p['score'] < 40 ) {
                                    $status = 'danger';
                                } elseif ( $p['error_count'] > 0 || $p['score'] < 75 ) {
                                    $status = 'warning';
                                }
                                ?>
                                <div class="spm-plugin-card spm-plugin-card--<?php echo esc_attr( $status ); ?>" data-spm-basename="<?php echo esc_attr( $p['plugin_name'] ); ?>" id="spm-plugin-<?php echo esc_attr( sanitize_title( $p['plugin_name'] ) ); ?>">
                                    <div class="spm-plugin-card__status">
                                        <span class="spm-dot spm-dot--<?php echo esc_attr( $status ); ?>"></span>
                                    </div>
                                    <div class="spm-plugin-card__info">
                                        <span class="spm-plugin-card__name"><?php echo esc_html( $p['plugin_name'] ); ?></span>
                                        <span class="spm-plugin-card__meta">
                                            <?php echo esc_html( $p['avg_ms'] ); ?> ms avg
                                            · <?php echo esc_html( $p['sample_count'] ); ?> samples
                                            <?php if ( $p['error_count'] > 0 ) : ?>
                                                · <span class="spm-plugin-card__errors"><?php echo esc_html( $p['error_count'] ); ?> errors</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="spm-plugin-card__score">
                                        <div class="spm-ring spm-ring--<?php echo esc_attr( strtolower( $p['grade'] ) ); ?>">
                                            <span class="spm-ring__value"><?php echo esc_html( $p['score'] ); ?></span>
                                        </div>
                                        <span class="spm-grade-label spm-grade-label--<?php echo esc_attr( strtolower( $p['grade'] ) ); ?>"><?php echo esc_html( $p['grade'] ); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right column -->
                <div class="spm-right-col">

                    <!-- Slow plugins alert -->
                    <?php if ( ! empty( $slow_plugins ) ) : ?>
                    <div class="spm-panel spm-panel--alert" id="spm-slow-panel">
                        <div class="spm-panel__header spm-panel__header--danger">
                            <h2 class="spm-panel__title">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                <?php esc_html_e( 'Slow Plugins', 'smart-plugin-monitor' ); ?>
                            </h2>
                            <span class="spm-panel__badge spm-panel__badge--danger"><?php echo esc_html( count( $slow_plugins ) ); ?></span>
                        </div>
                        <div class="spm-panel__body">
                            <?php foreach ( $slow_plugins as $sp ) : ?>
                                <div class="spm-alert-row">
                                    <span class="spm-alert-row__name"><?php echo esc_html( $sp['plugin_name'] ); ?></span>
                                    <span class="spm-alert-row__value spm-alert-row__value--danger"><?php echo esc_html( $sp['avg_ms'] ); ?> ms</span>
                                </div>
                            <?php endforeach; ?>
                            <p class="spm-alert-note">
                                <?php
                                printf(
                                    esc_html__( 'Threshold: %s ms average load time', 'smart-plugin-monitor' ),
                                    '500'
                                );
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Recent errors feed -->
                    <div class="spm-panel" id="spm-errors-panel">
                        <div class="spm-panel__header">
                            <h2 class="spm-panel__title"><?php esc_html_e( 'Recent Errors', 'smart-plugin-monitor' ); ?></h2>
                            <div style="display:flex; align-items:center; gap: 8px;">
                                <span class="spm-panel__badge <?php echo count( $recent_errors ) > 0 ? 'spm-panel__badge--warning' : ''; ?>">
                                    <?php echo esc_html( count( $recent_errors ) ); ?>
                                </span>
                                <?php if ( ! empty( $recent_errors ) ) : ?>
                                    <button class="spm-btn spm-btn--sm spm-btn--white" data-spm-action="wipe-logs" title="<?php esc_attr_e( 'Wipe all errors', 'smart-plugin-monitor' ); ?>" style="padding: 2px 6px !important; min-height: 24px; color: #ef4444 !important;">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="spm-panel__body spm-error-feed">
                            <?php if ( empty( $recent_errors ) ) : ?>
                                <div class="spm-empty-state spm-empty-state--compact">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity="0.4"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    <p><?php esc_html_e( 'No recent errors — everything looks healthy!', 'smart-plugin-monitor' ); ?></p>
                                </div>
                            <?php else : ?>
                                <?php foreach ( $recent_errors as $err ) : ?>
                                    <div class="spm-error-item">
                                        <div class="spm-error-item__head">
                                            <span class="spm-dot spm-dot--danger spm-dot--sm"></span>
                                            <span class="spm-error-item__plugin"><?php echo esc_html( $err['plugin_name'] ); ?></span>
                                            <span class="spm-error-item__level"><?php echo esc_html( $err['error_level'] ?? 'ERROR' ); ?></span>
                                            <time class="spm-error-item__time"><?php echo esc_html( $err['created_at'] ); ?></time>
                                        </div>
                                        <pre class="spm-error-item__msg"><?php echo esc_html( $err['error_message'] ); ?></pre>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
 
                    <!-- Support Development -->
                    <?php include SPM_PLUGIN_DIR . 'templates/support-card.php'; ?>

                </div>
            </div>

            <!-- ── License Verification ── -->
            <?php $this->render_license_panel( $license_report ); ?>

            <!-- ── Footer ── -->
            <footer class="spm-footer">
                <span><?php esc_html_e( 'Logs older than 30 days are automatically purged.', 'smart-plugin-monitor' ); ?></span>
                <span>
                    <?php
                    printf(
                        esc_html__( 'Report generated %s', 'smart-plugin-monitor' ),
                        esc_html( $snap['generated_at'] )
                    );
                    ?>
                </span>
            </footer>

        </div>
        <?php
    }

    /**
     * Render the radial score gauge.
     *
     * Uses a CSS conic-gradient to draw a circular progress ring.
     *
     * @param float $score Average score 0–100.
     */
    private function render_score_gauge( float $score ): void {
        $grade = SPM_Analyzer::score_to_grade( (int) round( $score ) );
        $color_class = strtolower( $grade );
        $pct   = max( 0, min( 100, $score ) );
        ?>
        <div class="spm-gauge spm-gauge--<?php echo esc_attr( $color_class ); ?>" id="spm-gauge">
            <div class="spm-gauge__ring" style="--spm-pct: <?php echo esc_attr( $pct ); ?>;">
                <div class="spm-gauge__inner">
                    <span class="spm-gauge__value"><?php echo esc_html( number_format( $score, 0 ) ); ?></span>
                    <span class="spm-gauge__label"><?php esc_html_e( 'Health Score', 'smart-plugin-monitor' ); ?></span>
                    <span class="spm-gauge__grade spm-grade-label--<?php echo esc_attr( $color_class ); ?>"><?php echo esc_html( $grade ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single KPI card.
     *
     * @param string $label    Card label.
     * @param string $value    Display value.
     * @param string $status   One of success, warning, danger, neutral.
     * @param string $icon_svg Inline SVG icon markup.
     */
    private function render_kpi_card( string $label, string $value, string $status, string $icon_svg ): void {
        ?>
        <div class="spm-kpi spm-kpi--<?php echo esc_attr( $status ); ?>">
            <div class="spm-kpi__icon">
                <?php echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?>
            </div>
            <div class="spm-kpi__body">
                <span class="spm-kpi__value"><?php echo esc_html( $value ); ?></span>
                <span class="spm-kpi__label"><?php echo esc_html( $label ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render the date period filter pills.
     *
     * @param int $active_period Currently active period in days.
     */
    private function render_period_filter( int $active_period ): void {
        $base_url = admin_url( 'admin.php?page=spm-dashboard' );
        ?>
        <div class="spm-period-filter">
            <?php foreach ( SPM_Data_Service::PERIOD_LABELS as $days => $label ) :
                $is_active = ( $days === $active_period );
                $url       = add_query_arg( 'spm_period', $days, $base_url );
            ?>
                <a href="<?php echo esc_url( $url ); ?>"
                   class="spm-period-pill <?php echo $is_active ? 'spm-period-pill--active' : ''; ?>"
                   aria-current="<?php echo $is_active ? 'true' : 'false'; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render the license verification panel.
     *
     * Full-width section showing source classification,
     * confidence scores, and verification status badges.
     *
     * @param array $report License scan report.
     */
    private function render_license_panel( array $report ): void {
        $summary = $report['summary'];
        $plugins = $report['plugins'];
        ?>
        <div class="spm-license-section" id="spm-license-panel">
            <div class="spm-panel">
                <div class="spm-panel__header">
                    <h2 class="spm-panel__title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <?php esc_html_e( 'License & Source Verification', 'smart-plugin-monitor' ); ?>
                    </h2>
                    <div class="spm-license-summary">
                        <span class="spm-license-pill spm-license-pill--verified">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php echo esc_html( $summary['verified'] ); ?> <?php esc_html_e( 'Verified', 'smart-plugin-monitor' ); ?>
                        </span>
                        <span class="spm-license-pill spm-license-pill--unverified">
                            <?php echo esc_html( $summary['unverified'] ); ?> <?php esc_html_e( 'Unverified', 'smart-plugin-monitor' ); ?>
                        </span>
                        <?php if ( $summary['needs_review'] > 0 ) : ?>
                        <span class="spm-license-pill spm-license-pill--review">
                            <?php echo esc_html( $summary['needs_review'] ); ?> <?php esc_html_e( 'Needs Review', 'smart-plugin-monitor' ); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="spm-panel__body spm-license-list">
                    <?php if ( empty( $plugins ) ) : ?>
                        <div class="spm-empty-state spm-empty-state--compact">
                            <p><?php esc_html_e( 'No plugins found.', 'smart-plugin-monitor' ); ?></p>
                        </div>
                    <?php else : ?>
                        <?php foreach ( $plugins as $p ) : ?>
                            <?php
                            $status_class = match( $p['verification'] ) {
                                SPM_License_Detector::STATUS_VERIFIED     => 'success',
                                SPM_License_Detector::STATUS_NEEDS_REVIEW => 'danger',
                                default                                   => 'warning',
                            };
                            ?>
                            <div class="spm-license-card spm-license-card--<?php echo esc_attr( $status_class ); ?>" data-spm-basename="<?php echo esc_attr( $p['basename'] ); ?>">
                                <div class="spm-license-card__left">
                                    <div class="spm-license-card__indicator">
                                        <span class="spm-dot spm-dot--<?php echo esc_attr( $status_class ); ?>"></span>
                                    </div>
                                    <div class="spm-license-card__info">
                                        <div class="spm-license-card__top">
                                            <span class="spm-license-card__name"><?php echo esc_html( $p['name'] ); ?></span>
                                            <?php if ( $p['version'] ) : ?>
                                                <span class="spm-license-card__version">v<?php echo esc_html( $p['version'] ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="spm-license-card__meta">
                                            <?php if ( $p['author'] ) : ?>
                                                <span>by <?php echo esc_html( $p['author'] ); ?></span>
                                            <?php endif; ?>
                                            <span class="spm-license-card__license"><?php echo esc_html( $p['license'] ); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="spm-license-card__right">
                                    <span class="spm-source-badge spm-source-badge--<?php echo esc_attr( $p['source'] ); ?>">
                                        <?php echo esc_html( $p['source_label'] ); ?>
                                    </span>
                                    <div class="spm-confidence" title="<?php printf( esc_attr__( 'Confidence: %d%%', 'smart-plugin-monitor' ), $p['confidence'] ); ?>">
                                        <div class="spm-confidence__bar">
                                            <div class="spm-confidence__fill spm-confidence__fill--<?php echo esc_attr( $status_class ); ?>"
                                                 style="width: <?php echo esc_attr( $p['confidence'] ); ?>%;">
                                            </div>
                                        </div>
                                        <span class="spm-confidence__value"><?php echo esc_html( $p['confidence'] ); ?>%</span>
                                    </div>
                                    <span class="spm-verification-badge spm-verification-badge--<?php echo esc_attr( $status_class ); ?>">
                                        <?php
                                        $badge_labels = [
                                            SPM_License_Detector::STATUS_VERIFIED     => __( 'Verified', 'smart-plugin-monitor' ),
                                            SPM_License_Detector::STATUS_UNVERIFIED   => __( 'Unverified', 'smart-plugin-monitor' ),
                                            SPM_License_Detector::STATUS_NEEDS_REVIEW => __( 'Needs Review', 'smart-plugin-monitor' ),
                                        ];
                                        echo esc_html( $badge_labels[ $p['verification'] ] ?? $p['verification'] );
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <?php if ( ! empty( $p['notes'] ) ) : ?>
                                <div class="spm-license-notes spm-license-notes--<?php echo esc_attr( $status_class ); ?>">
                                    <?php foreach ( $p['notes'] as $note ) : ?>
                                        <span class="spm-license-note">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                                            <?php echo esc_html( $note ); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
