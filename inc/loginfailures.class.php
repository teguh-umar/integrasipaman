<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Login failures (v4.3.0): why a login was refused, which accounts were locked
 * out, and which accounts keep failing.
 *
 * Built only from what the audit-log pull already stores. A failed login is one
 * row of glpi_plugin_jumpserver_logs (event_type 'user_login_failed'), and the
 * whole JumpServer record is kept in its `message` column as JSON:
 *   {"id", "username", "ip", "city", "mfa": {"value","label"}, "reason",
 *    "reason_display", "backend", "status": {...}, "datetime", "type": {...},
 *    "user_agent"}
 *
 * Three things this class decides, because the raw row gets each wrong:
 *   - WHEN. The row's date_creation is the moment the pull imported it. The
 *     time of the login attempt is `datetime` in the JSON.
 *   - WHY. JumpServer gives the reason as text, and as `reason_display` on some
 *     versions. Both are read; the readable one wins.
 *   - LOCKED OUT. JumpServer has no flag for "too many attempts": it is a
 *     reason. Which reason texts mean that is a keyword list (LOCKOUT_WORDS).
 *     It was written without a sample of the production reason texts, so the
 *     page also lists EVERY reason with its count: a lockout worded in a way
 *     the list does not know shows up there, and the list is one line to fix.
 *
 * The count of repeated failures does not depend on any wording: it is the same
 * username failing REPEAT_THRESHOLD times or more in the selection.
 *
 * Reads only the viewer's entities (suite entity rule, point 5).
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/connection.class.php';

class PluginIntegrasipamanLoginFailures
{
    private const LOGS_TABLE = 'glpi_plugin_jumpserver_logs';

    public const EVENT_TYPE = 'user_login_failed';

    /** Rows read before the page's own filters are applied. */
    public const ROW_LIMIT = 5000;

    /** The same username failing this many times in the selection is "repeated". */
    public const REPEAT_THRESHOLD = 5;

    /**
     * Words that, in a reason text, mean the account was refused because of
     * the number or the pace of attempts (blocked, locked, too many, too
     * frequent). Lower case; matched as substrings.
     */
    public const LOCKOUT_WORDS = ['block', 'lock', 'too many', 'frequent', 'try again later', 'exceed'];

    /**
     * One failed login, as the page shows it.
     *
     * @param array<string, mixed> $row A logs row (event_type user_login_failed).
     * @return array{
     *   at: string, at_known: bool, username: string, ip: string, city: string,
     *   reason: string, backend: string, mfa: string, locked: bool
     * }
     */
    public static function describe(array $row): array
    {
        $data = json_decode((string) ($row['message'] ?? ''), true);
        $data = is_array($data) ? $data : [];

        $made = self::parseTime($data['datetime'] ?? null);
        $ts   = $made ?? self::parseTime($row['date_creation'] ?? null);

        $reason = self::text($data['reason_display'] ?? null);
        if ($reason === '') {
            $reason = self::text($data['reason'] ?? null);
        }

        $username = self::text($data['username'] ?? null);
        if ($username === '') {
            $username = trim((string) ($row['username'] ?? ''));
        }
        $ip = self::text($data['ip'] ?? null);
        if ($ip === '') {
            $ip = trim((string) ($row['ip_address'] ?? ''));
        }

        return [
            'at'       => $ts === null ? '' : date('Y-m-d H:i:s', $ts),
            'at_known' => $made !== null,
            'username' => $username,
            'ip'       => $ip,
            'city'     => self::text($data['city'] ?? null),
            'reason'   => $reason,
            'backend'  => self::text($data['backend_display'] ?? ($data['backend'] ?? null)),
            'mfa'      => self::text($data['mfa'] ?? null),
            'locked'   => self::isLockout($reason),
        ];
    }

