<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_payload_test.php
//
// The signature is the one thing here that cannot be caught by reading the
// code: a wrong string-to-sign is a 401 in production and looks exactly
// like a wrong secret. These vectors pin the exact bytes this plugin sends.
//
// They pin what the code DOES, not what PAMAN accepts for a write: the
// header list is proven live for GET only, and the write probe has never
// been run. These tests stay green even if PAMAN rejects every POST.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

define('GLPI_ROOT', __DIR__);

// The messages name the server by the label derived from this URL (v3.10.0).
class Config
{
    public static function getConfigurationValues($context, $keys = [])
    {
        return ['jumpserver_url' => 'https://paman.example.org'];
    }
}

// GLPI's translation helpers do not exist outside a GLPI bootstrap; the
// classes under test build their admin-facing messages with them.
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('_n')) { function _n($s, $p, $n, $d = null) { return $n > 1 ? $p : $s; } }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }

require_once __DIR__ . '/../inc/jmsapi.class.php';

$date = 'Mon, 01 Sep 2026 00:00:00 GMT';
$post = PluginIntegrasipamanJmsApi::buildAuthHeader('POST', '/api/v1/assets/hosts/', $date, 'ORG', 'KID', 'SECRET');
$get  = PluginIntegrasipamanJmsApi::buildAuthHeader('GET',  '/api/v1/assets/hosts/', $date, 'ORG', 'KID', 'SECRET');
check($post !== $get, 'POST and GET produce different signatures for the same path');

$expected_sts = "(request-target): post /api/v1/assets/hosts/\naccept: application/json\ndate: {$date}\nx-jms-org: ORG";
$expected_sig = base64_encode(hash_hmac('sha256', $expected_sts, 'SECRET', true));
check(strpos($post, 'signature="' . $expected_sig . '"') !== false,
      'POST signature matches the lowercase-verb string-to-sign');

check(strpos($post, 'headers="(request-target) accept date x-jms-org"') !== false,
      'the advertised header list matches what was signed');
check(strpos($post, 'keyId="KID"') !== false, 'keyId carries the access key id');

// --- the payload shape PAMAN v4.10.19 actually accepts -----------------
if (!class_exists('CommonDBTM')) { class CommonDBTM {} }
require_once __DIR__ . '/../inc/provisioning.class.php';
$P = 'PluginIntegrasipamanProvisioning';

$computer = ['id' => 123, 'name' => 'VM-HQ-APP-01', 'comment' => 'app server'];
$payload  = $P::buildPayload($computer, '10.0.0.24', 'node-hq-app', 5);

check(($payload['address'] ?? null) === '10.0.0.24',
      'the address goes in `address` - `ip` is the v2 field name and is what made the old code fail');
check(!array_key_exists('ip', $payload), 'no `ip` key is sent at all');
check(($payload['name'] ?? null) === 'VM-HQ-APP-01', 'the GLPI name is the PAMAN host name');
check(($payload['platform']['id'] ?? null) === 5, 'platform is sent as an object carrying an int id');
check(($payload['nodes'][0]['id'] ?? null) === 'node-hq-app', 'nodes is a list of objects carrying ids');
check(count($payload['nodes'] ?? []) === 1, 'exactly one node is sent');
check(($payload['is_active'] ?? null) === true, 'the host is created active');
check(strpos((string) ($payload['comment'] ?? ''), '123') !== false,
      'the comment carries the GLPI Computer id, so PAMAN points back at the source of truth');

// --- the hash is order-independent and content-sensitive --------------
$same_other_order = ['address' => '10.0.0.24'] + $payload;
check($P::payloadHash($payload) === $P::payloadHash($same_other_order),
      'the hash ignores key order - otherwise an unrelated code change would flag every asset as drifted');

$moved = $payload;
$moved['address'] = '10.0.0.25';
check($P::payloadHash($payload) !== $P::payloadHash($moved), 'a changed address changes the hash');

// --- drift names the fields, not just the fact ------------------------
$drift = $P::driftFields($payload, $moved);
check($drift === ['address'], 'drift names exactly the field that moved (got ' . implode(',', $drift) . ')');

$renamed = $payload;
$renamed['name']    = 'VM-HQ-APP-02';
$renamed['comment'] = 'renamed';
$drift = $P::driftFields($payload, $renamed);
sort($drift);
check($drift === ['comment', 'name'], 'two changed fields are both named');

check($P::driftFields($payload, $payload) === [], 'an unchanged payload drifts in nothing');

// A node or platform change is drift too: an asset that moved to another
// node in GLPI terms is in the wrong place in the bastion.
$moved_node = $payload;
$moved_node['nodes'] = [['id' => 'node-hq-db']];
check($P::driftFields($payload, $moved_node) === ['nodes'], 'a node change is drift');

