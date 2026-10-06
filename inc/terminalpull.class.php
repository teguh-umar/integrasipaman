<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Pulls JumpServer terminal COMMAND and SESSION records into
 * glpi_plugin_jumpserver_commands / _sessions, one calendar month at a
 * time, deduped by JumpServer id. Driven by the pull_terminal_activity
 * CronTask (incremental, daily) and by front/backfill.php (one month, on
 * demand). Command "output" is deliberately never stored - see
 * docs/superpowers/specs/2026-08-29-pam-command-audit-piece1-terminal-pull-design.md.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/jmsapi.class.php';
require_once __DIR__ . '/apiwindow.class.php';
require_once __DIR__ . '/config.class.php';

class PluginIntegrasipamanTerminalPull extends CommonDBTM
{
    private const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    private const SESSIONS_TABLE = 'glpi_plugin_jumpserver_sessions';

    public const COMMANDS_PATH = '/api/v1/terminal/commands/';
    public const SESSIONS_PATH = '/api/v1/terminal/sessions/';

    /**
     * Terminal endpoints are far higher-volume than the audit logs, so
     * they request larger pages and allow many more of them:
     * TERMINAL_PAGE_SIZE * TERMINAL_MAX_PAGES = 400k rows/month ceiling.
     */
    public const TERMINAL_PAGE_SIZE = 1000;
    public const TERMINAL_MAX_PAGES = 400;

