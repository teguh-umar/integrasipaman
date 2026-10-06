<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/report_query_test.php
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

if (!function_exists('mb_substr'))     { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
function __($s, $d = null) { return $s; }
function _n($s, $p, $n, $d = null) { return $n == 1 ? $s : $p; }
function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
class QueryExpression { public function __construct(public string $e) {} public function __toString() { return $this->e; } }

class FakeDB {
    public array $seen = [];          // every query spec passed to request()
    public array $canned = [];        // rows to hand back, FIFO by call
    public function quoteName($n) { return "`$n`"; }
    public function request($q) {
        $this->seen[] = $q;
        $rows = array_shift($this->canned) ?? [];

        // Minimal WHERE emulation: apply the plain equality conditions
        // (string column => scalar value) actually present in the query,
        // against whichever field they name in the canned rows. It says
        // nothing about OR/LIKE/range clauses - but it is enough to make a
        // mutation that reads the wrong column, or turns an equality into a
        // LIKE, visible in the ROWS a test gets back, not just in the query
        // spec nobody downstream is asserting on.
        if (is_array($rows) && $rows !== [] && isset($q['WHERE']) && is_array($q['WHERE'])) {
            foreach ($q['WHERE'] as $col => $val) {
                if (!is_string($col) || !is_scalar($val)) {
                    continue;
                }
                $field = str_contains($col, '.') ? substr($col, strpos($col, '.') + 1) : $col;
                if (!array_key_exists($field, $rows[0])) {
                    continue;
                }
                $rows = array_values(array_filter($rows, static fn($r) => ($r[$field] ?? null) == $val));
            }
        }

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
      'asset' => 'x(1.1.1.1)', 'asset_ip' => '1.1.1.1', 'asset_class' => 'development',
      'account' => 'acc', 'command' => 'sudo su', 'risk_level' => 0, 'risk_label' => 'Accept',
      'priority' => 'LOW', 'risk_category' => 'Privilege Escalation', 'catatan' => 'Aset Non-Production',
      'session_jms_id' => 's1', 'remote_addr' => '9.9.9.9']],
];
$res = $R::report('evaluasi_highrisk', $period, ['paginate' => true, 'page' => 3, 'sort' => 'user', 'dir' => 'asc']);
check($res['total'] === 250, 'evaluasi_highrisk paginated returns the total count');
$dataQ = $db->seen[1];
check(($dataQ['LIMIT'] ?? null) === 25 && ($dataQ['START'] ?? $dataQ['OFFSET'] ?? null) === 50, 'evaluasi_highrisk page 3 -> LIMIT 25 OFFSET 50');
check(str_contains(strtolower(j($dataQ['ORDER'] ?? '')), 'jms_user') && str_contains(strtolower(j($dataQ['ORDER'] ?? '')), 'asc'), 'evaluasi_highrisk sort=user asc -> ORDER c.jms_user ASC');
$row = iterator_to_array($res['rows'])[0];
check($res['columns'][2] === 'UserStatus' && $row[2] === 'ADMIN', 'evaluasi_highrisk UserStatus derived ADMIN/USER');
// The column now carries the class itself rather than a boolean rendering
// of it. 'False' hid three different situations behind one word.
check($res['columns'][5] === 'Classification', 'evaluasi_highrisk reports the classification column');
check($row[5] === 'development', 'and the row carries the class verbatim, not True/False');
// $row[5] === 'development' above already implies $row[5] !== 'False' -
// a separate check() here could not fail without that one failing first.

// --- evaluasi_highrisk: an unclassified row's Classification cell must
// say so, not render an empty string a CSV reader can't tell from missing
// data (FIX 7). This is the case the whole feature exists for and nothing
// exercised it before now.
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['cnt' => 1]],
    [['executed_at' => '2026-02-02 01:00:00', 'jms_user' => 'B(b)', 'user_is_admin' => 0,
      'asset' => 'unmapped(203.0.113.5)', 'asset_ip' => '203.0.113.5', 'asset_class' => '',
      'account' => 'acc', 'command' => 'ls', 'risk_level' => 0, 'risk_label' => 'Accept',
      'priority' => 'MEDIUM', 'risk_category' => 'Reconnaissance', 'catatan' => 'Aset Belum Diklasifikasi',
      'session_jms_id' => 's2', 'remote_addr' => '203.0.113.5']],
];
$res = $R::report('evaluasi_highrisk', $period, ['paginate' => true, 'page' => 1]);
$row = iterator_to_array($res['rows'])[0];
check($row[5] === '(belum diklasifikasi)', 'evaluasi_highrisk renders an unclassified asset_class as "(belum diklasifikasi)", not an empty cell');

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
    [['cnt' => 0]], [],                                                 // highrisk count + page (last tab)
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

// --- per-card pagination + asset links ------------------------------
if (!class_exists('Computer')) {
    class Computer {
        public static function getFormURLWithID($id) { return '/front/computer.form.php?id=' . (int) $id; }
        public static function getTypeName($nb = 0) { return 'Computer'; }
    }
}

