<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_ticket_test.php
//
// The ticket is the only thing standing between "this asset looks retired"
// and a host deleted out of a production bastion. All three conditions are
// exercised in isolation, because a check that passes when two of three
// hold is not a control.
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

// GLPI's translation helpers and CommonDBTM do not exist outside a GLPI
// bootstrap; the classes under test build admin-facing messages with them
// and provisioning.class.php extends CommonDBTM.
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('_n')) { function _n($s, $p, $n, $d = null) { return $n > 1 ? $p : $s; } }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!class_exists('CommonDBTM')) { class CommonDBTM {} }

require_once __DIR__ . '/../inc/provisioningaction.class.php';

// v3.11.0: the label comes from the connection's URL, not from config.
PluginIntegrasipamanConnection::setCurrent(['url' => 'https://paman.example.org']);
$A = 'PluginIntegrasipamanProvisioningAction';

$closed   = ['id' => 4711, 'status' => 6, 'is_deleted' => 0];
$allowed  = [5, 6];

$r = $A::validateTicket($closed, [123], $allowed, 123);
check($r['ok'] === true, 'a closed ticket linked to this Computer authorises the removal');

// 1. the ticket must exist
$r = $A::validateTicket([], [123], $allowed, 123);
check($r['ok'] === false, 'a ticket number that matches nothing is refused');
check(strpos(strtolower($r['reason']), 'not found') !== false, '...and says the ticket was not found');

// 2. the status must be one the admin nominated
$r = $A::validateTicket(['id' => 4711, 'status' => 2, 'is_deleted' => 0], [123], $allowed, 123);
check($r['ok'] === false, 'a ticket still being processed is not an approval');
check(strpos(strtolower($r['reason']), 'status') !== false, '...and the reason names the status');

$r = $A::validateTicket(['id' => 4711, 'status' => 6, 'is_deleted' => 1], [123], $allowed, 123);
check($r['ok'] === false, 'a ticket in the GLPI trash is refused however closed it looks');

// 3. Review Focus 5: the ticket must be linked to THIS Computer.
// Without this, one old closed ticket number authorises removing anything.
$r = $A::validateTicket($closed, [999], $allowed, 123);
check($r['ok'] === false, 'a valid closed ticket linked to a different Computer is refused');
check(strpos($r['reason'], '123') !== false || strpos(strtolower($r['reason']), 'linked') !== false,
      '...and the reason names the mismatch rather than just saying no');

$r = $A::validateTicket($closed, [], $allowed, 123);
check($r['ok'] === false, 'a ticket linked to no asset at all is refused');

$r = $A::validateTicket($closed, [55, 123, 900], $allowed, 123);
check($r['ok'] === true, 'a ticket covering several assets is fine as long as this one is among them');

// --- an empty allow-list must not mean "anything goes" ----------------
$r = $A::validateTicket($closed, [123], [], 123);
check($r['ok'] === false,
      'with no ticket statuses configured, removal is refused outright - an unset control is not an open gate');

// --- a 404 on DELETE is the end state we wanted ----------------------
$r = $A::interpretRemoval(204, null, '');
check($r['ok'] === true, 'a 204 is a successful delete');

$r = $A::interpretRemoval(404, null, '');
check($r['ok'] === true, 'a 404 counts as success: the host is gone, which is what was asked for');
check(strpos(strtolower($r['message']), 'already') !== false,
      '...but it says so, rather than pretending it did the deleting');

$r = $A::interpretRemoval(403, ['detail' => 'permission denied'], '');
check($r['ok'] === false && strpos($r['message'], 'permission denied') !== false,
      'a refusal by PAMAN is a failure that quotes PAMAN');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
