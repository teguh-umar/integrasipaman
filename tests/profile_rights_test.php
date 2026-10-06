<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/profile_rights_test.php
//
// Covers PluginIntegrasipamanProfile::seedRights(), the one piece of v2.0.0
// that runs on every `plugin:install --force` upgrade and can silently
// overwrite what an admin set in the rights matrix.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

define('READ', 1);
define('UPDATE', 2);
// GLPI's real bit values - the provisioning right uses CREATE and PURGE,
// and a stub with invented values would let a wrong mask pass.
define('CREATE', 4);
define('PURGE', 1024);

function __($s, $d = null) { return $s; }
function _sx($ctx, $s, $d = null) { return $s; }
function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

class CommonGLPI {}
class CommonDBTM extends CommonGLPI {}

/** Stand-in for the instance's profiles + their stored rights. */
class FakeStore
{
    /** @var array<int, array<string, int>> profile id => right name => mask */
    public static array $rights = [];
    /** @var list<string> right names known to the instance */
    public static array $possible = [];
    /** @var list<array{0:int,1:array<string,int>}> every updateProfileRights() call */
    public static array $writes = [];
    public static array $added = [];
}

class Profile extends CommonDBTM
{
    public array $fields = [];

    public function find()
    {
        return array_map(static fn($id) => ['id' => $id], array_keys(FakeStore::$rights));
    }

    public function getFromDB($id)
    {
        if (!isset(FakeStore::$rights[$id])) {
            return false;
        }
        $this->fields = ['id' => $id] + FakeStore::$rights[$id];
        return true;
    }

    public function getID() { return $this->fields['id'] ?? -1; }
    public static function canUpdate(): bool { return true; }
    public static function getFormURL($full = true) { return '/front/profile.form.php'; }
    public static function createTabEntry($text, $nb = 0, $itemtype = null, $icon = null) { return $text; }
    public function displayRightsChoiceMatrix(array $rights = [], array $options = []) {}
}

class ProfileRight
{
    public static function getAllPossibleRights()
    {
        return array_fill_keys(FakeStore::$possible, '');
    }

    public static function addProfileRights(array $rights)
    {
        foreach ($rights as $r) {
            if (in_array($r, FakeStore::$possible, true)) {
                throw new RuntimeException("UNIQUE key violation: $r already exists");
            }
            FakeStore::$possible[] = $r;
            FakeStore::$added[]    = $r;
        }
    }

    public static function updateProfileRights($id, array $rights)
    {
        FakeStore::$writes[] = [$id, $rights];
        foreach ($rights as $name => $mask) {
            FakeStore::$rights[$id][$name] = $mask;
        }
    }

    public static function deleteProfileRights(array $names)
    {
        FakeStore::$possible = array_values(array_diff(FakeStore::$possible, $names));
        foreach (FakeStore::$rights as $id => $r) {
            foreach ($names as $n) {
                unset(FakeStore::$rights[$id][$n]);
            }
        }
    }
}

class Session { public static function haveRight($n, $r) { return true; } }
class Html {}

require __DIR__ . '/../inc/profile.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$P             = 'PluginIntegrasipamanProfile';
$CONFIG        = PluginIntegrasipamanProfile::RIGHT_CONFIG;
$REPORT        = PluginIntegrasipamanProfile::RIGHT_REPORT;
$PROVISIONING  = PluginIntegrasipamanProfile::RIGHT_PROVISIONING;

/**
 * 1 = super-admin (core config READ|UPDATE)
 * 2 = a reader of Setup (core config READ)
 * 3 = self-service (no core config at all)
 */
function reset_instance(array $extra_rights = []): void
{
    FakeStore::$rights = [
        1 => ['config' => READ | UPDATE],
        2 => ['config' => READ],
        3 => ['config' => 0],
    ];
    foreach ($extra_rights as $id => $r) {
        FakeStore::$rights[$id] = array_merge(FakeStore::$rights[$id] ?? [], $r);
    }
    FakeStore::$possible = ['config', 'computer'];
    FakeStore::$writes   = [];
    FakeStore::$added    = [];
}

// --- fresh install: the split applies from the start -----------------------
reset_instance();
$P::seedRights();

check(FakeStore::$added === [$CONFIG, $REPORT, $PROVISIONING], 'fresh install creates all three rights exactly once');
check(FakeStore::$rights[1][$CONFIG] === (READ | UPDATE), 'fresh: config/UPDATE profile gets config READ|UPDATE');
check(FakeStore::$rights[1][$REPORT] === READ,            'fresh: config/UPDATE profile gets report READ');
check(FakeStore::$rights[2][$REPORT] === READ,            'fresh: core config/READ buys the reports');
check(FakeStore::$rights[2][$CONFIG] === 0,               'fresh: core config/READ does NOT buy the settings');
check(FakeStore::$rights[3][$CONFIG] === 0 && FakeStore::$rights[3][$REPORT] === 0,
      'fresh: profile without core config gets neither');

