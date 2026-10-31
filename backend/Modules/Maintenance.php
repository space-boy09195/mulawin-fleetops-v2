<?php
/*
 * =====================================================================
 *  backend/Modules/Maintenance.php  —  VEHICLE MAINTENANCE (Motorpool)
 * =====================================================================
 *  FMS PDF II.L and pp.17-18 (Maintenance module):
 *    - Incident reports appear here automatically (Trips module).
 *    - Bagong PDF Scenario C: ang Maintenance ang nag-a-assess kung Minor
 *      o Major ang sira (assess). Minor: mekaniko sa lugar, tapos
 *      "Repaired - Cleared to Continue". Major: truck Under Maintenance,
 *      trip For Reschedule, ayusin, tapos Available ulit.
 *    - Suggested process: record issue -> vehicle -> photos/documents ->
 *      activity -> parts and materials used -> costs -> status ->
 *      completion and return the vehicle to Available.
 *    - Motorpool requests parts from Purchasing (stock-out request).
 *    - Preventive maintenance: by kilometers (automatic) or by schedule.
 *    - Operations Head sees trucks under maintenance and when they are
 *      expected to be available.
 * =====================================================================
 */
declare(strict_types=1);
core('FleetState');
core('Settings');
core('Notify');

final class Maintenance {
    public const JOB_STATUSES = ['Reported', 'Scheduled', 'In Progress', 'Waiting for Parts'];

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $data = readSections([
            'jobs' => fn() => many($p, "SELECT m.*, v.plate_number, v.truck_type, v.status AS vehicle_status, i.severity, i.status AS incident_status,
                    i.location AS incident_location, i.problem AS incident_problem, i.incident_at, t.trip_ref, t.status AS trip_status,
                    us.full_name AS created_by_name,
                    (SELECT COALESCE(SUM(mu.quantity*mu.unit_cost),0) FROM maintenance_parts_used mu WHERE mu.maintenance_job_id=m.id) AS parts_cost,
                    (SELECT COALESCE(SUM(mc.amount),0) FROM maintenance_costs mc WHERE mc.maintenance_job_id=m.id) AS other_cost
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN incidents i ON i.id=m.incident_id
                LEFT JOIN trips t ON t.id=m.trip_id LEFT JOIN users us ON us.id=m.created_by
                ORDER BY FIELD(m.status,'Reported','In Progress','Waiting for Parts','Scheduled','Completed','Cancelled'), m.id DESC LIMIT 500"),
            'schedules' => fn() => many($p, "SELECT s.*, v.plate_number, v.odometer_km, v.km_since_pm FROM maintenance_schedules s JOIN vehicles v ON v.id=s.vehicle_id
                ORDER BY FIELD(s.status,'Scheduled','Completed','Cancelled'), s.due_on IS NULL, s.due_on, s.id DESC LIMIT 300"),
            'vehicles' => fn() => many($p, "SELECT v.id, v.plate_number, v.truck_type, v.status, v.odometer_km, v.km_since_pm,
                    (SELECT MAX(m.expected_completion) FROM maintenance_jobs m WHERE m.vehicle_id=v.id AND m.status NOT IN ('Completed','Cancelled')) AS expected_available
                FROM vehicles v WHERE v.active=1 ORDER BY v.plate_number"),
            'partsUsed' => fn() => many($p, 'SELECT mu.*, pa.part_number, pa.description FROM maintenance_parts_used mu JOIN parts pa ON pa.id=mu.part_id ORDER BY mu.id DESC LIMIT 500'),
            'costs' => fn() => many($p, 'SELECT * FROM maintenance_costs ORDER BY id DESC LIMIT 500'),
            'partRequests' => fn() => many($p, 'SELECT pr.*, pa.part_number, pa.description, pa.quantity_on_hand, pa.unit, v.plate_number,
                    (SELECT COALESCE(SUM(mu.quantity),0) FROM maintenance_parts_used mu WHERE mu.part_request_id=pr.id) AS used_qty
                FROM part_requests pr JOIN parts pa ON pa.id=pr.part_id JOIN vehicles v ON v.id=pr.vehicle_id ORDER BY pr.id DESC LIMIT 300'),
            'parts' => fn() => many($p, 'SELECT id, part_number, description, unit, quantity_on_hand FROM parts WHERE active=1 ORDER BY description'),
        ]);
        $data['pmInterval'] = Settings::value('pm_interval_km');
        $data['jobStatuses'] = self::JOB_STATUSES;
        $data['can'] = ['edit' => hasRole($u, 'motorpool')];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        requireRole($u, 'motorpool');
        return match ($action) {
            'job_add' => self::jobAdd($u, $d),
            'job_update' => self::jobUpdate($u, $d),
            'job_complete' => self::jobComplete($u, $d),
            'assess' => self::assess($u, $d),
            'part_request' => self::partRequest($u, $d),
            'part_use' => self::partUse($u, $d),
            'cost_add' => self::costAdd($u, $d),
            'schedule_add' => self::scheduleAdd($u, $d),
            'schedule_start' => self::scheduleStart($u, $d),
            'schedule_cancel' => self::scheduleCancel($u, $d),
            default => throw new BusinessRuleException('Unknown maintenance action.', 404),
        };
    }

    private static function newJob(PDO $p, array $row): int {
        $id = insert($p, 'maintenance_jobs', $row);
        run($p, 'UPDATE maintenance_jobs SET job_number=? WHERE id=?', [refNumber('JOB', $id), $id]);
        return $id;
    }

    /** Record a repair or maintenance requirement (PDF II.L steps 50-53). */
    private static function jobAdd(array $u, array $d): array {
        $vid = idField($d, 'vehicle_id');
        $type = choiceField($d, 'maintenance_type', ['Repair', 'Preventive Maintenance']);
        $desc = field($d, 'description', 2000);
        $exp = dateField($d, 'expected_completion', false);
        $who = optField($d, 'assigned_to', 150);
        return transaction(function (PDO $p) use ($u, $vid, $type, $desc, $exp, $who) {
            $v = one($p, 'SELECT * FROM vehicles WHERE id=? AND active=1 FOR UPDATE', [$vid]);
            must((bool)$v, 'Truck not found.', 404);
            $trip = FleetState::activeTrip($p, $vid);
            must(!$trip || !in_array($trip['status'], FleetState::ON_ROAD_TRIP_STATUSES, true), 'Truck is on trip ' . ($trip['trip_ref'] ?? '') . '. The dispatcher reports road problems as an incident.', 409);
            must(!$trip, 'Truck is reserved for trip ' . ($trip['trip_ref'] ?? '') . '. Ask the Operations Head to assign another truck first.', 409);
            $id = self::newJob($p, ['vehicle_id' => $vid, 'source' => 'Manual', 'maintenance_type' => $type, 'description' => $desc,
                'assigned_to' => $who, 'expected_completion' => $exp, 'status' => 'Reported', 'odometer_at_service' => $v['odometer_km'], 'created_by' => $u['id']]);
            FleetState::setStatus($p, $vid, 'Under Maintenance', $u['id'], refNumber('JOB', $id) . ' ' . $type);
            audit($p, $u['id'], 'Maintenance', refNumber('JOB', $id), 'maintenance job created', $v['plate_number'] . ': ' . $desc);
            Notify::roles($p, ['operations_head'], 'Truck under maintenance', $v['plate_number'] . ' is under maintenance' . ($exp ? ', expected back ' . $exp : '') . '.', 'maintenance');
            return ['job_id' => $id, 'message' => 'Job ' . refNumber('JOB', $id) . ' created. Truck is Under Maintenance.'];
        });
    }

    private static function jobUpdate(array $u, array $d): array {
        $id = idField($d, 'job_id');
        $status = choiceField($d, 'status', self::JOB_STATUSES);
        $exp = dateField($d, 'expected_completion', false);
        $who = optField($d, 'assigned_to', 150);
        return transaction(function (PDO $p) use ($u, $id, $status, $exp, $who) {
            $j = one($p, 'SELECT m.*, v.plate_number FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id WHERE m.id=? FOR UPDATE', [$id]);
            must($j && !in_array($j['status'], ['Completed', 'Cancelled'], true), 'Job is not open.', 409);
            self::mustBeAssessed($p, $j);
            run($p, "UPDATE maintenance_jobs SET status=?, expected_completion=?, assigned_to=?, started_at=COALESCE(started_at, IF(?='In Progress', NOW(), NULL)) WHERE id=?",
                [$status, $exp, $who, $status, $id]);
            audit($p, $u['id'], 'Maintenance', $j['job_number'], 'job updated', $j['status'] . ' -> ' . $status . ($exp ? "; expected $exp" : ''));
            if ($exp && $exp !== $j['expected_completion']) {
                Notify::roles($p, ['operations_head'], 'Expected availability', $j['plate_number'] . ' expected to be available on ' . $exp . '.', 'maintenance');
            }
            return ['job_id' => $id];
        });
    }

    /** Incident job: kailangang ma-assess muna (Minor o Major) bago galawin. */
    private static function mustBeAssessed(PDO $p, array $j): void {
        if (!$j['incident_id']) return;
        $i = one($p, 'SELECT severity FROM incidents WHERE id=?', [$j['incident_id']]);
        must($i && $i['severity'], 'Assess the incident first (Minor or Major damage).', 409);
    }

    /**
     * Scenario C step 3: Maintenance -> Assess Truck Damage.
     *   Minor (C.1): magpapadala ng mekaniko sa lokasyon; tuloy ang trip pagkatapos.
     *   Major (C.2): truck "Under Maintenance", trip "For Reschedule",
     *                hindi pwedeng i-assign sa ibang trip habang inaayos.
     */
    private static function assess(array $u, array $d): array {
        $id = idField($d, 'job_id');
        $sev = choiceField($d, 'severity', ['Minor', 'Major']);
        $notes = optField($d, 'notes', 255);
        $mechanic = optField($d, 'assigned_to', 150);
        $exp = dateField($d, 'expected_completion', false);
        return transaction(function (PDO $p) use ($u, $id, $sev, $notes, $mechanic, $exp) {
            $j = one($p, 'SELECT m.*, v.plate_number FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id WHERE m.id=? FOR UPDATE', [$id]);
            must($j && $j['incident_id'], 'This job did not come from an incident report.', 409);
            must(!in_array($j['status'], ['Completed', 'Cancelled'], true), 'Job is not open.', 409);
            $i = one($p, 'SELECT * FROM incidents WHERE id=? FOR UPDATE', [$j['incident_id']]);
            must($i && !$i['severity'], 'This incident was already assessed as ' . ($i['severity'] ?? '') . '.', 409);
            $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$i['trip_id']]);
            must($t && $t['status'] === 'On Hold - Incident Reported', 'The trip is not on hold for this incident.', 409);
            $ev = $p->prepare('INSERT INTO trip_events (trip_id, actor_id, event_type, from_status, to_status, notes) VALUES (?,?,?,?,?,?)');
            if ($sev === 'Minor') {
                run($p, "UPDATE incidents SET severity='Minor', status='Under Repair', assessed_by=?, assessed_at=NOW(), action_taken=? WHERE id=?", [$u['id'], $notes, $i['id']]);
                run($p, "UPDATE maintenance_jobs SET maintenance_type='Roadside Repair', status='In Progress', started_at=COALESCE(started_at, NOW()), assigned_to=?, expected_completion=? WHERE id=?", [$mechanic, $exp, $id]);
                $ev->execute([$t['id'], $u['id'], 'incident assessed: minor damage (mechanic sent)', $t['status'], $t['status'], cut((string)$notes, 500)]);
                Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Minor damage', $j['plate_number'] . ' (' . $t['trip_ref'] . '): minor damage, mechanic sent to the location.', 'trips');
                $msg = 'Assessed as Minor. Mechanic sent; confirm the repair when the truck is safe to continue.';
            } else {
                run($p, "UPDATE incidents SET severity='Major', status='For Reschedule', assessed_by=?, assessed_at=NOW(), action_taken=? WHERE id=?", [$u['id'], $notes, $i['id']]);
                run($p, 'UPDATE maintenance_jobs SET assigned_to=?, expected_completion=? WHERE id=?', [$mechanic, $exp, $id]);
                run($p, "UPDATE trips SET status='For Reschedule' WHERE id=?", [$t['id']]);
                $ev->execute([$t['id'], $u['id'], 'incident assessed: major damage (truck returned for repair)', $t['status'], 'For Reschedule', cut((string)$notes, 500)]);
                FleetState::setStatus($p, (int)$j['vehicle_id'], 'Under Maintenance', $u['id'], 'Major damage on ' . $t['trip_ref']);
                FleetState::setCrew($p, [$t['driver_id'], $t['driver2_id'], $t['helper_id']], 'On Trip', 'Available');
                Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Major damage', $j['plate_number'] . ' (' . $t['trip_ref'] . '): major damage. Truck is Under Maintenance and the trip is For Reschedule.', 'trips');
                $msg = 'Assessed as Major. Truck is Under Maintenance and the trip is For Reschedule.';
            }
            audit($p, $u['id'], 'Maintenance', $j['job_number'], 'incident assessed', $sev . ($notes ? ": $notes" : ''));
            return ['job_id' => $id, 'message' => $msg];
        });
    }

    /**
     * Complete a job and return the truck to Available when nothing else is
     * open (PDF II.L step 57 and p.18 "pwede na baguhin ni maintenance yung
     * status as available").
     * Incident jobs (bagong PDF Scenario C):
     *   Minor -> incident "Repaired - Cleared to Continue"; dispatcher ang mag-Resume Trip.
     *   Major -> truck Available; dispatcher ang mag-Reschedule Trip.
     */
    private static function jobComplete(array $u, array $d): array {
        $id = idField($d, 'job_id');
        $notes = optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $id, $notes) {
            $j = one($p, 'SELECT m.*, v.plate_number, v.odometer_km FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id WHERE m.id=? FOR UPDATE', [$id]);
            must($j && !in_array($j['status'], ['Completed', 'Cancelled'], true), 'Job is not open.', 409);
            self::mustBeAssessed($p, $j);
            run($p, "UPDATE maintenance_jobs SET status='Completed', completed_at=NOW(), description=CONCAT(description, ?) WHERE id=?", [$notes ? "\nDone: $notes" : '', $id]);
            $inc = $j['incident_id'] ? one($p, 'SELECT * FROM incidents WHERE id=? FOR UPDATE', [$j['incident_id']]) : null;
            if ($inc && $inc['severity'] === 'Minor') {
                run($p, "UPDATE incidents SET status='Repaired - Cleared to Continue', resolved_at=NOW() WHERE id=?", [$inc['id']]);
            } elseif ($inc) {
                run($p, "UPDATE incidents SET status='Resolved', resolved_at=NOW() WHERE id=?", [$inc['id']]);
            }
            if ($j['maintenance_type'] === 'Preventive Maintenance') {
                // Reset the km counter after PM (TODO C08: confirm reset rule per maintenance type).
                run($p, 'UPDATE vehicles SET km_since_pm=0 WHERE id=?', [$j['vehicle_id']]);
                run($p, "UPDATE maintenance_schedules SET status='Completed' WHERE maintenance_job_id=?", [$id]);
            }
            $status = FleetState::reconcile($p, (int)$j['vehicle_id'], $u['id'], $j['job_number'] . ' completed');
            audit($p, $u['id'], 'Maintenance', $j['job_number'], 'job completed', $j['plate_number'] . ' is now ' . $status);
            $msg = 'Job completed. Truck ' . $j['plate_number'] . ' is now ' . $status . '.';
            $t = $j['trip_id'] ? one($p, 'SELECT trip_ref, status, dispatcher_role FROM trips WHERE id=?', [$j['trip_id']]) : null;
            if ($inc && $inc['severity'] === 'Minor' && $t && $t['status'] === 'On Hold - Incident Reported') {
                // Scenario C.1 step 6: abiso sa dispatcher na pwede nang ituloy ang biyahe.
                Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Repaired - Cleared to Continue', $j['plate_number'] . ' is repaired. ' . $t['trip_ref'] . ' can resume (Resume Trip).', 'trips');
                $msg = 'Repair confirmed: Repaired - Cleared to Continue. The dispatcher can now resume ' . $t['trip_ref'] . '.';
            } elseif ($t && $t['status'] === 'For Reschedule' && $status === 'Available') {
                // Scenario C.2 step 6: available na ulit ang truck; abiso sa dispatcher.
                Notify::roles($p, [$t['dispatcher_role'], 'operations_head'], 'Truck available', $j['plate_number'] . ' is repaired and Available. ' . $t['trip_ref'] . ' can now be rescheduled.', 'trips');
                $msg .= ' Trip ' . $t['trip_ref'] . ' can now be rescheduled by the dispatcher.';
            }
            Notify::roles($p, ['operations_head'], 'Truck status', $msg, 'fleet');
            return ['job_id' => $id, 'message' => $msg];
        });
    }

    /** Stock-out request to Purchasing (PDF p.17 A). */
    private static function partRequest(array $u, array $d): array {
        $vid = idField($d, 'vehicle_id'); $part = idField($d, 'part_id'); $qty = intField($d, 'quantity', 1);
        $job = optId($d, 'job_id'); $notes = optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $vid, $part, $qty, $job, $notes) {
            must((bool)one($p, 'SELECT id FROM vehicles WHERE id=?', [$vid]), 'Truck not found.');
            $pa = one($p, 'SELECT * FROM parts WHERE id=? AND active=1', [$part]);
            must((bool)$pa, 'Part not found.');
            if ($job) must((bool)one($p, "SELECT id FROM maintenance_jobs WHERE id=? AND vehicle_id=? AND status NOT IN ('Completed','Cancelled')", [$job, $vid]), 'Choose an open job of the same truck.');
            $id = insert($p, 'part_requests', ['vehicle_id' => $vid, 'maintenance_job_id' => $job, 'part_id' => $part, 'requested_qty' => $qty, 'notes' => $notes, 'requested_by' => $u['id']]);
            audit($p, $u['id'], 'Inventory', 'part request ' . $id, 'parts requested', $pa['part_number'] . ' x' . $qty);
            Notify::roles($p, ['purchasing'], 'Parts request', 'Motorpool requests ' . $qty . ' ' . $pa['unit'] . ' of ' . $pa['description'] . '.', 'inventory');
            if ($job) run($p, "UPDATE maintenance_jobs SET status='Waiting for Parts' WHERE id=? AND status IN ('Reported','Scheduled','In Progress')", [$job]);
            return ['request_id' => $id, 'message' => 'Parts request sent to Purchasing.'];
        });
    }

    /** Parts and materials used on a job, from issued stock (PDF II.L step 54, II.K maintenance integration). */
    private static function partUse(array $u, array $d): array {
        $jobId = idField($d, 'job_id'); $reqId = idField($d, 'request_id'); $qty = intField($d, 'quantity', 1);
        return transaction(function (PDO $p) use ($u, $jobId, $reqId, $qty) {
            $j = one($p, 'SELECT * FROM maintenance_jobs WHERE id=? FOR UPDATE', [$jobId]);
            must($j && !in_array($j['status'], ['Completed', 'Cancelled'], true), 'Job is not open.', 409);
            $r = one($p, 'SELECT pr.*, pa.unit_cost FROM part_requests pr JOIN parts pa ON pa.id=pr.part_id WHERE pr.id=? FOR UPDATE', [$reqId]);
            must($r && $r['status'] === 'Issued' && (int)$r['vehicle_id'] === (int)$j['vehicle_id'], 'Choose an issued parts request for the same truck.', 409);
            $used = (int)one($p, 'SELECT COALESCE(SUM(quantity),0) AS q FROM maintenance_parts_used WHERE part_request_id=?', [$reqId])['q'];
            must($used + $qty <= (int)$r['requested_qty'], 'Only ' . ((int)$r['requested_qty'] - $used) . ' left from this issued request.', 409);
            $id = insert($p, 'maintenance_parts_used', ['maintenance_job_id' => $jobId, 'part_request_id' => $reqId, 'part_id' => $r['part_id'],
                'quantity' => $qty, 'unit_cost' => $r['unit_cost'], 'recorded_by' => $u['id']]);
            audit($p, $u['id'], 'Maintenance', $j['job_number'], 'part used', 'request ' . $reqId . ' x' . $qty);
            return ['usage_id' => $id];
        });
    }

    /** Repair costs (labor, outside service) -> also a Maintenance expense for Finance. */
    private static function costAdd(array $u, array $d): array {
        $jobId = idField($d, 'job_id');
        $row = ['cost_type' => choiceField($d, 'cost_type', ['Labor', 'Outside Service', 'Materials', 'Other']),
                'description' => field($d, 'description', 255), 'provider' => optField($d, 'provider', 150),
                'amount' => moneyField($d, 'amount'), 'incurred_on' => dateField($d, 'incurred_on')];
        return transaction(function (PDO $p) use ($u, $jobId, $row) {
            $j = one($p, 'SELECT * FROM maintenance_jobs WHERE id=?', [$jobId]);
            must((bool)$j, 'Job not found.', 404);
            $id = insert($p, 'maintenance_costs', ['maintenance_job_id' => $jobId, 'recorded_by' => $u['id']] + $row);
            insert($p, 'expenses', ['category' => 'Maintenance', 'vehicle_id' => $j['vehicle_id'], 'trip_id' => $j['trip_id'], 'amount' => $row['amount'],
                'incurred_on' => $row['incurred_on'], 'payee' => $row['provider'], 'description' => $j['job_number'] . ' ' . $row['cost_type'] . ': ' . $row['description'],
                'source' => 'Maintenance', 'source_id' => $id, 'recorded_by' => $u['id']]);
            audit($p, $u['id'], 'Maintenance', $j['job_number'], 'cost recorded', $row['cost_type'] . ' PHP ' . $row['amount']);
            return ['cost_id' => $id, 'message' => 'Cost recorded and sent to Finance as a Maintenance expense.'];
        });
    }

    private static function scheduleAdd(array $u, array $d): array {
        $vid = idField($d, 'vehicle_id'); $type = field($d, 'maintenance_type', 100);
        $due = dateField($d, 'due_on', false); $km = intField($d, 'due_odometer_km', 0, false);
        must($due !== null || $km !== null, 'Enter a due date or a due odometer.');
        $notes = optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $vid, $type, $due, $km, $notes) {
            must((bool)one($p, 'SELECT id FROM vehicles WHERE id=?', [$vid]), 'Truck not found.');
            $id = insert($p, 'maintenance_schedules', ['vehicle_id' => $vid, 'maintenance_type' => $type, 'due_on' => $due, 'due_odometer_km' => $km, 'notes' => $notes, 'created_by' => $u['id']]);
            audit($p, $u['id'], 'Maintenance', 'schedule ' . $id, 'maintenance scheduled', $type . ' ' . ($due ?? '') . ' ' . ($km ? $km . ' km' : ''));
            return ['schedule_id' => $id];
        });
    }

    /** Start a scheduled service: creates the job and puts the truck under maintenance. */
    private static function scheduleStart(array $u, array $d): array {
        $id = idField($d, 'schedule_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $s = one($p, 'SELECT s.*, v.plate_number, v.odometer_km FROM maintenance_schedules s JOIN vehicles v ON v.id=s.vehicle_id WHERE s.id=? FOR UPDATE', [$id]);
            must($s && $s['status'] === 'Scheduled' && !$s['maintenance_job_id'], 'Schedule is not open.', 409);
            must(!FleetState::activeTrip($p, (int)$s['vehicle_id']), 'Truck is held by a trip. Wait for the trip to end or ask Operations to reassign.', 409);
            $type = stripos($s['maintenance_type'], 'prevent') !== false ? 'Preventive Maintenance' : 'Repair';
            $jobId = self::newJob($p, ['vehicle_id' => $s['vehicle_id'], 'source' => 'Preventive', 'maintenance_type' => $type,
                'description' => $s['maintenance_type'] . ($s['notes'] ? ': ' . $s['notes'] : ''), 'status' => 'In Progress', 'started_at' => date('Y-m-d H:i:s'),
                'odometer_at_service' => $s['odometer_km'], 'created_by' => $u['id']]);
            run($p, 'UPDATE maintenance_schedules SET maintenance_job_id=? WHERE id=?', [$jobId, $id]);
            FleetState::setStatus($p, (int)$s['vehicle_id'], 'Under Maintenance', $u['id'], 'Scheduled service started');
            audit($p, $u['id'], 'Maintenance', refNumber('JOB', $jobId), 'scheduled service started', $s['plate_number']);
            return ['job_id' => $jobId, 'message' => 'Service started as job ' . refNumber('JOB', $jobId) . '.'];
        });
    }

    private static function scheduleCancel(array $u, array $d): array {
        $id = idField($d, 'schedule_id'); $reason = field($d, 'reason', 200);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            must(run($p, "UPDATE maintenance_schedules SET status='Cancelled', notes=CONCAT(COALESCE(notes,''), ' | Cancelled: ', ?) WHERE id=? AND status='Scheduled' AND maintenance_job_id IS NULL", [$reason, $id]) === 1, 'Schedule cannot be cancelled.', 409);
            audit($p, $u['id'], 'Maintenance', 'schedule ' . $id, 'schedule cancelled', $reason);
            return ['schedule_id' => $id];
        });
    }
}
