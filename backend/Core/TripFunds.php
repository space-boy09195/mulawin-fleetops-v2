<?php
/*
 * =====================================================================
 *  backend/Core/TripFunds.php  —  FUND REQUEST NG DISPATCHER
 * =====================================================================
 *  Bagong FMS PDF (Scenario A step 3, Scenario B step 4):
 *    - Makikita ng Dispatcher ang pre-assigned trip at ang fund
 *      categories na may PRESET na halaga.
 *    - Pipiliin lang ang applicable na gastusin. Pwedeng magdagdag ng
 *      ibang category at halaga kung kailangan.
 *    - Ang system ang kukuha ng preset amount at magko-compute ng total.
 *    - Operations Head ang nag-a-approve (wala nang PHP 15,000 limit).
 *
 *  Ang preset categories ay nasa Formula Settings ("Fund request
 *  categories"). Default: Forklift Operator 100, Checker 100,
 *  Passway 200, Driver Meal Allowance 300, Helper Meal Allowance 200.
 *
 *  Shared ito ng Funds module (request at revise) para iisa lang ang
 *  computation.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('Notify');

final class TripFunds {
    /** Hanggang ilang dagdag na category ang pwedeng ilagay sa isang request. */
    public const MAX_EXTRA_LINES = 10;

    /** Preset categories: [['name' => 'Checker', 'amount' => 100.0], ...]. */
    public static function categories(): array {
        $out = [];
        foreach (Settings::computeItems('trip_allowance', [])['lines'] as $l) {
            $out[] = ['name' => $l['name'], 'amount' => (float)$l['amount']];
        }
        return $out;
    }

    /**
     * Binubuo ang breakdown mula sa piniling categories at dagdag na linya.
     * Ang halaga ng preset ay kinukuha ng system (hindi galing sa browser).
     *   $d['categories'] = ['Checker', 'Passway', ...]
     *   $d['extra']      = [['name' => 'Parking', 'amount' => 50], ...]
     */
    public static function buildLines(array $d): array {
        $presets = [];
        foreach (self::categories() as $c) $presets[$c['name']] = $c['amount'];
        $picked = $d['categories'] ?? [];
        must(is_array($picked), 'Invalid categories.');
        $lines = [];
        foreach (array_unique(array_map('strval', $picked)) as $name) {
            must(array_key_exists($name, $presets), 'Unknown fund category: ' . $name . '.');
            $lines[] = ['name' => $name, 'calc' => 'preset', 'amount' => round($presets[$name], 2), 'explain' => 'preset amount'];
        }
        $extra = $d['extra'] ?? [];
        must(is_array($extra) && count($extra) <= self::MAX_EXTRA_LINES, 'Too many added categories (max ' . self::MAX_EXTRA_LINES . ').');
        foreach ($extra as $l) {
            if (!is_array($l)) continue;
            $name = trim((string)($l['name'] ?? ''));
            $amt = trim((string)($l['amount'] ?? ''));
            if ($name === '' && $amt === '') continue;
            must($name !== '' && textLength($name) <= 80, 'Each added category needs a name (up to 80 characters).');
            must(is_numeric($amt) && (float)$amt > 0 && (float)$amt <= 1000000, 'Each added category needs an amount greater than zero.');
            $lines[] = ['name' => $name, 'calc' => 'added', 'amount' => round((float)$amt, 2), 'explain' => 'added by dispatcher'];
        }
        must(count($lines) > 0, 'Choose at least one fund category.');
        $total = round(array_sum(array_column($lines, 'amount')), 2);
        return ['lines' => $lines, 'total' => $total];
    }

    /** Ise-save ang breakdown (kasama ang preset values na ginamit) para hindi magbago ang lumang record. */
    public static function breakdownJson(array $calc, string $notes = ''): string {
        return json_encode(['lines' => $calc['lines'], 'notes' => $notes,
            'formula' => Settings::snapshot([], ['trip_allowance'])], JSON_UNESCAPED_UNICODE);
    }

    /** Gumagawa ng bagong fund request para sa trip (status Pending Approval). */
    public static function create(PDO $p, array $t, array $u, array $calc, string $notes = ''): array {
        $id = insert($p, 'fund_requests', ['trip_id' => $t['id'], 'attempt_number' => $t['attempt_number'],
            'amount' => number_format($calc['total'], 2, '.', ''), 'breakdown_json' => self::breakdownJson($calc, $notes),
            'approver_role' => 'operations_head', 'status' => 'Pending Approval', 'requested_by' => $u['id']]);
        audit($p, $u['id'], 'Finance', 'fund ' . $id, 'fund request submitted', $t['trip_ref'] . ': PHP ' . number_format($calc['total'], 2));
        Notify::roles($p, ['operations_head'], 'Fund approval', 'Fund request PHP ' . number_format($calc['total'], 2) . ' for ' . $t['trip_ref'] . ' needs your approval.', 'funds');
        return ['id' => $id, 'amount' => $calc['total']];
    }
}
