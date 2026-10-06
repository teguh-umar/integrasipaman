<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_eligibility_test.php
//
// Two things are pinned here. First, which address gets sent to PAMAN: the
// old code took LIMIT 1 with no ORDER BY, so a Computer with several
// network ports sent whichever row MySQL felt like returning - including a
// loopback address, which produces a host in the bastion that can never be
// reached. Second, the criteria matrix, including the shape everyone will
// actually have on day one: every filter empty.
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
if (!class_exists('CommonDBTM')) { class CommonDBTM {} }

// GLPI's translation helpers do not exist outside a GLPI bootstrap; the
// classes under test build their admin-facing messages with them.
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('_n')) { function _n($s, $p, $n, $d = null) { return $n > 1 ? $p : $s; } }

require_once __DIR__ . '/../inc/provisioning.class.php';

// v3.11.0: the label comes from the connection's URL, not from config.
PluginIntegrasipamanConnection::setCurrent(['url' => 'https://paman.example.org']);

$P = 'PluginIntegrasipamanProvisioning';

// --- address selection ------------------------------------------------
check($P::pickAddress(['10.0.0.24']) === '10.0.0.24', 'a single routable address is used');
check($P::pickAddress([]) === '', 'no addresses at all yields the empty string');
check($P::pickAddress(['127.0.0.1']) === '', 'loopback alone is not an address worth sending');
check($P::pickAddress(['0.0.0.0']) === '', '0.0.0.0 is rejected');
check($P::pickAddress(['169.254.11.9']) === '', 'an APIPA address is rejected - it means DHCP failed');
check($P::pickAddress(['fe80::1']) === '', 'IPv6 is rejected: PAMAN hosts here are addressed by IPv4');
check($P::pickAddress(['127.0.0.1', '10.0.0.24']) === '10.0.0.24',
      'a routable address wins over loopback regardless of order');
check($P::pickAddress(['10.0.0.24', '127.0.0.1']) === '10.0.0.24',
      '...and the same in the other order');

// Determinism matters more than which one is "right": the same Computer
// must not send a different address on Tuesday than it did on Monday, or
// every resend looks like drift.
check($P::pickAddress(['10.0.1.8', '10.0.0.24']) === $P::pickAddress(['10.0.0.24', '10.0.1.8']),
      'two routable addresses resolve to the same choice whatever the row order');

// --- the criteria matrix ---------------------------------------------
$empty = ['types' => [], 'states' => [], 'dispose_states' => [], 'entities' => [], 'ip_prefixes' => []];
$server = ['computertypes_id' => 3, 'states_id' => 1, 'entities_id' => 0, 'is_deleted' => 0];

// Review Focus 4: a fresh install has every filter empty. Document what
// that means rather than pretending otherwise - the brake is the mapping
// requirement (Task 5) and the button, not this function.
$r = $P::isEligible($server, '10.0.0.24', $empty);
check($r['eligible'] === true, 'with every filter empty, an ordinary Computer is eligible');

$r = $P::isEligible($server, '', $empty);
check($r['eligible'] === false, 'a Computer with no usable address is never eligible');
check(strpos(strtolower($r['reason']), 'address') !== false,
      '...and the reason says so in words the admin can act on');

$r = $P::isEligible(['computertypes_id' => 3, 'states_id' => 1, 'entities_id' => 0, 'is_deleted' => 1], '10.0.0.24', $empty);
check($r['eligible'] === false, 'a Computer in the GLPI trash is not eligible');

$types = ['types' => [3, 4]] + $empty;
check($P::isEligible($server, '10.0.0.24', $types)['eligible'] === true,  'a listed type passes the type filter');
check($P::isEligible(['computertypes_id' => 9] + $server, '10.0.0.24', $types)['eligible'] === false,
      'an unlisted type is filtered out');

$states = ['states' => [1, 2]] + $empty;
check($P::isEligible(['states_id' => 7] + $server, '10.0.0.24', $states)['eligible'] === false,
      'an unlisted status is filtered out');

$ents = ['entities' => [5]] + $empty;
check($P::isEligible(['entities_id' => 5] + $server, '10.0.0.24', $ents)['eligible'] === true,  'a listed entity passes');
check($P::isEligible(['entities_id' => 6] + $server, '10.0.0.24', $ents)['eligible'] === false, 'an unlisted entity is filtered out');

