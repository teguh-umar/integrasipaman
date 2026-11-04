<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/groupmembers_pull_test.php
//
// Phase 1's missing leg: rule JSON says a group grants access, but not who is
// in that group. Two ways this can look right and be wrong:
//
//   - a partial pull overwrites a complete one. The page then shows a group
//     with fewer members than JumpServer has, and no error anywhere.
//   - the reported users_amount and the members actually returned disagree.
//     The probe saw 5140 from the group-detail `users` field against 5124
//     from the filter endpoint; the filter wins, but the count is still the
//     cheap proof that the filter did not quietly come back global.
//
// So the pull fetches EVERYTHING first. Only when every group succeeded and
// every count matched does it delete the previous rows. A failure leaves the
// old data exactly where it was.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
// mbstring is absent from this CLI; GLPI requires it. Character-wise, as mb_substr() is,
// so a multi-byte fixture proves the cut is by character, not by byte.
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null, $e = null) { preg_match_all('/./us', (string) $s, $m); return implode('', array_slice($m[0], $start, $length)); } }

function __($s, $d = null) { return $s; }

class QueryExpression { public function __construct(public string $e) {} }

$GLOBALS['API_GROUPS']     = null;
$GLOBALS['API_MEMBERS']    = [];
$GLOBALS['API_MEMBERS_BY_GROUP'] = [];
$GLOBALS['API_MEMBER_FAIL'] = false;
$GLOBALS['API_MULTI_PAGES'] = [];
$GLOBALS['API_ASKED']       = [];

class PluginIntegrasipamanJmsApi
{
    public static function signedGet($path, $base, $kid, $secret, $org)
    {
        $GLOBALS['API_ASKED'][] = $path;
        if (!str_contains($path, '/users/groups/')) {
            return [];
        }
        return $GLOBALS['API_GROUPS'];
    }

    public static function signedGetEach($path, $base, $kid, $secret, $org, $on_page, $page_size = 100, $max_pages = 100)
    {
        $GLOBALS['API_ASKED'][] = $path;
        $GLOBALS['API_MEMBER_PAGE_SIZE'] = $page_size;
        if (!str_contains($path, '/users/users/') || $GLOBALS['API_MEMBER_FAIL']) {
            return false;
        }

        $pages = $GLOBALS['API_MULTI_PAGES'][$path] ?? [$GLOBALS['API_MEMBERS_BY_GROUP'][$path] ?? $GLOBALS['API_MEMBERS']];
        foreach ($pages as $page) {
            if ($on_page($page) === false) {
                return true;
            }
        }

        return true;
    }
}

class FakeDB
{
    public array $seen = [];

    public function quoteName($n) { return "`$n`"; }
    public function request($q) { $this->seen[] = $q; return new ArrayIterator([]); }
    public function delete($table, $where) { $this->seen[] = ['DELETE' => $table, 'w' => $where]; return true; }
    public function insert($table, $row) { $this->seen[] = ['INSERT' => $table, 'r' => $row]; return true; }
    public function updateOrInsert($table, $params, $where) { $this->seen[] = ['UPSERT' => $table, 'p' => $params, 'w' => $where]; return true; }
}

class CronTask
{
    public array $logs = [];
    public function log($msg) { $this->logs[] = $msg; }
}

require __DIR__ . '/../inc/entitlement.class.php';

PluginIntegrasipamanConnection::setCurrent([
    'id' => 4, 'entities_id' => 6, 'is_recursive' => 1,
    'url' => 'https://x', 'access_key_id' => 'k', 'access_key_secret' => 's', 'org_id' => 'o',
]);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
function j($v) { return json_encode($v); }

$E = 'PluginIntegrasipamanEntitlement';
$GROUPS  = 'glpi_plugin_jumpserver_usergroups';
$MEMBERS = 'glpi_plugin_jumpserver_groupmembers';

function reset_api(): void
{
    $GLOBALS['API_GROUPS']      = null;
    $GLOBALS['API_MEMBERS']     = [];
    $GLOBALS['API_MEMBERS_BY_GROUP'] = [];
    $GLOBALS['API_MEMBER_FAIL'] = false;
    $GLOBALS['API_MULTI_PAGES'] = [];
    $GLOBALS['API_ASKED']       = [];
    unset($GLOBALS['API_MEMBER_PAGE_SIZE']);
}

