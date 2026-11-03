/* assets/js/modules/maintenance/maintenance.js — VEHICLE MAINTENANCE
   Incidents to assess (minor / major), on-premise repairs, monthly PMS, repair costs and
   Confirm Repair (needs repair details + evidence). Truck availability follows the repair.
   Backend: backend/Modules/Maintenance/Maintenance.php */
'use strict';
const MaintUI = {
  data: null,
  start(vehicleId, source = 'On-Premise') {
    const d = MaintUI.data;
    const trucks = d.trucks.filter(v => !['On Trip', 'Under Maintenance'].includes(v.status));
    UI.formModalFiles(source === 'Preventive' ? 'Start monthly PMS' : 'Start maintenance (on-premise repair)', [
      { name: 'vehicle_id', label: 'Truck', type: 'select', required: true, col: 6, value: vehicleId, options: trucks.map(v => [v.id, `${v.plate_number}${v.truck_number ? ' #' + v.truck_number : ''} (${v.status})`]) },
      { name: 'source', type: 'hidden', value: source },
      { name: 'inspection_date', label: 'Date of inspection', type: 'date', value: UI.today(), required: true, col: 6 },
      { name: 'problem', label: source === 'Preventive' ? 'Work to do (optional)' : 'Description of damage or problem', type: 'textarea', required: source !== 'Preventive', col: 12,
        value: source === 'Preventive' ? 'Monthly Preventive Maintenance Service (PMS)' : '' },
      { name: 'assigned_to', label: 'Mechanic / service provider', col: 6 }, { name: 'expected_completion', label: 'Expected completion (truck back)', type: 'date', col: 6 },
      { name: 'photos', label: source === 'Preventive' ? 'Photos (optional)' : 'Photos of the damaged parts or truck condition', type: 'file', multiple: true, required: source !== 'Preventive', col: 12, attrs: 'accept="image/*"' },
      { name: 'remarks', label: 'Remarks', col: 12 }],
      'Maintenance.start', { intro: 'The truck becomes Under Maintenance and is removed from the available list. If it was pre-assigned to a trip, the system re-assigns that trip.', submit: 'Start maintenance' });
  },
  async open(id) {
    const d = MaintUI.data;
    let r; try { r = await App.api('Maintenance.details', { job_id: id }); } catch (e) { return UI.toast(e.message, 'error'); }
    const j = r.job, i = r.incident, act = d.can.act;
    const acts = [];
    if (act && j.source === 'Incident' && j.status === 'Reported') acts.push('<button class="btn btn-sm btn-primary" data-a="assess"><i class="bi bi-clipboard-pulse"></i> Assess damage</button>');
    if (act && j.status === 'Ongoing') acts.push('<button class="btn btn-sm btn-success" data-a="confirm"><i class="bi bi-check2-circle"></i> Confirm repair</button>',
      '<button class="btn btn-sm btn-outline-secondary" data-a="cost"><i class="bi bi-cash"></i> Add repair cost</button>',
      '<button class="btn btn-sm btn-outline-secondary" data-a="parts"><i class="bi bi-box-seam"></i> Request parts</button>');
    if (act && j.source !== 'Incident' && ['Reported', 'Ongoing'].includes(j.status)) acts.push('<button class="btn btn-sm btn-outline-danger" data-a="cancel">Cancel record</button>');
    const partsCost = r.parts.reduce((a, p) => a + (+p.released_qty) * (+p.unit_cost || 0), 0), other = r.costs.reduce((a, c) => a + +c.amount, 0);
    const html = `<div class="mb-2">${UI.badge(j.status)} <span class="badge-s">${UI.esc(j.source)}</span> ${j.severity ? UI.badge(j.severity) : ''}</div>
      ${acts.length ? `<div class="drawer-actions">${acts.join('')}</div>` : ''}
      ${UI.h('Maintenance record')}${UI.kv([['Truck', `<b>${UI.esc(j.plate_number)}</b>${j.truck_number ? ' #' + UI.esc(j.truck_number) : ''} · ${UI.esc(j.truck_type)}`], ['Problem', UI.e(j.problem)],
        ['Inspection date', UI.date(j.inspection_date)], ['Mechanic / provider', UI.e(j.assigned_to)], ['Expected back', UI.date(j.expected_completion)], ['Trip', UI.e(j.trip_ref)],
        ['Started', j.started_at ? UI.e(j.started_by_name) + ' · ' + UI.datetime(j.started_at) : '—'], ['Repair performed', UI.e(j.repair_performed)], ['Completed', j.completed_on ? UI.date(j.completed_on) + ' · ' + UI.e(j.completed_by_name) : '—'],
        ['Remarks', UI.e(j.remarks)]])}
      ${i ? UI.h('Incident report', `<button class="btn btn-link btn-sm p-0" data-a="printinc">Print form</button>`) + UI.kv([['Reference', UI.esc(i.incident_ref) + ' ' + UI.badge(i.status)], ['Type', UI.e(i.incident_type)], ['When / where', UI.datetime(i.incident_at) + ' · ' + UI.esc(i.location)],
        ['Problem', UI.e(i.problem)], ['Details', UI.e(i.details)], ['Persons involved', UI.e(i.persons_involved)], ['Reported by', UI.e(i.reported_by_name)], ['Recommendation', UI.e(i.recommendation)]]) : ''}
      ${UI.h('Parts used (released from inventory)')}${UI.table([['Request', 'request_ref'], ['Part', 'part_number'], ['Description', 'description'], ['Requested', 'quantity', 'num'], ['Released', 'released_qty', 'num'], ['Unit cost', 'unit_cost', 'money']], r.parts, { empty: 'No parts requested.' })}
      ${UI.h('Repair costs')}${UI.table([['Type', 'cost_type'], ['Description', 'description'], ['Provider', 'provider'], ['Date', 'incurred_on', 'date'], ['Amount', 'amount', 'money']], r.costs, { empty: 'No other costs.' })}
      <div class="text-end small mt-1">Parts ${UI.money(partsCost)} + other ${UI.money(other)} = <b>${UI.money(partsCost + other)}</b></div>
      ${UI.h('Photos and documents')}${UI.docs(r.documents)}`;
    const el = UI.drawer(UI.esc(j.job_ref) + ' · ' + UI.esc(j.plate_number), html, { subtitle: 'Maintenance' });
    el.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
      const a = b.dataset.a;
      if (a === 'assess') UI.formModal('Assess ' + UI.esc(i.incident_ref), [
          { name: 'severity', label: 'Assessment', type: 'select', required: true, col: 6, options: [['Minor', 'Minor damage: repair on site, trip continues'], ['Major', 'Major damage: return truck, repair, reschedule trip']] },
          { name: 'assigned_to', label: 'Mechanic sent / assigned', col: 6 }, { name: 'expected_completion', label: 'Expected truck availability (needed for major)', type: 'date', col: 6 },
          { name: 'recommendation', label: 'Recommendation', type: 'textarea', required: true, col: 12 }],
        'Maintenance.assess', { extra: { job_id: j.id }, intro: `${UI.esc(i.problem)} @ ${UI.esc(i.location)}. <br>Minor: truck stays on the trip. Major: truck becomes Under Maintenance and the trip is For Reschedule.`, after: () => { MaintUI.open(j.id); App.route(); } });
      if (a === 'confirm') UI.formModalFiles('Confirm repair ' + UI.esc(j.job_ref), [
          { name: 'repair_performed', label: 'Repair / maintenance activities performed', type: 'textarea', required: true, col: 12 },
          { name: 'completed_on', label: 'Completion date', type: 'date', value: UI.today(), required: true, col: 6 }, { name: 'remarks', label: 'Remarks', col: 6 },
          { name: 'evidence', label: 'Supporting photos or documents (evidence, required)', type: 'file', multiple: true, required: true, col: 12, attrs: 'accept="application/pdf,image/*"' }],
        'Maintenance.confirm', { extra: { job_id: j.id }, submit: 'Confirm repair', intro: 'Parts used come from the parts requests; add labor or outside service costs before confirming. The truck becomes available again (or the trip can resume / be rescheduled).',
          after: () => { UI.closeDrawer(); App.route(); } });
      if (a === 'cost') UI.formModal('Repair cost for ' + UI.esc(j.job_ref), [
          { name: 'cost_type', label: 'Type', type: 'select', options: UI.list(d.costTypes), required: true, col: 6 }, { name: 'amount', label: 'Amount', type: 'money', required: true, col: 6 },
          { name: 'description', label: 'Description', required: true, col: 12 }, { name: 'provider', label: 'Supplier / service provider', col: 6 }, { name: 'incurred_on', label: 'Date', type: 'date', value: UI.today(), required: true, col: 6 }],
        'Maintenance.add_cost', { extra: { job_id: j.id }, after: () => MaintUI.open(j.id) });
      if (a === 'parts') { UI.closeDrawer(); App.go('purchasing', { request_for: j.vehicle_id, job: j.id }); }
      if (a === 'cancel') UI.confirmAction('Cancel ' + UI.esc(j.job_ref), 'Use this only for a record made by mistake.', 'Maintenance.cancel', { job_id: j.id }, { danger: true, fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
      if (a === 'printinc') TripUI.printIncident({ ...i, assessed_by_name: i.assessed_by_name }, { trip_ref: j.trip_ref, plate_number: j.plate_number });
    });
  },
};

