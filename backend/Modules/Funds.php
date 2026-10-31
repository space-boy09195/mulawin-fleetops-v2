<?php
/*
 * =====================================================================
 *  backend/Modules/Funds.php  —  TRIP FUND REQUESTS
 * =====================================================================
 *  Bagong FMS PDF (Scenario A steps 3-5, Scenario B steps 4-6):
 *    1. Dispatcher -> Fund Request: pumipili ng preset categories at
 *       pwedeng magdagdag. System ang nagko-compute ng total.
 *       Status: Pending Approval.
 *    2. Operations Head -> Fund Approval (lahat ng halaga, walang limit):
 *       Approve -> "Ready for Release" at lalabas sa Finance.
 *       Reject  -> babalik sa Dispatcher para i-revise at i-resubmit.
 *    3. Finance -> Confirm Release (pwede kahit hindi pa tapos ang
 *       Pre-Dispatch Checklist) -> "Released".
 *    4. Dispatcher Confirm Dispatch -> "Completed".
 *    5. Finance liquidation (actual na nagastos / ibinalik na pera).
 *       Galing ito sa dating system at dating PDF (Finance dashboard).
 * =====================================================================
 */
declare(strict_types=1);
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
                    t.distance_km, c.name AS client, v.plate_number, ua.full_name AS approved_by_name, rq.full_name AS requested_by_name,
                    ur.full_name AS released_by_name
                FROM fund_requests f JOIN trips t ON t.id=f.trip_id JOIN clients c ON c.id=t.client_id
                LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN users ua ON ua.id=f.approved_by
                LEFT JOIN users rq ON rq.id=f.requested_by LEFT JOIN users ur ON ur.id=f.released_by
                WHERE 1=1 $where ORDER BY FIELD(f.status,'Pending Approval','Ready for Release','Released','Rejected','Completed','Cancelled'), f.id DESC LIMIT 500", $args),
        ]);
        $data['fundCategories'] = TripFunds::categories();
        $data['can'] = [
            'request' => roleIsDispatcher($u['role']),
            'approve' => hasRole($u, 'operations_head'),
            'release' => hasRole($u, 'finance'), 'liquidate' => hasRole($u, 'finance'),
        ];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'request' => self::request($u, $d),
            'revise' => self::revise($u, $d),
            'approve' => self::approve($u, $d),
            'reject' => self::reject($u, $d),
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

    /** Trip statuses na pwedeng lagyan o i-revise ang fund request (bago umalis ang truck). */
    private const FUND_TRIP_STATUSES = ['Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'];

    /** Dispatcher ng trip lang ang gagawa ng fund request (bagong PDF). */
    private static function mustBeTripDispatcher(array $u, string $dispatcherRole): void {
        must(roleIsDispatcher($u['role']), 'Only the dispatcher can submit the fund request.', 403);
        must($dispatcherRole === $u['role'], 'This trip belongs to the other dispatcher team.', 403);
    }

    /** Dispatcher -> Submit Fund Request (Scenario A step 3 / B step 4). */
    private static function request(array $u, array $d): array {
        $tripId = idField($d, 'trip_id');
        $calc = TripFunds::buildLines($d);
        $notes = optField($d, 'notes', 255) ?? '';
        return transaction(function (PDO $p) use ($u, $tripId, $calc, $notes) {
            $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$tripId]);
            must((bool)$t, 'Trip not found.', 404);
            self::mustBeTripDispatcher($u, (string)$t['dispatcher_role']);
            must(in_array($t['status'], self::FUND_TRIP_STATUSES, true), 'A fund request is made for a Pre-Assigned trip (before dispatch).', 409);
            $open = one($p, "SELECT status FROM fund_requests WHERE trip_id=? AND status IN ('Pending Approval','Ready for Release','Released','Rejected') AND attempt_number=? ORDER BY id DESC LIMIT 1", [$tripId, $t['attempt_number']]);
            must(!$open, $open && $open['status'] === 'Rejected' ? 'This trip has a rejected request. Use "Revise and resubmit".' : 'This trip already has a fund request (' . ($open['status'] ?? '') . ').', 409);
            $new = TripFunds::create($p, $t, $u, $calc, $notes);
            return ['fund_id' => $new['id'], 'message' => 'Fund request PHP ' . number_format($new['amount'], 2) . ' submitted to the Operations Head.'];
        });
    }

    /** Dispatcher -> revision at resubmission ng na-reject na request (parehong record). */
    private static function revise(array $u, array $d): array {
        $id = idField($d, 'fund_id');
        $calc = TripFunds::buildLines($d);
        $notes = optField($d, 'notes', 255) ?? '';
        return transaction(function (PDO $p) use ($u, $id, $calc, $notes) {
            $f = self::lockFund($p, $id);
            self::mustBeTripDispatcher($u, (string)$f['dispatcher_role']);
            must($f['status'] === 'Rejected', 'Only a rejected request can be revised and resubmitted.', 409);
            must(in_array($f['trip_status'], self::FUND_TRIP_STATUSES, true), 'The trip is no longer waiting for a fund.', 409);
            run($p, "UPDATE fund_requests SET amount=?, breakdown_json=?, status='Pending Approval', approved_by=NULL, approved_at=NULL WHERE id=?",
                [number_format($calc['total'], 2, '.', ''), TripFunds::breakdownJson($calc, $notes), $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund request revised and resubmitted', $f['trip_ref'] . ': PHP ' . $f['amount'] . ' -> ' . number_format($calc['total'], 2));
            Notify::roles($p, ['operations_head'], 'Fund approval', 'Revised fund request PHP ' . number_format($calc['total'], 2) . ' for ' . $f['trip_ref'] . ' needs your approval.', 'funds');
            return ['fund_id' => $id, 'message' => 'Revised request PHP ' . number_format($calc['total'], 2) . ' resubmitted.'];
        });
    }

    /** Operations Head -> Approve: diretso "Ready for Release" at lalabas sa Finance. */
    private static function approve(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'fund_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $f = self::lockFund($p, $id);
            must($f['status'] === 'Pending Approval', 'This request is not waiting for approval.', 409);
            must((float)$f['amount'] > 0, 'Amount is PHP 0.00.', 409);
            run($p, "UPDATE fund_requests SET status='Ready for Release', approved_by=?, approved_at=NOW() WHERE id=?", [$u['id'], $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund request approved (Ready for Release)', $f['trip_ref'] . ' PHP ' . number_format((float)$f['amount'], 2));
            Notify::roles($p, ['finance'], 'Fund release', 'Fund for ' . $f['trip_ref'] . ' (PHP ' . number_format((float)$f['amount'], 2) . ') is Ready for Release.', 'funds');
            Notify::roles($p, [$f['dispatcher_role']], 'Fund approved', 'Fund for ' . $f['trip_ref'] . ' was approved and is Ready for Release.', 'funds');
            return ['fund_id' => $id, 'message' => 'Fund approved. Status: Ready for Release (sent to Finance).'];
        });
    }

    /** Operations Head -> Reject: babalik sa Dispatcher para i-revise at i-resubmit. */
    private static function reject(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'fund_id'); $reason = field($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $f = self::lockFund($p, $id);
            must($f['status'] === 'Pending Approval', 'Only a request waiting for approval can be rejected.', 409);
            run($p, "UPDATE fund_requests SET status='Rejected', rejected_reason=? WHERE id=?", [$reason, $id]);
            audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund request rejected', $f['trip_ref'] . ': ' . $reason);
            Notify::roles($p, [$f['dispatcher_role']], 'Fund rejected', 'Fund for ' . $f['trip_ref'] . ' was rejected: ' . $reason . '. Revise and resubmit it in Trip Funds.', 'funds');
            return ['fund_id' => $id, 'message' => 'Fund request rejected and returned to the dispatcher.'];
        });
    }

    /**
     * Finance -> Confirm Release (bagong PDF Scenario A step 5 / B step 6).
     * Pwede kahit hindi pa tapos ang Pre-Dispatch Checklist.
     * Itinatala rin ang payment details, voucher at expense (dating PDF II.I).
     */
    private static function release(array $u, array $d): array {
        requireRole($u, 'finance');
        $id = idField($d, 'fund_id');
        $pay = Money::paymentFields($d);
        return transaction(function (PDO $p) use ($u, $id, $pay) {
            $f = self::lockFund($p, $id);
            must($f['status'] === 'Ready for Release', 'The fund is not Ready for Release yet (Operations Head approval first).', 409);
            must(in_array($f['trip_status'], self::FUND_TRIP_STATUSES, true), 'The trip is no longer waiting for this fund (' . $f['trip_status'] . ').', 409);
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
            Notify::roles($p, [$f['dispatcher_role']], 'Fund released', 'Fund for ' . $f['trip_ref'] . ' is Released. Confirm Dispatch is enabled once the trip is Ready for Dispatch.', 'trips');
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
