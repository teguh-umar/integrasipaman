<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_needs_mapping_test.php
//
// v4.14.0-rc3 (owner, 2026-11-07): "Eligible" only means something when it
// can be sent. A Computer whose node or platform cannot be resolved showed
// "Eligible" and an active Send that could only fail on the confirm page.
// The tab now says "Eligible - needs mapping", disables Send, and links to
// the two mapping pages. The queue page already disabled its checkbox for
// such rows; it now links to the mapping pages too. Display only: the row's
// status in the database does not change.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('htmlescape')) { function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!class_exists('CommonDBTM')) { class CommonDBTM {} }
class Config { public static function getConfigurationValues($c, $k = []) { return ['jumpserver_url' => 'https://paman.example.org']; } }
class Session { public static bool $send = true; public static function haveRight($r, $b) { return self::$send; } }
class Plugin { public static function getWebDir($p) { return '/plugins/' . $p; } }
class Html {
    public static function hidden($n, $o) { return '<input type="hidden" name="' . $n . '" value="' . $o['value'] . '">'; }
    public static function submit($l, $o) { return '<button type="submit" name="' . $o['name'] . '">' . $l . '</button>'; }
    public static function closeForm() { echo '</form>'; }
}
class PluginIntegrasipamanProfile { public const RIGHT_PROVISIONING = 'x'; }
if (!defined('CREATE')) { define('CREATE', 4); define('PURGE', 16); define('READ', 1); }

require_once __DIR__ . '/../inc/provisioning.class.php';
PluginIntegrasipamanConnection::setCurrent(['url' => 'https://paman.example.org']);
$P = 'PluginIntegrasipamanProvisioning';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$render = (new ReflectionClass($P))->getMethod('renderButtons');
$render->setAccessible(true);
$html = static function (string $status, string $blocked) use ($render): string {
    ob_start();
    $render->invoke(null, 42, $status, $blocked);
    return (string) ob_get_clean();
};

$ok = $html($P::STATUS_ELIGIBLE, '');
check(str_contains($ok, 'name="confirm_send"') && str_contains($ok, '>Send<'), 'resolvable: an active Send, as before');

$blocked = $html($P::STATUS_ELIGIBLE, 'Computer type Virtual Machine is not mapped to a PAMAN node');
check(!str_contains($blocked, 'name="confirm_send"'), 'needs mapping: no Send form is posted');
check(preg_match('/<button[^>]*disabled[^>]*>[^<]*Send/', $blocked) === 1, 'needs mapping: Send is shown disabled');
check(str_contains($blocked, '/plugins/integrasipaman/front/nodemap.php') && str_contains($blocked, '/plugins/integrasipaman/front/platformmap.php'),
    'needs mapping: links to the node and platform mapping pages');

$drift = $html($P::STATUS_DRIFT, 'OS Ubuntu is not mapped to a PAMAN platform');
check(!str_contains($drift, 'name="confirm_send"') && str_contains($drift, 'Resend'), 'a Resend that cannot resolve is disabled too');

Session::$send = false;
check(!str_contains($html($P::STATUS_ELIGIBLE, 'x'), 'nodemap.php'), 'without the right to send, no button and no links');
Session::$send = true;

// The status line: "Eligible - needs mapping" when blocked, plain otherwise.
check($P::statusLabel($P::STATUS_ELIGIBLE, 'x') === 'Eligible - needs mapping', 'blocked Eligible reads "Eligible - needs mapping"');
check($P::statusLabel($P::STATUS_ELIGIBLE, '') === 'Eligible', 'resolvable Eligible reads "Eligible"');
check($P::statusLabel($P::STATUS_PROVISIONED, 'x') === 'Provisioned', 'Provisioned is never relabelled');

$queue = file_get_contents(__DIR__ . '/../front/provisioning.php');
check(str_contains($queue, 'mappingLinks()'), 'the queue page links its blocked rows to the mapping pages');
check(str_contains($queue, "statusLabel((string) \$row['status'], \$blocked_reason)"),'the queue Status column says "Eligible - needs mapping" too');

// rc4 (VM, 2026-11-07): the two mapping pages listed "No results found" with
// no way to add a row. GLPI draws its "+ Add" from the menu entry, and these
// pages sit under "provisioning", which declares none; nothing else linked to
// the .form.php pages. Each list page now offers its own Add, to users who may
// create a mapping.
foreach (['nodemap' => 'PluginIntegrasipamanNodeMap', 'platformmap' => 'PluginIntegrasipamanPlatformMap'] as $page => $class) {
    $src = file_get_contents(__DIR__ . "/../front/$page.php");
    check(str_contains($src, "$class::canCreate()") && str_contains($src, "$page.form.php"),
        "front/$page.php offers an Add link to $page.form.php, gated on canCreate()");
}

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