// --- nested key order must not register as drift either ----------------
// This is the case the top-level ksort() missed: platform/nodes are arrays,
// and PHP's !== is order-sensitive for them.
$nested_a = $payload;
$nested_a['platform'] = ['id' => 5, 'name' => 'Linux'];
$nested_b = $payload;
$nested_b['platform'] = ['name' => 'Linux', 'id' => 5];
check($P::payloadHash($nested_a) === $P::payloadHash($nested_b),
      'a nested key reordered does not change the hash');
check($P::driftFields($nested_a, $nested_b) === [],
      'a nested key reordered is not drift');

// ...but a nested VALUE change still is.
$nested_c = $payload;
$nested_c['platform'] = ['id' => 6, 'name' => 'Linux'];
check($P::driftFields($nested_a, $nested_c) === ['platform'],
      'a changed nested value is still drift');

// The order of nodes is data, not incidental - two nodes in the other order
// are a different placement in PAMAN.
$two_a = $payload;
$two_a['nodes'] = [['id' => 'node-a'], ['id' => 'node-b']];
$two_b = $payload;
$two_b['nodes'] = [['id' => 'node-b'], ['id' => 'node-a']];
check($P::driftFields($two_a, $two_b) === ['nodes'],
      'reordered nodes ARE drift - a list order is meaningful, unlike a key order');

// --- lookup-before-create --------------------------------------------
// Production PAMAN already holds assets created by hand over years. A send
// that POSTs blindly would duplicate dozens of hosts on its first run.
require_once __DIR__ . '/../inc/provisioningaction.class.php';

// v3.11.0: the label comes from the connection's URL, not from config.
PluginIntegrasipamanConnection::setCurrent(['url' => 'https://paman.example.org']);
$A = 'PluginIntegrasipamanProvisioningAction';

$r = $A::interpretLookup([]);
check($r['action'] === 'create', 'no host at that address => create one');

$r = $A::interpretLookup([['id' => 'abc-123', 'name' => 'VM-HQ-APP-01']]);
check($r['action'] === 'adopt', 'exactly one host at that address => adopt it rather than duplicate it');
check($r['asset_id'] === 'abc-123', 'the adopted host id is carried back');

// Review Focus 2: two hosts share the address. Adoption assumes one, and
// picking the first is how the wrong production host gets overwritten.
$r = $A::interpretLookup([['id' => 'abc-123'], ['id' => 'def-456']]);
check($r['action'] === 'refuse', 'two hosts at one address => refuse and let a human decide');
check(strpos($r['error'], 'abc-123') !== false && strpos($r['error'], 'def-456') !== false,
      'the refusal names both host ids, so the admin can go and look at them');

// --- Review Focus 3: a resend must never create --------------------
$m = $A::methodFor('');
check($m['method'] === 'POST', 'with no asset id, the send creates');
check($m['path'] === '/api/v1/assets/hosts/', 'creation posts to the collection');

$m = $A::methodFor('abc-123');
check($m['method'] === 'PATCH', 'with an asset id, the send updates - a resend that POSTs is the duplication bug');
check($m['path'] === '/api/v1/assets/hosts/abc-123/', 'the update targets that host');

// --- what PAMAN said survives the call -------------------------------
$r = $A::interpretResponse(201, ['id' => 'abc-123'], '');
check($r['ok'] === true, 'a 201 is success');

$r = $A::interpretResponse(400, ['platform' => ['This field is required.']], '');
check($r['ok'] === false, 'a 400 is a failure');
check(strpos($r['message'], 'platform') !== false && strpos($r['message'], 'required') !== false,
      "PAMAN's own words survive into the message - 'failed to send asset' is not actionable");

$r = $A::interpretResponse(0, null, 'Could not resolve host');
check($r['ok'] === false && strpos($r['message'], 'Could not resolve host') !== false,
      'a transport failure reports the curl error rather than a bare zero');

$r = $A::interpretResponse(404, null, '');
check($r['ok'] === false, 'a 404 on a send is a failure (it is only success when removing)');

// --- fix round 1: a 2xx create with no id must not weaken the resend
// guarantee. doSend() now treats an empty asset id after a create as a
// failure (an empty jumpserver_asset_id would make the next send take the
// non-resend path again, risking a second POST); methodFor('') must still
// unconditionally answer POST, since that pure decision is unrelated to
// how doSend() interprets the id afterwards.
$m = $A::methodFor('');
check($m['method'] === 'POST', 'methodFor(\'\') still creates - the resend guarantee is not weakened by the empty-id fix');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
