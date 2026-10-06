<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_state_test.php
//
// The state machine is the safety property of this feature: a row may only
// reach `removed` through `pending_removal`, and may only be `provisioned`
// once PAMAN has handed back an asset id. Everything else in the queue
// trusts the status column, so an illegal transition is not a cosmetic bug
// - it is an asset deleted from a production bastion by the wrong path.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

define('GLPI_ROOT', __DIR__);
// GLPI's translation helpers do not exist outside a GLPI bootstrap; the
// classes under test build their admin-facing messages with them.
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('_n')) { function _n($s, $p, $n, $d = null) { return $n > 1 ? $p : $s; } }
// CommonDBTM is not available outside GLPI; the pure helpers under test are
// static and never touch $this, so a stub parent is enough to load the file.
if (!class_exists('CommonDBTM')) { class CommonDBTM {} }
require_once __DIR__ . '/../inc/provisioning.class.php';

$P = 'PluginIntegrasipamanProvisioning';

// --- the six statuses, and nothing else -------------------------------
$all = $P::allStatuses();
sort($all);
check($all === ['drift', 'eligible', 'failed', 'pending_removal', 'provisioned', 'removed'],
      'allStatuses() holds exactly the six known statuses (got ' . implode(',', $all) . ')');

// --- the legal journey ------------------------------------------------
check($P::canTransition('eligible', 'provisioned'),        'eligible -> provisioned (a send succeeded)');
check($P::canTransition('provisioned', 'drift'),           'provisioned -> drift (GLPI data changed)');
check($P::canTransition('drift', 'provisioned'),           'drift -> provisioned (a resend succeeded)');
check($P::canTransition('provisioned', 'pending_removal'), 'provisioned -> pending_removal (status went dispose)');
check($P::canTransition('drift', 'pending_removal'),       'drift -> pending_removal (dispose wins over drift)');
check($P::canTransition('pending_removal', 'removed'),     'pending_removal -> removed (a ticketed removal succeeded)');

// --- failure is reachable from any attempt, and always retryable ------
foreach (['eligible', 'provisioned', 'drift', 'pending_removal'] as $from) {
    check($P::canTransition($from, 'failed'), "{$from} -> failed (an attempt errored)");
}
check($P::canTransition('failed', 'provisioned'),      'failed -> provisioned (the retry worked)');
check($P::canTransition('failed', 'removed'),          'failed -> removed (the retried removal worked)');
check($P::canTransition('failed', 'pending_removal'),  'failed -> pending_removal (back to the queue)');

// --- the transitions that must never happen ---------------------------
check(!$P::canTransition('eligible', 'removed'),
      'eligible -> removed is refused: there is nothing in PAMAN to remove');
check(!$P::canTransition('eligible', 'drift'),
      'eligible -> drift is refused: nothing was ever sent to drift from');
check(!$P::canTransition('removed', 'provisioned'),
      'removed -> provisioned is refused: a removed row is a permanent audit record');
check(!$P::canTransition('removed', 'eligible'),
      'removed -> eligible is refused: re-provisioning starts a new row, it does not rewrite history');
check(!$P::canTransition('pending_removal', 'provisioned'),
      'pending_removal -> provisioned is refused: a disposed asset is not resent');

check($P::isTerminal('removed'), 'removed is terminal');
check(!$P::isTerminal('failed'), 'failed is NOT terminal - every failure is retryable');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
