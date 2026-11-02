/* assets/js/modules/general/documents.js — Document Management (FMS PDF II.M and II.O).
   Upload one or many files (bulk), link them to a record, set expiry, search and download.
   Backend: backend/Modules/General/Documents.php */
'use strict';
App.page('documents', {
  title: 'Documents',
  subtitle: 'OR/CR, insurance, contracts, forms, waybills, receipts and photos. Files are private and checked by role before download.',
  render(ctx) {
    const d = ctx.data, docs = d.documents || [];
    const labels = { trip: 'Trip', vehicle: 'Truck', personnel: 'Driver / helper', client: 'Client', maintenance: 'Maintenance job', inspection: 'Inspection (checklist)', purchase: 'Purchase request', parts: 'Part', fund: 'Fund request', expense: 'Expense', payable: 'Account payable', voucher: 'Payment voucher', billing: 'Invoice', waybill: 'Waybill', payroll: 'Payroll period', administrative: 'Administrative (not linked)' };
    if (d.uploadTypes.length) {
      ctx.tools.innerHTML = '<button class="btn btn-sm btn-primary" id="upl"><i class="bi bi-upload"></i> Upload</button>';
      document.getElementById('upl').onclick = () => UI.modal('Upload documents', UI.fields([
        { name: 'files', label: 'Files (PDF, JPG, PNG, WebP; up to 10 MB each; up to 20 at once)', type: 'file', multiple: true, required: true, col: 12, attrs: 'accept=".pdf,.jpg,.jpeg,.png,.webp"' },
        { name: 'category', label: 'Category', type: 'select', required: true, options: UI.list(d.categories), col: 6 },
        { name: 'entity_type', label: 'Attach to', type: 'select', required: true, options: d.uploadTypes.map(t => [t, labels[t] || t]), col: 6 },
        { name: 'entity_id', label: 'Record ID number', type: 'number', min: 1, col: 6, help: 'The # / ID of the trip, truck, job, etc. (leave blank for Administrative)' },
        { name: 'expires_on', label: 'Expiry date (if any)', type: 'date', col: 6, help: 'For OR/CR, insurance, permits, contracts' },
        { name: 'notes', label: 'Description', col: 12 }]), async (x, form) => {
        const fd = new FormData();
        [...form.querySelector('input[type=file]').files].forEach(f => fd.append('files[]', f));
        ['category', 'entity_type', 'entity_id', 'expires_on', 'notes'].forEach(k => fd.append(k, x[k] || ''));
        UI.result(await App.upload(fd)); App.route();
      }, { submit: 'Upload' });
    }
    const soon = docs.filter(x => x.days_left !== null && +x.days_left <= d.alertDays);
    ctx.el.innerHTML = UI.cards([
      { label: 'Documents', value: docs.length, icon: 'bi-folder2-open', color: 'green' },
      { label: `Expiring within ${d.alertDays} days / expired`, value: soon.length, icon: 'bi-calendar-x', color: soon.length ? 'red' : 'green' },
    ]);
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = UI.table([['#', 'id'], ['File', 'original_filename'], ['Category', 'category'], ['Attached to', 'entity_type', (x, r) => UI.esc(labels[x] || x) + (r.entity_id ? ' #' + r.entity_id : '')],
      ['Expiry', 'expires_on', (x, r) => x ? UI.date(x) + '<br>' + UI.days(r.days_left) : '—'], ['Description', 'notes'], ['Size', 'byte_size', (x) => (x / 1024).toFixed(0) + ' KB'], ['Uploaded by', 'uploader'], ['Date', 'created_at', 'datetime']], docs, {
      id: 'docs', empty: 'No documents you can view yet.',
      actions: () => UI.btn('open', 'View', 'outline-secondary', 'bi-eye') + UI.btn('dl', 'Download', 'outline-primary', 'bi-download'),
      onAction: (act, x) => window.open('index.php?api=document_download&id=' + x.id + (act === 'open' ? '&inline=1' : ''), '_blank'),
    });
    UI.activate(box);
  },
});