// 40 assets in asset_summary -> page 1 shows 15 of them plus a pager;
// the three assets that map to a Computer come back as links.
$assets = [];
for ($i = 1; $i <= 40; $i++) {
    $assets[] = ['asset' => 'srv' . $i . '(10.0.0.' . $i . ')', 'n' => 100 - $i];
}
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['ym' => '2026-02']],                                              // availableMonths (period form)
    [['total' => 10, 'high_risk' => 2, 'users' => 3, 'assets' => 40,
      'p_high' => 1, 'p_medium' => 1, 'p_low' => 0, 'unclassified' => 0]], // kpis
    [],                                                                 // evaluasi_summary
    [],                                                                 // user_summary
    $assets,                                                            // asset_summary
    [['asset' => 'srv1(10.0.0.1)', 'computers_id' => 7],
     ['asset' => 'srv2(10.0.0.2)', 'computers_id' => 8]],               // assetmap lookup
    [],                                                                 // category_summary
    [],                                                                 // top_commands
    [['cnt' => 0]], [],                                                 // highrisk count + page
];
ob_start();
$R::renderReportTab(['month' => '2026-02']);
$html = ob_get_clean();

check(substr_count($html, 'srv') > 0, 'asset_summary renders asset rows');
$shown = 0;
for ($i = 1; $i <= 40; $i++) {
    if (str_contains($html, '>srv' . $i . '(')) { $shown++; }
}
check($shown === 15, 'asset_summary shows only 15 rows per page (got ' . $shown . ')');
check(str_contains($html, 'p_asset_summary=2'), 'asset_summary emits a Next link for page 2');
check(str_contains($html, '#integrasipaman-report-tabs'), 'tabbed pager links anchor back to the tab group');
check(str_contains($html, 'rtab=asset_summary'), 'tabbed pager links carry the open tab');
check(str_contains($html, 'data-bs-toggle="tab"'), 'tab group renders Bootstrap tab nav');
check(substr_count($html, 'class="tab-pane') === 6, 'tab group renders all six panes');
check(substr_count($html, 'class="card mb-4"') === 2, 'only the tab card and the full-log card remain');
check(str_contains($html, 'computer.form.php?id=7') && str_contains($html, 'computer.form.php?id=8'),
      'mapped assets link to their GLPI Computer');
check(!str_contains($html, 'computer.form.php?id=0'), 'unmapped assets are not linked');

$mapQ = null;
foreach ($db->seen as $q) {
    if (str_contains(j($q), 'assetmap')) { $mapQ = $q; }
}
check($mapQ !== null && str_contains(j($mapQ), 'glpi_computers'), 'asset link lookup joins glpi_computers');
check($mapQ !== null && str_contains(j($mapQ), 'is_deleted'), 'asset link lookup skips deleted computers');
check($mapQ !== null && count($mapQ['WHERE']['am.asset']) === 15, 'asset link lookup only asks about the visible page');

// page 2 of one card keeps the other cards on their own page
$users = [];
for ($i = 1; $i <= 40; $i++) {
    $users[] = ['jms_user' => 'srv' . $i . '(10.0.0.' . $i . ')', 'n' => 100 - $i];
}
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['ym' => '2026-02']],
    [['total' => 0, 'high_risk' => 0, 'users' => 0, 'assets' => 0,
      'p_high' => 0, 'p_medium' => 0, 'p_low' => 0, 'unclassified' => 0]],
    [], $users, [], [], [],
    [['cnt' => 0]], [],
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'p_user_summary' => '2', 'p_asset_summary' => '3']);
$html = ob_get_clean();
check(str_contains($html, '>srv16(') && !str_contains($html, '>srv1('), 'user_summary page 2 shows rows 16-30');
check(str_contains($html, 'p_asset_summary=3'), 'other cards keep their page in every pager link');

// an out-of-range page falls back to the last page instead of an empty table
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['ym' => '2026-02']],
    [['total' => 0, 'high_risk' => 0, 'users' => 0, 'assets' => 0,
      'p_high' => 0, 'p_medium' => 0, 'p_low' => 0, 'unclassified' => 0]],
    [], $users, [], [], [],
    [['cnt' => 0]], [],
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'p_user_summary' => '99']);
$html = ob_get_clean();
check(str_contains($html, '>srv40('), 'out-of-range card page clamps to the last page');

// --- EVALUASI_HIGHRISK search box -------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [[['cnt' => 3]], []];
$R::report('evaluasi_highrisk', $period, ['paginate' => true, 'q' => 'sysadmin']);
// Inspect the WHERE, not the whole spec: 'catatan' and 'command' appear in
// every SELECT, so a substring check over the full query proves nothing.
$countQ = j($db->seen[0]['WHERE'] ?? []);
$dataQ  = j($db->seen[1]['WHERE'] ?? []);

