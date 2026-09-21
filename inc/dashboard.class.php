<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Registers native GLPI 11 dashboard cards backed by the plugin's own log
 * table. GLPI 11's dashboard card system has no "raw HTML override" for a
 * card's content - every widgettype (bigNumber, pie, bar, ...) renders a
 * fixed template from a specific, documented set of param keys only (see
 * Glpi\Dashboard\Widget in GLPI core) - so this class registers one small,
 * correctly-typed card per metric rather than one all-in-one custom-HTML
 * card. Filtering/date-range selection is intentionally NOT exposed here:
 * GLPI's native "Edit this card" dialog only supports background
 * color/data-source/widget-variant, not arbitrary plugin-defined fields.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanDashboard extends CommonGLPI
{
    /**
     * Source table for every card and for front/export.php.
     */
    private const TABLE = 'glpi_plugin_jumpserver_logs';

    /**
     * Card identifiers registered with GLPI's dashboard system.
     */
    private const CARD_TOTAL_ID           = 'jumpserver_total_events';
    private const CARD_BY_TYPE_PIE_ID     = 'jumpserver_events_by_type';
    private const CARD_BY_TYPE_BAR_ID     = 'jumpserver_events_by_type_bar';
    private const CARD_LOGIN_FAILURES_ID  = 'jumpserver_login_failures';
    private const CARD_SESSION_SHARING_ID = 'jumpserver_session_sharing';

    /**
     * Fixed lookback window for both cards (see class docblock: GLPI's
     * native card editor has no field for this to be end-user configurable).
     */
    private const CARD_LOOKBACK_DAYS = 30;

    /**
     * Slice cap for the "events by type" pie card, to keep it readable.
     */
    private const CHART_GROUP_LIMIT = 8;

    /**
     * Safety cap on rows returned for export (front/export.php), so a very
     * wide filter/date range can never pull an unbounded result set into
     * memory in one request.
     */
    public const EXPORT_ROW_LIMIT = 5000;

    /**
     * Menu entry content for $PLUGIN_HOOKS['menu_toadd']['tools'] (see
     * setup.php's plugin_init_integrasipaman()). Overrides CommonGLPI's
     * default implementation directly rather than calling
     * parent::getMenuContent(): the default assumes a CommonDBTM
     * search-page itemtype (canView(), getSearchURL(), ...), none of
     * which apply to this class - it is not a searchable item type, just
     * a place to hang dashboard/menu hooks.
     *
     * Gated on the same plugin right (READ) front/pbi.php itself
     * requires: the linked page surfaces PAM audit detail from a
     * separate database, so the menu entry is hidden entirely for users
     * who would only get denied - GLPI silently omits a menu category
     * whose 'types' array yields no content, so returning [] here is
     * enough to hide it.
     *
     * @return array<string, string>
     */
    public static function getMenuContent(): array
    {
        if (!Session::haveRight(PluginIntegrasipamanProfile::RIGHT, READ)) {
            return [];
        }

        return [
            'title' => __('JumpServer Integration', 'integrasipaman'),
            'page'  => Plugin::getWebDir('integrasipaman') . '/front/pbi.php',
            'icon'  => 'ti ti-terminal-2',
        ];
    }

    /**
     * Whether the current user may see any JumpServer figure at all.
     *
     * Dashboard cards are rendered by core with no right check of their
     * own, so before v2.0.0 every card provider handed PAM event counts to
     * anyone allowed to edit a GLPI dashboard. Each provider now returns an
     * empty result instead of querying, and getCardTypes() hides the cards
     * from the "Add widget" panel entirely.
     */
    private static function canViewCards(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT, READ);
    }

    /**
     * Dashboard hook target, registered as $PLUGIN_HOOKS['dashboard_cards']
     * in setup.php. GLPI calls this with the cards already registered by
     * core/other plugins so contributions can be appended without
     * clobbering them.
     *
     * @param array<string, mixed> $cards Cards registered so far.
     *
     * @return array<string, mixed> Cards including this plugin's additions.
     */
    public static function getCardTypes(array $cards = []): array
    {
        if (!self::canViewCards()) {
            return $cards;
        }

        $group = __('JumpServer Integration', 'integrasipaman');

        // Filter IDs this table supports (native GLPI dashboard "Add
        // filter" dropdown). Deliberately NOT using
        // Glpi\Dashboard\Filter::getAppliableFilters() here: it loops
        // through every registered filter class, and
        // AbstractGroupFilter::canBeApplied() crashes
        // (`Toolbox::hasTrait(getItemtypeForTable($table), ...)` with
        // $table=null) for any table that isn't backed by a real GLPI
        // itemtype - which a plugin-owned raw log table never is. Only
        // DatesFilter is checked directly instead, since that is the only
        // filter this table can ever support anyway (see the
        // date_creation naming note in hook.php's CREATE TABLE) and its
        // own canBeApplied() only does a safe fieldExists() check.
        $filters = \Glpi\Dashboard\Filters\DatesFilter::canBeApplied(self::TABLE)
            ? [\Glpi\Dashboard\Filters\DatesFilter::getId()]
            : [];

        $cards[self::CARD_TOTAL_ID] = [
            'widgettype' => ['bigNumber'],
            'label'      => __('JumpServer Total Events', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            // Must be a "Class::method" string, not a Closure: GLPI core
            // does `explode('::', $card['provider'])` on this value (see
            // Glpi\Dashboard\Grid::getCardHtml()), which throws "Object of
            // class Closure could not be converted to string" if given a
            // callable object instead.
            'provider'   => self::class . '::provideTotalEvents',
        ];

        $cards[self::CARD_BY_TYPE_PIE_ID] = [
            'widgettype' => ['pie'],
            'label'      => __('JumpServer Events by Type - Pie', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            'provider'   => self::class . '::provideEventsByTypePie',
        ];

        $cards[self::CARD_BY_TYPE_BAR_ID] = [
            'widgettype' => ['bar'],
            'label'      => __('JumpServer Events by Type - Bar', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            'provider'   => self::class . '::provideEventsByTypeBar',
        ];

        $cards[self::CARD_LOGIN_FAILURES_ID] = [
            'widgettype' => ['bigNumber'],
            'label'      => __('JumpServer Login Failures', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            'provider'   => self::class . '::provideLoginFailures',
        ];

        $cards[self::CARD_SESSION_SHARING_ID] = [
            'widgettype' => ['bigNumber'],
            'label'      => __('JumpServer Session Sharing', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            'provider'   => self::class . '::provideSessionSharing',
        ];

        return $cards;
    }

    /**
     * Render a small KPI strip (Total Events / Login Failures / Session
     * Sharing, last 30 days) for the top of front/pbi.php.
     *
     * These reuse the card providers below for their numbers but render
     * plain Tabler-card tiles rather than going through
     * Glpi\Dashboard\Widget / Grid::getCardHtml(): GLPI 11 only loads the
     * dashboard CSS bundle + its echarts/Dashboard.js runtime on pages
     * that actually host a dashboard grid, so a widget rendered standalone
     * on an ordinary Html::header() page comes out unstyled (and any chart
     * widget renders blank). A self-contained tile has no such dependency.
     * Charts are intentionally not reproduced here - see the design doc.
     */
    public static function renderOverview(): void
    {
        $tiles = [
            self::provideTotalEvents(),
            self::provideLoginFailures(),
            self::provideSessionSharing(),
        ];

        echo '<div class="integrasipaman-kpi-row">';

        foreach ($tiles as $tile) {
            $number = (string) (int) ($tile['number'] ?? 0);
            $label  = (string) ($tile['label'] ?? '');
            $icon   = (string) ($tile['icon'] ?? 'ti ti-chart-bar');
            $accent = isset($tile['color']) && is_string($tile['color'])
                ? ' style="--kpi-accent:' . htmlescape($tile['color']) . '"'
                : '';

            echo '<div class="card integrasipaman-kpi"' . $accent . '>';
            echo '<i class="' . htmlescape($icon) . ' integrasipaman-kpi-icon" aria-hidden="true"></i>';
            echo '<span class="integrasipaman-kpi-number">' . htmlescape($number) . '</span>';
            echo '<span class="integrasipaman-kpi-label">' . htmlescape($label) . '</span>';
            echo '</div>';
        }

        echo '</div>';

        echo '<p class="text-muted mb-4">' . htmlescape(__(
            'Totals from this plugin\'s own JumpServer event log (last 30 days). The detail tables below are read live from the PBI reporting database and may differ in coverage.',
            'integrasipaman'
        )) . '</p>';

        echo <<<'CSS'
<style>
.integrasipaman-kpi-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}
.integrasipaman-kpi {
    flex: 1 1 180px;
    padding: 1rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: .25rem;
    border-inline-start: 3px solid var(--kpi-accent, var(--tblr-primary, #066fd1));
}
.integrasipaman-kpi-icon {
    font-size: 1.25rem;
    opacity: .6;
}
.integrasipaman-kpi-number {
    font-size: 2.25rem;
    font-weight: 600;
    line-height: 1.15;
}
.integrasipaman-kpi-label {
    font-size: .8125rem;
    opacity: .7;
}
</style>
CSS;
    }

    /**
     * Provider for the "Total Events" bigNumber card. Called by GLPI core
     * as "PluginIntegrasipamanDashboard::provideTotalEvents", with a single
     * positional array argument - must return exactly the keys
     * Glpi\Dashboard\Widget::bigNumber() reads ('number', 'label', ...);
     * any other key (e.g. a raw 'html' blob) is silently ignored.
     *
     * @param array<string, mixed> $params Includes 'apply_filters' when the
     *                                     user has an active dashboard-level
     *                                     filter (e.g. a date range).
     *
     * @return array<string, mixed>
     */
    public static function provideTotalEvents(array $params = []): array
    {
        if (!self::canViewCards()) {
            return ['number' => 0, 'label' => __('JumpServer Total Events', 'integrasipaman'), 'icon' => 'ti ti-terminal-2'];
        }

        return [
            'number' => self::countTotal(self::resolveWhereClause($params)),
            'label'  => __('JumpServer Total Events', 'integrasipaman'),
            'icon'   => 'ti ti-terminal-2',
        ];
    }

    /**
     * Provider for the "Events by Type" pie card. Called by GLPI core as
     * "PluginIntegrasipamanDashboard::provideEventsByTypePie" - must return
     * exactly the keys Glpi\Dashboard\Widget::pie() reads ('data' as a
     * list of {number, label} entries, 'label', ...).
     *
     * @return array<string, mixed>
     */
    public static function provideEventsByTypePie(array $params = []): array
    {
        if (!self::canViewCards()) {
            return ['data' => [], 'label' => __('JumpServer Events by Type - Pie', 'integrasipaman'), 'legend' => true, 'labels' => true, 'donut' => true];
        }

        return [
            'data'   => self::eventsByTypeData($params),
            'label'  => __('JumpServer Events by Type - Pie', 'integrasipaman'),
            'legend' => true,
            'labels' => true,
            'donut'  => true,
        ];
    }

    /**
     * Provider for the "Events by Type" bar card. Called by GLPI core as
     * "PluginIntegrasipamanDashboard::provideEventsByTypeBar" - must
     * return exactly the keys Glpi\Dashboard\Widget::simpleBar() reads
     * (same 'data' shape as pie(): a list of {number, label} entries).
     *
     * @return array<string, mixed>
     */
    public static function provideEventsByTypeBar(array $params = []): array
    {
        if (!self::canViewCards()) {
            return ['data' => [], 'label' => __('JumpServer Events by Type - Bar', 'integrasipaman')];
        }

        return [
            'data'  => self::eventsByTypeData($params),
            'label' => __('JumpServer Events by Type - Bar', 'integrasipaman'),
        ];
    }

    /**
     * Provider for the "Login Failures" bigNumber card. Called by GLPI
     * core as "PluginIntegrasipamanDashboard::provideLoginFailures".
     * Counts rows with event_type='user_login_failed', populated by
     * inc/cron.class.php's pull of /api/v1/audits/login-logs/
     * (server-side filtered to status=0/Failed).
     *
     * @param array<string, mixed> $params Includes 'apply_filters' when the
     *                                     user has an active dashboard-level
     *                                     filter (e.g. a date range).
     *
     * @return array<string, mixed>
     */
    public static function provideLoginFailures(array $params = []): array
    {
        if (!self::canViewCards()) {
            return ['number' => 0, 'label' => __('JumpServer Login Failures', 'integrasipaman'), 'icon' => 'ti ti-lock-exclamation'];
        }

        $where = self::withEventTypeFilter($params, 'user_login_failed');

        return [
            'number' => self::countTotal($where),
            'label'  => __('JumpServer Login Failures', 'integrasipaman'),
            'icon'   => 'ti ti-lock-exclamation',
            'color'  => '#d63939',
        ];
    }

    /**
     * Provider for the "Session Sharing" bigNumber card. Called by GLPI
     * core as "PluginIntegrasipamanDashboard::provideSessionSharing".
     * Counts rows with event_type='session_share', populated by
     * inc/cron.class.php's pull of /api/v1/terminal/session-sharings/.
     *
     * @param array<string, mixed> $params Includes 'apply_filters' when the
     *                                     user has an active dashboard-level
     *                                     filter (e.g. a date range).
     *
     * @return array<string, mixed>
     */
    public static function provideSessionSharing(array $params = []): array
    {
        if (!self::canViewCards()) {
            return ['number' => 0, 'label' => __('JumpServer Session Sharing', 'integrasipaman'), 'icon' => 'ti ti-users'];
        }

        $where = self::withEventTypeFilter($params, 'session_share');

        return [
            'number' => self::countTotal($where),
            'label'  => __('JumpServer Session Sharing', 'integrasipaman'),
            'icon'   => 'ti ti-users',
        ];
    }

    /**
     * resolveWhereClause() plus an additional event_type restriction, for
     * cards scoped to one specific event type rather than all events.
     *
     * @param array<string, mixed> $params     Card params, passed through to resolveWhereClause().
     * @param string                $event_type Exact event_type value to restrict to.
     *
     * @return array<int|string, mixed>
     */
    private static function withEventTypeFilter(array $params, string $event_type): array
    {
        $where               = self::resolveWhereClause($params);
        $where['event_type'] = $event_type;

        return $where;
    }

    /**
     * Shared {number, label} data list for both the pie and bar cards.
     *
     * @param array<string, mixed> $params Passed through to resolveWhereClause().
     *
     * @return array<int, array{number: int, label: string}>
     */
    private static function eventsByTypeData(array $params): array
    {
        $grouped = self::countGroupedByEventType(self::resolveWhereClause($params));

        return array_map(
            static fn(array $row): array => [
                'number' => $row['count'],
                'label'  => $row['label'],
            ],
            $grouped
        );
    }

    /**
     * WHERE clause for all three cards: if the user has an active "dates"
     * dashboard filter, use it; otherwise fall back to a fixed lookback
     * window, since GLPI's native "Edit this card" dialog has no field of
     * its own to configure that (see class docblock).
     *
     * Deliberately calls Glpi\Dashboard\Filters\DatesFilter::getCriteria()
     * directly rather than the generic
     * Glpi\Dashboard\Provider::getFiltersCriteria() helper core cards use:
     * that helper evaluates canBeApplied() for every registered filter
     * class first, and AbstractGroupFilter::canBeApplied() crashes for any
     * table not backed by a real GLPI itemtype (same issue as
     * getCardTypes() above - see that comment for detail).
     *
     * @param array<string, mixed> $params Card params, including 'apply_filters'.
     *
     * @return array<int|string, mixed>
     */
    private static function resolveWhereClause(array $params): array
    {
        $apply_filters = is_array($params['apply_filters'] ?? null) ? $params['apply_filters'] : [];
        $dates_id      = \Glpi\Dashboard\Filters\DatesFilter::getId();

        if (array_key_exists($dates_id, $apply_filters)) {
            $criteria = \Glpi\Dashboard\Filters\DatesFilter::getCriteria(self::TABLE, $apply_filters[$dates_id]);

            if (!empty($criteria['WHERE'])) {
                return $criteria['WHERE'];
            }
        }

        return self::lookbackWhereClause();
    }

    /**
     * Public entry point used by front/export.php to fetch rows matching
     * the requested filter/date-range query parameters (export.php is a
     * plain GLPI page, not a dashboard card, so it is free to expose real
     * filtering unlike the cards above).
     *
     * @param array<string, mixed> $options filter_event, date_preset, date_from, date_to.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getFilteredRows(array $options): array
    {
        $filter_event = self::pickOption($options, 'filter_event', 'all');
        $date_preset  = self::pickOption($options, 'date_preset', 'today');
        $date_from    = self::pickOption($options, 'date_from', '');
        $date_to      = self::pickOption($options, 'date_to', '');

        $where = self::buildWhereClause($filter_event, $date_preset, $date_from, $date_to);

        return self::fetchRows($where, self::EXPORT_ROW_LIMIT);
    }

    /**
     * WHERE clause shared by both cards: all events, fixed lookback window.
     *
     * @return array<int|string, mixed>
     */
    private static function lookbackWhereClause(): array
    {
        return self::buildWhereClause('all', 'last_' . self::CARD_LOOKBACK_DAYS . '_days', '', '');
    }

    /**
     * Read one option value, falling back to a default when missing/blank.
     *
     * @param array<string, mixed> $options Raw options array from GLPI.
     * @param string                $key     Option key to read.
     * @param string                $default Fallback value.
     *
     * @return string
     */
    private static function pickOption(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? $default;
        $value = is_string($value) ? trim($value) : $default;

        return $value === '' ? $default : $value;
    }

    /**
     * Build a safe, array-based WHERE clause for $DB->request() from the
     * selected event filter and date range.
     *
     * @return array<int|string, mixed>
     */
    private static function buildWhereClause(string $filter_event, string $date_preset, string $date_from, string $date_to): array
    {
        $where = [];

        if ($filter_event !== 'all') {
            $where['event_type'] = $filter_event;
        }

        [$range_begin, $range_end] = self::resolveDateRange($date_preset, $date_from, $date_to);

        if ($range_begin !== null) {
            $where[] = ['date_creation' => ['>=', $range_begin]];
        }

        if ($range_end !== null) {
            $where[] = ['date_creation' => ['<=', $range_end]];
        }

        return $where;
    }

    /**
     * Resolve a date_preset (plus, for "custom", the raw date_from/date_to
     * strings) into a [begin, end] pair of full datetime boundaries.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function resolveDateRange(string $date_preset, string $date_from, string $date_to): array
    {
        switch ($date_preset) {
            case 'today':
                return [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];

            case 'last_7_days':
                return [date('Y-m-d 00:00:00', strtotime('-7 days')), date('Y-m-d 23:59:59')];

            case 'last_30_days':
                return [date('Y-m-d 00:00:00', strtotime('-30 days')), date('Y-m-d 23:59:59')];

            case 'custom':
                $begin = self::sanitizeDate($date_from);
                $end   = self::sanitizeDate($date_to);

                return [
                    $begin !== null ? $begin . ' 00:00:00' : null,
                    $end !== null ? $end . ' 23:59:59' : null,
                ];
        }

        // Unrecognized preset: no date restriction.
        return [null, null];
    }

    /**
     * Validate a Y-m-d date string before it is bound into a DB request
     * criterion.
     *
     * @return string|null The validated date, or null if invalid/empty.
     */
    private static function sanitizeDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    /**
     * Total matching row count, for the "Total Events" bigNumber card.
     */
    private static function countTotal(array $where): int
    {
        global $DB;

        $iterator = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => self::TABLE,
            'WHERE' => $where,
        ]);

        $row = $iterator->current();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Matching rows grouped by event_type, for the "Events by Type" pie
     * card.
     *
     * @return array<int, array{label: string, count: int}>
     */
    private static function countGroupedByEventType(array $where): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'  => ['event_type', new QueryExpression('COUNT(*) AS ' . $DB->quoteName('cnt'))],
            'FROM'    => self::TABLE,
            'WHERE'   => $where,
            'GROUPBY' => 'event_type',
            'ORDER'   => 'cnt DESC',
            'LIMIT'   => self::CHART_GROUP_LIMIT,
        ]);

        $data = [];

        foreach ($iterator as $row) {
            $data[] = [
                'label' => (string) $row['event_type'],
                'count' => (int) $row['cnt'],
            ];
        }

        return $data;
    }

    /**
     * Rows matching the given WHERE clause, used by front/export.php.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fetchRows(array $where, int $limit): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['date_creation', 'event_type', 'username', 'asset_name', 'ip_address', 'status', 'message'],
            'FROM'   => self::TABLE,
            'WHERE'  => $where,
            'ORDER'  => 'date_creation DESC',
            'LIMIT'  => $limit,
        ]);

        $rows = [];

        foreach ($iterator as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}
