/* assets/js/modules/maintenance/purchasing.js — Parts Inventory and Purchasing (FMS PDF II.K, p.17).
   Backend: backend/Modules/Maintenance/Purchasing.php (part requests from Motorpool use Maintenance.part_request). */
'use strict';
App.page('inventory', {
  title: 'Parts Inventory',
  subtitle: 'Stock levels, low-stock alerts, Motorpool parts requests (stock-out) and inventory transactions.',
  render(ctx) {
    const d = ctx.data, m = d.can.manage, parts = d.parts || [];
    const partForm = (p = {}) => UI.formModal(p.id ? 'Edit part' : 'Add part', [{ name: 'id', type: 'hidden', value: p.id },
      { name: 'part_number', label: 'Part ID / part number', required: true, value: p.part_number, col: 4 }, { name: 'description', label: 'Description', required: true, value: p.description, col: 8 },
      { name: 'category', label: 'Category', value: p.category, col: 4 }, { name: 'unit', label: 'Unit', required: true, value: p.unit || 'pc', col: 2 },
      { name: 'unit_cost', label: 'Unit cost (PHP)', type: 'money', value: p.unit_cost ?? 0, col: 3 }, { name: 'reorder_level', label: 'Reorder level', type: 'number', value: p.reorder_level ?? 0, col: 3, help: 'Low-stock alert at or below this' },
      { name: 'default_supplier_id', label: 'Usual supplier', type: 'select', options: UI.opts(d.suppliers, s => s.name), value: p.default_supplier_id, col: 6, placeholder: 'None' },
      { name: 'warranty_info', label: 'Warranty information', value: p.warranty_info, col: 6 },
      ...(p.id ? [{ name: 'inactive', label: 'Inactive (no longer used)', type: 'checkbox', value: +p.active ? 0 : 1 }] : [])],
      'Purchasing.part_save', { intro: p.id ? '' : 'New parts start at 0 stock. Record the verified count with "Adjust stock".' });
    if (m) {
      ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="addPart"><i class="bi bi-plus-lg"></i> Add part</button>';
      document.getElementById('addPart').onclick = () => partForm();
    }
    if (d.can.request) {
      ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="reqP"><i class="bi bi-box-seam"></i> Request parts</button>';
      document.getElementById('reqP').onclick = () => UI.formModal('Request truck parts from Purchasing', [
        { name: 'vehicle_id', label: 'Truck', type: 'select', required: true, options: UI.opts(d.vehicles, v => v.plate_number), col: 6 },
        { name: 'job_id', label: 'For job', type: 'select', options: UI.opts(d.jobs, j => j.job_number), col: 6, placeholder: 'None' },
        { name: 'part_id', label: 'Part', type: 'select', required: true, options: UI.opts(parts.filter(p => +p.active), p => `${p.part_number} ${p.description} (stock ${p.quantity_on_hand})`), col: 8 },
        { name: 'quantity', label: 'Quantity', type: 'number', min: 1, required: true, col: 4 }, { name: 'notes', label: 'Notes', col: 12 }], 'Maintenance.part_request', { size: '' });
    }
    const low = parts.filter(p => +p.low_stock);
    ctx.el.innerHTML = (low.length ? `<div class="alert alert-danger py-2">${low.length} part(s) are at or below the reorder level: ${low.map(p => UI.esc(p.description)).join(', ')}</div>` : '') + UI.cards([
      { label: 'Active parts', value: parts.filter(p => +p.active).length, icon: 'bi-box-seam', color: 'green' },
      { label: 'Low-stock items', value: low.length, icon: 'bi-exclamation-triangle', color: 'red' },
      { label: 'Stock value', value: parts.reduce((s, p) => s + p.quantity_on_hand * p.unit_cost, 0), format: 'money', icon: 'bi-cash', color: 'blue' },
      { label: 'Pending requests', value: (d.requests || []).filter(r => r.status === 'Requested').length, icon: 'bi-inbox', color: 'amber' },
    ]);
    UI.tabs(ctx.el, [
      ['parts', 'Parts and stock', () => UI.table([['Part no.', 'part_number'], ['Description', 'description'], ['Category', 'category'], ['On hand', 'quantity_on_hand', (x, r) => +r.low_stock ? `<b class="text-danger">${x}</b>` : x], ['Unit', 'unit'],
        ['Reorder level', 'reorder_level'], ['Unit cost', 'unit_cost', 'money'], ['Supplier', 'supplier'], ['Warranty', 'warranty_info'], ['Active', 'active', 'yes']], parts, {
        id: 'parts', actions: m ? () => UI.btn('edit', 'Edit', 'outline-primary') + UI.btn('adj', 'Adjust stock', 'outline-warning') : null,
        onAction(act, p) {
          if (act === 'edit') partForm(p);
          if (act === 'adj') UI.formModal('Adjust stock of ' + UI.esc(p.description), [{ name: 'part_id', type: 'hidden', value: p.id },
            { name: 'quantity', label: 'Add (+) or remove (−) quantity', type: 'number', min: -99999, required: true, col: 4 },
            { name: 'reason', label: 'Reason', type: 'select', required: true, options: UI.list(['Opening balance (verified count)', 'Physical count correction', 'Damaged / lost', 'Returned to stock', 'Other']), col: 8 }],
            'Purchasing.adjust', { size: '', intro: 'On hand now: ' + p.quantity_on_hand + ' ' + p.unit });
        } })],
      ['req', 'Parts requests (stock-out)', () => UI.table([['#', 'id'], ['Requested', 'created_at', 'datetime'], ['By', 'requested_by_name'], ['Truck', 'plate_number'], ['Job', 'job_number'],
        ['Part', 'part_number'], ['Description', 'description'], ['Qty', 'requested_qty'], ['On hand', 'quantity_on_hand'], ['Status', 'status', 'badge'], ['Notes', 'notes']], d.requests, {
        id: 'reqs', actions: m ? (r) => ['Requested', 'Not Available'].includes(r.status) ? UI.btn('issue', 'Release parts', 'success') + (r.status === 'Requested' ? UI.btn('na', 'Not available', 'outline-danger') : '') : '' : null,
        onAction(act, r) {
          if (act === 'issue') UI.confirmAction('Release parts', `Release ${r.requested_qty} ${UI.esc(r.unit)} of ${UI.esc(r.description)} to Motorpool for ${UI.esc(r.plate_number)}? (on hand: ${r.quantity_on_hand})`, 'Purchasing.issue', { request_id: r.id });
          if (act === 'na') UI.confirmAction('Not available', 'Mark as not available? Then create a purchase request in Purchasing.', 'Purchasing.not_available', { request_id: r.id });
        } })],
      ...((d.movements || []).length || m ? [['moves', 'Inventory transactions', () => UI.table([['When', 'created_at', 'datetime'], ['Type', 'movement_type', 'badge'], ['Part', 'part_number'], ['Description', 'description'], ['Qty', 'quantity_delta'], ['Unit cost', 'unit_cost', 'money'], ['Truck', 'plate_number'], ['Reason', 'reason'], ['By', 'actor']], d.movements, { id: 'moves' })]] : []),
    ]);
  },
});

