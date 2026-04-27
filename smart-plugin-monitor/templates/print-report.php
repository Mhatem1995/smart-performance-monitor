<?php
/**
 * Printable Diagnostic Report Template.
 *
 * @var array $snap Dashboard snapshot for the last 30 days.
 * @package SmartPluginMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php esc_html_e( 'Plugin Diagnostic Report', 'smart-plugin-monitor' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.5; color: #1e293b; padding: 40px; }
        .report-header { border-bottom: 2px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: baseline; }
        .report-title { font-size: 28px; font-weight: 800; margin: 0; color: #0f172a; }
        .report-meta { color: #64748b; font-size: 14px; }
        
        .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px; }
        .summary-card { padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; }
        .summary-label { font-size: 12px; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .summary-value { font-size: 24px; font-weight: 700; margin-top: 5px; }

        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; padding: 12px 10px; border-bottom: 2px solid #e2e8f0; font-size: 13px; color: #64748b; text-transform: uppercase; }
        td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        
        .grade { padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 12px; }
        .grade-a { background: #dcfce7; color: #166534; }
        .grade-b { background: #f0f9ff; color: #075985; }
        .grade-f { background: #fee2e2; color: #991b1b; }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">

    <div class="no-print" style="background: #3b82f6; color: #fff; padding: 10px 20px; margin: -40px -40px 40px -40px; display: flex; justify-content: space-between; align-items: center;">
        <span><?php esc_html_e( 'Report Preview — Use browser print dialog to save as PDF', 'smart-plugin-monitor' ); ?></span>
        <button onclick="window.close()" style="background: rgba(255,255,255,0.2); border: 1px solid #fff; color: #fff; padding: 5px 15px; border-radius: 4px; cursor: pointer;">
            <?php esc_html_e( 'Close', 'smart-plugin-monitor' ); ?>
        </button>
    </div>

    <header class="report-header">
        <div>
            <h1 class="report-title"><?php esc_html_e( 'Plugin Diagnostic Report', 'smart-plugin-monitor' ); ?></h1>
            <p class="report-meta"><?php echo esc_html( get_bloginfo( 'name' ) ); ?> · <?php echo esc_html( get_site_url() ); ?></p>
        </div>
        <div class="report-meta">
            <?php printf( esc_html__( 'Generated: %s', 'smart-plugin-monitor' ), esc_html( $snap['generated_at'] ) ); ?>
        </div>
    </header>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label"><?php esc_html_e( 'Health Score', 'smart-plugin-monitor' ); ?></div>
            <div class="summary-value"><?php echo esc_html( $snap['summary']['avg_score'] ); ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label"><?php esc_html_e( 'Plugins Tracked', 'smart-plugin-monitor' ); ?></div>
            <div class="summary-value"><?php echo esc_html( $snap['summary']['total_plugins'] ); ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label"><?php esc_html_e( 'Total Errors', 'smart-plugin-monitor' ); ?></div>
            <div class="summary-value"><?php echo esc_html( $snap['summary']['total_errors'] ); ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label"><?php esc_html_e( 'Analysis Window', 'smart-plugin-monitor' ); ?></div>
            <div class="summary-value">30 Days</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th><?php esc_html_e( 'Plugin', 'smart-plugin-monitor' ); ?></th>
                <th><?php esc_html_e( 'Avg Speed', 'smart-plugin-monitor' ); ?></th>
                <th><?php esc_html_e( 'Errors', 'smart-plugin-monitor' ); ?></th>
                <th><?php esc_html_e( 'Score', 'smart-plugin-monitor' ); ?></th>
                <th><?php esc_html_e( 'Grade', 'smart-plugin-monitor' ); ?></th>
                <th><?php esc_html_e( 'Trend', 'smart-plugin-monitor' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $snap['plugins'] as $p ) : ?>
            <tr>
                <td><strong><?php echo esc_html( $p['plugin_name'] ); ?></strong></td>
                <td><?php echo esc_html( $p['avg_ms'] ); ?> ms</td>
                <td><?php echo esc_html( $p['error_count'] ); ?></td>
                <td><?php echo esc_html( $p['score'] ); ?></td>
                <td>
                    <span class="grade grade-<?php echo strtolower( $p['grade'] ); ?>">
                        <?php echo esc_html( $p['grade'] ); ?>
                    </span>
                </td>
                <td><?php echo esc_html( ucfirst( $p['trend'] ?? 'stable' ) ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <footer style="margin-top: 60px; border-top: 1px solid #e2e8f0; padding-top: 20px; font-size: 12px; color: #94a3b8; text-align: center;">
        <p><?php esc_html_e( 'Confidential Diagnostic Report · Generated by Smart Plugin Monitor', 'smart-plugin-monitor' ); ?></p>
    </footer>

</body>
</html>
