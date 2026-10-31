/* assets/js/modules/admin.js — User Accounts, Activity Log, Formula Settings, Backup
   (FMS PDF II.A and V.61, V.65). Backend: backend/Modules/Admin.php */
'use strict';

App.page('users', {
  title: 'User Accounts',
  subtitle: 'Individual accounts, roles (FMS PDF user roles), activate / deactivate, password reset.',
  render(ctx) {
    const d = ctx.data, roles = Object.entries(d.roles);
    const form = (u = {}) => UI.formModal(u.id ? 'Edit account' : 'Create account', [{ name: 'id', type: 'hidden', value: u.id },
      ...(u.id ? [] : [{ name: 'username', label: 'Username', required: true, col: 6 }, { name: 'password', label: 'Temporary password', type: 'password', required: true, col: 6, help: (App.me.passwordMin || 8) + '+ characters with letters and numbers. The user changes it at first login.' }]),
      { name: 'full_name', label: 'Full name', required: true, value: u.full_name, col: 6 },
      { name: 'role', label: 'Role', type: 'select', required: true, options: roles, value: u.role, col: 3 },
      { name: 'shift', label: 'Shift (dispatchers)', type: 'select', options: UI.list(['Day', 'Night']), value: u.shift, col: 3 }], 'Admin.user_save');
    ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="addU"><i class="bi bi-person-plus"></i> Create account</button>';
    document.getElementById('addU').onclick = () => form();
    ctx.el.innerHTML = UI.table([['Username', 'username'], ['Full name', 'full_name'], ['Role', 'role', (x) => UI.esc(d.roles[x] || x)], ['Shift', 'shift'], ['Active', 'active', (x) => UI.badge(+x ? 'Active' : 'Inactive')],
      ['Must change password', 'must_change_password', 'yes'], ['Last login', 'last_login_at', 'datetime'], ['Created', 'created_at', 'datetime']], d.users, {
      id: 'users', actions: (u) => UI.btn('edit', 'Edit', 'outline-primary') + UI.btn('pw', 'Reset password', 'outline-warning') + (+u.id !== +App.me.user.id ? UI.btn('tog', +u.active ? 'Deactivate' : 'Activate', 'outline-danger') : ''),
      onAction(act, u) {
        if (act === 'edit') form(u);
        if (act === 'pw') UI.formModal('Reset password of ' + UI.esc(u.username), [{ name: 'user_id', type: 'hidden', value: u.id }, { name: 'password', label: 'New temporary password', type: 'password', required: true, col: 12 }], 'Admin.user_password', { size: '' });
        if (act === 'tog') UI.confirmAction(+u.active ? 'Deactivate account' : 'Activate account', `${+u.active ? 'Deactivate' : 'Activate'} ${UI.esc(u.username)}?`, 'Admin.user_toggle', { user_id: u.id, active: +u.active ? 0 : 1 }, { danger: !!+u.active });
      } });
    UI.activate(ctx.el);
  },
});

App.page('audit_log', {
  title: 'Activity Log',
  subtitle: 'Who created, updated, approved or processed a record, with date and time.',
  render(ctx) {
    const d = ctx.data, q = { ...d.range, ...ctx.query };
    ctx.el.innerHTML = `<form class="row g-2 align-items-end mb-3" id="alF">${UI.field({ name: 'from', label: 'From', type: 'date', value: q.from, col: 2 })}${UI.field({ name: 'to', label: 'To', type: 'date', value: q.to, col: 2 })}
      ${UI.field({ name: 'area', label: 'Area', type: 'select', options: UI.list(d.areas), value: q.area, placeholder: 'All', col: 3 })}${UI.field({ name: 'q', label: 'Search', value: q.q, col: 3 })}
      <div class="col-md-2"><button class="btn btn-sm btn-primary">Show</button></div></form>` +
      UI.table([['When', 'created_at', 'datetime'], ['By', 'actor'], ['Role', 'role', (x) => UI.esc(UI.label(x || ''))], ['Area', 'area', 'badge'], ['Record', 'record_ref'], ['Action', 'action'], ['Details', 'details']], d.events, { id: 'alog', limit: 100 });
    document.getElementById('alF').onsubmit = (e) => { e.preventDefault(); const v = UI.formData(e.target); Object.keys(v).forEach(k => v[k] === '' && delete v[k]); ctx.go('audit_log', v); };
    UI.activate(ctx.el);
  },
});

