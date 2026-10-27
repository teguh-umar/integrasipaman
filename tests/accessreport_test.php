<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/accessreport_test.php
//
// Phase 1's acceptance check lives here. On Computer 46 the Computer tab
// recorded 4 entitlements and 2 of them came through a group. A report built
// from users_json alone would show 2 and look complete; one that expands a
// group into its members would show 11 and look thorough. Neither is the
// question: this report counts GRANT PATHS, so the asset answers 4 - two
// direct, two naming a group.
//
// The fixture is invented. No live name, host or address reaches this file.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

function __($s, $d = null) { return $s; }
function _n($s, $p, $n, $d = null) { return $n == 1 ? $s : $p; }
if (!function_exists('mb_stripos')) {
    function mb_stripos($h, $n, $o = 0) { return stripos((string) $h, (string) $n, $o); }
}

class QueryExpression
{
    public function __construct(public string $e) {}
    public function __toString() { return $this->e; }
}

class Session
{
    public static function isCron() { return false; }
}
function isCommandLine() { return false; }

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

require __DIR__ . '/../inc/accessreport.class.php';

$GLOBALS['DB'] = new FakeDB();
$_SESSION = ['glpiactiveentities' => [6, 7]];

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
function j($v) { return json_encode($v); }

$R = 'PluginIntegrasipamanAccessReport';
$E = 'PluginIntegrasipamanEntitlement';

$recent = date('Y-m-d H:i:s', strtotime('-1 day'));
$old    = '2020-01-01 00:00:00';

function rule_row(
    string $rule,
    array $users,
    array $groups,
    int $computer = 46,
    string $computer_name = 'example-app-46',
    bool $valid = true
): array {
    return [
        'computers_id'     => $computer,
        'computer_name'    => $computer_name,
        'rule_jms_id'      => $rule,
        'rule_name'        => 'Rule ' . $rule,
        // forComputer() reads the unaliased names; build() reads the aliases.
        // One fixture feeds both so the comparison below is real.
        'permrules_jms_id' => $rule,
        'name'             => 'Rule ' . $rule,
        'users_json'       => json_encode($users),
        'user_groups_json' => json_encode($groups),
        'accounts'         => 'root',
        'actions'          => 'connect',
        'is_valid'         => $valid ? 1 : 0,
        'is_expired'       => 0,
        'date_expired'     => null,
        'date_refreshed'   => '2026-10-20 08:00:00',
    ];
}

// Computer 46, the known-true shape: R1 group only, R2 no members,
// R3 one direct user, R4 one direct user plus the same group.
$grant_rows = [
    rule_row('R1', [], [['id' => 'G-OPS', 'display' => 'Operations']]),
    rule_row('R3', [['id' => 'U-ALICE', 'display' => 'Alice Example (alice)']], []),
    rule_row('R4',
        [['id' => 'U-BOB', 'display' => 'Bob Example (bob)']],
        [['id' => 'G-OPS', 'display' => 'Operations']]),
    rule_row('R1', [], [['id' => 'G-OPS', 'display' => 'Operations']], 47, 'example-db-47'),
    rule_row('R3', [['id' => 'U-ALICE', 'display' => 'Alice Example (alice)']], [], 47, 'example-db-47'),
];

$group_rows = [
    ['jms_id' => 'G-OPS',   'name' => 'Operations', 'users_amount' => 2, 'date_pulled' => $recent],
    ['jms_id' => 'G-EMPTY', 'name' => 'Empty',      'users_amount' => 0, 'date_pulled' => $recent],
    ['jms_id' => 'G-OTHER', 'name' => 'Other',      'users_amount' => 1, 'date_pulled' => $recent],
    ['jms_id' => '',        'name' => '',           'users_amount' => 0, 'date_pulled' => $recent],
];
$member_rows = [
    ['user_groups_jms_id' => 'G-OPS',   'user_jms_id' => 'U-ALICE', 'user_login' => 'alice', 'user_display' => 'Alice Example'],
    ['user_groups_jms_id' => 'G-OPS',   'user_jms_id' => 'U-BOB',   'user_login' => 'bob',   'user_display' => 'Bob Example'],
    ['user_groups_jms_id' => 'G-OTHER', 'user_jms_id' => 'U-CAROL', 'user_login' => 'carol', 'user_display' => 'Carol Example'],
];

