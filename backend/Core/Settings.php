<?php
/*
 * =====================================================================
 *  backend/Core/Settings.php  —  FORMULA ENGINE
 * =====================================================================
 *  Lahat ng computation ng system ay dumadaan dito para iisang lugar
 *  lang ang babaguhin kapag nagbago ang formula ng kumpanya.
 *
 *  Saan galing ang value?
 *    1. Kung may naka-save sa database (Formula Settings page)  -> iyon.
 *    2. Kung wala                                                -> config/formulas.php
 *
 *  Main functions:
 *    Settings::value('km_per_liter')         -> isang numero
 *    Settings::items('payroll_deduction')    -> listahan ng computation
 *    Settings::fuelCost($km)                 -> (km ÷ km/L) × presyo
 *    Settings::computeItems($scope, $ctx)    -> kino-compute ang listahan
 *    Settings::snapshot([...])               -> kopya ng values na ginamit
 *                                               (sine-save sa record para
 *                                               hindi magbago ang luma)
 * =====================================================================
 */
declare(strict_types=1);

final class Settings {
    /** Allowed calc types. Must match the explanation in config/formulas.php. */
    public const CALC_TYPES = ['fixed', 'percent_of_rate', 'per_day', 'per_km', 'per_trip', 'fuel', 'route_toll', 'manual', 'ledger'];
    /** Which calc types make sense for each list. */
    public const SCOPE_CALCS = [
        'trip_allowance'    => ['fixed', 'per_km', 'fuel', 'route_toll', 'percent_of_rate'],
        'trip_pnl_expense'  => ['fixed', 'per_km', 'fuel', 'route_toll', 'percent_of_rate'],
        'payroll_earning'   => ['manual', 'fixed', 'per_day', 'per_trip'],
        'payroll_deduction' => ['manual', 'fixed', 'per_day', 'per_trip', 'ledger'],
    ];
    public const SCOPE_LABELS = [
        'trip_allowance'    => 'Trip allowance / fund request (automatic)',
        'trip_pnl_expense'  => 'Projected P&L expenses per trip',
        'payroll_earning'   => 'Payroll additional earnings',
        'payroll_deduction' => 'Payroll deductions',
    ];
    /** Which roles may edit which settings group / item scope (Admin may edit everything). */
    public const EDITORS = [
        'Approvals' => ['admin'],
        'Trip Allowance and Fuel' => ['admin', 'finance'],
        'Car Carrier' => ['admin'],
        'Maintenance' => ['admin'],
        'Inspection' => ['admin'],
        'Reminders' => ['admin'],
        'Billing' => ['admin', 'finance'],
        'Payroll' => ['admin', 'payroll'],
        'Auto-assignment' => ['admin'],
        'trip_allowance' => ['admin', 'finance'],
        'trip_pnl_expense' => ['admin', 'finance'],
        'payroll_earning' => ['admin', 'payroll'],
        'payroll_deduction' => ['admin', 'payroll'],
    ];

    private static ?array $defaults = null;
    private static ?array $saved = null;

    /** Defaults from config/formulas.php */
    public static function defaults(): array {
        if (self::$defaults === null) {
            $file = dirname(__DIR__, 2) . '/config/formulas.php';
            $cfg = is_file($file) ? require $file : [];
            self::$defaults = ['values' => $cfg['values'] ?? [], 'items' => $cfg['items'] ?? []];
        }
        return self::$defaults;
    }

    /** Saved overrides from the database (read once per request). */
    private static function saved(): array {
        if (self::$saved === null) {
            self::$saved = [];
            try {
                foreach (many(db(), 'SELECT setting_key, setting_value FROM app_settings') as $r) {
                    self::$saved[$r['setting_key']] = $r['setting_value'];
                }
            } catch (Throwable $e) {
                error_log('Settings read failed, using config defaults: ' . $e->getMessage());
            }
        }
        return self::$saved;
    }
    public static function clearCache(): void { self::$saved = null; }

    /** One number. Example: Settings::value('pm_interval_km') -> 10000 */
    public static function value(string $key): float {
        $saved = self::saved();
        if (array_key_exists($key, $saved) && is_numeric($saved[$key])) return (float)$saved[$key];
        $def = self::defaults()['values'][$key]['value'] ?? null;
        must($def !== null, "Formula setting '$key' is missing in config/formulas.php.", 500);
        return (float)$def;
    }

    /** All values with labels for the Formula Settings page. */
    public static function allValues(): array {
        $out = [];
        foreach (self::defaults()['values'] as $key => $meta) {
            $out[] = [
                'key' => $key, 'group' => $meta['group'] ?? 'Other', 'label' => $meta['label'] ?? $key,
                'unit' => $meta['unit'] ?? '', 'help' => $meta['help'] ?? '', 'source' => $meta['source'] ?? '',
                'default' => $meta['value'], 'value' => self::value($key),
                'is_default' => !array_key_exists($key, self::saved()),
            ];
        }
        return $out;
    }

