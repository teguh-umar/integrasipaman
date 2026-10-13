<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/loginfailures_test.php
//
// v4.3.0: login failures - why a login was refused, lockouts, accounts that keep
// failing. Built from the failed-login records the audit-log pull already stores.
//
// What goes wrong quietly, and is pinned here:
//   - the time shown is the IMPORT time, not the attempt;
//   - a lockout is missed because its wording differs from the keyword list, or
//     an ordinary wrong password is counted as one;
//   - an object (mfa, type) printed as "Array" or an unescaped value in the page;
//   - an account that failed five times is not listed because its name differs
//     only by case;
//   - rows of entities the viewer is not in come back.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
function __($s, $d = null) { return $s; }
function isCommandLine() { return false; }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower((string) $s); } }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
date_default_timezone_set('UTC');

class FakeDB
{
    public array $requests = [];
    public array $logs = [];
    public function request($q) { $this->requests[] = $q; return new ArrayIterator($this->logs); }
}
$DB = new FakeDB();
$_SESSION['glpiactiveentities'] = [6 => 6, 1 => 1];

require __DIR__ . '/../inc/loginfailures.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$L = 'PluginIntegrasipamanLoginFailures';

function failure(string $user, string $reason, string $at, string $ip = '10.0.0.1', array $extra = []): array
{
    return [
        'event_type' => 'user_login_failed', 'username' => $user, 'ip_address' => $ip,
        'date_creation' => '2026-10-12 08:00:00',
        'message' => json_encode($extra + [
            'id' => md5($user . $at), 'username' => $user, 'ip' => $ip, 'city' => 'LAN',
            'reason' => $reason, 'datetime' => $at, 'mfa' => ['value' => 0, 'label' => 'Disabled'],
            'backend' => 'Database',
        ]),
    ];
}

// --- WHEN ---------------------------------------------------------------------
$d = $L::describe(failure('a', 'Username/password check failed', '2026/10/10 07:24:40 +0000'));
check($d['at'] === '2026-10-10 07:24:40' && $d['at_known'] === true, 'the time is the attempt\'s own, not the import time on the row');
$none = failure('a', 'x', '');
$d = $L::describe($none);
check($d['at'] === '2026-10-12 08:00:00' && $d['at_known'] === false, 'with no time from JumpServer the import time is used and said not to be real');

// --- WHY / locked out -----------------------------------------------------------
foreach (['Username/password check failed', 'MFA failed', 'Username does not exist'] as $plain) {
    check($L::describe(failure('a', $plain, '2026/10/10 07:00:00'))['locked'] === false, "\"$plain\" is an ordinary refusal, not a lockout");
}
foreach (['User is blocked', 'Account locked', 'Too many failed attempts', 'Log in frequently and try again later', 'Limit exceeded'] as $lock) {
    check($L::describe(failure('a', $lock, '2026/10/10 07:00:00'))['locked'] === true, "\"$lock\" is a lockout");
}
check($L::isLockout('') === false, 'no reason is not a lockout');
check($L::isLockout('BLOCKED') === true, 'the match ignores case');

$dd = $L::describe(failure('a', 'raw_code', '2026/10/10 07:00:00', '10.0.0.1', ['reason_display' => 'User is blocked']));
check($dd['reason'] === 'User is blocked' && $dd['locked'] === true, 'reason_display, when JumpServer sends it, wins over the raw reason');
check($L::describe(failure('a', '', '2026/10/10 07:00:00'))['reason'] === '', 'a missing reason stays empty');

// --- values that are not plain text ------------------------------------------------
$o = $L::describe(failure('a', 'x', '2026/10/10 07:00:00', '10.0.0.1', ['city' => ['x'], 'mfa' => ['value' => 1, 'label' => 'Enabled']]));
check($o['mfa'] === 'Enabled' && $o['city'] === '', 'an object is read by its label, and a list never becomes "Array"');
$bad = $L::describe(['message' => 'not json', 'username' => 'u', 'ip_address' => '1.2.3.4', 'date_creation' => '2026-10-12 08:00:00']);
check($bad['username'] === 'u' && $bad['ip'] === '1.2.3.4' && $bad['locked'] === false, 'a message that is not JSON falls back on the row without a fatal');

