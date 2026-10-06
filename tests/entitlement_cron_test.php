<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/entitlement_cron_test.php
//
// The cron's contract, read as text: registered weekly, named the way
// GLPI resolves cron methods, and bounded so a large estate degrades into
// a slower refresh rather than a run that never finishes.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$root = __DIR__ . '/..';
$hook = (string) file_get_contents($root . '/hook.php');
$ent  = (string) file_get_contents($root . '/inc/entitlement.class.php');

// GLPI resolves a cron by calling cron<Name>() on the registered class.
// A lowercase method name is a cron that registers and never runs.
check(preg_match('/public static function cronEntitlement_pull\(CronTask \$task\): int/', $ent) === 1,
      'the cron method is cronEntitlement_pull(CronTask $task): int');
check(preg_match('/public static function cronInfo\(string \$name\): array/', $ent) === 1,
      'cronInfo() is declared, so the action has a description in the UI');

check(preg_match("/CronTask::Register\(\s*'PluginIntegrasipamanEntitlement',\s*'entitlement_pull',\s*WEEK_TIMESTAMP/", $hook) === 1,
      'registered as entitlement_pull on PluginIntegrasipamanEntitlement at WEEK_TIMESTAMP');

// A run must be bounded. 645 assets at one call each is not a thing to
// attempt in a single cron slot.
check(preg_match('/const\s+BATCH_LIMIT\s*=\s*(\d+)/', $ent, $m) === 1,
      'the run is bounded by a BATCH_LIMIT constant');

// ...but bounded ABOVE the estate. The candidate set is every Computer
// matching a JumpServer host (645 today), not just the ones with session
// history. A bound below that spreads one full pass over more than
// STALE_DAYS, so every pane would carry a staleness warning for ever and
// the warning would stop meaning anything.
check((int) ($m[1] ?? 0) >= 645,
      'the bound still lets a full pass finish inside the staleness window (got ' . ($m[1] ?? '?') . ')');

// The behavioural proof that the run picks the right Computers - the
// whole estate matched against the JumpServer host list, never-refreshed
// first, then oldest-refreshed first, capped at BATCH_LIMIT - lives in
// entitlement_pull_test.php, which has the FakeDB harness to invoke
// cronEntitlement_pull() and watch which assets it actually asks about.
// A str_contains() text match here would pass even if the ordering were
// deleted outright - see task-6-report.md.

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
