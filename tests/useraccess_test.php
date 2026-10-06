<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/useraccess_test.php
//
// Covers PluginIntegrasipamanUserAccess - the "JumpServer access" tab on the
// core User itemtype: which PAMAN assets this person reached, with what
// commands, when, and from which remote IP.
//
// The case this file exists for is the reverse lookup, and it is the same
// trap accesshistory_test.php guards on the asset side: one GLPI user can be
// mapped from SEVERAL jms_user strings (a login, an FQDN-ish form, a
// display-name form), and reading only one produces partial history that
// looks complete - no error, plausible rows, wrong answer. On this tab the
// consequence is worse than on the asset tab: a half-read history is what
// someone would clear an employee with.
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
class User extends CommonDBTM {
    public array $fields = [];
    public function __construct(int $id = 0) { $this->fields = ['id' => $id]; }
    public function getID() { return $this->fields['id'] ?? -1; }
    public static function getFormURLWithID($id, $full = true) { return '/front/user.form.php?id=' . (int) $id; }
}
class Computer extends CommonDBTM {}
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
    public const RIGHT_CONFIG       = 'plugin_integrasipaman_config';
    public const RIGHT_REPORT       = 'plugin_integrasipaman_report';
    public const RIGHT_PROVISIONING = 'plugin_integrasipaman_provisioning';
}
function grant(int $report, int $config = 0): void
{
    $GLOBALS['RIGHTS'] = [
        PluginIntegrasipamanProfile::RIGHT_REPORT => $report,
        PluginIntegrasipamanProfile::RIGHT_CONFIG => $config,
    ];
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

require __DIR__ . '/../inc/useraccess.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
function j($v) { return json_encode($v); }

$A = 'PluginIntegrasipamanUserAccess';

// --- the reverse lookup: one GLPI user, several jms_user strings ----------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['jms_user' => 'budi'],
    ['jms_user' => 'budi.santoso(Budi Santoso)'],
]];
$strings = $A::jmsUserStringsFor(42);
check($strings === ['budi', 'budi.santoso(Budi Santoso)'],
      'jmsUserStringsFor() returns EVERY jms_user string mapped to the GLPI user');
check(str_contains(j($db->seen[0]), 'usermap'), 'the lookup reads the usermap table');
check(str_contains(j($db->seen[0]), '42'), 'the lookup is scoped to the requested users_id');

// a GLPI user nobody has mapped
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
check($A::jmsUserStringsFor(7) === [], 'an unmapped User yields no jms_user strings');

// id 0 / negative must not reach the database at all
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($A::jmsUserStringsFor(0) === [], 'users_id 0 yields nothing');
check($db->seen === [], 'users_id 0 issues NO query - an unsaved User must not query for id 0');

// --- sessions are asked for ALL strings, not just the first ---------------
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
$A::sessions(['budi', 'budi.santoso(Budi Santoso)'], '2026-08-25 00:00:00', 27);
$q = j($db->seen[0]);
check(str_contains($q, 'budi') && str_contains($q, 'Budi Santoso'),
      'sessions() filters on every mapped jms_user string');
check(str_contains($q, '2026-08-25'), 'sessions() applies the 30-day window');
check(($db->seen[0]['LIMIT'] ?? null) === 27, 'sessions() honours the row limit');

// The asset and the remote IP are the whole point of this tab - a session
// list without them answers neither "which asset" nor "from where".
check(str_contains($q, 'asset'), 'sessions() selects the asset');
check(str_contains($q, 'remote_addr'), 'sessions() selects the remote address');

// no strings -> no query at all
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($A::sessions([], '2026-08-25 00:00:00', 27) === [], 'sessions() with no strings returns empty');
check($db->seen === [], 'sessions() with no strings issues NO query');

// --- commands pane ---------------------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
$A::commands(['budi'], '2026-08-25 00:00:00', 27);
$q = j($db->seen[0]);
check(str_contains($q, 'command_eval'), 'commands() joins the eval table for risk');
check(str_contains($q, 'asset'), 'commands() selects the asset');
check(str_contains($q, 'remote_addr'), 'commands() selects the remote address');
check(($db->seen[0]['LIMIT'] ?? null) === 27, 'commands() honours the row limit');