// --- happy path: every group, empty ones included --------------------------
reset_api();
$GLOBALS['API_GROUPS'] = [
    ['id' => 'G-OPS',   'name' => 'Operations', 'users_amount' => 2],
    ['id' => 'G-EMPTY', 'name' => 'Empty',      'users_amount' => 0],
];
$GLOBALS['API_MEMBERS'] = [
    ['id' => 'U-ALICE', 'username' => 'alice', 'name' => 'Alice Example'],
    ['id' => 'U-BOB',   'username' => 'bob',   'name' => 'Bob Example'],
];
$GLOBALS['API_MEMBERS_BY_GROUP'] = [
    '/api/v1/users/users/?group_id=G-OPS'   => $GLOBALS['API_MEMBERS'],
    '/api/v1/users/users/?group_id=G-EMPTY' => [],
];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$task = new CronTask();
$n = $E::pullGroupMembers(['https://x', 'k', 's', 'o'], $task);

check($n === 2, 'the pull returns the number of memberships stored (got ' . var_export($n, true) . ')');

$deletes = array_values(array_filter($db->seen, static fn($q) => isset($q['DELETE'])));
check(count($deletes) === 2, 'both tables are cleared before the new snapshot is written');
check(($deletes[0]['DELETE'] ?? '') === $GROUPS && (int) ($deletes[0]['w']['plugin_jumpserver_connections_id'] ?? 0) === 4,
      'the group clear is scoped to this connection, never another JumpServer');
check(($deletes[1]['DELETE'] ?? '') === $MEMBERS && (int) ($deletes[1]['w']['plugin_jumpserver_connections_id'] ?? 0) === 4,
      'the membership clear is scoped to this connection too');

$group_rows = [];
$member_rows = [];
foreach ($db->seen as $q) {
    if (($q['INSERT'] ?? '') === $GROUPS)  { $group_rows[]  = $q['r']; }
    if (($q['INSERT'] ?? '') === $MEMBERS) { $member_rows[] = $q['r']; }
}

check(count($group_rows) === 3, 'two groups plus the pull marker are stored (got ' . count($group_rows) . ')');
$by_id = [];
foreach ($group_rows as $r) {
    $by_id[(string) ($r['jms_id'] ?? '')] = $r;
}
check(isset($by_id['G-OPS'], $by_id['G-EMPTY'], $by_id['']),
      'the empty group and the marker are both kept - neither can be inferred from the join table');
check((int) ($by_id['G-EMPTY']['users_amount'] ?? -1) === 0,
      'an empty group keeps JumpServer\'s users_amount, so the list can say it grants nobody');
check((string) ($by_id['G-OPS']['name'] ?? '') === 'Operations', 'the group name is stored');
foreach ($group_rows as $r) {
    check((int) ($r['plugin_jumpserver_connections_id'] ?? 0) === 4 && (int) ($r['entities_id'] ?? 0) === 6,
          'every group row is stamped with the connection and its entity');
    check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($r['date_pulled'] ?? '')) === 1,
          'every group row carries a SQL pull time');
}

check(count($member_rows) === 2, 'two membership rows are stored');
$alice = null;
foreach ($member_rows as $r) {
    if (($r['user_jms_id'] ?? '') === 'U-ALICE') { $alice = $r; }
    check((string) ($r['user_groups_jms_id'] ?? '') === 'G-OPS', 'a member is tied to the group it came from');
    check((int) ($r['plugin_jumpserver_connections_id'] ?? 0) === 4 && (int) ($r['entities_id'] ?? 0) === 6,
          'a membership is stamped with the connection and its entity');
}
check($alice !== null && ($alice['user_login'] ?? '') === 'alice' && ($alice['user_display'] ?? '') === 'Alice Example',
      'both the login and the display name are kept - usermap matches against either');

check($GLOBALS['API_MEMBER_PAGE_SIZE'] === 500,
      'members are read 500 at a time, not the 100-row default - the largest group has 5124');

