<?php
/*
 * backend/Core/TripFunds.php  —  automatic trip fund / allowance request
 * Shared by the Trips module (after auto-assignment) and the Funds module
 * (re-submit after a rejection), so the formula lives in one place.
 */
declare(strict_types=1);
core('Settings');
core('Notify');

final class TripFunds {
    /**
     * FUND REQUEST (PDF p.16-17 "cacalculate ni system yung need na fund /
     * allowance" and "automatic na magrereflect yung fund request kay finance").
     * Amount = trip allowance list in Formula Settings (default: fuel + RFID).
     * Approver: Ops Head if amount <= limit, Admin if above (PDF p.13).
     */
    public static function create(PDO $p, array $t): array {
        $route = $t['route_id'] ? one($p, 'SELECT toll_fee FROM routes WHERE id=?', [$t['route_id']]) : null;
        $ctx = ['km' => (float)($t['distance_km'] ?? 0), 'toll' => (float)($route['toll_fee'] ?? 0), 'rate' => (float)($t['client_rate'] ?? 0)];
        $calc = Settings::computeItems('trip_allowance', $ctx);
        $amount = $calc['total'];
        $limit = Settings::value('fund_approval_threshold');
        $approver = $amount > $limit ? 'admin' : 'operations_head';
        $breakdown = ['lines' => $calc['lines'], 'context' => $ctx,
                      'formula' => Settings::snapshot(['km_per_liter', 'fuel_price_per_liter', 'fund_approval_threshold'], ['trip_allowance'])];
        $id = insert($p, 'fund_requests', ['trip_id' => $t['id'], 'attempt_number' => $t['attempt_number'], 'amount' => number_format($amount, 2, '.', ''),
            'breakdown_json' => json_encode($breakdown, JSON_UNESCAPED_UNICODE), 'approver_role' => $approver, 'status' => 'Pending Approval', 'requested_by' => null]);
        audit($p, null, 'Finance', 'fund ' . $id, 'fund request computed by system', $t['trip_ref'] . ': PHP ' . number_format($amount, 2) . ' -> approver ' . ROLE_LABELS[$approver]);
        Notify::roles($p, [$approver], 'Fund approval', 'Fund request PHP ' . number_format($amount, 2) . ' for ' . $t['trip_ref'] . ' needs your approval.', 'funds');
        Notify::roles($p, ['finance'], 'Fund request', 'New fund request for ' . $t['trip_ref'] . ' (PHP ' . number_format($amount, 2) . ').', 'funds');
        if ($amount <= 0) {
            Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Fund request', $t['trip_ref'] . ': allowance computed as PHP 0.00 (no route km / toll). Please adjust the amount in Trip Funds.', 'funds');
        }
        return ['id' => $id, 'amount' => $amount];
    }

}