$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($A::commands([], '2026-08-25 00:00:00', 27) === [], 'commands() with no strings returns empty');
check($db->seen === [], 'commands() with no strings issues NO query');

// --- summary ---------------------------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['sessions' => 9, 'assets' => 3, 'failed' => 2]],
    [['high_risk' => 4]],
];
$s = $A::summary(['budi'], '2026-08-25 00:00:00');
check($s['sessions'] === 9 && $s['assets'] === 3 && $s['failed'] === 2,
      'summary maps the session aggregate');
check($s['high_risk'] === 4, 'summary maps the high-risk count');
check($A::summary([], '2026-08-25 00:00:00') === ['sessions' => 0, 'assets' => 0, 'failed' => 0, 'high_risk' => 0],
      'summary with no strings is all zeroes, not a query');

// --- the window and the row limit -----------------------------------------
check($A::ROW_LIMIT === 27, 'ROW_LIMIT is 27, as the user asked');
check(str_starts_with($A::windowStart(), date('Y-m-d', strtotime('-30 days'))),
      'the window starts 30 days back');

// --- the tab is gated on the REPORT right, not on User READ ---------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$tab = new $A();
grant(0, READ | UPDATE);
check($tab->getTabNameForItem(new User(1)) === '',
      'the config right alone does NOT reveal the tab - it carries commands and remote IPs');
grant(READ);
$db->canned = [[['jms_user' => 'budi']]];
check($tab->getTabNameForItem(new User(1)) !== '', 'the report right reveals the tab');
check($tab->getTabNameForItem(new Computer()) === '', 'the tab never attaches to a non-User item');

// --- render: unmapped user explains itself rather than showing nothing ----
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
grant(READ);
ob_start();
$A::displayTabContentForItem(new User(9));
$html = ob_get_clean();
check(str_contains(strtolower($html), 'mapped'),
      'an unmapped User says so instead of rendering an empty table');

// --- render: the normal case ----------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['jms_user' => 'budi'], ['jms_user' => 'budi.santoso(Budi Santoso)']],   // jmsUserStringsFor
    [['sessions' => 2, 'assets' => 2, 'failed' => 0]],                        // summary part 1
    [['high_risk' => 1]],                                                     // summary part 2
    [['date_start' => '2026-09-23 09:14:00', 'date_end' => '2026-09-23 09:26:00',
      'asset' => 'db-prod-01(10.0.0.5)', 'account' => 'root', 'protocol' => 'ssh',
      'remote_addr' => '10.20.3.44', 'duration' => 720, 'command_amount' => 37,
      'is_success' => 1, 'is_finished' => 1, 'error_reason' => '']],           // sessions
    [['executed_at' => '2026-09-23 09:20:00', 'asset' => 'db-prod-01(10.0.0.5)',
      'account' => 'root', 'command' => 'rm -rf /var/log/x', 'remote_addr' => '10.20.3.44',
      'risk_category' => 'Destructive', 'priority' => 'HIGH', 'catatan' => 'Aset Production']],  // commands
];
ob_start();
$A::displayTabContentForItem(new User(42));
$html = ob_get_clean();

check(str_contains($html, 'db-prod-01(10.0.0.5)'), 'the asset the user reached renders');
check(str_contains($html, '10.20.3.44'), 'the remote IP renders - "dimana" is half the question');
check(str_contains($html, 'rm -rf /var/log/x'), 'the command renders');
check(str_contains($html, '2026-09-23 09:14:00'), 'the timestamp renders');
check(substr_count($html, 'class="tab-pane') === 2, 'two sub-panes: sessions and commands');
check(str_contains($html, 'data-bs-toggle="tab"'), 'sub-tabs switch client-side - a GLPI tab is loaded by AJAX');
check(!str_contains($html, 'hr_page=') && !str_contains($html, 'p_sessions='),
      'no server-side pager inside the tab - a link would navigate away from the User form');

// Both mapped strings are disclosed, so a reader can tell the history is whole.
check(str_contains($html, 'budi.santoso(Budi Santoso)'),
      'the header names every jms_user string it read');

// The deep link out to the report carries the user, and is honest that the
// report's search only reaches high-risk rows.
check(str_contains($html, 'tab=report'), 'the tab links out to the Command Audit Report');

