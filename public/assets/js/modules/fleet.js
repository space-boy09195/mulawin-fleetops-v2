/* assets/js/modules/fleet.js — Fleet Status & Trucks, Drivers/Helpers/Staff
   (FMS PDF II.B and II.C). Backend: backend/Modules/Fleet.php */
'use strict';

App.page('fleet', {
  title: 'Fleet Status & Trucks',
  subtitle: 'Truck records, OR/CR and insurance expiry, status, assignment and maintenance history.',
  render(ctx) {
    const d = ctx.data, can = d.can, vs = d.vehicles || [];
    const count = (s) => vs.filter(v => +v.active && v.status === s).length;
    const crewOpts = (type) => UI.opts((d.crew || []).filter(c => c.person_type === type), c => c.full_name);
    if (can.edit) ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="addTruck"><i class="bi bi-plus-lg"></i> Add truck</button>';
    const truckForm = (v = {}) => UI.formModal(v.id ? 'Edit truck ' + UI.esc(v.plate_number) : 'Add truck', [
      { name: 'id', type: 'hidden', value: v.id },
      { name: 'plate_number', label: 'Plate number', required: true, value: v.plate_number, col: 4 },
      { name: 'truck_number', label: 'Truck / unit no.', value: v.truck_number, col: 4, help: 'NO column in monitoring sheets' },
      { name: 'truck_type', label: 'Truck type', type: 'select', options: UI.list(App.me.truckTypes), required: true, value: v.truck_type, col: 4 },
      { name: 'make_brand', label: 'Make / brand', value: v.make_brand, col: 4 }, { name: 'model', label: 'Model', value: v.model, col: 4 },
      { name: 'mv_file_number', label: 'MV file no.', value: v.mv_file_number, col: 4 },
      { name: 'chassis_number', label: 'Chassis no.', value: v.chassis_number, col: 6 }, { name: 'engine_number', label: 'Engine no.', value: v.engine_number, col: 6 },
      { name: 'or_number', label: 'OR no.', value: v.or_number, col: 4 }, { name: 'cr_number', label: 'CR no.', value: v.cr_number, col: 4 },
      { name: 'registration_expiry', label: 'Registration expiry', type: 'date', value: v.registration_expiry, col: 4 },
      { name: 'insurance_provider', label: 'Insurance provider', value: v.insurance_provider, col: 4 },
      { name: 'insurance_policy_number', label: 'Policy no.', value: v.insurance_policy_number, col: 4 },
      { name: 'insurance_expiry', label: 'Insurance expiry', type: 'date', value: v.insurance_expiry, col: 4 },
      { name: 'assigned_driver_id', label: 'Regular driver', type: 'select', options: crewOpts('Driver'), value: v.assigned_driver_id, placeholder: 'None', col: 6, help: 'Preferred by auto-assignment' },
      { name: 'assigned_helper_id', label: 'Regular helper', type: 'select', options: crewOpts('Helper'), value: v.assigned_helper_id, placeholder: 'None', col: 6 },
      ...(v.id ? [] : [{ name: 'odometer_km', label: 'Current odometer (km)', type: 'number', value: 0, col: 6 },
        { name: 'km_since_pm', label: 'Km since last preventive maintenance', type: 'number', value: 0, col: 6 }]),
      { name: 'notes', label: 'Notes', type: 'textarea', value: v.notes, col: 12, rows: 2 },
    ], 'Fleet.vehicle_save');
    if (can.edit) document.getElementById('addTruck').onclick = () => truckForm();

    ctx.el.innerHTML = UI.cards([
      { label: 'Available', value: count('Available'), icon: 'bi-truck-front', color: 'green' },
      { label: 'Assigned', value: count('Assigned'), icon: 'bi-calendar-check', color: 'blue' },
      { label: 'On Trip', value: count('On Trip'), icon: 'bi-signpost-split', color: 'blue' },
      { label: 'Under Maintenance', value: count('Under Maintenance'), icon: 'bi-tools', color: 'red' },
      { label: 'Unavailable', value: count('Unavailable'), icon: 'bi-slash-circle', color: 'gray' },
    ]);
    UI.tabs(ctx.el, [
      ['trucks', 'Trucks', () => UI.table([
        ['Plate', 'plate_number'], ['No.', 'truck_number'], ['Type', 'truck_type'], ['Status', 'status', 'badge'], ['Current trip', 'current_trip'],
        ['Driver', 'driver_name'], ['Helper', 'helper_name'], ['Odometer', 'odometer_km', 'num'],
        ['Km since PM', 'km_since_pm', (x) => `${UI.num(x)} <span class="text-muted small">/ ${UI.num(d.pmInterval)}</span>`],
        ['Registration', 'registration_days_left', (x, r) => r.registration_expiry ? UI.date(r.registration_expiry) + '<br>' + UI.days(x) : '—'],
        ['Insurance', 'insurance_days_left', (x, r) => r.insurance_expiry ? UI.date(r.insurance_expiry) + '<br>' + UI.days(x) : '—'],
        ['Expected available', 'expected_available', 'date'], ['Active', 'active', 'yes'],
      ], vs, {
        id: 'trucks', empty: 'No trucks registered yet. The Admin adds trucks here.',
        actions: (r) => (can.edit ? UI.btn('edit', 'Edit', 'outline-primary', 'bi-pencil') : '') + (can.status ? UI.btn('status', 'Status', 'outline-warning', 'bi-arrow-left-right') : '') +
          UI.btn('history', 'History', 'outline-secondary', 'bi-clock-history') + (can.edit ? UI.btn('active', +r.active ? 'Deactivate' : 'Activate', 'outline-danger') : ''),
        onAction(act, r) {
          if (act === 'edit') truckForm(r);
          if (act === 'status') UI.formModal('Update status of ' + UI.esc(r.plate_number), [
            { name: 'vehicle_id', type: 'hidden', value: r.id },
            { name: 'status', label: 'New status', type: 'select', required: true, options: UI.list(UI.role('admin') ? ['Available', 'Under Maintenance', 'Unavailable'] : ['Available', 'Under Maintenance']) },
            { name: 'reason', label: 'Reason / remarks', col: 12 }], 'Fleet.vehicle_status', { size: '', intro: 'Current status: ' + r.status + '. Trucks held by a trip are changed through the trip (incident or cancellation).' });
          if (act === 'history') {
            const jobs = (d.maintenanceHistory || []).filter(m => +m.vehicle_id === +r.id);
            const log = (d.statusLog || []).filter(l => l.plate_number === r.plate_number);
            UI.modal('History of ' + UI.esc(r.plate_number), '<h6>Maintenance history</h6>' + UI.table([['Job', 'job_number'], ['Type', 'maintenance_type'], ['Status', 'status', 'badge'], ['Details', 'description'], ['Completed', 'completed_at', 'datetime']], jobs, { id: 'hj' + r.id }) +
              '<h6 class="mt-3">Status changes</h6>' + UI.table([['When', 'created_at', 'datetime'], ['From', 'from_status', 'badge'], ['To', 'to_status', 'badge'], ['Reason', 'reason'], ['By', 'actor']], log, { id: 'hl' + r.id }), null, { size: 'modal-xl', onShow: (w) => UI.activate(w) });
          }
          if (act === 'active') UI.confirmAction(+r.active ? 'Deactivate truck' : 'Activate truck', `${+r.active ? 'Deactivate' : 'Activate'} ${UI.esc(r.plate_number)}?`, 'Fleet.vehicle_active', { vehicle_id: r.id, active: +r.active ? 0 : 1 });
        },
      })],
      ['log', 'Status history', () => UI.table([['When', 'created_at', 'datetime'], ['Truck', 'plate_number'], ['From', 'from_status', 'badge'], ['To', 'to_status', 'badge'], ['Reason', 'reason'], ['By', 'actor']], d.statusLog, { id: 'vlog' })],
    ]);
  },
});

