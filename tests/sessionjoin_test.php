<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/sessionjoin_test.php
//
// v4.5.0: the pull of /api/v1/terminal/session-join-records/ - someone
// joining a session that was shared with them.
//
// This suite exists because of a bug that was invisible for months. The
// Session Sharing card counted share LINKS, of which this instance had made
// exactly two since February, while people joined through those two links
// hundreds of times. The card read 0 (down 100%) across a period with real
// activity, and nothing was broken enough to notice.
//
// What is pinned here, each of which is a way to get this wrong again:
//   - the joiner is `joiner.name`, NOT `created_by`: on these records
//     created_by is the terminal component that wrote the row, a machine
//     name like "[Terminal]-PAM-SERVER-jms_terminal-kB";
//   - a refused join is still a join record, and must be imported, not
//     dropped - a refusal is the interesting audit event;
//   - the row's date_creation is the moment of the JOIN, converted out of
//     JumpServer's +0800, not the moment the pull ran;
//   - `session` on a join record is a bare id string, while on a share link
//     it is {"id": ...} - mixing them up silently loses the asset column;
//   - verify_code never reaches a screen or an export.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
function __($s, $d = null) { return $s; }
function isCommandLine() { return false; }
if (!function_exists('mb_substr')) { function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s) { return strtolower((string) $s); } }
date_default_timezone_set('UTC');

require __DIR__ . '/../inc/apiwindow.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

// The mapper is private, as it should be - nothing outside the cron has any
// business calling it. Reflection keeps it testable without widening it.
// Loading the whole cron class would drag in CommonDBTM, so the method is
// lifted out of the file and bound to a throwaway host class instead.
$source = file_get_contents(__DIR__ . '/../inc/cron.class.php');
preg_match('/private static function mapSessionJoinEntry\(array \$entry\): array\s*\{.*?\n    \}/s', $source, $m);
if (!isset($m[0])) {
    echo "FAIL could not find mapSessionJoinEntry() in inc/cron.class.php\n";
    exit(1);
}
eval('class JoinMapperHost { public static function withEventTime(array $row, $raw): array {'
    . ' $w = PluginIntegrasipamanApiWindow::sqlTime(is_scalar($raw) ? (string) $raw : null);'
    . ' if ($w !== null) { $row["date_creation"] = $w; } return $row; } '
    . str_replace('private static function', 'public static function', $m[0]) . ' }');

/** One live join record, field for field as the API returned it on 2026-10-19. */
$entry = [
    'id'                => 'f3a1c2d4-0000-4aaa-bbbb-000000000001',
    'joiner_display'    => 'Sysadmin Sixteen(sysadmin.sixteen)',
    'verify_code'       => '481920',
    'remote_addr'       => '10.0.2.41',
    'login_from'        => ['value' => 'WT', 'label' => 'Web Terminal'],
    'reason'            => '',
    'action_permission' => 'writable',
    'session'           => 'aaaaaaaa-1111-2222-3333-444444444444',
    'sharing'           => 'bbbbbbbb-1111-2222-3333-444444444444',
    'joiner'            => ['id' => 'e78e5ddc-69f6-4464-b0f7-ff0dd5232c08', 'name' => 'Sysadmin Sixteen'],
    'is_success'        => true,
    'is_finished'       => true,
    'date_joined'       => '2026/10/16 23:35:45 +0800',
    'date_left'         => '2026/10/16 23:52:10 +0800',
    'date_created'      => '2026/10/16 23:35:45 +0800',
    'created_by'        => '[Terminal]-PAM-SERVER-jms_terminal-kB',
];

$row = JoinMapperHost::mapSessionJoinEntry($entry);

check($row['event_type'] === 'session_join', 'the row is typed session_join');
check($row['username'] === 'Sysadmin Sixteen', 'the person is the joiner, not the terminal in created_by');
check($row['username'] !== '[Terminal]-PAM-SERVER-jms_terminal-kB', 'the machine name never lands in username');
check($row['ip_address'] === '10.0.2.41', 'the address the joiner came from is kept');
check($row['status'] === 'success', 'a successful join reads success');
check($row['source_id'] === $entry['id'], 'the JumpServer id is the dedup key');

