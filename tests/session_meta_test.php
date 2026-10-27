<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/session_meta_test.php
//
// v4.8.0: the session TYPE and the readable LOGIN SOURCE.
//
// mapSessionEntry() kept only login_from['value'] ("WT") and dropped the
// label ("Web Terminal"); `type` ("Normal(normal)" in JumpServer's own
// export) was never read at all. Both halves are now stored, because
// neither alone does the job: the value is the stable key to filter on, the
// label is what the console shows.
//
// What can go wrong quietly, and is pinned here:
//   - reading {value,label} as if it were a string, storing "Array";
//   - a missing key inventing a word instead of leaving the column empty -
//     on this instance every historical row has these columns blank until
//     the one-shot updater runs, and "blank" must stay distinguishable from
//     "normal";
//   - the pair drifting apart, so a row says type=normal / label=Tunnel;
//   - a label long enough to be truncated into a different word.
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    define('GLPI_ROOT', __DIR__);

    if (!function_exists('mb_substr')) {
        function mb_substr($s, $o, $l = null, $e = null) { return $l === null ? substr((string) $s, $o) : substr((string) $s, $o, $l); }
    }
    function __($s, $d = null) { return $s; }

    class CommonDBTM {}

    // terminalpull.class.php pulls in jmsapi / apiwindow / config, and config
    // pulls in connection - so the real classes load here rather than stubs.
    // mapSessionEntry() touches none of them; what matters is only that the
    // file parses and declares. Stubbing PluginIntegrasipamanConnection would
    // collide with the real declaration further down that chain.
    require __DIR__ . '/../inc/terminalpull.class.php';

    $fails = 0;
    function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

    $T = 'PluginIntegrasipamanTerminalPull';

    // The shape /api/v1/terminal/sessions/ actually returns, as the user's own
    // export showed it: Type "Normal(normal)", Login Source "Web Terminal(WT)".
    $entry = [
        'id'         => 'f2b1c0de-0000-4000-8000-000000000001',
        'user'       => 'Sysadmin Five(sysadmin.five-e)',
        'asset'      => 'VM-STAG-WEBSITE(10.0.6.127)',
        'account'    => 'Manual Input(appuser)',
        'protocol'   => 'ssh',
        'type'       => ['value' => 'normal', 'label' => 'Normal'],
        'login_from' => ['value' => 'WT', 'label' => 'Web Terminal'],
        'duration'   => '0:44:57',
        'date_start' => '2026-09-30 20:43:21',
    ];

    $r = $T::mapSessionEntry($entry);

    check($r['type'] === 'normal', 'the type VALUE is stored, for filtering');
    check($r['type_label'] === 'Normal', 'and the type LABEL, for reading');
    check($r['login_from'] === 'WT', 'the login-source value is unchanged from before');
    check($r['login_from_label'] === 'Web Terminal', 'and its label is no longer thrown away');

    // Nothing may stringify an array into "Array".
    foreach (['type', 'type_label', 'login_from_label'] as $k) {
        check(is_string($r[$k]) && $r[$k] !== 'Array', "`$k` is a string, not a stringified array");
    }

    // An entry with neither key: the columns stay empty. That matters because
    // every row imported before 4.8.0 looks exactly like this until the
    // one-shot updater runs, and "not known yet" must not read as "normal".
    $bare = $entry;
    unset($bare['type'], $bare['login_from']);
    $r = $T::mapSessionEntry($bare);
    check($r['type'] === '' && $r['type_label'] === '', 'a missing type leaves both columns empty');
    check($r['login_from'] === '' && $r['login_from_label'] === '', 'so does a missing login source');

    // A version that sends a plain string rather than {value,label}.
    $r = $T::mapSessionEntry(['type' => 'tunnel'] + $entry);
    check($r['type'] === 'tunnel', 'a scalar type is taken as the value');
    check($r['type_label'] === '', 'and claims no label it was not given');

    // Half a pair is still half a pair - no guessing the other half.
    $r = $T::mapSessionEntry(['type' => ['value' => 'command']] + $entry);
    check($r['type'] === 'command' && $r['type_label'] === '', 'a value with no label does not invent one');
    $r = $T::mapSessionEntry(['type' => ['label' => 'Command']] + $entry);
    check($r['type'] === '' && $r['type_label'] === 'Command', 'and a label with no value does not invent one either');

    // Column widths: type 32, labels 64. A longer label must be cut, not refused.
    $long = str_repeat('x', 200);
    $r = $T::mapSessionEntry(['type' => ['value' => $long, 'label' => $long], 'login_from' => ['value' => $long, 'label' => $long]] + $entry);
    check(strlen($r['type']) === 32, 'an over-long type value is cut to the column width');
    check(strlen($r['type_label']) === 64, 'an over-long type label likewise');
    check(strlen($r['login_from_label']) === 64, 'and an over-long login-source label');

    // The pair must come from the same field - a drift here would label a
    // tunnel session "Normal", which is worse than showing nothing.
    $r = $T::mapSessionEntry(['type' => ['value' => 'tunnel', 'label' => 'Tunnel']] + $entry);
    check($r['type'] === 'tunnel' && $r['type_label'] === 'Tunnel', 'value and label stay paired');
    check($r['login_from'] === 'WT' && $r['login_from_label'] === 'Web Terminal', 'and the other pair is untouched by it');

    // Everything the older columns did must still hold.
    $r = $T::mapSessionEntry($entry);
    check($r['duration'] === 2697, 'the duration still parses to seconds');
    check($r['asset'] === 'VM-STAG-WEBSITE(10.0.6.127)', 'the asset is unchanged');
    check($r['jms_id'] === 'f2b1c0de-0000-4000-8000-000000000001', 'and the dedup key');

    echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
    exit($fails === 0 ? 0 : 1);
}
