<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Automatic action (CronTask) that pulls historical audit logs from the
 * JumpServer REST API (Access Key HTTP Signature auth) - the sole source
 * of events into glpi_plugin_jumpserver_logs (JumpServer has no
 * webhook/outbound-event feature to receive from instead). Pulls 4
 * endpoints: operating-logs (general admin/config actions), login-logs
 * (filtered to failures only), session-sharings (share links created) and
 * session-join-records (people joining a shared session).
 *
 * Every row is stamped with the time its event happened on JumpServer, not
 * the time the pull ran - see withEventTime() and the v4.5.0 note in
 * hook.php.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/jmsapi.class.php';
require_once __DIR__ . '/apiwindow.class.php';

require_once __DIR__ . '/serverlabel.class.php';

class PluginIntegrasipamanCron extends CommonDBTM
{
    // JumpServer's real path is "operate-logs" (singular "operate", no
    // "-ing") - confirmed against the JumpServer REST API reference.
    // "operating-logs" 404s even though the UI section is labelled
    // "Operate Logs", which is what led to the original typo here.
    private const OPERATING_LOGS_PATH  = '/api/v1/audits/operate-logs/';
    private const LOGIN_LOGS_PATH      = '/api/v1/audits/login-logs/';
    private const SESSION_SHARING_PATH = '/api/v1/terminal/session-sharings/';

    // Session sharing is TWO endpoints, and conflating them is what made the
    // Session Sharing card read 0 through a period with real activity
    // (root-caused live on 2026-10-19):
    //
    //   session-sharings/      a share LINK that was created. It carries
    //                          verify_code, expired_time and url. An active
    //                          link reused a hundred times never adds a row
    //                          - this instance has had exactly 2 since
    //                          February. Useful as audit evidence of who
    //                          opened a session up; useless as a measure of
    //                          activity.
    //   session-join-records/  someone actually JOINING a shared session.
    //                          1046 rows on the same instance, and what a
    //                          person means when they say a session was
    //                          shared. Carries the joiner, their address and
    //                          whether the join succeeded.
    //
    // Both are pulled. The card counts joins; the links stay for the audit
    // trail. Neither honours date_from/date_to (measured: a one-day filter
    // returned the full 1046), so the window is enforced client-side by
    // fetchWindowed() exactly as it is for operate-logs.
    private const SESSION_JOIN_PATH = '/api/v1/terminal/session-join-records/';

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

