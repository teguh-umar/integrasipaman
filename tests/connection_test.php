<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/connection_test.php
//
// v3.11.0: the JumpServer connection is a row owned by an entity (suite
// entity rule, point 3: one JumpServer per subsidiary), and every data row is
// stamped with its connection and an entity.
//
// What can go wrong quietly, and is pinned here:
//   - the migration loses or re-logs a secret (3.10.1 moved them once
//     already; this moves them again), or leaves the old keys behind so the
//     next install seeds a second, empty connection;
//   - a row is stamped with ROOT because 0 is the column default and root's
//     id - root is the holding, never the neutral place;
//   - a command is given the entity of a Computer that lies OUTSIDE the
//     connection's scope, which is a cross-company disclosure the moment
//     4.0.0 starts reading the column;
//   - the entity columns start changing what the screens show before 4.0.0.
//
// It also covers what tests/secured_config_test.php covered in 3.10.1: no
// secret in an input's value, none in glpi_configs, none read from a config
// array.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

// --- stand-ins --------------------------------------------------------------
class FakeDB
{
    /** @var array<string, array<int, array<string,mixed>>> table => rows */
    public array $tables = [];
    public array $seen = [];
    public array $queries = [];
    public bool $fail_insert = false;
    public $grandfather = null;
    private int $auto = 0;

    private function match(array $row, array $where): bool
    {
        foreach ($where as $k => $v) {
            $k = preg_replace('/^\w+\./', '', (string) $k);
            $have = $row[$k] ?? null;
            if (is_array($v) && isset($v[0]) && $v[0] === '>') {
                if (!($have > $v[1])) { return false; }
            } elseif (is_array($v)) {
                if (!in_array($have, $v)) { return false; }
            } elseif ($have != $v) {
                return false;
            }
        }
        return true;
    }
    public function tableExists($t) { return array_key_exists($t, $this->tables); }
    public function request($q)
    {
        $from = trim(explode(' AS ', (string) $q['FROM'])[0]);
        if (isset($q['INNER JOIN'], $q['DISTINCT']) && $this->grandfather !== null) {
            return ($this->grandfather)();
        }
        // The other join in the class: asset map -> computers, by asset.
        if (isset($q['INNER JOIN'])) {
            $out = [];
            foreach ($this->tables[$from] ?? [] as $m) {
                foreach ($this->tables['glpi_computers'] ?? [] as $c) {
                    if ((int) $c['id'] === (int) $m['computers_id']
                        && (int) ($m['plugin_jumpserver_connections_id'] ?? 0) === (int) $q['WHERE']['m.plugin_jumpserver_connections_id']
                        && in_array($m['asset'], $q['WHERE']['m.asset'], true)
                        && in_array((int) $c['entities_id'], $q['WHERE']['c.entities_id'], true)) {
                        $out[] = ['asset' => $m['asset'], 'entities_id' => $c['entities_id']];
                    }
                }
            }
            return new ArrayIterator($out);
        }
        $rows = array_values(array_filter($this->tables[$from] ?? [], fn ($r) => $this->match($r, $q['WHERE'] ?? [])));
        usort($rows, fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
        if (isset($q['LIMIT'])) {
            $rows = array_slice($rows, 0, (int) $q['LIMIT']);
        }
        return new ArrayIterator($rows);
    }
    public function insert($table, $row)
    {
        if ($this->fail_insert) { return false; }
        $this->seen[] = ['INSERT', $table];
        $this->tables[$table][] = ['id' => ++$this->auto] + $row;
        return true;
    }
    public function update($table, $params, $where)
    {
        $this->seen[] = ['UPDATE', $table];
        foreach ($this->tables[$table] as $i => $r) {
            if ($this->match($r, $where)) {
                $this->tables[$table][$i] = array_merge($r, $params);
            }
        }
        return true;
    }
    public function delete($table, $where)
    {
        $this->seen[] = ['DELETE', $table, $where];
        $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], fn ($r) => !$this->match($r, $where)));
        return true;
    }
    public function doQuery($sql) { $this->queries[] = $sql; return true; }
    public function error() { return 'boom'; }
}

class GLPIKey
{
    public static bool $readable = true;
    public function encrypt(string $s): string { return self::$readable ? 'ENC:' . strrev($s) : ''; }
    public function decrypt(?string $s): ?string
    {
        if ($s === null || $s === '') { return $s; }
        return str_starts_with($s, 'ENC:') ? strrev(substr($s, 4)) : '';
    }
}

// Any call here is a failure: Config item methods write the value to glpi_logs.
class Config
{
    public static array $calls = [];
    public static function __callStatic($name, $args) { self::$calls[] = $name; return []; }
}

$DB = new FakeDB();

require __DIR__ . '/../inc/connection.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$C   = 'PluginIntegrasipamanConnection';
$T   = $C::TABLE;
$FK  = $C::FK;
$OLD = 'plugin_integrasipaman';
$NEW = 'plugin:integrasipaman';

// Entity tree: 0 root > 1 (a subsidiary) > 3 > 5 ; 0 > 2 (a sibling subsidiary) > 4.
$entities = [
    ['id' => 0, 'entities_id' => null],
    ['id' => 1, 'entities_id' => 0],
    ['id' => 2, 'entities_id' => 0],
    ['id' => 3, 'entities_id' => 1],
    ['id' => 4, 'entities_id' => 2],
    ['id' => 5, 'entities_id' => 3],
];
$cfg = static fn (string $context, string $name, string $value) => ['context' => $context, 'name' => $name, 'value' => $value];

// --- nothing configured: a fresh install ------------------------------------
$DB->tables = [$T => [], 'glpi_configs' => [], 'glpi_entities' => $entities];
check($C::migrate() === [] && $DB->tables[$T] === [], 'a fresh install creates no connection (the Setup form does, on its first save)');
check($C::current() === null, 'and current() is null');
check($C::currentCredentials() === ['', '', '', ''], 'currentCredentials() is four empty strings - "not configured" to every caller');
check($C::stamp() === [] && $C::stampRows([['a' => 1]]) === [['a' => 1]], 'with no connection nothing is stamped, rather than stamped with 0');

// before the install function has created the table
$DB->tables = [];
$C::reset();
check($C::current() === null, 'current() survives the table not existing yet (new files, old schema)');