    /** Whether a reason text means "refused because of too many / too fast attempts". */
    public static function isLockout(string $reason): bool
    {
        // "(the account will be temporarily locked for 10 minutes)" is the warning that
        // rides on every wrong-password message, not a lockout. Only a refusal that says
        // the account IS or HAS BEEN locked counts.
        $reason = preg_replace('/will be (temporarily )?(locked|blocked)/', '', mb_strtolower($reason)) ?? '';
        if (trim($reason) === '') {
            return false;
        }
        foreach (self::LOCKOUT_WORDS as $word) {
            if (str_contains($reason, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A text from JumpServer: a string, or an object that carries its text as
     * `label` / `display` (the shape of `mfa` and `type`). Never an array into
     * a cell.
     */
    private static function text($value): string
    {
        if (is_array($value)) {
            $value = $value['label'] ?? ($value['display'] ?? '');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function parseTime($value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime(trim($value));

        return ($ts === false || $ts <= 0) ? null : $ts;
    }

    /**
     * The failed logins the viewer may see, newest first.
     *
     * @param array{from?: string, to?: string, q?: string, only?: string} $filters
     *        from / to are Y-m-d; only = 'locked' keeps lockouts.
     * @return list<array<string, mixed>>
     */
    public static function find(array $filters = []): array
    {
        global $DB;

        $from = self::dayStart($filters['from'] ?? '');
        $to   = self::dayEnd($filters['to'] ?? '');
        $q    = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $only = ($filters['only'] ?? '') === 'locked';
        $reason_key = isset($filters['reason']) && $filters['reason'] !== '' ? self::reasonKey((string) $filters['reason']) : null;

        $out = [];
        foreach ($DB->request([
            'FROM'  => self::LOGS_TABLE,
            'WHERE' => ['event_type' => self::EVENT_TYPE] + PluginIntegrasipamanConnection::viewer(),
            'ORDER' => 'id DESC',
            'LIMIT' => self::ROW_LIMIT,
        ]) as $row) {
            $item = self::describe($row);
            $at   = $item['at'] === '' ? null : strtotime($item['at']);

            if (($from !== null && ($at === null || $at < $from)) || ($to !== null && ($at === null || $at > $to))) {
                continue;
            }
            if ($only && !$item['locked']) {
                continue;
            }
            if ($reason_key !== null && self::reasonKey($item['reason']) !== $reason_key) {
                continue;
            }
            if ($q !== '' && !str_contains(mb_strtolower(implode(' ', [$item['username'], $item['ip'], $item['city'], $item['reason']])), $q)) {
                continue;
            }
            $out[] = $item;
        }

        usort($out, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return $out;
    }

    /**
     * Every reason with how often it occurs, most frequent first. Reasons are
     * grouped case-insensitively; an empty reason is "unknown".
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{reason: string, count: int, locked: bool}>
     */
    public static function byReason(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = self::reasonKey($item['reason']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['reason' => preg_replace('/\d+/', 'N', $item['reason']) ?? $item['reason'], 'key' => $key, 'count' => 0, 'locked' => $item['locked'], 'who' => []];
            }
            $groups[$key]['count']++;
            if ($item['username'] !== '') {
                $u = mb_strtolower($item['username']);
                $groups[$key]['who'][$u] = ['username' => $groups[$key]['who'][$u]['username'] ?? $item['username'], 'count' => ($groups[$key]['who'][$u]['count'] ?? 0) + 1];
            }
        }
        $list = [];
        foreach ($groups as $g) {
            $who = array_values($g['who']);
            usort($who, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $g['who'] = $who;
            $list[]   = $g;
        }
        usort($list, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $list;
    }

    /**
     * What makes two reasons "the same": the text without its numbers, so "You have 5
     * more attempts" and "You have 4 more attempts" are one reason, not five.
     */
    public static function reasonKey(string $reason): string
    {
        return mb_strtolower(preg_replace('/\d+/', 'N', trim($reason)) ?? '');
    }

    /**
     * Usernames that failed REPEAT_THRESHOLD times or more, most failures first,
     * with the number of distinct addresses they came from and the last attempt.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array{username: string, count: int, ips: int, last: string, locked: bool}>
     */
    public static function repeated(array $items, int $threshold = self::REPEAT_THRESHOLD): array
    {
        $per = [];
        foreach ($items as $item) {
            $key = mb_strtolower($item['username']);
            if ($key === '') {
                continue;
            }
            $per[$key]['username'] = $per[$key]['username'] ?? $item['username'];
            $per[$key]['count']    = ($per[$key]['count'] ?? 0) + 1;
            $per[$key]['ips'][$item['ip']] = true;
            $per[$key]['last']     = max($per[$key]['last'] ?? '', $item['at']);
            $per[$key]['locked']   = ($per[$key]['locked'] ?? false) || $item['locked'];
        }

        $list = [];
        foreach ($per as $p) {
            if ($p['count'] >= $threshold) {
                $list[] = ['username' => $p['username'], 'count' => $p['count'], 'ips' => count($p['ips']), 'last' => $p['last'], 'locked' => $p['locked']];
            }
        }
        usort($list, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $list;
    }

    /**
     * How many of the given log rows are lockouts. Used by the dashboard card,
     * which already holds its window's rows.
     *
     * @param list<array<string, mixed>> $rows logs rows
     */
    public static function countLockouts(array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            if (($row['event_type'] ?? '') === self::EVENT_TYPE && self::describe($row)['locked']) {
                $n++;
            }
        }

        return $n;
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