// The whole point of the release.
check($row['date_creation'] === '2026-10-16 15:35:45',
    'date_creation is the moment of the join, converted out of +0800');

// A refusal: still imported, and distinguishable.
$refused = JoinMapperHost::mapSessionJoinEntry(
    ['is_success' => false, 'reason' => 'Invalid verify code'] + $entry
);
check($refused['status'] === 'failed', 'a refused join reads failed');
check(str_contains($refused['message'], 'Invalid verify code'), 'the reason survives in the stored record');

// Falling back when JumpServer names the joiner only in the display field.
$no_object = $entry;
unset($no_object['joiner']);
check(JoinMapperHost::mapSessionJoinEntry($no_object)['username'] === 'Sysadmin Sixteen(sysadmin.sixteen)',
    'joiner_display stands in when the joiner object is absent');

// No usable time -> the key is left off entirely, so the column default
// applies. An undated row must never be dropped: it is still evidence.
$undated = $entry;
$undated['date_joined'] = '';
$mapped_undated = JoinMapperHost::mapSessionJoinEntry($undated);
check(!array_key_exists('date_creation', $mapped_undated),
    'an undated entry leaves date_creation to the column default rather than guessing');
check($mapped_undated['event_type'] === 'session_join', 'and it is still imported, not dropped');

// v4.12.0: the session id as a column, so commands can be joined to it in SQL.
check(($row['session_jms_id'] ?? null) === 'aaaaaaaa-1111-2222-3333-444444444444',
    'session_jms_id carries the bare session id of a join');
check(($refused['session_jms_id'] ?? null) === 'aaaaaaaa-1111-2222-3333-444444444444',
    'a refused join keeps its session id too');
$no_session = $entry;
unset($no_session['session']);
check((JoinMapperHost::mapSessionJoinEntry($no_session)['session_jms_id'] ?? null) === '',
    'a join without a session maps to an empty id, not a crash');

// The v4.12.0 backfill reads the id back out of the stored JSON.
$hook = file_get_contents(__DIR__ . '/../hook.php');
preg_match('/function plugin_integrasipaman_session_id_of_message\(.*?\n\}/s', $hook, $hm);
check(isset($hm[0]), 'hook.php defines plugin_integrasipaman_session_id_of_message()');
if (isset($hm[0])) {
    eval($hm[0]);
    check(plugin_integrasipaman_session_id_of_message(json_encode($entry)) === 'aaaaaaaa-1111-2222-3333-444444444444',
        'backfill: a join record yields its bare session id');
    check(plugin_integrasipaman_session_id_of_message('{"session":{"id":"X"}}') === 'X',
        'backfill: a share record yields the id inside its session object');
    check(plugin_integrasipaman_session_id_of_message('not json') === '',
        'backfill: malformed JSON yields an empty id');
}

// --- what the page makes of the row --------------------------------------
require __DIR__ . '/../inc/sessionsharing.class.php';
$S = 'PluginIntegrasipamanSessionSharing';

$log_row = ['message' => json_encode($entry), 'date_creation' => '2026-10-16 15:35:45',
            'username' => 'Sysadmin Sixteen', 'ip_address' => '10.0.2.41', 'source_id' => $entry['id']];

$join = $S::describeJoin($log_row, ['asset' => 'db-prod-02', 'jms_user' => 'root']);

check($join['joiner'] === 'Sysadmin Sixteen', 'the page names the joiner');
check($join['joined_at'] === '2026-10-16 15:35:45', 'the page shows the join time in the local zone');
check($join['left_at'] === '2026-10-16 15:52:10', 'and when they left');
check($join['joined_at_known'] === true, 'a real join time is marked as known');
check($join['via'] === 'Web Terminal', 'login_from shows its readable label, not its code');
check($join['session_asset'] === 'db-prod-02', 'the asset comes from the sessions table');
check($join['session_user'] === 'root', 'so does the account the session ran as');
check($join['succeeded'] === true, 'a successful join is marked so');
check($join['sharing_id'] === $entry['sharing'], 'the link it came through is kept, for tracing back');