foreach (['jms_user', 'asset', 'asset_ip', 'priority'] as $col) {
    check(str_contains($dataQ, $col), "search looks in $col");
}
check(str_contains($dataQ, 'sysadmin') && str_contains($dataQ, 'LIKE'),
      'search is a LIKE on the term');
check(!str_contains($dataQ, 'command') && !str_contains($dataQ, 'catatan'),
      'the WHERE does NOT search Command or Catatan - "HIGH" would match half the notes');
check(str_contains($countQ, 'sysadmin'),
      'the COUNT uses the same filter as the page - otherwise the pager disagrees with the rows');

// no term -> no LIKE at all
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[['cnt' => 0]], []];
$R::report('evaluasi_highrisk', $period, ['paginate' => true, 'q' => '   ']);
check(!str_contains(j($db->seen[1]['WHERE'] ?? []), 'LIKE'),
      'a whitespace-only term is not a filter');

// export path (no paginate) honours the term too
$db = new FakeDB(); $GLOBALS['DB'] = $db; $db->canned = [[]];
$res = $R::report('evaluasi_highrisk', $period, ['q' => 'HIGH']);
iterator_to_array($res['rows']);
check(str_contains(j($db->seen[0]['WHERE'] ?? []), 'HIGH'),
      'the export query honours the search - filtering then exporting means the filtered rows');
check(!array_key_exists('LIMIT', $db->seen[0]),
      'the export is still the whole matching set, not one page of it');

// --- toolbar + column picker ------------------------------------------
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['ym' => '2026-02']],
    [['total' => 1, 'high_risk' => 1, 'users' => 1, 'assets' => 1,
      'p_high' => 1, 'p_medium' => 0, 'p_low' => 0, 'unclassified' => 0]],
    [], [], [], [], [],
    [['cnt' => 0]], [],
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'rtab' => 'evaluasi_highrisk', 'hr_q' => 'sysadmin']);
$html = ob_get_clean();

check(str_contains($html, 'name="hr_q"'), 'the search box is rendered');
check(str_contains($html, 'value="sysadmin"'), 'the search box keeps the current term');
check(!str_contains($html, 'name="hr_page"'),
      'the search form carries no page - a new search starts at page 1');
check(str_contains($html, 'name="month"') && substr_count($html, 'name="month"') >= 2,
      'the search form replays the period so searching cannot move the report to another month');
check(str_contains($html, 'integrasipaman-col-toggle') && substr_count($html, 'integrasipaman-col-toggle') >= 15,
      'the column picker offers every column');
check(str_contains($html, 'id="integrasipaman-highrisk-table"'),
      'the table carries the id the column script looks for');
check(str_contains($html, '&q=sysadmin') || str_contains($html, '&amp;q=sysadmin'),
      'export links carry the search term');
check(!str_contains($html, 'hidden_cols') && !str_contains($html, 'hr_hide'),
      'hiding columns is not a server-side concern - it never reaches the export links');

// v2.7.0 shipped the picker script with the toolbar, which is rendered
// BEFORE the table. Inline script runs as the parser reaches it, so
// getElementById() returned null, the listener was never registered, and
// unchecking a column did nothing - while the checkbox still ticked,
// because that part is the browser's own behaviour. Order is the fix, so
// order is what this asserts.
$table_at  = strpos($html, 'id="integrasipaman-highrisk-table"');
$script_at = strpos($html, "getElementById('integrasipaman-highrisk-table')");
check($table_at !== false && $script_at !== false && $table_at < $script_at,
      'the column script is emitted AFTER the table it binds to');

// --- drill-down from the summary ---------------------------------------
// MEDIUM maps to TWO unrelated catatan since v3.6.0 - "Aktivitas Admin"
// (production, sanctioned admin) and "Aset Belum Diklasifikasi" (nobody
// has classified the asset). A drill-down keyed on PRIORITY merges them
// and the row count stops matching the number that was clicked.
//
// This is the assertion that separates a drill-down from a lie.
function highriskFixtureRow(string $catatan, string $priority = 'MEDIUM', string $kategori = 'Reconnaissance'): array
{
    static $n = 0;
    $n++;
    return [
        'executed_at' => '2026-02-0' . $n . ' 00:00:00', 'jms_user' => 'u' . $n . '(u' . $n . ')',
        'user_is_admin' => 0, 'asset' => 'a' . $n . '(192.0.2.' . $n . ')', 'asset_ip' => '192.0.2.' . $n,
        'asset_class' => '', 'account' => 'acc', 'command' => 'ls', 'risk_level' => 0, 'risk_label' => 'Accept',
        'priority' => $priority, 'risk_category' => $kategori, 'catatan' => $catatan,
        'session_jms_id' => 's' . $n, 'remote_addr' => '192.0.2.' . $n,
    ];
}