App.page('settings', {
  title: 'Formula Settings',
  subtitle: 'Change the numbers and computations without editing code. New records use the new values; old records keep the values used at that time.',
  render(ctx) {
    const d = ctx.data;
    const calcHelp = { fixed: 'Same amount every time', percent_of_rate: '% of the trip rate', per_day: '(amount ÷ divisor) × days present', per_km: 'amount × km', per_trip: 'amount × trips',
      fuel: '(km ÷ km per liter) × diesel price', route_toll: 'RFID / toll saved on the route', manual: 'Typed by the user', ledger: 'Installment from a balance' };
    const groups = {}; d.values.forEach(v => (groups[v.group] = groups[v.group] || []).push(v));
    let html = `<div class="alert alert-info small"><b>Paano gamitin:</b> Palitan ang numero at pindutin ang Save. Lahat ng pagbabago ay nakatala sa Activity Log. Ang "Reset" ay ibabalik sa default na nasa <code>config/formulas.php</code>.</div>`;
    Object.entries(groups).forEach(([g, list]) => {
      html += UI.card(UI.esc(g), `<div class="p-3">${list.map(v => `<form class="row g-2 align-items-center mb-2 border-bottom pb-2" data-key="${v.key}">
        <div class="col-md-5"><b class="small">${UI.esc(v.label)}</b><div class="form-help">${UI.esc(v.help)}</div><div class="form-help"><i>Source: ${UI.esc(v.source)}</i></div></div>
        <div class="col-md-3"><div class="input-group input-group-sm"><input class="form-control" name="value" value="${UI.esc(v.value)}" ${v.can_edit ? '' : 'disabled'}><span class="input-group-text">${UI.esc(v.unit)}</span></div>
        <div class="form-help">Default: ${UI.esc(v.default)}${v.is_default ? ' (in use)' : ''}</div></div>
        <div class="col-md-4">${v.can_edit ? `<button class="btn btn-sm btn-primary">Save</button> ${v.is_default ? '' : '<button type="button" class="btn btn-sm btn-link" data-reset>Reset</button>'}` : '<span class="small text-muted">View only for your role</span>'}</div></form>`).join('')}</div>`);
    });
    html += '<div class="section-title"><i class="bi bi-list-check"></i>Computation lists</div>';
    d.scopes.forEach(s => {
      const rows = s.items.map((it, i) => `<tr data-i="${i}"><td><input class="form-control form-control-sm" name="name" value="${UI.esc(it.name)}" ${s.can_edit ? '' : 'disabled'}></td>
        <td><select class="form-select form-select-sm" name="calc" ${s.can_edit ? '' : 'disabled'}>${s.calcs.map(c => `<option value="${c}" ${c === it.calc ? 'selected' : ''}>${c}</option>`).join('')}</select></td>
        <td><input class="form-control form-control-sm" name="amount" type="number" step="0.000001" value="${it.amount}" ${s.can_edit ? '' : 'disabled'} style="width:110px"></td>
        <td><input class="form-control form-control-sm" name="divisor" type="number" step="0.000001" value="${it.divisor}" ${s.can_edit ? '' : 'disabled'} style="width:80px"></td>
        <td class="text-center"><input type="checkbox" name="only_if_attended" ${+it.only_if_attended ? 'checked' : ''} ${s.can_edit ? '' : 'disabled'}></td>
        <td><select class="form-select form-select-sm" name="ledger" ${s.can_edit ? '' : 'disabled'}><option value=""></option>${['Cash Advance', 'Uniform', 'Damages', 'Cash Bond'].map(l => `<option ${l === it.ledger ? 'selected' : ''}>${l}</option>`).join('')}</select></td>
        <td class="text-center"><input type="checkbox" name="active" ${+it.active ? 'checked' : ''} ${s.can_edit ? '' : 'disabled'}></td>
        <td><input class="form-control form-control-sm" name="source" value="${UI.esc(it.source || '')}" ${s.can_edit ? '' : 'disabled'}></td>
        <td>${s.can_edit ? '<button type="button" class="btn btn-sm btn-outline-danger" data-del>✕</button>' : ''}</td></tr>`).join('');
      html += UI.card(UI.esc(s.label), `<form data-scope="${s.scope}"><div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Computation</th><th>Amount</th><th>Divisor</th><th>Only if with attendance</th><th>Balance type</th><th>Active</th><th>Source / note</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>
        ${s.can_edit ? `<div class="p-2 d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-primary" data-add><i class="bi bi-plus"></i> Add line</button><button class="btn btn-sm btn-primary">Save list</button><button type="button" class="btn btn-sm btn-link" data-resetlist>Reset to defaults</button></div>` : ''}
        <div class="p-2 small text-muted">Computation types: ${s.calcs.map(c => `<b>${c}</b> = ${calcHelp[c]}`).join(' · ')}</div></form>`);
    });
    const ex = d.example;
    html += UI.card('Example with the current values (Billing P&L sample: 143 km, RFID ₱651, rate ₱15,000)', `<div class="row p-3"><div class="col-md-6"><h6>Trip allowance</h6>${UI.breakdown(ex.allowance.lines, ex.allowance.total, 'Fund request')}</div>
      <div class="col-md-6"><h6>Projected P&L</h6><p class="small mb-1">Gross sales ${UI.money(ex.pnl.gross_sales)}</p>${UI.breakdown(ex.pnl.expenses, ex.pnl.total_expense, 'Total expense')}<p class="mt-2"><b>Net income ${UI.money(ex.pnl.net_income)}</b> (${ex.pnl.margin_percent}%)</p></div></div>`);
    ctx.el.innerHTML = html;
    ctx.el.querySelectorAll('form[data-key]').forEach(f => {
      f.onsubmit = async (e) => { e.preventDefault(); try { UI.result(await App.api('Admin.setting_save', { key: f.dataset.key, value: f.value.value })); ctx.reload(); } catch (x) { UI.toast(x.message, 'error'); } };
      f.querySelector('[data-reset]')?.addEventListener('click', async () => { try { UI.result(await App.api('Admin.setting_reset', { key: f.dataset.key })); ctx.reload(); } catch (x) { UI.toast(x.message, 'error'); } });
    });
    ctx.el.querySelectorAll('form[data-scope]').forEach(f => {
      const tbody = f.querySelector('tbody');
      f.addEventListener('click', (e) => {
        if (e.target.closest('[data-del]')) e.target.closest('tr').remove();
        if (e.target.closest('[data-add]')) { const tr = tbody.querySelector('tr')?.cloneNode(true); if (!tr) return UI.toast('Reset to defaults first, then add lines.', 'warn');
          tr.querySelectorAll('input').forEach(i => { if (i.type === 'checkbox') i.checked = i.name === 'active'; else i.value = i.name === 'divisor' ? 1 : ''; }); tbody.append(tr); }
      });
      f.querySelector('[data-resetlist]')?.addEventListener('click', async () => { if (!confirm('Reset this list to config/formulas.php?')) return; try { UI.result(await App.api('Admin.items_reset', { scope: f.dataset.scope })); ctx.reload(); } catch (x) { UI.toast(x.message, 'error'); } });
      f.onsubmit = async (e) => {
        e.preventDefault();
        const items = [...tbody.querySelectorAll('tr')].map(tr => { const o = {}; tr.querySelectorAll('[name]').forEach(i => o[i.name] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value); return o; });
        try { UI.result(await App.api('Admin.items_save', { scope: f.dataset.scope, items })); ctx.reload(); } catch (x) { UI.toast(x.message, 'error'); }
      };
    });
  },
});

