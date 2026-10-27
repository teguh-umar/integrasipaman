<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/entitlement_pull_test.php
//
// The rule LIST endpoint returns users_amount / user_groups_amount but
// NEVER the members. The relation tables are the only bulk source, so a
// pull that forgets to merge them stores rules that grant nobody - which
// renders as "no one has access" and looks like a real answer.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

function __($s, $d = null) { return $s; }

class QueryExpression { public function __construct(public string $e) {} }

$GLOBALS['API_RESPONSES'] = [];
class PluginIntegrasipamanJmsApi
{
    public static array $asked = [];
    public static function signedGet($path, $base, $kid, $secret, $org)
    {
        self::$asked[] = $path;
        foreach ($GLOBALS['API_RESPONSES'] as $frag => $rows) {
            if (str_contains($path, $frag)) { return $rows; }
        }
        return [];
    }
}

class FakeDB
{
    public array $seen = [];
    public array $canned = [];
    /** FROM table (alias stripped) => rows. Resolution now reads three
     *  tables, so positional canned results would silently mis-feed them. */
    public array $tables = [];
    public function quoteName($n) { return "`$n`"; }
    public function request($q)
    {
        $this->seen[] = $q;
        $from = trim(explode(' AS ', (string) ($q['FROM'] ?? ''))[0]);
        if ($this->tables !== []) {
            return new ArrayIterator($this->tables[$from] ?? []);
        }
        return new ArrayIterator(array_shift($this->canned) ?? []);
    }
    public function updateOrInsert($table, $params, $where) { $this->seen[] = ['UPSERT' => $table, 'p' => $params, 'w' => $where]; return true; }
    public function insert($table, $params) { $this->seen[] = ['INSERT' => $table, 'p' => $params]; return true; }
    public function delete($table, $where) { $this->seen[] = ['DELETE' => $table, 'w' => $where]; return true; }
    public function update($table, $params, $where) { $this->seen[] = ['UPDATE' => $table, 'p' => $params, 'w' => $where]; return true; }
}

// Minimal stand-ins so cronEntitlement_pull() can be invoked directly.
class PluginIntegrasipamanConfig { public const CONFIG_CONTEXT = 'plugin_integrasipaman'; }
class Config
{
    public static function getConfigurationValues($ctx) { return []; }
}
class GLPIKey { public function decrypt(?string $s): ?string { return $s; } }
class CronTask
{
    public array $logs = [];
    public function log($msg) { $this->logs[] = $msg; }
}

require __DIR__ . '/../inc/entitlement.class.php';

// v3.11.0: the credentials come from the connection, not from config.
PluginIntegrasipamanConnection::setCurrent([
    'id' => 4, 'entities_id' => 1, 'is_recursive' => 1,
    'url' => 'https://x', 'access_key_id' => 'k', 'access_key_secret' => 's', 'org_id' => 'o',
]);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$E = 'PluginIntegrasipamanEntitlement';

// --- members are grouped by the rule they belong to ---------------------
$grouped = $E::groupMembers([
    ['assetpermission' => 'R1', 'user' => 'U1', 'user_display' => 'Alice'],
    ['assetpermission' => 'R1', 'user' => 'U2', 'user_display' => 'Bob'],
    ['assetpermission' => 'R2', 'user' => 'U3', 'user_display' => 'Carol'],
]);
check(count($grouped['R1'] ?? []) === 2, 'two members land on rule R1');
check(count($grouped['R2'] ?? []) === 1, 'one member lands on rule R2');
check(($grouped['R1'][0]['display'] ?? '') === 'Alice', 'the display name is carried, not just the id');
check(!isset($grouped['R3']), 'no rule is invented for a relation nobody references');

