<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_existing_test.php
//
// v4.13.1: an asset that already exists in PAMAN is never "Eligible".
//
// Found on production 2026-11-07: a Computer whose host was created in PAMAN
// by hand showed "Status: Eligible" with a Send button, because the queue
// only knew about hosts THIS plugin had sent (jumpserver_asset_id on its own
// row) and never looked at the host list it already pulls. Existence is
// decided by identity - the address, or the name - and never by the type/OS
// mapping, which only matters for a new Send.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
if (!class_exists('CommonDBTM')) { class CommonDBTM {} }
class Config { public static function getConfigurationValues($c, $k = []) { return []; } }

class FakeDB {
    public array $requests = [];
    public array $results  = [];
    public function request($q) { $this->requests[] = $q; return new ArrayIterator(array_shift($this->results) ?? []); }
}
$DB = new FakeDB();

require_once __DIR__ . '/../inc/provisioning.class.php';
$P = 'PluginIntegrasipamanProvisioning';
PluginIntegrasipamanConnection::setCurrent(['id' => 3, 'url' => 'https://paman.example.org']);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$hosts = [
    ['jms_id' => 'h-1', 'name' => 'VM-APP-07', 'address' => '10.0.0.157'],
    ['jms_id' => 'h-2', 'name' => 'VM-WEB-01', 'address' => '10.0.0.20'],
];

// --- matchExisting: pure -------------------------------------------------
check(($P::matchExisting('VM-APP-07', '10.0.0.157', $hosts)['jms_id'] ?? null) === 'h-1', 'same name and address: found');
check(($P::matchExisting('vm-app-07-renamed', '10.0.0.157', $hosts)['jms_id'] ?? null) === 'h-1', 'address alone is enough');
check(($P::matchExisting('vm-app-07', '10.0.0.99', $hosts)['jms_id'] ?? null) === 'h-1', 'name alone is enough, case-insensitive');
check(($P::matchExisting(' VM-APP-07 ', '', $hosts)['jms_id'] ?? null) === 'h-1', 'surrounding spaces in the GLPI name do not hide it');
check($P::matchExisting('VM-NEW-01', '10.0.0.200', $hosts) === null, 'neither matches: not existing');
check($P::matchExisting('', '', $hosts) === null, 'no name and no address never matches an empty host field');
check($P::matchExisting('VM-NEW-01', '10.0.0.200', [['jms_id' => 'h-x', 'name' => '', 'address' => '']]) === null,
    'a host with blank name and address matches nothing');
check(($P::matchExisting('VM-WEB-01', '10.0.0.157', $hosts)['jms_id'] ?? null) === 'h-1',
    'name and address point at two hosts: the address wins (it is what a Send would collide on)');

// --- existingHost: reads the pulled host list of THIS connection --------
$DB->results = [[['jms_id' => 'h-1', 'name' => 'VM-APP-07', 'address' => '10.0.0.157']]];
$found = $P::existingHost('VM-APP-07', '10.0.0.157');
$q = $DB->requests[0];
check(($found['jms_id'] ?? null) === 'h-1', 'existingHost finds the pulled host');
check(($q['FROM'] ?? null) === 'glpi_plugin_jumpserver_hosts', 'existingHost reads glpi_plugin_jumpserver_hosts');
check(str_contains(json_encode($q['WHERE'] ?? []), 'plugin_jumpserver_connections_id'),
    'existingHost is limited to the current connection');
$DB->requests = [];
check($P::existingHost('', '') === null && $DB->requests === [], 'nothing to match on: no query');

// --- the decision is independent of type / OS mapping --------------------
// refreshFor() and renderNoRow() must ask existingHost() BEFORE isEligible()
// and before any node/platform resolution.
$src = file_get_contents(__DIR__ . '/../inc/provisioning.class.php');
preg_match('/private static function refreshFor\(.*?\n    \}/s', $src, $rf);
$body = $rf[0] ?? '';
$posExisting = strpos($body, 'self::existingHost(');
$posEligible = strpos($body, 'self::isEligible(');
check($posExisting !== false && $posEligible !== false && $posExisting < $posEligible,
    'refreshFor checks existence before eligibility');
preg_match('/private static function renderNoRow\(.*?\n    \}/s', $src, $rn);
check(str_contains($rn[0] ?? '', 'self::existingHostFor('), 'the tab without a queue row says when the asset already exists');
preg_match('/private static function renderRow\(.*?\n    \}/s', $src, $rr);
check(str_contains($rr[0] ?? '', 'self::existingHostFor('), 'the tab with an old eligible row hides Send when the asset already exists');

// --- v4.14.0-rc5 (VM, 2026-11-07): the queue and the Send path too -------
// An Eligible row made before 4.13.1 stays until the next refresh/scan. Its
// tab already said "already in PAMAN", but the queue still listed it Eligible
// with a live checkbox, and a bulk Send would have adopted the hand-made host
// and PATCHed its node/platform. Both now ask the same question.
$queue = file_get_contents(__DIR__ . '/../front/provisioning.php');
check(str_contains($queue, 'jumpserver_provisioning_existing('), 'the queue page checks each unsent row against the pulled host list');
check(preg_match('/if \(\$existing !== null\).*?Already in/s', $queue) === 1, 'such a row reads "Already in PAMAN as ..." and gets no checkbox');
$action = file_get_contents(__DIR__ . '/../inc/provisioningaction.class.php');
preg_match('/private static function doSend\(.*?\n    \}/s', $action, $ds);
$body = $ds[0] ?? '';
$posExisting = strpos($body, '::existingHost(');
$posTargets  = strpos($body, '::resolveTargets(');
check($posExisting !== false && $posTargets !== false && $posExisting < $posTargets,
    'doSend refuses a host PAMAN already holds, before any mapping or network step');
check(preg_match('/if \(!\$is_resend\) \{\s*\$existing = \$P::existingHost\(/', $body) === 1,'only a first Send is refused; a Resend of our own host is not');

// hostsMatching(): the queue loads the host list once per connection.
$all = [['jms_id' => 'h-1', 'name' => 'VM-APP-07', 'address' => '10.0.0.157']];
check(($P::matchExisting('VM-APP-07', '', $all)['jms_id'] ?? null) === 'h-1', 'matchExisting works on a preloaded list');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