            case 'provisioning_scan':
                return [
                    'description' => PluginIntegrasipamanServerLabel::in('Refresh the {server} provisioning queue: eligibility, drift, and assets marked for removal'),
                ];
        }

        return [];
    }

    /**
     * Automatic action: sweep every Computer and bring the provisioning
     * queue up to date.
     *
     * This never writes to PAMAN. It prepares work for a person - who is
     * eligible, what has drifted, what has been disposed of - and nothing
     * more. That is the property that stops a missed cron run from becoming
     * an incident: the worst a skipped run can do is show a stale queue.
     *
     * @return integer 1 when the sweep completed, 0 when it could not run.
     */
    public static function cronProvisioning_scan(CronTask $task): int
    {
        include_once(__DIR__ . '/provisioning.class.php');
        include_once(__DIR__ . '/nodemap.class.php');
        include_once(__DIR__ . '/platformmap.class.php');

        $summary = PluginIntegrasipamanProvisioning::scanAll();

        $task->setVolume($summary['seen']);
        $task->log(sprintf(
            'Scanned %d computers: %d added, %d dropped, %d drifted, %d marked for removal.',
            $summary['seen'],
            $summary['added'],
            $summary['dropped'],
            $summary['drifted'],
            $summary['pending_removal']
        ));

        return 1;
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
        // v4.0.0: one pull per connection, each with its own cursor. A
        // connection that fails - or throws - is logged and skipped; the
        // others still run.
        if (PluginIntegrasipamanConnection::all() === []) {
            $task->log('JumpServer URL/IP or Access Key ID/Secret is not configured. Skipping.');
            $task->setVolume(0);
            return 0;
        }

        $several  = count(PluginIntegrasipamanConnection::all()) > 1;
        $imported = 0;
        $any_ok   = false;

        $runs = PluginIntegrasipamanConnection::each(
            static function (PluginIntegrasipamanConnection $connection) use ($task, $several): array {
                if ($several) {
                    $task->log(sprintf('--- connection "%s" (entity %d) ---', $connection->label(), $connection->entity()));
                }
                return self::pullLogsFor($task, $connection);
            }
        );

        foreach ($runs as $run) {
            if ($run['error'] !== '') {
                $task->log(sprintf('Connection "%s": the pull stopped: %s', $run['connection']->label(), $run['error']));
                continue;
            }
            $imported += $run['result']['imported'];
            $any_ok    = $any_ok || $run['result']['ok'];
        }

        $task->setVolume($imported);

        return $any_ok ? 1 : 0;
    }

    /**
     * The audit-log pull for one connection, which is the current one.
     *
     * @return array{ok: bool, imported: int} ok is false only when the
     *         connection is not configured or every endpoint failed.
     */
    private static function pullLogsFor(CronTask $task, PluginIntegrasipamanConnection $connection): array
    {
        [$base_url, $access_key_id, $access_key_secret, $org_id] = $connection->credentials();

        if ($base_url === '' || $access_key_id === '' || $access_key_secret === '') {
            $task->log('JumpServer URL/IP or Access Key ID/Secret is not configured. Skipping.');
            return ['ok' => false, 'imported' => 0];
        }

        $start_date = self::computeStartDate($task, $connection);
        $run_start  = date('Y-m-d H:i:s');
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
        // date_from is NOT a documented parameter of /audits/operate-logs/
        // (swagger documents the numeric days/days__lt pair instead), and DRF
        // ignores unknown query params, so it may well do nothing. It is kept
        // - free if honoured - and the window is enforced client-side by
        // fetchWindowed() below, which also stops paging as soon as the
        // stream has provably run past $start_date.
        //
        // DO NOT "fix" this by switching to days/days__lt. Probed live against
        // paman.example.com 2026-09-22: both return **HTTP 500** on this
        // endpoint, with and without `order`, so it is the parameter itself
        // and not an interaction. Sending days= would kill every pull.
        //
        // `order` IS honoured (same probe, all three pulled endpoints), so the
        // early stop in PluginIntegrasipamanApiWindow really does engage and
        // this pull is already incremental - runs complete in 0-2 seconds. The
        // filter question is closed; re-probe before reopening it.
        $operating_query = ['order' => '-datetime'];
        if ($start_date !== null) {
            $operating_query['date_from'] = $start_date;
        }
        $operating_path = self::OPERATING_LOGS_PATH . '?' . http_build_query($operating_query);
        $operating_logs = self::fetchWindowed(
            $operating_path, $credentials, 'datetime', $start_date, null, $task, 'operate-logs'
        );

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
        // /audits/login-logs/ documents NO date filter at all (only city, id,
        // ip, mfa, status, type, username, plus limit/offset/order/q - swagger
        // checked 2026-09-21), so date_from/date_to here are almost certainly
        // inert. status=0 IS documented and is confirmed live. Same treatment:
        // send them anyway, enforce the window client-side. There is no days=
        // here to be tempted by, and `order` was confirmed honoured on this
        // endpoint too (probe 2026-09-22), so the early stop works.
        $login_query = ['status' => '0', 'order' => '-datetime', 'date_to' => $end_date];
        if ($start_date !== null) {
            $login_query['date_from'] = $start_date;
        }
        $login_path     = self::LOGIN_LOGS_PATH . '?' . http_build_query($login_query);
        $login_failures = self::fetchWindowed(
            $login_path, $credentials, 'datetime', $start_date, $end_date, $task, 'login-logs'
        );

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

        // --- Session joins: the one-time history (v4.5.0) --------------------
        // A connection that has never been back-filled gets its full join
        // history here, once, automatically. Without it the windowed pull
        // below only ever looks forward from the cursor - and a connection
        // added later inherits the cron task's `lastrun`, not a blank one, so
        // everything older than the moment it was configured would be
        // unreachable with nothing on any screen saying so. That silence is
        // what made the 4.5.0 card read zero for weeks.
        //
        // Runs before the windowed pull so the cursor it sets cannot hide
        // anything, and marks itself done only on a complete fetch: a run cut
        // short by a page failure leaves the marker NULL and is retried on the
        // next pull rather than reported as history anyone can rely on.
        if ($connection->cursor('joins_backfilled') === null) {
            $backfill = self::backfillSessionJoins();

            $total_imported += $backfill['imported'];
            $total_failed   += $backfill['failed'];
            $total_skipped  += $backfill['skipped'];

            if ($backfill['complete']) {
                $connection->setCursor('joins_backfilled', $run_start);
                $task->log(sprintf(
                    'First pull for this connection: back-filled its session-join history '
                    . '(%d fetched, %d imported, %d already present).',
                    $backfill['fetched'],
                    $backfill['imported'],
                    $backfill['skipped']
                ));
            } else {
                $task->log(
                    'The session-join history back-fill did not run to the end; it will be retried '
                    . 'on the next pull. Rows already imported are kept.'
                );
            }
        }

        // --- Session joins (v4.5.0) ------------------------------------------
        // The actual sharing activity: one row each time someone joined a
        // shared session. date_from/date_to are ignored here too - measured
        // live, a 2026-10-16 filter still returned all 1046 rows - so the
        // window is enforced by fetchWindowed() and `order` is what makes the
        // early stop engage. Sent on date_joined, which is the moment that
        // matters; date_created on these entries is the same instant.
        $join_query = ['order' => '-date_joined', 'date_to' => $end_date];
        if ($start_date !== null) {
            $join_query['date_from'] = $start_date;
        }
        $join_path    = self::SESSION_JOIN_PATH . '?' . http_build_query($join_query);
        $join_entries = self::fetchWindowed(
            $join_path, $credentials, 'date_joined', $start_date, $end_date, $task, 'session-join-records'
        );

        if ($join_entries === null) {
            $any_fetch_failed = true;
            $task->log('Failed to fetch session-join-records from the JumpServer API.');
        } else {
            $rows = array_map([self::class, 'mapSessionJoinEntry'], array_filter($join_entries, 'is_array'));
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

        // Only a total fetch failure across all 3 endpoints counts as a
        // hard failure; a partial fetch failure (e.g. one endpoint down)
        // still imports whatever succeeded and is logged above, matching
        // this plugin's established "never let one JumpServer-side
        // problem block everything else" pattern (see
        // inc/pbiconnection.class.php's per-section failure isolation).
        $ok = !($any_fetch_failed && $total_imported === 0 && $total_skipped === 0);

        // The cursor moves under exactly the condition the cron task's own
        // `lastrun` used to: a run that counts as a success. It is the time
        // this run STARTED, so nothing logged while it ran is skipped.
        if ($ok) {
            $connection->setCursor('last_logs_pull', $run_start);
        }

        return ['ok' => $ok, 'imported' => $total_imported];
    }

    /**
     * Page a JumpServer list endpoint, keeping only the entries whose
     * $date_field falls in [$from, $to), and stop paging as soon as the
     * stream has provably run past $from.
     *
     * This is the mitigation for the fact that most of the endpoints this
     * cron pulls do not document any date filter: whatever the server does
     * or does not do with date_from/date_to, the rows that come back from
     * here are inside the window. When the server clearly ignored the filter
     * (out-of-window rows arrived) that is logged once per endpoint, because
     * it is the difference between an incremental pull and re-downloading
     * history every hour.
     *
     * @param array{0:string,1:string,2:string,3:string} $credentials
     *        [base_url, access_key_id, access_key_secret, org_id]
     * @return array<int, mixed>|null Entries inside the window, or null when
     *         the very first page failed (caller treats that as a fetch failure).
     */
    private static function fetchWindowed(
        string $api_path,
        array $credentials,
        string $date_field,
        ?string $from,
        ?string $to,
        CronTask $task,
        string $label
    ): ?array {
        [$base_url, $access_key_id, $access_key_secret, $org_id] = $credentials;

        $window   = new PluginIntegrasipamanApiWindow($date_field, $from, $to);
        $entries  = [];
        $any_page = false;

        $complete = PluginIntegrasipamanJmsApi::signedGetEach(
            $api_path,
            $base_url,
            $access_key_id,
            $access_key_secret,
            $org_id,
            static function (array $page) use (&$entries, &$any_page, $window) {
                $any_page = true;
                foreach ($window->filter($page) as $entry) {
                    $entries[] = $entry;
                }

                return !$window->canStop();
            }
        );

        if (!$any_page && $complete === false) {
            return null; // first page failed outright
        }

        $stats = $window->stats();
        if ($window->serverFilterIgnored()) {
            $task->log(sprintf(
                '%s: JumpServer returned %d row(s) outside the requested window '
                . '(kept %d) - the date filter is not applied server-side on this '
                . 'endpoint, the window was enforced here.',
                $label,
                $stats['dropped'],
                $stats['kept']
            ));
        }
        if ($stats['undated'] > 0) {
            $task->log(sprintf(
                '%s: %d row(s) had no usable "%s" value; imported anyway rather than dropped.',
                $label,
                $stats['undated'],
                $date_field
            ));
        }

        return $entries;
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
    private static function computeStartDate(CronTask $task, PluginIntegrasipamanConnection $connection): ?string
    {
        // v4.0.0: each connection has its own cursor. Until it has completed
        // a pull of its own, it starts from the task's `lastrun` - which for
        // the connection that existed before this release is exactly where
        // it left off, so the upgrade does not re-pull history. A connection
        // added later starts there too, not from the beginning of time; its
        // older history is what the terminal back-fill is for.
        $last_run = $connection->cursor('last_logs_pull') ?? ($task->fields['lastrun'] ?? null);

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
    private static function importRows(array $rows, ?CronTask $task = null): array
    {
        global $DB;

        $imported = 0;
        $failed   = 0;
        $skipped  = 0;

        // v3.11.0: every row carries its connection and that connection's
        // entity. An audit-log entry names no asset this plugin can match.
        $rows = PluginIntegrasipamanConnection::stampRows($rows);

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
                // No CronTask when called from the back-fill page, which
                // reports its own totals on screen instead.
                if ($task !== null) {
                    $task->log(sprintf('Failed to insert log entry: %s', $DB->error()));
                }
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
            // Within this connection: another JumpServer may use the same id.
            'WHERE' => ['source_id' => $source_id] + PluginIntegrasipamanConnection::ownRows(),
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

        return self::withEventTime([
            'event_type' => mb_substr($event_type, 0, 50),
            'username'   => mb_substr($username, 0, 100),
            'asset_name' => mb_substr($asset_name, 0, 100),
            'ip_address' => mb_substr($ip_address, 0, 45),
            'status'     => mb_substr($status, 0, 20),
            'message'    => (string) json_encode($entry),
            'source_id'  => mb_substr((string) ($entry['id'] ?? ''), 0, 64),
        ], $entry['datetime'] ?? null);
    }

    /**
     * Stamp a mapped row with the time its event happened on JumpServer
     * (v4.5.0). Converted out of JumpServer's +0800 into GLPI's timezone by
     * PluginIntegrasipamanApiWindow::sqlTime().
     *
     * When the entry carries no usable time the key is left off entirely, so
     * the column's DEFAULT CURRENT_TIMESTAMP stands in and the row still
     * answers date filters - an undated row that fell outside every window
     * would be evidence quietly lost, which is worse than an approximate
     * timestamp. The exact import time is kept in `date_import` either way,
     * so the two can always be told apart afterwards.
     *
     * @param array<string, mixed> $row Mapped row, about to be inserted.
     * @param mixed                $raw The entry's own timestamp value.
     *
     * @return array<string, mixed>
     */
    private static function withEventTime(array $row, $raw): array
    {
        $when = PluginIntegrasipamanApiWindow::sqlTime(is_scalar($raw) ? (string) $raw : null);

        if ($when !== null) {
            $row['date_creation'] = $when;
        }

        return $row;
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

        return self::withEventTime([
            'event_type' => 'user_login_failed',
            'username'   => mb_substr($username, 0, 100),
            'asset_name' => '',
            'ip_address' => mb_substr($ip_address, 0, 45),
            'status'     => 'failed',
            'message'    => (string) json_encode($entry),
            'source_id'  => mb_substr((string) ($entry['id'] ?? ''), 0, 64),
        ], $entry['datetime'] ?? null);
    }

    /**
     * Map a single session-sharing entry from
     * /api/v1/terminal/session-sharings/ to a glpi_plugin_jumpserver_logs
     * row.
     *
     * This is the creation of a share LINK, not a join. See the comment on
     * SESSION_JOIN_PATH for why that distinction is the whole point.
     *
     * Verified live response shape (re-checked 2026-10-19): {"id",
     * "verify_code", "expired_time", "users_display": [...], "origin", "url",
     * "session": {"id"}, "creator": {"id","name"}, "org_id", "org_name",
     * "is_active", "date_created", "date_updated", "created_by"}. The
     * "origin", "url", "org_id", "org_name" and "date_updated" fields are
     * newer than the 2026-08-26 shape this docblock first recorded. No
     * asset/protocol field is available without a separate lookup on
     * "session.id", so asset_name is left blank rather than making a second
     * API call per shared session.
     *
     * @param array<string, mixed> $entry Decoded API entry.
     *
     * @return array<string, string>
     */
    private static function mapSessionSharingEntry(array $entry): array
    {
        $username = (string) ($entry['created_by'] ?? '');

        return self::withEventTime([
            'event_type' => 'session_share',
            'username'   => mb_substr($username, 0, 100),
            'asset_name' => '',
            'ip_address' => '',
            'status'     => !empty($entry['is_active']) ? 'success' : 'expired',
            'message'    => (string) json_encode($entry),
            'source_id'  => mb_substr((string) ($entry['id'] ?? ''), 0, 64),
        ], $entry['date_created'] ?? null);
    }

    /**
     * Import every session-join record JumpServer still holds, ignoring the
     * cursor (v4.5.0). One-shot, run by hand from front/backfilljoins.php.
     *
     * Why this exists: the hourly pull only reaches back to the cursor, so
     * the day this endpoint was first pulled, everything older than that
     * moment was out of reach forever. On this instance that was 1046 joins
     * - the entire history of the feature, including the activity that
     * started this whole investigation. A back-fill is the only way to get
     * them, and it is cheap precisely because of the trait that makes the
     * normal pull awkward: the endpoint ignores date filters and hands back
     * everything, 100 rows a page.
     *
     * Safe to run twice. importRows() skips any source_id already present,
     * so a second run imports nothing and reports it as skipped.
     *
     * Runs against the CURRENT connection, which the caller chooses.
     *
     * @return array{imported:int, failed:int, skipped:int, fetched:int, complete:bool}
     *         complete is false when paging was cut short (a page failed, or
     *         MAX_PAGES was reached) - the counts are then a partial result
     *         and the caller must say so rather than reporting success.
     */
    public static function backfillSessionJoins(): array
    {
        $connection = PluginIntegrasipamanConnection::current();

        if ($connection === null) {
            return ['imported' => 0, 'failed' => 0, 'skipped' => 0, 'fetched' => 0, 'complete' => false];
        }

        [$base_url, $access_key_id, $access_key_secret, $org_id] = $connection->credentials();

        if ($base_url === '' || $access_key_id === '' || $access_key_secret === '') {
            return ['imported' => 0, 'failed' => 0, 'skipped' => 0, 'fetched' => 0, 'complete' => false];
        }

        $imported = 0;
        $failed   = 0;
        $skipped  = 0;
        $fetched  = 0;

        // Page-at-a-time, importing each page before fetching the next, so a
        // long history never sits in memory all at once and an interrupted
        // run still leaves everything it had already imported.
        $complete = PluginIntegrasipamanJmsApi::signedGetEach(
            self::SESSION_JOIN_PATH,
            $base_url,
            $access_key_id,
            $access_key_secret,
            $org_id,
            static function (array $page) use (&$imported, &$failed, &$skipped, &$fetched): void {
                $fetched += count($page);
                $rows     = array_map([self::class, 'mapSessionJoinEntry'], array_filter($page, 'is_array'));
                [$i, $f, $s] = self::importRows($rows);
                $imported += $i;
                $failed   += $f;
                $skipped  += $s;
            }
        );

        return [
            'imported' => $imported,
            'failed'   => $failed,
            'skipped'  => $skipped,
            'fetched'  => $fetched,
            'complete' => $complete,
        ];
    }

    /**
     * Map one entry from /api/v1/terminal/session-join-records/ - someone
     * joining a session that was shared with them (v4.5.0).
     *
     * Verified live response shape (2026-10-19): {"id", "joiner_display",
     * "verify_code", "remote_addr", "login_from": {"value","label"},
     * "reason", "action_permission", "session", "sharing", "joiner":
     * {"id","name"}, "org_id", "org_name", "is_success", "is_finished",
     * "date_joined", "date_left", "date_created", "date_updated",
     * "created_by"}.
     *
     * Two field choices worth stating, because the obvious reading of each is
     * the wrong one:
     *
     *   username  is the JOINER, not `created_by`. On these entries
     *             `created_by` is the terminal component that wrote the
     *             record (e.g. "[Terminal]-PAM-SERVER-jms_terminal-kB"), which is
     *             machinery, not a person. The person is `joiner.name`.
     *   status    'failed' covers a refused join as well as a failed one.
     *             `reason` says which, and it stays readable in the stored
     *             JSON rather than being flattened into a 20-character
     *             column.
     *
     * `verify_code` is the secret that lets a holder into the session. It
     * arrives inside the entry and is therefore stored with it, exactly as
     * for share links; no screen or export in this plugin reads it.
     *
     * @param array<string, mixed> $entry Decoded API entry.
     *
     * @return array<string, string>
     */
    private static function mapSessionJoinEntry(array $entry): array
    {
        $joiner = trim((string) ($entry['joiner']['name'] ?? ''));
        if ($joiner === '') {
            $joiner = trim((string) ($entry['joiner_display'] ?? ''));
        }

        return self::withEventTime([
            'event_type' => 'session_join',
            'username'   => mb_substr($joiner, 0, 100),
            'asset_name' => '',
            'ip_address' => mb_substr((string) ($entry['remote_addr'] ?? ''), 0, 45),
            'status'     => !empty($entry['is_success']) ? 'success' : 'failed',
            'message'    => (string) json_encode($entry),
            'source_id'  => mb_substr((string) ($entry['id'] ?? ''), 0, 64),
        ], $entry['date_joined'] ?? null);
    }
}