// --- the pull merges rules with their members --------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => [['assetpermission' => 'R1', 'user' => 'U1', 'user_display' => 'Alice']],
    'asset-permissions-user-groups-relations' => [['assetpermission' => 'R1', 'usergroup' => 'G1', 'usergroup_display' => 'Ops']],
    'perms/asset-permissions/'                => [
        ['id' => 'R1', 'name' => 'Prod SSH', 'accounts' => ['root'], 'actions' => ['connect'],
         'is_active' => true, 'is_valid' => true, 'is_expired' => false,
         'date_start' => '2026-01-01T00:00:00Z', 'date_expired' => '2027-01-01T00:00:00Z'],
    ],
];
PluginIntegrasipamanJmsApi::$asked = [];
$n = $E::pullRules(['https://x', 'k', 's', 'o']);

check($n === 1, 'one rule stored');

$upserts = array_values(array_filter($db->seen, static fn($q) => isset($q['UPSERT'])));
check(count($upserts) === 1, 'exactly one upsert for one rule');
$p = $upserts[0]['p'] ?? [];
check(($p['name'] ?? '') === 'Prod SSH', 'the rule name is stored');
check(($p['plugin_jumpserver_connections_id'] ?? null) === 4 && ($p['entities_id'] ?? null) === 1,
      'v3.11.0: the rule is stamped with its connection and that connection\'s entity');
check(str_contains((string) ($p['users_json'] ?? ''), 'Alice'), 'the USER member is merged onto the rule');
check(str_contains((string) ($p['user_groups_json'] ?? ''), 'Ops'), 'the GROUP member is merged onto the rule');
// No (int) cast: the cast is what the assertion is testing FOR, so
// applying it here would make a production bool pass the "not a bool"
// check. TINYINT(1) takes a PHP bool without complaint, and is_valid
// would then read back inconsistently.
check(($p['is_valid'] ?? null) === 1, 'is_valid is stored as an int, not a bool (got '
      . var_export($p['is_valid'] ?? null, true) . ')');

// ISO-8601 from the API -> SQL datetime. A raw '2026-01-01T00:00:00Z'
// into a TIMESTAMP column becomes 0000-00-00 or is rejected outright,
// and "Valid until" then renders a date that never existed. Asserted by
// shape, not by literal value, so the check does not depend on the
// server's timezone.
check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($p['date_start'] ?? '')) === 1,
      'date_start is coerced to a SQL datetime (got ' . var_export($p['date_start'] ?? null, true) . ')');
check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($p['date_expired'] ?? '')) === 1,
      'date_expired too (got ' . var_export($p['date_expired'] ?? null, true) . ')');

// A rule the relations never mention still stores, with empty members.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => [],
    'asset-permissions-user-groups-relations' => [],
    'perms/asset-permissions/'                => [['id' => 'R9', 'name' => 'Grants nobody']],
];
$n = $E::pullRules(['https://x', 'k', 's', 'o']);
check($n === 1, 'a rule granting nobody is still stored');
$p = array_values(array_filter($db->seen, static fn($q) => isset($q['UPSERT'])))[0]['p'] ?? [];
check(($p['users_json'] ?? '') === '[]', 'its users_json is an empty array, not null');
// ?? would swallow the null this asserts, so the key is checked first.
check(array_key_exists('date_start', $p) && $p['date_start'] === null,
      'a rule with no dates stores NULL, not an empty string (got '
      . var_export($p['date_start'] ?? 'MISSING', true) . ')');

