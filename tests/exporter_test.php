<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/exporter_test.php
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

if (!function_exists('mb_substr'))     { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
function __($s, $d = null) { return $s; }
function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

require __DIR__ . '/../inc/exporter.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$E = 'PluginIntegrasipamanExporter';

// --- sanitizeCell -------------------------------------------------------
check($E::sanitizeCell('=SUM(A1)') === "'=SUM(A1)", 'sanitizeCell prefixes = formula');
check($E::sanitizeCell('+1')       === "'+1",        'sanitizeCell prefixes + formula');
check($E::sanitizeCell('-1')       === "'-1",        'sanitizeCell prefixes - formula');
check($E::sanitizeCell('@x')       === "'@x",        'sanitizeCell prefixes @ formula');
check($E::sanitizeCell("\tx")      === "'\tx",       'sanitizeCell prefixes TAB');
check($E::sanitizeCell('ls -la')   === 'ls -la',     'sanitizeCell leaves plain text');
check($E::sanitizeCell('')         === '',           'sanitizeCell leaves empty');

// --- sendCsv streams a BOM + comma rows, sanitising each cell ----------
ob_start();
$E::sendCsv('x.csv', ['A', 'B'], [['1', '=2'], ['ls', 'ok']]);
$out = ob_get_clean();
check(str_starts_with($out, "\xEF\xBB\xBF"),        'sendCsv writes a UTF-8 BOM first');
check(str_contains($out, "A,B\n") || str_contains($out, "A,B\r\n"), 'sendCsv writes the header row');
check(str_contains($out, '"\'=2"') || str_contains($out, "'=2"),    'sendCsv sanitises the =2 cell');
check(str_contains($out, "ls,ok"),                 'sendCsv writes a plain data row');

exit($fails > 0 ? 1 : 0);
