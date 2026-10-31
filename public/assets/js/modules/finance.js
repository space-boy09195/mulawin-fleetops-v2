/* assets/js/modules/finance.js — Trip Funds & Allowances, Finance & Payables
   (FMS PDF II.I and pp.13, 16-17). Backend: Modules/Funds.php, Modules/Finance.php */
'use strict';

/** Payment fields used for every money release (cash / cheque / transfer). */
const payFields = (extra = []) => [
  { name: 'payment_method', label: 'Payment method', type: 'select', required: true, options: UI.list(['Cash', 'Cheque', 'Bank Transfer', 'E-wallet', 'Other']), col: 4 },
  { name: 'released_on', label: 'Date released', type: 'date', required: true, value: UI.today(), col: 4 },
  { name: 'reference_number', label: 'Reference no.', col: 4 },
  { name: 'cheque_number', label: 'Cheque no. (if cheque)', col: 6 }, { name: 'bank_name', label: 'Bank', col: 6 }, ...extra];

App.page('funds', {
  title: 'Trip Funds & Allowances',
  subtitle: 'System-computed allowance → approval (Ops Head ≤ limit, Admin > limit) → Finance processing → release after checklist → completed at dispatch → liquidation.',
  render(ctx) {
    const d = ctx.data, c = d.can, fs = d.funds || [];
    const role = App.me.user.role;
    const canApprove = (f) => f.status === 'Pending Approval' && f.approver_role === role;
    const mineTeam = (f) => UI.role('operations_head') || f.dispatcher_role === role;
    const actions = (f) => {
      const b = [UI.btn('view', 'Breakdown', 'outline-secondary', 'bi-calculator')];
      if (canApprove(f)) b.push(UI.btn('approve', 'Approve', 'success'), UI.btn('reject', 'Reject', 'outline-danger'));
      if (c.adjust && f.status === 'Pending Approval' && mineTeam(f)) b.push(UI.btn('adjust', 'Adjust amount', 'outline-primary'));
      if (c.adjust && f.status === 'Rejected' && mineTeam(f) && ['Assigned', 'Ready for Dispatch'].includes(f.trip_status)) b.push(UI.btn('resubmit', 'Re-submit', 'outline-primary'));
      if (c.process && f.status === 'Approved') b.push(UI.btn('process', 'Process', 'primary'));
      if (c.process && ['Approved', 'Pending Release', 'Ready for Release'].includes(f.status)) b.push(UI.btn('reject', 'Reject', 'outline-danger'));
      if (c.release && f.status === 'Ready for Release') b.push(UI.btn('release', 'Release fund', 'success', 'bi-cash'));
      if (c.liquidate && ['Released', 'Completed'].includes(f.status) && f.liquidation_status !== 'Liquidated') b.push(UI.btn('liq', 'Liquidate', 'outline-warning'));
      return b.join('');
    };
    const onAction = (act, f) => {
      const bd = (() => { try { return JSON.parse(f.breakdown_json || '{}'); } catch { return {}; } })();
      if (act === 'view') return UI.modal('Fund computation — ' + UI.esc(f.trip_ref), `<p class="small">${UI.esc(f.client)} · ${UI.esc(f.origin)} → ${UI.esc(f.destination)} · ${UI.num(f.distance_km, 1)} km · ${UI.esc(f.plate_number || '')}</p>
        ${UI.breakdown(bd.lines, f.amount, 'Trip allowance')}
        ${bd.adjust_reason ? `<p class="small mt-2"><b>Adjustment reason:</b> ${UI.esc(bd.adjust_reason)}</p>` : ''}
        <p class="small text-muted mt-2">Values used: ${Object.entries(bd.formula?.values || {}).map(([k, v]) => UI.esc(k) + ' = ' + UI.esc(v)).join(', ')} (saved with this request; later formula changes do not change it).</p>
        <table class="table table-sm small">${[['Approver', UI.label(f.approver_role)], ['Approved by', f.approved_by_name], ['Processed by', f.processed_by_name], ['Released by', f.released_by_name], ['Release method / ref.', [f.release_method, f.release_reference].filter(Boolean).join(' / ')],
          ['Liquidation', f.liquidation_status + (f.liquidated_amount ? ': spent ' + UI.money(f.liquidated_amount) + ', returned ' + UI.money(f.returned_amount) : '')], ['Rejected reason', f.rejected_reason]].filter(x => x[1]).map(([k, v]) => `<tr><th>${k}</th><td>${UI.esc(v)}</td></tr>`).join('')}</table>`, null, { size: '' });
      if (act === 'approve') return UI.confirmAction('Approve fund request', `Approve ${UI.money(f.amount)} for ${UI.esc(f.trip_ref)}?`, 'Funds.approve', { fund_id: f.id });
      if (act === 'reject') return UI.confirmAction('Reject fund request', `Reject the fund request of ${UI.esc(f.trip_ref)}?`, 'Funds.reject', { fund_id: f.id }, { danger: true, submit: 'Reject', fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
      if (act === 'process') return UI.confirmAction('Process fund', `Process ${UI.money(f.amount)} for ${UI.esc(f.trip_ref)}? It becomes "Pending Release" until the dispatcher finishes the checklist.`, 'Funds.process', { fund_id: f.id });
      if (act === 'resubmit') return UI.confirmAction('Re-submit', 'Compute a new fund request for this trip?', 'Funds.resubmit', { fund_id: f.id });
      if (act === 'release') return UI.formModal('Release fund — ' + UI.esc(f.trip_ref), [{ name: 'fund_id', type: 'hidden', value: f.id }, ...payFields()], 'Funds.release', { intro: `Amount: <b>${UI.money(f.amount)}</b>. A payment voucher, disbursement and Trip Allowance expense are recorded.` });
      if (act === 'liq') return UI.formModal('Liquidation — ' + UI.esc(f.trip_ref), [{ name: 'fund_id', type: 'hidden', value: f.id },
        { name: 'liquidated_amount', label: 'Actual amount spent (PHP)', type: 'money', required: true, col: 6 }, { name: 'liquidation_notes', label: 'Notes', col: 6 }], 'Funds.liquidate', { size: '', intro: `Released: ${UI.money(f.amount)}. The difference is recorded as returned cash. Attach receipts in Documents (record type "fund").` });
      if (act === 'adjust') {
        const sys = (bd.lines || []).filter(l => l.calc !== 'adjustment'), adj = (bd.lines || []).filter(l => l.calc === 'adjustment');
        const rows = [0, 1, 2, 3].map(i => `<div class="row g-2 mb-1"><div class="col-8"><input class="form-control form-control-sm" name="ln${i}" placeholder="Item (e.g. Meal allowance, Extra toll)" value="${UI.esc(adj[i]?.name || '')}"></div>
          <div class="col-4"><input class="form-control form-control-sm" type="number" step="0.01" name="la${i}" placeholder="Amount (− to reduce)" value="${adj[i]?.amount ?? ''}"></div></div>`).join('');
        return UI.modal('Adjust fund — ' + UI.esc(f.trip_ref), `<p class="small">System computation (kept):</p>${UI.breakdown(sys, sys.reduce((s, l) => s + +l.amount, 0), 'System total')}
          <p class="small mt-3 mb-1">Extra lines:</p>${rows}${UI.fields([{ name: 'reason', label: 'Reason for adjustment', required: true, col: 12 }])}`, async (x) => {
          const lines = [0, 1, 2, 3].map(i => ({ name: x['ln' + i], amount: x['la' + i] })).filter(l => l.name);
          UI.result(await App.api('Funds.adjust', { fund_id: f.id, reason: x.reason, lines })); App.route();
        });
      }
    };
    const cnt = (s) => fs.filter(f => f.status === s).length;
    ctx.el.innerHTML = UI.cards([
      { label: 'Pending approval', value: cnt('Pending Approval'), icon: 'bi-hourglass-split', color: 'amber', hint: 'Ops Head ≤ ' + UI.money(d.threshold) + ', Admin above' },
      { label: 'Approved (Finance to process)', value: cnt('Approved'), icon: 'bi-check2-square', color: 'blue' },
      { label: 'Pending release (checklist)', value: cnt('Pending Release'), icon: 'bi-clipboard-check', color: 'blue' },
      { label: 'Ready for release', value: cnt('Ready for Release'), icon: 'bi-cash-stack', color: 'green' },
      { label: 'Not yet liquidated', value: fs.filter(f => ['Released', 'Completed'].includes(f.status) && f.liquidation_status !== 'Liquidated').length, icon: 'bi-clipboard-x', color: 'amber' },
    ]);
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = UI.table([['Trip', 'trip_ref'], ['Client', 'client'], ['Route', 'destination', (x, r) => UI.esc(r.origin) + ' → ' + UI.esc(x)], ['Truck', 'plate_number'],
      ['Trip status', 'trip_status', 'badge'], ['Amount', 'amount', 'money'], ['Approver', 'approver_role', (x) => UI.esc(UI.label(x))], ['Fund status', 'status', 'badge'],
      ['Released', 'released_at', 'datetime'], ['Liquidation', 'liquidation_status', 'badge'], ['Requested', 'created_at', 'datetime']], fs, { id: 'funds', title: 'Fund requests', actions, onAction, empty: 'Fund requests are created automatically when a trip is assigned.' });
    UI.activate(box);
  },
});

App.page('finance', {
  title: 'Finance & Payables',
  subtitle: 'Expenses, accounts payable, payment vouchers, cheques, disbursements, cash advances, budgets, payroll disbursement and cash flow.',
  render(ctx) {
    const d = ctx.data, e = d.can.edit, q = { ...d.range };
    if (e) {
      ctx.tools.innerHTML = `<div class="btn-group"><button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-plus-lg"></i> Record</button><ul class="dropdown-menu dropdown-menu-end">
        <li><button class="dropdown-item" data-new="exp">Expense</button></li><li><button class="dropdown-item" data-new="ap">Account payable</button></li>
        <li><button class="dropdown-item" data-new="ca">Cash advance (employee)</button></li><li><button class="dropdown-item" data-new="bud">Budget</button></li></ul></div>`;
      ctx.tools.querySelectorAll('[data-new]').forEach(b => b.onclick = () => ({
        exp: () => UI.formModal('Record expense', [
          { name: 'category', label: 'Category', type: 'select', required: true, options: UI.list(d.categories), col: 4 }, { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true, col: 4 },
          { name: 'incurred_on', label: 'Date', type: 'date', required: true, value: UI.today(), col: 4 }, { name: 'payee', label: 'Payee', col: 6 }, { name: 'reference_number', label: 'OR / reference no.', col: 6 },
          { name: 'trip_id', label: 'Trip (if for a trip)', type: 'select', options: UI.opts(d.trips, t => t.trip_ref), col: 6, placeholder: 'None' },
          { name: 'vehicle_id', label: 'Truck (if for a truck)', type: 'select', options: UI.opts(d.vehicles, v => v.plate_number), col: 6, placeholder: 'None' },
          { name: 'description', label: 'Description', type: 'textarea', rows: 2, col: 12 },
          { name: 'paid_now', label: 'Paid now (record the disbursement and voucher)', type: 'checkbox', col: 12 }, { ...payFields()[0], required: false, label: 'Payment method (if paid now)' }, ...payFields().slice(2)], 'Finance.expense_add',
          { transform: (x) => { if (!+x.paid_now) { delete x.payment_method; } else if (!x.payment_method) throw new Error('Choose the payment method.'); return x; } }),
        ap: () => UI.formModal('Record account payable', [
          { name: 'supplier_id', label: 'Supplier', type: 'select', options: UI.opts(d.suppliers, s => s.name), col: 6, placeholder: 'Other payee' }, { name: 'payee', label: 'Other payee', col: 6 },
          { name: 'description', label: 'Description', required: true, col: 12 }, { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true, col: 4 },
          { name: 'reference_number', label: 'Invoice / reference no.', col: 4 }, { name: 'due_on', label: 'Due date', type: 'date', col: 4 }], 'Finance.payable_add'),
        ca: () => UI.formModal('Cash advance', [
          { name: 'personnel_id', label: 'Employee', type: 'select', required: true, options: UI.opts(d.personnel, p => `${p.full_name} (${p.person_type})`), col: 6 },
          { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true, col: 3 }, { name: 'installment', label: 'Deduct per payroll (PHP)', type: 'money', required: true, col: 3 },
          { name: 'purpose', label: 'Purpose', required: true, col: 12 }, ...payFields()], 'Finance.cash_advance', { intro: 'Payroll deducts the installment every payroll ("AR EMP - CA") until the balance is zero.' }),
        bud: () => UI.formModal('Set budget', [
          { name: 'category', label: 'Budget for', type: 'select', required: true, options: UI.list(['Purchasing', 'Operations', 'Maintenance']), col: 4 },
          { name: 'budget_amount', label: 'Amount (PHP)', type: 'money', required: true, col: 4 }, { name: 'period_start', label: 'Period start', type: 'date', col: 2 }, { name: 'period_end', label: 'Period end', type: 'date', col: 2 }],
          'Finance.budget_set', { intro: 'Purchase approvals are checked against the active Purchasing budget (PDF p.17). A new budget closes the old active one of the same category.' }),
      })[b.dataset.new]());
    }
    const sum = (rows, k) => (rows || []).reduce((s, r) => s + +r[k], 0);
    const ap = (d.payables || []).filter(a => a.status !== 'Paid');
    ctx.el.innerHTML = `<form class="d-flex gap-2 align-items-end mb-3 flex-wrap" id="finRange">${UI.field({ name: 'from', label: 'From', type: 'date', value: q.from, col: 'auto' })}${UI.field({ name: 'to', label: 'To', type: 'date', value: q.to, col: 'auto' })}<button class="btn btn-sm btn-outline-primary">Apply</button></form>` + UI.cards([
      { label: 'Expenses (period)', value: sum(d.expenses, 'amount'), format: 'money', icon: 'bi-wallet2', color: 'red' },
      { label: 'Disbursements (period)', value: sum(d.disbursements, 'amount'), format: 'money', icon: 'bi-box-arrow-up-right', color: 'blue' },
      { label: 'Accounts payable balance', value: ap.reduce((s, a) => s + (+a.amount - +a.paid_amount), 0), format: 'money', icon: 'bi-journal-minus', color: 'amber', hint: ap.length + ' open' },
      { label: 'Cash advances outstanding', value: sum((d.cashAdvances || []).filter(x => x.status === 'Open'), 'balance'), format: 'money', icon: 'bi-person-lines-fill', color: 'amber' },
      { label: 'Payroll to disburse', value: (d.payrollToPay || []).filter(p => p.status === 'Submitted to Finance').length, icon: 'bi-cash-stack', color: 'blue' },
    ]);
    document.getElementById('finRange').onsubmit = (ev) => { ev.preventDefault(); ctx.go('finance', UI.formData(ev.target)); };
    const cf = d.cashFlow || [];
    UI.tabs(ctx.el, [
      ['exp', 'Expenses', () => UI.table([['Date', 'incurred_on', 'date'], ['Category', 'category', 'badge'], ['Amount', 'amount', 'money'], ['Payee', 'payee'], ['Reference', 'reference_number'], ['Trip', 'trip_ref'], ['Truck', 'plate_number'], ['Source', 'source'], ['Description', 'description'], ['By', 'recorded_by_name']], d.expenses, { id: 'exp', title: 'Expenses (fuel, payroll, maintenance, parts and other operating expenses)' })],
      ['ap', 'Accounts payable', () => UI.table([['Supplier / payee', 'supplier', (x, r) => UI.esc(x || r.payee)], ['Description', 'description'], ['Reference', 'reference_number'], ['Amount', 'amount', 'money'], ['Paid', 'paid_amount', 'money'],
        ['Balance', 'amount', (x, r) => UI.money(+r.amount - +r.paid_amount)], ['Due', 'due_on', 'date'], ['Days to due', 'days_to_due', (x, r) => r.status !== 'Paid' && x !== null ? UI.days(x) : '—'], ['Status', 'status', 'badge'], ['Source', 'source']], d.payables, {
        id: 'ap', actions: e ? (a) => a.status !== 'Paid' ? UI.btn('pay', 'Pay', 'success') : '' : null,
        onAction: (act, a) => UI.formModal('Pay ' + UI.esc(a.description), [{ name: 'payable_id', type: 'hidden', value: a.id },
          { name: 'amount', label: 'Amount to pay (PHP)', type: 'money', required: true, value: (+a.amount - +a.paid_amount).toFixed(2), col: 12 }, ...payFields()], 'Finance.payable_pay') })],
      ['disb', 'Disbursements and cheques', () => UI.table([['Date', 'released_on', 'date'], ['Voucher', 'voucher_number'], ['For', 'source_type', 'badge'], ['Payee', 'payee'], ['Method', 'payment_method'], ['Cheque no.', 'cheque_number'], ['Bank', 'bank_name'], ['Reference', 'reference_number'], ['Amount', 'amount', 'money'], ['By', 'recorded_by_name']], d.disbursements, { id: 'disb' })],
      ['pv', 'Payment vouchers', () => UI.table([['Voucher', 'voucher_number'], ['Date', 'created_at', 'datetime'], ['Type', 'reference_type'], ['Payee', 'payee'], ['Amount', 'amount', 'money'], ['Status', 'status', 'badge'], ['Prepared by', 'created_by_name']], d.vouchers, {
        id: 'pv', actions: () => UI.btn('print', 'Print', 'outline-secondary', 'bi-printer'),
        onAction: (a, v) => UI.print(`<div class="payslip"><h6>${UI.esc(App.me.company.name)}</h6><div class="addr">${UI.esc(App.me.company.address)}</div><h6 class="my-2">PAYMENT VOUCHER ${UI.esc(v.voucher_number)}</h6>
          <table><tr><td>Date</td><td>${UI.date(v.created_at)}</td></tr><tr><td>Payee</td><td>${UI.esc(v.payee)}</td></tr><tr><td>For</td><td>${UI.esc(v.reference_type)} #${UI.esc(v.reference_id)}</td></tr><tr><td>Amount</td><td><b>${UI.money(v.amount)}</b></td></tr></table>
          <div class="d-flex justify-content-between mt-4"><div class="sig">Prepared by: ${UI.esc(v.created_by_name)}</div><div class="sig">Approved by</div><div class="sig">Received by</div></div></div>`) })],
      ['ca', 'Cash advances', () => UI.table([['Employee', 'full_name'], ['Purpose', 'description'], ['Amount', 'total_amount', 'money'], ['Per payroll', 'installment', 'money'], ['Balance', 'balance', 'money'], ['Status', 'status', 'badge'], ['Date', 'created_at', 'datetime']], d.cashAdvances, { id: 'cas' })],
      ['pay', 'Payroll disbursement', () => UI.table([['Payroll', 'period_name'], ['Group', 'payroll_group'], ['From', 'date_from', 'date'], ['To', 'date_to', 'date'], ['Employees', 'employees'], ['Total net pay', 'total_net', 'money'], ['Status', 'status', 'badge'], ['Disbursed', 'disbursed_at', 'datetime']], d.payrollToPay, {
        id: 'pdis', actions: e ? (p) => p.status === 'Submitted to Finance' ? UI.btn('disb', 'Disburse', 'success') : '' : null,
        onAction: (a, p) => UI.formModal('Disburse payroll ' + UI.esc(p.period_name), [{ name: 'period_id', type: 'hidden', value: p.id }, ...payFields()], 'Finance.payroll_disburse', { intro: 'Total net pay: <b>' + UI.money(p.total_net) + '</b>. Recorded as a Payroll expense.' }) })],
      ['bud', 'Budgets', () => UI.table([['Category', 'category'], ['Budget', 'budget_amount', 'money'], ['Used', 'used_amount', 'money'], ['Remaining', 'budget_amount', (x, r) => UI.money(+r.budget_amount - +r.used_amount)], ['Period', 'period_start', (x, r) => x ? UI.date(x) + ' – ' + UI.date(r.period_end) : '—'], ['Status', 'status', 'badge'], ['Set by', 'set_by_name']], d.budgets, { id: 'buds' })],
      ['cf', 'Cash flow', () => UI.chartHtml({ title: 'Cash in vs cash out per month', type: 'bar', labels: cf.map(r => r.month), datasets: [{ label: 'Cash in (collections)', data: cf.map(r => r.cash_in) }, { label: 'Cash out (disbursements)', data: cf.map(r => r.cash_out) }], how: 'Cash in = collections recorded by Billing; cash out = disbursements recorded by Finance.' }) +
        UI.table([['Month', 'month'], ['Cash in', 'cash_in', 'money'], ['Cash out', 'cash_out', 'money'], ['Net', 'net', 'money'], ['Running total', 'running', 'money']], cf, { id: 'cft', title: 'Cash flow summary' })],
    ]);
  },
});
