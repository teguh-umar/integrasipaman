<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/sharednote_test.php
//
// v4.13.0: catatan "Shared session".
//
// A non-admin reaches production only through a session someone shared. The
// owner's rule (2026-11-04): a HIGH row - non-admin, production, high-risk -
// whose command ran while a join of its session was live reads the
// configurable note (default "Shared session") INSTEAD of "Butuh Tindak
// Lanjut". Every other branch keeps its note; HIGH outside a join keeps
// "Butuh Tindak Lanjut". The note is stored, not displayed, because the
// Evaluation summary groups on it and the drill-down filters on it.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
if (!function_exists('mb_substr'))     { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
date_default_timezone_set('UTC');

class CommonGLPI {}
class CommonDBTM {}
class QuerySubQuery { public function __construct(public array $q) {} }
class Config {
    public static array $values = [];
    public static array $saved  = [];
    public static function getConfigurationValues($c) { return self::$values; }
    public static function setConfigurationValues($c, $v) { self::$saved = $v; self::$values = $v + self::$values; }
}
class PluginIntegrasipamanCorrelation {
    public static function mapsGeneration(): int { return 1; }
    public static function classFor(string $ip, array $prod, array $dev): string {
        foreach ($prod as $p) { if ($p !== '' && str_starts_with($ip, $p)) { return 'production'; } }
        return '';
    }
}
class FakeDB {
    public array $requests = [];
    public array $updates  = [];
    public array $results  = [];
    public function request($q) { $this->requests[] = $q; return new ArrayIterator(array_shift($this->results) ?? []); }
    public function update($t, $v, $w) { $this->updates[] = [$t, $v, $w]; return true; }
    public function affectedRows() { return 3; }
}
$DB = new FakeDB();

require __DIR__ . '/../inc/riskeval.class.php';
require __DIR__ . '/../inc/sessionsharing.class.php';
require __DIR__ . '/../inc/shareoverlap.class.php';
$E = 'PluginIntegrasipamanRiskEval';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

// --- priorityNote ---------------------------------------------------------
check($E::priorityNote(true, 'production', false, true, 'Shared session') === ['HIGH', 'Shared session'],
    'HIGH (non-admin, production, high-risk) inside a join reads Shared session');
check($E::priorityNote(true, 'production', false, false, 'Shared session') === ['HIGH', 'Akses Langsung Non-Admin'],
    'rc3: HIGH outside a shared session reads Akses Langsung Non-Admin, not Butuh Tindak Lanjut');
check($E::priorityNote(true, 'production', false) === ['HIGH', 'Akses Langsung Non-Admin'],
    'rc3: the default direct-access note applies when none is passed');
check($E::priorityNote(true, 'production', false, false, 'Shared session', 'Pelanggaran Akses') === ['HIGH', 'Pelanggaran Akses'],
    'rc3: the direct-access note is the configured text');
check($E::priorityNote(true, 'production', false, false, 'Shared session', ' ') === ['HIGH', 'Akses Langsung Non-Admin'],
    'rc3: a blank direct-access note falls back to the default');
check($E::priorityNote(true, 'production', true, false, 'S', 'D') === ['MEDIUM', 'Aktivitas Admin'],
    'rc3: the direct-access note never touches an admin row');
check($E::priorityNote(true, 'production', true, true, 'Shared session') === ['MEDIUM', 'Aktivitas Admin'],
    'an admin in a shared session stays MEDIUM / Aktivitas Admin');
check($E::priorityNote(true, 'development', false, true, 'Shared session') === ['LOW', 'Aset Development'],
    'development in a shared session stays LOW');
check($E::priorityNote(true, '', false, true, 'Shared session') === ['MEDIUM', 'Aset Belum Diklasifikasi'],
    'unclassified in a shared session stays MEDIUM');
check($E::priorityNote(false, 'production', false, true, 'Shared session') === ['', ''],
    'not high-risk: no note at all');
check($E::priorityNote(true, 'production', false, true, '  ') === ['HIGH', 'Shared session'],
    'a blank custom note falls back to Shared session');
check($E::priorityNote(true, 'production', false, true, 'Sesi Bersama Tim') === ['HIGH', 'Sesi Bersama Tim'],
    'the note is the configured text');
check(strlen($E::priorityNote(true, 'production', false, true, str_repeat('x', 60))[1]) === 48,
    'a long note is cut to the 48 characters catatan holds');

// --- evaluateRow ----------------------------------------------------------
$rules = [
    'keywords' => ['passwd svc'], 'admins' => [], 'prefixes' => ['10.0.0.'], 'dev_prefixes' => [],
    'categories' => [], 'asset_class' => [], 'user_admin' => [], 'shared_note' => 'Shared session',
];
$cmd = ['id' => 7, 'risk_level' => 4, 'command' => 'passwd svc_example', 'asset' => 'VM-APP-01(10.0.0.9)',
        'jms_user' => 'Bob Example(bob.example)'];
check($E::evaluateRow($cmd + ['shared' => true], $rules, 'h')['catatan'] === 'Shared session',
    'evaluateRow: a command flagged shared gets the note');
check($E::evaluateRow($cmd, $rules, 'h')['catatan'] === 'Akses Langsung Non-Admin',
    'evaluateRow: no shared flag, the direct-access note');
check($E::evaluateRow($cmd, ['direct_note' => 'Pelanggaran Akses'] + $rules, 'h')['catatan'] === 'Pelanggaran Akses',
    'evaluateRow: the configured direct-access note');

// --- rulesHash ------------------------------------------------------------
$other = ['shared_note' => 'Sesi Bersama'] + $rules;
check($E::rulesHash($rules) !== $E::rulesHash($other), 'changing the note changes the rules hash (re-evaluates)');
check($E::rulesHash($rules) !== $E::rulesHash(['direct_note' => 'X'] + $rules), 'changing the direct-access note changes the rules hash');

// --- sharedFor: typed by a JOINER, inside the join -------------------------
// The owner (rc3, owner's decision): the session is "the result of a share"
// only for the person who joined it. The owner of the session had direct
// production access, so the owner's own commands are direct access, even
// while someone is joined.
$win = [['id' => 'j', 'joiner' => 'Bob Example', 'owner' => 'Alice Example(alice.example)',
         'from' => strtotime('2026-11-02 12:00:00'), 'to' => strtotime('2026-11-02 13:00:00')]];
check($E::sharedFor(['jms_user' => 'Bob Example(bob.example)', 'executed_at' => '2026-11-02 12:19:43'], $win) === true,
    'a joiner typing inside the join: shared');
check($E::sharedFor(['jms_user' => 'Alice Example(alice.example)', 'executed_at' => '2026-11-02 12:19:43'], $win) === false,
    'the session owner typing during the join: NOT shared, direct access');
check($E::sharedFor(['jms_user' => 'Bob Example(bob.example)', 'executed_at' => '2026-11-02 13:30:00'], $win) === false,
    'after the join ended: not shared');
$noOwner = [['owner' => ''] + $win[0]];
check($E::sharedFor(['jms_user' => 'Alice Example(alice.example)', 'executed_at' => '2026-11-02 12:19:43'], $noOwner) === true,
    'owner unknown (session row missing): the join window alone decides');
check($E::sharedFor(['jms_user' => 'X', 'executed_at' => '2026-11-02 12:19:43'], []) === false, 'no join: not shared');

// --- invalidateNewlyShared ------------------------------------------------
Config::$values = ['shared_note_join_watermark' => '10'];
$DB->results = [[
    ['id' => 11, 'session_jms_id' => 'S1'],
    ['id' => 14, 'session_jms_id' => 'S2'],
    ['id' => 15, 'session_jms_id' => 'S1'],
]];
$n = $E::invalidateNewlyShared();
$q = $DB->requests[0];
check(($q['WHERE']['event_type'] ?? null) === 'session_join' && ($q['WHERE']['status'] ?? null) === 'success'
    && ($q['WHERE']['id'] ?? null) === ['>', 10], 'reads only successful joins newer than the watermark');
[$t, $v, $w] = $DB->updates[0] ?? [null, null, null];
check($t === 'glpi_plugin_jumpserver_command_eval' && $v === ['rules_hash' => ''] && ($w['priority'] ?? null) === 'HIGH',
    'marks HIGH eval rows stale, nothing else');
check(($w['commands_id'] ?? null) instanceof QuerySubQuery
    && ($w['commands_id']->q['WHERE']['session_jms_id'] ?? null) === ['S1', 'S2'],
    'only the commands of the newly joined sessions');
check((Config::$saved['shared_note_join_watermark'] ?? null) === '15' && $n === 3, 'the watermark moves to the newest join');

$DB->requests = []; $DB->updates = []; $DB->results = [[]]; Config::$saved = [];
check($E::invalidateNewlyShared() === 0 && $DB->updates === [] && Config::$saved === [], 'no new join: no write');

// --- review fixes ----------------------------------------------------------
// The setting must actually be saved: every per-connection key is in the
// form's $rule_values, and an old tab that lacks the field cannot blank it.
$form = file_get_contents(__DIR__ . '/../front/config.form.php');
preg_match('/\$rule_values = \[(.*?)\];/s', $form, $rv);
require_once __DIR__ . '/../inc/rulesettings.class.php';
foreach (PluginIntegrasipamanRuleSettings::keys() as $key) {
    check(isset($rv[1]) && str_contains($rv[1], "'$key'"), "config.form.php saves $key");
}
check(str_contains($form, "array_key_exists('jumpserver_shared_session_note', \$_POST)"),
    'a POST without the note field leaves the stored note alone');
check(str_contains($form, "array_key_exists('jumpserver_direct_access_note', \$_POST)"),
    'a POST without the direct-access field leaves the stored note alone');

// The evaluator must see every join, whoever triggered it: front/reeval.php
// runs it in a signed-in request, where viewer() would hide other entities'
// joins and the rows would be saved without the note under a valid hash.
$src = file_get_contents(__DIR__ . '/../inc/riskeval.class.php');
check(str_contains($src, 'joinsBySession(array_column($rows, \'session_jms_id\'), false)'),
    'the evaluator asks for joins unrestricted by the viewer');
$so = file_get_contents(__DIR__ . '/../inc/shareoverlap.class.php');
check(str_contains($so, 'function joinsBySession(array $sessionIds, bool $restrict = true)'),
    'joinsBySession can be asked unrestricted, restricted by default');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
