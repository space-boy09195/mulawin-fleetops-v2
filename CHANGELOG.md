# Changelog

## 2026-11-04 (system revision: masterlists, simplified trip workflow, objective alignment)
Reference files: PDF #14 (objectives), PDF #12 (workflow), PDF #11 (system requirements), PDF #13 (form formats).
Client dummy data: CAR CARRIER and CONTAINER MONITORING 2026 workbooks (only the fields the system needs).

**Database (new install required, see "Install / upgrade" below)**
- `database/schema.sql` and `database/extension.sql` rewritten (35 tables). Masterlists are central and every
  transaction refers to them by foreign key: `vehicles` (trucks), `personnel` (drivers and helpers), `clients`
  (+ `client_rates` per truck type). Unique keys on plate number, client company name, driver/helper name + type.
- Availability is enforced in the database, not only in the screen: table `trip_assignments` has one row per
  reserved truck/driver/helper (primary key = resource), so the same resource can never be on two active trips.
  CHECK constraints on statuses and amounts (stock cannot go below zero), foreign keys on all links,
  one checklist per trip attempt, one billing per trip, one fund request per trip attempt.
- New `database/seed_masterlists.sql` (optional): 29 trucks (15 Car Carrier, 7 Container, 7 Wing Van),
  62 drivers, 18 helpers and 13 clients taken from the client workbooks. Clients start as "Incomplete"
  (documents and rates are not in the workbooks).
- Removed `database/migrations/` (old upgrade files for the previous schema; they do not apply to the new one).

**Masterlists**
- Trucks: truck number, plate, type, registration/insurance dates, regular driver/helper, status history,
  last PMS date. Status is computed by the system (Available, Assigned, On Trip, Under Maintenance) plus a manual
  Unavailable with reason. "Under Maintenance" always has an open maintenance job behind it.
- Drivers & Helpers: license number/expiry for drivers, availability (Available, Assigned, On Trip, Unavailable).
- Clients: company profile, contact, TIN, payment terms, required documents (signature, company profile,
  SEC/DTI, business permit, BIR 2303) uploaded as files, per-km rate and minimum charge per truck type.
  Status: Incomplete -> Pending Rate -> Active / Inactive. Only Active clients can be chosen for trips.
- All forms use dropdowns from the masterlists; deactivation is soft (history is kept).

