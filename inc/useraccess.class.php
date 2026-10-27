<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * "JumpServer access" tab on the core User itemtype: which PAMAN assets
 * this person reached, with what commands, when, and from which remote IP.
 *
 * This is the mirror of PluginIntegrasipamanAccessHistory. That one answers
 * "this server - who touched it?"; this one answers "this person - what did
 * they touch?". Same window, same row limit rationale, same gating. The two
 * are deliberately symmetrical, because an auditor moves between them.
 *
 * Sessions are the primary source, not commands. A session with zero
 * commands is still access - an RDP login that typed nothing, or a
 * connection that failed - and a commands-only view would drop it silently.
 * Commands are the detail pane underneath.
 *
 * Gated on RIGHT_REPORT, never on User READ. Anyone who can read a User
 * record is emphatically not the audience for that person's command
 * history.
 *
 * No new tables: sessions from glpi_plugin_jumpserver_sessions, commands
 * from _commands + _command_eval, and the mapping from _usermap.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/connection.class.php';
require_once __DIR__ . '/accessreport.class.php';

class PluginIntegrasipamanUserAccess extends CommonGLPI
{
    private const SESSIONS_TABLE = 'glpi_plugin_jumpserver_sessions';
    private const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    private const EVAL_TABLE     = 'glpi_plugin_jumpserver_command_eval';
    private const USERMAP_TABLE  = 'glpi_plugin_jumpserver_usermap';

    /** The tab is a summary, not a browser: newest N rows per pane. */
    public const ROW_LIMIT   = 27;
    private const WINDOW_DAYS = 30;

