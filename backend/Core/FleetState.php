<?php
/*
 * backend/Core/FleetState.php  —  shared truck status rules
 * Used by Trips, Maintenance and Fleet modules so that the same rule
 * applies everywhere (FMS PDF II.B, p.18).
 *
 * Truck status values (PDF II.B): Available, Assigned, On Trip,
 * Under Maintenance, Unavailable.
 */
declare(strict_types=1);

final class FleetState {
    public const STATUSES = ['Available', 'Assigned', 'On Trip', 'Under Maintenance', 'Unavailable'];
    /** Trip statuses that still hold the truck and crew. */
    public const HOLDING_TRIP_STATUSES = ['Assigned', 'Ready for Dispatch', 'In Progress', 'Returned'];

    /** Changes a truck status and writes the status log (for down-truck days). */
    public static function setStatus(PDO $p, int $vehicleId, string $to, ?int $actorId, string $reason = ''): void {
        must(in_array($to, self::STATUSES, true), 'Invalid truck status.');
        $v = one($p, 'SELECT status FROM vehicles WHERE id=? FOR UPDATE', [$vehicleId]);
        must((bool)$v, 'Truck not found.', 404);
        if ($v['status'] === $to) return;
        run($p, 'UPDATE vehicles SET status=? WHERE id=?', [$to, $vehicleId]);
        $p->prepare('INSERT INTO vehicle_status_log (vehicle_id, from_status, to_status, reason, actor_id) VALUES (?,?,?,?,?)')
          ->execute([$vehicleId, $v['status'], $to, cut($reason, 255), $actorId]);
    }

    public static function activeTrip(PDO $p, int $vehicleId, ?int $excludeTripId = null): ?array {
        $marks = implode(',', array_fill(0, count(self::HOLDING_TRIP_STATUSES), '?'));
        $args = array_merge([$vehicleId], self::HOLDING_TRIP_STATUSES);
        $sql = "SELECT id, status, trip_ref FROM trips WHERE vehicle_id=? AND status IN ($marks)";
        if ($excludeTripId) { $sql .= ' AND id<>?'; $args[] = $excludeTripId; }
        // A trip already on the road comes first, so the truck shows "On Trip".
        return one($p, $sql . " ORDER BY FIELD(status,'In Progress','Returned','Ready for Dispatch','Assigned'), id LIMIT 1", $args);
    }

    public static function openJob(PDO $p, int $vehicleId, ?int $excludeJobId = null): ?array {
        $sql = "SELECT id, maintenance_type, status FROM maintenance_jobs WHERE vehicle_id=? AND status NOT IN ('Completed','Cancelled')";
        $args = [$vehicleId];
        if ($excludeJobId) { $sql .= ' AND id<>?'; $args[] = $excludeJobId; }
        return one($p, $sql . ' ORDER BY id LIMIT 1', $args);
    }

    /**
     * Status a truck should have after a trip or repair ends.
     * Open repair -> Under Maintenance; trip on the road -> On Trip;
     * reserved trip -> Assigned; manually Unavailable stays Unavailable.
     */
    public static function reconcile(PDO $p, int $vehicleId, ?int $actorId, string $reason): string {
        $v = one($p, 'SELECT status FROM vehicles WHERE id=? FOR UPDATE', [$vehicleId]);
        must((bool)$v, 'Truck not found.', 404);
        $job = self::openJob($p, $vehicleId);
        $trip = self::activeTrip($p, $vehicleId);
        // A roadside (minor) repair does not take the truck off its trip.
        $roadside = $job && $job['maintenance_type'] === 'Roadside Repair';
        if ($trip && in_array($trip['status'], ['In Progress', 'Returned'], true)) $next = 'On Trip';
        elseif ($job && !$roadside) $next = 'Under Maintenance';
        elseif ($trip) $next = 'Assigned';
        elseif ($v['status'] === 'Unavailable') $next = 'Unavailable';
        else $next = 'Available';
        self::setStatus($p, $vehicleId, $next, $actorId, $reason);
        return $next;
    }

    /** Crew availability: only switches between Available and On Trip; manual Unavailable is kept. */
    public static function setCrew(PDO $p, array $personIds, string $from, string $to): void {
        foreach (array_unique(array_filter(array_map('intval', $personIds))) as $id) {
            run($p, 'UPDATE personnel SET availability=? WHERE id=? AND availability=?', [$to, $id, $from]);
        }
    }
}
