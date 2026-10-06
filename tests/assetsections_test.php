<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/assetsections_test.php
//
// Covers the move of this plugin's two Computer tabs into the shared
// "PGN Suite" asset tab (suite contract v1 r1).
//
// The failure this file exists for is silent: sections() returning a
// section whose `render` names a method that does not exist, or whose key
// does not match the contract's pattern. AssetTab skips anything malformed
// without a word, so a typo does not error - the sub-tab simply never
// appears, and the tab looks like it is working because the OTHER section
// still renders.
error_reporting(E_ALL & ~E_DEPRECATED);

define('READ', 1);
define('UPDATE', 2);
define('CREATE', 4);
define('PURGE', 1024);

function __($s, $d = null) { return $s; }
function _n($s, $p, $n, $d = null) { return $n == 1 ? $s : $p; }

class CommonGLPI {}
class CommonDBTM extends CommonGLPI {}
class Computer extends CommonDBTM { public function getID() { return 42; } }
class NetworkEquipment extends CommonDBTM {}

$GLOBALS['RIGHTS'] = [];
class Session
{
    public static function haveRight($name, $mask)
    {
        return ((int) ($GLOBALS['RIGHTS'][$name] ?? 0) & $mask) === $mask;
    }
}
class PluginIntegrasipamanProfile
{
    public const RIGHT_CONFIG       = 'plugin_integrasipaman_config';
    public const RIGHT_REPORT       = 'plugin_integrasipaman_report';
    public const RIGHT_PROVISIONING = 'plugin_integrasipaman_provisioning';
}
// Stand-ins so the render targets resolve; the real bodies are covered by
// accesshistory_test.php and the provisioning suites.
class PluginIntegrasipamanAccessHistory
{
    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);
    }
    public static function displayTabContentForItem($item, $tabnum = 1, $withtemplate = 0) { return true; }
}
class PluginIntegrasipamanProvisioning
{
    public static function displayTabContentForItem($item, $tabnum = 1, $withtemplate = 0) { return true; }
}

function grant(int $report, int $provisioning): void
{
    $GLOBALS['RIGHTS'] = [
        PluginIntegrasipamanProfile::RIGHT_REPORT       => $report,
        PluginIntegrasipamanProfile::RIGHT_PROVISIONING => $provisioning,
    ];
}

require __DIR__ . '/../inc/assetsections.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$A    = 'PluginIntegrasipamanAssetSections';
$comp = new Computer();

// --- both rights: both sections, in contract order ------------------------
grant(READ, READ);
$s = $A::sections($comp);
check(count($s) === 2, 'both rights yield both sections');
check(array_column($s, 'key') === ['access', 'provisioning'], 'sections come back in order');
check(array_column($s, 'order') === [10, 15],
      'orders are the ones the orchestrator allocated (10 access, 15 provisioning)');

// The band integrasipaman was given is 10-19. Straying out of it collides
// with riskregister (20) without anyone noticing until both are deployed.
foreach ($s as $sec) {
    check($sec['order'] >= 10 && $sec['order'] <= 19,
          "section '{$sec['key']}' order {$sec['order']} is inside integrasipaman's 10-19 band");
}

// --- the contract's own validation rules ----------------------------------
// AssetTab silently DROPS a section failing any of these.
foreach ($s as $sec) {
    check((bool) preg_match('/^[a-z0-9_-]+$/', $sec['key']),
          "section key '{$sec['key']}' matches the contract key pattern");
    check(is_string($sec['title']) && $sec['title'] !== '',
          "section '{$sec['key']}' has a title");
    check(is_string($sec['icon']) && str_starts_with($sec['icon'], 'ti ti-'),
          "section '{$sec['key']}' has a tabler icon");
    check(is_callable($sec['render']),
          "section '{$sec['key']}' render target '{$sec['render']}' is callable");
}

// --- render points at the ORIGINAL tab body, never a copy -----------------
check($s[0]['render'] === 'PluginIntegrasipamanAccessHistory::displayTabContentForItem',
      'the access section renders through the existing AccessHistory tab body');
check($s[1]['render'] === 'PluginIntegrasipamanProvisioning::displayTabContentForItem',
      'the provisioning section renders through the existing Provisioning tab body');