    /**
     * Map one /api/v1/terminal/commands/ entry to a
     * glpi_plugin_jumpserver_commands row. Field names follow
     * jumpserver_monthly_audit.py's proven mapping; "timestamp" is a Unix
     * epoch, "risk_level" is a nested {value,label} object.
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    public static function mapCommandEntry(array $e): array
    {
        $epoch = is_numeric($e['timestamp'] ?? null) ? (int) $e['timestamp'] : 0;

        return [
            'jms_id'         => mb_substr((string) ($e['id'] ?? ''), 0, 64),
            'executed_at'    => $epoch > 0 ? date('Y-m-d H:i:s', $epoch) : null,
            'jms_user'       => mb_substr((string) ($e['user'] ?? ''), 0, 191),
            'asset'          => mb_substr((string) ($e['asset'] ?? ''), 0, 191),
            'account'        => mb_substr((string) ($e['account'] ?? ''), 0, 191),
            // `command` is TEXT (65535 bytes) - truncate an over-long pasted
            // script rather than fail the INSERT under STRICT_TRANS_TABLES.
            'command'        => mb_strcut((string) ($e['input'] ?? ''), 0, 65535),
            'risk_level'     => (int) ($e['risk_level']['value'] ?? 0),
            'risk_label'     => mb_substr((string) ($e['risk_level']['label'] ?? ''), 0, 32),
            'session_jms_id' => mb_substr((string) ($e['session'] ?? ''), 0, 64),
            'remote_addr'    => mb_substr((string) ($e['remote_addr'] ?? ''), 0, 45),
        ];
    }

    /**
     * JumpServer's session "duration" is Python str(timedelta): "H:MM:SS",
     * or "N day[s], H:MM:SS" for long sessions, or (on some versions) a
     * plain number of seconds. Normalise all of those to an integer count
     * of seconds for the `duration` INT column - a bare (int) cast on
     * "0:03:32" silently yields 0.
     *
     * @param mixed $raw
     */
    private static function durationToSeconds($raw): int
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return 0;
        }

        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        $days = 0;
        if (preg_match('/^(\d+)\s*days?,\s*(.+)$/i', $raw, $m)) {
            $days = (int) $m[1];
            $raw  = trim($m[2]);
        }

        $parts = explode(':', $raw);
        if (count($parts) !== 3) {
            return 0;
        }

        return $days * 86400
            + (int) $parts[0] * 3600
            + (int) $parts[1] * 60
            + (int) $parts[2];
    }

    /**
     * Map one /api/v1/terminal/sessions/ entry to a
     * glpi_plugin_jumpserver_sessions row.
     *
     * @param array<string, mixed> $e
     * @return array<string, mixed>
     */
    public static function mapSessionEntry(array $e): array
    {
        return [
            'jms_id'         => mb_substr((string) ($e['id'] ?? ''), 0, 64),
            'jms_user'       => mb_substr((string) ($e['user'] ?? ''), 0, 191),
            'user_jms_id'    => mb_substr((string) ($e['user_id'] ?? ''), 0, 64),
            'asset'          => mb_substr((string) ($e['asset'] ?? ''), 0, 191),
            'asset_jms_id'   => mb_substr((string) ($e['asset_id'] ?? ''), 0, 64),
            'account'        => mb_substr((string) ($e['account'] ?? ''), 0, 191),
            'protocol'       => mb_substr((string) ($e['protocol'] ?? ''), 0, 32),
            'login_from'     => mb_substr((string) ($e['login_from']['value'] ?? ''), 0, 32),
            'remote_addr'    => mb_substr((string) ($e['remote_addr'] ?? ''), 0, 45),
            'duration'       => self::durationToSeconds($e['duration'] ?? 0),
            'command_amount' => (int) ($e['command_amount'] ?? 0),
            'is_success'     => !empty($e['is_success']) ? 1 : 0,
            'is_finished'    => !empty($e['is_finished']) ? 1 : 0,
            'date_start'     => !empty($e['date_start']) ? date('Y-m-d H:i:s', strtotime((string) $e['date_start'])) : null,
            'date_end'       => !empty($e['date_end']) ? date('Y-m-d H:i:s', strtotime((string) $e['date_end'])) : null,
            'error_reason'   => mb_substr((string) ($e['error_reason']['value'] ?? ''), 0, 128),
            'terminal_name'  => mb_substr((string) ($e['terminal']['name'] ?? ''), 0, 128),
        ];
    }

    /**
     * Insert mapped rows into $table, skipping any whose $id_col value is
     * already present. The existence check is one "WHERE jms_id IN (...)"
     * per call, not one query per row. Callers pass one page at a time
     * (<= TERMINAL_PAGE_SIZE rows) so the IN(...) list stays bounded.
     *
     * @param array<int, array<string, mixed>> $rows Mapped rows (must contain $id_col).
     * @return array{inserted: int, skipped: int, empty_id: int}
     */
    private static function insertDedup(string $table, string $id_col, array $rows): array
    {
        global $DB;

        $inserted = 0;
        $skipped  = 0;
        $empty_id = 0;

        // Collect the incoming ids (non-empty), then find which already exist.
        $incoming_ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row[$id_col] ?? '');
            if ($id !== '') {
                $incoming_ids[$id] = true;
            }
        }

        $existing = [];
        if ($incoming_ids !== []) {
            $iterator = $DB->request([
                'SELECT' => $id_col,
                'FROM'   => $table,
                // Within this connection: two JumpServers may issue the same id.
                'WHERE'  => [$id_col => array_keys($incoming_ids)] + PluginIntegrasipamanConnection::ownRows(),
            ]);
            foreach ($iterator as $r) {
                $existing[(string) $r[$id_col]] = true;
            }
        }

        // Guard against duplicates within the same batch too.
        $seen_this_batch = [];

        foreach ($rows as $row) {
            $id = (string) ($row[$id_col] ?? '');

            // No dedup key: skip it rather than insert unconditionally on
            // every run (would double-insert daily if the API omits id).
            if ($id === '') {
                $skipped++;
                $empty_id++;
                continue;
            }

            if ($id !== '' && (isset($existing[$id]) || isset($seen_this_batch[$id]))) {
                $skipped++;
                continue;
            }

            if ($id !== '') {
                $seen_this_batch[$id] = true;
            }

            if ($DB->insert($table, $row)) {
                $inserted++;
            } elseif (str_contains((string) $DB->error(), 'Duplicate entry')) {
                // Lost a dedup race against a concurrent cron + backfill-page
                // run for the same month (UNIQUE KEY jms_id). Count as skipped,
                // not a failure.
                $skipped++;
            } else {
                trigger_error(
                    sprintf('Failed to insert into %s: %s', $table, $DB->error()),
                    E_USER_WARNING
                );
            }
        }

        if ($empty_id > 0) {
            trigger_error(
                sprintf('insertDedup: %d row(s) with empty %s skipped (no dedup key)', $empty_id, $id_col),
                E_USER_WARNING
            );
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'empty_id' => $empty_id];
    }

    /**
     * ISO-8601 UTC start/end for a "YYYY-MM" string.
     *
     * @return array{0: string, 1: string} [date_from, date_to]
     */
    private static function monthBounds(string $ym): array
    {
        [$y, $m] = array_map('intval', explode('-', $ym));
        $last    = (int) date('t', mktime(0, 0, 0, $m, 1, $y));

        return [
            sprintf('%04d-%02d-01T00:00:00Z', $y, $m),
            sprintf('%04d-%02d-%02dT23:59:59Z', $y, $m, $last),
        ];
    }

    /**
     * Pull every command and session for one calendar month, dedup-insert
     * both, and return per-endpoint counts. Reads its own config. Each page
     * is mapped + dedup-inserted and then discarded (signedGetEach) so peak
     * memory stays bounded no matter how big the month is.
     *
     * @throws InvalidArgumentException on a malformed $ym
     * @return array{commands: array{fetched:int,inserted:int,skipped:int,empty_id:int,null_date:int,ok:bool,complete:bool}, sessions: array{fetched:int,inserted:int,skipped:int,empty_id:int,null_date:int,ok:bool,complete:bool}}
     */
    public static function pullMonth(string $ym): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new InvalidArgumentException('Month must be YYYY-MM');
        }

        [$base_url, $access_key_id, $access_key_secret, $org_id] = PluginIntegrasipamanConnection::currentCredentials();
        $storage_id = PluginIntegrasipamanConnection::currentValue('command_storage_id');

        $blank  = ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'empty_id' => 0, 'null_date' => 0, 'ok' => false, 'complete' => false];
        $result = ['commands' => $blank, 'sessions' => $blank];

        if ($base_url === '' || $access_key_id === '' || $access_key_secret === '') {
            trigger_error('JumpServer URL / Access Key not configured; terminal pull skipped.', E_USER_WARNING);
            return $result;
        }

        [$date_from, $date_to] = self::monthBounds($ym);

        // --- Commands --------------------------------------------------
        if ($storage_id === '') {
            trigger_error('jumpserver_command_storage_id not configured; command pull skipped.', E_USER_WARNING);
        } else {
            $acc = ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'empty_id' => 0, 'null_date' => 0];
            $cmd_path = self::COMMANDS_PATH . '?' . http_build_query([
                'command_storage_id' => $storage_id,
                'date_from'          => $date_from,
                'date_to'            => $date_to,
                'order'              => '-timestamp',
            ]);
            $complete = PluginIntegrasipamanJmsApi::signedGetEach(
                $cmd_path, $base_url, $access_key_id, $access_key_secret, $org_id,
                static function (array $page) use (&$acc): void {
                    $rows = array_map([self::class, 'mapCommandEntry'], array_filter($page, 'is_array'));
                    // v3.11.0: the entity of a command is decided here, once.
                    $rows = PluginIntegrasipamanConnection::stampRows($rows);
                    $acc['fetched']   += count($rows);
                    $acc['null_date'] += count(array_filter($rows, static fn($r) => $r['executed_at'] === null));
                    $c = self::insertDedup(self::COMMANDS_TABLE, 'jms_id', $rows);
                    $acc['inserted'] += $c['inserted'];
                    $acc['skipped']  += $c['skipped'];
                    $acc['empty_id'] += $c['empty_id'];
                },
                self::TERMINAL_PAGE_SIZE,
                self::TERMINAL_MAX_PAGES
            );
            $result['commands'] = $acc + ['ok' => !($complete === false && $acc['fetched'] === 0), 'complete' => $complete];
        }

        // --- Sessions -------------------------------------------------
        // Unlike /terminal/commands/, /terminal/sessions/ does NOT document
        // date_from/date_to (swagger documents the numeric days/days__lt pair
        // instead). DRF ignores query params outside a view's filterset, so
        // these two may do nothing at all, which would silently turn this
        // "one month" pull into a full-history pull that dedup then hides.
        // They are still sent - free if honoured - and the window is enforced
        // client-side by PluginIntegrasipamanApiWindow, which also decides
        // when it is safe to stop paging early. See inc/apiwindow.class.php.
        //
        // DO NOT switch this to days/days__lt: probed live 2026-09-22 against
        // paman.example.com, both return **HTTP 500** here, with and without
        // `order`. `order` itself IS honoured, so the early stop engages and
        // the pull is already bounded. Re-probe before reopening this.
        $acc = ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'empty_id' => 0, 'null_date' => 0];
        $sess_path = self::SESSIONS_PATH . '?' . http_build_query([
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'order'     => '-date_start',
        ]);
        $window = new PluginIntegrasipamanApiWindow('date_start', $date_from, $date_to);
        $complete = PluginIntegrasipamanJmsApi::signedGetEach(
            $sess_path, $base_url, $access_key_id, $access_key_secret, $org_id,
            static function (array $page) use (&$acc, $window) {
                $rows = array_map([self::class, 'mapSessionEntry'], $window->filter($page));
                $rows = PluginIntegrasipamanConnection::stampRows($rows);
                $acc['fetched']   += count($rows);
                $acc['null_date'] += count(array_filter($rows, static fn($r) => $r['date_start'] === null));
                $c = self::insertDedup(self::SESSIONS_TABLE, 'jms_id', $rows);
                $acc['inserted'] += $c['inserted'];
                $acc['skipped']  += $c['skipped'];
                $acc['empty_id'] += $c['empty_id'];

                return !$window->canStop();
            },
            self::TERMINAL_PAGE_SIZE,
            self::TERMINAL_MAX_PAGES
        );
        $acc['out_of_window'] = $window->stats()['dropped'];
        $result['sessions'] = $acc + ['ok' => !($complete === false && $acc['fetched'] === 0), 'complete' => $complete];

        return $result;
    }

    /**
     * Public entry for a single-month pull (front/backfill.php). Month-string
     * validation now lives in pullMonth(); the caller is responsible for
     * higher-level checks (not in the future, within retention).
     *
     * @throws InvalidArgumentException on a malformed $ym
     * @return array same shape as pullMonth()
     */
    public static function backfillMonth(string $ym): array
    {
        return self::pullMonth($ym);
    }

    /**
     * @param string $name
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        if ($name === 'pull_terminal_activity') {
            return ['description' => 'Pull JumpServer terminal command & session records, then prune past the retention window'];
        }

        return [];
    }

    /**
     * Months a run should (re-)pull: every month in the backfill window
     * that has no rows yet (self-healing across runs if an earlier run
     * died partway), plus previous + current month every time (to catch
     * late-flushed records). Oldest first. jms_id dedup makes re-scans cheap.
     *
     * @return array<int, string>
     */
    private static function monthsToPull(int $backfill_months): array
    {
        $months = [];

        // Loop stops at 2 because prev(1) and current(0) are always appended.
        for ($i = $backfill_months - 1; $i >= 2; $i--) {
            $ym = date('Y-m', strtotime("first day of -{$i} months"));
            // Re-pull the month if EITHER table is missing its data - a
            // month where only sessions came through (e.g. an earlier run
            // whose commands endpoint was misconfigured) still needs its
            // commands. pullMonth() fetches both endpoints regardless;
            // jms_id dedup makes the already-present half cheap.
            if (!self::monthHasRows(self::COMMANDS_TABLE, 'executed_at', $ym)
                || !self::monthHasRows(self::SESSIONS_TABLE, 'date_start', $ym)) {
                $months[] = $ym;
            }
        }

        $months[] = date('Y-m', strtotime('first day of -1 month'));
        $months[] = date('Y-m');

        return $months;
    }

    /** True if $table has a row whose $date_col falls inside this YYYY-MM. */
    private static function monthHasRows(string $table, string $date_col, string $ym): bool
    {
        global $DB;

        [$y, $m] = array_map('intval', explode('-', $ym));
        $start   = sprintf('%04d-%02d-01 00:00:00', $y, $m);
        $last    = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        $end     = sprintf('%04d-%02d-%02d 23:59:59', $y, $m, $last);

        $count = $DB->request([
            'COUNT' => 'c',
            'FROM'  => $table,
            // This connection's rows: a month another JumpServer has filled
            // is still an empty month for this one.
            'WHERE' => [
                [$date_col => ['>=', $start]],
                [$date_col => ['<=', $end]],
            ] + PluginIntegrasipamanConnection::ownRows(),
        ])->current()['c'] ?? 0;

        return $count > 0;
    }

    /**
     * Delete command rows older than $months by executed_at and session
     * rows older than $months by date_start. Rows whose event date is NULL
     * (API omitted the timestamp) are pruned on date_creation instead, so
     * they cannot accumulate forever.
     *
     * @return array{commands: int, sessions: int} rows deleted
     */
    private static function prune(int $months): array
    {
        global $DB;

        // Clean month start, matching monthsToPull() / front/backfill.php's
        // range floor - "-{$months} months" alone does day-preserving
        // arithmetic that overflows past a month end (31 Mar - 1 month =>
        // 3 Mar), deleting a few days from inside the retention window.
        $cutoff = date('Y-m-01 00:00:00', strtotime("first day of -{$months} months"));

        // Delete eval rows for the command rows about to be pruned. The
        // FK ON DELETE CASCADE covers this when the constraint exists, but
        // some GLPI schema tooling strips FKs (see hook.php's table
        // creation), so do it explicitly too - a JOIN-delete on the same
        // cutoff, cheap because it hits exactly the rows being removed.
        $DB->doQuery(
            'DELETE e FROM `glpi_plugin_jumpserver_command_eval` e'
          . ' JOIN `glpi_plugin_jumpserver_commands` c ON e.`commands_id` = c.`id`'
          . ' WHERE c.`executed_at` < ' . $DB->quote($cutoff)
          . ' OR (c.`executed_at` IS NULL AND c.`date_creation` < ' . $DB->quote($cutoff) . ')'
        );

        // Two deletes per table rather than a nested OR/AND criterion (the
        // GLPI query builder form the fix brief proposed) - simpler and
        // guaranteed to build.
        $DB->delete(self::COMMANDS_TABLE, ['executed_at' => ['<', $cutoff]]);
        $cmd = $DB->affectedRows();
        $DB->delete(self::COMMANDS_TABLE, ['executed_at' => null, 'date_creation' => ['<', $cutoff]]);
        $cmd += $DB->affectedRows();

        $DB->delete(self::SESSIONS_TABLE, ['date_start' => ['<', $cutoff]]);
        $sess = $DB->affectedRows();
        $DB->delete(self::SESSIONS_TABLE, ['date_start' => null, 'date_creation' => ['<', $cutoff]]);
        $sess += $DB->affectedRows();

        return ['commands' => $cmd, 'sessions' => $sess];
    }

    /**
     * CronTask 'pull_terminal_activity' (registered in hook.php, daily,
     * MODE_EXTERNAL).
     *
     * @return int 1 on success (even if zero rows), 0 only if the very
     *             first endpoint call of the run failed outright.
     */
    public static function cronPull_terminal_activity(CronTask $task): int
    {
        $config    = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
        $retention = PluginIntegrasipamanConfig::clampMonths($config['jumpserver_terminal_retention_months'] ?? '13');
        $backfill  = PluginIntegrasipamanConfig::clampMonths($config['jumpserver_terminal_backfill_months'] ?? '13');

        // Never pull a month we would delete minutes later in the same run.
        $backfill = min($backfill, $retention);

        // v4.0.0: one pull per connection. A connection that is not
        // configured, fails or throws is logged and skipped; the others
        // still run, and the retention prune below runs regardless.
        $connections = PluginIntegrasipamanConnection::all();
        if ($connections === []) {
            $task->log('JumpServer URL / Access Key ID / Access Key Secret / Command Storage ID not configured. Skipping.');
            $task->setVolume(0);
            return 0;
        }

        $several  = count($connections) > 1;
        $inserted = 0;
        $any_ok   = false;

        $runs = PluginIntegrasipamanConnection::each(
            static function (PluginIntegrasipamanConnection $connection) use ($task, $several, $backfill): array {
                if ($several) {
                    $task->log(sprintf('--- connection "%s" (entity %d) ---', $connection->label(), $connection->entity()));
                }
                return self::pullTerminalFor($task, $connection, $backfill);
            }
        );

        foreach ($runs as $run) {
            if ($run['error'] !== '') {
                $task->log(sprintf('Connection "%s": the pull stopped: %s', $run['connection']->label(), $run['error']));
                continue;
            }
            $inserted += $run['result']['inserted'];
            $any_ok    = $any_ok || $run['result']['ok'];
        }

        // Retention is one setting for the whole instance, so one prune.
        $pruned = self::prune($retention);
        $task->log(sprintf(
            'Retention prune (> %d months): %d command rows, %d session rows deleted.',
            $retention,
            $pruned['commands'],
            $pruned['sessions']
        ));

        $task->setVolume($inserted);

        return $any_ok ? 1 : 0;
    }

    /**
     * The terminal pull for one connection, which is the current one.
     *
     * @return array{ok: bool, inserted: int} ok is false when the connection
     *         is not configured, or when its very first endpoint call failed
     *         and nothing at all was imported or skipped - the same rule as
     *         PluginIntegrasipamanCron::cronPull_historical_logs.
     */
    private static function pullTerminalFor(CronTask $task, PluginIntegrasipamanConnection $connection, int $backfill): array
    {
        [$base_url, $access_key_id, $access_key_secret] = $connection->credentials();

        if ($base_url === ''
            || $access_key_id === ''
            || $access_key_secret === ''
            || $connection->get('command_storage_id') === '') {
            $task->log('JumpServer URL / Access Key ID / Access Key Secret / Command Storage ID not configured. Skipping.');
            return ['ok' => false, 'inserted' => 0];
        }

        $months = self::monthsToPull($backfill);
        $task->log(sprintf('Terminal pull: %d month(s) [%s].', count($months), implode(', ', $months)));

        $total_inserted = 0;
        $total_skipped  = 0;
        $first_call_failed = null;

        foreach ($months as $ym) {
            $r = self::pullMonth($ym);

            foreach (['commands', 'sessions'] as $kind) {
                $c = $r[$kind];
                $total_inserted += $c['inserted'];
                $total_skipped  += $c['skipped'];

                if ($first_call_failed === null) {
                    $first_call_failed = !$c['ok'];
                }

                $task->log(sprintf(
                    '  %s %s: %d fetched, %d inserted, %d skipped, %d empty-id, %d null-date%s%s.',
                    $ym, $kind, $c['fetched'], $c['inserted'], $c['skipped'],
                    $c['empty_id'], $c['null_date'],
                    $c['ok'] ? '' : ' (FETCH FAILED)',
                    ($c['ok'] && !$c['complete']) ? ' (INCOMPLETE - re-run front/backfill.php?month=' . $ym . ')' : ''
                ));
            }
        }

        return [
            'ok'       => !($first_call_failed === true && $total_inserted === 0 && $total_skipped === 0),
            'inserted' => $total_inserted,
        ];
    }
}
