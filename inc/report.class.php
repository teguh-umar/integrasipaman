<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * PAM Command Audit Piece 4: period-scoped reporting over this plugin's
 * own glpi_plugin_jumpserver_commands + _command_eval tables. Pure data
 * (resolvePeriod / availableMonths / kpis / report) plus the render for
 * the "Command Audit Report" tab on front/pbi.php. Downloads are routed
 * by front/reportexport.php through PluginIntegrasipamanExporter. See
 * docs/superpowers/specs/2026-09-07-pam-command-audit-piece4-reporting-design.md.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanReport
{
    private const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    private const EVAL_TABLE     = 'glpi_plugin_jumpserver_command_eval';

    /** Report keys accepted by report() and front/reportexport.php. */
    public const REPORTS = [
        'evaluasi_summary',
        'user_summary',
        'asset_summary',
        'category_summary',
        'top_commands',
        'evaluasi_highrisk',
        'command_detail',
    ];

    private const TOP_COMMANDS_LIMIT = 100;
    private const HIGHRISK_PAGE_SIZE = 100;
    private const RANGE_CAP_DAYS     = 366;

    /** Sortable columns for the on-page EVALUASI_HIGHRISK table: label key => SQL column. */
    private const HIGHRISK_SORT = [
        'timestamp' => 'c.executed_at',
        'user'      => 'c.jms_user',
        'asset'     => 'c.asset',
        'priority'  => 'e.priority',
        'kategori'  => 'e.risk_category',
    ];

    /**
     * Distinct YYYY-MM values present in the commands table, newest first.
     *
     * @return list<string>
     */
    public static function availableMonths(): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'SELECT' => [new QueryExpression("DATE_FORMAT(executed_at, '%Y-%m') AS " . $DB->quoteName('ym'))],
            'DISTINCT' => true,
            'FROM'   => self::COMMANDS_TABLE,
            'WHERE'  => [['executed_at' => ['>', '1970-01-01 00:00:00']]],
            'ORDER'  => 'ym DESC',
        ]) as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym !== '') {
                $out[] = $ym;
            }
        }

        return $out;
    }

    /**
     * Resolve request input to a concrete [start, end) window.
     *
     * @param array<string, mixed> $in Keys: month (YYYY-MM), from/to (YYYY-MM-DD), cap (bool).
     * @return array{start:string,end:string,label:string,stub:string,capped:bool}
     */
    public static function resolvePeriod(array $in): array
    {
        $cap = !empty($in['cap']);

        // 1. explicit custom range wins, if both ends are valid and ordered
        $from = self::validDate((string) ($in['from'] ?? ''));
        $to   = self::validDate((string) ($in['to'] ?? ''));
        if ($from !== null && $to !== null && $from <= $to) {
            return self::window($from, $to->modify('+1 day'), $cap);
        }

        // 2. explicit month
        $month = (string) ($in['month'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m)
            && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', "$month-01");
            if ($start instanceof DateTimeImmutable) {
                return self::window($start, $start->modify('first day of next month'), $cap);
            }
        }

        // 3. default: newest available month, else current month
        $months = self::availableMonths();
        $newest = $months[0] ?? date('Y-m');
        $start  = DateTimeImmutable::createFromFormat('!Y-m-d', "$newest-01")
                  ?: new DateTimeImmutable('first day of this month 00:00:00');

        return self::window($start, $start->modify('first day of next month'), $cap);
    }

    /**
     * @return array{start:string,end:string,label:string,stub:string,capped:bool}
     */
    private static function window(DateTimeImmutable $start, DateTimeImmutable $end, bool $cap): array
    {
        $capped = false;
        if ($cap) {
            $max_end = $start->modify('+' . self::RANGE_CAP_DAYS . ' days');
            if ($end > $max_end) {
                $end    = $max_end;
                $capped = true;
            }
        }

        $last = $end->modify('-1 day');

        return [
            'start'  => $start->format('Y-m-d H:i:s'),
            'end'    => $end->format('Y-m-d H:i:s'),
            'label'  => $start->format('Y-m-d') . ' – ' . $last->format('Y-m-d'),
            'stub'   => $start->format('Ymd') . '_' . $last->format('Ymd'),
            'capped' => $capped,
        ];
    }

    /** Strict YYYY-MM-DD -> DateTimeImmutable at 00:00:00, or null. */
    private static function validDate(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return ($d instanceof DateTimeImmutable && $d->format('Y-m-d') === $value) ? $d : null;
    }

    /**
     * Headline numbers for the whole period (one aggregate query).
     *
     * @param array{start:string,end:string} $period
     * @return array{total:int,high_risk:int,high_risk_pct:float,users:int,assets:int,p_high:int,p_medium:int,p_low:int,unclassified:int}
     */
    public static function kpis(array $period): array
    {
        global $DB;

        $e = self::EVAL_TABLE;
        $it = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS ' . $DB->quoteName('total')),
                new QueryExpression('COALESCE(SUM(' . $DB->quoteName('e.is_high_risk') . '), 0) AS ' . $DB->quoteName('high_risk')),
                new QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('c.jms_user') . ') AS ' . $DB->quoteName('users')),
                new QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('c.asset') . ') AS ' . $DB->quoteName('assets')),
                new QueryExpression("COALESCE(SUM(" . $DB->quoteName('e.priority') . " = 'HIGH'), 0) AS " . $DB->quoteName('p_high')),
                new QueryExpression("COALESCE(SUM(" . $DB->quoteName('e.priority') . " = 'MEDIUM'), 0) AS " . $DB->quoteName('p_medium')),
                new QueryExpression("COALESCE(SUM(" . $DB->quoteName('e.priority') . " = 'LOW'), 0) AS " . $DB->quoteName('p_low')),
                new QueryExpression('SUM(CASE WHEN ' . $DB->quoteName('e.id') . ' IS NULL THEN 1 ELSE 0 END) AS ' . $DB->quoteName('unclassified')),
            ],
            'FROM'      => self::COMMANDS_TABLE . ' AS c',
            'LEFT JOIN' => [$e . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'     => self::periodWhere($period),
        ]);
        $row = $it->current() ?: [];

        $total     = (int) ($row['total'] ?? 0);
        $high_risk = (int) ($row['high_risk'] ?? 0);

        return [
            'total'         => $total,
            'high_risk'     => $high_risk,
            'high_risk_pct' => $total > 0 ? round($high_risk * 100 / $total, 2) : 0.0,
            'users'         => (int) ($row['users'] ?? 0),
            'assets'        => (int) ($row['assets'] ?? 0),
            'p_high'        => (int) ($row['p_high'] ?? 0),
            'p_medium'      => (int) ($row['p_medium'] ?? 0),
            'p_low'         => (int) ($row['p_low'] ?? 0),
            'unclassified'  => (int) ($row['unclassified'] ?? 0),
        ];
    }

    /**
     * One report dataset.
     *
     * @param array{start:string,end:string} $period
     * @param array<string,mixed> $opts
     * @return array{columns:list<string>,rows:iterable,total:?int}
     */
    public static function report(string $key, array $period, array $opts = []): array
    {
        global $DB;

        switch ($key) {
            case 'user_summary':
                return self::groupCountReport(
                    ['User', 'TotalCommands'], 'c.jms_user', $period, null
                );

            case 'asset_summary':
                return self::groupCountReport(
                    ['Asset', 'TotalCommands'], 'c.asset', $period, null
                );

            case 'top_commands':
                return self::groupCountReport(
                    ['Command', 'Count'], 'c.command', $period, self::TOP_COMMANDS_LIMIT
                );

            case 'category_summary':
                return self::groupCountReport(
                    ['Kategori', 'Jumlah'], 'e.risk_category', $period, null, true
                );

            case 'evaluasi_summary':
                return self::evaluasiSummary($period);

            case 'evaluasi_highrisk':
                return self::evaluasiHighrisk($period, $opts);

            case 'command_detail':
                return self::commandDetail($period);

            default:
                return ['columns' => [], 'rows' => [], 'total' => 0];
        }
    }

    /**
     * WHERE for the period: c.executed_at in [start, end).
     *
     * @param array{start:string,end:string} $period
     * @return array<int,array<string,array{0:string,1:string}>>
     */
    private static function periodWhere(array $period): array
    {
        return [
            ['c.executed_at' => ['>=', $period['start']]],
            ['c.executed_at' => ['<', $period['end']]],
        ];
    }

    /**
     * "<dim>, COUNT(*)" grouped report. $highRisk = true adds the eval
     * join + is_high_risk filter; otherwise it is a pure commands query.
     *
     * @return array{columns:list<string>,rows:list<list<string>>,total:null}
     */
    private static function groupCountReport(array $columns, string $dim, array $period, ?int $limit, bool $highRisk = false): array
    {
        global $DB;

        $spec = [
            'SELECT'  => [$dim, new QueryExpression('COUNT(*) AS ' . $DB->quoteName('n'))],
            'FROM'    => self::COMMANDS_TABLE . ' AS c',
            'WHERE'   => self::periodWhere($period),
            'GROUPBY' => $dim,
            'ORDER'   => 'n DESC',
        ];
        if ($limit !== null) {
            $spec['LIMIT'] = $limit;
        }
        if ($highRisk) {
            $spec['INNER JOIN'] = [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]];
            $spec['WHERE']['e.is_high_risk'] = 1;
        }

        $dimKey = str_contains($dim, '.') ? substr($dim, strpos($dim, '.') + 1) : $dim;
        $rows   = [];
        foreach ($DB->request($spec) as $r) {
            $rows[] = [(string) ($r[$dimKey] ?? ''), (string) (int) ($r['n'] ?? 0)];
        }

        return ['columns' => $columns, 'rows' => $rows, 'total' => null];
    }

    /**
     * EVALUASI_SUMMARY: priority x catatan counts, high-risk only,
     * ordered HIGH -> MEDIUM -> LOW.
     *
     * @return array{columns:list<string>,rows:list<list<string>>,total:null}
     */
    private static function evaluasiSummary(array $period): array
    {
        global $DB;

        $rank = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2];
        $rows = [];
        foreach ($DB->request([
            'SELECT'  => ['e.priority', 'e.catatan', new QueryExpression('COUNT(*) AS ' . $DB->quoteName('n'))],
            'FROM'    => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'   => self::periodWhere($period) + ['e.is_high_risk' => 1],
            'GROUPBY' => ['e.priority', 'e.catatan'],
        ]) as $r) {
            $rows[] = [(string) $r['priority'], (string) $r['catatan'], (string) (int) $r['n']];
        }
        usort($rows, static fn($a, $b) => ($rank[$a[0]] ?? 9) <=> ($rank[$b[0]] ?? 9));

        return ['columns' => ['Priority', 'Catatan', 'Jumlah'], 'rows' => $rows, 'total' => null];
    }

    private const HIGHRISK_COLUMNS = [
        'Timestamp', 'User', 'UserStatus', 'Asset', 'AssetIP', 'IsProduction',
        'Account', 'Command', 'RiskLevel', 'RiskLabel', 'Priority', 'Kategori',
        'Catatan', 'Session', 'RemoteAddr',
    ];

    /** Row count for EVALUASI_HIGHRISK over the period (pager + page clamping). */
    private static function highriskTotal(array $period): int
    {
        global $DB;

        $row = $DB->request([
            'COUNT'      => 'cnt',
            'FROM'       => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => self::periodWhere($period) + ['e.is_high_risk' => 1],
        ])->current() ?: [];

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * EVALUASI_HIGHRISK. $opts['paginate'] true -> LIMIT/OFFSET + total
     * count for the on-page table; otherwise a full-set generator for export.
     *
     * @return array{columns:list<string>,rows:iterable,total:?int}
     */
    private static function evaluasiHighrisk(array $period, array $opts): array
    {
        global $DB;

        $sortKey = (string) ($opts['sort'] ?? '');
        $sortCol = self::HIGHRISK_SORT[$sortKey] ?? 'c.executed_at';
        $dir     = strtolower((string) ($opts['dir'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

        $base = [
            'SELECT' => [
                'c.executed_at', 'c.jms_user', 'e.user_is_admin', 'c.asset', 'e.asset_ip',
                'e.asset_is_production', 'c.account', 'c.command', 'c.risk_level', 'c.risk_label',
                'e.priority', 'e.risk_category', 'e.catatan', 'c.session_jms_id', 'c.remote_addr',
            ],
            'FROM'       => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => self::periodWhere($period) + ['e.is_high_risk' => 1],
            'ORDER'      => $sortCol . ' ' . $dir,
        ];

        $mapRow = static function (array $r): array {
            return [
                (string) $r['executed_at'],
                (string) $r['jms_user'],
                ((int) $r['user_is_admin']) === 1 ? 'ADMIN' : 'USER',
                (string) $r['asset'],
                (string) $r['asset_ip'],
                ((int) $r['asset_is_production']) === 1 ? 'True' : 'False',
                (string) $r['account'],
                (string) $r['command'],
                (string) (int) $r['risk_level'],
                (string) $r['risk_label'],
                (string) $r['priority'],
                (string) $r['risk_category'],
                (string) $r['catatan'],
                (string) $r['session_jms_id'],
                (string) $r['remote_addr'],
            ];
        };

        if (!empty($opts['paginate'])) {
            // The caller (renderHighriskCard) already counted, to clamp the page.
            $total = isset($opts['total']) ? (int) $opts['total'] : self::highriskTotal($period);

            $page  = max(1, (int) ($opts['page'] ?? 1));
            $spec  = $base + ['START' => ($page - 1) * self::HIGHRISK_PAGE_SIZE, 'LIMIT' => self::HIGHRISK_PAGE_SIZE];
            $rows  = [];
            foreach ($DB->request($spec) as $r) {
                $rows[] = $mapRow($r);
            }

            return ['columns' => self::HIGHRISK_COLUMNS, 'rows' => $rows, 'total' => $total];
        }

        $gen = (static function () use ($DB, $base, $mapRow) {
            foreach ($DB->request($base) as $r) {
                yield $mapRow($r);
            }
        })();

        return ['columns' => self::HIGHRISK_COLUMNS, 'rows' => $gen, 'total' => null];
    }

    private const DETAIL_COLUMNS = [
        'Timestamp', 'User', 'Asset', 'Account', 'Command', 'Output',
        'RiskLevel', 'RiskLabel', 'Session', 'RemoteAddr',
    ];

    /**
     * COMMAND_DETAIL: every command in the period, raw columns, streamed.
     * "Output" is always blank (command output is never stored - Piece 1).
     *
     * @return array{columns:list<string>,rows:Generator,total:null}
     */
    private static function commandDetail(array $period): array
    {
        global $DB;

        $gen = (static function () use ($DB, $period) {
            $it = $DB->request([
                'SELECT' => ['c.executed_at', 'c.jms_user', 'c.asset', 'c.account', 'c.command',
                             'c.risk_level', 'c.risk_label', 'c.session_jms_id', 'c.remote_addr'],
                'FROM'   => self::COMMANDS_TABLE . ' AS c',
                'WHERE'  => self::periodWhere($period),
                'ORDER'  => 'c.executed_at ASC',
            ]);
            foreach ($it as $r) {
                yield [
                    (string) $r['executed_at'],
                    (string) $r['jms_user'],
                    (string) $r['asset'],
                    (string) $r['account'],
                    (string) $r['command'],
                    '',
                    (string) (int) $r['risk_level'],
                    (string) $r['risk_label'],
                    (string) $r['session_jms_id'],
                    (string) $r['remote_addr'],
                ];
            }
        })();

        return ['columns' => self::DETAIL_COLUMNS, 'rows' => $gen, 'total' => null];
    }

    /** Report key => on-screen card title (localised at render time). */
    private const CARD_TITLES = [
        'evaluasi_summary'  => 'Evaluation summary',
        'user_summary'      => 'Commands per user',
        'asset_summary'     => 'Commands per asset',
        'category_summary'  => 'High-risk by category',
        'top_commands'      => 'Top commands',
    ];

    public static function renderReportTab(array $get): void
    {
        $period = self::resolvePeriod($get);
        $base   = Plugin::getWebDir('integrasipaman');

        self::renderTabStyle();
        self::renderPeriodForm($get, $period, $base);
        self::renderKpiStrip(self::kpis($period));

        // period query-string carried on every export/pager link
        $pq = self::periodQuery($get);

        foreach (self::CARD_TITLES as $key => $title) {
            $data = self::report($key, $period);
            self::renderSummaryCard(__($title, 'integrasipaman'), $key, $data, $base, $pq);
        }

        self::renderHighriskCard($get, $period, $base, $pq);

        // COMMAND_DETAIL: export-only, one link at the foot of the tab
        echo '<div class="card mb-4"><div class="card-body">';
        echo '<span class="fw-bold">' . htmlescape(__('Full command log', 'integrasipaman')) . '</span> ';
        echo '<span class="text-muted">' . htmlescape(__('(every command in the period, not shown on screen)', 'integrasipaman')) . '</span> ';
        echo '<a class="btn btn-sm btn-outline-secondary ms-2" href="'
            . htmlescape($base . '/front/reportexport.php?report=command_detail&format=csv&' . $pq)
            . '">' . htmlescape(__('Download CSV', 'integrasipaman')) . '</a>';
        echo '</div></div>';
    }

    /** @param array<string,mixed> $get */
    private static function periodQuery(array $get): string
    {
        $from = self::validDate((string) ($get['from'] ?? ''));
        $to   = self::validDate((string) ($get['to'] ?? ''));
        if ($from !== null && $to !== null && $from <= $to) {
            return http_build_query(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
        }
        $month = (string) ($get['month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            return http_build_query(['month' => $month]);
        }
        return http_build_query(['month' => substr(self::resolvePeriod($get)['start'], 0, 7)]);
    }

    private static function renderPeriodForm(array $get, array $period, string $base): void
    {
        $action = $base . '/front/pbi.php';
        echo '<form method="get" action="' . htmlescape($action) . '" class="integrasipaman-report-period card mb-4"><div class="card-body">';
        echo '<input type="hidden" name="tab" value="report">';

        echo '<label class="form-label me-2">' . htmlescape(__('Month', 'integrasipaman')) . '</label>';
        echo '<select name="month" class="form-select d-inline-block w-auto me-3">';
        $current = (string) ($get['month'] ?? substr($period['start'], 0, 7));
        $months  = self::availableMonths();
        if ($months === []) {
            $months = [date('Y-m')];
        }
        foreach ($months as $m) {
            $sel = $m === $current ? ' selected' : '';
            echo '<option value="' . htmlescape($m) . '"' . $sel . '>' . htmlescape($m) . '</option>';
        }
        echo '</select>';

        echo '<span class="text-muted me-2">' . htmlescape(__('or custom range', 'integrasipaman')) . '</span>';
        echo '<input type="date" name="from" value="' . htmlescape((string) ($get['from'] ?? '')) . '" class="form-control d-inline-block w-auto me-1">';
        echo '<input type="date" name="to" value="' . htmlescape((string) ($get['to'] ?? '')) . '" class="form-control d-inline-block w-auto me-2">';

        echo '<button type="submit" class="btn btn-primary">' . htmlescape(__('Show', 'integrasipaman')) . '</button>';
        echo '<div class="form-hint mt-2">' . htmlescape(sprintf(__('Showing %s', 'integrasipaman'), $period['label']));
        if ($period['capped']) {
            echo ' — ' . htmlescape(__('range capped to 366 days for export', 'integrasipaman'));
        }
        echo '</div>';
        echo '</div></form>';
    }

    /** @param array<string,int|float> $k */
    private static function renderKpiStrip(array $k): void
    {
        $tiles = [
            [__('Total commands', 'integrasipaman'),  (string) $k['total'],     'ti ti-terminal-2', null],
            [__('High-risk', 'integrasipaman'),       $k['high_risk'] . ' (' . $k['high_risk_pct'] . '%)', 'ti ti-alert-triangle', '#d63939'],
            [__('Users', 'integrasipaman'),           (string) $k['users'],     'ti ti-users', null],
            [__('Assets', 'integrasipaman'),          (string) $k['assets'],    'ti ti-server', null],
            [__('HIGH / MEDIUM / LOW', 'integrasipaman'), $k['p_high'] . ' / ' . $k['p_medium'] . ' / ' . $k['p_low'], 'ti ti-list-numbers', null],
        ];

        echo '<div class="integrasipaman-kpi-row">';
        foreach ($tiles as [$label, $number, $icon, $accent]) {
            $style = $accent ? ' style="--kpi-accent:' . htmlescape($accent) . '"' : '';
            echo '<div class="card integrasipaman-kpi"' . $style . '>';
            echo '<i class="' . htmlescape($icon) . ' integrasipaman-kpi-icon" aria-hidden="true"></i>';
            echo '<span class="integrasipaman-kpi-number">' . htmlescape($number) . '</span>';
            echo '<span class="integrasipaman-kpi-label">' . htmlescape($label) . '</span>';
            echo '</div>';
        }
        echo '</div>';

        if (($k['unclassified'] ?? 0) > 0) {
            echo '<p class="text-muted">' . htmlescape(sprintf(
                __('%d command(s) in this period are still awaiting classification.', 'integrasipaman'),
                $k['unclassified']
            )) . '</p>';
        }
    }

    /**
     * @param array{columns:list<string>,rows:iterable,total:?int} $data
     */
    private static function renderSummaryCard(string $title, string $key, array $data, string $base, string $pq): void
    {
        echo '<div class="card mb-4"><div class="card-header d-flex align-items-center">';
        echo '<h3 class="card-title mb-0">' . htmlescape($title) . '</h3>';
        echo '<span class="ms-auto">' . self::exportButtons($key, $base, $pq) . '</span>';
        echo '</div><div class="card-body p-0"><div class="table-responsive">';
        echo '<table class="table table-striped card-table"><thead><tr>';
        foreach ($data['columns'] as $c) {
            echo '<th>' . htmlescape(__($c, 'integrasipaman')) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $any = false;
        foreach ($data['rows'] as $row) {
            $any = true;
            echo '<tr>';
            foreach ($row as $i => $v) {
                $v = (string) $v;
                if ($i === (count($data['columns']) - 1)) {
                    // last column of a summary is always the count
                    echo '<td class="text-end">' . htmlescape($v) . '</td>';
                } else {
                    echo '<td>' . htmlescape(mb_substr($v, 0, 120)) . '</td>';
                }
            }
            echo '</tr>';
        }
        if (!$any) {
            echo '<tr><td colspan="' . count($data['columns']) . '" class="text-muted">'
                . htmlescape(__('No commands in this period.', 'integrasipaman')) . '</td></tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    private static function renderHighriskCard(array $get, array $period, string $base, string $pq): void
    {
        $page = max(1, (int) ($get['hr_page'] ?? 1));
        $sort = (string) ($get['hr_sort'] ?? 'timestamp');
        $dir  = strtolower((string) ($get['hr_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (!isset(self::HIGHRISK_SORT[$sort])) {
            $sort = 'timestamp';
        }

        // Clamp the page against the real row count *before* fetching, so an
        // out-of-range ?hr_page= shows the last page instead of an empty table
        // under a "Page N of N" pager.
        $total = self::highriskTotal($period);
        $pages = max(1, (int) ceil($total / self::HIGHRISK_PAGE_SIZE));
        $page  = min($page, $pages);

        $data  = self::report('evaluasi_highrisk', $period, [
            'paginate' => true, 'page' => $page, 'sort' => $sort, 'dir' => $dir,
            'total'    => $total,
        ]);

        echo '<div class="card mb-4"><div class="card-header d-flex align-items-center">';
        echo '<h3 class="card-title mb-0">' . htmlescape(__('High-risk detail (EVALUASI_HIGHRISK)', 'integrasipaman')) . '</h3>';
        echo '<span class="ms-auto">' . self::exportButtons('evaluasi_highrisk', $base, $pq . '&sort=' . $sort . '&dir=' . $dir) . '</span>';
        echo '</div><div class="card-body p-0"><div class="table-responsive">';
        echo '<table class="table table-striped card-table"><thead><tr>';

        $tabUrl = $base . '/front/pbi.php?tab=report&' . $pq;
        foreach ($data['columns'] as $i => $c) {
            $key = strtolower($c);
            if (isset(self::HIGHRISK_SORT[$key])) {
                $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
                $arrow   = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
                echo '<th><a href="' . htmlescape($tabUrl . '&hr_sort=' . $key . '&hr_dir=' . $nextDir . '&hr_page=1') . '">'
                    . htmlescape(__($c, 'integrasipaman')) . $arrow . '</a></th>';
            } else {
                echo '<th>' . htmlescape(__($c, 'integrasipaman')) . '</th>';
            }
        }
        echo '</tr></thead><tbody>';

        $any = false;
        foreach ($data['rows'] as $row) {
            $any = true;
            echo '<tr>';
            foreach ($row as $i => $v) {
                $v = (string) $v;
                $cell = self::HIGHRISK_COLUMNS[$i] === 'Command' ? mb_substr($v, 0, 120) : $v;
                echo '<td>' . htmlescape($cell) . '</td>';
            }
            echo '</tr>';
        }
        if (!$any) {
            echo '<tr><td colspan="' . count($data['columns']) . '" class="text-muted">'
                . htmlescape(__('No high-risk commands in this period.', 'integrasipaman')) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        // pager
        if ($pages > 1) {
            echo '<div class="card-body d-flex gap-2 align-items-center">';
            echo '<span class="text-muted">' . htmlescape(sprintf(__('Page %1$d of %2$d (%3$d rows)', 'integrasipaman'), $page, $pages, $total)) . '</span>';
            $mk = static function (int $p, string $lbl) use ($tabUrl, $sort, $dir) {
                return '<a class="btn btn-sm btn-outline-secondary" href="'
                    . htmlescape($tabUrl . '&hr_sort=' . $sort . '&hr_dir=' . $dir . '&hr_page=' . $p) . '">'
                    . htmlescape($lbl) . '</a>';
            };
            if ($page > 1)     { echo $mk($page - 1, __('Previous', 'integrasipaman')); }
            if ($page < $pages) { echo $mk($page + 1, __('Next', 'integrasipaman')); }
            echo '</div>';
        }
        echo '</div></div>';
    }

    /** CSV / Excel / Print button group for one report. */
    private static function exportButtons(string $key, string $base, string $pq): string
    {
        $url  = static fn(string $fmt): string => $base . '/front/reportexport.php?report=' . $key . '&format=' . $fmt . '&' . $pq;
        $html = '<div class="btn-group btn-group-sm" role="group">';
        $html .= '<a class="btn btn-outline-secondary" href="' . htmlescape($url('csv')) . '">' . htmlescape(__('CSV', 'integrasipaman')) . '</a>';
        $html .= '<a class="btn btn-outline-secondary" href="' . htmlescape($url('xls')) . '">' . htmlescape(__('Excel', 'integrasipaman')) . '</a>';
        $html .= '<a class="btn btn-outline-secondary" href="' . htmlescape($url('pdf')) . '" target="_blank">' . htmlescape(__('Print', 'integrasipaman')) . '</a>';
        $html .= '</div>';
        return $html;
    }

    private static function renderTabStyle(): void
    {
        echo <<<'CSS'
<style>
.integrasipaman-kpi-row { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem; }
.integrasipaman-kpi {
    flex: 1 1 160px; padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: .25rem;
    border-inline-start: 3px solid var(--kpi-accent, var(--tblr-primary, #066fd1));
}
.integrasipaman-kpi-icon { font-size: 1.15rem; opacity: .6; }
.integrasipaman-kpi-number { font-size: 1.9rem; font-weight: 600; line-height: 1.15; }
.integrasipaman-kpi-label { font-size: .8125rem; opacity: .7; }
</style>
CSS;
    }
}
