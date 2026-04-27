<?php
/**
 * Support Development Card Template.
 *
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$qr_url = SPM_PLUGIN_URL . 'assets/instapay-qr.jpg';
?>

<div class="spm-support-card">
    <div class="spm-support-card__header">
        <h3 class="spm-support-card__title"><?php esc_html_e( 'Support Smart Plugin Monitor', 'smart-plugin-monitor' ); ?></h3>
        <p class="spm-support-card__subtitle">
            <?php esc_html_e( 'Support ongoing development, security improvements, and future premium features.', 'smart-plugin-monitor' ); ?>
        </p>
    </div>

    <div class="spm-support-card__qr">
        <img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'Scan InstaPay QR', 'smart-plugin-monitor' ); ?>">
        <span><?php esc_html_e( 'Scan InstaPay QR', 'smart-plugin-monitor' ); ?></span>
    </div>

    <div class="spm-support-card__actions">
        <a href="https://ko-fi.com/marwanhatem31477" target="_blank" rel="noopener noreferrer" class="spm-btn spm-btn--kofi">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
            <?php esc_html_e( 'Support via Ko-fi', 'smart-plugin-monitor' ); ?>
        </a>
        <a href="https://ipn.eg/S/marwanhatem07/instapay/7wzFGM" target="_blank" rel="noopener noreferrer" class="spm-btn spm-btn--instapay">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20"/></svg>
            <?php esc_html_e( 'Support via InstaPay', 'smart-plugin-monitor' ); ?>
        </a>
    </div>

    <footer class="spm-support-card__footer">
        <?php esc_html_e( 'Every contribution helps improve Smart Plugin Monitor faster ❤️', 'smart-plugin-monitor' ); ?>
    </footer>
</div>
