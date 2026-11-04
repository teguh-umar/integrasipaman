<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/accessindex_test.php
//
// AccessIndex turns the pulled snapshot into one report row per person and
// one per group. compose() is pure, so most of this runs it on an invented
// fixture; rebuild() is checked against a FakeDB for what it reads, what it
// deletes and what it writes - and for writing nothing without a connection.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
// mbstring is absent from this CLI; GLPI requires it. Character-wise, as mb_substr() is,
// so a multi-byte fixture proves the cut is by character, not by byte.
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null, $e = null) { preg_match_all('/./us', (string) $s, $m); return implode('', array_slice($m[0], $start, $length)); } }

function __($s, $d = null) { return $s; }
class QueryExpression { public function __construct(public string $e) {} }

class FakeDB
{
    public array $seen = [];
    /** FROM table => rows. WHERE equality / IN is applied, so a row of
     *  another connection or a deleted Computer really is filtered out. */
    public array $tables = [];
    public bool $fail_insert = false;
    public function quoteName($n) { return "`$n`"; }
    public function error() { return 'fake'; }
    public function request($q)
    {
        $this->seen[] = $q;
        $from = trim(explode(' AS ', (string) ($q['FROM'] ?? ''))[0]);
        $rows = $this->tables[$from] ?? [];
        $where = (array) ($q['WHERE'] ?? []);
        $out = [];
        foreach ($rows as $row) {
            if (self::matches($row, $where)) {
                $out[] = $row;
            }
        }
        return new ArrayIterator($out);
    }
    private static function matches(array $row, array $where): bool
    {
        foreach ($where as $k => $v) {
            if (is_int($k)) {
                if (is_array($v) && isset($v['NOT'])) {
                    foreach ($v['NOT'] as $nk => $nv) {
                        if (in_array($row[$nk] ?? null, (array) $nv, false)) { return false; }
                    }
                }
                continue;
            }
            if ($k === 'NOT') {
                foreach ($v as $nk => $nv) {
                    if (in_array($row[$nk] ?? null, (array) $nv, false)) { return false; }
                }
                continue;
            }
            if (!in_array($row[$k] ?? null, (array) $v, false)) { return false; }
        }
        return true;
    }
    /** Final review, fix 2c: GLPI 11 throws on a SQL error (DBmysql::doQuery()). */
    public ?string $throw_on_insert = null;
    public function insert($table, $row)
    {
        $this->seen[] = ['INSERT' => $table, 'r' => $row];
        if ($table === $this->throw_on_insert) {
            throw new RuntimeException('MySQL query error: Data too long for column (fake)');
        }
        return !$this->fail_insert;
    }
    public function beginTransaction(): void { $this->seen[] = ['TX' => 'begin']; }
    public function commit(): void { $this->seen[] = ['TX' => 'commit']; }
    public function rollBack(): void { $this->seen[] = ['TX' => 'rollback']; }
    public function delete($table, $where) { $this->seen[] = ['DELETE' => $table, 'w' => $where]; return true; }
    public function updateOrInsert($table, $params, $where) { $this->seen[] = ['UPSERT' => $table, 'p' => $params, 'w' => $where]; return true; }
}

