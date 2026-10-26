<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Log Session (v4.6.0): JumpServer's terminal sessions - who connected to
 * which asset, with which account, over which protocol, from where, and for
 * how long.
 *
 * This is the one dataset in this plugin with a real asset on EVERY row, and
 * that is why the tab exists. The Activity Log next door is the operate-log,
 * whose `resource` is an asset in about 3% of rows and a person in 86% (see
 * PluginIntegrasipamanDashboard::resourceParts() and v4.5.2); asking it "who
 * accessed which server" was always asking the wrong table.
 *
 * Nothing is ingested here. `glpi_plugin_jumpserver_sessions` is already
 * filled by PluginIntegrasipamanTerminalPull::cronPull_terminal_activity()
 * from /api/v1/terminal/sessions/, for the command-audit work (Piece 1).
 * This class only reads it.
 *
 * Read-only; gated on the report right by the controller, and the rows are
 * those of the entities the signed-in user is working in.
 *
 * Not a persisted itemtype - no table of its own, no CommonDBTM.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanSessionLog
{
    private const TABLE = 'glpi_plugin_jumpserver_sessions';

    /**
     * Rows read before the page's own text filter is applied. Same cap and
     * same reason as PluginIntegrasipamanSessionSharing::ROW_LIMIT: the date
     * window is applied in SQL, so this only bounds a deliberately wide one.
     */
    public const ROW_LIMIT = 5000;

    /** Days the page opens on when the visitor has chosen no window of their own. */
    public const DEFAULT_WINDOW_DAYS = 30;

    /**
     * The window the Session page opens on (v4.6.0-rc3), as the two Y-m-d
     * strings its filters already parse.
     *
     * Why there is a default at all: this instance holds 39,800 sessions
     * against a ROW_LIMIT of 5,000, so an unfiltered Log Session is always
     * truncated - and the text search, which runs in PHP over whatever was
     * read, then covers only the newest slice. Opening on a window makes the
     * search mean what it appears to mean. 30 days matches what the dashboard
     * cards and front/export.php already use.
     *
     * The page PREFILLS the date inputs with this rather than applying it
     * invisibly: a filter the visitor cannot see is one they cannot clear,
     * and clearing it (submitting both dates empty) is how they ask for the
     * whole history back.
     *
     * @return array{from: string, to: string}
     */
    public static function defaultWindow(): array
    {
        return [
            'from' => date('Y-m-d', strtotime('-' . (self::DEFAULT_WINDOW_DAYS - 1) . ' days')),
            'to'   => date('Y-m-d'),
        ];
    }

    /**
     * JumpServer's own duration display, from the integer seconds the column
     * holds.
     *
     * `duration` is an INT because PluginIntegrasipamanTerminalPull::
     * durationToSeconds() normalises Python's str(timedelta) ("0:44:57", or
     * "2 days, 1:00:00") on the way in. Printing it raw would put "2697"
     * where the user's JumpServer export reads "0:44:57", so it is formatted
     * back here - once, in one place.
     *
     * Hours keep counting past 24 ("26:03:04") rather than becoming "1 day,
     * 2:03:04" as Python would: a session table is read by scanning down a
     * column, and a row that suddenly changes format is a row that gets
     * misread. A negative value cannot happen from a parsed timedelta, but
     * clamps to zero rather than rendering "-1:-5:-3".
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }

        return sprintf(
            '%d:%02d:%02d',
            intdiv($seconds, 3600),
            intdiv($seconds % 3600, 60),
            $seconds % 60
        );
    }

    /**
     * One session, as the page shows it.
     *
     * @param array<string, mixed> $row A glpi_plugin_jumpserver_sessions row.
     *
     * @return array{
     *   id: string, user: string, asset: string, account: string,
     *   protocol: string, login_from: string, remote_addr: string,
     *   started: string, ended: string, duration_seconds: int,
     *   duration_text: string, commands: int, succeeded: bool,
     *   finished: bool, terminal: string
     * }
     */
    public static function describe(array $row): array
    {
        $seconds = (int) ($row['duration'] ?? 0);

        return [
            'id'               => (string) ($row['jms_id'] ?? ''),
            'user'             => (string) ($row['jms_user'] ?? ''),
            'asset'            => (string) ($row['asset'] ?? ''),
            'account'          => (string) ($row['account'] ?? ''),
            'protocol'         => (string) ($row['protocol'] ?? ''),
            // v4.6.0-rc1 shows the short code JumpServer sends ("WT"); the
            // readable label ("Web Terminal") is not stored yet - see the
            // class docblock of the rc2 migration.
            'login_from'       => (string) ($row['login_from'] ?? ''),
            'remote_addr'      => (string) ($row['remote_addr'] ?? ''),
            'started'          => (string) ($row['date_start'] ?? ''),
            // A session still running has no end. Left empty rather than
            // defaulted, so the page can say "running" instead of printing
            // an end time that never happened.
            'ended'            => (string) ($row['date_end'] ?? ''),
            'duration_seconds' => $seconds,
            'duration_text'    => self::formatDuration($seconds),
            'commands'         => (int) ($row['command_amount'] ?? 0),
            'succeeded'        => !empty($row['is_success']),
            'finished'         => !empty($row['is_finished']),
            'terminal'         => (string) ($row['terminal_name'] ?? ''),
        ];
    }

    /**
     * The sessions the viewer may see, newest first.
     *
     * The date window is applied in SQL against `date_start` - WHEN THE
     * SESSION RAN - and never against `date_creation`, which is only when
     * the row was imported. Filtering on the import time is the mistake that
     * made the Session Sharing card read zero through weeks of real activity
     * (v4.5.0); `date_start` is indexed for exactly this query.
     *
     * The text filter runs in PHP over the described row, like
     * PluginIntegrasipamanSessionSharing::findJoins(), so that one search box
     * covers every column the page shows without six OR'd LIKEs.
     *
     * ROW_LIMIT is a real ceiling, not a theoretical one: this instance passed
     * it within days of the page shipping. So the caller is told whether the
     * read was cut short, through $meta, and the page says so instead of
     * presenting the cap as a count (v4.6.0-rc2).
     *
     * $meta comes back as {fetched, capped, total}. `total` costs a second
     * query and is only paid for when the cap was actually hit; otherwise
     * what was fetched IS the total.
     *
     * @param array{from?: string, to?: string, q?: string} $filters from / to are Y-m-d.
     * @param array{fetched: int, capped: bool, total: int}|null $meta Out-param.
     *
     * @return list<array<string, mixed>>
     */
    public static function find(array $filters = [], ?array &$meta = null): array
    {
        global $DB;

        $where = self::whereFor($filters);

        $q       = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $out     = [];
        $fetched = 0;

        foreach ($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => $where,
            'ORDER' => 'date_start DESC',
            'LIMIT' => self::ROW_LIMIT,
        ]) as $row) {
            $fetched++;
            $session = self::describe($row);

            if ($q !== '' && !str_contains(mb_strtolower(self::searchText($session)), $q)) {
                continue;
            }

            $out[] = $session;
        }

        $capped = $fetched >= self::ROW_LIMIT;

        $meta = [
            'fetched' => $fetched,
            'capped'  => $capped,
            // The COUNT carries the same WHERE as the rows, so the two
            // figures describe the same period and the same entities (suite
            // entity rule: a count comes from the same criteria as its rows).
            'total'   => $capped ? self::countIn($where) : $fetched,
        ];

        return $out;
    }

    /**
     * How many sessions the window holds in total, ignoring ROW_LIMIT.
     *
     * The text filter is NOT part of this: it is applied in PHP over the
     * described row, so a SQL COUNT cannot honour it. That is why the page
     * words a capped, searched view as "matches among the newest N" rather
     * than claiming a total for the search.
     *
     * @param array<mixed> $where The same clause find() read its rows with.
     */
    private static function countIn(array $where): int
    {
        global $DB;

        $row = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => self::TABLE,
            'WHERE' => $where,
        ])->current();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * The entity restriction plus the date window, shared by the row read and
     * the count so neither can drift from the other.
     *
     * @param array{from?: string, to?: string, q?: string} $filters
     *
     * @return array<mixed>
     */
    private static function whereFor(array $filters): array
    {
        $where = PluginIntegrasipamanConnection::viewer();

        $from = self::dayStart($filters['from'] ?? '');
        $to   = self::dayEnd($filters['to'] ?? '');
        if ($from !== null) {
            $where[] = ['date_start' => ['>=', date('Y-m-d H:i:s', $from)]];
        }
        if ($to !== null) {
            $where[] = ['date_start' => ['<=', date('Y-m-d H:i:s', $to)]];
        }

        return $where;
    }

    /** Everything one search box matches a session against. */
    private static function searchText(array $session): string
    {
        return implode(' ', [
            $session['user'], $session['asset'], $session['account'],
            $session['protocol'], $session['login_from'], $session['remote_addr'],
            $session['id'],
        ]);
    }

    /**
     * Midnight at the start of a Y-m-d day, or null when the value is empty
     * or unparseable - an unreadable date drops the filter rather than
     * silently narrowing the page to nothing.
     */
    private static function dayStart(string $value): ?int
    {
        $ts = self::parseDay($value);

        return $ts === null ? null : strtotime('today midnight', $ts);
    }

    /** The last second of a Y-m-d day, by the same rules. */
    private static function dayEnd(string $value): ?int
    {
        $ts = self::parseDay($value);

        return $ts === null ? null : strtotime('tomorrow midnight', $ts) - 1;
    }

    private static function parseDay(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $ts = strtotime($value);

        return ($ts === false || $ts <= 0) ? null : $ts;
    }
}
