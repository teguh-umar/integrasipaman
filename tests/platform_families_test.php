<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/platform_families_test.php
//
// v4.14.0: OS -> platform by family.
//
// GLPI names one OS family dozens of ways ("Oracle Linux Server 9.4",
// "CentOS Linux 7 (Core)", "Microsoft Windows Server 2019 Standard", ...), and
// the exact per-OS mapping needed one row per spelling. A family rule is a
// fallback after the exact mapping: "Platform: keyword, keyword", the first
// line whose keyword the OS name contains (case-insensitive) wins, and the
// platform is found by NAME in the PAMAN platform list. Kubernetes / SKE are
// deliberately absent: in PAMAN those are Cloud assets, and provisioning only
// ever creates Hosts.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!class_exists('CommonDBTM')) { class CommonDBTM {} }
class Config { public static function getConfigurationValues($c, $k = []) { return ['jumpserver_url' => 'https://paman.example.org']; } }

require_once __DIR__ . '/../inc/provisioning.class.php';
require_once __DIR__ . '/../inc/config.class.php';
$P = 'PluginIntegrasipamanProvisioning';
PluginIntegrasipamanConnection::setCurrent(['url' => 'https://paman.example.org']);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$rules     = $P::parseFamilies(PluginIntegrasipamanConfig::CONFIG_DEFAULTS['provisioning_platform_families']);
$platforms = [1 => 'Linux', 5 => 'Windows', 9 => 'Kubernetes', 12 => 'RDP'];

// --- the defaults against every OS name the production inventory held ----
$linux = ['Oracle', 'Oracle Linux Server 9.4', 'CentOS Linux', 'Red Hat', 'Ubuntu Linux', 'CentOS',
          'CentOS Linux 7 (Core)', 'openEuler 20.03', 'Red Hat Enterprise Linux', 'Rocky Linux 9.2 (Blue Onyx)',
          'Ubuntu', 'Ubuntu 22.04.2 LTS', 'Debian GNU/Linux 8 (jessie)', 'AlmaLinux 9.6 (Sage Margay)',
          'Red Hat Enterprise Linux Server release 5.4', 'CentOS Stream 9'];
foreach ($linux as $os) {
    check(($P::matchFamily($os, $rules, $platforms)['platform_id'] ?? null) === 1, "default rules: \"$os\" -> Linux");
}
$windows = ['Microsoft Windows Server 2019 Standard', 'Windows Server', 'Windows', 'Microsoft Windows 10 Pro',
            'Microsoft Windows 7 Professional', 'Windows Server 2025 Standart', 'Windows Server 2003 Enterprise'];
foreach ($windows as $os) {
    check(($P::matchFamily($os, $rules, $platforms)['platform_id'] ?? null) === 5, "default rules: \"$os\" -> Windows");
}
foreach (['Vanilla Kubernetes', 'SKE', 'VMware ESXi 8.0.3 build-24859861', ''] as $os) {
    check($P::matchFamily($os, $rules, $platforms) === null, "default rules: \"$os\" matches nothing (not a Host platform)");
}

// --- the rule mechanics ------------------------------------------------------
$m = $P::matchFamily('Ubuntu 22.04 LTS', $rules, $platforms);
check(($m['label'] ?? null) === 'Linux' && ($m['keyword'] ?? null) === 'ubuntu', 'the match names its platform and the keyword that fired');
$custom = $P::parseFamilies("RDP: windows\nLinux: linux");
check(($P::matchFamily('Windows Server', $custom, $platforms)['platform_id'] ?? null) === 12, 'a custom rule may point at any platform by name');
check(($P::matchFamily('Windows Server', $P::parseFamilies("rdp: windows"), $platforms)['platform_id'] ?? null) === 12,
    'the platform name is matched case-insensitively');
$order = $P::parseFamilies("Windows: server\nLinux: linux");
check(($P::matchFamily('Oracle Linux Server 9.4', $order, $platforms)['platform_id'] ?? null) === 5, 'line order decides when two lines match');
$missing = $P::matchFamily('Ubuntu', $P::parseFamilies('Linux-Prod: ubuntu'), $platforms);
check(($missing['platform_id'] ?? null) === 0 && ($missing['label'] ?? null) === 'Linux-Prod',
    'a rule naming a platform PAMAN does not have matches, with id 0 (Send is refused, not guessed)');
check($P::parseFamilies("# comment\n\nLinux: linux, , ubuntu\nnot a rule") === [['platform' => 'Linux', 'keywords' => ['linux', 'ubuntu']]],
    'comments, blank lines, empty keywords and lines without ":" are ignored');