// --- upgrade from the combined right (v2.0.0 -> v2.4.0) + provisioning (v2.4.0 -> current) -
// The migration promise: the report right is derived from whatever the
// combined right held, and RIGHT_CONFIG is never written for a non-admin, so
// a hand-narrowed mask survives. Run it TWICE - plugin:install --force
// re-runs this on every upgrade, and a revocation that came back each time
// would be worse than one that never happened. PROVISIONING follows the
// suite rule (user decision 2026-09-24): Super-Admin gets it in full every
// run, everyone else starts and stays at zero.
reset_instance([
    1 => [$CONFIG => READ],            // super-admin, narrowed by hand
    2 => [$CONFIG => 0],               // revoked by hand
    3 => [$CONFIG => READ | UPDATE],   // granted by hand to a non-Setup profile
]);
FakeStore::$possible[] = $CONFIG;
$P::seedRights();

check(FakeStore::$added === [$REPORT, $PROVISIONING], 'upgrade adds the report right and provisioning right');
check(FakeStore::$rights[2][$CONFIG] === 0,
      'upgrade: hand-revoked config right survives');
check(FakeStore::$rights[2][$REPORT] === 0,
      'upgrade: a profile with no combined access gets no report right either');
check(FakeStore::$rights[3][$CONFIG] === (READ | UPDATE),
      'upgrade: hand-granted config right survives untouched');
check(FakeStore::$rights[3][$REPORT] === READ,
      'upgrade: the report right is inherited from the combined right');
check(FakeStore::$rights[1][$CONFIG] === (READ | UPDATE) && FakeStore::$rights[1][$REPORT] === READ,
      'upgrade: super-admin is re-granted both every run (documented trade-off)');
// Super-Admin gets the provisioning right in full, by the suite rule. This
// assertion was the exact inverse until 2026-09-24; it is inverted rather
// than deleted so the change of policy is visible in the diff.
check(FakeStore::$rights[1][$PROVISIONING] === (READ | CREATE | PURGE),
      'upgrade: super-admin is granted the FULL provisioning right (suite rule "Super-Admin selalu penuh")');
check((!isset(FakeStore::$rights[2][$PROVISIONING]) || FakeStore::$rights[2][$PROVISIONING] === 0)
      && (!isset(FakeStore::$rights[3][$PROVISIONING]) || FakeStore::$rights[3][$PROVISIONING] === 0),
      'upgrade: every NON-admin profile still gets no provisioning right - it stays at zero');

$after_first = FakeStore::$rights;
FakeStore::$writes = [];
FakeStore::$added  = [];
$P::seedRights();

check(FakeStore::$added === [], 'second run adds nothing (UNIQUE key fatal if it did)');
check(FakeStore::$rights === $after_first,
      'second run changes no mask - a narrowing done by hand between upgrades stays done');
check(count(FakeStore::$writes) === 1,
      'second run writes to the super-admin profile only');

// --- upgrade must not re-seed the OLD right just because a NEW one appeared -
// The trap: gating on "$to_add !== []" instead of per right name. Profile 3
// holds a hand-set config mask that core `config` would not have produced;
// if the config right were re-seeded it would be overwritten from core.
reset_instance([3 => [$CONFIG => READ | UPDATE]]);
FakeStore::$possible[] = $CONFIG;
$P::seedRights();
check(FakeStore::$rights[3][$CONFIG] === (READ | UPDATE),
      'a new right name in the same run does not drag the old one back through seeding');

// --- hardened instance: nobody holds core config UPDATE --------------------
FakeStore::$rights = [
    2 => ['config' => READ],
    3 => ['config' => 0],
];
FakeStore::$possible = ['config', $CONFIG, $REPORT];
FakeStore::$writes   = [];
FakeStore::$added    = [];
$P::seedRights();

check(FakeStore::$writes === [], 'no config/UPDATE profile -> nothing is seeded, no fallback grant');

// --- uninstall -------------------------------------------------------------
reset_instance([
    1 => [$CONFIG => READ | UPDATE, $REPORT => READ, $PROVISIONING => 0],
    2 => [$CONFIG => READ, $REPORT => READ, $PROVISIONING => 0],
]);
FakeStore::$possible[] = $CONFIG;
FakeStore::$possible[] = $REPORT;
FakeStore::$possible[] = $PROVISIONING;
$P::dropRights();

check(!in_array($CONFIG, FakeStore::$possible, true) && !in_array($REPORT, FakeStore::$possible, true) && !in_array($PROVISIONING, FakeStore::$possible, true),
      'uninstall removes all three rights from the instance');
check(!isset(FakeStore::$rights[1][$CONFIG]) && !isset(FakeStore::$rights[1][$REPORT]) && !isset(FakeStore::$rights[1][$PROVISIONING])
      && !isset(FakeStore::$rights[2][$CONFIG]) && !isset(FakeStore::$rights[2][$REPORT]) && !isset(FakeStore::$rights[2][$PROVISIONING]),
      'uninstall clears all three rights from every profile');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
