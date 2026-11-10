<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/rulesettings_test.php
//
// v4.4.0: the rules that decide what a command, an asset and a Computer mean
// are kept per connection. A connection has its own, or follows the connection
// above it (the nearest one with "child entities" on), or the instance defaults.
//
// What goes wrong quietly, and is pinned here:
//   - a connection reads another's rules, or the instance's, when it has its own;
//   - a connection that changed nothing no longer behaves as before the upgrade;
//   - a connection under one that did NOT tick "child entities" inherits from it;
//   - the nearest connection does not win over a farther one;
//   - the evaluation hash is the same for two connections with different rules,
//     so a change to one connection's rules never re-evaluates its history;
//   - the Setup form releases (or overwrites) rules it was never showing;
//   - an unknown key, or a value from the request, reaches the stored JSON.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
define('READ', 1);
define('UPDATE', 2);
function __($s, $d = null) { return $s; }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }

class CommonGLPI {}
class CommonDBTM {}
// The instance defaults as stored in glpi_configs: what the admin saved on the
// "Defaults for all connections" form. Anything not here is the shipped seed.
class Config
{
    public static array $stored = [];
    public static function getConfigurationValues($context, $keys = []) { return self::$stored; }
}
// What riskeval asks the correlation class for; the maps are not the subject here.
class PluginIntegrasipamanCorrelation
{
    public static function assetClassMap(): array { return []; }
    public static function userAdminMap(): array { return []; }
    public static function mapsGeneration(): int { return 1; }
}

class FakeDB
{
    public array $connections = [];
    public array $entities = [];
    public array $updates = [];
    public bool $refuse = false;
    public function tableExists($t) { return true; }
    public function request($q)
    {
        $from = trim(explode(' AS ', (string) $q['FROM'])[0]);
        if ($from === 'glpi_entities') { return new ArrayIterator($this->entities); }
        $rows = array_values(array_filter($this->connections, static function ($r) use ($q) {
            foreach ($q['WHERE'] ?? [] as $k => $v) { if (($r[$k] ?? null) != $v) { return false; } }
            return true;
        }));
        return new ArrayIterator($rows);
    }
    public function update($table, $data, $where)
    {
        $this->updates[] = [$table, $data, $where];
        if ($this->refuse) { return false; }
        foreach ($this->connections as &$r) { if ((int) $r['id'] === (int) $where['id']) { $r = $data + $r; } }
        return true;
    }
    public function error() { return 'refused'; }
}

/** Entities: 0 root; 1 Group under root; 2 ICT under 1; 3 SubA under 2; 4 OT under 1; 5 Other under 0. */
function world(array $connections): void
{
    global $DB;
    $DB = new FakeDB();
    $DB->entities    = [
        ['id' => 0, 'entities_id' => null], ['id' => 1, 'entities_id' => 0], ['id' => 2, 'entities_id' => 1],
        ['id' => 3, 'entities_id' => 2], ['id' => 4, 'entities_id' => 1], ['id' => 5, 'entities_id' => 0],
    ];
    $DB->connections = $connections;
    PluginIntegrasipamanConnection::reset();
    Config::$stored = [];
}
function conn(int $id, int $entity, int $recursive, array $rules = [], string $extra = '', int $active = 1): array
{
    return [
        'id' => $id, 'name' => "C$id", 'entities_id' => $entity, 'is_recursive' => $recursive, 'extra_entities' => $extra,
        'is_active' => $active, 'rules' => $rules === [] ? null : json_encode($rules), 'url' => "https://c$id.example",
    ];
}

require __DIR__ . '/../inc/rulesettings.class.php';
require __DIR__ . '/../inc/riskeval.class.php';
require __DIR__ . '/../inc/provisioning.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$S = 'PluginIntegrasipamanRuleSettings';
$C = 'PluginIntegrasipamanConnection';
$seed = PluginIntegrasipamanConfig::CONFIG_DEFAULTS;

// --- what is per connection --------------------------------------------------------
check(count($S::keys()) === 18 && count(array_unique($S::keys())) === 18, 'eighteen settings (4.14.0: platform families), none listed twice');
foreach ($S::keys() as $key) {
    check(array_key_exists($key, $seed), "$key has a shipped default, so a connection with nothing stored still has a value");
}
check(!in_array('correlation_maps_generation', $S::keys(), true) && !in_array('jumpserver_terminal_retention_months', $S::keys(), true)
      && !in_array('provisioning_cache_nodes', $S::keys(), true),
      'the generation counter, the retention months and the caches stay instance-wide');