/** Run one build() against a fresh FakeDB and keep the DB for query checks. */
function build_with(
    array $filters = [],
    ?array $groups = null,
    ?array $members = null,
    ?array $grants = null
): array {
    global $group_rows, $member_rows, $grant_rows;

    $db = new FakeDB();
    $db->canned = [
        $groups ?? $group_rows,
        $members ?? $member_rows,
        $grants ?? $grant_rows,
    ];
    $GLOBALS['DB'] = $db;

    return [$db, PluginIntegrasipamanAccessReport::build($filters)];
}

// --- the acceptance check: Computer 46 has 4 ways in, 2 of them a group ----
[$db, $c46report] = build_with(['computer' => '46']);
$c46 = $c46report['grants'];
check(count($c46) === 4,
      'Computer 46 has 4 grant paths, not 2 (users_json alone) and not 11 (group expanded into people) - got ' . count($c46));
$direct = array_values(array_filter($c46, static fn (array $r): bool => $r['type'] === 'user'));
$groups = array_values(array_filter($c46, static fn (array $r): bool => $r['type'] === 'group'));
check(count($direct) === 2, '2 of the 4 are direct (got ' . count($direct) . ')');
check(count($groups) === 2, '2 of the 4 name a group as the grant path (got ' . count($groups) . ')');
$direct_names = array_map(static fn (array $r): string => (string) $r['member_display'], $direct);
sort($direct_names);
check($direct_names === ['Alice Example (alice)', 'Bob Example (bob)'],
      'the direct rows are the two the Computer tab already shows');
check(array_unique(array_map(static fn (array $r): string => (string) $r['group_jms_id'], $groups)) === ['G-OPS'],
      'both group rows name the same group through two different rules - deduping by (asset, user, group) would answer 3');
check(array_map(static fn (array $r): string => (string) $r['via'], $groups) === ['Rule R1', 'Rule R4'],
      'the two group rows are two paths: the same group reaches the asset through R1 and R4');

// --- the direct rows are the Computer tab's direct rows, compared ----------
// forComputer() is the live pane. The report must not have a second matcher.
$db = new FakeDB();
// FakeDB does not honour WHERE, so hand it what the real query would return.
$db->canned = [array_values(array_filter($grant_rows, static fn (array $r): bool => $r['computers_id'] === 46))];
$GLOBALS['DB'] = $db;
$pane = $E::forComputer(46);
$pane_direct = array_values(array_map(
    static fn (array $r): string => (string) ($r['display'] ?? ''),
    array_filter($pane, static fn (array $r): bool => ($r['type'] ?? '') === 'user')
));
sort($pane_direct);
check($pane_direct === $direct_names,
      'the report\'s direct rows are exactly the Computer tab\'s direct rows');
check(count($pane) === 4 && count(array_filter($pane, static fn (array $r): bool => ($r['type'] ?? '') === 'group')) === 2,
      'the Computer tab itself answers 4 / 2 - the same shape the report must reproduce');

// --- unfiltered: every asset, and the group list is not narrowed by filters --
[$db, $report] = build_with();
check(count($report['grants']) === 6, 'without a filter every grant path is returned (got ' . count($report['grants']) . ')');
check($report['summary']['grants'] === 6 && $report['summary']['direct'] === 3 && $report['summary']['group'] === 3,
      'the summary counts grant paths by type, not people');
check($report['summary']['assets'] === 2 && $report['summary']['groups'] === 1,
      'the summary counts distinct assets and only the groups that actually grant access');

// --- filters ---------------------------------------------------------------
[, $only_46] = build_with(['computer' => 'example-app-46']);
check(count($only_46['grants']) === 4, 'the computer filter matches a name as well as an id');

[, $only_group] = build_with(['group' => 'operations']);
check(count($only_group['grants']) === 3 && count(array_filter($only_group['grants'], static fn (array $r): bool => $r['type'] !== 'group')) === 0,
      'the group filter returns that group\'s grant paths and nothing direct');

[, $only_user] = build_with(['user' => 'alice']);
check(count($only_user['grants']) === 5,
      'the user filter includes the groups that contain that person, not only direct rows (got ' . count($only_user['grants']) . ')');