// --- every group is fetched, not just the first ----------------------------
$member_paths = array_values(array_filter($GLOBALS['API_ASKED'], static fn($p) => str_contains($p, '/users/users/')));
check(count($member_paths) === 2, 'one member fetch per group (got ' . count($member_paths) . ')');
check(str_contains($member_paths[0], 'group_id=G-OPS') && str_contains($member_paths[1], 'group_id=G-EMPTY'),
      'each fetch filters on that group\'s id - the only filter the probe proved');
check(str_contains($GLOBALS['API_ASKED'][0] ?? '', '/users/groups/'), 'the group list is read from the groups endpoint');

// --- a count mismatch must write NOTHING ----------------------------------
reset_api();
$GLOBALS['API_GROUPS'] = [
    ['id' => 'G-OPS', 'name' => 'Operations', 'users_amount' => 3],
];
$GLOBALS['API_MEMBERS'] = [
    ['id' => 'U-ALICE', 'username' => 'alice', 'name' => 'Alice Example'],
    ['id' => 'U-BOB',   'username' => 'bob',   'name' => 'Bob Example'],
];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$n = $E::pullGroupMembers(['https://x', 'k', 's', 'o']);
check($n === -1, 'users_amount disagreeing with the members returned is a failure, not an empty answer');
check($db->seen === [], 'and the previous snapshot is untouched: no DELETE, no INSERT');

// --- an empty count must still prove the fetch returned nothing -----------
// The same comparison, the other way round. A filter ignored by the server
// returns the global user list; on a group with users_amount=0 that is a
// mismatch, not "everyone is in this group".
reset_api();
$GLOBALS['API_GROUPS'] = [['id' => 'G-EMPTY', 'name' => 'Empty', 'users_amount' => 0]];
$GLOBALS['API_MEMBERS'] = [['id' => 'U-ALICE', 'username' => 'alice', 'name' => 'Alice Example']];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === -1,
      'a group that should have no members but returns one is rejected');
check($db->seen === [], 'and nothing is written');

// --- a failed group-list read writes nothing ------------------------------
reset_api();
$GLOBALS['API_GROUPS'] = null;   // null = transport/HTTP failure, [] = a real empty answer
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === -1, 'a failed group-list read fails the pull');
check($db->seen === [], 'and leaves the previous snapshot alone');

// --- a failed member page writes nothing, including groups already read ---
reset_api();
$GLOBALS['API_GROUPS'] = [
    ['id' => 'G-OPS',   'name' => 'Operations', 'users_amount' => 1],
    ['id' => 'G-LATER', 'name' => 'Later',      'users_amount' => 1],
];
$GLOBALS['API_MEMBER_FAIL'] = true;
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === -1,
      'a page failure mid-pull fails the whole pull');
check($db->seen === [], 'a half-read membership set never replaces a complete one');

// --- duplicates on a page collapse; the count is compared after dedup -----
reset_api();
$GLOBALS['API_GROUPS'] = [['id' => 'G-OPS', 'name' => 'Operations', 'users_amount' => 1]];
$GLOBALS['API_MEMBERS'] = [
    ['id' => 'U-ALICE', 'username' => 'alice', 'name' => 'Alice Example'],
    ['id' => 'U-ALICE', 'username' => 'alice', 'name' => 'Alice Example'],
];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === 1,
      'the same user twice in one group is one membership');
$member_rows = array_values(array_filter($db->seen, static fn($q) => ($q['INSERT'] ?? '') === $MEMBERS));
check(count($member_rows) === 1, 'and is stored once');

// --- a group read across pages is one set, not the last page -------------
reset_api();
$GLOBALS['API_GROUPS'] = [['id' => 'G-OPS', 'name' => 'Operations', 'users_amount' => 2]];
$GLOBALS['API_MULTI_PAGES'] = [
    '/api/v1/users/users/?group_id=G-OPS' => [
        [['id' => 'U-ALICE', 'username' => 'alice', 'name' => 'Alice Example']],
        [['id' => 'U-BOB',   'username' => 'bob',   'name' => 'Bob Example']],
    ],
];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === 2,
      'members are accumulated across pages and counted together');
$member_rows = array_values(array_filter($db->seen, static fn($q) => ($q['INSERT'] ?? '') === $MEMBERS));
check(count($member_rows) === 2, 'both pages reach the stored snapshot');

