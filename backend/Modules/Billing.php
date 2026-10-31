<?php
/*
 * =====================================================================
 *  backend/Modules/Billing.php  —  BILLING, ACCOUNTS RECEIVABLE, CLIENTS
 * =====================================================================
 *  FMS PDF II.H and the Billing and Collection role:
 *    - client profiles and client-specific / route-based rates
 *    - link completed trips to billing; attach waybills
 *    - billing summaries and invoices (one invoice can cover many trips)
 *    - collections, outstanding balances, AR reports
 *    - completed trips that are not yet billed
 *  Routes (origin, destination, km, RFID/toll) are kept here because the
 *  rates, the automatic allowance and the projected P&L all use them.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('Notify');

final class Billing {
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'clients') {
            $data = readSections([
                'clients' => fn() => many($p, "SELECT c.*, (SELECT COUNT(*) FROM trips t WHERE t.client_id=c.id AND t.status='Completed') AS completed_trips,
                    (SELECT COALESCE(SUM(b.net_amount),0) - COALESCE((SELECT SUM(co.amount) FROM collections co JOIN billings b2 ON b2.id=co.billing_id WHERE b2.client_id=c.id AND b2.status<>'Cancelled'),0)
                        FROM billings b WHERE b.client_id=c.id AND b.status<>'Cancelled') AS outstanding
                    FROM clients c ORDER BY c.active DESC, c.name"),
                'routes' => fn() => many($p, 'SELECT * FROM routes ORDER BY active DESC, origin, destination'),
                'rates' => fn() => hasRole($u, 'operations_head', 'payroll') ? [] : many($p, "SELECT r.*, c.name AS client, c.company_name, ro.origin, ro.destination, ro.distance_km, ro.toll_fee
                    FROM client_rates r JOIN clients c ON c.id=r.client_id LEFT JOIN routes ro ON ro.id=r.route_id ORDER BY FIELD(r.status,'Active','Inactive'), c.name, r.truck_type"),
            ]);
            // Projected P&L per active rate (Billing P&L workbook formula).
            $data['pnl'] = [];
            if (!hasRole($u, 'operations_head', 'payroll')) {
                foreach ($data['rates'] as $r) {
                    if ($r['status'] !== 'Active' || $r['rate_basis'] === 'Per KM') continue;
                    $rate = (float)$r['amount'] * ($r['rate_basis'] === 'Per Unit' ? Settings::value('units_per_trip') : 1);
                    $data['pnl'][] = ['rate_id' => $r['id'], 'client' => $r['client'], 'route' => $r['origin'] ? $r['origin'] . ' → ' . $r['destination'] : 'Any route',
                        'truck_type' => $r['truck_type']] + Settings::projectedPnl($rate, (float)($r['distance_km'] ?? 0), (float)($r['toll_fee'] ?? 0));
                }
            }
            $data['can'] = ['client' => hasRole($u, 'admin', 'billing'), 'route' => hasRole($u, 'admin', 'operations_head', 'billing'),
                            'rate' => hasRole($u, 'admin', 'billing')];
            return $data;
        }
        $data = readSections([
            'unbilled' => fn() => many($p, "SELECT t.id, t.trip_ref, t.client_id, c.name AS client, c.company_name, t.truck_type, t.origin, t.destination, t.scheduled_at,
                    t.completed_at, t.units_planned, t.units_delivered, t.client_rate, v.plate_number,
                    (SELECT GROUP_CONCAT(w.waybill_number SEPARATOR ', ') FROM waybills w WHERE w.trip_id=t.id) AS waybills,
                    (SELECT MIN(w.submitted_on) FROM waybills w WHERE w.trip_id=t.id) AS waybill_submitted
                FROM trips t JOIN clients c ON c.id=t.client_id LEFT JOIN vehicles v ON v.id=t.vehicle_id
                WHERE t.status='Completed' AND NOT EXISTS (SELECT 1 FROM billing_items bi JOIN billings b ON b.id=bi.billing_id WHERE bi.trip_id=t.id AND b.status<>'Cancelled')
                ORDER BY c.name, t.completed_at"),
            'invoices' => fn() => many($p, "SELECT b.*, c.name AS client, c.company_name,
                    COALESCE((SELECT SUM(co.amount) FROM collections co WHERE co.billing_id=b.id),0) AS collected,
                    (SELECT COUNT(*) FROM billing_items bi WHERE bi.billing_id=b.id) AS trip_count,
                    DATEDIFF(CURRENT_DATE(), b.due_date) AS days_overdue
                FROM billings b JOIN clients c ON c.id=b.client_id ORDER BY b.invoice_date DESC, b.id DESC LIMIT 1500"),
            'items' => fn() => many($p, "SELECT bi.*, t.trip_ref, t.origin, t.destination, (SELECT GROUP_CONCAT(w.waybill_number SEPARATOR ', ') FROM waybills w WHERE w.trip_id=t.id) AS waybills
                FROM billing_items bi JOIN trips t ON t.id=bi.trip_id ORDER BY bi.id DESC LIMIT 5000"),
            'collections' => fn() => many($p, 'SELECT co.*, b.invoice_number, c.name AS client, us.full_name AS recorded_by_name FROM collections co
                JOIN billings b ON b.id=co.billing_id JOIN clients c ON c.id=b.client_id JOIN users us ON us.id=co.recorded_by ORDER BY co.payment_date DESC, co.id DESC LIMIT 2000'),
            'clients' => fn() => many($p, 'SELECT id, name, company_name, payment_terms_days FROM clients ORDER BY name'),
        ]);
        $data['defaultTerms'] = Settings::value('default_payment_terms_days');
        $data['can'] = ['edit' => hasRole($u, 'billing')];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'client_save' => self::clientSave($u, $d),
            'route_save' => self::routeSave($u, $d),
            'rate_save' => self::rateSave($u, $d),
            'invoice_create' => self::invoiceCreate($u, $d),
            'invoice_cancel' => self::invoiceCancel($u, $d),
            'collection_add' => self::collectionAdd($u, $d),
            default => throw new BusinessRuleException('Unknown billing action.', 404),
        };
    }

    private static function clientSave(array $u, array $d): array {
        requireRole($u, 'admin', 'billing');
        $id = optId($d, 'id');
        $row = ['name' => field($d, 'name', 150), 'company_name' => optField($d, 'company_name', 150), 'contact_person' => optField($d, 'contact_person', 120),
                'contact_number' => optField($d, 'contact_number', 60), 'email' => optField($d, 'email', 120), 'address' => optField($d, 'address', 255),
                'payment_terms_days' => intField($d, 'payment_terms_days', 0, false), 'active' => empty($d['inactive']) ? 1 : 0];
        return transaction(function (PDO $p) use ($u, $id, $row) {
            must(!one($p, "SELECT id FROM clients WHERE name=? AND COALESCE(company_name,'')=COALESCE(?,'') AND id<>?", [$row['name'], $row['company_name'], $id ?? 0]), 'This client already exists.', 409);
            if ($id) run($p, 'UPDATE clients SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($row))) . ' WHERE id=?', array_merge(array_values($row), [$id]));
            else $id = insert($p, 'clients', $row);
            audit($p, $u['id'], 'Billing', 'client ' . $id, 'client saved', $row['name']);
            return ['client_id' => $id];
        });
    }

    private static function routeSave(array $u, array $d): array {
        requireRole($u, 'admin', 'operations_head', 'billing');
        $id = optId($d, 'id');
        $row = ['origin' => field($d, 'origin', 150), 'destination' => field($d, 'destination', 150), 'distance_km' => numberField($d, 'distance_km'),
                'toll_fee' => moneyField($d, 'toll_fee', true), 'notes' => optField($d, 'notes', 255), 'active' => empty($d['inactive']) ? 1 : 0];
        return transaction(function (PDO $p) use ($u, $id, $row) {
            must(!one($p, 'SELECT id FROM routes WHERE origin=? AND destination=? AND id<>?', [$row['origin'], $row['destination'], $id ?? 0]), 'This route already exists.', 409);
            if ($id) run($p, 'UPDATE routes SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($row))) . ' WHERE id=?', array_merge(array_values($row), [$id]));
            else $id = insert($p, 'routes', $row);
            audit($p, $u['id'], 'Billing', 'route ' . $id, 'route saved', $row['origin'] . ' -> ' . $row['destination'] . ' ' . $row['distance_km'] . ' km, toll ' . $row['toll_fee']);
            return ['route_id' => $id];
        });
    }

    /** Client-specific, route-based rate. Old rates are kept for history (set Inactive). */
    private static function rateSave(array $u, array $d): array {
        requireRole($u, 'admin', 'billing');
        $id = optId($d, 'id');
        $row = ['client_id' => idField($d, 'client_id'), 'route_id' => optId($d, 'route_id'), 'truck_type' => choiceField($d, 'truck_type', TRUCK_TYPES),
                'rate_basis' => choiceField($d, 'rate_basis', ['Per Trip', 'Per Unit', 'Per KM']), 'amount' => moneyField($d, 'amount'),
                'effective_on' => dateField($d, 'effective_on', false), 'status' => choiceField($d, 'status', ['Active', 'Inactive'])];
        return transaction(function (PDO $p) use ($u, $id, $row) {
            if ($id) run($p, 'UPDATE client_rates SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($row))) . ' WHERE id=?', array_merge(array_values($row), [$id]));
            else $id = insert($p, 'client_rates', $row + ['created_by' => $u['id']]);
            audit($p, $u['id'], 'Billing', 'rate ' . $id, 'client rate saved', $row['truck_type'] . ' ' . $row['rate_basis'] . ' PHP ' . $row['amount']);
            return ['rate_id' => $id];
        });
    }

    /**
     * Invoice for one client covering one or more completed trips.
     * Due date default = invoice date + client payment terms (repo docs Q15).
     */
    private static function invoiceCreate(array $u, array $d): array {
        requireRole($u, 'billing');
        $client = idField($d, 'client_id'); $no = field($d, 'invoice_number', 70); $date = dateField($d, 'invoice_date');
        $due = dateField($d, 'due_date', false); $discount = optMoney($d, 'discount') ?? '0.00'; $notes = optField($d, 'notes', 255);
        $items = $d['items'] ?? [];
        must(is_array($items) && count($items) >= 1 && count($items) <= 200, 'Choose at least one completed trip.');
        $clean = [];
        foreach ($items as $it) {
            must(is_array($it), 'Invalid trip line.');
            $qty = numberField($it, 'quantity', false) ?? 1.0;
            must($qty > 0, 'Quantity must be more than zero.');
            $clean[] = ['trip_id' => idField($it, 'trip_id'), 'rate' => (float)moneyField($it, 'rate'), 'quantity' => $qty,
                        'rate_basis' => choiceField($it, 'rate_basis', ['Per Trip', 'Per Unit', 'Per KM'], false) ?? 'Per Trip',
                        'description' => optField($it, 'description', 255)];
        }
        must(count(array_unique(array_column($clean, 'trip_id'))) === count($clean), 'A trip was listed twice.');
        return transaction(function (PDO $p) use ($u, $client, $no, $date, $due, $discount, $notes, $clean) {
            $c = one($p, 'SELECT * FROM clients WHERE id=?', [$client]);
            must((bool)$c, 'Client not found.');
            must(!one($p, 'SELECT id FROM billings WHERE invoice_number=?', [$no]), 'Invoice number already used.', 409);
            if (!$due) {
                $terms = $c['payment_terms_days'] !== null ? (int)$c['payment_terms_days'] : (int)Settings::value('default_payment_terms_days');
                $due = date('Y-m-d', strtotime($date . " +$terms days"));
            }
            must($due >= $date, 'Due date must be on or after the invoice date.');
            $gross = 0.0;
            foreach ($clean as &$it) {
                $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$it['trip_id']]);
                must($t && $t['status'] === 'Completed', 'Only completed trips can be billed.', 409);
                must((int)$t['client_id'] === $client, $t['trip_ref'] . ' belongs to another client.', 409);
                must(!one($p, "SELECT bi.id FROM billing_items bi JOIN billings b ON b.id=bi.billing_id WHERE bi.trip_id=? AND b.status<>'Cancelled'", [$it['trip_id']]), $t['trip_ref'] . ' is already billed.', 409);
                $it['amount'] = round($it['rate'] * $it['quantity'], 2);
                $it['description'] = $it['description'] ?? ($t['trip_ref'] . ' ' . $t['origin'] . ' - ' . $t['destination']);
                $gross += $it['amount'];
            }
            unset($it);
            $net = round($gross - (float)$discount, 2);
            must($net > 0, 'Invoice total must be more than zero.');
            $bid = insert($p, 'billings', ['invoice_number' => $no, 'client_id' => $client, 'invoice_date' => $date, 'due_date' => $due,
                'gross_amount' => number_format($gross, 2, '.', ''), 'discount' => $discount, 'net_amount' => number_format($net, 2, '.', ''), 'notes' => $notes, 'created_by' => $u['id']]);
            // Trips without a waybill are allowed but listed in the audit note (waybill follow-up).
            $missing = [];
            foreach ($clean as $it) {
                insert($p, 'billing_items', ['billing_id' => $bid, 'trip_id' => $it['trip_id'], 'description' => $it['description'], 'rate_basis' => $it['rate_basis'],
                    'quantity' => $it['quantity'], 'rate' => number_format($it['rate'], 2, '.', ''), 'amount' => number_format($it['amount'], 2, '.', '')]);
                if (!one($p, 'SELECT id FROM waybills WHERE trip_id=? LIMIT 1', [$it['trip_id']])) $missing[] = $it['trip_id'];
            }
            audit($p, $u['id'], 'Billing', $no, 'invoice issued', $c['name'] . ': ' . count($clean) . ' trip(s), PHP ' . number_format($net, 2) . ($missing ? '; trips without waybill: ' . implode(',', $missing) : ''));
            return ['billing_id' => $bid, 'message' => 'Invoice ' . $no . ' issued (PHP ' . number_format($net, 2) . ', due ' . $due . ').',
                    'warning' => $missing ? count($missing) . ' trip(s) have no waybill recorded yet. Please follow up.' : null];
        });
    }

    private static function invoiceCancel(array $u, array $d): array {
        requireRole($u, 'billing');
        $id = idField($d, 'billing_id'); $reason = field($d, 'reason', 200);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $b = one($p, 'SELECT * FROM billings WHERE id=? FOR UPDATE', [$id]);
            must($b && $b['status'] !== 'Cancelled', 'Invoice not found or already cancelled.', 409);
            must(!one($p, 'SELECT id FROM collections WHERE billing_id=? LIMIT 1', [$id]), 'Invoice has collections and cannot be cancelled.', 409);
            run($p, "UPDATE billings SET status='Cancelled', notes=CONCAT(COALESCE(notes,''), ' | Cancelled: ', ?) WHERE id=?", [$reason, $id]);
            run($p, 'DELETE FROM billing_items WHERE billing_id=?', [$id]);   // trips become billable again
            audit($p, $u['id'], 'Billing', $b['invoice_number'], 'invoice cancelled', $reason);
            return ['billing_id' => $id, 'message' => 'Invoice cancelled. Its trips can be billed again.'];
        });
    }

    private static function collectionAdd(array $u, array $d): array {
        requireRole($u, 'billing');
        $id = idField($d, 'billing_id'); $amount = moneyField($d, 'amount');
        $row = ['payment_date' => dateField($d, 'payment_date'), 'payment_method' => choiceField($d, 'payment_method', ['Cash', 'Cheque', 'Bank Transfer', 'E-wallet', 'Other']),
                'reference' => field($d, 'reference', 100), 'cheque_number' => optField($d, 'cheque_number', 60), 'bank_name' => optField($d, 'bank_name', 100)];
        if ($row['payment_method'] === 'Cheque') must($row['cheque_number'] !== null, 'Cheque number is required.');
        return transaction(function (PDO $p) use ($u, $id, $amount, $row) {
            $b = one($p, 'SELECT * FROM billings WHERE id=? FOR UPDATE', [$id]);
            must($b && $b['status'] !== 'Cancelled', 'Invoice not found.', 404);
            $paid = (float)one($p, 'SELECT COALESCE(SUM(amount),0) AS s FROM collections WHERE billing_id=?', [$id])['s'];
            $rem = round((float)$b['net_amount'] - $paid, 2);
            must($rem > 0 && (float)$amount <= $rem, 'Payment is more than the balance (PHP ' . number_format($rem, 2) . ').', 409);
            insert($p, 'collections', ['billing_id' => $id, 'amount' => $amount, 'recorded_by' => $u['id']] + $row);
            $status = abs($rem - (float)$amount) < 0.01 ? 'Paid' : 'Partially Paid';
            run($p, 'UPDATE billings SET status=? WHERE id=?', [$status, $id]);
            audit($p, $u['id'], 'Billing', $b['invoice_number'], 'collection recorded', 'PHP ' . $amount . ' ' . $row['payment_method'] . ' ref ' . $row['reference']);
            return ['billing_id' => $id, 'message' => 'Collection recorded. Invoice is ' . $status . '.'];
        });
    }
}