// --- the year-2123 sentinel, which made v3.3.0 inert -------------------
// JumpServer writes 2123-06-25 to mean "never expires". These columns are
// TIMESTAMP, which stops at 2038-01-19, so MySQL REJECTED the INSERT and the
// cron died on the first rule it read - the feature shipped, installed and
// rendered its pane while collecting nothing.
//
// DATETIME is not the way out on GLPI 11: Central.php:562 counts plugin
// tables in the deprecated-datetime warning, and migration:timestamps
// (TimestampsCommand.php:79) walks that same list and converts them back.
// So the value is normalised at ingestion instead.
//
// The exact string below is the one production sent, not an invented one.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => [],
    'asset-permissions-user-groups-relations' => [],
    'perms/asset-permissions/'                => [[
        'id'           => 'c4910614-05a5-45cb-ba0e-e7aa5ffc0529',
        'name'         => 'admin',
        'is_valid'     => true,
        'date_start'   => '2023-07-19T11:37:27.000000Z',
        'date_expired' => '2123-06-25T11:37:27.000000Z',
    ]],
];
$n = $E::pullRules(['https://x', 'k', 's', 'o']);
check($n === 1, 'the rule that killed v3.3.0 now stores');
$p = array_values(array_filter($db->seen, static fn($q) => isset($q['UPSERT'])))[0]['p'] ?? [];
// array_key_exists first: ?? would swallow the very null being asserted.
check(array_key_exists('date_expired', $p) && $p['date_expired'] === null,
      'a date beyond the TIMESTAMP range stores NULL, which is what the '
    . 'sentinel means - no expiry (got '
    . var_export($p['date_expired'] ?? 'MISSING', true) . ')');
// The in-range date on the SAME rule must survive. A normaliser that
// nulled everything would satisfy the assertion above on its own.
check((string) ($p['date_start'] ?? '') === '2023-07-19 11:37:27',
      'and the in-range date on that same rule is stored intact (got '
    . var_export($p['date_start'] ?? null, true) . ')');

// --- a FAILED relations call must not store rules with empty members ---
// signedGet() returns null on failure. `(array) null` is [], which is
// indistinguishable from "this rule grants nobody" - so a bastion blip
// would rewrite all 219 rules with users_json='[]' over a FRESH
// timestamp, and every Computer would read "No JumpServer permission
// reaches this Computer" with no stale warning to contradict it.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => null,   // the outage
    'asset-permissions-user-groups-relations' => [['assetpermission' => 'R1', 'usergroup' => 'G1', 'usergroup_display' => 'Ops']],
    'perms/asset-permissions/'                => [['id' => 'R1', 'name' => 'Prod SSH']],
];
$n = $E::pullRules(['https://x', 'k', 's', 'o']);
check($n === 0, 'a failed USER-relations call stores nothing (got ' . $n . ')');
check(array_filter($db->seen, static fn($q) => isset($q['UPSERT'])) === [],
      'and issues no upsert, so the previous members survive the outage');

$db = new FakeDB(); $GLOBALS['DB'] = $db;
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => [['assetpermission' => 'R1', 'user' => 'U1', 'user_display' => 'Alice']],
    'asset-permissions-user-groups-relations' => null,   // the outage
    'perms/asset-permissions/'                => [['id' => 'R1', 'name' => 'Prod SSH']],
];
$n = $E::pullRules(['https://x', 'k', 's', 'o']);
check($n === 0, 'a failed GROUP-relations call stores nothing either (got ' . $n . ')');
check(array_filter($db->seen, static fn($q) => isset($q['UPSERT'])) === [],
      'and issues no upsert');

// The distinction that matters: [] is a genuine answer, null is a failure.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => [],
    'asset-permissions-user-groups-relations' => [],
    'perms/asset-permissions/'                => [['id' => 'R1', 'name' => 'Prod SSH']],
];
check($E::pullRules(['https://x', 'k', 's', 'o']) === 1,
      'but genuinely EMPTY relations are not a failure - the rule still stores');