// Fixture: two rows unclassified, one row sanctioned admin activity - the
// two populations MEDIUM would otherwise merge.
$hrPool = [
    highriskFixtureRow('Aset Belum Diklasifikasi'),
    highriskFixtureRow('Aktivitas Admin'),
    highriskFixtureRow('Aset Belum Diklasifikasi'),
];

$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [$hrPool];
$res = $R::report('evaluasi_highrisk', $period, ['catatan' => 'Aset Belum Diklasifikasi']);
$notes = array_column(iterator_to_array($res['rows']), 12);   // catatan column index
check($notes !== [] && array_unique($notes) === ['Aset Belum Diklasifikasi'],
      'filtering by catatan returns ONLY that catatan (got '
    . json_encode(array_unique($notes)) . ')');

// Exact match, not LIKE: hr_q already searches several columns, so a
// catatan string could also catch an asset name containing that text. The
// number clicked and the rows shown must be the same set by construction.
$db->canned = [$hrPool];
$res = $R::report('evaluasi_highrisk', $period, ['catatan' => 'Aset']);
check(iterator_to_array($res['rows']) === [],
      'a partial catatan matches nothing - the filter is exact, not LIKE');

// The count on the summary and the rows behind it must agree.
$db->canned = [
    [['priority' => 'MEDIUM', 'catatan' => 'Aset Belum Diklasifikasi', 'n' => 2],
     ['priority' => 'MEDIUM', 'catatan' => 'Aktivitas Admin', 'n' => 1]],
    $hrPool,
];
$sum = $R::report('evaluasi_summary', $period);
$row = null;
foreach ($sum['rows'] as $r) {
    if ($r[1] === 'Aset Belum Diklasifikasi') { $row = $r; }
}
check($row !== null, 'the summary has an unclassified row to drill into');
$det = $R::report('evaluasi_highrisk', $period, ['catatan' => 'Aset Belum Diklasifikasi']);
$detRows = iterator_to_array($det['rows']);
check((string) count($detRows) === (string) $row[2],
      'the summary count equals the number of rows the link reaches (summary '
    . ($row[2] ?? '?') . ', detail ' . count($detRows) . ')');

// --- hr_catatan preserved across paging/sorting, and the link is honest -
// The summary card builds a link per row, with hr_catatan set to the
// catatan and hr_page/hr_q dropped. tabUrl() must carry hr_catatan on
// every subsequent reload (paging, sorting) or the filter silently
// vanishes and the operator sees the whole period again.
if (!function_exists('__s')) { function __s($s, $d = null) { echo $s; } }
if (!class_exists('Plugin')) { class Plugin { public static function getWebDir($n) { return '/plugins/' . $n; } } }
if (!class_exists('Computer')) {
    class Computer {
        public static function getFormURLWithID($id) { return '/front/computer.form.php?id=' . (int) $id; }
        public static function getTypeName($nb = 0) { return 'Computer'; }
    }
}

$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [
    [['ym' => '2026-02']],                                              // availableMonths
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]], // kpis
    [['priority' => 'MEDIUM', 'catatan' => 'Aset Belum Diklasifikasi', 'n' => 2]], // evaluasi_summary
    [], [], [], [],                                                     // user/asset/category/top_commands
    [['cnt' => 2]], $hrPool,                                            // highrisk count + page (active tab)
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'rtab' => 'evaluasi_highrisk', 'hr_catatan' => 'Aset Belum Diklasifikasi']);
$html = ob_get_clean();

check(str_contains($html, 'hr_catatan=Aset'),
      'the pager/sort links on the high-risk pane carry hr_catatan forward');
check(str_contains($html, 'Aset Belum Diklasifikasi'),
      'the toolbar names the active catatan filter so a reader can see why rows are missing');
check(substr_count($html, htmlescape(__('Clear', 'integrasipaman'))) === 1,
      'a link is offered to clear the active catatan filter');

// The summary card's own drill-down link: built from column 1 (catatan),
// never column 0 (priority) - HIGHRISK_SEARCH already contains e.priority,
// which is exactly what makes the priority route look correct right up
// until MEDIUM maps to two catatan.
$db2 = new FakeDB(); $GLOBALS['DB'] = $db2;
$db2->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [['priority' => 'MEDIUM', 'catatan' => 'Aset Belum Diklasifikasi', 'n' => 2]], // evaluasi_summary
    [], [], [], [],                                                     // 4 more summary cards
    [['cnt' => 0]], [],                                                 // highrisk count + page
];
ob_start();
$R::renderReportTab(['month' => '2026-02']);
$html2 = ob_get_clean();
check(str_contains($html2, 'hr_catatan=Aset+Belum+Diklasifikasi') || str_contains($html2, 'hr_catatan=Aset%20Belum%20Diklasifikasi'),
      'the summary count links to the high-risk pane filtered by ITS OWN catatan, not by priority');
check(!str_contains($html2, 'hr_catatan=MEDIUM'),
      'the drill-down link is never built from the priority column');
check(str_contains($html2, 'hr_priority=MEDIUM'),
      'the drill-down link ALSO carries the priority - the summary groups on both, so the link keys on both');