check($S::sectionOf('jumpserver_admin_users') === 'risk' && $S::sectionOf('correlation_match_mode') === 'correlation'
      && $S::sectionOf('provisioning_ip_prefixes') === 'provisioning' && $S::sectionOf('jumpserver_terminal_retention_months') === null,
      'each setting is in one group, and an instance-wide one in none');

// --- the instance defaults, and a connection that changed nothing ------------------------
world([conn(1, 1, 1), conn(2, 3, 0)]);
check($S::forConnection(null) === array_intersect_key($seed, array_flip($S::keys())), 'no connection: the shipped defaults');
Config::$stored = ['jumpserver_admin_users' => 'Saved Default(saved.default)', 'correlation_match_mode' => 'hybrid'];
check($S::forConnection(null)['jumpserver_admin_users'] === 'Saved Default(saved.default)' && $S::forConnection(null)['correlation_match_mode'] === 'hybrid',
      'what the admin saved as the defaults wins over the shipped ones');
check($S::forConnection($C::byId(1)) === $S::forConnection(null) && $S::forConnection($C::byId(2)) === $S::forConnection(null),
      'a connection that stored nothing is the defaults: the upgrade changes no verdict');

// --- own values ------------------------------------------------------------------------------
world([
    conn(1, 1, 1, ['jumpserver_admin_users' => 'Group Admin(group.admin)', 'jumpserver_risk_keywords' => 'sudo']),
    conn(2, 3, 0),
    conn(3, 4, 0, ['jumpserver_risk_keywords' => 'rm -rf']),
    conn(4, 5, 1),
]);
Config::$stored = ['jumpserver_admin_users' => 'Saved Default(saved.default)'];
$c1 = $S::forConnection($C::byId(1));
check($c1['jumpserver_admin_users'] === 'Group Admin(group.admin)' && $c1['jumpserver_risk_keywords'] === 'sudo', 'a connection reads its own values');
check($c1['correlation_match_mode'] === $seed['correlation_match_mode'], 'and the defaults for what it did not keep');
$c4 = $S::forConnection($C::byId(4));
check($c4['jumpserver_admin_users'] === 'Saved Default(saved.default)', 'a connection in another branch of the tree does not see them');

// --- inheritance ---------------------------------------------------------------------------------
check($C::byId(2)->inheritsFrom()?->id() === 1, 'SubA, under ICT under Group, follows the Group connection: it has child entities on');
$c2 = $S::forConnection($C::byId(2));
check($c2['jumpserver_admin_users'] === 'Group Admin(group.admin)' && $c2['jumpserver_risk_keywords'] === 'sudo', 'and reads what it keeps');
$c3 = $S::forConnection($C::byId(3));
check($c3['jumpserver_risk_keywords'] === 'rm -rf' && $c3['jumpserver_admin_users'] === 'Group Admin(group.admin)',
      'a connection that keeps one setting overrides only that one and follows the rest');
check($C::byId(1)->inheritsFrom() === null && $C::byId(4)->inheritsFrom() === null, 'a connection with nothing above it follows the defaults');

world([conn(1, 1, 0, ['jumpserver_admin_users' => 'Group Admin(group.admin)']), conn(2, 3, 0)]);
check($C::byId(2)->inheritsFrom() === null && $S::forConnection($C::byId(2))['jumpserver_admin_users'] === $seed['jumpserver_admin_users'],
      'under a connection WITHOUT child entities, nothing is inherited');

world([
    conn(1, 1, 1, ['jumpserver_admin_users' => 'Group(group)', 'jumpserver_risk_keywords' => 'one']),
    conn(4, 2, 1, ['jumpserver_admin_users' => 'Ict(ict)']),
    conn(2, 3, 0),
]);
check($C::byId(2)->inheritsFrom()?->id() === 4, 'the nearest connection above is the one followed');
$near = $S::forConnection($C::byId(2));
check($near['jumpserver_admin_users'] === 'Ict(ict)' && $near['jumpserver_risk_keywords'] === 'one',
      'the nearest wins for what it keeps, and a farther one still supplies what the nearest does not');
check(array_map(static fn ($c) => $c->id(), $S::chain($C::byId(2))) === [2, 4, 1], 'the chain is itself, then up');

world([conn(1, 1, 1, ['jumpserver_admin_users' => 'Group(group)']), conn(2, 3, 0, [], '', 0)]);
check($C::byId(2) === null, 'a switched-off connection is not among the active ones');
world([conn(1, 1, 1, ['jumpserver_admin_users' => 'Group(group)'], '', 0), conn(2, 3, 0)]);
check($C::byId(2)->inheritsFrom() === null, 'and a switched-off connection above is not followed');

