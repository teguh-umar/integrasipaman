<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/menu_test.php
//
// v3.9.0 moved the plugin from its own sidebar sector to one entry in the
// shared suite menu (lib/pgnsuite/Menu.php). That path is a stack of silent
// failure modes: the shared class drops an entry or option without a 'title'
// or 'page' with no error, a front controller naming an option key that does
// not exist just fails to highlight anything, and a controller left in the old
// sector renders with no active menu at all. None of that throws, so it is
// checked here instead.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

define('READ', 1);
define('UPDATE', 2);

function __($s, $d = null) { return $s; }

class CommonGLPI {}

/**
 * Right masks the stubbed Session reports, keyed by right NAME - the menu
 * asks about three different rights, so a single global mask would make
 * every scenario below indistinguishable.
 */
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

/**
 * @param int $config       Mask on RIGHT_CONFIG.
 * @param int $report       Mask on RIGHT_REPORT.
 * @param int $provisioning Mask on RIGHT_PROVISIONING.
 */
function grant(int $config, int $report, int $provisioning = 0): void
{
    $GLOBALS['RIGHTS'] = [
        PluginIntegrasipamanProfile::RIGHT_CONFIG       => $config,
        PluginIntegrasipamanProfile::RIGHT_REPORT       => $report,
        PluginIntegrasipamanProfile::RIGHT_PROVISIONING => $provisioning,
    ];
}

require __DIR__ . '/../inc/menu.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$M    = 'PluginIntegrasipamanMenu';
$BASE = '/plugins/integrasipaman/front';

// --- hidden entirely without the plugin right -------------------------------
grant(0, 0);
check($M::entry() === null, 'no right -> entry hidden (null, which the shared Menu drops)');
check($M::canView() === false, 'canView() false without READ');

// --- read-only on both halves (what an upgrade mirrors) ---------------------
grant(READ, READ);
$entry = $M::entry();
check(is_array($entry), 'READ -> entry returned');
check(($entry['title'] ?? '') === 'JumpServer Integration',
      'title present - the shared Menu drops an entry without one');
check(($entry['icon'] ?? '') === 'ti ti-terminal-2', 'the entry keeps the old sector icon');
check(($entry['order'] ?? null) === 10, 'suite order 10 (allocated in the hub)');
check(!method_exists($M, 'getMenuContent'),
      'the old sector getMenuContent() is gone - nothing registers this class as a sector');

$options = $entry['options'] ?? [];
check(array_keys($options) === ['insights', 'report', 'useraccess', 'loginfailures', 'session', 'setup', 'manual'],
      'read-only user gets insights / report / user access / loginfailures / session / setup, in that order');
check(!isset($options['activity']),
      'Activity Log is not a menu option - front/export.php renders no GLPI chrome');
check(!isset($options['correlation']),
      'correlation is hidden without UPDATE (its controller requires UPDATE)');
check(!isset($options['provisioning']),
      'provisioning is hidden without RIGHT_PROVISIONING - config/report READ does not imply it');
check($entry['page'] === $BASE . '/pbi.php', 'the entry opens the first visible page (insights)');

foreach ($options as $key => $option) {
    check(preg_match('/^[a-z0-9_-]+$/', $key) === 1,
          "option key '$key' matches the shared Menu key pattern (others are dropped)");
    check(isset($option['page']) && $option['page'] !== '',
          "option '$key' has a page (the shared Menu drops options without one)");
    check(isset($option['title']) && $option['title'] !== '', "option '$key' has a title");
    check(isset($option['icon']) && $option['icon'] !== '', "option '$key' has an icon");
    check(str_starts_with((string) $option['page'], $BASE . '/'),
          "option '$key' page is a root-relative plugin path");
}

// --- read + update + provisioning --------------------------------------------
// Provisioning READ is granted here too, since the generic scan below checks
// every option key any front controller passes to Html::header() (including
// nodemap/platformmap/provisioning, which point at 'provisioning') against
// this same $options_rw.
grant(READ | UPDATE, READ, READ);
$options_rw = $M::entry()['options'];
check(array_keys($options_rw) === ['insights', 'report', 'useraccess', 'loginfailures', 'session', 'correlation', 'provisioning', 'setup', 'manual'],
      'full rights -> the eight pages: the old ones plus user access after the report');
check($options_rw['useraccess']['page'] === $BASE . '/useraccess.php',
      'the user access option points at the Phase 1 report');
check($options_rw['correlation']['page'] === $BASE . '/correlation.php',
      'correlation points at its own controller');
check($options_rw['report']['page'] === $BASE . '/pbi.php?tab=report',
      'the report option carries the tab that front/pbi.php reads');
check($options_rw['provisioning']['page'] === $BASE . '/provisioning.php',
      'provisioning points at its own controller');