// --- migrate(): the 3.10.1 shape (secrets already encrypted) ----------------
$DB->tables = [
    $T => [],
    'glpi_entities' => $entities,
    'glpi_configs'  => [
        $cfg($OLD, 'jumpserver_url', ' https://paman.example.com '),
        $cfg($OLD, 'jumpserver_access_key_id', 'KID'),
        $cfg($OLD, 'jumpserver_org_id', 'ORG'),
        $cfg($OLD, 'jumpserver_command_storage_id', 'STORE'),
        $cfg($OLD, 'jumpserver_pbi_db_host', 'pbi.host'),
        $cfg($OLD, 'jumpserver_pbi_db_port', '3306'),
        $cfg($OLD, 'jumpserver_pbi_db_name', 'jumpserver_pbi_views'),
        $cfg($OLD, 'jumpserver_pbi_db_user', 'reader'),
        $cfg($OLD, 'jumpserver_risk_keywords', "sudo\nsu "),
        $cfg($NEW, 'jumpserver_access_key_secret', 'ENC:terces'),
        $cfg($NEW, 'jumpserver_pbi_db_password', 'ENC:ssap'),
    ],
];
foreach (array_keys($C::STAMPED_TABLES) as $t) { $DB->tables[$t] = []; }
$DB->queries = [];
$C::reset();
check($C::migrate() === [], 'migrate() reports no problem');
check(count($DB->tables[$T]) === 1, 'exactly one connection row is created');
$row = $DB->tables[$T][0];
check($row['url'] === 'https://paman.example.com' && $row['access_key_id'] === 'KID' && $row['org_id'] === 'ORG'
      && $row['command_storage_id'] === 'STORE', 'URL (trimmed), Access Key ID, Organization ID and Command Storage ID are carried over');
check($row['pbi_db_host'] === 'pbi.host' && $row['pbi_db_user'] === 'reader' && $row['pbi_db_name'] === 'jumpserver_pbi_views',
      'the PBI database settings are carried over');
check($row['access_key_secret'] === 'ENC:terces' && $row['pbi_db_password'] === 'ENC:ssap',
      'an already encrypted secret is copied as it is, not encrypted a second time');
check($row['entities_id'] === 1 && $row['is_recursive'] === 1,
      'the connection belongs to the first entity under root, recursive (user, 2026-10-05) - not to root');
check(array_column($DB->tables['glpi_configs'], 'name') === ['jumpserver_risk_keywords'],
      'every connection key is gone from glpi_configs, in both contexts; the other settings stay');
check(Config::$calls === [], 'nothing went through Config (its item methods log the value they add or purge)');
check(count($DB->queries) === count($C::STAMPED_TABLES), 'and every data table is stamped (' . count($DB->queries) . ' statements)');
check(count(preg_grep('/WHERE (t\.)?`' . $FK . '` IN \(0, \d+\)$/', $DB->queries)) === count($DB->queries),
      'the migration adopts the rows written before the columns existed (connection 0)');

$conn = $C::current();
check($conn !== null && $conn->credentials() === ['https://paman.example.com', 'KID', 'secret', 'ORG'], 'credentials() decrypts the secret');
check($conn->pbiDbPassword() === 'pass', 'pbiDbPassword() decrypts');
check($conn->get('access_key_secret') === '' && $conn->get('pbi_db_password') === '', 'get() never hands out a secret, not even its ciphertext');
check($conn->hasSecret('access_key_secret') && !$conn->hasSecret('url'), 'hasSecret() says a value is stored');

$before = $DB->tables;
check($C::migrate() === [] && $DB->tables == $before, 'migrate() is safe to re-run (install runs on every upgrade)');

// --- migrate(): straight from <= 3.10.0 (plain secrets) ----------------------
$DB->tables[$T] = [];
$DB->tables['glpi_configs'] = [
    $cfg($OLD, 'jumpserver_url', 'https://paman.example.com'),
    $cfg($OLD, 'jumpserver_access_key_id', 'KID'),
    $cfg($OLD, 'jumpserver_access_key_secret', 'plain-secret'),
    $cfg($OLD, 'jumpserver_pbi_db_password', ''),
];
$C::reset();
check($C::migrate() === [], 'an upgrade that skipped 3.10.1 migrates too');
check($DB->tables[$T][0]['access_key_secret'] === 'ENC:' . strrev('plain-secret'), 'its plain secret is encrypted on the way');
check(!isset($DB->tables[$T][0]['pbi_db_password']), 'an empty secret stays empty');
check($DB->tables['glpi_configs'] === [], 'and the plain rows are removed');

// unreadable GLPI key: never drop the only copy
GLPIKey::$readable = false;
$DB->tables[$T] = [];
$DB->tables['glpi_configs'] = [
    $cfg($OLD, 'jumpserver_url', 'https://paman.example.com'),
    $cfg($OLD, 'jumpserver_access_key_secret', 'plain-secret'),
];
$C::reset();
$problems = $C::migrate();
check(count($problems) === 1 && str_contains($problems[0], 'jumpserver_access_key_secret'), 'with no readable GLPI key, migrate() names the secret it could not encrypt');
check($DB->tables[$T] === [] && count($DB->tables['glpi_configs']) === 2, 'and changes nothing: no connection row, the old settings untouched');
GLPIKey::$readable = true;

// a refused INSERT must not delete the settings it failed to copy
$DB->fail_insert = true;
$C::reset();
check($C::migrate() !== [] && count($DB->tables['glpi_configs']) === 2, 'a refused INSERT leaves the old settings in place');
$DB->fail_insert = false;

// an instance with no entity but root
$DB->tables['glpi_entities'] = [['id' => 0, 'entities_id' => null]];
check($C::defaultEntity() === 0, 'the root entity is the default only where there is no other');
$DB->tables['glpi_entities'] = $entities;
check($C::defaultEntity() === 1, 'otherwise it is the first entity directly under root');

// --- scope ------------------------------------------------------------------
$C::setCurrent(['id' => 7, 'entities_id' => 1, 'is_recursive' => 1]);
check($C::current()->scope() === [1, 3, 5], 'a recursive connection covers its entity and every descendant');
$C::setCurrent(['id' => 7, 'entities_id' => 1, 'is_recursive' => 0]);
check($C::current()->scope() === [1], 'a non-recursive one covers its own entity only');

// --- stamping new rows -------------------------------------------------------
$C::setCurrent(['id' => 7, 'entities_id' => 1, 'is_recursive' => 1]);
$DB->tables['glpi_computers'] = [
    ['id' => 10, 'entities_id' => 3],   // inside the scope
    ['id' => 11, 'entities_id' => 2],   // another subsidiary
    ['id' => 12, 'entities_id' => 0],   // root: above the connection, not inside it
];
$DB->tables['glpi_plugin_jumpserver_assetmap'] = [
    [$FK => 7, 'asset' => 'srv-child(10.0.0.3)', 'computers_id' => 10],
    [$FK => 7, 'asset' => 'srv-other(10.0.0.2)', 'computers_id' => 11],
    [$FK => 7, 'asset' => 'srv-root(10.0.0.1)',  'computers_id' => 12],
    [$FK => 7, 'asset' => 'srv-unmatched',       'computers_id' => 0],
    // the same asset string known to ANOTHER connection, mapped inside this scope
    [$FK => 8, 'asset' => 'srv-theirs',          'computers_id' => 10],
];
check($C::stamp() === [$FK => 7, 'entities_id' => 1], 'stamp() is the connection id and the connection entity');