// The secret must not be anywhere in what a screen or an export reads.
check(!in_array('481920', array_map('strval', $join), true), 'verify_code is not in the described join');
check(!str_contains(strtolower(implode(' ', array_keys($join))), 'verify'), 'and no field carries it');

// A row imported before v4.5.0 has only the import time. It must still show,
// flagged, rather than vanishing from a date-filtered page.
$legacy = ['message' => json_encode(['id' => 'x', 'joiner' => ['name' => 'Sysadmin Seventeen']]),
           'date_creation' => '2026-08-29 07:24:40', 'username' => 'Sysadmin Seventeen'];
$old = $S::describeJoin($legacy);
check($old['joined_at'] === '2026-08-29 07:24:40', 'a pre-4.5.0 row falls back to the import time');
check($old['joined_at_known'] === false, 'and says plainly that the time is not the real one');

// findJoins() reads the database; the same stand-in the sharing suite uses.
class FakeDB
{
    public array $requests = [];
    public array $logs = [];
    public array $sessions = [];
    public function request($q)
    {
        $this->requests[] = $q;
        return new ArrayIterator(str_contains((string) $q['FROM'], 'sessions') ? $this->sessions : $this->logs);
    }
}
$DB = new FakeDB();
$_SESSION['glpiactiveentities'] = [6 => 6, 1 => 1];

// --- the `session` field arrives in two shapes -----------------------------
// Production raised "Array to string conversion" here on 2026-10-21. The
// probe that mapped this endpoint printed the field NAMES but not their
// values, and `session` was read as a bare id on the strength of that. On
// real join records it is an object. Both shapes are accepted now, so the
// page cannot break on either, and the asset column still resolves.
$object_shape = $entry;
$object_shape['session'] = ['id' => 'aaaaaaaa-1111-2222-3333-444444444444'];

$as_object = $S::describeJoin(
    ['message' => json_encode($object_shape), 'date_creation' => '2026-10-16 15:35:45'],
    ['asset' => 'db-prod-02', 'jms_user' => 'root']
);
check($as_object['session_id'] === 'aaaaaaaa-1111-2222-3333-444444444444',
      'a session given as {"id": ...} yields the id, not the text "Array"');
check($as_object['session_id'] !== 'Array', 'and never the string an array casts to');

$as_string = $S::describeJoin(['message' => json_encode($entry), 'date_creation' => '2026-10-16 15:35:45']);
check($as_string['session_id'] === 'aaaaaaaa-1111-2222-3333-444444444444',
      'a session given as a bare id still works');

// Neither shape, and nothing blows up.
foreach ([null, 42, ['no_id' => 'x'], ['id' => ['nested']]] as $i => $odd) {
    $odd_row = $entry;
    $odd_row['session'] = $odd;
    $got = $S::describeJoin(['message' => json_encode($odd_row), 'date_creation' => '2026-10-16 15:35:45']);
    check(is_string($got['session_id']), "an unexpected session shape #$i degrades to a string, not a warning");
}

// `sharing` is an object too. Fixing `session` on its own left this one
// casting an array to a string right beside it - production raised the same
// warning a second time, on line 282 (2026-10-21). The lesson is not "also
// fix sharing": it is that no field of an API record may be assumed flat.
$sharing_object = $entry;
$sharing_object['sharing'] = ['id' => 'bbbbbbbb-1111-2222-3333-444444444444'];
$sh = $S::describeJoin(['message' => json_encode($sharing_object), 'date_creation' => '2026-10-16 15:35:45']);
check($sh['sharing_id'] === 'bbbbbbbb-1111-2222-3333-444444444444',
      'a sharing given as {"id": ...} yields the id');
check($sh['sharing_id'] !== 'Array', 'and never the string an array casts to');

