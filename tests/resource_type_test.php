<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/resource_type_test.php
//
// v4.5.2: the "Asset" column on front/export.php showed a person, not an asset.
//
// The cause, proven against live data on 2026-10-22 (probe_resource_shape.php):
// mapOperatingLogEntry() fills `asset_name` from the operate-log's `resource`
// field, and `resource` is JumpServer's "object that was acted on" - which on
// this instance is an asset in only 41 of 1293 rows (3%). 863 rows are a
// UserSession, whose display is "Name(login)(ip)"; 252 are a User. So the
// column faithfully printed the wrong concept under an "Asset" heading.
//
// The fix reads `resource_type` out of the JSON the row already carries in
// `message` - every operate-log entry has it (1293/1293 in the probe), so no
// schema change and no second API call is needed.
//
// What can go wrong quietly, and is pinned here:
//   - deciding the type by looking at the shape of the display string
//     ("Name(login)(ip)" vs "VM-X(ip)") instead of reading resource_type -
//     that guess is exactly how the original bug was born;
//   - resource_type arriving as {value,label} on some version, like `action`
//     already does, and being stringified to "Array" or dropped;
//   - a feed that has no resource_type at all (session_join,
//     user_login_failed) being given a wrong one rather than none;
//   - unparseable or absent `message` JSON taking the page down instead of
//     degrading to "no type known";
//   - the Recent Events card still rendering "person@person".
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    define('GLPI_ROOT', __DIR__);
    define('READ', 1);

    function __($s, $d = null) { return $s; }
    function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    class QueryExpression { public function __construct(public string $e) {} }
    class CommonGLPI {}

    $GLOBALS['CAN'] = true;
    class Session
    {
        public static function haveRight($name, $mask) { return $GLOBALS['CAN']; }
    }
    class PluginIntegrasipamanProfile { public const RIGHT_REPORT = 'plugin_integrasipaman_report'; }

    class FakeDB
    {
        public array $requests = [];
        public array $counts = [];
        public array $rows = [];
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
    $_SESSION['glpiactiveentities'] = [6 => 6, 1 => 1];

    require __DIR__ . '/../inc/dashboard.class.php';

    $fails = 0;
    function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

    $D = 'PluginIntegrasipamanDashboard';

    /** Build a log row the way the DB hands one back. */
    function logrow(array $json, string $asset_name, string $event = 'create'): array
    {
        return [
            'date_creation' => '2026-10-22 10:51:42',
            'event_type'    => $event,
            'username'      => 'Sysadmin Nineteen(sysadmin.nineteen-e)',
            'asset_name'    => $asset_name,
            'ip_address'    => '10.0.242.126',
            'status'        => 'success',
            'message'       => json_encode($json),
        ];
    }

    // --- the four shapes the live probe actually found ---------------------------------
    // Counts and samples below are verbatim from probe_resource_shape.php, 2026-10-22.

    // 863 of 1293: a web/console login session. NOT an asset, though it ends in "(ip)".
    $r = $D::resourceParts(logrow([
        'resource_type' => 'User Session',
        'resource'      => 'Sysadmin Nineteen(sysadmin.nineteen-e)(10.0.242.126)',
    ], 'Sysadmin Nineteen(sysadmin.nineteen-e)(10.0.242.126)'));
    check($r['type'] === 'User Session', 'a UserSession row reports its real type');
    check($r['value'] === 'Sysadmin Nineteen(sysadmin.nineteen-e)(10.0.242.126)', 'and keeps the resource value intact');
    check($r['is_asset'] === false, 'a UserSession is not an asset, despite the trailing "(ip)"');

    // 38 of 1293: a genuine asset. Already formatted name(IP) - what the user asked for.
    $r = $D::resourceParts(logrow([
        'resource_type' => 'Host',
        'resource'      => 'VM-APP-07(10.0.0.160)',
    ], 'VM-APP-07(10.0.0.160)'));
    check($r['is_asset'] === true, 'a Host is an asset');
    check($r['value'] === 'VM-APP-07(10.0.0.160)', 'and already reads asset(IP) with no reformatting');

    // 3 of 1293.
    $r = $D::resourceParts(logrow([
        'resource_type' => 'Asset',
        'resource'      => 'VM-DEV-CTRL(10.0.3.111)',
    ], 'VM-DEV-CTRL(10.0.3.111)'));
    check($r['is_asset'] === true, 'an Asset is an asset');

    // 50 of 1293: an authorization RULE that happens to be named after a server.
    // Deliberately not an asset - the orchestrator's rule: type check, never a
    // guess from the display string.
    $r = $D::resourceParts(logrow([
        'resource_type' => 'Asset Authorization',
        'resource'      => 'SVR-Database One',
    ], 'SVR-Database One'));
    check($r['is_asset'] === false, 'an Asset Authorization is a permission rule, not an asset');

    // 32 of 1293: an account ON an asset. The asset is inside the parens, but
    // digging it out would be exactly the string-guessing that caused the bug.
    $r = $D::resourceParts(logrow([
        'resource_type' => 'Account',
        'resource'      => 'developers(VM-DEV2-SAMPLE)',
    ], 'developers(VM-DEV2-SAMPLE)'));
    check($r['is_asset'] === false, 'an Account is not an asset, even though an asset name sits in its parens');

    // --- shapes that must not take the page down --------------------------------------

    // `action` already arrives as {value,label}; resource_type may on some version.
    $r = $D::resourceParts(logrow([
        'resource_type' => ['value' => 'host', 'label' => 'Host'],
        'resource'      => 'VM-APP-07(10.0.0.160)',
    ], 'VM-APP-07(10.0.0.160)'));
    check($r['type'] === 'Host', 'a {value,label} resource_type is read by its label, not stringified');
    check($r['is_asset'] === true, 'and still classifies');

    // session_join / user_login_failed: no resource_type, no asset_name.
    $r = $D::resourceParts(logrow(['joiner_display' => 'someone'], '', 'session_join'));
    check($r['type'] === '' && $r['value'] === '', 'a feed with no resource reports none rather than inventing one');
    check($r['is_asset'] === false, 'and is not an asset');

    $r = $D::resourceParts(['asset_name' => 'X', 'message' => 'not json at all']);
    check($r['type'] === '' && $r['value'] === 'X', 'unparseable JSON degrades to "no type known", keeping the value');

    $r = $D::resourceParts([]);
    check($r['type'] === '' && $r['value'] === '' && $r['is_asset'] === false, 'an empty row is handled');

    // A row whose message is valid JSON but not an object (JumpServer never does
    // this; a truncated TEXT column could).
    $r = $D::resourceParts(['asset_name' => 'X', 'message' => '"a string"']);
    check($r['type'] === '', 'valid-but-scalar JSON is treated as no type');

    // --- the Recent Events card must stop printing person@person ----------------------

    $entry = $D::recentEntry(logrow([
        'resource_type' => 'User Session',
        'resource'      => 'Sysadmin Nineteen(sysadmin.nineteen-e)(10.0.242.126)',
    ], 'Sysadmin Nineteen(sysadmin.nineteen-e)(10.0.242.126)'));
    check(strpos($entry['label'], '@') === false,
          'a non-asset row no longer renders "user@asset" on the card');
    check(substr_count($entry['label'], 'Sysadmin Nineteen') === 1,
          'and names the person once, not twice');

    $entry = $D::recentEntry(logrow([
        'resource_type' => 'Host',
        'resource'      => 'VM-APP-07(10.0.0.160)',
    ], 'VM-APP-07(10.0.0.160)'));
    check(strpos($entry['label'], '@VM-APP-07(10.0.0.160)') !== false,
          'a genuine asset row still shows user@asset, which is the one case that reading was right');

    // Nothing here may pre-encode: the widget escapes labels itself.
    $entry = $D::recentEntry(logrow([
        'resource_type' => 'Host',
        'resource'      => 'VM-<b>X</b>(10.0.0.1)',
    ], 'VM-<b>X</b>(10.0.0.1)'));
    check(strpos($entry['label'], '&lt;') === false,
          'the card does not pre-escape - the widget does, and double-encoding would show &amp;lt;');

    echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
    exit($fails === 0 ? 0 : 1);
}
