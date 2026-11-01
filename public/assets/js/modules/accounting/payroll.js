/* assets/js/modules/accounting/payroll.js — Payroll, Attendance, Cash Advance & Balances
   (FMS PDF II.J; DRIVERS PAYROLL.xlsx layout). Backend: backend/Modules/Accounting/Payroll.php */
'use strict';

const Pay = {
  json(x) { try { return JSON.parse(x || '{}'); } catch { return {}; } },
  /** Printable payslip, same parts as the Excel payslip sheets. */
  slip(line, period) {
    const e = Pay.json(line.earnings_json), dd = Pay.json(line.deductions_json);
    const earn = [['Regular days', UI.num(line.days_worked, 2), true], ['Total # of trips', line.trips_count, true], ['Regular pay', UI.money(line.regular_wage)], ['Trip pay / allowances', UI.money(line.trip_pay)],
      ...(e.lines || []).filter(l => +l.amount).map(l => [l.name, UI.money(l.amount)])];
    const ded = (dd.lines || []).filter(l => +l.amount).map(l => [l.name, UI.money(l.amount)]);
    const n = Math.max(earn.length, ded.length);
    let rows = '';
    for (let i = 0; i < n; i++) rows += `<tr><td>${UI.esc(earn[i]?.[0] || '')}</td><td class="num">${earn[i]?.[1] ?? ''}</td><td style="width:20px"></td><td>${UI.esc(ded[i]?.[0] || '')}</td><td class="num">${ded[i]?.[1] ?? ''}</td></tr>`;
    return `<div class="payslip"><h6>${UI.esc(App.me.company.name)}</h6><div class="addr">${UI.esc(App.me.company.address)}</div>
      <div><b>PAYSLIP</b> for the period covered: ${UI.date(period.date_from)} – ${UI.date(period.date_to)} (${UI.esc(period.period_name)})</div>
      <div>Employee's Name: <b>${UI.esc(line.full_name)}</b> ${line.employee_no ? '(' + UI.esc(line.employee_no) + ')' : ''} · ${UI.esc(line.person_type)} · Daily rate ${UI.money(line.daily_rate)}</div>
      <table class="mt-2"><tr><th colspan="2">GROSS PAY:</th><th></th><th colspan="2">DEDUCTIONS:</th></tr>${rows}
      <tr><td><b>TOTAL</b></td><td class="num"><b>${UI.money(line.gross_pay)}</b></td><td></td><td><b>TOTAL</b></td><td class="num"><b>${UI.money(line.total_deductions)}</b></td></tr></table>
      <div class="mt-2 fs-6">NET PAY: <b>${UI.money(line.net_pay)}</b></div>
      <div class="d-flex justify-content-between align-items-end mt-3"><div class="small">I acknowledge to have received the amount of P <u>${UI.money(line.net_pay).replace('₱', '')}</u> &amp; have no further claims for services rendered.</div>
      <div class="sig">Signature of Employee</div></div></div>`;
  },
};

