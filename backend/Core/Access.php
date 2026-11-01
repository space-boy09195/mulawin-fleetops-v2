<?php
/*
 * =====================================================================
 *  backend/Core/Access.php  —  WHO CAN OPEN WHICH PAGE
 * =====================================================================
 *  Each page belongs to ONE backend module (backend/Modules/<Department>/<Module>.php)
 *  and ONE frontend file (public/assets/js/modules/<department>/<file>.js).
 *  Departments: Operations, Maintenance, Accounting, General, Administration.
 *  If a module has a problem, only its pages stop working.
 *
 *  Roles follow the FMS PDF "User Roles and Access Requirements".
 *  To give a role access to a page, add the role to 'roles' below.
 *  Each module still checks the role again before saving anything.
 * =====================================================================
 */
declare(strict_types=1);

final class Access {
    public const SECTIONS = [
        'Fleet Management'         => 'bi-truck',
        'Trip & Dispatch'          => 'bi-send',
        'Maintenance & Inspection' => 'bi-tools',
        'Financial & Billing'      => 'bi-wallet2',
        'Payroll & Timekeeping'    => 'bi-cash-stack',
        'Records'                  => 'bi-folder2-open',
        'Reports & Administration' => 'bi-gear',
    ];

    public static function pages(): array {
        $D = ['car_carrier_dispatcher', 'container_wingvan_dispatcher'];
        return [
            // id => [label, icon, section (null = top), module, js file, roles]
            'dashboard'    => ['Dashboard', 'bi-house-fill', null, 'Analytics', 'general/dashboard', array_keys(ROLE_LABELS)],

            'fleet'        => ['Fleet Status & Trucks', 'bi-truck', 'Fleet Management', 'Fleet', 'operations/fleet',
                               array_merge(['admin', 'operations_head', 'motorpool'], $D)],
            'crew'         => ['Drivers, Helpers & Staff', 'bi-person-badge', 'Fleet Management', 'Fleet', 'operations/fleet',
                               array_merge(['admin', 'operations_head', 'payroll'], $D)],

            'trips'        => ['Dispatch Board', 'bi-send', 'Trip & Dispatch', 'Trips', 'operations/trips',
                               array_merge(['admin', 'operations_head'], $D)],
            'trip_records' => ['Trip Records & Waybills', 'bi-journal-text', 'Trip & Dispatch', 'Trips', 'operations/trips',
                               array_merge(['admin', 'operations_head', 'billing', 'payroll', 'audit'], $D)],
            'incidents'    => ['Incidents', 'bi-exclamation-triangle', 'Trip & Dispatch', 'Trips', 'operations/trips',
                               array_merge(['admin', 'operations_head', 'motorpool'], $D)],

            'maintenance'  => ['Maintenance', 'bi-tools', 'Maintenance & Inspection', 'Maintenance', 'maintenance/maintenance',
                               ['admin', 'operations_head', 'motorpool']],
            'inventory'    => ['Parts Inventory', 'bi-box-seam', 'Maintenance & Inspection', 'Purchasing', 'maintenance/purchasing',
                               ['admin', 'purchasing', 'motorpool', 'finance']],
            'purchasing'   => ['Purchasing', 'bi-cart-check', 'Maintenance & Inspection', 'Purchasing', 'maintenance/purchasing',
                               ['admin', 'purchasing', 'finance', 'audit']],

            'funds'        => ['Trip Funds & Allowances', 'bi-cash-coin', 'Financial & Billing', 'Funds', 'accounting/finance',
                               array_merge(['admin', 'operations_head', 'finance', 'audit'], $D)],
            'finance'      => ['Finance & Payables', 'bi-wallet2', 'Financial & Billing', 'Finance', 'accounting/finance',
                               ['admin', 'finance', 'audit']],
            'billing'      => ['Billing & Collections', 'bi-receipt', 'Financial & Billing', 'Billing', 'accounting/billing',
                               ['admin', 'billing', 'audit']],
            'clients'      => ['Clients, Routes & Rates', 'bi-person-vcard', 'Financial & Billing', 'Billing', 'accounting/billing',
                               ['admin', 'billing', 'operations_head', 'finance', 'payroll']],

            'payroll'      => ['Payroll', 'bi-cash-stack', 'Payroll & Timekeeping', 'Payroll', 'accounting/payroll',
                               ['admin', 'payroll', 'finance', 'audit']],
            'attendance'   => ['Attendance', 'bi-calendar-check', 'Payroll & Timekeeping', 'Payroll', 'accounting/payroll',
                               ['admin', 'payroll']],
            'balances'     => ['Cash Advance & Balances', 'bi-piggy-bank', 'Payroll & Timekeeping', 'Payroll', 'accounting/payroll',
                               ['admin', 'payroll', 'finance', 'audit']],

            'documents'    => ['Documents', 'bi-folder2-open', 'Records', 'Documents', 'general/documents',
                               array_keys(ROLE_LABELS)],

            'reports'      => ['Reports & Analytics', 'bi-graph-up-arrow', 'Reports & Administration', 'Analytics', 'general/reports',
                               array_keys(ROLE_LABELS)],
            'settings'     => ['Formula Settings', 'bi-sliders', 'Reports & Administration', 'Admin', 'administration/admin',
                               ['admin', 'finance', 'payroll', 'audit']],
            'users'        => ['User Accounts', 'bi-people', 'Reports & Administration', 'Admin', 'administration/admin', ['admin']],
            'audit_log'    => ['Activity Log', 'bi-clock-history', 'Reports & Administration', 'Admin', 'administration/admin', ['admin', 'audit']],
            'backup'       => ['Backup', 'bi-hdd-network', 'Reports & Administration', 'Admin', 'administration/admin', ['admin']],
        ];
    }

    /** Pages the user can open, for the sidebar. */
    public static function navFor(array $u): array {
        $out = [];
        foreach (self::pages() as $id => [$label, $icon, $section, $module, $js, $roles]) {
            if (in_array($u['role'], $roles, true)) {
                $out[] = ['id' => $id, 'label' => $label, 'icon' => $icon, 'section' => $section, 'js' => $js];
            }
        }
        return $out;
    }

    public static function canOpen(array $u, string $page): bool {
        $p = self::pages()[$page] ?? null;
        return $p !== null && in_array($u['role'], $p[5], true);
    }

    public static function moduleOf(string $page): ?string { return self::pages()[$page][3] ?? null; }
}
