<?php
/*
 * =====================================================================
 *  backend/Modules/Administration/Admin.php  —  HEAD MANAGEMENT: ADMINISTRATION
 * =====================================================================
 *  Objective 4.1  user accounts and role-based access (activate / deactivate)
 *  Objective 4.2  activity log: creation, modification, approval, processing
 *  PDF #11 V.65   backup of system data
 *  Also: system settings and fund request categories (Admin only),
 *        own password, Admin recovery code, e-signature for PO approvals.
 *  Audit can READ the activity log; every save here needs the Admin role.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');
core('AccountSecurity');
core('Files');

final class Admin {
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $from = self::d((string)($q['from'] ?? '')) ?? date('Y-m-d', strtotime('-30 days'));
        $to = self::d((string)($q['to'] ?? '')) ?? date('Y-m-d');
        $w = 'WHERE DATE(a.created_at) BETWEEN ? AND ?'; $args = [$from, $to];
        if (!empty($q['area'])) { $w .= ' AND a.area=?'; $args[] = (string)$q['area']; }
        if (!empty($q['actor_id']) && ctype_digit((string)$q['actor_id'])) { $w .= ' AND a.actor_id=?'; $args[] = (int)$q['actor_id']; }
        $data = readSections([
            'events' => fn() => many($p, "SELECT a.*, COALESCE(us.full_name, 'System') AS actor, us.role FROM audit_events a LEFT JOIN users us ON us.id=a.actor_id $w ORDER BY a.id DESC LIMIT 3000", $args),
            'areas' => fn() => array_column(many($p, 'SELECT DISTINCT area FROM audit_events ORDER BY area'), 'area'),
            'actors' => fn() => many($p, 'SELECT id, full_name FROM users ORDER BY full_name'),
        ]);
        $data['range'] = ['from' => $from, 'to' => $to];
        if (hasRole($u, 'admin')) {
            $data = mergeSections($data, [
                'users' => fn() => many($p, 'SELECT id, username, full_name, role, shift, active, must_change_password, last_login_at, created_at, (signature_document_id IS NOT NULL) AS has_signature FROM users ORDER BY active DESC, role, full_name'),
                'fundCategories' => fn() => many($p, 'SELECT * FROM fund_categories ORDER BY sort_order, name'),
            ]);
            $data['settings'] = Settings::all();
            $dir = dirname(__DIR__, 3) . '/storage/backups';
            $data['backups'] = array_values(array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'date' => date('Y-m-d H:i', filemtime($f))],
                array_reverse(glob($dir . '/mulawin_backup_*.sql') ?: [])));
        }
        $data['roles'] = ROLE_LABELS;
        $data['can'] = ['admin' => hasRole($u, 'admin')];
        return $data;
    }
    private static function d(string $v): ?string { return ($v !== '' && DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $v : null; }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'user_save' => self::userSave($u, $d),
            'user_toggle' => self::userToggle($u, $d),
            'user_password' => self::userPassword($u, $d),
            'change_password' => self::changePassword($u, $d),
            'recovery_code_create' => self::recoveryCodeCreate($u, $d),
            'signature_upload' => self::signatureUpload($u, $d),
            'setting_save' => self::settingSave($u, $d),
            'fund_category_save' => self::fundCategorySave($u, $d),
            'backup_create' => self::backupCreate($u),
            'notifications_read' => self::notificationsRead($u, $d),
            default => throw new BusinessRuleException('Unknown admin action.', 404),
        };
    }

    private static function strongPassword(string $pw): void {
        must(strlen($pw) >= PASSWORD_MIN_LENGTH, 'Password must have at least ' . PASSWORD_MIN_LENGTH . ' characters.');
        must(preg_match('/[A-Za-z]/', $pw) && preg_match('/\d/', $pw), 'Password must have letters and numbers.');
    }

    // ---------------------------------------------------------------- users
    private static function userSave(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = optId($d, 'id');
        $role = choiceField($d, 'role', allowedRoles());
        $shift = roleIsDispatcher($role) ? choiceField($d, 'shift', ['Day', 'Night']) : null;
        $name = field($d, 'full_name', 100);
        return transaction(function (PDO $p) use ($u, $id, $role, $shift, $name, $d) {
            if ($id) {
                if ($id === $u['id']) must($role === 'admin', 'You cannot remove your own Admin role.');
                must((bool)one($p, 'SELECT id FROM users WHERE id=?', [$id]), 'User not found.', 404);
                run($p, 'UPDATE users SET full_name=?, role=?, shift=? WHERE id=?', [$name, $role, $shift, $id]);
                audit($p, $u['id'], 'Access', 'user ' . $id, 'account updated', "$name / $role" . ($shift ? " / $shift" : ''));
                return ['message' => 'Account updated.'];
            }
            $username = field($d, 'username', 70);
            must((bool)preg_match('/^[a-zA-Z0-9_.-]{3,70}$/', $username), 'Username: 3-70 letters, numbers, dot, dash or underscore.');
            $pw = field($d, 'password', 255); self::strongPassword($pw);
            must(!one($p, 'SELECT id FROM users WHERE username=?', [$username]), 'Username already exists.', 409);
            $id = insert($p, 'users', ['username' => $username, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'full_name' => $name,
                'role' => $role, 'shift' => $shift, 'must_change_password' => 1]);
            audit($p, $u['id'], 'Access', 'user ' . $id, 'account created', "$username / $role");
            return ['message' => 'Account created. The user must change the password at first login.'];
        });
    }

    private static function userToggle(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = idField($d, 'user_id'); $active = !empty($d['active']) ? 1 : 0;
        must($id !== $u['id'], 'You cannot deactivate your own account.');
        return transaction(function (PDO $p) use ($u, $id, $active) {
            $t = one($p, 'SELECT role, full_name FROM users WHERE id=? FOR UPDATE', [$id]);
            must((bool)$t, 'User not found.', 404);
            if (!$active && $t['role'] === 'admin') must((int)one($p, "SELECT COUNT(*) n FROM users WHERE role='admin' AND active=1")['n'] > 1, 'Keep at least one active Admin.');
            run($p, 'UPDATE users SET active=? WHERE id=?', [$active, $id]);
            audit($p, $u['id'], 'Access', 'user ' . $id, $active ? 'account activated' : 'account deactivated', $t['full_name']);
            return ['message' => $t['full_name'] . ($active ? ' can log in again.' : ' can no longer log in.')];
        });
    }

    private static function userPassword(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = idField($d, 'user_id'); $pw = field($d, 'password', 255); self::strongPassword($pw);
        return transaction(function (PDO $p) use ($u, $id, $pw) {
            run($p, 'UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            AccountSecurity::passwordChanged($id);
            audit($p, $u['id'], 'Access', 'user ' . $id, 'password reset by Admin');
            return ['message' => 'Password reset. The user must change it at next login.'];
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
            $_SESSION['login_at'] = strtotime(AccountSecurity::passwordChanged($u['id']));
            audit($p, $u['id'], 'Access', 'user ' . $u['id'], 'own password changed');
            return ['message' => 'Password changed.'];
        });
    }

    /** Admin recovery code (no email in the system): printed and kept safe; works once. */
    private static function recoveryCodeCreate(array $u, array $d): array {
        requireRole($u, 'admin');
        $current = field($d, 'current_password', 255);
        $row = one(db(), 'SELECT * FROM users WHERE id=?', [$u['id']]);
        must(password_verify($current, (string)$row['password_hash']), 'Current password is wrong.', 403);
        $code = AccountSecurity::newCode();
        run(db(), 'UPDATE users SET recovery_hash=? WHERE id=?', [password_hash(AccountSecurity::normalizeCode($code), PASSWORD_DEFAULT), $u['id']]);
        audit(db(), $u['id'], 'Access', 'user ' . $u['id'], 'recovery code created', 'the previous code no longer works');
        return ['code' => $code, 'message' => 'Recovery code created. It is shown only once.'];
    }

    /** E-signature image of an approver (printed on approved purchase orders). */
    private static function signatureUpload(array $u, array $d): array {
        must(hasRole($u, 'admin', 'finance'), 'Only purchase order approvers (Admin, Finance) need an e-signature.', 403);
        must(Files::has('signature'), 'Choose the signature image (PNG or JPG).');
        return transaction(function (PDO $p) use ($u) {
            $ids = Files::store($p, $u, 'signature', 'company', null, 'User E-Signature', true);
            $doc = one($p, 'SELECT mime_type FROM documents WHERE id=?', [$ids[0]]);
            must(str_starts_with($doc['mime_type'], 'image/'), 'The signature must be an image (PNG or JPG).');
            run($p, 'UPDATE users SET signature_document_id=? WHERE id=?', [$ids[0], $u['id']]);
            audit($p, $u['id'], 'Access', 'user ' . $u['id'], 'e-signature uploaded');
            return ['message' => 'E-signature saved. It will appear on the purchase orders you approve.'];
        });
    }

    // ---------------------------------------------------------------- settings
    private static function settingSave(array $u, array $d): array {
        requireRole($u, 'admin');
        $key = field($d, 'key', 80);
        must(isset(Settings::DEFS[$key]), 'Unknown setting.');
        [$label, , $unit, , $min, $max] = Settings::DEFS[$key];
        $raw = trim((string)($d['value'] ?? ''));
        must(is_numeric($raw) && (float)$raw >= $min && (float)$raw <= $max, "$label must be a number from $min to $max.");
        return transaction(function (PDO $p) use ($u, $key, $raw, $label) {
            $p->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)')
              ->execute([$key, $raw, $u['id']]);
            Settings::clearCache();
            audit($p, $u['id'], 'Settings', $key, 'setting changed', "$label = $raw");
            return ['message' => "$label saved ($raw). New records use the new value."];
        });
    }

    private static function fundCategorySave(array $u, array $d): array {
        requireRole($u, 'admin');
        $id = optId($d, 'id');
        $name = field($d, 'name', 80); $amount = moneyField($d, 'amount'); $active = !empty($d['active']) ? 1 : 0;
        $order = (int)(intField($d, 'sort_order', 0, false) ?? 0);
        return transaction(function (PDO $p) use ($u, $id, $name, $amount, $active, $order) {
            must(!one($p, 'SELECT id FROM fund_categories WHERE name=? AND id<>?', [$name, $id ?? 0]), 'Category "' . $name . '" already exists.', 409);
            if ($id) run($p, 'UPDATE fund_categories SET name=?, amount=?, active=?, sort_order=?, updated_by=? WHERE id=?', [$name, $amount, $active, $order, $u['id'], $id]);
            else insert($p, 'fund_categories', ['name' => $name, 'amount' => $amount, 'active' => $active, 'sort_order' => $order, 'updated_by' => $u['id']]);
            audit($p, $u['id'], 'Settings', 'fund category', 'fund category saved', "$name PHP $amount" . ($active ? '' : ' (inactive)'));
            return ['message' => 'Fund category saved. Old fund requests keep their amounts.'];
        });
    }

    // ---------------------------------------------------------------- backup
    private static function backupCreate(array $u): array {
        requireRole($u, 'admin');
        $p = db();
        $dir = dirname(__DIR__, 3) . '/storage/backups';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $name = 'mulawin_backup_' . date('Ymd_His') . '.sql';
        $fh = fopen($dir . '/' . $name, 'wb');
        must((bool)$fh, 'Cannot write to storage/backups.', 500);
        fwrite($fh, "-- Mulawin FleetOps backup " . date('Y-m-d H:i:s') . "\n-- Restore into an EMPTY database with phpMyAdmin Import.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
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
        return ['file' => $name, 'message' => 'Backup created. Download it and keep a copy outside this computer, together with the storage/private folder (uploaded files).'];
    }

    private static function notificationsRead(array $u, array $d): array {
        $id = optId($d, 'id');
        if ($id) run(db(), 'UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?', [$id, $u['id']]);
        else run(db(), 'UPDATE notifications SET is_read=1 WHERE user_id=?', [$u['id']]);
        return ['ok' => true];
    }
}