// --- the drill-down keys on the summary's WHOLE group key ---------------
// evaluasiSummary() GROUPs on ['e.priority', 'e.catatan']. The catatan
// alone happens to identify a group today only because priorityNote() maps
// each catatan to exactly one priority - by the CURRENT mapping, not by
// construction, and v3.6.0 changed that mapping, which is this branch's own
// reason for refusing to key on priority. ANDing the priority in makes the
// link exactly the group key, so a future mapping change cannot make a
// clicked count and the rows behind it disagree.
//
// This is NOT the priority filter section 8 forbids: that forbids
// SUBSTITUTING priority for catatan, which merges `Aktivitas Admin` with
// `Aset Belum Diklasifikasi`. An intersection can only narrow.
//
// The fixture deliberately crosses the two axes - one catatan under TWO
// priorities - which today's mapping does not produce. That is the point:
// it is the shape a future mapping change would produce, and the assertion
// exists so the drill-down is already correct when it does.
$crossPool = [
    highriskFixtureRow('Aset Belum Diklasifikasi', 'MEDIUM'),
    highriskFixtureRow('Aset Belum Diklasifikasi', 'HIGH'),
    highriskFixtureRow('Aktivitas Admin', 'MEDIUM'),
];

$db3 = new FakeDB(); $GLOBALS['DB'] = $db3;
$db3->canned = [$crossPool];
$res = $R::report('evaluasi_highrisk', $period, [
    'catatan' => 'Aset Belum Diklasifikasi', 'priority' => 'MEDIUM',
]);
$both = array_map(
    static fn(array $r): array => [$r[10], $r[12]],   // Priority, Catatan
    iterator_to_array($res['rows'])
);
check($both === [['MEDIUM', 'Aset Belum Diklasifikasi']],
      'filtering by catatan AND priority returns only the rows matching BOTH (got ' . json_encode($both) . ')');

// And the intersection NARROWS, never merges: the same catatan under a
// different priority is a different summary row and must not come back.
$db3->canned = [$crossPool];
$res = $R::report('evaluasi_highrisk', $period, [
    'catatan' => 'Aset Belum Diklasifikasi', 'priority' => 'HIGH',
]);
$both = array_map(
    static fn(array $r): array => [$r[10], $r[12]],
    iterator_to_array($res['rows'])
);
check($both === [['HIGH', 'Aset Belum Diklasifikasi']],
      'the same catatan under a different priority is a different group and is not returned (got ' . json_encode($both) . ')');

// tabUrl() must carry hr_priority forward too, or the filter degrades into
// the catatan-only one on the first click of a pager or a column header.
$db4 = new FakeDB(); $GLOBALS['DB'] = $db4;
$db4->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [['priority' => 'MEDIUM', 'catatan' => 'Aset Belum Diklasifikasi', 'n' => 1]],
    [], [], [], [],
    [['cnt' => 1]], $crossPool,
];
ob_start();
$R::renderReportTab([
    'month' => '2026-02', 'rtab' => 'evaluasi_highrisk',
    'hr_catatan' => 'Aset Belum Diklasifikasi', 'hr_priority' => 'MEDIUM',
]);
$html3 = ob_get_clean();
check(str_contains($html3, 'hr_priority=MEDIUM'),
      'the pager/sort links on the high-risk pane carry hr_priority forward as well as hr_catatan');

// --- an empty catatan must not produce a link --------------------------
// tabUrl() assigns an override without the !== '' guard its $get-parsing
// branch applies, so an empty catatan round-trips to '', highriskWhere()
// skips the filter entirely, and the pane shows EVERY high-risk row in the
// period under a count that said N. A dead-end count is far better than a
// wrong one.
$db5 = new FakeDB(); $GLOBALS['DB'] = $db5;
$db5->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 0, 'p_low' => 3, 'unclassified' => 3]],
    [['priority' => 'LOW', 'catatan' => '', 'n' => 3]],   // evaluasi_summary
    [], [], [], [],
    [['cnt' => 0]], [],
];
ob_start();
$R::renderReportTab(['month' => '2026-02']);
$html4 = ob_get_clean();
check(!str_contains($html4, 'hr_catatan='),
      'a summary row with an EMPTY catatan builds no drill-down link - an unfiltered pane under a count that said 3 is worse than a dead end');
check(str_contains($html4, '<td class="text-end">3</td>'),
      'and the count is still rendered, just unlinked (dead end, not missing)');

