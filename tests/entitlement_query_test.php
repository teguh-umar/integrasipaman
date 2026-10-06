<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/entitlement_query_test.php
//
// Covers the read path the "Who has access" pane renders from: members
// stored as broken JSON, a rule that grants nobody, an expired rule that
// must still be returned and flagged, an unmapped Computer, and the
// staleness threshold.
//
// NOT covered here: the INNER JOIN that drops a mapping row whose rule is
// absent from the rules cache. FakeDB hands back canned rows and executes
// no SQL, so the join is never evaluated and that behaviour is
// structurally untestable with this harness. Nothing below asserts it;
// proving it would need a real database.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

function __($s, $d = null) { return $s; }
class QueryExpression { public function __construct(public string $e) {} }
class PluginIntegrasipamanJmsApi { public static function signedGet(...$a) { return []; } }

class FakeDB
{
    public array $seen = [];
    public array $canned = [];
    public function quoteName($n) { return "`$n`"; }
    public function request($q) { $this->seen[] = $q; return new ArrayIterator(array_shift($this->canned) ?? []); }
    public function updateOrInsert($t, $p, $w) { return true; }
    public function delete($t, $w) { return true; }
}

require __DIR__ . '/../inc/entitlement.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$E = 'PluginIntegrasipamanEntitlement';

// --- the normal case ---------------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['permrules_jms_id' => 'R1', 'name' => 'Prod SSH',
     'users_json' => '[{"id":"U1","display":"Alice"}]',
     'user_groups_json' => '[{"id":"G1","display":"Ops","users_amount":4}]',
     'accounts' => 'root', 'actions' => 'connect',
     'is_valid' => 1, 'is_expired' => 0, 'date_expired' => '2027-01-01 00:00:00'],
]];
$rows = $E::forComputer(42);
check(count($rows) === 2, 'one user row and one group row (got ' . count($rows) . ')');

$byType = [];
foreach ($rows as $r) { $byType[$r['type']][] = $r; }
check(($byType['user'][0]['display'] ?? '') === 'Alice', 'the user renders by display name');
check(str_contains((string) ($byType['group'][0]['display'] ?? ''), 'Ops'), 'the group renders by name');
check(str_contains((string) ($byType['group'][0]['display'] ?? ''), '4'), 'the group carries its member count, since it is not expanded');
check(($rows[0]['via'] ?? '') === 'Prod SSH', 'every row names the rule it came from');
check(($rows[0]['valid'] ?? null) === true, 'a live rule is marked valid');

// --- a rule that grants NOBODY ----------------------------------------
// Must contribute no rows at all. A blank row reads as "someone has
// access but we cannot name them", which is worse than saying nothing.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['permrules_jms_id' => 'R9', 'name' => 'Grants nobody', 'users_json' => '[]',
     'user_groups_json' => '[]', 'accounts' => '', 'actions' => '',
     'is_valid' => 1, 'is_expired' => 0, 'date_expired' => null],
]];
check($E::forComputer(42) === [], 'a rule granting nobody contributes no rows');

// --- broken JSON must not fatal an asset form -------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['permrules_jms_id' => 'R1', 'name' => 'Truncated', 'users_json' => '[{"id":"U1","dis',
     'user_groups_json' => null, 'accounts' => '', 'actions' => '',
     'is_valid' => 1, 'is_expired' => 0, 'date_expired' => null],
]];
$rows = $E::forComputer(42);
check($rows === [], 'malformed members degrade to no rows instead of a PHP fatal');

// --- an expired rule is RETURNED, and flagged -------------------------
// Hiding it would make the pane answer a narrower question than it looks
// like it answers. A lapsed entitlement is an audit fact.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['permrules_jms_id' => 'R2', 'name' => 'Old contractor',
     'users_json' => '[{"id":"U2","display":"Bob"}]', 'user_groups_json' => '[]',
     'accounts' => 'root', 'actions' => 'connect',
     'is_valid' => 0, 'is_expired' => 1, 'date_expired' => '2026-01-01 00:00:00'],
]];
$rows = $E::forComputer(42);
check(count($rows) === 1, 'the expired rule is still returned');
check(($rows[0]['valid'] ?? null) === false, 'and is flagged not-valid');

// --- unmapped Computer -------------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::forComputer(0) === [], 'computers_id 0 returns nothing');
check($db->seen === [], 'and issues NO query');

// --- staleness ---------------------------------------------------------
check($E::isStale(null) === true, 'never refreshed counts as stale');
check($E::isStale(date('Y-m-d H:i:s')) === false, 'just refreshed is not stale');
check($E::isStale(date('Y-m-d H:i:s', strtotime('-8 days'))) === false,
      'one missed weekly run is NOT stale - the threshold is twice the interval');
check($E::isStale(date('Y-m-d H:i:s', strtotime('-15 days'))) === true,
      'a stopped cron becomes visible after 14 days');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
