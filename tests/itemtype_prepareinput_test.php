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
$a->fields = ['asset_class' => '', 'is_manual' => 0, 'resolve_method' => 'unresolved'];
$out = $a->prepareInputForUpdate(['asset_class' => 'production', 'is_manual' => 0, 'note' => 'x']);
check(($out['is_manual'] ?? null) === 1 && ($out['resolve_method'] ?? null) === 'manual',
    'asset: real form shape (asset_class "" -> production, is_manual posted 0, was 0) LOCKS the row');

// revert-to-auto: was locked, admin unchecks manual
$a->fields = ['is_manual' => 1, 'resolve_method' => 'manual'];
$out = $a->prepareInputForUpdate(['is_manual' => 0]);
check(($out['is_manual'] ?? null) === 0 && !isset($out['resolve_method']),
    'asset: was locked, is_manual 1->0 -> revert to auto (stays unlocked)');

// no verdict change -> untouched
$a->fields = ['is_manual' => 0, 'resolve_method' => 'ipnet'];
$out = $a->prepareInputForUpdate(['is_manual' => 0, 'note' => 'n']);
check(!isset($out['is_manual']) || (int) $out['is_manual'] === 0,
    'asset: no verdict change -> not force-locked');

// I5: readonly columns stripped
$a->fields = ['is_manual' => 0, 'resolve_method' => 'unresolved'];
$out = $a->prepareInputForUpdate(['asset' => 'evil(1.2.3.4)', 'parsed_name' => 'x', 'parsed_ip' => '9.9.9.9', 'resolve_method' => 'group']);
check(!isset($out['asset']) && !isset($out['parsed_name']) && !isset($out['parsed_ip']) && !isset($out['resolve_method']),
    'asset: asset/parsed_*/resolve_method never accepted from the browser');

// --- v3.5.0: picking a Computer by hand locks the row the same way -------
// Without this the pick is silently discarded: the operator chooses a
// Computer, leaves Manual override at No, and the next resolveAll() rewrites
// the row from the parsed name/IP. Nothing on screen would say it happened.
$a->fields = ['is_manual' => 0, 'resolve_method' => 'computer_ip', 'computers_id' => 12];
$out = $a->prepareInputForUpdate(['is_manual' => 0, 'computers_id' => 99]);
check(($out['is_manual'] ?? null) === 1 && ($out['resolve_method'] ?? null) === 'manual',
    'asset: changing computers_id LOCKS the row, so the resolver cannot overwrite the pick');

// Clearing the link is a decision too - "this asset is deliberately not
// mapped" must survive the resolver, or it is re-mapped on the next run.
$a->fields = ['is_manual' => 0, 'resolve_method' => 'computer_ip', 'computers_id' => 12];
$out = $a->prepareInputForUpdate(['is_manual' => 0, 'computers_id' => 0]);
check(($out['is_manual'] ?? null) === 1,
    'asset: clearing computers_id locks too - not-mapped-on-purpose is a verdict');

// Re-posting the SAME Computer is not a change and must not lock a row the
// resolver is managing happily.
$a->fields = ['is_manual' => 0, 'resolve_method' => 'computer_ip', 'computers_id' => 12];
$out = $a->prepareInputForUpdate(['is_manual' => 0, 'computers_id' => 12]);
check(!isset($out['is_manual']) || (int) $out['is_manual'] === 0,
    'asset: re-posting the same computers_id does NOT force-lock');

// Reverting to auto still wins over a computers_id in the same POST: the
// form posts every field, so an unchanged dropdown would otherwise re-lock
// the row the operator just released.
$a->fields = ['is_manual' => 1, 'resolve_method' => 'manual', 'computers_id' => 12];
$out = $a->prepareInputForUpdate(['is_manual' => 0, 'computers_id' => 99]);
check(($out['is_manual'] ?? null) === 0 && !isset($out['resolve_method']),
    'asset: releasing the lock beats a changed computers_id in the same POST');

