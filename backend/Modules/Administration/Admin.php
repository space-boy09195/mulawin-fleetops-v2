<?php
/*
 * =====================================================================
 *  backend/Modules/Administration/Admin.php  —  USERS, ACTIVITY LOG, FORMULA SETTINGS, BACKUP
 * =====================================================================
 *  FMS PDF II.A (user accounts, roles, activate / deactivate, activity
 *  logs) and V.65 (data backup and recovery).
 *  Formula Settings lets Admin / Finance / Payroll change the numbers
 *  used by computations without editing code.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');

final class Admin {
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        if ($page === 'users') {
            return readSections([
                'users' => fn() => many($p, 'SELECT id, username, full_name, role, shift, active, must_change_password, last_login_at, created_at FROM users ORDER BY active DESC, role, full_name'),
            ]) + ['roles' => ROLE_LABELS];
        }
        if ($page === 'audit_log') {
            $from = self::d($q['from'] ?? '') ?? date('Y-m-d', strtotime('-30 days'));
            $to = self::d($q['to'] ?? '') ?? date('Y-m-d');
            $w = 'WHERE DATE(a.created_at) BETWEEN ? AND ?'; $a = [$from, $to];
            if (hasRole($u, 'audit')) $w .= " AND a.area IN ('Finance','Billing','Purchasing','Payroll','Documents','Operations')";
            if (!empty($q['area'])) { $w .= ' AND a.area=?'; $a[] = (string)$q['area']; }
            if (!empty($q['q'])) { $w .= ' AND (a.action LIKE ? OR a.details LIKE ? OR a.record_ref LIKE ?)'; $l = '%' . $q['q'] . '%'; array_push($a, $l, $l, $l); }
            return readSections([
                'events' => fn() => many($p, "SELECT a.*, COALESCE(us.full_name, 'System') AS actor, us.role FROM audit_events a LEFT JOIN users us ON us.id=a.actor_id $w ORDER BY a.id DESC LIMIT 3000", $a),
                'areas' => fn() => array_column(many($p, 'SELECT DISTINCT area FROM audit_events ORDER BY area'), 'area'),
            ]) + ['range' => ['from' => $from, 'to' => $to]];
        }
        if ($page === 'settings') {
            $scopes = [];
            foreach (array_keys(Settings::SCOPE_CALCS) as $s) {
                $scopes[] = ['scope' => $s, 'label' => Settings::SCOPE_LABELS[$s], 'calcs' => Settings::SCOPE_CALCS[$s],
                             'items' => Settings::items($s, false), 'can_edit' => Settings::canEdit($u, $s)];
            }
            $values = Settings::allValues();
            foreach ($values as &$v) $v['can_edit'] = Settings::canEdit($u, $v['group']); unset($v);
            // Example so users can see the effect right away.
            $example = ['km' => 143, 'toll' => 651, 'rate' => 15000];
            return ['values' => $values, 'scopes' => $scopes, 'example' => $example + [
                'allowance' => Settings::computeItems('trip_allowance', $example),
                'pnl' => Settings::projectedPnl($example['rate'], $example['km'], $example['toll'])]];
        }
        // backup page
        return ['backups' => array_values(array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'date' => date('Y-m-d H:i', filemtime($f))],
            glob(dirname(__DIR__, 3) . '/storage/backups/*.sql') ?: []))];
    }
    private static function d(string $v): ?string { return ($v !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $v : null; }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'user_save' => self::userSave($u, $d),
            'user_toggle' => self::userToggle($u, $d),
            'user_password' => self::userPassword($u, $d),
            'change_password' => self::changePassword($u, $d),
            'setting_save' => self::settingSave($u, $d),
            'setting_reset' => self::settingReset($u, $d),
            'items_save' => self::itemsSave($u, $d),
            'items_reset' => self::itemsReset($u, $d),
            'backup_create' => self::backupCreate($u),
            'notifications_read' => self::notificationsRead($u, $d),
            default => throw new BusinessRuleException('Unknown admin action.', 404),
        };
    }

    private static function strongPassword(string $pw): void {
        must(strlen($pw) >= PASSWORD_MIN_LENGTH, 'Password must have at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        must(preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw), 'Password must have letters and numbers.');
    }

    private static function userSave(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = optId($d, 'id');
        $role = choiceField($d, 'role', allowedRoles());
        $shift = roleIsDispatcher($role) ? choiceField($d, 'shift', ['Day', 'Night'], false) : null;
        $name = field($d, 'full_name', 100);
        return transaction(function (PDO $p) use ($u, $id, $role, $shift, $name, $d) {
            if ($id) {
                if ($id === $u['id']) must($role === 'admin', 'You cannot remove your own Admin role.');
                run($p, 'UPDATE users SET full_name=?, role=?, shift=? WHERE id=?', [$name, $role, $shift, $id]);
                audit($p, $u['id'], 'Access', 'user ' . $id, 'account updated', "$name / $role");
                return ['user_id' => $id, 'message' => 'Account updated.'];
            }
            $username = field($d, 'username', 70);
            must((bool)preg_match('/^[a-zA-Z0-9_.-]{3,70}$/', $username), 'Username: 3-70 letters, numbers, dot, dash or underscore.');
            $pw = field($d, 'password', 255); self::strongPassword($pw);
            must(!one($p, 'SELECT id FROM users WHERE username=?', [$username]), 'Username already exists.', 409);
            $id = insert($p, 'users', ['username' => $username, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => $name,
                'role' => $role, 'shift' => $shift, 'must_change_password' => 1]);
            audit($p, $u['id'], 'Access', 'user ' . $id, 'account created', "$username / $role");
            return ['user_id' => $id, 'message' => 'Account created. The user must change the password at first login.'];
        });
    }

    private static function userToggle(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = idField($d, 'user_id'); $active = intField($d, 'active', 0) ? 1 : 0;
        must($id !== $u['id'], 'You cannot deactivate your own account.');
        return transaction(function (PDO $p) use ($u, $id, $active) {
            if (!$active) {
                $t = one($p, 'SELECT role FROM users WHERE id=?', [$id]);
                if ($t && $t['role'] === 'admin') must((int)one($p, "SELECT COUNT(*) n FROM users WHERE role='admin' AND active=1")['n'] > 1, 'Keep at least one active Admin.');
            }
            run($p, 'UPDATE users SET active=? WHERE id=?', [$active, $id]);
            audit($p, $u['id'], 'Access', 'user ' . $id, $active ? 'account activated' : 'account deactivated');
            return ['user_id' => $id];
        });
    }

    private static function userPassword(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = idField($d, 'user_id'); $pw = field($d, 'password', 255); self::strongPassword($pw);
        return transaction(function (PDO $p) use ($u, $id, $pw) {
            run($p, 'UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            audit($p, $u['id'], 'Access', 'user ' . $id, 'password reset by Admin');
            return ['user_id' => $id, 'message' => 'Password reset. The user must change it at next login.'];
        });
    }

    private static function changePassword(array $u, array $d): array {
        $current = field($d, 'current_password', 255); $new = field($d, 'new_password', 255);
        self::strongPassword($new);
        must($new !== $current, 'Choose a different password.');
        return transaction(function (PDO $p) use ($u, $current, $new) {
            $row = one($p, 'SELECT password_hash FROM users WHERE id=?', [$u['id']]);
            must(password_verify($current, $row['password_hash']), 'Current password is wrong.', 403);
            run($p, 'UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            audit($p, $u['id'], 'Access', 'user ' . $u['id'], 'own password changed');
            return ['message' => 'Password changed.'];
        });
    }

    // ---------- Formula Settings ----------
    private static function settingSave(array $u, array $d): array {
        $key = field($d, 'key', 80);
        $meta = Settings::defaults()['values'][$key] ?? null;
        must($meta !== null, 'Unknown setting.');
        must(Settings::canEdit($u, $meta['group']), 'Your role cannot change this setting.', 403);
        $raw = trim(str_replace(',', '', (string)($d['value'] ?? '')));
        must(is_numeric($raw) && (float)$raw >= 0, 'Enter a number (0 or more).');
        if (($meta['unit'] ?? '') === '1/0') must(in_array((float)$raw, [0.0, 1.0], true), 'Enter 1 (yes) or 0 (no).');
        if ($key === 'km_per_liter') must((float)$raw > 0, 'Kilometers per liter must be more than 0.');
        return transaction(function (PDO $p) use ($u, $key, $raw) {
            $old = Settings::value($key);
            $p->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)')
              ->execute([$key, $raw, $u['id']]);
            Settings::clearCache();
            audit($p, $u['id'], 'Settings', $key, 'formula value changed', "$old -> $raw");
            return ['message' => 'Saved. New records will use ' . $raw . '. Old records keep their values.'];
        });
    }

    private static function settingReset(array $u, array $d): array {
        $key = field($d, 'key', 80);
        $meta = Settings::defaults()['values'][$key] ?? null;
        must($meta !== null && Settings::canEdit($u, $meta['group']), 'Not allowed.', 403);
        return transaction(function (PDO $p) use ($u, $key, $meta) {
            run($p, 'DELETE FROM app_settings WHERE setting_key=?', [$key]);
            audit($p, $u['id'], 'Settings', $key, 'formula value reset to default', (string)$meta['value']);
            return ['message' => 'Back to the default value (' . $meta['value'] . ').'];
        });
    }

    /** Saves a whole computation list (add / remove / change lines). */
    private static function itemsSave(array $u, array $d): array {
        $scope = field($d, 'scope', 40);
        must(isset(Settings::SCOPE_CALCS[$scope]), 'Unknown list.');
        must(Settings::canEdit($u, $scope), 'Your role cannot change this list.', 403);
        $items = $d['items'] ?? [];
        must(is_array($items) && count($items) <= 40, 'Invalid list.');
        $clean = []; $names = [];
        foreach ($items as $i => $it) {
            must(is_array($it), 'Invalid line.');
            $name = field($it, 'name', 100);
            must(!in_array(strtolower($name), $names, true), "The name \"$name\" is used twice.");
            $names[] = strtolower($name);
            $calc = choiceField($it, 'calc', Settings::SCOPE_CALCS[$scope]);
            $amount = trim((string)($it['amount'] ?? '0'));
            must(is_numeric($amount) && (float)$amount >= 0, "Amount of \"$name\" must be a number.");
            $div = trim((string)($it['divisor'] ?? '1'));
            must(is_numeric($div) && (float)$div > 0, "Divisor of \"$name\" must be more than 0.");
            $ledger = $calc === 'ledger' || !empty($it['ledger']) ? choiceField($it, 'ledger', ['Cash Advance', 'Uniform', 'Damages', 'Cash Bond'], $calc === 'ledger') : null;
            $clean[] = ['scope' => $scope, 'name' => $name, 'calc' => $calc, 'amount' => (float)$amount, 'divisor' => (float)$div,
                        'only_if_attended' => !empty($it['only_if_attended']) ? 1 : 0, 'ledger' => $ledger, 'source' => optField($it, 'source', 255),
                        'active' => !empty($it['active']) ? 1 : 0, 'sort_order' => $i, 'updated_by' => $u['id']];
        }
        return transaction(function (PDO $p) use ($u, $scope, $clean) {
            $before = json_encode(Settings::items($scope, false));
            run($p, 'DELETE FROM formula_items WHERE scope=?', [$scope]);
            foreach ($clean as $row) insert($p, 'formula_items', $row);
            audit($p, $u['id'], 'Settings', $scope, 'formula list changed', 'Before: ' . cut($before, 900));
            return ['message' => 'Saved. New records will use this list.'];
        });
    }

    private static function itemsReset(array $u, array $d): array {
        $scope = field($d, 'scope', 40);
        must(isset(Settings::SCOPE_CALCS[$scope]) && Settings::canEdit($u, $scope), 'Not allowed.', 403);
        return transaction(function (PDO $p) use ($u, $scope) {
            run($p, 'DELETE FROM formula_items WHERE scope=?', [$scope]);
            audit($p, $u['id'], 'Settings', $scope, 'formula list reset to config/formulas.php');
            return ['message' => 'List reset to the defaults in config/formulas.php.'];
        });
    }

    // ---------- Backup (PDF V.65) ----------
    /** Writes a full SQL dump to storage/backups and returns its name for download. */
    private static function backupCreate(array $u): array {
        requireRole($u, 'admin');
        $p = db();
        $dir = dirname(__DIR__, 3) . '/storage/backups';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $name = 'mulawin_backup_' . date('Ymd_His') . '.sql';
        $fh = fopen($dir . '/' . $name, 'wb');
        must((bool)$fh, 'Cannot write to storage/backups.', 500);
        fwrite($fh, "-- R.P. Mulawin FMS V2 backup " . date('Y-m-d H:i:s') . "\n-- Restore into an EMPTY database with phpMyAdmin Import.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach ($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $create = $p->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$create;\n");
            $stmt = $p->query('SELECT * FROM `' . $table . '`');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $p->quote((string)$v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . $table . '` (`' . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $vals) . ");\n");
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
        audit($p, $u['id'], 'Backup', $name, 'database backup created');
        return ['file' => $name, 'message' => 'Backup created. Download it and keep a copy outside this computer. Also copy the storage/private folder (uploaded files).'];
    }

    private static function notificationsRead(array $u, array $d): array {
        $id = optId($d, 'id');
        if ($id) run(db(), 'UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?', [$id, $u['id']]);
        else run(db(), 'UPDATE notifications SET is_read=1 WHERE user_id=?', [$u['id']]);
        return ['ok' => true];
    }
}
