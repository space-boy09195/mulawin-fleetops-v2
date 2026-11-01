<?php
/*
 * =====================================================================
 *  backend/Core/BillingDraft.php  —  AUTOMATIC BILLING DRAFT
 * =====================================================================
 *  Bagong FMS PDF v7 (Scenario A step 9 / Scenario B step 10, Billing and Collection):
 *    "Kapag ongoing ang trip, magccreate na ng billing draft si system
 *     (automation based sa route)."
 *  Tinatawag ito ng Trips sa Confirm Dispatch (doon nagiging ongoing ang trip).
 *  Ang rate ay galing sa client rate ng client + route + truck type (Billing > Client rates).
 *  Walang rate = draft pa rin, pero NULL ang rate at ilalagay ito ng Billing.
 *  Ang finalize (kapag Completed na at verified na ang documents) ay nasa Billing module.
 * =====================================================================
 */
declare(strict_types=1);

final class BillingDraft {
    /**
     * Gagawa ng draft para sa trip kung wala pa (isang draft bawat trip, kahit may Second Attempt).
     * Hindi pinipigilan ang dispatch kapag pumalya ito: nakatala lang sa error log.
     */
    public static function createFor(PDO $p, array $t, ?int $uid): ?int {
        try {
            if (one($p, 'SELECT id FROM billing_drafts WHERE trip_id=?', [$t['id']])) return null;
            $r = $t['truck_type'] ? one($p, "SELECT amount, rate_basis FROM client_rates WHERE client_id=? AND truck_type=? AND status='Active'
                    AND (route_id=? OR route_id IS NULL) AND (effective_on IS NULL OR effective_on<=DATE(?))
                  ORDER BY (route_id IS NULL), effective_on DESC, id DESC LIMIT 1",
                [$t['client_id'], $t['truck_type'], $t['route_id'], $t['scheduled_at']]) : null;
            $basis = $r['rate_basis'] ?? 'Per Trip';
            $qty = match ($basis) {
                'Per Unit' => max(1, (int)($t['units_planned'] ?? 1)),
                'Per KM' => (float)($t['distance_km'] ?? 0),
                default => 1,
            };
            $rate = $r ? (float)$r['amount'] : null;
            $id = insert($p, 'billing_drafts', ['trip_id' => $t['id'], 'client_id' => $t['client_id'], 'rate_basis' => $basis,
                'quantity' => $qty, 'rate' => $rate === null ? null : number_format($rate, 2, '.', ''),
                'amount' => $rate === null ? null : number_format(round($rate * $qty, 2), 2, '.', ''),
                'description' => cut($t['trip_ref'] . ' ' . ($t['origin'] ?? '') . ' - ' . ($t['destination'] ?? ''), 255)]);
            audit($p, $uid, 'Billing', $t['trip_ref'], 'billing draft created by system',
                $rate === null ? 'No client rate for this route yet; Billing will enter the rate.' : "$basis: PHP " . number_format($rate, 2) . ' x ' . $qty);
            if (class_exists('Notify')) Notify::roles($p, ['billing'], 'Billing draft', 'Billing draft created for ' . $t['trip_ref'] . ' (trip is ongoing).', 'billing');
            return $id;
        } catch (Throwable $e) {
            error_log('Billing draft for trip ' . ($t['id'] ?? '?') . ' failed: ' . $e->getMessage());
            return null;
        }
    }
}
