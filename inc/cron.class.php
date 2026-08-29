<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Automatic action (CronTask) that pulls historical audit logs from the
 * JumpServer REST API (Access Key HTTP Signature auth) - the sole source
 * of events into glpi_plugin_jumpserver_logs (JumpServer has no
 * webhook/outbound-event feature to receive from instead). Pulls 3
 * endpoints: operating-logs (general admin/config actions), login-logs
 * (filtered to failures only), and session-sharings.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/jmsapi.class.php';

class PluginIntegrasipamanCron extends CommonDBTM
{
    // JumpServer's real path is "operate-logs" (singular "operate", no
    // "-ing") - confirmed against the JumpServer REST API reference.
    // "operating-logs" 404s even though the UI section is labelled
    // "Operate Logs", which is what led to the original typo here.
    private const OPERATING_LOGS_PATH  = '/api/v1/audits/operate-logs/';
    private const LOGIN_LOGS_PATH      = '/api/v1/audits/login-logs/';
    private const SESSION_SHARING_PATH = '/api/v1/terminal/session-sharings/';

    /**
     * Describe automatic actions provided by this class, used on the
     * GLPI Automatic Actions configuration page.
     *
     * @param string $name Automatic action name.
     *
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        switch ($name) {
            case 'pull_historical_logs':
                return [
                    'description' => 'Pull historical audit logs from the JumpServer REST API',
                ];
        }

        return [];
    }

    /**
     * Automatic action: pull operating-logs, login failures, and session
     * sharing activity from JumpServer into glpi_plugin_jumpserver_logs.
     *
     * @param CronTask $task The running CronTask instance.
     *
     * @return integer 1 on success (logs processed, even if zero), 0 on failure.
     */
    public static function cronPull_historical_logs(CronTask $task): int
    {
        $config            = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
        $base_url          = trim($config['jumpserver_url'] ?? '');
        $access_key_id     = trim($config['jumpserver_access_key_id'] ?? '');
        $access_key_secret = trim($config['jumpserver_access_key_secret'] ?? '');
        $org_id            = trim($config['jumpserver_org_id'] ?? '');

        if ($base_url === '' || $access_key_id === '' || $access_key_secret === '') {
            $task->log('JumpServer URL/IP or Access Key ID/Secret is not configured. Skipping.');
            $task->setVolume(0);
            return 0;
        }

        $start_date = self::computeStartDate($task);
        $end_date   = date('Y-m-d\TH:i:s');

        $credentials = [$base_url, $access_key_id, $access_key_secret, $org_id];

        // null start_date = first run (or first run after a reinstall,
        // since the table survives uninstall now): pull full history.
        $window_label = $start_date !== null ? 'since ' . $start_date : 'full history';

        $total_imported = 0;
        $total_failed   = 0;
        $total_skipped  = 0;
        $any_fetch_failed = false;

        // --- Operating logs (general admin/config audit trail) ---------------
        $operating_query = $start_date !== null ? ['date_from' => $start_date] : [];
        $operating_path  = self::OPERATING_LOGS_PATH
            . ($operating_query !== [] ? '?' . http_build_query($operating_query) : '');
        $operating_logs  = PluginIntegrasipamanJmsApi::signedGet($operating_path, ...$credentials);

        if ($operating_logs === null) {
            $any_fetch_failed = true;
            $task->log('Failed to fetch operating-logs from the JumpServer API.');
        } else {
            $rows = array_map([self::class, 'mapOperatingLogEntry'], array_filter($operating_logs, 'is_array'));
            [$imported, $failed, $skipped] = self::importRows($rows, $task);
            $total_imported += $imported;
            $total_failed   += $failed;
            $total_skipped  += $skipped;
        }

        // --- Login failures only (status=0 per JumpServer's own filter choices,
        // confirmed live: status=0 -> Failed, status=1 -> Success) ------------
        $login_query = ['status' => '0', 'date_to' => $end_date];
        if ($start_date !== null) {
            $login_query['date_from'] = $start_date;
        }
        $login_path     = self::LOGIN_LOGS_PATH . '?' . http_build_query($login_query);
        $login_failures = PluginIntegrasipamanJmsApi::signedGet($login_path, ...$credentials);

        if ($login_failures === null) {
            $any_fetch_failed = true;
            $task->log('Failed to fetch login-logs from the JumpServer API.');
        } else {
            $rows = array_map([self::class, 'mapLoginFailureEntry'], array_filter($login_failures, 'is_array'));
            [$imported, $failed, $skipped] = self::importRows($rows, $task);
            $total_imported += $imported;
            $total_failed   += $failed;
            $total_skipped  += $skipped;
        }

        // --- Session sharing (no date_from/date_to filter on this JumpServer
        // endpoint - confirmed live - so every run re-fetches the full result
        // set, now across all pages; dedup relies entirely on source_id via
        // importRows() below) -----------------------------------------------
        $sharing_entries = PluginIntegrasipamanJmsApi::signedGet(self::SESSION_SHARING_PATH, ...$credentials);

        if ($sharing_entries === null) {
            $any_fetch_failed = true;
            $task->log('Failed to fetch session-sharings from the JumpServer API.');
        } else {
            $rows = array_map([self::class, 'mapSessionSharingEntry'], array_filter($sharing_entries, 'is_array'));
            [$imported, $failed, $skipped] = self::importRows($rows, $task);
            $total_imported += $imported;
            $total_failed   += $failed;
            $total_skipped  += $skipped;
        }

        $task->log(sprintf(
            'JumpServer pull complete: %d imported, %d failed, %d already-imported skipped (%s).',
            $total_imported,
            $total_failed,
            $total_skipped,
            $window_label
        ));

        $task->setVolume($total_imported);

        // Only a total fetch failure across all 3 endpoints counts as a
        // hard failure; a partial fetch failure (e.g. one endpoint down)
        // still imports whatever succeeded and is logged above, matching
        // this plugin's established "never let one JumpServer-side
        // problem block everything else" pattern (see
        // inc/pbiconnection.class.php's per-section failure isolation).
        return ($any_fetch_failed && $total_imported === 0 && $total_skipped === 0) ? 0 : 1;
    }

