<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Session sharing (v4.2.0): who shared a session, with whom, which session,
 * until when.
 *
 * Built only from what the audit-log pull already stores. A share is one row
 * of glpi_plugin_jumpserver_logs (event_type 'session_share'), and the whole
 * JumpServer record is kept in its `message` column as JSON:
 *   {"id", "verify_code", "expired_time" (minutes), "users_display": [...],
 *    "session": {"id"}, "creator": {"id","name"}, "is_active", "date_created",
 *    "created_by"}
 * The asset and the owner of the shared session come from the sessions table
 * the terminal pull fills, joined on the session id: no new API call.
 *
 * Three things this class decides, because the raw row gets each of them wrong:
 *   - WHEN. The row's date_creation is the moment the pull imported it. Two
 *     shares made weeks apart carry the same time if one pull fetched both.
 *     The time of the share is `date_created` in the JSON.
 *   - STATUS. The stored status was worked out once, at import. Whether a share
 *     is still open depends on now, so it is worked out when it is read.
 *   - WHO. An empty `users_display` is a share by link and code, not a share
 *     with nobody: the recipient is whoever holds the link. It is said so
 *     instead of leaving the cell blank, which reads as "no recipient".
 *
 * `verify_code` is the secret that lets a holder join the share. It is never
 * read into the result: not shown, not exported.
 *
 * Reads only the viewer's entities (suite entity rule, point 5).
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/connection.class.php';

class PluginIntegrasipamanSessionSharing
{
    private const LOGS_TABLE     = 'glpi_plugin_jumpserver_logs';
    private const SESSIONS_TABLE = 'glpi_plugin_jumpserver_sessions';

    public const EVENT_TYPE = 'session_share';

    /** Rows read before the page's own filters are applied. */
    public const ROW_LIMIT = 5000;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_INACTIVE = 'inactive';

    /**
     * One share, as the page shows it.
     *
     * @param array<string, mixed>      $row     A logs row (event_type session_share).
     * @param array<string, mixed>|null $session The sessions row of the shared
     *                                           session, when the terminal pull has it.
     * @param int|null                  $now     Unix time; the clock when null.
     * @return array{
     *   id: string, shared_at: string, shared_at_known: bool, shared_by: string,
     *   recipients: list<string>, by_link: bool, session_id: string,
     *   session_asset: string, session_user: string, minutes: int,
     *   expires_at: string, status: string
     * }
     */
    public static function describe(array $row, ?array $session = null, ?int $now = null): array
    {
        $now  = $now ?? time();
        $data = json_decode((string) ($row['message'] ?? ''), true);
        $data = is_array($data) ? $data : [];

        // WHEN: the share's own time; the import time only as a last resort.
        $made      = self::parseTime($data['date_created'] ?? null);
        $known     = $made !== null;
        $shared_ts = $made ?? self::parseTime($row['date_creation'] ?? null);

        // WHO shared: the creator JumpServer names, else what the pull stored.
        $by = trim((string) ($data['created_by'] ?? ''));
        if ($by === '') {
            $by = trim((string) ($data['creator']['name'] ?? ($row['username'] ?? '')));
        }

        // WITH WHOM: the named users. Empty means a link and a code.
        $recipients = [];
        foreach ((array) ($data['users_display'] ?? []) as $name) {
            $name = is_scalar($name) ? trim((string) $name) : '';
            if ($name !== '') {
                $recipients[] = $name;
            }
        }

        // UNTIL: minutes after the share was made, when both are known.
        $minutes = is_numeric($data['expired_time'] ?? null) ? (int) $data['expired_time'] : 0;
        $expires = ($shared_ts !== null && $minutes > 0) ? $shared_ts + $minutes * 60 : null;

        if (empty($data['is_active']) && $data !== []) {
            $status = self::STATUS_INACTIVE;
        } elseif ($expires !== null && $expires < $now) {
            $status = self::STATUS_EXPIRED;
        } else {
            $status = self::STATUS_ACTIVE;
        }

        return [
            'id'              => (string) ($data['id'] ?? ($row['source_id'] ?? '')),
            'shared_at'       => $shared_ts === null ? '' : date('Y-m-d H:i:s', $shared_ts),
            'shared_at_known' => $known,
            'shared_by'       => $by,
            'recipients'      => $recipients,
            'by_link'         => $recipients === [],
            'session_id'      => (string) ($data['session']['id'] ?? ''),
            'session_asset'   => trim((string) ($session['asset'] ?? '')),
            'session_user'    => trim((string) ($session['jms_user'] ?? '')),
            'minutes'         => $minutes,
            'expires_at'      => $expires === null ? '' : date('Y-m-d H:i:s', $expires),
            'status'          => $status,
        ];
    }

