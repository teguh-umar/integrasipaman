<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/manual_test.php
//
// v4.15.0: the plugin links its user manual (suite rule, 2026-11-09, hub §4).
// The source is docs/manual/integrasipaman.md at the suite root (Copilot's);
// at build it is copied to docs/manual.md inside the plugin and ships in the
// zip. front/manual.php renders it with GLPI's league/commonmark, raw HTML
// escaped and unsafe links dropped, shows the manual's version line and says
// so when that lags the plugin; a missing file is a plain notice.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
if (!function_exists('__'))  { function __($s, $d = null) { return $s; } }
if (!function_exists('htmlescape')) { function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }

require_once __DIR__ . '/../inc/manual.class.php';
$M = 'PluginIntegrasipamanManual';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

// --- where it lives -------------------------------------------------------
check(str_replace('\\', '/', $M::path()) === str_replace('\\', '/', realpath(__DIR__ . '/..') . '/docs/manual.md'),
    'the manual is read from docs/manual.md inside the plugin');
check(is_file($M::path()), 'docs/manual.md is shipped in the plugin tree (so it is in the zip)');

// --- the version line ---------------------------------------------------------
check($M::versionOf("# T\n\n> Versi didokumentasikan: 4.5.0 (tag `v4.5.0`) · Status VM") === '4.5.0', 'reads "Versi didokumentasikan: x.y.z"');
check($M::versionOf("> Version documented: 4.13.1\n") === '4.13.1', 'reads the English line too');
check($M::versionOf("# no version here") === '', 'no version line: empty');
check($M::versionOf("> Versi didokumentasikan: **4.15.0** (tag `v4.15.0`)") === '4.15.0', 'reads a bold version (the 4.15.0 manual header)');
check($M::versionOf((string) file_get_contents($M::path())) !== '', 'the shipped manual has a readable version line');
check($M::lags('4.5.0', '4.15.0') === true && $M::lags('4.15.0', '4.15.0-rc1') === false && $M::lags('', '4.15.0') === false,
    'lags(): an older manual lags; the same release (rc or final) does not; unknown is not claimed');
check($M::lags('4.15.0', '4.15.1-rc1') === false && $M::lags('4.15.0', '4.15.3') === false && $M::lags('4.15.0', '4.16.0') === true && $M::lags('4.9.0', '4.15.0') === true,
    'lags() ignores the patch level (v4.15.1): a 4.15.0 manual is current for 4.15.x, not for 4.16.0');

// --- rendering ------------------------------------------------------------------
check($M::CONVERTER_OPTIONS === ['html_input' => 'escape', 'allow_unsafe_links' => false],
    'commonmark runs with html_input=escape and allow_unsafe_links=false');
$html = $M::render("# Title\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))");
check(!str_contains($html, '<script>'), 'raw HTML in the manual is never emitted as HTML');
check(!str_contains($html, 'href="javascript:'), 'a javascript: link is never emitted');

// --- the page -------------------------------------------------------------------
$missing = $M::body(null, '4.15.0');
check(str_contains($missing, 'alert') && str_contains($missing, 'not installed'), 'a missing file is a plain notice');
$lag = $M::body("> Versi didokumentasikan: 4.5.0\n\n# Hi", '4.15.0');
check(str_contains($lag, '4.5.0') && str_contains($lag, '4.15.0'), 'a lagging manual says which version it documents and which is installed');
$cur = $M::body("> Versi didokumentasikan: 4.15.0\n\n# Hi", '4.15.0-rc1');
check(!str_contains($cur, 'alert-warning'), 'a current manual carries no lag warning');

// --- wiring ---------------------------------------------------------------------
$page = file_get_contents(__DIR__ . '/../front/manual.php');
check(str_contains($page, 'PluginIntegrasipamanMenu::canView()'), 'front/manual.php checks the plugin view right');
check(str_contains($page, 'Html::header(') && str_contains($page, "renderSubNav('integrasipaman', 'manual')"), 'front/manual.php sits in the plugin menu');
$menu = file_get_contents(__DIR__ . '/../inc/menu.class.php');
check(preg_match("/'manual'\s*\]\s*=\s*\[.*?ti ti-book/s", $menu) === 1, 'the plugin menu has a Manual entry with ti ti-book');
$config = file_get_contents(__DIR__ . '/../inc/config.class.php');
check(str_contains($config, '/front/manual.php') && str_contains($config, 'ti ti-book'), 'the Setup page links the Manual');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