$prefixes = ['ip_prefixes' => ['10.0.0.', '10.0.1.']] + $empty;
check($P::isEligible($server, '10.0.0.24', $prefixes)['eligible'] === true,  'an address on a listed prefix passes');
check($P::isEligible($server, '192.168.4.9', $prefixes)['eligible'] === false, 'an address off every listed prefix is filtered out');

// Filters combine with AND: passing one is not passing all.
$both = ['types' => [3], 'ip_prefixes' => ['192.168.']] + $empty;
check($P::isEligible($server, '10.0.0.24', $both)['eligible'] === false,
      'filters are ANDed - the right type on the wrong network is still out');

// --- dispose --------------------------------------------------------
$crit = ['dispose_states' => [7]] + $empty;
check($P::isDisposed(['states_id' => 7, 'is_deleted' => 0], $crit) === true,
      'a Computer in a configured dispose status is disposed');
check($P::isDisposed(['states_id' => 1, 'is_deleted' => 0], $crit) === false,
      'an active Computer is not disposed');
check($P::isDisposed(['states_id' => 1, 'is_deleted' => 1], $crit) === true,
      'a Computer deleted in GLPI counts as disposed - it needs taking out of PAMAN too');
check($P::isDisposed(['states_id' => 7, 'is_deleted' => 0], $empty) === false,
      'with no dispose status configured, nothing is ever auto-marked for removal');

// --- node / platform resolution --------------------------------------
// Review Focus 4, second half: eligibility alone must not make a row
// sendable. A fresh install has no mappings, so every row is listed with a
// written reason and a dead checkbox - the admin is pointed at the form to
// fill in, not handed a failure.
$computer = [
    'computertypes_id' => 3, 'type_name' => 'Server',
    'operatingsystems_id' => 12, 'os_name' => 'Windows Server 2019',
];

$r = $P::resolveTargets($computer, [], []);
check($r['blocked'] !== '', 'with no mappings at all, the row is blocked rather than sent');
check(strpos($r['blocked'], 'Server') !== false,
      'the block reason names the unmapped computer type, so the admin knows what to fill in');

$nodes = [3 => ['id' => 'node-hq-app', 'label' => '/HQ/App']];
$r = $P::resolveTargets($computer, $nodes, []);
check($r['node_id'] === 'node-hq-app', 'a mapped type resolves its node');
check($r['blocked'] !== '', '...but an unmapped OS still blocks the row');
check(strpos($r['blocked'], 'Windows Server 2019') !== false,
      'the block reason names the unmapped OS by its GLPI label');

$platforms = [12 => ['id' => 5, 'label' => 'Windows']];
$r = $P::resolveTargets($computer, $nodes, $platforms);
check($r['blocked'] === '', 'with both mapped, nothing blocks the send');
check($r['node_id'] === 'node-hq-app' && $r['platform_id'] === 5, 'both targets resolve');
check($r['node_label'] === '/HQ/App' && $r['platform_label'] === 'Windows',
      'labels travel with the ids, for display when PAMAN later renames or removes them');

// --- the default rows are the safety net ------------------------------
$default_nodes     = [0 => ['id' => 'node-unsorted', 'label' => '/Default']];
$default_platforms = [0 => ['id' => 1, 'label' => 'Linux']];
$r = $P::resolveTargets($computer, $default_nodes, $default_platforms);
check($r['blocked'] === '', 'the default rows (source id 0) catch anything unmapped');
check($r['node_id'] === 'node-unsorted' && $r['platform_id'] === 1, 'the defaults supply both targets');

$r = $P::resolveTargets($computer, $nodes + $default_nodes, $default_platforms);
check($r['node_id'] === 'node-hq-app',
      'an explicit mapping beats the default - the default is a fallback, not an override');

// An empty id stored in a mapping row is not a mapping. A half-filled row
// that silently resolved would send assets to node "" and PAMAN would
// reject the lot with a message about a field the admin never saw.
$r = $P::resolveTargets($computer, [3 => ['id' => '', 'label' => 'broken']], $platforms);
check($r['blocked'] !== '', 'a mapping row with an empty node id blocks rather than sending nothing');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
