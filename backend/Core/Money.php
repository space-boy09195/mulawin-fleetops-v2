<?php
/*
 * backend/Core/Money.php  —  shared payment helpers
 * Used by Funds, Finance and Payroll so every money release records the
 * same details (method, cheque number, bank, reference, date) and gets a
 * payment voucher number (PDF II.I: payment vouchers and cheque processing).
 */
declare(strict_types=1);

final class Money {
    /** Payment details shared by every money release (cash / cheque / transfer). */
    public static function paymentFields(array $d): array {
        $method = choiceField($d, 'payment_method', ['Cash', 'Cheque', 'Bank Transfer', 'E-wallet', 'Other']);
        $out = ['payment_method' => $method, 'released_on' => dateField($d, 'released_on'),
                'reference_number' => optField($d, 'reference_number', 100), 'cheque_number' => optField($d, 'cheque_number', 60),
                'bank_name' => optField($d, 'bank_name', 100)];
        if ($method === 'Cheque') must($out['cheque_number'] !== null, 'Cheque number is required for cheque payments.');
        return $out;
    }

    /** Creates a payment voucher number like PV-2026-0001. */
    public static function voucher(PDO $p, array $u, string $type, ?int $refId, string $payee, string $amount, string $status = 'Prepared'): int {
        $vid = insert($p, 'payment_vouchers', ['voucher_number' => 'TMP-' . bin2hex(random_bytes(5)), 'reference_type' => $type, 'reference_id' => $refId,
            'payee' => cut($payee, 180), 'amount' => $amount, 'status' => $status, 'created_by' => $u['id']]);
        run($p, 'UPDATE payment_vouchers SET voucher_number=? WHERE id=?', [refNumber('PV', $vid), $vid]);
        return $vid;
    }
}
