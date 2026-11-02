<?php
/*
 * =====================================================================
 *  public/index.php  —  the only page the browser opens
 * =====================================================================
 *  1. Without ?api=  -> sends the HTML shell (login / app screen).
 *  2. With ?api=...  -> JSON API used by the screen:
 *       whoami, login, recover, logout, me session (recover = Admin recovery code)
 *       data&page=xyz                      data for one page
 *       Module.action  (POST JSON)         save something
 *       document_upload / document_download / backup_download
 *  All POST requests need the CSRF token.
 * =====================================================================
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/bootstrap.php';
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once dirname(__DIR__) . '/backend/Data.php';

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
core('AccountSecurity');
const LOCKED_MSG = 'This account is locked for 15 minutes because of too many wrong passwords. Try again later.';

function checkCsrf(?string $given): void {
    must(hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$given), 'Security token expired. Please reload the page.', 403);
}

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    try {
        switch ($api) {
            case 'whoami':
                jsonOut(['ok' => true, 'signedIn' => (bool)($_SESSION['uid'] ?? false), 'csrf' => $_SESSION['csrf']]);
            case 'login':
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                $d = inputData();
                checkCsrf($d['csrf'] ?? '');
                $username = field($d, 'username', 70); $password = field($d, 'password', 255);
                // Simple throttle: 5 wrong tries in 15 minutes per browser session.
                $now = time();
                $fails = array_values(array_filter($_SESSION['login_failures'] ?? [], fn($t) => $now - (int)$t < 900));
                must(count($fails) < 5, 'Too many wrong tries. Please wait 15 minutes.', 429);
                $row = one(db(), 'SELECT * FROM users WHERE username=? AND active=1', [$username]);
                // Lock ng account (kahit saang computer): 5 maling password = 15 minutong naka-lock.
                must(!$row || !AccountSecurity::isLocked($row), LOCKED_MSG, 429);
                if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                    $fails[] = $now; $_SESSION['login_failures'] = $fails;
                    if ($row && AccountSecurity::failure($row)) fail(LOCKED_MSG, 429);
                    fail('Wrong username or password.' . (count($fails) >= 3 ? ' (' . (5 - count($fails)) . ' tries left)' : ''), 401);
                }
                must(in_array($row['role'], allowedRoles(), true), 'Account role is not allowed.', 403);
                unset($_SESSION['login_failures']);
                AccountSecurity::success((int)$row['id']);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$row['id']; $_SESSION['last_seen'] = time(); $_SESSION['login_at'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(24));
                run(db(), 'UPDATE users SET last_login_at=NOW() WHERE id=?', [$row['id']]);
                audit(db(), (int)$row['id'], 'Access', 'user ' . $row['id'], 'signed in');
                jsonOut(['ok' => true]);
            case 'recover':
                // Nakalimutan / na-hack ang Admin password: recovery code (naka-print at nakatago) + bagong password.
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                $d = inputData();
                checkCsrf($d['csrf'] ?? '');
                $username = field($d, 'username', 70); $code = AccountSecurity::normalizeCode(field($d, 'code', 40)); $new = field($d, 'new_password', 255);
                $now = time();
                $fails = array_values(array_filter($_SESSION['login_failures'] ?? [], fn($t) => $now - (int)$t < 900));
                must(count($fails) < 5, 'Too many wrong tries. Please wait 15 minutes.', 429);
                $row = one(db(), "SELECT * FROM users WHERE username=? AND active=1 AND role='admin'", [$username]);
                must(!$row || array_key_exists('recovery_hash', $row), 'Recovery is not ready yet. Ask IT to import database/migrations/2026-11-04_account_security.sql.', 409);
                must(!$row || !AccountSecurity::isLocked($row), LOCKED_MSG, 429);
                if (!$row || empty($row['recovery_hash']) || !password_verify($code, (string)$row['recovery_hash'])) {
                    $fails[] = $now; $_SESSION['login_failures'] = $fails;
                    if ($row && AccountSecurity::failure($row)) fail(LOCKED_MSG, 429);
                    fail('Wrong Admin username or recovery code.', 401);
                }
                must(strlen($new) >= PASSWORD_MIN_LENGTH && preg_match('/[A-Za-z]/', $new) && preg_match('/\d/', $new), 'Password must have at least ' . PASSWORD_MIN_LENGTH . ' characters with letters and numbers.');
                run(db(), 'UPDATE users SET password_hash=?, must_change_password=0, recovery_hash=NULL WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $row['id']]);
                AccountSecurity::passwordChanged((int)$row['id']); // lahat ng lumang session ng account ay mala-log out
                audit(db(), (int)$row['id'], 'Access', 'user ' . $row['id'], 'password reset with recovery code', 'old sessions signed out; recovery code used up');
                unset($_SESSION['login_failures']);
                jsonOut(['ok' => true, 'data' => ['message' => 'Password reset. Log in with the new password, then make a new recovery code (the old one no longer works).']]);
            case 'logout':
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
                session_unset(); session_destroy();
                jsonOut(['ok' => true]);
            case 'me':
                jsonOut(Data::me());
            case 'data':
                jsonOut(['ok' => true, 'data' => Actions::pageData((string)($_GET['page'] ?? ''), $_GET)]);
            case 'document_upload':
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                checkCsrf($_POST['csrf'] ?? '');
                $u = currentUser();
                Actions::load('Documents');
                jsonOut(['ok' => true, 'data' => Documents::upload($u)]);
            case 'document_download':
                $u = currentUser();
                $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
                must($id !== false && $id > 0, 'Invalid document.');
                Actions::load('Documents');
                Documents::download($u, (int)$id, !empty($_GET['inline']));
            case 'backup_download':
                $u = currentUser(); requireRole($u, 'admin');
                $name = basename((string)($_GET['file'] ?? ''));
                must((bool)preg_match('/^mulawin_backup_\d{8}_\d{6}\.sql$/', $name), 'Invalid backup file.');
                $file = dirname(__DIR__) . '/storage/backups/' . $name;
                must(is_file($file), 'Backup not found.', 404);
                header('Content-Type: application/sql'); header('Content-Disposition: attachment; filename="' . $name . '"');
                header('Content-Length: ' . filesize($file)); readfile($file); exit;
            default:
                must($_SERVER['REQUEST_METHOD'] === 'POST', 'POST required.', 405);
                checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
                jsonOut(['ok' => true, 'data' => Actions::run($api, inputData())]);
        }
    } catch (BusinessRuleException $e) {
        fail($e->getMessage(), $e->httpStatus);
    } catch (ModuleUnavailable $e) {
        fail($e->getMessage(), 503);
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        $msg = str_contains($e->getMessage(), 'Duplicate entry') ? 'This record already exists (duplicate).' : 'Database problem. Check the setup and the server error log.';
        fail($msg, 500);
    } catch (Throwable $e) {
        error_log('Unexpected error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        fail('Unexpected error. See the server error log.', 500);
    }
}

// ---------------- HTML shell ----------------
// Each module script is separate: if one file fails, the other pages still work.
// Screen files naka-grupo ayon sa department (public/assets/js/modules/<department>/<file>.js).
$jsModules = ['general/dashboard', 'operations/fleet', 'operations/trips', 'maintenance/maintenance', 'maintenance/purchasing',
              'accounting/finance', 'accounting/billing', 'accounting/payroll', 'general/documents', 'general/reports', 'administration/admin'];
$v = static fn(string $f): string => is_file(__DIR__ . '/' . $f) ? (string)filemtime(__DIR__ . '/' . $f) : '1';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Mulawin FleetOps — R.P. Mulawin Fleet Management System</title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/layout.css?v=<?= $v('assets/css/layout.css') ?>">
  <link rel="stylesheet" href="assets/css/login.css?v=<?= $v('assets/css/login.css') ?>">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= $v('assets/css/app.css') ?>">
</head>
<body>
  <div id="app"><div class="text-center p-5 text-muted">Loading Mulawin FleetOps…</div></div>
  <div class="toast-zone" id="toastZone" aria-live="polite"></div>
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/chartjs/chart.umd.min.js"></script>
  <script src="assets/js/core.js?v=<?= $v('assets/js/core.js') ?>"></script>
  <script src="assets/js/i18n.js?v=<?= $v('assets/js/i18n.js') ?>"></script>
  <script src="assets/js/i18n/en.js?v=<?= $v('assets/js/i18n/en.js') ?>"></script>
  <script src="assets/js/i18n/tl.js?v=<?= $v('assets/js/i18n/tl.js') ?>"></script>
<?php foreach ($jsModules as $m): ?>
  <script src="assets/js/modules/<?= $m ?>.js?v=<?= $v('assets/js/modules/' . $m . '.js') ?>"></script>
<?php endforeach; ?>
  <script>App.start();</script>
</body>
</html>