App.page('payroll', {
  title: 'Payroll',
  subtitle: 'Weekly / semi-monthly payroll from attendance, completed trips with waybills, trip pay rates, earnings and deductions (Formula Settings).',
  render(ctx) {
    const d = ctx.data, e = d.can.edit, pid = +ctx.query.period || 0;
    const period = (d.periods || []).find(p => +p.id === pid);
    if (e) {
      ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="newPer"><i class="bi bi-plus-lg"></i> New payroll</button>';
      document.getElementById('newPer').onclick = () => {
        const mon = new Date(UI.today()); mon.setDate(mon.getDate() - ((mon.getDay() + 6) % 7) - 7);
        const iso = (x) => { const y = new Date(x); y.setMinutes(y.getMinutes() - y.getTimezoneOffset()); return y.toISOString().slice(0, 10); };
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        UI.formModal('New payroll', [
          { name: 'period_name', label: 'Payroll name', required: true, value: 'Week of ' + iso(mon), col: 6 },
          { name: 'payroll_group', label: 'Payroll group', type: 'select', options: UI.list(d.groups), col: 6, placeholder: 'All active employees' },
          { name: 'date_from', label: 'From', type: 'date', required: true, value: iso(mon), col: 6 }, { name: 'date_to', label: 'To', type: 'date', required: true, value: iso(sun), col: 6 }],
          'Payroll.period_create', { intro: 'Lines are computed from attendance (Present days), completed trips with waybills and the trip pay rates. You can edit each line while it is a Draft.' });
      };
    }
    if (!period) {
      const per = d.periods || [];
      ctx.el.innerHTML = UI.cards([
        { label: 'Draft', value: per.filter(p => p.status === 'Draft').length, icon: 'bi-pencil-square', color: 'amber' },
        { label: 'Finalized', value: per.filter(p => p.status === 'Finalized').length, icon: 'bi-file-earmark-check', color: 'blue' },
        { label: 'Submitted to Finance', value: per.filter(p => p.status === 'Submitted to Finance').length, icon: 'bi-send', color: 'blue' },
        { label: 'Disbursed', value: per.filter(p => p.status === 'Disbursed').length, icon: 'bi-check2-circle', color: 'green' },
      ]);
      const rateForm = (r = {}) => UI.formModal('Trip pay rate', [{ name: 'id', type: 'hidden', value: r.id },
        { name: 'truck_type', label: 'Truck type', type: 'select', required: true, options: UI.list(App.me.truckTypes), value: r.truck_type, col: 4 },
        { name: 'route_id', label: 'Route', type: 'select', options: UI.opts(d.routes, x => `${x.origin} → ${x.destination}`), value: r.route_id, col: 8, placeholder: 'Use destination below' },
        { name: 'destination', label: 'Destination (if no route)', value: r.destination, col: 6 },
        { name: 'driver_rate', label: 'Driver rate per trip (PHP)', type: 'money', required: true, value: r.driver_rate, col: 3 }, { name: 'helper_rate', label: 'Helper rate per trip (PHP)', type: 'money', required: true, value: r.helper_rate ?? 0, col: 3 },
        ...(r.id ? [{ name: 'inactive', label: 'Inactive', type: 'checkbox', value: +r.active ? 0 : 1 }] : [])], 'Payroll.rate_save', { intro: 'Trip pay = rate per destination × number of trips (driver and helper have different rates).' });
      UI.tabs(ctx.el, [
        ['per', 'Payroll periods', () => UI.table([['Payroll', 'period_name'], ['Group', 'payroll_group'], ['From', 'date_from', 'date'], ['To', 'date_to', 'date'], ['Employees', 'employees'], ['Trip pay', 'total_trip_pay', 'money'],
          ['Gross', 'total_gross', 'money'], ['Deductions', 'total_deductions', 'money'], ['Net', 'total_net', 'money'], ['Status', 'status', 'badge']], per, { id: 'per', actions: () => UI.btn('open', 'Open', 'primary'), onAction: (a, p) => ctx.go('payroll', { period: p.id }) })],
        ['rates', 'Trip pay rates', () => UI.table([['Truck type', 'truck_type'], ['Route / destination', 'route_destination', (x, r) => x ? UI.esc(r.origin) + ' → ' + UI.esc(x) : UI.esc(r.destination)], ['Driver rate', 'driver_rate', 'money'], ['Helper rate', 'helper_rate', 'money'], ['Active', 'active', 'yes']], d.tripRates, {
          id: 'tpr', tools: e ? '<button class="btn btn-sm btn-primary" id="addRate"><i class="bi bi-plus-lg"></i> Rate</button>' : '', actions: e ? () => UI.btn('edit', 'Edit', 'outline-primary') : null, onAction: (a, r) => rateForm(r) })],
        ['items', 'Computation used', () => `<div class="card"><div class="card-body-custom small"><p><b>Regular wage</b> = daily rate × days Present · <b>Trip pay</b> = trip pay rate × trips (completed, with waybill) · <b>Gross</b> = regular wage + trip pay + earnings · <b>Net</b> = gross − deductions.</p>
          <p class="mb-1"><b>Other earnings:</b> ${d.earningItems.map(i => UI.esc(i.name) + ' (' + i.calc + ')').join(', ')}</p><p><b>Deductions:</b> ${d.deductionItems.map(i => UI.esc(i.name) + ' (' + i.calc + (i.calc === 'per_day' ? ': ' + i.amount + ' ÷ ' + i.divisor + ' × days' : i.calc === 'fixed' ? ': ' + UI.money(i.amount) : '') + (i.only_if_attended ? ', if with attendance' : '') + ')').join(', ')}</p>
          <p class="text-muted mb-0">Change these in Formula Settings (Admin / Payroll).</p></div></div>`],
      ]);
      ctx.el.addEventListener('click', (ev) => { if (ev.target.closest('#addRate')) rateForm(); });
      return;
    }
    // ---------- one payroll period ----------
    const lines = (d.lines || []).filter(l => +l.period_id === pid);
    const draft = period.status === 'Draft';
    ctx.el.innerHTML = `<div class="mb-2"><a href="#payroll" class="small"><i class="bi bi-arrow-left"></i> All payrolls</a></div>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-3"><h5 class="mb-0">${UI.esc(period.period_name)}</h5>${UI.badge(period.status)}<span class="text-muted small">${UI.date(period.date_from)} – ${UI.date(period.date_to)} · ${UI.esc(period.payroll_group || 'All groups')}</span></div>
      <div class="d-flex flex-wrap gap-2 mb-3 no-print" id="perActs"></div>` + UI.cards([
      { label: 'Employees', value: lines.length, icon: 'bi-people', color: 'blue' }, { label: 'Trip pay', value: period.total_trip_pay, format: 'money', icon: 'bi-truck', color: 'green' },
      { label: 'Gross pay', value: period.total_gross, format: 'money', icon: 'bi-cash', color: 'blue' }, { label: 'Deductions', value: period.total_deductions, format: 'money', icon: 'bi-dash-circle', color: 'red' },
      { label: 'Net pay', value: period.total_net, format: 'money', icon: 'bi-cash-stack', color: 'green' }]);
    const acts = [];
    if (e && draft) acts.push(['recompute', 'Recompute all', 'outline-primary', 'bi-arrow-clockwise'], ['add', 'Add employee', 'outline-primary', 'bi-person-plus'], ['finalize', 'Finalize', 'success', 'bi-lock']);
    if (e && period.status === 'Finalized') acts.push(['reopen', 'Reopen as draft', 'outline-warning', 'bi-unlock'], ['submit', 'Submit to Finance', 'success', 'bi-send']);
    acts.push(['slips', 'Print payslips', 'outline-secondary', 'bi-printer'], ['dform', 'Print deduction form', 'outline-secondary', 'bi-printer']);
    const box = document.getElementById('perActs');
    box.innerHTML = acts.map(([a, l, c, i]) => UI.btn(a, l, c, i)).join('');
    box.querySelectorAll('[data-act]').forEach(b => b.onclick = () => ({
      recompute: () => UI.confirmAction('Recompute', 'Recompute every line from attendance, trips and balances? Typed amounts are kept.', 'Payroll.period_compute', { period_id: pid }),
      add: () => UI.formModal('Add employee', [{ name: 'period_id', type: 'hidden', value: pid }, { name: 'personnel_id', label: 'Employee', type: 'select', required: true,
        options: UI.opts((d.people || []).filter(p => !lines.some(l => +l.personnel_id === +p.id)), p => `${p.full_name} (${p.person_type}, ${UI.money(p.daily_rate)}/day)`), col: 12 }], 'Payroll.line_add', { size: '' }),
      finalize: () => UI.confirmAction('Finalize payroll', 'Finalize? Lines are locked, payslips are ready, and the balance deductions (cash advance, uniform, damages) and cash bond are posted.', 'Payroll.finalize', { period_id: pid }),
      reopen: () => UI.confirmAction('Reopen payroll', 'Reopen as Draft? The balance postings are reversed.', 'Payroll.reopen', { period_id: pid }, { fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] }),
      submit: () => UI.confirmAction('Submit to Finance', 'Send this payroll to Finance for disbursement?', 'Payroll.submit', { period_id: pid }),
      slips: () => UI.print(lines.map(l => Pay.slip(l, period)).join('')),
      dform: () => {
        const names = [...new Set(lines.flatMap(l => (Pay.json(l.deductions_json).lines || []).map(x => x.name)))];
        UI.print(`<div class="payslip"><h6>${UI.esc(App.me.company.name)}</h6><div class="addr">DEDUCTION FORM — ${UI.esc(period.period_name)} (${UI.date(period.date_from)} – ${UI.date(period.date_to)})</div>
          <table border="1"><tr><th>Employee</th>${names.map(n => `<th>${UI.esc(n)}</th>`).join('')}<th>Total</th></tr>
          ${lines.map(l => { const m = Object.fromEntries((Pay.json(l.deductions_json).lines || []).map(x => [x.name, x.amount])); return `<tr><td>${UI.esc(l.full_name)}</td>${names.map(n => `<td class="num">${+m[n] ? UI.money(m[n]) : ''}</td>`).join('')}<td class="num"><b>${UI.money(l.total_deductions)}</b></td></tr>`; }).join('')}
          </table><div class="d-flex justify-content-between mt-4"><div class="sig">Prepared by (Payroll)</div><div class="sig">Checked by (Finance)</div></div></div>`);
      },
    })[b.dataset.act]());
    const edit = (l) => {
      const e1 = Pay.json(l.earnings_json), dd = Pay.json(l.deductions_json), inp = dd.inputs || {};
      const row = (it, kind) => it.calc === 'manual'
        ? { name: 'manual__' + it.name, label: it.name + ' (type the amount)', type: 'money', value: inp.manual?.[it.name] ?? '', col: 4 }
        : { name: 'override__' + it.name, label: `${it.name}: computed ${UI.money(it.amount)}`, type: 'money', value: inp.override?.[it.name] ?? '', col: 4, help: 'Leave blank to keep the computed value' };
      UI.modal('Edit payroll line — ' + UI.esc(l.full_name), UI.fields([
        { name: 'days_override', label: `Days worked (from attendance: ${UI.num(l.days_worked, 2)})`, type: 'number', step: '0.5', value: inp.days_override ?? '', col: 4, help: 'Blank = use attendance' },
        { type: 'html', html: '<h6 class="mt-2 mb-0">Other earnings</h6>' }, ...(e1.lines || []).map(it => row(it, 'e')),
        { type: 'html', html: '<h6 class="mt-2 mb-0">Deductions</h6>' }, ...(dd.lines || []).map(it => row(it, 'd')),
        { name: 'notes', label: 'Notes', value: l.notes, col: 12 }]), async (x) => {
        const manual = {}, override = {};
        Object.entries(x).forEach(([k, v]) => { if (k.startsWith('manual__')) manual[k.slice(8)] = v; if (k.startsWith('override__') && v !== '') override[k.slice(10)] = v; });
        await App.api('Payroll.line_update', { line_id: l.id, days_override: x.days_override, manual, override, notes: x.notes }); UI.toast('Line updated.'); App.route();
      }, { size: 'modal-xl' });
    };
    const ded = (l, name) => (Pay.json(l.deductions_json).lines || []).find(x => x.name === name)?.amount ?? 0;
    const dNames = [...new Set(lines.flatMap(l => (Pay.json(l.deductions_json).lines || []).map(x => x.name)))];
    const eNames = [...new Set(lines.flatMap(l => (Pay.json(l.earnings_json).lines || []).map(x => x.name)))];
    const rows = lines.map(l => ({ ...l, ...Object.fromEntries(dNames.map(n => ['d_' + n, ded(l, n)])), ...Object.fromEntries(eNames.map(n => ['e_' + n, (Pay.json(l.earnings_json).lines || []).find(x => x.name === n)?.amount ?? 0])) }));
    const tb = document.createElement('div'); ctx.el.append(tb);
    tb.innerHTML = UI.table([['Name', 'full_name'], ['Position', 'person_type'], ['Daily rate', 'daily_rate', 'money'], ['Days', 'days_worked', 'num'], ['Trips', 'trips_count'], ['Regular wage', 'regular_wage', 'money'], ['Trip pay', 'trip_pay', 'money'],
      ...eNames.map(n => [n, 'e_' + n, 'money']), ['Gross pay', 'gross_pay', 'money'], ...dNames.map(n => [n, 'd_' + n, 'money']), ['Total deductions', 'total_deductions', 'money'],
      ['Net pay', 'net_pay', (x) => `<b class="${+x < 0 ? 'text-danger' : ''}">${UI.money(x)}</b>`], ['Notes', 'notes']], rows, {
      id: 'plines', title: 'Payroll lines (same columns as the Excel payroll sheet)', limit: 200,
      actions: (l) => UI.btn('slip', 'Payslip', 'outline-secondary', 'bi-printer') + UI.btn('trips', 'Trips', 'outline-secondary') + (e && draft ? UI.btn('edit', 'Edit', 'outline-primary') + UI.btn('rm', 'Remove', 'outline-danger') : ''),
      onAction(act, l) {
        if (act === 'slip') UI.print(Pay.slip(l, period));
        if (act === 'edit') edit(l);
        if (act === 'rm') UI.confirmAction('Remove', 'Remove ' + UI.esc(l.full_name) + ' from this payroll?', 'Payroll.line_remove', { line_id: l.id }, { danger: true });
        if (act === 'trips') { const t = Pay.json(l.earnings_json).trips || {};
          UI.modal('Trips of ' + UI.esc(l.full_name), UI.table([['Trip', 'trip_ref'], ['Date', 'date', 'date'], ['Destination', 'destination'], ['As', 'role'], ['Rate', 'rate', 'money']], t.counted || [], { id: 'lt' + l.id }) +
            ((t.missing_waybill || []).length ? `<div class="alert alert-warning mt-2 py-2">Not counted (no waybill): ${t.missing_waybill.map(UI.esc).join(', ')}</div>` : '') +
            ((t.no_rate || []).length ? `<div class="alert alert-warning mt-2 py-2">No trip pay rate for: ${t.no_rate.map(UI.esc).join(', ')}. Add the rate in Payroll > Trip pay rates.</div>` : ''), null, { size: 'modal-lg', onShow: (w) => UI.activate(w) }); }
      } });
    UI.activate(tb);
  },
});

