<?php
/**
 * Security & Trust Tab Template for Plugin Details.
 *
 * @var array $meta     Plugin metadata.
 * @var array $security Security report from SPM_Security_Scanner.
 * @var array $trust    Trust report from SPM_License_Detector.
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Map Trust Status to Badges
$trust_map = [
    'verified'     => [ 'label' => __( 'Verified', 'smart-plugin-monitor' ),   'class' => 'success' ],
    'unverified'   => [ 'label' => __( 'Unknown', 'smart-plugin-monitor' ),    'class' => 'warning' ],
    'needs-review' => [ 'label' => __( 'Suspicious', 'smart-plugin-monitor' ), 'class' => 'danger' ],
];

$trust_status = $trust_map[ $trust['verification'] ] ?? $trust_map['unverified'];

// Unique affected files
$affected_files = [];
foreach ( $security['findings'] as $f ) {
    $affected_files[ $f['file'] ] = true;
}
$affected_count = count( $affected_files );
?>

<div class="spm-tab-content" id="spm-tab-security">
    
    <!-- ── Trust Overview ── -->
    <div class="spm-details-stats-grid">
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Trust Classification', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value">
                 <span class="spm-trust-badge spm-trust-badge--large spm-trust-badge--<?php echo esc_attr( $trust_status['class'] ?? '' ); ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?php echo esc_html( $trust_status['label'] ?? '' ); ?>
                </span>
            </span>
            <div class="spm-detail-card__footer">
                <span><?php printf( esc_html__( 'Confidence Score: %d%%', 'smart-plugin-monitor' ), $trust['confidence'] ); ?></span>
            </div>
        </div>
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Security Risk Score', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value">
                <span class="spm-text--<?php echo esc_attr( $security['risk_level'] === 'clean' ? 'success' : ($security['risk_level'] === 'medium' ? 'warning' : 'danger') ); ?>">
                    <?php echo (int) $security['score']; ?>
                </span><small>/100</small>
            </span>
            <div class="spm-detail-card__footer">
                <span><?php printf( esc_html__( 'Risk Level: %s', 'smart-plugin-monitor' ), ucfirst($security['risk_level']) ); ?></span>
            </div>
        </div>
        <div class="spm-detail-card">
            <span class="spm-detail-card__label"><?php esc_html_e( 'Suspicious Calls', 'smart-plugin-monitor' ); ?></span>
            <span class="spm-detail-card__value <?php echo $security['findings_count'] > 0 ? 'spm-text--danger' : ''; ?>">
                <?php echo (int) $security['findings_count']; ?>
            </span>
            <div class="spm-detail-card__footer">
                <span><?php printf( esc_html__( 'Across %d affected files', 'smart-plugin-monitor' ), $affected_count ); ?></span>
            </div>
        </div>
    </div>

    <!-- ── Verification Grid ── -->
    <div class="spm-panel">
        <div class="spm-panel__header">
            <h2 class="spm-panel__title"><?php esc_html_e( 'Identity & Source Verification', 'smart-plugin-monitor' ); ?></h2>
        </div>
        <div class="spm-panel__body">
            <div class="spm-verification-grid">
                <div class="spm-v-item">
                    <div class="spm-v-icon spm-v-icon--<?php echo $trust['source'] === 'wordpress.org' ? 'success' : 'neutral'; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </div>
                    <div class="spm-v-content">
                        <span class="spm-v-label"><?php esc_html_e( 'Source Identification', 'smart-plugin-monitor' ); ?></span>
                        <span class="spm-v-value"><?php echo esc_html( $trust['source_label'] ?? '' ); ?></span>
                    </div>
                </div>
                <div class="spm-v-item">
                    <div class="spm-v-icon spm-v-icon--<?php echo $trust['verification'] === 'verified' ? 'success' : 'warning'; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                    </div>
                    <div class="spm-v-content">
                        <span class="spm-v-label"><?php esc_html_e( 'Author Status', 'smart-plugin-monitor' ); ?></span>
                        <span class="spm-v-value"><?php echo $trust['verification'] === 'verified' ? esc_html__( 'Verified Author', 'smart-plugin-monitor' ) : esc_html__( 'Unverified Author', 'smart-plugin-monitor' ); ?></span>
                    </div>
                </div>
                <div class="spm-v-item">
                    <div class="spm-v-icon spm-v-icon--<?php echo $trust['license'] !== 'Not specified' ? 'success' : 'neutral'; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div class="spm-v-content">
                        <span class="spm-v-label"><?php esc_html_e( 'License Declaration', 'smart-plugin-monitor' ); ?></span>
                        <span class="spm-v-value"><?php echo esc_html( $trust['license'] ?? '' ); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Detailed Security Findings ── -->
    <div class="spm-panel">
        <div class="spm-panel__header">
            <h2 class="spm-panel__title"><?php esc_html_e( 'Suspicious Code Analysis', 'smart-plugin-monitor' ); ?></h2>
            <span class="spm-panel__badge"><?php echo (int) $security['findings_count']; ?></span>
        </div>
        <div class="spm-panel__body">
            <?php if ( ! empty( $security['findings'] ) ) : ?>
                <div class="spm-findings-list">
                    <?php foreach ( $security['findings'] as $f ) : ?>
                        <div class="spm-finding spm-finding--<?php echo esc_attr( $f['severity'] ?? '' ); ?>">
                            <div class="spm-finding__header">
                                <span class="spm-severity-badge spm-severity-badge--<?php echo $f['severity'] === 'critical' || $f['severity'] === 'high' ? 'danger' : ($f['severity'] === 'medium' ? 'warning' : 'neutral'); ?>">
                                    <?php echo esc_html( $f['severity'] ?? '' ); ?>
                                </span>
                                <span class="spm-finding__label"><?php echo esc_html( $f['label'] ?? '' ); ?></span>
                            </div>
                            <p class="spm-finding__desc"><?php echo esc_html( $f['description'] ?? '' ); ?></p>
                            <code class="spm-finding__location"><?php echo esc_html( $f['file'] ?? '' ); ?>:<?php echo (int) ( $f['line'] ?? 0 ); ?></code>
                            <?php if ( ! empty( $f['context'] ) ) : ?>
                                <pre class="spm-finding__context"><?php echo esc_html( $f['context'] ?? '' ); ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="spm-clean-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity="0.4"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                    <p><?php esc_html_e( 'Scan complete. No suspicious functions (eval, exec, etc.) or dangerous patterns were detected in the plugin codebase.', 'smart-plugin-monitor' ); ?></p>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 24px; text-align: center; border-top: 1px solid #f1f5f9; padding-top: 20px;">
                 <button class="spm-btn spm-btn--outline" data-spm-basename="<?php echo esc_attr( $meta['basename'] ?? '' ); ?>" data-spm-action="security-scan">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
                    <?php esc_html_e( 'Perform Deep Security Scan', 'smart-plugin-monitor' ); ?>
                </button>
            </div>
        </div>
    </div>

</div>
