<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/shareoverlap_test.php
//
// v4.12.0: who was joined to a shared session when a command ran in it.
//
// JumpServer records every command of a shared session under the session
// OWNER. A non-admin reaches production only by joining someone's shared
// session, so without this the high-risk list never names them. What is
// pinned here:
//   - the window is [date_joined, date_left], bounds included;
//   - a join with no date_left ends at the session's date_end, and only when
//     that is unknown too is it open-ended;
//   - a refused join is never presence;
//   - both sides are compared on one clock: JumpServer's offset string and
//     the GLPI-local executed_at resolve to the same instant.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
function __($s, $d = null) { return $s; }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower((string) $s); } }
date_default_timezone_set('UTC');

$_SESSION['glpiactiveentities'] = [0 => 0];
class FakeDB {
    public array $requests = [];
    public array $results  = [];
    public function request(array $q) { $this->requests[] = $q; return array_shift($this->results) ?? []; }
}
$DB = new FakeDB();

require __DIR__ . '/../inc/sessionsharing.class.php';
require __DIR__ . '/../inc/shareoverlap.class.php';
$O = 'PluginIntegrasipamanShareOverlap';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

function joinRow(string $who, string $joined, string $left, bool $ok = true, string $sid = 'S1'): array
{
    return [
        'session_jms_id' => $sid,
        'status'         => $ok ? 'success' : 'failed',
        'message'        => json_encode([
            'joiner' => ['name' => $who], 'session' => $sid, 'is_success' => $ok,
            'date_joined' => $joined, 'date_left' => $left,
        ]),
    ];
}

// --- joinersAt ----------------------------------------------------------
$w = $O::windowsFrom([joinRow('Bob Example', '2026/11/02 14:15:14 +0000', '2026/11/02 14:57:19 +0000')], []);
check(array_keys($w) === ['S1'], 'windows are keyed by session id');
check($O::joinersAt($w['S1'], '2026-11-02 14:20:00') === ['Bob Example'], 'a command inside the join names the joiner');
check($O::joinersAt($w['S1'], '2026-11-02 14:57:20') === [], 'a second after leaving, nobody');
check($O::joinersAt($w['S1'], '2026-11-02 14:15:14') === ['Bob Example'], 'the join instant is included');
check($O::joinersAt($w['S1'], '2026-11-02 14:57:19') === ['Bob Example'], 'the leave instant is included');
check($O::joinersAt($w['S1'], '') === [], 'a command with no time matches nobody');

// --- open joins ---------------------------------------------------------
$open = $O::windowsFrom([joinRow('A', '2026/11/02 10:00:00 +0000', '')], []);
check($O::joinersAt($open['S1'], '2026-11-03 10:00:00') === ['A'], 'open_join_unbounded: no date_left and no session end');
$ended = $O::windowsFrom([joinRow('A', '2026/11/02 10:00:00 +0000', '')], ['S1' => '2026-11-02 11:00:00']);
check($O::joinersAt($ended['S1'], '2026-11-02 10:30:00') === ['A'], 'open_join_uses_session_end: inside');
check($O::joinersAt($ended['S1'], '2026-11-02 11:00:01') === [], 'open_join_uses_session_end: after the session ended');

// --- several joiners, refusals ------------------------------------------
$many = $O::windowsFrom([
    joinRow('A', '2026/11/02 10:00:00 +0000', '2026/11/02 12:00:00 +0000'),
    joinRow('B', '2026/11/02 10:30:00 +0000', '2026/11/02 11:00:00 +0000'),
    joinRow('A', '2026/11/02 10:40:00 +0000', '2026/11/02 10:50:00 +0000'),
    joinRow('R', '2026/11/02 10:00:00 +0000', '2026/11/02 12:00:00 +0000', false),
], []);
check($O::joinersAt($many['S1'], '2026-11-02 10:45:00') === ['A', 'B'], 'overlapping joiners, deduplicated, in join order');
check(!in_array('R', $O::joinersAt($many['S1'], '2026-11-02 10:45:00'), true), 'a refused join is never presence');