App.page('attendance', {
  title: 'Attendance',
  subtitle: 'Timekeeping (kept separate from payroll disbursement). Payroll counts the "Present" days.',
  render(ctx) {
    const d = ctx.data, e = d.can.edit, from = d.range.from, to = d.range.to;
    const days = []; for (let x = new Date(from + 'T00:00'); x <= new Date(to + 'T00:00'); x.setDate(x.getDate() + 1)) { const y = new Date(x); y.setMinutes(y.getMinutes() - y.getTimezoneOffset()); days.push(y.toISOString().slice(0, 10)); }
    const map = {}; (d.attendance || []).forEach(a => map[a.personnel_id + '|' + a.work_date] = a.status);
    const short = { Present: 'P', Absent: 'A', VL: 'VL', SIL: 'SIL', 'Rest Day': 'RD' };
    ctx.el.innerHTML = `<form class="d-flex gap-2 align-items-end flex-wrap mb-3" id="atR">${UI.field({ name: 'from', label: 'From', type: 'date', value: from, col: 'auto' })}${UI.field({ name: 'to', label: 'To', type: 'date', value: to, col: 'auto' })}
      <button class="btn btn-sm btn-outline-primary">Show</button>${e ? '<button type="button" class="btn btn-sm btn-success ms-auto" id="atSave"><i class="bi bi-save"></i> Save attendance</button>' : ''}</form>
      <div class="card"><div class="table-wrap" style="max-height:640px"><table class="data-table"><thead><tr><th>Employee</th><th>Group</th>${days.map(x => `<th class="text-center">${new Date(x + 'T00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' })}
        ${e ? `<br><button type="button" class="btn btn-link btn-sm p-0" data-allp="${x}">all P</button>` : ''}</th>`).join('')}<th>Present</th></tr></thead><tbody>
      ${(d.people || []).map(p => `<tr><td>${UI.esc(p.full_name)} <span class="text-muted small">${UI.esc(p.person_type)}</span></td><td class="small">${UI.esc(p.payroll_group || '')}</td>${days.map(x => {
        const v = map[p.id + '|' + x] || '';
        return `<td>${e ? `<select class="form-select form-select-sm" data-p="${p.id}" data-d="${x}" style="min-width:70px"><option value=""></option>${d.statuses.map(s => `<option value="${s}" ${s === v ? 'selected' : ''}>${short[s]}</option>`).join('')}</select>` : (v ? UI.badge(short[v]) : '')}</td>`;
      }).join('')}<td class="num">${days.filter(x => map[p.id + '|' + x] === 'Present').length}</td></tr>`).join('')}</tbody></table></div></div>
      <p class="small text-muted mt-2">P = Present, A = Absent, VL = vacation leave, SIL = service incentive leave, RD = rest day. Like the Excel payroll, only Present days are paid as regular days.</p>`;
    document.getElementById('atR').onsubmit = (ev) => { ev.preventDefault(); ctx.go('attendance', UI.formData(ev.target)); };
    if (!e) return;
    ctx.el.querySelectorAll('[data-allp]').forEach(b => b.onclick = () => ctx.el.querySelectorAll(`select[data-d="${b.dataset.allp}"]`).forEach(s => { if (!s.value) s.value = 'Present'; }));
    document.getElementById('atSave').onclick = async () => {
      const rows = [...ctx.el.querySelectorAll('select[data-p]')].filter(s => s.value !== (map[s.dataset.p + '|' + s.dataset.d] || '')).map(s => ({ personnel_id: s.dataset.p, work_date: s.dataset.d, status: s.value }));
      if (!rows.length) return UI.toast('No changes to save.');
      try { UI.result(await App.api('Payroll.attendance_save', { rows })); ctx.reload(); } catch (x) { UI.toast(x.message, 'error'); }
    };
  },
});

