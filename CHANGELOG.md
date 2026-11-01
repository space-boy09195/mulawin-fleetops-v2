# Changelog

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