App.page('crew', {
  title: 'Drivers, Helpers & Staff',
  subtitle: 'Employee and contractor records, license expiry, availability and trip history.',
  render(ctx) {
    const d = ctx.data, can = d.can, ps = d.people || [];
    const active = ps.filter(p => p.employment_status === 'Active');
    const cnt = (type, av) => active.filter(p => p.person_type === type && (!av || p.availability === av)).length;
    if (can.edit) ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="addPerson"><i class="bi bi-plus-lg"></i> Add person</button>';
    const personForm = (p = {}) => UI.formModal(p.id ? 'Edit ' + UI.esc(p.full_name) : 'Add person', [
      { name: 'id', type: 'hidden', value: p.id },
      { name: 'full_name', label: 'Full name', required: true, value: p.full_name, col: 6 }, { name: 'employee_no', label: 'Employee / contractor no.', value: p.employee_no, col: 6 },
      { name: 'person_type', label: 'Position', type: 'select', required: true, options: UI.list(d.personTypes), value: p.person_type, col: 4 },
      { name: 'employment_type', label: 'Employee or contractor', type: 'select', options: UI.list(['Employee', 'Contractor']), value: p.employment_type || 'Employee', placeholder: false, col: 4 },
      { name: 'employment_status', label: 'Status', type: 'select', options: UI.list(['Active', 'Resigned', 'Inactive']), value: p.employment_status || 'Active', placeholder: false, col: 4 },
      { name: 'contact_number', label: 'Contact number', value: p.contact_number, col: 6 }, { name: 'date_hired', label: 'Date hired', type: 'date', value: p.date_hired, col: 6 },
      { name: 'address', label: 'Address', value: p.address, col: 12 },
      { name: 'license_number', label: "Driver's license no.", value: p.license_number, col: 6 }, { name: 'license_expiry', label: 'License expiry', type: 'date', value: p.license_expiry, col: 6, help: 'Required for drivers' },
      { name: 'payroll_group', label: 'Payroll group', value: p.payroll_group, col: 4, help: 'e.g. South Carrier, South Container, North Container, Wing Van' },
      { name: 'pay_frequency', label: 'Pay frequency', type: 'select', options: UI.list(['Weekly', 'Semi-monthly']), value: p.pay_frequency || 'Weekly', placeholder: false, col: 4 },
      { name: 'daily_rate', label: 'Daily rate (PHP)', type: 'money', value: p.daily_rate, col: 4 },
      { name: 'notes', label: 'Notes', type: 'textarea', rows: 2, value: p.notes, col: 12 },
    ], 'Fleet.person_save');
    if (can.edit) document.getElementById('addPerson').onclick = () => personForm();

    ctx.el.innerHTML = `<div class="section-note">Staffing: compare the available drivers / helpers with the trips planned (Dispatch Board).</div>` + UI.cards([
      { label: 'Active drivers', value: cnt('Driver'), icon: 'bi-person-badge', color: 'green', hint: cnt('Driver', 'Available') + ' available, ' + cnt('Driver', 'On Trip') + ' on trip' },
      { label: 'Active helpers', value: cnt('Helper'), icon: 'bi-people', color: 'green', hint: cnt('Helper', 'Available') + ' available, ' + cnt('Helper', 'On Trip') + ' on trip' },
      { label: 'Unavailable drivers / helpers', value: cnt('Driver', 'Unavailable') + cnt('Helper', 'Unavailable'), icon: 'bi-slash-circle', color: 'red' },
      { label: 'Licenses expiring ≤ 30 days', value: active.filter(p => p.license_days_left !== null && +p.license_days_left <= 30).length, icon: 'bi-card-heading', color: 'amber' },
      { label: 'Resigned / inactive', value: ps.length - active.length, icon: 'bi-person-x', color: 'gray' },
    ]);
    const cols = [['Name', 'full_name'], ['No.', 'employee_no'], ['Position', 'person_type'], ['Type', 'employment_type'], ['Status', 'employment_status', 'badge'],
      ['Availability', 'availability', 'badge'], ['License expiry', 'license_days_left', (x, r) => r.license_expiry ? UI.date(r.license_expiry) + '<br>' + UI.days(x) : '—'],
      ['Regular truck', 'assigned_truck'], ['Trips (recent)', 'recent_trips', 'num'], ['Completed trips', 'total_trips', 'num']];
    if (can.pay) cols.push(['Payroll group', 'payroll_group'], ['Daily rate', 'daily_rate', 'money'], ['Pay', 'pay_frequency']);
    if (!UI.role('payroll')) cols.splice(2, 0, ['Contact', 'contact_number']);
    const body = document.createElement('div'); ctx.el.append(body);
    body.innerHTML = UI.table(cols, ps, {
      id: 'people', empty: 'No personnel yet. The Admin adds drivers, helpers and staff here.',
      actions: (r) => (can.edit ? UI.btn('edit', 'Edit', 'outline-primary', 'bi-pencil') : '') + (can.pay && !can.edit ? UI.btn('pay', 'Pay info', 'outline-primary') : '') +
        (can.availability && r.availability !== 'On Trip' && r.employment_status === 'Active' ? UI.btn('avail', r.availability === 'Available' ? 'Set unavailable' : 'Set available', 'outline-warning') : '') +
        ((d.tripHistory || []).length ? UI.btn('trips', 'Trips', 'outline-secondary', 'bi-list') : ''),
      onAction(act, r) {
        if (act === 'edit') personForm(r);
        if (act === 'pay') UI.formModal('Pay information of ' + UI.esc(r.full_name), [{ name: 'id', type: 'hidden', value: r.id },
          { name: 'daily_rate', label: 'Daily rate (PHP)', type: 'money', required: true, value: r.daily_rate, col: 4 },
          { name: 'payroll_group', label: 'Payroll group', value: r.payroll_group, col: 4 },
          { name: 'pay_frequency', label: 'Pay frequency', type: 'select', options: UI.list(['Weekly', 'Semi-monthly']), value: r.pay_frequency, placeholder: false, col: 4 }], 'Fleet.person_pay', { size: '' });
        if (act === 'avail') UI.confirmAction('Change availability', `Set ${UI.esc(r.full_name)} as ${r.availability === 'Available' ? 'Unavailable' : 'Available'}?`, 'Fleet.person_availability', { id: r.id, availability: r.availability === 'Available' ? 'Unavailable' : 'Available' });
        if (act === 'trips') {
          const t = d.tripHistory.filter(x => [x.driver_id, x.driver2_id, x.helper_id].map(Number).includes(+r.id));
          UI.modal('Trip history of ' + UI.esc(r.full_name), UI.table([['Trip', 'trip_ref'], ['Date', 'scheduled_at', 'datetime'], ['Client', 'client'], ['Destination', 'destination'], ['Status', 'status', 'badge']], t, { id: 'th' + r.id }), null, { size: 'modal-xl', onShow: (w) => UI.activate(w) });
        }
      },
    });
    UI.activate(body);
  },
});