// --- export-shaped opts: the contract front/reportexport.php depends on -
// front/reportexport.php itself is NOT exercised here and this does not
// pretend to be a test of it: that file requires GLPI's Session class via
// ../../../inc/includes.php (not present in this harness), ends in a bare
// `exit;` that would kill this whole test process rather than just the one
// check, and its CSV/XLS/print output writes raw bytes straight to stdout
// that would corrupt every PASS/FAIL line every OTHER test in this file
// greps for. This instead pins the one contract that controller depends
// on: report() honours 'catatan' in an opts array shaped exactly the way
// reportexport.php builds it (sort/dir/q/catatan).
$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [$hrPool];
$exportOpts = ['sort' => '', 'dir' => 'desc', 'q' => '', 'catatan' => 'Aset Belum Diklasifikasi', 'priority' => ''];
$res = $R::report('evaluasi_highrisk', $period, $exportOpts);
$notes = array_column(iterator_to_array($res['rows']), 12);
check($notes !== [] && array_unique($notes) === ['Aset Belum Diklasifikasi'],
      'an export-shaped opts array (sort/dir/q/catatan) with catatan set returns only that catatan (got '
    . json_encode(array_unique($notes)) . ')');

// If reportexport.php forgot to thread hr_catatan into 'catatan' - the
// exact regression this fix round exists to close - report() would be
// handed opts with no 'catatan' key at all, and the whole period comes
// back unfiltered instead of just the drilled-into rows.
$db->canned = [$hrPool];
$exportOptsNoCatatan = ['sort' => '', 'dir' => 'desc', 'q' => ''];
$res = $R::report('evaluasi_highrisk', $period, $exportOptsNoCatatan);
$notes2 = array_column(iterator_to_array($res['rows']), 12);
check(array_unique($notes2) !== ['Aset Belum Diklasifikasi'],
      'omitting catatan from the export opts (what reportexport.php would send if it forgot to read hr_catatan) returns the unfiltered set, not just the drilled-into rows');

// --- drill-down from the category card ----------------------------------
// hr_kategori: the same "dedicated exact-match parameter, never the LIKE
// search" shape as hr_catatan. hr_q is a LIKE across several columns, so a
// category name could also match a command containing that text - and
// then the card's number and the detail's row count would disagree, which
// reads as a bug somewhere else entirely.
$categoryPool = [
    highriskFixtureRow('Aktivitas Admin', 'MEDIUM', 'Destructive'),
    highriskFixtureRow('Aktivitas Admin', 'MEDIUM', 'Reconnaissance'),
    // Belum Dikategorikan is a real filter value, not a null-ish
    // placeholder - it is the bucket an operator works through, so it must
    // be reachable through the exact same parameter as any other category.
    highriskFixtureRow('Aset Belum Diklasifikasi', 'MEDIUM', 'Belum Dikategorikan'),
];

$db = new FakeDB(); $GLOBALS['DB'] = $db;
$db->canned = [$categoryPool];
$res   = $R::report('evaluasi_highrisk', $period, ['kategori' => 'Destructive']);
$rows  = is_array($res['rows']) ? $res['rows'] : iterator_to_array($res['rows']);
$kinds = array_unique(array_column($rows, 11));   // Kategori column index
check($kinds !== [] && $kinds === ['Destructive'],
      'filtering by kategori returns ONLY that category (got ' . json_encode($kinds) . ')');

$db->canned = [$categoryPool];
$res  = $R::report('evaluasi_highrisk', $period, ['kategori' => 'Destruct']);
$rows = is_array($res['rows']) ? $res['rows'] : iterator_to_array($res['rows']);
check($rows === [],
      'a partial category name matches nothing - exact, not LIKE, so the number '
    . 'clicked and the rows shown cannot drift apart');

// Belum Dikategorikan is a real filter value, not a null-ish placeholder:
// it is the bucket an operator works through, so it must be reachable.
$db->canned = [$categoryPool];
$res  = $R::report('evaluasi_highrisk', $period, ['kategori' => 'Belum Dikategorikan']);
$rows = is_array($res['rows']) ? $res['rows'] : iterator_to_array($res['rows']);
check(array_unique(array_column($rows, 11)) === ['Belum Dikategorikan'] || $rows === [],
      'the uncategorised bucket is reachable by the same filter as any other');

// --- the category summary card's own drill-down link --------------------
// Built from the category_summary row's own value (column 0), and it must
// clear any hr_catatan left over from the OTHER card's drill-down: left in
// place it ANDs with the new category filter and the pane shows a subset
// of the number that was clicked.
$db7 = new FakeDB(); $GLOBALS['DB'] = $db7;
$db7->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [],                                                          // evaluasi_summary (empty - no links to build there)
    [], [],                                                      // user/asset summary
    [['risk_category' => 'Destructive', 'n' => 2]],               // category_summary
    [],                                                          // top_commands
    [['cnt' => 0]], [],                                          // highrisk count + page
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'hr_catatan' => 'Aset Belum Diklasifikasi']);
$html5 = ob_get_clean();
check(str_contains($html5, 'hr_kategori=Destructive'),
      'the category summary count links to the high-risk pane filtered by ITS OWN category');