$stamped = $C::stampRows([
    ['jms_id' => 'a', 'asset' => 'srv-child(10.0.0.3)'],
    ['jms_id' => 'b', 'asset' => 'srv-other(10.0.0.2)'],
    ['jms_id' => 'c', 'asset' => 'srv-root(10.0.0.1)'],
    ['jms_id' => 'd', 'asset' => 'srv-unmatched'],
    ['jms_id' => 'e', 'asset' => 'never-seen'],
    ['source_id' => 'f'],
    ['jms_id' => 'g', 'asset' => 'srv-theirs'],
]);
check(array_column($stamped, $FK) === [7, 7, 7, 7, 7, 7, 7], 'every row gets the connection');
check($stamped[6]['entities_id'] === 1, "another connection's asset map does not decide this connection's rows");
check($stamped[0]['entities_id'] === 3, 'a row whose asset is matched to a Computer in a child entity takes that entity');
check($stamped[1]['entities_id'] === 1, 'a Computer in ANOTHER subsidiary does not pull the row out of the connection');
check($stamped[2]['entities_id'] === 1, 'nor does a Computer in root: root is above the scope, not inside it');
check($stamped[3]['entities_id'] === 1 && $stamped[4]['entities_id'] === 1, 'an unmatched or unknown asset stays with the connection');
check($stamped[5]['entities_id'] === 1, 'a row with no asset at all (an audit-log entry) stays with the connection');
check($stamped[0]['jms_id'] === 'a' && $stamped[0]['asset'] === 'srv-child(10.0.0.3)', 'the row itself is untouched');

// --- restamp: the same rule, as SQL ------------------------------------------
$all = $C::current()->restampQueries();
check(count($all) === count($C::STAMPED_TABLES), 'restamp writes one statement per stamped table');
check(!array_key_exists('glpi_plugin_jumpserver_command_eval', $C::STAMPED_TABLES),
      'command_eval is not stamped: it is a child of commands and inherits');
foreach ($all as $sql) {
    check(preg_match('/`entities_id` = (1|COALESCE\(c\.`entities_id`, 1\)) WHERE (t\.)?`' . $FK . '` = 7$/', $sql) === 1,
          'sets an entity that falls back to the connection entity, on this connection\'s rows only: ' . substr($sql, 0, 52));
}
check(preg_grep('/IN \(0, 7\)$/', $all) === [], 'an ordinary restamp never adopts unowned rows');
$joined = array_values(array_filter($all, static fn ($s) => str_contains($s, 'glpi_computers')));
check(count($joined) === 5, 'five tables follow a Computer: asset map, provisioning, asset permissions, commands, sessions');
foreach ($joined as $sql) {
    check(str_contains($sql, 'c.`entities_id` IN (1, 3, 5)'), 'and only a Computer inside the scope counts');
    check(str_contains($sql, 'LEFT JOIN'), 'by LEFT JOIN, so an unmatched row is still stamped');
}
check(count(preg_grep('/LEFT JOIN `glpi_plugin_jumpserver_assetmap` m ON m\.`asset` = t\.`asset` AND m\.`' . $FK . '` IN \(0, 7\)/', $all)) === 2,
      'commands and sessions look their asset up in this connection\'s asset map, not in another\'s');
check(count($C::current()->restampQueries(false)) === 3,
      'the cheap pass after a correlation run touches only the three tables that link a Computer directly');
check(preg_grep('/jumpserver_(commands|sessions|logs)`/', $C::current()->restampQueries(false)) === [],
      'it never re-decides history: a command keeps the entity it was given at ingest');

// --- save(): the Setup form ---------------------------------------------------
$DB->tables[$T] = [];
$DB->tables['glpi_configs'] = [];
$DB->queries = [];
$C::reset();
check($C::save(null, ['url' => 'https://a.example']) !== '' && $DB->tables[$T] === [],
      'a new connection is refused without an owning entity - the owner is never guessed');
check($C::save(null, ['url' => 'https://a.example', 'access_key_id' => 'K', 'org_id' => 'O', 'evil' => 'x'], ['access_key_secret' => 'typed'], 1) === '',
      'the first save creates the connection');
$row = $DB->tables[$T][0];
$one = (int) $row['id'];
check($row['access_key_secret'] === 'ENC:' . strrev('typed'), 'the secret is stored encrypted');
check(!array_key_exists('evil', $row), 'a column the form may not write is dropped');
check($row['entities_id'] === 1 && $row['is_recursive'] === 1 && $row['extra_entities'] === '', 'owned by the chosen entity, child entities on, nothing extra');
check($DB->queries !== [], 'and its rows are stamped');
check($C::current() !== null && $C::current()->id() === $one, 'with one connection, current() is that connection');

$DB->queries = [];
check($C::save($one, ['url' => 'https://b.example']) === '' && $DB->tables[$T][0]['url'] === 'https://b.example', 'a later save updates the same row');
check(count($DB->tables[$T]) === 1, 'there is still one connection');
check($DB->tables[$T][0]['access_key_secret'] === 'ENC:' . strrev('typed'), 'a secret that was not typed is kept');
check($DB->queries === [], 'and nothing is restamped when the scope did not change');
check($C::save(999, ['url' => 'x']) !== '', 'saving an id that does not exist is refused');

check($C::save($one, [], ['pbi_db_password' => 'pw']) === '' && $C::current()->pbiDbPassword() === 'pw', 'a typed PBI password is stored');
check($C::save($one, [], ['pbi_db_password' => '']) === '' && !$C::current()->hasSecret('pbi_db_password'), "'' clears it");

$C::save($one, [], [], 2, null);
check($DB->tables[$T][0]['entities_id'] === 2 && $DB->queries !== [], 'changing the entity restamps the rows');
$DB->queries = [];
$C::save($one, [], [], 2, false);
check($DB->tables[$T][0]['is_recursive'] === 0 && $DB->queries !== [], 'so does changing "child entities"');
$DB->queries = [];
$C::save($one, [], [], 2, false, []);
check($DB->queries === [], 'saving the same scope again does not');

GLPIKey::$readable = false;
check($C::save($one, ['url' => 'https://c.example'], ['access_key_secret' => 'new']) !== '', 'save() fails when the secret cannot be encrypted');
check($DB->tables[$T][0]['url'] === 'https://b.example', 'and writes nothing, rather than a row with an empty secret');
GLPIKey::$readable = true;
check(Config::$calls === [], 'no save went through Config');