// --- a MULTI-MAPPED Computer unions across every mapped asset ----------
// 73 Computers are mapped from more than one JumpServer asset string.
// Reading one of them gives a shorter list that looks complete - the
// same failure the access tab's assetStringsFor() exists to prevent.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = [
    // v4.0.0: only a Computer inside the connection's scope is a candidate.
    'glpi_computers' => [['id' => 42, 'name' => 'glpi-name-matching-no-host']],
    'glpi_plugin_jumpserver_assetmap' => [
        ['computers_id' => 42, 'parsed_name' => 'db-prod-01',           'parsed_ip' => '10.0.0.5'],
        ['computers_id' => 42, 'parsed_name' => 'db-prod-01.example.com', 'parsed_ip' => ''],
    ],
];
// name and IP both resolve to A1; the FQDN form is a second host, A2.
$lookup = ['db-prod-01' => 'A1', '10.0.0.5' => 'A1', 'db-prod-01.example.com' => 'A2'];
$GLOBALS['API_RESPONSES'] = [
    'asset_id=A1' => [['id' => 'R1'], ['id' => 'R2']],
    'asset_id=A2' => [['id' => 'R2'], ['id' => 'R3']],
];
PluginIntegrasipamanJmsApi::$asked = [];
$n = $E::refreshComputer(42, ['https://x', 'k', 's', 'o'], $lookup);

check(count(PluginIntegrasipamanJmsApi::$asked) === 2, 'one call per DISTINCT UUID - name and IP hitting one host counts once (got '
      . count(PluginIntegrasipamanJmsApi::$asked) . ')');
check($n === 3, 'the union of both assets is 3 distinct rules, not 2 or 4 (got ' . $n . ')');

$upserts = array_values(array_filter($db->seen, static fn($q) => isset($q['UPSERT'])));
$mapped  = [];
foreach ($upserts as $u) { $mapped[] = (string) ($u['w']['permrules_jms_id'] ?? ''); }
sort($mapped);
check($mapped === ['R1', 'R2', 'R3'], 'R2 is stored once, not twice: ' . implode(',', $mapped));

$deletes = array_values(array_filter($db->seen, static fn($q) => isset($q['DELETE'])));
check(count($deletes) === 1, 'the old mapping is cleared exactly once before rewriting');
check(($deletes[0]['w']['computers_id'] ?? null) === 42, 'the clear is scoped to this Computer only');

// --- a FAILED per-asset call must leave the cache alone ----------------
// The old code DELETEd this Computer's rows before any successful write,
// so a bastion outage wiped the cache, lastRefreshed() went null, and the
// pane announced "Entitlements have never been collected" - a transient
// fault rendered as a state that never existed, with the real answer
// destroyed. Spec section 7: "a failed page leaves prior data intact".
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = [
    // v4.0.0: only a Computer inside the connection's scope is a candidate.
    'glpi_computers' => [['id' => 42, 'name' => 'glpi-name-matching-no-host']],
    'glpi_plugin_jumpserver_assetmap' => [
        ['computers_id' => 42, 'parsed_name' => 'db-prod-01',           'parsed_ip' => ''],
        ['computers_id' => 42, 'parsed_name' => 'db-prod-01.example.com', 'parsed_ip' => ''],
    ],
];
$lookup = ['db-prod-01' => 'A1', 'db-prod-01.example.com' => 'A2'];
$GLOBALS['API_RESPONSES'] = [
    'asset_id=A1' => [['id' => 'R1'], ['id' => 'R2']],   // this one succeeds
    'asset_id=A2' => null,                               // and this one times out
];
$n = $E::refreshComputer(42, ['https://x', 'k', 's', 'o'], $lookup);

check($n === -1, 'a partial failure reports itself (-1), not a smaller count that looks like a real answer (got ' . $n . ')');
check(array_filter($db->seen, static fn($q) => isset($q['DELETE'])) === [],
      'the cache is NOT deleted, so the previous entitlements survive the outage');
check(array_filter($db->seen, static fn($q) => isset($q['UPSERT'])) === [],
      'and no narrowed union is written over them');

// A genuinely empty answer is not a failure: the rules really were revoked
// upstream, and the cache must follow.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = [
    'glpi_computers' => [['id' => 42, 'name' => 'glpi-name-matching-no-host']],
    'glpi_plugin_jumpserver_assetmap' => [['computers_id' => 42, 'parsed_name' => 'db-prod-01', 'parsed_ip' => '']],
];
$GLOBALS['API_RESPONSES'] = ['asset_id=A1' => []];
check($E::refreshComputer(42, ['https://x', 'k', 's', 'o'], $lookup) === 0,
      'an asset that legitimately has no rules refreshes to zero');
