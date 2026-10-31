<?php
/*
 * =====================================================================
 *  backend/Modules/Documents.php  —  DOCUMENT MANAGEMENT
 * =====================================================================
 *  FMS PDF II.M and II.O:
 *    - upload scanned documents, photos and files (bulk upload allowed)
 *    - attach to trips, vehicles, employees, transactions, maintenance
 *    - search, view, download by authorized users
 *    - categories, descriptions, expiry reminders
 *    - Admin keeps OR/CR, insurance, contracts, forms and templates
 *  Files are saved in storage/private with random names; they are only
 *  given out through the download check below.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');

final class Documents {
    private const MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const MAX_BYTES = 10000000; // 10 MB per file
    /** Record type -> table that must contain the linked id. */
    public const ENTITY_TABLES = [
        'trip' => 'trips', 'vehicle' => 'vehicles', 'personnel' => 'personnel', 'client' => 'clients',
        'maintenance' => 'maintenance_jobs', 'inspection' => 'inspections', 'purchase' => 'purchase_requests',
        'parts' => 'parts', 'fund' => 'fund_requests', 'expense' => 'expenses', 'payable' => 'accounts_payable',
        'voucher' => 'payment_vouchers', 'billing' => 'billings', 'waybill' => 'waybills', 'payroll' => 'payroll_periods',
        'administrative' => null,
    ];
    public const CATEGORIES = ['OR/CR', 'Insurance', 'Contract / Agreement', 'Company Form / Template', 'Permit / Pass', 'Waybill', 'Receipt / Invoice',
                               'Inspection Photo', 'Repair Document', 'Purchase Document', 'Payroll / Payslip', 'Employee Record', 'Other'];

    /** Upload rights per role (PDF role list). */
    private static function canUpload(string $role, string $type): bool {
        if ($role === 'admin') return true;
        return in_array($type, match ($role) {
            'operations_head' => ['trip', 'inspection', 'waybill'],
            'car_carrier_dispatcher', 'container_wingvan_dispatcher' => ['trip', 'inspection', 'waybill'],
            'motorpool' => ['vehicle', 'maintenance', 'inspection'],
            'purchasing' => ['purchase', 'parts'],
            'finance' => ['fund', 'expense', 'payable', 'voucher'],
            'billing' => ['billing', 'waybill', 'client', 'trip'],
            'payroll' => ['payroll', 'personnel'],
            default => [],
        }, true);
    }
    /** View rights: upload rights plus read-only access (Audit = financial records only). */
    private static function canView(string $role, string $type): bool {
        if (self::canUpload($role, $type)) return true;
        return in_array($type, match ($role) {
            'operations_head' => ['vehicle', 'maintenance', 'personnel'],
            'car_carrier_dispatcher', 'container_wingvan_dispatcher' => ['vehicle'],
            'motorpool' => ['trip'],
            'finance' => ['purchase', 'payroll', 'billing'],
            'audit' => ['fund', 'expense', 'payable', 'voucher', 'billing', 'waybill', 'purchase', 'payroll'],
            default => [],
        }, true);
    }

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $types = array_values(array_filter(array_keys(self::ENTITY_TABLES), fn($t) => self::canView($u['role'], $t)));
        $data = readSections([
            'documents' => function () use ($p, $u, $types) {
                if (!$types) return [];
                $marks = implode(',', array_fill(0, count($types), '?'));
                $rows = many($p, "SELECT d.id, d.category, d.entity_type, d.entity_id, d.original_filename, d.mime_type, d.byte_size, d.expires_on, d.notes, d.created_at,
                        DATEDIFF(d.expires_on, CURRENT_DATE()) AS days_left, us.full_name AS uploader
                    FROM documents d JOIN users us ON us.id=d.uploaded_by WHERE d.entity_type IN ($marks) ORDER BY d.id DESC LIMIT 2000", $types);
                if (roleIsDispatcher($u['role'])) {
                    $rows = array_values(array_filter($rows, fn($d) => $d['entity_type'] !== 'trip' || self::dispatcherTrip($p, $u, (int)$d['entity_id'])));
                }
                return $rows;
            },
        ]);
        $data['uploadTypes'] = array_values(array_filter(array_keys(self::ENTITY_TABLES), fn($t) => self::canUpload($u['role'], $t)));
        $data['categories'] = self::CATEGORIES;
        $data['alertDays'] = Settings::value('document_alert_days');
        return $data;
    }

    private static function dispatcherTrip(PDO $p, array $u, int $tripId): bool {
        return (bool)one($p, 'SELECT id FROM trips WHERE id=? AND (dispatcher_role=? OR created_by=?)', [$tripId, $u['role'], $u['id']]);
    }

    public static function action(array $u, string $action, array $d): array {
        throw new BusinessRuleException('Use the upload form for documents.', 404);
    }

    private static function assertLink(PDO $p, array $u, string $type, ?int $id): void {
        must(array_key_exists($type, self::ENTITY_TABLES), 'Unknown record type.');
        $table = self::ENTITY_TABLES[$type];
        if ($table === null) { must($id === null, 'Administrative documents are not linked to a record number.'); return; }
        must($id !== null, 'Enter the record number (ID) this file belongs to.');
        must((bool)one($p, "SELECT id FROM $table WHERE id=?", [$id]), 'The linked record was not found.', 404);
        if ($type === 'trip' && roleIsDispatcher($u['role'])) must(self::dispatcherTrip($p, $u, $id), 'This trip belongs to the other dispatcher team.', 403);
    }

    /** POST multipart: one or many files (bulk upload). */
    public static function upload(array $u): array {
        $type = trim((string)($_POST['entity_type'] ?? ''));
        must(self::canUpload($u['role'], $type), 'You cannot upload this type of document.', 403);
        $category = trim((string)($_POST['category'] ?? ''));
        must($category !== '' && textLength($category) <= 80, 'Choose a document category.');
        $eid = trim((string)($_POST['entity_id'] ?? ''));
        $eid = $eid === '' ? null : filter_var($eid, FILTER_VALIDATE_INT);
        must($eid === null || ($eid !== false && $eid > 0), 'Invalid record number.');
        $expiry = trim((string)($_POST['expires_on'] ?? ''));
        if ($expiry !== '') must((bool)DateTimeImmutable::createFromFormat('!Y-m-d', $expiry), 'Invalid expiry date.');
        $notes = cut(trim((string)($_POST['notes'] ?? '')), 255);
        self::assertLink(db(), $u, $type, $eid);

        // Normalise single and multiple file inputs into one list.
        $files = [];
        $f = $_FILES['files'] ?? ($_FILES['file'] ?? null);
        must(is_array($f), 'No file received. Check the upload size limit in PHP (upload_max_filesize).');
        if (is_array($f['name'])) { foreach ($f['name'] as $i => $n) $files[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]]; }
        else $files[] = $f;
        must(count($files) <= 20, 'Upload at most 20 files at a time.');

        $dir = dirname(__DIR__, 2) . '/storage/private';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $saved = []; $moved = [];
        try {
            $p = db(); $p->beginTransaction();
            foreach ($files as $file) {
                must(($file['error'] ?? 1) === UPLOAD_ERR_OK, 'Upload failed for ' . basename((string)$file['name']) . '.');
                $bytes = (int)$file['size'];
                must($bytes > 0 && $bytes <= self::MAX_BYTES, basename((string)$file['name']) . ' is larger than 10 MB.');
                must(is_uploaded_file($file['tmp_name']), 'Invalid upload.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                must(isset(self::MIME[$mime]), 'Only PDF, JPG, PNG and WebP files are allowed (' . basename((string)$file['name']) . ').');
                $original = cut(basename((string)$file['name']), 220);
                $storage = bin2hex(random_bytes(20)) . '.' . self::MIME[$mime];
                must(move_uploaded_file($file['tmp_name'], $dir . '/' . $storage), 'Could not save the file. Check folder permissions of storage/private.', 500);
                $moved[] = $dir . '/' . $storage;
                $id = insert($p, 'documents', ['category' => $category, 'entity_type' => $type, 'entity_id' => $eid, 'original_filename' => $original,
                    'storage_name' => $storage, 'mime_type' => $mime, 'byte_size' => $bytes, 'expires_on' => $expiry ?: null, 'notes' => $notes ?: null, 'uploaded_by' => $u['id']]);
                audit($p, $u['id'], 'Documents', 'document ' . $id, 'document uploaded', "$category / $type " . ($eid ?? '') . ' / ' . $original);
                $saved[] = $id;
            }
            $p->commit();
        } catch (Throwable $e) {
            if (isset($p) && $p->inTransaction()) $p->rollBack();
            foreach ($moved as $m) @unlink($m);
            throw $e;
        }
        return ['documents' => $saved, 'message' => count($saved) . ' file(s) uploaded.'];
    }

    /** Authenticated download / inline view. */
    public static function download(array $u, int $id, bool $inline): never {
        $p = db();
        $d = one($p, 'SELECT * FROM documents WHERE id=?', [$id]);
        must((bool)$d, 'Document not found.', 404);
        must(self::canView($u['role'], $d['entity_type']), 'You cannot open this document.', 403);
        if ($d['entity_type'] === 'trip' && roleIsDispatcher($u['role'])) must(self::dispatcherTrip($p, $u, (int)$d['entity_id']), 'You cannot open this document.', 403);
        $file = dirname(__DIR__, 2) . '/storage/private/' . $d['storage_name'];
        must(is_file($file), 'The file is missing on the server.', 404);
        header('Content-Type: ' . $d['mime_type']);
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '_', $d['original_filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }
}