// render($item) is called with ONE argument by AssetTab; both targets must
// tolerate that. A method requiring $tabnum would fatal on every tab open.
foreach ($s as $sec) {
    [$cls, $m] = explode('::', $sec['render']);
    $r = new ReflectionMethod($cls, $m);
    check($r->getNumberOfRequiredParameters() <= 1,
          "section '{$sec['key']}' render survives being called with just the item");
}

// --- each right is independent -------------------------------------------
grant(READ, 0);
$s = $A::sections($comp);
check(array_column($s, 'key') === ['access'], 'report right alone yields only the access section');

grant(0, READ);
$s = $A::sections($comp);
check(array_column($s, 'key') === ['provisioning'],
      'provisioning right alone yields only the provisioning section');

// --- no rights: NO sections, so AssetTab hides the whole tab --------------
grant(0, 0);
check($A::sections($comp) === [],
      'a user with neither right gets no sections - the tab disappears rather than showing empty');

// --- wrong itemtype --------------------------------------------------------
grant(READ, READ);
check($A::sections(new NetworkEquipment()) === [],
      'no sections on a non-Computer, even with both rights');
check($A::sections(new CommonDBTM()) === [], 'no sections on a bare CommonDBTM');

// --- the itemtype list this plugin registers ------------------------------
check($A::ITEMTYPES === ['Computer'],
      'ITEMTYPES is Computer only - both backing tables are keyed on computers_id');

// --- setup.php wiring -----------------------------------------------------
$setup = (string) file_get_contents(__DIR__ . '/../setup.php');

// Comments in setup.php legitimately QUOTE the registrations they explain
// ("no 'addtabon' => 'Computer' any more"), so scanning the raw text finds
// the very strings this migration removed. Strip comments and test the
// code. Found by this suite failing on its own explanatory comment.
$setup_code = '';
foreach (token_get_all($setup) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $setup_code .= is_array($t) ? $t[1] : $t;
}

check(!preg_match("/registerClass\(\s*'PluginIntegrasipamanAccessHistory'\s*,/", $setup),
      'AccessHistory no longer registers its own addtabon');
check(!preg_match("/registerClass\(\s*'PluginIntegrasipamanProvisioning'\s*,/", $setup),
      'Provisioning no longer registers its own addtabon');
check(substr_count($setup_code, "'addtabon' => 'Computer'") === 0,
      'NO Computer addtabon survives in setup.php CODE - two tabs would defeat the merge');
check(str_contains($setup_code, "'addtabon' => 'Profile'"),
      'the Profile rights tab is untouched - it is not an asset tab');
check(str_contains($setup_code, 'AssetTab::register(PluginIntegrasipamanAssetSections::ITEMTYPES)'),
      'setup.php registers AssetTab for this plugin itemtypes');
check(str_contains($setup_code, "'PluginIntegrasipamanAssetSections::sections'"),
      'setup.php points the AssetTab hook at the provider');
check(str_contains($setup_code, 'require_once __DIR__ . \'/lib/pgnsuite/AssetTab.php\''),
      'setup.php ships and requires the canonical AssetTab');
check(str_contains($setup_code, 'class_exists(\GlpiPlugin\Pgnsuite\AssetTab::class, false)'),
      'the require is guarded with class_exists(..., false), as ConfigTab is');

// --- the shipped canonical file -------------------------------------------
$asset_tab = __DIR__ . '/../lib/pgnsuite/AssetTab.php';
check(is_file($asset_tab), 'lib/pgnsuite/AssetTab.php ships with the plugin');
$bytes = (string) file_get_contents($asset_tab);
check(strtoupper(hash('sha256', $bytes)) === 'F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466',
      'the shipped AssetTab is byte-identical to canonical r2');
check(!str_contains($bytes, "\r\n"),
      'the shipped AssetTab is LF-only - a CRLF copy changes the SHA and breaks the contract check');

// --- contract rule 1: no server-side pager in a section body --------------
// Every pane renders on every tab open; a pager link would navigate away
// from the asset form entirely.
$access = (string) file_get_contents(__DIR__ . '/../inc/accesshistory.class.php');
check(!str_contains($access, 'hr_page=') && !str_contains($access, 'p_sessions='),
      'the access section body still has no server-side pager');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
