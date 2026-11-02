<?php
/*
 * scripts/reset_password.php — HULING PARAAN kapag hindi makapasok ang Admin at wala ring recovery code.
 * Para sa IT / developer na may access sa server (XAMPP sa computer, o Hostinger SSH kung meron).
 * Usage (XAMPP sa Windows, sa loob ng project folder):
 *   C:\xampp\php\php.exe scripts\reset_password.php USERNAME
 * Gagawa ng bagong temporary password (lalabas nang isang beses). Papalitan ito sa unang login.
 * Naa-unlock din ang account at mala-log out ang lahat ng lumang session nito.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require dirname(__DIR__) . '/backend/bootstrap.php';
core('AccountSecurity');
$username = $argv[1] ?? '';
if ($username === '') { fwrite(STDERR, "Usage: php scripts/reset_password.php USERNAME\n"); exit(2); }
try {
    $p = db();
    $u = one($p, 'SELECT id, role, active FROM users WHERE username=?', [$username]);
    if (!$u) throw new RuntimeException('Username not found.');
    $pass = 'Fms' . bin2hex(random_bytes(6)) . random_int(10, 99);
    run($p, 'UPDATE users SET password_hash=?, must_change_password=1, active=1 WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    AccountSecurity::passwordChanged((int)$u['id']);
    audit($p, null, 'Access', 'user ' . $u['id'], 'password reset from the server (scripts/reset_password.php)');
    echo "Password reset: $username ({$u['role']})\nTemporary password (shown once): $pass\nThe user will be asked to change it at first login.\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
