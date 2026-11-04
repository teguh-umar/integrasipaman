<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/jmsusers_pull_test.php
//
// pullUsers() stores every JumpServer user with their system role. It runs
// against stubs: an incomplete or malformed answer must never delete the
// previous snapshot; a complete empty answer is a real answer and does.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
// mbstring is absent from this CLI; GLPI requires it. Character-wise, as mb_substr() is,
// so a multi-byte fixture proves the cut is by character, not by byte.
if (!function_exists('mb_substr')) { function mb_substr($s, $start, $length = null, $e = null) { preg_match_all('/./us', (string) $s, $m); return implode('', array_slice($m[0], $start, $length)); } }

function __($s, $d = null) { return $s; }

class QueryExpression { public function __construct(public string $e) {} }

$GLOBALS['API_PAGES'] = [];
$GLOBALS['API_FAIL']  = false;
$GLOBALS['API_ASKED'] = [];

class PluginIntegrasipamanJmsApi
{
    public static function signedGet($path, $base, $kid, $secret, $org)
    {
        return [];
    }

    public static function signedGetEach($path, $base, $kid, $secret, $org, $on_page, $page_size = 100, $max_pages = 100)
    {
        $GLOBALS['API_ASKED'][] = $path;
        $GLOBALS['API_PAGE_SIZE'] = $page_size;
        if ($GLOBALS['API_FAIL']) {
            return false;
        }
        foreach ($GLOBALS['API_PAGES'] as $page) {
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

$E = 'PluginIntegrasipamanEntitlement';
$T = 'glpi_plugin_jumpserver_jmsusers';
check($E::JMSUSERS_TABLE === $T, 'the user table constant is JMSUSERS_TABLE');

function run(array $pages, bool $fail = false): array
{
    global $E;
    $GLOBALS['API_PAGES'] = $pages;
    $GLOBALS['API_FAIL']  = $fail;
    $GLOBALS['API_ASKED'] = [];
    $db = new FakeDB(); $GLOBALS['DB'] = $db;
    $n = $E::pullUsers(['https://x', 'k', 's', 'o'], new CronTask());
    $del = array_values(array_filter($db->seen, static fn($q) => isset($q['DELETE'])));
    $ins = array_values(array_filter($db->seen, static fn($q) => isset($q['INSERT'])));
    return [$n, $del, $ins];
}

// --- two pages ---------------------------------------------------------------
[$n, $del, $ins] = run([
    [['id' => 'U1', 'username' => 'alice', 'name' => 'Alice Example', 'system_roles' => [['id' => 'r1', 'display_name' => 'System Admin']]]],
    [['id' => 'U2', 'username' => 'bob', 'name' => 'Bob Example', 'system_roles' => [['id' => 'r2', 'display_name' => 'System Operator']]]],
]);
check($n === 2, 'returns the number of users stored (got ' . var_export($n, true) . ')');
check(count($del) === 1 && $del[0]['DELETE'] === $T && $del[0]['w'] === PluginIntegrasipamanConnection::ownRows(),
      'one DELETE, on the user table, scoped by ownRows()');
check(count($ins) === 2, 'two INSERTs');
$roles = array_map(static fn($q) => $q['r']['system_role'] ?? null, $ins);
check($roles === ['System Admin', 'System Operator'], 'system_role is the display name of each role');
check(($ins[0]['r']['jms_id'] ?? '') === 'U1' && ($ins[0]['r']['username'] ?? '') === 'alice' && ($ins[0]['r']['name'] ?? '') === 'Alice Example',
      'jms_id, username and name are stored');
check((int) ($ins[0]['r']['plugin_jumpserver_connections_id'] ?? 0) === 4 && (int) ($ins[0]['r']['entities_id'] ?? 0) === 6,
      'rows are stamped with connection and entity');
check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($ins[0]['r']['date_pulled'] ?? '')) === 1, 'date_pulled is a SQL time');
check(($GLOBALS['API_PAGE_SIZE'] ?? null) === $E::MEMBER_PAGE_SIZE && ($GLOBALS['API_ASKED'][0] ?? '') === '/api/v1/users/users/',
      'reads the users endpoint at MEMBER_PAGE_SIZE');

// --- several roles joined, none kept as empty --------------------------------
[$n, $del, $ins] = run([[
    ['id' => 'U3', 'username' => 'carol', 'name' => 'Carol Example', 'system_roles' => [['id' => 'r1', 'display_name' => 'System Admin'], ['id' => 'r2', 'display_name' => 'System Auditor']]],
    ['id' => 'U4', 'username' => 'dave', 'name' => 'Dave Example', 'system_roles' => []],
]]);
check($n === 2 && count($ins) === 2, 'a user with no roles is kept');
check(($ins[0]['r']['system_role'] ?? null) === 'System Admin, System Auditor', 'several roles are joined with ", "');
check(($ins[1]['r']['system_role'] ?? null) === '', 'no roles gives system_role === ""');

// --- failed page -------------------------------------------------------------
[$n, $del, $ins] = run([[['id' => 'U1', 'username' => 'alice']]], true);
check($n === -1 && count($del) === 0 && count($ins) === 0, 'a failed page returns -1 with no DELETE and no INSERT');

// --- malformed ---------------------------------------------------------------
[$n, $del, $ins] = run([[['id' => 'U1', 'username' => 'alice'], 'junk']]);
check($n === -1 && count($del) === 0 && count($ins) === 0, 'a non-array entry returns -1 with no DELETE');
[$n, $del, $ins] = run([[['id' => 'U1', 'username' => 'alice'], ['username' => 'noid']]]);
check($n === -1 && count($del) === 0 && count($ins) === 0, 'an entry without id returns -1 with no DELETE');

// --- complete empty answer ---------------------------------------------------
[$n, $del, $ins] = run([]);
check($n === 0 && count($del) === 1 && count($ins) === 0, 'a complete empty answer returns 0 and still DELETEs');

// --- final review, fix 2a: values fit their VARCHAR(191) columns ---------------
[$n, $del, $ins] = run([[
    ['id' => 'U9', 'username' => str_repeat('ü', 300), 'name' => str_repeat('ñ', 300),
     'system_roles' => [['display_name' => str_repeat('r', 150)], ['display_name' => str_repeat('s', 150)]]],
]]);
$r9 = $ins[0]['r'] ?? [];
check($n === 1 && ($r9['username'] ?? null) === str_repeat('ü', 191) && ($r9['name'] ?? null) === str_repeat('ñ', 191),
      'a 300-character username and name are stored at 191 characters');
check(preg_match_all('/./us', (string) ($r9['system_role'] ?? '')) === 191,
      'and the joined system_role at 191 too (got ' . preg_match_all('/./us', (string) ($r9['system_role'] ?? '')) . ')');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
