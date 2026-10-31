<?php
/*
 * =====================================================================
 *  backend/Modules/Funds.php  —  TRIP FUND / ALLOWANCE REQUESTS
 * =====================================================================
 *  FMS PDF II.I "Suggested fund request process" and pp.13, 16-17:
 *    1. System computes the allowance and creates the request.
 *       (Dispatcher / Ops Head may add or adjust lines with a reason.)
 *    2. Approval: Ops Head if amount <= limit, Admin if above.
 *    3. Finance processes ("approve pero pending pa") -> Pending Release.
 *    4. Dispatcher checklist done -> Ready for Release.
 *    5. Finance releases and records payment details -> Released.
 *    6. Dispatcher confirms dispatch -> Completed.
 *    7. Finance records liquidation (actual spent / cash returned).
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('Notify');
core('TripFunds');
core('Money');

final class Funds {
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $where = ''; $args = [];
        if (roleIsDispatcher($u['role'])) { $where = ' AND (t.dispatcher_role=? OR t.created_by=?)'; $args = [$u['role'], $u['id']]; }
        $data = readSections([
            'funds' => fn() => many($p, "SELECT f.*, t.trip_ref, t.status AS trip_status, t.truck_type, t.origin, t.destination, t.scheduled_at, t.dispatcher_role,
                    t.distance_km, c.name AS client, v.plate_number, ua.full_name AS approved_by_name, up.full_name AS processed_by_name,
                    ur.full_name AS released_by_name
                FROM fund_requests f JOIN trips t ON t.id=f.trip_id JOIN clients c ON c.id=t.client_id
                LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN users ua ON ua.id=f.approved_by
                LEFT JOIN users up ON up.id=f.processed_by LEFT JOIN users ur ON ur.id=f.released_by
                WHERE 1=1 $where ORDER BY FIELD(f.status,'Pending Approval','Approved','Ready for Release','Pending Release','Released','Completed','Rejected','Cancelled'), f.id DESC LIMIT 500", $args),
        ]);
        $data['threshold'] = Settings::value('fund_approval_threshold');
        $data['can'] = [
            'approve_ops' => hasRole($u, 'operations_head'), 'approve_admin' => hasRole($u, 'admin'),
            'process' => hasRole($u, 'finance'), 'release' => hasRole($u, 'finance'), 'liquidate' => hasRole($u, 'finance'),
            'adjust' => hasRole($u, 'operations_head') || roleIsDispatcher($u['role']),
        ];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'approve' => self::approve($u, $d),
            'reject' => self::reject($u, $d),
            'process' => self::process($u, $d),
            'adjust' => self::adjust($u, $d),
            'resubmit' => self::resubmit($u, $d),
            'release' => self::release($u, $d),
            'liquidate' => self::liquidate($u, $d),
            default => throw new BusinessRuleException('Unknown fund action.', 404),
        };
    }

    private static function lockFund(PDO $p, int $id): array {
        $f = one($p, 'SELECT f.*, t.trip_ref, t.status AS trip_status, t.dispatcher_role, t.id AS trip_id FROM fund_requests f JOIN trips t ON t.id=f.trip_id WHERE f.id=? FOR UPDATE', [$id]);
        must((bool)$f, 'Fund request not found.', 404);
        return $f;
    }

    /** Ops Head (<= limit) or Admin (> limit) approves (PDF p.13). */
    private static function approve(array $u, array $d): array {
        $id = idField($d, 'fund_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $f = self::lockFund($p, $id);
            must($f['status'] === 'Pending Approval', 'This request is not waiting for approval.', 409);
            must($u['role'] === $f['approver_role'], 'This amount must be approved by ' . ROLE_LABELS[$f['approver_role']] . ' (limit PHP ' . number_format(Settings::value('fund_approval_threshold'), 2) . ').', 403);
            must((float)$f['amount'] > 0, 'Amount is PHP 0.00. Adjust the amount first.', 409);
            run($p, "UPDATE fund_requests SET status='Approved', approved_by=?, approved_at=NOW() WHERE id=?", [$u['id'], $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund request approved', $f['trip_ref'] . ' PHP ' . number_format((float)$f['amount'], 2));
            Notify::roles($p, ['finance'], 'Fund to process', 'Fund for ' . $f['trip_ref'] . ' (PHP ' . number_format((float)$f['amount'], 2) . ') was approved. Please process it.', 'funds');
            return ['fund_id' => $id, 'message' => 'Fund request approved and sent to Finance.'];
        });
    }

    private static function reject(array $u, array $d): array {
        $id = idField($d, 'fund_id'); $reason = field($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $f = self::lockFund($p, $id);
            $ok = ($f['status'] === 'Pending Approval' && $u['role'] === $f['approver_role'])
               || (in_array($f['status'], ['Approved', 'Pending Release', 'Ready for Release'], true) && hasRole($u, 'finance'));
            must($ok, 'You cannot reject this request at its current step.', 403);
            run($p, "UPDATE fund_requests SET status='Rejected', rejected_reason=? WHERE id=?", [$reason, $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund request rejected', $f['trip_ref'] . ': ' . $reason);
            Notify::roles($p, [$f['dispatcher_role'], 'operations_head'], 'Fund rejected', 'Fund for ' . $f['trip_ref'] . ' was rejected: ' . $reason . '. Adjust and re-submit in Trip Funds.', 'funds');
            return ['fund_id' => $id, 'message' => 'Fund request rejected.'];
        });
    }

    /** Finance processes: "iprocess ni finance then approve pero pending pa" (PDF p.16). */
    private static function process(array $u, array $d): array {
        requireRole($u, 'finance');
        $id = idField($d, 'fund_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $f = self::lockFund($p, $id);
            must($f['status'] === 'Approved', 'Only approved requests can be processed.', 409);
            // If the dispatcher already finished the checklist, go straight to Ready for Release.
            $next = $f['trip_status'] === 'Ready for Dispatch' ? 'Ready for Release' : 'Pending Release';
            run($p, 'UPDATE fund_requests SET status=?, processed_by=?, processed_at=NOW() WHERE id=?', [$next, $u['id'], $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund processed by Finance', $f['trip_ref'] . ' -> ' . $next);
            return ['fund_id' => $id, 'message' => 'Processed. Status: ' . $next . '.'];
        });
    }

    /**
     * Adjust a pending request: add extra lines (e.g. a toll not in the route)
     * with a reason. Approver is recomputed from the new total.
     */
    private static function adjust(array $u, array $d): array {
        must(hasRole($u, 'operations_head') || roleIsDispatcher($u['role']), 'Not allowed.', 403);
        $id = idField($d, 'fund_id');
        $reason = field($d, 'reason', 255);
        $lines = $d['lines'] ?? [];
        must(is_array($lines) && count($lines) <= 15, 'Invalid adjustment lines.');
        $clean = [];
        foreach ($lines as $l) {
            if (!is_array($l)) continue;
            $name = field($l, 'name', 80, false);
            if ($name === '') continue;
            $amt = trim((string)($l['amount'] ?? ''));
            must(is_numeric($amt), 'Each adjustment line needs an amount (negative to reduce).');
            $clean[] = ['name' => $name, 'calc' => 'adjustment', 'amount' => round((float)$amt, 2), 'explain' => 'added by ' . $u['full_name']];
        }
        return transaction(function (PDO $p) use ($u, $id, $reason, $clean) {
            $f = self::lockFund($p, $id);
            if (roleIsDispatcher($u['role'])) must($f['dispatcher_role'] === $u['role'], 'This trip belongs to the other dispatcher team.', 403);
            must($f['status'] === 'Pending Approval', 'Only a request waiting for approval can be adjusted.', 409);
            $b = json_decode((string)$f['breakdown_json'], true) ?: [];
            $system = array_values(array_filter($b['lines'] ?? [], fn($l) => ($l['calc'] ?? '') !== 'adjustment'));
            $all = array_merge($system, $clean);
            $total = round(array_sum(array_column($all, 'amount')), 2);
            must($total >= 0, 'Total cannot be negative.');
            $b['lines'] = $all; $b['adjust_reason'] = $reason;
            $approver = $total > Settings::value('fund_approval_threshold') ? 'admin' : 'operations_head';
            run($p, 'UPDATE fund_requests SET amount=?, approver_role=?, breakdown_json=? WHERE id=?',
                [number_format($total, 2, '.', ''), $approver, json_encode($b, JSON_UNESCAPED_UNICODE), $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund request adjusted', $f['trip_ref'] . ': PHP ' . $f['amount'] . ' -> ' . number_format($total, 2) . " ($reason)");
            Notify::roles($p, [$approver], 'Fund approval', 'Fund for ' . $f['trip_ref'] . ' was adjusted to PHP ' . number_format($total, 2) . ' and needs your approval.', 'funds');
            return ['fund_id' => $id, 'message' => 'Amount updated to PHP ' . number_format($total, 2) . ' (approver: ' . ROLE_LABELS[$approver] . ').'];
        });
    }

    /** After a rejection, compute a new request for the same trip. */
    private static function resubmit(array $u, array $d): array {
        must(hasRole($u, 'operations_head') || roleIsDispatcher($u['role']), 'Not allowed.', 403);
        $id = idField($d, 'fund_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $f = self::lockFund($p, $id);
            if (roleIsDispatcher($u['role'])) must($f['dispatcher_role'] === $u['role'], 'This trip belongs to the other dispatcher team.', 403);
            must($f['status'] === 'Rejected', 'Only a rejected request can be re-submitted.', 409);
            $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$f['trip_id']]);
            must(in_array($t['status'], ['Assigned', 'Ready for Dispatch'], true), 'The trip is no longer waiting for a fund.', 409);
            must(!one($p, "SELECT id FROM fund_requests WHERE trip_id=? AND status NOT IN ('Rejected','Cancelled')", [$t['id']]), 'There is already an active request.', 409);
            $new = TripFunds::create($p, $t);
            audit($p, $u['id'], 'Finance', 'fund ' . $new['id'], 'fund request re-submitted', $t['trip_ref']);
            return ['fund_id' => $new['id'], 'message' => 'New request PHP ' . number_format($new['amount'], 2) . ' created. Adjust it if needed.'];
        });
    }

    /** Finance releases the money and records the payment details (PDF II.I step 48). */
    private static function release(array $u, array $d): array {
        requireRole($u, 'finance');
        $id = idField($d, 'fund_id');
        $pay = Money::paymentFields($d);
        return transaction(function (PDO $p) use ($u, $id, $pay) {
            $f = self::lockFund($p, $id);
            must($f['status'] === 'Ready for Release', 'The fund is not Ready for Release yet (dispatcher checklist first).', 409);
            must($f['trip_status'] === 'Ready for Dispatch', 'Trip is no longer Ready for Dispatch.', 409);
            run($p, "UPDATE fund_requests SET status='Released', released_by=?, released_at=NOW(), release_method=?, release_reference=? WHERE id=?",
                [$u['id'], $pay['payment_method'], $pay['reference_number'] ?? $pay['cheque_number'], $id]);
            $vno = Money::voucher($p, $u, 'fund_request', $id, 'Trip allowance ' . $f['trip_ref'], (string)$f['amount'], 'Paid');
            insert($p, 'disbursements', ['source_type' => 'fund_request', 'source_id' => $id, 'voucher_id' => $vno, 'payee' => 'Trip allowance ' . $f['trip_ref'],
                'amount' => $f['amount'], 'recorded_by' => $u['id']] + $pay);
            $t = one($p, 'SELECT vehicle_id FROM trips WHERE id=?', [$f['trip_id']]);
            insert($p, 'expenses', ['category' => 'Trip Allowance', 'trip_id' => $f['trip_id'], 'vehicle_id' => $t['vehicle_id'], 'amount' => $f['amount'],
                'incurred_on' => $pay['released_on'], 'payee' => 'Trip crew', 'reference_number' => $pay['reference_number'] ?? $pay['cheque_number'],
                'description' => 'Trip allowance released for ' . $f['trip_ref'], 'source' => 'Fund Release', 'source_id' => $id, 'recorded_by' => $u['id']]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund released', $f['trip_ref'] . ' PHP ' . $f['amount'] . ' via ' . $pay['payment_method']);
            Notify::roles($p, [$f['dispatcher_role']], 'Fund released', 'Fund for ' . $f['trip_ref'] . ' was released. You can confirm dispatch once cleared.', 'trips');
            return ['fund_id' => $id, 'message' => 'Fund released and disbursement recorded.'];
        });
    }

    /** Cash advance liquidation status (PDF Finance dashboard). */
    private static function liquidate(array $u, array $d): array {
        requireRole($u, 'finance');
        $id = idField($d, 'fund_id');
        $spent = moneyField($d, 'liquidated_amount', true);
        $notes = optField($d, 'liquidation_notes', 300);
        return transaction(function (PDO $p) use ($u, $id, $spent, $notes) {
            $f = self::lockFund($p, $id);
            must(in_array($f['status'], ['Released', 'Completed'], true), 'Only released funds can be liquidated.', 409);
            must($f['liquidation_status'] !== 'Liquidated', 'Already liquidated.', 409);
            must((float)$spent <= (float)$f['amount'], 'Actual spent cannot be more than the released amount. Record extra spending as an expense.');
            $returned = round((float)$f['amount'] - (float)$spent, 2);
            run($p, "UPDATE fund_requests SET liquidation_status='Liquidated', liquidated_amount=?, returned_amount=?, liquidation_notes=?, liquidated_by=?, liquidated_at=NOW() WHERE id=?",
                [$spent, $returned, $notes, $u['id'], $id]);
            // The expense now shows the actual amount spent.
            run($p, "UPDATE expenses SET amount=?, description=CONCAT(description, ' (liquidated; returned PHP ', ?, ')') WHERE source='Fund Release' AND source_id=?", [$spent, number_format($returned, 2, '.', ''), $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund liquidated', $f['trip_ref'] . ": spent $spent, returned $returned");
            return ['fund_id' => $id, 'message' => 'Liquidation recorded. Returned cash: PHP ' . number_format($returned, 2) . '.'];
        });
    }
}
