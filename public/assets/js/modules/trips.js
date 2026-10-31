/* assets/js/modules/trips.js — Dispatch Board, Trip Records & Waybills, Incidents
   (FMS PDF II.D-II.G at ang bagong flow: Scenario A, B at C).
   Backend: backend/Modules/Trips.php (fund request: backend/Modules/Funds.php) */
'use strict';

/** Trip status groups (same names as backend Trips::STATUSES). */
const ON_ROAD = ['Dispatched', 'On Hold - Incident Reported', 'In Transit'];
const OPEN_FUND = ['Pending Approval', 'Ready for Release', 'Released'];

const Trip = {
  /** Workflow steps shown on each trip card. */
  steps(t) {
    const s = t.status, done = (x) => x ? 'done' : '';
    const after = (list) => list.includes(s);
    const fund = t.fund_status || '';
    const left = ['Dispatched', 'On Hold - Incident Reported', 'In Transit', 'Returned', 'Completed'];
    return [
      ['Pre-assigned', done(!['Pending Review', 'Awaiting Resources', 'Rejected'].includes(s))],
      ['Fund requested', done(!!fund || t.last_fund_status === 'Rejected')],
      ['Fund approved', done(['Ready for Release', 'Released', 'Completed'].includes(fund))],
      ['Fund released', done(['Released', 'Completed'].includes(fund))],
      ['Checklist', done(after(['Ready for Dispatch', ...left]))],
      ['Dispatched', done(after(left))],
      ['Arrived', done(after(['Returned', 'Completed']))],
      ['Completed', done(s === 'Completed')],
    ].map(([l, c], i, arr) => { const firstOpen = ['Cancelled', 'Rejected'].includes(s) ? -1 : arr.findIndex(x => !x[1]); return `<span class="step ${c || (i === firstOpen ? 'now' : '')}">${l}</span>`; }).join('');
  },
  route(t) { return `${UI.esc(t.origin)} → ${UI.esc(t.destination)}${t.distance_km ? ` <span class="text-muted">(${UI.num(t.distance_km, 1)} km)</span>` : ''}`; },
  crew(t) { return [t.driver_name && 'Driver: ' + t.driver_name, t.driver2_name && 'Driver 2: ' + t.driver2_name, t.helper_name && 'Helper: ' + t.helper_name].filter(Boolean).map(UI.esc).join(' · ') || '—'; },
  mine(t) { return UI.isDispatcher() && t.dispatcher_role === App.me.user.role; },

  /** Trip details: info, timeline, updates, checklists side by side. */
  details(t, d) {
    const ev = (d.events || []).filter(e => +e.trip_id === +t.id);
    const up = (d.updates || []).filter(e => +e.trip_id === +t.id);
    const ins = (d.inspections || []).filter(e => +e.trip_id === +t.id);
    const info = [['Trip', t.trip_ref], ['Status', UI.badge(t.status)], ['Source', t.intake_source], ['Client', t.client + (t.company_name ? ' / ' + t.company_name : '')],
      ['Truck type', t.truck_type], ['Route', Trip.route(t)], ['Schedule', UI.datetime(t.scheduled_at)], ['Truck', t.plate_number], ['Crew', Trip.crew(t)],
      ['Dispatcher team', UI.label(t.dispatcher_role)], ['Dispatched by', t.dispatcher_name], ['Booking / nomination', t.booking_reference], ['Units', t.units_planned],
      ['Container', [t.container_number, t.container_size, t.seal_number && 'seal ' + t.seal_number, t.shipment_number && 'shipment ' + t.shipment_number].filter(Boolean).join(' / ')],
      ['Fund', t.fund_status ? UI.badge(t.fund_status) + ' ' + UI.money(t.fund_amount) : '—'], ['Incident', t.incident_status ? UI.badge(t.incident_status) + (t.incident_severity ? ' ' + UI.badge(t.incident_severity) : '') : '—'],
      ['Departed', UI.datetime(t.departure_at)], ['Arrived', UI.datetime(t.arrival_at)], ['Odometer out / in', (t.pre_odometer ?? '—') + ' / ' + (t.arrival_odometer ?? '—')],
      ['Km traveled', t.km_traveled], ['Units delivered', t.units_delivered], ['Delivery notes', t.delivery_notes], ['Waybills', t.waybill_numbers], ['Attempt', +t.attempt_number > 1 ? t.attempt_number + ' (Second Attempt after major damage)' : t.attempt_number], ['Remarks', t.remarks], ['Cancel / reject reason', t.cancel_reason]]
      .filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== '—')
      .map(([k, v]) => `<tr><th class="text-muted small pe-3">${k}</th><td>${typeof v === 'string' && v.startsWith('<') ? v : UI.esc(v)}</td></tr>`).join('');
    const compare = Trip.compare(ins, d.checklist || []);
    UI.modal('Trip ' + UI.esc(t.trip_ref), `<table class="table table-sm">${info}</table>
      <h6 class="mt-3">History (review, assignment, dispatch, incidents, completion)</h6>${UI.table([['When', 'created_at', 'datetime'], ['Event', 'event_type'], ['From', 'from_status', 'badge'], ['To', 'to_status', 'badge'], ['By', 'actor'], ['Notes', 'notes']], ev, { id: 'ev' + t.id })}
      <h6 class="mt-3">Progress, delivery and delay updates</h6>${UI.table([['When', 'created_at', 'datetime'], ['Type', 'update_type', 'badge'], ['Delay cause', 'delay_cause', 'badge'], ['Note', 'note'], ['By', 'actor']], up, { id: 'up' + t.id })}
      <h6 class="mt-3">Checklists: departure vs arrival</h6>${compare}`, null, { size: 'modal-xl', onShow: (w) => UI.activate(w) });
  },
  /** Compare vehicle condition before departure and upon arrival (PDF II.G). */
  compare(ins, sections) {
    if (!ins.length) return '<div class="table-empty">No checklist recorded yet.</div>';
    const attempts = [...new Set(ins.map(i => i.attempt_number))];
    return attempts.map(a => {
      const dep = ins.find(i => i.attempt_number == a && i.inspection_type === 'Departure'), arr = ins.find(i => i.attempt_number == a && i.inspection_type === 'Arrival');
      const items = (x) => { try { return JSON.parse(x?.items_json || '{}').items || {}; } catch { return {}; } };
      const di = items(dep), ai = items(arr);
      const head = `<div class="small mb-1"><b>Attempt ${a}</b> — Departure: ${dep ? UI.datetime(dep.completed_at) + ' by ' + UI.esc(dep.actor) + ', odometer ' + (dep.odometer_km ?? '—') + ', fuel ' + UI.esc(dep.fuel_level || '—') : 'not done'}
        · Arrival: ${arr ? UI.datetime(arr.completed_at) + ' by ' + UI.esc(arr.actor) + ', odometer ' + (arr.odometer_km ?? '—') + ', fuel ' + UI.esc(arr.fuel_level || '—') : 'not done'}</div>`;
      const rows = sections.map(s => `<tr><td colspan="4" class="checklist-section-title">${UI.esc(s.title)}</td></tr>` + s.items.map((label, i) => {
        const k = s.key + '_' + String(i + 1).padStart(2, '0'); const x = di[k], y = ai[k];
        const bad = (x && x.condition === 'NOT OK') || (y && y.condition === 'NOT OK');
        return `<tr class="${bad ? 'notok' : ''}"><td>${UI.esc(label)}</td><td>${x ? UI.badge(x.condition) : '—'}</td><td>${y ? UI.badge(y.condition) : '—'}</td><td>${UI.esc([x?.remarks, y?.remarks].filter(Boolean).join(' / '))}</td></tr>`;
      }).join('')).join('');
      return head + `<div class="table-wrap" style="max-height:340px"><table class="data-table checklist-table"><thead><tr><th>Item</th><th>Departure</th><th>Arrival</th><th>Remarks</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    }).join('<hr>');
  },

  /** 57-item checklist form (from the company checklist scans). */
  checklist(t, d, type) {
    const ins = (d.inspections || []).find(i => +i.trip_id === +t.id && +i.attempt_number === +t.attempt_number && i.inspection_type === type);
    if (!ins || ins.status !== 'In Progress') {
      UI.confirmAction(type + ' checklist', `Start the ${type.toLowerCase()} checklist for ${UI.esc(t.trip_ref)} (${UI.esc(t.plate_number)})?<br><span class="small text-muted">The timer starts now. Minimum duration: ${d.minMinutes} minute(s).</span>`,
        'Trips.checklist_start', { trip_id: t.id, inspection_type: type }, { submit: 'Start checklist' });
      return;
    }
    let depItems = {};
    if (type === 'Arrival') { const dep = (d.inspections || []).find(i => +i.trip_id === +t.id && +i.attempt_number === +t.attempt_number && i.inspection_type === 'Departure'); try { depItems = JSON.parse(dep?.items_json || '{}').items || {}; } catch { } }
    const rows = (d.checklist || []).map(s => `<tr><td colspan="${type === 'Arrival' ? 5 : 4}" class="checklist-section-title">${UI.esc(s.title)}</td></tr>` + s.items.map((label, i) => {
      const k = s.key + '_' + String(i + 1).padStart(2, '0');
      return `<tr><td>${i + 1}</td><td>${UI.esc(label)}</td>${type === 'Arrival' ? `<td>${depItems[k] ? UI.badge(depItems[k].condition) : '—'}</td>` : ''}
        <td class="text-nowrap"><label class="me-2"><input type="radio" name="c_${k}" value="OK" required> OK</label><label><input type="radio" name="c_${k}" value="NOT OK"> NOT OK</label></td>
        <td><input class="form-control form-control-sm" name="r_${k}" maxlength="250" placeholder="Remarks"></td></tr>`;
    }).join('')).join('');
    const started = new Date(ins.started_at.replace(' ', 'T'));
    const body = `<div class="timer-box mb-2"><i class="bi bi-stopwatch"></i> Started ${UI.datetime(ins.started_at)} · <span id="ckElapsed"></span> · minimum ${d.minMinutes} min</div>
      ${UI.fields([
        { name: 'odometer_km', label: type === 'Departure' ? 'ODOMETER-OUT (km)' : 'ODOMETER-IN (km)', type: 'number', required: true, col: 3, help: type === 'Arrival' && t.pre_odometer ? 'Departure: ' + t.pre_odometer : '' },
        { name: 'fuel_level', label: 'Fuel level', type: 'select', options: UI.list(['Full', '3/4', '1/2', '1/4', 'Low']), col: 3 },
        { name: 'm_truck_number', label: 'Truck number', value: t.truck_number || t.plate_number, col: 3 },
        { name: 'm_driver_1', label: "Driver's name 1", value: t.driver_name, col: 3 }, { name: 'm_driver_2', label: "Driver's name 2", value: t.driver2_name || t.helper_name, col: 3 },
        { name: 'm_joint_dispatcher', label: 'Joint checklist: Dispatcher', value: App.me.user.full_name, col: 3 },
        { name: 'm_approved_by_operations', label: 'Approved by: Operations', col: 3 }, { name: 'm_guard_on_duty', label: 'Guard on duty', col: 3 },
        ...(type === 'Arrival' ? [{ name: 'units_delivered', label: 'Units delivered (car carrier)', type: 'number', value: t.units_planned, col: 3 },
          { name: 'delivery_notes', label: 'Delivery and return details', type: 'textarea', required: true, col: 9, rows: 2 }] : []),
      ])}
      <div class="d-flex gap-2 my-2"><button type="button" class="btn btn-sm btn-outline-success" id="ckAllOk">Mark all OK</button><span class="small text-muted align-self-center">Then change any item that is NOT OK. Attach photos in Documents (record type "inspection").</span></div>
      <div class="table-wrap" style="max-height:420px"><table class="data-table checklist-table"><thead><tr><th>No.</th><th>Item</th>${type === 'Arrival' ? '<th>Departure</th>' : ''}<th>${type} condition</th><th>Remarks</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    UI.modal(`${type} checklist — ${UI.esc(t.trip_ref)} / ${UI.esc(t.plate_number)}`, body, async (f) => {
      const items = {}, meta = {};
      Object.keys(f).forEach(k => {
        if (k.startsWith('c_')) items[k.slice(2)] = { condition: f[k], remarks: f['r_' + k.slice(2)] || '' };
        if (k.startsWith('m_')) meta[k.slice(2)] = f[k];
      });
      const payload = { trip_id: t.id, items, meta, odometer_km: f.odometer_km, fuel_level: f.fuel_level };
      if (type === 'Arrival') Object.assign(payload, { delivery_notes: f.delivery_notes, units_delivered: f.units_delivered });
      const r = await App.api(type === 'Departure' ? 'Trips.checklist_departure' : 'Trips.arrival', payload);
      UI.result(r); App.route();
    }, { size: 'modal-xl', submit: type === 'Departure' ? 'Submit checklist (Ready for Dispatch)' : 'Submit checklist', onShow(w) {
      const el = w.querySelector('#ckElapsed');
      const tick = () => { const m = Math.floor((Date.now() - started) / 60000); el.textContent = m + ' min elapsed'; };
      tick(); const iv = setInterval(tick, 15000); w.addEventListener('hidden.bs.modal', () => clearInterval(iv));
      w.querySelector('#ckAllOk').onclick = () => w.querySelectorAll('input[type=radio][value=OK]').forEach(r => r.checked = true);
    } });
  },
};

App.page('trips', {
  title: 'Dispatch Board',
  subtitle: 'Scenario A (client → Operations Head), Scenario B (client → Dispatcher) and Scenario C (truck breakdown): review, auto-assignment, fund request, checklist, dispatch, incidents.',
  render(ctx) {
    const d = ctx.data, can = d.can;
    const routeOpts = UI.opts(d.routes, r => `${r.origin} → ${r.destination} (${UI.num(r.distance_km, 1)} km, toll ${UI.money(r.toll_fee)})`);
    const myTypes = UI.role('car_carrier_dispatcher') ? ['Car Carrier'] : UI.role('container_wingvan_dispatcher') ? ['Container', 'Wing Van'] : App.me.truckTypes;
    if (can.create) {
      ctx.tools.innerHTML = `<button class="btn btn-sm btn-primary" id="newTrip"><i class="bi bi-plus-lg"></i> ${UI.role('operations_head') ? 'Create trip' : 'Create delivery request'}</button>`;
      document.getElementById('newTrip').onclick = () => UI.formModal(UI.role('operations_head') ? 'Create trip (Scenario A: client to Operations Head)' : 'Create delivery request (Scenario B: client to Dispatcher)', [
        { name: 'client_id', label: 'Client', type: 'select', required: true, options: UI.opts(d.clients, c => c.name + (c.company_name ? ' / ' + c.company_name : '')), col: 6 },
        { name: 'scheduled_at', label: 'Scheduled date and time', type: 'datetime', required: true, col: 6 },
        { name: 'truck_type', label: 'Truck type', type: 'select', required: UI.role('operations_head'), options: UI.list(myTypes), col: 6,
          help: UI.role('operations_head') ? 'The dispatcher type follows the truck type.' : 'Optional. The Operations Head confirms it.' },
        { name: 'route_id', label: 'Route (from Clients, Routes & Rates)', type: 'select', options: routeOpts, col: 6, placeholder: 'Not in the list (type below)', help: 'The route km is used for the client rate and trip records.' },
        { name: 'origin', label: 'Origin (pickup point)', col: 4 }, { name: 'destination', label: 'Destination (delivery point)', col: 4 },
        { name: 'distance_km', label: 'Total km of the trip', type: 'number', step: '0.1', col: 4 },
        { name: 'booking_reference', label: 'Booking / nomination details', col: 6 }, { name: 'units_planned', label: 'Units (car carrier)', type: 'number', min: 1, col: 6, help: 'Default ' + d.unitsPerTrip + ' per car carrier trip' },
        { name: 'container_number', label: 'Container no.', col: 3 }, { name: 'container_size', label: 'Size (e.g. 1X20, X40)', col: 3 },
        { name: 'seal_number', label: 'Seal no.', col: 3 }, { name: 'shipment_number', label: 'Shipment no.', col: 3 },
        { name: 'remarks', label: 'Delivery info / remarks', type: 'textarea', col: 12, rows: 2 },
      ], 'Trips.create', { transform: (x) => { if (!x.route_id && (!x.origin || !x.destination)) throw new Error('Choose a route or type the origin and destination.'); return x; } });
    }

    /**
     * Dispatch validation (bagong PDF): Confirm Dispatch ay enabled lang kapag
     * trip "Ready for Dispatch" AT fund "Released". Sa redispatch (attempt 2),
     * optional ang fund pero kung may bukas na request, dapat Released ito.
     */
    const dispatchWhy = (t) => {
      const why = [];
      if (t.status !== 'Ready for Dispatch') why.push('Trip must be Ready for Dispatch (do the pre-dispatch checklist).');
      const open = OPEN_FUND.includes(t.last_fund_status);
      if (open && t.last_fund_status !== 'Released') why.push('Fund is ' + t.last_fund_status + ' (needs Released).');
      if (!open && +t.attempt_number === 1) why.push('Fund must be Released (submit the fund request first).');
      return why;
    };
    const actions = (t) => {
      const b = []; const mine = Trip.mine(t); const s = t.status;
      b.push(UI.btn('view', 'Details', 'outline-secondary', 'bi-eye'));
      if (can.review && s === 'Pending Review') b.push(UI.btn('review', 'Review and confirm', 'primary', 'bi-check2-square'));
      if ((can.reassign || mine) && s === 'Awaiting Resources') b.push(UI.btn('auto', 'Try auto-assign again', 'primary', 'bi-magic'));
      if (can.reassign && ['Awaiting Resources', 'Pre-Assigned', 'For Reschedule'].includes(s)) b.push(UI.btn('reassign', 'Assign manually', 'outline-primary', 'bi-person-gear'));
      if ((mine || can.reassign) && !['Completed', 'Cancelled', 'Rejected', 'Pending Review'].includes(s)) b.push(UI.btn('details', t.truck_type === 'Car Carrier' ? 'Trip details' : 'Booking / client steps', 'outline-primary', 'bi-card-checklist'));
      if (mine && ['Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'].includes(s)) {
        if (t.last_fund_status === 'Rejected') b.push(UI.btn('revise', 'Revise fund request', 'warning', 'bi-pencil-square'));
        else if (!OPEN_FUND.includes(t.last_fund_status) && (!t.last_fund_status || +t.attempt_number > 1)) b.push(UI.btn('fund', 'Fund request', 'warning', 'bi-cash-coin'));
      }
      if (mine && s === 'Pre-Assigned') b.push(UI.btn('dep', 'Pre-dispatch checklist', 'warning', 'bi-clipboard-check'));
      if (mine && ['Pre-Assigned', 'Ready for Dispatch'].includes(s)) {
        const why = dispatchWhy(t), label = +t.attempt_number > 1 ? 'Confirm Redispatch' : 'Confirm Dispatch';
        b.push(why.length ? `<button class="btn btn-sm btn-success" disabled title="${UI.esc(why.join(' '))}"><i class="bi bi-send"></i> ${label}</button>` : UI.btn('dispatch', label, 'success', 'bi-send'));
      }
      if (mine && ['Dispatched', 'In Transit'].includes(s)) b.push(UI.btn('progress', 'Update / delay', 'outline-primary', 'bi-chat-left-text'), UI.btn('incident', 'Report incident', 'outline-danger', 'bi-exclamation-triangle'), UI.btn('arr', 'Arrival checklist', 'warning', 'bi-clipboard-check'));
      if (mine && s === 'On Hold - Incident Reported') {
        b.push(UI.btn('progress', 'Update / delay', 'outline-primary', 'bi-chat-left-text'));
        b.push(t.incident_status === 'Repaired - Cleared to Continue' ? UI.btn('resume', 'Resume trip', 'success', 'bi-play-fill')
          : `<button class="btn btn-sm btn-success" disabled title="Waiting for Maintenance: ${UI.esc(t.incident_status || 'assessment')}"><i class="bi bi-play-fill"></i> Resume trip</button>`);
      }
      if (mine && [...ON_ROAD, 'Returned'].includes(s)) b.push(UI.btn('waybill', 'Waybill', 'outline-secondary', 'bi-file-earmark-text'));
      if (mine && s === 'Returned') b.push(UI.btn('complete', 'Submit completed trip', 'success', 'bi-flag'));
      if (mine && s === 'For Reschedule') b.push(UI.btn('reschedule', 'Reschedule trip', 'primary', 'bi-calendar-event'));
      if (can.cancel && ['Pending Review', 'Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'].includes(s)) b.push(UI.btn('cancel', 'Cancel', 'outline-danger', 'bi-x-circle'));
      return b.join('');
    };
    const onAction = (act, t) => {
      const crew = (type) => UI.opts((d.crew || []).filter(c => c.person_type === type), c => `${c.full_name} (${c.availability}${c.license_expiry ? ', lic. ' + c.license_expiry : ''})`);
      switch (act) {
        case 'view': return Trip.details(t, d);
        case 'review': return UI.formModal('Review and complete trip details ' + UI.esc(t.trip_ref), [
          { name: 'trip_id', type: 'hidden', value: t.id },
          { name: 'decision', label: 'Decision', type: 'select', required: true, options: [['approve', 'Confirm: set truck type and let the system assign'], ['reject', 'Reject']], placeholder: false, col: 12 },
          { name: 'truck_type', label: 'Truck type (sets the dispatcher type)', type: 'select', options: UI.list(App.me.truckTypes), value: t.truck_type, col: 4 },
          { name: 'scheduled_at', label: 'Schedule', type: 'datetime', value: (t.scheduled_at || '').slice(0, 16).replace(' ', 'T'), col: 4 },
          { name: 'units_planned', label: 'Units (car carrier)', type: 'number', min: 1, value: t.units_planned, col: 4 },
          { name: 'reason', label: 'Reason (if rejected)', col: 12 }], 'Trips.review', { intro: `${UI.esc(t.client)} · ${Trip.route(t)} · encoded by ${UI.esc(t.created_by_name)}` });
        case 'auto': return UI.confirmAction('Auto-assign', 'Let the system look again for a free truck, driver and helper?', 'Trips.auto_assign', { trip_id: t.id });
        case 'reassign': return UI.formModal('Assign resources for ' + UI.esc(t.trip_ref), [
          { name: 'trip_id', type: 'hidden', value: t.id },
          { name: 'vehicle_id', label: 'Truck', type: 'select', required: true, options: UI.opts((d.vehicles || []).filter(v => v.truck_type === t.truck_type), v => `${v.plate_number} (${v.status}, ${UI.num(v.km_since_pm)} km since PM)`), value: t.vehicle_id, col: 6 },
          { name: 'driver_id', label: 'Driver', type: 'select', required: true, options: crew('Driver'), value: t.driver_id, col: 6 },
          { name: 'driver2_id', label: 'Driver 2 (car carrier)', type: 'select', options: crew('Driver'), value: t.driver2_id, col: 6, placeholder: 'None' },
          { name: 'helper_id', label: 'Helper', type: 'select', options: crew('Helper'), value: t.helper_id, col: 6, placeholder: 'None', help: 'Required for Container and Wing Van' },
          { name: 'reason', label: 'Reason for changing the automatic assignment', required: true, col: 12 }], 'Trips.reassign', { intro: 'The system checks double bookings on the same day (PDF II.B).' });
        case 'details': return UI.formModal('Trip details / client steps — ' + UI.esc(t.trip_ref), [
          { name: 'trip_id', type: 'hidden', value: t.id },
          { name: 'booking_reference', label: 'Booking / nomination', value: t.booking_reference, col: 6 }, { name: 'units_planned', label: 'Units', type: 'number', min: 1, value: t.units_planned, col: 6 },
          { name: 'container_number', label: 'Container no.', value: t.container_number, col: 3 }, { name: 'container_size', label: 'Size', value: t.container_size, col: 3 },
          { name: 'seal_number', label: 'Seal no.', value: t.seal_number, col: 3 }, { name: 'shipment_number', label: 'Shipment no.', value: t.shipment_number, col: 3 },
          { name: 'empty_container_note', label: 'Empty container coordination', value: t.empty_container_note, col: 12 },
          { name: 'remarks', label: 'Remarks', type: 'textarea', rows: 2, value: t.remarks, col: 12 },
          ...(t.truck_type !== 'Car Carrier' ? [
            { name: 'client_confirmed', label: t.truck_type === 'Container' ? 'Client nomination / booking confirmed' : 'Trip confirmed with the client', type: 'checkbox', value: t.client_confirmed_at ? 1 : 0, col: 4, help: t.client_confirmed_at ? 'Done ' + UI.datetime(t.client_confirmed_at) : '' },
            ...(t.truck_type === 'Container' ? [{ name: 'pre_advice_sent', label: 'Pre-advice sent to client', type: 'checkbox', value: t.pre_advice_sent_at ? 1 : 0, col: 4, help: t.pre_advice_sent_at ? 'Done ' + UI.datetime(t.pre_advice_sent_at) : '' }] : []),
            ...(t.truck_type === 'Wing Van' ? [{ name: 'client_advised', label: 'Truck details and plate given to client', type: 'checkbox', value: t.client_advised_at ? 1 : 0, col: 4, help: t.client_advised_at ? 'Done ' + UI.datetime(t.client_advised_at) : '' }] : []),
          ] : []),
        ], 'Trips.details', { intro: t.truck_type === 'Container' ? 'Container steps (PDF II.E): confirm nomination and booking, send pre-advice, coordinate empty container.' : t.truck_type === 'Wing Van' ? 'Wing Van steps (PDF II.E): confirm the trip, give the client the truck details and plate number.' : '' });
        case 'dep': return Trip.checklist(t, d, 'Departure');
        case 'arr': return Trip.checklist(t, d, 'Arrival');
        case 'fund': return UI.fundForm({ title: 'Fund request — ' + UI.esc(t.trip_ref), categories: d.fundCategories, action: 'Funds.request', payload: { trip_id: t.id },
          intro: (+t.attempt_number > 1 ? 'Redispatch: a fund request is optional (only if needed). ' : '') + 'Choose the applicable expenses. The system takes the preset amounts and computes the total. The Operations Head approves it.' });
        case 'revise': return UI.fundForm({ title: 'Revise and resubmit fund request — ' + UI.esc(t.trip_ref), categories: d.fundCategories, action: 'Funds.revise', payload: { fund_id: t.fund_id },
          intro: 'The Operations Head rejected this request. Choose the categories again, then resubmit.' });
        case 'dispatch': return UI.confirmAction(+t.attempt_number > 1 ? 'Confirm Redispatch' : 'Confirm Dispatch', `Confirm that ${UI.esc(t.plate_number)} is actually leaving for ${UI.esc(t.destination)}?${+t.attempt_number > 1 ? '<br><span class="small text-muted">This will be recorded as the Second Attempt of the same trip.</span>' : ''}`, 'Trips.dispatch', { trip_id: t.id });
        case 'resume': return UI.confirmAction('Resume trip', `Maintenance confirmed: Repaired - Cleared to Continue. Resume ${UI.esc(t.trip_ref)}?<br><span class="small text-muted">This continues the original trip (not a second attempt).</span>`, 'Trips.resume', { trip_id: t.id });
        case 'reschedule': return UI.formModal('Reschedule trip — ' + UI.esc(t.trip_ref), [{ name: 'trip_id', type: 'hidden', value: t.id },
          { name: 'scheduled_at', label: 'New dispatch schedule', type: 'datetime', required: true, col: 12, help: 'Truck ' + (t.plate_number || '') + ' is ' + (t.vehicle_status || '') + '. It must be Available (repaired).' }], 'Trips.reschedule',
          { size: '', intro: 'After rescheduling: do the pre-dispatch checklist again (and a fund request if needed), then Confirm Redispatch. It will be recorded as the Second Attempt.' });
        case 'progress': return UI.formModal('Trip update — ' + UI.esc(t.trip_ref), [{ name: 'trip_id', type: 'hidden', value: t.id },
          { name: 'update_type', label: 'Type', type: 'select', required: true, options: UI.list(d.updateTypes), col: 6 },
          { name: 'delay_cause', label: 'Delay cause (if delay)', type: 'select', options: UI.list(d.delayCauses), col: 6, help: 'Client-caused delays are reported separately.' },
          { name: 'note', label: 'Details', type: 'textarea', required: true, col: 12 }], 'Trips.progress', { size: '' });
        case 'incident': return UI.formModal('Incident report — ' + UI.esc(t.trip_ref), [{ name: 'trip_id', type: 'hidden', value: t.id },
          { name: 'truck', label: 'Assigned truck', value: t.plate_number, col: 4, attrs: 'readonly' }, { name: 'trip_ref', label: 'Trip reference', value: t.trip_ref, col: 4, attrs: 'readonly' },
          { name: 'incident_at', label: 'Date and time of incident', type: 'datetime', required: true, col: 4, value: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16) },
          { name: 'location', label: 'Location of incident', required: true, col: 12 },
          { name: 'details', label: 'Incident description (as reported by the driver)', type: 'textarea', required: true, col: 12 },
          { name: 'problem', label: 'Reported problem or damage', required: true, col: 12 },
          { name: 'cause', label: 'Cause (if with damage)', col: 6 }, { name: 'damaged_unit', label: 'Damaged unit / VIN', col: 6 }], 'Trips.incident',
          { submit: 'Submit Incident Report', intro: 'The trip will be On Hold - Incident Reported. Maintenance is notified and decides if it is Minor or Major damage.', transform: (x) => { delete x.truck; delete x.trip_ref; return x; } });
        case 'waybill': return UI.formModal('Waybill — ' + UI.esc(t.trip_ref), [{ name: 'trip_id', type: 'hidden', value: t.id },
          { name: 'waybill_number', label: 'RPM waybill no.', required: true, col: 6 }, { name: 'client_waybill_number', label: 'Client waybill no.', col: 6 },
          { name: 'dr_number', label: 'DR no.', col: 4 }, { name: 'units', label: 'Units', type: 'number', col: 4, value: t.units_planned }, { name: 'submitted_on', label: 'Submitted on', type: 'date', value: UI.today(), col: 4 },
          { name: 'note', label: 'Note', col: 12 }], 'Trips.waybill_add', { size: '' });
        case 'complete': return UI.confirmAction('Submit completed trip', `Submit the completed trip record of ${UI.esc(t.trip_ref)}?${+t.waybill_count ? '' : '<br><b class="text-danger">No waybill recorded yet.</b>'}`, 'Trips.complete', { trip_id: t.id });
        case 'cancel': return UI.confirmAction('Cancel trip', `Cancel ${UI.esc(t.trip_ref)}?`, 'Trips.cancel', { trip_id: t.id }, { danger: true, submit: 'Cancel trip', fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
      }
    };
    const card = (t) => `<div class="trip-card"><div class="trip-head"><div><span class="trip-ref">${UI.esc(t.trip_ref)}</span> ${UI.badge(t.status)}
        ${+t.attempt_number > 1 ? `<span class="badge-s amber">Attempt ${t.attempt_number}</span>` : ''} <span class="text-muted small">${UI.esc(t.truck_type || 'type not set')} · ${UI.esc(UI.label(t.dispatcher_role))}</span></div>
        <div class="small text-muted">${UI.datetime(t.scheduled_at)}</div></div>
      <div class="trip-meta"><span><i class="bi bi-person-vcard"></i> ${UI.esc(t.client)}</span><span><i class="bi bi-geo-alt"></i> ${Trip.route(t)}</span>
        <span><i class="bi bi-truck"></i> ${UI.esc(t.plate_number || '—')}</span><span>${Trip.crew(t)}</span>
        <span><i class="bi bi-cash-coin"></i> Fund: ${t.fund_status ? UI.badge(t.fund_status) + ' ' + UI.money(t.fund_amount) : (t.last_fund_status === 'Rejected' ? UI.badge('Rejected') : '—')}</span>
        ${+t.delay_count ? `<span class="text-danger">${t.delay_count} delay(s)</span>` : ''}${+t.incident_count ? `<span class="text-danger">${t.incident_count} incident(s)</span>` : ''}</div>
      <div class="steps">${Trip.steps(t)}</div><div class="trip-actions" data-trip="${t.id}">${actions(t)}</div></div>`;
    const draw = (box, list, empty) => {
      box.innerHTML = list.length ? list.map(card).join('') : `<div class="table-empty">${empty}</div>`;
      box.querySelectorAll('[data-trip]').forEach(row => row.querySelectorAll('[data-act]').forEach(btn => btn.onclick = () => onAction(btn.dataset.act, (d.trips.concat(d.recentDone)).find(x => +x.id === +row.dataset.trip))));
    };
    const needs = (t) => {
      if (UI.role('operations_head')) return ['Pending Review', 'Awaiting Resources'].includes(t.status) || t.fund_status === 'Pending Approval';
      if (UI.isDispatcher()) return Trip.mine(t) && ['Pre-Assigned', 'Ready for Dispatch', ...ON_ROAD, 'Returned', 'For Reschedule', 'Awaiting Resources'].includes(t.status);
      return false;
    };
    const all = d.trips || [];
    ctx.el.innerHTML = UI.cards([
      { label: 'Pending review', value: all.filter(t => t.status === 'Pending Review').length, icon: 'bi-inbox', color: 'amber' },
      { label: 'Pre-assigned / awaiting', value: all.filter(t => ['Pre-Assigned', 'Awaiting Resources'].includes(t.status)).length, icon: 'bi-calendar-check', color: 'blue' },
      { label: 'Ready for dispatch', value: all.filter(t => t.status === 'Ready for Dispatch').length, icon: 'bi-send-check', color: 'blue' },
      { label: 'On the road', value: all.filter(t => ['Dispatched', 'In Transit', 'Returned'].includes(t.status)).length, icon: 'bi-signpost-split', color: 'green' },
      { label: 'On hold / for reschedule', value: all.filter(t => ['On Hold - Incident Reported', 'For Reschedule'].includes(t.status)).length, icon: 'bi-arrow-repeat', color: 'red' },
    ]);
    UI.tabs(ctx.el, [
      ['needs', 'Needs my action', (b) => draw(b, all.filter(needs), 'Nothing needs your action right now.')],
      ['open', 'All open trips (' + all.length + ')', (b) => draw(b, all, 'No open trips.')],
      ['done', 'Recently closed', (b) => draw(b, d.recentDone || [], 'No closed trips yet.')],
    ], (UI.role('operations_head') || UI.isDispatcher()) ? 'needs' : 'open');
  },
});

App.page('trip_records', {
  title: 'Trip Records & Waybills',
  subtitle: 'Search previous trips by trip no., date, client, truck, driver, dispatcher or waybill.',
  render(ctx) {
    const d = ctx.data, q = ctx.query;
    ctx.el.innerHTML = `<div class="card mb-3"><div class="card-body-custom"><form id="trF">${UI.fields([
      { name: 'q', label: 'Search (trip no., place, booking, container, waybill)', value: q.q, col: 4 },
      { name: 'from', label: 'From', type: 'date', value: q.from, col: 2 }, { name: 'to', label: 'To', type: 'date', value: q.to, col: 2 },
      { name: 'status', label: 'Status', type: 'select', options: UI.list(d.statuses), value: q.status, placeholder: 'All', col: 2 },
      { name: 'truck_type', label: 'Truck type', type: 'select', options: UI.list(App.me.truckTypes), value: q.truck_type, placeholder: 'All', col: 2 },
      { name: 'client_id', label: 'Client', type: 'select', options: UI.opts(d.clients, c => c.name), value: q.client_id, placeholder: 'All', col: 3 },
      { name: 'vehicle_id', label: 'Truck', type: 'select', options: UI.opts(d.vehicles, v => v.plate_number), value: q.vehicle_id, placeholder: 'All', col: 3 },
      { name: 'driver_id', label: 'Driver / helper', type: 'select', options: UI.opts(d.crew, c => c.full_name), value: q.driver_id, placeholder: 'All', col: 3 },
      { name: 'dispatcher_id', label: 'Dispatcher', type: 'select', options: UI.opts(d.dispatchers, c => c.full_name), value: q.dispatcher_id, placeholder: 'All', col: 3 },
    ])}<button class="btn btn-primary btn-sm mt-2"><i class="bi bi-search"></i> Search</button> <a class="btn btn-link btn-sm mt-2" href="#trip_records">Clear</a></form></div></div>`;
    document.getElementById('trF').onsubmit = (e) => { e.preventDefault(); const v = UI.formData(e.target); Object.keys(v).forEach(k => v[k] === '' && delete v[k]); ctx.go('trip_records', v); };
    const actions = (t) => UI.btn('view', 'Details', 'outline-secondary', 'bi-eye') + (d.can.waybill && [...ON_ROAD, 'Returned', 'Completed'].includes(t.status) && (!UI.isDispatcher() || t.dispatcher_role === App.me.user.role) ? UI.btn('waybill', 'Add waybill', 'outline-primary') : '');
    const onAction = (act, t) => {
      if (act === 'view') Trip.details(t, d);
      if (act === 'waybill') UI.formModal('Waybill — ' + UI.esc(t.trip_ref), [{ name: 'trip_id', type: 'hidden', value: t.id },
        { name: 'waybill_number', label: 'RPM waybill no.', required: true, col: 6 }, { name: 'client_waybill_number', label: 'Client waybill no.', col: 6 },
        { name: 'dr_number', label: 'DR no.', col: 4 }, { name: 'units', label: 'Units', type: 'number', col: 4 }, { name: 'submitted_on', label: 'Submitted on', type: 'date', value: UI.today(), col: 4 },
        { name: 'note', label: 'Note', col: 12 }], 'Trips.waybill_add', { size: '' });
    };
    UI.tabs(ctx.el, [
      ['trips', 'Trips (' + (d.trips || []).length + ')', () => UI.table([['Trip', 'trip_ref'], ['Schedule', 'scheduled_at', 'datetime'], ['Client', 'client'], ['Type', 'truck_type'],
        ['Origin', 'origin'], ['Destination', 'destination'], ['Truck', 'plate_number'], ['Driver', 'driver_name'], ['Driver 2', 'driver2_name'], ['Helper', 'helper_name'],
        ['Dispatcher', 'dispatcher_name'], ['Departed', 'departure_at', 'datetime'], ['Arrived', 'arrival_at', 'datetime'], ['Units', 'units_delivered'], ['Waybill', 'waybill_numbers'],
        ['Invoice', 'invoice_number'], ['Status', 'status', 'badge']], d.trips, { id: 'trrec', actions, onAction, title: 'Trip records' })],
      ['waybills', 'Waybills', () => UI.table([['Trip', 'trip_ref'], ['RPM waybill', 'waybill_number'], ['Client waybill', 'client_waybill_number'], ['DR no.', 'dr_number'], ['Units', 'units'], ['Submitted', 'submitted_on', 'date'], ['Recorded by', 'recorded_by_name']], d.waybills, { id: 'wbs', title: 'Waybills' })],
      ...((d.updates || []).length || !UI.role('billing', 'payroll', 'audit') ? [['updates', 'Updates and delays', () => UI.table([['When', 'created_at', 'datetime'], ['Trip', 'trip_ref'], ['Type', 'update_type', 'badge'], ['Delay cause', 'delay_cause', 'badge'], ['Note', 'note'], ['By', 'actor']], d.updates, { id: 'ups', title: 'Progress, delivery and delay updates' })]] : []),
    ]);
  },
});

App.page('incidents', {
  title: 'Incidents',
  subtitle: 'Scenario C: driver calls the dispatcher, dispatcher reports, Maintenance assesses. Minor: mechanic on site, trip resumes. Major: truck repaired, trip rescheduled (Second Attempt).',
  render(ctx) {
    const d = ctx.data;
    ctx.el.innerHTML = UI.table([['Incident date', 'incident_at', 'datetime'], ['Trip', 'trip_ref'], ['Client', 'client'], ['Truck', 'plate_number'], ['Severity', 'severity', (v) => v ? UI.badge(v) : '<span class="text-muted">for assessment</span>'],
      ['Problem / damage', 'problem'], ['Description', 'details'], ['Location', 'location'], ['Cause', 'cause'], ['Damaged unit', 'damaged_unit'], ['Assessment notes', 'action_taken'], ['Reported by', 'reported_by_name'],
      ['Maintenance job', 'job_number'], ['Job status', 'job_status', 'badge'], ['Expected repair', 'expected_completion', 'date'], ['Trip status', 'trip_status', 'badge'], ['Incident', 'status', 'badge']],
      d.incidents, { id: 'inc', title: 'Incident reports', empty: 'No incidents reported.' });
    UI.activate(ctx.el);
  },
});