check(count(array_filter($db->seen, static fn($q) => isset($q['DELETE']))) === 1,
      'and DOES clear the old rows, so a revoked permission stops showing');
// Code review, 4.0.0: "collected, nobody has access" must leave a trace. With no
// row at all the pane said "never been collected" and batchOrder() put the
// Computer first on every run.
$markers = array_values(array_filter($db->seen, static fn($q) => isset($q['UPSERT']) && ($q['w']['permrules_jms_id'] ?? null) === $E::NONE_MARKER));
check(count($markers) === 1 && ($markers[0]['w']['computers_id'] ?? 0) === 42 && !empty($markers[0]['p']['date_refreshed']),
      'and leaves one marker row with the refresh date, so the Computer counts as refreshed');
check($E::NONE_MARKER === '', 'the marker has no rule id, so the pane (an INNER JOIN on the rule) never lists it');

// An unmapped Computer must not call the API at all.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = ['glpi_plugin_jumpserver_assetmap' => []];
PluginIntegrasipamanJmsApi::$asked = [];
check($E::refreshComputer(7, ['https://x', 'k', 's', 'o'], $lookup) === 0, 'an unmapped Computer maps no rules');
check(PluginIntegrasipamanJmsApi::$asked === [], 'and issues NO API call');

// --- hostLookup() builds the name/address -> UUID map ------------------
// Everything else in this feature resolves through this map: a bug here
// yields an empty or misattributed entitlement list that still looks
// like a real answer.
$GLOBALS['API_RESPONSES'] = [
    'assets/hosts/' => [
        ['id' => 'A1', 'name' => 'db-prod-01', 'address' => '10.0.0.5'],
        ['id' => '',   'name' => 'ghost-host', 'address' => '10.0.0.9'],   // no id: must be skipped
        ['id' => 'A2', 'name' => ' Web-Prod-02 ', 'address' => ''],        // mixed case, padded, blank address
    ],
];
$lookup = $E::hostLookup(['https://x', 'k', 's', 'o']);

check(($lookup['db-prod-01'] ?? null) === 'A1', "host's name maps to its UUID (got " . var_export($lookup['db-prod-01'] ?? null, true) . ')');
check(($lookup['10.0.0.5'] ?? null) === 'A1', "host's address maps to the same UUID (got " . var_export($lookup['10.0.0.5'] ?? null, true) . ')');
check(!isset($lookup['ghost-host']) && !isset($lookup['10.0.0.9']), 'a host with an empty id is skipped entirely');
check(!isset($lookup['']), 'nothing is stored under an empty key');
check(($lookup['web-prod-02'] ?? null) === 'A2', 'keys are lowercased and trimmed, so lookups are case-insensitive (got '
      . var_export($lookup['web-prod-02'] ?? null, true) . ')');
check(count($lookup) === 3, 'exactly 3 keys stored: name+address for A1, name only for A2 (got ' . count($lookup) . ')');

// An empty API response yields an empty map, not an error.
$GLOBALS['API_RESPONSES'] = ['assets/hosts/' => []];
check($E::hostLookup(['https://x', 'k', 's', 'o']) === [], 'an empty API response yields an empty map');

// --- the candidate set is the ESTATE, not just assets with history -----
// assetmap is populated from DISTINCT asset in the COMMANDS table, i.e.
// only machines someone has already typed on. Drawing candidates from it
// makes this feature answer "who may reach machines that already have
// session history" - the population the existing access pane already
// covers - while the whole point (spec section 1) is the dormant
// machine whose permission nobody has exercised.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = [
    'glpi_computers' => [
        ['id' => 11, 'name' => 'dormant-01'],      // never touched: NO assetmap row
        ['id' => 12, 'name' => 'renamed-in-glpi'], // GLPI name matches no host
        ['id' => 13, 'name' => 'no-such-host'],
    ],
    'glpi_ipaddresses' => [
        ['mainitems_id' => 12, 'name' => '10.0.0.12'],
    ],
    'glpi_plugin_jumpserver_assetmap' => [
        ['computers_id' => 13, 'parsed_name' => 'legacy-name', 'parsed_ip' => ''],
    ],
];
$lookup = ['dormant-01' => 'A11', '10.0.0.12' => 'A12', 'legacy-name' => 'A13'];
$cand = $E::candidateAssets($lookup);

