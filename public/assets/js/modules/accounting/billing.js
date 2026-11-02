/* assets/js/modules/accounting/billing.js — Billing & Collections, Clients, Routes & Rates
   (FMS PDF II.H). Backend: backend/Modules/Accounting/Billing.php */
'use strict';

App.page('billing', {
  title: 'Billing & Collections',
  subtitle: 'Billing drafts (made by the system when a trip is ongoing), finalized billings, invoices (one invoice can cover many trips), collections and outstanding balances.',
  render(ctx) {
    const d = ctx.data, e = d.can.edit, inv = d.invoices || [];
    const bal = (b) => b.status === 'Cancelled' ? 0 : +b.net_amount - +b.collected;
    const drafts = d.drafts || [];
    const fin = drafts.filter(x => x.status === 'Finalized');
    // Bagong PDF v7: pwedeng i-finalize kapag Completed na ang trip AT verified na ang documents.
    const blocker = (x) => x.trip_status !== 'Completed' ? 'Trip is ' + x.trip_status + ' (needs Completed)'
      : !x.docs_verified_at ? 'Verify the documents first' : !(+x.amount > 0) ? 'Enter the rate first (Edit)' : '';
    const editDraft = (x) => UI.formModal('Edit billing draft — ' + UI.esc(x.trip_ref), [{ name: 'draft_id', type: 'hidden', value: x.id },
      { name: 'rate_basis', label: 'Basis', type: 'select', required: true, options: UI.list(['Per Trip', 'Per Unit', 'Per KM']), value: x.rate_basis || 'Per Trip', placeholder: false, col: 4 },
      { name: 'quantity', label: 'Quantity', type: 'number', step: '0.01', required: true, value: x.quantity, col: 4, help: x.units_delivered ? 'Units delivered: ' + x.units_delivered : (x.distance_km ? 'Route km: ' + x.distance_km : '') },
      { name: 'rate', label: 'Rate (PHP)', type: 'money', required: true, value: x.rate ?? '', col: 4, help: x.rate === null ? 'No client rate for this route yet.' : 'From the client rate of the route.' },
      { name: 'description', label: 'Description', value: x.description, col: 12 }], 'Billing.draft_update', { intro: 'Amount = rate × quantity.' });
    const newInvoice = (clientId) => {
      const lines = fin.filter(x => +x.client_id === +clientId);
      const client = (d.clients || []).find(c => +c.id === +clientId);
      const rows = lines.map(x => `<tr><td><input type="checkbox" name="pick_${x.id}" value="1" checked></td><td>${UI.esc(x.trip_ref)}</td><td>${UI.esc(x.origin)} → ${UI.esc(x.destination)}</td>
        <td>${UI.date(x.completed_at)}</td><td>${UI.esc(x.waybills || '—')}</td><td>${UI.esc(x.rate_basis)}</td><td class="num">${UI.num(x.quantity, 2)}</td><td class="num">${UI.money(x.rate)}</td><td class="num">${UI.money(x.amount)}</td></tr>`).join('');
      UI.modal('New invoice — ' + UI.esc(client?.name || ''), UI.fields([
        { name: 'invoice_number', label: 'Invoice no.', required: true, col: 4 }, { name: 'invoice_date', label: 'Invoice date', type: 'date', required: true, value: UI.today(), col: 4 },
        { name: 'due_date', label: 'Due date', type: 'date', col: 4, help: 'Blank = invoice date + ' + (client?.payment_terms_days ?? d.defaultTerms) + ' days (payment terms)' },
        { name: 'discount', label: 'Discount (PHP)', type: 'money', value: 0, col: 4 }, { name: 'notes', label: 'Notes', col: 8 }]) +
        `<h6 class="mt-3">Finalized billings of this client</h6><div class="table-wrap"><table class="data-table"><thead><tr><th></th><th>Trip</th><th>Route</th><th>Completed</th><th>Waybill</th><th>Basis</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead><tbody>${rows}</tbody></table></div>
         <p class="small text-muted mt-2">The amounts come from the finalized billings. To change one, return it to Draft first.</p>`,
        async (x) => {
          const ids = lines.filter(l => +x['pick_' + l.id]).map(l => l.id);
          if (!ids.length) throw new Error('Choose at least one billing.');
          UI.result(await App.api('Billing.invoice_create', { client_id: clientId, invoice_number: x.invoice_number, invoice_date: x.invoice_date, due_date: x.due_date, discount: x.discount, notes: x.notes, draft_ids: ids }));
          App.route();
        }, { size: 'modal-xl', submit: 'Issue invoice' });
    };
    const printInvoice = (b) => {
      const items = (d.items || []).filter(i => +i.billing_id === +b.id);
      UI.print(`<div class="payslip"><h6>${UI.esc(App.me.company.name)}</h6><div class="addr">${UI.esc(App.me.company.address)}</div>
        <h6 class="my-2">BILLING STATEMENT / INVOICE ${UI.esc(b.invoice_number)}</h6>
        <table><tr><td>Client</td><td><b>${UI.esc(b.client)}</b>${b.company_name ? ' / ' + UI.esc(b.company_name) : ''}</td><td>Date</td><td>${UI.date(b.invoice_date)}</td></tr><tr><td></td><td></td><td>Due</td><td>${UI.date(b.due_date)}</td></tr></table>
        <table class="mt-2" border="1"><tr><th>Trip</th><th>Description</th><th>Waybill</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>
        ${items.map(i => `<tr><td>${UI.esc(i.trip_ref)}</td><td>${UI.esc(i.description)}</td><td>${UI.esc(i.waybills || '')}</td><td>${i.quantity}</td><td>${UI.money(i.rate)}</td><td>${UI.money(i.amount)}</td></tr>`).join('')}
        <tr><td colspan="5">Gross</td><td>${UI.money(b.gross_amount)}</td></tr><tr><td colspan="5">Less discount</td><td>${UI.money(b.discount)}</td></tr><tr><td colspan="5"><b>Total due</b></td><td><b>${UI.money(b.net_amount)}</b></td></tr></table>
        <div class="d-flex justify-content-between mt-4"><div class="sig">Prepared by</div><div class="sig">Received by</div></div></div>`);
    };
    // Finalized billings grouped by client (handa nang i-invoice)
    const groups = {};
    fin.forEach(x => { (groups[x.client_id] = groups[x.client_id] || { client_id: x.client_id, client: x.client, trips: 0, total: 0 }); groups[x.client_id].trips++; groups[x.client_id].total += +(x.amount || 0); });
    const byClient = {};
    inv.filter(b => b.status !== 'Cancelled').forEach(b => { const k = b.client; byClient[k] = byClient[k] || { client: k, billed: 0, collected: 0, outstanding: 0, overdue: 0 };
      byClient[k].billed += +b.net_amount; byClient[k].collected += +b.collected; byClient[k].outstanding += bal(b); if (+b.days_overdue > 0) byClient[k].overdue += bal(b); });
    ctx.el.innerHTML = UI.cards([
      { label: 'Billing drafts (trip ongoing)', value: drafts.filter(x => x.status === 'Draft' && x.trip_status !== 'Completed').length, icon: 'bi-pencil-square', color: 'blue' },
      { label: 'Completed trips awaiting billing', value: drafts.filter(x => x.trip_status === 'Completed').length, icon: 'bi-hourglass-split', color: 'amber' },
      { label: 'Finalized, ready to invoice', value: fin.length, icon: 'bi-check2-square', color: 'green' },
      { label: 'Invoices issued', value: inv.filter(b => b.status !== 'Cancelled').length, icon: 'bi-receipt', color: 'blue' },
      { label: 'Total billed', value: inv.filter(b => b.status !== 'Cancelled').reduce((s, b) => s + +b.net_amount, 0), format: 'money', icon: 'bi-cash', color: 'blue' },
      { label: 'Collections received', value: (d.collections || []).reduce((s, c) => s + +c.amount, 0), format: 'money', icon: 'bi-cash-coin', color: 'green' },
      { label: 'Outstanding balance', value: inv.reduce((s, b) => s + bal(b), 0), format: 'money', icon: 'bi-exclamation-circle', color: 'amber' },
      { label: 'Overdue', value: inv.filter(b => +b.days_overdue > 0).reduce((s, b) => s + bal(b), 0), format: 'money', icon: 'bi-alarm', color: 'red' },
    ]);
    UI.tabs(ctx.el, [
      ['drafts', 'Billing drafts', () => `<div class="alert alert-info small">The system makes a billing draft when a trip is dispatched (ongoing), using the client rate of the route.
        <b>Finalize</b> is allowed only when the trip is <b>Completed</b> and its documents (waybill) are <b>verified</b>.</div>` +
        UI.table([['Trip', 'trip_ref'], ['Client', 'client'], ['Trip status', 'trip_status', 'badge'], ['Route', 'destination', (x, r) => UI.esc(r.origin) + ' → ' + UI.esc(x)], ['Truck', 'plate_number'],
          ['Waybill', 'waybills', (x) => x ? UI.esc(x) : '<span class="badge-s red">none yet</span>'], ['Documents', 'docs_verified_at', (x, r) => x ? `<span class="badge-s green">Verified</span> <span class="small text-muted">${UI.esc(r.verified_by_name || '')}</span>` : '<span class="badge-s amber">Not verified</span>'],
          ['Basis', 'rate_basis'], ['Qty', 'quantity', 'num'], ['Rate', 'rate', (x) => x === null ? '<span class="badge-s red">no rate</span>' : UI.money(x)], ['Amount', 'amount', 'money'], ['Billing status', 'status', 'badge']], drafts, {
          id: 'drafts', title: 'Billing drafts and finalized billings', empty: 'No billing drafts yet. They appear when a trip is dispatched.',
          actions: e ? (x) => x.status === 'Draft'
            ? UI.btn('edit', 'Edit', 'outline-primary') + (!x.docs_verified_at && +x.waybill_count > 0 ? UI.btn('verify', 'Verify documents', 'outline-success') : '')
              + (blocker(x) ? `<button class="btn btn-sm btn-outline-secondary" disabled title="${UI.esc(blocker(x))}">Finalize</button>` : UI.btn('final', 'Finalize', 'success'))
            : UI.btn('reopen', 'Return to draft', 'outline-danger') : null,
          onAction(act, x) {
            if (act === 'edit') editDraft(x);
            if (act === 'verify') UI.confirmAction('Verify documents', `Waybill of <b>${UI.esc(x.trip_ref)}</b>: ${UI.esc(x.waybills)}. Mark the required documents as verified?`, 'Billing.draft_verify', { draft_id: x.id },
              { submit: 'Verify', fields: [{ name: 'docs_note', label: 'Note (optional)', col: 12 }] });
            if (act === 'final') UI.confirmAction('Finalize billing', `Finalize <b>${UI.esc(x.trip_ref)}</b> for ${UI.money(x.amount)}? It will be ready for the invoice.`, 'Billing.draft_finalize', { draft_id: x.id }, { submit: 'Finalize' });
            if (act === 'reopen') UI.confirmAction('Return to draft', `Return <b>${UI.esc(x.trip_ref)}</b> to Draft so it can be corrected?`, 'Billing.draft_reopen', { draft_id: x.id },
              { danger: true, fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
          } })],
      ['ready', 'Ready to invoice', () => UI.table([['Client', 'client'], ['Finalized trips', 'trips'], ['Total amount', 'total', 'money']], Object.values(groups), {
        id: 'ub', title: 'Finalized billings per client', empty: 'No finalized billings yet. Finalize a billing draft first.',
        actions: e ? () => UI.btn('inv', 'Create invoice', 'primary', 'bi-receipt') : null, onAction: (a, g) => newInvoice(g.client_id) })],
      ['inv', 'Invoices', () => UI.table([['Invoice', 'invoice_number'], ['Client', 'client'], ['Date', 'invoice_date', 'date'], ['Due', 'due_date', 'date'], ['Trips', 'trip_count'], ['Net amount', 'net_amount', 'money'],
        ['Collected', 'collected', 'money'], ['Balance', 'net_amount', (x, r) => UI.money(bal(r))], ['Overdue', 'days_overdue', (x, r) => bal(r) > 0 && +x > 0 ? `<span class="badge-s red">${x} days</span>` : '—'], ['Status', 'status', 'badge']], inv, {
        id: 'inv', actions: (b) => UI.btn('items', 'Trips', 'outline-secondary') + UI.btn('print', 'Print', 'outline-secondary', 'bi-printer') +
          (e && bal(b) > 0 ? UI.btn('collect', 'Record payment', 'success') : '') + (e && b.status === 'Issued' && +b.collected === 0 ? UI.btn('cancel', 'Cancel', 'outline-danger') : ''),
        onAction(act, b) {
          if (act === 'items') UI.modal('Trips on ' + UI.esc(b.invoice_number), UI.table([['Trip', 'trip_ref'], ['Description', 'description'], ['Waybill', 'waybills'], ['Basis', 'rate_basis'], ['Qty', 'quantity'], ['Rate', 'rate', 'money'], ['Amount', 'amount', 'money']], (d.items || []).filter(i => +i.billing_id === +b.id), { id: 'ii' + b.id }), null, { size: 'modal-xl', onShow: (w) => UI.activate(w) });
          if (act === 'print') printInvoice(b);
          if (act === 'collect') UI.formModal('Record payment — ' + UI.esc(b.invoice_number), [{ name: 'billing_id', type: 'hidden', value: b.id },
            { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true, value: bal(b).toFixed(2), col: 4 }, { name: 'payment_date', label: 'Payment date', type: 'date', required: true, value: UI.today(), col: 4 },
            { name: 'payment_method', label: 'Method', type: 'select', required: true, options: UI.list(['Cash', 'Cheque', 'Bank Transfer', 'E-wallet', 'Other']), col: 4 },
            { name: 'reference', label: 'OR / reference no.', required: true, col: 4 }, { name: 'cheque_number', label: 'Cheque no.', col: 4 }, { name: 'bank_name', label: 'Bank', col: 4 }], 'Billing.collection_add', { intro: 'Balance: ' + UI.money(bal(b)) + '. Partial payments are allowed.' });
          if (act === 'cancel') UI.confirmAction('Cancel invoice', 'Cancel ' + UI.esc(b.invoice_number) + '? Its billings go back to Finalized and can be invoiced again.', 'Billing.invoice_cancel', { billing_id: b.id }, { danger: true, fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
        } })],
      ['col', 'Collections', () => UI.table([['Date', 'payment_date', 'date'], ['Invoice', 'invoice_number'], ['Client', 'client'], ['Amount', 'amount', 'money'], ['Method', 'payment_method'], ['Reference', 'reference'], ['Cheque', 'cheque_number'], ['Bank', 'bank_name'], ['By', 'recorded_by_name']], d.collections, { id: 'cols', title: 'Collections' })],
      ['ar', 'Receivables by client', () => UI.table([['Client', 'client'], ['Billed', 'billed', 'money'], ['Collected', 'collected', 'money'], ['Outstanding', 'outstanding', 'money'], ['Overdue', 'overdue', 'money']], Object.values(byClient), { id: 'ar', title: 'Billing and collection history by client' })],
    ]);
  },
});

App.page('clients', {
  title: 'Clients, Routes & Rates',
  subtitle: 'Client profiles, routes (km and RFID/toll used by the projected P&L), client-specific route rates and projected P&L per trip.',
  render(ctx) {
    const d = ctx.data, c = d.can;
    const clientForm = (x = {}) => UI.formModal(x.id ? 'Edit client' : 'Add client', [{ name: 'id', type: 'hidden', value: x.id },
      { name: 'name', label: 'Client (brand / consignee)', required: true, value: x.name, col: 6 }, { name: 'company_name', label: 'Company (broker / forwarder)', value: x.company_name, col: 6 },
      { name: 'contact_person', label: 'Contact person', value: x.contact_person, col: 4 }, { name: 'contact_number', label: 'Contact number', value: x.contact_number, col: 4 }, { name: 'email', label: 'Email', value: x.email, col: 4 },
      { name: 'address', label: 'Address', value: x.address, col: 8 }, { name: 'payment_terms_days', label: 'Payment terms (days)', type: 'number', value: x.payment_terms_days, col: 4, help: 'e.g. 15 or 30' },
      ...(x.id ? [{ name: 'inactive', label: 'Inactive', type: 'checkbox', value: +x.active ? 0 : 1 }] : [])], 'Billing.client_save');
    const routeForm = (x = {}) => UI.formModal(x.id ? 'Edit route' : 'Add route', [{ name: 'id', type: 'hidden', value: x.id },
      { name: 'origin', label: 'Origin / pickup point', required: true, value: x.origin, col: 6 }, { name: 'destination', label: 'Destination / delivery point', required: true, value: x.destination, col: 6 },
      { name: 'distance_km', label: 'Total km of the whole trip', type: 'number', step: '0.1', required: true, value: x.distance_km, col: 4, help: 'Billing P&L: KM = 52+67+24' },
      { name: 'toll_fee', label: 'RFID / toll for the whole trip (PHP)', type: 'money', value: x.toll_fee ?? 0, col: 4, help: 'Billing P&L RFID row' },
      { name: 'notes', label: 'Notes', value: x.notes, col: 4 }, ...(x.id ? [{ name: 'inactive', label: 'Inactive', type: 'checkbox', value: +x.active ? 0 : 1 }] : [])], 'Billing.route_save');
    const rateForm = (x = {}) => UI.formModal(x.id ? 'Edit rate' : 'Add client rate', [{ name: 'id', type: 'hidden', value: x.id },
      { name: 'client_id', label: 'Client', type: 'select', required: true, options: UI.opts(d.clients, k => k.name + (k.company_name ? ' / ' + k.company_name : '')), value: x.client_id, col: 6 },
      { name: 'route_id', label: 'Route', type: 'select', options: UI.opts(d.routes, r => `${r.origin} → ${r.destination}`), value: x.route_id, col: 6, placeholder: 'Any route' },
      { name: 'truck_type', label: 'Truck type', type: 'select', required: true, options: UI.list(App.me.truckTypes), value: x.truck_type, col: 3 },
      { name: 'rate_basis', label: 'Basis', type: 'select', required: true, options: UI.list(['Per Trip', 'Per Unit', 'Per KM']), value: x.rate_basis, col: 3 },
      { name: 'amount', label: 'Rate (PHP)', type: 'money', required: true, value: x.amount, col: 3 }, { name: 'effective_on', label: 'Effective from', type: 'date', value: x.effective_on, col: 3 },
      { name: 'status', label: 'Status', type: 'select', options: UI.list(['Active', 'Inactive']), value: x.status || 'Active', placeholder: false, col: 3 }], 'Billing.rate_save');
    const tools = [];
    if (c.client) tools.push('<button class="btn btn-sm btn-primary" data-n="c"><i class="bi bi-plus-lg"></i> Client</button>');
    if (c.route) tools.push('<button class="btn btn-sm btn-outline-primary" data-n="r"><i class="bi bi-plus-lg"></i> Route</button>');
    if (c.rate) tools.push('<button class="btn btn-sm btn-outline-primary" data-n="t"><i class="bi bi-plus-lg"></i> Rate</button>');
    ctx.tools.innerHTML = tools.join(' ');
    ctx.tools.querySelectorAll('[data-n]').forEach(b => b.onclick = () => ({ c: clientForm, r: routeForm, t: rateForm })[b.dataset.n]());
    const tabs = [
      ['clients', 'Clients', () => UI.table([['Client', 'name'], ['Company', 'company_name'], ['Contact', 'contact_person'], ['Number', 'contact_number'], ['Email', 'email'], ['Terms (days)', 'payment_terms_days'],
        ['Completed trips', 'completed_trips'], ...(UI.role('admin', 'billing', 'finance') ? [['Outstanding', 'outstanding', 'money']] : []), ['Active', 'active', 'yes']], d.clients, {
        id: 'cl', actions: c.client ? () => UI.btn('edit', 'Edit', 'outline-primary') : null, onAction: (a, x) => clientForm(x) })],
      ['routes', 'Routes', () => UI.table([['Origin', 'origin'], ['Destination', 'destination'], ['Total km', 'distance_km', 'num'], ['RFID / toll', 'toll_fee', 'money'], ['Notes', 'notes'], ['Active', 'active', 'yes']], d.routes, {
        id: 'rt', actions: c.route ? () => UI.btn('edit', 'Edit', 'outline-primary') : null, onAction: (a, x) => routeForm(x) })],
    ];
    if ((d.rates || []).length || c.rate) tabs.push(['rates', 'Client rates', () => UI.table([['Client', 'client'], ['Company', 'company_name'], ['Route', 'origin', (x, r) => x ? UI.esc(x) + ' → ' + UI.esc(r.destination) : 'Any'], ['Truck type', 'truck_type'], ['Basis', 'rate_basis'], ['Rate', 'amount', 'money'], ['Effective', 'effective_on', 'date'], ['Status', 'status', 'badge']], d.rates, {
      id: 'rates', actions: c.rate ? () => UI.btn('edit', 'Edit', 'outline-primary') : null, onAction: (a, x) => rateForm(x) })]);
    if ((d.pnl || []).length || c.rate) tabs.push(['pnl', 'Projected P&L per trip', (box) => {
      box.innerHTML = `<p class="small text-muted">Formula from "Dummy Computation for Billing PL.xlsx": Gross sales = rate; expenses = fuel ((km ÷ km/L) × price), RFID, misc. logistics %, misc. PDI %, salaries; Net income = gross − total expense. Change the numbers in Formula Settings.</p>` +
        UI.table([['Client', 'client'], ['Route', 'route'], ['Truck type', 'truck_type'], ['Gross sales', 'gross_sales', 'money'], ['Total expense', 'total_expense', 'money'], ['Net income', 'net_income', 'money'], ['Margin %', 'margin_percent', 'num']], d.pnl, {
          id: 'pnl', actions: () => UI.btn('view', 'Breakdown', 'outline-secondary'),
          onAction: (a, p) => UI.modal('Projected P&L — ' + UI.esc(p.client) + ', ' + UI.esc(p.route), `<p>Gross sales: <b>${UI.money(p.gross_sales)}</b></p>${UI.breakdown(p.expenses, p.total_expense, 'Total expense')}<p class="mt-3 fs-6">Net income: <b>${UI.money(p.net_income)}</b> (${p.margin_percent ?? '—'}%)</p>`, null, { size: '' }) });
    }]);
    UI.tabs(ctx.el, tabs);
  },
});