// --- find(): rows, filters, entities ----------------------------------------------
$DB->logs = [
    failure('svc.shared', 'Username/password check failed', '2026/10/10 07:00:00'),
    failure('svc.shared', 'User is blocked', '2026/10/11 09:00:00'),
    failure('Budi', 'Username/password check failed', '2026/10/12 10:00:00', '10.0.0.9'),
];
$all = $L::find([]);
check(count($all) === 3 && $all[0]['username'] === 'Budi', 'newest first, by the attempt\'s own time');
$q = $DB->requests[0];
check($q['WHERE']['event_type'] === 'user_login_failed' && $q['WHERE']['entities_id'] === [6, 1], 'only failed logins, only the viewer\'s entities');
check($q['LIMIT'] === 5000, 'bounded');
check(count($L::find(['only' => 'locked'])) === 1, 'lockouts only');
check(array_column($L::find(['from' => '2026-10-11']), 'username') === ['Budi', 'svc.shared'], 'from: on the attempt\'s own time');
check(count($L::find(['to' => '2026-10-10'])) === 1, 'to: includes the whole last day');
check(count($L::find(['from' => 'junk', 'to' => '2026-13-45'])) === 3, 'a date that is not a date is ignored');
check(count($L::find(['q' => 'BUDI'])) === 1, 'search finds a user in any case');
check(count($L::find(['q' => '10.0.0.9'])) === 1, 'an address');
check(count($L::find(['q' => 'blocked'])) === 1, 'a reason');
check($L::find(['q' => 'nobody']) === [], 'and nothing when nothing matches');

$DB->requests = []; $_SESSION['glpiactiveentities'] = [];
$L::find([]);
check($DB->requests[0]['WHERE']['entities_id'] === [-1], 'no active entity reads nothing');
$_SESSION['glpiactiveentities'] = [6 => 6, 1 => 1];

// --- reasons and repeated failures --------------------------------------------------
$items = $L::find([]);
$by = $L::byReason($items);
check($by[0]['reason'] === 'Username/password check failed' && $by[0]['count'] === 2 && $by[0]['locked'] === false, 'reasons are counted, most frequent first');
check($by[1]['reason'] === 'User is blocked' && $by[1]['locked'] === true, 'and a lockout is marked');

$many = [];
for ($i = 0; $i < 4; $i++) { $many[] = failure('svc.shared', 'Username/password check failed', '2026/10/1' . $i . ' 07:00:00', '10.0.0.' . $i); }
$many[] = failure('svc.shared', 'User is blocked', '2026/10/14 07:00:00', '10.0.0.1');
$many[] = failure('other', 'x', '2026/10/14 07:00:00');
$DB->logs = $many;
$rep = $L::repeated($L::find([]));
check(count($rep) === 1 && $rep[0]['count'] === 5, 'five failures under names that differ only by case are one account');
check($rep[0]['ips'] === 4 && $rep[0]['locked'] === true && $rep[0]['last'] === '2026-10-14 07:00:00', 'with its distinct addresses, last attempt and lockout flag');
check($L::repeated($L::find([]), 6) === [], 'under the threshold, nobody is listed');
check($L::repeated([['username' => '', 'ip' => '', 'at' => '', 'locked' => false]], 1) === [], 'an empty username is never an account');

check($L::countLockouts($DB->logs) === 1, 'the dashboard count agrees with the page on what a lockout is');
check($L::countLockouts([['event_type' => 'session_share', 'message' => '{"reason":"blocked"}']]) === 0, 'and counts only failed logins');

// --- the page -----------------------------------------------------------------------
$page = file_get_contents(__DIR__ . '/../front/loginfailures.php');
check(str_contains($page, 'PluginIntegrasipamanProfile::RIGHT_REPORT, READ'), 'the page needs the report right');
check(strpos($page, "=== 'csv'") < strpos($page, 'Html::header('), 'the CSV is sent before any HTML, from the same list');
check(str_contains($page, 'PluginIntegrasipamanExporter::sendCsv('), 'through the exporter that neutralises spreadsheet formulas');
check(preg_match("/Menu::SECTOR,\s*'integrasipaman',\s*'loginfailures'\s*\);\s*\\\\GlpiPlugin\\\\Pgnsuite\\\\Menu::renderSubNav\('integrasipaman', 'loginfailures'\);/", $page) === 1,
      'it is in the shared sector with its own sub-nav entry');
$bare = preg_match_all("/echo[^;]*\\\$(item|account|reason)\['(at|username|ip|city|reason|last|backend|mfa)'\]\s*\./", str_replace('htmlescape($', 'ESC($', $page));
check($bare === 0, 'no value from JumpServer is concatenated into HTML without htmlescape()');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
