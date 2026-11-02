/* assets/js/modules/general/dashboard.js — role dashboards (FMS PDF III).
   The backend (Modules/General/Analytics.php) decides what each role sees. */
'use strict';
App.page('dashboard', {
  title: 'Dashboard',
  render(ctx) {
    const d = ctx.data;
    ctx.el.innerHTML = `<div class="mb-3"><h4 class="mb-0">${UI.esc(d.title || 'Dashboard')}</h4><div class="text-muted small">${UI.esc(d.subtitle || '')}<span class="ms-2" id="dashUpdated">· Updated ${new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}</span></div></div>` + UI.blocks(d, { maxCharts: 2, key: 'dash' }); // alerts -> cards -> 2 charts (iba ay Show more) -> tables
    ctx.tools.innerHTML = '<button class="btn btn-sm btn-outline-secondary" id="dashRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>';
    ctx.tools.querySelector('#dashRefresh').onclick = () => ctx.reload();
    // Admin na wala pang recovery code: paalala (walang email ang system, ito ang paraan kapag nakalimutan ang password).
    if (App.me.user.role === 'admin' && App.me.user.recovery_ready === false) {
      ctx.el.insertAdjacentHTML('afterbegin', `<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2" id="recAlert">
        <span><i class="bi bi-shield-exclamation me-1"></i>You have no recovery code yet. Make one, print it and keep it safe. It is the way back in if the Admin password is forgotten or hacked.</span>
        <button class="btn btn-sm btn-warning" type="button" id="recMake">Make recovery code</button></div>`);
      ctx.el.querySelector('#recMake').onclick = () => App.recoveryCode();
    }
    UI.activate(ctx.el);
  },
});