// --- v4.0.0: several connections ------------------------------------------------
// Production shape: ICT owns the JumpServer; OT is a SIBLING of ICT that uses the
// same JumpServer and the same JumpServer organisation, so it cannot have a
// connection of its own. Here: entity 1 = ICT (children 3 > 5), entity 2 = OT (child 4).
$DB->tables[$T] = [];
$C::reset();
check($C::save(null, ['name' => 'ICT'], [], 1, true, [2]) === '', 'a connection may cover an additional entity');
$ict = (int) $DB->tables[$T][0]['id'];
check($DB->tables[$T][0]['extra_entities'] === '2', 'stored as a list of entity ids');
$scope = $C::byId($ict)->scope();
sort($scope);
check($scope === [1, 2, 3, 4, 5], 'its scope is the owner, the additional entity, and the descendants of both');
check($C::forEntity(2)->id() === $ict && $C::forEntity(4)->id() === $ict, 'the additional entity and its child are served by it');
check($C::forEntity(0) === null, 'root is above every connection: nothing serves it');

$C::save($ict, [], [], null, null, [2, 2, 1, 4]);
check($DB->tables[$T][0]['extra_entities'] === '2,4', 'duplicates and the owner itself are dropped from the additional list');
$C::save($ict, [], [], null, null, [2]);

check($C::save(null, ['name' => 'again'], [], 1) !== '' && count($DB->tables[$T]) === 1,
      'a second connection for an entity that already owns one is refused');

// A subsidiary under ICT gets its own JumpServer: the more specific connection wins.
$DB->queries = [];
check($C::save(null, ['name' => 'Sub'], [], 3, true) === '', 'a child entity may get a connection of its own');
$sub = (int) $DB->tables[$T][1]['id'];
check($C::forEntity(3)->id() === $sub && $C::forEntity(5)->id() === $sub, 'it serves its entity and that entity\'s children');
check($C::forEntity(1)->id() === $ict, 'the parent keeps its own entity');
$ict_scope = $C::byId($ict)->scope();
sort($ict_scope);
check($ict_scope === [1, 2, 4], 'and the subtree leaves the parent\'s scope, so no Computer is claimed twice');
check($C::byId($sub)->scope() === [3, 5], 'the new connection covers exactly that subtree');
check(count(preg_grep('/`' . $FK . '` = ' . $ict . '$/', $DB->queries)) === count($C::STAMPED_TABLES)
      && count(preg_grep('/`' . $FK . '` = ' . $sub . '$/', $DB->queries)) === count($C::STAMPED_TABLES),
      'creating it re-decides the rows of BOTH connections, each over its own rows only');
check(preg_grep('/c\.`entities_id` IN \(1, 2, 4\)/', $DB->queries) !== [], 'the parent\'s restamp no longer counts Computers of the subtree');

// an additional entity beats a recursive ancestor, an owner beats both
$C::save($ict, [], [], null, null, [2, 5]);
check($C::forEntity(5)->id() === $ict, 'an entity listed by one connection is not taken by another connection\'s "child entities"');
$C::save($ict, [], [], null, null, [2]);

// which connection is "current"
check($C::current() === null, 'with several connections and none chosen, current() is null - never a guess');
check($C::currentCredentials() === ['', '', '', ''] && $C::stamp() === [], 'so nothing is signed and nothing is stamped by accident');
$inside = $C::using($C::byId($sub), static fn ($c) => [$c->id(), $C::current()->id(), $C::stamp()]);
check($inside === [$sub, $sub, [$FK => $sub, 'entities_id' => 3]], 'using() makes one connection current for the work it is given');
check($C::current() === null, 'and puts back what was current before');
try {
    $C::using($C::byId($ict), static function () { throw new RuntimeException('pull failed'); });
} catch (RuntimeException $e) {
}
check($C::current() === null, 'also when the work throws: one connection\'s failure cannot leak its key into the next');
$nested = $C::using($C::byId($ict), static fn () => $C::using($C::byId($sub), static fn () => $C::current()->id()) . '/' . $C::current()->id());
check($nested === "$sub/$ict", 'using() nests');

// each(): the cron loop
$visited = [];
$runs = $C::each(static function ($c) use (&$visited, $ict, $C) {
    $visited[] = [$c->id(), $C::current()->id(), $C::ownRows(), $C::ownRows('c')];
    if ($c->id() === $ict) {
        throw new RuntimeException('JumpServer unreachable');
    }
    return 'pulled';
});
check(array_column($visited, 0) === [$ict, $sub] && array_column($visited, 1) === [$ict, $sub],
      'each() runs the work once per connection, with that connection current');
check($visited[1][2] === [$FK => $sub] && $visited[1][3] === ["c.$FK" => $sub],
      'ownRows() keeps a query to the current connection\'s rows, with or without a table alias');
check($runs[$ict]['error'] === 'JumpServer unreachable' && $runs[$ict]['result'] === null,
      'a connection that throws is reported in its own entry');
check($runs[$sub]['error'] === '' && $runs[$sub]['result'] === 'pulled', 'and the next connection still runs');
check($C::current() === null && $C::ownRows() === [], 'afterwards nothing is current, and ownRows() adds no condition');

// label and cursor
check($C::byId($ict)->label() === 'ICT', 'a connection is called by its name');
$C::setCurrent(['id' => 9, 'url' => 'https://paman.example.com/']);
check($C::current()->label() === 'paman.example.com', 'or by the host of its URL when it has no name');
$C::setCurrent(['id' => 9]);
check($C::current()->label() === '#9', 'or by its id');
$C::reset();
check($C::byId($sub)->cursor('last_logs_pull') === null, 'a connection that never completed a pull has no cursor');
$C::byId($sub)->setCursor('last_logs_pull', '2026-10-05 10:00:00');
check($C::byId($sub)->cursor('last_logs_pull') === '2026-10-05 10:00:00' && $DB->tables[$T][1]['last_logs_pull'] === '2026-10-05 10:00:00',
      'setCursor() moves it, in the row and in memory');
check(($DB->tables[$T][0]['last_logs_pull'] ?? null) === null, 'for that connection only');