    /**
     * Every JumpServer user string mapped to this GLPI user.
     *
     * One person routinely holds more than one: a bare login, a
     * display-name form, sometimes a separate admin account. The
     * correlation resolver maps each string separately, so reading only
     * the first gives partial history that looks complete - no error,
     * plausible rows, wrong answer. On this tab that is the difference
     * between clearing someone and not, so everything below takes the
     * whole list.
     *
     * @return list<string>
     */
    public static function jmsUserStringsFor(int $users_id): array
    {
        global $DB;

        if ($users_id <= 0) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['jms_user'],
            'FROM'   => self::USERMAP_TABLE,
            // NOT restricted to the viewer's entities, unlike every read below:
            // a user-map row lives in the entity that owns the connection, so a
            // viewer working in a child entity would find no name here and the
            // tab would be empty although that entity holds this person's
            // sessions. The names disclose nothing by themselves; the sessions
            // and commands they select are restricted.
            'WHERE'  => ['users_id' => $users_id],
        ]) as $row) {
            $jms_user = (string) ($row['jms_user'] ?? '');
            if ($jms_user !== '') {
                $out[] = $jms_user;
            }
        }

        return $out;
    }

    /** Start of the window, as a SQL datetime. */
    public static function windowStart(): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . self::WINDOW_DAYS . ' days'));
    }

    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);
    }

    /**
     * Headline counts for the window.
     *
     * Counts DISTINCT assets rather than users - on this side of the
     * mirror "how many machines did they reach" is the question.
     *
     * @param list<string> $users
     * @return array{sessions:int,assets:int,failed:int,high_risk:int}
     */
    public static function summary(array $users, string $since): array
    {
        global $DB;

        $empty = ['sessions' => 0, 'assets' => 0, 'failed' => 0, 'high_risk' => 0];
        if ($users === []) {
            return $empty;
        }

        $row = $DB->request([
            'SELECT' => [
                new QueryExpression('COUNT(*) AS ' . $DB->quoteName('sessions')),
                new QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('asset') . ') AS ' . $DB->quoteName('assets')),
                new QueryExpression('COALESCE(SUM(' . $DB->quoteName('is_success') . ' = 0), 0) AS ' . $DB->quoteName('failed')),
            ],
            'FROM'  => self::SESSIONS_TABLE,
            'WHERE' => ['jms_user' => $users, ['date_start' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer(),
        ])->current() ?: [];

        $hr = $DB->request([
            'SELECT'     => [new QueryExpression('COUNT(*) AS ' . $DB->quoteName('high_risk'))],
            'FROM'       => self::COMMANDS_TABLE . ' AS c',
            'INNER JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => ['c.jms_user' => $users, 'e.is_high_risk' => 1, ['c.executed_at' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer('c'),
        ])->current() ?: [];

        return [
            'sessions'  => (int) ($row['sessions'] ?? 0),
            'assets'    => (int) ($row['assets'] ?? 0),
            'failed'    => (int) ($row['failed'] ?? 0),
            'high_risk' => (int) ($hr['high_risk'] ?? 0),
        ];
    }

    /**
     * Newest sessions in the window.
     *
     * @param list<string> $users
     * @return list<array<string,mixed>>
     */
    public static function sessions(array $users, string $since, int $limit): array
    {
        global $DB;

        if ($users === []) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['date_start', 'date_end', 'asset', 'account', 'protocol',
                         'remote_addr', 'duration', 'command_amount', 'is_success',
                         'is_finished', 'error_reason'],
            'FROM'   => self::SESSIONS_TABLE,
            'WHERE'  => ['jms_user' => $users, ['date_start' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer(),
            'ORDER'  => 'date_start DESC',
            'LIMIT'  => $limit,
        ]) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Newest commands in the window - all of them, not only high-risk.
     *
     * The asset tab shows high-risk only, because on a server the question
     * is "what went wrong here". On a person the question is "what did they
     * do", and a list filtered to the alarming rows answers it dishonestly.
     * The LEFT JOIN is what makes that possible: a command with no eval row
     * yet still appears, with an empty risk.
     *
     * @param list<string> $users
     * @return list<array<string,mixed>>
     */
    public static function commands(array $users, string $since, int $limit): array
    {
        global $DB;

        if ($users === []) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'    => ['c.executed_at', 'c.asset', 'c.account', 'c.command',
                            'c.remote_addr', 'e.risk_category', 'e.priority',
                            'e.is_high_risk', 'e.catatan'],
            'FROM'      => self::COMMANDS_TABLE . ' AS c',
            'LEFT JOIN' => [self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'     => ['c.jms_user' => $users, ['c.executed_at' => ['>=', $since]]] + PluginIntegrasipamanConnection::viewer('c'),
            'ORDER'     => 'c.executed_at DESC',
            'LIMIT'     => $limit,
        ]) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Non-static on purpose: CommonGLPI declares getTabNameForItem() as an
     * instance method while keeping displayTabContentForItem() static.
     * Declaring this one static is a fatal "Cannot make non static method
     * static" the moment a User form loads.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof User) || !self::canView()) {
            return '';
        }

        return self::createTabEntry(
            __('JumpServer access', 'integrasipaman'),
            0,
            $item::class,
            'ti ti-history'
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof User) || !self::canView()) {
            return false;
        }

        $users = self::jmsUserStringsFor((int) $item->getID());

        if ($users === []) {
            echo '<div class="alert alert-info m-3">';
            echo htmlescape(__('This user is not mapped to any JumpServer account, so there is no access history to show.', 'integrasipaman'));
            echo ' ' . htmlescape(__('Mappings are resolved under Asset & user correlation.', 'integrasipaman'));
            echo '</div>';
            return true;
        }

        $since    = self::windowStart();
        $summary  = self::summary($users, $since);
        $sessions = self::sessions($users, $since, self::ROW_LIMIT);
        $commands = self::commands($users, $since, self::ROW_LIMIT);
        $access   = PluginIntegrasipamanAccessReport::forUser((int) $item->getID());

        self::renderHeader($summary, $users);
        self::renderPanes($sessions, $commands, $summary, $access);
        self::renderReportLinks($users);

        return true;
    }

    /** @param array{sessions:int,assets:int,failed:int,high_risk:int} $s */
    private static function renderHeader(array $s, array $users): void
    {
        echo '<div class="p-3">';
        echo '<div class="d-flex align-items-center flex-wrap gap-3 mb-2">';
        echo '<span class="fw-bold">' . htmlescape(sprintf(
            __('Last %d days', 'integrasipaman'),
            self::WINDOW_DAYS
        )) . '</span>';
        echo '<span class="text-muted">' . htmlescape(sprintf(
            __('%1$d session(s) · %2$d asset(s) · %3$d high-risk command(s) · %4$d failed', 'integrasipaman'),
            $s['sessions'],
            $s['assets'],
            $s['high_risk'],
            $s['failed']
        )) . '</span>';
        echo '</div>';

        // More than one account is normal, not a fault - say which ones were
        // read, so a reader can tell this is the whole picture. On this tab
        // that disclosure is the difference between a complete history and
        // one that merely looks complete.
        echo '<div class="form-hint">' . htmlescape(sprintf(
            _n('JumpServer account: %s', 'JumpServer accounts: %s', count($users), 'integrasipaman'),
            implode(', ', array_map(static fn($u): string => (string) $u, $users))
        )) . '</div>';

        echo '<div class="form-hint">' . htmlescape(
            __('History is bounded by the plugin retention window - older activity has been pruned, not lost from view.', 'integrasipaman')
        ) . '</div>';
        echo '</div>';
    }

    /**
     * Three sub-tabs. Every pane renders in this one AJAX load and Bootstrap
     * switches between them: a GLPI tab's content is fetched by AJAX, so a
     * server-side pager link would navigate away from the User form
     * entirely. That is why each history pane shows the newest ROW_LIMIT rows
     * and says so, instead of paging.
     *
     * Ids are prefixed with the plugin key: these nav-tabs nest inside the
     * shared PGN Suite tab on asset forms, and a collision there would
     * switch the wrong pane.
     *
     * @param list<array<string,mixed>> $sessions
     * @param list<array<string,mixed>> $commands
     * @param array{sessions:int,assets:int,failed:int,high_risk:int} $summary
     * @param array{
     *   identities: array<string,list<string>>,
     *   groups: list<array<string,mixed>>,
     *   rows: list<array<string,mixed>>,
     *   membership: array{collected:bool,at:?string,stale:bool}
     * } $access
     */
    private static function renderPanes(array $sessions, array $commands, array $summary, array $access): void
    {
        echo '<ul class="nav nav-tabs px-3" role="tablist">';
        echo '<li class="nav-item"><a class="nav-link active" href="#integrasipaman-usr-sessions"'
            . ' data-bs-toggle="tab" data-bs-target="#integrasipaman-usr-sessions" role="tab">'
            . htmlescape(__('Sessions', 'integrasipaman')) . '</a></li>';
        echo '<li class="nav-item"><a class="nav-link" href="#integrasipaman-usr-commands"'
            . ' data-bs-toggle="tab" data-bs-target="#integrasipaman-usr-commands" role="tab">'
            . htmlescape(__('Commands', 'integrasipaman')) . '</a></li>';
        echo '<li class="nav-item"><a class="nav-link" href="#integrasipaman-usr-permissions"'
            . ' data-bs-toggle="tab" data-bs-target="#integrasipaman-usr-permissions" role="tab">'
            . htmlescape(__('Permissions', 'integrasipaman')) . ' <span class="badge bg-blue-lt">'
            . count($access['rows']) . '</span></a></li>';
        echo '</ul>';

        echo '<div class="tab-content">';

        echo '<div class="tab-pane fade show active" id="integrasipaman-usr-sessions" role="tabpanel">';
        self::showingLine(count($sessions), $summary['sessions']);
        self::table(
            [__('Started', 'integrasipaman'), __('Asset', 'integrasipaman'), __('Account', 'integrasipaman'),
             __('Protocol', 'integrasipaman'), __('From', 'integrasipaman'), __('Duration', 'integrasipaman'),
             __('Commands', 'integrasipaman'), __('Status', 'integrasipaman')],
            array_map(static function (array $r): array {
                return [
                    (string) ($r['date_start'] ?? ''),
                    (string) ($r['asset'] ?? ''),
                    (string) ($r['account'] ?? ''),
                    (string) ($r['protocol'] ?? ''),
                    (string) ($r['remote_addr'] ?? ''),
                    self::duration((int) ($r['duration'] ?? 0)),
                    (string) (int) ($r['command_amount'] ?? 0),
                    ((int) ($r['is_success'] ?? 0) === 1)
                        ? __('OK', 'integrasipaman')
                        : trim(__('Failed', 'integrasipaman') . ' ' . (string) ($r['error_reason'] ?? '')),
                ];
            }, $sessions),
            __('No JumpServer sessions for this user in this window.', 'integrasipaman')
        );
        echo '</div>';

        echo '<div class="tab-pane fade" id="integrasipaman-usr-commands" role="tabpanel">';
        self::showingLine(count($commands), 0);
        self::table(
            [__('Executed', 'integrasipaman'), __('Asset', 'integrasipaman'), __('Account', 'integrasipaman'),
             __('Command', 'integrasipaman'), __('From', 'integrasipaman'), __('Priority', 'integrasipaman'),
             __('Catatan', 'integrasipaman')],
            array_map(static function (array $r): array {
                return [
                    (string) ($r['executed_at'] ?? ''),
                    (string) ($r['asset'] ?? ''),
                    (string) ($r['account'] ?? ''),
                    mb_substr((string) ($r['command'] ?? ''), 0, 120),
                    (string) ($r['remote_addr'] ?? ''),
                    (string) ($r['priority'] ?? ''),
                    (string) ($r['catatan'] ?? ''),
                ];
            }, $commands),
            __('No JumpServer commands for this user in this window.', 'integrasipaman')
        );
        echo '</div>';

        echo '<div class="tab-pane fade" id="integrasipaman-usr-permissions" role="tabpanel">';
        self::renderPermissionsPane($access);
        echo '</div>';

        echo '</div>';
    }

    /**
     * What this person may reach, from the permission cache and the
     * membership snapshot - never a live bastion call.
     *
     * One row per asset, with every way in kept separate. A group grant is
     * attributed only when a membership row carries this person's login, so
     * an uncollected snapshot means "cannot say", not "nothing".
     *
     * @param array{
     *   identities: array<string,list<string>>,
     *   groups: list<array<string,mixed>>,
     *   rows: list<array<string,mixed>>,
     *   membership: array{collected:bool,at:?string,stale:bool}
     * } $access
     */
    private static function renderPermissionsPane(array $access): void
    {
        $membership = $access['membership'];

        if (!$membership['collected']) {
            echo '<div class="alert alert-warning m-3">';
            echo htmlescape(__(
                'Group membership has never been collected, so access granted through a group cannot be listed here. Only grants that name this person directly are shown; the group column is incomplete until the entitlement cron runs.',
                'integrasipaman'
            ));
            echo '</div>';
        } elseif ($membership['stale']) {
            echo '<div class="alert alert-warning m-3">';
            echo htmlescape(sprintf(
                __('Group membership was last collected at %s. Group-granted access below is based on that snapshot and may be out of date.', 'integrasipaman'),
                (string) ($membership['at'] ?? __('an unknown time', 'integrasipaman'))
            ));
            echo '</div>';
        }

        // Every group this person is in, including ones no rule uses: that
        // answers "why does this not show up" before it is asked.
        $group_names = [];
        foreach ($access['groups'] as $group) {
            $name = trim((string) ($group['name'] ?? ''));
            $group_names[] = $name !== '' ? $name : (string) ($group['jms_id'] ?? '');
        }
        $group_names = array_values(array_filter($group_names, static fn (string $n): bool => $n !== ''));

        if ($group_names !== []) {
            echo '<div class="form-hint px-3 pt-3">' . htmlescape(sprintf(
                _n('Group held: %s', 'Groups held: %s', count($group_names), 'integrasipaman'),
                implode(', ', $group_names)
            )) . '</div>';
        }

        echo '<div class="table-responsive"><table class="table table-sm table-striped card-table"><thead><tr>';
        foreach ([
            __('Asset', 'integrasipaman'),
            __('Ways in', 'integrasipaman'),
            __('Accounts', 'integrasipaman'),
            __('Actions', 'integrasipaman'),
            __('Status', 'integrasipaman'),
        ] as $heading) {
            echo '<th>' . htmlescape($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($access['rows'] === []) {
            $empty = $membership['collected']
                ? __('No rule in the permission cache grants this user access.', 'integrasipaman')
                : __('No rule names this user directly, and group-granted access cannot be checked yet.', 'integrasipaman');
            echo '<tr><td colspan="5" class="text-muted">' . htmlescape($empty) . '</td></tr>';
        }

        foreach ($access['rows'] as $row) {
            $note = PluginIntegrasipamanAccessReport::assetNote($row);

            echo '<tr><td>' . htmlescape(PluginIntegrasipamanAccessReport::assetName($row));
            if ($note !== '') {
                echo ' <span class="text-muted">(' . htmlescape($note) . ')</span>';
            }
            echo '</td><td>';

            $ways = [];
            foreach ((array) ($row['paths'] ?? []) as $path) {
                $via = (string) ($path['via'] ?? '');
                if (($path['type'] ?? '') === 'group') {
                    $member = (string) ($path['member_display'] ?? '');
                    if (empty($path['group_known'])) {
                        $member .= ' (' . __('membership unknown', 'integrasipaman') . ')';
                    }
                    $ways[] = '<span class="badge bg-blue-lt">' . htmlescape(__('Group', 'integrasipaman')) . '</span> '
                        . htmlescape($member)
                        . ($via !== '' ? ' <span class="text-muted">· ' . htmlescape($via) . '</span>' : '');
                } else {
                    $ways[] = '<span class="badge bg-secondary-lt">' . htmlescape(__('Direct', 'integrasipaman')) . '</span>'
                        . ($via !== '' ? ' <span class="text-muted">· ' . htmlescape($via) . '</span>' : '');
                }
            }
            echo implode('<br>', $ways);
            if ($ways === []) {
                echo '<span class="text-muted">—</span>';
            }
            echo '</td>';

            echo '<td>' . htmlescape(implode(', ', array_map('strval', (array) ($row['accounts'] ?? [])))) . '</td>'
                . '<td>' . htmlescape(implode(', ', array_map('strval', (array) ($row['actions'] ?? [])))) . '</td>'
                . '<td>';

            if (!empty($row['valid'])) {
                echo htmlescape(__('Valid', 'integrasipaman'));
            } else {
                echo '<span class="badge bg-red-lt">' . htmlescape(__('Expired', 'integrasipaman')) . '</span>';
            }
            if ((int) ($row['expired_paths'] ?? 0) > 0 && !empty($row['valid'])) {
                echo ' <span class="text-muted">(' . htmlescape(sprintf(
                    __('%d expired path(s)', 'integrasipaman'),
                    (int) $row['expired_paths']
                )) . ')</span>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table></div>';

        echo '<div class="form-hint px-3 pb-3">' . htmlescape(
            __('Read from the permission cache the entitlement cron fills. A person can reach an asset directly, through a group, or both; every way in is listed rather than merged.', 'integrasipaman')
        ) . '</div>';
    }

    /**
     * Links out to the Command Audit Report, pre-filtered to this person.
     *
     * ONE LINK PER MAPPED ACCOUNT, deliberately. The report's search takes a
     * single term, so a person holding two JumpServer accounts cannot be
     * covered by one link - and a single link that silently filtered on only
     * the first account would under-report exactly the person who has most
     * reason to hold two.
     *
     * The term comes from the mapped account list, NOT from a row: the
     * session and command queries filter BY jms_user and never select it,
     * so reading it back off a row yields an empty filter. That shipped
     * once already and is what the test now pins.
     *
     * Also explicit that the report's search only reaches the high-risk
     * detail - that is where the search box lives. Sending someone to a
     * filtered view without saying what it filters is how a quiet list gets
     * read as "nothing happened".
     *
     * @param list<string> $users
     */
    private static function renderReportLinks(array $users): void
    {
        $base = Plugin::getWebDir('integrasipaman') . '/front/pbi.php?tab=report&rtab=evaluasi_highrisk&hr_q=';

        echo '<div class="form-hint px-3 pb-3">';
        echo htmlescape(__('Open in the Command Audit Report:', 'integrasipaman')) . ' ';

        $links = [];
        foreach ($users as $u) {
            $u = (string) $u;
            if ($u === '') {
                continue;
            }
            $links[] = '<a href="' . htmlescape($base . urlencode($u)) . '">' . htmlescape($u) . '</a>';
        }
        echo implode(' · ', $links);

        echo '<br>' . htmlescape(
            __('That view searches high-risk commands only, not every command.', 'integrasipaman')
        );
        echo '</div>';
    }

    /** "Showing 27 of 91" - the tab must not imply it is showing everything. */
    private static function showingLine(int $shown, int $total): void
    {
        // Nothing shown means the pane is about to print its own "no rows"
        // message. "Showing the 0 most recent of 1" stacked on top of that
        // reads as a fault in the tab rather than an empty window.
        if ($shown === 0) {
            return;
        }

        if ($total > $shown) {
            echo '<div class="form-hint px-3 pt-2">' . htmlescape(sprintf(
                __('Showing the %1$d most recent of %2$d - the full set is in the Command Audit Report.', 'integrasipaman'),
                $shown,
                $total
            )) . '</div>';
            return;
        }

        if ($shown >= self::ROW_LIMIT) {
            // No total to compare against (counting every command in the
            // window is a second aggregate for a pane that is already a
            // summary). A full page is the honest signal that there may be
            // more behind it.
            echo '<div class="form-hint px-3 pt-2">' . htmlescape(sprintf(
                __('Showing the %d most recent - there may be more in the Command Audit Report.', 'integrasipaman'),
                $shown
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