    /** List of computation items for a scope (database list if customized, otherwise config defaults). */
    public static function items(string $scope, bool $activeOnly = true): array {
        must(isset(self::SCOPE_CALCS[$scope]), 'Unknown formula list.', 500);
        $rows = [];
        try {
            $rows = many(db(), 'SELECT * FROM formula_items WHERE scope=? ORDER BY sort_order, id', [$scope]);
        } catch (Throwable $e) {
            error_log('formula_items read failed, using defaults: ' . $e->getMessage());
        }
        if (!$rows) {
            $rows = [];
            foreach (self::defaults()['items'][$scope] ?? [] as $i => $it) {
                $rows[] = ['id' => null, 'scope' => $scope, 'name' => $it['name'], 'calc' => $it['calc'],
                    'amount' => (float)($it['amount'] ?? 0), 'divisor' => (float)($it['divisor'] ?? 1),
                    'only_if_attended' => !empty($it['only_if_attended']) ? 1 : 0, 'ledger' => $it['ledger'] ?? null,
                    'source' => $it['source'] ?? '', 'active' => 1, 'sort_order' => $i, 'is_default' => true];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $r['amount'] = (float)$r['amount'];
            $r['divisor'] = (float)($r['divisor'] ?: 1);
            $r['only_if_attended'] = (int)$r['only_if_attended'];
            $r['active'] = (int)$r['active'];
            if ($activeOnly && !$r['active']) continue;
            $out[] = $r;
        }
        return $out;
    }

    // -----------------------------------------------------------------
    //  FORMULAS
    // -----------------------------------------------------------------

    /** Fuel cost = (km ÷ km per liter) × price per liter.  (Billing P&L G53, G54) */
    public static function fuelCost(float $km): float {
        $kmPerLiter = self::value('km_per_liter');
        if ($kmPerLiter <= 0) return 0.0;
        return round(($km / $kmPerLiter) * self::value('fuel_price_per_liter'), 2);
    }

    /**
     * Computes every active item in a list.
     * $ctx keys used (all optional):
     *   km, toll, rate (trip rate / gross sales), days (days worked),
     *   trips (number of trips), manual => [name => amount],
     *   ledgers => [ledger type => installment amount]
     * Returns ['lines' => [[name, calc, amount, explain]], 'total' => float]
     */
    public static function computeItems(string $scope, array $ctx): array {
        $lines = []; $total = 0.0;
        $days = (float)($ctx['days'] ?? 0);
        foreach (self::items($scope) as $it) {
            $amt = 0.0; $explain = '';
            $a = (float)$it['amount'];
            switch ($it['calc']) {
                case 'fixed':
                    $amt = $a; $explain = 'fixed ' . number_format($a, 2); break;
                case 'percent_of_rate':
                    $rate = (float)($ctx['rate'] ?? 0);
                    $amt = $rate * $a / 100; $explain = number_format($a, 2) . '% of ' . number_format($rate, 2); break;
                case 'per_day':
                    $div = (float)$it['divisor'] ?: 1;
                    $amt = ($a / $div) * $days;
                    $explain = '(' . rtrim(rtrim(number_format($a, 4, '.', ''), '0'), '.') . ' ÷ ' . rtrim(rtrim(number_format($div, 4, '.', ''), '0'), '.') . ') × ' . $days . ' day(s)'; break;
                case 'per_km':
                    $km = (float)($ctx['km'] ?? 0);
                    $amt = $a * $km; $explain = number_format($a, 2) . ' × ' . $km . ' km'; break;
                case 'per_trip':
                    $trips = (float)($ctx['trips'] ?? 0);
                    $amt = $a * $trips; $explain = number_format($a, 2) . ' × ' . $trips . ' trip(s)'; break;
                case 'fuel':
                    $km = (float)($ctx['km'] ?? 0);
                    $amt = self::fuelCost($km);
                    $explain = '(' . $km . ' km ÷ ' . self::value('km_per_liter') . ' km/L) × ' . number_format(self::value('fuel_price_per_liter'), 2) . '/L'; break;
                case 'route_toll':
                    $amt = (float)($ctx['toll'] ?? 0); $explain = 'RFID/toll saved on route'; break;
                case 'manual':
                    $amt = (float)($ctx['manual'][$it['name']] ?? 0); $explain = 'entered manually'; break;
                case 'ledger':
                    $amt = (float)($ctx['ledgers'][$it['ledger'] ?? ''] ?? 0); $explain = 'installment from ' . ($it['ledger'] ?? '') . ' balance'; break;
            }
            // Same as Excel IF(D<>"", ...): only when the employee had attendance.
            if ((int)$it['only_if_attended'] === 1 && $days <= 0) { $amt = 0.0; $explain = 'no attendance, not applied'; }
            // Manual overrides (Payroll officer edited the draft line).
            if ($it['calc'] !== 'manual' && isset($ctx['override'][$it['name']])) {
                $amt = (float)$ctx['override'][$it['name']]; $explain = 'edited by Payroll';
            }
            $amt = round($amt, 2);
            $lines[] = ['name' => $it['name'], 'calc' => $it['calc'], 'amount' => $amt, 'explain' => $explain,
                        'ledger' => $it['ledger'] ?? null];
            $total += $amt;
        }
        return ['lines' => $lines, 'total' => round($total, 2)];
    }

    /** Projected P&L for one trip (Billing P&L workbook). */
    public static function projectedPnl(float $rate, float $km, float $toll): array {
        $exp = self::computeItems('trip_pnl_expense', ['rate' => $rate, 'km' => $km, 'toll' => $toll]);
        $net = round($rate - $exp['total'], 2);
        return ['gross_sales' => round($rate, 2), 'expenses' => $exp['lines'], 'total_expense' => $exp['total'],
                'net_income' => $net, 'margin_percent' => $rate > 0 ? round($net / $rate * 100, 2) : null];
    }

    /** Copy of values used, saved inside fund requests / payroll so old records never change. */
    public static function snapshot(array $keys, array $scopes = []): array {
        $out = ['values' => [], 'items' => [], 'taken_at' => date('Y-m-d H:i:s')];
        foreach ($keys as $k) $out['values'][$k] = self::value($k);
        foreach ($scopes as $s) $out['items'][$s] = self::items($s);
        return $out;
    }

    public static function canEdit(array $u, string $groupOrScope): bool {
        return in_array($u['role'], self::EDITORS[$groupOrScope] ?? ['admin'], true);
    }
}
