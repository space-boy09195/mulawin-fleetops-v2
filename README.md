# Mulawin FleetOps Technical Documentation

Oct 31, 2026 · @kenneth

## Buod ng system

Ang Mulawin FleetOps (Fleet Management System V2) ay web-based na system para sa R P Mulawin Trucking Service. Hawak nito ang buong daloy ng trip, mula request, assignment, fund, inspection at dispatch, hanggang maintenance, purchasing, billing, collections at payroll.

| Item | Detalye |
| --- | --- |
| Pangalan | Mulawin FleetOps (RP Mulawin FMS V2) |
| Client | R P Mulawin Trucking Service, Tanauan City, Batangas |
| Uri | Web application na tumatakbo sa browser (desktop at mobile) |
| Users | 10 roles: Admin, Operations Head, Car Carrier Dispatcher, Container / Wing Van Dispatcher, Motorpool, Purchasing, Finance, Billing, Payroll, Audit |
| Truck types | Car Carrier, Container, Wing Van |
| Pangunahing source | R-P-Mulawin-Trucking Trucking Management System PDF (workflow at roles); ang Scenario A, B at C ay galing sa updated na bersyon 2 |
| Source ng formula | Dummy Computation for Billing PL.xlsx, DRIVERS PAYROLL 1.xlsx, CAR CARRIER at CONTAINER MONITORING 2026 |
| Base code | Repo na mulawin\_fleetops (branch system-hardening), inayos ayon sa structure ng RP\_Mulawin\_FMS\_V2 zip |

## Tech stack

Plain PHP at MySQL ang system, walang framework at walang build step, para madaling i-host sa XAMPP o shared hosting gaya ng Hostinger.