    /**
     * ISO-8601 start date to request logs from: the CronTask's last
     * successful run for an incremental pull, or null on the first run to
     * request the full available history (no date_from at all).
     *
     * A reinstall lands here with a null lastrun again, but the event
     * table now survives uninstall, so a null-start full pull just re-
     * confirms the rows already present (importRows() skips them by
     * source_id) and fills the gap for the period the plugin was absent -
     * as far back as JumpServer's own audit retention still goes.
     *
     * @param CronTask $task The running CronTask instance.
     *
     * @return string|null Formatted start date (Y-m-d\TH:i:s), or null for a full pull.
     */
    private static function computeStartDate(CronTask $task): ?string
    {
        $last_run = $task->fields['lastrun'] ?? null;

        if (empty($last_run)) {
            return null;
        }

        return date('Y-m-d\TH:i:s', strtotime($last_run));
    }

    /**
     * Insert a batch of already-mapped rows, skipping any whose source_id
     * already exists in the table (see hook.php's source_id column
     * migration for why this matters - it is the only thing preventing
     * duplicate rows from /api/v1/terminal/session-sharings/, which has
     * no date filter to scope it incrementally).
     *
     * @param array<int, array<string, mixed>> $rows Rows produced by a mapXxxEntry() method.
     * @param CronTask                          $task Current CronTask, for logging insert failures.
     *
     * @return array{0: int, 1: int, 2: int} [imported, failed, skipped]
     */
    private static function importRows(array $rows, CronTask $task): array
    {
        global $DB;

        $imported = 0;
        $failed   = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $source_id = $row['source_id'] ?? '';

            if ($source_id !== '' && self::rowExistsForSource($source_id)) {
                $skipped++;
                continue;
            }

            if ($DB->insert('glpi_plugin_jumpserver_logs', $row)) {
                $imported++;
            } else {
                $failed++;
                $task->log(sprintf('Failed to insert log entry: %s', $DB->error()));
            }
        }

