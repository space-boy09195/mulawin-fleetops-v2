/* =====================================================================
   assets/js/core.js — app shell, router and shared screen helpers
   =====================================================================
   - App.start()        login / change password / main shell
   - App.page(id, def)  each module file registers its pages here
   - App.api(name, d)   POST to backend (Module.action)
   - UI.*               tables, forms, modals, badges, charts, CSV
   MODULE ISOLATION: every page loads its own data. If a module file
   (assets/js/modules/<department>/*.js) or its backend fails, only that page shows
   an error; the sidebar and the other pages keep working.
   ===================================================================== */
'use strict';

const UI = {};
const App = {
  pages: {}, me: null, csrf: '', current: null, timer: null,

  /** Called by module files: App.page('trips', {title, subtitle, render}) */
  page(id, def) { this.pages[id] = def; },

  // ---------------------------------------------------------------- API
  async request(url, opts = {}) {
    let res, data;
    try { res = await fetch(url, { credentials: 'same-origin', ...opts }); }
    catch { throw new Error('Cannot reach the server. Check that Apache is running.'); }
    try { data = await res.json(); }
    catch { throw new Error('Server returned an invalid answer (check PHP errors / XAMPP setup).'); }
    if (res.status === 401 && this.me) { this.me = null; this.loginScreen(data.error); throw new Error(data.error || 'Please log in.'); }
    if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    return data;
  },
  async api(action, payload = {}) {
    const r = await this.request('index.php?api=' + encodeURIComponent(action), {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf }, body: JSON.stringify(payload) });
    return r.data || {};
  },
  async data(page, query = {}) {
    const qs = new URLSearchParams({ api: 'data', page, ...query });
    return (await this.request('index.php?' + qs.toString())).data;
  },
  async upload(formData) {
    formData.append('csrf', this.csrf);
    return (await this.request('index.php?api=document_upload', { method: 'POST', body: formData })).data;
  },

  // ---------------------------------------------------------------- start
  async start() {
    // Wika: English muna (login screen); pagka-login, ang napili ng user (users.language).
    if (typeof I18n !== 'undefined') I18n.start('en');
    try {
      const w = await this.request('index.php?api=whoami');
      this.csrf = w.csrf;
      if (!w.signedIn) return this.loginScreen();
      await this.loadMe();
    } catch (e) { this.loginScreen(e.message); }
  },
  async loadMe() {
    const me = await this.request('index.php?api=me');
    this.me = me; this.csrf = me.csrf;
    if (typeof I18n !== 'undefined') I18n.start(me.user.language);
    if (+me.user.must_change_password) return this.passwordScreen(true);
    this.shell();
    window.onhashchange = () => this.route();
    this.route();
    clearInterval(this.timer);
    this.timer = setInterval(() => this.refreshBell(), 60000);
  },

  loginScreen(message = '', username = '') {
    document.getElementById('app').innerHTML = `
    <div class="login-page"><div class="login-shell">
      <aside class="login-visual" aria-label="Fleet operations overview">
        <span class="visual-shape visual-shape--a"></span><span class="visual-shape visual-shape--b"></span>
        <div class="visual-content"><div class="brand-icon"><i class="bi bi-truck-front-fill"></i></div>
          <h2>Welcome to Mulawin FleetOps</h2>
          <p>Fleet Management System of R.P. Mulawin Trucking: trips, dispatch, maintenance, billing, finance and payroll in one place.</p></div>
      </aside>
      <div class="login-card">
        <div class="login-brand"><div class="brand-icon brand-icon--compact"><i class="bi bi-truck-front-fill"></i></div>
          <h1 class="brand-name">Welcome back</h1><p class="brand-sub">Sign in with your work account.</p></div>
        ${message ? `<div class="alert alert-danger py-2">${UI.esc(message)}</div>` : ''}
        <form id="loginForm" novalidate>
          <div class="mb-3"><label class="form-label" for="lgU">Username</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-person-fill"></i></span>
            <input class="form-control" id="lgU" name="username" autocomplete="username" value="${UI.esc(username)}" required></div></div>
          <div class="mb-4"><label class="form-label" for="lgP">Password</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
            <input class="form-control" type="password" id="lgP" name="password" autocomplete="current-password" required>
            <button class="btn btn-outline-secondary" type="button" id="lgEye" aria-label="Show password"><i class="bi bi-eye-slash"></i></button></div></div>
          <button class="btn btn-primary w-100 login-btn" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i> Sign In</button>
          <div class="text-center mt-3"><span class="text-muted small">Forgot your password? Ask the Admin to reset it.</span></div>
        </form>
        <p class="login-footer-text">Mulawin FleetOps &copy; ${new Date().getFullYear()}</p>
      </div></div></div>`;
    document.getElementById('lgEye').onclick = () => { const p = document.getElementById('lgP'); p.type = p.type === 'password' ? 'text' : 'password'; };
    document.getElementById('loginForm').onsubmit = async (e) => {
      e.preventDefault();
      const f = Object.fromEntries(new FormData(e.target));
      const btn = e.target.querySelector('button[type=submit]'); btn.disabled = true;
      try {
        const w = await this.request('index.php?api=whoami'); this.csrf = w.csrf;
        await this.request('index.php?api=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...f, csrf: this.csrf }) });
        location.hash = ''; await this.loadMe();
      } catch (err) { this.loginScreen(err.message, f.username || ''); document.getElementById(f.username ? 'lgP' : 'lgU')?.focus(); }
    };
  },

  passwordScreen(forced) {
    const body = `${forced ? '<div class="alert alert-warning py-2">Please change your temporary password before you continue.</div>' : ''}
      ${UI.fields([{ name: 'current_password', label: 'Current password', type: 'password', required: true },
        { name: 'new_password', label: 'New password', type: 'password', required: true, help: (App.me?.passwordMin || 8) + '+ characters with letters and numbers.' },
        { name: 'confirm', label: 'Type the new password again', type: 'password', required: true }])}`;
    const run = async (d) => {
      if (d.new_password !== d.confirm) throw new Error('The new passwords do not match.');
      await this.api('Admin.change_password', d); UI.toast('Password changed.');
      if (forced) await this.loadMe();
    };
    if (forced) {
      document.getElementById('app').innerHTML = `<div class="login-page"><div class="login-shell"><div class="login-card mx-auto">
        <div class="login-brand"><h1 class="brand-name">Change password</h1></div><form id="pwForm">${body}
        <div class="alert alert-danger py-2 form-error"></div><button class="btn btn-primary w-100 login-btn">Save password</button>
        <button type="button" class="btn btn-link w-100 mt-2" id="pwOut">Log out</button></form></div></div></div>`;
      document.getElementById('pwOut').onclick = () => this.logout();
      document.getElementById('pwForm').onsubmit = async (e) => {
        e.preventDefault(); const err = e.target.querySelector('.form-error'); err.style.display = 'none';
        try { await run(Object.fromEntries(new FormData(e.target))); } catch (x) { err.textContent = x.message; err.style.display = 'block'; }
      };
    } else UI.modal('Change password', body, run, { submit: 'Save password' });
  },

  async logout() {
    try { await this.request('index.php?api=logout', { method: 'POST', headers: { 'X-CSRF-Token': this.csrf } }); } catch { }
    this.me = null; location.hash = ''; this.start();
  },

  // ---------------------------------------------------------------- shell (repo layout)
  shell() {
    const me = this.me, u = me.user;
    const top = me.nav.filter(n => !n.section);
    const bySection = {};
    me.nav.filter(n => n.section).forEach(n => (bySection[n.section] = bySection[n.section] || []).push(n));
    const initials = (u.full_name || u.username).split(' ').filter(Boolean).map(s => s[0]).slice(0, 2).join('').toUpperCase();
    const navLink = (n, cls) => `<a href="#${n.id}" class="${cls}" data-nav="${n.id}" title="${UI.esc(n.label)}"><i class="bi ${n.icon} nav-icon"></i><span class="nav-label">${UI.esc(n.label)}</span></a>`;
    let sections = '';
    Object.entries(me.sections).forEach(([name, icon]) => {
      const items = bySection[name]; if (!items) return;
      const id = 'sec' + name.replace(/[^A-Za-z]/g, '');
      sections += `<section class="nav-section"><button class="nav-section-toggle collapsed" type="button" data-sec="${id}" title="${UI.esc(name)}">
        <i class="bi ${icon} nav-section-icon"></i><span class="nav-section-label">${UI.esc(name)}</span><i class="bi bi-chevron-down nav-section-chevron"></i></button>
        <div class="nav-section-items collapsed" id="${id}" hidden>${items.map(n => `<div class="nav-item">${navLink(n, 'nav-link')}</div>`).join('')}</div></section>`;
    });
    document.getElementById('app').innerHTML = `
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="app-shell" id="appShell">
      <aside class="sidebar" id="appSidebar">
        <div class="sidebar-brand"><a href="#dashboard" class="sidebar-home-btn"><div class="sidebar-brand-icon"><i class="bi bi-truck-front-fill"></i></div></a>
          <div class="sidebar-brand-text"><div class="brand-title">FleetOps</div><div class="brand-sub">RP Mulawin Trucking</div></div></div>
        <nav class="sidebar-nav">${top.map(n => navLink(n, 'sidebar-home-nav')).join('')}${sections}</nav>
      </aside>
      <div class="main-wrapper">
        <header class="topnav no-print">
          <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle sidebar"><i class="bi bi-list"></i></button>
          <div class="topnav-breadcrumb"><a href="#dashboard" class="bc-home"><i class="bi bi-house"></i></a><i class="bi bi-chevron-right"></i><span class="bc-current" id="bcTitle">Dashboard</span></div>
          <div class="topnav-actions">
            <div class="dropdown"><button class="notif-btn" type="button" data-bs-toggle="dropdown" title="Notifications"><i class="bi bi-bell"></i>
              <span class="notif-badge d-none" id="bellBadge">0</span></button>
              <div class="dropdown-menu dropdown-menu-end p-0 notif-menu"><div class="notif-menu-head"><span class="fw-semibold">Notifications</span>
                <button type="button" class="notif-clear-btn" id="bellClear">Mark all read</button></div><div id="bellList"></div></div></div>
            <div class="dropdown profile-dropdown"><button class="profile-trigger" type="button" data-bs-toggle="dropdown">
              <span class="profile-avatar">${UI.esc(initials)}</span><span class="profile-identity"><span class="profile-name">${UI.esc(u.full_name)}</span></span>
              <i class="bi bi-chevron-down profile-chevron"></i></button>
              <div class="dropdown-menu dropdown-menu-end profile-menu"><div class="profile-menu-user"><span class="profile-menu-name">${UI.esc(u.full_name)}</span>
                <span class="profile-menu-role">${UI.esc(u.role_label)}${u.shift ? ' · ' + UI.esc(u.shift) + ' shift' : ''}</span></div>
                <div class="dropdown-divider"></div><div class="dropdown-header small py-1">Language</div>
                ${[['en', 'English'], ['tl', 'Tagalog']].map(([k, l]) => `<button class="dropdown-item lang-item" type="button" data-lang="${k}"><i class="bi ${(u.language || 'en') === k ? 'bi-check2' : 'bi-blank'} me-2"></i><span class="no-i18n">${l}</span></button>`).join('')}
                <div class="dropdown-divider"></div><button class="dropdown-item" id="chgPw"><i class="bi bi-key me-2"></i>Change password</button>
                <button class="dropdown-item profile-logout" id="logoutBtn"><i class="bi bi-box-arrow-right me-2"></i>Log Out</button></div></div>
          </div>
        </header>
        <main class="page-content" id="pageContent"></main>
      </div>
    </div><button type="button" class="to-top no-print" id="toTop" title="Back to top"><i class="bi bi-arrow-up"></i></button><div id="printArea"></div>`;
    // Accordion: isang section lang ang bukas para hindi mahaba ang sidebar.
    document.querySelectorAll('[data-sec]').forEach(b => b.onclick = () => {
      const box = document.getElementById(b.dataset.sec); this.openSection(box.hidden ? b.dataset.sec : null);
    });
    // Mahabang page: itago ang subtitle habang naka-scroll at ipakita ang "Back to top".
    document.getElementById('toTop').onclick = () => window.scrollTo({ top: 0, behavior: 'smooth' });
    window.onscroll = () => {
      // May pagitan (120 at 30) para hindi kumurap: lumiliit ang header kapag naka-scroll, kaya umiikli ang page.
      const cl = document.body.classList;
      if (window.scrollY > 120) cl.add('page-scrolled'); else if (window.scrollY < 30) cl.remove('page-scrolled');
      document.getElementById('toTop').classList.toggle('show', window.scrollY > 400);
    };
    const sidebar = document.getElementById('appSidebar'), shell = document.getElementById('appShell'), overlay = document.getElementById('sidebarOverlay');
    document.getElementById('sidebarToggleBtn').onclick = () => {
      if (window.innerWidth <= 768) { sidebar.classList.toggle('mobile-open'); overlay.classList.toggle('active'); }
      else { sidebar.classList.toggle('collapsed'); shell.classList.toggle('sidebar-collapsed'); }
    };
    overlay.onclick = () => { sidebar.classList.remove('mobile-open'); overlay.classList.remove('active'); };
    document.getElementById('logoutBtn').onclick = () => { if (confirm('Log out of Mulawin FleetOps?')) this.logout(); };
    document.getElementById('chgPw').onclick = () => this.passwordScreen(false);
    // Wika ng screen: naka-save sa account, kaya pareho saan mang computer o phone mag-login.
    document.querySelectorAll('.lang-item').forEach(b => b.onclick = async () => {
      if (b.dataset.lang === (this.me.user.language || 'en')) return;
      try { await this.api('Admin.set_language', { language: b.dataset.lang }); location.reload(); }
      catch (e) { UI.toast(e.message, 'error'); }
    });
    document.getElementById('bellClear').onclick = async (e) => { e.stopPropagation(); await this.api('Admin.notifications_read', {}); this.refreshBell(); };
    this.renderBell();
  },

  /** Bubuksan ang isang sidebar section at isasara ang iba (null = isara lahat). */
  openSection(secId) {
    document.querySelectorAll('[data-sec]').forEach(b => {
      const box = document.getElementById(b.dataset.sec), open = b.dataset.sec === secId;
      box.hidden = !open; b.classList.toggle('collapsed', !open); box.classList.toggle('collapsed', !open);
    });
  },

  renderBell() {
    const me = this.me; const badge = document.getElementById('bellBadge'); if (!badge) return;
    badge.textContent = me.unread; badge.classList.toggle('d-none', !me.unread);
    document.getElementById('bellList').innerHTML = me.notifications.length ? me.notifications.map(n =>
      `<a class="dropdown-item small notif-item ${+n.is_read ? '' : 'unread'}" href="#${UI.esc(n.page || 'dashboard')}" data-nid="${n.id}">
        <strong>${UI.esc(n.category)}</strong><br>${UI.esc(n.message)}<span class="notif-time d-block text-muted">${UI.datetime(n.created_at)}</span></a>`).join('')
      : '<div class="text-muted small px-3 py-3">No notifications.</div>';
    document.querySelectorAll('[data-nid]').forEach(a => a.addEventListener('click', () => this.api('Admin.notifications_read', { id: a.dataset.nid }).then(() => this.refreshBell()).catch(() => { })));
  },
  async refreshBell() {
    if (!this.me) return;
    try { const me = await this.request('index.php?api=me'); this.me.notifications = me.notifications; this.me.unread = me.unread; this.renderBell(); } catch { }
  },

  // ---------------------------------------------------------------- router (#page?x=y)
  go(page, query = {}) {
    const qs = new URLSearchParams(query).toString();
    const hash = '#' + page + (qs ? '?' + qs : '');
    if (location.hash === hash) this.route(); else location.hash = hash;
  },
  async route() {
    if (!this.me) return;
    const [page, qs] = (location.hash.slice(1) || 'dashboard').split('?');
    const query = Object.fromEntries(new URLSearchParams(qs || ''));
    const nav = this.me.nav.find(n => n.id === page);
    if (!nav) return this.go('dashboard');
    this.current = { page, query };
    document.querySelectorAll('[data-nav]').forEach(a => a.classList.toggle('active', a.dataset.nav === page));
    const link = document.querySelector(`[data-nav="${page}"]`);
    const sec = link && link.closest('.nav-section-items');
    this.openSection(sec ? sec.id : null);
    document.getElementById('appSidebar').classList.remove('mobile-open'); document.getElementById('sidebarOverlay').classList.remove('active');
    document.getElementById('bcTitle').textContent = nav.label;
    const el = document.getElementById('pageContent');
    const def = this.pages[page];
    if (!def) {
      el.innerHTML = `<div class="page-header"><div class="page-title">${UI.esc(nav.label)}</div></div>
        <div class="module-down"><i class="bi bi-exclamation-triangle me-2"></i>This module's screen (assets/js/modules/${UI.esc(nav.js)}.js) did not load. Other modules still work. Reload the page or check the file.</div>`;
      return;
    }
    // Nakadikit ang header (title at buttons) habang nag-i-scroll. Ang dashboard at reports
    // ay may malalaking cards; ang ibang page (work pages) ay compact ang cards.
    const work = !['dashboard', 'reports'].includes(page);
    el.innerHTML = `<div class="page-header page-header-sticky d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div><div class="page-title">${UI.esc(def.title || nav.label)}</div><div class="page-subtitle">${UI.esc(def.subtitle || '')}</div></div>
        <div class="d-flex gap-2 no-print" id="pageTools"></div></div><div id="pageBody" class="${work ? 'work-page' : ''}"><div class="text-muted p-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div></div>`;
    if (this._lastPage !== page) window.scrollTo(0, 0); // bagong page lang; hindi kapag nag-reload pagka-save
    this._lastPage = page;
    const body = document.getElementById('pageBody');
    const ctx = { page, query, el: body, tools: document.getElementById('pageTools'), me: this.me, data: null,
      reload: () => this.route(), go: (p, q) => this.go(p, q), setQuery: (q) => this.go(page, { ...query, ...q }) };
    try {
      ctx.data = def.noData ? {} : await this.data(page, query);
      if (this.current.page !== page) return;
      body.innerHTML = '';
      await def.render(ctx);
      if (ctx.data && ctx.data._errors && Object.keys(ctx.data._errors).length) {
        body.insertAdjacentHTML('afterbegin', `<div class="alert alert-warning py-2">${Object.values(ctx.data._errors).map(UI.esc).join('<br>')}</div>`);
      }
    } catch (e) {
      console.error(e);
      body.innerHTML = `<div class="module-down"><i class="bi bi-exclamation-triangle me-2"></i>${UI.esc(e.message)}<div class="small mt-2">Only this page is affected. You can keep using the other modules.</div></div>`;
    }
  },
};