world([conn(1, 1, 1, ['jumpserver_admin_users' => 'Shared(shared)'], '5'), conn(2, 5, 0)]);
check($C::byId(2)->inheritsFrom() === null, 'an entity listed as additional is not a child: its own tree decides');

// --- where a group comes from ----------------------------------------------------------------------
world([conn(1, 1, 1, ['jumpserver_admin_users' => 'G(g)']), conn(2, 3, 0, ['jumpserver_risk_keywords' => 'k']), conn(3, 4, 0)]);
check($S::sourceOf($C::byId(2), 'risk')['own'] === true, 'a group is its own when the connection stores any of its keys');
check($S::sourceOf($C::byId(3), 'risk')['own'] === false && $S::sourceOf($C::byId(3), 'risk')['from']?->id() === 1, 'otherwise it says whom it follows');
check($S::sourceOf($C::byId(3), 'provisioning')['own'] === false && $S::sourceOf($C::byId(3), 'provisioning')['from'] === null, 'and "the defaults" when nobody above keeps that group');

// --- what the form stores -----------------------------------------------------------------------------
$posted = ['jumpserver_risk_keywords' => 'sudo', 'jumpserver_admin_users' => 'A(a)', 'correlation_match_mode' => 'hybrid', 'provisioning_eligible_types' => '3,4'];
check($S::mapToStore($posted, []) === [], 'no group switched on: nothing is kept, the connection follows again');
check($S::mapToStore($posted, ['risk']) === ['jumpserver_risk_keywords' => 'sudo', 'jumpserver_admin_users' => 'A(a)'], 'a group switched on takes its posted values and only those');
check(array_keys($S::mapToStore($posted, ['risk', 'provisioning'])) === ['jumpserver_risk_keywords', 'jumpserver_admin_users', 'provisioning_eligible_types'],
      'two groups, and a key the form did not post (an old page) is simply not stored');
check($S::mapToStore($posted, ['nonsense']) === [], 'a group name from the request that does not exist stores nothing');

// --- saveRules ------------------------------------------------------------------------------------------
world([conn(1, 1, 1), conn(2, 3, 0)]);
check($C::saveRules(1, ['jumpserver_admin_users' => 'X(x)', 'not_a_setting' => 'evil', 'jumpserver_risk_keywords' => ['array']]) === '', 'a save succeeds');
check($C::byId(1)->rules() === ['jumpserver_admin_users' => 'X(x)'], 'only known keys with a plain value are stored');
check(json_decode($DB->updates[0][1]['rules'], true) === ['jumpserver_admin_users' => 'X(x)'], 'as JSON in the rules column');
check($C::saveRules(1, []) === '' && $DB->updates[1][1]['rules'] === null && $C::byId(1)->rules() === [], 'an empty map clears it (NULL), and the connection follows again');
check($C::saveRules(99, ['jumpserver_admin_users' => 'X']) === 'no such connection' && count($DB->updates) === 2, 'an unknown connection is refused and nothing is written');
$DB->refuse = true;
check(str_contains($C::saveRules(2, ['jumpserver_admin_users' => 'X']), 'refused'), 'a database failure is reported, not hidden');
check($C::byId(1)->rules() === [] , 'and a connection whose JSON is damaged reads as keeping nothing');
world([array_merge(conn(1, 1, 1), ['rules' => '{not json'])]);
check($C::byId(1)->rules() === [], 'a rules column that is not JSON is read as empty, without a fatal');

// --- the code that applies the rules reads the CURRENT connection's -------------------------------------
world([
    conn(1, 1, 1, ['jumpserver_risk_keywords' => 'alpha', 'provisioning_eligible_types' => '7', 'provisioning_ticket_statuses' => '6']),
    conn(2, 3, 0, ['jumpserver_risk_keywords' => 'beta']),
    conn(3, 4, 0),
]);
Config::$stored = ['jumpserver_risk_keywords' => 'gamma', 'provisioning_eligible_types' => '9'];
$R = 'PluginIntegrasipamanRiskEval';
$P = 'PluginIntegrasipamanProvisioning';

$rules = [];
foreach ([1, 2, 3] as $id) {
    $rules[$id] = $C::using($C::byId($id), static fn () => $R::loadRules());
}
check($rules[1]['keywords'] === ['alpha'] && $rules[2]['keywords'] === ['beta'] && $rules[3]['keywords'] === ['alpha'],
      'loadRules() reads the connection it runs as: its own, or the one it follows (3 follows 1)');
