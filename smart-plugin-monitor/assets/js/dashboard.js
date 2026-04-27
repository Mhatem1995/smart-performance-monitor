/**
 * Smart Plugin Monitor — Interactive Dashboard
 *
 * Powers: slide-in drawer, 4 tabbed detail views (Performance, Security,
 * License, Actions), AJAX via WP REST API, toast notifications.
 *
 * @package SmartPluginMonitor
 */
(function () {
    'use strict';

    /* ── Config from wp_localize_script ── */
    const CFG = window.spmConfig || {};
    const API = CFG.restUrl || '/wp-json/spm/v1';
    const NONCE = CFG.nonce || '';

    /* ── DOM refs (set in init) ── */
    let drawer, overlay, drawerBody, drawerTitle, drawerLoading;
    let currentBasename = '';

    /* ══════════════════════════════════════════
       Initialization
       ══════════════════════════════════════════ */
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const dashboard = document.getElementById('spm-dashboard');
        const details   = document.getElementById('spm-details-root');

        if (!dashboard && !details) return;

        if (details) {
            const params = new URLSearchParams(window.location.search);
            currentBasename = params.get('plugin') || '';
            bindDetailsTabs();
        }

        if (dashboard) {
            injectDrawer(dashboard);
            bindClickableCards();
        }

        bindGlobalActions();
    }

    /* ══════════════════════════════════════════
       Drawer Injection
       ══════════════════════════════════════════ */
    function injectDrawer(root) {
        root.insertAdjacentHTML('beforeend', `
        <div class="spm-overlay" id="spm-overlay"></div>
        <aside class="spm-drawer" id="spm-drawer" aria-hidden="true">
            <header class="spm-drawer__header">
                <h2 class="spm-drawer__title" id="spm-drawer-title">Plugin Detail</h2>
                <button class="spm-drawer__close" id="spm-drawer-close" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            </header>
            <div class="spm-drawer__loading" id="spm-drawer-loading">
                <div class="spm-spinner"></div>
                <span>Loading plugin details…</span>
            </div>
            <div class="spm-drawer__body" id="spm-drawer-body"></div>
        </aside>`);

        drawer        = document.getElementById('spm-drawer');
        overlay       = document.getElementById('spm-overlay');
        drawerBody    = document.getElementById('spm-drawer-body');
        drawerTitle   = document.getElementById('spm-drawer-title');
        drawerLoading = document.getElementById('spm-drawer-loading');

        document.getElementById('spm-drawer-close').addEventListener('click', closeDrawer);
        overlay.addEventListener('click', closeDrawer);
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeDrawer();
        });
    }

    /* ══════════════════════════════════════════
       Click Bindings — all cards with data-spm-basename
       ══════════════════════════════════════════ */
    function bindClickableCards() {
        document.querySelectorAll('[data-spm-basename]').forEach(card => {
            card.style.cursor = 'pointer';
            card.setAttribute('role', 'button');
            card.setAttribute('tabindex', '0');

            card.addEventListener('click', () => {
                window.location.href = `admin.php?page=plugin-monitor-details&plugin=${encodeURIComponent(card.dataset.spmBasename)}`;
            });

            // Keyboard support.
            card.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    window.location.href = `admin.php?page=plugin-monitor-details&plugin=${encodeURIComponent(card.dataset.spmBasename)}`;
                }
            });
        });
    }

    function getCardLabel(card) {
        const el = card.querySelector('.spm-plugin-card__name, .spm-license-card__name');
        return el ? el.textContent.trim() : card.dataset.spmBasename;
    }

    /* ══════════════════════════════════════════
       Global Action Buttons (header)
       ══════════════════════════════════════════ */
    function bindGlobalActions() {
        document.querySelectorAll('[data-spm-action]').forEach(btn => {
            btn.addEventListener('click', async e => {
                e.preventDefault();
                btn.disabled = true;
                btn.classList.add('spm-btn--loading');
                try {
                    const action = btn.dataset.spmAction;
                    const bname  = btn.dataset.spmBasename || currentBasename;

                    if (action === 'scan')          await apiScan();
                    if (action === 'deep-scan')      await apiDeepScan();
                    if (action === 'security-scan')  await apiSecurityScan(bname);
                    if (action === 'export' || action === 'export-json') await apiExport();
                    if (action === 'export-csv')     await apiExportCSV();
                    if (action === 'export-pdf')     await apiExportPDF();
                    if (action === 'disable')        await apiDisable(bname);
                    if (action === 'enable')         await apiEnable(bname);
                    if (action === 'isolate')        await apiIsolate(bname);
                    if (action === 'restore')        await apiRestore();
                    if (action === 'wipe-logs')      await apiWipeLogs(bname);
                } finally {
                    btn.disabled = false;
                    btn.classList.remove('spm-btn--loading');
                }
            });
        });
    }

    /* ══════════════════════════════════════════
       Drawer Open / Close
       ══════════════════════════════════════════ */
    function openDrawer(basename, displayName) {
        currentBasename = basename;
        drawerTitle.textContent = displayName || basename;
        drawerBody.innerHTML = '';
        drawerLoading.style.display = 'flex';
        drawer.classList.add('spm-drawer--open');
        overlay.classList.add('spm-overlay--open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        fetchPluginDetail(basename);
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove('spm-drawer--open');
        overlay.classList.remove('spm-overlay--open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        currentBasename = '';
    }

    /* ══════════════════════════════════════════
       REST API Helpers
       ══════════════════════════════════════════ */
    function headers() {
        return { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE };
    }

    async function apiFetch(path, opts = {}) {
        const res = await fetch(`${API}${path}`, { headers: headers(), ...opts });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    /* ── Specific API calls ── */
    async function fetchPluginDetail(basename) {
        try {
            const data = await apiFetch(`/plugin/${encodeURIComponent(basename)}`);
            renderDetail(data);
        } catch (err) {
            drawerLoading.style.display = 'none';
            drawerBody.innerHTML = `
            <div class="spm-drawer-error">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" opacity="0.4">
                    <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <p>Failed to load plugin details.</p>
                <code>${esc(err.message)}</code>
            </div>`;
        }
    }

    async function apiScan() {
        const d = await apiFetch('/scan', { method: 'POST' });
        toast(d.success ? 'Performance scan complete!' : 'Scan failed.', d.success ? 'success' : 'danger');
    }

    async function apiDeepScan() {
        const d = await apiFetch('/deep-scan', { method: 'POST' });
        toast(d.success ? 'License scan complete!' : 'License scan failed.', d.success ? 'success' : 'danger');
        if (currentBasename) fetchPluginDetail(currentBasename);
    }

    async function apiSecurityScan(basename) {
        const b = basename || currentBasename;
        if (!b) return;
        toast('Running deep security scan…', 'neutral');
        const body = JSON.stringify({ basename: b });
        const d = await apiFetch(`/security-scan`, { method: 'POST', body });
        toast(d.success ? 'Security scan complete!' : 'Security scan failed.', d.success ? 'success' : 'danger');
        if (detailsPage()) setTimeout(() => window.location.reload(), 1000);
    }

    async function apiDisable(basename) {
        const b = basename || currentBasename;
        if (!b) return;
        
        const label = b.split('/')[0];
        if (!confirm(`Are you sure you want to DEACTIVATE "${label}"? This may affect your site functionality.`)) return;
        
        toast('Deactivating plugin...', 'neutral');
        const body = JSON.stringify({ basename: b });
        const d = await apiFetch(`/disable`, { method: 'POST', body });
        toast(d.message, d.success ? 'success' : 'danger');
        
        if (d.success) {
            setTimeout(() => window.location.reload(), 1000);
        }
    }

    async function apiEnable(basename) {
        const b = basename || currentBasename;
        if (!b) return;
        
        const label = b.split('/')[0];
        if (!confirm(`Are you sure you want to ACTIVATE "${label}"?`)) return;
        
        toast('Activating plugin...', 'neutral');
        const body = JSON.stringify({ basename: b });
        const d = await apiFetch(`/enable`, { method: 'POST', body });
        toast(d.message, d.success ? 'success' : 'danger');
        
        if (d.success) {
            setTimeout(() => window.location.reload(), 1000);
        }
    }

    async function apiIsolate(basename) {
        const b = basename || currentBasename;
        if (!b) return;

        const label = b.split('/')[0];
        if (!confirm(`Enter ISOLATION MODE for "${label}"?\n\nThis will temporarily disable the plugin so you can measure site performance without it. You can restore it with one click.`)) return;

        toast('Entering Isolation Mode...', 'warning');
        const body = JSON.stringify({ basename: b });
        const d = await apiFetch(`/isolate`, { method: 'POST', body });
        toast(d.message, d.success ? 'success' : 'danger');
        
        if (d.success) {
            setTimeout(() => window.location.reload(), 1000);
        }
    }

    async function apiRestore() {
        toast('Restoring site state...', 'neutral');
        const d = await apiFetch('/restore', { method: 'POST' });
        toast(d.message, d.success ? 'success' : 'danger');
        
        if (d.success) {
            setTimeout(() => window.location.reload(), 1000);
        }
    }

    async function apiWipeLogs(basename) {
        if (!confirm('Are you sure you want to permanently delete these error logs?')) return;
        toast('Wiping logs...', 'neutral');
        const b = detailsPage() ? (basename || currentBasename) : '';
        const body = b ? JSON.stringify({ basename: b }) : '{}';
        const d = await apiFetch(`/wipe-logs`, { method: 'POST', body });
        toast(d.message, d.success ? 'success' : 'danger');
        if (d.success) {
            setTimeout(() => window.location.reload(), 1000);
        }
    }

    function detailsPage() {
        return !!document.getElementById('spm-details-root');
    }

    /* ══════════════════════════════════════════
       Details Page Tabs
       ══════════════════════════════════════════ */
    function bindDetailsTabs() {
        const tabs = document.querySelectorAll('.spm-details-tab');
        const panels = document.querySelectorAll('.spm-tab-content');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tabId;

                tabs.forEach(t => t.classList.toggle('spm-details-tab--active', t === tab));
                panels.forEach(p => p.classList.toggle('spm-tab-content--active', p.id === `spm-tab-${target}`));
            });
        });
    }

    async function apiExport() {
        const data = await apiFetch('/export');
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `spm-report-${new Date().toISOString().slice(0, 10)}.json`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        toast('JSON report downloaded!', 'success');
    }

    async function apiExportCSV() {
        window.location.href = `${API}/export/csv?_wpnonce=${NONCE}`;
        toast('CSV report downloading...', 'success');
    }

    async function apiExportPDF() {
        window.open(`${API}/export/pdf?_wpnonce=${NONCE}`, '_blank');
        toast('Generating printable report...', 'success');
    }

    /* ══════════════════════════════════════════
       Detail Renderer
       ══════════════════════════════════════════ */
    function renderDetail(data) {
        drawerLoading.style.display = 'none';

        const m = data.meta || {};
        const p = data.performance || {};
        const l = data.license || null;
        const sec = data.security || null;
        const logs = data.error_logs || [];

        drawerTitle.textContent = m.name || m.basename;
        currentBasename = m.basename;

        const active = m.is_active;
        const statusHtml = active
            ? '<span class="spm-dot spm-dot--success"></span> Active'
            : '<span class="spm-dot spm-dot--danger"></span> Inactive';

        const gradeClass = (p.grade || 'f').toLowerCase();

        drawerBody.innerHTML = `
        <!-- ── Tabs ── -->
        <div class="spm-tabs">
            <button class="spm-tab spm-tab--active" data-tab="performance">Performance</button>
            <button class="spm-tab" data-tab="security">Security</button>
            <button class="spm-tab" data-tab="license">License</button>
            <button class="spm-tab" data-tab="actions">Actions</button>
        </div>

        <!-- ═══ Performance Tab ═══ -->
        <div class="spm-tab-panel spm-tab-panel--active" data-panel="performance">
            <div class="spm-detail-grid">
                ${detailCard('Status', statusHtml)}
                ${detailCard('Version', esc(m.version || 'N/A'))}
                ${detailCard('Author', esc(m.author || 'Unknown'))}
                ${detailCard('Grade', `<span class="spm-grade-label spm-grade-label--${gradeClass}">${esc(p.grade || 'N/A')}</span>`)}
            </div>

            <div class="spm-detail-section">
                <h3 class="spm-detail-section__title">Performance Score</h3>
                <div class="spm-drawer-score-bar">
                    <div class="spm-drawer-score-bar__fill spm-drawer-score-bar__fill--${gradeClass}"
                         style="width:${Math.max(0, Math.min(100, p.score || 0))}%"></div>
                </div>
                <div class="spm-drawer-score-label">
                    <span>${p.score || 0} / 100</span>
                    <span>${p.is_slow ? '⚠ Slow Plugin' : '✓ Within threshold'}</span>
                </div>
            </div>

            <div class="spm-detail-section">
                <h3 class="spm-detail-section__title">Load Time</h3>
                <div class="spm-detail-grid spm-detail-grid--3">
                    ${detailCard('Average', `${p.avg_ms || 0} ms`, true)}
                    ${detailCard('Min', `${p.min_ms || 0} ms`, true)}
                    ${detailCard('Max', `${p.max_ms || 0} ms`, true)}
                </div>
            </div>

            <div class="spm-detail-section">
                <h3 class="spm-detail-section__title">Plugin Path</h3>
                <code class="spm-detail-path">${esc(m.full_path || '')}</code>
            </div>

            <div class="spm-detail-section">
                <h3 class="spm-detail-section__title">Error Log (${logs.length})</h3>
                ${logs.length === 0
                    ? '<p class="spm-detail-empty">No errors recorded — looking good!</p>'
                    : `<div class="spm-detail-errors">${logs.map(renderErrorRow).join('')}</div>`}
            </div>
        </div>

        <!-- ═══ Security Tab ═══ -->
        <div class="spm-tab-panel" data-panel="security">
            ${renderSecurityTab(sec, l)}
        </div>

        <!-- ═══ License Tab ═══ -->
        <div class="spm-tab-panel" data-panel="license">
            ${renderLicenseTab(l, m)}
        </div>

        <!-- ═══ Actions Tab ═══ -->
        <div class="spm-tab-panel" data-panel="actions">
            ${renderActionsTab(m, active)}
        </div>`;

        bindTabs();
        bindDrawerActions();
    }

    /* ── Sub-renderers ── */

    function detailCard(label, value, compact) {
        return `<div class="spm-detail-card${compact ? ' spm-detail-card--compact' : ''}">
            <span class="spm-detail-card__label">${label}</span>
            <span class="spm-detail-card__value">${value}</span>
        </div>`;
    }

    function renderErrorRow(e) {
        return `<div class="spm-detail-error">
            <div class="spm-detail-error__head">
                <span class="spm-dot spm-dot--danger spm-dot--sm"></span>
                <span class="spm-detail-error__level">${esc(e.error_level || 'ERROR')}</span>
                <time>${esc(e.created_at || '')}</time>
            </div>
            <pre class="spm-detail-error__msg">${esc(e.error_message || '')}</pre>
        </div>`;
    }

    function renderSecurityTab(sec, l) {
        // Start with deep scan data if available.
        let html = '';

        if (sec && sec.score !== undefined) {
            const s = sec;
            const st = s.stats || {};
            const riskClass = s.risk_level === 'clean' ? 'success'
                : s.risk_level === 'low' ? 'success'
                : s.risk_level === 'medium' ? 'warning' : 'danger';
            const riskLabel = { clean: 'Clean', low: 'Low Risk', medium: 'Medium Risk', high: 'High Risk' };

            html += `
            <div class="spm-detail-section">
                <h3 class="spm-detail-section__title">Deep Security Scan</h3>
                <div class="spm-detail-grid">
                    ${detailCard('Security Score', `<span class="spm-text--${riskClass}" style="font-size:22px;font-weight:700">${s.score}</span><small>/100</small>`)}
                    ${detailCard('Risk Level', `<span class="spm-severity-badge spm-severity-badge--${riskClass}">${esc(riskLabel[s.risk_level] || s.risk_level)}</span>`)}
                    ${detailCard('Files Scanned', String(st.files_scanned || 0))}
                    ${detailCard('Total Lines', String(st.total_lines || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ','))}
                </div>
                <div class="spm-drawer-score-bar" style="margin-top:12px">
                    <div class="spm-drawer-score-bar__fill spm-drawer-score-bar__fill--${riskClass}"
                         style="width:${s.score}%"></div>
                </div>
                <div class="spm-drawer-score-label">
                    <span>${s.score} / 100</span>
                    <span>Scanned ${esc(s.scanned_at || '')}</span>
                </div>
            </div>

            <div class="spm-detail-section">
                <h3 class="spm-detail-section__title">Detection Summary</h3>
                <div class="spm-detail-grid spm-detail-grid--4">
                    ${detailCard('Critical', `<span class="spm-text--danger">${st.critical_count || 0}</span>`, true)}
                    ${detailCard('High', `<span class="spm-text--danger">${st.high_count || 0}</span>`, true)}
                    ${detailCard('Medium', `<span class="spm-text--warning">${st.medium_count || 0}</span>`, true)}
                    ${detailCard('Low', `<span class="spm-text--neutral">${st.low_count || 0}</span>`, true)}
                </div>
            </div>`;

            // Findings table.
            const findings = s.findings || [];
            if (findings.length > 0) {
                html += `
                <div class="spm-detail-section">
                    <h3 class="spm-detail-section__title">Findings (${findings.length})</h3>
                    <div class="spm-findings-list">
                        ${findings.map(f => `
                        <div class="spm-finding spm-finding--${f.severity}">
                            <div class="spm-finding__header">
                                <span class="spm-severity-badge spm-severity-badge--${sevClass(f.severity)}">${esc(f.severity)}</span>
                                <span class="spm-finding__label">${esc(f.label)}</span>
                                <span class="spm-finding__type">${esc(f.type)}</span>
                            </div>
                            <p class="spm-finding__desc">${esc(f.description)}</p>
                            <div class="spm-finding__location">
                                <code>${esc(f.file)}:${f.line}</code>
                            </div>
                            ${f.context ? `<pre class="spm-finding__context">${esc(f.context)}</pre>` : ''}
                        </div>`).join('')}
                    </div>
                </div>`;
            } else {
                html += `
                <div class="spm-detail-section">
                    <p class="spm-detail-empty">No suspicious patterns found — this plugin looks clean!</p>
                </div>`;
            }

            html += `
            <div class="spm-detail-section" style="text-align:center;padding-top:8px">
                <button class="spm-btn spm-btn--outline" data-drawer-action="security-scan">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    Re-run Security Scan
                </button>
            </div>`;

        } else {
            html += `
            <div class="spm-detail-empty" style="text-align:center;padding:32px 0">
                <p>No deep scan data yet.</p>
                <button class="spm-btn spm-btn--outline" data-drawer-action="security-scan" style="margin-top:12px">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    Run Security Scan
                </button>
            </div>`;
        }

        // Also show license-based security info if available.
        if (l) {
            const flags = l.flags || [];
            const notes = l.notes || [];
            const sus = l.suspicious_count || 0;
            const conf = l.confidence || 0;
            const confClass = conf >= 80 ? 'success' : conf >= 50 ? 'warning' : 'danger';

            html += `
            <div class="spm-detail-section" style="margin-top:20px;padding-top:20px;border-top:1px solid #e5e7eb">
                <h3 class="spm-detail-section__title">License-Based Analysis</h3>
                <div class="spm-detail-grid spm-detail-grid--3">
                    ${detailCard('Pattern Flags', `<span class="${sus > 0 ? 'spm-text--danger' : 'spm-text--success'}">${sus}</span>`, true)}
                    ${detailCard('Confidence', `<span class="spm-text--${confClass}">${conf}%</span>`, true)}
                    ${detailCard('Verification', esc(l.verification || 'unknown'), true)}
                </div>
            </div>`;

            if (flags.length > 0) {
                html += `
                <div class="spm-detail-section">
                    <h3 class="spm-detail-section__title">Flags</h3>
                    <div class="spm-flag-list">${flags.map(f => `<span class="spm-flag">${esc(f)}</span>`).join('')}</div>
                </div>`;
            }

            if (notes.length > 0) {
                html += `
                <div class="spm-detail-section">
                    <h3 class="spm-detail-section__title">Analysis Notes</h3>
                    <ul class="spm-note-list">${notes.map(n => `<li>${esc(n)}</li>`).join('')}</ul>
                </div>`;
            }
        }

        return html;
    }

    function sevClass(sev) {
        if (sev === 'critical' || sev === 'high') return 'danger';
        if (sev === 'medium') return 'warning';
        return 'neutral';
    }

    function renderLicenseTab(l, m) {
        if (!l) return '<p class="spm-detail-empty">No license data available yet.</p>';

        return `
        <div class="spm-detail-grid">
            ${detailCard('Source', `<span class="spm-source-badge spm-source-badge--${l.source}">${esc(l.source_label)}</span>`)}
            ${detailCard('License', esc(l.license || 'Not specified'))}
        </div>
        <div class="spm-detail-section">
            <h3 class="spm-detail-section__title">Plugin Metadata</h3>
            <table class="spm-detail-table">
                <tr><td>Basename</td><td><code>${esc(l.basename)}</code></td></tr>
                <tr><td>Author</td><td>${esc(l.author || 'N/A')}</td></tr>
                <tr><td>Plugin URI</td><td>${m.plugin_uri
                    ? `<a href="${esc(m.plugin_uri)}" target="_blank" rel="noopener">${esc(m.plugin_uri)}</a>`
                    : 'N/A'}</td></tr>
                <tr><td>Description</td><td>${esc(m.description || 'N/A')}</td></tr>
                <tr><td>Text Domain</td><td>${esc(m.text_domain || 'N/A')}</td></tr>
                <tr><td>Requires WP</td><td>${esc(m.requires_wp || 'Not specified')}</td></tr>
                <tr><td>Requires PHP</td><td>${esc(m.requires_php || 'Not specified')}</td></tr>
            </table>
        </div>`;
    }

    function renderActionsTab(m, active) {
        return `
        <div class="spm-actions-grid">
            <div class="spm-action-card" data-drawer-action="scan">
                <div class="spm-action-card__icon spm-action-card__icon--blue">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 1 1-9-9"/><polyline points="21 3 21 9 15 9"/>
                    </svg>
                </div>
                <div class="spm-action-card__body">
                    <span class="spm-action-card__title">Scan Now</span>
                    <span class="spm-action-card__desc">Re-run performance analysis on all plugins</span>
                </div>
            </div>

            <div class="spm-action-card" data-drawer-action="deep-scan">
                <div class="spm-action-card__icon spm-action-card__icon--purple">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                </div>
                <div class="spm-action-card__body">
                    <span class="spm-action-card__title">License Scan</span>
                    <span class="spm-action-card__desc">Force-refresh license verification</span>
                </div>
            </div>

            <div class="spm-action-card" data-drawer-action="security-scan">
                <div class="spm-action-card__icon spm-action-card__icon--orange">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <path d="M9 12l2 2 4-4"/>
                    </svg>
                </div>
                <div class="spm-action-card__body">
                    <span class="spm-action-card__title">Security Scan</span>
                    <span class="spm-action-card__desc">Deep-scan plugin files for dangerous code patterns</span>
                </div>
            </div>
            </div>

            <div class="spm-action-card" data-drawer-action="export">
                <div class="spm-action-card__icon spm-action-card__icon--teal">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                </div>
                <div class="spm-action-card__body">
                    <span class="spm-action-card__title">Export Report</span>
                    <span class="spm-action-card__desc">Download full diagnostic report as JSON</span>
                </div>
            </div>

            ${active ? `
            <div class="spm-action-card spm-action-card--danger" data-drawer-action="disable">
                <div class="spm-action-card__icon spm-action-card__icon--red">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                    </svg>
                </div>
                <div class="spm-action-card__body">
                    <span class="spm-action-card__title">Disable Plugin</span>
                    <span class="spm-action-card__desc">Deactivate this plugin immediately</span>
                </div>
            </div>` : `
            <div class="spm-action-card" data-drawer-action="enable" style="border-color: #10b981;">
                <div class="spm-action-card__icon" style="background: #ecfdf5; color: #10b981;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <div class="spm-action-card__body">
                    <span class="spm-action-card__title" style="color: #10b981;">Enable Plugin</span>
                    <span class="spm-action-card__desc">Re-activate this plugin immediately</span>
                </div>
            </div>`}
        </div>`;
    }

    /* ══════════════════════════════════════════
       Tab Switching
       ══════════════════════════════════════════ */
    function bindTabs() {
        drawerBody.querySelectorAll('.spm-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const name = tab.dataset.tab;
                drawerBody.querySelectorAll('.spm-tab').forEach(t =>
                    t.classList.toggle('spm-tab--active', t.dataset.tab === name));
                drawerBody.querySelectorAll('.spm-tab-panel').forEach(p =>
                    p.classList.toggle('spm-tab-panel--active', p.dataset.panel === name));
            });
        });
    }

    /* ══════════════════════════════════════════
       Drawer Action Dispatch
       ══════════════════════════════════════════ */
    function bindDrawerActions() {
        drawerBody.querySelectorAll('[data-drawer-action]').forEach(el => {
            el.style.cursor = 'pointer';
            el.addEventListener('click', async () => {
                const action = el.dataset.drawerAction;
                el.classList.add('spm-action-card--loading');
                try {
                    if (action === 'scan')           await apiScan();
                    if (action === 'deep-scan')       await apiDeepScan();
                    if (action === 'security-scan')   await apiSecurityScan();
                    if (action === 'export')          await apiExport();
                    if (action === 'disable')         await apiDisable();
                    if (action === 'enable')          await apiEnable();
                    if (action === 'isolate')         await apiIsolate();
                    if (action === 'restore')         await apiRestore();
                } finally {
                    el.classList.remove('spm-action-card--loading');
                }
            });
        });
    }

    /* ══════════════════════════════════════════
       Toast Notification
       ══════════════════════════════════════════ */
    function toast(msg, type) {
        const prev = document.querySelector('.spm-toast');
        if (prev) prev.remove();

        const el = document.createElement('div');
        el.className = `spm-toast spm-toast--${type || 'neutral'}`;
        el.textContent = msg;
        document.body.appendChild(el);

        requestAnimationFrame(() => el.classList.add('spm-toast--show'));
        setTimeout(() => {
            el.classList.remove('spm-toast--show');
            setTimeout(() => el.remove(), 300);
        }, 3000);
    }

    /* ══════════════════════════════════════════
       Utility
       ══════════════════════════════════════════ */
    function esc(str) {
        if (str == null) return '';
        const el = document.createElement('span');
        el.textContent = String(str);
        return el.innerHTML;
    }
})();
