<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/report_period_test.php
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

if (!function_exists('mb_substr'))     { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
function __($s, $d = null) { return $s; }
function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
class QueryExpression { public function __construct(public string $e) {} }

class FakeDB {
    public array $months = [];              // ['2026-02', '2026-01']
    public function request($q) {
        return new ArrayIterator(array_map(static fn($m) => ['ym' => $m], $this->months));
    }
    public function quoteName($name) {
        return '`' . $name . '`';
    }
}
$GLOBALS['DB'] = new FakeDB();
$GLOBALS['DB']->months = ['2026-02', '2026-01'];

require __DIR__ . '/../inc/report.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$R = 'PluginIntegrasipamanReport';

// month
$p = $R::resolvePeriod(['month' => '2026-02']);
check($p['start'] === '2026-02-01 00:00:00', 'month start');
check($p['end']   === '2026-03-01 00:00:00', 'month end is exclusive first-of-next');
check($p['stub']  === '20260201_20260228',   'month stub is first_last day');

// december wrap
$p = $R::resolvePeriod(['month' => '2026-12']);
check($p['end'] === '2027-01-01 00:00:00', 'december wraps the year');

// custom range
$p = $R::resolvePeriod(['from' => '2026-01-15', 'to' => '2026-02-03']);
check($p['start'] === '2026-01-15 00:00:00', 'custom start');
check($p['end']   === '2026-02-04 00:00:00', 'custom end is to +1 day');
check($p['stub']  === '20260115_20260203',   'custom stub');

// to < from -> default (newest available month)
$p = $R::resolvePeriod(['from' => '2026-03-01', 'to' => '2026-02-01']);
check($p['start'] === '2026-02-01 00:00:00', 'to<from falls back to newest month');

// malformed -> default
foreach (['2026-13', '2026-00', 'junk', ''] as $bad) {
    $p = $R::resolvePeriod(['month' => $bad]);
    check($p['start'] === '2026-02-01 00:00:00', "malformed month '$bad' -> default");
}
$p = $R::resolvePeriod(['from' => '2026-01-15']);   // to missing
check($p['start'] === '2026-02-01 00:00:00', 'from without to -> default');
$p = $R::resolvePeriod(['from' => '2026-02-30', 'to' => '2026-03-01']);
check($p['start'] === '2026-02-01 00:00:00', 'impossible date -> default');

// empty table -> current month
$GLOBALS['DB']->months = [];
$p = $R::resolvePeriod([]);
check($p['start'] === date('Y-m-01 00:00:00'), 'no data -> current month');
$GLOBALS['DB']->months = ['2026-02', '2026-01'];

// 366-day cap
$p = $R::resolvePeriod(['from' => '2024-01-01', 'to' => '2026-01-01', 'cap' => true]);
check($p['capped'] === true, 'cap flag set for > 366 day span');
check((strtotime($p['end']) - strtotime($p['start'])) <= 367 * 86400, 'capped span <= ~366 days');
$p = $R::resolvePeriod(['from' => '2024-01-01', 'to' => '2026-01-01']);   // no cap key
check($p['capped'] === false, 'no cap key -> span left alone');

exit($fails > 0 ? 1 : 0);
