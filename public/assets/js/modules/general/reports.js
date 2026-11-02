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
    // Print: ang nakabukas na section o lahat ng sections (may header, period at filters).
    ctx.tools.innerHTML = `<div class="dropdown"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button" id="repPrint"><i class="bi bi-printer"></i> Print</button>
      <div class="dropdown-menu dropdown-menu-end"><button class="dropdown-item" type="button" data-print="one"><i class="bi bi-file-earmark me-2"></i>Print this section</button>
      <button class="dropdown-item" type="button" data-print="all"><i class="bi bi-files me-2"></i>Print all sections</button></div></div>`;
    // Laging kita: period at group by. Ang ibang filters ay nasa "More filters" (bukas kapag may gamit).
    const more = ['truck_type', 'status', 'client_id', 'vehicle_id', 'driver_id', 'dispatcher_id'].filter(k => ctx.query[k]).length;
    ctx.el.innerHTML = `<div class="card mb-3 no-print report-filters"><div class="card-body-custom"><form id="repForm"><div class="row g-2 align-items-end">${[
      { name: 'preset', label: 'Quick period', type: 'select', col: 2, options: [['today', 'Today'], ['week', 'This week (Mon-Sun)'], ['month', 'This month'], ['last_month', 'Last month'], ['year', 'This year']], placeholder: 'Custom' },
      { name: 'from', label: 'From', type: 'date', col: 2, value: q.from }, { name: 'to', label: 'To', type: 'date', col: 2, value: q.to },
      { name: 'group', label: 'Group by', type: 'select', col: 2, options: [['day', 'Daily'], ['week', 'Weekly'], ['month', 'Monthly']], value: q.group, placeholder: false },
    ].map(UI.field).join('')}<div class="col-md-4 d-flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Show report</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="repMore"><i class="bi bi-sliders"></i> More filters${more ? ` (${more})` : ''}</button>
      <button type="button" class="btn btn-link btn-sm" id="repClear">Clear filters</button></div></div>
      <div class="row g-2 mt-1" id="repMoreBox" ${more ? '' : 'hidden'}>${[
      { name: 'truck_type', label: 'Truck type', type: 'select', col: 2, options: UI.list(App.me.truckTypes), value: q.truck_type, placeholder: 'All' },
      { name: 'status', label: 'Trip status', type: 'select', col: 2, options: UI.list(['Completed', 'Dispatched', 'In Transit', 'On Hold - Incident Reported', 'For Reschedule', 'Ready for Dispatch', 'Pre-Assigned', 'Pending Review', 'Cancelled']), value: q.status, placeholder: 'All' },
      { name: 'client_id', label: 'Client', type: 'select', col: 2, options: opts(f.clients, 'name'), value: q.client_id, placeholder: 'All' },
      { name: 'vehicle_id', label: 'Truck', type: 'select', col: 2, options: opts(f.vehicles, 'plate_number'), value: q.vehicle_id, placeholder: 'All' },
      { name: 'driver_id', label: 'Driver / helper', type: 'select', col: 2, options: opts(f.crew, 'full_name'), value: q.driver_id, placeholder: 'All' },
      { name: 'dispatcher_id', label: 'Dispatcher', type: 'select', col: 2, options: opts(f.dispatchers, 'full_name'), value: q.dispatcher_id, placeholder: 'All' },
    ].map(UI.field).join('')}</div></form></div></div><div id="repBody"></div>`;
    document.getElementById('repMore').onclick = () => { const b = document.getElementById('repMoreBox'); b.hidden = !b.hidden; };
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
    // Buod ng period at filters (para alam agad kung ano ang tinitingnan, at kasama sa print).
    const nameOf = (rows, id, key) => ((rows || []).find(r => String(r.id) === String(id)) || {})[key] || id;
    const groupLbl = { day: 'Daily', week: 'Weekly', month: 'Monthly' }[d.range.group || q.group] || '';
    const chips = [groupLbl && ['Group by', groupLbl], q.truck_type && ['Truck type', q.truck_type], q.status && ['Status', q.status],
      q.client_id && ['Client', nameOf(f.clients, q.client_id, 'name')], q.vehicle_id && ['Truck', nameOf(f.vehicles, q.vehicle_id, 'plate_number')],
      q.driver_id && ['Driver / helper', nameOf(f.crew, q.driver_id, 'full_name')], q.dispatcher_id && ['Dispatcher', nameOf(f.dispatchers, q.dispatcher_id, 'full_name')]].filter(Boolean);
    const period = `${UI.date(d.range.from)} to ${UI.date(d.range.to)}`;
    const filterText = chips.map(([k, v]) => `${k}: ${v}`).join(' · ');
    const body = document.getElementById('repBody');
    // Isang tab bawat section (Trips, Fleet, Money, Payroll, Stock ayon sa role) para hindi mahaba ang page.
    body.innerHTML = `<div class="report-summary text-muted mb-2" id="repSummary">Showing: <b>${UI.esc(period)}</b>${chips.map(([k, v]) => ` <span class="badge text-bg-light border ms-1">${UI.esc(k)}: ${UI.esc(v)}</span>`).join('')}</div>`;
    const sections = d.sections || [];
    ctx.tools.querySelectorAll('[data-print]').forEach(b => b.onclick = () => {
      if (!sections.length) return UI.toast('Nothing to print for your role.', 'warn');
      const cur = sessionStorage.getItem('tab:reports');
      const pick = b.dataset.print === 'all' ? sections : [sections.find(s => s.title === cur) || sections[0]];
      UI.printReport(pick, { title: 'Reports & Analytics' + (pick.length === 1 ? ': ' + pick[0].title : ''), period, filters: filterText });
    });
    if (!sections.length) { body.insertAdjacentHTML('beforeend', '<div class="table-empty">No report sections for your role.</div>'); return; }
    UI.tabs(body, sections.map((s, i) => [s.title, `<i class="bi ${s.icon} me-1"></i>${UI.esc(s.title)}`,
      () => `${s.note ? `<div class="section-note mt-0">${UI.esc(s.note)}</div>` : ''}${UI.blocks(s, { key: s.title })}`]));
  },
});
