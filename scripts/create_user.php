<?php
/*
 * scripts/create_user.php — create an account from the command line.
 * Usage (XAMPP on Windows, inside the project folder):
 *   C:\xampp\php\php.exe scripts\create_user.php admin myusername "Full Name"
 * A random password is shown ONCE. The user must change it at first login.
 * Sumusunod sa limit bawat role (default: isang Admin). Nakalimutang password: scripts/reset_password.php.
 * Roles: admin, operations_head, car_carrier_dispatcher, container_wingvan_dispatcher,
 *        motorpool, purchasing, finance, billing, payroll, audit
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/backend/bootstrap.php';
$role = $argv[1] ?? ''; $username = $argv[2] ?? ''; $name = $argv[3] ?? '';
if (!$username || !$name || !in_array($role, allowedRoles(), true)) {
    fwrite(STDERR, "Usage: php scripts/create_user.php ROLE USERNAME \"FULL NAME\"\nRoles: " . implode(', ', allowedRoles()) . "\n"); exit(2);
}
if (!preg_match('/^[a-zA-Z0-9_.-]{3,70}$/', $username)) { fwrite(STDERR, "Invalid username.\n"); exit(2); }
$pass = 'Fms' . bin2hex(random_bytes(6)) . random_int(10, 99);
try {
    $p = db(); $p->beginTransaction();
    if ($role !== 'admin' && !one($p, "SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1")) throw new RuntimeException('Create an admin account first.');
    if (one($p, 'SELECT id FROM users WHERE username=?', [$username])) throw new RuntimeException('Username already exists.');
    // Limit bawat role (default: isang Admin). Kapag nakalimutan ang Admin password, gamitin ang recovery code
    // o ang scripts/reset_password.php, hindi bagong Admin.
    core('AccountSecurity');
    if (!roleIsDispatcher($role)) AccountSecurity::checkSlot($p, $role, null);
    $p->prepare('INSERT INTO users (username, password_hash, full_name, role, must_change_password) VALUES (?,?,?,?,1)')
      ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), cut($name, 100), $role]);
    $p->commit();
    echo "Account created: $username ($role)\nTemporary password (shown once): $pass\nThe user will be asked to change it at first login.\n";
} catch (Throwable $e) { if (isset($p) && $p->inTransaction()) $p->rollBack(); fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
