/* assets/js/modules/general/reports.js — Reports & Analytics (FMS PDF IV and II.F).
   Filters: daily / weekly / monthly / custom range, truck type, truck,
   driver, dispatcher, client, status. Each table has a CSV download. */
'use strict';
App.page('reports', {
  title: 'Reports & Analytics',
  subtitle: 'Trip performance, fleet, billing, payroll and inventory summaries. Download any table as CSV or print the page.',
  render(ctx) {
    const d = ctx.data, f = d.filters || {}, q = { ...d.range, ...ctx.query };
    const opts = (rows, key) => UI.opts(rows, r => r[key]);
    ctx.tools.innerHTML = '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>';
    ctx.el.innerHTML = `<div class="card mb-3 no-print"><div class="card-body-custom"><form id="repForm">${UI.fields([
      { name: 'preset', label: 'Quick period', type: 'select', col: 2, options: [['today', 'Today'], ['week', 'This week (Mon-Sun)'], ['month', 'This month'], ['last_month', 'Last month'], ['year', 'This year']], placeholder: 'Custom' },
      { name: 'from', label: 'From', type: 'date', col: 2, value: q.from }, { name: 'to', label: 'To', type: 'date', col: 2, value: q.to },
      { name: 'group', label: 'Group by', type: 'select', col: 2, options: [['day', 'Daily'], ['week', 'Weekly'], ['month', 'Monthly']], value: q.group, placeholder: false },
      { name: 'truck_type', label: 'Truck type', type: 'select', col: 2, options: UI.list(App.me.truckTypes), value: q.truck_type, placeholder: 'All' },
      { name: 'status', label: 'Trip status', type: 'select', col: 2, options: UI.list(['Completed', 'Dispatched', 'In Transit', 'On Hold - Incident Reported', 'For Reschedule', 'Ready for Dispatch', 'Pre-Assigned', 'Pending Review', 'Cancelled']), value: q.status, placeholder: 'All' },
      { name: 'client_id', label: 'Client', type: 'select', col: 3, options: opts(f.clients, 'name'), value: q.client_id, placeholder: 'All' },
      { name: 'vehicle_id', label: 'Truck', type: 'select', col: 3, options: opts(f.vehicles, 'plate_number'), value: q.vehicle_id, placeholder: 'All' },
      { name: 'driver_id', label: 'Driver / helper', type: 'select', col: 3, options: opts(f.crew, 'full_name'), value: q.driver_id, placeholder: 'All' },
      { name: 'dispatcher_id', label: 'Dispatcher', type: 'select', col: 3, options: opts(f.dispatchers, 'full_name'), value: q.dispatcher_id, placeholder: 'All' },
    ])}<div class="mt-2"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Show report</button>
      <button type="button" class="btn btn-link btn-sm" id="repClear">Clear filters</button></div></form></div></div><div id="repBody"></div>`;
    const form = document.getElementById('repForm');
    form.preset.onchange = () => {
      const t = new Date(UI.today()), iso = (x) => { x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10); };
      let a = new Date(t), b = new Date(t);
      if (form.preset.value === 'week') { a.setDate(t.getDate() - ((t.getDay() + 6) % 7)); b = new Date(a); b.setDate(a.getDate() + 6); form.group.value = 'day'; }
      if (form.preset.value === 'month') { a = new Date(t.getFullYear(), t.getMonth(), 1); b = new Date(t.getFullYear(), t.getMonth() + 1, 0); form.group.value = 'week'; }
      if (form.preset.value === 'last_month') { a = new Date(t.getFullYear(), t.getMonth() - 1, 1); b = new Date(t.getFullYear(), t.getMonth(), 0); form.group.value = 'week'; }
      if (form.preset.value === 'year') { a = new Date(t.getFullYear(), 0, 1); b = new Date(t.getFullYear(), 11, 31); form.group.value = 'month'; }
      if (form.preset.value === 'today') form.group.value = 'day';
      form.from.value = iso(a); form.to.value = iso(b);
    };
    form.onsubmit = (e) => { e.preventDefault(); const v = UI.formData(form); delete v.preset; Object.keys(v).forEach(k => v[k] === '' && delete v[k]); ctx.go('reports', v); };
    document.getElementById('repClear').onclick = () => ctx.go('reports', {});
    const body = document.getElementById('repBody');
    body.innerHTML = `<div class="small text-muted mb-2">Period: <b>${UI.date(d.range.from)}</b> to <b>${UI.date(d.range.to)}</b></div>` +
      (d.sections || []).map(s => `<div class="section-title"><i class="bi ${s.icon}"></i>${UI.esc(s.title)}</div>${s.note ? `<div class="section-note">${UI.esc(s.note)}</div>` : ''}${UI.blocks(s)}`).join('');
    UI.activate(body);
  },
});
