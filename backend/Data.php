<?php
/*
 * backend/Data.php  —  session information for the screen
 * Returns who is signed in, the sidebar pages for the role and the
 * latest notifications. Page data is loaded separately per page by
 * Actions::pageData(), so one broken page never blocks the others.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

final class Data {
    public static function me(): array {
        core('Access');
        $u = currentUser();
        $notes = []; $unread = 0;
        try {
            $notes = many(db(), 'SELECT id, category, message, page, is_read, created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 40', [$u['id']]);
            $unread = (int)one(db(), 'SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0', [$u['id']])['n'];
        } catch (Throwable $e) { error_log('Notifications unavailable: ' . $e->getMessage()); }
        // Wika ng screen (en o tl). Kapag hindi pa na-import ang migration, English muna (hindi nasisira ang login).
        try { $u['language'] = (string)(one(db(), 'SELECT language FROM users WHERE id=?', [$u['id']])['language'] ?? 'en'); }
        catch (Throwable $e) { $u['language'] = 'en'; }
        return [
            'ok' => true, 'user' => $u, 'csrf' => $_SESSION['csrf'] ?? '',
            'nav' => Access::navFor($u), 'sections' => Access::SECTIONS,
            'notifications' => $notes, 'unread' => $unread,
            'company' => ['name' => envValue('COMPANY_NAME', 'R P MULAWIN TRUCKING SERVICE'),
                          'address' => envValue('COMPANY_ADDRESS', '#086-A Purok 2 Brgy. Luyos Tanauan City, Batangas')],
            'truckTypes' => TRUCK_TYPES, 'passwordMin' => PASSWORD_MIN_LENGTH, 'roles' => ROLE_LABELS,
        ];
    }
}