foreach ($only_user['grants'] as $row) {
    $ok = $row['type'] === 'user'
        ? mb_stripos((string) $row['member_display'], 'alice') !== false
        : (bool) array_filter($row['group_members'], static fn (array $m): bool => mb_stripos($m['login'] . ' ' . $m['display'], 'alice') !== false);
    check($ok, 'every row returned for a user is a path that user can take');
}

// --- the group list: empty groups included, counts from the snapshot --------
check(count($report['groups']) === 3, 'the group list has the three real groups, marker excluded');
$by_name = [];
foreach ($report['groups'] as $g) { $by_name[$g['name']] = $g; }
check(isset($by_name['Operations'], $by_name['Empty'], $by_name['Other']),
      'an empty group is still listed - it grants nobody and must not vanish');
check($by_name['Operations']['members'] === 2 && $by_name['Operations']['users_amount'] === 2,
      'a group carries the stored member count beside JumpServer\'s users_amount');
check($by_name['Operations']['rules'] === 2 && $by_name['Operations']['assets'] === 2,
      'a group counts the rules and assets it reaches');
check($by_name['Empty']['members'] === 0 && $by_name['Empty']['rules'] === 0,
      'a group that grants nothing is shown as such, not hidden');

// --- membership state ------------------------------------------------------
check($report['membership']['collected'] === true, 'a marker row means membership was collected');
check($report['membership']['stale'] === false, 'a recent pull is not stale');
check($report['coverage'] === ['granted' => 1, 'known' => 1, 'missing' => [], 'mismatch' => []],
      'the granting group has membership data, so coverage is complete');

// --- every read is restricted to the viewer's entities ---------------------
check(count($db->seen) === 3, 'one build() is three reads: groups, memberships, grants (got ' . count($db->seen) . ')');
foreach ($db->seen as $i => $q) {
    check(str_contains(j($q), 'entities_id') && str_contains(j($q), '[6,7]'),
          'read ' . ($i + 1) . ' is limited to the viewer\'s active entities');
}

// --- never collected is a state, not "nobody is in any group" --------------
$db = new FakeDB();
$db->canned = [[], [], $grant_rows];
$GLOBALS['DB'] = $db;
$empty = $R::build();
check($empty['membership'] === ['collected' => false, 'at' => null, 'stale' => true],
      'no rows at all means membership has never been collected');
check($empty['coverage']['missing'] === ['Operations'],
      'the page can name the group whose members it cannot count');
$unknown_group_rows = array_values(array_filter($empty['grants'], static fn (array $r): bool => $r['type'] === 'group'));
check(count($unknown_group_rows) === 3, 'the group grant paths are still known - the rule cache does not need membership');
foreach ($unknown_group_rows as $row) {
    check($row['group_known'] === false && $row['group_members'] === [],
          'and they say the members are unknown rather than empty');
}

// --- a stale snapshot is still used, but says so ---------------------------
$db = new FakeDB();
$db->canned = [
    [['jms_id' => 'G-OPS', 'name' => 'Operations', 'users_amount' => 2, 'date_pulled' => $old]],
    $member_rows,
    $grant_rows,
];
$GLOBALS['DB'] = $db;
$stale = $R::build();
check($stale['membership']['collected'] === true && $stale['membership']['stale'] === true,
      'an old snapshot is reported as stale, not as absent');
check($stale['membership']['at'] === $old, 'and the page can say when it was collected');

// --- a count that disagrees with JumpServer is visible --------------------
$db = new FakeDB();
$db->canned = [
    [['jms_id' => 'G-OPS', 'name' => 'Operations', 'users_amount' => 5, 'date_pulled' => $recent]],
    $member_rows,
    $grant_rows,
];
$GLOBALS['DB'] = $db;
$short = $R::build();
$ops = array_values(array_filter($short['groups'], static fn (array $g): bool => $g['jms_id'] === 'G-OPS'))[0];
check($ops['mismatch'] === true, 'a stored member count that disagrees with users_amount is flagged');
check($short['coverage']['mismatch'] === ['Operations'], 'and the summary of that disagreement names the group');

