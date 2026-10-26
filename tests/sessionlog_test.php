<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/sessionlog_test.php
//
// v4.6.0: the "Log Session" tab - JumpServer's terminal sessions, which is the
// one dataset in this plugin that has a real asset on every row.
//
// It reads `glpi_plugin_jumpserver_sessions`, already filled by
// PluginIntegrasipamanTerminalPull::cronPull_terminal_activity(). Nothing is
// ingested here; this is a read over a table that is already populated.
//
// What can go wrong quietly, and is pinned here:
//   - `duration` is an INT of seconds, but JumpServer shows "H:MM:SS" - a bare
//     print would put "2697" where the user expects "0:44:57", and a bad
//     formatter would silently wrap past 24h;
//   - the date window filtering on `date_creation` (when the row was IMPORTED)
//     instead of `date_start` (when the session actually ran) - the same
//     confusion that made the Session Sharing card read zero;
//   - a row from an entity the viewer cannot see arriving on the page;
//   - a session still running (date_end NULL, is_finished 0) rendered as if it
//     had ended at the epoch;
//   - the text search matching on only some of the columns shown.
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    define('GLPI_ROOT', __DIR__);

    function __($s, $d = null) { return $s; }

    // This PHP build has no mbstring; the VM's does. Same polyfill as
    // sessionsharing_test.php, so the test exercises the production code
    // rather than a rewritten copy of it.
    if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower((string) $s); } }

    class FakeDB
    {
        public array $requests = [];
        public array $rows = [];
        public array $counts = [];
        public function quoteName($n) { return "`$n`"; }
        public function request($q)
        {
            $this->requests[] = $q;
            if (isset($q['COUNT'])) {
                return new ArrayIterator([['cnt' => array_shift($this->counts) ?? 0]]);
            }
            return new ArrayIterator($this->rows);
        }
    }
    $DB = new FakeDB();

    // Scoping is the connection class's job everywhere else in this plugin;
    // the page must go through it rather than rolling its own WHERE.
    class PluginIntegrasipamanConnection
    {
        public static function viewer(): array { return ['entities_id' => [6, 1]]; }
    }

    require __DIR__ . '/../inc/sessionlog.class.php';

    $fails = 0;
    function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

    $S = 'PluginIntegrasipamanSessionLog';

    // A row shaped as PluginIntegrasipamanTerminalPull::mapSessionEntry() writes it,
    // with the values from the user's own JumpServer export (2026-10-22).
    $row = [
        'jms_id'         => 'f2b1c0de-0000-4000-8000-000000000001',
        'jms_user'       => 'Sysadmin Five(sysadmin.five-e)',
        'asset'          => 'VM-STAG-WEBSITE(10.0.6.127)',
        'account'        => 'Manual Input(appuser)',
        'protocol'       => 'ssh',
        'login_from'     => 'WT',
        'remote_addr'    => '10.0.9.51',
        'duration'       => 2697,                     // "0:44:57"
        'command_amount' => 12,
        'is_success'     => 1,
        'is_finished'    => 1,
        'date_start'     => '2026-09-30 20:43:21',
        'date_end'       => '2026-09-30 21:28:08',
        'terminal_name'  => '[Terminal]-PAM-SERVER',
    ];

    // --- duration: seconds in the column, H:MM:SS on the page ------------------------
    check($S::formatDuration(2697) === '0:44:57', '2697 seconds reads as JumpServer shows it: 0:44:57');
    check($S::formatDuration(103) === '0:01:43', 'a short session keeps two-digit minutes and seconds');
    check($S::formatDuration(0) === '0:00:00', 'a zero duration is a time, not an empty cell');
    check($S::formatDuration(86400) === '24:00:00', 'a full day does not wrap back to 0:00:00');
    check($S::formatDuration(93784) === '26:03:04', 'past 24h the hours keep counting rather than rolling over');
    check($S::formatDuration(-5) === '0:00:00', 'a negative duration cannot render as a negative clock');

    // --- describe: what one row becomes on the page ----------------------------------
    $d = $S::describe($row);
    check($d['asset'] === 'VM-STAG-WEBSITE(10.0.6.127)', 'the asset carries its IP, as JumpServer stores it');
    check($d['account'] === 'Manual Input(appuser)', 'the account is the one used on the asset');
    check($d['protocol'] === 'ssh' && $d['login_from'] === 'WT', 'protocol and login source come straight across');
    check($d['duration_text'] === '0:44:57', 'the duration is formatted once, here, not in the template');
    check($d['started'] === '2026-09-30 20:43:21' && $d['ended'] === '2026-09-30 21:28:08', 'both ends of the session');
    check($d['finished'] === true && $d['succeeded'] === true, 'the two flags are booleans, not 1/0 strings');
    check($d['commands'] === 12, 'the command count is an int');

    // A session still running: no end, not finished. It must not read as ended.
    $d = $S::describe(['date_end' => null, 'is_finished' => 0, 'duration' => 0] + $row);
    check($d['ended'] === '' && $d['finished'] === false, 'a running session has no end time rather than a fake one');

    // A failed session is marked, like everywhere else in this plugin.
    $d = $S::describe(['is_success' => 0] + $row);
    check($d['succeeded'] === false, 'a failed session is distinguishable');

    $d = $S::describe([]);
    check($d['asset'] === '' && $d['duration_text'] === '0:00:00', 'an empty row is handled rather than fatal');

    // --- find: the query it builds ---------------------------------------------------
    $DB->rows = [$row];
    $DB->requests = [];
    $out = $S::find([]);
    $q = $DB->requests[0];

    check(count($out) === 1, 'one row in, one described row out');
    check($q['FROM'] === 'glpi_plugin_jumpserver_sessions', 'it reads the sessions table, not the logs table');
    check($q['WHERE']['entities_id'] === [6, 1], 'scoped to the viewer\'s entities, via the connection class');
    check($q['ORDER'] === 'date_start DESC', 'newest session first, by when it RAN');
    check($q['LIMIT'] === $S::ROW_LIMIT, 'and capped, so a wide window cannot pull the table into memory');

    // The window must filter on when the session ran, not on when it was imported.
    $DB->requests = [];
    $S::find(['from' => '2026-09-01', 'to' => '2026-09-30']);
    $where = $DB->requests[0]['WHERE'];
    $flat  = json_encode($where);
    check(str_contains($flat, 'date_start'), 'the date window filters on date_start');
    check(!str_contains($flat, 'date_creation'), 'never on date_creation, which is only when the row was imported');
    check(str_contains($flat, '2026-09-01 00:00:00'), 'the "from" day starts at midnight');
    check(str_contains($flat, '2026-09-30 23:59:59'), 'and the "to" day includes its whole last second');

    // A malformed date is ignored, not passed through.
    $DB->requests = [];
    $S::find(['from' => 'nonsense']);
    check(!str_contains(json_encode($DB->requests[0]['WHERE']), 'date_start'), 'an unparseable date drops the filter');

    // --- the text search covers every column the page shows --------------------------
    $DB->rows = [$row];
    foreach ([
        'VM-STAG'      => 'the asset',
        'appuser'       => 'the account',
        'Sysadmin Five'        => 'the user',
        'ssh'          => 'the protocol',
        '10.0.9'   => 'the remote address',
        'WT'           => 'the login source',
    ] as $needle => $what) {
        check(count($S::find(['q' => $needle])) === 1, "the search matches on $what");
    }
    check(count($S::find(['q' => 'VM-APP-07'])) === 0, 'and misses when nothing matches');
    check(count($S::find(['q' => 'vm-stag'])) === 1, 'the search ignores case');

    // --- the default window ----------------------------------------------------------
    // 39,800 sessions against a 5,000 cap means an unfiltered page is always
    // truncated, and the text search - which runs in PHP over what was read -
    // then covers only the newest slice. Opening on a 30-day window is the
    // user's own fix (2026-10-25), and matches the window the dashboard cards
    // and front/export.php already use.
    $w = $S::defaultWindow();
    check(preg_match('/^\d{4}-\d{2}-\d{2}$/', $w['from']) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w['to']) === 1,
          'the default window is two Y-m-d days, the format the filters parse');
    check($w['to'] === date('Y-m-d'), 'it ends today');
    check($w['from'] === date('Y-m-d', strtotime('-29 days')), 'and covers 30 days including today');
    check(strtotime($w['from']) < strtotime($w['to']), 'from precedes to');

    // It has to survive a round trip through find()'s own parsing, or the
    // page would open on a window its filters then silently drop.
    $DB->rows = [$row];
    $DB->requests = [];
    $S::find($w);
    $flat = json_encode($DB->requests[0]['WHERE']);
    check(str_contains($flat, $w['from'] . ' 00:00:00') && str_contains($flat, $w['to'] . ' 23:59:59'),
          'and find() turns it into a real date window rather than discarding it');

    // --- truncation: the page must not pass a cap off as a total ---------------------
    // Found live on 2026-10-25: the VM has more sessions than ROW_LIMIT, so the
    // summary line read "5000 session(s)" - which is the cap, not a count, and
    // the running/failed figures beside it covered only the fetched slice.
    $DB->rows = [$row];
    $DB->requests = [];
    $meta = null;
    $S::find([], $meta);
    check($meta['fetched'] === 1 && $meta['capped'] === false, 'a short read is not capped');
    check($meta['total'] === 1, 'and its total needs no second query');
    check(count($DB->requests) === 1, 'so an uncapped page costs exactly one query');

    // Fill the fetch to the cap: the page must then learn the real total.
    $DB->rows = array_fill(0, $S::ROW_LIMIT, $row);
    $DB->requests = [];
    $DB->counts = [87654];
    $meta = null;
    $out = $S::find([], $meta);
    check(count($out) === $S::ROW_LIMIT, 'every fetched row is still returned');
    check($meta['capped'] === true, 'a fetch that fills the cap is reported as capped');
    check($meta['total'] === 87654, 'and the true total comes from a COUNT, not from the cap');
    check(count($DB->requests) === 2, 'the COUNT is only paid for when the cap was actually hit');
    $count_q = $DB->requests[1];
    check(isset($count_q['COUNT']), 'the second query is a COUNT');
    check($count_q['FROM'] === 'glpi_plugin_jumpserver_sessions', 'over the same table');
    check($count_q['WHERE']['entities_id'] === [6, 1], 'under the same entity restriction as the rows');

    // The count must share the rows' date window, or the two figures describe
    // different periods - the suite rule that a count comes from the same
    // criteria as its rows.
    $DB->requests = [];
    $DB->counts = [42];
    $S::find(['from' => '2026-09-01'], $meta);
    check(str_contains(json_encode($DB->requests[1]['WHERE']), '2026-09-01 00:00:00'),
          'the COUNT carries the same date window as the rows it is about');

    echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
    exit($fails === 0 ? 0 : 1);
}
