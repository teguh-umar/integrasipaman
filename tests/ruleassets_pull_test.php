<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/ruleassets_pull_test.php
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

function __($s, $d = null) { return $s; }
class QueryExpression { public function __construct(public string $e) {} }

$GLOBALS['API_BY_PATH'] = [];
$GLOBALS['API_ASKED']   = [];

class PluginIntegrasipamanJmsApi
{
    public static function signedGet($path, $base, $kid, $secret, $org)
    {
        $GLOBALS['API_ASKED'][] = $path;
        return array_key_exists($path, $GLOBALS["API_BY_PATH"]) ? $GLOBALS["API_BY_PATH"][$path] : [];
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
$T = 'glpi_plugin_jumpserver_ruleassets';
$P = '/api/v1/perms/asset-permissions/?limit=200&asset_id=';
$creds = ['https://x', 'k', 's', 'o'];

// --- mixed run: duplicates, a failed call, an honest empty answer ----------
$GLOBALS['API_BY_PATH'] = [
    $P . 'a1-uuid' => [['id' => 'R1'], ['id' => 'R2'], ['id' => 'R1']],
    $P . 'a2-uuid' => null,
    $P . 'a3-uuid' => [],
];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$r = $E::pullRuleAssets($creds, ['a1-uuid', 'a2-uuid', 'a3-uuid']);

check($E::RULEASSETS_TABLE === $T, 'table constant names the ruleassets table');
check($r === ['refreshed' => 2, 'failed' => 1, 'pruned' => true], 'result counts: ' . json_encode($r));
check($GLOBALS['API_ASKED'] === [$P . 'a1-uuid', $P . 'a2-uuid', $P . 'a3-uuid'], 'one call per host on the refreshComputer path');

$del = fn(string $a) => array_values(array_filter($db->seen, fn($q) => ($q['DELETE'] ?? '') === $T && ($q['w']['jms_asset_id'] ?? null) === $a));
$ins = fn(string $a) => array_values(array_filter($db->seen, fn($q) => ($q['INSERT'] ?? '') === $T && ($q['r']['jms_asset_id'] ?? null) === $a));

check(count($del('a1-uuid')) === 1 && (int) $del('a1-uuid')[0]['w']['plugin_jumpserver_connections_id'] === 4, 'A1 old rows deleted, scoped to this connection');
$i1 = $ins('a1-uuid');
check(count($i1) === 2, 'A1 duplicate rule collapsed to two inserts');
check(array_column(array_column($i1, 'r'), 'permrules_jms_id') === ['R1', 'R2'], 'A1 inserts are R1 and R2');
check(($i1[0]['r']['date_refreshed'] ?? '') !== '' && (int) ($i1[0]['r']['entities_id'] ?? -1) === 6
      && (int) ($i1[0]['r']['plugin_jumpserver_connections_id'] ?? 0) === 4, 'rows carry date_refreshed and the stamp');
check(count($del('a2-uuid')) === 0 && count($ins('a2-uuid')) === 0, 'failed call: A2 old rows kept (no delete, no insert)');
check(count($del('a3-uuid')) === 1 && count($ins('a3-uuid')) === 0, '[] is an answer: A3 deleted, nothing inserted');

$prunes = array_values(array_filter($db->seen, fn($q) => ($q['DELETE'] ?? '') === $T && isset($q['w']['NOT'])));
check(count($prunes) === 1 && $prunes[0] === end($db->seen), 'exactly one prune, and it runs last');
check(($prunes[0]['w']['NOT']['jms_asset_id'] ?? null) === ['a1-uuid', 'a2-uuid', 'a3-uuid']
      && (int) $prunes[0]['w']['plugin_jumpserver_connections_id'] === 4, 'prune is NOT IN the host list, own connection only');

// --- Review Focus 2: empty host list touches nothing -----------------------
$GLOBALS['API_ASKED'] = [];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$r = $E::pullRuleAssets($creds, []);
check($r === ['refreshed' => 0, 'failed' => 0, 'pruned' => false], 'empty list result');
check($GLOBALS['API_ASKED'] === [], 'empty list: zero API calls');
check($db->seen === [], 'empty list: zero DB calls, the table is never wiped');

// --- final review, fix 1: an incomplete host list prunes nothing ------------
// hostIndex() says complete=false when a later page of the host list failed.
// The hosts it did list are refreshed; the NOT-IN prune would delete every
// host on the missing page, so it is skipped and the run says so.
$GLOBALS['API_BY_PATH'] = [
    $P . 'a1-uuid' => [['id' => 'R1']],
    $P . 'a2-uuid' => [],
];
$GLOBALS['API_ASKED'] = [];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$task = new CronTask();
$r = $E::pullRuleAssets($creds, ['a1-uuid', 'a2-uuid'], $task, false);
$seen = $db->seen;
$pdel = static fn(string $a) => array_values(array_filter($seen, static fn($q) => ($q['DELETE'] ?? '') === $T && ($q['w']['jms_asset_id'] ?? null) === $a));
$pins = static fn(string $a) => array_values(array_filter($seen, static fn($q) => ($q['INSERT'] ?? '') === $T && ($q['r']['jms_asset_id'] ?? null) === $a));
check($r === ['refreshed' => 2, 'failed' => 0, 'pruned' => false], 'partial list: both listed hosts refreshed, not pruned: ' . json_encode($r));
check(array_values(array_filter($seen, static fn($q) => ($q['DELETE'] ?? '') === $T && isset($q['w']['NOT']))) === [],
      'partial list: zero NOT-IN prune');
check(count($pdel('a1-uuid')) === 1 && count($pins('a1-uuid')) === 1 && count($pdel('a2-uuid')) === 1,
      'partial list: the listed hosts are still refreshed (A1 rewritten, A2 cleared by its honest [])');
check(count(array_filter($task->logs, static fn($l) => str_contains((string) $l, 'prune') && str_contains((string) $l, 'skipped'))) === 1,
      'partial list: one log line says the prune was skipped (' . implode(' | ', $task->logs) . ')');

// --- final review, fix 3: no current connection, nothing asked or written ---
// ownRows() is then [], and the prune would be a DELETE across every
// connection's rows - the same guard as AccessIndex::rebuild().
PluginIntegrasipamanConnection::setCurrent(null);
$GLOBALS['API_BY_PATH'] = [$P . 'a1-uuid' => [['id' => 'R1']]];
$GLOBALS['API_ASKED'] = [];
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$r = $E::pullRuleAssets($creds, ['a1-uuid']);
check($r === ['refreshed' => 0, 'failed' => 0, 'pruned' => false], 'no connection: zero result (' . json_encode($r) . ')');
check($GLOBALS['API_ASKED'] === [], 'no connection: zero API calls');
check($db->seen === [], 'no connection: zero DB calls (saw ' . count($db->seen) . ')');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
