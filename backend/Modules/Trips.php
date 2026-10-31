<?php
/*
 * =====================================================================
 *  backend/Modules/Trips.php  —  OPERATIONS MODULE (trip and dispatch)
 * =====================================================================
 *  Source: FMS PDF II.D, II.E, II.F, II.G and pp.16-18 (Operation module).
 *
 *  SCENARIO A (client -> Operations Head)
 *    Ops Head encodes delivery info + truck type + dispatcher type
 *    -> system auto-assigns truck, driver, helper
 *    -> system computes the allowance and sends the fund request
 *
 *  SCENARIO B (client -> Dispatcher)
 *    Dispatcher encodes the request -> Ops Head sets truck type,
 *    dispatcher type and other details (approval) -> same as above.
 *
 *  THEN
 *    Dispatcher pre-departure checklist (57 items) -> "Ready for Dispatch"
 *    -> fund becomes "Ready for Release" -> Finance releases
 *    -> Ops Head departure clearance -> Dispatcher confirms dispatch
 *    -> progress / delay / incident updates -> arrival checklist
 *    -> dispatcher submits completed trip.
 *
 *  INCIDENT
 *    Minor: roadside repair job for Motorpool, trip continues.
 *    Major: trip "Rescheduled", truck "Under Maintenance". After Motorpool
 *    completes the repair, dispatcher re-dispatches as 2nd attempt.
 * =====================================================================
 */
declare(strict_types=1);
core('FleetState');
core('Settings');
core('Notify');
core('TripFunds');

final class Trips {
    public const STATUSES = ['Pending Approval', 'Awaiting Resources', 'Assigned', 'Ready for Dispatch', 'In Progress',
                             'Returned', 'Completed', 'Rescheduled', 'Cancelled', 'Rejected'];
    public const UPDATE_TYPES = ['Progress', 'Loading', 'Unloading', 'Delivery', 'Delay', 'Other'];
    public const DELAY_CAUSES = ['Client', 'Pending Documents', 'Operational', 'Traffic', 'Weather', 'Other'];