// =====================================================================
//  UI HELPERS
// =====================================================================
UI.esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
UI.money = (v) => (v === null || v === undefined || v === '') ? '—' : '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
UI.num = (v, d = 0) => (v === null || v === undefined || v === '') ? '—' : Number(v).toLocaleString('en-PH', { maximumFractionDigits: d });
UI.date = (v) => v ? new Date(String(v).replace(' ', 'T')).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
UI.datetime = (v) => v ? new Date(String(v).replace(' ', 'T')).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
UI.today = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
UI.label = (role) => (App.me && App.me.roles[role]) || role;
UI.role = (...roles) => App.me && roles.includes(App.me.user.role);
UI.isDispatcher = () => UI.role('car_carrier_dispatcher', 'container_wingvan_dispatcher');

/** Status colors (same meaning everywhere). */
UI.badgeColor = (s) => {
  const v = String(s || '');
  if (/^(Available|Completed|Paid|Approved|Released|Liquidated|Present|Issued|Received|Active|OK|Resolved|Disbursed|Stock In|Contribution|Repaired - Cleared to Continue)$/.test(v)) return 'green';
  if (/^(Assigned|Pre-Assigned|Ready for Dispatch|Dispatched|In Transit|In Progress|On Trip|Ready for Release|Ordered|Finalized|Submitted to Finance|Scheduled|Partially Paid|Stock Out|Payment)$/.test(v)) return 'blue';
  if (/^(Pending Approval|Pending Review|Awaiting Resources|Under Repair|Requested|Waiting for Parts|Reported|Draft|Returned|Not Liquidated|Open|Minor|Adjustment|Prepared|Issued Invoice|Client|Pending Documents|VL|SIL|Charge)$/.test(v)) return 'amber';
  if (/(Under Maintenance|On Hold|For Reschedule|Rejected|Cancelled|Rescheduled|Not Available|Unavailable|Major|Absent|Overdue|Resigned|Inactive)/.test(v)) return 'red';
  return '';
};
UI.badge = (s) => s === null || s === undefined || s === '' ? '—' : `<span class="badge-s ${UI.badgeColor(s)}">${UI.esc(s)}</span>`;
UI.days = (d) => {
  if (d === null || d === undefined || d === '') return '—';
  const n = +d; const c = n < 0 ? 'red' : n <= 30 ? 'amber' : 'green';
  return `<span class="badge-s ${c}">${n < 0 ? 'expired ' + Math.abs(n) + 'd ago' : n === 0 ? 'today' : n + ' days left'}</span>`;
};
UI.fmt = (type, v, row) => {
  if (typeof type === 'function') return type(v, row);
  switch (type) {
    case 'money': return UI.money(v); case 'date': return UI.date(v); case 'datetime': return UI.datetime(v);
    case 'badge': return UI.badge(v); case 'days': return UI.days(v); case 'num': return UI.num(v, 2);
    case 'yes': return +v ? 'Yes' : 'No';
    default: return v === null || v === undefined || v === '' ? '—' : UI.esc(v);
  }
};