// grandfatherScope(): the upgrade keeps the links that already exist
$saved_connections = $DB->tables[$T];
$DB->tables[$T] = [];
$C::reset();
$C::save(null, ['name' => 'ICT'], [], 1, true);
$only = (int) $DB->tables[$T][0]['id'];
$DB->tables['glpi_computers'] = [
    ['id' => 10, 'entities_id' => 3],   // inside the scope (child of 1)
    ['id' => 11, 'entities_id' => 2],   // the sibling subsidiary
    ['id' => 12, 'entities_id' => 0],   // root
    ['id' => 13, 'entities_id' => 4],   // child of the sibling
];
$DB->tables['glpi_plugin_jumpserver_assetmap'] = [
    [$FK => $only, 'asset' => 'a', 'computers_id' => 10],
    [$FK => $only, 'asset' => 'b', 'computers_id' => 11],
    [$FK => $only, 'asset' => 'c', 'computers_id' => 12],
    [$FK => $only, 'asset' => 'd', 'computers_id' => 13],
    [$FK => $only, 'asset' => 'e', 'computers_id' => 0],
];
// FakeDB's join stand-in serves entitiesForAssets(); this query has another shape.
$real_request = null;
$DB->grandfather = static function () use ($DB, $only, $FK) {
    $out = [];
    foreach ($DB->tables['glpi_plugin_jumpserver_assetmap'] as $m) {
        foreach ($DB->tables['glpi_computers'] as $c) {
            if ((int) $m[$FK] === $only && (int) $c['id'] === (int) $m['computers_id']) {
                $out[$c['entities_id']] = ['entities_id' => $c['entities_id']];
            }
        }
    }
    return new ArrayIterator(array_values($out));
};
$report = $C::grandfatherScope();
sort($report['added']);
check($report['added'] === [2, 4], 'the entities of Computers already matched outside the scope become additional entities');
check($report['root'] === true, 'a matched Computer in root is reported, and root is NOT added (it would cover the whole instance)');
check($DB->tables[$T][0]['extra_entities'] === '2,4' || $DB->tables[$T][0]['extra_entities'] === '4,2', 'and they are stored on the connection');
check($C::byId($only)->entity() === 1, 'the owner is untouched');
$again = $C::grandfatherScope();
check($again['added'] === [], 'a second run adds nothing');
$C::save(null, ['name' => 'second'], [], 5, false);
check($C::grandfatherScope() === ['added' => [], 'root' => false], 'with several connections it does nothing at all: the scopes are the admin\'s');
$DB->grandfather = null;
$DB->tables[$T] = $saved_connections;
$C::reset();

// switching a connection off and on from Setup
$DB->queries = [];
check($C::save($sub, [], [], null, null, null, false) === '' && (int) $DB->tables[$T][1]['is_active'] === 0, 'a connection can be switched off');
check(!isset($C::all()[$sub]) && isset($C::every()[$sub]) && !$C::every()[$sub]->isActive(),
      'it leaves all() - nothing is pulled or matched through it - but Setup still lists it through every()');
check($C::forEntity(3)->id() === $ict, 'its entities are served by the covering connection again');
check($DB->queries !== [], 'and that is a change of scope, so the rows are decided again');
check($C::save(null, ['name' => 'dup'], [], 3) !== '', 'its entity still cannot be given to a second connection');
check($C::save($sub, ['name' => 'Sub renamed']) === '' && $DB->tables[$T][1]['name'] === 'Sub renamed', 'a switched-off connection can still be edited');
check($C::save($sub, [], [], null, null, null, true) === '' && isset($C::all()[$sub]) && $C::forEntity(3)->id() === $sub,
      'and switched back on');

// a switched-off connection serves nothing
$DB->tables[$T][1]['is_active'] = 0;
$C::reset();
check($C::forEntity(3)->id() === $ict && count($C::all()) === 1, 'an inactive connection is not loaded, and its entities fall back to the covering one');
$DB->tables[$T][1]['is_active'] = 1;
$C::reset();
// --- the wiring, read from the sources ----------------------------------------
$root   = __DIR__ . '/..';
$setup  = (string) file_get_contents("$root/setup.php");
$hook   = (string) file_get_contents("$root/hook.php");
$config = (string) file_get_contents("$root/inc/config.class.php");
$form   = (string) file_get_contents("$root/front/config.form.php");

check(preg_match("/PLUGIN_HOOKS\['secured_fields'\]\['integrasipaman'\]\s*=\s*PluginIntegrasipamanConnection::SECURED_FIELDS;.*if \(Plugin::isPluginActive/s", $setup) === 1,
      'setup.php registers both secret columns for security:changekey');
check($C::SECURED_FIELDS === ["$T.access_key_secret", "$T.pbi_db_password"], 'as table.column, the form core expects');
check(!str_contains($setup, 'secured_configs'), 'no secret is left in glpi_configs, so nothing is registered there');
check(preg_match("/include_once\(__DIR__ \. '\/inc\/connection\.class\.php'\);.*serverlabel\.class\.php/s", $setup) === 1,
      'the class is loaded on every request, before the label that reads its URL');

// install: table, columns, migration, then seeding
$at = static fn (string $needle) => strpos($hook, $needle);
check($at('CREATE TABLE `$connections_table`') !== false, 'install creates the connections table');
check(preg_match('/CREATE TABLE `\$connections_table`.*?UNIQUE KEY `entities_id` \(`entities_id`\)/s', $hook) === 1,
      'one connection per entity');
check($at('array_keys(PluginIntegrasipamanConnection::STAMPED_TABLES)') !== false && $at('ADD COLUMN `$connection_fk`') !== false,
      'install adds the connection and entity columns to every stamped table');
check($at('ADD COLUMN `$connection_fk`') < $at('PluginIntegrasipamanConnection::migrate()')
      && $at('PluginIntegrasipamanConnection::migrate()') < $at('array_diff_key(PluginIntegrasipamanConfig::CONFIG_DEFAULTS'),
      'in that order: columns, then the migration that stamps them, then the config seeding');
check(preg_match('/fieldExists\(\$stamped, \$connection_fk, false\)/', $hook) === 1,
      'the column check bypasses the per-request SHOW COLUMNS cache');
check(preg_match('/function plugin_integrasipaman_uninstall.*purgeLegacyConfig\(\).*DROP TABLE IF EXISTS/s', $hook) === 1,
      'uninstall removes the credentials');

preg_match('/public const CONFIG_DEFAULTS = \[(.*?)\n    \];/s', $config, $m);
$leftover = array_filter(array_merge(array_keys($C::LEGACY_KEYS), array_keys($C::LEGACY_SECRETS)),
                         static fn ($k) => str_contains($m[1] ?? '', "'$k'"));
check(isset($m[1]) && $leftover === [], 'no connection key is in CONFIG_DEFAULTS (install would seed it back): ' . implode(', ', $leftover));

