<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/report_query_test.php
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

if (!function_exists('mb_substr'))     { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
function __($s, $d = null) { return $s; }
function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
class QueryExpression { public function __construct(public string $e) {} public function __toString() { return $this->e; } }

class FakeDB {
    public array $seen = [];          // every query spec passed to request()
    public array $canned = [];        // rows to hand back, FIFO by call
    public function quoteName($n) { return "`$n`"; }
    public function request($q) {
        $this->seen[] = $q;
        $rows = array_shift($this->canned) ?? [];
        return new ArrayIterator($rows);
    }
}

require __DIR__ . '/../inc/report.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$R = 'PluginIntegrasipamanReport';
$period = ['start' => '2026-02-01 00:00:00', 'end' => '2026-03-01 00:00:00'];

function j($v) { return json_encode($v); }

// --- user_summary: GROUP BY jms_user, COUNT desc, no eval join --------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[['jms_user' => 'A(a)', 'n' => 5]]];
$res = $R::report('user_summary', $period);
$q = $db->seen[0];
check($res['columns'] === ['User', 'TotalCommands'], 'user_summary columns');
check(str_contains(j($q), 'jms_user') && str_contains(strtoupper(j($q)), 'GROUP'), 'user_summary groups by jms_user');
check(!str_contains(j($q), 'command_eval'), 'user_summary does not join command_eval');
check(iterator_to_array($res['rows'])[0] === ['A(a)', '5'], 'user_summary row is [user, count-as-string]');

// --- category_summary: is_high_risk filter + eval join ---------------
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
$R::report('category_summary', $period);
check(str_contains(j($db->seen[0]), 'command_eval'), 'category_summary joins command_eval');
check(str_contains(j($db->seen[0]), 'is_high_risk'), 'category_summary filters is_high_risk');

// --- top_commands: LIMIT 100 ---------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
$R::report('top_commands', $period);
check(($db->seen[0]['LIMIT'] ?? null) === 100, 'top_commands LIMIT 100');

// --- evaluasi_highrisk paginated: LIMIT/OFFSET + total count -------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['cnt' => 250]],                                  // the COUNT(*) call
    [['executed_at' => '2026-02-02 01:00:00', 'jms_user' => 'A(a)', 'user_is_admin' => 1,
      'asset' => 'x(1.1.1.1)', 'asset_ip' => '1.1.1.1', 'asset_is_production' => 0,
      'account' => 'acc', 'command' => 'sudo su', 'risk_level' => 0, 'risk_label' => 'Accept',
      'priority' => 'LOW', 'risk_category' => 'Privilege Escalation', 'catatan' => 'Aset Non-Production',
      'session_jms_id' => 's1', 'remote_addr' => '9.9.9.9']],
];
$res = $R::report('evaluasi_highrisk', $period, ['paginate' => true, 'page' => 3, 'sort' => 'user', 'dir' => 'asc']);
check($res['total'] === 250, 'evaluasi_highrisk paginated returns the total count');
$dataQ = $db->seen[1];
check(($dataQ['LIMIT'] ?? null) === 100 && ($dataQ['START'] ?? $dataQ['OFFSET'] ?? null) === 200, 'evaluasi_highrisk page 3 -> LIMIT 100 OFFSET 200');
check(str_contains(strtolower(j($dataQ['ORDER'] ?? '')), 'jms_user') && str_contains(strtolower(j($dataQ['ORDER'] ?? '')), 'asc'), 'evaluasi_highrisk sort=user asc -> ORDER c.jms_user ASC');
$row = iterator_to_array($res['rows'])[0];
check($res['columns'][2] === 'UserStatus' && $row[2] === 'ADMIN', 'evaluasi_highrisk UserStatus derived ADMIN/USER');
check($res['columns'][5] === 'IsProduction' && $row[5] === 'False', 'evaluasi_highrisk IsProduction derived True/False');

// bad sort key -> default executed_at desc
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[['cnt' => 0]], []];
$R::report('evaluasi_highrisk', $period, ['paginate' => true, 'sort' => 'nonsense']);
check(str_contains(strtolower(j($db->seen[1]['ORDER'] ?? '')), 'executed_at'), 'bad sort key -> ORDER executed_at');

// --- command_detail: generator, Output column present, no LIMIT ----
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[['executed_at' => '2026-02-02 01:00:00', 'jms_user' => 'A(a)', 'asset' => 'x(1.1.1.1)',
    'account' => 'acc', 'command' => 'ls', 'risk_level' => 0, 'risk_label' => 'Accept',
    'session_jms_id' => 's1', 'remote_addr' => '9.9.9.9']]];
$res = $R::report('command_detail', $period);
check($res['rows'] instanceof Traversable && !is_array($res['rows']), 'command_detail rows is a lazy Traversable');
check($res['columns'][5] === 'Output', 'command_detail Output column is at index 5');
$row = iterator_to_array($res['rows'])[0];
check($row[5] === '', 'command_detail Output cell is blank');
check($row[0] === '2026-02-02 01:00:00' && $row[4] === 'ls', 'command_detail maps timestamp + command');
check(!array_key_exists('LIMIT', $db->seen[0]), 'command_detail has no LIMIT');

// --- unknown key -------------------------------------------------
$res = $R::report('nope', $period);
check($res['columns'] === [] && $res['total'] === 0, 'unknown report key -> empty result');

// --- kpis --------------------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[['total' => 100, 'high_risk' => 8, 'users' => 5, 'assets' => 7,
    'p_high' => 3, 'p_medium' => 4, 'p_low' => 1, 'unclassified' => 2]]];
$k = $R::kpis($period);
check($k['total'] === 100 && $k['high_risk'] === 8 && $k['p_medium'] === 4, 'kpis maps the aggregate row');
check(abs($k['high_risk_pct'] - 8.0) < 0.001, 'kpis computes high_risk_pct');

// --- renderReportTab smoke: emits the period form + every card ------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['ym' => '2026-02']],                                              // availableMonths
    [['total' => 10, 'high_risk' => 2, 'users' => 3, 'assets' => 4,
      'p_high' => 1, 'p_medium' => 1, 'p_low' => 0, 'unclassified' => 0]], // kpis
    [], [], [], [], [],                                                 // 5 summary reports
    [['cnt' => 0]], [],                                                 // highrisk count + page
];
if (!function_exists('__s')) { function __s($s, $d = null) { echo $s; } }
if (!class_exists('Plugin')) { class Plugin { public static function getWebDir($n) { return '/plugins/' . $n; } } }
ob_start();
$R::renderReportTab(['month' => '2026-02']);
$html = ob_get_clean();
check(str_contains($html, 'reportexport.php'), 'renderReportTab links to the export controller');
check(str_contains($html, 'name="month"') || str_contains($html, "name='month'"), 'renderReportTab emits the month picker');
check(str_contains($html, 'EVALUASI') || str_contains(strtolower($html), 'high'), 'renderReportTab renders the high-risk card');
check(substr_count($html, 'reportexport.php?') >= 6, 'renderReportTab has a download link per report');

exit($fails > 0 ? 1 : 0);