/** Toast message at the bottom right. */
UI.toast = (msg, type = '') => {
  if (!msg) return;
  const el = document.createElement('div'); el.className = 'app-toast ' + type; el.textContent = msg;
  document.getElementById('toastZone').append(el); setTimeout(() => el.remove(), type ? 8000 : 4500);
};
/** Shows the result of a backend action (message + warning). */
UI.result = (r) => { UI.toast(r.message || 'Saved.'); if (r.warning) UI.toast(r.warning, 'warn'); };

/** Stat cards (repo .stat-card style). */
UI.cards = (cards) => `<div class="stat-grid">${(cards || []).map(c => { const go = UI.canGo(c.page); return `
  <div class="stat-card ${go ? 'clickable' : ''}" ${go ? `data-go="${UI.esc(c.page)}" title="Open the list behind this number"` : ''}>${go ? '<i class="bi bi-chevron-right stat-go" aria-hidden="true"></i>' : ''}
    <div class="stat-icon ${c.color || 'green'}"><i class="bi ${c.icon || 'bi-circle'}"></i></div>
    <div class="stat-info"><div class="stat-value">${c.format === 'money' ? UI.money(c.value) : UI.esc(typeof c.value === 'number' ? UI.num(c.value, 2) : c.value)}</div>
    <div class="stat-label">${UI.esc(c.label)}</div>${c.hint ? `<div class="stat-hint">${UI.esc(c.hint)}</div>` : ''}</div></div>`; }).join('')}</div>`;