App.page('maintenance', {
  title: 'Maintenance',
  subtitle: 'Incident repairs, on-premise repairs and monthly PMS. Truck availability follows the repair status.',
  render(ctx) {
    const d = MaintUI.data = ctx.data;
    if (d.can.act) {
      const b1 = document.createElement('button'); b1.className = 'btn btn-sm btn-primary'; b1.innerHTML = '<i class="bi bi-tools"></i> Start maintenance'; b1.onclick = () => MaintUI.start();
      const b2 = document.createElement('button'); b2.className = 'btn btn-sm btn-outline-primary'; b2.innerHTML = '<i class="bi bi-calendar-check"></i> Start PMS'; b2.onclick = () => MaintUI.start(null, 'Preventive');
      ctx.tools.append(b1, b2);
    }
    const jobs = d.jobs;
    const assess = jobs.filter(j => j.source === 'Incident' && j.status === 'Reported'), ongoing = jobs.filter(j => j.status === 'Ongoing');
    const due = d.pms.filter(v => !v.open_pms && (v.last_pms_date === null || +v.days_left <= 7));
    const um = d.trucks.filter(v => v.status === 'Under Maintenance').length;
    ctx.el.innerHTML = UI.cards([
      { label: 'Incidents to assess', value: assess.length, icon: 'bi-exclamation-triangle', color: 'red' },
      { label: 'Ongoing repairs / PMS', value: ongoing.length, icon: 'bi-tools', color: 'blue' },
      { label: 'Trucks under maintenance', value: um, icon: 'bi-truck', color: 'amber' },
      { label: 'PMS due (7 days) or never recorded', value: due.length, icon: 'bi-calendar-check', color: 'gray' },
    ]);
    const cols = [['Record', 'job_ref'], ['Truck', 'plate_number'], ['Type', 'source'], ['Severity', 'severity', 'badge'], ['Problem', 'problem'], ['Trip', 'trip_ref'],
      ['Status', 'status', 'badge'], ['Truck status', 'truck_status', 'badge'], ['Expected back', 'expected_completion', 'date'], ['Started', 'started_at', 'date']];
    const opt = { actions: () => UI.btn('view', 'Open'), onAction: (a, j) => MaintUI.open(j.id) };
    const box = document.createElement('div'); ctx.el.append(box);
    UI.tabs(box, [
      ['assess', `Incidents to assess (${assess.length})`, () => UI.table([['Record', 'job_ref'], ['Incident', 'incident_ref'], ['Truck', 'plate_number'], ['When', 'incident_at', 'datetime'], ['Where', 'incident_location'], ['Problem', 'problem'], ['Trip', 'trip_ref']], assess, { ...opt, empty: 'No incident is waiting for assessment.' })],
      ['ongoing', `Ongoing (${ongoing.length})`, () => UI.table(cols, ongoing, { ...opt, empty: 'No ongoing maintenance.' })],
      ['pms', 'Monthly PMS schedule', () => UI.table([['Truck', 'plate_number'], ['No.', 'truck_number'], ['Type', 'truck_type'], ['Status', 'status', 'badge'], ['Last PMS', 'last_pms_date', 'date'], ['Next due', 'next_due', 'date'],
        ['Due in', 'days_left', (v, r) => r.last_pms_date ? UI.days(v) : '<span class="badge-s amber">no PMS recorded</span>'], ['Open PMS', 'open_pms']], d.pms,
        { title: 'Preventive Maintenance Service (monthly, regardless of mileage)', actions: (v) => d.can.act && !v.open_pms && v.status !== 'On Trip' ? UI.btn('pms', 'Start PMS', 'outline-primary') : '', onAction: (a, v) => MaintUI.start(v.id, 'Preventive') })],
      ['history', 'All records', () => UI.table([...cols, ['Completed', 'completed_on', 'date'], ['Parts', 'parts_cost', 'money'], ['Other cost', 'other_cost', 'money']], jobs, { ...opt, title: 'Maintenance history' })],
    ], assess.length ? 'assess' : null);
    if (ctx.query.start) MaintUI.start(ctx.query.start);
  },
});
