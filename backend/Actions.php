<?php
/*
 * =====================================================================
 *  backend/Actions.php  —  sends each request to its module
 * =====================================================================
 *  Action names look like  "Trips.create"  =  module "Trips", action "create".
 *
 *  MODULE ISOLATION:
 *  A module file is loaded only when it is needed, inside try/catch.
 *  If backend/Modules/Payroll.php has an error, only Payroll actions
 *  fail with "module unavailable"; Trips, Billing and the rest keep
 *  working. (PHP syntax errors in a required file are catchable as
 *  ParseError, so even a broken file does not stop the others.)
 * =====================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

final class ModuleUnavailable extends RuntimeException {}

final class Actions {
    /** Modules that exist. Add a new module here and create backend/Modules/<Name>.php */
    public const MODULES = ['Fleet', 'Trips', 'Funds', 'Maintenance', 'Purchasing', 'Finance', 'Billing',
                            'Payroll', 'Documents', 'Analytics', 'Admin'];

    /** Loads backend/Modules/<Name>.php safely and returns the class name. */
    public static function load(string $module): string {
        if (!in_array($module, self::MODULES, true)) throw new BusinessRuleException('Unknown module.', 404);
        if (class_exists($module, false)) return $module;
        try {
            require_once __DIR__ . '/Modules/' . $module . '.php';
        } catch (Throwable $e) {
            error_log("Module $module failed to load: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            throw new ModuleUnavailable("The $module module is temporarily unavailable. Other modules are still working.");
        }
        if (!class_exists($module, false)) throw new ModuleUnavailable("The $module module is not installed correctly.");
        return $module;
    }

    /** POST ?api=Module.action */
    public static function run(string $api, array $data): array {
        must((bool)preg_match('/^([A-Z][A-Za-z]+)\.([a-z_]+)$/', $api, $m), 'Unknown action.', 404);
        $u = currentUser();
        must(!$u['must_change_password'] || $api === 'Admin.change_password', 'Please change your password first.', 403);
        $class = self::load($m[1]);
        return $class::action($u, $m[2], $data);
    }

    /** GET ?api=data&page=xyz  -> data for one page only (each page loads separately). */
    public static function pageData(string $page, array $query): array {
        core('Access');
        $u = currentUser();
        must(Access::canOpen($u, $page), 'You do not have access to this page.', 403);
        $class = self::load(Access::moduleOf($page));
        return $class::read($u, $page, $query);
    }
}

/**
 * Helper for modules: runs several read sections and keeps going when
 * one section fails (the page shows a small warning for that part only).
 */
function readSections(array $sections): array {
    $out = ['_errors' => []];
    foreach ($sections as $name => $fn) {
        try { $out[$name] = $fn(); }
        catch (BusinessRuleException $e) { throw $e; }
        catch (Throwable $e) {
            error_log("Read section $name failed: " . $e->getMessage());
            $out[$name] = [];
            $out['_errors'][$name] = 'This part is temporarily unavailable. The rest of the page still works.';
        }
    }
    return $out;
}
