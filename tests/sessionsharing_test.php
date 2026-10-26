<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/sessionsharing_test.php
//
// v4.2.0: who shared a session, with whom. Built from the share records the
// audit-log pull already stores (production, 2026-10-12: two rows).
//
// What goes wrong quietly, and is pinned here:
//   - the time shown is the IMPORT time: two shares made weeks apart read as
//     one second apart when a single pull fetched both;
//   - an empty recipient list is read as "shared with nobody", when it is a
//     share by link and code;
//   - a share that expired an hour ago still reads "success", because the
//     status was worked out once at import;
//   - verify_code - the secret that lets a holder join - reaches the page or
//     the export;
//   - rows or sessions of entities the viewer is not in come back.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
function __($s, $d = null) { return $s; }
function isCommandLine() { return false; }
// mbstring is on the VM; some local PHP builds lack it (the other suites shim it too).
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower((string) $s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
date_default_timezone_set('UTC');

class FakeDB
{
    public array $requests = [];
    public array $logs = [];
    public array $sessions = [];
    public function request($q)
    {
        $this->requests[] = $q;
        return new ArrayIterator(str_contains((string) $q['FROM'], 'sessions') ? $this->sessions : $this->logs);
    }
}
$DB = new FakeDB();
$_SESSION['glpiactiveentities'] = [6 => 6, 1 => 1];

require __DIR__ . '/../inc/sessionsharing.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$S = 'PluginIntegrasipamanSessionSharing';

$NOW = strtotime('2026-10-12 12:00:00');

// The two rows found on production, as they are stored.
$share_by_link = [
    'id' => 1, 'username' => 'Sysadmin Eight(sysadmin.eight)', 'status' => 'success',
    'source_id' => 'sh-1', 'date_creation' => '2026-08-29 07:24:40',
    'message' => json_encode([
        'id' => 'sh-1', 'verify_code' => 'SECRET-111', 'expired_time' => 60, 'users_display' => [],
        'session' => ['id' => '77648721-6f28-4c90-90e1-36ce7c79f33e'], 'creator' => ['id' => 'u1', 'name' => 'Sysadmin Eight(sysadmin.eight)'],
        'is_active' => true, 'date_created' => '2026/08/22 09:00:00 +0000', 'created_by' => 'Sysadmin Eight(sysadmin.eight)',
    ]),
];
$share_named = [
    'id' => 2, 'username' => 'Sysadmin Eight(sysadmin.eight)', 'status' => 'success',
    'source_id' => 'sh-2', 'date_creation' => '2026-08-29 07:24:41',
    'message' => json_encode([
        'id' => 'sh-2', 'verify_code' => 'SECRET-222', 'expired_time' => 60, 'users_display' => ['Sysadmin Eighteen(sysadmin.eighteen-e)'],
        'session' => ['id' => '94c7a001-01a2-4b33-a5c3-f8d538a7301d'], 'creator' => ['id' => 'u1', 'name' => 'Sysadmin Eight(sysadmin.eight)'],
        'is_active' => true, 'date_created' => '2026-10-12T11:30:00+00:00', 'created_by' => 'Sysadmin Eight(sysadmin.eight)',
    ]),
];

// --- WHEN ---------------------------------------------------------------------------
$d = $S::describe($share_by_link, null, $NOW);
check($d['shared_at'] === '2026-08-22 09:00:00' && $d['shared_at_known'] === true,
      'the time is the share\'s own (date_created), not the moment the pull imported it');
$d2 = $S::describe($share_named, null, $NOW);
check($d2['shared_at'] === '2026-10-12 11:30:00', 'both of JumpServer\'s time formats are read ("2026/08/22 09:00:00 +0000" and ISO 8601)');
check($d['shared_at'] !== substr($share_by_link['date_creation'], 0, 19), 'so it differs from the import time on the row, as it should');

$no_time = $share_named; $m = json_decode($no_time['message'], true); unset($m['date_created']); $no_time['message'] = json_encode($m);
$d3 = $S::describe($no_time, null, $NOW);
check($d3['shared_at'] === '2026-08-29 07:24:41' && $d3['shared_at_known'] === false,
      'with no time from JumpServer the import time is used, and the row says it is not the real one');
$bad_time = $share_named; $m = json_decode($bad_time['message'], true); $m['date_created'] = 'not a time'; $bad_time['message'] = json_encode($m);
check($S::describe($bad_time, null, $NOW)['shared_at_known'] === false, 'a value that is not a time counts as no time');

// --- WITH WHOM -------------------------------------------------------------------------
check($d['by_link'] === true && $d['recipients'] === [], 'an empty list is a share by link and code');
check($S::recipientsText($d) === 'Anyone with the link and code', 'and is said in words, not left as an empty cell');
check($d2['by_link'] === false && $d2['recipients'] === ['Sysadmin Eighteen(sysadmin.eighteen-e)'], 'a named recipient is listed');
check($S::recipientsText($d2) === 'Sysadmin Eighteen(sysadmin.eighteen-e)', 'and shown by name');
$many = $share_named; $m = json_decode($many['message'], true); $m['users_display'] = ['A(a)', '', '  ', 'B(b)', ['x']]; $many['message'] = json_encode($m);
check($S::describe($many, null, $NOW)['recipients'] === ['A(a)', 'B(b)'], 'blank and non-text entries in the list are dropped');
check($S::recipientsText($S::describe($many, null, $NOW)) === 'A(a), B(b)', 'several recipients are joined');

// --- WHO shared -------------------------------------------------------------------------
check($d['shared_by'] === 'Sysadmin Eight(sysadmin.eight)', 'the creator is shown');
$no_by = $share_named; $m = json_decode($no_by['message'], true); unset($m['created_by']); $no_by['message'] = json_encode($m);
check($S::describe($no_by, null, $NOW)['shared_by'] === 'Sysadmin Eight(sysadmin.eight)', 'without created_by, the creator object\'s name is used');

// --- STATUS and expiry -------------------------------------------------------------------
check($d['expires_at'] === '2026-08-22 10:00:00' && $d['status'] === 'expired', 'a share of 60 minutes made in August has expired, whatever the stored status said');
check($share_by_link['status'] === 'success', '(the stored status was "success": it was worked out at import and never again)');
check($d2['expires_at'] === '2026-10-12 12:30:00' && $d2['status'] === 'active', 'a share made 30 minutes ago with 60 minutes is still open');
check($S::describe($share_named, null, $NOW + 3600)['status'] === 'expired', 'and is expired an hour later: it follows the clock');
$off = $share_named; $m = json_decode($off['message'], true); $m['is_active'] = false; $off['message'] = json_encode($m);
check($S::describe($off, null, $NOW)['status'] === 'inactive', 'a share JumpServer marks inactive is revoked, even if its time has not run out');
$never = $share_named; $m = json_decode($never['message'], true); $m['expired_time'] = 0; $never['message'] = json_encode($m);
$dn = $S::describe($never, null, $NOW + 999999);
check($dn['expires_at'] === '' && $dn['status'] === 'active', 'no expiry given: no expiry shown, and not called expired');
$dj = $S::describe(['message' => 'not json', 'username' => 'x', 'date_creation' => '2026-10-12 11:00:00', 'source_id' => 'z'], null, $NOW);
check($dj['shared_by'] === 'x' && $dj['status'] === 'active' && $dj['recipients'] === [], 'a row whose message is not JSON does not fatal, and falls back on the row');

// --- the session ------------------------------------------------------------------------------
$ds = $S::describe($share_named, ['asset' => 'db-prod-02', 'jms_user' => 'agus(agus)'], $NOW);
check($ds['session_asset'] === 'db-prod-02' && $ds['session_user'] === 'agus(agus)', 'the asset and the owner come from the stored session');
check($d2['session_asset'] === '' && $d2['session_id'] === '94c7a001-01a2-4b33-a5c3-f8d538a7301d', 'with no stored session the id is kept and the asset is left unknown');

// --- the secret ------------------------------------------------------------------------------------
check(!str_contains(json_encode($d) . json_encode($d2) . json_encode($ds), 'SECRET'), 'verify_code is not in the result');
check(!str_contains(file_get_contents(__DIR__ . '/../front/sessionsharing.php'), 'verify_code'), 'and the page and the export never name it');

// --- find(): rows, filters, entities ---------------------------------------------------------------
$DB->logs     = [$share_by_link, $share_named];
$DB->sessions = [['jms_id' => '94c7a001-01a2-4b33-a5c3-f8d538a7301d', 'asset' => 'db-prod-02', 'jms_user' => 'agus(agus)']];
$all = $S::find([], $NOW);
check(count($all) === 2 && $all[0]['id'] === 'sh-2' && $all[1]['id'] === 'sh-1', 'newest share first, by the share\'s own time (not by import order)');
check($all[0]['session_asset'] === 'db-prod-02', 'the session is joined by its id');

$q = $DB->requests[0];
check($q['WHERE']['event_type'] === 'session_share' && $q['WHERE']['entities_id'] === [6, 1], 'only session shares, only the viewer\'s entities');
check($q['LIMIT'] === 5000, 'bounded');
$qs = $DB->requests[1];
check($qs['WHERE']['entities_id'] === [6, 1] && $qs['WHERE']['jms_id'] === ['77648721-6f28-4c90-90e1-36ce7c79f33e', '94c7a001-01a2-4b33-a5c3-f8d538a7301d'],
      'the sessions are looked up by id, and also only in the viewer\'s entities');

check(array_column($S::find(['from' => '2026-10-01'], $NOW), 'id') === ['sh-2'], 'from: on the share\'s own time');
check(array_column($S::find(['to' => '2026-08-31'], $NOW), 'id') === ['sh-1'], 'to: includes the whole last day');
check(array_column($S::find(['from' => '2026-10-12', 'to' => '2026-10-12'], $NOW), 'id') === ['sh-2'], 'one day');
check(count($S::find(['from' => 'garbage', 'to' => '2026-13-45'], $NOW)) === 2, 'a date that is not a date is ignored, not applied');
check(array_column($S::find(['q' => 'eighteen'], $NOW), 'id') === ['sh-2'], 'search finds a recipient, in any case');
check(array_column($S::find(['q' => 'DB-PROD'], $NOW), 'id') === ['sh-2'], 'an asset');
check(array_column($S::find(['q' => '77648721'], $NOW), 'id') === ['sh-1'], 'a session id');
check(count($S::find(['q' => 'sysadmin.eight)'], $NOW)) === 2, 'the creator');
check($S::find(['q' => 'nobody'], $NOW) === [], 'and nothing when nothing matches');

$DB->logs = []; $DB->requests = [];
check($S::find([], $NOW) === [] && count($DB->requests) === 1, 'no shares: no sessions lookup at all');

// no active entity: nothing, not everything
$DB->logs = [$share_named]; $DB->requests = []; $_SESSION['glpiactiveentities'] = [];
$S::find([], $NOW);
check($DB->requests[0]['WHERE']['entities_id'] === [-1], 'a session with no active entity reads nothing');

// --- the page ------------------------------------------------------------------------------------------
$page = file_get_contents(__DIR__ . '/../front/sessionsharing.php');
check(str_contains($page, 'PluginIntegrasipamanProfile::RIGHT_REPORT, READ'), 'the page needs the report right');
// v4.6.0: each tab guards its own export, so a format=csv meant for one tab
// cannot be answered by the other's block. What matters is unchanged - the
// CSV leaves before a byte of HTML does.
// The header position is matched at the start of a line, not with a bare
// strpos: a COMMENT mentioning Html::header() sits above the export blocks
// and would otherwise be found first, quietly passing this check no matter
// where the real call was.
preg_match('/^Html::header\(/m', $page, $_h, PREG_OFFSET_CAPTURE);
check(preg_match('/if \(\$tab === \'sharing\' && \(\$_GET\[\'format\'\] \?\? \'\'\) === \'csv\'\) \{.*?exit;/s', $page) === 1
      && $_h !== [] && strpos($page, "=== 'csv'") < $_h[0][1], 'the CSV is sent before any HTML, from the same list');
check(str_contains($page, 'PluginIntegrasipamanExporter::sendCsv('), 'through the exporter that neutralises spreadsheet formulas');
// v4.6.0: one option, 'session', covering both of the page's in-page tabs.
check(preg_match("/Menu::SECTOR,\s*'integrasipaman',\s*'session'\s*\);\s*\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::renderSubNav\('integrasipaman', 'session'\);/", $page) === 1,
      'it is in the shared sector under the Session option');
$echoes = preg_match_all("/echo [^;]*\\\$share\['(shared_by|session_asset|session_user|session_id|shared_at|expires_at)'\][^;]*;/", $page, $mm);
check($echoes >= 5, "every value from JumpServer that is printed is printed through htmlescape() ($echoes places)");
check(!preg_match("/echo[^;]*\\\$share\['(shared_by|session_asset|session_user|session_id)'\]\s*\./", str_replace('htmlescape($share', 'ESC($share', $page)),
      'none of them is concatenated into HTML without it');

// --- findJoins(): the v4.5.0 table ------------------------------------------------------------
// Unlike find(), this filters in SQL, because since v4.5.0 the event time is
// a column. The shape of that query is what is pinned here: get the event
// type or the entity restriction wrong and the page shows another entity's
// joins, or none.
// The no-active-entity check above left the session empty on purpose; put a
// working viewer back before testing anything else.
$_SESSION['glpiactiveentities'] = [6 => 6, 1 => 1];

$join_row = static fn (string $id, string $when, string $who): array => [
    'date_creation' => $when,
    'event_type'    => 'session_join',
    'username'      => $who,
    'source_id'     => $id,
    'message'       => json_encode([
        'id' => $id, 'joiner' => ['name' => $who], 'session' => '94c7a001-01a2-4b33-a5c3-f8d538a7301d',
        'remote_addr' => '10.0.2.41', 'login_from' => ['value' => 'WT', 'label' => 'Web Terminal'],
        'is_success' => true, 'date_joined' => $when,
    ]),
];

$DB->requests = [];
$DB->logs     = [$join_row('jn-2', '2026-10-16 23:35:45', 'Sysadmin Sixteen'), $join_row('jn-1', '2026-10-15 08:00:00', 'Sysadmin Seventeen')];
$DB->sessions = [['jms_id' => '94c7a001-01a2-4b33-a5c3-f8d538a7301d', 'asset' => 'db-prod-02', 'jms_user' => 'agus(agus)']];

$joins = $S::findJoins([]);
check(count($joins) === 2, 'the joins come back');
check($joins[0]['session_asset'] === 'db-prod-02', 'a join is joined to its session, by the bare id it stores');

$jq = $DB->requests[0];
check($jq['WHERE']['event_type'] === 'session_join', 'only joins');
check($jq['WHERE']['entities_id'] === [6, 1], 'only the viewer\'s entities');
check($jq['ORDER'] === 'date_creation DESC', 'newest first, from the column - no sort through decoded JSON');
check($jq['LIMIT'] === 5000, 'bounded like the shares');

// The date window is a column comparison now, not a pass over the rows.
$DB->requests = [];
$S::findJoins(['from' => '2026-10-16', 'to' => '2026-10-16']);
$bounds = array_values(array_filter($DB->requests[0]['WHERE'], 'is_array'));
$texts  = array_map(static fn ($b) => ($b['date_creation'][0] ?? '') . ' ' . ($b['date_creation'][1] ?? ''), $bounds);
check(in_array('>= 2026-10-16 00:00:00', $texts, true), 'from goes into the query as a column bound');
check(in_array('<= 2026-10-16 23:59:59', $texts, true), 'and to covers the whole last day');

$DB->requests = [];
$S::findJoins(['from' => 'garbage', 'to' => '2026-13-45']);
check(array_filter($DB->requests[0]['WHERE'], 'is_array') === [] || !str_contains(json_encode($DB->requests[0]['WHERE']), 'garbage'),
      'a date that is not a date never reaches the query');

check(array_column($S::findJoins(['q' => 'seventeen']), 'id') === ['jn-1'], 'search finds a joiner, in any case');
check(array_column($S::findJoins(['q' => '10.0.2.41']), 'id') === ['jn-2', 'jn-1'], 'and the address they came from');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
