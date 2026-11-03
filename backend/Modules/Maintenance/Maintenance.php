<?php
/*
 * =====================================================================
 *  backend/Modules/Maintenance/Maintenance.php  —  VEHICLE MAINTENANCE
 * =====================================================================
 *  Objective 2.1  maintenance records (inspection findings, problems, repair
 *                 activities, parts used, repair dates, costs, documents)
 *  Objective 2.2  maintenance requests from trip incident reports + truck
 *                 availability based on repair status
 *  Objective 2.3  on-premise repairs (inspection, repair, completion, verification)
 *  Objective 2.6  monitoring of ongoing and completed maintenance
 *
 *  PDF #12 Maintenance Module C:
 *   From an incident: View Incident -> assess Minor or Major
 *     Minor: mechanic repairs on site -> Confirm Repair -> "Repaired - Cleared to Continue"
 *            -> dispatcher Resume Trip
 *     Major: truck Under Maintenance, trip For Reschedule -> repair -> Confirm Repair
 *            -> truck Available again -> dispatcher Reschedule Trip
 *   On-premise: Start Maintenance (truck, problem, inspection date, photos) -> truck
 *     Under Maintenance (removed from the available list) -> Confirm Repair (activities,
 *     parts, completion date, costs, evidence) -> truck Available.
 *   Monthly PMS of every truck (PDF #13 question 7).
 *  Confirm Repair is refused while the repair details or evidence are missing.
 * =====================================================================
 */
declare(strict_types=1);
core('FleetState');
core('Notify');
core('Files');
core('Money');
core('Settings');

final class Maintenance {
    private const ACT = ['motorpool'];
    public const COST_TYPES = ['Labor', 'Outside Service', 'Materials', 'Other'];

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $months = max(1, (int)Settings::value('pms_interval_months'));
        $data = readSections([
            'jobs' => fn() => many($p, "SELECT m.*, v.plate_number, v.truck_number, v.truck_type, v.status AS truck_status,
                    i.incident_ref, i.incident_type, i.incident_at, i.location AS incident_location, i.status AS incident_status, t.trip_ref, t.status AS trip_status,
                    (SELECT COALESCE(SUM(c.amount),0) FROM maintenance_costs c WHERE c.maintenance_job_id=m.id) AS other_cost,
                    (SELECT COALESCE(SUM(l.released_qty*l.unit_cost),0) FROM part_request_lines l JOIN part_requests r ON r.id=l.part_request_id WHERE r.maintenance_job_id=m.id) AS parts_cost
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN incidents i ON i.id=m.incident_id LEFT JOIN trips t ON t.id=m.trip_id
                ORDER BY FIELD(m.status,'Reported','Ongoing','Completed','Cancelled'), m.id DESC LIMIT 1000"),
            'pms' => fn() => many($p, "SELECT v.id, v.plate_number, v.truck_number, v.truck_type, v.status, v.last_pms_date,
                    DATE_ADD(v.last_pms_date, INTERVAL $months MONTH) AS next_due,
                    DATEDIFF(DATE_ADD(v.last_pms_date, INTERVAL $months MONTH), CURRENT_DATE()) AS days_left,
                    (SELECT m.job_ref FROM maintenance_jobs m WHERE m.vehicle_id=v.id AND m.source='Preventive' AND m.status IN ('Reported','Ongoing') LIMIT 1) AS open_pms
                FROM vehicles v WHERE v.active=1 ORDER BY (v.last_pms_date IS NULL) DESC, next_due, v.plate_number"),
            'trucks' => fn() => many($p, "SELECT v.id, v.plate_number, v.truck_number, v.truck_type, v.status FROM vehicles v WHERE v.active=1 ORDER BY v.plate_number"),
        ]);
        $data['costTypes'] = self::COST_TYPES;
        $data['can'] = ['act' => hasRole($u, ...self::ACT)];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'assess' => self::assess($u, $d),
            'start' => self::start($u, $d),
            'add_cost' => self::addCost($u, $d),
            'confirm' => self::confirm($u, $d),
            'cancel' => self::cancel($u, $d),
            'details' => self::details($u, $d),
            default => throw new BusinessRuleException('Unknown action.', 404),
        };
    }

    private static function lockJob(PDO $p, int $id): array {
        $j = one($p, 'SELECT * FROM maintenance_jobs WHERE id=? FOR UPDATE', [$id]);
        must((bool)$j, 'Maintenance record not found.', 404);
        return $j;
    }

