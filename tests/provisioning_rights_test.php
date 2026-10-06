<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_rights_test.php
//
// This right controls who may add an asset to the production bastion and,
// separately, who may take one out. Modelled on profile_rights_test.php.
// The assertions that matter most are the negative ones: nobody is granted
// these bits by an upgrade, and no controller is reachable without them.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$root    = __DIR__ . '/..';
$profile = (string) file_get_contents($root . '/inc/profile.class.php');

// --- the right exists and is installed --------------------------------
check(strpos($profile, "public const RIGHT_PROVISIONING = 'plugin_integrasipaman_provisioning';") !== false,
      'RIGHT_PROVISIONING is declared');
preg_match('/public static function names\(\): array\s*\{(.*?)\}/s', $profile, $m);
check(strpos($m[1] ?? '', 'RIGHT_PROVISIONING') !== false,
      'names() includes it - otherwise the right is never created and every check silently denies');

// --- the three bits are all offered in the matrix ---------------------
preg_match('/RIGHT_PROVISIONING,?\s*\]/s', $profile, $unused);
$i = strpos($profile, 'self::RIGHT_PROVISIONING,');
check($i !== false, 'the matrix has a row bound to the provisioning right');
$block = substr($profile, max(0, (int) $i - 700), 800);
check(strpos($block, 'READ') !== false,   'the matrix offers READ');
check(strpos($block, 'CREATE') !== false, 'the matrix offers CREATE (send / resend)');
check(strpos($block, 'PURGE') !== false,  'the matrix offers PURGE (remove from PAMAN)');
check(strpos($block, 'UPDATE') === false,
      'UPDATE is NOT offered - there is no third thing to do, and an unused bit invites being checked for by mistake');

// --- who is granted it by an install or an upgrade --------------------
// Until 2026-09-24 this asserted that seedRights() granted it to NOBODY.
// The user rejected that exception: the suite rule "Super-Admin selalu
// penuh" applies, so Super-Admin gets it in full and everyone else gets
// nothing. Inverted rather than removed - the seeding of a right that
// authorises writes to a production bastion should never be able to change
// without a test changing with it.
preg_match('/public static function seedRights\(\): void\s*\{(.*?)\n    \}/s', $profile, $m);
$seed = $m[1] ?? '';
check($seed !== '', 'seedRights() was found');
check(preg_match('/RIGHT_PROVISIONING\s*=>\s*READ \| CREATE \| PURGE/', $seed) === 1,
      'seedRights() grants the FULL provisioning right');
check(substr_count($seed, 'RIGHT_PROVISIONING =>') === 1,
      'the provisioning right is written in exactly ONE branch - a second write would be a non-admin being seeded');

// The single write must sit inside the `if ($is_admin)` branch. A correct
// mask in the wrong branch hands every profile the bastion.
$admin_at = strpos($seed, 'if ($is_admin)');
$prov_at  = strpos($seed, 'RIGHT_PROVISIONING =>');
check($admin_at !== false && $prov_at !== false && $prov_at > $admin_at,
      'the provisioning grant sits inside the is_admin branch, not before it');

// --- every controller enforces it server-side -------------------------
$queue_path = $root . '/front/provisioning.php';

// Task 10 creates this file. Asserting its existence directly means the
// SKIP branch below can never again pass silently just because the file
// was renamed or moved out from under this test.
check(file_exists($queue_path), 'front/provisioning.php exists - the four right-gating checks below are real, not skipped');

if (!file_exists($queue_path)) {
    // The queue page arrives in a later task. These four assertions become
    // real the moment it does; until then they are skipped rather than
    // failed, so this task's suite is honestly green.
    echo "SKIP front/provisioning.php does not exist yet - its four right-gating checks are deferred\n";
} else {
    $queue = (string) file_get_contents($queue_path);
    check(strpos($queue, 'Session::checkRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, READ)') !== false,
          'the queue page requires READ to open at all');
    check(preg_match('/haveRight\(\s*PluginIntegrasipamanProfile::RIGHT_PROVISIONING,\s*CREATE\s*\)/', $queue) === 1,
          'the send path checks CREATE');
    check(preg_match('/haveRight\(\s*PluginIntegrasipamanProfile::RIGHT_PROVISIONING,\s*PURGE\s*\)/', $queue) === 1,
          'the removal path checks PURGE');

    // A hidden button is a courtesy, not a control: the POST branch must check
    // the bit itself, not rely on the button having been rendered.
    preg_match('/POST.*?remove/s', $queue, $unused2);
    check(substr_count($queue, 'RIGHT_PROVISIONING') >= 4,
          'the right is checked in the POST branches, not only where buttons are drawn');
}

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
