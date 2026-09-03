<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/correlation_resolver_test.php

error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
$_SESSION['glpi_currenttime'] = '2026-08-31 00:00:00';

if (!function_exists('mb_strrpos'))   { function mb_strrpos($h, $n, $o = 0, $e = null) { return strrpos($h, $n, $o); } }
if (!function_exists('mb_substr'))    { function mb_substr($s, $start, $length = null, $e = null) { return $length === null ? substr((string) $s, $start) : substr((string) $s, $start, $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }

class CommonGLPI {}
class CommonDBTM {}

class PluginIntegrasipamanRiskEval {
    public static function extractIp(string $s): string {
        return preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $s, $m) ? $m[1] : '';
    }
    public static function parseList(string $raw, bool $k = false): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) {
            $p = trim($l);
            if ($p === '' || $p[0] === '#') continue;
            $out[] = $p;
        }
        return $out;
    }
}

class Config {
    public static array $vals = [];
    public static function getConfigurationValues($ctx) { return self::$vals; }
    public static function setConfigurationValues($ctx, array $v) { self::$vals = array_merge(self::$vals, $v); }
}

class FakeDB {
    public array $tables = [];
    public array $updates = [];
    public array $inserts = [];
    public function request($q) {
        $from = is_array($q['FROM'] ?? null) ? implode(',', $q['FROM']) : (string) ($q['FROM'] ?? '');
        if (str_contains($from, 'commands')) {
            $col = is_array($q['SELECT'] ?? null) ? ($q['SELECT'][0] ?? '') : '';
            $key = str_contains((string) $col, 'asset') ? 'distinct_asset' : 'distinct_user';
            return new ArrayIterator($this->tables[$key] ?? []);
        }
        if (str_contains($from, 'assetmap'))    return new ArrayIterator($this->tables['assetmap'] ?? []);
        if (str_contains($from, 'usermap'))     return new ArrayIterator($this->tables['usermap'] ?? []);
        if (str_contains($from, 'ipnetworks'))  return new ArrayIterator($this->tables['ipnetworks'] ?? []);
        if (str_contains($from, 'addressing'))  return new ArrayIterator($this->tables['addressing'] ?? []);
        if (str_contains($from, 'ipaddresses')) {
            $w = $q['WHERE'] ?? [];
            if (($w['name'] ?? null) !== null)  return new ArrayIterator($this->tables['ipaddr_by_ip'] ?? []);
            return new ArrayIterator($this->tables['ipaddr_by_computer'] ?? []);
        }
        if (str_contains($from, 'computers'))    return new ArrayIterator($this->tables['computers'] ?? []);
        if (str_contains($from, 'groups_users')) return new ArrayIterator($this->tables['groups_users'] ?? []);
        if (str_contains($from, 'users'))        return new ArrayIterator($this->tables['users'] ?? []);
        return new ArrayIterator([]);
    }
    public function insert($t, $v) { $this->inserts[] = [$t, $v]; return true; }
    public function update($t, $v, $w) { $this->updates[] = [$t, $v, $w]; return true; }
    public function error() { return ''; }
    public function tableExists($t) { return isset($this->tables['addressing']); }
}

require __DIR__ . '/../inc/correlation.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$C = 'PluginIntegrasipamanCorrelation';

function run_asset(array $settings, array $tables): array {
    global $C;
    Config::$vals = $settings;
    $db = new FakeDB();
    $db->tables = $tables + ['distinct_asset' => [], 'distinct_user' => [], 'usermap' => []];
    $GLOBALS['DB'] = $db;
    $C::resolveAll();
    $u = $db->updates[0][1] ?? [];
    return ['method' => $u['resolve_method'] ?? '(none)', 'prod' => $u['is_production'] ?? '(none)'];
}

$prod_net = [['id' => 7, 'address' => '10.0.0.0', 'netmask' => '255.255.255.0']];
$settings_ipnet = [
    'correlation_match_mode' => 'ip_only',
    'correlation_production_ipnetworks' => '7',
    'correlation_admin_groups' => '',
    'jumpserver_production_ip_prefixes' => "10.99.99.\n",
    'jumpserver_admin_users' => '',
    'correlation_maps_generation' => '0',
];