    /** Assessment of an incident: Minor (on-site repair) or Major (return, repair, redispatch). PDF #12 Scenario C step 3. */
    private static function assess(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $sev = choiceField($d, 'severity', ['Minor', 'Major']);
        $rec = field($d, 'recommendation', 500);
        $who = optField($d, 'assigned_to', 150);
        $exp = dateField($d, 'expected_completion', $sev === 'Major');
        if ($exp) must($exp >= date('Y-m-d'), 'Expected completion cannot be in the past.');
        return transaction(function (PDO $p) use ($u, $id, $sev, $rec, $who, $exp) {
            $j = self::lockJob($p, $id);
            must($j['source'] === 'Incident' && $j['status'] === 'Reported', 'Only a newly reported incident can be assessed.', 409);
            $inc = one($p, 'SELECT * FROM incidents WHERE id=? FOR UPDATE', [$j['incident_id']]);
            $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$j['trip_id']]);
            must($t && $t['status'] === 'On Hold - Incident Reported', 'The trip is not On Hold.', 409);
            run($p, "UPDATE maintenance_jobs SET status='Ongoing', severity=?, assigned_to=?, expected_completion=?, started_by=?, started_at=NOW(), remarks=? WHERE id=?",
                [$sev, $who, $exp, $u['id'], $rec, $id]);
            run($p, "UPDATE incidents SET severity=?, recommendation=?, status='Under Repair', assessed_by=?, assessed_at=NOW() WHERE id=?", [$sev, $rec, $u['id'], $inc['id']]);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'incident assessed', $inc['incident_ref'] . ': ' . $sev);
            Actions::load('Trips');
            if ($sev === 'Major') {
                Trips::setStatus($p, $u['id'], $t, 'For Reschedule', 'major damage: truck returned for repair', $inc['incident_ref'] . ($exp ? '; expected back ' . $exp : ''));
                $now = FleetState::reconcile($p, (int)$j['vehicle_id'], $u['id'], 'Major damage ' . $inc['incident_ref']);
                Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Major damage', $t['trip_ref'] . ' is For Reschedule. Truck is ' . $now . ($exp ? ' (expected back ' . $exp . ')' : '') . '.', 'trips');
                return ['message' => 'Assessed as MAJOR. Truck is ' . $now . ' and the trip is For Reschedule.'];
            }
            Trips::event($p, $u['id'], (int)$t['id'], 'minor damage: mechanic sent to the location', $t['status'], $t['status'], $inc['incident_ref'] . ($who ? '; ' . $who : ''));
            Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Minor damage', $t['trip_ref'] . ': mechanic sent for on-site repair.', 'trips');
            return ['message' => 'Assessed as MINOR. Send the mechanic, then Confirm Repair when the truck is safe to continue.'];
        });
    }

    /** Start Maintenance: on-premise repair or monthly PMS. The truck becomes Under Maintenance. */
    private static function start(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $vid = idField($d, 'vehicle_id');
        $source = choiceField($d, 'source', ['On-Premise', 'Preventive']);
        $problem = $source === 'Preventive' ? (optField($d, 'problem', 500) ?? 'Monthly Preventive Maintenance Service (PMS)') : field($d, 'problem', 500);
        $date = dateField($d, 'inspection_date');
        must($date <= date('Y-m-d'), 'Inspection date cannot be in the future.');
        $who = optField($d, 'assigned_to', 150);
        $exp = dateField($d, 'expected_completion', false);
        if ($exp) must($exp >= $date, 'Expected completion is before the inspection date.');
        $remarks = optField($d, 'remarks', 500);
        return transaction(function (PDO $p) use ($u, $vid, $source, $problem, $date, $who, $exp, $remarks) {
            $v = one($p, 'SELECT * FROM vehicles WHERE id=? FOR UPDATE', [$vid]);
            must($v && (int)$v['active'], 'Truck not found or inactive.', 404);
            must(!($open = FleetState::openJob($p, $vid)), 'This truck already has an open maintenance record (' . ($open['job_ref'] ?? '') . ').', 409);
            $trip = FleetState::activeTrip($p, $vid);
            must(!$trip || !in_array($trip['status'], FleetState::ON_ROAD, true), 'The truck is on the road (' . ($trip['trip_ref'] ?? '') . '). Use an incident report for trouble during a trip.', 409);
            $jid = insert($p, 'maintenance_jobs', ['job_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'vehicle_id' => $vid, 'source' => $source, 'problem' => $problem,
                'inspection_date' => $date, 'assigned_to' => $who, 'expected_completion' => $exp, 'remarks' => $remarks, 'status' => 'Ongoing',
                'started_by' => $u['id'], 'started_at' => date('Y-m-d H:i:s'), 'created_by' => $u['id']]);
            $ref = refNumber('JOB', $jid);
            run($p, 'UPDATE maintenance_jobs SET job_ref=? WHERE id=?', [$ref, $jid]);
            Files::store($p, $u, 'photos', 'maintenance', $jid, 'Inspection Photo', $source === 'On-Premise', 'photos of the damaged parts or truck condition');
            $msg = "Maintenance $ref started. Truck " . $v['plate_number'] . ' is Under Maintenance and removed from the available list.';
            if ($trip) { Actions::load('Trips'); $msg .= ' ' . Trips::resourceLost($p, $u, (int)$trip['id'], 'Truck ' . $v['plate_number'] . ' went Under Maintenance'); }
            FleetState::reconcile($p, $vid, $u['id'], "$source maintenance $ref");
            audit($p, $u['id'], 'Maintenance', 'job ' . $jid, 'maintenance started', $ref . ' ' . $v['plate_number'] . ' (' . $source . ')');
            Notify::roles($p, ['operations_head'], 'Truck under maintenance', $v['plate_number'] . ' is Under Maintenance (' . $source . ')' . ($exp ? ', expected back ' . $exp : '') . '.', 'maintenance');
            return ['job_id' => $jid, 'message' => $msg];
        });
    }

    /** Repair costs (labor, outside service ...). Recorded as maintenance expenses. */
    private static function addCost(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $row = ['cost_type' => choiceField($d, 'cost_type', self::COST_TYPES), 'description' => field($d, 'description', 255),
                'provider' => optField($d, 'provider', 150), 'amount' => moneyField($d, 'amount'), 'incurred_on' => dateField($d, 'incurred_on')];
        must($row['incurred_on'] <= date('Y-m-d'), 'The date cannot be in the future.');
        return transaction(function (PDO $p) use ($u, $id, $row) {
            $j = self::lockJob($p, $id);
            must($j['status'] === 'Ongoing', 'Costs are added while the maintenance is Ongoing.', 409);
            $cid = insert($p, 'maintenance_costs', ['maintenance_job_id' => $id, 'recorded_by' => $u['id']] + $row);
            Money::expense($p, $u, 'Maintenance', $row['amount'], $row['incurred_on'], 'Maintenance', $cid, null, (int)$j['vehicle_id'], $row['provider'], $j['job_ref'], $row['cost_type'] . ': ' . $row['description']);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'repair cost added', $j['job_ref'] . ' ' . $row['cost_type'] . ' PHP ' . $row['amount']);
            return ['message' => 'Cost recorded.'];
        });
    }

    /** Confirm Repair: needs repair details + evidence. Updates truck, incident and trip. */
    private static function confirm(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id');
        $performed = field($d, 'repair_performed', 2000);
        $done = dateField($d, 'completed_on');
        must($done <= date('Y-m-d'), 'Completion date cannot be in the future.');
        $remarks = optField($d, 'remarks', 500);
        return transaction(function (PDO $p) use ($u, $id, $performed, $done, $remarks) {
            $j = self::lockJob($p, $id);
            must($j['status'] === 'Ongoing', 'Only Ongoing maintenance can be confirmed' . ($j['status'] === 'Reported' ? ' (assess the incident first).' : '.'), 409);
            must($done >= substr((string)$j['started_at'], 0, 10), 'Completion date is before the start of the repair.');
            Files::store($p, $u, 'evidence', 'maintenance', $id, 'Repair Evidence');
            must(Files::count($p, 'maintenance', $id, 'Repair Evidence') > 0, 'Attach at least one supporting photo or document as evidence of the repair.', 422);
            run($p, "UPDATE maintenance_jobs SET status='Completed', repair_performed=?, completed_on=?, remarks=COALESCE(?, remarks), completed_by=?, completed_at=NOW() WHERE id=?",
                [$performed, $done, $remarks, $u['id'], $id]);
            $v = one($p, 'SELECT plate_number FROM vehicles WHERE id=?', [$j['vehicle_id']]);
            if ($j['source'] === 'Preventive') run($p, 'UPDATE vehicles SET last_pms_date=GREATEST(COALESCE(last_pms_date, ?), ?) WHERE id=?', [$done, $done, $j['vehicle_id']]);
            $now = FleetState::reconcile($p, (int)$j['vehicle_id'], $u['id'], 'Repair confirmed ' . $j['job_ref']);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'repair confirmed', $j['job_ref'] . ': ' . cut($performed, 200));
            $msg = 'Repair confirmed. Truck ' . $v['plate_number'] . ' is ' . $now . '.';
            if ($j['source'] === 'Incident') {
                $inc = one($p, 'SELECT * FROM incidents WHERE id=? FOR UPDATE', [$j['incident_id']]);
                $t = one($p, 'SELECT * FROM trips WHERE id=?', [$j['trip_id']]);
                Actions::load('Trips');
                if ($j['severity'] === 'Minor') {
                    run($p, "UPDATE incidents SET status='Repaired - Cleared to Continue' WHERE id=?", [$inc['id']]);
                    Trips::event($p, $u['id'], (int)$t['id'], 'repaired - cleared to continue', $t['status'], $t['status'], $inc['incident_ref']);
                    Notify::roles($p, [$t['dispatcher_role']], 'Cleared to continue', $t['trip_ref'] . ': truck repaired on site. Resume the trip.', 'trips');
                    $msg .= ' The dispatcher was told to resume the trip.';
                } else {
                    run($p, "UPDATE incidents SET status='Resolved', resolved_at=NOW() WHERE id=?", [$inc['id']]);
                    Trips::event($p, $u['id'], (int)$t['id'], 'truck repaired after major damage', $t['status'], $t['status'], $inc['incident_ref']);
                    Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Truck available again', $v['plate_number'] . ' is repaired. Reschedule ' . $t['trip_ref'] . '.', 'trips');
                    $msg .= ' The dispatcher can now reschedule the trip.';
                }
            } else {
                Notify::roles($p, ['operations_head', 'car_carrier_dispatcher', 'container_wingvan_dispatcher'], 'Truck available again', $v['plate_number'] . ' finished maintenance and is ' . $now . '.', 'trucks');
                Actions::load('Trips');
                if ($auto = Trips::assignWaiting($p, $u)) $msg .= ' ' . $auto;
            }
            return ['message' => $msg];
        });
    }

    /** Cancel an on-premise / PMS record made by mistake. Incident repairs cannot be cancelled. */
    private static function cancel(array $u, array $d): array {
        requireRole($u, ...self::ACT);
        $id = idField($d, 'job_id'); $reason = field($d, 'reason', 255);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $j = self::lockJob($p, $id);
            must($j['source'] !== 'Incident', 'Incident repairs cannot be cancelled; confirm the repair instead.', 409);
            must(in_array($j['status'], ['Reported', 'Ongoing'], true), 'Already ' . $j['status'] . '.', 409);
            must(!one($p, "SELECT r.id FROM part_requests r JOIN part_request_lines l ON l.part_request_id=r.id WHERE r.maintenance_job_id=? AND l.released_qty>0", [$id]), 'Parts were already released for this record; confirm it instead.', 409);
            run($p, "UPDATE maintenance_jobs SET status='Cancelled', remarks=? WHERE id=?", [cut('Cancelled: ' . $reason, 500), $id]);
            run($p, "UPDATE part_requests SET status='Cancelled' WHERE maintenance_job_id=? AND status='Requested'", [$id]);
            $now = FleetState::reconcile($p, (int)$j['vehicle_id'], $u['id'], 'Maintenance cancelled ' . $j['job_ref']);
            audit($p, $u['id'], 'Maintenance', 'job ' . $id, 'maintenance cancelled', $j['job_ref'] . ': ' . $reason);
            return ['message' => 'Maintenance record cancelled. Truck is ' . $now . '.'];
        });
    }

    private static function details(array $u, array $d): array {
        must(Access::canOpen($u, 'maintenance') || Access::canOpen($u, 'trucks'), 'Not allowed.', 403);
        $id = idField($d, 'job_id'); $p = db();
        $j = one($p, 'SELECT m.*, v.plate_number, v.truck_number, v.truck_type, t.trip_ref, su.full_name AS started_by_name, cu.full_name AS completed_by_name
            FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN trips t ON t.id=m.trip_id
            LEFT JOIN users su ON su.id=m.started_by LEFT JOIN users cu ON cu.id=m.completed_by WHERE m.id=?', [$id]);
        must((bool)$j, 'Record not found.', 404);
        $inc = $j['incident_id'] ? one($p, 'SELECT i.*, ru.full_name AS reported_by_name, au.full_name AS assessed_by_name FROM incidents i JOIN users ru ON ru.id=i.reported_by LEFT JOIN users au ON au.id=i.assessed_by WHERE i.id=?', [$j['incident_id']]) : null;
        $docs = Files::of($p, 'maintenance', $id);
        if ($inc) foreach (Files::of($p, 'incident', (int)$inc['id']) as $doc) $docs[] = $doc;
        return [
            'job' => $j, 'incident' => $inc, 'documents' => $docs,
            'costs' => many($p, 'SELECT c.*, u.full_name AS recorded_by_name FROM maintenance_costs c JOIN users u ON u.id=c.recorded_by WHERE c.maintenance_job_id=? ORDER BY c.id', [$id]),
            'parts' => many($p, 'SELECT r.request_ref, r.status, pt.part_number, pt.description, l.quantity, l.released_qty, l.unit_cost FROM part_requests r
                JOIN part_request_lines l ON l.part_request_id=r.id JOIN parts pt ON pt.id=l.part_id WHERE r.maintenance_job_id=? ORDER BY r.id, l.id', [$id]),
        ];
    }
}