check(($cand[11] ?? []) === ['A11'],
      'a Computer with NO assetmap row is a candidate when its GLPI name matches a host - the dormant case this feature exists for');
check(($cand[12] ?? []) === ['A12'],
      'and one whose ADDRESS matches a host is a candidate too');
check(($cand[13] ?? []) === ['A13'],
      'a correlation-resolved assetmap name still resolves, so nothing that worked before stops working');
check(count($cand) === 3, 'exactly the three matching Computers are candidates (got ' . count($cand) . ')');

// A host nobody in GLPI matches must not invent a Computer.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = ['glpi_computers' => [['id' => 11, 'name' => 'unknown-to-jumpserver']]];
check($E::candidateAssets(['some-host' => 'A1']) === [], 'a Computer matching no host is not a candidate');
check($E::candidateAssets([]) === [], 'an empty host lookup yields no candidates and cannot match by accident');

// Name and address hitting the same host count once.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = [
    'glpi_computers'   => [['id' => 11, 'name' => 'db-prod-01']],
    'glpi_ipaddresses' => [['mainitems_id' => 11, 'name' => '10.0.0.5']],
];
check($E::candidateAssets(['db-prod-01' => 'A1', '10.0.0.5' => 'A1'])[11] === ['A1'],
      'name and address resolving to one host yield one UUID, not two');

// assetIdsFor() must answer from the same matcher, not a second one.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = ['glpi_computers' => [['id' => 11, 'name' => 'dormant-01']]];
check($E::assetIdsFor(11, ['dormant-01' => 'A11']) === ['A11'],
      'assetIdsFor() resolves a Computer with no assetmap row at all');

// --- the cron refreshes dormant Computers, oldest-first, bounded -------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->tables = [
    'glpi_computers' => [
        ['id' => 11, 'name' => 'never-refreshed'],
        ['id' => 12, 'name' => 'oldest'],
        ['id' => 13, 'name' => 'newest'],
    ],
    'glpi_ipaddresses'                => [],
    'glpi_plugin_jumpserver_assetmap' => [],
    'glpi_plugin_jumpserver_assetperms' => [   // read ordered date_refreshed ASC
        ['computers_id' => 12, 'date_refreshed' => '2026-01-01 00:00:00'],
        ['computers_id' => 13, 'date_refreshed' => '2026-06-01 00:00:00'],
    ],
];
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => [],
    'asset-permissions-user-groups-relations' => [],
    'perms/asset-permissions/'                => [],
    'assets/hosts/'                           => [
        ['id' => 'A11', 'name' => 'never-refreshed', 'address' => ''],
        ['id' => 'A12', 'name' => 'oldest',          'address' => ''],
        ['id' => 'A13', 'name' => 'newest',          'address' => ''],
    ],
    'asset_id=' => [],
];
PluginIntegrasipamanJmsApi::$asked = [];
$task = new CronTask();
$E::cronEntitlement_pull($task);

$order = [];
foreach (PluginIntegrasipamanJmsApi::$asked as $path) {
    if (preg_match('/asset_id=(A\d+)/', $path, $m)) { $order[] = $m[1]; }
}
check($order === ['A11', 'A12', 'A13'],
      'the batch runs never-refreshed first, then oldest-refreshed first (got ' . implode(',', $order) . ')');
check(in_array('A11', $order, true),
      'a Computer with no assetperms row and no assetmap row IS refreshed - the C1 regression guard');