// Changing the link does not re-run risk evaluation: the generation folds in
// asset_class, which decides HOW a command is scored. Which Computer the
// asset points at changes attribution, not scoring.
PluginIntegrasipamanCorrelation::$bumps = 0;
$a->updates = ['computers_id'];
$a->post_updateItem();
check(PluginIntegrasipamanCorrelation::$bumps === 0,
    'asset: a computers_id-only edit does not bump the evaluation generation');

// --- v3.6.0: classification changes lock the row, like computers_id ------
// Same reason as v3.5.0: the form posts every field on every save and
// resolveAll() rewrites anything unlocked, so an unlocked correction is
// gone at the next run with nothing on screen to say so.
$a->fields = ['asset_class' => 'development', 'is_manual' => 0, 'resolve_method' => 'prefix_fallback', 'computers_id' => 5];
$out = $a->prepareInputForUpdate(['asset_class' => 'production', 'is_manual' => 0, 'computers_id' => 5]);
check(($out['is_manual'] ?? null) === 1 && ($out['resolve_method'] ?? null) === 'manual',
    'asset: changing asset_class LOCKS the row');

// Setting it back to unclassified is a statement too - "this was looked at
// and is genuinely neither" - so it locks.
$a->fields = ['asset_class' => 'development', 'is_manual' => 0, 'resolve_method' => 'prefix_fallback', 'computers_id' => 5];
$out = $a->prepareInputForUpdate(['asset_class' => '', 'is_manual' => 0, 'computers_id' => 5]);
check(($out['is_manual'] ?? null) === 1,
    'asset: clearing asset_class back to unclassified locks too');

$a->fields = ['asset_class' => 'production', 'is_manual' => 0, 'resolve_method' => 'ipnet', 'computers_id' => 5];
$out = $a->prepareInputForUpdate(['asset_class' => 'production', 'is_manual' => 0, 'computers_id' => 5]);
check(!isset($out['is_manual']) || (int) $out['is_manual'] === 0,
    'asset: re-posting the same asset_class does NOT force-lock');

$a->fields = ['asset_class' => 'production', 'is_manual' => 1, 'resolve_method' => 'manual', 'computers_id' => 5];
$out = $a->prepareInputForUpdate(['asset_class' => 'development', 'is_manual' => 0, 'computers_id' => 5]);
check(($out['is_manual'] ?? null) === 0 && !isset($out['resolve_method']),
    'asset: releasing the lock beats a changed asset_class in the same POST');

// --- FIX 1: asset_class is a 3-value domain, not a free VARCHAR(20) ------
// search option id 4 is 'datatype' => 'string', which renders a free-text
// input on GLPI's massive-action Update form. An out-of-domain value must
// be dropped before it can be stored AND before it can trip the lock above -
// otherwise the row would score unclassified while vanishing from the
// unclassified queue (which keys on resolve_method), permanently.
$a->fields = ['asset_class' => 'production', 'is_manual' => 0, 'resolve_method' => 'ipnet', 'computers_id' => 5];
$out = $a->prepareInputForUpdate(['asset_class' => 'Prod', 'is_manual' => 0, 'computers_id' => 5]);
check(!isset($out['asset_class']),
    'asset: an out-of-domain asset_class ("Prod") is dropped, not stored');
check(!isset($out['is_manual']) || (int) $out['is_manual'] === 0,
    'asset: an out-of-domain asset_class does NOT lock the row (dropped before the lock check)');

foreach (['', 'production', 'development'] as $legal) {
    $a->fields = ['asset_class' => 'production' === $legal ? 'development' : 'production', 'is_manual' => 0, 'resolve_method' => 'ipnet', 'computers_id' => 5];
    $out = $a->prepareInputForUpdate(['asset_class' => $legal, 'is_manual' => 0, 'computers_id' => 5]);
    check(($out['asset_class'] ?? null) === $legal,
        "asset: legal asset_class '$legal' is accepted");
    check(($out['is_manual'] ?? null) === 1 && ($out['resolve_method'] ?? null) === 'manual',
        "asset: legal asset_class '$legal' locks the row");
}