// the Setup form
foreach (['jumpserver_access_key_secret', 'jumpserver_pbi_db_password'] as $name) {
    check(preg_match("/Html::input\('$name', \[(.*?)\]\);/s", $config, $m) === 1 && !str_contains($m[1], "'value'"),
          "the $name input is rendered without a value");
    check(!str_contains($config, "\$config_values['$name']"), "showFormConfig() never reads $name");
}
check(str_contains($config, "'name'   => 'connection_entities_id'") && str_contains($config, "Dropdown::showYesNo('connection_is_recursive'"),
      'the form shows the entity and the "child entities" switch, not a hidden entities_id');
check(str_contains($config, '_clear_jumpserver_pbi_db_password'), 'and still offers to clear the stored PBI password');

// the save controller
preg_match('/\$config_values = \[(.*?)\n    \];/s', $form, $m);
$leftover = array_filter(array_merge(array_keys($C::LEGACY_KEYS), array_keys($C::LEGACY_SECRETS)),
                         static fn ($k) => str_contains($m[1] ?? '', "'$k'"));
check(isset($m[1]) && $leftover === [], 'the controller writes no connection key to glpi_configs: ' . implode(', ', $leftover));
check(str_contains($form, 'PluginIntegrasipamanConnection::save('), 'it saves the connection through save()');
check(str_contains($form, 'Session::haveAccessToEntity($connection_entity)'), 'and never trusts the entity id from the request');
check(preg_match("/array_key_exists\('connection_entities_id', \\\$_POST\)/", $form) === 1,
      'a form rendered before this release (no entity field) keeps the owner instead of moving it to root');

// nothing reads a connection key out of a config array any more
$files = array_merge(glob("$root/*.php"), glob("$root/inc/*.php"), glob("$root/front/*.php"));
$keys  = implode('|', array_merge(array_keys($C::LEGACY_KEYS), array_keys($C::LEGACY_SECRETS)));
$stale = [];
foreach ($files as $file) {
    if (basename($file) === 'config.class.php') {
        continue; // $config_values[...] there is the form, filled from the connection
    }
    foreach (file($file) as $i => $line) {
        if (preg_match("/\\\$(config|cfg)\w*\[\s*'($keys)'\s*\]/", $line) === 1) {
            $stale[] = basename($file) . ':' . ($i + 1);
        }
    }
}
check($stale === [], 'no code reads a connection key out of a config array (' . implode(', ', $stale) . ')');
check(preg_match('/foreach \(PluginIntegrasipamanConnection::LEGACY_KEYS as \$field => \$column\)/', $config) === 1,
      'and the form fills those fields from the connection');
$uses = 0;
foreach ($files as $file) {
    if (basename($file) === 'connection.class.php') {
        continue;
    }
    $uses += preg_match_all('/PluginIntegrasipamanConnection::currentCredentials\(\)|\$connection->credentials\(\)/', (string) file_get_contents($file));
}
check($uses >= 8, "the callers sign with the connection's credentials ($uses call sites)");
check(!is_file("$root/inc/secret.class.php"), 'the 3.10.1 secret class is gone; nothing can read the old context by accident');

// every place that creates a row stamps it
$stampers = [
    'cron.class.php'         => 'PluginIntegrasipamanConnection::stampRows($rows)',
    'terminalpull.class.php' => 'PluginIntegrasipamanConnection::stampRows($rows)',
    'correlation.class.php'  => '] + PluginIntegrasipamanConnection::stamp())',
    'entitlement.class.php'  => '] + PluginIntegrasipamanConnection::stamp(),',
    'provisioning.class.php' => '] + PluginIntegrasipamanConnection::stamp());',
    'nodemap.class.php'      => '$stamp = PluginIntegrasipamanConnection::stamp();',
    'platformmap.class.php'  => '$stamp = PluginIntegrasipamanConnection::stamp();',
];
$want = ['terminalpull.class.php' => 2, 'correlation.class.php' => 2, 'entitlement.class.php' => 3];   // rules, mappings, the 'none' marker
foreach ($stampers as $file => $needle) {
    $n = substr_count((string) file_get_contents("$root/inc/$file"), $needle);
    check($n === ($want[$file] ?? 1), "$file stamps the rows it creates ($n)");
}
check(str_contains((string) file_get_contents("$root/inc/correlation.class.php"), 'PluginIntegrasipamanConnection::syncLinked();'),
      'and a correlation pass ends by moving linked rows to their Computer\'s entity');

// v4.0.0: the two pulling crons loop over the connections
$cron = (string) file_get_contents("$root/inc/cron.class.php");
$term = (string) file_get_contents("$root/inc/terminalpull.class.php");
foreach (['cron.class.php' => $cron, 'terminalpull.class.php' => $term] as $name => $src) {
    check(substr_count($src, 'PluginIntegrasipamanConnection::each(') === 1, "$name runs its pull once per connection");
    check(preg_match('/if \(\$run\[\'error\'\] !== \'\'\) \{\s*\$task->log\(/', $src) === 1,
          "$name logs a connection that threw and goes on to the next");
    check(!str_contains($src, 'PluginIntegrasipamanConnection::currentCredentials()') || $name === 'terminalpull.class.php',
          "$name signs with the credentials of the connection it was handed");
}
check(preg_match('/\$connection->cursor\(\'last_logs_pull\'\) \?\? \(\$task->fields\[\'lastrun\'\] \?\? null\)/', $cron) === 1,
      'the audit-log pull starts from the connection\'s own cursor, and from the task\'s lastrun until it has one (no re-pull on upgrade)');
check(preg_match('/if \(\$ok\) \{\s*\$connection->setCursor\(\'last_logs_pull\', \$run_start\);/', $cron) === 1,
      'and the cursor only moves after a pull that counts as a success, to the time that pull started');
check(str_contains($cron, "['source_id' => \$source_id] + PluginIntegrasipamanConnection::ownRows()"),
      'an audit-log entry is a duplicate only within its own connection');
check(str_contains($term, '[$id_col => array_keys($incoming_ids)] + PluginIntegrasipamanConnection::ownRows()'),
      'a command or session id is a duplicate only within its own connection');
check(preg_match('/\[\$date_col => \[\'<=\', \$end\]\],\s*\] \+ PluginIntegrasipamanConnection::ownRows\(\)/', $term) === 1,
      'a month filled by another connection is still an empty month for this one');
check(substr_count($term, 'self::prune($retention)') === 1 && strpos($term, 'self::prune($retention)') < strpos($term, 'private static function pullTerminalFor'),
      'the retention prune runs once per run, outside the per-connection pull');

// v4.0.0: correlation, evaluation, entitlement and provisioning work per connection
$corr = (string) file_get_contents("$root/inc/correlation.class.php");
$risk = (string) file_get_contents("$root/inc/riskeval.class.php");
$ent  = (string) file_get_contents("$root/inc/entitlement.class.php");
$prov = (string) file_get_contents("$root/inc/provisioning.class.php");
$act  = (string) file_get_contents("$root/inc/provisioningaction.class.php");

