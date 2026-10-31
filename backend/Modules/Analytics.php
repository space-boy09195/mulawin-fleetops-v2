<?php
/*
 * =====================================================================
 *  backend/Modules/Analytics.php  —  DASHBOARDS, REPORTS AND ANALYTICS
 * =====================================================================
 *  FMS PDF III (dashboards per role) and IV (trip performance reports).
 *
 *  Formulas come from the company files (see docs/SOURCE_TRACEABILITY.csv):
 *   - Trip date   = actual departure date, or scheduled date if not yet
 *                   dispatched (CONTAINER MONITORING uses ACTUAL DEPARTURE DATE).
 *   - Weeks start on Monday ("WEEK 10: MARCH 2-8" in the monitoring sheets).
 *   - Units & trips per company -> client (CAR CARRIER "TRIPS SUMMARY PER WEEK").
 *   - Down-truck days = days a truck was Under Maintenance
 *                   (CONTAINER "TRUCK STATUS": COUNTIF(day cells,"DT")).
 *   - Maintenance cost per truck = parts + labor + other (repo docs Q26 #3).
 *   - Delays are REPORTED delays with a cause; client-caused delays are
 *     shown separately (repo docs Q5 / Q8). No on-time % is invented.
 *   - Expiry = expiry date − today (repo docs Q26 #2).
 *   - Profit = billed amount − recorded expenses (Billing P&L structure).
 *  Every dashboard returns the same shape:
 *     cards[], charts[], tables[], alerts[]
 *  so the screen code stays simple.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('Notify');

final class Analytics {
    public static function action(array $u, string $action, array $d): array {
        throw new BusinessRuleException('Reports are read-only.', 404);
    }

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'dashboard') {
            Notify::dailyReminders($p);
            $fn = match ($u['role']) {
                'admin' => 'management', 'operations_head' => 'operations',
                'car_carrier_dispatcher', 'container_wingvan_dispatcher' => 'dispatcher',
                'billing' => 'billing', 'finance' => 'finance', 'payroll' => 'payroll',
                'purchasing' => 'purchasing', 'motorpool' => 'maintenance', 'audit' => 'audit',
            };
            // Each block is isolated: if one fails, the rest of the dashboard still shows.
            try { $out = self::$fn($p, $u); }
            catch (Throwable $e) { error_log('Dashboard failed: ' . $e->getMessage()); $out = ['alerts' => [['level' => 'warning', 'text' => 'Some dashboard numbers could not be loaded.']]]; }
            return $out + ['cards' => [], 'charts' => [], 'tables' => [], 'alerts' => []];
        }
        return self::reports($p, $u, $q);
    }

    // =================================================================
    // SMALL HELPERS
    // =================================================================
    private static function val(PDO $p, string $sql, array $a = []): float { return (float)(one($p, $sql, $a)['v'] ?? 0); }
    private static function card(string $label, $value, string $icon, string $color = 'green', ?string $page = null, string $hint = '', string $format = 'number'): array {
        return compact('label', 'value', 'icon', 'color', 'page', 'hint', 'format');
    }
    private static function pairs(array $rows, string $label, string $value): array {
        return ['labels' => array_column($rows, $label), 'values' => array_map('floatval', array_column($rows, $value))];
    }
    private static function chart(string $title, string $type, array $labels, array $datasets, string $how = ''): array {
        return compact('title', 'type', 'labels', 'datasets', 'how');
    }
    private static function table(string $title, array $columns, array $rows, string $page = ''): array {
        return compact('title', 'columns', 'rows', 'page');
    }
    private const TRIP_DATE = 'DATE(COALESCE(t.departure_at, t.scheduled_at))';

    /** Expiring documents, registrations, insurance and licenses (Admin monitoring). */
    private static function expiring(PDO $p): array {
        $days = (int)Settings::value('document_alert_days'); $lic = (int)Settings::value('license_alert_days');
        return many($p, "SELECT * FROM (
              SELECT 'Registration' AS kind, plate_number AS item, registration_expiry AS expires_on, DATEDIFF(registration_expiry, CURRENT_DATE()) AS days_left FROM vehicles WHERE active=1 AND registration_expiry IS NOT NULL AND registration_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
              UNION ALL SELECT 'Insurance', plate_number, insurance_expiry, DATEDIFF(insurance_expiry, CURRENT_DATE()) FROM vehicles WHERE active=1 AND insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
              UNION ALL SELECT 'Driver license', full_name, license_expiry, DATEDIFF(license_expiry, CURRENT_DATE()) FROM personnel WHERE employment_status='Active' AND license_expiry IS NOT NULL AND license_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
              UNION ALL SELECT CONCAT('Document: ', category), original_filename, expires_on, DATEDIFF(expires_on, CURRENT_DATE()) FROM documents WHERE expires_on IS NOT NULL AND expires_on <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
            ) x ORDER BY expires_on LIMIT 50", [$days, $days, $lic, $days]);
    }

    private static function fleetCounts(PDO $p, string $extra = '', array $a = []): array {
        $rows = many($p, "SELECT status, COUNT(*) AS n FROM vehicles WHERE active=1 $extra GROUP BY status", $a);
        $out = array_fill_keys(['Available', 'Assigned', 'On Trip', 'Under Maintenance', 'Unavailable'], 0);
        foreach ($rows as $r) $out[$r['status']] = (int)$r['n'];
        return $out;
    }

    private static function underMaintenance(PDO $p): array {
        return many($p, "SELECT v.plate_number, v.truck_type, m.job_number, m.maintenance_type, m.status, m.expected_completion AS expected_available
            FROM vehicles v JOIN maintenance_jobs m ON m.vehicle_id=v.id AND m.status NOT IN ('Completed','Cancelled') AND m.maintenance_type<>'Roadside Repair'
            WHERE v.status='Under Maintenance' ORDER BY m.expected_completion IS NULL, m.expected_completion");
    }

    /** Monthly totals for the last 6 months: trips completed, billed, collected, expenses. */
    private static function sixMonths(PDO $p): array {
        $labels = []; for ($i = 5; $i >= 0; $i--) $labels[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $start = $labels[0] . '-01';
        $get = function (string $sql) use ($p, $start, $labels): array {
            $m = []; foreach (many($p, $sql, [$start]) as $r) $m[$r['m']] = (float)$r['v'];
            return array_map(fn($l) => round($m[$l] ?? 0, 2), $labels);
        };
        return ['labels' => $labels,
            'trips' => $get("SELECT DATE_FORMAT(completed_at,'%Y-%m') m, COUNT(*) v FROM trips WHERE status='Completed' AND completed_at>=? GROUP BY m"),
            'billed' => $get("SELECT DATE_FORMAT(invoice_date,'%Y-%m') m, SUM(net_amount) v FROM billings WHERE status<>'Cancelled' AND invoice_date>=? GROUP BY m"),
            'collected' => $get("SELECT DATE_FORMAT(payment_date,'%Y-%m') m, SUM(amount) v FROM collections WHERE payment_date>=? GROUP BY m"),
            'expenses' => $get("SELECT DATE_FORMAT(incurred_on,'%Y-%m') m, SUM(amount) v FROM expenses WHERE incurred_on>=? GROUP BY m"),
            'cash_out' => $get("SELECT DATE_FORMAT(released_on,'%Y-%m') m, SUM(amount) v FROM disbursements WHERE released_on>=? GROUP BY m"),
        ];
    }

    private static function outstanding(PDO $p): float {
        return self::val($p, "SELECT COALESCE(SUM(b.net_amount - COALESCE((SELECT SUM(c.amount) FROM collections c WHERE c.billing_id=b.id),0)),0) v FROM billings b WHERE b.status IN ('Issued','Partially Paid')");
    }

    // =================================================================
    // DASHBOARDS (FMS PDF III)
    // =================================================================
    /** Sum of counts for several statuses. */
    private static function sumOf(array $counts, array $statuses): int {
        $n = 0; foreach ($statuses as $st) $n += (int)($counts[$st] ?? 0); return $n;
    }

    /** Management / Admin dashboard. */
    private static function management(PDO $p, array $u): array {
        $m0 = date('Y-m-01'); $prev0 = date('Y-m-01', strtotime('first day of last month')); $prev1 = date('Y-m-t', strtotime('first day of last month'));
        $td = self::TRIP_DATE;
        $tripsMonth = self::val($p, "SELECT COUNT(*) v FROM trips t WHERE t.status NOT IN ('Cancelled','Rejected') AND $td>=?", [$m0]);
        $tripsPrev = self::val($p, "SELECT COUNT(*) v FROM trips t WHERE t.status NOT IN ('Cancelled','Rejected') AND $td BETWEEN ? AND ?", [$prev0, $prev1]);
        $status = []; foreach (many($p, "SELECT status, COUNT(*) n FROM trips GROUP BY status") as $r) $status[$r['status']] = (int)$r['n'];
        $ongoing = self::sumOf($status, ['Dispatched', 'On Hold - Incident Reported', 'In Transit', 'Returned']);
        $pending = self::sumOf($status, ['Pending Review', 'Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'For Reschedule']);
        $fleet = self::fleetCounts($p);
        $billedMonth = self::val($p, "SELECT COALESCE(SUM(net_amount),0) v FROM billings WHERE status<>'Cancelled' AND invoice_date>=?", [$m0]);
        $collectedMonth = self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM collections WHERE payment_date>=?', [$m0]);
        $expMonth = self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM expenses WHERE incurred_on>=?', [$m0]);
        $six = self::sixMonths($p);
        $change = $tripsPrev > 0 ? round(($tripsMonth - $tripsPrev) / $tripsPrev * 100, 1) : null;
        $byType = many($p, "SELECT COALESCE(t.truck_type,'Not set') AS truck_type, COUNT(*) n FROM trips t WHERE t.status NOT IN ('Cancelled','Rejected') AND $td>=? GROUP BY truck_type", [$m0]);
        $byClient = many($p, "SELECT c.name AS client, COUNT(*) n FROM trips t JOIN clients c ON c.id=t.client_id WHERE t.status NOT IN ('Cancelled','Rejected') AND $td>=? GROUP BY c.id ORDER BY n DESC LIMIT 8", [$m0]);
        $expCat = many($p, 'SELECT category, SUM(amount) total FROM expenses WHERE incurred_on>=? GROUP BY category ORDER BY total DESC', [$m0]);
        $alerts = [];
        if ($status['Pending Review'] ?? 0) $alerts[] = ['level' => 'info', 'text' => ($status['Pending Review']) . ' delivery request(s) are Pending Review by the Operations Head.', 'page' => 'trips'];
        if ($status['On Hold - Incident Reported'] ?? 0) $alerts[] = ['level' => 'warning', 'text' => $status['On Hold - Incident Reported'] . ' trip(s) are On Hold because of an incident.', 'page' => 'incidents'];
        $adminPr = self::val($p, "SELECT COUNT(*) v FROM purchase_requests WHERE status='Pending Approval' AND approver_role='admin'");
        if ($adminPr) $alerts[] = ['level' => 'warning', 'text' => (int)$adminPr . ' purchase request(s) above PHP ' . number_format(Settings::value('purchase_approval_threshold')) . ' need YOUR approval.', 'page' => 'purchasing'];
        return [
            'title' => 'Management Dashboard', 'subtitle' => 'Company-wide summary for ' . date('F Y'),
            'alerts' => $alerts,
            'cards' => [
                self::card('Trips this month', (int)$tripsMonth, 'bi-truck', 'green', 'trip_records', $change === null ? 'no data last month' : ($change >= 0 ? '+' : '') . $change . '% vs last month'),
                self::card('Completed (all time)', $status['Completed'] ?? 0, 'bi-check2-circle', 'green', 'trip_records'),
                self::card('Ongoing trips', $ongoing, 'bi-signpost-split', 'blue', 'trips'),
                self::card('Pending / scheduled trips', $pending, 'bi-hourglass-split', 'amber', 'trips'),
                self::card('Available trucks', $fleet['Available'] . ' / ' . array_sum($fleet), 'bi-truck-front', 'green', 'fleet'),
                self::card('Under maintenance', $fleet['Under Maintenance'], 'bi-tools', 'red', 'maintenance'),
                self::card('Billed this month', $billedMonth, 'bi-receipt', 'blue', 'billing', '', 'money'),
                self::card('Collected this month', $collectedMonth, 'bi-cash-coin', 'green', 'billing', '', 'money'),
                self::card('Outstanding receivables', self::outstanding($p), 'bi-exclamation-circle', 'amber', 'billing', '', 'money'),
                self::card('Expenses this month', $expMonth, 'bi-wallet2', 'red', 'finance', '', 'money'),
            ],
            'charts' => [
                self::chart('Completed trips per month', 'bar', $six['labels'], [['label' => 'Completed trips', 'data' => $six['trips']]], 'COUNT of trips with status Completed, grouped by completion month.'),
                self::chart('Billed vs collected vs expenses', 'line', $six['labels'], [['label' => 'Billed', 'data' => $six['billed']], ['label' => 'Collected', 'data' => $six['collected']], ['label' => 'Expenses', 'data' => $six['expenses']]], 'Billed = invoice net amounts; Collected = payments received; Expenses = recorded expenses.'),
                self::chart('Trips this month by truck type', 'doughnut', array_column($byType, 'truck_type'), [['label' => 'Trips', 'data' => array_map('intval', array_column($byType, 'n'))]]),
                self::chart('Trips this month by client (top 8)', 'bar', array_column($byClient, 'client'), [['label' => 'Trips', 'data' => array_map('intval', array_column($byClient, 'n'))]]),
                self::chart('Fleet availability now', 'doughnut', array_keys($fleet), [['label' => 'Trucks', 'data' => array_values($fleet)]]),
                self::chart('Expenses this month by category', 'doughnut', array_column($expCat, 'category'), [['label' => 'PHP', 'data' => array_map('floatval', array_column($expCat, 'total'))]]),
            ],
            'tables' => [
                self::table('Trucks under maintenance', [['Truck', 'plate_number'], ['Type', 'truck_type'], ['Job', 'job_number'], ['Work', 'maintenance_type'], ['Status', 'status', 'badge'], ['Expected available', 'expected_available', 'date']], self::underMaintenance($p), 'maintenance'),
                self::table('Expiring employee and vehicle documents', [['Kind', 'kind'], ['Item', 'item'], ['Expires', 'expires_on', 'date'], ['Days left', 'days_left', 'days']], self::expiring($p), 'documents'),
                self::table('Cash flow (last 6 months)', [['Month', 'month'], ['Cash in (collections)', 'in', 'money'], ['Cash out (disbursements)', 'out', 'money'], ['Net', 'net', 'money']],
                    array_map(fn($i) => ['month' => $six['labels'][$i], 'in' => $six['collected'][$i], 'out' => $six['cash_out'][$i], 'net' => round($six['collected'][$i] - $six['cash_out'][$i], 2)], array_keys($six['labels'])), 'finance'),
            ],
        ];
    }

    /** Operations dashboard (Operations Head). */
    private static function operations(PDO $p, array $u): array {
        $m0 = date('Y-m-01'); $td = self::TRIP_DATE;
        $s = []; foreach (many($p, 'SELECT status, COUNT(*) n FROM trips GROUP BY status') as $r) $s[$r['status']] = (int)$r['n'];
        $fleet = self::fleetCounts($p);
        $crew = many($p, "SELECT person_type, availability, COUNT(*) n FROM personnel WHERE employment_status='Active' AND person_type IN ('Driver','Helper') GROUP BY person_type, availability");
        $cc = []; foreach ($crew as $r) $cc[$r['person_type']][$r['availability']] = (int)$r['n'];
        $myFunds = self::val($p, "SELECT COUNT(*) v FROM fund_requests WHERE status='Pending Approval' AND approver_role='operations_head'");
        $byDisp = many($p, "SELECT COALESCE(us.full_name,'(not yet dispatched)') AS name, COUNT(*) AS trips, SUM(t.status='Completed') AS completed
            FROM trips t LEFT JOIN users us ON us.id=t.dispatcher_id WHERE $td>=? AND t.status NOT IN ('Cancelled','Rejected') GROUP BY t.dispatcher_id ORDER BY trips DESC", [$m0]);
        $byDriver = self::crewCounts($p, $m0, date('Y-m-d'), '', []);
        $byTruck = many($p, "SELECT v.plate_number AS name, COUNT(*) trips, SUM(t.status='Completed') completed FROM trips t JOIN vehicles v ON v.id=t.vehicle_id
            WHERE $td>=? AND t.status NOT IN ('Cancelled','Rejected') GROUP BY v.id ORDER BY trips DESC LIMIT 15", [$m0]);
        $alerts = [];
        if ($s['Pending Review'] ?? 0) $alerts[] = ['level' => 'warning', 'text' => $s['Pending Review'] . ' delivery request(s) need your review.', 'page' => 'trips'];
        if ($myFunds) $alerts[] = ['level' => 'warning', 'text' => (int)$myFunds . ' fund request(s) need your approval.', 'page' => 'funds'];
        if ($s['On Hold - Incident Reported'] ?? 0) $alerts[] = ['level' => 'warning', 'text' => $s['On Hold - Incident Reported'] . ' trip(s) are On Hold because of an incident.', 'page' => 'incidents'];
        if ($s['Awaiting Resources'] ?? 0) $alerts[] = ['level' => 'danger', 'text' => $s['Awaiting Resources'] . ' confirmed trip(s) have no free truck / crew yet.', 'page' => 'trips'];
        return [
            'title' => 'Operations Dashboard', 'subtitle' => 'Trips, fleet and crew today',
            'alerts' => $alerts,
            'cards' => [
                self::card('Pending review', $s['Pending Review'] ?? 0, 'bi-inbox', 'amber', 'trips'),
                self::card('Pre-assigned / awaiting', self::sumOf($s, ['Pre-Assigned', 'Awaiting Resources']), 'bi-check2-square', 'blue', 'trips'),
                self::card('Ready for dispatch', $s['Ready for Dispatch'] ?? 0, 'bi-send-check', 'blue', 'trips'),
                self::card('On the road', self::sumOf($s, ['Dispatched', 'In Transit', 'Returned']), 'bi-signpost-split', 'green', 'trips'),
                self::card('On hold (incident)', $s['On Hold - Incident Reported'] ?? 0, 'bi-exclamation-triangle', 'red', 'incidents'),
                self::card('For reschedule', $s['For Reschedule'] ?? 0, 'bi-arrow-repeat', 'red', 'incidents'),
                self::card('Completed this month', (int)self::val($p, "SELECT COUNT(*) v FROM trips WHERE status='Completed' AND completed_at>=?", [$m0]), 'bi-check2-circle', 'green', 'trip_records'),
                self::card('Available trucks', $fleet['Available'], 'bi-truck-front', 'green', 'fleet'),
                self::card('Unavailable / maintenance', $fleet['Unavailable'] + $fleet['Under Maintenance'], 'bi-tools', 'red', 'fleet'),
                self::card('Available drivers', $cc['Driver']['Available'] ?? 0, 'bi-person-check', 'green', 'crew'),
                self::card('Available helpers', $cc['Helper']['Available'] ?? 0, 'bi-people', 'green', 'crew'),
            ],
            'charts' => [
                self::chart('Fleet status now', 'doughnut', array_keys($fleet), [['label' => 'Trucks', 'data' => array_values($fleet)]]),
                self::chart('Trips this month by dispatcher', 'bar', array_column($byDisp, 'name'), [['label' => 'Trips', 'data' => array_map('intval', array_column($byDisp, 'trips'))], ['label' => 'Completed', 'data' => array_map('intval', array_column($byDisp, 'completed'))]]),
            ],
            'tables' => [
                self::table('Trucks under maintenance and expected availability', [['Truck', 'plate_number'], ['Type', 'truck_type'], ['Job', 'job_number'], ['Status', 'status', 'badge'], ['Expected available', 'expected_available', 'date']], self::underMaintenance($p), 'maintenance'),
                self::table('Trip performance by driver / helper (this month)', [['Name', 'name'], ['Role', 'role'], ['Trips', 'trips'], ['Completed', 'completed']], array_slice($byDriver, 0, 20), 'reports'),
                self::table('Trip performance by truck (this month)', [['Truck', 'name'], ['Trips', 'trips'], ['Completed', 'completed']], $byTruck, 'reports'),
            ],
        ];
    }

    /** Dispatcher home: work list of the dispatcher's team. */
    private static function dispatcher(PDO $p, array $u): array {
        $r = $u['role'];
        $s = []; foreach (many($p, 'SELECT status, COUNT(*) n FROM trips WHERE dispatcher_role=? GROUP BY status', [$r]) as $x) $s[$x['status']] = (int)$x['n'];
        $types = $r === 'car_carrier_dispatcher' ? ['Car Carrier'] : ['Container', 'Wing Van'];
        $marks = implode(',', array_fill(0, count($types), '?'));
        $fleet = self::fleetCounts($p, "AND truck_type IN ($marks)", $types);
        $today = many($p, "SELECT t.trip_ref, t.status, c.name AS client, t.destination, t.scheduled_at, v.plate_number,
                (SELECT f.status FROM fund_requests f WHERE f.trip_id=t.id AND f.status NOT IN ('Rejected','Cancelled') ORDER BY f.id DESC LIMIT 1) AS fund_status
            FROM trips t JOIN clients c ON c.id=t.client_id LEFT JOIN vehicles v ON v.id=t.vehicle_id
            WHERE t.dispatcher_role=? AND t.status IN ('Pending Review','Awaiting Resources','Pre-Assigned','Ready for Dispatch','Dispatched','On Hold - Incident Reported','In Transit','Returned','For Reschedule')
            ORDER BY t.scheduled_at LIMIT 50", [$r]);
        $missingWb = many($p, "SELECT t.trip_ref, c.name AS client, t.completed_at FROM trips t JOIN clients c ON c.id=t.client_id
            WHERE t.dispatcher_role=? AND t.status IN ('Completed','Returned','Dispatched','In Transit') AND NOT EXISTS (SELECT 1 FROM waybills w WHERE w.trip_id=t.id) ORDER BY t.id DESC LIMIT 30", [$r]);
        $alerts = [];
        if ($s['Pre-Assigned'] ?? 0) $alerts[] = ['level' => 'info', 'text' => $s['Pre-Assigned'] . ' pre-assigned trip(s) need the fund request and the pre-dispatch checklist.', 'page' => 'trips'];
        $rejected = self::val($p, "SELECT COUNT(*) v FROM fund_requests f JOIN trips t ON t.id=f.trip_id WHERE t.dispatcher_role=? AND f.status='Rejected' AND t.status IN ('Pre-Assigned','Ready for Dispatch','For Reschedule')", [$r]);
        if ($rejected) $alerts[] = ['level' => 'warning', 'text' => (int)$rejected . ' fund request(s) were rejected. Revise and resubmit.', 'page' => 'funds'];
        $ready = self::val($p, "SELECT COUNT(*) v FROM trips t WHERE t.dispatcher_role=? AND t.status='Ready for Dispatch' AND EXISTS (SELECT 1 FROM fund_requests f WHERE f.trip_id=t.id AND f.status='Released')", [$r]);
        if ($ready) $alerts[] = ['level' => 'success', 'text' => (int)$ready . ' trip(s) are Ready for Dispatch with a Released fund. Confirm Dispatch is enabled.', 'page' => 'trips'];
        $cleared = self::val($p, "SELECT COUNT(*) v FROM trips t WHERE t.dispatcher_role=? AND t.status='On Hold - Incident Reported' AND EXISTS (SELECT 1 FROM incidents i WHERE i.trip_id=t.id AND i.status='Repaired - Cleared to Continue')", [$r]);
        if ($cleared) $alerts[] = ['level' => 'success', 'text' => (int)$cleared . ' trip(s) are Repaired - Cleared to Continue. Resume the trip.', 'page' => 'trips'];
        if ($missingWb) $alerts[] = ['level' => 'warning', 'text' => count($missingWb) . ' trip(s) have no waybill recorded.', 'page' => 'trip_records'];
        return [
            'title' => ROLE_LABELS[$r] . ' Dashboard', 'subtitle' => 'Trips of your team (' . implode(' / ', $types) . ')',
            'alerts' => $alerts,
            'cards' => [
                self::card('Pending review', $s['Pending Review'] ?? 0, 'bi-hourglass', 'amber', 'trips'),
                self::card('Pre-assigned', $s['Pre-Assigned'] ?? 0, 'bi-clipboard-check', 'blue', 'trips'),
                self::card('Ready for dispatch', $s['Ready for Dispatch'] ?? 0, 'bi-send-check', 'blue', 'trips'),
                self::card('On the road', self::sumOf($s, ['Dispatched', 'In Transit', 'Returned']), 'bi-signpost-split', 'green', 'trips'),
                self::card('On hold / for reschedule', self::sumOf($s, ['On Hold - Incident Reported', 'For Reschedule']), 'bi-arrow-repeat', 'red', 'trips'),
                self::card('Available trucks', $fleet['Available'], 'bi-truck-front', 'green', 'fleet'),
            ],
            'tables' => [
                self::table('Open trips', [['Trip', 'trip_ref'], ['Client', 'client'], ['Destination', 'destination'], ['Schedule', 'scheduled_at', 'datetime'], ['Truck', 'plate_number'], ['Status', 'status', 'badge'], ['Fund', 'fund_status', 'badge']], $today, 'trips'),
                self::table('Trips without waybill', [['Trip', 'trip_ref'], ['Client', 'client'], ['Completed', 'completed_at', 'datetime']], $missingWb, 'trip_records'),
            ],
        ];
    }

    /** Billing and collection dashboard. */
    private static function billing(PDO $p, array $u): array {
        $m0 = date('Y-m-01');
        $unbilled = one($p, "SELECT COUNT(*) n, COALESCE(SUM(t.client_rate),0) est FROM trips t WHERE t.status='Completed' AND NOT EXISTS (SELECT 1 FROM billing_items bi JOIN billings b ON b.id=bi.billing_id WHERE bi.trip_id=t.id AND b.status<>'Cancelled')");
        $overdue = self::val($p, "SELECT COALESCE(SUM(b.net_amount - COALESCE((SELECT SUM(c.amount) FROM collections c WHERE c.billing_id=b.id),0)),0) v FROM billings b WHERE b.status IN ('Issued','Partially Paid') AND b.due_date < CURRENT_DATE()");
        $byClient = many($p, "SELECT c.name AS client, COALESCE(SUM(b.net_amount),0) AS billed,
                COALESCE(SUM((SELECT COALESCE(SUM(co.amount),0) FROM collections co WHERE co.billing_id=b.id)),0) AS collected
            FROM billings b JOIN clients c ON c.id=b.client_id WHERE b.status<>'Cancelled' GROUP BY c.id ORDER BY billed DESC LIMIT 20");
        foreach ($byClient as &$r) $r['outstanding'] = round((float)$r['billed'] - (float)$r['collected'], 2); unset($r);
        $six = self::sixMonths($p);
        return [
            'title' => 'Billing and Collection Dashboard', 'subtitle' => date('F Y'),
            'alerts' => (int)$unbilled['n'] ? [['level' => 'warning', 'text' => (int)$unbilled['n'] . ' completed trip(s) are not yet billed.', 'page' => 'billing']] : [],
            'cards' => [
                self::card('Completed trips awaiting billing', (int)$unbilled['n'], 'bi-hourglass-split', 'amber', 'billing', 'est. PHP ' . number_format((float)$unbilled['est'], 2)),
                self::card('Total billing this month', self::val($p, "SELECT COALESCE(SUM(net_amount),0) v FROM billings WHERE status<>'Cancelled' AND invoice_date>=?", [$m0]), 'bi-receipt', 'blue', 'billing', '', 'money'),
                self::card('Invoices issued this month', (int)self::val($p, "SELECT COUNT(*) v FROM billings WHERE status<>'Cancelled' AND invoice_date>=?", [$m0]), 'bi-file-earmark-text', 'blue', 'billing'),
                self::card('Collections this month', self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM collections WHERE payment_date>=?', [$m0]), 'bi-cash-coin', 'green', 'billing', '', 'money'),
                self::card('Outstanding balance', self::outstanding($p), 'bi-exclamation-circle', 'amber', 'billing', '', 'money'),
                self::card('Overdue balance', $overdue, 'bi-alarm', 'red', 'billing', '', 'money'),
            ],
            'charts' => [self::chart('Billed vs collected (6 months)', 'bar', $six['labels'], [['label' => 'Billed', 'data' => $six['billed']], ['label' => 'Collected', 'data' => $six['collected']]])],
            'tables' => [self::table('Billing and collection history by client', [['Client', 'client'], ['Billed', 'billed', 'money'], ['Collected', 'collected', 'money'], ['Outstanding', 'outstanding', 'money']], $byClient, 'billing')],
        ];
    }

    /** Finance dashboard. */
    private static function finance(PDO $p, array $u): array {
        $m0 = date('Y-m-01');
        $f = []; foreach (many($p, 'SELECT status, COUNT(*) n, SUM(amount) a FROM fund_requests GROUP BY status') as $r) $f[$r['status']] = $r;
        $ap = one($p, "SELECT COALESCE(SUM(amount-paid_amount),0) bal, SUM(due_on IS NOT NULL AND due_on <= DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)) due_soon FROM accounts_payable WHERE status<>'Paid'");
        $ca = self::val($p, "SELECT COALESCE(SUM(balance),0) v FROM employee_ledgers WHERE ledger_type='Cash Advance' AND status='Open'");
        $notLiq = one($p, "SELECT COUNT(*) n, COALESCE(SUM(amount),0) a FROM fund_requests WHERE status IN ('Released','Completed') AND liquidation_status<>'Liquidated'");
        $payroll = one($p, "SELECT COUNT(*) n, COALESCE(SUM((SELECT SUM(net_pay) FROM payroll_lines pl WHERE pl.period_id=pp.id)),0) a FROM payroll_periods pp WHERE status='Submitted to Finance'");
        $pr = self::val($p, "SELECT COUNT(*) v FROM purchase_requests WHERE status='Pending Approval' AND approver_role='finance'");
        $six = self::sixMonths($p);
        $alerts = [];
        if ($f['Ready for Release']['n'] ?? 0) $alerts[] = ['level' => 'warning', 'text' => $f['Ready for Release']['n'] . ' fund(s) are Ready for Release.', 'page' => 'funds'];
        if ($pr) $alerts[] = ['level' => 'info', 'text' => (int)$pr . ' purchase request(s) need your approval.', 'page' => 'purchasing'];
        if ((int)$payroll['n']) $alerts[] = ['level' => 'info', 'text' => (int)$payroll['n'] . ' payroll(s) are waiting for disbursement.', 'page' => 'finance'];
        return [
            'title' => 'Finance Dashboard', 'subtitle' => date('F Y'), 'alerts' => $alerts,
            'cards' => [
                self::card('Pending fund requests (Ops Head)', (int)($f['Pending Approval']['n'] ?? 0), 'bi-hourglass-split', 'amber', 'funds'),
                self::card('Ready for release', (int)($f['Ready for Release']['n'] ?? 0), 'bi-cash-stack', 'blue', 'funds', 'PHP ' . number_format((float)($f['Ready for Release']['a'] ?? 0), 2)),
                self::card('Allowances released this month', self::val($p, "SELECT COALESCE(SUM(amount),0) v FROM fund_requests WHERE released_at>=?", [$m0]), 'bi-send', 'green', 'funds', '', 'money'),
                self::card('Accounts payable balance', (float)$ap['bal'], 'bi-journal-minus', 'red', 'finance', (int)$ap['due_soon'] . ' due within 7 days', 'money'),
                self::card('Expenses this month', self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM expenses WHERE incurred_on>=?', [$m0]), 'bi-wallet2', 'red', 'finance', '', 'money'),
                self::card('Disbursements this month', self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM disbursements WHERE released_on>=?', [$m0]), 'bi-box-arrow-up-right', 'blue', 'finance', '', 'money'),
                self::card('Cash advances outstanding', $ca, 'bi-person-lines-fill', 'amber', 'finance', '', 'money'),
                self::card('Allowances not yet liquidated', (int)$notLiq['n'], 'bi-clipboard-x', 'amber', 'funds', 'PHP ' . number_format((float)$notLiq['a'], 2)),
            ],
            'charts' => [self::chart('Cash flow (6 months)', 'bar', $six['labels'], [['label' => 'Cash in (collections)', 'data' => $six['collected']], ['label' => 'Cash out (disbursements)', 'data' => $six['cash_out']]], 'Cash in = collections recorded by Billing. Cash out = all disbursements recorded by Finance.')],
            'tables' => [self::table('Fund requests by status', [['Status', 'status', 'badge'], ['Count', 'n'], ['Amount', 'a', 'money']], array_values($f), 'funds')],
        ];
    }

    /** Payroll dashboard. */
    private static function payroll(PDO $p, array $u): array {
        $periods = many($p, "SELECT pp.period_name, pp.payroll_group, pp.date_from, pp.date_to, pp.status,
                (SELECT COUNT(*) FROM payroll_lines pl WHERE pl.period_id=pp.id) employees,
                (SELECT COALESCE(SUM(trip_pay),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) trip_pay,
                (SELECT COALESCE(SUM(gross_pay),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) gross,
                (SELECT COALESCE(SUM(total_deductions),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) deductions,
                (SELECT COALESCE(SUM(net_pay),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) net
            FROM payroll_periods pp ORDER BY pp.date_to DESC LIMIT 12");
        $missing = many($p, "SELECT t.trip_ref, c.name AS client, t.completed_at FROM trips t JOIN clients c ON c.id=t.client_id
            WHERE t.status='Completed' AND NOT EXISTS (SELECT 1 FROM waybills w WHERE w.trip_id=t.id) ORDER BY t.completed_at DESC LIMIT 30");
        $st = []; foreach ($periods as $r) $st[$r['status']] = ($st[$r['status']] ?? 0) + 1;
        return [
            'title' => 'Payroll Dashboard', 'subtitle' => 'Payroll periods, trip pay and deductions',
            'alerts' => $missing ? [['level' => 'warning', 'text' => count($missing) . ' completed trip(s) have no waybill; they are not counted in trip pay.', 'page' => 'trip_records']] : [],
            'cards' => [
                self::card('Draft payrolls', $st['Draft'] ?? 0, 'bi-pencil-square', 'amber', 'payroll'),
                self::card('Finalized (payslips ready)', $st['Finalized'] ?? 0, 'bi-file-earmark-check', 'blue', 'payroll'),
                self::card('With Finance', $st['Submitted to Finance'] ?? 0, 'bi-send', 'blue', 'payroll'),
                self::card('Disbursed', $st['Disbursed'] ?? 0, 'bi-check2-circle', 'green', 'payroll'),
                self::card('Latest net pay', (float)($periods[0]['net'] ?? 0), 'bi-cash-stack', 'green', 'payroll', $periods[0]['period_name'] ?? '', 'money'),
                self::card('Latest trip pay', (float)($periods[0]['trip_pay'] ?? 0), 'bi-truck', 'blue', 'payroll', '', 'money'),
            ],
            'charts' => [self::chart('Payroll per period', 'bar', array_reverse(array_column($periods, 'period_name')), [
                ['label' => 'Gross', 'data' => array_reverse(array_map('floatval', array_column($periods, 'gross')))],
                ['label' => 'Deductions', 'data' => array_reverse(array_map('floatval', array_column($periods, 'deductions')))],
                ['label' => 'Net', 'data' => array_reverse(array_map('floatval', array_column($periods, 'net')))]])],
            'tables' => [
                self::table('Payroll periods', [['Period', 'period_name'], ['Group', 'payroll_group'], ['From', 'date_from', 'date'], ['To', 'date_to', 'date'], ['Status', 'status', 'badge'], ['Employees', 'employees'], ['Trip pay', 'trip_pay', 'money'], ['Deductions', 'deductions', 'money'], ['Net', 'net', 'money']], $periods, 'payroll'),
                self::table('Completed trips without waybill', [['Trip', 'trip_ref'], ['Client', 'client'], ['Completed', 'completed_at', 'datetime']], $missing, 'trip_records'),
            ],
        ];
    }

    /** Purchasing and inventory dashboard. */
    private static function purchasing(PDO $p, array $u): array {
        $m0 = date('Y-m-01');
        $low = many($p, 'SELECT part_number, description, quantity_on_hand, reorder_level, unit FROM parts WHERE active=1 AND reorder_level>0 AND quantity_on_hand<=reorder_level ORDER BY quantity_on_hand');
        $moves = many($p, 'SELECT sm.created_at, sm.movement_type, pa.part_number, pa.description, sm.quantity_delta, sm.reason FROM stock_movements sm JOIN parts pa ON pa.id=sm.part_id ORDER BY sm.id DESC LIMIT 15');
        return [
            'title' => 'Purchasing and Inventory Dashboard', 'subtitle' => date('F Y'),
            'alerts' => $low ? [['level' => 'danger', 'text' => count($low) . ' part(s) are at or below the reorder level.', 'page' => 'inventory']] : [],
            'cards' => [
                self::card('Parts requests to act on', (int)self::val($p, "SELECT COUNT(*) v FROM part_requests WHERE status='Requested'"), 'bi-inbox', 'amber', 'inventory'),
                self::card('Purchase requests pending', (int)self::val($p, "SELECT COUNT(*) v FROM purchase_requests WHERE status='Pending Approval'"), 'bi-hourglass-split', 'amber', 'purchasing'),
                self::card('Approved, not yet ordered', (int)self::val($p, "SELECT COUNT(*) v FROM purchase_requests WHERE status='Approved'"), 'bi-cart-plus', 'blue', 'purchasing'),
                self::card('Open purchase orders', (int)self::val($p, "SELECT COUNT(*) v FROM purchase_orders WHERE status='Ordered'"), 'bi-truck', 'blue', 'purchasing'),
                self::card('Inventory value', self::val($p, 'SELECT COALESCE(SUM(quantity_on_hand*unit_cost),0) v FROM parts WHERE active=1'), 'bi-box-seam', 'green', 'inventory', '', 'money'),
                self::card('Low-stock items', count($low), 'bi-exclamation-triangle', 'red', 'inventory'),
                self::card('Parts purchased this month', self::val($p, "SELECT COALESCE(SUM(amount),0) v FROM expenses WHERE category='Parts' AND incurred_on>=?", [$m0]), 'bi-bag-check', 'blue', 'purchasing', '', 'money'),
                self::card('Parts issued this month', (int)self::val($p, "SELECT COALESCE(-SUM(quantity_delta),0) v FROM stock_movements WHERE movement_type='Stock Out' AND created_at>=?", [$m0]), 'bi-box-arrow-right', 'green', 'inventory', 'units'),
                self::card('Suppliers', (int)self::val($p, 'SELECT COUNT(*) v FROM suppliers WHERE active=1'), 'bi-building', 'green', 'purchasing'),
            ],
            'tables' => [
                self::table('Low-stock items', [['Part no.', 'part_number'], ['Description', 'description'], ['On hand', 'quantity_on_hand'], ['Reorder level', 'reorder_level'], ['Unit', 'unit']], $low, 'inventory'),
                self::table('Latest inventory transactions', [['Date', 'created_at', 'datetime'], ['Type', 'movement_type', 'badge'], ['Part', 'part_number'], ['Description', 'description'], ['Qty', 'quantity_delta'], ['Reason', 'reason']], $moves, 'inventory'),
            ],
        ];
    }

    /** Maintenance dashboard (Motorpool). */
    private static function maintenance(PDO $p, array $u): array {
        $m0 = date('Y-m-01');
        $limit = Settings::value('pm_interval_km');
        $near = many($p, 'SELECT plate_number, truck_type, km_since_pm, ? - km_since_pm AS km_left, status FROM vehicles WHERE active=1 AND km_since_pm >= ? * 0.8 ORDER BY km_left', [$limit, $limit]);
        $history = many($p, "SELECT m.job_number, v.plate_number, m.maintenance_type, m.status, m.completed_at FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id WHERE m.status='Completed' ORDER BY m.completed_at DESC LIMIT 10");
        $pending = (int)self::val($p, "SELECT COUNT(*) v FROM maintenance_jobs WHERE status IN ('Reported','Waiting for Parts')");
        return [
            'title' => 'Maintenance Dashboard', 'subtitle' => 'Motorpool',
            'alerts' => $pending ? [['level' => 'warning', 'text' => $pending . ' repair request(s) need attention.', 'page' => 'maintenance']] : [],
            'cards' => [
                self::card('Trucks under maintenance', (int)self::val($p, "SELECT COUNT(*) v FROM vehicles WHERE status='Under Maintenance'"), 'bi-tools', 'red', 'maintenance'),
                self::card('Pending repair requests', $pending, 'bi-inbox', 'amber', 'maintenance'),
                self::card('Roadside repairs open', (int)self::val($p, "SELECT COUNT(*) v FROM maintenance_jobs WHERE maintenance_type='Roadside Repair' AND status NOT IN ('Completed','Cancelled')"), 'bi-geo-alt', 'amber', 'maintenance'),
                self::card('Scheduled preventive maintenance', (int)self::val($p, "SELECT COUNT(*) v FROM maintenance_schedules WHERE status='Scheduled'") + (int)self::val($p, "SELECT COUNT(*) v FROM maintenance_jobs WHERE maintenance_type='Preventive Maintenance' AND status NOT IN ('Completed','Cancelled')"), 'bi-calendar-check', 'blue', 'maintenance'),
                self::card('Parts used this month', (int)self::val($p, 'SELECT COALESCE(SUM(quantity),0) v FROM maintenance_parts_used WHERE created_at>=?', [$m0]), 'bi-gear', 'blue', 'maintenance'),
                self::card('Maintenance expenses this month', self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM maintenance_costs WHERE incurred_on>=?', [$m0]) + self::val($p, 'SELECT COALESCE(SUM(quantity*unit_cost),0) v FROM maintenance_parts_used WHERE created_at>=?', [$m0]), 'bi-wallet2', 'red', 'maintenance', 'parts + labor + other', 'money'),
            ],
            'tables' => [
                self::table('Expected vehicle availability', [['Truck', 'plate_number'], ['Type', 'truck_type'], ['Job', 'job_number'], ['Work', 'maintenance_type'], ['Status', 'status', 'badge'], ['Expected available', 'expected_available', 'date']], self::underMaintenance($p), 'maintenance'),
                self::table('Trucks near the preventive maintenance limit (' . number_format($limit) . ' km)', [['Truck', 'plate_number'], ['Type', 'truck_type'], ['Km since PM', 'km_since_pm'], ['Km left', 'km_left'], ['Status', 'status', 'badge']], $near, 'maintenance'),
                self::table('Recent maintenance history', [['Job', 'job_number'], ['Truck', 'plate_number'], ['Work', 'maintenance_type'], ['Completed', 'completed_at', 'datetime']], $history, 'maintenance'),
            ],
        ];
    }

    /** Audit: financial transactions and approval logs (read-only). */
    private static function audit(PDO $p, array $u): array {
        $logs = many($p, "SELECT a.created_at, COALESCE(us.full_name,'System') AS actor, a.area, a.action, a.record_ref, a.details FROM audit_events a LEFT JOIN users us ON us.id=a.actor_id
            WHERE a.area IN ('Finance','Billing','Purchasing','Payroll') ORDER BY a.id DESC LIMIT 25");
        return [
            'title' => 'Audit Dashboard', 'subtitle' => 'Financial records and approval history (read-only)',
            'cards' => [
                self::card('Fund requests', (int)self::val($p, 'SELECT COUNT(*) v FROM fund_requests'), 'bi-cash-coin', 'blue', 'funds'),
                self::card('Released allowances', self::val($p, "SELECT COALESCE(SUM(amount),0) v FROM fund_requests WHERE status IN ('Released','Completed')"), 'bi-send', 'green', 'funds', '', 'money'),
                self::card('Invoices', (int)self::val($p, "SELECT COUNT(*) v FROM billings WHERE status<>'Cancelled'"), 'bi-receipt', 'blue', 'billing'),
                self::card('Collections', self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM collections'), 'bi-cash-stack', 'green', 'billing', '', 'money'),
                self::card('Expenses', self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM expenses'), 'bi-wallet2', 'red', 'finance', '', 'money'),
                self::card('Disbursements', self::val($p, 'SELECT COALESCE(SUM(amount),0) v FROM disbursements'), 'bi-box-arrow-up-right', 'amber', 'finance', '', 'money'),
            ],
            'tables' => [self::table('Latest financial activity and approvals', [['When', 'created_at', 'datetime'], ['By', 'actor'], ['Area', 'area'], ['Action', 'action'], ['Record', 'record_ref'], ['Details', 'details']], $logs, 'audit_log')],
        ];
    }

    // =================================================================
    // REPORTS (FMS PDF IV and II.F) — filters + sections per role
    // =================================================================
    private static function reports(PDO $p, array $u, array $q): array {
        $to = self::d($q['to'] ?? '') ?? date('Y-m-d');
        $from = self::d($q['from'] ?? '') ?? date('Y-m-01', strtotime($to));
        must($from <= $to, 'The start date must be before the end date.');
        $gran = in_array($q['group'] ?? '', ['day', 'week', 'month'], true) ? $q['group'] : 'week';
        $role = $u['role'];
        $sections = [];
        $canTrips = in_array($role, ['admin', 'operations_head', 'car_carrier_dispatcher', 'container_wingvan_dispatcher', 'billing', 'audit'], true);
        $canFleet = in_array($role, ['admin', 'operations_head', 'motorpool'], true);
        $canMoney = in_array($role, ['admin', 'finance', 'billing', 'audit'], true);
        $canPayroll = in_array($role, ['admin', 'payroll', 'finance', 'audit'], true);
        $canStock = in_array($role, ['admin', 'purchasing', 'motorpool'], true);

        // Trip filters (PDF IV: truck type, truck, driver, dispatcher, client, status)
        $w = " AND " . self::TRIP_DATE . " BETWEEN ? AND ?"; $a = [$from, $to];
        if (roleIsDispatcher($role)) { $w .= ' AND t.dispatcher_role=?'; $a[] = $role; }
        foreach (['client_id' => 't.client_id', 'vehicle_id' => 't.vehicle_id', 'dispatcher_id' => 't.dispatcher_id'] as $k => $c) {
            if (!empty($q[$k]) && ctype_digit((string)$q[$k])) { $w .= " AND $c=?"; $a[] = (int)$q[$k]; }
        }
        if (!empty($q['driver_id']) && ctype_digit((string)$q['driver_id'])) { $w .= ' AND (t.driver_id=? OR t.driver2_id=? OR t.helper_id=?)'; $id = (int)$q['driver_id']; array_push($a, $id, $id, $id); }
        if (!empty($q['truck_type']) && in_array($q['truck_type'], TRUCK_TYPES, true)) { $w .= ' AND t.truck_type=?'; $a[] = $q['truck_type']; }
        if (!empty($q['status'])) { $w .= ' AND t.status=?'; $a[] = (string)$q['status']; }

        if ($canTrips) $sections[] = self::tripSection($p, $w, $a, $from, $to, $gran, $q);
        if ($canFleet) $sections[] = self::fleetSection($p, $from, $to);
        if ($canMoney) $sections[] = self::moneySection($p, $from, $to, $gran, $role);
        if ($canPayroll) $sections[] = self::payrollSection($p, $from, $to);
        if ($canStock) $sections[] = self::stockSection($p, $from, $to);

        return ['range' => ['from' => $from, 'to' => $to, 'group' => $gran], 'sections' => $sections,
            'filters' => readSections([
                'clients' => fn() => many($p, 'SELECT id, name FROM clients ORDER BY name'),
                'vehicles' => fn() => many($p, 'SELECT id, plate_number FROM vehicles ORDER BY plate_number'),
                'crew' => fn() => many($p, "SELECT id, full_name FROM personnel WHERE person_type IN ('Driver','Helper') ORDER BY full_name"),
                'dispatchers' => fn() => many($p, "SELECT id, full_name FROM users WHERE role IN ('car_carrier_dispatcher','container_wingvan_dispatcher') ORDER BY full_name"),
            ])];
    }
    private static function d(string $v): ?string { return ($v !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $v : null; }

    /** Bucket label for a date: day, Monday-start week, or month. */
    private static function bucket(string $date, string $g): string {
        if ($g === 'day') return $date;
        if ($g === 'month') return substr($date, 0, 7);
        $ts = strtotime($date); $mon = strtotime('-' . ((int)date('N', $ts) - 1) . ' days', $ts);
        return date('Y-m-d', $mon);   // week starts on Monday (monitoring sheets)
    }
    private static function buckets(string $from, string $to, string $g): array {
        $out = []; $d = $from;
        while ($d <= $to) { $out[self::bucket($d, $g)] = 0; $d = date('Y-m-d', strtotime($d . ' +1 day')); }
        return $out;
    }

    /** Driver/helper credit: driver 1, driver 2 and helper each get the trip (CC has DRIVER 1 and DRIVER 2). */
    private static function crewCounts(PDO $p, string $from, string $to, string $w, array $a): array {
        $td = self::TRIP_DATE;
        $base = "FROM trips t JOIN personnel pe ON pe.id=t.%s WHERE t.status NOT IN ('Cancelled','Rejected') AND $td BETWEEN ? AND ? $w";
        $rows = [];
        foreach (['driver_id' => 'Driver 1', 'driver2_id' => 'Driver 2', 'helper_id' => 'Helper'] as $col => $label) {
            foreach (many($p, "SELECT pe.full_name AS name, COUNT(*) trips, SUM(t.status='Completed') completed " . sprintf($base, $col) . ' GROUP BY pe.id', array_merge([$from, $to], $a)) as $r) {
                $k = $r['name'];
                $rows[$k] = $rows[$k] ?? ['name' => $k, 'role' => [], 'trips' => 0, 'completed' => 0];
                $rows[$k]['role'][$label] = true; $rows[$k]['trips'] += (int)$r['trips']; $rows[$k]['completed'] += (int)$r['completed'];
            }
        }
        foreach ($rows as &$r) $r['role'] = implode(', ', array_keys($r['role'])); unset($r);
        usort($rows, fn($x, $y) => $y['trips'] <=> $x['trips']);
        return array_values($rows);
    }

    private static function tripSection(PDO $p, string $w, array $a, string $from, string $to, string $g, array $q): array {
        $td = self::TRIP_DATE;
        $trips = many($p, "SELECT t.id, t.trip_ref, t.status, t.truck_type, $td AS trip_date, t.units_planned, t.units_delivered, t.origin, t.destination,
                t.departure_at, t.arrival_at, t.km_traveled, t.attempt_number, c.name AS client, COALESCE(c.company_name, '') AS company, v.plate_number,
                d1.full_name AS driver, d2.full_name AS driver2, hp.full_name AS helper, us.full_name AS dispatcher,
                (SELECT GROUP_CONCAT(w.waybill_number SEPARATOR ', ') FROM waybills w WHERE w.trip_id=t.id) AS waybills
            FROM trips t JOIN clients c ON c.id=t.client_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN personnel d1 ON d1.id=t.driver_id
            LEFT JOIN personnel d2 ON d2.id=t.driver2_id LEFT JOIN personnel hp ON hp.id=t.helper_id LEFT JOIN users us ON us.id=t.dispatcher_id
            WHERE 1=1 $w ORDER BY trip_date, t.id", $a);
        $active = array_values(array_filter($trips, fn($t) => !in_array($t['status'], ['Cancelled', 'Rejected'], true)));
        $count = fn(string $s) => count(array_filter($trips, fn($t) => $t['status'] === $s));
        // Previous period of the same length for comparison (PDF IV "compare trip volumes across periods").
        $days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
        $pFrom = date('Y-m-d', strtotime($from . " -$days days")); $pTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $pa = $a; $pa[0] = $pFrom; $pa[1] = $pTo;
        $prev = (int)(one($p, "SELECT COUNT(*) n FROM trips t WHERE t.status NOT IN ('Cancelled','Rejected') $w", $pa)['n'] ?? 0);

        $series = self::buckets($from, $to, $g); $completed = $series; $units = $series;
        foreach ($active as $t) {
            $b = self::bucket($t['trip_date'], $g);
            $series[$b] = ($series[$b] ?? 0) + 1;
            if ($t['status'] === 'Completed') $completed[$b] = ($completed[$b] ?? 0) + 1;
            $units[$b] = ($units[$b] ?? 0) + (int)($t['units_delivered'] ?? $t['units_planned'] ?? 0) * ($t['truck_type'] === 'Car Carrier' ? 1 : 0);
        }
        $group = function (string $key) use ($active): array {
            $m = [];
            foreach ($active as $t) { $k = $t[$key] ?: '(none)'; $m[$k] = $m[$k] ?? ['name' => $k, 'trips' => 0, 'completed' => 0, 'units' => 0];
                $m[$k]['trips']++; if ($t['status'] === 'Completed') $m[$k]['completed']++;
                if ($t['truck_type'] === 'Car Carrier') $m[$k]['units'] += (int)($t['units_delivered'] ?? $t['units_planned'] ?? 0); }
            usort($m, fn($x, $y) => $y['trips'] <=> $x['trips']);
            return array_values($m);
        };
        // Company -> client weekly summary (CAR CARRIER "TRIPS SUMMARY PER WEEK": COMPANY, CLIENT, UNITS, TRIPS)
        $cc = [];
        foreach ($active as $t) {
            $k = self::bucket($t['trip_date'], 'week') . '|' . $t['company'] . '|' . $t['client'];
            $cc[$k] = $cc[$k] ?? ['week_of' => self::bucket($t['trip_date'], 'week'), 'company' => $t['company'] ?: '-', 'client' => $t['client'], 'units' => 0, 'trips' => 0];
            $cc[$k]['trips']++; if ($t['truck_type'] === 'Car Carrier') $cc[$k]['units'] += (int)($t['units_delivered'] ?? $t['units_planned'] ?? 0);
        }
        $ids = array_column($trips, 'id');
        $delays = $ids ? many($p, 'SELECT up.created_at, t.trip_ref, up.delay_cause, up.note FROM trip_updates up JOIN trips t ON t.id=up.trip_id WHERE up.update_type=\'Delay\' AND up.trip_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY up.id') : [];
        $incidents = $ids ? many($p, 'SELECT i.created_at, t.trip_ref, v.plate_number, i.severity, i.details, i.status FROM incidents i JOIN trips t ON t.id=i.trip_id JOIN vehicles v ON v.id=i.vehicle_id WHERE i.trip_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY i.id') : [];
        $byCause = [];
        foreach ($delays as $d) $byCause[$d['delay_cause'] ?? 'Other'] = ($byCause[$d['delay_cause'] ?? 'Other'] ?? 0) + 1;
        $clientDelays = $byCause['Client'] ?? 0;
        $noWaybill = array_values(array_filter($trips, fn($t) => in_array($t['status'], ['Completed', 'Returned', 'Dispatched', 'In Transit'], true) && !$t['waybills']));
        $crewW = preg_replace('/ AND DATE\(COALESCE\(t\.departure_at, t\.scheduled_at\)\) BETWEEN \? AND \?/', '', $w, 1);
        $crewA = array_slice($a, 2);
        $change = $prev > 0 ? round((count($active) - $prev) / $prev * 100, 1) : null;
        return [
            'title' => 'Trip performance', 'icon' => 'bi-truck',
            'note' => "Trip date = actual departure date (or schedule if not yet dispatched). Weeks start on Monday. Units are counted for Car Carrier trips. Previous period: $pFrom to $pTo.",
            'cards' => [
                self::card('Total trips', count($active), 'bi-truck', 'green', null, $change === null ? "previous period: $prev" : ($change >= 0 ? '+' : '') . "$change% vs previous ($prev)"),
                self::card('Completed', $count('Completed'), 'bi-check2-circle', 'green'),
                self::card('Cancelled', $count('Cancelled'), 'bi-x-circle', 'red'),
                self::card('For reschedule (incident)', $count('For Reschedule'), 'bi-arrow-repeat', 'amber'),
                self::card('Units delivered (Car Carrier)', array_sum(array_map(fn($t) => $t['truck_type'] === 'Car Carrier' ? (int)($t['units_delivered'] ?? $t['units_planned'] ?? 0) : 0, $active)), 'bi-car-front', 'blue'),
                self::card('Reported delays', count($delays), 'bi-clock-history', 'amber', null, $clientDelays . ' caused by client'),
                self::card('Incidents', count($incidents), 'bi-exclamation-triangle', 'red'),
                self::card('Trips without waybill', count($noWaybill), 'bi-file-earmark-x', 'red'),
            ],
            'charts' => [
                self::chart('Trips per ' . $g, 'bar', array_keys($series), [['label' => 'All trips', 'data' => array_values($series)], ['label' => 'Completed', 'data' => array_values($completed)]], 'COUNT of trips (not Cancelled / Rejected) by trip date.'),
                self::chart('Delays by reported cause', 'doughnut', array_keys($byCause), [['label' => 'Delays', 'data' => array_values($byCause)]], 'Reported delay updates; client-caused delays are not counted against operations.'),
            ],
            'tables' => [
                self::table('Trips per driver / helper', [['Name', 'name'], ['Credited as', 'role'], ['Trips', 'trips'], ['Completed', 'completed']], self::crewCounts($p, $from, $to, $crewW, $crewA)),
                self::table('Trips per truck', [['Truck', 'name'], ['Trips', 'trips'], ['Completed', 'completed'], ['Units', 'units']], $group('plate_number')),
                self::table('Trips per client', [['Client', 'name'], ['Trips', 'trips'], ['Completed', 'completed'], ['Units', 'units']], $group('client')),
                self::table('Trips per dispatcher', [['Dispatcher', 'name'], ['Trips', 'trips'], ['Completed', 'completed']], $group('dispatcher')),
                self::table('Weekly summary by company and client (units and trips)', [['Week of (Mon)', 'week_of', 'date'], ['Company', 'company'], ['Client', 'client'], ['Units', 'units'], ['Trips', 'trips']], array_values($cc)),
                self::table('Delivery and return records', [['Trip', 'trip_ref'], ['Date', 'trip_date', 'date'], ['Client', 'client'], ['Truck', 'plate_number'], ['Driver', 'driver'], ['Driver 2', 'driver2'], ['Helper', 'helper'],
                    ['Origin', 'origin'], ['Destination', 'destination'], ['Departed', 'departure_at', 'datetime'], ['Arrived', 'arrival_at', 'datetime'], ['Km', 'km_traveled'], ['Units', 'units_delivered'], ['Waybill', 'waybills'], ['Attempt', 'attempt_number'], ['Status', 'status', 'badge']], $trips),
                self::table('Recorded delays', [['When', 'created_at', 'datetime'], ['Trip', 'trip_ref'], ['Cause', 'delay_cause', 'badge'], ['Note', 'note']], $delays),
                self::table('Recorded incidents', [['When', 'created_at', 'datetime'], ['Trip', 'trip_ref'], ['Truck', 'plate_number'], ['Severity', 'severity', 'badge'], ['Details', 'details'], ['Status', 'status', 'badge']], $incidents),
                self::table('Trips without waybill (follow up)', [['Trip', 'trip_ref'], ['Client', 'client'], ['Status', 'status', 'badge'], ['Date', 'trip_date', 'date']], $noWaybill),
            ],
        ];
    }

    /** Down-truck days from the status log (TRUCK STATUS COUNTIF "DT"). */
    private static function fleetSection(PDO $p, string $from, string $to): array {
        $vehicles = many($p, 'SELECT id, plate_number, truck_type, status FROM vehicles WHERE active=1 ORDER BY plate_number');
        $rows = [];
        foreach ($vehicles as $v) {
            $before = one($p, 'SELECT to_status FROM vehicle_status_log WHERE vehicle_id=? AND created_at < ? ORDER BY id DESC LIMIT 1', [$v['id'], $from . ' 00:00:00']);
            $state = $before['to_status'] ?? 'Available';
            $logs = many($p, 'SELECT to_status, DATE(created_at) d FROM vehicle_status_log WHERE vehicle_id=? AND created_at BETWEEN ? AND ? ORDER BY id', [$v['id'], $from . ' 00:00:00', $to . ' 23:59:59']);
            $byDay = []; foreach ($logs as $l) $byDay[$l['d']][] = $l['to_status'];
            $down = 0; $d = $from; $today = date('Y-m-d');
            while ($d <= $to && $d <= $today) {
                $wasDown = $state === 'Under Maintenance';
                foreach ($byDay[$d] ?? [] as $s) { $state = $s; if ($s === 'Under Maintenance') $wasDown = true; }
                if ($wasDown) $down++;
                $d = date('Y-m-d', strtotime($d . ' +1 day'));
            }
            $cost = one($p, "SELECT
                    (SELECT COALESCE(SUM(mu.quantity*mu.unit_cost),0) FROM maintenance_parts_used mu JOIN maintenance_jobs m ON m.id=mu.maintenance_job_id WHERE m.vehicle_id=? AND DATE(mu.created_at) BETWEEN ? AND ?) AS parts,
                    (SELECT COALESCE(SUM(mc.amount),0) FROM maintenance_costs mc JOIN maintenance_jobs m ON m.id=mc.maintenance_job_id WHERE m.vehicle_id=? AND mc.cost_type='Labor' AND mc.incurred_on BETWEEN ? AND ?) AS labor,
                    (SELECT COALESCE(SUM(mc.amount),0) FROM maintenance_costs mc JOIN maintenance_jobs m ON m.id=mc.maintenance_job_id WHERE m.vehicle_id=? AND mc.cost_type<>'Labor' AND mc.incurred_on BETWEEN ? AND ?) AS other,
                    (SELECT COUNT(*) FROM trips t WHERE t.vehicle_id=? AND t.status='Completed' AND DATE(t.completed_at) BETWEEN ? AND ?) AS trips",
                [$v['id'], $from, $to, $v['id'], $from, $to, $v['id'], $from, $to, $v['id'], $from, $to]);
            $rows[] = ['plate_number' => $v['plate_number'], 'truck_type' => $v['truck_type'], 'status' => $v['status'], 'down_days' => $down, 'trips' => (int)$cost['trips'],
                'parts' => (float)$cost['parts'], 'labor' => (float)$cost['labor'], 'other' => (float)$cost['other'],
                'total_cost' => round((float)$cost['parts'] + (float)$cost['labor'] + (float)$cost['other'], 2)];
        }
        return ['title' => 'Fleet and maintenance', 'icon' => 'bi-tools',
            'note' => 'Down-truck days = days the truck was Under Maintenance at any time of the day (same as counting "DT" in the TRUCK STATUS sheet). Maintenance cost = parts used + labor + other costs.',
            'cards' => [self::card('Total down-truck days', array_sum(array_column($rows, 'down_days')), 'bi-calendar-x', 'red'),
                        self::card('Total maintenance cost', round(array_sum(array_column($rows, 'total_cost')), 2), 'bi-wallet2', 'red', null, '', 'money')],
            'charts' => [self::chart('Maintenance cost per truck', 'bar', array_column($rows, 'plate_number'), [['label' => 'Parts', 'data' => array_column($rows, 'parts')], ['label' => 'Labor', 'data' => array_column($rows, 'labor')], ['label' => 'Other', 'data' => array_column($rows, 'other')]])],
            'tables' => [self::table('Per truck', [['Truck', 'plate_number'], ['Type', 'truck_type'], ['Status now', 'status', 'badge'], ['Completed trips', 'trips'], ['Down-truck days', 'down_days'], ['Parts', 'parts', 'money'], ['Labor', 'labor', 'money'], ['Other', 'other', 'money'], ['Total cost', 'total_cost', 'money']], $rows)]];
    }

    private static function moneySection(PDO $p, string $from, string $to, string $g, string $role): array {
        $billed = self::buckets($from, $to, $g); $collected = $billed; $spent = $billed;
        foreach (many($p, "SELECT invoice_date d, net_amount a FROM billings WHERE status<>'Cancelled' AND invoice_date BETWEEN ? AND ?", [$from, $to]) as $r) { $b = self::bucket($r['d'], $g); $billed[$b] = round(($billed[$b] ?? 0) + (float)$r['a'], 2); }
        foreach (many($p, 'SELECT payment_date d, amount a FROM collections WHERE payment_date BETWEEN ? AND ?', [$from, $to]) as $r) { $b = self::bucket($r['d'], $g); $collected[$b] = round(($collected[$b] ?? 0) + (float)$r['a'], 2); }
        foreach (many($p, 'SELECT incurred_on d, amount a FROM expenses WHERE incurred_on BETWEEN ? AND ?', [$from, $to]) as $r) { $b = self::bucket($r['d'], $g); $spent[$b] = round(($spent[$b] ?? 0) + (float)$r['a'], 2); }
        // AR aging (days past due date)
        $aging = ['Not yet due' => 0, '1-30 days' => 0, '31-60 days' => 0, '61-90 days' => 0, 'Over 90 days' => 0];
        foreach (many($p, "SELECT b.due_date, b.net_amount - COALESCE((SELECT SUM(c.amount) FROM collections c WHERE c.billing_id=b.id),0) AS bal FROM billings b WHERE b.status IN ('Issued','Partially Paid')") as $r) {
            $late = $r['due_date'] ? (int)floor((time() - strtotime($r['due_date'])) / 86400) : 0;
            $k = $late <= 0 ? 'Not yet due' : ($late <= 30 ? '1-30 days' : ($late <= 60 ? '31-60 days' : ($late <= 90 ? '61-90 days' : 'Over 90 days')));
            $aging[$k] = round($aging[$k] + (float)$r['bal'], 2);
        }
        $byClient = many($p, "SELECT c.name AS client, COUNT(DISTINCT b.id) invoices, SUM(b.net_amount) billed FROM billings b JOIN clients c ON c.id=b.client_id WHERE b.status<>'Cancelled' AND b.invoice_date BETWEEN ? AND ? GROUP BY c.id ORDER BY billed DESC", [$from, $to]);
        $byTruck = many($p, "SELECT COALESCE(v.plate_number,'-') AS truck, COUNT(*) trips, SUM(bi.amount) billed FROM billing_items bi JOIN billings b ON b.id=bi.billing_id JOIN trips t ON t.id=bi.trip_id LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE b.status<>'Cancelled' AND b.invoice_date BETWEEN ? AND ? GROUP BY v.id ORDER BY billed DESC", [$from, $to]);
        // Actual trip P&L: revenue billed for the trip minus expenses linked to the trip (Billing P&L structure).
        $tripPnl = many($p, "SELECT t.trip_ref, c.name AS client, t.origin, t.destination, COALESCE(bi.amount,0) AS revenue,
                COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.trip_id=t.id),0) AS expenses
            FROM trips t JOIN clients c ON c.id=t.client_id LEFT JOIN billing_items bi ON bi.trip_id=t.id
            WHERE t.status='Completed' AND DATE(t.completed_at) BETWEEN ? AND ? ORDER BY t.completed_at", [$from, $to]);
        foreach ($tripPnl as &$r) { $r['net'] = round((float)$r['revenue'] - (float)$r['expenses'], 2); $r['margin'] = (float)$r['revenue'] > 0 ? round($r['net'] / (float)$r['revenue'] * 100, 1) : null; } unset($r);
        $expCat = many($p, 'SELECT category, SUM(amount) total FROM expenses WHERE incurred_on BETWEEN ? AND ? GROUP BY category ORDER BY total DESC', [$from, $to]);
        $unbilled = many($p, "SELECT t.trip_ref, c.name AS client, t.completed_at, t.client_rate FROM trips t JOIN clients c ON c.id=t.client_id WHERE t.status='Completed' AND NOT EXISTS (SELECT 1 FROM billing_items bi JOIN billings b ON b.id=bi.billing_id WHERE bi.trip_id=t.id AND b.status<>'Cancelled') ORDER BY t.completed_at");
        $totBilled = array_sum($billed); $totExp = array_sum($spent);
        return ['title' => 'Billing, collections and expenses', 'icon' => 'bi-cash-coin',
            'note' => 'Profit = billed amount minus recorded expenses (structure of the Billing P&L workbook). Aging = days past the invoice due date.',
            'cards' => [self::card('Billed', $totBilled, 'bi-receipt', 'blue', null, '', 'money'), self::card('Collected', array_sum($collected), 'bi-cash-coin', 'green', null, '', 'money'),
                        self::card('Expenses', $totExp, 'bi-wallet2', 'red', null, '', 'money'), self::card('Billed minus expenses', round($totBilled - $totExp, 2), 'bi-graph-up', 'green', null, '', 'money'),
                        self::card('Outstanding (all invoices)', self::outstanding($p), 'bi-exclamation-circle', 'amber', null, '', 'money'), self::card('Completed trips not yet billed', count($unbilled), 'bi-hourglass', 'amber')],
            'charts' => [self::chart('Billed, collected and expenses per ' . $g, 'bar', array_keys($billed), [['label' => 'Billed', 'data' => array_values($billed)], ['label' => 'Collected', 'data' => array_values($collected)], ['label' => 'Expenses', 'data' => array_values($spent)]]),
                         self::chart('Receivables aging', 'doughnut', array_keys($aging), [['label' => 'PHP', 'data' => array_values($aging)]]),
                         self::chart('Expenses by category', 'doughnut', array_column($expCat, 'category'), [['label' => 'PHP', 'data' => array_map('floatval', array_column($expCat, 'total'))]])],
            'tables' => [self::table('Billing by client', [['Client', 'client'], ['Invoices', 'invoices'], ['Billed', 'billed', 'money']], $byClient),
                         self::table('Billing by truck', [['Truck', 'truck'], ['Trips billed', 'trips'], ['Billed', 'billed', 'money']], $byTruck),
                         self::table('Profit per completed trip', [['Trip', 'trip_ref'], ['Client', 'client'], ['Origin', 'origin'], ['Destination', 'destination'], ['Revenue', 'revenue', 'money'], ['Trip expenses', 'expenses', 'money'], ['Net', 'net', 'money'], ['Margin %', 'margin']], $tripPnl),
                         self::table('Completed trips not yet billed', [['Trip', 'trip_ref'], ['Client', 'client'], ['Completed', 'completed_at', 'datetime'], ['Expected rate', 'client_rate', 'money']], $unbilled),
                         self::table('Expenses by category', [['Category', 'category'], ['Total', 'total', 'money']], $expCat)]];
    }

    private static function payrollSection(PDO $p, string $from, string $to): array {
        $rows = many($p, "SELECT pp.period_name, pp.date_from, pp.date_to, pp.status, COUNT(pl.id) employees, SUM(pl.regular_wage) wage, SUM(pl.trip_pay) trip_pay,
                SUM(pl.gross_pay) gross, SUM(pl.total_deductions) deductions, SUM(pl.net_pay) net
            FROM payroll_periods pp LEFT JOIN payroll_lines pl ON pl.period_id=pp.id WHERE pp.date_to BETWEEN ? AND ? GROUP BY pp.id ORDER BY pp.date_to", [$from, $to]);
        return ['title' => 'Payroll', 'icon' => 'bi-cash-stack', 'note' => 'Net pay = gross pay − total deductions (DRIVERS PAYROLL workbook).',
            'cards' => [self::card('Gross pay', round(array_sum(array_column($rows, 'gross')), 2), 'bi-cash', 'blue', null, '', 'money'), self::card('Trip pay', round(array_sum(array_column($rows, 'trip_pay')), 2), 'bi-truck', 'green', null, '', 'money'),
                        self::card('Deductions', round(array_sum(array_column($rows, 'deductions')), 2), 'bi-dash-circle', 'red', null, '', 'money'), self::card('Net pay', round(array_sum(array_column($rows, 'net')), 2), 'bi-cash-stack', 'green', null, '', 'money')],
            'tables' => [self::table('Payroll periods', [['Period', 'period_name'], ['From', 'date_from', 'date'], ['To', 'date_to', 'date'], ['Status', 'status', 'badge'], ['Employees', 'employees'], ['Regular wage', 'wage', 'money'], ['Trip pay', 'trip_pay', 'money'], ['Gross', 'gross', 'money'], ['Deductions', 'deductions', 'money'], ['Net', 'net', 'money']], $rows)]];
    }

    private static function stockSection(PDO $p, string $from, string $to): array {
        $rows = many($p, "SELECT pa.part_number, pa.description, pa.quantity_on_hand, pa.unit_cost,
                COALESCE(SUM(CASE WHEN sm.movement_type='Stock In' THEN sm.quantity_delta END),0) AS stock_in,
                COALESCE(-SUM(CASE WHEN sm.movement_type='Stock Out' THEN sm.quantity_delta END),0) AS stock_out,
                COALESCE(SUM(CASE WHEN sm.movement_type='Adjustment' THEN sm.quantity_delta END),0) AS adjusted
            FROM parts pa LEFT JOIN stock_movements sm ON sm.part_id=pa.id AND DATE(sm.created_at) BETWEEN ? AND ?
            WHERE pa.active=1 GROUP BY pa.id ORDER BY pa.description", [$from, $to]);
        foreach ($rows as &$r) $r['value'] = round((float)$r['quantity_on_hand'] * (float)$r['unit_cost'], 2); unset($r);
        return ['title' => 'Parts and inventory', 'icon' => 'bi-box-seam', 'note' => 'Stock in = purchase receipts; stock out = parts issued to Motorpool; adjustment = physical count corrections.',
            'cards' => [self::card('Parts received', array_sum(array_column($rows, 'stock_in')), 'bi-box-arrow-in-down', 'green'), self::card('Parts issued', array_sum(array_column($rows, 'stock_out')), 'bi-box-arrow-up', 'blue'),
                        self::card('Stock value now', round(array_sum(array_column($rows, 'value')), 2), 'bi-box-seam', 'green', null, '', 'money')],
            'tables' => [self::table('Parts movement', [['Part no.', 'part_number'], ['Description', 'description'], ['Received', 'stock_in'], ['Issued', 'stock_out'], ['Adjusted', 'adjusted'], ['On hand', 'quantity_on_hand'], ['Unit cost', 'unit_cost', 'money'], ['Value', 'value', 'money']], $rows)]];
    }
}
