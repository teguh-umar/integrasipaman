<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/accesshistory_test.php
//
// Covers PluginIntegrasipamanAccessHistory - the JumpServer access-history
// tab on the core Computer itemtype. The case this file exists for is the
// reverse lookup: one GLPI Computer can be mapped from SEVERAL JumpServer
// asset strings, and reading only one of them produces partial history that
// looks complete - no error, plausible output, wrong answer.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

define('READ', 1);
define('UPDATE', 2);

if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
function __($s, $d = null) { return $s; }
function _n($s, $p, $n, $d = null) { return $n == 1 ? $s : $p; }
function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

class QueryExpression { public function __construct(public string $e) {} public function __toString() { return $this->e; } }
class CommonGLPI {
    public static function createTabEntry($text, $nb = 0, $itemtype = null, $icon = null) { return $text; }
}
class CommonDBTM extends CommonGLPI {}
class Computer extends CommonDBTM {
    public array $fields = [];
    public function __construct(int $id = 0) { $this->fields = ['id' => $id]; }
    public function getID() { return $this->fields['id'] ?? -1; }
    public static function getFormURLWithID($id, $full = true) { return '/front/computer.form.php?id=' . (int) $id; }
}
class Plugin { public static function getWebDir($n) { return '/plugins/' . $n; } }

/** Right masks keyed by right name. */
$GLOBALS['RIGHTS'] = [];
class Session
{
    public static function haveRight($name, $mask)
    {
        return ((int) ($GLOBALS['RIGHTS'][$name] ?? 0) & $mask) === $mask;
    }
}
class PluginIntegrasipamanProfile
{
    public const RIGHT_CONFIG = 'plugin_integrasipaman_config';
    public const RIGHT_REPORT = 'plugin_integrasipaman_report';
}
function grant(int $config, int $report): void
{
    $GLOBALS['RIGHTS'] = [
        PluginIntegrasipamanProfile::RIGHT_CONFIG => $config,
        PluginIntegrasipamanProfile::RIGHT_REPORT => $report,
    ];
}
class PluginIntegrasipamanEntitlement
{
    public static array $rows = [];
    public static ?string $refreshed = null;
    public static function forComputer(int $id): array { return self::$rows; }
    public static function lastRefreshed(int $id): ?string { return self::$refreshed; }
    /** null = the real rule (never refreshed is stale); set it to force
     *  the dated-but-stale branch, which a null timestamp cannot reach. */
    public static ?bool $stale = null;
    public static function isStale(?string $r): bool { return self::$stale ?? ($r === null); }
}

class FakeDB
{
    public array $seen   = [];
    public array $canned = [];
    public function quoteName($n) { return "`$n`"; }
    public function request($q)
    {
        $this->seen[] = $q;
        return new ArrayIterator(array_shift($this->canned) ?? []);
    }
}

require __DIR__ . '/../inc/accesshistory.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
function j($v) { return json_encode($v); }

$A = 'PluginIntegrasipamanAccessHistory';

// --- the reverse lookup: one Computer, several asset strings ---------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['asset' => 'db-prod-01(10.0.0.5)'],
    ['asset' => 'db-prod-01.example.com(10.0.0.5)'],
]];
$assets = $A::assetStringsFor(42);
check($assets === ['db-prod-01(10.0.0.5)', 'db-prod-01.example.com(10.0.0.5)'],
      'assetStringsFor() returns EVERY asset string mapped to the Computer');
check(str_contains(j($db->seen[0]), 'assetmap'), 'the lookup reads the assetmap table');
check(str_contains(j($db->seen[0]), '42'), 'the lookup is scoped to the requested computers_id');

// a Computer with no mapping at all
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
check($A::assetStringsFor(7) === [], 'an unmapped Computer yields no asset strings');

// --- sessions are asked for ALL strings, not just the first ----------------
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
$A::sessions(['a(1.1.1.1)', 'b(1.1.1.1)'], '2026-08-24 00:00:00', 20);
$q = j($db->seen[0]);
check(str_contains($q, 'a(1.1.1.1)') && str_contains($q, 'b(1.1.1.1)'),
      'sessions() filters on every mapped asset string');
check(str_contains($q, '2026-08-24'), 'sessions() applies the 30-day window');
check(($db->seen[0]['LIMIT'] ?? null) === 20, 'sessions() honours the row limit');

// no strings -> no query at all, and an empty result
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($A::sessions([], '2026-08-24 00:00:00', 20) === [], 'sessions() with no asset strings returns empty');
check($db->seen === [], 'sessions() with no asset strings issues NO query');

// --- high-risk pane really is high-risk only -------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
$A::highRisk(['a(1.1.1.1)'], '2026-08-24 00:00:00', 20);
$q = j($db->seen[0]);
check(str_contains($q, 'is_high_risk'), 'highRisk() filters on is_high_risk');
check(str_contains($q, 'command_eval'), 'highRisk() joins the eval table');