| Layer | Technology | Version | Gamit |
| --- | --- | --- | --- |
| Backend language | PHP | 8.2 o mas bago (na-test sa 8.3) | Lahat ng business logic, API at computation |
| Database access | PDO (pdo\_mysql) | Kasama ng PHP | Prepared statements at transactions |
| Database | MySQL o MariaDB | MySQL 8 / MariaDB 10.4+ (na-test sa MariaDB 10.11) | 43 tables, InnoDB, utf8mb4 |
| Web server | Apache na may .htaccess | 2.4 | Hinaharangan ang folders na hindi dapat makita sa browser |
| Frontend | HTML, CSS, vanilla JavaScript | ES2020 | Single-page app: isang page lang, papalit-palit ang laman |
| UI framework | Bootstrap | 5.3.3 | Layout, forms, modals, tabs |
| Icons | Bootstrap Icons | 1.11.3 | Icons sa sidebar at buttons |
| Charts | Chart.js | 4.4.0 | Charts sa Dashboard at Reports |
| Design | layout.css at login.css mula sa repo, app.css |  | Forest green na sidebar (#123a28) at gold accent (#c78c00) |
| PHP extensions | pdo\_mysql, mbstring, fileinfo |  | Database, text, at pag-check ng uri ng uploaded file |
| Version control | Git at GitHub |  | Source code at auto-deploy |
| Hosting | XAMPP (local), Hostinger (live) |  | Hostinger Git auto-deployment mula GitHub |

Naka-save sa loob ng system ang Bootstrap, Bootstrap Icons at Chart.js (public/assets/vendor), hindi galing sa CDN. Gumagana ang itsura kahit mabagal ang internet o down ang CDN.

## Architecture

Single-page app ang frontend at JSON API ang backend, at iisa ang pasukan ng lahat: public/index.php.

&#91;embedded content: architecture · browser hanggang database\]

Ang browser ay nagpapadala ng request sa ?api=data&page=... (pagkuha ng laman ng page), ?api=Module.action (pag-save, halimbawa ?api=Trips.create), o ?api=login, logout, me, document\_upload, document\_download at backup\_download. Pinipili ng Actions.php ang tamang module, at ginagamit ng module ang Core para sa mga shared na gawain bago magbasa o mag-save sa database.

## Folder structure

Ang public/ lang ang dapat bukas sa browser. Ang ibang folders ay hinaharangan ng .htaccess.

| Folder o file | Laman at gamit |
| --- | --- |
| index.php (root) | Nire-redirect ang bisita papunta sa public/ |
| .htaccess (root) | Hinaharangan ang backend, config, database, docs, scripts, storage at tests |
| .env.example | Template ng settings: database, timezone, company name, session timeout, install token |
| public/index.php | Iisang pasukan ng system: HTML ng app at lahat ng API request (?api=) |
| public/install.php | Pang-isang-beses na paggawa ng unang Admin account |
| public/assets/css | layout.css at login.css (galing sa repo), app.css (dagdag na style) |
| public/assets/js/core.js | Login, sidebar, routing, tables, forms, modals, charts at CSV |
| public/assets/js/modules | Isang JS file bawat module: dashboard, fleet, trips, maintenance, purchasing, finance, billing, payroll, documents, reports, admin |
| public/assets/vendor | Bootstrap, Bootstrap Icons at Chart.js |
| backend/bootstrap.php | Settings, database connection, session, input checks at mga helper |
| backend/Actions.php | Taga-tawag ng tamang module, may proteksyon para hindi madamay ang ibang module kapag may error |
| backend/Data.php | Impormasyon ng naka-login na user, sidebar at notifications |
| backend/Core | Mga shared na bahagi: Access (pages bawat role), Settings (formula engine), FleetState (status ng truck at crew), TripFunds (pag-compute ng fund), Money (vouchers), Notify (abiso) |
| backend/Modules | Fleet, Trips, Funds, Maintenance, Purchasing, Finance, Billing, Payroll, Documents, Analytics, Admin, at Inspections/Checklist (57 items) |
| config/formulas.php | Default na values ng lahat ng formula, may paliwanag sa Tagalog |
| database | schema.sql (11 core tables) at extension.sql (32 tables) |
| storage/private | Uploaded na documents (hindi makikita sa browser nang direkta) |
| storage/backups | Mga .sql backup na ginawa sa Backup page |
| scripts | create\_user.php (gumawa ng user sa command line), preflight.php (i-check kung handa ang server) |
| tests | formula\_test.php at e2e\_workflow.py |
| docs | Gabay sa pagbago ng formula, Hostinger preparation, pending confirmations, source traceability, test report |

## Modules at pages

May 11 backend modules at 22 pages. Bawat page ay hawak ng isang backend module at isang JS file, kaya kapag nagka-error ang isang module, gumagana pa rin ang iba.

| Module (backend/Modules) | Pages | JS file | Saklaw |
| --- | --- | --- | --- |
| Analytics | Dashboard, Reports & Analytics | dashboard.js, reports.js | Dashboard bawat role, reports na may filter (petsa, truck type, truck, driver, dispatcher, client, status) |
| Fleet | Fleet Status & Trucks, Drivers, Helpers & Staff | fleet.js | Trucks, status history, crew, availability, pay info |
| Trips | Dispatch Board, Trip Records & Waybills, Incidents | trips.js | Trip request (Scenario A at B), auto-assign, checklist, dispatch validation, updates, incident report, resume, reschedule at redispatch (Second Attempt), waybill |
| Maintenance | Maintenance | maintenance.js | Incident assessment (Minor o Major), repair jobs, PM ayon sa km, schedules, parts used, gastos |
| Purchasing | Parts Inventory, Purchasing | purchasing.js | Parts, suppliers, parts requests, purchase request, PO, receiving, stock movement |
| Funds | Trip Funds & Allowances | finance.js | Fund request ng dispatcher (preset categories), Ops Head approval, revise at resubmit, release, liquidation |
| Finance | Finance & Payables | finance.js | Expenses, payables, disbursements, vouchers, cash advance, budgets, payroll disbursement, cash flow |
| Billing | Billing & Collections, Clients, Routes & Rates | billing.js | Clients, routes, rates, invoices, collections, receivables |
| Payroll | Payroll, Attendance, Cash Advance & Balances | payroll.js | Trip pay rates, attendance, payroll periods, payslips, deductions, employee ledgers |
| Documents | Documents | documents.js | Upload at download ng files na naka-link sa record |
| Admin | Formula Settings, User Accounts, Activity Log, Backup | admin.js | Users, formula settings, audit log, database backup, change password, notifications |

Paano naiiwasan ang pagkadamay ng ibang module:

- Nilo-load ang module file sa loob ng try/catch. Kapag may error, "temporarily unavailable" lang ang module na iyon.
- May sariling data request ang bawat page.
- Hiwa-hiwalay na kinukuha ang bawat bahagi ng page. Kapag pumalya ang isang bahagi, gumagana pa rin ang natitira.
- Ang mga bagay na ginagamit ng ilang module (fund computation, truck status, vouchers, abiso) ay nasa backend/Core para hindi magtawagan ang mga module sa isa't isa.

## Database

May 43 tables, lahat InnoDB at utf8mb4. I-import muna ang schema.sql (11 tables) bago ang extension.sql (32 tables). Walang laman na data ang dalawang file; ang default na formula values ay galing sa config/formulas.php hangga't walang binabago sa Formula Settings.

| Area | Tables | Bilang |
| --- | --- | --- |
| Users at system | users, audit\_events, notifications, app\_settings, formula\_items | 5 |
| Master data | clients, routes, client\_rates, personnel, vehicles, vehicle\_status\_log | 6 |
| Trips at dispatch | trips, trip\_events, trip\_updates, inspections, incidents, waybills | 6 |
| Trip funds at finance | fund\_requests, expenses, accounts\_payable, payment\_vouchers, disbursements, budgets | 6 |
| Maintenance | maintenance\_jobs, maintenance\_parts\_used, maintenance\_costs, maintenance\_schedules | 4 |
| Parts at purchasing | parts, suppliers, part\_requests, purchase\_requests, purchase\_orders, stock\_movements | 6 |
| Billing | billings, billing\_items, collections | 3 |
| Payroll | trip\_pay\_rates, attendance, employee\_ledgers, ledger\_entries, payroll\_periods, payroll\_lines | 6 |
| Documents | documents | 1 |

Mahahalagang disenyo:

- **Snapshot ng computation:** naka-save sa fund request at payroll line ang breakdown at values na ginamit. Kapag binago ang formula, hindi magbabago ang lumang records.
- **History:** may sariling log ang trip status (trip\_events), truck status (vehicle\_status\_log), stock (stock\_movements) at lahat ng ginawa ng users (audit\_events).
- **Reference numbers:** kusang ginagawa ng system ang numero na may prefix at taon: TRP (trip), JOB (maintenance job), PR (purchase request), PO (purchase order), PV (payment voucher). Halimbawa: TRP-2026-0001.

Mga status ayon sa bagong PDF. Trip: Pending Review, Awaiting Resources, Pre-Assigned, Ready for Dispatch, Dispatched, On Hold - Incident Reported, In Transit, For Reschedule, Returned, Completed, Cancelled, Rejected. Fund request: Pending Approval, Ready for Release, Released, Completed, Rejected, Cancelled. Incident: Reported, Under Repair, Repaired - Cleared to Continue, For Reschedule, Resolved.

## Roles at access

May 10 roles ayon sa "User Roles and Access Requirements" ng FMS PDF. Ang listahan ng pages bawat role ay nasa backend/Core/Access.php, at sinusuri ulit ng bawat module ang role bago mag-save.

| Role | Bilang ng pages | Pangunahing gawain |
| --- | --- | --- |
| Admin | 22 | Users, malalaking approval, formula settings, backup |
| Operations Head | 11 | Review ng trip request, fund approval hanggang ₱15,000, departure clearance |
| Car Carrier Dispatcher | 9 | Trips ng car carrier |
| Container / Wing Van Dispatcher | 9 | Trips ng container at wing van |
| Motorpool | 7 | Maintenance, PM, request ng parts |
| Purchasing | 5 | Inventory, suppliers, purchase request at PO |
| Finance | 11 | Fund release, payables, expenses, budget, payroll disbursement |
| Billing | 6 | Clients, rates, invoices, collections |
| Payroll | 10 | Attendance, trip pay, payroll, balances |
| Audit | 12 | Tingin lang sa financial records at approval history |

Ang buong detalye ng bawat role ay nasa hiwalay na doc: [Mulawin FleetOps User Guide](https://claude.ai/artifact/NZNcUwFfDc9Zmq91ryekVy).

## Mga formula at computation

Lahat ng formula ay galing sa Excel files at sa FMS PDF, at pwedeng baguhin sa Formula Settings page nang walang coding. Ang default values ay nasa config/formulas.php.

### Fund request at P&L per trip

| Computation | Formula | Source |
| --- | --- | --- |
| Fund request | Kabuuan ng piniling preset categories + dagdag na linya ng dispatcher | FMS PDF v2 Scenario B step 4 |
| Preset categories | Forklift Operator ₱100, Checker ₱100, Passway ₱200, Driver Meal Allowance ₱300, Helper Meal Allowance ₱200 | FMS PDF v2 Scenario B step 4 |
| Fuel (P&L lang) | (total km ÷ 4 km/L) × ₱100 bawat litro | Dummy Computation for Billing PL |
| RFID / Toll (P&L lang) | Toll ng route | Billing PL |
| Misc. Expense Logistics | 3% ng client rate | Billing PL G28 |
| Misc. Expense PDI | 5% ng client rate | Billing PL G29 |
| Salaries Admin, Logistics, PDI | ₱769.23, ₱1,500, ₱2,307.69 bawat trip (dummy values) | Billing PL G31 hanggang G33 |
| Net income per trip | Client rate − kabuuang expense | Billing PL |

Wala na ang fuel at toll sa fund request; ginagamit na lang ang mga ito sa projected P&L.

Halimbawa mula sa Excel: 143 km, toll ₱651, rate ₱15,000, net income ₱4,997.08. Ito rin ang lumalabas sa system.

### Payroll

| Computation | Formula | Source |
| --- | --- | --- |
| Regular wage | Daily rate × araw na Present | DRIVERS PAYROLL |
| Trip pay | Trip pay rate × completed trips na may waybill | DRIVERS PAYROLL, FMS PDF |
| Gross pay | Regular wage + trip pay + manual earnings (Trade Test, Manual Pullout, Adjustment) | DRIVERS PAYROLL |
| SSS | (₱775 ÷ 24) × araw na pumasok | Payroll N14 |
| PhilHealth | ₱75 kung may pasok | Payroll O14 |
| Pag-IBIG | ₱50 kung may pasok | Payroll P14 |
| Cash Bond | ₱500 kung may pasok | Payroll col X |
| Cash advance, Uniform, Damages | Installment bawat payroll hanggang maubos ang balance | Payroll col T hanggang V |
| Net pay | Gross pay − kabuuang deductions | DRIVERS PAYROLL |

### Iba pang settings

| Setting | Default | Source |
| --- | --- | --- |
| Fund approval | Operations Head, kahit magkano (inalis ang ₱15,000 limit) | FMS PDF v2; utos ng kumpanya |
| Purchase approval limit ng Finance | ₱10,000 (lagpas: Admin) | FMS PDF p.17 |
| Preventive maintenance | Bawat 10,000 km | FMS PDF p.18 |
| Minimum na tagal ng checklist | 15 minuto | FMS PDF p.6 |
| Units bawat car carrier trip | 5 | CAR CARRIER MONITORING 2026 |
| Paalala bago mag-expire ang documents at lisensya | 30 araw | FMS PDF pp.2 hanggang 3 |
| Default payment terms | 30 araw | Repo docs |
| Trip pay kung may waybill lang | Oo | FMS PDF p.2 at p.7 |
| Auto-assign: patas na ikot ng truck at driver | Huling 30 araw | Hinihintay pa ang kumpirmasyon (C01) |

Ang mga uri ng computation na pwedeng piliin sa bawat item: fixed, percent of rate, per day, per km, per trip, fuel, route toll, manual at ledger. Ang detalyadong gabay ay nasa docs/GABAY\_PAGBAGO\_NG\_FORMULA.md.

## Security features

| Area | Ginawa sa system |
| --- | --- |
| Password | Naka-hash gamit ang PHP password\_hash. Minimum 8 characters. Kailangang palitan sa unang login at pagkatapos ma-reset |
| Login | Hanggang 5 maling subok sa loob ng 15 minuto, tapos maghihintay ng 15 minuto. Bagong session ID pagka-login |
| Session | HttpOnly at SameSite=Strict na cookie, Secure kapag HTTPS, strict mode. Auto logout pagkatapos ng 60 minutong walang ginagawa (SESSION\_IDLE\_MINUTES) |
| CSRF | Lahat ng POST request ay may CSRF token |
| Access control | Sinusuri sa server ang role sa bawat page at bawat save, hindi lang tinatago ang button. Trips lang ng sariling team ang nagagalaw ng dispatcher |
| Database | Prepared statements (PDO) sa lahat ng query. Transactions at row locking sa mga hakbang na sabay-sabay pwedeng mangyari |
| Input | Sinusuri ang uri, haba at range ng bawat field bago i-save |
| Output | Ine-escape ang lahat ng text bago ipakita sa page |
| HTTP headers | X-Content-Type-Options: nosniff, X-Frame-Options: DENY, Referrer-Policy: same-origin, Cache-Control: no-store sa API |
| Folders | Hinaharangan ng .htaccess ang backend, config, database, docs, scripts, storage, tests, at ang .env at .sql files. Walang directory listing |
| Uploads | PDF, JPG, PNG at WEBP lang, sinusuri ang totoong uri ng file, hanggang 10 MB bawat file, nakatago sa storage/private |
| Errors | Simpleng mensahe lang ang nakikita ng user. Ang technical na detalye ay nasa server error log |
| Installer | Gumagana lang ang install.php kapag walang user pa at may INSTALL\_TOKEN na 32+ characters |
| Audit trail | Naka-log ang mahahalagang gawain: sino, ano, kailan, at aling record |
| Backup | Admin lang ang makakagawa at makaka-download ng database backup |

## System requirements at deployment

### Server

| Requirement | Detalye |
| --- | --- |
| PHP | 8.2 o mas bago, may pdo\_mysql, mbstring at fileinfo |
| Database | MySQL o MariaDB, isang empty database na utf8mb4 |
| Web server | Apache na pinapayagan ang .htaccess (XAMPP at Hostinger ay pareho) |
| Writable folders | storage/private (uploads) at storage/backups (backups) |
| HTTPS | Kailangan sa live site para Secure ang session cookie |

Pwedeng i-check kung handa ang server gamit ang scripts/preflight.php.

### User

Kahit anong modernong browser (Chrome, Edge, Firefox, Safari) sa desktop, tablet o phone. Walang kailangang i-install.

### Settings (.env)

| Key | Gamit |
| --- | --- |
| APP\_TIMEZONE | Asia/Manila |
| SESSION\_NAME, SESSION\_IDLE\_MINUTES | Pangalan ng session cookie at minuto bago ma-auto logout |
| DB\_HOST, DB\_PORT, DB\_NAME, DB\_USER, DB\_PASS | Koneksyon sa database |
| COMPANY\_NAME, COMPANY\_ADDRESS | Lumalabas sa payslips at reports |
| INSTALL\_TOKEN | Pang-isang-beses na paggawa ng unang Admin. Burahin pagkatapos |

Hindi kasama sa GitHub ang .env, storage/private at storage/backups (naka-.gitignore).

### Deployment

1. **Local:** XAMPP sa Windows. Hakbang-hakbang na nasa README.md.
2. **Code:** naka-save sa GitHub (private repo). Commit at push gamit ang VS Code.
3. **Live:** Hostinger shared hosting. Naka-connect ang GitHub repo sa hPanel (Advanced → Git), kaya kada push sa branch ay kusang naa-update ang files sa public\_html.
4. **Database changes:** hindi kasama sa auto-deploy. Ini-import nang mano-mano sa phpMyAdmin.
5. **Backup:** sa Backup page ng system. Gawa sa PHP mismo kaya gumagana kahit sa shared hosting. I-download at itago sa labas ng server.

## Testing

Lahat ng test ay PASS noong Oktubre 31, 2026, pagkatapos ng flow update mula sa FMS PDF bersyon 2, sa bagong empty database. Environment: Ubuntu 24.04, PHP 8.3.6, MariaDB 10.11.14 at Chromium. Ang buong listahan ay nasa docs/TEST\_REPORT.md.

| Test | Ano ang sinuri | Resulta |
| --- | --- | --- |
| Syntax check | Bawat PHP at JS file | PASS |
| Preflight | PHP extensions, folders, lahat ng module nagloload, lahat ng tables | PASS |
| Formula test (15 checks) | Billing P&L, fund categories at DRIVERS PAYROLL laban sa Excel at PDF | PASS |
| End-to-end workflow (191 checks) | Buong daloy gamit ang lahat ng 10 roles | PASS |
| Scenario A | Ops Head create trip, Pre-Assigned, walang automatic fund | PASS |
| Fund request | Dispatcher lang, preset + dagdag (₱550), Ops Head lang ang approve, Ready for Release, release bago ang checklist | PASS |
| Scenario B | Pending Review, confirm, reject at revise ng fund (₱20,900, Ops Head pa rin) | PASS |
| Dispatch validation | Hindi makaka-dispatch hangga't hindi Ready for Dispatch at Released; walang clearance | PASS |
| Scenario C.1 (minor) | On Hold, assess, Repaired - Cleared to Continue, Resume, In Transit, attempt 1 pa rin | PASS |
| Scenario C.2 (major) | For Reschedule, Under Maintenance, repair, Reschedule, checklist ulit, Confirm Redispatch = Second Attempt | PASS |
| Preventive maintenance | PM job sa 10,000 km, Under Maintenance pagkatapos ng trip, reset ng km | PASS |
| Purchasing, billing, payroll, documents, backup | Hindi nagbago ang daloy | PASS |
| Formula Settings | Fixed amount lang sa fund categories; bagong preset para sa bagong request, hindi nagbabago ang luma | PASS |
| Browser test | Bawat page ng bawat role, walang JavaScript error | PASS |
| UI click-through | Fund request form (live total), approve, release, checklist, disabled tapos enabled Confirm Dispatch, incident, assess, confirm repair, resume | PASS |
| Module isolation | Sirang Funds module: Funds lang ang apektado, gumagana ang Trips at Dashboard | PASS |
| Password | test1234 tinatanggap, 7 characters tinatanggihan | PASS |

Hindi pa na-test: XAMPP sa Windows, Hostinger, maraming sabay-sabay na users, malalim na test sa mobile phones, at pag-print sa totoong printer.

## Mga limitasyon at hinihintay pang kumpirmasyon

Ang C02, C03 at C04 ay nasagot na ng bagong PDF at ng kumpanya. May mga natitira at bagong tanong; ginamit muna ang pinakamalapit na patakaran mula sa PDF, Excel o dating system, at may comment sa code. Ang buong listahan ay nasa docs/PENDING\_COMPANY\_CONFIRMATION.md.

| Code | Tanong sa kumpanya | Ginamit muna |
| --- | --- | --- |
| C01 | Patakaran sa pagpili ng truck, driver at helper sa auto-assign | Parehong truck type, walang bukas na repair, walang ibang trip sa araw na iyon, may patas na ikot |
| C02 | Fund request categories | **Nasagot:** preset categories ng PDF v2; inalis ang fuel at toll |
| C03 | Finance release at Ops Head clearance | **Nasagot:** walang clearance; release kahit bago ang checklist |
| C04 | ₱15,000 limit | **Nasagot:** inalis; Ops Head ang nag-a-approve ng lahat |
| C05 | Budget period at paano dinadagdagan | Isang active budget bawat category |
| C06 | Allowed na NOT OK items, pirma, 15 minutong minimum | Lahat ng 57 items kailangan OK bago maging Ready for Dispatch |
| C07 | Fund sa redispatch | Optional; kung may request, dapat Released |
| C08 | PM interval at reset bawat uri ng maintenance | 10,000 km, nire-reset pagkatapos ng PM |
| C09 | Bilang ng units sa car carrier | 5 bawat trip, pwedeng palitan |
| C10 | Tax, itsura ng official invoice, simula ng payment terms | Due date = invoice date + terms ng client |
| C11 | Partial delivery at pagbalik ng parts | Dapat tugma sa dami ng PO |
| C12 | Retention ng documents at iskedyul ng paalala | Paalala 30 araw bago mag-expire |
| C13 | Buong access matrix | Ayon sa User Roles ng PDF |
| C14 | Payroll: trip pay bawat destination, fixed deductions, bayad sa VL at SIL | Formula mula sa DRIVERS PAYROLL 2026 |
| C15 | Walang laman ang "Billing and Account Receivable" sa PDF | Ginamit ang Section II.H ng dating PDF |
| C16 | Hostinger plan, PHP version, upload limit, cron | Na-test lang sa local |
| C17 | Pwede bang i-reject ng Ops Head ang delivery request (Scenario B)? | Oo, may dahilan (galing sa dating system) |
| C18 | Kailan dapat tapos ang client steps ng container at wing van? | Bago ang pre-dispatch checklist |
| C19 | Liquidation ng na-release na fund | Finance ang nagtatala (galing sa dating system) |
| C20 | Kapag walang bakanteng truck o crew sa auto-assign | Awaiting Resources, Try auto-assign again o Assign manually |

### Wala pa sa system

- Overtime at holiday pay
- Email o SMS na abiso (sa loob lang ng system ang notifications)
- Automatic na backup ayon sa iskedyul
- Pag-import mula sa Excel
- Pag-reset ng password gamit ang email (Admin ang nagre-reset)
- Logo ng kumpanya sa printouts
