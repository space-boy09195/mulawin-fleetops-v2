<?php
/*
 * =====================================================================
 *  backend/Modules/Accounting/Finance.php  —  FINANCE AND ACCOUNTS PAYABLE
 * =====================================================================
 *  FMS PDF II.I and the Finance role:
 *    - record accounts payable and company expenses
 *    - cash advances, disbursements and payment vouchers
 *    - cheque payments and other payment transactions
 *    - cash flow and operating expenses
 *    - payroll disbursement (coordination with Payroll)
 *    - budgets used by the purchase approval check (PDF p.17)
 *  Trip fund requests are in Modules/Accounting/Funds.php.
 *  Audit has read-only access.
 * =====================================================================
 */
declare(strict_types=1);
core('Notify');
core('Money');

final class Finance {
    public const EXPENSE_CATEGORIES = ['Fuel', 'RFID/Toll', 'Trip Allowance', 'Payroll', 'Maintenance', 'Parts', 'Office', 'Utilities', 'Permits and Registration', 'Insurance', 'Other'];

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $from = self::date($q['from'] ?? '') ?? date('Y-m-01', strtotime('first day of -11 months'));
        $to = self::date($q['to'] ?? '') ?? date('Y-m-d');
        $data = readSections([
            'expenses' => fn() => many($p, 'SELECT e.*, t.trip_ref, v.plate_number, us.full_name AS recorded_by_name FROM expenses e
                LEFT JOIN trips t ON t.id=e.trip_id LEFT JOIN vehicles v ON v.id=e.vehicle_id LEFT JOIN users us ON us.id=e.recorded_by
                WHERE e.incurred_on BETWEEN ? AND ? ORDER BY e.incurred_on DESC, e.id DESC LIMIT 2000', [$from, $to]),
            'payables' => fn() => many($p, "SELECT a.*, s.name AS supplier, DATEDIFF(a.due_on, CURRENT_DATE()) AS days_to_due FROM accounts_payable a
                LEFT JOIN suppliers s ON s.id=a.supplier_id ORDER BY FIELD(a.status,'Unpaid','Partially Paid','Paid'), a.due_on IS NULL, a.due_on, a.id DESC LIMIT 1000"),
            'vouchers' => fn() => many($p, 'SELECT pv.*, us.full_name AS created_by_name FROM payment_vouchers pv JOIN users us ON us.id=pv.created_by ORDER BY pv.id DESC LIMIT 1000'),
            'disbursements' => fn() => many($p, 'SELECT d.*, pv.voucher_number, us.full_name AS recorded_by_name FROM disbursements d
                LEFT JOIN payment_vouchers pv ON pv.id=d.voucher_id JOIN users us ON us.id=d.recorded_by
                WHERE d.released_on BETWEEN ? AND ? ORDER BY d.released_on DESC, d.id DESC LIMIT 2000', [$from, $to]),
            'budgets' => fn() => many($p, 'SELECT b.*, us.full_name AS set_by_name FROM budgets b JOIN users us ON us.id=b.set_by ORDER BY b.id DESC LIMIT 100'),
            'cashAdvances' => fn() => many($p, "SELECT l.*, pe.full_name FROM employee_ledgers l JOIN personnel pe ON pe.id=l.personnel_id
                WHERE l.ledger_type='Cash Advance' ORDER BY FIELD(l.status,'Open','Paid'), l.id DESC LIMIT 500"),
            'payrollToPay' => fn() => many($p, "SELECT pp.*, (SELECT COALESCE(SUM(net_pay),0) FROM payroll_lines pl WHERE pl.period_id=pp.id) AS total_net,
                (SELECT COUNT(*) FROM payroll_lines pl WHERE pl.period_id=pp.id) AS employees
                FROM payroll_periods pp WHERE pp.status IN ('Submitted to Finance','Disbursed') ORDER BY FIELD(pp.status,'Submitted to Finance','Disbursed'), pp.id DESC LIMIT 100"),
            'cashFlow' => fn() => self::cashFlow($p, $from, $to),
            'suppliers' => fn() => many($p, 'SELECT id, name FROM suppliers WHERE active=1 ORDER BY name'),
            'personnel' => fn() => many($p, "SELECT id, full_name, person_type FROM personnel WHERE employment_status='Active' ORDER BY full_name"),
            'vehicles' => fn() => many($p, 'SELECT id, plate_number FROM vehicles ORDER BY plate_number'),
            'trips' => fn() => many($p, "SELECT id, trip_ref FROM trips WHERE status NOT IN ('Cancelled','Rejected') ORDER BY id DESC LIMIT 500"),
        ]);
        $data['range'] = ['from' => $from, 'to' => $to];
        $data['categories'] = self::EXPENSE_CATEGORIES;
        $data['can'] = ['edit' => hasRole($u, 'finance')];
        return $data;
    }

    private static function date(string $v): ?string {
        return ($v !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $v : null;
    }

    /** Cash flow per month: money in = collections, money out = disbursements. */
    private static function cashFlow(PDO $p, string $from, string $to): array {
        $in = many($p, "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS m, SUM(amount) AS total FROM collections WHERE payment_date BETWEEN ? AND ? GROUP BY m", [$from, $to]);
        $out = many($p, "SELECT DATE_FORMAT(released_on,'%Y-%m') AS m, SUM(amount) AS total FROM disbursements WHERE released_on BETWEEN ? AND ? GROUP BY m", [$from, $to]);
        $months = [];
        foreach ($in as $r) $months[$r['m']]['in'] = (float)$r['total'];
        foreach ($out as $r) $months[$r['m']]['out'] = (float)$r['total'];
        ksort($months);
        $rows = []; $running = 0.0;
        foreach ($months as $m => $v) {
            $net = ($v['in'] ?? 0) - ($v['out'] ?? 0); $running += $net;
            $rows[] = ['month' => $m, 'cash_in' => round($v['in'] ?? 0, 2), 'cash_out' => round($v['out'] ?? 0, 2), 'net' => round($net, 2), 'running' => round($running, 2)];
        }
        return $rows;
    }

    public static function action(array $u, string $action, array $d): array {
        requireRole($u, 'finance');
        return match ($action) {
            'expense_add' => self::expenseAdd($u, $d),
            'payable_add' => self::payableAdd($u, $d),
            'payable_pay' => self::payablePay($u, $d),
            'budget_set' => self::budgetSet($u, $d),
            'cash_advance' => self::cashAdvance($u, $d),
            'payroll_disburse' => self::payrollDisburse($u, $d),
            default => throw new BusinessRuleException('Unknown finance action.', 404),
        };
    }

    private static function expenseAdd(array $u, array $d): array {
        $row = ['category' => choiceField($d, 'category', self::EXPENSE_CATEGORIES), 'trip_id' => optId($d, 'trip_id'), 'vehicle_id' => optId($d, 'vehicle_id'),
                'amount' => moneyField($d, 'amount'), 'incurred_on' => dateField($d, 'incurred_on'), 'payee' => optField($d, 'payee', 150),
                'reference_number' => optField($d, 'reference_number', 100), 'description' => optField($d, 'description', 500)];
        $paidNow = !empty($d['paid_now']);
        $pay = $paidNow ? Money::paymentFields($d + ['released_on' => $row['incurred_on']]) : null;
        return transaction(function (PDO $p) use ($u, $row, $pay) {
            $id = insert($p, 'expenses', $row + ['source' => 'Manual', 'recorded_by' => $u['id']]);
            if ($pay) {
                $vid = Money::voucher($p, $u, 'other', $id, $row['payee'] ?? $row['category'], $row['amount'], 'Paid');
                insert($p, 'disbursements', ['source_type' => 'expense', 'source_id' => $id, 'voucher_id' => $vid, 'payee' => $row['payee'] ?? $row['category'],
                    'amount' => $row['amount'], 'recorded_by' => $u['id']] + $pay);
            }
            audit($p, $u['id'], 'Finance', 'expense ' . $id, 'expense recorded', $row['category'] . ' PHP ' . $row['amount']);
            return ['expense_id' => $id, 'message' => 'Expense recorded' . ($pay ? ' and payment released.' : '.')];
        });
    }

    private static function payableAdd(array $u, array $d): array {
        $row = ['supplier_id' => optId($d, 'supplier_id'), 'payee' => optField($d, 'payee', 150), 'reference_number' => optField($d, 'reference_number', 100),
                'description' => field($d, 'description', 255), 'amount' => moneyField($d, 'amount'), 'due_on' => dateField($d, 'due_on', false)];
        must($row['supplier_id'] !== null || $row['payee'] !== null, 'Choose a supplier or type the payee.');
        return transaction(function (PDO $p) use ($u, $row) {
            $id = insert($p, 'accounts_payable', $row + ['recorded_by' => $u['id']]);
            audit($p, $u['id'], 'Finance', 'payable ' . $id, 'payable recorded', $row['description'] . ' PHP ' . $row['amount']);
            return ['payable_id' => $id];
        });
    }

    /** Pays a payable fully or partly (cheque or other). */
    private static function payablePay(array $u, array $d): array {
        $id = idField($d, 'payable_id'); $amount = moneyField($d, 'amount'); $pay = Money::paymentFields($d);
        return transaction(function (PDO $p) use ($u, $id, $amount, $pay) {
            $a = one($p, 'SELECT a.*, s.name AS supplier FROM accounts_payable a LEFT JOIN suppliers s ON s.id=a.supplier_id WHERE a.id=? FOR UPDATE', [$id]);
            must($a && $a['status'] !== 'Paid', 'Payable is already paid.', 409);
            $balance = round((float)$a['amount'] - (float)$a['paid_amount'], 2);
            must((float)$amount <= $balance, 'Payment is more than the balance (PHP ' . number_format($balance, 2) . ').');
            $payee = $a['supplier'] ?? $a['payee'] ?? 'Payee';
            $vid = Money::voucher($p, $u, 'payable', $id, $payee, $amount, 'Paid');
            insert($p, 'disbursements', ['source_type' => 'payable', 'source_id' => $id, 'voucher_id' => $vid, 'payee' => $payee, 'amount' => $amount, 'recorded_by' => $u['id']] + $pay);
            $paid = round((float)$a['paid_amount'] + (float)$amount, 2);
            run($p, 'UPDATE accounts_payable SET paid_amount=?, status=? WHERE id=?', [$paid, abs($paid - (float)$a['amount']) < 0.01 ? 'Paid' : 'Partially Paid', $id]);
            audit($p, $u['id'], 'Finance', 'payable ' . $id, 'payable paid', 'PHP ' . $amount . ' via ' . $pay['payment_method'] . ($pay['cheque_number'] ? ' cheque ' . $pay['cheque_number'] : ''));
            return ['payable_id' => $id, 'message' => 'Payment recorded.'];
        });
    }

    /** Budget used by the purchase approval check. Setting a new one closes the old active one of the same category. */
    private static function budgetSet(array $u, array $d): array {
        $cat = choiceField($d, 'category', ['Purchasing', 'Operations', 'Maintenance']);
        $amount = moneyField($d, 'budget_amount');
        $start = dateField($d, 'period_start', false); $end = dateField($d, 'period_end', false);
        if ($start && $end) must($end >= $start, 'Period end must be after the start.');
        return transaction(function (PDO $p) use ($u, $cat, $amount, $start, $end) {
            run($p, "UPDATE budgets SET status='Closed' WHERE category=? AND status='Active'", [$cat]);
            $id = insert($p, 'budgets', ['category' => $cat, 'budget_amount' => $amount, 'period_start' => $start, 'period_end' => $end, 'set_by' => $u['id']]);
            audit($p, $u['id'], 'Finance', 'budget ' . $id, 'budget set', "$cat PHP $amount");
            return ['budget_id' => $id, 'message' => "$cat budget set to PHP " . number_format((float)$amount, 2) . '.'];
        });
    }

    /** Employee cash advance: money released now, deducted later in payroll. */
    private static function cashAdvance(array $u, array $d): array {
        $person = idField($d, 'personnel_id'); $amount = moneyField($d, 'amount'); $inst = moneyField($d, 'installment');
        $purpose = field($d, 'purpose', 255); $pay = Money::paymentFields($d);
        must((float)$inst <= (float)$amount, 'Installment cannot be more than the amount.');
        return transaction(function (PDO $p) use ($u, $person, $amount, $inst, $purpose, $pay) {
            $pe = one($p, "SELECT full_name FROM personnel WHERE id=? AND employment_status='Active'", [$person]);
            must((bool)$pe, 'Employee not found.');
            $lid = insert($p, 'employee_ledgers', ['personnel_id' => $person, 'ledger_type' => 'Cash Advance', 'description' => $purpose, 'total_amount' => $amount,
                'installment' => $inst, 'balance' => $amount, 'source' => 'Finance', 'created_by' => $u['id']]);
            insert($p, 'ledger_entries', ['ledger_id' => $lid, 'entry_type' => 'Charge', 'amount' => $amount, 'note' => $purpose, 'created_by' => $u['id']]);
            $vid = Money::voucher($p, $u, 'cash_advance', $lid, $pe['full_name'], $amount, 'Paid');
            insert($p, 'disbursements', ['source_type' => 'cash_advance', 'source_id' => $lid, 'voucher_id' => $vid, 'payee' => $pe['full_name'], 'amount' => $amount, 'recorded_by' => $u['id']] + $pay);
            audit($p, $u['id'], 'Finance', 'cash advance ' . $lid, 'cash advance released', $pe['full_name'] . " PHP $amount, PHP $inst per payroll");
            Notify::roles($p, ['payroll'], 'Cash advance', $pe['full_name'] . ' got a cash advance of PHP ' . number_format((float)$amount, 2) . ' (PHP ' . number_format((float)$inst, 2) . ' per payroll).', 'balances');
            return ['ledger_id' => $lid, 'message' => 'Cash advance released. Payroll will deduct PHP ' . number_format((float)$inst, 2) . ' each payroll.'];
        });
    }

    /** Payroll disbursement (PDF: Finance coordinates with Payroll). */
    private static function payrollDisburse(array $u, array $d): array {
        $id = idField($d, 'period_id'); $pay = Money::paymentFields($d);
        return transaction(function (PDO $p) use ($u, $id, $pay) {
            $pp = one($p, 'SELECT * FROM payroll_periods WHERE id=? FOR UPDATE', [$id]);
            must($pp && $pp['status'] === 'Submitted to Finance', 'Payroll is not waiting for disbursement.', 409);
            $total = (float)one($p, 'SELECT COALESCE(SUM(net_pay),0) AS t FROM payroll_lines WHERE period_id=?', [$id])['t'];
            must($total > 0, 'Payroll total is zero.');
            $amt = number_format($total, 2, '.', '');
            $vid = Money::voucher($p, $u, 'payroll', $id, 'Payroll ' . $pp['period_name'], $amt, 'Paid');
            insert($p, 'disbursements', ['source_type' => 'payroll', 'source_id' => $id, 'voucher_id' => $vid, 'payee' => 'Payroll ' . $pp['period_name'], 'amount' => $amt, 'recorded_by' => $u['id']] + $pay);
            insert($p, 'expenses', ['category' => 'Payroll', 'amount' => $amt, 'incurred_on' => $pay['released_on'], 'payee' => 'Employees',
                'reference_number' => $pay['reference_number'] ?? $pay['cheque_number'], 'description' => 'Payroll ' . $pp['period_name'], 'source' => 'Payroll', 'source_id' => $id, 'recorded_by' => $u['id']]);
            run($p, "UPDATE payroll_periods SET status='Disbursed', disbursed_at=NOW() WHERE id=?", [$id]);
            audit($p, $u['id'], 'Finance', 'payroll ' . $id, 'payroll disbursed', $pp['period_name'] . ' PHP ' . $amt);
            Notify::roles($p, ['payroll'], 'Payroll disbursed', $pp['period_name'] . ' was disbursed by Finance.', 'payroll');
            return ['period_id' => $id, 'message' => 'Payroll disbursed: PHP ' . number_format($total, 2) . '.'];
        });
    }
}
