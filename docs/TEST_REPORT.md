# Test report — 2026-10-31

Environment: Ubuntu 24.04, PHP 8.3.6, MariaDB 10.11.14, Apache 2.4 (mod_rewrite, .htaccess) and PHP built-in server,
Chromium (Playwright). Fresh empty database imported from `database/schema.sql` + `database/extension.sql`.

| Test | Result |
|---|---|
| `php -l` on every PHP file; `node --check` on every JS file | PASS |
| `scripts/preflight.php` (extensions, folders, every module loads, tables) | PASS |
| `tests/formula_test.php` (formulas vs Billing P&L and DRIVERS PAYROLL cells) — 11 checks | PASS |
| `tests/e2e_workflow.py` full workflow through the API (all 10 roles) — 120+ checks, 0 failures | PASS |
| Scenario A: Ops Head trip → auto-assign → fund ₱4,226 (=143/4×100+651) → Ops Head approval | PASS |
| Scenario B: dispatcher request → Ops Head approval → fund ₱18,700 > ₱15,000 → Admin approval only | PASS |
| Checklist: 57 items, NOT OK blocks Ready for Dispatch, restart required, odometer check | PASS |
| Dispatch blocked without clearance / release; container booking + pre-advice required | PASS |
| Minor incident: trip continues, truck stays On Trip; Major: Rescheduled → repair → attempt #2 | PASS |
| Preventive maintenance at 10,000 km: job created, truck Under Maintenance after trip, km reset after PM | PASS |
| Purchasing: >₱10k Admin, ≤₱10k Finance; no budget → blocked; insufficient → "Rejected - Insufficient Budget" by system | PASS |
| Stock out / stock in, payable + parts expense, cheque payment needs cheque no. | PASS |
| Billing: due date from client terms, trip cannot be billed twice, partial collection, over-payment blocked | PASS |
| Payroll: wage 600×6, trip pay 800, SSS 193.75, PhilHealth 75, Pag-IBIG 50, CA 500, uniform 500, cash bond 500 → net 2,581.25 | PASS |
| Payroll line edit (manual + override), finalize posts balances, reopen reverses, Finance disburses | PASS |
| Formula Settings: role limits, new value used for new fund (₱3,632.25), old fund unchanged | PASS |
| Documents: bulk upload, download, role block (403); backup create + download | PASS |
| Every page for every role in the browser (no JS errors, no failed sections, tabs clicked) | PASS |
| UI forms: new trip, fund approval, checklist modal with timer and "Mark all OK" | PASS |
| Module isolation: broken `Payroll.php` → payroll 503, Trips / Dashboard still 200 | PASS |
| Module isolation: broken `payroll.js` → payroll page message, Trips / Billing still work | PASS |
| Apache: `/` → `public/`; `.env`, `backend`, `config`, `database`, `docs`, `storage`, `README.md` → 403 | PASS |

Not tested: real XAMPP on Windows, Hostinger, many users at the same time, mobile phones in depth, printing on a real printer.