// --- two connections may issue the same group UUID -------------------------
// Their members are not interchangeable. A report that keyed groups by UUID
// alone would attach one bastion's people to the other's rule.
$same_uuid_groups = [
    ['jms_id' => 'G-SHARED', 'name' => 'Ops One', 'users_amount' => 1, 'date_pulled' => $recent,
     PluginIntegrasipamanConnection::FK => 1],
    ['jms_id' => 'G-SHARED', 'name' => 'Ops Two', 'users_amount' => 1, 'date_pulled' => $recent,
     PluginIntegrasipamanConnection::FK => 2],
];
$same_uuid_members = [
    ['user_groups_jms_id' => 'G-SHARED', 'user_jms_id' => 'U-ALICE', 'user_login' => 'alice',
     'user_display' => 'Alice Example', PluginIntegrasipamanConnection::FK => 1],
    ['user_groups_jms_id' => 'G-SHARED', 'user_jms_id' => 'U-BOB', 'user_login' => 'bob',
     'user_display' => 'Bob Example', PluginIntegrasipamanConnection::FK => 2],
];
$same_uuid_grants = [
    rule_row('R1', [], [['id' => 'G-SHARED', 'display' => 'Ops One']], 46, 'example-app-46'),
    rule_row('R2', [], [['id' => 'G-SHARED', 'display' => 'Ops Two']], 47, 'example-db-47'),
];
$same_uuid_grants[0][PluginIntegrasipamanConnection::FK] = 1;
$same_uuid_grants[1][PluginIntegrasipamanConnection::FK] = 2;
// The fixture's aliased column carries the connection the join selected.
$same_uuid_grants[0]['connection_id'] = 1;
$same_uuid_grants[1]['connection_id'] = 2;

[$db, $split] = build_with([], $same_uuid_groups, $same_uuid_members, $same_uuid_grants);
$by_asset = [];
foreach ($split['grants'] as $row) { $by_asset[$row['computers_id']] = $row; }
check($by_asset[46]['member_display'] === 'Ops One' && $by_asset[47]['member_display'] === 'Ops Two',
      'the same group UUID on two connections keeps each connection\'s own name');
check(array_column($by_asset[46]['group_members'], 'login') === ['alice']
      && array_column($by_asset[47]['group_members'], 'login') === ['bob'],
      'and each grant carries its own connection\'s members, not the other bastion\'s');

$db = new FakeDB();
$db->canned = [$same_uuid_groups, $same_uuid_members,
    [['jms_user' => 'Alice Example (alice)', 'parsed_login' => 'alice']],
    $same_uuid_grants];
$GLOBALS['DB'] = $db;
$alice_split = $R::forUser(42);
check(count($alice_split['rows']) === 1 && $alice_split['rows'][0]['computers_id'] === 46,
      'and Alice reaches only the asset granted by her own connection');

// --- the per-user view: one row per asset, all paths in the row ------------
$alice_ids = [['jms_user' => 'Alice Example (alice)', 'parsed_login' => 'alice']];
$db = new FakeDB();
$db->canned = [$group_rows, $member_rows, $alice_ids, $grant_rows];
$GLOBALS['DB'] = $db;
$alice = $R::forUser(42);
check($alice['identities']['accounts'] === ['Alice Example (alice)'],
      'the per-user view keeps the mapped account strings');
check(count($alice['rows']) === 2, 'Alice reaches two assets; each is one row (got ' . count($alice['rows']) . ')');
$by_asset = [];
foreach ($alice['rows'] as $row) { $by_asset[$row['computers_id']] = $row; }
check(isset($by_asset[46], $by_asset[47]), 'both assets are present');
check($by_asset[46]['path_count'] === 3 && $by_asset[46]['groups'] === ['Operations'],
      'the asset reached directly and through a group is one row with every path, and the group named once');
$path_types = array_map(static fn (array $p): string => $p['type'], $by_asset[46]['paths']);
sort($path_types);
check($path_types === ['group', 'group', 'user'],
      'and the row keeps the direct path apart from the two group paths - the same group through two rules is two paths');

// A case difference in the login is the same person: GLPI resolves a login
// against glpi_users.name under a case-insensitive collation, and losing that
// match here would silently drop real access.
$upper_ids = [['jms_user' => 'ALICE EXAMPLE (ALICE)', 'parsed_login' => 'ALICE']];
$db = new FakeDB();
$db->canned = [$group_rows, $member_rows, $upper_ids, $grant_rows];
$GLOBALS['DB'] = $db;
$upper = $R::forUser(42);
check(count($upper['rows']) === 2, 'a login that differs only in case still matches its memberships');

