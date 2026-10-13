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

// Only the window constant is needed here: refreshIpMismatch() reads it to
// decide which rows have aged out of the PAMAN host list.
class PluginIntegrasipamanEntitlement {
    public const STALE_DAYS = 14;
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
            $w  = $q['WHERE'] ?? [];
            // The production query keys this qualified (glpi_ipaddresses.name)
            // once it carries the join back to glpi_computers; keep matching
            // the old unqualified 'name' too so both the pre- and post-fix
            // query shapes dispatch the same way.
            $ip = $w['glpi_ipaddresses.name'] ?? $w['name'] ?? null;
            if ($ip !== null) {
                $fixture = $this->tables['ipaddr_by_ip'] ?? [];
                // Two fixture shapes: a flat list of rows (existing tests,
                // one IP per test, order/content is the whole answer), or a
                // map of ip => mainitems_id (new tests that need the SAME ip
                // to resolve to a SPECIFIC id so the join filter below has
                // something concrete to check against).
                $is_map = $fixture !== [] && array_keys($fixture) !== range(0, count($fixture) - 1);
                $rows   = $is_map
                    ? (isset($fixture[$ip]) ? [['mainitems_id' => $fixture[$ip]]] : [])
                    : $fixture;

                if (isset($q['INNER JOIN']['glpi_computers'])) {
                    // A fake cannot execute a join. Derive the same filter a
                    // real "INNER JOIN glpi_computers ON ... is_deleted = 0
                    // AND is_template = 0" would apply, from the 'computers'
                    // fixture: a row naming an id that fixture marks deleted
                    // or templated is dropped. A row whose id has no entry in
                    // 'computers' at all is kept - the fixture only lists
                    // Computers relevant to deletion state, not a full mirror
                    // of every Computer in the system (existing tests rely on
                    // this: they name a Computer by IP without ever listing
                    // it in 'computers').
                    $computers = $this->tables['computers'] ?? [];
                    $rows = array_values(array_filter($rows, function ($r) use ($computers) {
                        foreach ($computers as $c) {
                            if ((int) ($c['id'] ?? -1) === (int) ($r['mainitems_id'] ?? -2)) {
                                return (int) ($c['is_deleted'] ?? 0) === 0
                                    && (int) ($c['is_template'] ?? 0) === 0;
                            }
                        }
                        return true;
                    }));
                }
                return new ArrayIterator($rows);
            }
            if (!isset($w['mainitems_id']) && !isset($w['glpi_ipaddresses.mainitems_id'])) {
                // refreshIpMismatch()'s batched "every address for every
                // linked Computer" fetch: WHERE carries only mainitemtype,
                // no id and no name - it is not scoped to one Computer the
                // way computerIps() is (that query below still carries
                // mainitems_id and keeps using 'ipaddr_by_computer').
                // 'computer_ips' is a map of computers_id => [ip,...];
                // convert it into the mainitems_id/name row shape the
                // production code actually reads.
                $rows = [];
                foreach ($this->tables['computer_ips'] ?? [] as $cid => $ips) {
                    foreach ($ips as $ip2) {
                        $rows[] = ['mainitems_id' => (int) $cid, 'name' => $ip2];
                    }
                }
                return new ArrayIterator($rows);
            }
            return new ArrayIterator($this->tables['ipaddr_by_computer'] ?? []);
        }
        if (str_contains($from, 'computers')) {
            $w = $q['WHERE'] ?? [];
            // Shared by both real shapes below: a row only excludes a query
            // when BOTH sides carry the flag and they disagree. Absent from
            // the row means the fixture didn't bother stating it (most
            // fixtures only set the field they're testing).
            $flagsMatch = function (array $r) use ($w): bool {
                foreach (['is_deleted', 'is_template'] as $flag) {
                    if (array_key_exists($flag, $w) && array_key_exists($flag, $r)
                        && (int) $r[$flag] !== (int) $w[$flag]) {
                        return false;
                    }
                }
                return true;
            };
            if (!isset($w['id']) && !isset($w['name'])) {
                // repairDanglingLinks()'s batched "every live Computer id"
                // fetch (Fix round 2): WHERE carries only is_deleted /
                // is_template, no id, no name - it's not scoped to one row,
                // it IS the authoritative live set. Filtering the whole
                // 'computers' fixture by those two flags is the correct,
                // literal answer: an id absent from 'computers' is correctly
                // absent from the live set too, same as a real WHERE would
                // give against glpi_computers.
                return new ArrayIterator(array_values(array_filter($this->tables['computers'] ?? [], $flagsMatch)));
            }
            // findComputer()'s two NAME lookups filter is_deleted/is_template
            // for real; replicate that here so a fixture row built to
            // exercise the IP-join filter above doesn't leak back in through
            // the unconditional name fallback using the same fixture data.
            // (No id-keyed query against glpi_computers remains in
            // production since computerExists() was deleted in Fix round 2 -
            // repairDanglingLinks() now checks membership in the batched set
            // above instead of querying per id.)
            $rows = array_values(array_filter($this->tables['computers'] ?? [], $flagsMatch));
            return new ArrayIterator($rows);
        }
        if (str_contains($from, 'groups_users')) return new ArrayIterator($this->tables['groups_users'] ?? []);
        if (str_contains($from, 'users'))        return new ArrayIterator($this->tables['users'] ?? []);
        return new ArrayIterator([]);
    }
    public function insert($t, $v) { $this->inserts[] = [$t, $v]; return true; }
    /**
     * Records the call (assertions depend on that) AND applies it to the
     * matching fixture row, like a real UPDATE would. Without this, a
     * second pass in the same resolveAll() run (repairDanglingLinks() then
     * refreshIpMismatch()) reads stale pre-write fixture data instead of
     * what the first pass just wrote, which cannot tell "the ordering
     * works" apart from "the ordering is untested".
     */
    public function update($t, $v, $w) {
        $this->updates[] = [$t, $v, $w];
        $key = null;
        if (str_contains($t, 'assetmap')) {
            $key = 'assetmap';
        } elseif (str_contains($t, 'usermap')) {
            $key = 'usermap';
        }
        if ($key !== null && isset($w['id']) && isset($this->tables[$key])) {
            foreach ($this->tables[$key] as &$row) {
                if ((int) ($row['id'] ?? -1) === (int) $w['id']) {
                    foreach ($v as $col => $val) {
                        $row[$col] = $val;
                    }
                    break;
                }
            }
            unset($row);
        }
        return true;
    }
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
    return [
        'method'       => $u['resolve_method'] ?? '(none)',
        'class'        => $u['asset_class'] ?? '(none)',
        'computers_id' => $u['computers_id'] ?? '(none)',
    ];
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
    'assetmap' => [['id' => 1, 'asset' => 'web01(10.0.0.5)', 'parsed_name' => 'web01', 'parsed_ip' => '10.0.0.5', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'ipnet', 'class' => 'production', 'computers_id' => 0], "ip_only + IP in prod net -> ipnet/production (got " . json_encode($r) . ")");