// The dropdown itself cannot be exercised here - showForm() needs GLPI's
// Dropdown and a database - so this reads the source instead. It is a weak
// check and worth saying so: it proves the field is still WIRED, not that it
// renders. Rendering is verified on screen at deploy.
//
// It earns its place anyway: the locking logic above is meaningless if the
// control that feeds it is ever removed, and that removal would leave every
// assertion in this file green.
$src = (string) file_get_contents(__DIR__ . '/../inc/assetmap.class.php');
check(str_contains($src, 'Computer::dropdown('),
    'asset: the form still posts computers_id, so the lock above has an input');
check(str_contains($src, "'is_deleted' => 0, 'is_template' => 0"),
    'asset: and deleted Computers and templates stay out of the dropdown');
check(str_contains($src, "showFromArray('asset_class'"),
    'asset: the form still posts asset_class, so the lock above has an input');
check(!str_contains($src, "Dropdown::showYesNo('is_production'"),
    'asset: the old yes/no production control is gone, not left beside it');

// post_updateItem bump on verdict change
PluginIntegrasipamanCorrelation::$bumps = 0;
$a->updates = ['asset_class', 'is_manual'];
$a->post_updateItem();
check(PluginIntegrasipamanCorrelation::$bumps === 1, 'asset: post_updateItem bumps generation when asset_class changed');
PluginIntegrasipamanCorrelation::$bumps = 0;
$a->updates = ['note'];
$a->post_updateItem();
check(PluginIntegrasipamanCorrelation::$bumps === 0, 'asset: post_updateItem no bump for a note-only edit');

check(PluginIntegrasipamanAssetMap::canCreate() === false, 'asset: canCreate() is false');

// The two signals must be findable and visible, or they are decoration.
check(str_contains($src, "'field'    => 'ip_mismatch'"),
      'asset: ip_mismatch is a search option, so the list can be filtered');
check(str_contains($src, "'field'    => 'live_checked_at'"),
      'asset: live_checked_at is a search option too');

// The form must name BOTH addresses. The difference is the diagnosis:
// 10.0.3.136 against 10.0.14.136 tells an operator immediately that
// the machine moved and the inventory did not follow.
//
// Scoped to the warning block, the way schema_conventions_test.php scopes
// its CREATE slice. A whole-file search proved only that both strings exist
// SOMEWHERE - and ip_mismatch appears in rawSearchOptions() quite
// independently, so the assertion would have stayed green with the warning
// block deleted outright. The slice runs from the flag's guard to the end of
// the alert div.
$warn_from = strpos($src, "if ((int) (\$this->fields['ip_mismatch'] ?? 0) === 1) {");
$warn_end  = $warn_from === false ? false : strpos($src, "echo '</div>';", $warn_from);
$warn_slice = ($warn_from === false || $warn_end === false)
    ? ''
    : substr($src, $warn_from, $warn_end - $warn_from);

// ANTI-VACUITY: a slice that silently came back empty would make every
// str_contains() below false - but a slice that came back as the WHOLE FILE
// would make them all true for the emptiest possible reason. Assert the
// slice was actually found before trusting anything read out of it.
check($warn_slice !== '' && strlen($warn_slice) < strlen($src),
      'asset: the mismatch warning block was located in the source (slice length '
    . strlen($warn_slice) . ' of ' . strlen($src) . ')');
check(str_contains($warn_slice, 'parsed_ip'),
      'asset: the mismatch warning names the address PAMAN reported');
check(str_contains($warn_slice, 'computerAddresses'),
      'asset: and the addresses GLPI records - both, because the difference is the diagnosis');

// Neither signal may reach prepareInputForUpdate's verdict test - they are
// observations about our knowledge, not decisions about the asset.
check(!preg_match("/verdict_changed[^;]*ip_mismatch/s", $src),
      'asset: ip_mismatch is NOT part of the verdict test - a signal never locks a row');
check(!preg_match("/verdict_changed[^;]*live_checked_at/s", $src),
      'asset: nor is live_checked_at');

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
