<?php
/*
 * backend/Core/Notify.php  —  in-app notifications (bell icon)
 * Pending approvals, trip updates, expiring documents and other
 * important activities (PDF #11 V.63).
 * A failed notification never stops the main transaction.
 */
declare(strict_types=1);

final class Notify {
    /** Send to every active user with one of the roles. */
    public static function roles(PDO $p, array $roles, string $category, string $message, ?string $page = null, ?string $dedupeKey = null): void {
        try {
            $roles = array_values(array_unique(array_filter($roles)));
            if (!$roles) return;
            $marks = implode(',', array_fill(0, count($roles), '?'));
            foreach (many($p, "SELECT id FROM users WHERE active=1 AND role IN ($marks)", $roles) as $u) {
                self::user($p, (int)$u['id'], $category, $message, $page, $dedupeKey);
            }
        } catch (Throwable $e) { error_log('Notify::roles failed: ' . $e->getMessage()); }
    }

    /** Send to one user. With $dedupeKey the same message is not repeated. */
    public static function user(PDO $p, ?int $userId, string $category, string $message, ?string $page = null, ?string $dedupeKey = null): void {
        if (!$userId) return;
        try {
            $p->prepare('INSERT IGNORE INTO notifications (user_id, category, message, page, dedupe_key) VALUES (?,?,?,?,?)')
              ->execute([$userId, $category, cut($message, 300), $page, $dedupeKey]);
        } catch (Throwable $e) { error_log('Notify::user failed: ' . $e->getMessage()); }
    }

    /**
     * Daily reminders: expiring truck documents, driver licenses, uploaded
     * documents, low stock and PMS due. Runs when a dashboard is opened;
     * dedupe_key makes it once per item per day.
     */
    public static function dailyReminders(PDO $p): void {
        try {
            core('Settings');
            $today = date('Y-m-d');
            $docDays = (int)Settings::value('document_alert_days');
            $licDays = (int)Settings::value('license_alert_days');
            foreach (many($p, "SELECT id, category, original_filename, expires_on FROM documents WHERE expires_on IS NOT NULL AND expires_on <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)", [$docDays]) as $d) {
                self::roles($p, ['admin'], 'Expiring document', $d['category'] . ' "' . $d['original_filename'] . '" expires on ' . $d['expires_on'], 'documents', 'doc-' . $d['id'] . '-' . $today);
            }
            foreach (many($p, "SELECT id, plate_number, registration_expiry, insurance_expiry FROM vehicles WHERE active=1 AND ((registration_expiry IS NOT NULL AND registration_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)) OR (insurance_expiry IS NOT NULL AND insurance_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)))", [$docDays, $docDays]) as $v) {
                self::roles($p, ['admin', 'operations_head'], 'Truck documents', 'Truck ' . $v['plate_number'] . ': registration ' . ($v['registration_expiry'] ?? '-') . ', insurance ' . ($v['insurance_expiry'] ?? '-'), 'trucks', 'veh-' . $v['id'] . '-' . $today);
            }
            foreach (many($p, "SELECT id, full_name, license_expiry FROM personnel WHERE active=1 AND license_expiry IS NOT NULL AND license_expiry <= DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)", [$licDays]) as $c) {
                self::roles($p, ['admin', 'operations_head'], 'Driver license', $c['full_name'] . ' license expires on ' . $c['license_expiry'], 'crew', 'lic-' . $c['id'] . '-' . $today);
            }
            foreach (many($p, 'SELECT id, part_number, description, quantity_on_hand, reorder_level FROM parts WHERE active=1 AND reorder_level > 0 AND quantity_on_hand <= reorder_level') as $pt) {
                self::roles($p, ['purchasing'], 'Low stock', $pt['part_number'] . ' ' . $pt['description'] . ': ' . $pt['quantity_on_hand'] . ' left (reorder level ' . $pt['reorder_level'] . ')', 'purchasing', 'stock-' . $pt['id'] . '-' . $today);
            }
            $months = max(1, (int)Settings::value('pms_interval_months'));
            foreach (many($p, "SELECT id, plate_number, last_pms_date FROM vehicles WHERE active=1 AND (last_pms_date IS NULL OR DATE_ADD(last_pms_date, INTERVAL ? MONTH) <= CURRENT_DATE())
                               AND NOT EXISTS (SELECT 1 FROM maintenance_jobs m WHERE m.vehicle_id=vehicles.id AND m.source='Preventive' AND m.status IN ('Reported','Ongoing'))", [$months]) as $v) {
                self::roles($p, ['motorpool'], 'PMS due', $v['plate_number'] . ': monthly Preventive Maintenance Service is due (last: ' . ($v['last_pms_date'] ?? 'none recorded') . ').', 'maintenance', 'pms-' . $v['id'] . '-' . date('Y-m'));
            }
        } catch (Throwable $e) { error_log('dailyReminders failed: ' . $e->getMessage()); }
    }
}