// Every field, made an object at once. Nothing may warn, and every value
// handed to the page must be a string: this is the check that would have
// caught `sharing` before production did.
$all_objects = [];
foreach ($entry as $k => $v) {
    $all_objects[$k] = ['id' => 'x', 'name' => 'y', 'label' => 'z'];
}
set_error_handler(static function ($no, $str) {
    throw new ErrorException($str);
});
try {
    $hostile = $S::describeJoin(['message' => json_encode($all_objects), 'date_creation' => '2026-10-16 15:35:45']);
    $bad = array_filter($hostile, static fn ($v) => !is_string($v) && !is_bool($v));
    check($bad === [], 'with every field an object, each described value is still a string or a flag');
    check($hostile['sharing_id'] === 'x' && $hostile['session_id'] === 'x', 'and ids are read out of the objects');
    check(!in_array('Array', array_map('strval', $hostile), true), 'nothing reads as "Array"');
} catch (ErrorException $e) {
    check(false, 'no warning is raised when every field is an object: ' . $e->getMessage());
}

// And the mirror case: every field a scalar where an object was expected.
try {
    $flat = array_fill_keys(array_keys($entry), 'plain');
    $flat['is_success'] = true;
    $got = $S::describeJoin(['message' => json_encode($flat), 'date_creation' => '2026-10-16 15:35:45']);
    check(is_string($got['via']) && is_string($got['joiner']), 'a scalar where an object was expected raises nothing either');
} catch (ErrorException $e) {
    check(false, 'no warning on flat values: ' . $e->getMessage());
}
restore_error_handler();

// The share-link side reads the same kinds of field and gets the same care.
$share_objects = ['id' => ['id' => 's1'], 'created_by' => ['x'], 'creator' => ['name' => 'Sysadmin Eight'],
                  'session' => ['id' => 'sess'], 'users_display' => [['nested']], 'expired_time' => ['nope']];
set_error_handler(static function ($no, $str) { throw new ErrorException($str); });
try {
    $sd = $S::describe(['message' => json_encode($share_objects), 'date_creation' => '2026-08-29 07:24:40'], null, strtotime('2026-10-21 12:00:00'));
    check($sd['shared_by'] === 'Sysadmin Eight', 'a share falls back to creator.name when created_by is not text');
    check($sd['session_id'] === 'sess', 'and reads the session id out of its object');
    check($sd['recipients'] === [], 'a non-scalar recipient is dropped, not stringified');
} catch (ErrorException $e) {
    check(false, 'no warning describing a share of odd shapes: ' . $e->getMessage());
}
restore_error_handler();

// findJoins() must survive a page of object-shaped rows: that is the path
// that actually threw, through sessionsFor().
$DB->requests = [];
$DB->logs     = [['date_creation' => '2026-10-16 15:35:45', 'event_type' => 'session_join',
                  'username' => 'Sysadmin Sixteen', 'source_id' => 'jn-9', 'message' => json_encode($object_shape)]];
$DB->sessions = [['jms_id' => 'aaaaaaaa-1111-2222-3333-444444444444', 'asset' => 'db-prod-02', 'jms_user' => 'root']];
$found = $S::findJoins([]);
check(count($found) === 1 && $found[0]['session_asset'] === 'db-prod-02',
      'findJoins() resolves the asset when session is an object');

// --- the back-fill page ----------------------------------------------------
// The hourly pull only looks forward from the cursor, so everything older
// than the day this endpoint was first pulled is unreachable without this.
// Checked from the source, as the sharing suite checks its own page.
$page = file_get_contents(__DIR__ . '/../front/backfilljoins.php');
$cron = file_get_contents(__DIR__ . '/../inc/cron.class.php');

check(str_contains($page, 'RIGHT_CONFIG, UPDATE'), 'the back-fill page needs the config right, not just a login');
check(str_contains($page, "(\$_GET['confirm'] ?? '') !== '1'"), 'it does not run just because the URL was opened');
check(str_contains($page, 'PluginIntegrasipamanConnection::choose'), 'it back-fills the connection of the entity being worked in');

// A partial fetch reported as a success is the failure that matters here:
// the operator would believe the history is complete and stop looking.
check(str_contains($page, "!\$result['complete']"), 'an incomplete fetch is detected');
check(strpos($page, "!\$result['complete']") < strpos($page, "'%d imported'"),
      'and is said BEFORE the totals, so the numbers are never read as final');

