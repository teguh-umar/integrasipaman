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
     * Fallback lookback window (in seconds) used only on the very first
     * run, when the CronTask has no recorded last run date yet.
     */
    private const INITIAL_LOOKBACK_SECONDS = DAY_TIMESTAMP;

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

        $total_imported = 0;
        $total_failed   = 0;
        $total_skipped  = 0;
        $any_fetch_failed = false;

        // --- Operating logs (general admin/config audit trail) ---------------
        $operating_path = self::OPERATING_LOGS_PATH . '?' . http_build_query(['date_from' => $start_date]);
        $operating_logs = self::signedGet($operating_path, ...$credentials);

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
        $login_path = self::LOGIN_LOGS_PATH . '?' . http_build_query([
            'status'    => '0',
            'date_from' => $start_date,
            'date_to'   => $end_date,
        ]);
        $login_failures = self::signedGet($login_path, ...$credentials);

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
        // set; dedup relies entirely on source_id via importRows() below) ----
        $sharing_entries = self::signedGet(self::SESSION_SHARING_PATH, ...$credentials);

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
            'JumpServer pull complete: %d imported, %d failed, %d already-imported skipped (since %s).',
            $total_imported,
            $total_failed,
            $total_skipped,
            $start_date
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
     * Compute the ISO-8601 start date to request logs from, based on the
     * CronTask's last successful run, falling back to a fixed lookback
     * window on first run.
     *
     * @param CronTask $task The running CronTask instance.
     *
     * @return string Formatted start date (Y-m-d\TH:i:s).
     */
    private static function computeStartDate(CronTask $task): string
    {
        $last_run = $task->fields['lastrun'] ?? null;

        $start_timestamp = !empty($last_run)
            ? strtotime($last_run)
            : (time() - self::INITIAL_LOOKBACK_SECONDS);

        return date('Y-m-d\TH:i:s', $start_timestamp);
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
     * Call the JumpServer REST API and return the decoded "results" list.
     *
     * Authenticates via JumpServer's Access Key HTTP Signature scheme
     * (HMAC-SHA256 request signing), not a plain bearer token - JumpServer
     * has no plain-Token auth mode for this endpoint. Signing logic
     * verified directly against production PAMAN (2026-08-26, HTTP 200 on
     * a live curl test, for all 3 endpoints this class calls): string-to-
     * sign is
     * "(request-target): get {path}\naccept: application/json\ndate: {date}\nx-jms-org: {org}",
     * signed with the Access Key Secret, sent as a "Signature" Authorization
     * header alongside matching literal Date and X-JMS-ORG headers.
     *
     * @param string $api_path          Relative path + query string, exactly as sent on the wire.
     * @param string $base_url          Configured JumpServer base URL/IP.
     * @param string $access_key_id     Configured JumpServer Access Key ID.
     * @param string $access_key_secret Configured JumpServer Access Key Secret.
     * @param string $org_id            Configured JumpServer organization ID (X-JMS-ORG header).
     *
     * @return array<int, mixed>|null Decoded results, or null on failure.
     */
    private static function signedGet(
        string $api_path,
        string $base_url,
        string $access_key_id,
        string $access_key_secret,
        string $org_id
    ): ?array {
        $url = rtrim($base_url, '/') . $api_path;

        // RFC 1123 date, matching the exact format signed into the request
        // (JumpServer validates the Date header against the signature).
        $date_header = gmdate('D, d M Y H:i:s \G\M\T');

        $string_to_sign = "(request-target): get {$api_path}\naccept: application/json\ndate: {$date_header}\nx-jms-org: {$org_id}";
        $signature      = base64_encode(hash_hmac('sha256', $string_to_sign, $access_key_secret, true));

        $authorization = sprintf(
            'Signature keyId="%s",algorithm="hmac-sha256",headers="(request-target) accept date x-jms-org",signature="%s"',
            $access_key_id,
            $signature
        );

        $curl_handle = curl_init();

        curl_setopt_array($curl_handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Date: ' . $date_header,
                'Authorization: ' . $authorization,
                'X-JMS-ORG: ' . $org_id,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response   = curl_exec($curl_handle);
        $http_code  = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl_handle);

        curl_close($curl_handle);

        if ($response === false) {
            trigger_error('JumpServer API cURL error: ' . $curl_error, E_USER_WARNING);
            return null;
        }

        if ($http_code < 200 || $http_code >= 300) {
            trigger_error('JumpServer API returned HTTP ' . $http_code . ' for ' . $api_path, E_USER_WARNING);
            return null;
        }

        $data = json_decode((string) $response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            trigger_error('JumpServer API returned invalid JSON for ' . $api_path, E_USER_WARNING);
            return null;
        }

        // JumpServer's DRF-paginated endpoints wrap the list in "results";
        // fall back to treating the whole payload as the list otherwise.
        return $data['results'] ?? $data;
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