// Isolate the actual href built for the category link (param order is not
// guaranteed, so this parses the URL rather than pattern-matching adjacent
// substrings, which would trivially pass whether or not hr_catatan were
// present anywhere else in the querystring).
preg_match('/href="([^"]*hr_kategori=Destructive[^"]*)"/', $html5, $catLinkMatch);
$catLinkUrl = $catLinkMatch[1] ?? '';
check($catLinkUrl !== '', 'the category drill-down link was found in the rendered page');
$catLinkQuery = parse_url(html_entity_decode($catLinkUrl), PHP_URL_QUERY) ?? '';
parse_str($catLinkQuery, $catLinkParams);
check(!array_key_exists('hr_catatan', $catLinkParams),
      'the category link clears any hr_catatan left over from the other card - '
    . 'left in place it would AND with the category and show a subset of the number clicked '
    . '(got query ' . $catLinkQuery . ')');

// --- the toolbar names the active category and offers to clear it -------
$db8 = new FakeDB(); $GLOBALS['DB'] = $db8;
$db8->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [['priority' => 'MEDIUM', 'catatan' => 'Aktivitas Admin', 'n' => 2]], // evaluasi_summary
    [], [], [], [],
    [['cnt' => 1]], [$categoryPool[0]],                          // highrisk count + page (active tab)
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'rtab' => 'evaluasi_highrisk', 'hr_kategori' => 'Destructive']);
$html6 = ob_get_clean();
check(str_contains($html6, 'hr_kategori=Destructive'),
      'the pager/sort links on the high-risk pane carry hr_kategori forward');
check(str_contains($html6, 'Filtered to category') && str_contains($html6, 'Destructive'),
      'the toolbar names the active category filter so a reader can see why rows are missing');
check(substr_count($html6, htmlescape(__('Clear', 'integrasipaman'))) === 1,
      'a link is offered to clear the active category filter');

// --- Fix round 1: the two summary cards must clear EACH OTHER's filter --
// The category_summary link nulled hr_catatan but not hr_priority, and the
// evaluasi_summary link never nulled hr_kategori at all. Either gap means
// an operator who drills into one card and then clicks the other carries a
// leftover filter that ANDs with the new one, showing a subset of the
// number just clicked - the exact failure this task exists to close.
//
// $get carries BOTH hr_catatan and hr_priority (the evaluasi_summary pair)
// plus a category_summary row for 'Belum Dikategorikan' alongside
// 'Destructive', so this render also pins the space-containing bucket name
// round-tripping through tabUrl() -> http_build_query() and back through
// parse_str() intact.
$db9 = new FakeDB(); $GLOBALS['DB'] = $db9;
$db9->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [],                                                                     // evaluasi_summary (empty)
    [], [],                                                                 // user/asset summary
    [['risk_category' => 'Destructive', 'n' => 2],
     ['risk_category' => 'Belum Dikategorikan', 'n' => 1]],                 // category_summary
    [],                                                                     // top_commands
    [['cnt' => 0]], [],                                                     // highrisk count + page
];
ob_start();
$R::renderReportTab([
    'month' => '2026-02', 'hr_catatan' => 'Aset Belum Diklasifikasi', 'hr_priority' => 'MEDIUM',
]);
$html7 = ob_get_clean();

preg_match('/href="([^"]*hr_kategori=Destructive[^"]*)"/', $html7, $catLinkMatch2);
$catLinkUrl2 = $catLinkMatch2[1] ?? '';
check($catLinkUrl2 !== '', 'the Destructive category link was found in the rendered page');
parse_str(parse_url(html_entity_decode($catLinkUrl2), PHP_URL_QUERY) ?? '', $catLinkParams2);
check(!array_key_exists('hr_catatan', $catLinkParams2) && !array_key_exists('hr_priority', $catLinkParams2),
      'the category link clears BOTH halves of the evaluasi_summary group key (hr_catatan AND hr_priority) - '
    . 'left in place either one would AND with the category and show a subset of the number clicked '
    . '(got ' . json_encode($catLinkParams2) . ')');

// The uncategorised bucket's own link must carry its exact, space-including
// name through the URL and back - it is the bucket an operator will click
// most, and it is the one value in this feature most likely to be mangled
// by an encode/decode round trip that a plain string never would be.
preg_match('/href="([^"]*hr_kategori=Belum[^"]*)"/', $html7, $uncatLinkMatch);
$uncatLinkUrl = $uncatLinkMatch[1] ?? '';
check($uncatLinkUrl !== '', 'the Belum Dikategorikan category link was found in the rendered page');
parse_str(parse_url(html_entity_decode($uncatLinkUrl), PHP_URL_QUERY) ?? '', $uncatLinkParams);
check(($uncatLinkParams['hr_kategori'] ?? null) === 'Belum Dikategorikan',
      'the uncategorised bucket round-trips through the URL as the exact string, space intact (got '
    . json_encode($uncatLinkParams['hr_kategori'] ?? null) . ')');