// --- a malformed member entry fails the pull ------------------------------
reset_api();
$GLOBALS['API_GROUPS'] = [['id' => 'G-OPS', 'name' => 'Operations', 'users_amount' => 1]];
$GLOBALS['API_MEMBERS'] = [['username' => 'alice', 'name' => 'Alice Example']];  // no id
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === -1,
      'a member without an id fails the pull; dropping it would under-report access');
check($db->seen === [], 'and nothing is written');

// --- a malformed group cannot be skipped silently ------------------------
reset_api();
$GLOBALS['API_GROUPS'] = [['name' => 'No id', 'users_amount' => 0]];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === -1,
      'a group without an id fails the pull; skipping it would silently drop its members');
check($db->seen === [], 'and nothing is written');

// --- a successful pull with no groups still records that it ran -----------
reset_api();
$GLOBALS['API_GROUPS'] = [];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::pullGroupMembers(['https://x', 'k', 's', 'o']) === 0,
      'zero groups is a successful answer, not a failure');
$group_rows = array_values(array_filter($db->seen, static fn($q) => ($q['INSERT'] ?? '') === $GROUPS));
check(count($group_rows) === 1 && ($group_rows[0]['r']['jms_id'] ?? null) === '',
      'and the pull marker is written, so "never collected" stays distinguishable');

// --- wiring: the entitlement cron calls it -------------------------------
$src = (string) file_get_contents(__DIR__ . '/../inc/entitlement.class.php');
check(str_contains($src, 'pullGroupMembers($credentials'),
      'the entitlement cron calls the membership pull - it is not a second cron task');
// v4.10.0-rc3 moved the landmark twice over: pullFor() now calls hostIndex(),
// because the same host response also carries the names the access report
// needs, and hostLookup() survives as a one-line wrapper that ALSO contains
// the string. A needle counted from the start of the file therefore finds the
// wrapper, not the call site. The order is a property of pullFor(), so it is
// read inside pullFor() - the earlier version of this check was pinned to a
// file offset and reported false when the landmark moved.
$pull_for = substr($src, (int) strpos($src, 'private static function pullFor'));
check(str_contains($pull_for, 'hostIndex($credentials)'),
      'the host pull is still a single call in pullFor()');
check(strpos($pull_for, 'pullGroupMembers($credentials') < strpos($pull_for, 'hostIndex($credentials)'),
      'membership is pulled before per-Computer refreshes, once per connection');

// --- Task 2: group description is kept -----------------------------------
reset_api();
$GLOBALS['API_GROUPS'] = [['id' => 'G-C', 'name' => 'Apps', 'comment' => 'Tim aplikasi', 'users_amount' => 0]];
$GLOBALS['API_MEMBERS_BY_GROUP'] = ['/api/v1/users/users/?group_id=G-C' => []];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$E::pullGroupMembers(['https://x', 'k', 's', 'o']);
$crow = [];
foreach ($db->seen as $q) {
    if (($q['INSERT'] ?? '') === $GROUPS) { $crow[(string) ($q['r']['jms_id'] ?? '')] = $q['r']; }
}
check(($crow['G-C']['comment'] ?? null) === 'Tim aplikasi', 'the group description is stored');
check(array_key_exists('comment', $crow['']) && $crow[''] ['comment'] === '', 'the marker row has comment ""');

// --- final review, fix 2a: the comment fits usergroups.comment VARCHAR(255) --
// On GLPI 11 a value that does not fit is a RuntimeException on strict MySQL,
// after the DELETE of the previous snapshot.
reset_api();
$GLOBALS['API_GROUPS'] = [['id' => 'G-L', 'name' => 'Long', 'comment' => str_repeat('é', 300), 'users_amount' => 0]];
$GLOBALS['API_MEMBERS_BY_GROUP'] = ['/api/v1/users/users/?group_id=G-L' => []];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$E::pullGroupMembers(['https://x', 'k', 's', 'o']);
$lrow = [];
foreach ($db->seen as $q) {
    if (($q['INSERT'] ?? '') === $GROUPS && ($q['r']['jms_id'] ?? '') === 'G-L') { $lrow = $q['r']; }
}
$lcom = (string) ($lrow['comment'] ?? '');
check($lcom === str_repeat('é', 255),
      'a 300-character group comment is stored at 255 characters (got ' . preg_match_all('/./us', $lcom) . ')');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