check($options_rw['session']['page'] === $BASE . '/session.php',
      'the Session option points at the page that carries both of its tabs, behind the report right');

// --- provisioning-only: neither config nor report, just the queue -----------
// The whole reason canView() also checks RIGHT_PROVISIONING: a person who
// may only send/remove PAMAN assets holds neither of the other two rights,
// and must still get the entry - with only the provisioning page behind it.
grant(0, 0, READ);
$entry = $M::entry();
check(is_array($entry), 'provisioning-only -> entry still returned, not hidden');
check(array_keys($entry['options']) === ['provisioning', 'manual'],
      'provisioning-only user sees the provisioning page and nothing else');
check($entry['page'] === $BASE . '/provisioning.php',
      'and the entry opens that page, not a report page that would deny them');

grant(0, 0, 0);
check($M::canView() === false, 'no right at all, including provisioning -> canView() false');
check($M::entry() === null, 'no right at all -> entry hidden');

// --- reports-only: the whole point of the v2.4.0 split -----------------------
grant(0, READ);
$entry = $M::entry();
check(is_array($entry), 'reports-only -> entry still returned');
check(array_keys($entry['options']) === ['insights', 'report', 'useraccess', 'loginfailures', 'session', 'manual'],
      'reports-only sees insights + report + user access + Session, and not Setup / correlation / provisioning');

// --- config-only: settings without the audit detail -------------------------
grant(READ, 0);
$entry = $M::entry();
check(is_array($entry), 'config-only -> entry still returned');
check(array_keys($entry['options']) === ['setup', 'manual'],
      'config-only sees Setup only - the reports would only 403 in their controllers');
check($entry['page'] === $BASE . '/config.form.php', 'and the entry opens Setup');

// --- wiring in setup.php ---------------------------------------------------
$setup = (string) file_get_contents(__DIR__ . '/../setup.php');
check(preg_match("/if \(!class_exists\(\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::class, false\)\) \{\s*require_once __DIR__ \. '\/lib\/pgnsuite\/Menu\.php';/", $setup) === 1,
      'the shared Menu is loaded only when no other plugin already did');
check(str_contains($setup, "\\GlpiPlugin\\Pgnsuite\\Menu::register('integrasipaman');"),
      'the shared Menu is registered for integrasipaman');
check(preg_match("/Menu::HOOK\]\['integrasipaman'\]\s*=\s*'PluginIntegrasipamanMenu::entry';/", $setup) === 1,
      'the entry provider is hooked as a "Class::method" string');
check(!str_contains($setup, "'integrasipaman' => [PluginIntegrasipamanMenu::class]"),
      'the old own sector is no longer registered');
check(preg_match("/'author'\s*=>\s*'ICT',/", $setup) === 1 && !str_contains($setup, "'PGN ICT'"),
      "author is 'ICT' (suite decision 2026-10-03)");
check(str_contains($setup, "'page' => 'front/pgnsuite.settings.php?section=integrasipaman',"),
      'the Setup > Plugins wrench opens the integrasipaman section of the suite settings (4.11.1)');

// --- every front controller is in the shared sector ------------------------
// 3rd arg = Menu::SECTOR, 4th = the plugin key, 5th = an option key; and the
// sub-nav is printed right after the header, since the sidebar no longer
// lists the plugin's pages.
$front = __DIR__ . '/../front';
$used  = [];
foreach (glob($front . '/*.php') as $file) {
    $name = basename($file);
    $src  = (string) file_get_contents($file);
    if (!str_contains($src, 'Html::header(')) {
        continue;   // export.php / reportexport.php / groupmembers.php render no GLPI chrome
    }
    $ok = preg_match(
        "/Html::header\([^;]*''\s*,\s*\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::SECTOR,\s*'integrasipaman',\s*(\\\$tab|'[a-z]+')\s*\);\s*"
        . "\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::renderSubNav\('integrasipaman', (\\\$tab|'[a-z]+')\);/",
        $src,
        $m
    );
    check($ok === 1, "$name: header in the shared sector, sub-nav right after it");
    if ($ok === 1) {
        check($m[1] === $m[2], "$name: the sub-nav marks the same option the header does");
        $used[trim($m[1], "'")][] = $name;
    }
    check(preg_match("/Html::header\([^;]*''\s*,\s*'integrasipaman'\s*,/", $src) === 0,
          "$name: no header left in the old 'integrasipaman' sector");
}
// 17 since v4.5.0: front/backfilljoins.php, the one-shot session-join
// back-fill, joined the 'setup' option.
// 18 since v4.8.0: front/backfillsessionmeta.php, the one-shot that fills
// `type` / `login_from_label` on sessions imported before then - the third
// one-shot under 'setup', beside backfill.php and backfilljoins.php.
// (front/sessionsharing.php is not counted: since v4.7.0 it is a bare
// redirect to front/session.php and renders no chrome of its own.)
// 19 since the Phase 1 user access page: front/useraccess.php, under its own
// 'useraccess' option beside the audit report.
// 21 since v4.11.0: front/accessuser.php and front/accessgroup.php, the User
// and Group sub-tabs, under that same 'useraccess' option. (The members
// pop-up, front/groupmembers.php, is an AJAX fragment and renders no chrome.)
check(count($used) > 0 && array_sum(array_map('count', $used)) === 22,
      'all 22 chrome-rendering front controllers were found (4.15.0: manual.php)');