// Bounded: a large estate degrades into a slower refresh, never a run
// that does not finish.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$rows = []; $hosts = [];
for ($i = 1; $i <= $E::BATCH_LIMIT + 50; $i++) {
    $rows[]  = ['id' => $i, 'name' => 'host-' . $i];
    $hosts[] = ['id' => 'A' . $i, 'name' => 'host-' . $i, 'address' => ''];
}
$db->tables = [
    'glpi_computers'                    => $rows,
    'glpi_ipaddresses'                  => [],
    'glpi_plugin_jumpserver_assetmap'   => [],
    'glpi_plugin_jumpserver_assetperms' => [],
];
$GLOBALS['API_RESPONSES'] = [
    'asset-permissions-users-relations'       => [],
    'asset-permissions-user-groups-relations' => [],
    'perms/asset-permissions/'                => [],
    'assets/hosts/'                           => $hosts,
    'asset_id='                               => [],
];
PluginIntegrasipamanJmsApi::$asked = [];
$E::cronEntitlement_pull(new CronTask());
$per_asset = count(array_filter(PluginIntegrasipamanJmsApi::$asked, static fn($p) => str_contains($p, 'asset_id=')));
check($per_asset === $E::BATCH_LIMIT,
      'exactly BATCH_LIMIT Computers are refreshed out of ' . ($E::BATCH_LIMIT + 50) . ' candidates (got ' . $per_asset . ')');

// --- live_checked_at: confirmed present, never "marked dead" ------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['id' => 1, 'parsed_name' => 'alive',  'parsed_ip' => '192.0.2.5'],
    ['id' => 2, 'parsed_name' => 'gone',   'parsed_ip' => '203.0.113.7'],
]];
$n = $E::markLiveAssets(['alive' => 'U1', '192.0.2.5' => 'U1']);
check($n === 1, 'one row confirmed (got ' . var_export($n, true) . ')');

$written = [];
foreach ($db->seen as $q) {
    if (isset($q['UPDATE'])) {
        $written[(int) ($q['w']['id'] ?? 0)] = $q['p'];
    }
}
check(isset($written[1]) && array_key_exists('live_checked_at', $written[1]),
      'the row present in the host list gets its timestamp advanced');
check(!isset($written[2]),
      'the row ABSENT from the list is left untouched - it ages, and its age '
    . 'is the signal. Nothing writes "dead", because an asset renamed in '
    . 'PAMAN this morning is not dead, it is differently named');

// The address branch is not a fallback for odd cases - it is the normal
// path for every asset PAMAN names differently from GLPI, which is most
// of the reason hostLookup() keys on both name AND address in the first
// place. A separate call (rather than adding to the fixture above) keeps
// this pinned without disturbing the "one row confirmed" count above.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[
    ['id' => 3, 'parsed_name' => 'renamed-in-paman', 'parsed_ip' => '198.51.100.9'],
]];
$n = $E::markLiveAssets(['198.51.100.9' => 'U2']);
check($n === 1, 'a row whose NAME misses is still confirmed by its ADDRESS alone (got '
      . var_export($n, true) . ')');
$written = [];
foreach ($db->seen as $q) {
    if (isset($q['UPDATE'])) {
        $written[(int) ($q['w']['id'] ?? 0)] = $q['p'];
    }
}
check(isset($written[3]) && array_key_exists('live_checked_at', $written[3]),
      'and that row is the one actually written - a name-only matcher would '
    . 'silently under-count every asset PAMAN renamed');

// Review Focus 3: a failed pull must not age the whole estate.
// signedGet() returns null on failure and a list on success, so [] is a
// real answer and null is not. v3.3.0 shipped the opposite once: the
// entitlement pull deleted its cache before any successful write, so a
// bastion blip rendered as "never collected".
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[['id' => 1, 'parsed_name' => 'alive', 'parsed_ip' => '192.0.2.5']]];
check($E::markLiveAssets([]) === -1,
      'an empty lookup is refused outright, not treated as "nothing is live"');
