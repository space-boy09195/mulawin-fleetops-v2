<?php
/*
 * =====================================================================
 *  backend/Modules/Fleet.php  —  FLEET AND PERSONNEL
 * =====================================================================
 *  FMS PDF II.B Fleet Management and II.C Driver and Helper Management.
 *    - Admin keeps truck records (OR/CR, insurance, expiry dates) and
 *      the driver / helper / staff records.
 *    - Motorpool updates truck status: Available or Under Maintenance
 *      (PDF p.18 "motorpool custodian can modify / update truck status").
 *    - Payroll updates only the pay fields (daily rate, payroll group).
 *    - Operations Head sees fleet status, availability and staffing.
 *
 *  Every module has the same two entry points:
 *    read($user, $page, $query)    -> data for a page
 *    action($user, $name, $data)   -> saves something
 * =====================================================================
 */
declare(strict_types=1);
core('FleetState');
core('Settings');

final class Fleet {
    public const PERSON_TYPES = ['Driver', 'Helper', 'Admin Staff', 'PDI Personnel'];

    // -----------------------------------------------------------------
    // READ
    // -----------------------------------------------------------------
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'fleet') {
            $data = readSections([
                'vehicles' => fn() => many($p, "SELECT v.*, d.full_name AS driver_name, h.full_name AS helper_name,
                        DATEDIFF(v.registration_expiry, CURRENT_DATE()) AS registration_days_left,
                        DATEDIFF(v.insurance_expiry, CURRENT_DATE()) AS insurance_days_left,
                        (SELECT t.trip_ref FROM trips t WHERE t.vehicle_id=v.id AND t.status IN ('Pre-Assigned','Ready for Dispatch','Dispatched','On Hold - Incident Reported','In Transit','Returned') ORDER BY FIELD(t.status,'Dispatched','On Hold - Incident Reported','In Transit','Returned','Ready for Dispatch','Pre-Assigned') LIMIT 1) AS current_trip,
                        (SELECT m.expected_completion FROM maintenance_jobs m WHERE m.vehicle_id=v.id AND m.status NOT IN ('Completed','Cancelled') ORDER BY m.id DESC LIMIT 1) AS expected_available,
                        (SELECT m.description FROM maintenance_jobs m WHERE m.vehicle_id=v.id AND m.status NOT IN ('Completed','Cancelled') ORDER BY m.id DESC LIMIT 1) AS open_job
                    FROM vehicles v
                    LEFT JOIN personnel d ON d.id=v.assigned_driver_id
                    LEFT JOIN personnel h ON h.id=v.assigned_helper_id
                    ORDER BY v.active DESC, v.truck_type, v.plate_number"),
                'statusLog' => fn() => many($p, 'SELECT l.*, v.plate_number, u.full_name AS actor FROM vehicle_status_log l
                    JOIN vehicles v ON v.id=l.vehicle_id LEFT JOIN users u ON u.id=l.actor_id ORDER BY l.id DESC LIMIT 100'),
                'crew' => fn() => many($p, "SELECT id, full_name, person_type FROM personnel WHERE employment_status='Active' AND person_type IN ('Driver','Helper') ORDER BY full_name"),
                'maintenanceHistory' => fn() => many($p, "SELECT m.id, m.job_number, m.vehicle_id, m.maintenance_type, m.status, m.description, m.completed_at, m.created_at
                    FROM maintenance_jobs m ORDER BY m.id DESC LIMIT 300"),
            ]);
            $data['pmInterval'] = Settings::value('pm_interval_km');
            $data['alertDays'] = Settings::value('document_alert_days');
            $data['can'] = ['edit' => hasRole($u, 'admin'), 'status' => hasRole($u, 'admin', 'motorpool')];
            return $data;
        }
        // page 'crew'
        $lookback = (int)Settings::value('auto_assign_lookback_days');
        $data = readSections([
            'people' => fn() => many($p, "SELECT pe.*, DATEDIFF(pe.license_expiry, CURRENT_DATE()) AS license_days_left,
                    (SELECT v.plate_number FROM vehicles v WHERE v.assigned_driver_id=pe.id OR v.assigned_helper_id=pe.id LIMIT 1) AS assigned_truck,
                    (SELECT COUNT(*) FROM trips t WHERE (t.driver_id=pe.id OR t.driver2_id=pe.id OR t.helper_id=pe.id) AND t.status='Completed') AS total_trips,
                    (SELECT COUNT(*) FROM trips t WHERE (t.driver_id=pe.id OR t.driver2_id=pe.id OR t.helper_id=pe.id) AND t.status NOT IN ('Cancelled','Rejected') AND t.scheduled_at >= DATE_SUB(NOW(), INTERVAL ? DAY)) AS recent_trips
                FROM personnel pe ORDER BY FIELD(pe.employment_status,'Active','Inactive','Resigned'), pe.person_type, pe.full_name", [$lookback]),
            'tripHistory' => fn() => (hasRole($u, 'payroll') ? [] : many($p, "SELECT t.id, t.trip_ref, t.scheduled_at, t.status, t.destination, c.name AS client,
                    t.driver_id, t.driver2_id, t.helper_id FROM trips t JOIN clients c ON c.id=t.client_id
                    WHERE t.driver_id IS NOT NULL ORDER BY t.scheduled_at DESC LIMIT 500")),
        ]);
        // Payroll sees pay fields only, not contact details.
        if (hasRole($u, 'payroll')) {
            foreach ($data['people'] as &$r) { unset($r['address'], $r['contact_number'], $r['license_number']); }
            unset($r);
        } elseif (!hasRole($u, 'admin')) {
            // Operations and dispatchers do not need pay information.
            foreach ($data['people'] as &$r) { unset($r['daily_rate'], $r['pay_frequency'], $r['payroll_group']); }
            unset($r);
        }
        $data['personTypes'] = self::PERSON_TYPES;
        $data['can'] = ['edit' => hasRole($u, 'admin'), 'pay' => hasRole($u, 'admin', 'payroll'),
                        'availability' => hasRole($u, 'admin', 'operations_head')];
        return $data;
    }

    // -----------------------------------------------------------------
    // ACTIONS
    // -----------------------------------------------------------------
    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'vehicle_save' => self::vehicleSave($u, $d),
            'vehicle_status' => self::vehicleStatus($u, $d),
            'vehicle_active' => self::vehicleActive($u, $d),
            'person_save' => self::personSave($u, $d),
            'person_pay' => self::personPay($u, $d),
            'person_availability' => self::personAvailability($u, $d),
            default => throw new BusinessRuleException('Unknown fleet action.', 404),
        };
    }

    /** Admin adds or edits a truck (PDF II.B list of vehicle information). */
    private static function vehicleSave(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = optId($d, 'id');
        $row = [
            'plate_number' => strtoupper(field($d, 'plate_number', 40)),
            'truck_number' => optField($d, 'truck_number', 20),
            'truck_type' => choiceField($d, 'truck_type', TRUCK_TYPES),
            'make_brand' => optField($d, 'make_brand', 80),
            'model' => optField($d, 'model', 80),
            'chassis_number' => optField($d, 'chassis_number', 80),
            'engine_number' => optField($d, 'engine_number', 80),
            'mv_file_number' => optField($d, 'mv_file_number', 80),
            'or_number' => optField($d, 'or_number', 80),
            'cr_number' => optField($d, 'cr_number', 80),
            'registration_expiry' => dateField($d, 'registration_expiry', false),
            'insurance_provider' => optField($d, 'insurance_provider', 120),
            'insurance_policy_number' => optField($d, 'insurance_policy_number', 80),
            'insurance_expiry' => dateField($d, 'insurance_expiry', false),
            'assigned_driver_id' => optId($d, 'assigned_driver_id'),
            'assigned_helper_id' => optId($d, 'assigned_helper_id'),
            'notes' => optField($d, 'notes', 255),
        ];
        return transaction(function (PDO $p) use ($u, $id, $row, $d) {
            foreach (['assigned_driver_id' => 'Driver', 'assigned_helper_id' => 'Helper'] as $col => $type) {
                if ($row[$col]) must((bool)one($p, "SELECT id FROM personnel WHERE id=? AND person_type=? AND employment_status='Active'", [$row[$col], $type]), "Select an active $type.");
            }
            $dup = one($p, 'SELECT id FROM vehicles WHERE plate_number=? AND id<>?', [$row['plate_number'], $id ?? 0]);
            must(!$dup, 'Another truck already uses this plate number.', 409);
            if ($id) {
                $set = implode(',', array_map(fn($c) => "$c=?", array_keys($row)));
                run($p, "UPDATE vehicles SET $set WHERE id=?", array_merge(array_values($row), [$id]));
                audit($p, $u['id'], 'Fleet', (string)$id, 'truck updated', $row['plate_number']);
            } else {
                $row['odometer_km'] = intField($d, 'odometer_km', 0, false) ?? 0;
                $row['km_since_pm'] = intField($d, 'km_since_pm', 0, false) ?? 0;
                $id = insert($p, 'vehicles', $row);
                $p->prepare('INSERT INTO vehicle_status_log (vehicle_id, from_status, to_status, reason, actor_id) VALUES (?,?,?,?,?)')
                  ->execute([$id, null, 'Available', 'Truck registered', $u['id']]);
                audit($p, $u['id'], 'Fleet', (string)$id, 'truck registered', $row['plate_number'] . ' / ' . $row['truck_type']);
            }
            return ['vehicle_id' => $id];
        });
    }

    /** Motorpool: Available / Under Maintenance. Admin may also set Unavailable. */
    private static function vehicleStatus(array $u, array $d): array {
        requireRole($u, 'admin', 'motorpool');
        $id = idField($d, 'vehicle_id');
        $allowed = hasRole($u, 'admin') ? ['Available', 'Under Maintenance', 'Unavailable'] : ['Available', 'Under Maintenance'];
        $status = choiceField($d, 'status', $allowed);
        $reason = field($d, 'reason', 255, false);
        return transaction(function (PDO $p) use ($u, $id, $status, $reason) {
            $v = one($p, 'SELECT * FROM vehicles WHERE id=? FOR UPDATE', [$id]);
            must((bool)$v, 'Truck not found.', 404);
            $trip = FleetState::activeTrip($p, $id);
            must(!$trip, 'Truck is held by trip ' . ($trip['trip_ref'] ?? '') . '. Change it through the trip (incident or cancellation).', 409);
            if ($status === 'Available') {
                must(!FleetState::openJob($p, $id), 'This truck still has an open maintenance job. Complete the job in Maintenance first.', 409);
            }
            FleetState::setStatus($p, $id, $status, $u['id'], $reason ?: 'Manual status update');
            audit($p, $u['id'], 'Fleet', (string)$id, 'truck status updated', $v['plate_number'] . ': ' . $v['status'] . ' -> ' . $status . ($reason ? " ($reason)" : ''));
            return ['vehicle_id' => $id, 'status' => $status];
        });
    }

    private static function vehicleActive(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = idField($d, 'vehicle_id'); $active = intField($d, 'active', 0) ? 1 : 0;
        return transaction(function (PDO $p) use ($u, $id, $active) {
            if (!$active) must(!FleetState::activeTrip($p, $id), 'Truck has an active trip.', 409);
            run($p, 'UPDATE vehicles SET active=? WHERE id=?', [$active, $id]);
            audit($p, $u['id'], 'Fleet', (string)$id, $active ? 'truck activated' : 'truck deactivated');
            return ['vehicle_id' => $id];
        });
    }

    /** Admin adds or edits a person (PDF II.C list of personnel information). */
    private static function personSave(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = optId($d, 'id');
        $row = [
            'employee_no' => optField($d, 'employee_no', 40),
            'full_name' => field($d, 'full_name', 120),
            'person_type' => choiceField($d, 'person_type', self::PERSON_TYPES),
            'employment_type' => choiceField($d, 'employment_type', ['Employee', 'Contractor']),
            'employment_status' => choiceField($d, 'employment_status', ['Active', 'Resigned', 'Inactive']),
            'contact_number' => optField($d, 'contact_number', 60),
            'address' => optField($d, 'address', 255),
            'license_number' => optField($d, 'license_number', 60),
            'license_expiry' => dateField($d, 'license_expiry', false),
            'date_hired' => dateField($d, 'date_hired', false),
            'payroll_group' => optField($d, 'payroll_group', 60),
            'pay_frequency' => choiceField($d, 'pay_frequency', ['Weekly', 'Semi-monthly']),
            'daily_rate' => optMoney($d, 'daily_rate') ?? '0.00',
            'notes' => optField($d, 'notes', 255),
        ];
        if ($row['person_type'] === 'Driver') must($row['license_expiry'] !== null, 'Driver license expiry date is required for drivers.');
        return transaction(function (PDO $p) use ($u, $id, $row) {
            if ($row['employment_status'] !== 'Active' && $id) {
                $busy = one($p, "SELECT trip_ref FROM trips WHERE (driver_id=? OR driver2_id=? OR helper_id=?) AND status IN ('Pre-Assigned','Ready for Dispatch','Dispatched','On Hold - Incident Reported','In Transit','Returned','For Reschedule') LIMIT 1", [$id, $id, $id]);
                must(!$busy, 'This person is assigned to active trip ' . ($busy['trip_ref'] ?? '') . '.', 409);
            }
            if ($id) {
                $set = implode(',', array_map(fn($c) => "$c=?", array_keys($row)));
                run($p, "UPDATE personnel SET $set WHERE id=?", array_merge(array_values($row), [$id]));
                if ($row['employment_status'] !== 'Active') run($p, "UPDATE personnel SET availability='Unavailable' WHERE id=?", [$id]);
                audit($p, $u['id'], 'Personnel', (string)$id, 'personnel updated', $row['full_name']);
            } else {
                $id = insert($p, 'personnel', $row);
                audit($p, $u['id'], 'Personnel', (string)$id, 'personnel added', $row['full_name'] . ' / ' . $row['person_type']);
            }
            return ['person_id' => $id];
        });
    }

    /** Payroll keeps pay information (daily rate, group, frequency). */
    private static function personPay(array $u, array $d): array {
        requireRole($u, 'admin', 'payroll');
        $id = idField($d, 'id');
        $rate = moneyField($d, 'daily_rate', true);
        $group = optField($d, 'payroll_group', 60);
        $freq = choiceField($d, 'pay_frequency', ['Weekly', 'Semi-monthly']);
        return transaction(function (PDO $p) use ($u, $id, $rate, $group, $freq) {
            $old = one($p, 'SELECT full_name, daily_rate FROM personnel WHERE id=?', [$id]);
            must((bool)$old, 'Person not found.', 404);
            run($p, 'UPDATE personnel SET daily_rate=?, payroll_group=?, pay_frequency=? WHERE id=?', [$rate, $group, $freq, $id]);
            audit($p, $u['id'], 'Payroll', (string)$id, 'pay information updated', $old['full_name'] . ': daily rate ' . $old['daily_rate'] . ' -> ' . $rate);
            return ['person_id' => $id];
        });
    }

    private static function personAvailability(array $u, array $d): array {
        requireRole($u, 'admin', 'operations_head');
        $id = idField($d, 'id');
        $to = choiceField($d, 'availability', ['Available', 'Unavailable']);
        return transaction(function (PDO $p) use ($u, $id, $to) {
            $pe = one($p, 'SELECT full_name, availability, employment_status FROM personnel WHERE id=? FOR UPDATE', [$id]);
            must((bool)$pe, 'Person not found.', 404);
            must($pe['availability'] !== 'On Trip', 'This person is on a trip right now.', 409);
            if ($to === 'Available') must($pe['employment_status'] === 'Active', 'Only active personnel can be available.', 409);
            run($p, 'UPDATE personnel SET availability=? WHERE id=?', [$to, $id]);
            audit($p, $u['id'], 'Personnel', (string)$id, 'availability changed', $pe['full_name'] . ': ' . $to);
            return ['person_id' => $id];
        });
    }
}
