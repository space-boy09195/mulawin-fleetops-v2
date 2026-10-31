# Changelog

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