// --- one clock -----------------------------------------------------------
$tz = $O::windowsFrom([joinRow('A', '2026/11/02 14:15:14 +0700', '2026/11/02 14:57:19 +0700')], []);
check($O::joinersAt($tz['S1'], '2026-11-02 07:20:00') === ['A'], 'window_uses_same_clock: +0700 join vs GLPI-local (UTC here) command');
check($O::joinersAt($tz['S1'], '2026-11-02 14:20:00') === [], 'window_uses_same_clock: the raw local hour is NOT matched');

// --- countDuring ---------------------------------------------------------
$win = $w['S1'][0];
check($O::countDuring($win, ['2026-11-02 14:20:00', '2026-11-02 15:10:00', '2026-11-02 14:16:00']) === 2,
    'countDuring counts only the commands inside one join');

// --- joinsBySession ------------------------------------------------------
check($O::joinsBySession([]) === [] && $DB->requests === [], 'no session ids, no query');
$DB->results = [[joinRow('A', '2026/11/02 10:00:00 +0000', '')], [['jms_id' => 'S1', 'date_end' => '2026-11-02 11:00:00']]];
$got = $O::joinsBySession(['S1', 'S1']);
$q   = $DB->requests[0];
check($q['WHERE']['event_type'] === 'session_join' && $q['WHERE']['status'] === 'success'
    && $q['WHERE']['session_jms_id'] === ['S1'] && PluginIntegrasipamanConnection::viewer() !== [] && array_intersect_key($q['WHERE'], PluginIntegrasipamanConnection::viewer()) === PluginIntegrasipamanConnection::viewer(),
    'joinsBySession: successful joins of those sessions, viewer-restricted');
check($O::joinersAt($got['S1'], '2026-11-02 11:30:00') === [], 'joinsBySession closes an open join at the session end');

// --- highriskCounts: Who joined -> how many high-risk commands ran during it
$S = 'PluginIntegrasipamanSessionSharing';
$jA = $S::describeJoin(joinRow('A', '2026/11/02 14:15:14 +0000', '2026/11/02 14:57:19 +0000') + ['source_id' => 'j1']);
$jR = $S::describeJoin(joinRow('R', '2026/11/02 14:15:14 +0000', '2026/11/02 14:57:19 +0000', false) + ['source_id' => 'j2']);
$jO = $S::describeJoin(joinRow('O', '2026/11/02 14:00:00 +0000', '', true, 'S2') + ['source_id' => 'j3']);
$DB->requests = [];
$DB->results  = [[
    ['session_jms_id' => 'S1', 'executed_at' => '2026-11-02 14:20:00'],
    ['session_jms_id' => 'S1', 'executed_at' => '2026-11-02 15:10:00'],
    ['session_jms_id' => 'S2', 'executed_at' => '2026-11-02 14:30:00'],
    ['session_jms_id' => 'S2', 'executed_at' => '2026-11-03 09:00:00'],
], []];
$counts = $O::highriskCounts([$jA, $jR, $jO]);
check(($counts['j1'] ?? null) === 1, 'highriskCounts: only the command inside the join counts');
check(($counts['j2'] ?? null) === 0, 'highriskCounts: a refused join counts 0');
check(($counts['j3'] ?? null) === 2, 'highriskCounts: an open join with no session end counts every later command');
$cq = $DB->requests[0];
check(($cq['WHERE']['e.is_high_risk'] ?? null) === 1 && ($cq['WHERE']['c.session_jms_id'] ?? null) === ['S1', 'S2'],
    'highriskCounts: one query, high-risk only, the joined sessions only');
$DB->requests = [];
check($O::highriskCounts([$jR]) === ['j2' => 0] && $DB->requests === [], 'highriskCounts: no successful join, no query');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