App.page('purchasing', {
  title: 'Purchasing',
  subtitle: 'Purchase requests (stock-in), approval by limit, budget check, purchase orders, receipts and suppliers.',
  render(ctx) {
    const d = ctx.data, c = d.can, b = d.budget || {};
    const remaining = b.id ? (+b.budget_amount - +b.used_amount) : null;
    if (c.manage) {
      ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="newPr"><i class="bi bi-plus-lg"></i> Purchase request</button> <button class="btn btn-sm btn-outline-primary" id="newSup"><i class="bi bi-building-add"></i> Add supplier</button>';
      document.getElementById('newPr').onclick = () => prForm();
      document.getElementById('newSup').onclick = () => supForm();
    }
    const prForm = (na) => UI.formModal('Purchase request', [
      { name: 'part_id', label: 'Part', type: 'select', required: true, options: UI.opts(d.parts, p => `${p.part_number} ${p.description}`), value: na?.part_id, col: 6 },
      { name: 'quantity', label: 'Quantity', type: 'number', min: 1, required: true, value: na?.requested_qty, col: 3 },
      { name: 'unit_cost', label: 'Unit cost (PHP)', type: 'money', required: true, col: 3 },
      { name: 'supplier_id', label: 'Supplier', type: 'select', options: UI.opts((d.suppliers || []).filter(s => +s.active), s => s.name), col: 6, placeholder: 'Decide later' },
      { name: 'part_request_id', label: 'For Motorpool request (not available)', type: 'select', options: UI.opts(d.notAvailable, r => `#${r.id} ${r.description} for ${r.plate_number}`), value: na?.id, col: 6, placeholder: 'None' },
      { name: 'reason', label: 'Reason', col: 12 }], 'Purchasing.pr_create',
      { intro: `Approval: up to ${UI.money(d.threshold)} → Finance; above → Admin. After approval the system checks the Finance budget${remaining !== null ? ' (remaining ' + UI.money(remaining) + ')' : ''}.` });
    const supForm = (s = {}) => UI.formModal(s.id ? 'Edit supplier' : 'Add supplier', [{ name: 'id', type: 'hidden', value: s.id },
      { name: 'name', label: 'Supplier name', required: true, value: s.name, col: 6 }, { name: 'contact_person', label: 'Contact person', value: s.contact_person, col: 6 },
      { name: 'contact_number', label: 'Contact number', value: s.contact_number, col: 4 }, { name: 'email', label: 'Email', value: s.email, col: 4 }, { name: 'address', label: 'Address', value: s.address, col: 4 },
      { name: 'notes', label: 'Notes', value: s.notes, col: 12 }, ...(s.id ? [{ name: 'inactive', label: 'Inactive', type: 'checkbox', value: +s.active ? 0 : 1 }] : [])], 'Purchasing.supplier_save');
    const reqs = d.requests || [];
    ctx.el.innerHTML = UI.cards([
      { label: 'Purchasing budget remaining', value: remaining ?? 'Not set', format: remaining !== null ? 'money' : '', icon: 'bi-piggy-bank', color: remaining !== null ? 'green' : 'red', hint: b.id ? `of ${UI.money(b.budget_amount)} set by Finance` : 'Finance sets it in Finance & Payables > Budgets' },
      { label: 'Pending approval', value: reqs.filter(r => r.status === 'Pending Approval').length, icon: 'bi-hourglass-split', color: 'amber' },
      { label: 'Approved, to order', value: reqs.filter(r => r.status === 'Approved').length, icon: 'bi-cart-plus', color: 'blue' },
      { label: 'Ordered, to receive', value: (d.orders || []).filter(o => o.status === 'Ordered').length, icon: 'bi-truck', color: 'blue' },
      { label: 'Approval limit', value: UI.money(d.threshold), icon: 'bi-sliders', color: 'gray', hint: '≤ Finance, > Admin' },
    ]);
    if (c.manage && (d.notAvailable || []).length) ctx.el.insertAdjacentHTML('beforeend', `<div class="alert alert-warning py-2">${d.notAvailable.length} Motorpool request(s) are not in stock and have no purchase request yet.</div>`);
    const canDecide = (r) => r.status === 'Pending Approval' && ((r.approver_role === 'finance' && c.approve_finance) || (r.approver_role === 'admin' && c.approve_admin));
    UI.tabs(ctx.el, [
      ['pr', 'Purchase requests', () => UI.table([['PR no.', 'pr_number'], ['Date', 'created_at', 'datetime'], ['Part', 'part_number'], ['Description', 'description'], ['Qty', 'quantity'], ['Unit cost', 'unit_cost', 'money'],
        ['Amount', 'amount', 'money'], ['Supplier', 'supplier'], ['Approver', 'approver_role', (x) => UI.esc(UI.label(x))], ['Status', 'status', 'badge'], ['Decided by', 'decided_by_name'], ['Notes', 'decision_notes'], ['PO', 'po_number']], reqs, {
        id: 'prs', actions: (r) => (canDecide(r) ? UI.btn('decide', 'Approve / reject', 'primary') : '') + (c.manage && r.status === 'Approved' ? UI.btn('po', 'Create PO', 'success') : ''),
        onAction(act, r) {
          if (act === 'decide') UI.formModal('Decide ' + UI.esc(r.pr_number), [{ name: 'purchase_id', type: 'hidden', value: r.id },
            { name: 'decision', label: 'Decision', type: 'select', required: true, options: [['approve', 'Approve (system checks the budget)'], ['reject', 'Reject']], col: 6 },
            { name: 'notes', label: 'Notes / reason', col: 6 }], 'Purchasing.decide', { size: '', intro: `${UI.esc(r.description)} × ${r.quantity} = <b>${UI.money(r.amount)}</b>. Budget remaining: ${remaining !== null ? UI.money(remaining) : 'not set'}.` });
          if (act === 'po') UI.formModal('Purchase order for ' + UI.esc(r.pr_number), [{ name: 'purchase_id', type: 'hidden', value: r.id },
            { name: 'supplier_id', label: 'Supplier', type: 'select', required: !r.supplier_id, options: UI.opts((d.suppliers || []).filter(s => +s.active), s => s.name), value: r.supplier_id, col: 6 },
            { name: 'ordered_on', label: 'Order date', type: 'date', required: true, value: UI.today(), col: 3 }, { name: 'expected_on', label: 'Expected delivery', type: 'date', col: 3 }], 'Purchasing.po_create', { size: '' });
        } })],
      ['po', 'Purchase orders and receipts', () => UI.table([['PO no.', 'po_number'], ['PR no.', 'pr_number'], ['Supplier', 'supplier'], ['Part', 'description'], ['Qty', 'quantity'], ['Amount', 'amount', 'money'],
        ['Ordered', 'ordered_on', 'date'], ['Expected', 'expected_on', 'date'], ['Status', 'status', 'badge'], ['Received', 'received_on', 'date'], ['Receipt ref.', 'receipt_reference'], ['Warranty until', 'warranty_until', 'date']], d.orders, {
        id: 'pos', actions: (o) => c.manage && o.status === 'Ordered' ? UI.btn('recv', 'Receive (stock-in)', 'success') : '',
        onAction(act, o) {
          UI.formModal('Receive ' + UI.esc(o.po_number), [{ name: 'order_id', type: 'hidden', value: o.id },
            { name: 'quantity', label: 'Quantity received', type: 'number', min: 1, required: true, value: o.quantity, col: 4 },
            { name: 'received_on', label: 'Date received', type: 'date', required: true, value: UI.today(), col: 4 },
            { name: 'receipt_reference', label: 'Receipt / DR no.', col: 4 }, { name: 'warranty_until', label: 'Warranty until (if any)', type: 'date', col: 4 }], 'Purchasing.receive',
            { size: '', intro: 'Stock is added, and Finance gets the payable and parts expense. Attach the receipt in Documents (record type "purchase").' });
        } })],
      ['sup', 'Suppliers', () => UI.table([['Name', 'name'], ['Contact person', 'contact_person'], ['Number', 'contact_number'], ['Email', 'email'], ['Address', 'address'], ['Active', 'active', 'yes']], d.suppliers, {
        id: 'sups', actions: c.manage ? () => UI.btn('edit', 'Edit', 'outline-primary') : null, onAction: (a, s) => supForm(s) })],
      ...(c.manage ? [['na', 'Not in stock (from Motorpool)', () => UI.table([['Request', 'id'], ['Part', 'description'], ['Qty', 'requested_qty'], ['Truck', 'plate_number']], d.notAvailable, { id: 'nas', actions: () => UI.btn('pr', 'Create purchase request', 'primary'), onAction: (a, r) => prForm(r) })]] : []),
    ]);
  },
});