check($db->seen === [],
      'and it issues no query at all - the refusal happens before the table is '
    . 'touched, not after a pointless walk of every row (saw '
    . count($db->seen) . ')');

// --- hostIndex() keeps the names the same response already carried --------
// rc2's VM pass asked for the asset's PAMAN name on rows GLPI has lost.
// hostLookup() had the names in hand and discarded them. hostIndex() returns
// both directions from ONE call: the host list is 645 rows on one paginated
// request, and a second call for data that cannot change within a run would
// be waste for nothing.
$GLOBALS['API_RESPONSES'] = [
    'assets/hosts/' => [
        ['id' => 'A1', 'name' => 'db-prod-01', 'address' => '10.0.0.5'],
        ['id' => '',   'name' => 'ghost-host', 'address' => '10.0.0.9'],
        ['id' => 'A2', 'name' => ' Web-Prod-02 ', 'address' => ''],
    ],
];
PluginIntegrasipamanJmsApi::$asked = [];
$index = $E::hostIndex(['https://x', 'k', 's', 'o']);

check(count(PluginIntegrasipamanJmsApi::$asked) === 1,
      'hostIndex() issues exactly ONE host call for both directions');
check(($index['hosts']['A1']['name'] ?? null) === 'db-prod-01'
      && ($index['hosts']['A1']['address'] ?? null) === '10.0.0.5',
      'UUID -> name/address is returned alongside the lookup');
check(($index['hosts']['A2']['name'] ?? null) === 'Web-Prod-02',
      'the name is trimmed but NOT lowercased: it is shown to a person, not matched');
check(!isset($index['hosts']['']), 'a host with no id is skipped here too');
check(count($index['hosts']) === 2, 'two hosts indexed by UUID');
check($index['lookup'] === $E::hostLookup(['https://x', 'k', 's', 'o']),
      'hostLookup() still answers exactly what it used to');

// --- storeHosts() is all-or-nothing, like the group pull ------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
check($E::storeHosts([]) === -1,
      'an empty host list writes NOTHING and says so: a failed call must not blank every name');
check($db->seen === [], 'and it issues no write at all');

$db = new FakeDB(); $GLOBALS['DB'] = $db;
$written = $E::storeHosts($index['hosts']);
check($written === 2, 'both hosts are written (got ' . $written . ')');

$upserts = array_values(array_filter($db->seen, static fn ($q): bool => isset($q['UPSERT'])));
check(count($upserts) === 2 && $upserts[0]['UPSERT'] === 'glpi_plugin_jumpserver_hosts',
      'written to the host-name table');
check(($upserts[0]['w']['jms_id'] ?? null) === 'A1'
      && isset($upserts[0]['w']['plugin_jumpserver_connections_id']),
      'keyed by (connection, UUID): two bastions may issue the same UUID');
check(($upserts[0]['p']['name'] ?? null) === 'db-prod-01'
      && ($upserts[0]['p']['address'] ?? null) === '10.0.0.5',
      'the name and address are what gets stored');
check(array_key_exists('date_pulled', $upserts[0]['p']),
      'with the pull date, so a stale name can be recognised as stale');
check(array_key_exists('entities_id', $upserts[0]['p']),
      'and stamped with the entity, like every other pulled row');

// The pull must collect the names before it walks the Computers, and it must
// not stop the run when they cannot be collected.
$src = (string) file_get_contents(__DIR__ . '/../inc/entitlement.class.php');
check(strpos($src, 'storeHosts($index[') !== false
      && strpos($src, 'hostIndex($credentials)') < strpos($src, 'storeHosts($index['),
      'pullFor() stores the names from the index it just fetched');
check(strpos($src, 'storeHosts($index[') < strpos($src, 'candidateAssets($lookup)'),
      'and does so before matching Computers, so one run leaves the report complete');
check(strpos($src, 'left in place') !== false,
      'a failed host pull keeps the previous names rather than emptying them');


echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