$r = run_asset($settings_ipnet, [
    'assetmap' => [['id' => 1, 'asset' => 'web01(10.0.0.5)', 'parsed_name' => 'web01', 'parsed_ip' => '10.0.0.5', 'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'ipnet', 'prod' => 1], "ip_only + IP in prod net -> ipnet/1 (got " . json_encode($r) . ")");

$s = $settings_ipnet; $s['jumpserver_production_ip_prefixes'] = "10.200.0.\n";
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'x(10.200.0.9)', 'parsed_name' => 'x', 'parsed_ip' => '10.200.0.9', 'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'prefix_fallback', 'prod' => 1], "prefix fallback -> prefix_fallback/1 (got " . json_encode($r) . ")");

$r = run_asset($settings_ipnet, [
    'assetmap' => [['id' => 1, 'asset' => 'y(172.16.0.4)', 'parsed_name' => 'y', 'parsed_ip' => '172.16.0.4', 'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'unresolved', 'prod' => 0], "no match -> unresolved/0 (got " . json_encode($r) . ")");

// IP Addressing plugin range: parsed IP inside begin_ip..end_ip -> addr_range/1
$s = $settings_ipnet;
$s['correlation_production_ipnetworks'] = '';           // no core IPNetwork
$s['correlation_production_addressings'] = '9';         // "Production OT" range
$s['jumpserver_production_ip_prefixes'] = '';
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'ot01(10.0.18.40)', 'parsed_name' => 'ot01', 'parsed_ip' => '10.0.18.40', 'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => [], 'ipaddr_by_ip' => [], 'computers' => [],
    'addressing' => [['id' => 9, 'begin_ip' => '10.0.18.0', 'end_ip' => '10.0.19.254']],
]);
check($r === ['method' => 'addr_range', 'prod' => 1], "IP in addressing range -> addr_range/1 (got " . json_encode($r) . ")");

// IP just outside the addressing range -> unresolved
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'x(10.0.20.1)', 'parsed_name' => 'x', 'parsed_ip' => '10.0.20.1', 'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => [], 'ipaddr_by_ip' => [], 'computers' => [],
    'addressing' => [['id' => 9, 'begin_ip' => '10.0.18.0', 'end_ip' => '10.0.19.254']],
]);
check($r === ['method' => 'unresolved', 'prod' => 0], "IP outside addressing range -> unresolved/0 (got " . json_encode($r) . ")");

$s = $settings_ipnet; $s['correlation_match_mode'] = 'name_only';
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'web01(192.168.1.9)', 'parsed_name' => 'web01', 'parsed_ip' => '192.168.1.9', 'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [['id' => 42]],
    'ipaddr_by_computer' => [['name' => '10.0.0.50']],
]);
check($r === ['method' => 'computer_ip', 'prod' => 1], "name_only + computer IP in prod net -> computer_ip/1 (got " . json_encode($r) . ")");

// is_manual row is never written
Config::$vals = $settings_ipnet;
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 9, 'asset' => 'z(1.1.1.1)', 'parsed_name' => 'z', 'parsed_ip' => '1.1.1.1', 'is_manual' => 1, 'is_production' => 0]],
    'ipnetworks' => $prod_net, 'distinct_asset' => [], 'distinct_user' => [], 'usermap' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check($db->updates === [], "is_manual=1 asset row is not written by resolveAll()");

// user: group hit
Config::$vals = ['correlation_match_mode' => 'ip_only', 'correlation_production_ipnetworks' => '',
    'correlation_admin_groups' => '4', 'jumpserver_production_ip_prefixes' => '', 'jumpserver_admin_users' => '', 'correlation_maps_generation' => '0'];
$db = new FakeDB();
$db->tables = ['assetmap' => [], 'distinct_asset' => [], 'distinct_user' => [],
    'usermap' => [['id' => 1, 'jms_user' => 'A(alice)', 'parsed_login' => 'alice', 'is_manual' => 0, 'is_admin' => 0]],
    'users' => [['id' => 15]], 'groups_users' => [['c' => 1]]];
$GLOBALS['DB'] = $db;
$C::resolveAll();
$uu = $db->updates[0][1] ?? [];
check(($uu['is_admin'] ?? null) === 1 && ($uu['resolve_method'] ?? null) === 'group', "user in admin group -> group/1 (got " . json_encode($uu) . ")");

// user: no group, name fallback
Config::$vals = ['correlation_match_mode' => 'ip_only', 'correlation_production_ipnetworks' => '',
    'correlation_admin_groups' => '4', 'jumpserver_production_ip_prefixes' => '',
    'jumpserver_admin_users' => "A(alice)\n", 'correlation_maps_generation' => '0'];
$db = new FakeDB();
$db->tables = ['assetmap' => [], 'distinct_asset' => [], 'distinct_user' => [],
    'usermap' => [['id' => 1, 'jms_user' => 'A(alice)', 'parsed_login' => 'alice', 'is_manual' => 0, 'is_admin' => 0]],
    'users' => [['id' => 15]], 'groups_users' => [['c' => 0]]];
$GLOBALS['DB'] = $db;
$C::resolveAll();
$uu = $db->updates[0][1] ?? [];
check(($uu['is_admin'] ?? null) === 1 && ($uu['resolve_method'] ?? null) === 'name_fallback', "user name fallback -> name_fallback/1 (got " . json_encode($uu) . ")");

