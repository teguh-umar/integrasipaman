<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * "JumpServer access history" tab on the core Computer itemtype: who
 * connected to this machine through the PAM, when, from where, and which
 * of their commands were flagged high-risk.
 *
 * This is the reverse of the link added in v2.2.0. There, the Command Audit
 * Report points *at* an asset; here the asset answers for itself, which is
 * the direction an auditor actually asks in ("this server - who touched it
 * last month?").
 *
 * Gated on RIGHT_REPORT, never on Computer READ. The tab carries privileged
 * usernames and the commands they ran, so anyone who can read the Computer
 * is emphatically not the right audience; the v2.4.0 rights split exists in
 * part to give this tab something to gate on.
 *
 * No new tables: sessions come from glpi_plugin_jumpserver_sessions,
 * commands from _commands + _command_eval, and the mapping from _assetmap.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

// This guard exists for the standalone test harness, not for production:
// accesshistory_test.php pre-declares a lightweight PluginIntegrasipamanEntitlement
// stub under its real name before requiring this file - the same
// dependency-substitution pattern every standalone test in this plugin
// uses - and an unconditional require_once here would fatal with "Cannot
// redeclare class". There is no production path where this class is
// declared twice. class_exists(..., false) on purpose, so the check asks
// "has this already been declared" rather than "can the autoloader
// resolve this" - the two differ in a real GLPI request, and only the
// former is the question this guard means to ask.
if (!class_exists('PluginIntegrasipamanEntitlement', false)) {
    require_once __DIR__ . '/entitlement.class.php';
}

require_once __DIR__ . '/connection.class.php';

class PluginIntegrasipamanAccessHistory extends CommonGLPI
{
    private const SESSIONS_TABLE = 'glpi_plugin_jumpserver_sessions';
    private const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    private const EVAL_TABLE     = 'glpi_plugin_jumpserver_command_eval';
    private const ASSETMAP_TABLE = 'glpi_plugin_jumpserver_assetmap';

    /** The tab is a summary, not a browser: newest N rows per pane. */
    private const ROW_LIMIT  = 20;
    private const WINDOW_DAYS = 30;

    /**
     * Every JumpServer asset string mapped to this Computer.
     *
     * A Computer can be reached under more than one string - a short name
     * and an FQDN form of the same host both resolve to it, and the
     * correlation resolver maps each separately. Reading only the first
     * gives partial history that looks complete: no error, plausible rows,
     * wrong answer. Everything below takes the whole list.
     *
     * @return list<string>
     */
    public static function assetStringsFor(int $computers_id): array
    {
        global $DB;

        if ($computers_id <= 0) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['asset'],
            'FROM'   => self::ASSETMAP_TABLE,
            'WHERE'  => ['computers_id' => $computers_id] + PluginIntegrasipamanConnection::viewer(),
        ]) as $row) {
            $asset = (string) ($row['asset'] ?? '');
            if ($asset !== '') {
                $out[] = $asset;
            }
        }

        return $out;
    }

    /** Start of the window, as a SQL datetime. */
    public static function windowStart(): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_DAYS . ' days'));
    }

    /**
     * Headline counts for the window.
     *
     * @param list<string> $assets
     * @return array{sessions:int,users:int,failed:int,high_risk:int}
     */
    public static function summary(array $assets, string $since): array
    {
        global $DB;

        $empty = ['sessions' => 0, 'users' => 0, 'failed' => 0, 'high_risk' => 0];
        if ($assets === []) {
            return $empty;
        }

        $row = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS ' . $DB->quoteName('sessions')),
                new QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('jms_user') . ') AS ' . $DB->quoteName('users')),
                new QueryExpression('COALESCE(SUM(' . $DB->quoteName('is_success') . ' = 0), 0) AS ' . $DB->quoteName('failed')),
            ],
            'FROM'  => self::SESSIONS_TABLE,
            'WHERE' => ['asset' => $assets, ['date_start' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer(),
        ])->current() ?: [];

        $hr = $DB->request([
            'SELECT'     => [new QueryExpression('COUNT(*) AS ' . $DB->quoteName('high_risk'))],
            'FROM'       => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => ['c.asset' => $assets, 'e.is_high_risk' => 1, ['c.executed_at' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer('c'),
        ])->current() ?: [];

        return [
            'sessions'  => (int) ($row['sessions'] ?? 0),
            'users'     => (int) ($row['users'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'high_risk' => (int) ($hr['high_risk'] ?? 0),
        ];
    }

    /**
     * Newest sessions in the window.
     *
     * @param list<string> $assets
     * @return list<array<string,mixed>>
     */
    public static function sessions(array $assets, string $since, int $limit): array
    {
        global $DB;

        if ($assets === []) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['date_start', 'date_end', 'jms_user', 'account', 'protocol',
                         'remote_addr', 'duration', 'command_amount', 'is_success',
                         'is_finished', 'error_reason'],
            'FROM'   => self::SESSIONS_TABLE,
            'WHERE'  => ['asset' => $assets, ['date_start' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer(),
            'ORDER'  => 'date_start DESC',
            'LIMIT'  => $limit,
        ]) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Newest high-risk commands in the window.
     *
     * @param list<string> $assets
     * @return list<array<string,mixed>>
     */
    public static function highRisk(array $assets, string $since, int $limit): array
    {
        global $DB;

        if ($assets === []) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['c.executed_at', 'c.jms_user', 'c.command',
                             'e.risk_category', 'e.priority', 'e.catatan'],
            'FROM'       => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => ['c.asset' => $assets, 'e.is_high_risk' => 1,
                             ['c.executed_at' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer('c'),
            'ORDER'      => 'c.executed_at DESC',
            'LIMIT'      => $limit,
        ]) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Non-static on purpose: CommonGLPI declares getTabNameForItem() as an
     * instance method while keeping displayTabContentForItem() static.
     * Declaring this one static is a fatal "Cannot make non static method
     * static" the moment a Computer form loads.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Computer) || !self::canView()) {
            return '';
        }

        return self::createTabEntry(
            __('JumpServer access', 'integrasipaman'),
            0,
            $item::class,
            'ti ti-history'
        );
    }

    /** The report right, not Computer READ - see the file docblock. */
    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Computer) || !self::canView()) {
            return false;
        }

        $assets = self::assetStringsFor((int) $item->getID());

        if ($assets === []) {
            // No assetmap row means no session HISTORY - and nothing more.
            // Entitlements are keyed on computers_id in assetperms and never
            // touch assetmap, so they are still answerable here.
            //
            // This used to `return true` at this point, which hid all three
            // sub-tabs. That made the entitlement feature invisible for the
            // machines it exists to cover: a Computer nobody has connected to
            // has no command history, so correlation never maps it. Found on
            // the VM after v3.4.1 - Computer 46 held entitlement rows in the
            // database while its tab read "not mapped" with no sub-tabs at
            // all. The guard was written in v2.5.0, when history was all this
            // tab had; v3.3.0 added a pane behind it without revisiting it.
            echo '<div class="alert alert-info m-3">';
            echo htmlescape(__('This Computer is not mapped to any JumpServer asset, so there is no access history to show.', 'integrasipaman'));
            echo ' ' . htmlescape(__('Mappings are resolved under Asset & user correlation.', 'integrasipaman'));
            echo ' ' . htmlescape(__('Permissions are collected separately and are shown below.', 'integrasipaman'));
            echo '</div>';

            // Open on the pane that has something to say. Landing on an empty
            // Sessions table would repeat, in a different shape, the mistake
            // this fix is undoing.
            self::renderPanes(
                [],
                [],
                ['sessions' => 0, 'users' => 0, 'failed' => 0, 'high_risk' => 0],
                (int) $item->getID(),
                'entitlement'
            );

            return true;
        }

        $since   = self::windowStart();
        $summary = self::summary($assets, $since);
        $rows    = self::sessions($assets, $since, self::ROW_LIMIT);
        $risky   = self::highRisk($assets, $since, self::ROW_LIMIT);

        self::renderHeader($summary, $assets);
        self::renderPanes($rows, $risky, $summary, (int) $item->getID());

        return true;
    }

    /** @param array{sessions:int,users:int,failed:int,high_risk:int} $s */
    private static function renderHeader(array $s, array $assets): void
    {
        echo '<div class="p-3">';
        echo '<div class="d-flex align-items-center flex-wrap gap-3 mb-2">';
        echo '<span class="fw-bold">' . htmlescape(sprintf(
            __('Last %d days', 'integrasipaman'),
            self::WINDOW_DAYS
        )) . '</span>';
        echo '<span class="text-muted">' . htmlescape(sprintf(
            __('%1$d session(s) · %2$d user(s) · %3$d high-risk command(s) · %4$d failed', 'integrasipaman'),
            $s['sessions'],
            $s['users'],
            $s['high_risk'],
            $s['failed']
        )) . '</span>';
        echo '</div>';

        // More than one string is normal, not a fault - say which ones were
        // read, so a reader can tell this is the whole picture.
        echo '<div class="form-hint">' . htmlescape(sprintf(
            _n('JumpServer asset: %s', 'JumpServer assets: %s', count($assets), 'integrasipaman'),
            implode(', ', array_map(static fn($a): string => (string) $a, $assets))
        )) . '</div>';

        echo '<div class="form-hint">' . htmlescape(
            __('History is bounded by the plugin retention window - older activity has been pruned, not lost from view.', 'integrasipaman')
        ) . '</div>';
        echo '</div>';
    }

    /**
     * Three sub-tabs. All panes are rendered in this one AJAX load and
     * Bootstrap switches between them: a GLPI tab's content is fetched by
     * AJAX, so a server-side pager link would navigate away from the
     * Computer form entirely. That is why each pane shows the newest
     * ROW_LIMIT rows and says so, instead of paging.
     */
    private static function renderPanes(
        array $rows,
        array $risky,
        array $summary,
        int $computers_id,
        string $active = 'sessions'
    ): void {
        // Bootstrap needs the `active` class on BOTH the nav link and its
        // pane, and on exactly one of each - two actives renders two panes
        // stacked, none renders a tab strip over blank space.
        $on = static fn(string $pane, string $extra = ''): string
            => $pane === $active ? $extra . ' active' : '';
        echo '<ul class="nav nav-tabs px-3" role="tablist">';
        echo '<li class="nav-item"><a class="nav-link' . $on('sessions') . '" href="#integrasipaman-acc-sessions"'
            . ' data-bs-toggle="tab" data-bs-target="#integrasipaman-acc-sessions" role="tab">'
            . htmlescape(__('Sessions', 'integrasipaman')) . '</a></li>';
        echo '<li class="nav-item"><a class="nav-link" href="#integrasipaman-acc-risk"'
            . ' data-bs-toggle="tab" data-bs-target="#integrasipaman-acc-risk" role="tab">'
            . htmlescape(__('High-risk commands', 'integrasipaman')) . '</a></li>';
        echo '<li class="nav-item"><a class="nav-link' . $on('entitlement') . '" href="#integrasipaman-acc-entitlement"'
            . ' data-bs-toggle="tab" data-bs-target="#integrasipaman-acc-entitlement" role="tab">'
            . htmlescape(__('Who has access', 'integrasipaman')) . '</a></li>';
        echo '</ul>';

        echo '<div class="tab-content">';

        echo '<div class="tab-pane fade' . $on('sessions', ' show') . '" id="integrasipaman-acc-sessions" role="tabpanel">';
        self::showingLine(count($rows), $summary['sessions']);
        self::table(
            [__('Started', 'integrasipaman'), __('User', 'integrasipaman'), __('Account', 'integrasipaman'),
             __('Protocol', 'integrasipaman'), __('From', 'integrasipaman'), __('Duration', 'integrasipaman'),
             __('Commands', 'integrasipaman'), __('Status', 'integrasipaman')],
            array_map(static function (array $r): array {
                return [
                    (string) ($r['date_start'] ?? ''),
                    (string) ($r['jms_user'] ?? ''),
                    (string) ($r['account'] ?? ''),
                    (string) ($r['protocol'] ?? ''),
                    (string) ($r['remote_addr'] ?? ''),
                    self::duration((int) ($r['duration'] ?? 0)),
                    (string) (int) ($r['command_amount'] ?? 0),
                    ((int) ($r['is_success'] ?? 0)) === 1
                        ? __('OK', 'integrasipaman')
                        : trim(__('Failed', 'integrasipaman') . ' ' . (string) ($r['error_reason'] ?? '')),
                ];
            }, $rows),
            __('No JumpServer sessions on this Computer in this window.', 'integrasipaman')
        );
        echo '</div>';

        echo '<div class="tab-pane fade" id="integrasipaman-acc-risk" role="tabpanel">';
        self::showingLine(count($risky), $summary['high_risk']);
        self::table(
            [__('Executed', 'integrasipaman'), __('User', 'integrasipaman'), __('Command', 'integrasipaman'),
             __('Category', 'integrasipaman'), __('Priority', 'integrasipaman'), __('Note', 'integrasipaman')],
            array_map(static function (array $r): array {
                return [
                    (string) ($r['executed_at'] ?? ''),
                    (string) ($r['jms_user'] ?? ''),
                    mb_substr((string) ($r['command'] ?? ''), 0, 120),
                    (string) ($r['risk_category'] ?? ''),
                    (string) ($r['priority'] ?? ''),
                    (string) ($r['catatan'] ?? ''),
                ];
            }, $risky),
            __('No high-risk commands on this Computer in this window.', 'integrasipaman')
        );
        echo '</div>';

        echo '<div class="tab-pane fade' . $on('entitlement', ' show') . '" id="integrasipaman-acc-entitlement" role="tabpanel">';
        self::renderEntitlement($computers_id);
        echo '</div>';

        echo '</div>';
    }

    /**
     * Who is PERMITTED to reach this machine, as opposed to who already
     * has. Rendered from the cache that PluginIntegrasipamanEntitlement
     * fills weekly - never from a live API call, because this tab renders
     * on every Computer form open and a bastion round-trip does not belong
     * on that path.
     */
    private static function renderEntitlement(int $computers_id): void
    {
        $refreshed = PluginIntegrasipamanEntitlement::lastRefreshed($computers_id);

        if (PluginIntegrasipamanEntitlement::isStale($refreshed)) {
            // Never present a stale answer as a current one. "Nobody has
            // access" and "we have not asked recently" look identical
            // otherwise, and only one of them is safe to act on.
            echo '<div class="alert alert-warning m-3">';
            echo htmlescape($refreshed === null
                // GLPI's own UI calls these "Automatic actions" and never
                // says "cron", so neither does this.
                ? __('Entitlements have never been collected for this Computer. Check that the automatic action "entitlement_pull" is running.', 'integrasipaman')
                : sprintf(__('Entitlements were last collected on %s and may be out of date. Check that the automatic action "entitlement_pull" is running.', 'integrasipaman'), $refreshed));
            echo '</div>';
        } else {
            echo '<div class="form-hint px-3 pt-2">' . htmlescape(sprintf(
                __('Collected from JumpServer on %s.', 'integrasipaman'),
                (string) $refreshed
            )) . '</div>';
        }

        $rows = PluginIntegrasipamanEntitlement::forComputer($computers_id);

        self::table(
            [__('User / group', 'integrasipaman'), __('Type', 'integrasipaman'),
             __('Via', 'integrasipaman'), __('Accounts', 'integrasipaman'),
             __('Actions', 'integrasipaman'), __('Status', 'integrasipaman')],
            array_map(static function (array $r): array {
                return [
                    (string) ($r['display'] ?? ''),
                    (string) ($r['type'] ?? ''),
                    (string) ($r['via'] ?? ''),
                    (string) ($r['accounts'] ?? ''),
                    (string) ($r['actions'] ?? ''),
                    !empty($r['valid'])
                        ? __('Active', 'integrasipaman')
                        : trim(__('Expired', 'integrasipaman') . ' ' . (string) ($r['date_expired'] ?? '')),
                ];
            }, $rows),
            __('No JumpServer permission reaches this Computer.', 'integrasipaman')
        );
    }

    /** "Showing 20 of 37" - the tab must not imply it is showing everything. */
    private static function showingLine(int $shown, int $total): void
    {
        if ($total > $shown) {
            echo '<div class="form-hint px-3 pt-2">' . htmlescape(sprintf(
                __('Showing the %1$d most recent of %2$d - the full set is in the Command Audit Report.', 'integrasipaman'),
                $shown,
                $total
            )) . '</div>';
        }
    }

    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    private static function table(array $columns, array $rows, string $empty): void
    {
        echo '<div class="table-responsive"><table class="table table-striped card-table"><thead><tr>';
        foreach ($columns as $c) {
            echo '<th>' . htmlescape($c) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlescape((string) $cell) . '</td>';
            }
            echo '</tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="' . count($columns) . '" class="text-muted">' . htmlescape($empty) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Seconds -> "12m" / "1h 04m"; JumpServer reports session length in seconds. */
    private static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '-';
        }
        $m = intdiv($seconds, 60);
        $h = intdiv($m, 60);

        return $h > 0 ? sprintf('%dh %02dm', $h, $m % 60) : $m . 'm';
    }
}