$s = $settings_ipnet; $s['jumpserver_production_ip_prefixes'] = "10.200.0.\n";
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'x(10.200.0.9)', 'parsed_name' => 'x', 'parsed_ip' => '10.200.0.9', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'prefix_fallback', 'class' => 'production', 'computers_id' => 0], "prefix fallback -> prefix_fallback/production (got " . json_encode($r) . ")");

$r = run_asset($settings_ipnet, [
    'assetmap' => [['id' => 1, 'asset' => 'y(172.16.0.4)', 'parsed_name' => 'y', 'parsed_ip' => '172.16.0.4', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'unresolved', 'class' => '', 'computers_id' => 0], "no match -> unresolved/'' (got " . json_encode($r) . ")");

// --- staging assets classify by NAME, and only where nothing else decided --
// User decision 2026-09-27: JumpServer assets whose name carries STG or STAG
// are staging boxes, deleted after UAT, and deleting them needs no approval.
// They should generate neither mapping work nor alerts.
//
// Leaving them unmapped would do the OPPOSITE: with no assetmap row there is
// no class, the prefix fallback matches nothing, the result is '' and every
// high-risk command on them scores MEDIUM. Staging would become the loudest
// thing in the report. So they are STATED as development, not left unstated.
// 60 of 615 assets on this VM carry the marker; 55 of those were unresolved.
$r = run_asset($settings_ipnet, [
    'assetmap' => [['id' => 1, 'asset' => 'APP-STG-01(172.16.9.9)', 'parsed_name' => 'APP-STG-01', 'parsed_ip' => '172.16.9.9', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'staging_name', 'class' => 'development', 'computers_id' => 0],
      "a STG name with no other evidence -> staging_name/development (got " . json_encode($r) . ")");

$r = run_asset($settings_ipnet, [
    'assetmap' => [['id' => 1, 'asset' => 'db-stag-7(172.16.9.10)', 'parsed_name' => 'db-stag-7', 'parsed_ip' => '172.16.9.10', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'staging_name', 'class' => 'development', 'computers_id' => 0],
      "STAG matches too, and case does not matter (got " . json_encode($r) . ")");

// The rule must NOT override production evidence. A production box whose name
// happens to carry STG scores LOW if the name wins - and a production asset
// scored LOW is blindness, which is the failure this whole feature removes.
// A staging box wrongly called production only costs noise. The asymmetry
// decides it, the same way it decides the prefix tie-break.
$r = run_asset($settings_ipnet, [
    'assetmap' => [['id' => 1, 'asset' => 'WEB-STG-9(10.0.0.5)', 'parsed_name' => 'WEB-STG-9', 'parsed_ip' => '10.0.0.5', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'ipnet', 'class' => 'production', 'computers_id' => 0],
      "production evidence BEATS the staging name (got " . json_encode($r) . ")");

// And a name with neither marker still falls through to unresolved, so the
// rule cannot be passing by simply classifying everything.
$r = run_asset($settings_ipnet, [
    'assetmap' => [['id' => 1, 'asset' => 'plain-box(172.16.9.11)', 'parsed_name' => 'plain-box', 'parsed_ip' => '172.16.9.11', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [],
]);
check($r === ['method' => 'unresolved', 'class' => '', 'computers_id' => 0],
      "a name with no marker is still unresolved (got " . json_encode($r) . ")");

// IP Addressing plugin range: parsed IP inside begin_ip..end_ip -> addr_range/1
$s = $settings_ipnet;
$s['correlation_production_ipnetworks'] = '';           // no core IPNetwork
$s['correlation_production_addressings'] = '9';         // "Production OT" range
$s['jumpserver_production_ip_prefixes'] = '';
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'ot01(10.0.18.40)', 'parsed_name' => 'ot01', 'parsed_ip' => '10.0.18.40', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => [], 'ipaddr_by_ip' => [], 'computers' => [],
    'addressing' => [['id' => 9, 'begin_ip' => '10.0.18.0', 'end_ip' => '10.0.19.254']],
]);
check($r === ['method' => 'addr_range', 'class' => 'production', 'computers_id' => 0], "IP in addressing range -> addr_range/production (got " . json_encode($r) . ")");

// IP just outside the addressing range -> unresolved
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'x(10.0.20.1)', 'parsed_name' => 'x', 'parsed_ip' => '10.0.20.1', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => [], 'ipaddr_by_ip' => [], 'computers' => [],
    'addressing' => [['id' => 9, 'begin_ip' => '10.0.18.0', 'end_ip' => '10.0.19.254']],
]);
check($r === ['method' => 'unresolved', 'class' => '', 'computers_id' => 0], "IP outside addressing range -> unresolved/'' (got " . json_encode($r) . ")");

$s = $settings_ipnet; $s['correlation_match_mode'] = 'name_only';
$r = run_asset($s, [
    'assetmap' => [['id' => 1, 'asset' => 'web01(192.168.1.9)', 'parsed_name' => 'web01', 'parsed_ip' => '192.168.1.9', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'ipaddr_by_ip' => [], 'computers' => [['id' => 42]],
    'ipaddr_by_computer' => [['name' => '10.0.0.50']],
]);
check($r === ['method' => 'computer_ip', 'class' => 'production', 'computers_id' => 42], "name_only + computer IP in prod net -> computer_ip/production (got " . json_encode($r) . ")");

// A LOCKED row's CLASSIFICATION is never rewritten by resolveAll().
//
// Scoped to asset_class / resolve_method on purpose. "$db->updates === []"
// used to stand here and is no longer true as stated: resolveAll() DOES
// write locked rows now - that is the feature (the mismatch and repair
// passes both run for them by design, sections 6 and 7). It only still
// passed because this fixture has no computers_id, so neither of those
// passes happened to have anything to write - a vacuity, not a property.
//
// What is actually required is that a lock keeps the operator's
// CLASSIFICATION verdict: resolveAssetRows() must continue past this row
// before resolveAssetRow() computes a class for it. The mismatch and repair
// writes are deliberately out of scope here; they have their own tests.
Config::$vals = $settings_ipnet;
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 9, 'asset' => 'z(1.1.1.1)', 'parsed_name' => 'z', 'parsed_ip' => '1.1.1.1', 'is_manual' => 1, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'distinct_asset' => [], 'distinct_user' => [], 'usermap' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll();
$class_writes = [];
foreach ($db->updates as [$t, $vals, $where]) {
    if (!str_contains($t, 'assetmap')) { continue; }
    foreach (['asset_class', 'resolve_method', 'computers_id'] as $col) {
        if (array_key_exists($col, $vals)) { $class_writes[] = [$col => $vals[$col]] + ['where' => $where]; }
    }
}
check($class_writes === [],
      "is_manual=1: resolveAll() writes no asset_class and no resolve_method - the lock keeps the operator's verdict (got "
    . json_encode($class_writes) . ')');

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
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'BOX(10.0.0.9)', 'asset_class' => '']], 'usermap' => [], 'distinct_user' => [],
    'distinct_asset' => [['asset' => 'box(10.0.0.9)'], ['asset' => 'newbox(10.0.0.10)']], 'ipnetworks' => $prod_net];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check(count($db->inserts) === 1 && $db->inserts[0][1]['asset'] === 'newbox(10.0.0.10)',
    "discovery skips case-variant duplicate, inserts only the genuinely new asset (got " . json_encode(array_column(array_column($db->inserts, 1), 'asset')) . ")");

// generation bump: a verdict flip bumps correlation_maps_generation
Config::$vals = $settings_ipnet; // generation '0'
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'was-prod(172.16.0.9)', 'parsed_name' => 'was-prod', 'parsed_ip' => '172.16.0.9',
    'is_manual' => 0, 'asset_class' => 'production', 'resolve_method' => 'ipnet', 'last_resolved_at' => '2026-08-30 00:00:00']],
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
    'is_manual' => 0, 'asset_class' => '']],  // no last_resolved_at -> first resolve
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
    'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'usermap' => [], 'distinct_asset' => [], 'distinct_user' => [], 'ipaddr_by_ip' => [], 'computers' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll();
check((int) Config::$vals['correlation_maps_generation'] === 1,
    "first resolve to prod that DIVERGES from the fallback -> bump (got " . Config::$vals['correlation_maps_generation'] . ")");

// no bump when nothing classification-relevant changed
Config::$vals = $settings_ipnet;
$db = new FakeDB();
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'steady(10.0.0.5)', 'parsed_name' => 'steady', 'parsed_ip' => '10.0.0.5',
    'is_manual' => 0, 'asset_class' => 'production', 'ipnetworks_id' => 7, 'computers_id' => 0, 'resolve_method' => 'ipnet', 'last_resolved_at' => '2026-08-30 00:00:00']],
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
$db->tables = ['assetmap' => [['id' => 1, 'asset' => 'a(10.0.0.5)', 'parsed_name' => 'a', 'parsed_ip' => '10.0.0.5', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks' => $prod_net, 'usermap' => [], 'distinct_asset' => [], 'distinct_user' => [], 'ipaddr_by_ip' => [], 'computers' => []];
$GLOBALS['DB'] = $db;
$C::resolveAll(0.0000001);
check($db->updates === [], "past deadline -> row loop breaks before any write");

// --- hybrid_name: match the Computer by NAME before falling back to the IP ---
// The production case this exists for (glpi-server, 2026-09-23): two Computers
// carry 10.0.0.207. IP-first takes the lowest id and attributes the asset
// VM-IPA-REPL-01 to the Computer vm-wm-tc-01, so one server's PAM access
// history is filed under another. Name-first stops at the Computer that
// actually bears the name.
function run_asset_full(array $settings, array $tables): array {
    global $C;
    Config::$vals = $settings;
    $db = new FakeDB();
    $db->tables = $tables + ['distinct_asset' => [], 'distinct_user' => [], 'usermap' => []];
    $GLOBALS['DB'] = $db;
    $C::resolveAll();
    return $db->updates[0][1] ?? [];
}

$shared_ip_tables = [
    'assetmap' => [[
        'id' => 1, 'asset' => 'VM-IPA-REPL-01(10.0.0.207)',
        'parsed_name' => 'VM-IPA-REPL-01', 'parsed_ip' => '10.0.0.207',
        'is_manual' => 0, 'asset_class' => '',
    ]],
    'ipnetworks'         => [],
    'ipaddr_by_ip'       => [['mainitems_id' => 1566]],  // WRONG Computer, lowest id on that IP
    'ipaddr_by_computer' => [],
    'computers'          => [['id' => 9001]],            // the Computer actually named VM-IPA-REPL-01
];

$s = $settings_ipnet; $s['correlation_production_ipnetworks'] = ''; $s['correlation_match_mode'] = 'ip_only';
$u = run_asset_full($s, $shared_ip_tables);
check((int) ($u['computers_id'] ?? 0) === 1566,
      'ip_only reproduces the defect: shared IP wins, asset lands on the wrong Computer');

$s = $settings_ipnet; $s['correlation_production_ipnetworks'] = ''; $s['correlation_match_mode'] = 'hybrid_name';
$u = run_asset_full($s, $shared_ip_tables);
check((int) ($u['computers_id'] ?? 0) === 9001,
      'hybrid_name matches by name and never consults the shared IP');

$no_name = $shared_ip_tables; $no_name['computers'] = [];
$s = $settings_ipnet; $s['correlation_production_ipnetworks'] = ''; $s['correlation_match_mode'] = 'hybrid_name';
$u = run_asset_full($s, $no_name);
check((int) ($u['computers_id'] ?? 0) === 1566,
      'hybrid_name falls back to the IP when no Computer carries that name');

$s = $settings_ipnet; $s['correlation_production_ipnetworks'] = ''; $s['correlation_match_mode'] = 'nonsense';
$u = run_asset_full($s, $shared_ip_tables);
check((int) ($u['computers_id'] ?? 0) === 1566,
      'an unrecognised mode falls back to ip_only, not to name-first');

// --- v3.6.0: three-valued classification --------------------------------
// RFC 5737 documentation ranges throughout. Never put a real production
// prefix in a test file.
check(PluginIntegrasipamanCorrelation::classFor('192.0.2.7', ['192.0.2.'], ['198.51.100.']) === 'production',
      'a production prefix match classifies production');
check(PluginIntegrasipamanCorrelation::classFor('198.51.100.7', ['192.0.2.'], ['198.51.100.']) === 'development',
      'a development prefix match classifies development');
check(PluginIntegrasipamanCorrelation::classFor('203.0.113.7', ['192.0.2.'], ['198.51.100.']) === '',
      'an address matching neither list is left unclassified, not guessed');

// Production wins a tie. Calling a production box "development" understates
// every command run on it; the reverse only overstates.
check(PluginIntegrasipamanCorrelation::classFor('192.0.2.7', ['192.0.2.'], ['192.0.2.']) === 'production',
      'an address on BOTH lists is production - the asymmetric cost decides it');

// Review Focus 1: the operator deploys before filling the development list.
// Everything that is not production becomes unclassified, and that must be
// what happens rather than a silent fallback to "development".
check(PluginIntegrasipamanCorrelation::classFor('198.51.100.7', ['192.0.2.'], []) === '',
      'an empty development list leaves non-production assets unclassified');

// An empty address cannot be classified by address.
check(PluginIntegrasipamanCorrelation::classFor('', ['192.0.2.'], ['198.51.100.']) === '',
      'an asset with no parsed IP is unclassified, not development');

// Review Focus 4: the first-resolve bump. The old comparison was a boolean
// inequality; comparing strings with it makes '' and 'development' differ on
// every run and bumps the generation forever, re-evaluating ~280k rows each
// time the resolver runs.
check(PluginIntegrasipamanCorrelation::firstResolveDiffers('', '') === false,
      'two unclassified verdicts do not count as a change');
check(PluginIntegrasipamanCorrelation::firstResolveDiffers('development', 'development') === false,
      'two identical verdicts do not count as a change');
check(PluginIntegrasipamanCorrelation::firstResolveDiffers('production', 'development') === true,
      'a genuine verdict difference does count');
check(PluginIntegrasipamanCorrelation::firstResolveDiffers('', 'development') === true,
      'unclassified vs development is a real difference, not an artefact');

// --- the IP path must not bind a deleted Computer -----------------------
// Both NAME lookups already filter is_deleted (correlation.class.php:549
// and :574). The IP lookup queries glpi_ipaddresses and never joins back,
// and GLPI KEEPS ipaddresses rows when a Computer is soft-deleted.
//
// Observed live on 2026-09-28: while Computer 1677 sat in the GLPI trash,
// the name lookup correctly found nothing, the resolver fell through to
// the IP path, and bound an OT asset - a machine reachable only through
// PAMAN - to a different Computer entirely. "hybrid_name protects you" is
// false for exactly as long as a soft delete lasts.
$r = run_asset($settings_ipnet, [
    'assetmap'    => [['id' => 1, 'asset' => 'ghost(192.0.2.9)', 'parsed_name' => 'ghost', 'parsed_ip' => '192.0.2.9', 'is_manual' => 0, 'asset_class' => '']],
    'ipnetworks'  => [], 'addressing' => [],
    'ipaddr_by_ip' => ['192.0.2.9' => 4242],
    'computers'   => [['id' => 4242, 'name' => 'ghost', 'is_deleted' => 1, 'is_template' => 0]],
]);
check(($r['computers_id'] ?? 0) === 0,
      'a soft-deleted Computer is not reachable through the IP path (got '
    . json_encode($r) . ')');

// --- repairDanglingLinks(): a dangling STORED link is cleared, a live ----
// one is left alone.
// Observed live: assetmap row 74 held computers_id 1682 while
// SELECT id FROM glpi_computers WHERE id = 1682 returned Empty set.
// Nothing validated that the link still pointed to a live record. (What
// was observed was exactly this - a stale stored id - never a phantom id
// coming back out of findComputer().)
//
// resolveAssetRow() never reads a row's own computers_id column - it always
// recomputes fresh from findComputer(), which (after the Step 3 join) can
// only return an id that already exists. So a dangling STORED link can only
// survive on an is_manual=1 row: resolveAssetRows() continues past those
// before resolveAssetRow() is ever called, so nothing else revisits them.
// repairDanglingLinks() is its own pass, run before resolveAssetRows(),
// specifically to reach the rows resolveAssetRow() never sees. Both fixture
// rows here are is_manual=1 so resolveAssetRows() cannot also touch
// computers_id afterwards and mask what the repair pass alone did.
//
// Fix round 2: the pass batches ONE "every live Computer id" query (WHERE
// is_deleted=0, is_template=0, no id filter) instead of a per-row
// computerExists() lookup - resolveAll() runs every 15 minutes via the
// cron, and a query per linked row (~500 on the live estate) was the same
// regression this plan already rejected once elsewhere in the file.
//
// A third row pins the SPEC BOUNDARY: id 99 DOES have a row in 'computers'
// (unlike 1682, absent entirely), but that row is soft-deleted - and a
// soft-deleted Computer must have its link PRESERVED. The live set is
// scoped by existence alone, because section 7 authorises clearing a link
// to a Computer that "does not exist" / was "later purged", and the
// evidence was an id returning Empty set. Soft-deleted is neither: the
// delete is reversible, the purge is not, and on a locked row nothing ever
// revisits the row to rebuild the link once the Computer comes back.
//
// This row previously asserted the opposite, when the live-set query still
// carried is_deleted = 0. Inverting it is the better assertion: it pins
// what the spec permits rather than what the query happened to say.
//
// A fourth row (77) holds a second dangling id so the end-state loop below
// has more than one row to speak about - see its counter.
Config::$vals = $settings_ipnet;
$db = new FakeDB();
$db->tables = [
    'assetmap' => [
        ['id' => 74, 'asset' => 'orphan(203.0.113.9)', 'parsed_name' => 'orphan', 'parsed_ip' => '203.0.113.9', 'is_manual' => 1, 'asset_class' => 'production', 'computers_id' => 1682],
        ['id' => 75, 'asset' => 'live(203.0.113.10)', 'parsed_name' => 'live', 'parsed_ip' => '203.0.113.10', 'is_manual' => 1, 'asset_class' => 'production', 'computers_id' => 42],
        ['id' => 76, 'asset' => 'stale(203.0.113.11)', 'parsed_name' => 'stale', 'parsed_ip' => '203.0.113.11', 'is_manual' => 1, 'asset_class' => 'production', 'computers_id' => 99],
        ['id' => 77, 'asset' => 'orphan2(203.0.113.12)', 'parsed_name' => 'orphan2', 'parsed_ip' => '203.0.113.12', 'is_manual' => 1, 'asset_class' => 'production', 'computers_id' => 1683],
    ],
    'ipnetworks' => [], 'distinct_asset' => [], 'distinct_user' => [], 'usermap' => [],
    'computers'  => [
        ['id' => 42, 'is_deleted' => 0, 'is_template' => 0],
        ['id' => 99, 'is_deleted' => 1, 'is_template' => 0],
    ],
    // refreshIpMismatch() now also runs inside resolveAll(), AFTER
    // repairDanglingLinks() has cleared rows 74 and 77's computers_id to 0
    // (FakeDB::update() mutates the fixture, same as a real UPDATE would) -
    // so by the time the mismatch pass reads THOSE two rows, computers_id
    // is genuinely 0 and mismatch computes to 0 via the "no link" path,
    // honestly, with no addresses fixture needed for 1682 or 1683.
    //
    // Row 76's link to soft-deleted Computer 99 SURVIVES, so that row still
    // reaches the mismatch pass with a link. GLPI keeps glpi_ipaddresses
    // rows when a Computer is soft-deleted (spec section 7), so 99 carrying
    // its recorded address is the ordinary state - and giving it one keeps
    // the assertion below exact: no write AT ALL for row 76, rather than
    // "no computers_id write, but a mismatch write".
    //
    // Row 75 (live) is not repaired - its computers_id stays 42 for real -
    // so it genuinely needs a recorded address to avoid a mismatch write of
    // its own, which this test does not care about and must not let mask
    // "no write for the live link". A live, still-linked Computer legitimately
    // having a matching recorded IP is ordinary, so this line is a real
    // fixture, not a workaround for the other two.
    'computer_ips' => [42 => ['203.0.113.10'], 99 => ['203.0.113.11']],
];
$GLOBALS['DB'] = $db;
$C::resolveAll();

$dangling_update = null;
$stale_touched   = false;
$live_touched    = false;
foreach ($db->updates as $upd) {
    if ($upd[2] === ['id' => 74]) { $dangling_update = $upd[1]; }
    if ($upd[2] === ['id' => 76]) { $stale_touched = true; }
    if ($upd[2] === ['id' => 75]) { $live_touched = true; }
}
check($dangling_update === ['computers_id' => 0],
      'a computers_id naming a Computer that does not exist is reset to 0, and only that column is written (got '
    . json_encode($dangling_update) . ')');
check($stale_touched === false,
      'a computers_id naming a SOFT-DELETED Computer is PRESERVED - the trash is reversible and a purge is not (got touched='
    . json_encode($stale_touched) . ')');
check($live_touched === false,
      'a computers_id naming a live Computer is left alone - no write at all (got touched=' . json_encode($live_touched) . ')');

// A row with no link cannot disagree about an address. Asserted on the END
// STATE of the fixture (which FakeDB::update() now mutates for real), not
// on the sequence of $updates - the property that matters is what an
// operator sees after resolveAll() finishes, and it must hold no matter how
// the repair and mismatch passes are later arranged (even merged into one).
// Get it wrong - run the mismatch pass before the repair, as this plugin's
// own history already did once - and a row lands with ip_mismatch = 1 and
// no Computer linked at all: the correlation page would say "this asset's
// address disagrees with GLPI" about an asset GLPI does not link to
// anything, and the form warning would try to name two addresses while
// holding only one. Wrong on an audit screen, and invisible in any log.
//
// ANTI-VACUITY: the loop only speaks about rows that ended with
// computers_id = 0, which is a state repairDanglingLinks() has to CREATE.
// Regress that pass to a no-op and no row qualifies, no check() is emitted
// at all, and the suite stays green on zero assertions. The counter makes
// that failure loud. Two, not one: rows 74 and 77 both hold dangling ids,
// so a repair that reached only the first would be caught too.
$nolink_checked = 0;
foreach ($db->tables['assetmap'] as $row) {
    if ((int) ($row['computers_id'] ?? 0) === 0) {
        $nolink_checked++;
        check((int) ($row['ip_mismatch'] ?? 0) === 0,
              'row ' . $row['id'] . ': a row with no link cannot disagree about an address (got ip_mismatch='
            . json_encode($row['ip_mismatch'] ?? null) . ')');
    }
}
check($nolink_checked >= 2,
      'the end-state loop actually ran - ' . $nolink_checked . ' repaired row(s) inspected, expected at least 2 (zero would mean repairDanglingLinks() regressed to a no-op and this loop asserted nothing)');

// --- ip_mismatch: a signal, computed even for locked rows ---------------
// resolveAssetRows() skips is_manual = 1, and this flag must NOT be
// skipped with it. Locking states a CLASSIFICATION decision; it is not a
// claim that the address is still current. Locked rows are the likeliest
// to hold a stale address precisely because nothing revisits them.
//
// Real shape from production: PAMAN said 10.0.14.136, GLPI said
// 10.0.3.136 - same host octet, different third. Fixtures use RFC 5737.
/**
 * Runs only the mismatch pass. Returns [assetmap id => ip_mismatch written].
 *
 * Note that a row absent from the return value was NOT written at all -
 * refreshIpMismatch() only issues an UPDATE when the computed value differs
 * from the stored one. So an assertion that a row reads 0 must start it at
 * stored 1, or "not written" and "written 0" are indistinguishable.
 */
function run_asset_mismatch(array $rows, array $computer_ips): array {
    global $C;
    $db = new FakeDB();
    $db->tables = ['assetmap' => $rows, 'computer_ips' => $computer_ips];
    $GLOBALS['DB'] = $db;
    $C::refreshIpMismatch();
    $out = [];
    foreach ($db->updates as [$table, $vals, $where]) {
        if (array_key_exists('ip_mismatch', $vals)) {
            $out[(int) ($where['id'] ?? 0)] = (int) $vals['ip_mismatch'];
        }
    }
    return $out;
}

$r = run_asset_mismatch([
    ['id' => 1, 'asset' => 'moved(192.0.2.136)', 'parsed_name' => 'moved', 'parsed_ip' => '192.0.2.136',
     'is_manual' => 1, 'computers_id' => 55, 'ip_mismatch' => 0],
], ['55' => ['198.51.100.136']]);
check(($r[1] ?? null) === 1,
      'a LOCKED row still gets its mismatch computed (got ' . json_encode($r) . ')');

$r = run_asset_mismatch([
    ['id' => 2, 'asset' => 'agrees(192.0.2.10)', 'parsed_name' => 'agrees', 'parsed_ip' => '192.0.2.10',
     'is_manual' => 0, 'computers_id' => 56, 'ip_mismatch' => 1],
], ['56' => ['192.0.2.10', '198.51.100.1']]);
check(($r[2] ?? null) === 0,
      'and it CLEARS itself when the addresses agree again - derived, not sticky');

$r = run_asset_mismatch([
    ['id' => 3, 'asset' => 'nolink(192.0.2.11)', 'parsed_name' => 'nolink', 'parsed_ip' => '192.0.2.11',
     'is_manual' => 0, 'computers_id' => 0, 'ip_mismatch' => 1],
], []);
check(($r[3] ?? null) === 0,
      'a row with no Computer cannot mismatch - the flag is about a LINK');

$r = run_asset_mismatch([
    ['id' => 4, 'asset' => 'noip()', 'parsed_name' => 'noip', 'parsed_ip' => '',
     'is_manual' => 0, 'computers_id' => 57, 'ip_mismatch' => 1],
], ['57' => ['192.0.2.50']]);
check(($r[4] ?? null) === 0,
      'an asset with no parsed IP cannot mismatch either');

// --- discrete: a repaired link (computers_id = 0) clears a stale flag ---
// The repairDanglingLinks() test above proves the ORDERING (repair, then
// mismatch, in the same resolveAll() run) works. This proves the discrete
// fact that ordering exists to make true: a row that used to disagree
// (ip_mismatch stored as 1) but whose link has since been cleared to 0 has
// nothing left to disagree about, and must read back as 0 - not stay stuck
// on the last verdict from before the link was cut.
$r = run_asset_mismatch([
    ['id' => 5, 'asset' => 'wasdangling(192.0.2.99)', 'parsed_name' => 'wasdangling', 'parsed_ip' => '192.0.2.99',
     'is_manual' => 1, 'computers_id' => 0, 'ip_mismatch' => 1],
], []);
check(($r[5] ?? null) === 0,
      'a row whose link was already repaired to 0 clears a stale ip_mismatch (got ' . json_encode($r) . ')');

// --- edge case: a linked Computer with ZERO recorded addresses mismatches -
// This is not exotic - it is the production scenario that started this
// whole release: a GLPI agent that cannot reach the server reports no
// address at all. $ips_by_computer[$cid] ?? [] already handles this (an
// absent key becomes an empty array, and nothing is ever "in" an empty
// array), but nothing pinned it before this assertion. Without this test,
// a future "cleanup" of the null-coalesce into a skip-when-absent branch
// would silently turn "cannot reach it" into "nothing to see here".
$r = run_asset_mismatch([
    ['id' => 6, 'asset' => 'noaddr(192.0.2.77)', 'parsed_name' => 'noaddr', 'parsed_ip' => '192.0.2.77',
     'is_manual' => 0, 'computers_id' => 58, 'ip_mismatch' => 0],
], ['58' => []]);
check(($r[6] ?? null) === 1,
      'a linked Computer with zero recorded addresses still mismatches - silence is not agreement (got ' . json_encode($r) . ')');

// --- the window: aged-out rows are FORCED to 0, NULL is still computed ---
// Spec section 5: the flag is skipped "only for aged-out rows - comparing
// an address that PAMAN no longer lists produces noise. It is still
// computed for NULL rows". Forced to 0 and not merely skipped, because a
// skipped row keeps whatever it last held - the sticky flag section 9
// forbids - and the correlation page's "investigate" count would fill up
// with machines that are gone and whose addresses cannot agree.
$stale_stamp  = date('Y-m-d H:i:s', strtotime('-40 days'));
$recent_stamp = date('Y-m-d H:i:s', strtotime('-2 days'));

// The addresses GENUINELY disagree here. That is the point: the flag is
// forced to 0 anyway, because the asset PAMAN is being compared against is
// no longer in PAMAN's list at all.
$r = run_asset_mismatch([
    ['id' => 7, 'asset' => 'gone(192.0.2.7)', 'parsed_name' => 'gone', 'parsed_ip' => '192.0.2.7',
     'is_manual' => 0, 'computers_id' => 60, 'ip_mismatch' => 1, 'live_checked_at' => $stale_stamp],
], ['60' => ['198.51.100.7']]);
check(($r[7] ?? null) === 0,
      'an aged-out row is FORCED to 0 even though its addresses disagree - not frozen on its last verdict (got '
    . json_encode($r) . ')');

$r = run_asset_mismatch([
    ['id' => 8, 'asset' => 'unasked(192.0.2.8)', 'parsed_name' => 'unasked', 'parsed_ip' => '192.0.2.8',
     'is_manual' => 0, 'computers_id' => 61, 'ip_mismatch' => 0, 'live_checked_at' => null],
], ['61' => ['198.51.100.8']]);
check(($r[8] ?? null) === 1,
      'a NULL live_checked_at row is still computed - never asked is our ignorance, not a finding (got '
    . json_encode($r) . ')');

$r = run_asset_mismatch([
    ['id' => 9, 'asset' => 'fresh(192.0.2.9)', 'parsed_name' => 'fresh', 'parsed_ip' => '192.0.2.9',
     'is_manual' => 0, 'computers_id' => 62, 'ip_mismatch' => 0, 'live_checked_at' => $recent_stamp],
], ['62' => ['198.51.100.9']]);
check(($r[9] ?? null) === 1,
      'a within-window row is still computed (got ' . json_encode($r) . ')');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