// discovery inserts a new distinct asset, dedup key normalises case
Config::$vals = $settings_ipnet;
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'BOX(10.0.0.9)', 'is_production' => 0]], 'usermap' => [], 'distinct_user' => [],
    'distinct_asset' => [['asset' => 'box(10.0.0.9)'], ['asset' => 'newbox(10.0.0.10)']], 'ipnetworks' => $prod_net];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check(count($db->inserts) === 1 && $db->inserts[0][1]['asset'] === 'newbox(10.0.0.10)',
    "discovery skips case-variant duplicate, inserts only the genuinely new asset (got " . json_encode(array_column(array_column($db->inserts, 1), 'asset')) . ")");

// generation bump: a verdict flip bumps correlation_maps_generation
Config::$vals = $settings_ipnet; // generation '0'
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'was-prod(172.16.0.9)', 'parsed_name' => 'was-prod', 'parsed_ip' => '172.16.0.9',
    'is_manual' => 0, 'is_production' => 1, 'resolve_method' => 'ipnet', 'last_resolved_at' => '2026-08-30 00:00:00']],
    'ipnetworks' => $prod_net, 'usermap' => [], 'distinct_asset' => [], 'distinct_user' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check((int) Config::$vals['correlation_maps_generation'] === 1, "verdict flip (prod 1 -> 0) bumps generation to 1 (got " . Config::$vals['correlation_maps_generation'] . ")");

// I1 residual: a first resolve to production that AGREES with the prefix
// fallback must NOT bump (Piece 2 already classified those commands prod).
$s = $settings_ipnet; $s['jumpserver_production_ip_prefixes'] = "10.0.0.\n"; // prefix would also say prod
$db = new FakeDB();
Config::$vals = $s;
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'fresh(10.0.0.7)', 'parsed_name' => 'fresh', 'parsed_ip' => '10.0.0.7',
    'is_manual' => 0, 'is_production' => 0]],  // no last_resolved_at -> first resolve
    'ipnetworks' => $prod_net, 'usermap' => [], 'distinct_asset' => [], 'distinct_user' => [], 'ipaddr_by_ip' => [], 'computers' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check((int) Config::$vals['correlation_maps_generation'] === 0,
    "first resolve to prod that AGREES with the prefix fallback -> no bump (got " . Config::$vals['correlation_maps_generation'] . ")");

// first resolve whose verdict DIVERGES from the fallback -> bump
$s = $settings_ipnet; $s['jumpserver_production_ip_prefixes'] = "10.200.0.\n"; // prefix says NOT prod
$db = new FakeDB();
Config::$vals = $s;
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'fresh2(10.0.0.7)', 'parsed_name' => 'fresh2', 'parsed_ip' => '10.0.0.7',
    'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => $prod_net, 'usermap' => [], 'distinct_asset' => [], 'distinct_user' => [], 'ipaddr_by_ip' => [], 'computers' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check((int) Config::$vals['correlation_maps_generation'] === 1,
    "first resolve to prod that DIVERGES from the fallback -> bump (got " . Config::$vals['correlation_maps_generation'] . ")");

// no bump when nothing classification-relevant changed
Config::$vals = $settings_ipnet;
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'steady(10.0.0.5)', 'parsed_name' => 'steady', 'parsed_ip' => '10.0.0.5',
    'is_manual' => 0, 'is_production' => 1, 'ipnetworks_id' => 7, 'computers_id' => 0, 'resolve_method' => 'ipnet', 'last_resolved_at' => '2026-08-30 00:00:00']],
    'ipnetworks' => $prod_net, 'usermap' => [], 'distinct_asset' => [], 'distinct_user' => [], 'ipaddr_by_ip' => [], 'computers' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check((int) Config::$vals['correlation_maps_generation'] === 0, "steady row -> no generation bump (got " . Config::$vals['correlation_maps_generation'] . ")");

// mapsGeneration reads the config; bumpGeneration increments
Config::$vals = ['correlation_maps_generation' => '5'];
check($C::mapsGeneration() === 5, 'mapsGeneration reads config');
$C::bumpGeneration();
check($C::mapsGeneration() === 6, 'bumpGeneration increments');

// budget: with a 0-second deadline the row loop still processes discovery
// but bails before writing (deadline in the past)
Config::$vals = $settings_ipnet;
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'a(10.0.0.5)', 'parsed_name' => 'a', 'parsed_ip' => '10.0.0.5', 'is_manual' => 0, 'is_production' => 0]],
    'ipnetworks' => $prod_net, 'usermap' => [], 'distinct_asset' => [], 'distinct_user' => [], 'ipaddr_by_ip' => [], 'computers' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll(0.0000001);
check($db->updates === [], "past deadline -> row loop breaks before any write");

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