App.page('balances', {
  title: 'Cash Advance & Balances',
  subtitle: 'Cash advances, uniform and damages (paid by installment in payroll) and cash bond / savings.',
  render(ctx) {
    const d = ctx.data, e = d.can.edit;
    if (e) {
      ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="addBal"><i class="bi bi-plus-lg"></i> Add balance</button>';
      document.getElementById('addBal').onclick = () => UI.formModal('Add balance', [
        { name: 'personnel_id', label: 'Employee', type: 'select', required: true, options: UI.opts(d.people, p => p.full_name), col: 6 },
        { name: 'ledger_type', label: 'Type', type: 'select', required: true, options: UI.list(d.types), col: 6 },
        { name: 'description', label: 'Description (e.g. uniform set, damage of unit VIN...)', required: true, col: 12 },
        { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true, col: 6 }, { name: 'installment', label: 'Deduct per payroll (PHP)', type: 'money', value: 500, col: 6, help: 'UNIFORM sheet: 500 / salary. Not used for Cash Bond.' }],
        'Payroll.ledger_add', { intro: 'New cash advances are released by Finance (Finance & Payables). Use "Cash Advance" here only for opening balances.' });
    }
    const L = d.ledgers || [];
    const tot = (t) => L.filter(l => l.ledger_type === t && l.status === 'Open').reduce((s, l) => s + +l.balance, 0);
    ctx.el.innerHTML = UI.cards(d.types.map((t, i) => ({ label: t + (t === 'Cash Bond' ? ' (held for employees)' : ' outstanding'), value: tot(t), format: 'money', icon: ['bi-cash-coin', 'bi-person-badge', 'bi-exclamation-triangle', 'bi-piggy-bank'][i], color: t === 'Cash Bond' ? 'green' : 'amber' })));
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = UI.table([['Employee', 'full_name'], ['Group', 'payroll_group'], ['Type', 'ledger_type', 'badge'], ['Description', 'description'], ['Total', 'total_amount', 'money'], ['Per payroll', 'installment', 'money'], ['Balance', 'balance', 'money'], ['Status', 'status', 'badge']], L, {
      id: 'led', actions: (l) => UI.btn('hist', 'History', 'outline-secondary') + (e && l.status === 'Open' ? UI.btn('pay', l.ledger_type === 'Cash Bond' ? 'Release' : 'Payment', 'outline-primary') + (l.ledger_type !== 'Cash Bond' ? UI.btn('inst', 'Installment', 'outline-primary') : '') : ''),
      onAction(act, l) {
        if (act === 'hist') UI.modal('History — ' + UI.esc(l.full_name) + ' / ' + UI.esc(l.ledger_type), UI.table([['Date', 'created_at', 'datetime'], ['Entry', 'entry_type', 'badge'], ['Amount', 'amount', 'money'], ['Note', 'note']], (d.entries || []).filter(x => +x.ledger_id === +l.id), { id: 'le' + l.id }), null, { size: 'modal-lg', onShow: (w) => UI.activate(w) });
        if (act === 'pay') UI.formModal((l.ledger_type === 'Cash Bond' ? 'Release cash bond' : 'Payment outside payroll') + ' — ' + UI.esc(l.full_name), [{ name: 'ledger_id', type: 'hidden', value: l.id },
          { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true, col: 6 }, { name: 'note', label: 'Note', required: true, col: 6 }], 'Payroll.ledger_payment', { size: '', intro: 'Balance: ' + UI.money(l.balance) });
        if (act === 'inst') UI.formModal('Installment per payroll', [{ name: 'ledger_id', type: 'hidden', value: l.id }, { name: 'installment', label: 'Amount per payroll (PHP)', type: 'money', required: true, value: l.installment, col: 12 }], 'Payroll.ledger_installment', { size: '' });
      } });
    UI.activate(box);
  },
});