    /**
     * A time from JumpServer: ISO 8601 or "2026/08/29 07:24:40 +0700". Null
     * when it is not a time at all.
     */
    private static function parseTime($value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime(trim($value));

        return ($ts === false || $ts <= 0) ? null : $ts;
    }

    /**
     * The shares the viewer may see, newest first.
     *
     * Filtering is done here, after the rows are described, because the time
     * of a share is inside the JSON and not in a column.
     *
     * @param array{from?: string, to?: string, q?: string} $filters from / to are Y-m-d.
     * @return list<array<string, mixed>>
     */
    public static function find(array $filters = [], ?int $now = null): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::LOGS_TABLE,
            'WHERE' => ['event_type' => self::EVENT_TYPE] + PluginIntegrasipamanConnection::viewer(),
            'ORDER' => 'id DESC',
            'LIMIT' => self::ROW_LIMIT,
        ]) as $row) {
            $rows[] = $row;
        }
        if ($rows === []) {
            return [];
        }

        $sessions = self::sessionsFor($rows);

        $from = self::dayStart($filters['from'] ?? '');
        $to   = self::dayEnd($filters['to'] ?? '');
        $q    = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        $out = [];
        foreach ($rows as $row) {
            $session_id = (string) (json_decode((string) ($row['message'] ?? ''), true)['session']['id'] ?? '');
            $share      = self::describe($row, $sessions[$session_id] ?? null, $now);

            $at = $share['shared_at'] === '' ? null : strtotime($share['shared_at']);
            if (($from !== null && ($at === null || $at < $from)) || ($to !== null && ($at === null || $at > $to))) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower(self::searchText($share)), $q)) {
                continue;
            }
            $out[] = $share;
        }

        usort($out, static fn (array $a, array $b): int => strcmp($b['shared_at'], $a['shared_at']));

        return $out;
    }

    /**
     * The sessions rows of the shared sessions, by session id. Restricted to the
     * viewer like everything else: a session of an entity the viewer cannot see
     * leaves the share with an unknown asset, not with another entity's data.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function sessionsFor(array $rows): array
    {
        global $DB;

        $ids = [];
        foreach ($rows as $row) {
            $id = (string) (json_decode((string) ($row['message'] ?? ''), true)['session']['id'] ?? '');
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT' => ['jms_id', 'asset', 'jms_user'],
            'FROM'   => self::SESSIONS_TABLE,
            'WHERE'  => ['jms_id' => array_keys($ids)] + PluginIntegrasipamanConnection::viewer(),
        ]) as $session) {
            $out[(string) $session['jms_id']] = $session;
        }

        return $out;
    }

    /** Everything a text search matches against. */
    private static function searchText(array $share): string
    {
        return implode(' ', array_merge(
            [$share['shared_by'], $share['session_asset'], $share['session_user'], $share['session_id']],
            $share['recipients']
        ));
    }

    /**
     * What the recipient cell says: the names, or that it is a link share.
     */
    public static function recipientsText(array $share): string
    {
        return $share['by_link']
            ? __('Anyone with the link and code', 'integrasipaman')
            : implode(', ', $share['recipients']);
    }

    private static function dayStart(string $date): ?int
    {
        return self::validDay($date) ? (int) strtotime($date . ' 00:00:00') : null;
    }

    private static function dayEnd(string $date): ?int
    {
        return self::validDay($date) ? (int) strtotime($date . ' 23:59:59') : null;
    }

    private static function validDay(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
