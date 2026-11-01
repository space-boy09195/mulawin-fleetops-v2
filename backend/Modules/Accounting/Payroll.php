<?php
/*
 * =====================================================================
 *  backend/Modules/Accounting/Payroll.php  —  PAYROLL AND TIMEKEEPING
 * =====================================================================
 *  FMS PDF II.J and the Payroll role:
 *    - payroll for drivers, helpers, admin employees, contractors, PDI
 *    - trip and waybill information is the basis of trip-related pay
 *    - allowances, deductions and other adjustments
 *    - payslips and deduction forms
 *    - attendance (timekeeping) kept separate from disbursement
 *    - coordinate with Finance for payroll disbursement
 *
 *  COMPUTATION (DRIVERS PAYROLL.xlsx, 2026 weekly sheets):
 *    Regular wage   = Daily rate × days with attendance         (G = C × E)
 *    Trip pay       = trip pay rate per destination × trips       (Q23)
 *    Gross pay      = Regular wage + Trip pay + other earnings     (M = SUM(G:K))
 *    Deductions     = SSS, PhilHealth, Pag-IBIG, loans, CA, ...    (Y = SUM(N:X))
 *    Net pay        = Gross pay − Total deductions                  (Z = M − Y)
 *  The numbers come from Formula Settings (config/formulas.php).
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('Notify');

final class Payroll {
    public const ATTENDANCE = ['Present', 'Absent', 'VL', 'SIL', 'Rest Day'];
    public const LEDGER_TYPES = ['Cash Advance', 'Uniform', 'Damages', 'Cash Bond'];

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'attendance') {
            $from = self::d($q['from'] ?? '') ?? date('Y-m-d', strtotime('monday this week'));
            $to = self::d($q['to'] ?? '') ?? date('Y-m-d', strtotime($from . ' +6 days'));
            must((strtotime($to) - strtotime($from)) / 86400 <= 31, 'Show at most 31 days at a time.');
            $data = readSections([
                'people' => fn() => many($p, "SELECT id, full_name, person_type, payroll_group FROM personnel WHERE employment_status='Active' ORDER BY payroll_group, person_type, full_name"),
                'attendance' => fn() => many($p, 'SELECT personnel_id, work_date, status, remarks FROM attendance WHERE work_date BETWEEN ? AND ?', [$from, $to]),
            ]);
            $data['range'] = ['from' => $from, 'to' => $to];
            $data['statuses'] = self::ATTENDANCE;
            $data['can'] = ['edit' => hasRole($u, 'payroll')];
            return $data;
        }
        if ($page === 'balances') {
            $data = readSections([
                'ledgers' => fn() => many($p, "SELECT l.*, pe.full_name, pe.payroll_group FROM employee_ledgers l JOIN personnel pe ON pe.id=l.personnel_id
                    ORDER BY FIELD(l.status,'Open','Paid'), pe.full_name, l.ledger_type"),
                'entries' => fn() => many($p, 'SELECT e.*, l.personnel_id, l.ledger_type FROM ledger_entries e JOIN employee_ledgers l ON l.id=e.ledger_id ORDER BY e.id DESC LIMIT 3000'),
                'people' => fn() => many($p, "SELECT id, full_name FROM personnel WHERE employment_status='Active' ORDER BY full_name"),
            ]);
            $data['types'] = self::LEDGER_TYPES;
            $data['can'] = ['edit' => hasRole($u, 'payroll')];
            return $data;
        }
        $data = readSections([
            'periods' => fn() => many($p, "SELECT pp.*, (SELECT COUNT(*) FROM payroll_lines pl WHERE pl.period_id=pp.id) AS employees,
                (SELECT COALESCE(SUM(gross_pay),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) AS total_gross,
                (SELECT COALESCE(SUM(total_deductions),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) AS total_deductions,
                (SELECT COALESCE(SUM(net_pay),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) AS total_net,
                (SELECT COALESCE(SUM(trip_pay),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) AS total_trip_pay
                FROM payroll_periods pp ORDER BY pp.date_to DESC, pp.id DESC LIMIT 60"),
            'lines' => fn() => many($p, 'SELECT pl.*, pe.full_name, pe.person_type, pe.employee_no, pe.payroll_group FROM payroll_lines pl
                JOIN personnel pe ON pe.id=pl.personnel_id WHERE pl.period_id IN (SELECT id FROM (SELECT id FROM payroll_periods ORDER BY date_to DESC, id DESC LIMIT 60) x)
                ORDER BY pe.person_type, pe.full_name'),
            'people' => fn() => many($p, "SELECT id, full_name, person_type, payroll_group, daily_rate, pay_frequency FROM personnel WHERE employment_status='Active' ORDER BY full_name"),
            'tripRates' => fn() => many($p, 'SELECT r.*, ro.origin, ro.destination AS route_destination FROM trip_pay_rates r LEFT JOIN routes ro ON ro.id=r.route_id ORDER BY r.active DESC, r.truck_type, COALESCE(ro.destination, r.destination)'),
            'routes' => fn() => many($p, 'SELECT id, origin, destination FROM routes WHERE active=1 ORDER BY destination'),
            'groups' => fn() => array_column(many($p, "SELECT DISTINCT payroll_group FROM personnel WHERE payroll_group IS NOT NULL AND payroll_group<>'' ORDER BY payroll_group"), 'payroll_group'),
        ]);
        $data['earningItems'] = Settings::items('payroll_earning');
        $data['deductionItems'] = Settings::items('payroll_deduction');
        $data['can'] = ['edit' => hasRole($u, 'payroll')];
        return $data;
    }
    private static function d(string $v): ?string { return ($v !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $v : null; }

    public static function action(array $u, string $action, array $d): array {
        requireRole($u, 'payroll');
        return match ($action) {
            'rate_save' => self::rateSave($u, $d),
            'period_create' => self::periodCreate($u, $d),
            'period_compute' => self::periodCompute($u, $d),
            'line_update' => self::lineUpdate($u, $d),
            'line_add' => self::lineAdd($u, $d),
            'line_remove' => self::lineRemove($u, $d),
            'finalize' => self::finalize($u, $d),
            'reopen' => self::reopen($u, $d),
            'submit' => self::submit($u, $d),
            'attendance_save' => self::attendanceSave($u, $d),
            'ledger_add' => self::ledgerAdd($u, $d),
            'ledger_payment' => self::ledgerPayment($u, $d),
            'ledger_installment' => self::ledgerInstallment($u, $d),
            default => throw new BusinessRuleException('Unknown payroll action.', 404),
        };
    }

    /** Trip pay rate per destination; driver and helper rates differ (repo docs Q23). */
    private static function rateSave(array $u, array $d): array {
        $id = optId($d, 'id');
        $row = ['route_id' => optId($d, 'route_id'), 'destination' => optField($d, 'destination', 150), 'truck_type' => choiceField($d, 'truck_type', TRUCK_TYPES),
                'driver_rate' => moneyField($d, 'driver_rate', true), 'helper_rate' => moneyField($d, 'helper_rate', true), 'active' => empty($d['inactive']) ? 1 : 0];
        must($row['route_id'] !== null || $row['destination'] !== null, 'Choose a route or type the destination.');
        return transaction(function (PDO $p) use ($u, $id, $row) {
            if ($id) run($p, 'UPDATE trip_pay_rates SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($row))) . ' WHERE id=?', array_merge(array_values($row), [$id]));
            else $id = insert($p, 'trip_pay_rates', $row + ['created_by' => $u['id']]);
            audit($p, $u['id'], 'Payroll', 'trip pay rate ' . $id, 'trip pay rate saved', $row['truck_type'] . ' driver ' . $row['driver_rate'] . ' / helper ' . $row['helper_rate']);
            return ['rate_id' => $id];
        });
    }

    // -----------------------------------------------------------------
    // COMPUTATION OF ONE PAYROLL LINE
    // -----------------------------------------------------------------
    /** Trips of a person in the period, with the trip pay rate for each. */
    public static function tripsFor(PDO $p, int $personId, string $from, string $to): array {
        $needWaybill = (int)Settings::value('payroll_require_waybill') === 1;
        $rows = many($p, "SELECT t.id, t.trip_ref, t.truck_type, t.route_id, t.destination, DATE(COALESCE(t.departure_at, t.scheduled_at)) AS trip_date,
                (t.helper_id=?) AS as_helper, (SELECT COUNT(*) FROM waybills w WHERE w.trip_id=t.id) AS waybills
            FROM trips t WHERE t.status='Completed' AND (t.driver_id=? OR t.driver2_id=? OR t.helper_id=?)
            AND DATE(COALESCE(t.departure_at, t.scheduled_at)) BETWEEN ? AND ? ORDER BY trip_date", [$personId, $personId, $personId, $personId, $from, $to]);
        $out = ['counted' => [], 'missing_waybill' => [], 'no_rate' => []];
        foreach ($rows as $t) {
            if ($needWaybill && (int)$t['waybills'] === 0) { $out['missing_waybill'][] = $t['trip_ref']; continue; }
            $r = one($p, 'SELECT * FROM trip_pay_rates WHERE active=1 AND truck_type=? AND (route_id=? OR (route_id IS NULL AND destination=?)) ORDER BY route_id IS NULL, id DESC LIMIT 1',
                [$t['truck_type'], $t['route_id'], $t['destination']]);
            $rate = $r ? (float)($t['as_helper'] ? $r['helper_rate'] : $r['driver_rate']) : 0.0;
            if (!$r) $out['no_rate'][] = $t['trip_ref'];
            $out['counted'][] = ['trip_ref' => $t['trip_ref'], 'date' => $t['trip_date'], 'destination' => $t['destination'], 'role' => $t['as_helper'] ? 'Helper' : 'Driver', 'rate' => $rate];
        }
        return $out;
    }

    /** Builds a full payroll line from attendance, trips, ledgers and the inputs typed by Payroll. */
    private static function computeLine(PDO $p, array $period, array $person, array $inputs): array {
        $days = isset($inputs['days_override']) && $inputs['days_override'] !== null
            ? (float)$inputs['days_override']
            : (float)one($p, "SELECT COUNT(*) AS n FROM attendance WHERE personnel_id=? AND status='Present' AND work_date BETWEEN ? AND ?",
                [$person['id'], $period['date_from'], $period['date_to']])['n'];
        $trips = self::tripsFor($p, (int)$person['id'], $period['date_from'], $period['date_to']);
        $tripPay = round(array_sum(array_column($trips['counted'], 'rate')), 2);
        $tripCount = count($trips['counted']);
        $rate = (float)$person['daily_rate'];
        $wage = round($rate * $days, 2);
        $earn = Settings::computeItems('payroll_earning', ['days' => $days, 'trips' => $tripCount, 'manual' => $inputs['manual'] ?? [], 'override' => $inputs['override'] ?? []]);
        $gross = round($wage + $tripPay + $earn['total'], 2);
        // Installments from open balances (never more than the balance left).
        $ledgers = [];
        foreach (many($p, "SELECT ledger_type, SUM(LEAST(installment, balance)) AS due FROM employee_ledgers WHERE personnel_id=? AND status='Open' AND ledger_type<>'Cash Bond' GROUP BY ledger_type", [$person['id']]) as $l) {
            $ledgers[$l['ledger_type']] = (float)$l['due'];
        }
        $ded = Settings::computeItems('payroll_deduction', ['days' => $days, 'trips' => $tripCount, 'manual' => $inputs['manual'] ?? [], 'ledgers' => $ledgers, 'override' => $inputs['override'] ?? []]);
        $net = round($gross - $ded['total'], 2);
        return [
            'daily_rate' => number_format($rate, 2, '.', ''), 'days_worked' => $days, 'trips_count' => $tripCount,
            'regular_wage' => number_format($wage, 2, '.', ''), 'trip_pay' => number_format($tripPay, 2, '.', ''),
            'earnings_json' => json_encode(['lines' => $earn['lines'], 'trips' => $trips], JSON_UNESCAPED_UNICODE),
            'gross_pay' => number_format($gross, 2, '.', ''),
            'deductions_json' => json_encode(['lines' => $ded['lines'], 'inputs' => $inputs], JSON_UNESCAPED_UNICODE),
            'total_deductions' => number_format($ded['total'], 2, '.', ''), 'net_pay' => number_format($net, 2, '.', ''),
        ];
    }

    private static function lockPeriod(PDO $p, int $id, string $status = 'Draft'): array {
        $pp = one($p, 'SELECT * FROM payroll_periods WHERE id=? FOR UPDATE', [$id]);
        must((bool)$pp, 'Payroll period not found.', 404);
        must($pp['status'] === $status, "Payroll must be $status for this step (now: {$pp['status']}).", 409);
        return $pp;
    }

    private static function periodCreate(array $u, array $d): array {
        $name = field($d, 'period_name', 120); $group = optField($d, 'payroll_group', 60);
        $from = dateField($d, 'date_from'); $to = dateField($d, 'date_to');
        must($to >= $from, 'End date must be on or after the start date.');
        return transaction(function (PDO $p) use ($u, $name, $group, $from, $to) {
            $id = insert($p, 'payroll_periods', ['period_name' => $name, 'payroll_group' => $group, 'date_from' => $from, 'date_to' => $to, 'created_by' => $u['id']]);
            $pp = one($p, 'SELECT * FROM payroll_periods WHERE id=?', [$id]);
            $sql = "SELECT * FROM personnel WHERE employment_status='Active'" . ($group ? ' AND payroll_group=?' : '');
            $people = many($p, $sql, $group ? [$group] : []);
            foreach ($people as $pe) {
                insert($p, 'payroll_lines', ['period_id' => $id, 'personnel_id' => $pe['id']] + self::computeLine($p, $pp, $pe, []));
            }
            audit($p, $u['id'], 'Payroll', 'payroll ' . $id, 'payroll period created', "$name ($from to $to), " . count($people) . ' employee(s)');
            return ['period_id' => $id, 'message' => 'Draft payroll created for ' . count($people) . ' employee(s).'];
        });
    }

    /** Recompute every line (after attendance, waybills or formula changes). Typed inputs are kept. */
    private static function periodCompute(array $u, array $d): array {
        $id = idField($d, 'period_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $pp = self::lockPeriod($p, $id);
            foreach (many($p, 'SELECT pl.*, pe.* , pl.id AS line_id FROM payroll_lines pl JOIN personnel pe ON pe.id=pl.personnel_id WHERE pl.period_id=?', [$id]) as $row) {
                $inputs = json_decode((string)$row['deductions_json'], true)['inputs'] ?? [];
                $person = one($p, 'SELECT * FROM personnel WHERE id=?', [$row['personnel_id']]);
                $c = self::computeLine($p, $pp, $person, $inputs);
                run($p, 'UPDATE payroll_lines SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($c))) . ' WHERE id=?', array_merge(array_values($c), [$row['line_id']]));
            }
            audit($p, $u['id'], 'Payroll', 'payroll ' . $id, 'payroll recomputed');
            return ['period_id' => $id, 'message' => 'Payroll recomputed.'];
        });
    }

    /** Payroll edits one line: days, manual earnings/deductions, overrides. */
    private static function lineUpdate(array $u, array $d): array {
        $lineId = idField($d, 'line_id');
        $inputs = ['manual' => [], 'override' => [], 'days_override' => null];
        if (isset($d['days_override']) && $d['days_override'] !== '') $inputs['days_override'] = numberField($d, 'days_override');
        foreach (['manual', 'override'] as $k) {
            $vals = $d[$k] ?? [];
            must(is_array($vals), 'Invalid values.');
            foreach ($vals as $name => $amt) {
                if ($amt === '' || $amt === null) continue;
                must(is_string($name) && textLength($name) <= 100 && is_numeric($amt) && (float)$amt >= 0, 'Amounts must be numbers of zero or more.');
                $inputs[$k][$name] = round((float)$amt, 2);
            }
        }
        $notes = optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $lineId, $inputs, $notes) {
            $line = one($p, 'SELECT * FROM payroll_lines WHERE id=? FOR UPDATE', [$lineId]);
            must((bool)$line, 'Line not found.', 404);
            $pp = self::lockPeriod($p, (int)$line['period_id']);
            $person = one($p, 'SELECT * FROM personnel WHERE id=?', [$line['personnel_id']]);
            $c = self::computeLine($p, $pp, $person, $inputs) + ['notes' => $notes];
            run($p, 'UPDATE payroll_lines SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($c))) . ' WHERE id=?', array_merge(array_values($c), [$lineId]));
            audit($p, $u['id'], 'Payroll', 'payroll ' . $pp['id'], 'payroll line edited', $person['full_name'] . ': net ' . $c['net_pay']);
            return ['line_id' => $lineId, 'net_pay' => $c['net_pay']];
        });
    }

    private static function lineAdd(array $u, array $d): array {
        $id = idField($d, 'period_id'); $person = idField($d, 'personnel_id');
        return transaction(function (PDO $p) use ($u, $id, $person) {
            $pp = self::lockPeriod($p, $id);
            $pe = one($p, 'SELECT * FROM personnel WHERE id=?', [$person]);
            must((bool)$pe, 'Employee not found.');
            must(!one($p, 'SELECT id FROM payroll_lines WHERE period_id=? AND personnel_id=?', [$id, $person]), 'Already in this payroll.', 409);
            insert($p, 'payroll_lines', ['period_id' => $id, 'personnel_id' => $person] + self::computeLine($p, $pp, $pe, []));
            audit($p, $u['id'], 'Payroll', 'payroll ' . $id, 'employee added to payroll', $pe['full_name']);
            return ['period_id' => $id];
        });
    }

    private static function lineRemove(array $u, array $d): array {
        $lineId = idField($d, 'line_id');
        return transaction(function (PDO $p) use ($u, $lineId) {
            $line = one($p, 'SELECT pl.*, pe.full_name FROM payroll_lines pl JOIN personnel pe ON pe.id=pl.personnel_id WHERE pl.id=? FOR UPDATE', [$lineId]);
            must((bool)$line, 'Line not found.', 404);
            self::lockPeriod($p, (int)$line['period_id']);
            run($p, 'DELETE FROM payroll_lines WHERE id=?', [$lineId]);
            audit($p, $u['id'], 'Payroll', 'payroll ' . $line['period_id'], 'employee removed from payroll', $line['full_name']);
            return ['line_id' => $lineId];
        });
    }

    /**
     * Finalize: lock the payroll, save the formula values used, and post
     * the balance deductions (cash advance, uniform, damages) and cash bond.
     */
    private static function finalize(array $u, array $d): array {
        $id = idField($d, 'period_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $pp = self::lockPeriod($p, $id);
            $lines = many($p, 'SELECT * FROM payroll_lines WHERE period_id=?', [$id]);
            must((bool)$lines, 'Payroll has no employees.');
            foreach ($lines as $line) {
                $ded = json_decode((string)$line['deductions_json'], true)['lines'] ?? [];
                foreach ($ded as $dl) {
                    $amt = (float)$dl['amount'];
                    if ($amt <= 0 || empty($dl['ledger'])) continue;
                    self::postLedger($p, $u, (int)$line['personnel_id'], (string)$dl['ledger'], $amt, (int)$line['id'], $pp['period_name']);
                }
            }
            $snap = Settings::snapshot(['payroll_require_waybill'], ['payroll_earning', 'payroll_deduction']);
            run($p, "UPDATE payroll_periods SET status='Finalized', finalized_by=?, finalized_at=NOW(), formula_snapshot=? WHERE id=?",
                [$u['id'], json_encode($snap, JSON_UNESCAPED_UNICODE), $id]);
            audit($p, $u['id'], 'Payroll', 'payroll ' . $id, 'payroll finalized', $pp['period_name']);
            return ['period_id' => $id, 'message' => 'Payroll finalized. Payslips are ready. Submit it to Finance for disbursement.'];
        });
    }

    /** Applies one payroll deduction to an employee balance. */
    private static function postLedger(PDO $p, array $u, int $personId, string $type, float $amount, int $lineId, string $periodName): void {
        if ($type === 'Cash Bond') {
            // Cash bond / savings grows (company keeps it for the employee).
            $l = one($p, "SELECT * FROM employee_ledgers WHERE personnel_id=? AND ledger_type='Cash Bond' ORDER BY id LIMIT 1 FOR UPDATE", [$personId]);
            $lid = $l ? (int)$l['id'] : insert($p, 'employee_ledgers', ['personnel_id' => $personId, 'ledger_type' => 'Cash Bond', 'description' => 'Savings / cash bond',
                'source' => 'Payroll', 'created_by' => $u['id']]);
            run($p, 'UPDATE employee_ledgers SET total_amount=total_amount+?, balance=balance+? WHERE id=?', [$amount, $amount, $lid]);
            insert($p, 'ledger_entries', ['ledger_id' => $lid, 'entry_type' => 'Contribution', 'amount' => $amount, 'payroll_line_id' => $lineId, 'note' => $periodName, 'created_by' => $u['id']]);
            return;
        }
        // Pay the oldest open balances of that type first.
        $left = $amount;
        foreach (many($p, "SELECT * FROM employee_ledgers WHERE personnel_id=? AND ledger_type=? AND status='Open' ORDER BY id FOR UPDATE", [$personId, $type]) as $l) {
            if ($left <= 0) break;
            $pay = min($left, (float)$l['balance']);
            if ($pay <= 0) continue;
            $bal = round((float)$l['balance'] - $pay, 2);
            run($p, 'UPDATE employee_ledgers SET balance=?, status=? WHERE id=?', [$bal, $bal <= 0 ? 'Paid' : 'Open', $l['id']]);
            insert($p, 'ledger_entries', ['ledger_id' => $l['id'], 'entry_type' => 'Payment', 'amount' => $pay, 'payroll_line_id' => $lineId, 'note' => $periodName, 'created_by' => $u['id']]);
            $left = round($left - $pay, 2);
        }
    }

    /** Re-open a finalized payroll (before Finance gets it): balance postings are reversed. */
    private static function reopen(array $u, array $d): array {
        $id = idField($d, 'period_id'); $reason = field($d, 'reason', 200);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            self::lockPeriod($p, $id, 'Finalized');
            $entries = many($p, 'SELECT e.*, l.ledger_type FROM ledger_entries e JOIN employee_ledgers l ON l.id=e.ledger_id
                WHERE e.payroll_line_id IN (SELECT id FROM payroll_lines WHERE period_id=?) FOR UPDATE', [$id]);
            foreach ($entries as $e) {
                if ($e['entry_type'] === 'Contribution') run($p, 'UPDATE employee_ledgers SET total_amount=total_amount-?, balance=balance-? WHERE id=?', [$e['amount'], $e['amount'], $e['ledger_id']]);
                else run($p, "UPDATE employee_ledgers SET balance=balance+?, status='Open' WHERE id=?", [$e['amount'], $e['ledger_id']]);
                run($p, 'DELETE FROM ledger_entries WHERE id=?', [$e['id']]);
            }
            run($p, "UPDATE payroll_periods SET status='Draft', finalized_by=NULL, finalized_at=NULL WHERE id=?", [$id]);
            audit($p, $u['id'], 'Payroll', 'payroll ' . $id, 'payroll reopened', $reason);
            return ['period_id' => $id, 'message' => 'Payroll reopened as Draft.'];
        });
    }

    private static function submit(array $u, array $d): array {
        $id = idField($d, 'period_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $pp = self::lockPeriod($p, $id, 'Finalized');
            run($p, "UPDATE payroll_periods SET status='Submitted to Finance', submitted_at=NOW() WHERE id=?", [$id]);
            $total = (float)one($p, 'SELECT COALESCE(SUM(net_pay),0) AS t FROM payroll_lines WHERE period_id=?', [$id])['t'];
            audit($p, $u['id'], 'Payroll', 'payroll ' . $id, 'payroll submitted to Finance', $pp['period_name'] . ' PHP ' . number_format($total, 2));
            Notify::roles($p, ['finance'], 'Payroll disbursement', 'Payroll ' . $pp['period_name'] . ' (PHP ' . number_format($total, 2) . ') is ready for disbursement.', 'finance');
            return ['period_id' => $id, 'message' => 'Submitted to Finance.'];
        });
    }

    /** Saves attendance for many people and dates at once. */
    private static function attendanceSave(array $u, array $d): array {
        $rows = $d['rows'] ?? [];
        must(is_array($rows) && count($rows) <= 3000, 'Invalid attendance data.');
        return transaction(function (PDO $p) use ($u, $rows) {
            $saved = 0;
            foreach ($rows as $r) {
                must(is_array($r), 'Invalid attendance row.');
                $pid = idField($r, 'personnel_id'); $date = dateField($r, 'work_date');
                $status = trim((string)($r['status'] ?? ''));
                if ($status === '') { run($p, 'DELETE FROM attendance WHERE personnel_id=? AND work_date=?', [$pid, $date]); continue; }
                must(in_array($status, self::ATTENDANCE, true), 'Invalid attendance status.');
                $p->prepare('INSERT INTO attendance (personnel_id, work_date, status, recorded_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), recorded_by=VALUES(recorded_by)')
                  ->execute([$pid, $date, $status, $u['id']]);
                $saved++;
            }
            audit($p, $u['id'], 'Payroll', 'attendance', 'attendance saved', $saved . ' entries');
            return ['saved' => $saved, 'message' => 'Attendance saved.'];
        });
    }

    /** New balance: uniform, damages, opening cash advance or cash bond. */
    private static function ledgerAdd(array $u, array $d): array {
        $person = idField($d, 'personnel_id'); $type = choiceField($d, 'ledger_type', self::LEDGER_TYPES);
        $amount = moneyField($d, 'amount'); $inst = moneyField($d, 'installment', true); $desc = field($d, 'description', 255);
        return transaction(function (PDO $p) use ($u, $person, $type, $amount, $inst, $desc) {
            must((bool)one($p, 'SELECT id FROM personnel WHERE id=?', [$person]), 'Employee not found.');
            $lid = insert($p, 'employee_ledgers', ['personnel_id' => $person, 'ledger_type' => $type, 'description' => $desc, 'total_amount' => $amount,
                'installment' => $type === 'Cash Bond' ? 0 : $inst, 'balance' => $amount, 'source' => 'Payroll', 'created_by' => $u['id']]);
            insert($p, 'ledger_entries', ['ledger_id' => $lid, 'entry_type' => $type === 'Cash Bond' ? 'Contribution' : 'Charge', 'amount' => $amount, 'note' => 'Opening / new: ' . $desc, 'created_by' => $u['id']]);
            audit($p, $u['id'], 'Payroll', 'ledger ' . $lid, 'balance added', "$type PHP $amount");
            return ['ledger_id' => $lid];
        });
    }

    /** Payment made outside payroll (e.g. paid in cash) or cash bond released. */
    private static function ledgerPayment(array $u, array $d): array {
        $lid = idField($d, 'ledger_id'); $amount = moneyField($d, 'amount'); $note = field($d, 'note', 255);
        return transaction(function (PDO $p) use ($u, $lid, $amount, $note) {
            $l = one($p, 'SELECT * FROM employee_ledgers WHERE id=? FOR UPDATE', [$lid]);
            must($l && $l['status'] === 'Open', 'Balance is closed.', 409);
            must((float)$amount <= (float)$l['balance'], 'Amount is more than the balance.');
            $bal = round((float)$l['balance'] - (float)$amount, 2);
            run($p, 'UPDATE employee_ledgers SET balance=?, status=? WHERE id=?', [$bal, $bal <= 0 ? 'Paid' : 'Open', $lid]);
            insert($p, 'ledger_entries', ['ledger_id' => $lid, 'entry_type' => $l['ledger_type'] === 'Cash Bond' ? 'Release' : 'Payment', 'amount' => $amount, 'note' => $note, 'created_by' => $u['id']]);
            audit($p, $u['id'], 'Payroll', 'ledger ' . $lid, 'balance payment', "PHP $amount ($note)");
            return ['ledger_id' => $lid];
        });
    }

    private static function ledgerInstallment(array $u, array $d): array {
        $lid = idField($d, 'ledger_id'); $inst = moneyField($d, 'installment', true);
        return transaction(function (PDO $p) use ($u, $lid, $inst) {
            must(run($p, "UPDATE employee_ledgers SET installment=? WHERE id=? AND ledger_type<>'Cash Bond'", [$inst, $lid]) >= 0, '');
            audit($p, $u['id'], 'Payroll', 'ledger ' . $lid, 'installment changed', "PHP $inst per payroll");
            return ['ledger_id' => $lid];
        });
    }
}
