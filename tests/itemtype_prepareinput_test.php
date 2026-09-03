<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/itemtype_prepareinput_test.php
// Verifies the is_manual auto-lock logic (against the shape the real form
// posts), the readonly-column stripping, and the post_updateItem bump.

error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

abstract class CommonDBTM {
    public array $fields = [];
    public array $updates = [];
    public static function getTable($classname = null) { return ''; }
}
class Session { public static function haveRight($a, $b) { return true; } }
class PluginIntegrasipamanCorrelation {
    public static int $bumps = 0;
    public static function bumpGeneration(): void { self::$bumps++; }
}

require __DIR__ . '/../inc/assetmap.class.php';
require __DIR__ . '/../inc/usermap.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$a = new PluginIntegrasipamanAssetMap();

// --- C1: the exact shape Dropdown::showYesNo posts (is_manual always present) ---
$a->fields = ['is_production' => 0, 'is_manual' => 0, 'resolve_method' => 'unresolved'];
$out = $a->prepareInputForUpdate(['is_production' => 1, 'is_manual' => 0, 'note' => 'x']);
check(($out['is_manual'] ?? null) === 1 && ($out['resolve_method'] ?? null) === 'manual',
    'asset: real form shape (is_production 0->1, is_manual posted 0, was 0) LOCKS the row');

// revert-to-auto: was locked, admin unchecks manual
$a->fields = ['is_production' => 1, 'is_manual' => 1, 'resolve_method' => 'manual'];
$out = $a->prepareInputForUpdate(['is_production' => 1, 'is_manual' => 0]);
check(($out['is_manual'] ?? null) === 0 && !isset($out['resolve_method']),
    'asset: was locked, is_manual 1->0 -> revert to auto (stays unlocked)');

// no verdict change -> untouched
$a->fields = ['is_production' => 1, 'is_manual' => 0, 'resolve_method' => 'ipnet'];
$out = $a->prepareInputForUpdate(['is_production' => 1, 'is_manual' => 0, 'note' => 'n']);
check(!isset($out['is_manual']) || (int) $out['is_manual'] === 0,
    'asset: no verdict change -> not force-locked');

// I5: readonly columns stripped
$a->fields = ['is_production' => 0, 'is_manual' => 0, 'resolve_method' => 'unresolved'];
$out = $a->prepareInputForUpdate(['asset' => 'evil(1.2.3.4)', 'parsed_name' => 'x', 'parsed_ip' => '9.9.9.9', 'resolve_method' => 'group', 'is_production' => 0]);
check(!isset($out['asset']) && !isset($out['parsed_name']) && !isset($out['parsed_ip']) && !isset($out['resolve_method']),
    'asset: asset/parsed_*/resolve_method never accepted from the browser');

// post_updateItem bump on verdict change
PluginIntegrasipamanCorrelation::$bumps = 0;
$a->updates = ['is_production', 'is_manual'];
$a->post_updateItem();
check(PluginIntegrasipamanCorrelation::$bumps === 1, 'asset: post_updateItem bumps generation when is_production changed');
PluginIntegrasipamanCorrelation::$bumps = 0;
$a->updates = ['note'];
$a->post_updateItem();
check(PluginIntegrasipamanCorrelation::$bumps === 0, 'asset: post_updateItem no bump for a note-only edit');

check(PluginIntegrasipamanAssetMap::canCreate() === false, 'asset: canCreate() is false');

// --- user map ---
$u = new PluginIntegrasipamanUserMap();
$u->fields = ['is_admin' => 0, 'is_manual' => 0, 'resolve_method' => 'unresolved'];
$out = $u->prepareInputForUpdate(['is_admin' => 1, 'is_manual' => 0]);
check(($out['is_manual'] ?? null) === 1 && ($out['resolve_method'] ?? null) === 'manual',
    'user: real form shape (is_admin 0->1) LOCKS the row');

$u->fields = ['is_admin' => 1, 'is_manual' => 1, 'resolve_method' => 'manual'];
$out = $u->prepareInputForUpdate(['is_admin' => 1, 'is_manual' => 0]);
check(($out['is_manual'] ?? null) === 0, 'user: is_manual 1->0 -> revert to auto');

$out = $u->prepareInputForUpdate(['jms_user' => 'x', 'parsed_login' => 'y', 'resolve_method' => 'group', 'is_admin' => 1]);
check(!isset($out['jms_user']) && !isset($out['parsed_login']) && !isset($out['resolve_method']),
    'user: jms_user/parsed_login/resolve_method stripped');

PluginIntegrasipamanCorrelation::$bumps = 0;
$u->updates = ['is_admin'];
$u->post_updateItem();
check(PluginIntegrasipamanCorrelation::$bumps === 1, 'user: post_updateItem bumps generation when is_admin changed');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