App.page('backup', {
  title: 'Backup',
  subtitle: 'Data backup and recovery (FMS PDF V.65).',
  render(ctx) {
    const d = ctx.data;
    ctx.el.innerHTML = `<div class="card"><div class="card-body-custom"><p>1. Click <b>Create backup</b> to save a copy of the whole database (.sql). 2. Download it and keep it outside this computer (USB / cloud).
      3. Also copy the folder <code>storage/private</code> (uploaded documents).<br><b>To restore:</b> create a NEW empty database in phpMyAdmin, choose it, Import the .sql file, then put the database name in <code>.env</code>.</p>
      <button class="btn btn-primary btn-sm" id="mkB"><i class="bi bi-hdd-network"></i> Create backup</button></div></div>` +
      UI.table([['File', 'name'], ['Created', 'date'], ['Size', 'size', (x) => (x / 1024).toFixed(0) + ' KB']], d.backups.sort((a, b) => b.name.localeCompare(a.name)), {
        id: 'bks', title: 'Backups on the server', actions: () => UI.btn('dl', 'Download', 'outline-primary', 'bi-download'),
        onAction: (a, b) => window.open('index.php?api=backup_download&file=' + encodeURIComponent(b.name), '_blank') });
    document.getElementById('mkB').onclick = async (e) => { e.target.disabled = true; try { const r = await App.api('Admin.backup_create', {}); UI.result(r); window.open('index.php?api=backup_download&file=' + encodeURIComponent(r.file), '_blank'); ctx.reload(); } catch (x) { UI.toast(x.message, 'error'); e.target.disabled = false; } };
    UI.activate(ctx.el);
  },
});