check($R::rulesHash($rules[1]) !== $R::rulesHash($rules[2]), 'two connections with different rules have different hashes, so a change to one re-evaluates only its own history');
check($R::rulesHash($rules[1]) === $R::rulesHash($rules[3]), 'and a connection that follows the same rules has the same hash');
check($R::loadRules()['keywords'] === ['gamma'] || count($C::all()) !== 1, 'with several connections and none chosen, loadRules() reads the defaults');

check($P::criteriaFromConfig($C::byId(1))['types'] === [7] && $P::criteriaFromConfig($C::byId(2))['types'] === [7] && $P::criteriaFromConfig(null)['types'] === [9],
      'the provisioning criteria are the connection\'s, the one it follows, or the defaults');
check($P::criteriaFromConfig($C::byId(1))['ticket_statuses'] === [6], 'including the ticket statuses that approve a removal');
check($C::using($C::byId(1), static fn () => $P::criteriaFromConfig())['types'] === [7], 'with none given, the connection the code runs as');

// --- the sources of the instance-wide read are gone ------------------------------------------------------
$root = dirname(__DIR__);
foreach (['inc/riskeval.class.php', 'inc/provisioning.class.php'] as $file) {
    $src = (string) file_get_contents("$root/$file");
    check(!preg_match('/Config::getConfigurationValues\(PluginIntegrasipamanConfig::CONFIG_CONTEXT\);\s*\n\s*\n?\s*(return|\$ids)/', $src),
          "$file no longer reads the rule keys from the instance config directly");
}
$corr = (string) file_get_contents("$root/inc/correlation.class.php");
check(str_contains($corr, '$cfg  = PluginIntegrasipamanRuleSettings::current();'), 'the correlation resolver reads the current connection\'s rules');
check(preg_match('/\$criteria_for\[\$criteria_key\]\s*\?\?=\s*self::criteriaFromConfig\(\$serving\)/', (string) file_get_contents("$root/inc/provisioning.class.php")) === 1,
      'the provisioning scan reads the criteria once per serving connection, not once for the sweep');

// --- the form and the controller ---------------------------------------------------------------------------
$config = str_replace("\r\n", "\n", (string) file_get_contents("$root/inc/config.class.php"));
$form   = str_replace("\r\n", "\n", (string) file_get_contents("$root/front/config.form.php"));
check(str_contains($config, 'PluginIntegrasipamanRuleSettings::forConnection($connection)') && str_contains($config, 'PluginIntegrasipamanRuleSettings::defaults()'),
      'the rule tabs show the edited connection\'s effective values, and the defaults for the defaults form and a new connection');
check(substr_count($config, 'self::renderRuleToggle(') === 3, 'each of the three rule tabs has its switch');
check(str_contains($config, 'name="rule_own[') && str_contains($config, "Html::hidden('rule_own_shown'"), 'the switches are posted, and the form says it carries them');
check(str_contains($config, "if (\$defaults_mode) {\n            unset(\$sections['connection'], \$sections['pbi']);"), 'the defaults form has no connection or PBI tab');
check(preg_match('/if \(\$connection !== null && !empty\(\$_POST\[\'rule_own_shown\'\]\)\)/', $form) === 1,
      'rules are only released or stored when the page carried the switches (a page from before 4.4.0 cannot wipe them)');
$defaults_write = strpos($form, 'CONFIG_CONTEXT, $config_values + $rule_values');
check(preg_match('/Config::setConfigurationValues\(PluginIntegrasipamanConfig::CONFIG_CONTEXT, \$config_values\);/', $form) === 1
      && substr_count($form, '$config_values + $rule_values') === 1
      && $defaults_write !== false && $defaults_write < strpos($form, '$posted_connection = '),
      'the rule lists are written to the instance defaults only by the defaults branch, which runs before any connection is read');
check(preg_match('/=== \'defaults\'\) \{\s*if \(!PluginIntegrasipamanConfig::canEditDefaults\(\)\)/', $form) === 1,
      'the defaults are changed only by someone who may change them for everyone');
check(preg_match('/PluginIntegrasipamanConnection::saveRules\(\s*\$connection->id\(\),/', $form) === 1, 'a connection\'s rules are written to that connection, by id from the validated row');
check(str_contains($config, "Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE)\n            && Session::haveAccessToEntity(0)"), 'canEditDefaults() needs UPDATE and the root entity');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
