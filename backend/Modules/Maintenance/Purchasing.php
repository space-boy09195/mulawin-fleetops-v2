<?php
/*
 * =====================================================================
 *  backend/Modules/Maintenance/Purchasing.php  —  PURCHASING AND INVENTORY
 * =====================================================================
 *  FMS PDF II.K and p.17 (Maintenance module A and B).
 *
 *  A. STOCK-OUT  Motorpool requests parts -> Purchasing checks stock ->
 *     if available, Purchasing releases and updates the system.
 *  B. STOCK-IN   Purchasing sends a purchase request for approval:
 *       amount > limit  -> Admin approves
 *       amount <= limit -> Finance approves
 *     Then the system checks the Finance budget. If it does not fit, the
 *     request is rejected by the system as "Insufficient Budget".
 *     Approved -> purchase order -> receipt (stock-in, AP, parts expense).
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('Notify');

final class Purchasing {
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'inventory') {
            $data = readSections([
                'parts' => fn() => many($p, 'SELECT pa.*, s.name AS supplier, (pa.reorder_level > 0 AND pa.quantity_on_hand <= pa.reorder_level) AS low_stock
                    FROM parts pa LEFT JOIN suppliers s ON s.id=pa.default_supplier_id ORDER BY pa.active DESC, pa.description'),
                'requests' => fn() => many($p, "SELECT pr.*, pa.part_number, pa.description, pa.unit, pa.quantity_on_hand, v.plate_number, m.job_number,
                        us.full_name AS requested_by_name
                    FROM part_requests pr JOIN parts pa ON pa.id=pr.part_id JOIN vehicles v ON v.id=pr.vehicle_id
                    LEFT JOIN maintenance_jobs m ON m.id=pr.maintenance_job_id JOIN users us ON us.id=pr.requested_by
                    ORDER BY FIELD(pr.status,'Requested','Not Available','Issued','Cancelled'), pr.id DESC LIMIT 400"),
                'movements' => fn() => hasRole($u, 'motorpool') ? [] : many($p, 'SELECT sm.*, pa.part_number, pa.description, us.full_name AS actor, v.plate_number
                    FROM stock_movements sm JOIN parts pa ON pa.id=sm.part_id JOIN users us ON us.id=sm.actor_id LEFT JOIN vehicles v ON v.id=sm.vehicle_id
                    ORDER BY sm.id DESC LIMIT 500'),
                'suppliers' => fn() => many($p, 'SELECT id, name FROM suppliers WHERE active=1 ORDER BY name'),
                'vehicles' => fn() => many($p, 'SELECT id, plate_number FROM vehicles WHERE active=1 ORDER BY plate_number'),
                'jobs' => fn() => many($p, "SELECT id, job_number, vehicle_id FROM maintenance_jobs WHERE status NOT IN ('Completed','Cancelled') ORDER BY id DESC"),
            ]);
            $data['can'] = ['manage' => hasRole($u, 'purchasing'), 'request' => hasRole($u, 'motorpool')];
            return $data;
        }
        $data = readSections([
            'requests' => fn() => many($p, "SELECT r.*, pa.part_number, pa.description, pa.unit, s.name AS supplier, us.full_name AS requested_by_name,
                    ud.full_name AS decided_by_name, po.po_number, po.status AS po_status
                FROM purchase_requests r JOIN parts pa ON pa.id=r.part_id LEFT JOIN suppliers s ON s.id=r.supplier_id
                JOIN users us ON us.id=r.requested_by LEFT JOIN users ud ON ud.id=r.decided_by LEFT JOIN purchase_orders po ON po.purchase_request_id=r.id
                ORDER BY FIELD(r.status,'Pending Approval','Approved','Ordered','Received','Rejected','Rejected - Insufficient Budget'), r.id DESC LIMIT 500"),
            'orders' => fn() => many($p, 'SELECT po.*, r.pr_number, r.quantity, r.amount, r.unit_cost, pa.part_number, pa.description, s.name AS supplier
                FROM purchase_orders po JOIN purchase_requests r ON r.id=po.purchase_request_id JOIN parts pa ON pa.id=r.part_id
                LEFT JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.id DESC LIMIT 500'),
            'suppliers' => fn() => many($p, 'SELECT * FROM suppliers ORDER BY active DESC, name'),
            'parts' => fn() => many($p, 'SELECT id, part_number, description, unit, unit_cost, default_supplier_id FROM parts WHERE active=1 ORDER BY description'),
            'notAvailable' => fn() => many($p, "SELECT pr.id, pr.requested_qty, pa.id AS part_id, pa.description, v.plate_number FROM part_requests pr JOIN parts pa ON pa.id=pr.part_id
                JOIN vehicles v ON v.id=pr.vehicle_id WHERE pr.status='Not Available' AND NOT EXISTS (SELECT 1 FROM purchase_requests x WHERE x.part_request_id=pr.id AND x.status NOT LIKE 'Rejected%')"),
            'budget' => fn() => one($p, "SELECT * FROM budgets WHERE category='Purchasing' AND status='Active' ORDER BY id DESC LIMIT 1") ?? [],
        ]);
        $data['threshold'] = Settings::value('purchase_approval_threshold');
        $data['can'] = ['manage' => hasRole($u, 'purchasing'), 'approve_finance' => hasRole($u, 'finance'), 'approve_admin' => hasRole($u, 'admin')];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        if ($action === 'decide') return self::decide($u, $d);
        requireRole($u, 'purchasing');
        return match ($action) {
            'supplier_save' => self::supplierSave($u, $d),
            'part_save' => self::partSave($u, $d),
            'adjust' => self::adjust($u, $d),
            'issue' => self::issue($u, $d),
            'not_available' => self::notAvailable($u, $d),
            'pr_create' => self::prCreate($u, $d),
            'po_create' => self::poCreate($u, $d),
            'receive' => self::receive($u, $d),
            default => throw new BusinessRuleException('Unknown purchasing action.', 404),
        };
    }

    private static function supplierSave(array $u, array $d): array {
        $id = optId($d, 'id');
        $row = ['name' => field($d, 'name', 150), 'contact_person' => optField($d, 'contact_person', 120), 'contact_number' => optField($d, 'contact_number', 60),
                'email' => optField($d, 'email', 120), 'address' => optField($d, 'address', 255), 'notes' => optField($d, 'notes', 255),
                'active' => empty($d['inactive']) ? 1 : 0];
        return transaction(function (PDO $p) use ($u, $id, $row) {
            must(!one($p, 'SELECT id FROM suppliers WHERE name=? AND id<>?', [$row['name'], $id ?? 0]), 'Supplier name already exists.', 409);
            if ($id) { run($p, 'UPDATE suppliers SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($row))) . ' WHERE id=?', array_merge(array_values($row), [$id])); }
            else { $id = insert($p, 'suppliers', $row); }
            audit($p, $u['id'], 'Purchasing', 'supplier ' . $id, 'supplier saved', $row['name']);
            return ['supplier_id' => $id];
        });
    }

    private static function partSave(array $u, array $d): array {
        $id = optId($d, 'id');
        $row = ['part_number' => field($d, 'part_number', 70), 'description' => field($d, 'description', 200), 'category' => optField($d, 'category', 80),
                'unit' => field($d, 'unit', 20), 'unit_cost' => moneyField($d, 'unit_cost', true), 'reorder_level' => intField($d, 'reorder_level', 0),
                'warranty_info' => optField($d, 'warranty_info', 150), 'default_supplier_id' => optId($d, 'default_supplier_id'),
                'active' => empty($d['inactive']) ? 1 : 0];
        return transaction(function (PDO $p) use ($u, $id, $row) {
            must(!one($p, 'SELECT id FROM parts WHERE part_number=? AND id<>?', [$row['part_number'], $id ?? 0]), 'Part number already exists.', 409);
            if ($id) { run($p, 'UPDATE parts SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($row))) . ' WHERE id=?', array_merge(array_values($row), [$id])); }
            else { $id = insert($p, 'parts', $row); } // new parts start with 0 stock; use Adjustment for the verified opening count
            audit($p, $u['id'], 'Inventory', 'part ' . $id, 'part saved', $row['part_number'] . ' ' . $row['description']);
            return ['part_id' => $id];
        });
    }

    /** Inventory adjustment (physical count, opening balance, damage). */
    private static function adjust(array $u, array $d): array {
        $part = idField($d, 'part_id');
        $qty = filter_var($d['quantity'] ?? null, FILTER_VALIDATE_INT);
        must($qty !== false && $qty !== 0, 'Enter a quantity to add (positive) or remove (negative).');
        $reason = field($d, 'reason', 150);
        return transaction(function (PDO $p) use ($u, $part, $qty, $reason) {
            $pa = one($p, 'SELECT * FROM parts WHERE id=? FOR UPDATE', [$part]);
            must((bool)$pa, 'Part not found.', 404);
            must((int)$pa['quantity_on_hand'] + $qty >= 0, 'Stock cannot go below zero.', 409);
            run($p, 'UPDATE parts SET quantity_on_hand=quantity_on_hand+? WHERE id=?', [$qty, $part]);
            insert($p, 'stock_movements', ['part_id' => $part, 'movement_type' => 'Adjustment', 'quantity_delta' => $qty, 'unit_cost' => $pa['unit_cost'],
                'reason' => $reason, 'actor_id' => $u['id']]);
            audit($p, $u['id'], 'Inventory', $pa['part_number'], 'inventory adjusted', "$qty ($reason)");
            return ['part_id' => $part];
        });
    }

    /** Release parts to Motorpool (stock-out). */
    private static function issue(array $u, array $d): array {
        $id = idField($d, 'request_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $r = one($p, 'SELECT * FROM part_requests WHERE id=? FOR UPDATE', [$id]);
            must($r && in_array($r['status'], ['Requested', 'Not Available'], true), 'Request is not pending.', 409);
            $pa = one($p, 'SELECT * FROM parts WHERE id=? FOR UPDATE', [$r['part_id']]);
            must((int)$pa['quantity_on_hand'] >= (int)$r['requested_qty'], 'Not enough stock (' . $pa['quantity_on_hand'] . ' on hand). Mark it Not Available and create a purchase request.', 409);
            run($p, 'UPDATE parts SET quantity_on_hand=quantity_on_hand-? WHERE id=?', [$r['requested_qty'], $r['part_id']]);
            run($p, "UPDATE part_requests SET status='Issued', issued_by=?, issued_at=NOW() WHERE id=?", [$u['id'], $id]);
            insert($p, 'stock_movements', ['part_id' => $r['part_id'], 'movement_type' => 'Stock Out', 'quantity_delta' => -(int)$r['requested_qty'],
                'unit_cost' => $pa['unit_cost'], 'reason' => 'Issued to Motorpool', 'reference_type' => 'part_request', 'reference_id' => $id,
                'vehicle_id' => $r['vehicle_id'], 'actor_id' => $u['id']]);
            audit($p, $u['id'], 'Inventory', 'part request ' . $id, 'parts issued', $pa['part_number'] . ' x' . $r['requested_qty']);
            Notify::user($p, (int)$r['requested_by'], 'Parts issued', $r['requested_qty'] . ' ' . $pa['unit'] . ' of ' . $pa['description'] . ' released.', 'maintenance');
            if ($r['maintenance_job_id']) run($p, "UPDATE maintenance_jobs SET status='In Progress' WHERE id=? AND status='Waiting for Parts'", [$r['maintenance_job_id']]);
            return ['request_id' => $id, 'message' => 'Parts released and stock updated.'];
        });
    }

    private static function notAvailable(array $u, array $d): array {
        $id = idField($d, 'request_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $r = one($p, 'SELECT pr.*, pa.description FROM part_requests pr JOIN parts pa ON pa.id=pr.part_id WHERE pr.id=? FOR UPDATE', [$id]);
            must($r && $r['status'] === 'Requested', 'Request is not pending.', 409);
            run($p, "UPDATE part_requests SET status='Not Available' WHERE id=?", [$id]);
            audit($p, $u['id'], 'Inventory', 'part request ' . $id, 'marked not available');
            Notify::user($p, (int)$r['requested_by'], 'Parts not available', $r['description'] . ' is out of stock. Purchasing will request a purchase.', 'maintenance');
            return ['request_id' => $id, 'message' => 'Marked Not Available. Create a purchase request in Purchasing.'];
        });
    }

    /** Purchase request; approver depends on the PHP limit (PDF p.17). */
    private static function prCreate(array $u, array $d): array {
        $part = idField($d, 'part_id'); $qty = intField($d, 'quantity', 1); $cost = moneyField($d, 'unit_cost');
        $supplier = optId($d, 'supplier_id'); $reason = optField($d, 'reason', 255); $link = optId($d, 'part_request_id');
        return transaction(function (PDO $p) use ($u, $part, $qty, $cost, $supplier, $reason, $link) {
            $pa = one($p, 'SELECT * FROM parts WHERE id=?', [$part]);
            must((bool)$pa, 'Part not found.');
            $amount = round($qty * (float)$cost, 2);
            $approver = $amount > Settings::value('purchase_approval_threshold') ? 'admin' : 'finance';
            $id = insert($p, 'purchase_requests', ['part_id' => $part, 'part_request_id' => $link, 'supplier_id' => $supplier, 'quantity' => $qty,
                'unit_cost' => $cost, 'amount' => number_format($amount, 2, '.', ''), 'reason' => $reason, 'approver_role' => $approver, 'requested_by' => $u['id']]);
            run($p, 'UPDATE purchase_requests SET pr_number=? WHERE id=?', [refNumber('PR', $id), $id]);
            audit($p, $u['id'], 'Purchasing', refNumber('PR', $id), 'purchase request created', $pa['description'] . ' x' . $qty . ' = PHP ' . number_format($amount, 2) . ' -> ' . ROLE_LABELS[$approver]);
            Notify::roles($p, [$approver], 'Purchase approval', refNumber('PR', $id) . ': ' . $pa['description'] . ' PHP ' . number_format($amount, 2) . ' needs approval.', 'purchasing');
            return ['purchase_id' => $id, 'message' => 'Purchase request sent to ' . ROLE_LABELS[$approver] . '.'];
        });
    }

    /** Admin (> limit) or Finance (<= limit) decides; then the budget is checked. */
    private static function decide(array $u, array $d): array {
        requireRole($u, 'admin', 'finance');
        $id = idField($d, 'purchase_id');
        $decision = choiceField($d, 'decision', ['approve', 'reject']);
        $notes = optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $id, $decision, $notes) {
            $r = one($p, 'SELECT * FROM purchase_requests WHERE id=? FOR UPDATE', [$id]);
            must($r && $r['status'] === 'Pending Approval', 'Request is not pending.', 409);
            must($u['role'] === $r['approver_role'], 'This amount must be approved by ' . ROLE_LABELS[$r['approver_role']] . '.', 403);
            if ($decision === 'reject') {
                must($notes !== null, 'Please give a reason.');
                run($p, "UPDATE purchase_requests SET status='Rejected', decided_by=?, decided_at=NOW(), decision_notes=? WHERE id=?", [$u['id'], $notes, $id]);
                audit($p, $u['id'], 'Purchasing', $r['pr_number'], 'purchase request rejected', $notes);
                Notify::user($p, (int)$r['requested_by'], 'Purchase rejected', $r['pr_number'] . ' was rejected: ' . $notes, 'purchasing');
                return ['purchase_id' => $id, 'result' => 'Rejected', 'message' => 'Rejected.'];
            }
            // Budget check (PDF p.17). TODO C05: budget period / allocation rules are not in the PDF.
            $b = one($p, "SELECT * FROM budgets WHERE category='Purchasing' AND status='Active' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            must((bool)$b, 'Finance has not set an active Purchasing budget yet. Set it in Finance > Budgets first.', 409);
            $remaining = round((float)$b['budget_amount'] - (float)$b['used_amount'], 2);
            if ($remaining < (float)$r['amount']) {
                run($p, "UPDATE purchase_requests SET status='Rejected - Insufficient Budget', decided_by=?, decided_at=NOW(), decision_notes=?, budget_id=? WHERE id=?",
                    [$u['id'], 'System: remaining budget PHP ' . number_format($remaining, 2), $b['id'], $id]);
                audit($p, null, 'Purchasing', $r['pr_number'], 'rejected by system: insufficient budget', 'Amount ' . $r['amount'] . ', remaining ' . $remaining);
                Notify::user($p, (int)$r['requested_by'], 'Purchase rejected', $r['pr_number'] . ' was rejected by the system: insufficient budget.', 'purchasing');
                return ['purchase_id' => $id, 'result' => 'Rejected - Insufficient Budget', 'warning' => 'Insufficient budget: remaining PHP ' . number_format($remaining, 2) . '. The system rejected the request.'];
            }
            run($p, "UPDATE purchase_requests SET status='Approved', decided_by=?, decided_at=NOW(), decision_notes=?, budget_id=? WHERE id=?", [$u['id'], $notes, $b['id'], $id]);
            run($p, 'UPDATE budgets SET used_amount=used_amount+? WHERE id=?', [$r['amount'], $b['id']]);
            audit($p, $u['id'], 'Purchasing', $r['pr_number'], 'purchase request approved', 'Budget remaining after: ' . number_format($remaining - (float)$r['amount'], 2));
            Notify::user($p, (int)$r['requested_by'], 'Purchase approved', $r['pr_number'] . ' was approved. You can now order the parts.', 'purchasing');
            return ['purchase_id' => $id, 'result' => 'Approved', 'message' => 'Approved. Budget remaining: PHP ' . number_format($remaining - (float)$r['amount'], 2) . '.'];
        });
    }

    private static function poCreate(array $u, array $d): array {
        $id = idField($d, 'purchase_id'); $supplier = optId($d, 'supplier_id');
        $ordered = dateField($d, 'ordered_on'); $expected = dateField($d, 'expected_on', false);
        return transaction(function (PDO $p) use ($u, $id, $supplier, $ordered, $expected) {
            $r = one($p, 'SELECT * FROM purchase_requests WHERE id=? FOR UPDATE', [$id]);
            must($r && $r['status'] === 'Approved', 'Only approved requests can be ordered.', 409);
            $supplier = $supplier ?? ($r['supplier_id'] ? (int)$r['supplier_id'] : null);
            must($supplier !== null, 'Choose the supplier.');
            $po = insert($p, 'purchase_orders', ['po_number' => 'TMP-' . bin2hex(random_bytes(5)), 'purchase_request_id' => $id, 'supplier_id' => $supplier,
                'ordered_on' => $ordered, 'expected_on' => $expected, 'entered_by' => $u['id']]);
            run($p, 'UPDATE purchase_orders SET po_number=? WHERE id=?', [refNumber('PO', $po), $po]);
            run($p, "UPDATE purchase_requests SET status='Ordered', supplier_id=? WHERE id=?", [$supplier, $id]);
            audit($p, $u['id'], 'Purchasing', refNumber('PO', $po), 'purchase order created', $r['pr_number']);
            return ['order_id' => $po, 'message' => 'Purchase order ' . refNumber('PO', $po) . ' created.'];
        });
    }

    /** Stock-in from a purchase order: updates stock, creates payable and parts expense. */
    private static function receive(array $u, array $d): array {
        $id = idField($d, 'order_id'); $qty = intField($d, 'quantity', 1);
        $date = dateField($d, 'received_on'); $ref = optField($d, 'receipt_reference', 100); $warranty = dateField($d, 'warranty_until', false);
        return transaction(function (PDO $p) use ($u, $id, $qty, $date, $ref, $warranty) {
            $po = one($p, 'SELECT po.*, r.part_id, r.quantity, r.unit_cost, r.amount, r.part_request_id, r.pr_number, s.name AS supplier
                FROM purchase_orders po JOIN purchase_requests r ON r.id=po.purchase_request_id LEFT JOIN suppliers s ON s.id=po.supplier_id WHERE po.id=? FOR UPDATE', [$id]);
            must($po && $po['status'] === 'Ordered', 'Order is not waiting for delivery.', 409);
            // TODO PENDING COMPANY CONFIRMATION (C11): partial deliveries and returns.
            must($qty === (int)$po['quantity'], 'Received quantity must match the ordered quantity (' . $po['quantity'] . '). Partial deliveries need company rules.');
            $pa = one($p, 'SELECT * FROM parts WHERE id=? FOR UPDATE', [$po['part_id']]);
            run($p, 'UPDATE parts SET quantity_on_hand=quantity_on_hand+?, unit_cost=? WHERE id=?', [$qty, $po['unit_cost'], $po['part_id']]);
            run($p, "UPDATE purchase_orders SET status='Received', received_on=?, receipt_reference=?, warranty_until=? WHERE id=?", [$date, $ref, $warranty, $id]);
            run($p, "UPDATE purchase_requests SET status='Received' WHERE id=?", [$po['purchase_request_id']]);
            insert($p, 'stock_movements', ['part_id' => $po['part_id'], 'movement_type' => 'Stock In', 'quantity_delta' => $qty, 'unit_cost' => $po['unit_cost'],
                'reason' => 'Purchase order received', 'reference_type' => 'purchase_order', 'reference_id' => $id, 'actor_id' => $u['id']]);
            // Finance side: amount owed to supplier and the parts expense.
            insert($p, 'accounts_payable', ['supplier_id' => $po['supplier_id'], 'reference_number' => $po['po_number'] . ($ref ? ' / ' . $ref : ''),
                'description' => 'Parts: ' . $pa['description'] . ' x' . $qty, 'amount' => $po['amount'], 'due_on' => null,
                'source' => 'Purchase Order', 'source_id' => $id, 'recorded_by' => $u['id']]);
            insert($p, 'expenses', ['category' => 'Parts', 'amount' => $po['amount'], 'incurred_on' => $date, 'payee' => $po['supplier'],
                'reference_number' => $po['po_number'], 'description' => 'Parts purchase ' . $po['pr_number'] . ': ' . $pa['description'] . ' x' . $qty,
                'source' => 'Parts Receipt', 'source_id' => $id, 'recorded_by' => $u['id']]);
            if ($po['part_request_id']) {
                run($p, "UPDATE part_requests SET status='Requested' WHERE id=? AND status='Not Available'", [$po['part_request_id']]);
            }
            audit($p, $u['id'], 'Inventory', $po['po_number'], 'stock received', $pa['part_number'] . ' +' . $qty);
            Notify::roles($p, ['finance'], 'Payable', 'Parts received on ' . $po['po_number'] . ' (PHP ' . number_format((float)$po['amount'], 2) . ') is now in Accounts Payable.', 'finance');
            return ['order_id' => $id, 'message' => 'Stock received. Payable and parts expense recorded for Finance.'];
        });
    }
}