foreach ($used as $key => $files) {
    if ($key === '$tab') {
        continue;
    }
    check(isset($options_rw[$key]),
          "option '$key' (" . implode(', ', $files) . ") exists in the entry");
}
// front/pbi.php passes $tab, a variable - assert both its values are options.
check(($used['$tab'] ?? []) === ['pbi.php'] && isset($options_rw['insights'], $options_rw['report']),
      "front/pbi.php's \$tab values ('insights'/'report') are both options");

// --- the shared files are the canonical revisions ---------------------------
// Byte-exact on purpose, no line-ending normalisation: whichever active plugin
// loads first supplies the class to the whole suite, and integrasipaman is
// that plugin on production (lowest glpi_plugins.id).
$expected = [
    'Menu.php'      => '34F40CBBFE14842972BB8806F6F0E9C8810D914FD57D2D1DD286033255CCCF88',   // r2
    'ConfigTab.php' => '46FEAA6C01CB000722A496D0922892ED37FFB9CD0CF892F2851EC9423C2B59C8',   // r5
    'AssetTab.php'  => 'F8629CCA6D141B1E9271686C8D7F9D65E4FC84E39CF07E85B10FBC849A0B2466',   // r2
];
// Found by walking up from the plugin root, because the plugin is checked out
// two ways: inside the monorepo (four levels down) and at the root of its own
// published repo (one level up). Pinning one depth let the published copy ship
// a .gitattributes that protects only some of these files - and an unprotected
// shared file is line-ending-converted on checkout, which changes its bytes and
// so its SHA, which is exactly what the suite contract forbids.
$attr = '';
for ($dir = __DIR__ . '/..', $up = 0; $up < 5; $up++, $dir .= '/..') {
    if (is_file("$dir/.gitattributes")) {
        $attr = (string) @file_get_contents("$dir/.gitattributes");
        break;
    }
}
check($attr !== '', 'a .gitattributes was found above the plugin root');

foreach ($expected as $file => $sha) {
    $path   = __DIR__ . '/../lib/pgnsuite/' . $file;
    $actual = is_file($path) ? strtoupper(hash_file('sha256', $path)) : 'missing';
    check($actual === $sha, "lib/pgnsuite/$file is the canonical revision (got $actual)");
    check(str_contains($attr, "lib/pgnsuite/$file -text"),
          "lib/pgnsuite/$file is kept byte-exact by .gitattributes");
}
// The settings page's own controller is shared too, from r5 on. It carries no
// logic - it includes core and calls ConfigTab::displayPage() - but it is what
// ConfigTab::pagePath() looks for in every active plugin, so a plugin that
// ships a different one hands the suite a different page.
$page = __DIR__ . '/../front/pgnsuite.settings.php';
$page_sha = is_file($page) ? strtoupper(hash_file('sha256', $page)) : 'missing';
check($page_sha === '4BFF4A4B9866CF7373DE2B45EB096BE4D72999E854E2C2887806F5573F6B49FA',
      "front/pgnsuite.settings.php is the canonical revision (got $page_sha)");
check(str_contains($attr, 'front/pgnsuite.settings.php -text'),
      'front/pgnsuite.settings.php is kept byte-exact by .gitattributes');

// r5 reaches the page through Setup, not through a tab on Setup > General.
// register() is still the only call a plugin makes, so this is what proves the
// plugin is on r5 rather than silently still shipping r4.
$configtab = (string) file_get_contents(__DIR__ . '/../lib/pgnsuite/ConfigTab.php');
check(str_contains($configtab, 'public static function displayPage(): void')
      && str_contains($configtab, 'public static function getMenuContent()'),
      'the shipped ConfigTab is the Setup-entry revision (r5)');
check(!str_contains($configtab, 'displayTabContentForItem'),
      'and no longer renders itself as a tab on Setup > General');
check(str_contains($configtab, "PAGE_FILE = 'front/pgnsuite.settings.php'"),
      'r5 looks for exactly the controller this plugin ships');


echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