check(str_contains($corr, 'PluginIntegrasipamanConnection::each(static fn () => self::resolveCurrent($deadline))'),
      'the resolver runs once per connection, on a shared time budget');
check(preg_match_all('/FROM\' => self::(ASSETMAP|USERMAP|COMMANDS)_TABLE(?!, \'WHERE\' => PluginIntegrasipamanConnection::ownRows\(\))[^.]/', $corr) === 0,
      'every asset-map, user-map and command read in the resolver is kept to the current connection');
check(substr_count($corr, '+ $in_scope,') === 2 && substr_count($corr, '+ $in_scope_joined,') === 1,
      'an asset is matched only to a Computer inside the connection\'s scope: by name (twice) and by address');
check(str_contains($risk, 'PluginIntegrasipamanConnection::each(static fn () => self::evaluateCurrent($chunk, $deadline))'),
      'commands are evaluated per connection, each against its own maps');
check(substr_count($risk, '] + $mine,') === 2, 'the stale selection and the stale count are both kept to that connection');
check(substr_count($ent, 'PluginIntegrasipamanConnection::each(') === 1, 'entitlements are collected once per connection');
check(str_contains($ent, "['jms_id' => \$jms_id] + PluginIntegrasipamanConnection::ownRows()"), 'a rule id is unique within its connection');
check(str_contains($ent, "'entities_id' => \$connection->scope()") , 'a JumpServer host can only be a Computer inside the scope');
check(preg_match('/new QueryExpression\(\s*\$DB->quoteName\(\'r\.\' \. PluginIntegrasipamanConnection::FK\) \. \' = \' \. \$DB->quoteName\(\'m\.\'/', $ent) === 1,
      'the "Who has access" pane joins a mapping only to a rule of the same connection');
check(str_contains($prov, 'PluginIntegrasipamanConnection::forEntity((int) ($computer->fields[\'entities_id\'] ?? 0))'),
      'a Computer is queued for the JumpServer that serves its entity');
check(str_contains($prov, ": ['eligible' => false];"), 'and for none when connections exist and none serves it');
check(substr_count($act, 'self::withRowConnection(') === 2, 'sending to and removing from the bastion both run as the queue row\'s own connection');
check(preg_match('/if \(\$connection_id === 0 && count\(PluginIntegrasipamanConnection::all\(\)\) <= 1\) \{\s*return \$work\(\);/', $act) === 1,
      'only a row that names NO connection falls back to the single one; a row whose own connection is switched off is refused, not signed with another key');
foreach (['nodemap', 'platformmap'] as $class) {
    check(str_contains((string) file_get_contents("$root/inc/$class.class.php"), "'WHERE' => PluginIntegrasipamanConnection::ownRows()"),
          "$class: the lookup is the current connection's own mapping");
}

// the unique keys
foreach (['commands', 'sessions', 'permrules', 'assetmap', 'usermap', 'nodemap', 'platformmap'] as $t) {
    check(preg_match("/\['glpi_plugin_jumpserver_$t',\s+'\w+',\s+'\w+'\],/", $hook) === 1, "$t: its unique key is scoped to the connection");
}
check(str_contains($hook, 'ADD UNIQUE KEY `$scoped_key` (`$connection_fk`, `$scoped_col`)'), 'the connection is the first column of each');
check(preg_match('/\$DB->numrows\(\$key_parts\) !== 1\) \{\s*continue;/', $hook) === 1, 'a key that is already scoped is left alone (install runs on every upgrade)');
check($at('PluginIntegrasipamanConnection::migrate()') < $at("['glpi_plugin_jumpserver_commands',    'jms_id',"),
      'the keys are scoped after the rows have been given their connection');
check(str_contains($hook, 'connection_scope_grandfathered') && $at('connection_scope_grandfathered') < $at('PluginIntegrasipamanConnection::grandfatherScope()'),
      'existing links are grandfathered once, behind a marker read before the call');

// --- v4.0.0: what a signed-in user sees ------------------------------------------
// No isCommandLine() and no Session class exist in this file, so viewer() and
// canSee() behave here as they do for a browser request.
$_SESSION['glpiactiveentities'] = [6 => 6, 1 => 1];
check($C::viewer() === ['entities_id' => [6, 1]], 'viewer() keeps a screen to the entities the user is working in');
check($C::viewer('c') === ['c.entities_id' => [6, 1]], 'with a table alias when the query has one');
check($C::canSee(1) && !$C::canSee(22) && !$C::canSee(0), 'canSee() answers for a row loaded by id');
$_SESSION['glpiactiveentities'] = [];
check($C::viewer() === ['entities_id' => [-1]], 'a session with no active entity sees nothing - not everything');
check(!$C::canSee(0), 'and may open no row, the root\'s included');
unset($_SESSION['glpiactiveentities']);
check($C::viewer() === ['entities_id' => [-1]] && !$C::canSee(6), 'the same without the session key at all');

$_SESSION['glpiactiveentities'] = [3 => 3, 5 => 5];
$_SESSION['glpiactive_entity']  = 5;
check(array_keys($C::visible()) === [$sub], 'visible() lists only the connections owned by an entity the user is in');
check($C::forActiveEntity()->id() === $sub, 'forActiveEntity() is the connection serving the entity the user is working in');
$_SESSION['glpiactive_entity'] = 0;
check($C::forActiveEntity() === null, 'and null where none serves it - never "the" connection');
$C::choose($C::byId($ict));
check($C::current()->id() === $ict, 'choose() makes a connection current for the rest of the request');
$C::choose(null);
check($C::current() === null, 'and choose(null) undoes it');
unset($_SESSION['glpiactiveentities'], $_SESSION['glpiactive_entity']);

function isCommandLine() { return $GLOBALS['CLI'] ?? false; }
$GLOBALS['CLI'] = true;
check($C::viewer() === [] && $C::viewer('c') === [] && $C::canSee(22),
      'cron and CLI are never restricted (or only the root\'s data would be pulled and resolved)');
$GLOBALS['CLI'] = false;