// Bob is in the same group and has his own direct rule on 46.
$bob_ids = [['jms_user' => 'Bob Example (bob)', 'parsed_login' => 'bob']];
$db = new FakeDB();
$db->canned = [$group_rows, $member_rows, $bob_ids, $grant_rows];
$GLOBALS['DB'] = $db;
$bob = $R::forUser(43);
$bob_46 = array_values(array_filter($bob['rows'], static fn (array $r): bool => $r['computers_id'] === 46))[0];
check(count($bob['rows']) === 2 && $bob_46['path_count'] === 3,
      'Bob reaches both assets through the group; only the asset with his direct rule has three paths');
check(count(array_filter($bob_46['paths'], static fn (array $p): bool => $p['type'] === 'user')) === 1,
      'and exactly one of those is his own direct grant');

// Carol is in a group no rule uses.
$carol_ids = [['jms_user' => 'Carol Example (carol)', 'parsed_login' => 'carol']];
$db = new FakeDB();
$db->canned = [$group_rows, $member_rows, $carol_ids, $grant_rows];
$GLOBALS['DB'] = $db;
$carol = $R::forUser(44);
check($carol['rows'] === [], 'a person in a group no rule references reaches nothing through it');
check(array_map(static fn (array $g): string => $g['name'], $carol['groups']) === ['Other'],
      'but the group they are in is still disclosed');

// An unmapped user must not be attributed anyone else's access.
$db = new FakeDB();
$db->canned = [[], [], []];
$GLOBALS['DB'] = $db;
$nobody = $R::forUser(99);
check($nobody['identities']['accounts'] === [] && $nobody['rows'] === [],
      'an unmapped user gets no rows - never another person\'s access');

// Alias matching is by LOGIN, not by a similar display name: a display-only
// membership is only used when the person has no mapped login at all.
$db = new FakeDB();
$db->canned = [$group_rows, $member_rows, [['jms_user' => 'Somebody Else (someone)', 'parsed_login' => 'someone']], $grant_rows];
$GLOBALS['DB'] = $db;
$wrong = $R::forUser(45);
check($wrong['rows'] === [], 'a different login is not given Alice\'s or Bob\'s group access');

// --- the page's paging (v4.9.0-rc2) ---------------------------------------
// 4002 grant paths on the live instance. The slice is DISPLAY ONLY, and the
// one way to get this wrong is expensive and silent: paginating the export
// hands someone 100 rows and lets them believe it is the data. These are
// source-level guards, like the CSV-before-HTML check in
// sessionsharing_test.php - the page is procedural and cannot be included.
$page_src = (string) file_get_contents(__DIR__ . '/../front/useraccess.php');

check(preg_match('/foreach \(\$report\[\'grants\'\] as \$grant\) \{/', $page_src) === 1,
      'the CSV still iterates the WHOLE result, not the page slice');
check(preg_match('/foreach \(\$grants_page as \$grant\) \{/', $page_src) === 1,
      'the table iterates the page slice');
check(strpos($page_src, "foreach (\$report['grants'] as \$grant)") < strpos($page_src, 'Html::header('),
      'and the export loop is the one that runs before any HTML');

// A filter applied from page 7 must land on page 1 of the new result, not on
// an empty page 7. The form is a GET, so it does that by simply not carrying
// the page number - which is only true while no hidden input adds one.
check(!preg_match('/name="page"/', $page_src),
      'the filter form carries no page input, so applying a filter returns to page 1');

// The page number comes from the URL and is clamped at both ends: a hand-typed
// ?page=0 or ?page=9999 must render a real page, not an empty table.
check(preg_match('/max\(1, min\(\$pages, \(int\) \(\$_GET\[\'page\'\] \?\? 1\)\)\)/', $page_src) === 1,
      'the requested page is clamped between 1 and the last page');
check(preg_match('/\$pages\s*=\s*max\(1, \(int\) ceil\(/', $page_src) === 1,
      'there is always at least one page, so an empty result still renders its table');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