/**
 * Link ng card o alert: "page", "page?filter=x" o "page#tab=key".
 * Ang "#..." ay nagsasabi kung aling tab ang bubuksan (alaala ng UI.tabs):
 *   "_" = pangunahing tabs ng page, ibang pangalan = key ng tab group (halimbawa finance "fin" at "fin:money").
 */
UI.goParts = (spec) => {
  const [main, mem] = String(spec || '').split('#'); const [page, qs] = main.split('?');
  return { page, query: Object.fromEntries(new URLSearchParams(qs || '')), tabs: [...new URLSearchParams(mem || '')] };
};
UI.canGo = (spec) => !!spec && !!App.me && (App.me.nav || []).some(n => n.id === UI.goParts(spec).page);
UI.goTo = (spec) => {
  const g = UI.goParts(spec);
  g.tabs.forEach(([k, v]) => sessionStorage.setItem('tab:' + g.page + (k === '_' ? '' : ':' + k), v));
  App.go(g.page, g.query);
};
UI.bindGo = (root) => root.querySelectorAll('[data-go]').forEach(x => x.onclick = () => UI.goTo(x.dataset.go));

/** Card wrapper. */
UI.card = (title, body, tools = '') => `<div class="card card-gap"><div class="card-header-custom"><h3 class="card-title-custom">${title}</h3><div class="d-flex gap-2 flex-wrap">${tools}</div></div><div class="card-body-custom p-0">${body}</div></div>`;

/**
 * Data table with search, "show more" and CSV download.
 * columns: [[label, key, format]] ; opts: {title, actions(row) -> html, empty, id, tools}
 */
UI.table = (columns, rows, opts = {}) => {
  const id = opts.id || 'tbl' + Math.random().toString(36).slice(2, 8);
  UI._tables[id] = { columns, rows: rows || [], opts, limit: opts.limit || 25, page: 0, filter: '' };
  const head = `<div class="table-tools"><input class="form-control form-control-sm" placeholder="Search…" data-tsearch="${id}">
     <div class="d-flex gap-2 align-items-center"><span class="text-muted small" data-tcount="${id}"></span>${opts.tools || ''}
     <button class="btn btn-sm btn-outline-secondary" data-tcsv="${id}" title="Download CSV"><i class="bi bi-download"></i> CSV</button></div></div>`;
  const html = `${head}<div class="table-wrap" data-tbody="${id}"></div><div class="table-pager no-print" data-tpager="${id}"></div>`;
  return opts.title ? UI.card(opts.title, html) : `<div class="card card-gap">${html}</div>`;
};
UI._tables = {};
UI.tableRows = (id) => {
  const t = UI._tables[id]; if (!t) return [];
  const f = t.filter.toLowerCase();
  return f ? t.rows.filter(r => t.columns.some(([, k]) => String(r[k] ?? '').toLowerCase().includes(f))) : t.rows;
};
/** Gumuguhit ng isang page ng table (25 rows bawat page, may Prev at Next). */
UI.drawTable = (id) => {
  const t = UI._tables[id]; const box = document.querySelector(`[data-tbody="${id}"]`); if (!t || !box) return;
  const rows = UI.tableRows(id);
  document.querySelector(`[data-tcount="${id}"]`).textContent = rows.length + ' record(s)';
  const pager = box.parentElement.querySelector(`[data-tpager="${id}"]`);
  if (pager) pager.innerHTML = '';
  if (!rows.length) { box.innerHTML = `<div class="table-empty">${UI.esc(t.opts.empty || 'No records yet.')}</div>`; return; }
  const pages = Math.ceil(rows.length / t.limit);
  t.page = Math.min(Math.max(t.page, 0), pages - 1);
  const first = t.page * t.limit, shown = rows.slice(first, first + t.limit);
  const money = (f) => f === 'money' || f === 'num';
  box.innerHTML = `<table class="data-table"><thead><tr>${t.columns.map(([l, , f]) => `<th class="${money(f) ? 'num' : ''}">${UI.esc(l)}</th>`).join('')}${t.opts.actions ? '<th>Actions</th>' : ''}</tr></thead>
    <tbody>${shown.map((r) => `<tr>${t.columns.map(([, k, f]) => `<td class="${money(f) ? 'num' : ''}">${UI.fmt(f, r[k], r)}</td>`).join('')}${t.opts.actions ? `<td><div class="row-actions" data-row="${id}:${t.rows.indexOf(r)}">${t.opts.actions(r) || ''}</div></td>` : ''}</tr>`).join('')}</tbody></table>`;
  if (pager) pager.innerHTML = pages > 1 ? `<span class="small text-muted">${first + 1}–${first + shown.length} of ${rows.length}</span>
    <div class="btn-group btn-group-sm"><button type="button" class="btn btn-outline-secondary" data-tpg="-1" ${t.page === 0 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i> Prev</button>
    <button type="button" class="btn btn-outline-secondary" disabled>Page ${t.page + 1} of ${pages}</button>
    <button type="button" class="btn btn-outline-secondary" data-tpg="1" ${t.page >= pages - 1 ? 'disabled' : ''}>Next <i class="bi bi-chevron-right"></i></button></div>` : '';
  pager?.querySelectorAll('[data-tpg]').forEach(b => b.onclick = () => { t.page += +b.dataset.tpg; UI.drawTable(id); box.scrollTop = 0; });
  if (t.opts.onAction) box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => {
    const idx = +b.closest('[data-row]').dataset.row.split(':')[1]; t.opts.onAction(b.dataset.act, t.rows[idx], b);
  }));
};
/** Call after inserting table HTML into the page. */
UI.activate = (root) => {
  root.querySelectorAll('[data-tbody]').forEach(b => UI.drawTable(b.dataset.tbody));
  root.querySelectorAll('[data-tsearch]').forEach(inp => inp.addEventListener('input', () => { const t = UI._tables[inp.dataset.tsearch]; t.filter = inp.value; t.page = 0; UI.drawTable(inp.dataset.tsearch); }));
  root.querySelectorAll('[data-tcsv]').forEach(b => b.addEventListener('click', () => {
    const t = UI._tables[b.dataset.tcsv]; UI.csv((t.opts.title || 'report').replace(/<[^>]+>/g, ''), t.columns, UI.tableRows(b.dataset.tcsv));
  }));
  UI.bindGo(root);
  root.querySelectorAll('[data-chart]').forEach(c => UI.drawChart(c));
  root.querySelectorAll('[data-tpicker]').forEach(box => UI.drawPicker(box));
  root.querySelectorAll('[data-chartmore]').forEach(m => m.querySelector('button').onclick = () => {
    const holder = document.createElement('div'); holder.className = 'chart-grid';
    holder.innerHTML = (UI._more[m.dataset.chartmore] || []).map(UI.chartHtml).join('');
    m.replaceWith(holder); holder.querySelectorAll('[data-chart]').forEach(c => UI.drawChart(c));
  });
};
UI.btn = (act, label, cls = 'outline-primary', icon = '') => `<button class="btn btn-sm btn-${cls}" data-act="${act}">${icon ? `<i class="bi ${icon}"></i> ` : ''}${UI.esc(label)}</button>`;