// every session-driven read is restricted, and a count by the same clause as its rows
$src = static fn (string $rel) => (string) file_get_contents("$root/$rel");
$expect = [
    'inc/report.class.php'        => 3,   // the one period clause, the month list, the asset links
    'inc/dashboard.class.php'     => 3,   // count, grouped count, rows
    'inc/accesshistory.class.php' => 5,
    'inc/useraccess.class.php'    => 4,   // the sessions and commands; NOT the name lookup, see the class
    'inc/entitlement.class.php'   => 2,
    'front/correlation.php'       => 4,
    'front/provisioning.php'      => 2,   // the tab counts and the table
];
foreach ($expect as $rel => $n) {
    $got = substr_count($src($rel), 'PluginIntegrasipamanConnection::viewer(');
    check($got === $n, "$rel restricts its $n read(s) to the viewer's entities (got $got)");
}
$report = $src('inc/report.class.php');
check(preg_match('/private static function periodWhere\(array \$period\): array\s*\{.*?\] \+ PluginIntegrasipamanConnection::viewer\(\'c\'\);\s*\}/s', $report) === 1,
      'the report restricts in periodWhere(), the one clause its KPIs, tabs, totals and exports are all built on');
check(substr_count($report, 'self::periodWhere($period)') >= 5, 'and they do all go through it (' . substr_count($report, 'self::periodWhere($period)') . ' uses)');

// the ingest side must NOT use it
foreach (['inc/cron.class.php', 'inc/terminalpull.class.php', 'inc/riskeval.class.php', 'inc/correlation.class.php', 'inc/provisioning.class.php'] as $rel) {
    check(!str_contains($src($rel), 'PluginIntegrasipamanConnection::viewer('), "$rel is ingest or correlation: never restricted by a session");
}

// rows opened by id
$page = $src('front/provisioning.php');
check(substr_count($page, 'jumpserver_provisioning_row(') >= 5 && preg_match('/\$row->getFromDB\(\$row_id\)\s*\|\|\s*!PluginIntegrasipamanConnection::canSee\(/', $page) === 1,
      'the provisioning page loads a row from the request only through the entity-checked helper');
check(!preg_match('/new \$P\(\);\s*if \(\$\w+->getFromDB\(\$row_id\)/', $page), 'no branch reads a posted row id directly any more');
check(str_contains($page, "jumpserver_provisioning_row((int) \$_POST['row']) === null"), 'a removal is refused for a row that is not the viewer\'s');
foreach (['assetmap', 'usermap', 'nodemap', 'platformmap'] as $form) {
    check(preg_match('/\$map->check\(\(?(int\) )?\$?\w*.*, READ\);/', $src("front/$form.form.php")) === 1, "$form.form.php checks the row before showing it, not only the right");
}
foreach (['nodemap', 'platformmap'] as $form) {
    $f = $src("front/$form.form.php");
    check(str_contains($f, 'PluginIntegrasipamanConnection::choose(PluginIntegrasipamanConnection::forActiveEntity());')
          && str_contains($f, 'PluginIntegrasipamanConnection::byId((int) ($map->fields[PluginIntegrasipamanConnection::FK] ?? 0))'),
          "$form.form.php works as the row's connection, or for a new row the one serving the admin's entity");
    check(preg_match('/if \(\$stamp === \[\] && PluginIntegrasipamanConnection::all\(\) !== \[\]\) \{.*?return false;/s', $src("inc/$form.class.php")) === 1,
          "$form: a mapping that would belong to no connection is refused");
}
check(str_contains($src('front/pbi.php'), '$pbi_connection = PluginIntegrasipamanConnection::forActiveEntity();')
      && !str_contains($src('front/pbi.php'), 'PluginIntegrasipamanConnection::current()'),
      'PBI Insights shows the reporting database of the connection serving the viewer\'s entity, never simply "the" connection');
check(str_contains($src('front/backfill.php'), 'PluginIntegrasipamanConnection::choose(PluginIntegrasipamanConnection::forActiveEntity());'),
      'the back-fill pulls the JumpServer of the admin\'s entity');
check(str_contains($src('inc/pamanlists.class.php'), "\$cache_key .= '_' . \$connection->id();"), 'the node / platform list cache is per connection');

// the Setup page: several connections
// ($form was reused as a loop variable above; read the controller again.)
$form = (string) file_get_contents("$root/front/config.form.php");
check(str_contains($config, '[$connection_is_new, $connection] = self::editedConnection();') && !str_contains($config, 'PluginIntegrasipamanConnection::current()'),
      'the Setup form edits a chosen connection, never simply "the" connection');
check(preg_match('/function editedConnection\(\): array\s*\{\s*\$visible = PluginIntegrasipamanConnection::visible\(true\);/', $config) === 1,
      'chosen among the connections owned by an entity the admin is working in, switched-off ones included');
check(str_contains($config, "Html::hidden('connection_id', ['value' => \$connection_is_new ? 'new' : (string) \$connection->id()])"),
      'the form says which connection it is for');
check(str_contains($config, "'connection_extra_entities'") && str_contains($config, "Html::hidden('connection_extra_entities_shown'")
      && str_contains($config, "Dropdown::showYesNo('connection_is_active'") && str_contains($config, "Html::input('connection_name'"),
      'and has the name, the additional entities (with a marker, since an emptied multi-select posts nothing) and the on/off switch');
check(str_contains($config, "(int) (\$_SESSION['glpiactive_entity'] ?? 0)") && !str_contains($config, 'defaultEntity()'),
      'a new connection starts in the entity the admin is working in - the guessed default is gone from the form');
check(preg_match('/if \(Session::haveAccessToEntity\(\(int\) \$e\[\'id\'\]\)\) \{\s*\$coverable/', $config) === 1,
      'only entities the admin has access to are offered as additional entities');
check(str_contains($form, "PluginIntegrasipamanConnection::visible(true)[(int) \$posted_connection] ?? null")
      && !str_contains($form, 'PluginIntegrasipamanConnection::current()'),
      'the controller saves the posted connection, and only one the admin may see');
check(preg_match('/foreach \(\$connection_extra as \$extra_entity\) \{\s*if \(!Session::haveAccessToEntity\(\$extra_entity\)\)/', $form) === 1,
      'it never trusts an additional entity id from the request either');
check(str_contains($form, "!empty(\$_POST['connection_extra_entities_shown'])"), 'and keeps the additional entities when the field was not on the form');
check(preg_match("/isset\(\\\$_GET\['select_connection'\]\).*?Html::redirect/s", $form) === 1, 'choosing a connection only records the choice and redirects');
check(str_contains((string) file_get_contents("$root/inc/serverlabel.class.php"), 'PluginIntegrasipamanConnection::current() ?? PluginIntegrasipamanConnection::forActiveEntity()'),
      'a screen names the JumpServer of the connection serving the viewer\'s entity');

// core restricts the four lists and their forms: the 3.11.0 override is gone
foreach (['assetmap', 'usermap', 'nodemap', 'platformmap', 'provisioning'] as $class) {
    check(!str_contains($src("inc/$class.class.php"), 'function isEntityAssign'),
          "$class: nothing stops core from treating it as entity-owning (Search::show() and can() now check the entity)");
}
echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