// Symmetric direction: a catatan drill-down link must clear hr_kategori,
// or clicking a summary row after having drilled into a category carries
// the category filter along and the pane shows a subset of the number
// just clicked on the summary card.
$db10 = new FakeDB(); $GLOBALS['DB'] = $db10;
$db10->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [['priority' => 'MEDIUM', 'catatan' => 'Aset Belum Diklasifikasi', 'n' => 2]], // evaluasi_summary
    [], [], [],                                                             // user/asset/category_summary (empty)
    [],                                                                     // top_commands
    [['cnt' => 0]], [],                                                     // highrisk count + page
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'hr_kategori' => 'Destructive']);
$html8 = ob_get_clean();

preg_match('/href="([^"]*hr_catatan=Aset[^"]*)"/', $html8, $catatanLinkMatch);
$catatanLinkUrl = $catatanLinkMatch[1] ?? '';
check($catatanLinkUrl !== '', 'the evaluasi_summary drill-down link was found in the rendered page');
parse_str(parse_url(html_entity_decode($catatanLinkUrl), PHP_URL_QUERY) ?? '', $catatanLinkParams);
check(!array_key_exists('hr_kategori', $catatanLinkParams),
      'the evaluasi_summary link clears any hr_kategori left over from the category card - '
    . 'left in place it would AND with the catatan and show a subset of the number clicked '
    . '(got ' . json_encode($catatanLinkParams) . ')');

// The page renders several GET forms (the period picker is the first), so
// pick the one that actually holds the search box rather than the first
// match - otherwise every check below reads the period form and says
// nothing about the toolbar.
function hrSearchForm(string $html): string {
    preg_match_all('/<form method="get"[^>]*>(.*?)<\/form>/s', $html, $m);
    foreach ($m[1] as $inner) {
        if (str_contains($inner, 'name="hr_q"')) { return $inner; }
    }
    return '';
}

// --- the search form must replay the ACTIVE drill-down filters ----------
// The GET form emitted tab, rtab, the period inputs, hr_sort and hr_dir,
// but none of hr_catatan, hr_priority or hr_kategori. An operator who
// drills into a category showing 40 rows and then types anything into the
// search box silently lands on the whole period's high-risk set, and the
// "filtered to" banner goes with it. Same "number clicked != rows shown"
// failure as the export, arriving through the search box.
$db10 = new FakeDB(); $GLOBALS['DB'] = $db10;
$db10->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [], [], [], [], [],
    [['cnt' => 1]], [$categoryPool[0]],                          // highrisk count + page (active tab)
];
ob_start();
$R::renderReportTab([
    'month' => '2026-02', 'rtab' => 'evaluasi_highrisk',
    // These match $categoryPool[0] exactly: FakeDB's WHERE emulation walks
    // the conditions in order and reads $rows[0] each time, so a filter that
    // excludes every fixture row makes the HARNESS fatal, not the assertion
    // fail. The subject here is the hidden inputs, not the WHERE clause.
    'hr_catatan' => 'Aktivitas Admin', 'hr_priority' => 'MEDIUM',
    'hr_kategori' => 'Destructive',
]);
$html9 = ob_get_clean();

// Bound to the search FORM, not the whole page: all three names also appear
// in the pager and sort hrefs on this same render, so a whole-page search
// would pass with every hidden input deleted - which is exactly the bug.
$hrForm = hrSearchForm($html9);
check($hrForm !== '' && str_contains($hrForm, 'name="hr_q"'),
      'the high-risk search form was isolated from the rendered page, so the '
    . 'checks below are not reading the pager links instead');
check(str_contains($hrForm, '<input type="hidden" name="hr_catatan" value="Aktivitas Admin">')
   && str_contains($hrForm, '<input type="hidden" name="hr_priority" value="MEDIUM">')
   && str_contains($hrForm, '<input type="hidden" name="hr_kategori" value="Destructive">'),
      'the search form replays ALL THREE active drill-down filters, so '
    . 'searching inside a drill-down narrows that drill-down instead of '
    . 'silently widening to the whole period (got ' . $hrForm . ')');

// And an inactive filter contributes no empty hidden input, which would
// otherwise put hr_kategori= in the querystring and read as a real filter
// on an empty category name.
$db11 = new FakeDB(); $GLOBALS['DB'] = $db11;
$db11->canned = [
    [['ym' => '2026-02']],
    [['total' => 10, 'high_risk' => 3, 'users' => 3, 'assets' => 4,
      'p_high' => 0, 'p_medium' => 3, 'p_low' => 0, 'unclassified' => 0]],
    [], [], [], [], [],
    [['cnt' => 1]], [$categoryPool[0]],
];
ob_start();
$R::renderReportTab(['month' => '2026-02', 'rtab' => 'evaluasi_highrisk']);
$html10 = ob_get_clean();
$hrForm2 = hrSearchForm($html10);
check($hrForm2 !== '' && !str_contains($hrForm2, 'name="hr_kategori"'),
      'with no filter active the form emits no empty hr_kategori input, which '
    . 'would read as a filter on the empty category name');


exit($fails > 0 ? 1 : 0);