    // =================================================================
    // READ
    // =================================================================
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        [$scopeSql, $scopeArgs] = self::scope($u);
        if ($page === 'trips') {
            $data = readSections([
                'trips' => fn() => many($p, self::tripSelect() . " WHERE t.status NOT IN ('Completed','Cancelled','Rejected') $scopeSql ORDER BY t.scheduled_at, t.id", $scopeArgs),
                'recentDone' => fn() => many($p, self::tripSelect() . " WHERE t.status IN ('Completed','Cancelled','Rejected') $scopeSql ORDER BY t.id DESC LIMIT 30", $scopeArgs),
                'inspections' => fn() => many($p, "SELECT i.id, i.trip_id, i.attempt_number, i.inspection_type, i.status, i.items_json, i.odometer_km, i.fuel_level, i.has_not_ok, i.started_at, i.completed_at, u.full_name AS actor
                    FROM inspections i JOIN trips t ON t.id=i.trip_id LEFT JOIN users u ON u.id=i.actor_id
                    WHERE t.status NOT IN ('Completed','Cancelled','Rejected') $scopeSql ORDER BY i.id", $scopeArgs),
                'updates' => fn() => many($p, "SELECT up.*, us.full_name AS actor FROM trip_updates up JOIN trips t ON t.id=up.trip_id JOIN users us ON us.id=up.actor_id
                    WHERE t.status NOT IN ('Completed','Cancelled','Rejected') $scopeSql ORDER BY up.id DESC LIMIT 300", $scopeArgs),
                'events' => fn() => many($p, "SELECT e.*, us.full_name AS actor FROM trip_events e JOIN trips t ON t.id=e.trip_id LEFT JOIN users us ON us.id=e.actor_id
                    WHERE t.status NOT IN ('Completed','Cancelled','Rejected') $scopeSql ORDER BY e.id DESC LIMIT 400", $scopeArgs),
                'clients' => fn() => many($p, 'SELECT id, name, company_name FROM clients WHERE active=1 ORDER BY name'),
                'routes' => fn() => many($p, 'SELECT id, origin, destination, distance_km, toll_fee FROM routes WHERE active=1 ORDER BY origin, destination'),
                'vehicles' => fn() => many($p, 'SELECT id, plate_number, truck_number, truck_type, status, km_since_pm FROM vehicles WHERE active=1 ORDER BY truck_type, plate_number'),
                'crew' => fn() => many($p, "SELECT id, full_name, person_type, availability, license_expiry FROM personnel WHERE employment_status='Active' AND person_type IN ('Driver','Helper') ORDER BY full_name"),
            ]);
            require_once __DIR__ . '/Inspections/Checklist.php';
            $data['checklist'] = class_exists('Checklist') ? Checklist::sections() : [];
            $data['minMinutes'] = Settings::value('inspection_min_minutes');
            $data['unitsPerTrip'] = Settings::value('units_per_trip');
            $data['updateTypes'] = self::UPDATE_TYPES;
            $data['delayCauses'] = self::DELAY_CAUSES;
            $data['can'] = [
                'create' => hasRole($u, 'operations_head') || roleIsDispatcher($u['role']),
                'review' => hasRole($u, 'operations_head'), 'clear' => hasRole($u, 'operations_head'),
                'dispatch' => roleIsDispatcher($u['role']), 'cancel' => hasRole($u, 'operations_head'),
                'reassign' => hasRole($u, 'operations_head'),
            ];
            return $data;
        }
        if ($page === 'trip_records') {
            [$where, $args] = self::filters($q);
            // Billing and Payroll only need finished trips; Audit only trips with money records.
            if (hasRole($u, 'billing', 'payroll')) $where .= " AND t.status='Completed'";
            if (hasRole($u, 'audit')) $where .= ' AND (EXISTS(SELECT 1 FROM fund_requests f WHERE f.trip_id=t.id) OR EXISTS(SELECT 1 FROM billing_items b WHERE b.trip_id=t.id))';
            $where .= $scopeSql; $args = array_merge($args, $scopeArgs);
            $data = readSections([
                'trips' => fn() => many($p, self::tripSelect() . " WHERE 1=1 $where ORDER BY t.scheduled_at DESC, t.id DESC LIMIT 1000", $args),
                'waybills' => fn() => many($p, "SELECT w.*, t.trip_ref, us.full_name AS recorded_by_name FROM waybills w JOIN trips t ON t.id=w.trip_id
                    JOIN users us ON us.id=w.recorded_by WHERE 1=1 $where ORDER BY w.id DESC LIMIT 1500", $args),
                'updates' => fn() => hasRole($u, 'billing', 'payroll', 'audit') ? [] : many($p, "SELECT up.*, t.trip_ref, us.full_name AS actor FROM trip_updates up
                    JOIN trips t ON t.id=up.trip_id JOIN users us ON us.id=up.actor_id WHERE 1=1 $where ORDER BY up.id DESC LIMIT 1500", $args),
                'events' => fn() => many($p, "SELECT e.*, t.trip_ref, us.full_name AS actor FROM trip_events e JOIN trips t ON t.id=e.trip_id
                    LEFT JOIN users us ON us.id=e.actor_id WHERE 1=1 $where ORDER BY e.id DESC LIMIT 2000", $args),
                'inspections' => fn() => hasRole($u, 'billing', 'payroll', 'audit') ? [] : many($p, "SELECT i.id, i.trip_id, i.attempt_number, i.inspection_type, i.status, i.items_json, i.odometer_km, i.fuel_level, i.has_not_ok, i.started_at, i.completed_at, us.full_name AS actor
                    FROM inspections i JOIN trips t ON t.id=i.trip_id LEFT JOIN users us ON us.id=i.actor_id WHERE 1=1 $where ORDER BY i.id DESC LIMIT 1000", $args),
                'clients' => fn() => many($p, 'SELECT id, name, company_name FROM clients ORDER BY name'),
                'vehicles' => fn() => many($p, 'SELECT id, plate_number, truck_type FROM vehicles ORDER BY plate_number'),
                'crew' => fn() => many($p, "SELECT id, full_name, person_type FROM personnel WHERE person_type IN ('Driver','Helper') ORDER BY full_name"),
                'dispatchers' => fn() => many($p, "SELECT id, full_name, role FROM users WHERE role IN ('car_carrier_dispatcher','container_wingvan_dispatcher') ORDER BY full_name"),
            ]);
            require_once __DIR__ . '/Inspections/Checklist.php';
            $data['checklist'] = class_exists('Checklist') ? Checklist::sections() : [];
            $data['statuses'] = self::STATUSES;
            $data['can'] = ['waybill' => roleIsDispatcher($u['role']) || hasRole($u, 'admin', 'billing')];
            return $data;
        }
        // incidents
        $data = readSections([
            'incidents' => fn() => many($p, "SELECT i.*, t.trip_ref, t.status AS trip_status, t.attempt_number AS trip_attempt, v.plate_number, us.full_name AS reported_by_name,
                    m.id AS job_id, m.job_number, m.status AS job_status, m.expected_completion, c.name AS client
                FROM incidents i JOIN trips t ON t.id=i.trip_id JOIN vehicles v ON v.id=i.vehicle_id JOIN clients c ON c.id=t.client_id
                JOIN users us ON us.id=i.reported_by LEFT JOIN maintenance_jobs m ON m.incident_id=i.id
                WHERE 1=1 $scopeSql ORDER BY i.id DESC LIMIT 500", $scopeArgs),
        ]);
        return $data;
    }

    /** Dispatchers only see trips of their own dispatcher type or that they encoded. */
    private static function scope(array $u): array {
        if (roleIsDispatcher($u['role'])) return [' AND (t.dispatcher_role=? OR t.created_by=?)', [$u['role'], $u['id']]];
        return ['', []];
    }

    private static function tripSelect(): string {
        return "SELECT t.*, c.name AS client, c.company_name, v.plate_number, v.truck_number, v.status AS vehicle_status,
                d1.full_name AS driver_name, d2.full_name AS driver2_name, hp.full_name AS helper_name,
                ds.full_name AS dispatcher_name, cr.full_name AS created_by_name, oc.full_name AS cleared_by_name,
                (SELECT f.status FROM fund_requests f WHERE f.trip_id=t.id AND f.status NOT IN ('Rejected','Cancelled') ORDER BY f.id DESC LIMIT 1) AS fund_status,
                (SELECT f.amount FROM fund_requests f WHERE f.trip_id=t.id AND f.status NOT IN ('Rejected','Cancelled') ORDER BY f.id DESC LIMIT 1) AS fund_amount,
                (SELECT f.id FROM fund_requests f WHERE f.trip_id=t.id ORDER BY f.id DESC LIMIT 1) AS fund_id,
                (SELECT f.status FROM fund_requests f WHERE f.trip_id=t.id ORDER BY f.id DESC LIMIT 1) AS last_fund_status,
                (SELECT COUNT(*) FROM waybills w WHERE w.trip_id=t.id) AS waybill_count,
                (SELECT GROUP_CONCAT(w.waybill_number SEPARATOR ', ') FROM waybills w WHERE w.trip_id=t.id) AS waybill_numbers,
                (SELECT COUNT(*) FROM trip_updates up WHERE up.trip_id=t.id AND up.update_type='Delay') AS delay_count,
                (SELECT COUNT(*) FROM incidents i WHERE i.trip_id=t.id) AS incident_count,
                (SELECT b.invoice_number FROM billing_items bi JOIN billings b ON b.id=bi.billing_id WHERE bi.trip_id=t.id LIMIT 1) AS invoice_number
            FROM trips t
            JOIN clients c ON c.id=t.client_id
            LEFT JOIN vehicles v ON v.id=t.vehicle_id
            LEFT JOIN personnel d1 ON d1.id=t.driver_id
            LEFT JOIN personnel d2 ON d2.id=t.driver2_id
            LEFT JOIN personnel hp ON hp.id=t.helper_id
            LEFT JOIN users ds ON ds.id=t.dispatcher_id
            LEFT JOIN users cr ON cr.id=t.created_by
            LEFT JOIN users oc ON oc.id=t.ops_cleared_by";
    }

    /** Search filters (PDF II.F and V.59: trip number, date, client, truck, driver, dispatcher). */
    private static function filters(array $q): array {
        $w = ''; $a = [];
        $from = trim((string)($q['from'] ?? '')); $to = trim((string)($q['to'] ?? ''));
        if ($from !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $from)) { $w .= ' AND DATE(t.scheduled_at)>=?'; $a[] = $from; }
        if ($to !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $to)) { $w .= ' AND DATE(t.scheduled_at)<=?'; $a[] = $to; }
        foreach (['client_id' => 't.client_id', 'vehicle_id' => 't.vehicle_id', 'dispatcher_id' => 't.dispatcher_id'] as $k => $col) {
            if (!empty($q[$k]) && ctype_digit((string)$q[$k])) { $w .= " AND $col=?"; $a[] = (int)$q[$k]; }
        }
        if (!empty($q['driver_id']) && ctype_digit((string)$q['driver_id'])) {
            $w .= ' AND (t.driver_id=? OR t.driver2_id=? OR t.helper_id=?)'; $id = (int)$q['driver_id']; array_push($a, $id, $id, $id);
        }
        if (!empty($q['status']) && in_array($q['status'], self::STATUSES, true)) { $w .= ' AND t.status=?'; $a[] = $q['status']; }
        if (!empty($q['truck_type']) && in_array($q['truck_type'], TRUCK_TYPES, true)) { $w .= ' AND t.truck_type=?'; $a[] = $q['truck_type']; }
        $text = trim((string)($q['q'] ?? ''));
        if ($text !== '') {
            $w .= ' AND (t.trip_ref LIKE ? OR t.origin LIKE ? OR t.destination LIKE ? OR t.booking_reference LIKE ? OR t.container_number LIKE ? OR EXISTS(SELECT 1 FROM waybills wx WHERE wx.trip_id=t.id AND wx.waybill_number LIKE ?))';
            $like = '%' . $text . '%'; array_push($a, $like, $like, $like, $like, $like, $like);
        }
        return [$w, $a];
    }

    // =================================================================
    // ACTIONS
    // =================================================================
    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'create' => self::create($u, $d),
            'review' => self::review($u, $d),
            'auto_assign' => self::retryAssign($u, $d),
            'reassign' => self::reassign($u, $d),
            'details' => self::details($u, $d),
            'checklist_start' => self::checklistStart($u, $d),
            'checklist_departure' => self::checklistDeparture($u, $d),
            'clear' => self::clear($u, $d),
            'dispatch' => self::dispatch($u, $d),
            'progress' => self::progress($u, $d),
            'incident' => self::incident($u, $d),
            'arrival' => self::arrival($u, $d),
            'complete' => self::complete($u, $d),
            'second_attempt' => self::secondAttempt($u, $d),
            'cancel' => self::cancel($u, $d),
            'waybill_add' => self::waybillAdd($u, $d),
            default => throw new BusinessRuleException('Unknown trip action.', 404),
        };
    }

    // ---------- helpers ----------
    private static function lockTrip(PDO $p, int $id): array {
        $t = one($p, 'SELECT * FROM trips WHERE id=? FOR UPDATE', [$id]);
        must((bool)$t, 'Trip not found.', 404);
        return $t;
    }
    /** Only dispatchers of the trip's dispatcher type may run dispatcher steps (Day/Night shifts share). */
    private static function mustBeTripDispatcher(array $u, array $t): void {
        must(roleIsDispatcher($u['role']), 'Only the dispatcher can do this step.', 403);
        must($t['dispatcher_role'] === $u['role'], 'This trip belongs to the ' . (ROLE_LABELS[$t['dispatcher_role']] ?? 'other') . ' team.', 403);
    }
    private static function event(PDO $p, ?int $uid, int $tripId, string $type, string $from, string $to, string $notes = ''): void {
        $p->prepare('INSERT INTO trip_events (trip_id, actor_id, event_type, from_status, to_status, notes) VALUES (?,?,?,?,?,?)')
          ->execute([$tripId, $uid, $type, $from, $to, cut($notes, 500)]);
        audit($p, $uid, 'Operations', 'trip ' . $tripId, $type, ($from !== $to ? "$from -> $to. " : '') . $notes);
    }
    private static function setStatus(PDO $p, ?int $uid, array $t, string $to, string $type, string $notes = ''): void {
        run($p, 'UPDATE trips SET status=? WHERE id=?', [$to, $t['id']]);
        self::event($p, $uid, (int)$t['id'], $type, $t['status'], $to, $notes);
    }
    /** Active fund request of the trip (not rejected / cancelled). */
    private static function activeFund(PDO $p, int $tripId): ?array {
        return one($p, "SELECT * FROM fund_requests WHERE trip_id=? AND status NOT IN ('Rejected','Cancelled') ORDER BY id DESC LIMIT 1 FOR UPDATE", [$tripId]);
    }

    /** Client rate for this trip (Billing: client-specific and route-based rates). */
    private static function clientRate(PDO $p, array $t): ?float {
        if (!$t['truck_type']) return null;
        $r = one($p, "SELECT amount, rate_basis FROM client_rates WHERE client_id=? AND truck_type=? AND status='Active'
                AND (route_id=? OR route_id IS NULL) AND (effective_on IS NULL OR effective_on<=DATE(?))
              ORDER BY (route_id IS NULL), effective_on DESC, id DESC LIMIT 1",
            [$t['client_id'], $t['truck_type'], $t['route_id'], $t['scheduled_at']]);
        if (!$r) return null;
        $amount = (float)$r['amount'];
        if ($r['rate_basis'] === 'Per Unit') $amount *= max(1, (int)($t['units_planned'] ?? 1));
        if ($r['rate_basis'] === 'Per KM') $amount *= (float)($t['distance_km'] ?? 0);
        return round($amount, 2);
    }

    // ---------- create (Scenario A / B) ----------
    private static function create(array $u, array $d): array {
        must(hasRole($u, 'operations_head') || roleIsDispatcher($u['role']), 'Only the Operations Head or a dispatcher can create a trip.', 403);
        $isOps = hasRole($u, 'operations_head');
        $clientId = idField($d, 'client_id');
        $routeId = optId($d, 'route_id');
        $scheduled = dateTimeField($d, 'scheduled_at');
        $row = [
            'client_id' => $clientId, 'route_id' => $routeId, 'scheduled_at' => $scheduled,
            'booking_reference' => optField($d, 'booking_reference', 150),
            'units_planned' => intField($d, 'units_planned', 1, false),
            'container_number' => optField($d, 'container_number', 60),
            'container_size' => optField($d, 'container_size', 20),
            'seal_number' => optField($d, 'seal_number', 60),
            'shipment_number' => optField($d, 'shipment_number', 60),
            'remarks' => optField($d, 'remarks', 500),
        ];
        // Dispatcher type follows the dispatcher's own role; Ops Head picks the truck type (PDF p.16).
        if ($isOps) {
            $row['truck_type'] = choiceField($d, 'truck_type', TRUCK_TYPES);
        } else {
            $type = choiceField($d, 'truck_type', TRUCK_TYPES, false);
            if ($type) {
                must(dispatcherRoleFor($type) === $u['role'], 'This truck type is handled by the other dispatcher team.');
            }
            $row['truck_type'] = $type;
        }
        return transaction(function (PDO $p) use ($u, $d, $row, $isOps) {
            must((bool)one($p, 'SELECT id FROM clients WHERE id=? AND active=1', [$row['client_id']]), 'Client not found.');
            if ($row['route_id']) {
                $r = one($p, 'SELECT * FROM routes WHERE id=? AND active=1', [$row['route_id']]);
                must((bool)$r, 'Route not found.');
                $row['origin'] = $r['origin']; $row['destination'] = $r['destination']; $row['distance_km'] = $r['distance_km'];
            } else {
                $row['origin'] = field($d, 'origin', 200); $row['destination'] = field($d, 'destination', 200);
                $row['distance_km'] = numberField($d, 'distance_km', false);
            }
            if ($row['truck_type'] === 'Car Carrier' && !$row['units_planned']) $row['units_planned'] = (int)Settings::value('units_per_trip');
            $row['trip_ref'] = 'TMP-' . bin2hex(random_bytes(6));
            $row['created_by'] = $u['id'];
            $row['intake_source'] = $isOps ? 'Scenario A: Client to Operations Head' : 'Scenario B: Client to Dispatcher';
            $row['dispatcher_role'] = $isOps ? dispatcherRoleFor($row['truck_type']) : $u['role'];
            $row['dispatcher_id'] = $isOps ? null : $u['id'];
            $row['status'] = 'Pending Approval';
            $id = insert($p, 'trips', $row);
            $ref = refNumber('TRP', $id);
            run($p, 'UPDATE trips SET trip_ref=? WHERE id=?', [$ref, $id]);
            self::event($p, $u['id'], $id, 'trip request encoded', '', 'Pending Approval', $row['intake_source']);
            if ($isOps) {
                // Scenario A: Ops Head already decided -> approve and auto-assign right away.
                $t = self::lockTrip($p, $id);
                run($p, 'UPDATE trips SET approved_by=?, approved_at=NOW() WHERE id=?', [$u['id'], $id]);
                $t['approved_by'] = $u['id'];
                $result = self::approveAndAssign($p, $u, $t);
                return ['trip_id' => $id, 'trip_ref' => $ref] + $result;
            }
            Notify::roles($p, ['operations_head'], 'Trip request', "New trip request $ref from dispatcher " . $u['full_name'] . ' needs your review.', 'trips');
            return ['trip_id' => $id, 'trip_ref' => $ref, 'message' => 'Trip request sent to the Operations Head.'];
        });
    }

    // ---------- Ops Head review (Scenario B) ----------
    private static function review(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'trip_id');
        $decision = choiceField($d, 'decision', ['approve', 'reject']);
        return transaction(function (PDO $p) use ($u, $d, $id, $decision) {
            $t = self::lockTrip($p, $id);
            must($t['status'] === 'Pending Approval', 'This trip is not waiting for approval.', 409);
            if ($decision === 'reject') {
                $reason = field($d, 'reason', 300);
                run($p, 'UPDATE trips SET cancel_reason=? WHERE id=?', [$reason, $id]);
                self::setStatus($p, $u['id'], $t, 'Rejected', 'trip request rejected', $reason);
                Notify::user($p, (int)$t['created_by'], 'Trip request', $t['trip_ref'] . ' was rejected: ' . $reason, 'trips');
                return ['trip_id' => $id, 'message' => 'Trip request rejected.'];
            }
            // Ops Head sets truck type, dispatcher type and other details (PDF p.17).
            $type = choiceField($d, 'truck_type', TRUCK_TYPES);
            $sched = !empty($d['scheduled_at']) ? dateTimeField($d, 'scheduled_at') : $t['scheduled_at'];
            $units = intField($d, 'units_planned', 1, false) ?? ($t['units_planned'] ? (int)$t['units_planned'] : null);
            if ($type === 'Car Carrier' && !$units) $units = (int)Settings::value('units_per_trip');
            $dispRole = dispatcherRoleFor($type);
            run($p, 'UPDATE trips SET truck_type=?, dispatcher_role=?, scheduled_at=?, units_planned=?, approved_by=?, approved_at=NOW() WHERE id=?',
                [$type, $dispRole, $sched, $units, $u['id'], $id]);
            $t = self::lockTrip($p, $id);
            return ['trip_id' => $id] + self::approveAndAssign($p, $u, $t);
        });
    }

    /** After approval: auto-assign resources and create the fund request. */
    private static function approveAndAssign(PDO $p, array $u, array $t): array {
        $rate = self::clientRate($p, $t);
        if ($rate !== null) run($p, 'UPDATE trips SET client_rate=? WHERE id=?', [$rate, $t['id']]);
        $res = self::autoAssign($p, $t);
        if (!$res['ok']) {
            self::setStatus($p, $u['id'], $t, 'Awaiting Resources', 'trip approved; waiting for resources', $res['reason']);
            Notify::roles($p, ['operations_head', $t['dispatcher_role']], 'Trip', $t['trip_ref'] . ' approved but ' . $res['reason'] . ' Use "Try auto-assign again" or assign manually.', 'trips');
            return ['message' => 'Trip approved. ' . $res['reason'] . ' It is marked "Awaiting Resources".'];
        }
        self::applyAssignment($p, $u['id'], $t, $res, 'Auto');
        $fund = TripFunds::create($p, self::lockTrip($p, (int)$t['id']));
        Notify::roles($p, [$t['dispatcher_role']], 'Trip assigned', $t['trip_ref'] . ' is pre-assigned to truck ' . $res['plate'] . '. Prepare the pre-departure checklist.', 'trips');
        return ['message' => 'Trip approved and auto-assigned to ' . $res['plate'] . '. Fund request of PHP ' . number_format($fund['amount'], 2) . ' sent for approval.'];
    }

    /**
     * AUTOMATIC ASSIGNMENT (PDF pp.16-17 "si system ang magaasign").
     * TODO PENDING COMPANY CONFIRMATION (C01): the PDF gives no priority rule.
     * Default rule used (documented, editable look-back in Formula Settings):
     *   Truck : same truck type, not under maintenance, not booked that day,
     *           prefer one that will not reach the PM km limit on this trip,
     *           then fewest trips in the last N days, then lowest km since PM.
     *   Driver: the truck's assigned driver if free; otherwise an active
     *           driver with a valid license, free that day, fewest recent trips.
     *   Car Carrier: a 2nd driver is added when one is free (monitoring
     *           sheet has DRIVER 1 and DRIVER 2).
     *   Container / Wing Van: a helper is required.
     */
    private static function autoAssign(PDO $p, array $t, ?int $keepVehicle = null): array {
        $date = substr($t['scheduled_at'], 0, 10);
        $sameDay = $date <= date('Y-m-d');
        $look = (int)Settings::value('auto_assign_lookback_days');
        $pmLimit = Settings::value('pm_interval_km');
        $km = (float)($t['distance_km'] ?? 0);
        $holding = "'Assigned','Ready for Dispatch','In Progress','Returned'";
        $vehStatuses = $sameDay ? "('Available')" : "('Available','Assigned','On Trip')";
        $sql = "SELECT v.* , (SELECT COUNT(*) FROM trips x WHERE x.vehicle_id=v.id AND x.status NOT IN ('Cancelled','Rejected') AND x.scheduled_at>=DATE_SUB(NOW(), INTERVAL ? DAY)) AS recent
                FROM vehicles v WHERE v.active=1 AND v.truck_type=? AND v.status IN $vehStatuses
                AND NOT EXISTS (SELECT 1 FROM maintenance_jobs m WHERE m.vehicle_id=v.id AND m.status NOT IN ('Completed','Cancelled') AND m.maintenance_type<>'Roadside Repair')
                AND NOT EXISTS (SELECT 1 FROM trips x WHERE x.vehicle_id=v.id AND x.id<>? AND x.status IN ($holding) AND DATE(x.scheduled_at)=?)";
        $args = [$look, $t['truck_type'], $t['id'], $date];
        if ($keepVehicle) { $sql .= ' AND v.id=?'; $args[] = $keepVehicle; }
        $sql .= ' ORDER BY ((v.km_since_pm + ?) >= ?), recent, v.km_since_pm, v.id';
        $args[] = $km; $args[] = $pmLimit;
        $trucks = many($p, $sql, $args);
        if (!$trucks) return ['ok' => false, 'reason' => 'No ' . $t['truck_type'] . ' truck is available on ' . $date . '.'];

        $crewSql = function (string $type, array $exclude) use ($p, $t, $date, $sameDay, $look, $holding): array {
            $avail = $sameDay ? "pe.availability='Available'" : "pe.availability<>'Unavailable'";
            $ex = $exclude ? ' AND pe.id NOT IN (' . implode(',', array_map('intval', $exclude)) . ')' : '';
            return many($p, "SELECT pe.id, pe.full_name,
                    (SELECT COUNT(*) FROM trips x WHERE (x.driver_id=pe.id OR x.driver2_id=pe.id OR x.helper_id=pe.id) AND x.status NOT IN ('Cancelled','Rejected') AND x.scheduled_at>=DATE_SUB(NOW(), INTERVAL ? DAY)) AS recent
                FROM personnel pe WHERE pe.person_type=? AND pe.employment_status='Active' AND $avail $ex
                AND (pe.person_type<>'Driver' OR pe.license_expiry IS NULL OR pe.license_expiry>=?)
                AND NOT EXISTS (SELECT 1 FROM trips x WHERE x.id<>? AND x.status IN ($holding) AND DATE(x.scheduled_at)=? AND (x.driver_id=pe.id OR x.driver2_id=pe.id OR x.helper_id=pe.id))
                ORDER BY recent, pe.id", [$look, $type, $date, $t['id'], $date]);
        };
        foreach ($trucks as $v) {
            $drivers = $crewSql('Driver', []);
            if (!$drivers) return ['ok' => false, 'reason' => 'No driver with a valid license is free on ' . $date . '.'];
            $ids = array_column($drivers, 'id');
            $driver = in_array((int)$v['assigned_driver_id'], array_map('intval', $ids), true) ? (int)$v['assigned_driver_id'] : (int)$drivers[0]['id'];
            $driver2 = null; $helper = null;
            if ($t['truck_type'] === 'Car Carrier') {
                $others = array_values(array_filter($drivers, fn($x) => (int)$x['id'] !== $driver));
                $driver2 = $others ? (int)$others[0]['id'] : null;
            } else {
                $helpers = $crewSql('Helper', []);
                if (!$helpers) return ['ok' => false, 'reason' => 'No helper is free on ' . $date . '.'];
                $hids = array_map('intval', array_column($helpers, 'id'));
                $helper = in_array((int)$v['assigned_helper_id'], $hids, true) ? (int)$v['assigned_helper_id'] : $hids[0];
            }
            return ['ok' => true, 'vehicle_id' => (int)$v['id'], 'plate' => $v['plate_number'], 'driver_id' => $driver,
                    'driver2_id' => $driver2, 'helper_id' => $helper];
        }
        return ['ok' => false, 'reason' => 'No resources available.'];
    }

    private static function applyAssignment(PDO $p, ?int $uid, array $t, array $res, string $mode): void {
        // Lock rows in a fixed order to avoid two dispatchers taking the same truck.
        one($p, 'SELECT id FROM vehicles WHERE id=? FOR UPDATE', [$res['vehicle_id']]);
        run($p, 'UPDATE trips SET vehicle_id=?, driver_id=?, driver2_id=?, helper_id=?, assignment_mode=?, status=? WHERE id=?',
            [$res['vehicle_id'], $res['driver_id'], $res['driver2_id'], $res['helper_id'], $mode, 'Assigned', $t['id']]);
        $v = one($p, 'SELECT status FROM vehicles WHERE id=?', [$res['vehicle_id']]);
        if ($v['status'] === 'Available') FleetState::setStatus($p, $res['vehicle_id'], 'Assigned', $uid, 'Assigned to ' . $t['trip_ref']);
        self::event($p, $uid, (int)$t['id'], $mode === 'Auto' ? 'system auto-assigned resources' : 'resources assigned manually',
            $t['status'], 'Assigned', 'Truck ' . $res['plate'] . '; driver #' . $res['driver_id'] . ($res['driver2_id'] ? '; driver 2 #' . $res['driver2_id'] : '') . ($res['helper_id'] ? '; helper #' . $res['helper_id'] : ''));
    }

    private static function retryAssign(array $u, array $d): array {
        must(hasRole($u, 'operations_head') || roleIsDispatcher($u['role']), 'Not allowed.', 403);
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            if (roleIsDispatcher($u['role'])) self::mustBeTripDispatcher($u, $t);
            must($t['status'] === 'Awaiting Resources', 'Trip is not waiting for resources.', 409);
            $res = self::autoAssign($p, $t);
            must($res['ok'], $res['reason'] ?? 'No resources.', 409);
            self::applyAssignment($p, $u['id'], $t, $res, 'Auto');
            $msg = 'Auto-assigned to ' . $res['plate'] . '.';
            if (!self::activeFund($p, $id)) { $f = TripFunds::create($p, self::lockTrip($p, $id)); $msg .= ' Fund request PHP ' . number_format($f['amount'], 2) . ' sent.'; }
            Notify::roles($p, [$t['dispatcher_role']], 'Trip assigned', $t['trip_ref'] . ' is pre-assigned to truck ' . $res['plate'] . '.', 'trips');
            return ['trip_id' => $id, 'message' => $msg];
        });
    }

    /** Ops Head manual override of the auto-assignment (PDF: Ops Head approves truck and driver assignments). */
    private static function reassign(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'trip_id'); $vid = idField($d, 'vehicle_id'); $driver = idField($d, 'driver_id');
        $driver2 = optId($d, 'driver2_id'); $helper = optId($d, 'helper_id');
        $reason = field($d, 'reason', 255);
        return transaction(function (PDO $p) use ($u, $id, $vid, $driver, $driver2, $helper, $reason) {
            $t = self::lockTrip($p, $id);
            must(in_array($t['status'], ['Awaiting Resources', 'Assigned', 'Rescheduled'], true), 'Resources can only be changed before the checklist is done.', 409);
            $ids = array_filter([$driver, $driver2, $helper]);
            must(count($ids) === count(array_unique($ids)), 'The same person was chosen twice.');
            $v = one($p, 'SELECT * FROM vehicles WHERE id=? AND active=1 FOR UPDATE', [$vid]);
            must($v && $v['truck_type'] === $t['truck_type'], 'Choose an active ' . $t['truck_type'] . ' truck.');
            must(!in_array($v['status'], ['Under Maintenance', 'Unavailable'], true), 'Truck is ' . $v['status'] . '.', 409);
            $date = substr($t['scheduled_at'], 0, 10);
            $clash = one($p, "SELECT trip_ref FROM trips WHERE id<>? AND status IN ('Assigned','Ready for Dispatch','In Progress','Returned') AND DATE(scheduled_at)=? AND (vehicle_id=? OR driver_id IN (?,?,?) OR driver2_id IN (?,?,?) OR helper_id IN (?,?,?)) LIMIT 1",
                [$id, $date, $vid, $driver, $driver2 ?? 0, $helper ?? 0, $driver, $driver2 ?? 0, $helper ?? 0, $driver, $driver2 ?? 0, $helper ?? 0]);
            must(!$clash, 'Double booking: truck or crew is already on ' . ($clash['trip_ref'] ?? '') . ' that day (PDF II.B).', 409);
            foreach ([[$driver, 'Driver'], [$driver2, 'Driver'], [$helper, 'Helper']] as [$pid, $type]) {
                if (!$pid) continue;
                $pe = one($p, "SELECT * FROM personnel WHERE id=? AND person_type=? AND employment_status='Active' FOR UPDATE", [$pid, $type]);
                must((bool)$pe, "Choose an active $type.");
                must($pe['availability'] !== 'Unavailable', $pe['full_name'] . ' is marked Unavailable.', 409);
                if ($type === 'Driver' && $pe['license_expiry']) must($pe['license_expiry'] >= $date, $pe['full_name'] . "'s license expires before the trip.", 409);
            }
            if ($t['truck_type'] !== 'Car Carrier') must((bool)$helper, 'A helper is required for ' . $t['truck_type'] . ' trips.');
            $oldVehicle = $t['vehicle_id'] ? (int)$t['vehicle_id'] : null;
            self::applyAssignment($p, $u['id'], $t, ['vehicle_id' => $vid, 'plate' => $v['plate_number'], 'driver_id' => $driver, 'driver2_id' => $driver2, 'helper_id' => $helper], 'Manual');
            if ($t['status'] === 'Rescheduled') run($p, "UPDATE trips SET status='Rescheduled' WHERE id=?", [$id]); // keep waiting for 2nd attempt
            if ($oldVehicle && $oldVehicle !== $vid) FleetState::reconcile($p, $oldVehicle, $u['id'], 'Released from ' . $t['trip_ref']);
            self::event($p, $u['id'], $id, 'assignment override reason', $t['status'], $t['status'], $reason);
            if ($t['status'] === 'Awaiting Resources' && !self::activeFund($p, $id)) TripFunds::create($p, self::lockTrip($p, $id));
            return ['trip_id' => $id, 'message' => 'Assignment updated.'];
        });
    }

