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
            'command'        => (string) ($e['input'] ?? ''),
            'risk_level'     => (int) ($e['risk_level']['value'] ?? 0),
            'risk_label'     => mb_substr((string) ($e['risk_level']['label'] ?? ''), 0, 32),
            'session_jms_id' => mb_substr((string) ($e['session'] ?? ''), 0, 64),
            'remote_addr'    => mb_substr((string) ($e['remote_addr'] ?? ''), 0, 45),
        ];
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
            'duration'       => (int) ($e['duration'] ?? 0),
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
     * for the whole batch, not one query per row - a month can be 150k
     * commands.
     *
     * @param array<int, array<string, mixed>> $rows Mapped rows (must contain $id_col).
     * @return array{inserted: int, skipped: int}
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
                'WHERE'  => [$id_col => array_keys($incoming_ids)],
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

        return ['inserted' => $inserted, 'skipped' => $skipped];
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
     * both, and return per-endpoint counts. Reads its own config.
     *
     * @return array{commands: array{fetched:int,inserted:int,skipped:int,ok:bool}, sessions: array{fetched:int,inserted:int,skipped:int,ok:bool}}
     */
    public static function pullMonth(string $ym): array
    {
        $config            = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
        $base_url          = trim($config['jumpserver_url'] ?? '');
        $access_key_id     = trim($config['jumpserver_access_key_id'] ?? '');
        $access_key_secret = trim($config['jumpserver_access_key_secret'] ?? '');
        $org_id            = trim($config['jumpserver_org_id'] ?? '');
        $storage_id        = trim($config['jumpserver_command_storage_id'] ?? '');

        $blank = ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'ok' => false];
        $result = ['commands' => $blank, 'sessions' => $blank];

        if ($base_url === '' || $access_key_id === '' || $access_key_secret === '') {
            trigger_error('JumpServer URL / Access Key not configured; terminal pull skipped.', E_USER_WARNING);
            return $result;
        }

        [$date_from, $date_to] = self::monthBounds($ym);

        // --- Commands ---------------------------------------------------
        if ($storage_id === '') {
            trigger_error('jumpserver_command_storage_id not configured; command pull skipped.', E_USER_WARNING);
        } else {
            $cmd_path = self::COMMANDS_PATH . '?' . http_build_query([
                'command_storage_id' => $storage_id,
                'date_from'          => $date_from,
                'date_to'            => $date_to,
                'order'              => '-timestamp',
            ]);
            $entries = PluginIntegrasipamanJmsApi::signedGet(
                $cmd_path,
                $base_url,
                $access_key_id,
                $access_key_secret,
                $org_id,
                self::TERMINAL_PAGE_SIZE,
                self::TERMINAL_MAX_PAGES
            );

            if ($entries === null) {
                $result['commands'] = $blank; // ok stays false
            } else {
                $rows = array_map([self::class, 'mapCommandEntry'], array_filter($entries, 'is_array'));
                $counts = self::insertDedup(self::COMMANDS_TABLE, 'jms_id', $rows);
                $result['commands'] = [
                    'fetched'  => count($rows),
                    'inserted' => $counts['inserted'],
                    'skipped'  => $counts['skipped'],
                    'ok'       => true,
                ];
            }
        }

        // --- Sessions --------------------------------------------------
        $sess_path = self::SESSIONS_PATH . '?' . http_build_query([
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'order'     => '-date_start',
        ]);
        $entries = PluginIntegrasipamanJmsApi::signedGet(
            $sess_path,
            $base_url,
            $access_key_id,
            $access_key_secret,
            $org_id,
            self::TERMINAL_PAGE_SIZE,
            self::TERMINAL_MAX_PAGES
        );

        if ($entries !== null) {
            $rows = array_map([self::class, 'mapSessionEntry'], array_filter($entries, 'is_array'));
            $counts = self::insertDedup(self::SESSIONS_TABLE, 'jms_id', $rows);
            $result['sessions'] = [
                'fetched'  => count($rows),
                'inserted' => $counts['inserted'],
                'skipped'  => $counts['skipped'],
                'ok'       => true,
            ];
        }

        return $result;
    }

    /**
     * Public entry for a single-month pull (front/backfill.php). Validates
     * the month string; the caller is responsible for higher-level checks
     * (not in the future, within retention).
     *
     * @throws InvalidArgumentException on a malformed $ym
     * @return array same shape as pullMonth()
     */
    public static function backfillMonth(string $ym): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
            throw new InvalidArgumentException('Month must be YYYY-MM');
        }

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
     * The list of "YYYY-MM" months a cron run should (re-)pull.
     *
     * First run (both tables empty): the last $backfill_months calendar
     * months, oldest first. Every later run: last month + this month, so a
     * daily run keeps re-scanning the previous month until it rolls off -
     * catching command records JumpServer flushed late. jms_id dedup makes
     * the re-scan cheap.
     *
     * @return array<int, string>
     */
    private static function monthsToPull(int $backfill_months): array
    {
        global $DB;

        $has_data = $DB->request(['FROM' => self::COMMANDS_TABLE, 'COUNT' => 'c'])->current()['c'] > 0
            || $DB->request(['FROM' => self::SESSIONS_TABLE, 'COUNT' => 'c'])->current()['c'] > 0;

        if (!$has_data) {
            $months = [];
            for ($i = $backfill_months - 1; $i >= 0; $i--) {
                $months[] = date('Y-m', strtotime("first day of -{$i} months"));
            }
            return $months;
        }

        return [
            date('Y-m', strtotime('first day of -1 month')),
            date('Y-m'),
        ];
    }

    /**
     * Delete command rows older than $months by executed_at and session
     * rows older than $months by date_start.
     *
     * @return array{commands: int, sessions: int} rows deleted
     */
    private static function prune(int $months): array
    {
        global $DB;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$months} months"));

        $DB->delete(self::COMMANDS_TABLE, ['executed_at' => ['<', $cutoff]]);
        $cmd = $DB->affectedRows();

        $DB->delete(self::SESSIONS_TABLE, ['date_start' => ['<', $cutoff]]);
        $sess = $DB->affectedRows();

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

        if (trim($config['jumpserver_url'] ?? '') === ''
            || trim($config['jumpserver_access_key_id'] ?? '') === ''
            || trim($config['jumpserver_access_key_secret'] ?? '') === ''
            || trim($config['jumpserver_command_storage_id'] ?? '') === '') {
            $task->log('JumpServer URL / Access Key / Command Storage ID not configured. Skipping.');
            $task->setVolume(0);
            return 0;
        }

        $months = self::monthsToPull($backfill);
        $task->log(sprintf('Terminal pull: %d month(s) [%s].', count($months), implode(', ', $months)));

        $total_inserted = 0;
        $first_call_failed = null;

        foreach ($months as $ym) {
            $r = self::pullMonth($ym);

            foreach (['commands', 'sessions'] as $kind) {
                $c = $r[$kind];
                $total_inserted += $c['inserted'];

                if ($first_call_failed === null) {
                    $first_call_failed = !$c['ok'];
                }

                $task->log(sprintf(
                    '  %s %s: %d fetched, %d inserted, %d skipped%s.',
                    $ym,
                    $kind,
                    $c['fetched'],
                    $c['inserted'],
                    $c['skipped'],
                    $c['ok'] ? '' : ' (FETCH FAILED)'
                ));
            }
        }

        $pruned = self::prune($retention);
        $task->log(sprintf(
            'Retention prune (> %d months): %d command rows, %d session rows deleted.',
            $retention,
            $pruned['commands'],
            $pruned['sessions']
        ));

        $task->setVolume($total_inserted);

        // Hard failure only if the very first endpoint call failed and
        // nothing at all was imported - same rule as pull_historical_logs.
        return ($first_call_failed === true && $total_inserted === 0) ? 0 : 1;
    }
}
