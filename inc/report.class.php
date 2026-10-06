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

require_once __DIR__ . '/connection.class.php';

class PluginIntegrasipamanReport
{
    private const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    private const EVAL_TABLE     = 'glpi_plugin_jumpserver_command_eval';
    private const ASSETMAP_TABLE = 'glpi_plugin_jumpserver_assetmap';

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
    private const HIGHRISK_PAGE_SIZE = 25;
    /** Rows per page for each summary card on the report tab. */
    private const SUMMARY_PAGE_SIZE  = 15;
    private const RANGE_CAP_DAYS     = 366;

    /**
     * Columns the EVALUASI_HIGHRISK search box looks in. Deliberately four,
     * not all fifteen: a box that also matched Command or Catatan would turn
     * a search for "HIGH" into a search for every note containing it.
     */
    private const HIGHRISK_SEARCH = ['c.jms_user', 'c.asset', 'e.asset_ip', 'e.priority'];

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
            'WHERE'  => [['executed_at' => ['>', '1970-01-01 00:00:00']]] + PluginIntegrasipamanConnection::viewer(),
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
        // v4.0.0: and only the commands of the entities the viewer works in.
        // Every figure of the report - the KPIs, the six tabs, the high-risk
        // list and total, each export - is built on this one clause, so a
        // count and its rows cannot disagree about whose data they show.
        return [
            ['c.executed_at' => ['>=', $period['start']]],
            ['c.executed_at' => ['<', $period['end']]],
        ] + PluginIntegrasipamanConnection::viewer('c');
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
        'Timestamp', 'User', 'UserStatus', 'Asset', 'AssetIP', 'Classification',
        'Account', 'Command', 'RiskLevel', 'RiskLabel', 'Priority', 'Kategori',
        'Catatan', 'Session', 'RemoteAddr',
    ];

    /**
     * WHERE for EVALUASI_HIGHRISK: the period, high-risk only, and the
     * optional search term OR-ed across HIGHRISK_SEARCH.
     *
     * One builder for the count and the page on purpose - two copies would
     * eventually disagree, and a pager computed from a different filter than
     * the rows is the kind of wrong that looks like a rounding error.
     */
    private static function highriskWhere(array $period, string $q = '', string $catatan = '', string $priority = '', string $kategori = ''): array
    {
        $where = self::periodWhere($period) + ['e.is_high_risk' => 1];

        $q = trim((string) $q);
        if ($q !== '') {
            $or = [];
            foreach (self::HIGHRISK_SEARCH as $col) {
                $or[] = [$col => ['LIKE', '%' . $q . '%']];
            }
            $where[] = ['OR' => $or];
        }

        if ($catatan !== '') {
            // Exact, not LIKE. hr_q already searches several columns, so a
            // catatan string could also match an asset name containing that
            // text - and then the summary's number and the detail's row
            // count would disagree, which reads as a bug somewhere else.
            $where['e.catatan'] = $catatan;
        }

        // ANDed WITH the catatan, never instead of it - and that is not the
        // "never filter on priority" the spec forbids.
        //
        // What section 8 forbids is SUBSTITUTING priority for catatan: MEDIUM
        // maps to both `Aktivitas Admin` and `Aset Belum Diklasifikasi`, so a
        // priority-only link merges two unrelated populations and the row
        // count stops matching the number that was clicked. Intersecting can
        // only ever NARROW, so it cannot merge anything.
        //
        // It is here because the summary GROUPs on ['e.priority',
        // 'e.catatan'] and the drill-down must key on the whole group key. It
        // matches today on catatan alone only because priorityNote() happens
        // to map each catatan to exactly one priority - by the current
        // mapping, not by construction. v3.6.0 changed that mapping, which is
        // this branch's own reason for refusing to key on priority; the next
        // change to it must not be able to make a clicked count and its rows
        // disagree.
        if ($priority !== '') {
            $where['e.priority'] = $priority;
        }

        if ($kategori !== '') {
            // Exact, like the catatan filter beside it. hr_q is a LIKE across
            // several columns, so a category name could also match a command
            // containing that text - and then the card's number and the
            // detail's row count would disagree, which reads as a bug
            // somewhere else entirely.
            $where['e.risk_category'] = $kategori;
        }

        return $where;
    }

    /** Row count for EVALUASI_HIGHRISK over the period (pager + page clamping). */
    private static function highriskTotal(array $period, string $q = '', string $catatan = '', string $priority = '', string $kategori = ''): int
    {
        global $DB;

        $row = $DB->request([
            'COUNT'      => 'cnt',
            'FROM'       => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => self::highriskWhere($period, $q, $catatan, $priority, $kategori),
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
                'e.asset_class', 'c.account', 'c.command', 'c.risk_level', 'c.risk_label',
                'e.priority', 'e.risk_category', 'e.catatan', 'c.session_jms_id', 'c.remote_addr',
            ],
            'FROM'       => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => self::highriskWhere(
                $period,
                (string) ($opts['q'] ?? ''),
                (string) ($opts['catatan'] ?? ''),
                (string) ($opts['priority'] ?? ''),
                (string) ($opts['kategori'] ?? '')
            ),
            'ORDER'      => $sortCol . ' ' . $dir,
        ];

        $mapRow = static function (array $r): array {
            return [
                (string) $r['executed_at'],
                (string) $r['jms_user'],
                ((int) $r['user_is_admin']) === 1 ? 'ADMIN' : 'USER',
                (string) $r['asset'],
                (string) $r['asset_ip'],
                // 'production' and 'development' are rendered verbatim - not
                // reduced to True/False, which is the confusion this whole
                // change exists to undo. Unclassified ('') is the one value
                // that needs a label: an empty CSV cell reads as missing
                // data, indistinguishable from an export glitch, so it gets
                // the same Indonesian phrase already used in catatan.
                ((string) ($r['asset_class'] ?? '')) === ''
                    ? __('(belum diklasifikasi)', 'integrasipaman')
                    : (string) $r['asset_class'],
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
            // The caller (renderHighriskPane) already counted, to clamp the page.
            $total = isset($opts['total'])
                ? (int) $opts['total']
                : self::highriskTotal(
                    $period,
                    (string) ($opts['q'] ?? ''),
                    (string) ($opts['catatan'] ?? ''),
                    (string) ($opts['priority'] ?? ''),
                    (string) ($opts['kategori'] ?? '')
                );

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

    /**
     * Every on-screen report shares one tabbed card. Order here is tab
     * order - summary first, then the per-dimension breakdowns, with the
     * row-level detail last - and the first entry is the default tab.
     */
    private const TAB_CARDS = [
        'evaluasi_summary', 'user_summary', 'asset_summary',
        'category_summary', 'top_commands', 'evaluasi_highrisk',
    ];

    /** Tab labels (localised at render time). */
    private const TAB_TITLES = [
        'evaluasi_summary'  => 'Evaluation summary',
        'user_summary'      => 'Commands per user',
        'asset_summary'     => 'Commands per asset',
        'category_summary'  => 'High-risk by category',
        'top_commands'      => 'Top commands',
        'evaluasi_highrisk' => 'High-risk detail (EVALUASI_HIGHRISK)',
    ];

    private const TAB_ANCHOR = 'integrasipaman-report-tabs';

    public static function renderReportTab(array $get): void
    {
        $period = self::resolvePeriod($get);
        $base   = Plugin::getWebDir('integrasipaman');

        self::renderTabStyle();
        self::renderPeriodForm($get, $period, $base);
        self::renderKpiStrip(self::kpis($period));

        // period query-string carried on every export/pager link
        $pq = self::periodQuery($get);

        self::renderTabGroup($get, $period, $base, $pq);

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

    /**
     * A link back to this tab that keeps every *other* card on the page it
     * is currently showing - so paging one box does not reset the rest.
     * $overrides replaces (or, with null, drops) individual page params.
     *
     * @param array<string,mixed>           $get
     * @param array<string,int|string|null> $overrides
     */
    private static function tabUrl(array $get, string $pq, string $base, array $overrides = [], string $anchor = ''): string
    {
        $params = [];

        foreach (self::TAB_CARDS as $k) {
            $p = max(1, (int) ($get['p_' . $k] ?? 1));
            if ($p > 1) {
                $params['p_' . $k] = $p;
            }
        }

        $hr_page = max(1, (int) ($get['hr_page'] ?? 1));
        if ($hr_page > 1) {
            $params['hr_page'] = $hr_page;
        }
        $hr_sort = (string) ($get['hr_sort'] ?? '');
        if (isset(self::HIGHRISK_SORT[$hr_sort])) {
            $params['hr_sort'] = $hr_sort;
        }
        $hr_dir = strtolower((string) ($get['hr_dir'] ?? ''));
        if ($hr_dir === 'asc' || $hr_dir === 'desc') {
            $params['hr_dir'] = $hr_dir;
        }

        $hr_q = trim((string) ($get['hr_q'] ?? ''));
        if ($hr_q !== '') {
            $params['hr_q'] = $hr_q;
        }

        $hr_catatan = trim((string) ($get['hr_catatan'] ?? ''));
        if ($hr_catatan !== '') {
            $params['hr_catatan'] = $hr_catatan;
        }

        // The other half of the summary's group key; see highriskWhere().
        $hr_priority = trim((string) ($get['hr_priority'] ?? ''));
        if ($hr_priority !== '') {
            $params['hr_priority'] = $hr_priority;
        }

        $hr_kategori = trim((string) ($get['hr_kategori'] ?? ''));
        if ($hr_kategori !== '') {
            $params['hr_kategori'] = $hr_kategori;
        }

        // Which tab is open must survive every reload this page can cause.
        $rtab = (string) ($get['rtab'] ?? '');
        if (in_array($rtab, self::TAB_CARDS, true)) {
            $params['rtab'] = $rtab;
        }

        foreach ($overrides as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            } else {
                $params[$k] = $v;
            }
        }

        $qs = http_build_query($params);

        return $base . '/front/pbi.php?tab=report&' . $pq
            . ($qs !== '' ? '&' . $qs : '')
            . ($anchor !== '' ? '#' . $anchor : '');
    }

    /**
     * Shared Previous / Next pager for the cards on this tab. $url maps a
     * page number to the link for that page.
     */
    private static function renderPager(int $page, int $pages, int $total, callable $url): void
    {
        if ($pages <= 1) {
            return;
        }

        echo '<div class="card-body d-flex gap-2 align-items-center">';
        echo '<span class="text-muted">' . htmlescape(sprintf(
            __('Page %1$d of %2$d (%3$d rows)', 'integrasipaman'),
            $page,
            $pages,
            $total
        )) . '</span>';

        $mk = static function (int $p, string $lbl) use ($url): void {
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . htmlescape($url($p)) . '">'
                . htmlescape($lbl) . '</a>';
        };
        if ($page > 1) {
            $mk($page - 1, __('Previous', 'integrasipaman'));
        }
        if ($page < $pages) {
            $mk($page + 1, __('Next', 'integrasipaman'));
        }
        echo '</div>';
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
     * asset string => glpi_computers.id, for the asset strings the
     * correlation resolver managed to map to a live (non-deleted)
     * Computer. Everything else renders as plain text.
     *
     * @param list<string> $assets
     * @return array<string,int>
     */
    private static function assetComputerMap(array $assets): array
    {
        global $DB;

        $assets = array_values(array_unique(array_filter(
            array_map(static fn($a): string => (string) $a, $assets),
            static fn(string $a): bool => $a !== ''
        )));
        if ($assets === []) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['am.asset', 'am.computers_id'],
            'FROM'       => self::ASSETMAP_TABLE . ' AS am',
            'INNER JOIN' => ['glpi_computers AS comp' => ['ON' => ['comp' => 'id', 'am' => 'computers_id']]],
            'WHERE'      => [
                'am.asset'        => $assets,
                'am.computers_id' => ['>', 0],
                'comp.is_deleted' => 0,
            ] + PluginIntegrasipamanConnection::viewer('am'),
        ]) as $r) {
            $out[(string) $r['asset']] = (int) $r['computers_id'];
        }

        return $out;
    }

    /**
     * One asset cell: a link to the mapped Computer, or the plain string
     * when the asset was never resolved to one.
     *
     * @param array<string,int> $map
     */
    private static function assetCell(string $value, array $map): string
    {
        $text = htmlescape(mb_substr($value, 0, 120));
        $id   = (int) ($map[$value] ?? 0);
        if ($id <= 0) {
            return $text;
        }

        return '<a href="' . htmlescape(Computer::getFormURLWithID($id)) . '" title="'
            . htmlescape(sprintf(__('Open %s in GLPI', 'integrasipaman'), Computer::getTypeName(1)))
            . '">' . $text . ' <i class="ti ti-external-link" aria-hidden="true"></i></a>';
    }

    /**
     * Page one card's rows. Returns everything the table + pager need.
     *
     * @param array{columns:list<string>,rows:iterable,total:?int} $data
     * @param array<string,mixed> $get
     * @return array{rows:list<list<string>>,page:int,pages:int,total:int,assets:array<string,int>}
     */
    private static function summarySlice(string $key, array $data, array $get): array
    {
        $all   = is_array($data['rows']) ? $data['rows'] : iterator_to_array($data['rows'], false);
        $total = count($all);
        $pages = max(1, (int) ceil($total / self::SUMMARY_PAGE_SIZE));
        $page  = min(max(1, (int) ($get['p_' . $key] ?? 1)), $pages);
        $rows  = array_slice($all, ($page - 1) * self::SUMMARY_PAGE_SIZE, self::SUMMARY_PAGE_SIZE);

        // Only the asset dimension is linkable - the other cards group by
        // user / category / command text.
        $assets = $key === 'asset_summary' ? self::assetComputerMap(array_column($rows, 0)) : [];

        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total, 'assets' => $assets];
    }

    /**
     * @param array{columns:list<string>,rows:iterable,total:?int} $data
     * @param list<list<string>>  $rows
     * @param array<string,int>   $assets
     * @param array<int,string>   $links Row index (in $rows) => drill-down URL for the last column.
     */
    private static function summaryTable(array $data, array $rows, array $assets, array $links = []): void
    {
        echo '<div class="table-responsive">';
        echo '<table class="table table-striped card-table"><thead><tr>';
        foreach ($data['columns'] as $c) {
            echo '<th>' . htmlescape(__($c, 'integrasipaman')) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $ri => $row) {
            echo '<tr>';
            foreach ($row as $i => $v) {
                $v = (string) $v;
                if ($i === (count($data['columns']) - 1)) {
                    // last column of a summary is always the count. The link
                    // rides alongside the data, never inside it: this cell
                    // also feeds the CSV/Excel export, and an <a href> in a
                    // CSV cell would be a worse bug than the dead-end count.
                    $cell = htmlescape($v);
                    if (isset($links[$ri])) {
                        $cell = '<a href="' . htmlescape($links[$ri]) . '">' . $cell . '</a>';
                    }
                    echo '<td class="text-end">' . $cell . '</td>';
                } elseif ($i === 0 && $assets !== []) {
                    echo '<td>' . self::assetCell($v, $assets) . '</td>';
                } else {
                    echo '<td>' . htmlescape(mb_substr($v, 0, 120)) . '</td>';
                }
            }
            echo '</tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="' . count($data['columns']) . '" class="text-muted">'
                . htmlescape(__('No commands in this period.', 'integrasipaman')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * The tabbed card. Every report shares one card instead of stacking,
     * which is what keeps this page a screen tall instead of six.
     *
     * Every pane is rendered server-side and Bootstrap switches between them
     * with no reload; the nav links are still real URLs carrying ?rtab=, so
     * the tab survives a pager/sort click (which does reload) and the page
     * still works if the JS never runs.
     *
     * @param array<string,mixed> $get
     * @param array{start:string,end:string} $period
     */
    private static function renderTabGroup(array $get, array $period, string $base, string $pq): void
    {
        $active = (string) ($get['rtab'] ?? '');
        if (!in_array($active, self::TAB_CARDS, true)) {
            $active = self::TAB_CARDS[0];
        }
        $anchor = self::TAB_ANCHOR;

        echo '<div class="card mb-4" id="' . htmlescape($anchor) . '">';
        echo '<div class="card-header"><ul class="nav nav-tabs card-header-tabs" role="tablist">';
        foreach (self::TAB_CARDS as $key) {
            $url = self::tabUrl($get, $pq, $base, ['rtab' => $key], $anchor);
            echo '<li class="nav-item" role="presentation">';
            echo '<a class="nav-link' . ($key === $active ? ' active' : '') . '"'
                . ' href="' . htmlescape($url) . '"'
                . ' data-bs-toggle="tab" data-bs-target="#integrasipaman-pane-' . htmlescape($key) . '"'
                . ' role="tab">' . htmlescape(__(self::TAB_TITLES[$key], 'integrasipaman')) . '</a>';
            echo '</li>';
        }
        echo '</ul></div>';

        echo '<div class="tab-content">';
        foreach (self::TAB_CARDS as $key) {
            echo '<div class="tab-pane fade' . ($key === $active ? ' show active' : '')
                . '" id="integrasipaman-pane-' . htmlescape($key) . '" role="tabpanel">';

            if ($key === 'evaluasi_highrisk') {
                self::renderHighriskPane($get, $period, $base, $pq, $anchor);
            } else {
                $data = self::report($key, $period);
                $s    = self::summarySlice($key, $data, $get);

                self::paneToolbar($key, $base, $pq, $s['total']);

                $links = [];
                if ($key === 'evaluasi_summary') {
                    foreach ($s['rows'] as $ri => $sr) {
                        // An empty catatan gets NO LINK. tabUrl() assigns an
                        // override without the !== '' guard its $get-parsing
                        // branch applies, so an empty value round-trips to
                        // '', highriskWhere() skips the filter entirely, and
                        // the pane shows EVERY high-risk row in the period
                        // under a count that said N. A dead-end count is far
                        // better than a wrong one: summaryTable() simply
                        // renders the number unlinked when no link exists.
                        if (trim((string) $sr[1]) === '') {
                            continue;
                        }

                        // The summary GROUPs on ['e.priority', 'e.catatan'],
                        // so the link carries BOTH - $sr[0] is the priority,
                        // $sr[1] the catatan - and the filter is their
                        // intersection, which is exactly the group key. See
                        // highriskWhere() for why this is not the priority
                        // filter section 8 forbids.
                        // The two summary cards' drill-downs must clear EACH
                        // OTHER'S filter, not just their own: an operator who
                        // drilled into a category and then clicks a catatan
                        // row on the same page would otherwise carry
                        // hr_kategori along, ANDing it with the catatan and
                        // showing a subset of the number just clicked.
                        $links[$ri] = self::tabUrl($get, $pq, $base, [
                            'rtab'        => 'evaluasi_highrisk',
                            'hr_catatan'  => $sr[1],
                            'hr_priority' => $sr[0],
                            'hr_kategori' => null,
                            'hr_page'     => null,
                            'hr_q'        => null,
                        ], $anchor);
                    }
                }

                if ($key === 'category_summary') {
                    foreach ($s['rows'] as $ri => $sr) {
                        if (trim((string) $sr[0]) === '') {
                            continue;
                        }
                        // Symmetric to the evaluasi_summary link above:
                        // clears BOTH halves of the OTHER card's group key,
                        // or a leftover hr_priority ANDs with the category
                        // and the pane shows a subset of the number clicked.
                        $links[$ri] = self::tabUrl($get, $pq, $base, [
                            'rtab'        => 'evaluasi_highrisk',
                            'hr_kategori' => $sr[0],
                            'hr_catatan'  => null,
                            'hr_priority' => null,
                            'hr_page'     => null,
                            'hr_q'        => null,
                        ], $anchor);
                    }
                }
                self::summaryTable($data, $s['rows'], $s['assets'], $links);
                self::renderPager($s['page'], $s['pages'], $s['total'], static function (int $p) use ($get, $pq, $base, $key, $anchor): string {
                    return self::tabUrl($get, $pq, $base, ['p_' . $key => $p, 'rtab' => $key], $anchor);
                });
            }

            echo '</div>';
        }
        echo '</div></div>';
    }

    /**
     * Toolbar for the high-risk pane: row count, search box, column picker,
     * export buttons.
     *
     * @param array<string,mixed> $get
     */
    private static function renderHighriskToolbar(
        array $get,
        string $pq,
        string $base,
        string $anchor,
        string $q,
        string $catatan,
        int $total,
        string $export_pq,
        string $kategori = ''
    ): void {
        $action = $base . '/front/pbi.php';

        echo '<div class="card-body d-flex align-items-center flex-wrap gap-2 py-2">';

        // Row count first: with a search active this is the size of the
        // RESULT, not of the period, which is the number the pager agrees with.
        echo '<span class="text-muted">' . htmlescape(sprintf(
            _n('%d row', '%d rows', $total, 'integrasipaman'),
            $total
        )) . '</span>';

        // --- search ------------------------------------------------------
        // A GET form rather than a link: the browser keeps the term, and every
        // other bit of tab state has to be replayed as hidden inputs or it is
        // lost the moment someone searches.
        echo '<form method="get" action="' . htmlescape($action) . '" class="d-flex align-items-center gap-1 ms-2">';
        echo '<input type="hidden" name="tab" value="report">';
        echo '<input type="hidden" name="rtab" value="evaluasi_highrisk">';
        foreach (self::periodInputs($get) as $name => $value) {
            echo '<input type="hidden" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '">';
        }
        $sort = (string) ($get['hr_sort'] ?? '');
        $dir  = strtolower((string) ($get['hr_dir'] ?? ''));
        if (isset(self::HIGHRISK_SORT[$sort])) {
            echo '<input type="hidden" name="hr_sort" value="' . htmlescape($sort) . '">';
        }
        if ($dir === 'asc' || $dir === 'desc') {
            echo '<input type="hidden" name="hr_dir" value="' . htmlescape($dir) . '">';
        }
        // The active drill-down filters, replayed like hr_sort above. Without
        // these, an operator who drills into a category showing 40 rows and
        // then types anything into this box silently lands on the WHOLE
        // period's high-risk set, and the "filtered to" banner vanishes with
        // it - the same "number clicked != rows shown" failure the branch
        // spent two tasks closing, arriving through the search box instead of
        // the export. hr_catatan/hr_priority are a v3.7.0 defect; this branch
        // adds hr_kategori to the same broken form, so all three go together.
        foreach (['hr_catatan', 'hr_priority', 'hr_kategori'] as $filter) {
            $value = trim((string) ($get[$filter] ?? ''));
            if ($value !== '') {
                echo '<input type="hidden" name="' . htmlescape($filter) . '" value="' . htmlescape($value) . '">';
            }
        }
        // No hr_page: a new search starts at page 1, or you land on page 7 of
        // a three-page result and see nothing.
        echo '<input type="search" name="hr_q" class="form-control form-control-sm" style="width:16rem"'
            . ' value="' . htmlescape($q) . '"'
            . ' placeholder="' . htmlescape(__('Search user, asset, IP, priority', 'integrasipaman')) . '">';
        echo '<button type="submit" class="btn btn-sm btn-primary">' . htmlescape(__('Search', 'integrasipaman')) . '</button>';
        if ($q !== '') {
            echo '<a class="btn btn-sm btn-outline-secondary" href="'
                . htmlescape(self::tabUrl($get, $pq, $base, ['hr_q' => null, 'hr_page' => null, 'rtab' => 'evaluasi_highrisk'], $anchor))
                . '">' . htmlescape(__('Clear', 'integrasipaman')) . '</a>';
        }
        echo '</form>';

        echo '<span class="ms-auto d-flex align-items-center gap-2">';
        self::renderColumnPicker();
        echo self::exportButtons('evaluasi_highrisk', $base, $export_pq);
        echo '</span>';

        echo '</div>';

        if ($q !== '') {
            echo '<div class="form-hint px-3 pb-2">' . htmlescape(sprintf(
                __('Filtered by "%s" on user, asset, asset IP and priority. CSV/Excel/Print export these filtered rows - all of them, not just this page.', 'integrasipaman'),
                $q
            )) . '</div>';
        }

        // A filter a reader cannot see is a filter that will be blamed for
        // missing rows - same reasoning as the hr_q banner above, for the
        // drill-down link from the Evaluation summary.
        if ($catatan !== '') {
            echo '<div class="form-hint px-3 pb-2">' . htmlescape(sprintf(
                __('Filtered to catatan "%s", from the Evaluation summary.', 'integrasipaman'),
                $catatan
            )) . ' <a href="' . htmlescape(self::tabUrl($get, $pq, $base, [
                // Clears BOTH halves of the drill-down key - leaving the
                // priority behind would "clear" the filter into a different
                // one rather than into no filter.
                'hr_catatan' => null, 'hr_priority' => null, 'hr_page' => null,
                'rtab' => 'evaluasi_highrisk',
            ], $anchor)) . '">' . htmlescape(__('Clear', 'integrasipaman')) . '</a></div>';
        }

        // Same reasoning as the catatan banner above, for the drill-down
        // link from the High-risk by category card.
        if ($kategori !== '') {
            echo '<div class="form-hint px-3 pb-2">' . htmlescape(sprintf(
                __('Filtered to category "%s", from the High-risk by category summary.', 'integrasipaman'),
                $kategori
            )) . ' <a href="' . htmlescape(self::tabUrl($get, $pq, $base, [
                'hr_kategori' => null, 'hr_page' => null,
                'rtab' => 'evaluasi_highrisk',
            ], $anchor)) . '">' . htmlescape(__('Clear', 'integrasipaman')) . '</a></div>';
        }
    }

    /**
     * The period as hidden form inputs, so searching does not silently move
     * the report to a different month.
     *
     * @param array<string,mixed> $get
     * @return array<string,string>
     */
    private static function periodInputs(array $get): array
    {
        $from = self::validDate((string) ($get['from'] ?? ''));
        $to   = self::validDate((string) ($get['to'] ?? ''));
        if ($from !== null && $to !== null && $from <= $to) {
            return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
        }

        $month = (string) ($get['month'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            return ['month' => $month];
        }

        return ['month' => substr(self::resolvePeriod($get)['start'], 0, 7)];
    }

    /**
     * Column show/hide, in the shape GLPI users expect: a button, a list of
     * checkboxes, applied immediately.
     *
     * Hiding is done in the browser and remembered in localStorage, NOT in the
     * URL and not on the server. Three consequences worth knowing:
     *
     *   - it survives paging, sorting and searching for free, because it is
     *     re-applied on every render rather than carried through each link;
     *   - it is per browser, where GLPI's own display preferences are per
     *     account. The storage is isolated in one place here so it can be
     *     moved server-side later;
     *   - exports are untouched by it. A filter selects rows and belongs in
     *     the file; hiding a column is a screen convenience, and a CSV that
     *     silently lost columns is worse than a wide one.
     *
     * Without JavaScript every column simply shows, which is the safe way to
     * fail for an audit table.
     */
    private static function renderColumnPicker(): void
    {
        echo '<div class="dropdown">';
        echo '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"'
            . ' data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">';
        echo '<i class="ti ti-columns-3" aria-hidden="true"></i> ' . htmlescape(__('Columns', 'integrasipaman'));
        echo '</button>';
        echo '<div class="dropdown-menu dropdown-menu-end p-2" style="max-height:20rem;overflow:auto">';

        foreach (self::HIGHRISK_COLUMNS as $i => $col) {
            $id = 'integrasipaman-col-' . $i;
            echo '<label class="dropdown-item d-flex align-items-center gap-2 mb-0" for="' . htmlescape($id) . '">';
            echo '<input type="checkbox" class="form-check-input m-0 integrasipaman-col-toggle"'
                . ' id="' . htmlescape($id) . '" data-col="' . (int) $i . '" checked>';
            echo '<span>' . htmlescape(__($col, 'integrasipaman')) . '</span>';
            echo '</label>';
        }

        echo '<div class="dropdown-divider"></div>';
        echo '<button type="button" class="dropdown-item text-center" id="integrasipaman-col-reset">'
            . htmlescape(__('Show all', 'integrasipaman')) . '</button>';
        echo '</div></div>';
    }

    /** The whole of the column picker's behaviour, in one place. */
    private static function renderColumnPickerScript(): void
    {
        $key = 'integrasipaman.highrisk.hiddenCols';
        echo <<<HTML
<script>
(function () {
    var KEY = '{$key}';
    var table = document.getElementById('integrasipaman-highrisk-table');
    if (!table) { return; }

    function load() {
        // Storage can throw (private mode, blocked site data) and can hold
        // anything. Either way the table must render with all columns.
        try {
            var raw = window.localStorage.getItem(KEY);
            var val = raw ? JSON.parse(raw) : [];
            return Array.isArray(val) ? val.map(Number).filter(function (n) { return !isNaN(n); }) : [];
        } catch (e) { return []; }
    }

    function save(hidden) {
        try { window.localStorage.setItem(KEY, JSON.stringify(hidden)); } catch (e) { /* not fatal */ }
    }

    function apply(hidden) {
        var rows = table.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
            var cells = rows[r].children;
            for (var c = 0; c < cells.length; c++) {
                // An empty-result row spans every column - never hide it, or
                // "no rows" becomes a blank table.
                if (cells[c].hasAttribute('colspan')) { continue; }
                cells[c].classList.toggle('d-none', hidden.indexOf(c) !== -1);
            }
        }
        var boxes = document.querySelectorAll('.integrasipaman-col-toggle');
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].checked = hidden.indexOf(Number(boxes[i].dataset.col)) === -1;
        }
    }

    var hidden = load();
    apply(hidden);

    document.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('integrasipaman-col-toggle')) { return; }
        var col = Number(e.target.dataset.col);
        var at  = hidden.indexOf(col);
        if (e.target.checked) { if (at !== -1) { hidden.splice(at, 1); } }
        else if (at === -1)   { hidden.push(col); }
        save(hidden);
        apply(hidden);
    });

    var reset = document.getElementById('integrasipaman-col-reset');
    if (reset) {
        reset.addEventListener('click', function () { hidden = []; save(hidden); apply(hidden); });
    }
})();
</script>
HTML;
    }

    /** Row count + export buttons, standing in for the card header a pane does not get. */
    private static function paneToolbar(string $key, string $base, string $pq, int $total): void
    {
        echo '<div class="card-body d-flex align-items-center py-2">';
        echo '<span class="text-muted">' . htmlescape(sprintf(
            _n('%d row', '%d rows', $total, 'integrasipaman'),
            $total
        )) . '</span>';
        echo '<span class="ms-auto">' . self::exportButtons($key, $base, $pq) . '</span>';
        echo '</div>';
    }

    /**
     * EVALUASI_HIGHRISK as a tab pane: sortable headers, its own pager, and
     * asset cells linked to their GLPI Computer.
     *
     * @param array<string,mixed> $get
     * @param array{start:string,end:string} $period
     */
    private static function renderHighriskPane(array $get, array $period, string $base, string $pq, string $anchor): void
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
        $q        = trim((string) ($get['hr_q'] ?? ''));
        $catatan  = trim((string) ($get['hr_catatan'] ?? ''));
        $priority = trim((string) ($get['hr_priority'] ?? ''));
        $kategori = trim((string) ($get['hr_kategori'] ?? ''));
        $total    = self::highriskTotal($period, $q, $catatan, $priority, $kategori);
        $pages    = max(1, (int) ceil($total / self::HIGHRISK_PAGE_SIZE));
        $page     = min($page, $pages);

        $data  = self::report('evaluasi_highrisk', $period, [
            'paginate' => true, 'page' => $page, 'sort' => $sort, 'dir' => $dir,
            'total'    => $total, 'q' => $q, 'catatan' => $catatan,
            'priority' => $priority, 'kategori' => $kategori,
        ]);

        $assetCol = (int) array_search('Asset', self::HIGHRISK_COLUMNS, true);
        $assets   = self::assetComputerMap(array_column($data['rows'], $assetCol));

        // The search term rides along to the export controller: filtering
        // selects ROWS, and someone who filters then clicks Excel means the
        // rows they filtered to. Hidden columns do not - see renderColumnPicker.
        // hr_catatan is the same kind of filter - it selects rows, not a
        // screen convenience - so it rides along too, under the same
        // "catatan" name reportexport.php reads (hr_q -> q is the existing
        // precedent). Leaving it out would let an operator drill into
        // "Aset Belum Diklasifikasi", see 70 rows, click Excel, and receive
        // every high-risk row in the period instead - the two catatan
        // populations this whole feature exists to keep apart, back together
        // in a file with a name that promises otherwise.
        // hr_priority rides along for the same reason, and must: it is the
        // other half of the summary's group key, so an export that carried
        // only the catatan could return a WIDER set than the screen showed.
        // hr_kategori rides along too, under the same "kategori" name
        // reportexport.php reads - without it, clicking a category then
        // Excel hands back a wider set than the screen, the same defect
        // v3.7.0 fixed for the catatan recurring in a second code path.
        $export_pq = $pq . '&sort=' . $sort . '&dir=' . $dir
            . ($q !== '' ? '&q=' . rawurlencode($q) : '')
            . ($catatan !== '' ? '&catatan=' . rawurlencode($catatan) : '')
            . ($priority !== '' ? '&priority=' . rawurlencode($priority) : '')
            . ($kategori !== '' ? '&kategori=' . rawurlencode($kategori) : '');

        self::renderHighriskToolbar($get, $pq, $base, $anchor, $q, $catatan, $total, $export_pq, $kategori);

        echo '<div class="table-responsive">';
        echo '<table class="table table-striped card-table" id="integrasipaman-highrisk-table"><thead><tr>';
        foreach ($data['columns'] as $c) {
            $key = strtolower($c);
            if (isset(self::HIGHRISK_SORT[$key])) {
                $nextDir = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
                $arrow   = $sort === $key ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
                $url     = self::tabUrl($get, $pq, $base, [
                    'hr_sort' => $key, 'hr_dir' => $nextDir, 'hr_page' => null,
                    'rtab'    => 'evaluasi_highrisk',
                ], $anchor);
                echo '<th><a href="' . htmlescape($url) . '">'
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
                if ($i === $assetCol) {
                    echo '<td>' . self::assetCell($v, $assets) . '</td>';
                    continue;
                }
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

        self::renderPager($page, $pages, $total, static function (int $p) use ($get, $pq, $base, $sort, $dir, $anchor): string {
            return self::tabUrl($get, $pq, $base, [
                'hr_sort' => $sort, 'hr_dir' => $dir, 'hr_page' => $p,
                'rtab'    => 'evaluasi_highrisk',
            ], $anchor);
        });

        // AFTER the table, never with the picker markup. Inline script runs as
        // the parser reaches it, so emitting it up in the toolbar meant
        // getElementById() returned null and the whole listener registration
        // was skipped - the checkboxes still ticked, because that is the
        // browser's own behaviour, so it looked wired up and did nothing.
        self::renderColumnPickerScript();
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