    /** Booking / container / car carrier details and client coordination steps (PDF II.E Container and Wing Van). */
    private static function details(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $d, $id) {
            $t = self::lockTrip($p, $id);
            if (!hasRole($u, 'operations_head')) self::mustBeTripDispatcher($u, $t);
            must(!in_array($t['status'], ['Completed', 'Cancelled', 'Rejected'], true), 'Trip is already closed.', 409);
            $set = [
                'booking_reference' => optField($d, 'booking_reference', 150),
                'units_planned' => intField($d, 'units_planned', 1, false),
                'container_number' => optField($d, 'container_number', 60),
                'container_size' => optField($d, 'container_size', 20),
                'seal_number' => optField($d, 'seal_number', 60),
                'shipment_number' => optField($d, 'shipment_number', 60),
                'empty_container_note' => optField($d, 'empty_container_note', 255),
                'remarks' => optField($d, 'remarks', 500),
            ];
            $steps = [];
            foreach (['client_confirmed' => 'client_confirmed_at', 'pre_advice_sent' => 'pre_advice_sent_at', 'client_advised' => 'client_advised_at'] as $flag => $col) {
                if (!empty($d[$flag]) && !$t[$col]) { $set[$col] = date('Y-m-d H:i:s'); $steps[] = str_replace('_', ' ', $flag); }
            }
            $cols = implode(',', array_map(fn($c) => "$c=?", array_keys($set)));
            run($p, "UPDATE trips SET $cols WHERE id=?", array_merge(array_values($set), [$id]));
            self::event($p, $u['id'], $id, 'trip details updated', $t['status'], $t['status'], $steps ? 'Marked: ' . implode(', ', $steps) : '');
            return ['trip_id' => $id];
        });
    }

    // ---------- checklists ----------
    /** "Start checklist": records the start time used for the minimum duration (PDF II.G). */
    private static function checklistStart(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $type = choiceField($d, 'inspection_type', ['Departure', 'Arrival']);
        return transaction(function (PDO $p) use ($u, $id, $type) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must($type === 'Departure' ? $t['status'] === 'Assigned' : $t['status'] === 'In Progress',
                $type === 'Departure' ? 'Departure checklist is for an assigned trip.' : 'Arrival checklist is for a trip in progress.', 409);
            $existing = one($p, 'SELECT * FROM inspections WHERE trip_id=? AND attempt_number=? AND inspection_type=? FOR UPDATE', [$id, $t['attempt_number'], $type]);
            if ($existing && $existing['status'] === 'In Progress') return ['started_at' => $existing['started_at'], 'message' => 'Checklist already started at ' . $existing['started_at'] . '.'];
            if ($existing) {
                // Re-doing a checklist (e.g. after fixing a NOT OK item) starts a new timer.
                run($p, "UPDATE inspections SET status='In Progress', started_at=NOW(), completed_at=NULL, actor_id=? WHERE id=?", [$u['id'], $existing['id']]);
            } else {
                insert($p, 'inspections', ['trip_id' => $id, 'vehicle_id' => $t['vehicle_id'], 'attempt_number' => $t['attempt_number'],
                    'inspection_type' => $type, 'status' => 'In Progress', 'started_at' => date('Y-m-d H:i:s'), 'actor_id' => $u['id']]);
            }
            self::event($p, $u['id'], $id, strtolower($type) . ' checklist started', $t['status'], $t['status']);
            return ['started_at' => date('Y-m-d H:i:s'), 'message' => $type . ' checklist started.'];
        });
    }

    /** Validates the 57 items and the minimum duration; returns the saved inspection id. */
    private static function saveChecklist(PDO $p, array $u, array $t, string $type, array $d): array {
        require_once __DIR__ . '/Inspections/Checklist.php';
        $check = Checklist::validate($d);
        $km = intField($d, 'odometer_km', 0);
        $fuel = optField($d, 'fuel_level', 20);
        $ins = one($p, 'SELECT * FROM inspections WHERE trip_id=? AND attempt_number=? AND inspection_type=? FOR UPDATE', [$t['id'], $t['attempt_number'], $type]);
        must($ins && $ins['status'] === 'In Progress', 'Press "Start checklist" first.', 409);
        $min = (float)Settings::value('inspection_min_minutes');
        $elapsed = (time() - strtotime($ins['started_at'])) / 60;
        must($elapsed >= $min, sprintf('The checklist must take at least %d minutes (started %s; %.0f min so far).', $min, substr($ins['started_at'], 11, 5), floor($elapsed)), 409);
        run($p, "UPDATE inspections SET status='Completed', items_json=?, odometer_km=?, fuel_level=?, has_not_ok=?, completed_at=NOW(), actor_id=? WHERE id=?",
            [json_encode($check, JSON_UNESCAPED_UNICODE), $km, $fuel, $check['has_not_ok'] ? 1 : 0, $u['id'], $ins['id']]);
        return ['id' => (int)$ins['id'], 'km' => $km, 'has_not_ok' => $check['has_not_ok']];
    }

    /** Pre-departure checklist. All OK -> Ready for Dispatch and fund Ready for Release (PDF p.16). */
    private static function checklistDeparture(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $d, $id) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must($t['status'] === 'Assigned', 'Trip must be assigned.', 409);
            $res = self::saveChecklist($p, $u, $t, 'Departure', $d);
            if ($res['has_not_ok']) {
                self::event($p, $u['id'], $id, 'departure checklist has NOT OK items', 'Assigned', 'Assigned', 'Fix the items, then do the checklist again.');
                return ['trip_id' => $id, 'warning' => 'Checklist saved with NOT OK items. The trip cannot be Ready for Dispatch until all items are OK. Fix the items and start the checklist again.'];
            }
            run($p, 'UPDATE trips SET pre_odometer=?, dispatcher_id=COALESCE(dispatcher_id, ?) WHERE id=?', [$res['km'], $u['id'], $id]);
            self::setStatus($p, $u['id'], $t, 'Ready for Dispatch', 'pre-departure checklist completed', 'Odometer out: ' . $res['km']);
            $fund = self::activeFund($p, $id);
            $msg = 'Trip is Ready for Dispatch.';
            if ($fund && $fund['status'] === 'Pending Release') {
                run($p, "UPDATE fund_requests SET status='Ready for Release' WHERE id=?", [$fund['id']]);
                audit($p, null, 'Finance', 'fund ' . $fund['id'], 'fund ready for release', $t['trip_ref'] . ' checklist done');
                Notify::roles($p, ['finance'], 'Fund release', 'Fund for ' . $t['trip_ref'] . ' is Ready for Release.', 'funds');
                $msg .= ' Fund is now Ready for Release.';
            } elseif ($fund && in_array($fund['status'], ['Pending Approval', 'Approved'], true)) {
                $msg .= ' Fund is still ' . $fund['status'] . '; it becomes Ready for Release once Finance processes it.';
            }
            Notify::roles($p, ['operations_head'], 'Departure clearance', $t['trip_ref'] . ' passed the pre-departure checklist and needs your departure clearance.', 'trips');
            return ['trip_id' => $id, 'message' => $msg];
        });
    }

    /** Operations Head departure clearance (PDF II.D step 8). */
    private static function clear(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            must($t['status'] === 'Ready for Dispatch', 'The pre-departure checklist must be completed first.', 409);
            must(!$t['ops_cleared_at'], 'Already cleared.', 409);
            run($p, 'UPDATE trips SET ops_cleared_by=?, ops_cleared_at=NOW() WHERE id=?', [$u['id'], $id]);
            self::event($p, $u['id'], $id, 'departure clearance given', $t['status'], $t['status']);
            Notify::roles($p, [$t['dispatcher_role']], 'Departure clearance', $t['trip_ref'] . ' has departure clearance from the Operations Head.', 'trips');
            return ['trip_id' => $id, 'message' => 'Departure clearance recorded.'];
        });
    }

    /** Dispatcher confirms dispatch once fund is released (PDF p.16 "icconfirm dispatch na ni dispatcher"). */
    private static function dispatch(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must($t['status'] === 'Ready for Dispatch', 'Trip is not Ready for Dispatch.', 409);
            must((bool)$t['ops_cleared_at'], 'Waiting for the Operations Head departure clearance.', 409);
            $fund = self::activeFund($p, $id);
            if ((int)$t['attempt_number'] === 1 || !$fund || $fund['status'] !== 'Completed') {
                must($fund && $fund['status'] === 'Released', 'Waiting for Finance to release the trip fund.' . ($fund ? ' Current fund status: ' . $fund['status'] . '.' : ''), 409);
            }
            // PDF II.E: container needs confirmed booking + pre-advice; wing van needs confirmation + truck details given.
            if ($t['truck_type'] === 'Container') {
                must((bool)$t['client_confirmed_at'], 'Confirm the client nomination / booking first (Container step).', 409);
                must((bool)$t['pre_advice_sent_at'], 'Mark the pre-advice as sent to the client first (Container step).', 409);
            }
            if ($t['truck_type'] === 'Wing Van') {
                must((bool)$t['client_confirmed_at'], 'Confirm the trip with the client first (Wing Van step).', 409);
                must((bool)$t['client_advised_at'], 'Give the client the truck details and plate number first (Wing Van step).', 409);
            }
            $v = one($p, 'SELECT status, plate_number FROM vehicles WHERE id=? FOR UPDATE', [$t['vehicle_id']]);
            must($v && $v['status'] !== 'Under Maintenance' && $v['status'] !== 'Unavailable', 'Truck is ' . ($v['status'] ?? 'missing') . '.', 409);
            must(!one($p, "SELECT id FROM trips WHERE vehicle_id=? AND id<>? AND status IN ('In Progress','Returned')", [$t['vehicle_id'], $id]), 'This truck is still on another trip.', 409);
            run($p, "UPDATE trips SET status='In Progress', departure_at=NOW(), dispatcher_id=? WHERE id=?", [$u['id'], $id]);
            self::event($p, $u['id'], $id, 'truck dispatched', $t['status'], 'In Progress', 'Attempt ' . $t['attempt_number']);
            FleetState::setStatus($p, (int)$t['vehicle_id'], 'On Trip', $u['id'], 'Dispatched ' . $t['trip_ref']);
            FleetState::setCrew($p, [$t['driver_id'], $t['driver2_id'], $t['helper_id']], 'Available', 'On Trip');
            if ($fund && $fund['status'] === 'Released') {
                run($p, "UPDATE fund_requests SET status='Completed' WHERE id=?", [$fund['id']]);
                audit($p, $u['id'], 'Finance', 'fund ' . $fund['id'], 'fund completed (truck dispatched)', $t['trip_ref']);
            }
            Notify::roles($p, ['operations_head', 'finance'], 'Trip dispatched', $t['trip_ref'] . ' departed with truck ' . $v['plate_number'] . '.', 'trips');
            return ['trip_id' => $id, 'message' => 'Truck dispatched. Fund status is now Completed.'];
        });
    }

    /** Progress, delivery, delays (PDF II.F). */
    private static function progress(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $type = choiceField($d, 'update_type', self::UPDATE_TYPES);
        $cause = $type === 'Delay' ? choiceField($d, 'delay_cause', self::DELAY_CAUSES) : null;
        $note = field($d, 'note', 500);
        return transaction(function (PDO $p) use ($u, $id, $type, $cause, $note) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must(in_array($t['status'], ['In Progress', 'Ready for Dispatch', 'Assigned'], true), 'Trip is not active.', 409);
            insert($p, 'trip_updates', ['trip_id' => $id, 'actor_id' => $u['id'], 'update_type' => $type, 'delay_cause' => $cause, 'note' => $note]);
            self::event($p, $u['id'], $id, 'trip update: ' . strtolower($type), $t['status'], $t['status'], ($cause ? "[$cause] " : '') . $note);
            if ($type === 'Delay') Notify::roles($p, ['operations_head'], 'Trip delay', $t['trip_ref'] . " delay ($cause): $note", 'trips');
            return ['trip_id' => $id];
        });
    }

    /**
     * INCIDENT REPORT (PDF pp.16-18). Driver calls dispatcher; dispatcher
     * encodes. A maintenance job appears for Motorpool right away.
     */
    private static function incident(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $sev = choiceField($d, 'severity', ['Minor', 'Major']);
        $details = field($d, 'details', 2000);
        $extra = ['location' => optField($d, 'location', 200), 'cause' => optField($d, 'cause', 255),
                  'damaged_unit' => optField($d, 'damaged_unit', 150), 'action_taken' => optField($d, 'action_taken', 255)];
        return transaction(function (PDO $p) use ($u, $id, $sev, $details, $extra) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must($t['status'] === 'In Progress', 'Incidents are reported for a trip in progress.', 409);
            must(!one($p, "SELECT id FROM incidents WHERE trip_id=? AND status='Reported'", [$id]), 'This trip already has an open incident. Wait for Motorpool to resolve it.', 409);
            $iid = insert($p, 'incidents', ['trip_id' => $id, 'vehicle_id' => $t['vehicle_id'], 'attempt_number' => $t['attempt_number'],
                'severity' => $sev, 'details' => $details, 'reported_by' => $u['id']] + $extra);
            $jobId = insert($p, 'maintenance_jobs', ['vehicle_id' => $t['vehicle_id'], 'source' => 'Incident', 'incident_id' => $iid, 'trip_id' => $id,
                'maintenance_type' => $sev === 'Minor' ? 'Roadside Repair' : 'Repair', 'description' => $details . ($extra['location'] ? ' @ ' . $extra['location'] : ''),
                'status' => 'Reported', 'created_by' => $u['id']]);
            run($p, 'UPDATE maintenance_jobs SET job_number=? WHERE id=?', [refNumber('JOB', $jobId), $jobId]);
            $plate = one($p, 'SELECT plate_number FROM vehicles WHERE id=?', [$t['vehicle_id']])['plate_number'];
            if ($sev === 'Major') {
                // Major: truck goes home for repair; the trip is for reschedule.
                self::setStatus($p, $u['id'], $t, 'Rescheduled', 'major incident: trip for reschedule', $details);
                FleetState::setStatus($p, (int)$t['vehicle_id'], 'Under Maintenance', $u['id'], 'Major incident on ' . $t['trip_ref']);
                FleetState::setCrew($p, [$t['driver_id'], $t['driver2_id'], $t['helper_id']], 'On Trip', 'Available');
                $msg = 'Major incident recorded. Trip is Rescheduled and the truck is Under Maintenance.';
            } else {
                self::event($p, $u['id'], $id, 'minor incident: mechanic to be sent', 'In Progress', 'In Progress', $details);
                $msg = 'Minor incident recorded. Motorpool will send a mechanic; the trip continues.';
            }
            Notify::roles($p, ['motorpool'], 'Incident', "$sev incident on $plate (" . $t['trip_ref'] . "): $details", 'maintenance');
            Notify::roles($p, ['operations_head'], 'Incident', "$sev incident on $plate (" . $t['trip_ref'] . ').', 'incidents');
            return ['incident_id' => $iid, 'job_id' => $jobId, 'message' => $msg];
        });
    }

    /**
     * Arrival checklist + delivery / return details (PDF II.D steps 11-12).
     * Odometer difference is added to the truck's km since PM. When the km
     * limit is reached the system creates the preventive maintenance (PDF p.18).
     */
    private static function arrival(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        $notes = field($d, 'delivery_notes', 500);
        $units = intField($d, 'units_delivered', 0, false);
        return transaction(function (PDO $p) use ($u, $d, $id, $notes, $units) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must($t['status'] === 'In Progress', 'Trip is not in progress.', 409);
            $res = self::saveChecklist($p, $u, $t, 'Arrival', $d);
            must($t['pre_odometer'] !== null && $res['km'] >= (int)$t['pre_odometer'], 'Arrival odometer (' . $res['km'] . ') is lower than the departure odometer (' . $t['pre_odometer'] . ').', 409);
            $traveled = $res['km'] - (int)$t['pre_odometer'];
            run($p, "UPDATE trips SET status='Returned', arrival_at=NOW(), arrival_odometer=?, km_traveled=COALESCE(km_traveled,0)+?, delivery_notes=?, units_delivered=? WHERE id=?",
                [$res['km'], $traveled, $notes, $units, $id]);
            self::event($p, $u['id'], $id, 'arrival checklist completed', 'In Progress', 'Returned', "Odometer in: {$res['km']}; traveled $traveled km" . ($res['has_not_ok'] ? '; NOT OK items found' : ''));
            run($p, 'UPDATE vehicles SET odometer_km=GREATEST(odometer_km, ?), km_since_pm=km_since_pm+? WHERE id=?', [$res['km'], $traveled, $t['vehicle_id']]);
            $warn = [];
            if ($res['has_not_ok']) {
                // Damage or defects found on arrival go to Motorpool (PDF II.G comparison and II.L inspection findings).
                $jobId = insert($p, 'maintenance_jobs', ['vehicle_id' => $t['vehicle_id'], 'source' => 'Inspection', 'trip_id' => $id,
                    'maintenance_type' => 'Repair', 'description' => 'Arrival checklist NOT OK items on ' . $t['trip_ref'] . '. See the checklist for details.',
                    'status' => 'Reported', 'created_by' => $u['id']]);
                run($p, 'UPDATE maintenance_jobs SET job_number=? WHERE id=?', [refNumber('JOB', $jobId), $jobId]);
                Notify::roles($p, ['motorpool'], 'Inspection finding', 'Arrival checklist of ' . $t['trip_ref'] . ' has NOT OK items. Job ' . refNumber('JOB', $jobId) . ' created.', 'maintenance');
                $warn[] = 'NOT OK items were sent to Motorpool as a repair job.';
            }
            $pm = self::checkPreventive($p, (int)$t['vehicle_id'], $u['id']);
            if ($pm) $warn[] = $pm;
            return ['trip_id' => $id, 'message' => 'Arrival recorded (' . $traveled . ' km).', 'warning' => $warn ? implode(' ', $warn) : null];
        });
    }

    /** Preventive maintenance by kilometers (PDF p.18). Returns a message or null. */
    public static function checkPreventive(PDO $p, int $vehicleId, ?int $uid): ?string {
        $v = one($p, 'SELECT * FROM vehicles WHERE id=? FOR UPDATE', [$vehicleId]);
        $limit = (int)Settings::value('pm_interval_km');
        if ($limit <= 0 || (int)$v['km_since_pm'] < $limit) return null;
        if (one($p, "SELECT id FROM maintenance_jobs WHERE vehicle_id=? AND maintenance_type='Preventive Maintenance' AND status NOT IN ('Completed','Cancelled')", [$vehicleId])) return null;
        if (one($p, "SELECT id FROM maintenance_schedules WHERE vehicle_id=? AND maintenance_type='Preventive Maintenance' AND status='Scheduled'", [$vehicleId])) return null;
        $text = 'Preventive maintenance due: ' . $v['km_since_pm'] . ' km since last PM (limit ' . $limit . ' km).';
        if ((int)Settings::value('pm_auto_hold') === 1) {
            $jobId = insert($p, 'maintenance_jobs', ['vehicle_id' => $vehicleId, 'source' => 'Preventive', 'maintenance_type' => 'Preventive Maintenance',
                'description' => $text, 'status' => 'Scheduled', 'odometer_at_service' => $v['odometer_km'], 'created_by' => $uid]);
            run($p, 'UPDATE maintenance_jobs SET job_number=? WHERE id=?', [refNumber('JOB', $jobId), $jobId]);
            // Truck stays On Trip until the trip is completed; reconcile then sets Under Maintenance.
            if (!FleetState::activeTrip($p, $vehicleId)) FleetState::setStatus($p, $vehicleId, 'Under Maintenance', null, 'Preventive maintenance due (km)');
        } else {
            insert($p, 'maintenance_schedules', ['vehicle_id' => $vehicleId, 'maintenance_type' => 'Preventive Maintenance', 'due_on' => date('Y-m-d'),
                'due_odometer_km' => $v['odometer_km'], 'notes' => $text, 'status' => 'Scheduled', 'created_by' => $uid ?? (int)one($p, "SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")['id']]);
        }
        audit($p, null, 'Maintenance', 'vehicle ' . $vehicleId, 'preventive maintenance set by system', $v['plate_number'] . ': ' . $text);
        Notify::roles($p, ['motorpool', 'operations_head'], 'Preventive maintenance', $v['plate_number'] . ': ' . $text, 'maintenance');
        return $v['plate_number'] . ': ' . $text;
    }

    /** Dispatcher submits the completed trip record (PDF II.D step 13). */
    private static function complete(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must($t['status'] === 'Returned', 'Do the arrival checklist first.', 409);
            run($p, "UPDATE trips SET status='Completed', completed_at=NOW() WHERE id=?", [$id]);
            self::event($p, $u['id'], $id, 'completed trip record submitted', 'Returned', 'Completed');
            $vs = FleetState::reconcile($p, (int)$t['vehicle_id'], $u['id'], 'Trip ' . $t['trip_ref'] . ' completed');
            FleetState::setCrew($p, [$t['driver_id'], $t['driver2_id'], $t['helper_id']], 'On Trip', 'Available');
            $wb = (int)one($p, 'SELECT COUNT(*) AS n FROM waybills WHERE trip_id=?', [$id])['n'];
            Notify::roles($p, ['billing'], 'Completed trip', $t['trip_ref'] . ' is completed and ready for billing' . ($wb ? '.' : ' (no waybill yet).'), 'billing');
            Notify::roles($p, ['operations_head'], 'Completed trip', $t['trip_ref'] . ' completed report submitted.', 'trip_records');
            return ['trip_id' => $id, 'message' => 'Trip completed. Truck is now ' . $vs . '.' . ($wb ? '' : ' Reminder: record the waybill so Billing and Payroll can use this trip.')];
        });
    }

    /** Re-dispatch after major repair: system sets 2nd attempt (PDF p.16-18). */
    private static function secondAttempt(array $u, array $d): array {
        $id = idField($d, 'trip_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $t = self::lockTrip($p, $id);
            self::mustBeTripDispatcher($u, $t);
            must($t['status'] === 'Rescheduled', 'Only a rescheduled trip can be re-dispatched.', 409);
            $v = one($p, 'SELECT status, plate_number FROM vehicles WHERE id=? FOR UPDATE', [$t['vehicle_id']]);
            must($v && $v['status'] === 'Available', 'The truck is still ' . ($v['status'] ?? 'missing') . '. Motorpool must finish the repair and set it Available, or the Operations Head can assign another truck.', 409);
            $next = (int)$t['attempt_number'] + 1;
            // TODO PENDING COMPANY CONFIRMATION (C07): extra allowance / re-approval
            // rules for a second attempt are not in the PDF. The released fund of the
            // first attempt is kept; a new pre-departure checklist and Ops Head
            // clearance are required again.
            run($p, 'UPDATE trips SET attempt_number=?, status=?, ops_cleared_by=NULL, ops_cleared_at=NULL, pre_odometer=NULL, scheduled_at=GREATEST(scheduled_at, NOW()) WHERE id=?', [$next, 'Assigned', $id]);
            self::event($p, $u['id'], $id, 'system set attempt #' . $next, 'Rescheduled', 'Assigned', 'Repaired truck ' . $v['plate_number'] . ' re-dispatch. New checklist and clearance required.');
            FleetState::setStatus($p, (int)$t['vehicle_id'], 'Assigned', $u['id'], 'Attempt ' . $next . ' of ' . $t['trip_ref']);
            Notify::roles($p, ['operations_head'], 'Second attempt', $t['trip_ref'] . ' is set as attempt #' . $next . '.', 'trips');
            return ['trip_id' => $id, 'message' => 'Trip set as attempt #' . $next . '. Do the pre-departure checklist again.'];
        });
    }

    /** Ops Head cancels a trip that has not left yet. */
    private static function cancel(array $u, array $d): array {
        requireRole($u, 'operations_head');
        $id = idField($d, 'trip_id'); $reason = field($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $t = self::lockTrip($p, $id);
            must(!in_array($t['status'], ['In Progress', 'Returned', 'Completed', 'Cancelled', 'Rejected'], true), 'This trip can no longer be cancelled.', 409);
            run($p, 'UPDATE trips SET cancel_reason=? WHERE id=?', [$reason, $id]);
            self::setStatus($p, $u['id'], $t, 'Cancelled', 'trip cancelled', $reason);
            $fund = self::activeFund($p, $id);
            $msg = 'Trip cancelled.';
            if ($fund && in_array($fund['status'], ['Pending Approval', 'Approved', 'Pending Release', 'Ready for Release'], true)) {
                run($p, "UPDATE fund_requests SET status='Cancelled' WHERE id=?", [$fund['id']]);
                audit($p, $u['id'], 'Finance', 'fund ' . $fund['id'], 'fund cancelled (trip cancelled)');
            } elseif ($fund && in_array($fund['status'], ['Released', 'Completed'], true)) {
                // TODO PENDING COMPANY CONFIRMATION: return of released cash is handled by Finance liquidation.
                Notify::roles($p, ['finance'], 'Liquidation', $t['trip_ref'] . ' was cancelled after the fund was released. Please record the liquidation / returned cash.', 'funds');
                $msg .= ' The fund was already released; Finance was asked to record the liquidation.';
            }
            if ($t['vehicle_id']) FleetState::reconcile($p, (int)$t['vehicle_id'], $u['id'], 'Released by cancelled ' . $t['trip_ref']);
            Notify::roles($p, [$t['dispatcher_role']], 'Trip cancelled', $t['trip_ref'] . ' was cancelled: ' . $reason, 'trips');
            return ['trip_id' => $id, 'message' => $msg];
        });
    }

    /** Waybill (basis for billing and trip-related pay). */
    private static function waybillAdd(array $u, array $d): array {
        must(roleIsDispatcher($u['role']) || hasRole($u, 'admin', 'billing'), 'Not allowed.', 403);
        $id = idField($d, 'trip_id');
        $row = ['waybill_number' => field($d, 'waybill_number', 100), 'client_waybill_number' => optField($d, 'client_waybill_number', 100),
                'dr_number' => optField($d, 'dr_number', 100), 'units' => intField($d, 'units', 0, false),
                'submitted_on' => dateField($d, 'submitted_on', false) ?? date('Y-m-d'), 'note' => optField($d, 'note', 255)];
        return transaction(function (PDO $p) use ($u, $id, $row) {
            $t = self::lockTrip($p, $id);
            if (roleIsDispatcher($u['role'])) self::mustBeTripDispatcher($u, $t);
            must(in_array($t['status'], ['In Progress', 'Returned', 'Completed'], true), 'Waybills are recorded for dispatched or completed trips.', 409);
            must(!one($p, 'SELECT id FROM waybills WHERE trip_id=? AND waybill_number=?', [$id, $row['waybill_number']]), 'This waybill number is already recorded for the trip.', 409);
            $wid = insert($p, 'waybills', ['trip_id' => $id, 'recorded_by' => $u['id']] + $row);
            self::event($p, $u['id'], $id, 'waybill recorded', $t['status'], $t['status'], $row['waybill_number']);
            return ['waybill_id' => $wid, 'message' => 'Waybill recorded.'];
        });
    }
}
