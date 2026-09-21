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

$P     = 'PluginIntegrasipamanProfile';
$RIGHT = PluginIntegrasipamanProfile::RIGHT;

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

// --- fresh install ---------------------------------------------------------
reset_instance();
$P::seedRights();

check(FakeStore::$added === [$RIGHT], 'fresh install creates the right exactly once');
check(FakeStore::$rights[1][$RIGHT] === (READ | UPDATE), 'config/UPDATE profile gets READ|UPDATE');
check(FakeStore::$rights[2][$RIGHT] === READ,            'config/READ-only profile gets READ');
check(FakeStore::$rights[3][$RIGHT] === 0,               'profile without core config gets nothing');

// --- upgrade: the right already exists, admin has narrowed it --------------
reset_instance([
    1 => [$RIGHT => READ],            // super-admin, narrowed by hand
    2 => [$RIGHT => 0],               // revoked by hand
    3 => [$RIGHT => READ | UPDATE],   // granted by hand to a non-Setup profile
]);
FakeStore::$possible[] = $RIGHT;
$P::seedRights();

check(FakeStore::$added === [], 'upgrade never re-adds an existing right (UNIQUE key fatal)');
check(FakeStore::$rights[2][$RIGHT] === 0,
      'hand-revoked right on a non-admin profile survives the upgrade');
check(FakeStore::$rights[3][$RIGHT] === (READ | UPDATE),
      'hand-granted right on a non-admin profile survives the upgrade');
check(FakeStore::$rights[1][$RIGHT] === (READ | UPDATE),
      'super-admin is re-granted the full mask every run (documented trade-off)');
check(count(FakeStore::$writes) === 1,
      'upgrade writes to the super-admin profile only');

// --- hardened instance: nobody holds core config UPDATE --------------------
FakeStore::$rights = [
    2 => ['config' => READ],
    3 => ['config' => 0],
];
FakeStore::$possible = ['config', $RIGHT];
FakeStore::$writes   = [];
FakeStore::$added    = [];
$P::seedRights();

check(FakeStore::$writes === [], 'no config/UPDATE profile -> nothing is seeded, no fallback grant');

// --- uninstall -------------------------------------------------------------
reset_instance([1 => [$RIGHT => READ | UPDATE], 2 => [$RIGHT => READ]]);
FakeStore::$possible[] = $RIGHT;
$P::dropRights();

check(!in_array($RIGHT, FakeStore::$possible, true), 'uninstall removes the right from the instance');
check(!isset(FakeStore::$rights[1][$RIGHT]) && !isset(FakeStore::$rights[2][$RIGHT]),
      'uninstall clears the right from every profile');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
