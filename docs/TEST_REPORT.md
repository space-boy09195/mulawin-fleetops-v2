# Test report — 2026-10-31 (after the FMS PDF version 2 flow update)

Environment: Ubuntu 24.04, PHP 8.3.6, MariaDB 10.11.14, PHP built-in server, Chromium (Playwright).
Fresh empty database imported from `database/schema.sql` + `database/extension.sql`.

| Test | Result |
|---|---|
| `php -l` on every PHP file; `node --check` on every JS file | PASS |
| `scripts/preflight.php` (extensions, folders, every module loads, tables) | PASS |
| `tests/formula_test.php` (Billing P&L, fund categories, DRIVERS PAYROLL) — 15 checks | PASS |
| `tests/e2e_workflow.py` full workflow through the API (all 10 roles) — 191 checks, 0 failures | PASS |
| Scenario A: Ops Head creates trip → auto-assign → Pre-Assigned; no automatic fund | PASS |
| Fund request by dispatcher only (own team): 5 preset categories + added line, 100+100+300+50 = ₱550 | PASS |
| Fund approval by Operations Head only (Finance and Admin blocked) → Ready for Release | PASS |
| Finance releases before the checklist; cheque needs a cheque number | PASS |
| Dispatch blocked until trip Ready for Dispatch AND fund Released; no Ops Head clearance needed | PASS |
| Scenario B: delivery request → Pending Review → Ops Head confirms → Pre-Assigned | PASS |
| Fund rejected → dispatcher revises same request (₱20,900, still Ops Head: no ₱15,000 limit) → approved → released | PASS |
| Container: booking + pre-advice required before the checklist | PASS |
| Checklist: 57 items, NOT OK keeps trip Pre-Assigned, restart required, odometer check | PASS |
| Scenario C.1 minor: On Hold → Maintenance assesses Minor → Repaired - Cleared to Continue → Resume → In Transit, attempt stays 1 | PASS |
| Scenario C.2 major: For Reschedule, truck Under Maintenance → repair → Available → Reschedule → checklist → Confirm Redispatch (Second Attempt, same trip, both checklists kept) | PASS |
| Only Motorpool assesses; job cannot be completed before assessment | PASS |
| Preventive maintenance at 10,000 km: PM job created, truck Under Maintenance after the trip, km reset after PM | PASS |
| Liquidation updates the Trip Allowance expense | PASS |
| Purchasing: >₱10k Admin, ≤₱10k Finance; no budget → blocked; insufficient → "Rejected - Insufficient Budget" by system | PASS |
| Billing, payroll, documents, backup (unchanged flows) | PASS |
| Formula Settings: fund categories accept fixed amounts only; new preset used for new request (₱350), old request unchanged (₱550); P&L still uses fuel | PASS |
| Every page for every role in the browser (no JS errors, no failed sections, tabs clicked) | PASS |
| UI click-through: Create trip, Fund request form (live total ₱425), approve, Confirm release, checklist, disabled then enabled Confirm Dispatch, incident form, Assess damage, Confirm repair, Resume trip | PASS |
| Module isolation: broken `Funds.php` → funds page 503, Trips / Dashboard still 200 | PASS |

### Re-test 2026-11-01 (after grouping modules into department folders)

| Test | Result |
|---|---|
| `php -l` on every PHP file; `node --check` on every JS file | PASS |
| `scripts/preflight.php` (every module loads from its department folder) | PASS |
| `tests/formula_test.php` on a fresh database — 15 checks | PASS |
| `tests/e2e_workflow.py` — 191 checks, 0 failures | PASS |
| Every page for every role in the browser | PASS |
| UI click-through (create trip to resume trip) | PASS |
| Module isolation: broken `Accounting/Payroll.php` → payroll 503; trips, billing, dashboard still 200 | PASS |

### Re-test 2026-11-01 (after the navigation polish)

| Test | Result |
|---|---|
| `php -l` / `node --check` on every file | PASS |
| `scripts/preflight.php`; `tests/formula_test.php` on a fresh database (15 checks) | PASS |
| `tests/e2e_workflow.py` — 191 checks, 0 failures | PASS |
| Every page for every role, every tab, sub-tab (pills), table picker and "Show more charts" clicked: no JS errors, no failed sections | PASS |
| UI click-through (create trip to resume trip) | PASS |
| Pagination (Prev/Next, search resets to page 1), sidebar accordion, Back to top | PASS |
| Phone (390 px wide): no page wider than the screen | PASS |

Page height before → after (1440×900 screen, test data): Reports (Admin) 6,949 → 1,454 px; Reports (Audit) 5,858 → 1,454;
Formula Settings 4,806 → 900; Dashboard (Admin) 2,706 → 1,557; Dashboard (Ops Head) 1,724 → 1,394. Longest page now: 1,664 px.

Notes:
- `tests/formula_test.php` compares with the Excel defaults, so run it on a database where Formula Settings were not changed.
- The Apache `.htaccess` rules were not changed in this update (last Apache test: PASS).

Not tested: real XAMPP on Windows, Hostinger, many users at the same time, mobile phones in depth, printing on a real printer.