// --- resolveTargets: exact mapping first, then family, then the [0] default --
$node = [7 => ['id' => 'n-7', 'label' => '/Default']];
$comp = ['computertypes_id' => 7, 'operatingsystems_id' => 40, 'os_name' => 'Oracle Linux Server 9.4', 'type_name' => 'Virtual Machine'];
$t = $P::resolveTargets($comp, $node, [], $rules, $platforms);
check($t['platform_id'] === 1 && $t['blocked'] === '', 'no exact mapping: the family rule gives the platform');
check(str_contains($t['platform_label'], 'Linux'), 'the label says which platform');
$t = $P::resolveTargets($comp, $node, [40 => ['id' => 12, 'label' => 'RDP']], $rules, $platforms);
check($t['platform_id'] === 12, 'an exact per-OS mapping wins over a family rule');
$t = $P::resolveTargets(['os_name' => 'SKE', 'operatingsystems_id' => 41] + $comp, $node, [], $rules, $platforms);
check($t['platform_id'] === 0 && str_contains($t['blocked'], 'OS SKE is not mapped'), 'no rule matches: still blocked, as before');
$t = $P::resolveTargets(['os_name' => 'Ubuntu'] + $comp, $node, [], $P::parseFamilies('Linux-Prod: ubuntu'), $platforms);
check($t['platform_id'] === 0 && str_contains($t['blocked'], 'Linux-Prod'), 'a rule naming a missing platform blocks, naming it');
$t = $P::resolveTargets(['os_name' => '', 'operatingsystems_id' => 0] + $comp, $node, [], $rules, $platforms);
check($t['platform_id'] === 0 && str_contains($t['blocked'], 'has no operating system'), 'a Computer with no OS in GLPI says so, not "OS  is not mapped"');

// --- the OS comes from glpi_items_operatingsystems ---------------------------
// GLPI >= 9.3 keeps a Computer's OS there; glpi_computers has no
// operatingsystems_id, so reading it off the Computer gave 0 - and an exact
// per-OS mapping that could never match. resolveTargets() looks it up by the
// Computer id when the caller had none.
class FakeDB {
    public array $requests = [];
    public array $results  = [];
    public function request($q) { $this->requests[] = $q; return new ArrayIterator(array_shift($this->results) ?? []); }
}
$DB = new FakeDB();
$DB->results = [[['operatingsystems_id' => 40, 'name' => 'Oracle Linux Server 9.4']]];
$os = $P::osFor(1671);
check($os === ['id' => 40, 'name' => 'Oracle Linux Server 9.4'], 'osFor reads the OS of the Computer');
check(($DB->requests[0]['WHERE']['io.itemtype'] ?? null) === 'Computer' && ($DB->requests[0]['WHERE']['io.items_id'] ?? null) === 1671,
    'osFor reads glpi_items_operatingsystems for that Computer');
$DB->requests = [];
$DB->results = [[['operatingsystems_id' => 40, 'name' => 'Oracle Linux Server 9.4']]];
$t = $P::resolveTargets(['id' => 1671, 'computertypes_id' => 7, 'os_name' => ''], $node, [40 => ['id' => 12, 'label' => 'RDP']], [], $platforms);
check($t['platform_id'] === 12, 'resolveTargets finds the exact per-OS mapping through glpi_items_operatingsystems');
$DB->results = [[]];
$t = $P::resolveTargets(['id' => 1671, 'computertypes_id' => 7, 'os_name' => ''], $node, [], $rules, $platforms);
check(str_contains($t['blocked'], 'has no operating system'), 'a Computer with no OS row says it has no operating system');

// --- rc2: the rules are actually read in GLPI ------------------------------
// rc1 guarded familiesFromConfig() with class_exists('PluginIntegrasipamanConfig',
// false). In GLPI that class is autoloaded on first use and was not loaded yet,
// so the guard read "no rules" on every Computer (VM, 2026-11-07: "OS Oracle
// Linux Server 9.6 is not mapped"). This suite had loaded config.class.php
// itself and never saw it. The guard must let the autoloader run.
$src = file_get_contents(__DIR__ . '/../inc/provisioning.class.php');
preg_match('/private static function familiesFromConfig\(\).*?\n    \}/s', $src, $ff);
check(isset($ff[0]) && !preg_match("/class_exists\\([^)]*,\\s*false\\)/", $ff[0]),
    'familiesFromConfig() lets the autoloader load the classes it needs');

// --- the setting is per connection and saved by the form -------------------
require_once __DIR__ . '/../inc/rulesettings.class.php';
check(PluginIntegrasipamanRuleSettings::sectionOf('provisioning_platform_families') === 'provisioning', 'the family list is a per-connection provisioning setting');
$form = file_get_contents(__DIR__ . '/../front/config.form.php');
preg_match('/\$rule_values = \[(.*?)\];/s', $form, $rv);
check(str_contains($rv[1] ?? '', "'provisioning_platform_families'"), 'config.form.php saves it');
check(str_contains($form, "array_key_exists('provisioning_platform_families', \$_POST)"), 'an old tab without the field cannot blank it');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