// --- summary ---------------------------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['sessions' => 12, 'users' => 4, 'failed' => 1]],
    [['high_risk' => 5]],
];
$s = $A::summary(['a(1.1.1.1)'], '2026-08-24 00:00:00');
check($s['sessions'] === 12 && $s['users'] === 4 && $s['failed'] === 1, 'summary maps the session aggregate');
check($s['high_risk'] === 5, 'summary maps the high-risk count');

// --- the tab is gated on the REPORT right, not on Computer READ ------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$tab = new $A();
grant(READ | UPDATE, 0);
check($tab->getTabNameForItem(new Computer(1)) === '',
      'config right alone does NOT reveal the tab - it carries privileged usernames');
grant(0, READ);
$db->canned = [[['asset' => 'a(1.1.1.1)']]];
check($tab->getTabNameForItem(new Computer(1)) !== '', 'the report right reveals the tab');
check($tab->getTabNameForItem(new CommonDBTM()) === '', 'the tab never attaches to a non-Computer item');

// --- render: unmapped Computer explains itself rather than showing nothing --
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
grant(0, READ);
ob_start();
$A::displayTabContentForItem(new Computer(9));
$html = ob_get_clean();
check(str_contains($html, 'not mapped') || str_contains(strtolower($html), 'mapped'),
      'an unmapped Computer says so instead of rendering an empty table');

// --- render: an unmapped Computer STILL gets its entitlement pane ---------
// The whole point of the entitlement feature is the machine nobody has
// touched. Those machines have no row in assetmap, because correlation fills
// that table from the COMMANDS history - so the outer "not mapped" guard,
// written in v2.5.0 when this tab held only history panes, hid the one pane
// that does not need assetmap at all.
//
// Found on the VM after v3.4.1: Computer 46 had entitlement rows in the
// database and a tab that said "not mapped" with no sub-tabs at all. The
// data-level check passed precisely because it bypassed this layer.
//
// forComputer() reads assetperms keyed on computers_id and never touches
// assetmap, so there is no reason for the absence of history to suppress it.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[]];   // assetStringsFor: no assetmap row, and nothing after it
PluginIntegrasipamanEntitlement::$rows = [[
    'display'  => 'Sysadmin Three(sysadmin.three-e)', 'type' => 'user',
    'via'      => 'DBA standing access', 'accounts' => '@ALL',
    'actions'  => 'Connect (All Protocols)', 'valid' => true,
    'date_expired' => null,
]];
PluginIntegrasipamanEntitlement::$refreshed = '2026-09-27 13:00:02';
PluginIntegrasipamanEntitlement::$stale     = false;
grant(0, READ);
ob_start();
$A::displayTabContentForItem(new Computer(46));
$html = ob_get_clean();
check(str_contains($html, 'integrasipaman-acc-entitlement'),
      'a Computer with no assetmap row still renders the entitlement pane');
check(str_contains($html, 'Sysadmin Three(sysadmin.three-e)'),
      'and the entitlement rows themselves are on the page');
// The history explanation must survive - it is still true, and dropping it
// would leave an empty Sessions pane reading as "nobody ever connected".
check(str_contains(strtolower($html), 'mapped'),
      'while still explaining why there is no session history');
// Which pane OPENS is load-bearing, not cosmetic: landing on an empty
// Sessions table would repeat, in a different shape, the mistake this fix
// undoes - the reader would again see nothing and conclude there is nothing.
check(str_contains($html, 'class="tab-pane fade show active" id="integrasipaman-acc-entitlement"'),
      'the entitlement pane is the one that OPENS when there is no history');
check(str_contains($html, 'class="tab-pane fade" id="integrasipaman-acc-sessions"'),
      'and the empty Sessions pane is not the one in front');

// The stub is shared state and the tests after this one set it themselves,
// but resetting here keeps this block from being the reason one of them
// passes.
PluginIntegrasipamanEntitlement::$rows = [];
PluginIntegrasipamanEntitlement::$refreshed = null;
PluginIntegrasipamanEntitlement::$stale = null;

// --- render: the normal case ----------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['asset' => 'db-prod-01(10.0.0.5)']],                       // assetStringsFor
    [['sessions' => 2, 'users' => 1, 'failed' => 0]],            // summary part 1
    [['high_risk' => 1]],                                        // summary part 2
    [['date_start' => '2026-09-23 09:14:00', 'date_end' => '2026-09-23 09:26:00',
      'jms_user' => 'budi(Budi)', 'account' => 'root', 'protocol' => 'ssh',
      'remote_addr' => '10.20.3.44', 'duration' => 720, 'command_amount' => 37,
      'is_success' => 1, 'is_finished' => 1, 'error_reason' => '']],      // sessions
    [['executed_at' => '2026-09-23 09:20:00', 'jms_user' => 'budi(Budi)',
      'command' => 'rm -rf /var/log/x', 'risk_category' => 'Destructive',
      'priority' => 'HIGH', 'catatan' => 'Aset Production']],             // high risk
];
ob_start();
$A::displayTabContentForItem(new Computer(42));
$html = ob_get_clean();
// The default is unchanged for a mapped Computer: history is what someone
// opening this tab is usually after.
check(str_contains($html, 'class="tab-pane fade show active" id="integrasipaman-acc-sessions"'),
      'a mapped Computer still opens on Sessions');