        return [$imported, $failed, $skipped];
    }

    /**
     * Whether a row with this JumpServer source_id has already been
     * imported.
     */
    private static function rowExistsForSource(string $source_id): bool
    {
        global $DB;

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_jumpserver_logs',
            'WHERE' => ['source_id' => $source_id],
            'LIMIT' => 1,
        ]);

        return $iterator->count() > 0;
    }

    /**
     * Map a single JumpServer operating-log entry (general admin/config
     * audit trail) to a row for the glpi_plugin_jumpserver_logs table.
     *
     * @param array<string, mixed> $entry Decoded API entry.
     *
     * @return array<string, string>
     */
    private static function mapOperatingLogEntry(array $entry): array
    {
        // "action" is a nested {"value","label"} object (e.g.
        // {"value":"create","label":"Create"}), not a plain string -
        // confirmed against live operate-logs data (2026-08-26).
        $event_type = (string) ($entry['action']['value'] ?? 'unknown');
        $username   = (string) ($entry['user'] ?? '');
        $asset_name = (string) ($entry['resource'] ?? ($entry['asset'] ?? ''));
        $ip_address = (string) ($entry['remote_addr'] ?? '');
        // operate-logs entries have no "status" field at all - they are a
        // general admin/config audit trail (create/update/export/view),
        // not pass/fail attempts, so every entry is simply "success".
        $status     = 'success';

        return [
            'event_type' => mb_substr($event_type, 0, 50),
            'username'   => mb_substr($username, 0, 100),
            'asset_name' => mb_substr($asset_name, 0, 100),
            'ip_address' => mb_substr($ip_address, 0, 45),
            'status'     => mb_substr($status, 0, 20),
            'message'    => (string) json_encode($entry),
            'source_id'  => mb_substr((string) ($entry['id'] ?? ''), 0, 64),
        ];
    }

    /**
     * Map a single failed-login entry from /api/v1/audits/login-logs/
     * (already server-side filtered to status=0/Failed - see
     * cronPull_historical_logs()) to a glpi_plugin_jumpserver_logs row.
     *
     * Verified live response shape (2026-08-26): {"id", "username", "ip",
     * "city", "mfa": {"value","label"}, "reason", "backend",
     * "status": {"value": false, "label": "Failed"}, "datetime": "..."}.
     *
     * @param array<string, mixed> $entry Decoded API entry.
     *
     * @return array<string, string>
     */
    private static function mapLoginFailureEntry(array $entry): array
    {
        $username   = (string) ($entry['username'] ?? '');
        $ip_address = (string) ($entry['ip'] ?? '');

        return [
            'event_type' => 'user_login_failed',
            'username'   => mb_substr($username, 0, 100),
            'asset_name' => '',
            'ip_address' => mb_substr($ip_address, 0, 45),
            'status'     => 'failed',
            'message'    => (string) json_encode($entry),
            'source_id'  => mb_substr((string) ($entry['id'] ?? ''), 0, 64),
        ];
    }

    /**
     * Map a single session-sharing entry from
     * /api/v1/terminal/session-sharings/ to a glpi_plugin_jumpserver_logs
     * row.
     *
     * Verified live response shape (2026-08-26): {"id", "verify_code",
     * "expired_time", "users_display": [...], "session": {"id"},
     * "creator": {"id","name"}, "is_active", "date_created",
     * "created_by"}. No asset/protocol field is available without a
     * separate lookup on "session.id", so asset_name is left blank rather
     * than making a second API call per shared session.
     *
     * @param array<string, mixed> $entry Decoded API entry.
     *
     * @return array<string, string>
     */
    private static function mapSessionSharingEntry(array $entry): array
    {
        $username = (string) ($entry['created_by'] ?? '');

        return [
            'event_type' => 'session_share',
            'username'   => mb_substr($username, 0, 100),
            'asset_name' => '',
            'ip_address' => '',
            'status'     => !empty($entry['is_active']) ? 'success' : 'expired',
            'message'    => (string) json_encode($entry),
            'source_id'  => mb_substr((string) ($entry['id'] ?? ''), 0, 64),
        ];
    }
}
