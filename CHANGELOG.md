# Changelog

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