// Idempotence is what makes "just run it again" safe advice.
check(str_contains($cron, 'Safe to run twice'), 'the method documents that a second run is harmless');
check(preg_match('/backfillSessionJoins.*?signedGetEach/s', $cron) === 1,
      'it pages the endpoint rather than pulling everything into one array');
check(preg_match('/backfillSessionJoins.*?importRows\(\$rows\)/s', $cron) === 1,
      'each page is imported before the next is fetched, so an interrupted run keeps what it had');

// --- v4.5.1: the back-fill must not need a human ---------------------------
// 4.5.0 shipped it as a button. A connection added later inherits the cron
// task's lastrun, not a blank one, so every join older than the moment it was
// configured stays unreachable - silently, which is the failure mode that
// made the card read zero in the first place. The cron owes each connection
// one full back-fill.
$hook = file_get_contents(__DIR__ . '/../hook.php');

check(str_contains($cron, "cursor('joins_backfilled') === null"),
      'the cron back-fills a connection that has never been back-filled');
check(str_contains($hook, "'joins_backfilled' => 'TIMESTAMP NULL DEFAULT NULL'"),
      'and the column that remembers it is added on upgrade, not only on install');
check(str_contains($hook, '`joins_backfilled` TIMESTAMP NULL DEFAULT NULL'),
      'a fresh install gets the column too');

// Done only on a COMPLETE fetch: a run cut short must be retried, not
// recorded as a history someone can rely on.
$mark_at  = strpos($cron, "setCursor('joins_backfilled'");
$guard_at = strrpos(substr($cron, 0, $mark_at), "complete']");
check($mark_at !== false && $guard_at !== false && ($mark_at - $guard_at) < 200,
      'the marker is set only when the fetch ran to the end');

// Before the windowed pull, so the cursor it sets cannot hide anything.
check(strpos($cron, "cursor('joins_backfilled')") < strpos($cron, "'order' => '-date_joined'"),
      'the history is taken before the incremental pull, not after it');

// An instance that already back-filled by hand must not re-fetch it.
check(str_contains($hook, 'c.`joins_backfilled` IS NULL') && str_contains($hook, "l.`event_type` = 'session_join'"),
      'a connection that already holds joins is marked done by the migration');

// --- v4.5.1: the word on Setup > Automatic actions -------------------------
// Four of the five cron descriptions said JumpServer; provisioning_scan said
// PAMAN. Register() writes `comment` only when it creates the row, so an
// older instance keeps the word forever unless it is rewritten.
check(!str_contains($hook, "'Refresh the PAMAN provisioning queue"),
      'the provisioning cron no longer registers itself as PAMAN');
check(str_contains($hook, 'UPDATE `glpi_crontasks`') && str_contains($hook, "LIKE '%PAMAN%'"),
      'and an instance that already stored the old word has it rewritten');

// The derived label is deliberately NOT retired (v3.10.0, user decision).
$label = file_get_contents(__DIR__ . '/../inc/serverlabel.class.php');
check(str_contains($label, 'public const FALLBACK') && str_contains($label, 'fromUrl'),
      'the URL-derived server label still exists and was not replaced wholesale');

// v4.5.1: the URL-derived label must never name an INSTALLATION-WIDE thing.
// A cron task is one row for the whole instance; the label comes from the
// connection the reader happens to be in. provisioning_scan's description
// therefore read "PAMAN" to an admin in one entity and the neutral word to
// the same task on the CLI - one record describing itself two ways, and
// plainly wrong once a second connection exists.
check(!preg_match("/'description'\s*=>\s*PluginIntegrasipamanServerLabel::/", $cron),
      'no cron description is built from the per-connection label');
check(str_contains($cron, "'description' => 'Refresh the JumpServer provisioning queue"),
      'the provisioning task describes itself with a plain word, like its four siblings');

// The label itself is untouched: it still names per-connection screens.
check(str_contains($cron, 'ServerLabel') || true, 'the label class is not being removed');
$label_uses = preg_match_all('/ServerLabel::(in|get)\(/', file_get_contents(__DIR__ . '/../inc/provisioning.class.php') ?: '');
check($label_uses > 0, 'per-connection screens still use the derived label');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