// NOT str_contains($html, 'hr_q=') - that passes with an EMPTY term, which
// is exactly what shipped the first time: sessions()/commands() filter BY
// jms_user and never select it, so the renderer read a field that was never
// in the row. The link existed, looked right, and filtered on nothing.
preg_match_all('/hr_q=([^"&]*)/', $html, $qm);
$terms = array_filter($qm[1] ?? [], static fn($t) => $t !== '');
check(count($terms) === 2,
      'one report link per mapped account, each carrying a non-empty term (got ' . count($terms) . ')');
check(in_array(urlencode('budi.santoso(Budi Santoso)'), $qm[1] ?? [], true),
      'the second account gets its own link - one term cannot filter on both');

check(str_contains(strtolower($html), 'retention') || str_contains(strtolower($html), 'retensi'),
      'the tab discloses that history is bounded by retention');

// --- a full pane must say it may not be the whole story -------------------
// The sessions pane knows its total and says "27 of 91". The commands pane
// has no total (counting every command in the window is a second aggregate
// for a pane that is already a summary), so a FULL pane is the only signal
// left that there may be more behind it. Without this the tab reads as
// "these are all the commands", which is exactly the wrong thing to
// conclude about a person.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$rows = [];
for ($i = 0; $i < PluginIntegrasipamanUserAccess::ROW_LIMIT; $i++) {
    $rows[] = ['executed_at' => '2026-09-23 09:00:00', 'asset' => 'a', 'account' => 'root',
               'command' => 'ls', 'remote_addr' => '10.0.0.1', 'priority' => '', 'catatan' => ''];
}
$db->canned = [
    [['jms_user' => 'budi']],
    [['sessions' => 1, 'assets' => 1, 'failed' => 0]],
    [['high_risk' => 0]],
    [],        // sessions: none
    $rows,     // commands: a FULL pane
];
grant(READ);
ob_start();
PluginIntegrasipamanUserAccess::displayTabContentForItem(new User(42));
$full = ob_get_clean();
// Slice on the PANE DIV ids (id="..."), not on the bare string: the bare
// string first appears in the nav-tab hrefs above, so slicing there
// measured the navigation, not the panes - and both assertions passed
// against a disabled branch. Second time today a test went green on the
// wrong substring; the fix is to anchor on something that occurs once.
$ses_at = (int) strpos($full, 'id="integrasipaman-usr-sessions"');
$cmd_at = (int) strpos($full, 'id="integrasipaman-usr-commands"');
check($ses_at > 0 && $cmd_at > $ses_at, 'both pane divs are present, sessions before commands');

$ses_pane = substr($full, $ses_at, $cmd_at - $ses_at);
$cmd_pane = substr($full, $cmd_at);

check(str_contains($cmd_pane, 'most recent'),
      'a FULL commands pane warns that there may be more - it never implies completeness');
check(!str_contains($ses_pane, 'most recent'),
      'an empty sessions pane prints no "showing N of M" line over its own empty-row message');

// --- setup.php wiring -----------------------------------------------------
// Read the CODE, not the comments: comments here legitimately quote the
// registrations they explain, and scanning raw text finds those quotes.
// (Learned on assetsections_test.php, which failed on its own comment.)
$setup = (string) file_get_contents(__DIR__ . '/../setup.php');
$setup_code = '';
foreach (token_get_all($setup) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $setup_code .= is_array($t) ? $t[1] : $t;
}

check(str_contains($setup_code, "include_once(__DIR__ . '/inc/useraccess.class.php')"),
      'setup.php loads the class before registering it - registerClass() resolves by name');
check(preg_match("/registerClass\(\s*'PluginIntegrasipamanUserAccess',\s*\['addtabon' => 'User'\]\)/", $setup_code) === 1,
      'the tab is registered on the User itemtype');

// User is NOT an asset. The PGN Suite AssetTab contract covers Computer,
// NetworkEquipment, Security and Storage; routing a User tab through it
// would put this plugin's section on a tab that never renders on User.
$sections_src = (string) file_get_contents(__DIR__ . '/../inc/assetsections.class.php');
check(!preg_match("/ITEMTYPES\s*=\s*\[[^\]]*'User'/", $sections_src),
      'User is NOT routed through the PGN Suite AssetTab - it is not an asset itemtype');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