/** CSV download (Excel-friendly with BOM). Downloadable reports — PDF V.64. */
UI.csv = (name, columns, rows) => {
  const clean = (v) => { let s = String(v ?? '').replace(/<[^>]+>/g, ''); if (/^[=+\-@]/.test(s)) s = "'" + s; return '"' + s.replace(/"/g, '""') + '"'; };
  const lines = [columns.map(c => clean(c[0])).join(','), ...rows.map(r => columns.map(([, k]) => clean(r[k])).join(','))];
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
  a.download = name.toLowerCase().replace(/[^a-z0-9]+/g, '_') + '_' + UI.today() + '.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
};

// ------------------------------------------------------------------ forms
/**
 * fields: [{name, label, type, options:[[value,label]], required, value, help, col, min, step, placeholder, attrs}]
 * types: text, number, money, date, datetime, select, textarea, checkbox, password, file, hidden, html
 */
UI.fields = (fields) => `<div class="row g-2">${fields.map(UI.field).join('')}</div>`;
UI.field = (f) => {
  if (f.type === 'hidden') return `<input type="hidden" name="${f.name}" value="${UI.esc(f.value ?? '')}">`;
  if (f.type === 'html') return `<div class="col-${f.col || 12}">${f.html}</div>`;
  const id = 'f_' + f.name + '_' + Math.random().toString(36).slice(2, 6);
  const req = f.required ? 'required' : '';
  const val = f.value ?? '';
  let input;
  if (f.type === 'select') {
    input = `<select class="form-select form-select-sm" id="${id}" name="${f.name}" ${req} ${f.attrs || ''}>${f.placeholder === false ? '' : `<option value="">${UI.esc(f.placeholder || 'Choose…')}</option>`}
      ${(f.options || []).map(o => { const [v, l] = Array.isArray(o) ? o : [o, o]; return `<option value="${UI.esc(v)}" ${String(v) === String(val) ? 'selected' : ''}>${UI.esc(l)}</option>`; }).join('')}</select>`;
  } else if (f.type === 'textarea') {
    input = `<textarea class="form-control form-control-sm" id="${id}" name="${f.name}" rows="${f.rows || 3}" ${req} placeholder="${UI.esc(f.placeholder || '')}">${UI.esc(val)}</textarea>`;
  } else if (f.type === 'checkbox') {
    return `<div class="col-${f.col || 12}"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="${id}" name="${f.name}" value="1" ${+val ? 'checked' : ''}>
      <label class="form-check-label small" for="${id}">${UI.esc(f.label)}</label>${f.help ? `<div class="form-help">${UI.esc(f.help)}</div>` : ''}</div></div>`;
  } else {
    const type = { money: 'number', datetime: 'datetime-local' }[f.type] || f.type || 'text';
    const step = f.type === 'money' ? 'step="0.01" min="0"' : (f.type === 'number' ? `step="${f.step || 1}" min="${f.min ?? 0}"` : '');
    input = `<input class="form-control form-control-sm" type="${type}" id="${id}" name="${f.name}" value="${UI.esc(val)}" ${step} ${req} placeholder="${UI.esc(f.placeholder || '')}" ${f.type === 'file' && f.multiple ? 'multiple' : ''} ${f.attrs || ''}>`;
  }
  return `<div class="col-md-${f.col || 6}"><label class="form-label ${f.required ? 'req' : ''}" for="${id}">${UI.esc(f.label)}</label>${input}${f.help ? `<div class="form-help">${UI.esc(f.help)}</div>` : ''}</div>`;
};
/** Reads a form into an object (checkboxes -> 1/0). */
UI.formData = (form) => {
  const d = {};
  new FormData(form).forEach((v, k) => { if (!(v instanceof File)) d[k] = v; });
  form.querySelectorAll('input[type=checkbox][name]').forEach(c => { d[c.name] = c.checked ? 1 : 0; });
  return d;
};

/**
 * Bootstrap modal with a form. onSubmit(data, form) may throw an Error;
 * the message shows inside the modal. Returns the modal element.
 */
UI.modal = (title, bodyHtml, onSubmit, opts = {}) => {
  const wrap = document.createElement('div');
  wrap.className = 'modal fade'; wrap.tabIndex = -1;
  wrap.innerHTML = `<div class="modal-dialog ${opts.size || 'modal-lg'} modal-dialog-scrollable"><form class="modal-content" novalidate>
    <div class="modal-header"><h5 class="modal-title">${title}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">${bodyHtml}<div class="alert alert-danger py-2 mt-3 form-error"></div></div>
    <div class="modal-footer">${opts.extraFooter || ''}<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
    ${onSubmit ? `<button type="submit" class="btn btn-${opts.danger ? 'danger' : 'primary'} btn-sm">${UI.esc(opts.submit || 'Save')}</button>` : ''}</div></form></div>`;
  document.body.append(wrap);
  const m = new bootstrap.Modal(wrap);
  const form = wrap.querySelector('form');
  form.onsubmit = async (e) => {
    e.preventDefault();
    const err = wrap.querySelector('.form-error'); err.style.display = 'none';
    const missing = [...form.querySelectorAll('[required]')].find(x => !x.value);
    if (missing) { err.textContent = 'Please fill in: ' + (form.querySelector(`label[for="${missing.id}"]`)?.textContent || missing.name); err.style.display = 'block'; missing.focus(); return; }
    const btn = form.querySelector('button[type=submit]'); btn.disabled = true;
    try { await onSubmit(UI.formData(form), form, wrap); m.hide(); }
    catch (x) { err.textContent = x.message; err.style.display = 'block'; }
    finally { btn.disabled = false; }
  };
  wrap.addEventListener('hidden.bs.modal', () => wrap.remove());
  m.show();
  if (opts.onShow) opts.onShow(wrap, form);
  return wrap;
};
/** Standard "form -> action -> reload page" modal. */
UI.formModal = (title, fields, action, opts = {}) => UI.modal(title, (opts.intro ? `<p class="small text-muted">${opts.intro}</p>` : '') + UI.fields(fields), async (d, form, wrap) => {
  const payload = opts.transform ? opts.transform(d, form, wrap) : d;
  const r = await App.api(action, { ...(opts.extra || {}), ...payload });
  UI.result(r);
  if (opts.after) opts.after(r); else App.route();
}, opts);
UI.confirmAction = (title, text, action, payload, opts = {}) => UI.modal(title, `<p>${text}</p>${opts.fields ? UI.fields(opts.fields) : ''}`, async (d) => {
  const r = await App.api(action, { ...payload, ...d }); UI.result(r); App.route();
}, { size: '', submit: opts.submit || 'Confirm', danger: opts.danger });

/**
 * Tabs: tabs = [[key, label, htmlFn]]. Naaalala ang huling tab bawat page.
 * opts.key  = hiwalay na alaala kapag may higit sa isang tab group sa page (halimbawa tabs sa loob ng tab)
 * opts.pills = maliit na pills (para sa tabs sa loob ng tab)
 */
UI.tabs = (root, tabs, active, opts = {}) => {
  if (!tabs.length) return;
  const id = 'tabs' + Math.random().toString(36).slice(2, 6);
  const mem = 'tab:' + App.current.page + (opts.key ? ':' + opts.key : '');
  active = active || tabs[0][0];
  root.insertAdjacentHTML('beforeend', `<ul class="nav ${opts.pills ? 'nav-pills sub-tabs' : 'nav-tabs'} mb-3 no-print" id="${id}">${tabs.map(([k, l]) => `<li class="nav-item"><button type="button" class="nav-link ${k === active ? 'active' : ''}" data-tab="${k}">${l}</button></li>`).join('')}</ul><div id="${id}body"></div>`);
  const body = document.getElementById(id + 'body');
  const show = (k) => {
    document.querySelectorAll(`#${id} > li > [data-tab]`).forEach(b => b.classList.toggle('active', b.dataset.tab === k));
    const t = tabs.find(x => x[0] === k);
    try { body.innerHTML = ''; const out = t[2](body); if (typeof out === 'string') body.innerHTML = out; UI.activate(body); }
    catch (e) { console.error(e); body.innerHTML = `<div class="module-down">${UI.esc(e.message)}</div>`; }
    sessionStorage.setItem(mem, k);
  };
  document.querySelectorAll(`#${id} > li > [data-tab]`).forEach(b => b.onclick = () => show(b.dataset.tab));
  const remembered = sessionStorage.getItem(mem);
  show(tabs.some(t => t[0] === remembered) ? remembered : active);
};

/**
 * Naka-grupong tabs: groups = [[key, label, [[key, label, htmlFn], ...]], ...].
 * Unang hilera = grupo; sa loob = maliit na pills ng mga tab ng grupong iyon.
 */
UI.groupTabs = (root, groups, opts = {}) => {
  UI.tabs(root, groups.map(([gk, gl, tabs]) => [gk, gl, (body) => {
    if (tabs.length === 1) return tabs[0][2](body);
    UI.tabs(body, tabs, null, { key: (opts.key || 'g') + ':' + gk, pills: true });
  }]), null, { key: opts.key });
};

// ------------------------------------------------------------------ charts (Chart.js, repo colors)
UI.palette = ['#0e4a26', '#c78c00', '#3b82f6', '#66b878', '#c62828', '#8d6400', '#7c3aed', '#0891b2', '#64748b', '#f59e0b'];
/** Iisang kulay bawat kahulugan sa lahat ng chart (status, pera, payroll, aging, truck type). */
UI.meaningColors = {
  'Available': '#15803d', 'On Trip': '#2563eb', 'Under Maintenance': '#c62828', 'Unavailable': '#94a3b8',
  'Billed': '#2563eb', 'Collected': '#15803d', 'Expenses': '#c62828',
  'Cash in (collections)': '#15803d', 'Cash out (disbursements)': '#c62828',
  'Gross': '#2563eb', 'Deductions': '#c62828', 'Net': '#15803d',
  'All trips': '#0e4a26', 'Trips': '#0e4a26', 'Completed': '#66b878', 'Completed trips': '#66b878',
  'Parts': '#0891b2', 'Labor': '#c78c00', 'Other': '#64748b',
  'Not yet due': '#15803d', '1-30 days': '#f59e0b', '31-60 days': '#ea580c', '61-90 days': '#c62828', 'Over 90 days': '#7f1d1d',
  'Assigned': '#c78c00',
  'Car Carrier': '#0e4a26', 'Container': '#c78c00', 'Wing Van': '#3b82f6',
  'Client': '#c78c00',
};
UI.colorOf = (label, i) => UI.meaningColors[label] || UI.palette[i % UI.palette.length];
UI._charts = [];
UI.chartEmpty = (c) => !c.labels || !c.labels.length || c.datasets.every(d => !d.data.some(x => +x));
UI.chartHtml = (c) => {
  const id = 'ch' + Math.random().toString(36).slice(2, 8);
  UI._charts[id] = c;
  // Pahigang bar: taas ayon sa dami ng pangalan para hindi siksik.
  const h = c.horizontal ? Math.max(220, Math.min(640, 70 + c.labels.length * 30)) : 0;
  return `<div class="card"><div class="card-header-custom"><h3 class="card-title-custom">${UI.esc(c.title)}</h3></div><div class="card-body-custom">
    ${UI.chartEmpty(c) ? '<div class="table-empty">No data for this period.</div>' : `<div class="chart-box${c.horizontal ? ' chart-box-h' : ''}" ${h ? `style="height:${h}px"` : ''}><canvas data-chart="${id}"></canvas></div>`}
    ${c.how ? `<div class="chart-how"><i class="bi bi-info-circle"></i> ${UI.esc(c.how)}</div>` : ''}</div></div>`;
};
/** Chart.js settings mula sa spec: peso format, %, kulay ayon sa kahulugan, pahiga, stacked. */
UI.chartConfig = (c, forPrint = false) => {
  const round = c.type === 'doughnut' || c.type === 'pie';
  const money = c.format === 'money';
  const fmt = (v) => money ? UI.money(v) : UI.num(v, 2);
  const axisFmt = (v) => money ? '₱' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }) : UI.num(v, 2);
  const valueAxis = c.horizontal ? 'x' : 'y', nameAxis = c.horizontal ? 'y' : 'x';
  // Bilang (trips, units): buong numero lang sa axis, walang 0.5.
  const whole = !money && c.datasets.every(d => d.data.every(x => Number.isInteger(+x)));
  return {
    type: c.type,
    data: { labels: c.labels, datasets: c.datasets.map((d, i) => ({ ...d,
      backgroundColor: round ? c.labels.map((l, j) => UI.colorOf(l, j)) : UI.colorOf(d.label, i),
      borderColor: round ? '#fff' : UI.colorOf(d.label, i), tension: .3, fill: false, maxBarThickness: 48 })) },
    options: {
      responsive: !forPrint, maintainAspectRatio: false, animation: forPrint ? false : undefined,
      indexAxis: c.horizontal ? 'y' : 'x',
      plugins: {
        legend: { display: round || c.datasets.length > 1, position: 'bottom' },
        tooltip: { callbacks: { label: (ctx) => {
          const v = ctx.raw;
          if (round) { const total = ctx.dataset.data.reduce((a, b) => a + (+b || 0), 0); return `${ctx.label}: ${fmt(v)} (${total ? (v / total * 100).toFixed(1) : 0}%)`; }
          return `${ctx.dataset.label}: ${fmt(v)}`;
        } } },
      },
      scales: round ? {} : {
        [valueAxis]: { beginAtZero: true, stacked: !!c.stacked, ticks: { callback: axisFmt, ...(whole ? { precision: 0 } : {}) } },
        [nameAxis]: { stacked: !!c.stacked, ticks: { autoSkip: !c.horizontal } },
      },
    },
  };
};
UI.drawChart = (canvas) => {
  const c = UI._charts[canvas.dataset.chart]; if (!c || typeof Chart === 'undefined') return;
  new Chart(canvas, UI.chartConfig(c));
};
/** Larawan (PNG) ng chart para sa print. */
UI.chartImage = (c, w = 900, h = 340) => {
  if (typeof Chart === 'undefined' || UI.chartEmpty(c)) return '';
  const cv = document.createElement('canvas'); cv.width = w; cv.height = c.horizontal ? Math.max(h, 70 + c.labels.length * 26) : h;
  cv.style.position = 'fixed'; cv.style.left = '-10000px'; document.body.append(cv);
  const ch = new Chart(cv, UI.chartConfig(c, true));
  const url = cv.toDataURL('image/png'); ch.destroy(); cv.remove();
  return url;
};