check(str_contains($html, 'budi(Budi)'), 'the session row renders');
check(str_contains($html, 'rm -rf /var/log/x'), 'the high-risk command renders');
check(substr_count($html, 'class="tab-pane') === 3, 'three sub-panes: sessions, high-risk, entitlement');
check(str_contains($html, 'data-bs-toggle="tab"'), 'sub-tabs switch client-side - a GLPI tab is loaded by AJAX');
check(!str_contains($html, 'hr_page=') && !str_contains($html, 'p_sessions='),
      'no server-side pager inside the tab - a link would navigate away from the Computer form');
check(str_contains(strtolower($html), 'retention') || str_contains(strtolower($html), 'retensi'),
      'the tab discloses that history is bounded by retention');

// --- the entitlement pane ---------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['asset' => 'db-prod-01(10.0.0.5)']],
    [['sessions' => 0, 'users' => 0, 'failed' => 0]],
    [['high_risk' => 0]],
    [],
    [],
];
grant(0, READ);
PluginIntegrasipamanEntitlement::$rows = [
    ['display' => 'Alice', 'type' => 'user', 'via' => 'Prod SSH', 'accounts' => 'root',
     'actions' => 'connect', 'valid' => true, 'date_expired' => '2027-01-01 00:00:00'],
    ['display' => 'Old contractor', 'type' => 'user', 'via' => 'Lapsed', 'accounts' => 'root',
     'actions' => 'connect', 'valid' => false, 'date_expired' => '2026-01-01 00:00:00'],
];
PluginIntegrasipamanEntitlement::$refreshed = '2026-09-24 08:00:00';
ob_start();
$A::displayTabContentForItem(new Computer(42));
$ent = ob_get_clean();

check(str_contains($ent, 'Alice'), 'an entitled user renders');
check(str_contains($ent, 'Prod SSH'), 'the rule it came from renders');
check(str_contains($ent, 'integrasipaman-acc-entitlement'), 'the pane has its own prefixed id');
check(substr_count($ent, 'class="tab-pane') === 3, 'still exactly three panes');

// The FRESH branch: a recent cache states its date and does NOT raise the
// warning. Without this, deleting the whole else-branch breaks nothing and
// a fresh pane would silently lose its provenance line.
check(str_contains($ent, 'Collected from JumpServer on'),
      'a fresh cache says when it was collected');
check(str_contains($ent, '2026-09-24 08:00:00'),
      'and names the actual timestamp, not a placeholder');
check(!str_contains($ent, 'alert-warning'),
      'and raises NO staleness warning');

// An expired entitlement is shown, not hidden - it is an audit fact.
check(str_contains($ent, 'Old contractor'), 'an EXPIRED entitlement is still shown');
check(stripos($ent, 'expired') !== false, 'and is labelled as expired');

// Stale cache must announce itself.
PluginIntegrasipamanEntitlement::$refreshed = null;
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['asset' => 'db-prod-01(10.0.0.5)']],
    [['sessions' => 0, 'users' => 0, 'failed' => 0]],
    [['high_risk' => 0]], [], [],
];
ob_start();
$A::displayTabContentForItem(new Computer(42));
$stale = ob_get_clean();
// Pinned on a stable half of the sentence, not on the word "cron": the
// old assertion passed on incidental copy and would have survived the
// warning being replaced by anything mentioning a refresh.
check(str_contains($stale, 'never been collected'),
      'a never-refreshed cache says so rather than presenting rows as current');
check(str_contains($stale, 'alert-warning'),
      'and says it as a warning, not as a hint');
check(!str_contains($stale, 'Collected from JumpServer on'),
      'and does not also claim a collection date it does not have');

// The dated-but-stale branch says WHEN, so an auditor can judge the age.
PluginIntegrasipamanEntitlement::$refreshed = '2026-01-01 00:00:00';
PluginIntegrasipamanEntitlement::$stale = true;
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['asset' => 'db-prod-01(10.0.0.5)']],
    [['sessions' => 0, 'users' => 0, 'failed' => 0]],
    [['high_risk' => 0]], [], [],
];
ob_start();
$A::displayTabContentForItem(new Computer(42));
$old_cache = ob_get_clean();
check(str_contains($old_cache, 'last collected'),
      'a stale-but-dated cache says it was last collected, not that it never was');
check(str_contains($old_cache, '2026-01-01 00:00:00'),
      'and gives the date, so the reader can judge how stale it is');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
