/* assets/js/modules/maintenance.js — Vehicle Maintenance (FMS PDF II.L, pp.17-18).
   Backend: backend/Modules/Maintenance.php */
'use strict';
App.page('maintenance', {
  title: 'Maintenance',
  subtitle: 'Repair requests (incl. incidents), preventive maintenance, parts used, costs and expected availability.',
  render(ctx) {
    const d = ctx.data, edit = d.can.edit, jobs = d.jobs || [];
    const open = jobs.filter(j => !['Completed', 'Cancelled'].includes(j.status));
    const vOpts = UI.opts(d.vehicles, v => `${v.plate_number} (${v.status})`);
    if (edit) {
      ctx.tools.innerHTML = `<button class="btn btn-sm btn-primary" id="addJob"><i class="bi bi-plus-lg"></i> Record repair / maintenance</button>
        <button class="btn btn-sm btn-outline-primary" id="addSched"><i class="bi bi-calendar-plus"></i> Schedule service</button>
        <button class="btn btn-sm btn-outline-primary" id="reqPart"><i class="bi bi-box-seam"></i> Request parts</button>`;
      document.getElementById('addJob').onclick = () => UI.formModal('Record repair or maintenance requirement', [
        { name: 'vehicle_id', label: 'Truck', type: 'select', required: true, options: vOpts, col: 6 },
        { name: 'maintenance_type', label: 'Type', type: 'select', required: true, options: UI.list(['Repair', 'Preventive Maintenance']), col: 6 },
        { name: 'description', label: 'Reported issue / work needed', type: 'textarea', required: true, col: 12 },
        { name: 'assigned_to', label: 'Mechanic / service provider', col: 6 }, { name: 'expected_completion', label: 'Expected completion (available again)', type: 'date', col: 6 },
      ], 'Maintenance.job_add', { intro: 'The truck becomes Under Maintenance. Attach photos in Documents (record type "maintenance"). Road problems during a trip are reported by the dispatcher as an incident.' });
      document.getElementById('addSched').onclick = () => UI.formModal('Schedule service', [
        { name: 'vehicle_id', label: 'Truck', type: 'select', required: true, options: vOpts, col: 6 },
        { name: 'maintenance_type', label: 'Service', required: true, value: 'Preventive Maintenance', col: 6 },
        { name: 'due_on', label: 'Due date', type: 'date', col: 6 }, { name: 'due_odometer_km', label: 'Due odometer (km)', type: 'number', col: 6 },
        { name: 'notes', label: 'Notes', col: 12 }], 'Maintenance.schedule_add', { size: '' });
      document.getElementById('reqPart').onclick = () => partReq();
    }
    const partReq = (job) => UI.formModal('Request truck parts from Purchasing', [
      { name: 'vehicle_id', label: 'Truck', type: 'select', required: true, options: vOpts, value: job?.vehicle_id, col: 6 },
      { name: 'job_id', label: 'For job', type: 'select', options: UI.opts(open, j => `${j.job_number} ${j.plate_number}`), value: job?.id, col: 6, placeholder: 'None' },
      { name: 'part_id', label: 'Part', type: 'select', required: true, options: UI.opts(d.parts, p => `${p.part_number} ${p.description} (stock ${p.quantity_on_hand} ${p.unit})`), col: 8 },
      { name: 'quantity', label: 'Quantity', type: 'number', min: 1, required: true, col: 4 },
      { name: 'notes', label: 'Notes', col: 12 }], 'Maintenance.part_request', { size: '' });

    const jobCost = (j) => +j.parts_cost + +j.other_cost;
    ctx.el.innerHTML = UI.cards([
      { label: 'Open jobs', value: open.length, icon: 'bi-tools', color: 'amber' },
      { label: 'Trucks under maintenance', value: (d.vehicles || []).filter(v => v.status === 'Under Maintenance').length, icon: 'bi-truck', color: 'red' },
      { label: 'Roadside repairs (minor incidents)', value: open.filter(j => j.maintenance_type === 'Roadside Repair').length, icon: 'bi-geo-alt', color: 'amber' },
      { label: 'Scheduled services', value: (d.schedules || []).filter(s => s.status === 'Scheduled').length, icon: 'bi-calendar-check', color: 'blue' },
      { label: 'PM limit', value: UI.num(d.pmInterval) + ' km', icon: 'bi-speedometer2', color: 'green', hint: 'Formula Settings: pm_interval_km' },
    ]);
    const jobActions = (j) => {
      if (!edit || ['Completed', 'Cancelled'].includes(j.status)) return UI.btn('detail', 'Details', 'outline-secondary', 'bi-eye');
      return UI.btn('detail', 'Details', 'outline-secondary', 'bi-eye') + UI.btn('update', 'Update', 'outline-primary') + UI.btn('parts', 'Parts', 'outline-primary') + UI.btn('use', 'Parts used', 'outline-primary') +
        UI.btn('cost', 'Cost', 'outline-primary') + UI.btn('done', 'Complete', 'success', 'bi-check2');
    };
    const onJob = (act, j) => {
      if (act === 'detail') {
        const used = (d.partsUsed || []).filter(x => +x.maintenance_job_id === +j.id), costs = (d.costs || []).filter(x => +x.maintenance_job_id === +j.id);
        return UI.modal('Job ' + UI.esc(j.job_number), `<table class="table table-sm">${[['Truck', j.plate_number], ['Type', j.maintenance_type], ['Source', j.source], ['Trip', j.trip_ref], ['Status', j.status], ['Mechanic / provider', j.assigned_to], ['Expected completion', UI.date(j.expected_completion)], ['Started', UI.datetime(j.started_at)], ['Completed', UI.datetime(j.completed_at)], ['Created by', j.created_by_name]].map(([k, v]) => `<tr><th class="small text-muted">${k}</th><td>${UI.esc(v ?? '—')}</td></tr>`).join('')}</table>
          <p style="white-space:pre-wrap">${UI.esc(j.description)}</p>
          <h6>Parts used</h6>${UI.table([['Part', 'part_number'], ['Description', 'description'], ['Qty', 'quantity'], ['Unit cost', 'unit_cost', 'money']], used, { id: 'pu' + j.id })}
          <h6 class="mt-3">Costs</h6>${UI.table([['Type', 'cost_type'], ['Description', 'description'], ['Provider', 'provider'], ['Date', 'incurred_on', 'date'], ['Amount', 'amount', 'money']], costs, { id: 'co' + j.id })}
          <div class="mt-2 fw-bold">Total: ${UI.money(jobCost(j))} (parts ${UI.money(j.parts_cost)} + other ${UI.money(j.other_cost)})</div>`, null, { size: 'modal-xl', onShow: (w) => UI.activate(w) });
      }
      if (act === 'update') return UI.formModal('Update ' + UI.esc(j.job_number), [{ name: 'job_id', type: 'hidden', value: j.id },
        { name: 'status', label: 'Status', type: 'select', required: true, options: UI.list(d.jobStatuses), value: j.status, col: 4 },
        { name: 'assigned_to', label: 'Mechanic / provider', value: j.assigned_to, col: 4 },
        { name: 'expected_completion', label: 'Expected completion', type: 'date', value: j.expected_completion, col: 4 }], 'Maintenance.job_update', { size: '' });
      if (act === 'parts') return partReq(j);
      if (act === 'use') {
        const reqs = (d.partRequests || []).filter(r => r.status === 'Issued' && +r.vehicle_id === +j.vehicle_id && +r.used_qty < +r.requested_qty);
        return UI.formModal('Record parts used on ' + UI.esc(j.job_number), [{ name: 'job_id', type: 'hidden', value: j.id },
          { name: 'request_id', label: 'Issued parts request', type: 'select', required: true, options: UI.opts(reqs, r => `#${r.id} ${r.description} (issued ${r.requested_qty}, used ${r.used_qty})`), col: 8 },
          { name: 'quantity', label: 'Quantity used', type: 'number', min: 1, required: true, col: 4 }], 'Maintenance.part_use', { size: '', intro: reqs.length ? '' : 'No issued parts for this truck yet. Request parts first; Purchasing releases them.' });
      }
      if (act === 'cost') return UI.formModal('Repair cost for ' + UI.esc(j.job_number), [{ name: 'job_id', type: 'hidden', value: j.id },
        { name: 'cost_type', label: 'Type', type: 'select', required: true, options: UI.list(['Labor', 'Outside Service', 'Materials', 'Other']), col: 4 },
        { name: 'amount', label: 'Amount (PHP)', type: 'money', required: true, col: 4 }, { name: 'incurred_on', label: 'Date', type: 'date', required: true, value: UI.today(), col: 4 },
        { name: 'description', label: 'Description', required: true, col: 8 }, { name: 'provider', label: 'Supplier / service provider', col: 4 }], 'Maintenance.cost_add', { intro: 'Saved as a Maintenance expense for Finance. Attach the receipt in Documents.' });
      if (act === 'done') return UI.confirmAction('Complete job', `Complete ${UI.esc(j.job_number)} on ${UI.esc(j.plate_number)}? The truck returns to Available when nothing else is open.`, 'Maintenance.job_complete', { job_id: j.id }, { fields: [{ name: 'notes', label: 'Work done (optional)', col: 12 }] });
    };
    UI.tabs(ctx.el, [
      ['jobs', 'Repair and maintenance jobs', () => UI.table([['Job', 'job_number'], ['Truck', 'plate_number'], ['Type', 'maintenance_type'], ['Source', 'source'], ['Severity', 'severity', 'badge'], ['Trip', 'trip_ref'],
        ['Issue', 'description'], ['Mechanic', 'assigned_to'], ['Status', 'status', 'badge'], ['Expected available', 'expected_completion', 'date'], ['Cost', 'parts_cost', (x, r) => UI.money(jobCost(r))], ['Created', 'created_at', 'datetime']],
        jobs, { id: 'jobs', actions: jobActions, onAction: onJob })],
      ['avail', 'Expected availability', () => UI.table([['Truck', 'plate_number'], ['Type', 'truck_type'], ['Status', 'status', 'badge'], ['Expected available', 'expected_available', 'date'], ['Odometer', 'odometer_km', 'num'], ['Km since PM', 'km_since_pm', (x) => `${UI.num(x)} / ${UI.num(d.pmInterval)}`]], d.vehicles, { id: 'vav' })],
      ['sched', 'Preventive maintenance schedule', () => UI.table([['Truck', 'plate_number'], ['Service', 'maintenance_type'], ['Due date', 'due_on', 'date'], ['Due km', 'due_odometer_km', 'num'], ['Current km', 'odometer_km', 'num'], ['Notes', 'notes'], ['Status', 'status', 'badge']], d.schedules, {
        id: 'sch', actions: (s) => edit && s.status === 'Scheduled' && !s.maintenance_job_id ? UI.btn('start', 'Start service', 'primary') + UI.btn('cancel', 'Cancel', 'outline-danger') : '',
        onAction(act, s) {
          if (act === 'start') UI.confirmAction('Start service', `Start ${UI.esc(s.maintenance_type)} on ${UI.esc(s.plate_number)}? The truck becomes Under Maintenance.`, 'Maintenance.schedule_start', { schedule_id: s.id });
          if (act === 'cancel') UI.confirmAction('Cancel schedule', 'Cancel this schedule?', 'Maintenance.schedule_cancel', { schedule_id: s.id }, { danger: true, fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
        } })],
      ['parts', 'Parts requests', () => UI.table([['#', 'id'], ['Truck', 'plate_number'], ['Part', 'part_number'], ['Description', 'description'], ['Qty', 'requested_qty'], ['Used', 'used_qty'], ['Stock', 'quantity_on_hand'], ['Status', 'status', 'badge'], ['Requested', 'created_at', 'datetime']], d.partRequests, { id: 'preq' })],
      ['costs', 'Costs', () => UI.table([['Job id', 'maintenance_job_id'], ['Type', 'cost_type'], ['Description', 'description'], ['Provider', 'provider'], ['Date', 'incurred_on', 'date'], ['Amount', 'amount', 'money']], d.costs, { id: 'mcosts' })],
    ]);
  },
});
