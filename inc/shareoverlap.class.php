<?php

/**
 * -------------------------------------------------------------------------
 * integrasipaman - who was joined to a shared session when a command ran
 * -------------------------------------------------------------------------
 *
 * v4.12.0. A non-admin reaches production only by joining a shared session.
 * JumpServer files each command under whoever typed it (VM-checked
 * 2026-11-04), so the command record already names the joiner - what it
 * never says is that the session was someone else's, shared to them. The
 * joins laid over the commands give both: who was joined, and the owner who
 * shared the session.
 *
 * "Joined by" means PRESENT when the command ran: JumpServer does not record
 * who of the people in a session typed a given command.
 *
 * Times: join records carry an explicit offset ("... +0700") and are parsed
 * by PluginIntegrasipamanSessionSharing::describeJoin() into GLPI's timezone;
 * executed_at is already GLPI-local. Both are compared as strtotime() epochs
 * in the same timezone, so JumpServer's own offset does not matter.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanShareOverlap
{
    private const LOGS_TABLE     = 'glpi_plugin_jumpserver_logs';
    private const SESSIONS_TABLE = 'glpi_plugin_jumpserver_sessions';

    /**
     * The successful joins of these sessions the viewer may see, as windows.
     * $restrict = false skips the viewer's entities: the risk evaluator must
     * see every join even when a signed-in request (front/reeval.php) runs it,
     * or the rows it saves would lack the shared-session note for good.
     *
     * @param list<string> $sessionIds
     * @return array<string, list<array{id: string, joiner: string, from: int, to: ?int}>>
     */
    public static function joinsBySession(array $sessionIds, bool $restrict = true): array
    {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('strval', $sessionIds), 'strlen')));
        if ($ids === []) {
            return [];
        }
        $viewer = $restrict ? PluginIntegrasipamanConnection::viewer() : [];

        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['session_jms_id', 'status', 'message', 'source_id', 'date_creation'],
            'FROM'   => self::LOGS_TABLE,
            'WHERE'  => [
                'event_type'     => PluginIntegrasipamanSessionSharing::EVENT_TYPE_JOIN,
                'status'         => 'success',
                'session_jms_id' => $ids,
            ] + $viewer,
        ]) as $row) {
            $rows[] = $row;
        }
        if ($rows === []) {
            return [];
        }

        $ends   = [];
        $owners = [];
        foreach ($DB->request([
            'SELECT' => ['jms_id', 'date_end', 'jms_user'],
            'FROM'   => self::SESSIONS_TABLE,
            'WHERE'  => ['jms_id' => array_values(array_unique(array_column($rows, 'session_jms_id')))] + $viewer,
        ]) as $session) {
            $ends[(string) $session['jms_id']]   = (string) ($session['date_end'] ?? '');
            $owners[(string) $session['jms_id']] = (string) ($session['jms_user'] ?? '');
        }

        return self::windowsFrom($rows, $ends, $owners);
    }

    /**
     * Join windows out of logs rows, oldest join first. Refused joins and
     * joins with no usable time are left out. A join with no date_left ends
     * at its session's date_end, or is open when that is unknown too.
     *
     * @param list<array<string, mixed>> $rows     logs rows (session_jms_id, status, message).
     * @param array<string, string>      $sessionEnds session id => GLPI-local date_end.
     * @param array<string, string>      $owners      session id => session owner, the one who shared it.
     * @return array<string, list<array{id: string, joiner: string, owner: string, from: int, to: ?int}>>
     */
    public static function windowsFrom(array $rows, array $sessionEnds, array $owners = []): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? '') !== 'success') {
                continue;
            }
            $join = PluginIntegrasipamanSessionSharing::describeJoin($row);
            $sid  = (string) ($row['session_jms_id'] ?? '') ?: $join['session_id'];
            $from = self::ts($join['joined_at']);
            if ($sid === '' || $from === null || !$join['succeeded']) {
                continue;
            }
            $to = self::ts($join['left_at']) ?? self::ts($sessionEnds[$sid] ?? '');

            $out[$sid][] = [
                'id' => $join['id'], 'joiner' => $join['joiner'], 'owner' => (string) ($owners[$sid] ?? ''),
                'from' => $from, 'to' => $to,
            ];
        }
        foreach ($out as &$windows) {
            usort($windows, static fn($a, $b) => $a['from'] <=> $b['from']);
        }

        return $out;
    }

    /**
     * Who was joined when a command ran: deduplicated, in join order.
     *
     * @param list<array{joiner: string, from: int, to: ?int}> $windows One session's windows.
     * @return list<string>
     */
    public static function joinersAt(array $windows, string $executedAt): array
    {
        $ts = self::ts($executedAt);
        if ($ts === null) {
            return [];
        }

        $out = [];
        foreach ($windows as $w) {
            if (self::inside($w, $ts) && $w['joiner'] !== '' && !in_array($w['joiner'], $out, true)) {
                $out[] = $w['joiner'];
            }
        }

        return $out;
    }

    /**
     * How many of these command times fall inside one join window.
     *
     * @param array{from: int, to: ?int} $window
     * @param list<string>               $executedAt
     */
    public static function countDuring(array $window, array $executedAt): int
    {
        $n = 0;
        foreach ($executedAt as $time) {
            $ts = self::ts($time);
            if ($ts !== null && self::inside($window, $ts)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * For each join, how many high-risk commands ran in its session while it
     * lasted. Refused joins count 0. One query for the commands, one for the
     * session ends of joins JumpServer never closed.
     *
     * @param list<array<string, mixed>> $joins describeJoin() results.
     * @return array<string, int> join id => count.
     */
    public static function highriskCounts(array $joins): array
    {
        global $DB;

        $counts  = [];
        $windows = [];
        foreach ($joins as $join) {
            $counts[$join['id']] = 0;
            $from = self::ts($join['joined_at']);
            if ($join['succeeded'] && $join['session_id'] !== '' && $from !== null) {
                $windows[] = ['id' => $join['id'], 'sid' => $join['session_id'], 'from' => $from, 'to' => self::ts($join['left_at'])];
            }
        }
        if ($windows === []) {
            return $counts;
        }

        $sids  = array_values(array_unique(array_column($windows, 'sid')));
        $times = [];
        foreach ($DB->request([
            'SELECT'     => ['c.session_jms_id', 'c.executed_at'],
            'FROM'       => 'glpi_plugin_jumpserver_commands AS c',
            'INNER JOIN' => ['glpi_plugin_jumpserver_command_eval AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']]],
            'WHERE'      => ['c.session_jms_id' => $sids, 'e.is_high_risk' => 1] + PluginIntegrasipamanConnection::viewer('c'),
        ]) as $row) {
            $times[(string) $row['session_jms_id']][] = (string) $row['executed_at'];
        }

        $open = array_values(array_unique(array_column(array_filter($windows, static fn($w) => $w['to'] === null), 'sid')));
        $ends = [];
        if ($open !== []) {
            foreach ($DB->request([
                'SELECT' => ['jms_id', 'date_end'],
                'FROM'   => self::SESSIONS_TABLE,
                'WHERE'  => ['jms_id' => $open] + PluginIntegrasipamanConnection::viewer(),
            ]) as $session) {
                $ends[(string) $session['jms_id']] = self::ts((string) ($session['date_end'] ?? ''));
            }
        }

        foreach ($windows as $w) {
            $w['to'] ??= $ends[$w['sid']] ?? null;
            $counts[$w['id']] = self::countDuring($w, $times[$w['sid']] ?? []);
        }

        return $counts;
    }

    private static function inside(array $window, int $ts): bool
    {
        return $window['from'] <= $ts && ($window['to'] === null || $ts <= $window['to']);
    }

    private static function ts(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);

        return ($ts === false || $ts <= 0) ? null : $ts;
    }
}