/**
 * Renders the common {alerts, cards, charts, tables} block used by dashboards and reports.
 * Pagkakasunod: alerts (kailangang gawin) -> cards -> charts -> tables.
 * opts.maxCharts = ilang chart ang kita agad; ang iba ay nasa "Show more charts".
 * Kapag higit sa isa ang table: iisang card na may pamimilian (pills). Ang walang laman
 * ay isang maikling linya na lang.
 */
UI.blocks = (spec, opts = {}) => {
  let h = '';
  if (spec.alerts && spec.alerts.length) h += `<div class="dash-alerts">${spec.alerts.map(a => `<div class="alert alert-${a.level || 'info'}"><span>${UI.esc(a.text)}</span>${a.page ? `<button class="btn btn-sm btn-light" data-go="${a.page}">Open</button>` : ''}</div>`).join('')}</div>`;
  if (spec.cards && spec.cards.length) h += UI.cardGroups(spec.cards);
  const charts = spec.charts || [], max = opts.maxCharts || charts.length;
  if (charts.length) {
    h += `<div class="chart-grid">${charts.slice(0, max).map(UI.chartHtml).join('')}</div>`;
    if (charts.length > max) {
      const id = 'cm' + Math.random().toString(36).slice(2, 8);
      UI._more[id] = charts.slice(max);
      h += `<div class="chart-more no-print" data-chartmore="${id}"><button type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart"></i> Show ${charts.length - max} more chart(s)</button></div>`;
    }
  }
  const tables = spec.tables || [];
  if (tables.length === 1) h += UI.blockTable(tables[0]);
  else if (tables.length > 1) {
    const id = 'tp' + Math.random().toString(36).slice(2, 8);
    UI._pickers[id] = { tables, key: opts.key || '' };
    h += `<div class="card card-gap" data-tpicker="${id}"></div>`;
  }
  return h;
};
UI._more = {}; UI._pickers = {};
UI.blockTable = (t, title = true) => UI.table(t.columns.map(([l, k, f]) => [l, k, f]), t.rows, { title: title ? UI.esc(t.title) + (t.page ? ` <a href="#${t.page}" class="small ms-2">open</a>` : '') : '', limit: 25 });
/** Stat cards; kapag may "group" ang cards, may maliit na heading bawat grupo. */
UI.cardGroups = (cards) => {
  if (!cards.some(c => c.group)) return UI.cards(cards);
  const groups = [];
  cards.forEach(c => { const g = groups.find(x => x[0] === (c.group || '')); g ? g[1].push(c) : groups.push([c.group || '', [c]]); });
  return groups.map(([g, list]) => `${g ? `<div class="card-group-title">${UI.esc(g)}</div>` : ''}${UI.cards(list)}`).join('');
};
/** Iginuguhit ang table picker: pills ng mga table na may laman, at isang linya para sa walang laman. */
UI.drawPicker = (box) => {
  const p = UI._pickers[box.dataset.tpicker]; if (!p) return;
  const full = p.tables.filter(t => (t.rows || []).length), empty = p.tables.filter(t => !(t.rows || []).length);
  const mem = 'tpick:' + App.current.page + ':' + p.key;
  let cur = sessionStorage.getItem(mem);
  if (!full.some(t => t.title === cur)) cur = full.length ? full[0].title : null;
  const t = full.find(x => x.title === cur);
  box.innerHTML = `${full.length ? `<div class="card-header-custom picker-head"><div class="picker-pills">${full.map(x => `<button type="button" class="picker-pill ${x === t ? 'active' : ''}" data-pick="${UI.esc(x.title)}">${UI.esc(x.title)} <span class="picker-count">${x.rows.length}</span></button>`).join('')}</div>
      ${t && t.page ? `<a href="#${t.page}" class="small text-nowrap">open page</a>` : ''}</div>` : ''}
    ${t ? UI.blockTable(t, false).replace(/^<div class="card card-gap">/, '<div class="picker-body">') : ''}
    ${empty.length ? `<div class="picker-empty"><i class="bi bi-info-circle"></i> No records: ${empty.map(x => UI.esc(x.title)).join(', ')}.</div>` : ''}`;
  box.querySelectorAll('[data-pick]').forEach(b => b.onclick = () => { sessionStorage.setItem(mem, b.dataset.pick); UI.drawPicker(box); });
  box.querySelectorAll('[data-tbody]').forEach(b => UI.drawTable(b.dataset.tbody));
  box.querySelectorAll('[data-tsearch]').forEach(inp => inp.addEventListener('input', () => { const tb = UI._tables[inp.dataset.tsearch]; tb.filter = inp.value; tb.page = 0; UI.drawTable(inp.dataset.tsearch); }));
  box.querySelectorAll('[data-tcsv]').forEach(b => b.addEventListener('click', () => {
    const tb = UI._tables[b.dataset.tcsv]; UI.csv(t ? t.title : 'report', tb.columns, UI.tableRows(b.dataset.tcsv));
  }));
};

