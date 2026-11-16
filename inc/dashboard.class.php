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

require_once __DIR__ . '/connection.class.php';

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
    private const CARD_RECENT_ID          = 'jumpserver_recent_events';
    private const CARD_LOCKOUTS_ID        = 'jumpserver_login_lockouts';

    /** Rows listed by the "Recent Events" card. */
    private const RECENT_LIMIT = 10;
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
     * The operate-log `resource_type` values that really are an asset
     * (v4.5.2). Everything else JumpServer can act on - a user, a login
     * session, a permission rule, a node, an access key - is not, however
     * much its display string may look like one.
     *
     * This list is deliberately an explicit allow-list of types rather than
     * a pattern over the display string. The bug this constant exists to fix
     * was caused by trusting the string: a UserSession renders as
     * "Name(login)(10.0.242.126)", which is indistinguishable by shape from
     * an asset's "VM-APP-07(10.0.0.160)".
     *
     * Two near misses are left out on purpose, both verified against live
     * data (probe_resource_shape.php, 2026-10-22):
     *   Asset Authorization  an authorization RULE, often named after the
     *                        server it covers ("SVR-Database One").
     *   Account              an account ON an asset ("developers(VM-DEV2-
     *                        SAMPLE)"). The asset is in the parens, but
     *                        digging it out is the same string-guessing.
     *
     * Matched against the label JumpServer sends. If a future version or a
     * non-English locale renames these, rows stop being recognised as assets
     * and fall back to "not an asset" - under-claiming, which is the safe
     * direction for an audit screen.
     */
    private const ASSET_RESOURCE_TYPES = ['Asset', 'Host'];

    /**
     * Safety cap on rows returned for export (front/export.php), so a very
     * wide filter/date range can never pull an unbounded result set into
     * memory in one request.
     */
    public const EXPORT_ROW_LIMIT = 5000;

    // The sidebar menu used to be a single Tools entry served by a
    // getMenuContent() here. It is now one entry in the shared suite menu,
    // with the plugin's pages as its options - see inc/menu.class.php - so this class is back to
    // being only what its name says: dashboard cards plus the shared
    // query/filter helpers front/export.php reuses.

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
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);
    }

    /**
     * Dashboard hook target, registered as $PLUGIN_HOOKS['dashboard_cards']
     * in setup.php. GLPI calls this with the cards already registered by
     * core/other plugins so contributions can be appended without
     * clobbering them.
     *
     * @param array<string, mixed>|null $cards Cards registered so far. GLPI's
     *        Plugin::doHookFunction() can call a hook with no argument, so
     *        this arrives as null: a strict `array` type fataled the whole
     *        dashboard page (fixed in 4.15.4).
     *
     * @return array<string, mixed> Cards including this plugin's additions.
     */
    public static function getCardTypes(?array $cards = []): array
    {
        $cards ??= [];

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
            'label'      => __('JumpServer All Events', 'integrasipaman'),
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

        // 4.3.0: logins refused because of too many / too frequent attempts.
        $cards[self::CARD_LOCKOUTS_ID] = [
            'widgettype' => ['bigNumber'],
            'label'      => __('JumpServer Login Lockouts', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            'provider'   => self::class . '::provideLoginLockouts',
        ];

        $cards[self::CARD_SESSION_SHARING_ID] = [
            'widgettype' => ['bigNumber'],
            'label'      => __('JumpServer Session Sharing', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            'provider'   => self::class . '::provideSessionSharing',
        ];

        // 4.1.0: the table-like card of the design. Core GLPI has no generic
        // table widget; multipleNumber lists rows and escapes every label, which
        // matters here because user names and asset names come from JumpServer.
        $cards[self::CARD_RECENT_ID] = [
            'widgettype' => ['multipleNumber'],
            'label'      => __('JumpServer Recent Events', 'integrasipaman'),
            'group'      => $group,
            'filters'    => $filters,
            'provider'   => self::class . '::provideRecentEvents',
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
            self::provideLoginLockouts(),
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

            // A tile with a page behind it is a link to that page.
            $url = (string) ($tile['url'] ?? '');
            echo $url !== ''
                ? '<a class="card integrasipaman-kpi text-reset text-decoration-none" href="' . htmlescape($url) . '"' . $accent . '>'
                : '<div class="card integrasipaman-kpi"' . $accent . '>';
            echo '<i class="' . htmlescape($icon) . ' integrasipaman-kpi-icon" aria-hidden="true"></i>';
            echo '<span class="integrasipaman-kpi-number">' . htmlescape($number) . '</span>';
            echo '<span class="integrasipaman-kpi-label">' . htmlescape($label) . '</span>';
            echo $url !== '' ? '</a>' : '</div>';
        }

        echo '</div>';

        // v4.6.0: say outright that these four tiles overlap. "All Events" is
        // a bare COUNT(*) over the log with no event_type filter, so it
        // already CONTAINS the login failures and the session sharing shown
        // beside it - on this instance, 1410 rows of which 32 and 85 are the
        // next two tiles. Read as a breakdown, the strip would not add up,
        // and a reader would reasonably conclude a number was wrong.
        echo '<p class="text-muted mb-4">' . htmlescape(__(
            'Totals from this plugin\'s own JumpServer event log (last 30 days). "All Events" counts every event in the window, so the other tiles are subsets of it, not separate shares of it. The detail tables below are read live from the PBI reporting database and may differ in coverage.',
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
            return ['number' => 0, 'label' => __('JumpServer All Events', 'integrasipaman'), 'icon' => 'ti ti-terminal-2'];
        }

        $number = self::countTotal(self::resolveWhereClause($params));

        return [
            'number' => $number,
            'icon'   => 'ti ti-terminal-2',
        ] + self::labelWithDelta(__('JumpServer All Events', 'integrasipaman'), $params, null, $number);
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

        $number = self::countTotal($where);

        return [
            'number' => $number,
            'icon'   => 'ti ti-lock-exclamation',
            'color'  => '#d63939',
            'url'    => self::pageUrl('loginfailures.php', self::windowQuery($params)),
        ] + self::labelWithDelta(__('JumpServer Login Failures', 'integrasipaman'), $params, 'user_login_failed', $number);
    }

    /**
     * Provider for the "Login Lockouts" bigNumber card: failed logins whose
     * reason says too many or too frequent attempts (see
     * PluginIntegrasipamanLoginFailures::LOCKOUT_WORDS). Clicking it opens the
     * Login failures page on the lockouts of the same period.
     *
     * @return array<string, mixed>
     */
    public static function provideLoginLockouts(array $params = []): array
    {
        $label = __('JumpServer Login Lockouts', 'integrasipaman');
        if (!self::canViewCards()) {
            return ['number' => 0, 'label' => $label, 'icon' => 'ti ti-lock'];
        }

        require_once __DIR__ . '/loginfailures.class.php';

        return [
            'number' => PluginIntegrasipamanLoginFailures::countLockouts(
                self::fetchRows(self::withEventTypeFilter($params, 'user_login_failed'), PluginIntegrasipamanLoginFailures::ROW_LIMIT)
            ),
            'label'  => $label,
            'icon'   => 'ti ti-lock',
            'color'  => '#d63939',
            'url'    => self::pageUrl('loginfailures.php', self::windowQuery($params) + ['only' => 'locked']),
        ];
    }

    /**
     * Address of one of the plugin's pages, for a card to link to. Root-relative
     * when core's Plugin class is not there to ask.
     *
     * @param array<string, string> $query
     */
    private static function pageUrl(string $page, array $query = []): string
    {
        $base = class_exists('Plugin') ? Plugin::getWebDir('integrasipaman') : '/plugins/integrasipaman';

        return $base . '/front/' . $page . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * The from / to a page needs to show the period a card counts: the
     * dashboard's date filter, or the fixed lookback.
     *
     * @return array<string, string>
     */
    private static function windowQuery(array $params): array
    {
        $window = self::periodWindow($params);

        return $window === null ? [] : ['from' => substr($window[0], 0, 10), 'to' => substr($window[1], 0, 10)];
    }

    /**
     * Provider for the "Session Sharing" bigNumber card. Called by GLPI
     * core as "PluginIntegrasipamanDashboard::provideSessionSharing".
     *
     * Counts rows with event_type='session_join' - each time someone joined
     * a shared session - from inc/cron.class.php's pull of
     * /api/v1/terminal/session-join-records/.
     *
     * v4.5.0 changed what this counts, and the reason is worth keeping:
     * until then it counted 'session_share', the creation of a share LINK.
     * This instance had created two links since February and reused them
     * ever since, so the card read 0 through weeks that held hundreds of
     * real joins. Counting links measured how often someone opened a session
     * up; counting joins measures how often a session was actually shared,
     * which is the question the card is on the dashboard to answer. The
     * links are still pulled and still listed on the page this card links
     * to - "who opened a session up" is its own audit finding.
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

        $where = self::withEventTypeFilter($params, 'session_join');

        $number = self::countTotal($where);

        return [
            'number' => $number,
            'icon'   => 'ti ti-users',
            // v4.6.0: the page now opens on its "Log Session" tab by default,
            // so this card has to name the tab it means or it would land the
            // reader on a different dataset than the number they clicked.
            'url'    => self::pageUrl('session.php', self::windowQuery($params) + ['tab' => 'sharing']),
        ] + self::labelWithDelta(__('JumpServer Session Sharing', 'integrasipaman'), $params, 'session_join', $number);
    }

    /**
     * Provider for the "Recent Events" card (4.1.0): the newest rows of the
     * window, newest first. Restricted to the viewer's entities by
     * fetchRows(), like every other read of the log table.
     *
     * @return array<string, mixed>
     */
    public static function provideRecentEvents(array $params = []): array
    {
        $label = __('JumpServer Recent Events', 'integrasipaman');
        if (!self::canViewCards()) {
            return ['data' => [], 'label' => $label, 'icon' => 'ti ti-list-details'];
        }

        $data = [];
        foreach (self::fetchRows(self::resolveWhereClause($params), self::RECENT_LIMIT) as $row) {
            $data[] = self::recentEntry($row);
        }

        return ['data' => $data, 'label' => $label, 'icon' => 'ti ti-list-details'];
    }

    /**
     * One list entry: the time on the left (the widget's number slot accepts
     * preformatted text), then "event - user@asset". The widget escapes the
     * label; nothing here is HTML.
     *
     * @param array<string, mixed> $row
     * @return array{number: string, label: string, icon: string, color?: string}
     */
    public static function recentEntry(array $row): array
    {
        $when     = strtotime((string) ($row['date_creation'] ?? ''));
        $user     = trim((string) ($row['username'] ?? ''));
        $status   = (string) ($row['status'] ?? '');
        $resource = self::resourceParts($row);

        // "user@asset" only where there IS an asset. Until v4.5.2 this read
        // asset_name unconditionally, so a UserSession row - 863 of 1293 on
        // this instance - rendered as "Sysadmin Nineteen(...)@Sysadmin Nineteen(...)
        // (10.0.242.126)": the same person on both sides of an "@" that
        // claims a connection to a machine. The card has no room for a type
        // column, so a non-asset resource is simply left off; the full
        // picture is on front/export.php, which shows type and resource
        // side by side.
        $subject = $user !== '' ? $user : '-';
        if ($resource['is_asset']) {
            $subject .= '@' . $resource['value'];
        }

        $entry = [
            'number' => $when === false ? '-' : date('d/m H:i', $when),
            'label'  => trim((string) ($row['event_type'] ?? '')) . ' - ' . $subject,
            'icon'   => $status === 'success' ? 'ti ti-check' : 'ti ti-x',
        ];
        if ($status !== 'success') {
            $entry['color'] = '#d63939';
        }

        return $entry;
    }

    /**
     * What a log row's `asset_name` column actually refers to (v4.5.2).
     *
     * `asset_name` is filled by PluginIntegrasipamanCron::mapOperatingLogEntry()
     * from the operate-log's `resource` field, and `resource` is JumpServer's
     * generic "object that was acted on" - an asset only sometimes. Live
     * figures from this instance (probe_resource_shape.php, 2026-10-22, 1293
     * operate-log rows in 30 days):
     *
     *   User Session         863    a person's web/console login session
     *   User                 252    a person
     *   Asset Authorization   50    a permission rule
     *   Host                  38    AN ASSET
     *   Account               32    an account on an asset
     *   ...11 more types      58    node, role binding, access key, ...
     *   Asset                  3    AN ASSET
     *
     * So the column was an asset in 41 of 1293 rows, and a person in 1115.
     *
     * The type is read from the JSON the row already carries in `message` -
     * every operate-log entry has `resource_type` (1293 of 1293), so this
     * costs no schema change and no second API call. Rows from the other
     * feeds (session_join, user_login_failed) carry no resource at all and
     * report none rather than being given a wrong one.
     *
     * For rows that ARE an asset the value already reads "name(IP)" -
     * "VM-APP-07(10.0.0.160)" - so nothing is reformatted here.
     *
     * @param array<string, mixed> $row A row as fetchRows() returns it; must
     *                                  include `message` for the type to be
     *                                  known.
     *
     * @return array{type: string, value: string, is_asset: bool}
     */
    public static function resourceParts(array $row): array
    {
        $value = trim((string) ($row['asset_name'] ?? ''));
        $type  = '';

        $data = json_decode((string) ($row['message'] ?? ''), true);

        if (is_array($data)) {
            $raw = $data['resource_type'] ?? null;

            // `action` already arrives as {"value","label"} on this API; if
            // resource_type ever does too, prefer the label - that is what
            // the allow-list and the screen are both written against.
            if (is_array($raw)) {
                $raw = $raw['label'] ?? $raw['value'] ?? null;
            }

            if (is_scalar($raw)) {
                $type = trim((string) $raw);
            }
        }

        return [
            'type'     => $type,
            'value'    => $value,
            'is_asset' => $type !== '' && in_array($type, self::ASSET_RESOURCE_TYPES, true),
        ];
    }

    /**
     * The card label with the change against the previous period appended,
     * and the full sentence as the tooltip.
     *
     * @return array{label: string, alt?: string}
     */
    private static function labelWithDelta(string $label, array $params, ?string $event_type, int $headline): array
    {
        $delta = self::deltaFor($params, $event_type, $headline);
        if ($delta === null) {
            return ['label' => $label];
        }

        return [
            'label' => $label . ' (' . $delta['text'] . ')',
            'alt'   => sprintf(
                __('%1$s in this period, %2$s in the period before it.', 'integrasipaman'),
                $delta['current'],
                $delta['previous']
            ),
        ];
    }

    /**
     * Compare the period of the card with the period of the same length right
     * before it.
     *
     * The window is worked out here, from the dashboard's date filter or the
     * fixed lookback. Null - no change is shown - when it cannot be worked out
     * or when this query does not reproduce the number the card is showing:
     * two figures that disagree about the same period are worse than one.
     *
     * @return array{text: string, current: int, previous: int}|null
     */
    private static function deltaFor(array $params, ?string $event_type, int $headline): ?array
    {
        $window = self::periodWindow($params);
        if ($window === null) {
            return null;
        }

        $current = self::countTotal(self::windowWhere($window, $event_type));
        if ($current !== $headline) {
            return null;
        }

        $previous = self::countTotal(self::windowWhere(self::previousWindow($window), $event_type));
        $text     = self::deltaText($current, $previous);

        return $text === '' ? null : ['text' => $text, 'current' => $current, 'previous' => $previous];
    }

    /**
     * [begin, end] of the card's period, as datetimes; null when the
     * dashboard's date filter holds values that are not dates.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function periodWindow(array $params): ?array
    {
        $apply_filters = is_array($params['apply_filters'] ?? null) ? $params['apply_filters'] : [];
        $dates_id      = \Glpi\Dashboard\Filters\DatesFilter::getId();
        $value         = $apply_filters[$dates_id] ?? null;

        // Same rule as resolveWhereClause(): only a filter with two values
        // restricts; anything else falls back to the fixed lookback.
        if (is_array($value) && count($value) === 2) {
            $from = strtotime((string) reset($value));
            $to   = strtotime((string) end($value));
            if ($from === false || $to === false || $to < $from) {
                return null;
            }

            return [date('Y-m-d 00:00:00', $from), date('Y-m-d 23:59:59', $to)];
        }

        [$begin, $end] = self::resolveDateRange('last_' . self::CARD_LOOKBACK_DAYS . '_days', '', '');

        return $begin === null || $end === null ? null : [$begin, $end];
    }

    /**
     * The period of the same length that ends one second before $window starts.
     *
     * @param array{0: string, 1: string} $window
     * @return array{0: string, 1: string}
     */
    public static function previousWindow(array $window): array
    {
        $begin  = strtotime($window[0]);
        $length = strtotime($window[1]) - $begin + 1;

        return [date('Y-m-d H:i:s', $begin - $length), date('Y-m-d H:i:s', $begin - 1)];
    }

    /**
     * @param array{0: string, 1: string} $window
     * @return array<int|string, mixed>
     */
    private static function windowWhere(array $window, ?string $event_type): array
    {
        $where = [
            ['date_creation' => ['>=', $window[0]]],
            ['date_creation' => ['<=', $window[1]]],
        ];
        if ($event_type !== null) {
            $where['event_type'] = $event_type;
        }

        return $where;
    }

    /**
     * "▲ 12%", "▼ 8%", "= 0%", "▲ new" (it was zero before), or '' when there
     * is nothing to compare (zero in both periods).
     */
    public static function deltaText(int $current, int $previous): string
    {
        if ($previous <= 0) {
            return $current > 0 ? "\u{25B2} " . __('new', 'integrasipaman') : '';
        }

        $percent = (int) round(($current - $previous) / $previous * 100);

        return ($percent > 0 ? "\u{25B2} " : ($percent < 0 ? "\u{25BC} " : '= ')) . abs($percent) . '%';
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
            'WHERE' => $where + PluginIntegrasipamanConnection::viewer(),
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
            'WHERE'   => $where + PluginIntegrasipamanConnection::viewer(),
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
            'WHERE'  => $where + PluginIntegrasipamanConnection::viewer(),
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
