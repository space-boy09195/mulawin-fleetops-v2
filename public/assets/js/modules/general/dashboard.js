/* assets/js/modules/general/dashboard.js — role dashboards (FMS PDF III).
   The backend (Modules/General/Analytics.php) decides what each role sees. */
'use strict';
App.page('dashboard', {
  title: 'Dashboard',
  render(ctx) {
    const d = ctx.data;
    ctx.el.innerHTML = `<div class="mb-3"><h4 class="mb-0">${UI.esc(d.title || 'Dashboard')}</h4><div class="text-muted small">${UI.esc(d.subtitle || '')}</div></div>` + UI.blocks(d, { maxCharts: 2, key: 'dash' }); // alerts -> cards -> 2 charts (iba ay Show more) -> tables
    ctx.tools.innerHTML = '<button class="btn btn-sm btn-outline-secondary" id="dashRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>';
    document.getElementById('dashRefresh').onclick = () => ctx.reload();
    UI.activate(ctx.el);
  },
});
