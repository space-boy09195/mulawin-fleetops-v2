<?php
/*
 * =====================================================================
 *  backend/Core/AccountSecurity.php  —  simpleng proteksyon ng accounts
 * =====================================================================
 *  Walang email o SMS ang system, kaya ganito ang proteksyon (walang dagdag na setup):
 *  1. Lock ng account: 5 maling password (kahit saang computer) = naka-lock nang 15 minuto.
 *  2. Recovery code ng Admin: isang code na ipi-print at itatago. Kapag nakalimutan o na-hack ang
 *     Admin password, gamitin ito sa login page ("Forgot the Admin password?"). Isang beses lang gumagana.
 *  3. Kapag napalitan ang password (ng user, ng Admin o gamit ang recovery code), lahat ng lumang
 *     login session ng account na iyon ay mala-log out.
 *  4. Limit ng users bawat role (User Accounts > Limits per role). Dispatchers: bawat shift.
 *  Kailangan ang migration database/migrations/2026-11-04_account_security.sql sa existing na database.
 *  Kapag wala pa ang migration, gumagana pa rin ang login (walang lock at recovery hanggang ma-import).
 *  Huling paraan (IT / developer, may access sa server): php scripts/reset_password.php USERNAME
 * =====================================================================
 */
declare(strict_types=1);

final class AccountSecurity {
    public const MAX_TRIES = 5;
    public const LOCK_MINUTES = 15;
    /** Default na limit bawat role (0 = walang limit). Dispatchers: bawat shift (Day / Night). */
    public const LIMIT_DEFAULTS = ['admin' => 1, 'operations_head' => 1, 'car_carrier_dispatcher' => 1, 'container_wingvan_dispatcher' => 1,
        'motorpool' => 1, 'purchasing' => 1, 'finance' => 1, 'billing' => 1, 'payroll' => 1, 'audit' => 1];

    public static function now(): string { return date('Y-m-d H:i:s'); }

    public static function isLocked(array $row): bool {
        return !empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time();
    }

    /** Maling password: dagdagan ang bilang; sa ika-5, i-lock nang 15 minuto. Ibinabalik kung na-lock. */
    public static function failure(array $row): bool {
        try {
            $n = (int)($row['failed_logins'] ?? 0) + 1;
            $lock = $n >= self::MAX_TRIES ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            run(db(), 'UPDATE users SET failed_logins=?, locked_until=? WHERE id=?', [$lock ? 0 : $n, $lock, $row['id']]);
            if ($lock) audit(db(), (int)$row['id'], 'Access', 'user ' . $row['id'], 'account locked', self::MAX_TRIES . ' wrong passwords; locked for ' . self::LOCK_MINUTES . ' minutes');
            return (bool)$lock;
        } catch (Throwable $e) { error_log('Account lock unavailable: ' . $e->getMessage()); return false; }
    }

    public static function success(int $id): void {
        try { run(db(), 'UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?', [$id]); }
        catch (Throwable $e) { error_log('Account lock unavailable: ' . $e->getMessage()); }
    }

    /** Itala kung kailan napalitan ang password (para ma-log out ang lumang sessions). */
    public static function passwordChanged(int $id): string {
        $now = self::now();
        try { run(db(), 'UPDATE users SET password_changed_at=?, failed_logins=0, locked_until=NULL WHERE id=?', [$now, $id]); }
        catch (Throwable $e) { error_log('password_changed_at unavailable: ' . $e->getMessage()); }
        return $now;
    }

    /** True kapag napalitan ang password pagkatapos mag-login ang session na ito. */
    public static function sessionOutdated(int $id): bool {
        try { $pc = one(db(), 'SELECT password_changed_at FROM users WHERE id=?', [$id])['password_changed_at'] ?? null; }
        catch (Throwable $e) { return false; }
        return $pc !== null && strtotime((string)$pc) > (int)($_SESSION['login_at'] ?? 0);
    }

    /** Recovery code: 16 letra at numero (walang O/0/I/1 para hindi malito), naka-grupo ng 4. */
    public static function newCode(): string {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
        for ($i = 0; $i < 16; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
        return implode('-', str_split($s, 4));
    }
    public static function normalizeCode(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); }

    public static function hasRecovery(int $id): ?bool {
        try { return !empty(one(db(), 'SELECT recovery_hash FROM users WHERE id=?', [$id])['recovery_hash']); }
        catch (Throwable $e) { return null; }
    }

    // ------------------------------------------------------------- limit bawat role
    public static function limits(): array {
        $out = self::LIMIT_DEFAULTS;
        try {
            foreach (many(db(), "SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'role_limit_%'") as $r) {
                $role = substr($r['setting_key'], 11);
                if (isset($out[$role]) && is_numeric($r['setting_value'])) $out[$role] = max(0, (int)$r['setting_value']);
            }
        } catch (Throwable $e) { error_log('Role limits unavailable: ' . $e->getMessage()); }
        $out['admin'] = max(1, $out['admin']);
        return $out;
    }

    /** Bilang ng active na accounts bawat role, at bawat role+shift para sa dispatchers. */
    public static function counts(PDO $p): array {
        $c = [];
        foreach (many($p, 'SELECT role, COALESCE(shift, \'\') AS shift, COUNT(*) n FROM users WHERE active=1 GROUP BY role, shift') as $r) {
            $c[$r['role']] = ($c[$r['role']] ?? 0) + (int)$r['n'];
            if ($r['shift'] !== '') $c[$r['role'] . '|' . $r['shift']] = (int)$r['n'];
        }
        return $c;
    }

    /** Hihinto kapag puno na ang role (o ang shift ng dispatcher). $excludeId = account na ine-edit. */
    public static function checkSlot(PDO $p, string $role, ?string $shift, int $excludeId = 0): void {
        $limit = self::limits()[$role] ?? 0;
        if ($limit <= 0) return;
        $label = ROLE_LABELS[$role] ?? $role;
        if (roleIsDispatcher($role)) {
            must((bool)$shift, 'Choose the shift (Day or Night) of the dispatcher.');
            $n = (int)one($p, 'SELECT COUNT(*) n FROM users WHERE active=1 AND role=? AND shift=? AND id<>?', [$role, $shift, $excludeId])['n'];
            must($n < $limit, "$label ($shift shift) already has $n active account(s). Limit: $limit per shift. Deactivate the old account first, or change the limit in User Accounts > Limits per role.", 409);
            return;
        }
        $n = (int)one($p, 'SELECT COUNT(*) n FROM users WHERE active=1 AND role=? AND id<>?', [$role, $excludeId])['n'];
        must($n < $limit, "$label already has $n active account(s). Limit: $limit. Deactivate the old account first, or change the limit in User Accounts > Limits per role.", 409);
    }
}