// Page helpers (Task 7): renderTabs() and popupScript() print through core.
class Html
{
    public static function scriptBlock($script)
    {
        return '<script type="text/javascript">' . $script . '</script>';
    }
}
class Plugin
{
    public static function getWebDir($plugin) { return '/plugins/' . $plugin; }
}
if (!function_exists('htmlescape')) {
    function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

require __DIR__ . '/../inc/accessindex.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$X = 'PluginIntegrasipamanAccessIndex';

check($X::USERS_TABLE === 'glpi_plugin_jumpserver_accessusers', 'USERS_TABLE names the per-user report table');
check($X::GROUPS_TABLE === 'glpi_plugin_jumpserver_accessgroups', 'GROUPS_TABLE names the per-group report table');

// --- the invented fixture -------------------------------------------------
$rules = [
    'R1' => ['name' => 'R1-name', 'users' => [['id' => 'U1', 'display' => 'U1-display']], 'groups' => [],
             'accounts' => 'acct-a', 'actions' => 'connect', 'protocols' => 'all', 'is_valid' => 1, 'is_expired' => 0],
    'R2' => ['name' => 'R2-name', 'users' => [], 'groups' => [['id' => 'G1', 'display' => 'G1-display']],
             'accounts' => 'acct-b, acct-a', 'actions' => 'connect, upload', 'protocols' => 'ssh', 'is_valid' => 1, 'is_expired' => 1],
    'R3' => ['name' => 'R3-name', 'users' => [['id' => 'U3', 'display' => 'U3-display']], 'groups' => [],
             'accounts' => 'acct-c', 'actions' => 'connect', 'protocols' => '', 'is_valid' => 0, 'is_expired' => 0],
    // A rule that reaches no host: it must make no row.
    'R4' => ['name' => 'R4-name', 'users' => [['id' => 'U4', 'display' => 'U4-display']], 'groups' => [],
             'accounts' => 'acct-d', 'actions' => 'connect', 'protocols' => 'all', 'is_valid' => 1, 'is_expired' => 0],
];
$rule_assets = [
    ['permrules_jms_id' => 'R1', 'jms_asset_id' => 'A1'],
    ['permrules_jms_id' => 'R2', 'jms_asset_id' => 'A1'],
    ['permrules_jms_id' => 'R2', 'jms_asset_id' => 'A2'],
    ['permrules_jms_id' => 'R3', 'jms_asset_id' => 'A3'],
];
$hosts = [
    'A1' => ['name' => 'A1-name', 'protocols' => 'ssh, sftp'],
    'A2' => ['name' => 'A2-name', 'protocols' => 'rdp'],
    'A3' => ['name' => 'A3-name', 'protocols' => 'ssh'],
];
$links  = ['A3' => 77];
$groups = [
    'G1' => ['name' => 'G1-name', 'comment' => 'G1-comment', 'users_amount' => 2],
    'G9' => ['name' => 'G9-name', 'comment' => '', 'users_amount' => 1],
];
$members = [
    ['user_groups_jms_id' => 'G1', 'user_jms_id' => 'U1', 'user_login' => 'u1-login', 'user_display' => 'U1-display'],
    ['user_groups_jms_id' => 'G1', 'user_jms_id' => 'U2', 'user_login' => 'u2-login', 'user_display' => 'U2-display'],
    ['user_groups_jms_id' => 'G9', 'user_jms_id' => 'U1', 'user_login' => 'u1-login', 'user_display' => 'U1-display'],
];
$users = [
    'U1' => ['username' => 'u1-user', 'name' => 'U1-name', 'system_role' => 'Role-A'],
    'U2' => ['username' => 'u2-user', 'name' => 'U2-name', 'system_role' => ''],
    // U3 is deliberately absent: identity falls back to the relation.
];

$out = $X::compose($rules, $rule_assets, $hosts, $links, $groups, $members, $users);

$byUser = [];
foreach ($out['users'] as $row) { $byUser[$row['user_jms_id']] = $row; }
$ids = array_keys($byUser); sort($ids);
check($ids === ['U1', 'U2', 'U3'], 'three user rows: U1, U2, U3; a rule with no host and a group without rules add none (got ' . implode(',', $ids) . ')');

$detail = static function (array $row): array {
    $d = json_decode((string) $row['assets_detail'], true);
    $by = [];
    foreach ((array) $d as $a) { $by[$a['id']] = $a; }
    return $by;
};

$u1 = $byUser['U1'] ?? [];
$d1 = $detail($u1);
check(($u1['asset_names'] ?? null) === 'A1-name, A2-name', "U1 asset_names === 'A1-name, A2-name' (got " . var_export($u1['asset_names'] ?? null, true) . ')');
check(($u1['assets_count'] ?? null) === 2, 'U1 assets_count is 2');
check(($d1['A1']['live'] ?? null) === true, 'U1: A1 is live (one live rule is enough)');
$a1r = $d1['A1']['rules'] ?? [];
check(count($a1r) === 2, 'U1: A1 has two rule entries');
check(($a1r[0]['name'] ?? '') === 'R1-name' && ($a1r[0]['via'] ?? null) === '' && ($a1r[0]['state'] ?? '') === 'live'
      && ($a1r[0]['protocols'] ?? null) === ['ssh', 'sftp'],
      "U1/A1: R1 direct, live, protocols ['ssh','sftp'] (got " . json_encode($a1r[0] ?? null) . ')');
check(($a1r[1]['name'] ?? '') === 'R2-name' && ($a1r[1]['via'] ?? null) === 'G1-name' && ($a1r[1]['state'] ?? '') === 'expired'
      && ($a1r[1]['protocols'] ?? null) === ['ssh'],
      "U1/A1: R2 via 'G1-name', expired, protocols ['ssh'] (got " . json_encode($a1r[1] ?? null) . ')');
check(($a1r[1]['actions'] ?? null) === 'connect, upload' && ($a1r[1]['accounts'] ?? null) === 'acct-b, acct-a',
      'the entry carries the rule actions and accounts as stored');
check(($d1['A2']['live'] ?? null) === false, 'U1: A2 is not live (only R2, which is expired)');
$a2r = $d1['A2']['rules'] ?? [];
check(count($a2r) === 1 && ($a2r[0]['protocols'] ?? null) === [],
      'U1/A2: R2 effective protocols are [] - ssh and rdp do not intersect (got ' . json_encode($a2r) . ')');
check(($d1['A1']['computers_id'] ?? null) === 0, 'an asset with no link carries computers_id 0');
$order = array_map(static fn($a) => $a['id'], (array) json_decode((string) $u1['assets_detail'], true));
check($order === ['A1', 'A2'], 'assets_detail is ordered by asset name');
check(($u1['account_names'] ?? null) === 'acct-a, acct-b', "U1 account_names: split, unique, sorted (got " . var_export($u1['account_names'] ?? null, true) . ')');
check(($u1['group_names'] ?? null) === 'G1-name, G9-name', "U1 group_names lists every group, G9 included (got " . var_export($u1['group_names'] ?? null, true) . ')');
check(($u1['name'] ?? null) === 'U1-name' && ($u1['username'] ?? null) === 'u1-user' && ($u1['system_role'] ?? null) === 'Role-A',
      'U1 identity comes from $users');

// Review Focus #3: a dead-rule asset is still listed.
$u3 = $byUser['U3'] ?? [];
$d3 = $detail($u3);
check(isset($d3['A3']), 'U3 is a row although its only rule is inactive');
check(($d3['A3']['live'] ?? null) === false, 'U3: A3 is not live');
check(($d3['A3']['rules'][0]['state'] ?? null) === 'inactive', 'U3: the rule state is inactive');
check(($d3['A3']['computers_id'] ?? null) === 77, 'U3: A3 carries computers_id 77');
check(($d3['A3']['rules'][0]['protocols'] ?? null) === ['ssh'], 'an empty rule protocol means the asset protocols');
check(($u3['name'] ?? null) === 'U3-display' && ($u3['system_role'] ?? null) === '',
      "a user absent from \$users: name is the relation display, system_role '' (got " . var_export($u3['name'] ?? null, true) . ')');

$u2 = $byUser['U2'] ?? [];
check(($u2['group_names'] ?? null) === 'G1-name', "U2 group_names === 'G1-name'");
check(($u2['asset_names'] ?? null) === 'A1-name, A2-name', 'U2 reaches A1 and A2 through G1');

$byGroup = [];
foreach ($out['groups'] as $row) { $byGroup[$row['jms_id']] = $row; }
check(count($out['groups']) === 2, 'one row per group');
check(($byGroup['G1']['rules_count'] ?? null) === 1 && ($byGroup['G1']['assets_count'] ?? null) === 2,
      'G1: rules_count 1, assets_count 2');
check(($byGroup['G9']['rules_count'] ?? null) === 0 && ($byGroup['G9']['assets_count'] ?? null) === 0,
      'G9: rules_count 0, assets_count 0');
check(($byGroup['G1']['name'] ?? null) === 'G1-name' && ($byGroup['G1']['comment'] ?? null) === 'G1-comment'
      && ($byGroup['G1']['users_amount'] ?? null) === 2, 'G1 name, comment and users_amount are carried');

check($X::compose([], [], [], [], [], [], []) === ['users' => [], 'groups' => []], 'compose() of nothing is nothing');

// The same rule reaching one user directly AND through a group is two entries.
$both = $X::compose(
    ['R5' => ['name' => 'R5-name', 'users' => [['id' => 'U1', 'display' => '']], 'groups' => [['id' => 'G1', 'display' => '']],
              'accounts' => '', 'actions' => '', 'protocols' => 'all', 'is_valid' => 1, 'is_expired' => 0]],
    [['permrules_jms_id' => 'R5', 'jms_asset_id' => 'A9']], [], [], $groups, $members, $users
);
$b1 = [];
foreach ($both['users'] as $r) { if ($r['user_jms_id'] === 'U1') { $b1 = $r; } }
$bd = $detail($b1);
$vias = array_map(static fn($e) => $e['via'], $bd['A9']['rules'] ?? []);
sort($vias);
check($vias === ['', 'G1-name'], 'one rule, two paths: a direct entry and a via-G1 entry (got ' . json_encode($vias) . ')');
check(($bd['A9']['name'] ?? null) === 'A9', 'an asset absent from $hosts is named by its UUID');
check(($bd['A9']['rules'][0]['protocols'] ?? null) === [], "'all' on an unknown host means no protocols, not ['all']");

// --- rebuild() ------------------------------------------------------------
$fixture = static function (): array {
    $c = 'plugin_jumpserver_connections_id';
    return [
        'glpi_plugin_jumpserver_permrules' => [
            ['jms_id' => 'R1', 'name' => 'R1-name', 'users_json' => json_encode([['id' => 'U1', 'display' => 'U1-display']]),
             'user_groups_json' => '[]', 'accounts' => 'acct-a', 'actions' => 'connect', 'protocols' => 'all',
             'is_valid' => 1, 'is_expired' => 0, $c => 4],
            ['jms_id' => 'RX', 'name' => 'other-connection', 'users_json' => json_encode([['id' => 'UX', 'display' => 'UX']]),
             'user_groups_json' => '[]', 'accounts' => '', 'actions' => '', 'protocols' => 'all',
             'is_valid' => 1, 'is_expired' => 0, $c => 9],
        ],
        'glpi_plugin_jumpserver_ruleassets' => [
            ['permrules_jms_id' => 'R1', 'jms_asset_id' => 'A1', $c => 4],
            ['permrules_jms_id' => 'R1', 'jms_asset_id' => 'A2', $c => 4],
            ['permrules_jms_id' => 'RX', 'jms_asset_id' => 'AX', $c => 9],
        ],
        'glpi_plugin_jumpserver_hosts' => [
            ['jms_id' => 'A1', 'name' => 'A1-Name', 'address' => '192.0.2.1', 'protocols' => 'ssh', $c => 4],
            ['jms_id' => 'A2', 'name' => 'A2-name', 'address' => '192.0.2.2', 'protocols' => 'rdp', $c => 4],
        ],
        'glpi_plugin_jumpserver_assetmap' => [
            ['computers_id' => 77, 'parsed_name' => 'a1-name', 'parsed_ip' => '', $c => 4],   // name match, case-folded
            ['computers_id' => 88, 'parsed_name' => '', 'parsed_ip' => '192.0.2.2', $c => 4], // a deleted Computer
        ],
        'glpi_computers' => [
            ['id' => 77, 'is_deleted' => 0],
            ['id' => 88, 'is_deleted' => 1],
        ],
        'glpi_plugin_jumpserver_usergroups' => [
            ['jms_id' => '', 'name' => '', 'comment' => '', 'users_amount' => 0, $c => 4],  // the pull marker
            ['jms_id' => 'G1', 'name' => 'G1-name', 'comment' => '', 'users_amount' => 0, $c => 4],
        ],
        'glpi_plugin_jumpserver_groupmembers' => [],
        'glpi_plugin_jumpserver_jmsusers' => [
            ['jms_id' => 'U1', 'username' => 'u1-user', 'name' => 'U1-name', 'system_role' => 'Role-A', $c => 4],
        ],
    ];
};

PluginIntegrasipamanConnection::setCurrent([
    'id' => 4, 'entities_id' => 6, 'is_recursive' => 1,
    'url' => 'https://x', 'access_key_id' => 'k', 'access_key_secret' => 's', 'org_id' => 'o',
]);
$_SESSION['glpi_currenttime'] = '2026-11-02 08:00:00';

$db = new FakeDB(); $db->tables = $fixture(); $GLOBALS['DB'] = $db;
$r = $X::rebuild();
check($r === ['users' => 1, 'groups' => 1], 'rebuild() reports one user and one group (got ' . json_encode($r) . ')');

$deletes = array_values(array_filter($db->seen, static fn($q) => isset($q['DELETE'])));
$inserts = array_values(array_filter($db->seen, static fn($q) => isset($q['INSERT'])));
$delTables = array_map(static fn($q) => $q['DELETE'], $deletes);
sort($delTables);
check($delTables === ['glpi_plugin_jumpserver_accessgroups', 'glpi_plugin_jumpserver_accessusers'], 'both report tables are cleared');
$scoped = true;
foreach ($deletes as $q) { if ($q['w'] !== ['plugin_jumpserver_connections_id' => 4]) { $scoped = false; } }
check($scoped, 'each DELETE is exactly ownRows() - this connection only, never the whole table');

$reads = array_values(array_filter($db->seen, static fn($q) => isset($q['FROM'])));
$unscoped = [];
foreach ($reads as $q) {
    if ($q['FROM'] === 'glpi_computers') { continue; }
    if (($q['WHERE']['plugin_jumpserver_connections_id'] ?? null) !== 4) { $unscoped[] = $q['FROM']; }
}
check($unscoped === [], 'every snapshot read is limited to ownRows() (unscoped: ' . implode(',', $unscoped) . ')');

$uins = array_values(array_filter($inserts, static fn($q) => $q['INSERT'] === 'glpi_plugin_jumpserver_accessusers'));
$gins = array_values(array_filter($inserts, static fn($q) => $q['INSERT'] === 'glpi_plugin_jumpserver_accessgroups'));
check(count($uins) === 1 && ($uins[0]['r']['user_jms_id'] ?? '') === 'U1', 'one user row inserted, U1; the other connection\'s user is not');
check(count($gins) === 1 && ($gins[0]['r']['jms_id'] ?? null) === 'G1', 'one group row inserted, G1; the pull marker is not a group');
$ur = $uins[0]['r'] ?? [];
check(($ur['plugin_jumpserver_connections_id'] ?? null) === 4 && ($ur['entities_id'] ?? null) === 6,
      'the inserted row is stamped with its connection and entity');
check(($ur['date_built'] ?? null) === '2026-11-02 08:00:00', 'and carries date_built');
check(($gins[0]['r']['date_built'] ?? null) === '2026-11-02 08:00:00', 'the group row carries date_built too');
check(($ur['name'] ?? null) === 'U1-name' && ($ur['system_role'] ?? null) === 'Role-A', 'identity read from jmsusers');
check(($ur['asset_names'] ?? null) === 'A1-Name, A2-name', 'asset names read from hosts (got ' . var_export($ur['asset_names'] ?? null, true) . ')');
$rd = $detail($ur);
check(($rd['A1']['computers_id'] ?? null) === 77, 'A1 is linked to Computer 77 by its lower-cased name');
check(($rd['A2']['computers_id'] ?? null) === 0, 'A2 is NOT linked: its Computer is deleted');
check(($rd['A1']['rules'][0]['protocols'] ?? null) === ['ssh'], 'host protocols read from the hosts table');

// Per table, the delete comes before the inserts.
$ordered = true;
foreach (['glpi_plugin_jumpserver_accessusers', 'glpi_plugin_jumpserver_accessgroups'] as $t) {
    $firstIns = null; $del = null;
    foreach ($db->seen as $i => $q) {
        if (($q['INSERT'] ?? null) === $t && $firstIns === null) { $firstIns = $i; }
        if (($q['DELETE'] ?? null) === $t) { $del = $i; }
    }
    if ($del === null || $firstIns === null || $del > $firstIns) { $ordered = false; }
}
check($ordered, 'in each table the old rows are deleted before the new ones are inserted');

// --- final review, fix 2c: one transaction, rolled back on failure -------------
$tx = array_values(array_filter($db->seen, static fn($q) => isset($q['TX']) || isset($q['DELETE']) || isset($q['INSERT'])));
$kinds = array_map(static fn($q) => $q['TX'] ?? (isset($q['DELETE']) ? 'DELETE' : 'INSERT'), $tx);
check(($kinds[0] ?? null) === 'begin' && end($kinds) === 'commit' && !in_array('rollback', $kinds, true)
      && count(array_keys($kinds, 'begin', true)) === 1 && count(array_keys($kinds, 'commit', true)) === 1,
      'every DELETE and INSERT runs between one beginTransaction() and one commit() (' . implode(',', $kinds) . ')');

$db = new FakeDB(); $db->tables = $fixture(); $GLOBALS['DB'] = $db;
$db->throw_on_insert = 'glpi_plugin_jumpserver_accessgroups';
$thrown = null;
try {
    $X::rebuild();
} catch (Throwable $e) {
    $thrown = $e;
}
check($thrown instanceof RuntimeException && str_contains($thrown->getMessage(), 'Data too long'),
      'an INSERT that throws propagates out of rebuild() (got ' . ($thrown === null ? 'nothing' : get_class($thrown)) . ')');
$kinds = array_map(static fn($q) => $q['TX'] ?? (isset($q['DELETE']) ? 'DELETE' : 'INSERT'),
    array_values(array_filter($db->seen, static fn($q) => isset($q['TX']) || isset($q['DELETE']) || isset($q['INSERT']))));
check(in_array('rollback', $kinds, true) && !in_array('commit', $kinds, true) && end($kinds) === 'rollback',
      'and the transaction is rolled back, never committed: zero DELETE committed (' . implode(',', $kinds) . ')');
check(array_search('begin', $kinds, true) === 0, 'the transaction opened before the first DELETE');

// No current connection: an unscoped DELETE would wipe every connection's report.
PluginIntegrasipamanConnection::setCurrent(null);
$db = new FakeDB(); $db->tables = $fixture(); $GLOBALS['DB'] = $db;
$r = $X::rebuild();
check($r === ['users' => 0, 'groups' => 0], 'without a connection rebuild() returns zeros');
$writes = array_filter($db->seen, static fn($q) => isset($q['DELETE']) || isset($q['INSERT']) || isset($q['UPSERT']));
check($writes === [], 'and writes nothing at all (saw ' . count($writes) . ' write(s))');

// --- the pages (Task 7) ---------------------------------------------------
// tabFor(): an old link or a CSV request still lands on the asset table.
check($X::tabFor([]) === 'user', 'tabFor([]) opens the User tab');
check($X::tabFor(['tab' => 'group']) === 'group', "tabFor(tab=group) is 'group'");
check($X::tabFor(['tab' => 'asset']) === 'asset', "tabFor(tab=asset) is 'asset'");
check($X::tabFor(['computer' => '46']) === 'asset', 'an old ?computer= link stays on the asset table');
check($X::tabFor(['user' => 'someone']) === 'asset', 'an old ?user= link stays on the asset table');
check($X::tabFor(['group' => 'ops']) === 'asset', 'an old ?group= link stays on the asset table');
check($X::tabFor(['format' => 'csv']) === 'asset', 'a CSV request is the asset table, never a redirect');
check($X::tabFor(['tab' => 'group', 'format' => 'csv']) === 'asset', 'format wins over tab=group');
check($X::tabFor(['tab' => 'x']) === 'user', 'an unknown tab falls back to User');
check($X::tabFor(['tab' => ['asset']]) === 'user', 'a non-string tab is not trusted');

// renderTabs(): three links, the active one marked.
ob_start();
$X::renderTabs('group');
$tabs = (string) ob_get_clean();
check(str_contains($tabs, 'nav nav-tabs'), 'renderTabs() prints a nav-tabs list');
check(str_contains($tabs, 'href="/plugins/integrasipaman/front/accessuser.php"'), 'the User tab links to front/accessuser.php');
check(str_contains($tabs, 'href="/plugins/integrasipaman/front/accessgroup.php"'), 'the Group tab links to front/accessgroup.php');
check(str_contains($tabs, 'href="/plugins/integrasipaman/front/useraccess.php?tab=asset"'), 'the Asset tab links to useraccess.php?tab=asset');
check(substr_count($tabs, 'nav-link active') === 1
      && preg_match('/nav-link active"[^>]*href="[^"]*accessgroup\.php"/', $tabs) === 1,
      'exactly one tab is active, and it is the one asked for');

// renderMembershipBanner(): the asset table's own collected / stale sentences.
$banner = static function (array $m) use ($X): string { ob_start(); $X::renderMembershipBanner($m); return (string) ob_get_clean(); };
$ua_src = (string) file_get_contents(__DIR__ . '/../front/useraccess.php');
$never  = $banner(['collected' => false, 'at' => null, 'stale' => true]);
check(str_contains($never, 'Group membership has never been collected') && str_contains($never, 'alert-warning'),
      'never collected -> the never-collected warning');
check(str_contains($ua_src, "'Group membership has never been collected, so this report cannot count access granted through a group."),
      'the same sentence the asset table uses');
$stale_b = $banner(['collected' => true, 'at' => '2020-01-01 00:00:00', 'stale' => true]);
check(str_contains($stale_b, 'Group membership was last collected at 2020-01-01 00:00:00.'), 'stale -> the stale warning, with the date');
check(str_contains($ua_src, "'Group membership was last collected at %s. It is used as-is;"), 'the same stale sentence the asset table uses');
check($banner(['collected' => true, 'at' => '2026-11-01 00:00:00', 'stale' => false]) === '', 'a fresh collection prints nothing');

// popupScript(): ONE delegated script, so Search's AJAX reloads keep it.
$js = $X::popupScript();
check(substr_count($js, '<script') === 1, 'popupScript() is exactly one script block (got ' . substr_count($js, '<script') . ')');
check(str_contains($js, 'integrasipaman-chip') && str_contains($js, 'tooltip-invisible'),
      'it pops the chip\'s own tooltip-invisible content');
check(str_contains($js, 'integrasipaman-members') && str_contains($js, 'data-members-url'),
      'and loads the member list from the count\'s data-members-url');
check(str_contains($js, '$(document).on('), 'it is delegated on document');
check(str_contains($js, "'qtip-shadow qtip-bootstrap'") && str_contains($js, 'fixed: true') && str_contains($js, 'delay: 200')
      && str_contains($js, 'viewport: $(window)'),
      'with the options of Html::showToolTip()');
check(str_contains($js, "show: 'click'"), 'the member list opens on click');

// groupmembers.php: the entity check is in the code (Review Focus #4, static side).
$gm = (string) @file_get_contents(__DIR__ . '/../front/groupmembers.php');
check(str_contains($gm, 'haveAccessToEntity'), 'front/groupmembers.php checks the row\'s entity');
check(!str_contains($gm, 'Html::header('), 'and renders no GLPI chrome: it is a pop-up fragment');

// --- final review, fix 2a: report values fit their columns -----------------------
$long = $X::compose(
    ['RL' => ['name' => 'RL', 'users' => [['id' => 'UL', 'display' => str_repeat('ñ', 300)]], 'groups' => [],
              'accounts' => '', 'actions' => '', 'protocols' => 'all', 'is_valid' => 1, 'is_expired' => 0]],
    [['permrules_jms_id' => 'RL', 'jms_asset_id' => 'AL']], [], [],
    ['GL' => ['name' => str_repeat('g', 300), 'comment' => str_repeat('é', 300), 'users_amount' => 0]],
    [],
    ['UL' => ['username' => str_repeat('u', 300), 'name' => '', 'system_role' => str_repeat('r', 300)]]
);
$lu = $long['users'][0] ?? [];
$lg = $long['groups'][0] ?? [];
check(($lg['comment'] ?? null) === str_repeat('é', 255), 'accessgroups.comment: a 300-character comment is stored at 255 characters');
check(($lg['name'] ?? null) === str_repeat('g', 191), 'accessgroups.name at 191');
check(($lu['name'] ?? null) === str_repeat('ñ', 191) && ($lu['username'] ?? null) === str_repeat('u', 191)
      && ($lu['system_role'] ?? null) === str_repeat('r', 191),
      'accessusers name, username and system_role at 191 characters');

// --- final review, fix 4: "not built yet" until the viewer can see a report row ---
PluginIntegrasipamanConnection::setCurrent([
    'id' => 4, 'entities_id' => 6, 'is_recursive' => 1,
    'url' => 'https://bastion-test.example.org', 'access_key_id' => 'k', 'access_key_secret' => 's', 'org_id' => 'o',
]);
PluginIntegrasipamanServerLabel::reset();
$_SESSION['glpiactiveentities'] = [6];
$notice = static function (string $table) use ($X): string { ob_start(); $X::renderNotBuiltNotice($table); return (string) ob_get_clean(); };

$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = [$X::USERS_TABLE => [], $X::GROUPS_TABLE => []];
check($X::reportBuilt($X::USERS_TABLE) === false, 'an empty report table is not built');
$q = $db->seen[0] ?? [];
check(($q['FROM'] ?? null) === $X::USERS_TABLE && ($q['WHERE'] ?? null) === PluginIntegrasipamanConnection::viewer(),
      'reportBuilt() reads the table through viewer(), the entity restriction of the page (' . json_encode($q) . ')');
$out = $notice($X::USERS_TABLE);
check(str_contains($out, 'This report has not been built yet. It is built by the entitlement pull from BASTION-TEST, and this page fills after the next run.')
      && str_contains($out, 'alert'), 'the notice names the server and says the page fills after the next run (' . $out . ')');
check(!str_contains($out, '{server}'), 'the {server} token is replaced');

// Rows exist, but only in an entity the viewer is not working in.
$db->tables[$X::GROUPS_TABLE] = [['id' => 1, 'entities_id' => 9]];
check($X::reportBuilt($X::GROUPS_TABLE) === false, 'rows of another entity do not count: the viewer still sees nothing');
check($notice($X::GROUPS_TABLE) !== '', 'so the Group tab still shows the notice');
$db->tables[$X::GROUPS_TABLE][] = ['id' => 2, 'entities_id' => 6];
check($X::reportBuilt($X::GROUPS_TABLE) === true, 'one row in the viewer\'s entity: built');
check($notice($X::GROUPS_TABLE) === '', 'and the notice is gone');

// Both pages print it for their own table, after the membership banner.
foreach (['accessuser' => 'USERS_TABLE', 'accessgroup' => 'GROUPS_TABLE'] as $page => $const) {
    $pg = (string) file_get_contents(__DIR__ . "/../front/$page.php");
    check(str_contains($pg, "PluginIntegrasipamanAccessIndex::renderNotBuiltNotice(PluginIntegrasipamanAccessIndex::$const);")
          && strpos($pg, 'renderMembershipBanner(') < strpos($pg, 'renderNotBuiltNotice('),
          "front/$page.php prints the notice for $const");
}

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