/** Small list showing a computation (name, how, amount). */
UI.breakdown = (lines, total, totalLabel = 'Total') => `<table class="breakdown w-100">${(lines || []).map(l => `<tr><td>${UI.esc(l.name)}</td><td class="text-muted">${UI.esc(l.explain || '')}</td><td class="num">${UI.money(l.amount)}</td></tr>`).join('')}
  <tr class="total"><td colspan="2">${UI.esc(totalLabel)}</td><td class="num">${UI.money(total)}</td></tr></table>`;

/**
 * Fund Request form (bagong FMS PDF Scenario A step 3 / B step 4).
 * Pipili ang dispatcher ng preset categories (may naka-set na halaga) at pwedeng
 * magdagdag ng ibang category. Ang total ay kino-compute habang pumipili; ang
 * server pa rin ang kumukuha ng tunay na preset amount.
 * opts: { title, categories:[{name, amount}], selected:[names], extra:[{name, amount}], notes, action, payload, intro }
 */
UI.fundForm = (opts) => {
  const sel = new Set(opts.selected || []);
  const extra = (opts.extra || []).concat(Array(Math.max(0, 3 - (opts.extra || []).length)).fill({ name: '', amount: '' }));
  const cats = (opts.categories || []).map((c, i) => `<label class="d-flex justify-content-between border rounded px-2 py-1 mb-1">
      <span><input type="checkbox" class="form-check-input me-2 fund-cat" data-name="${UI.esc(c.name)}" data-amount="${+c.amount}" ${sel.has(c.name) ? 'checked' : ''}>${UI.esc(c.name)}</span>
      <span class="num">${UI.money(c.amount)}</span></label>`).join('') || '<div class="text-muted small">No preset categories yet. Ask Admin or Finance to add them in Formula Settings.</div>';
  const rows = extra.map((l, i) => `<div class="row g-2 mb-1 fund-extra">
      <div class="col-8"><input class="form-control form-control-sm" maxlength="80" placeholder="Other expense category" value="${UI.esc(l.name || '')}"></div>
      <div class="col-4"><input class="form-control form-control-sm" type="number" min="0" step="0.01" placeholder="Amount" value="${l.amount === '' ? '' : +l.amount}"></div></div>`).join('');
  const body = `${opts.intro ? `<p class="small text-muted">${opts.intro}</p>` : ''}
    <h6>Fund categories (preset amounts)</h6>${cats}
    <h6 class="mt-3">Other expense categories (optional)</h6>${rows}
    <div class="mt-2"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes" maxlength="255" value="${UI.esc(opts.notes || '')}"></div>
    <div class="fs-6 mt-3 text-end">Total requested fund: <b id="fundTotal">₱0.00</b></div>`;
  UI.modal(opts.title, body, async (d, form) => {
    const categories = [...form.querySelectorAll('.fund-cat:checked')].map(c => c.dataset.name);
    const extraLines = [...form.querySelectorAll('.fund-extra')].map(r => { const [n, a] = r.querySelectorAll('input'); return { name: n.value.trim(), amount: a.value }; }).filter(x => x.name || x.amount);
    if (!categories.length && !extraLines.length) throw new Error('Choose at least one fund category.');
    const r = await App.api(opts.action, { ...opts.payload, categories, extra: extraLines, notes: d.notes || '' });
    UI.result(r); App.route();
  }, { submit: 'Submit Fund Request', onShow(w) {
    const total = () => {
      let t = 0;
      w.querySelectorAll('.fund-cat:checked').forEach(c => t += +c.dataset.amount);
      w.querySelectorAll('.fund-extra').forEach(r => { const a = +r.querySelectorAll('input')[1].value; if (a > 0) t += a; });
      w.querySelector('#fundTotal').textContent = UI.money(t);
    };
    w.addEventListener('input', total); w.addEventListener('change', total); total();
  } });
};