**Trip workflow (PDF #12, simplified)**
- Statuses: Pending Review, Awaiting Resources, Pre-Assigned, Ready for Dispatch, Dispatched, In Transit,
  On Hold - Incident Reported, For Reschedule, Completed, Cancelled, Rejected. Each trip shows a progress
  stepper and a timeline.
- Operations Head reviews (approve or reject with reason). Approval auto-assigns truck, driver and helper
  (same truck type, Available, no open repair; regular crew first; then fewest completed trips in the last 30 days;
  helper required for Container/Wing Van, second driver optional for Car Carrier). If nothing is free the trip is
  "Awaiting Resources" and is assigned automatically when a resource becomes free. Manual reassignment with reason.
- If an assigned truck or person becomes unavailable, the system re-assigns or moves the trip back to Awaiting Resources.
- Pre-dispatch checklist (57 items, "Mark all OK" helper); any NOT OK keeps the trip from Ready for Dispatch and
  notifies Motorpool. Confirm Dispatch needs Ready for Dispatch + Released fund (checked again on the server).
- Pickup/drop-off are chosen on a map (Leaflet + OpenStreetMap, vendored in `public/assets/vendor/leaflet/`);
  distance is entered in km and used for billing. Map tiles need internet.
- Completion requires Waybill and Acknowledgement Receipt files and a completion time not earlier than dispatch.
- Incidents: Minor (truck stays on the trip; Confirm Repair -> Resume) or Major (truck Under Maintenance, trip
  For Reschedule; after repair: Reschedule -> second attempt -> new checklist -> Confirm Redispatch).
  Printable incident report.

**Funds, billing, finance, purchasing, maintenance**
- Trip fund: preset categories (Admin > Fund categories) + added lines; Ops Head approves or rejects; rejected
  requests are corrected and resubmitted; Finance releases (payment voucher + expense); liquidation records returned cash.
  Printable Request for Cash Fund (PDF #13 format).
- Billing: draft created at dispatch from the client's per-km rate (minimum charge applies) + additional charges;
  becomes For Billing on completion; Billing verifies Waybill + Acknowledgement Receipt, finalizes the Service Invoice
  (due date = invoice date + client terms); collections require an uploaded receipt; Partially Paid / Paid; SOA print.
- Purchasing: parts requests from Motorpool released from stock; purchase orders route to Finance (up to the
  approval limit, default 10,000) or Admin (above); budget is checked and reserved atomically, otherwise
  "Rejected - Insufficient Budget"; partial receiving; Finance pays received value. PO print with e-signature.
- Maintenance: incident repairs, on-premise repairs (photos required), monthly PMS reminders, Confirm Repair
  requires details and evidence; parts and labor costs go to expenses.
- Reports & Analytics: trips, fleet (downtime from status history), money (revenue, expenses, profit per trip,
  receivables aging), stock; filters with Apply/Reset; comparison with previous period.

**Removed (not needed by the objectives, or by instruction)**
- Payroll, Attendance, Cash Advance & Balances (module, pages, role `payroll`, tables).
- English/Tagalog language switch (`public/assets/js/i18n.js`, `public/assets/js/i18n/`, `users.language`).
- Per-role account limits, arrival checklist, 15-minute minimum checklist time, container/wing van client steps,
  saved routes table, Formula Settings computation lists (replaced by simple Settings + Fund categories).
- Duplicate/stale files: `backend/Modules/*.php` (top level), `backend/Modules/Inspections/`,
  `public/assets/js/modules/*.js` (top level), `backend/Core/TripFunds.php`.

**"Pending company confirmation" items settled into final rules**
- Auto-assign priority (C01), allowance categories (C02), dispatch order (C03), budget (one active Purchasing budget
  set by Finance, C05), checklist (all items OK, no minimum time, C06), redispatch fund optional (C07),
  PMS monthly instead of km-based (C08), 5 units per car carrier trip default (C09), billing per-km rate + minimum,
  VAT shown at 12% on the invoice, due date from client terms (C10), partial receiving allowed (C11), 30-day expiry
  reminders (C12), access per role in `backend/Core/Access.php` (C13), payroll removed (C14), Ops Head may reject a
  request (C17), client steps removed (C18), liquidation kept (C19), Awaiting Resources with automatic assignment (C20),
  billing documents = Waybill + Acknowledgement Receipt. No `TODO PENDING COMPANY CONFIRMATION` marks remain in code.
- Note: `docs/PENDING_COMPANY_CONFIRMATION.md`, `docs/`, `guide/`, `README.md` and `README-initial.md` were not
  modified (project rule) and still describe the previous version. `config/formulas.php` was not modified; the
  system reads only these defaults from it: purchase approval limit, document/license reminder days, payment terms,
  km per liter, diesel price and units per trip (its payroll and computation-list values are no longer used).

**Install / upgrade**
- New install: import `database/schema.sql`, then `database/extension.sql`, then (optional) `database/seed_masterlists.sql`;
  create the first Admin with `public/install.php` or `php scripts/create_user.php admin USERNAME "FULL NAME"`.
- The old database and the old backup in `storage/backups/` are for the previous schema and cannot be restored into this version.

**Verification (what was actually run)**
- Separate API script (outside the project) covering all workflows, role restrictions and database safeguards: 124 passed, 0 failed.
- Browser smoke test of every page and tab for every role: no JavaScript errors. Browser end-to-end flow
  (client -> trip -> fund -> dispatch -> completion -> invoice -> payment -> reports): 24 passed, 0 failed.
- Existing project tests were run without modification and do NOT pass, because they test removed features:
  `tests/formula_test.php` stops at `Settings::fuelCost()` (Formula Settings removed); `tests/e2e_workflow.py`
  stops when it cannot create/login the `payroll` role; `tests/system_test.py` (`--mode xampp --no-browser`):
  33 passed, 3 failed (all 3 are the payroll account), then stops at the account stage.

## 2026-11-02 (security, limit bawat role, mapa)
- **Lock ng account:** 5 maling password kahit saang computer = naka-lock nang 15 minuto (dati: bawat browser lang).
  Ang "Reset password" ng Admin ay nag-a-unlock din.
- **Recovery code ng Admin** (simple, walang email o setup): pangalan > Recovery code (kailangan ang kasalukuyang password),
  i-print at itago. Sa login page: "Forgot the Admin password? Use the recovery code". Isang beses lang gumagana ang code.
  May paalala sa dashboard ng Admin kapag wala pang code. Huling paraan para sa IT: `scripts/reset_password.php`.
- Kapag napalitan ang password (ng user, ng Admin o gamit ang recovery code), mala-log out ang lahat ng lumang session ng account.
- **Limit bawat role** (User Accounts > Limits per role): ilang active na account bawat role; dispatchers bawat shift (Day / Night);
  0 = walang limit; Admin kahit 1. Default: tig-iisa. Ang punong role o shift ay hindi na lalabas sa pagpipilian. Sinusunod din
  ito ng `scripts/create_user.php`.
- **Mapa (Google Maps embed, walang API key at walang bayad, gaya ng sa mulawin_fleetops repo):** sa Add / Edit route, sa
  paggawa ng trip, sa Trip details, at sa Map button ng Dispatch Board. May "Open in Google Maps" para makita ang layo sa km.
- **DATABASE CHANGE:** `database/migrations/2026-11-04_account_security.sql` (bagong install: kasama na sa `schema.sql`).
- Test: may checks na para dito sa `tests/system_test.py` (14c at mapa).

## 2026-11-02 (wika: English o Tagalog)
- Sa dropdown ng pangalan (kung nasaan ang Log Out): **Wika / Language: English o Tagalog**. Naka-save sa account, kaya
  pareho saan mang computer o phone mag-login. English ang default.
- Tagalog: pang-opisinang Taglish sa lahat ng nakikita sa screen (buttons, labels, tabs, cards, charts, forms, mensahe ng
  system at notifications). Hindi isinasalin: mga pangalan ng page sa menu, mga status at termino ng PDF at Excel
  (Pre-Assigned, Ready for Dispatch, Billed, SSS ...), at ang mga printout (invoice, payslip, payment voucher, printed report).
- Paano gumagana: `public/assets/js/i18n.js` (tagasalin) at `public/assets/js/i18n/tl.js` (diksiyunaryo). Para magdagdag
  o magpalit ng salin, i-edit ang `tl.js`; walang ibang file na kailangang galawin.
- Sa English, English na rin ang ilang tekstong Tagalog dati sa screen (halimbawa ang tabs ng Finance: Expenses & Payables,
  Employees, Planning).
- **DATABASE CHANGE:** bagong column `users.language`. Bagong install: kasama na sa `schema.sql`. Live: i-import ang
  `database/migrations/2026-11-03_user_language.sql` pagkatapos ng backup (ligtas kahit dalawang beses).
- Test: may language checks na sa `tests/system_test.py` (API at browser sa Tagalog).

## 2026-11-02 (polish: analytics)
- **Bug fix:** gumagana na ang Print sa Reports & Analytics (dati ay blangkong page, o lumang payslip ang lumalabas).
  Dalawang pagpipilian: Print this section at Print all sections. May header (kumpanya, period, filters, sino at kailan
  nag-print), cards, charts at buong tables.
- Charts: may ₱ at comma ang pera sa axis at tooltip, may porsyento ang bilog na charts, buong numero lang ang bilang,
  iisang kulay bawat kahulugan sa lahat ng chart, pahigang bar para sa client at dispatcher, stacked na maintenance cost
  per truck (pinakamalaki muna). Bagong chart sa Reports > Payroll (gross, deductions at net kada period, galing sa
  parehong data ng table). Mas maikli ang charts sa phone.
- Cards: may maliit na arrow ang card na may link at bubukas sa mismong tab o filter (halimbawa Overdue balance ->
  Billing > Receivables by client). Hindi na clickable kapag wala sa menu ng role ang page.
- Dashboard: "Updated" na oras sa tabi ng Refresh. Reports: buod ng period at filters sa itaas ng tabs.
- Labels: ang card group na "Pera" ay "Money" na; ₱ na rin ang hints ng cards (dating "PHP").
- Walang database change at walang binagong formula.

## 2026-11-02 (automated system test)
- Bagong `tests/system_test.py` (at `tests/run_system_test.bat`): pipili ng XAMPP o Hostinger, magla-login bilang Admin,
  gagawa ng test account kada role kung wala, susubukan ang buong workflow at mga bawal na gawain sa API, iki-click ang bawat
  page at tab ng bawat role sa browser, at gagawa ng HTML report na may screenshots. Guide: `tests/SYSTEM_TEST_GUIDE.md`.
- Login: hindi na nabubura ang username kapag mali ang password.
- Walang database change sa update na ito.

## 2026-11-02 (FMS PDF v6 at v7)
- **Billing draft (PDF v7, Scenario A step 9 / B step 10):** sa Confirm Dispatch (ongoing na ang trip), gumagawa ang
  system ng billing draft batay sa client rate ng route (`backend/Core/BillingDraft.php`). Sa Billing > Billing drafts:
  Edit (habang Draft), **Verify documents** (kailangan may waybill), **Finalize** (trip Completed + verified + may rate),
  Return to draft. Ang invoice ay galing na sa finalized billings (tab na Ready to invoice); hindi na tina-type ang rate.
  Ang cancel ng invoice ay nagbabalik sa Finalized.
- **Drivers at helpers lang (PDF v6):** inalis ang Admin Staff at PDI Personnel, ang "Employee o Contractor" at ang
  Active/Resigned sa screen. Ang page ay "Drivers & Helpers". Para sa drivers at helpers lang ang Payroll at cash advance.
- **Payroll:** sinusunod ang PDF v6 (attendance, trip pay, deductions). Bukas pa ang tanong kung per biyahe lang ang
  sahod ng drivers (C13); pwedeng i-switch sa settings nang walang code change (tingnan ang PENDING_COMPANY_CONFIRMATION).
- **DATABASE CHANGE:** bagong table `billing_drafts`. Bagong install: `schema.sql` + `extension.sql`. Existing database
  (live): i-import ang `database/migrations/2026-11-02_billing_drafts.sql` pagkatapos ng backup.
- Maliit na ayos: hindi na kumukurap ang nakadikit na header sa maiikling page.

## 2026-11-01 (polish: navigation at layout)
- Sidebar: isang section lang ang bukas (accordion); bubukas ang section ng current page.
- Nakadikit ang page header (title at buttons) habang nag-i-scroll; may "Back to top" button.
- Reports & Analytics: isang tab bawat section (Trips, Fleet, Money, Payroll, Stock ayon sa role); ang mga table ng
  section ay nasa iisang card na may pills; ang walang laman ay isang linya na lang; "More filters" para sa
  truck type, status, client, truck, driver at dispatcher.
- Dashboards: alerts, cards, 2 charts (ang iba ay "Show more"), tapos tables sa iisang card na may pills.
  Ang cards ng Admin at Operations Head ay naka-grupo (Trips, Fleet, Pera / Fleet at crew).
- Formula Settings: tabs (Values na may pill bawat group, Computation lists, Example); editable muna, View only sa huli.
- Finance & Payables: 8 tabs naka-grupo sa 3 (Gastos at Bayarin, Empleyado, Plano).
- Work pages: compact na stat cards para kita agad ang table.
- Tables: 25 rows bawat page na may Prev at Next (dating "Show more"). Search at CSV ay sakop pa rin ang lahat ng rows.
- Phone: tabs na naka-swipe, 2 cards bawat hilera, walang page na lumalampas sa lapad ng screen.
- Walang binago sa database, workflow, permissions o formulas. Files: `core.js`, `app.css`, `general/reports.js`,
  `general/dashboard.js`, `administration/admin.js`, `accounting/finance.js`, `backend/Modules/General/Analytics.php`.

## 2026-11-01 (modules grouped by department)
- Backend modules moved to department folders: `backend/Modules/Operations/` (Fleet, Trips, Inspections),
  `Maintenance/` (Maintenance, Purchasing), `Accounting/` (Funds, Finance, Billing, Payroll),
  `General/` (Analytics, Documents), `Administration/` (Admin).
- Screen files moved the same way: `public/assets/js/modules/<department>/<file>.js`.
- `Actions::MODULES` is now a map (module => department); `Access.php`, `public/index.php`, `scripts/preflight.php`
  and the storage paths of Admin and Documents were updated. The sidebar was NOT changed.
- File header comments, README, docs/SOURCE_TRACEABILITY.csv and TEST_REPORT use the new paths.
- No change to the workflow, database or formulas.
- New guides: one user guide per department (Operations, Maintenance, Accounting), one for Admin and Audit,
  and a full Technical Documentation (architecture, API, database, data sources, security, deployment).

## 2026-10-31 (flow update: FMS PDF version 2)
- Trip statuses follow the new PDF: Pending Review, Pre-Assigned, Ready for Dispatch, Dispatched,
  On Hold - Incident Reported, In Transit, For Reschedule, Returned, Completed (plus Awaiting Resources, Cancelled, Rejected).
- Fund request is now made by the **dispatcher**: preset categories (Forklift Operator 100, Checker 100, Passway 200,
  Driver Meal 300, Helper Meal 200) plus added categories; the system computes the total.
- **Removed**: automatic fund (fuel + toll), the ₱15,000 fund approval limit (Admin no longer approves funds),
  Finance "Process" step and "Pending Release", Operations Head departure clearance.
- Operations Head approves every fund → "Ready for Release"; reject → dispatcher revises and resubmits the same request.
- Finance can release the fund before the pre-dispatch checklist. Confirm Dispatch is enabled only when
  fund = Released and trip = Ready for Dispatch (disabled button shows what is missing).
- Scenario C: dispatcher incident report (date/time, location, description, problem) puts the trip On Hold;
  Maintenance assesses Minor or Major. Minor: Repaired - Cleared to Continue → Resume Trip (In Transit, not a second
  attempt). Major: truck Under Maintenance, trip For Reschedule → repair → Reschedule Trip → Confirm Redispatch (Second Attempt).
- Kept: ₱10,000 purchase approval limit, arrival checklist and Submit completed trip, liquidation.
- Database: trips.ops_cleared_* and fund_requests.processed_* removed; incidents get incident_at, problem,
  assessed_by, assessed_at and a nullable severity. Use a new empty database (schema.sql + extension.sql).

## 2026-10-31 (update)
- Minimum password length changed from 12 to 8 (letters and numbers still required). One setting: `PASSWORD_MIN_LENGTH` in `backend/bootstrap.php`.

## 2026-10-31 — Aligned V2 (V2 structure + repo design + PDF workflow + Excel formulas)
- Workflow follows the FMS PDF: Scenario A/B, automatic assignment, automatic allowance, fund approval limits
  (Ops Head ≤ ₱15,000, Admin > ₱15,000), Finance processing, checklist → Ready for Release, release, clearance,
  dispatch, incidents (minor / major), 2nd attempt, preventive maintenance by km.
- Added back from the PDF / repo: Payroll and Timekeeping (attendance, payroll, payslips, deduction form, cash advance,
  uniform, damages, cash bond), dashboards per role, reports and analytics with charts, notifications, activity log,
  backup, Formula Settings, document bulk upload, purchase budget check by the system.
- Formulas from Excel: Billing P&L (fuel, RFID, misc %, salaries, net income, margin), Drivers Payroll (wage, SSS 775/24,
  PhilHealth 75, Pag-IBIG 50, cash bond 500, net), Car Carrier (5 units per trip, company → client weekly summary),
  Container (down-truck days).
- Design from the `mulawin_fleetops` repo (layout.css, login.css, Bootstrap 5, Bootstrap Icons, Chart.js).
- Module isolation: one PHP module + one JS screen file per module; each page loads its own data.
- New empty database (schema.sql + extension.sql). Roles renamed: `head_management` → `admin`; added `payroll`.