/** Print only the given HTML (payslips, deduction forms, invoices). */
UI.print = (html) => { const a = document.getElementById('printArea'); a.innerHTML = html; window.print(); };
/**
 * Print ng report sections (Reports & Analytics). Laging bago ang laman ng printArea, kaya hindi na
 * lumalabas ang lumang payslip o blangkong page. meta = {title, period, filters}.
 * Kasama: header (kumpanya, report, period, filters, sino at kailan nag-print), cards, charts (larawan) at buong tables.
 */
UI.printReport = (sections, meta = {}) => {
  const e = UI.esc, u = App.me.user, co = (App.me.company || {}).name || '';
  const cards = (list) => (list || []).length ? `<div class="pr-cards">${list.map(c => `<div class="pr-card"><div class="pr-val">${c.format === 'money' ? UI.money(c.value) : e(typeof c.value === 'number' ? UI.num(c.value, 2) : c.value)}</div><div class="pr-lbl">${e(c.label)}</div>${c.hint ? `<div class="pr-hint">${e(c.hint)}</div>` : ''}</div>`).join('')}</div>` : '';
  const charts = (list) => (list || []).map(c => { const round = c.type === 'doughnut' || c.type === 'pie';
    const img = round ? UI.chartImage(c, 520, 300) : UI.chartImage(c); return img ? `<div class="pr-chart${round ? ' pr-half' : ''}"><div class="pr-h3">${e(c.title)}</div><img src="${img}" alt="${e(c.title)}">${c.how ? `<div class="pr-note">${e(c.how)}</div>` : ''}</div>` : ''; }).join('');
  const tables = (list) => {
    const full = (list || []).filter(t => (t.rows || []).length), empty = (list || []).filter(t => !(t.rows || []).length);
    return full.map(t => `<div class="pr-table"><div class="pr-h3">${e(t.title)} <span class="pr-count">(${t.rows.length})</span></div><table><thead><tr>${t.columns.map(([l, , f]) => `<th class="${f === 'money' ? 'num' : ''}">${e(l)}</th>`).join('')}</tr></thead>
      <tbody>${t.rows.map(r => `<tr>${t.columns.map(([, k, f]) => `<td class="${f === 'money' ? 'num' : ''}">${String(UI.fmt(f, r[k], r)).replace(/<(?!\/?(b|i|span)\b)[^>]+>/g, '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`).join('')
      + (empty.length ? `<div class="pr-note">No data: ${empty.map(t => e(t.title)).join(', ')}.</div>` : '');
  };
  const html = `<div class="print-report"><div class="pr-head"><div><div class="pr-co">${e(co)}</div><div class="pr-title">${e(meta.title || 'Report')}</div>
      <div class="pr-meta">Period: <b>${e(meta.period || '')}</b>${meta.filters ? ` · ${e(meta.filters)}` : ''}</div></div>
      <div class="pr-by">Printed by ${e(u.full_name)} (${e(u.role_label || u.role)})<br>${e(new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }))}</div></div>
    ${sections.map((s, i) => `<section class="pr-section${i ? ' pr-break' : ''}"><h2>${e(s.title)}</h2>${s.note ? `<div class="pr-note">${e(s.note)}</div>` : ''}${cards(s.cards)}${charts(s.charts)}${tables(s.tables)}</section>`).join('')}</div>`;
  document.getElementById('printArea').innerHTML = html;
  setTimeout(() => window.print(), 50);
};

/** Options helpers for selects. */
UI.opts = (rows, labelFn, valueKey = 'id') => (rows || []).map(r => [r[valueKey], labelFn(r)]);
UI.list = (arr) => (arr || []).map(x => [x, x]);
