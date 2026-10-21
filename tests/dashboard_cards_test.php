<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/dashboard_cards_test.php
//
// v4.1.0: the dashboard cards that came out of the widget design. Core GLPI has
// no per-card settings and no generic table widget, so the design became:
//   - a change against the previous period on the three big-number cards;
//   - a "Recent Events" list card.
//
// What can go wrong quietly, and is pinned here:
//   - a change figure computed over a different period than the number it sits
//     next to: two figures about the same period that disagree;
//   - a percentage of zero (a division by it), or a rise from zero reported as
//     a number;
//   - text from JumpServer (a user name, an asset name) reaching the page as
//     HTML - the list widget escapes labels, so the card must not pre-encode
//     or pre-trust them;
//   - the list or a count reading rows the viewer's entities do not include.
namespace Glpi\Dashboard\Filters {
    class DatesFilter
    {
        public static function getId(): string { return 'dates'; }
        public static function canBeApplied($table): bool { return true; }
        // The dashboard's own shape: a list of WHERE fragments.
        public static function getCriteria($table, $value): array
        {
            return ['WHERE' => [['date_creation' => ['>=', (string) reset($value)]]]];
        }
    }
}

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

    // Every request the class makes is recorded; counts come from a queue, rows from a list.
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
    $up = "\u{25B2}"; $down = "\u{25BC}";

    // --- the text of the change ---------------------------------------------------
    check($D::deltaText(112, 100) === "$up 12%", 'a rise is an up arrow and the percentage');
    check($D::deltaText(92, 100) === "$down 8%", 'a fall is a down arrow and the percentage, without a minus sign');
    check($D::deltaText(100, 100) === '= 0%', 'no change reads as no change');
    check($D::deltaText(5, 0) === "$up new", 'a rise from zero is "new", not a percentage of zero');
    check($D::deltaText(0, 0) === '', 'nothing in both periods shows nothing');
    check($D::deltaText(0, 40) === "$down 100%", 'falling to zero is 100%');
    check($D::deltaText(1003, 1000) === '= 0%' || $D::deltaText(1003, 1000) === "$up 0%", 'a rounding to zero is not an arrow with a minus');

    // --- the period before ----------------------------------------------------------
    check($D::previousWindow(['2026-09-01 00:00:00', '2026-09-30 23:59:59']) === ['2026-08-02 00:00:00', '2026-08-31 23:59:59'],
          'the previous period has the same length and ends one second before this one starts');
    check($D::previousWindow(['2026-10-12 00:00:00', '2026-10-12 23:59:59']) === ['2026-10-11 00:00:00', '2026-10-11 23:59:59'],
          'a single day is compared with the day before');

    // --- the three big-number cards ---------------------------------------------------
    // Query order inside a provider: the number the card shows, the same period again,
    // then the period before. The second must equal the first or no change is shown.
    $DB->counts = [120, 120, 100];
    $r = $D::provideTotalEvents([]);
    check($r['number'] === 120 && $r['label'] === "JumpServer Total Events ($up 20%)", 'the change sits next to the number it is about');
    check(isset($r['alt']) && str_contains($r['alt'], '120') && str_contains($r['alt'], '100'), 'and the tooltip says both figures');
    check(count($DB->requests) === 3, 'three counts: shown, same period, period before');

    $DB->requests = []; $DB->counts = [120, 119, 100];
    $r = $D::provideTotalEvents([]);
    check($r['number'] === 120 && $r['label'] === 'JumpServer Total Events' && !isset($r['alt']),
          'when this query does not reproduce the shown number, no change is shown');
    check(count($DB->requests) === 2, 'and the period before is not even read');

    $DB->requests = []; $DB->counts = [0, 0, 0];
    $r = $D::provideTotalEvents([]);
    check($r['label'] === 'JumpServer Total Events', 'zero in both periods: no change');

    $DB->requests = []; $DB->counts = [30, 30, 25];
    $r = $D::provideLoginFailures([]);
    check($r['label'] === "JumpServer Login Failures ($up 20%)" && $r['color'] === '#d63939', 'login failures keep their colour and get the change');
    $withEvent = array_values(array_filter($DB->requests, static fn ($q) => ($q['WHERE']['event_type'] ?? null) === 'user_login_failed'));
    check(count($withEvent) === 3, 'all three counts are for that event type');

    $DB->requests = []; $DB->counts = [4, 4, 8];
    $r = $D::provideSessionSharing([]);
    check($r['label'] === "JumpServer Session Sharing ($down 50%)", 'session sharing too');

    // v4.5.0: WHAT it counts. Until then this card counted 'session_share',
    // the creation of a share link - two of which covered every join this
    // instance had seen since February, so the card read 0 through weeks of
    // real activity. It counts joins now. If this assertion ever flips back
    // to session_share, the card is broken again in exactly that way.
    $joinQ = array_values(array_filter($DB->requests, static fn ($q) => ($q['WHERE']['event_type'] ?? null) === 'session_join'));
    check(count($joinQ) === 3, 'the card counts session joins, not the share links that allowed them');
    check(array_filter($DB->requests, static fn ($q) => ($q['WHERE']['event_type'] ?? null) === 'session_share') === [],
          'and never counts share links');

    // a dashboard date filter
    $DB->requests = []; $DB->counts = [10, 10, 5];
    $r = $D::provideTotalEvents(['apply_filters' => ['dates' => ['2026-09-01', '2026-09-30']]]);
    $bounds = array_map(static fn ($w) => $w['date_creation'][1] ?? null, array_filter($DB->requests[1]['WHERE'], 'is_array'));
    check(in_array('2026-09-01 00:00:00', $bounds, true) && in_array('2026-09-30 23:59:59', $bounds, true),
          'with a dashboard date filter the period is the filter\'s, whole days');
    check(str_contains($r['label'], "$up 100%"), 'and the change is against the period before the filter');

    $DB->requests = []; $DB->counts = [10, 10, 5];
    $r = $D::provideTotalEvents(['apply_filters' => ['dates' => ['not a date', '2026-09-30']]]);
    check($r['label'] === 'JumpServer Total Events' && count($DB->requests) === 1,
          'a filter that is not made of dates shows the number and no change');

    $DB->requests = []; $DB->counts = [10, 10, 5];
    $r = $D::provideTotalEvents(['apply_filters' => ['dates' => ['2026-09-30', '2026-09-01']]]);
    check($r['label'] === 'JumpServer Total Events', 'and so does a filter that ends before it starts');

    // every count is the viewer's
    $DB->requests = []; $DB->counts = [1, 1, 1];
    $D::provideTotalEvents([]);
    $all = array_map(static fn ($q) => $q['WHERE']['entities_id'] ?? null, $DB->requests);
    check($all === [[6, 1], [6, 1], [6, 1]], 'all three counts are restricted to the viewer\'s entities');

    // no right: nothing is read at all
    $GLOBALS['CAN'] = false; $DB->requests = [];
    $r = $D::provideTotalEvents([]);
    check($r['number'] === 0 && $DB->requests === [], 'without the report right a card reads nothing');
    $r = $D::provideRecentEvents([]);
    check($r['data'] === [] && $DB->requests === [], 'neither does the list');
    check($D::getCardTypes(['x' => 1]) === ['x' => 1], 'and the cards are not even offered');
    $GLOBALS['CAN'] = true;

    // --- the list ------------------------------------------------------------------------
    $row = ['date_creation' => '2026-10-12 09:14:00', 'event_type' => 'session_share_join', 'username' => 'agus.prasetyo',
            'asset_name' => 'db-prod-02', 'status' => 'success'];
    $e = $D::recentEntry($row);
    check($e['number'] === '12/10 09:14' && $e['label'] === 'session_share_join - agus.prasetyo@db-prod-02'
          && $e['icon'] === 'ti ti-check' && !isset($e['color']), 'a successful event: time, event, user@asset, a check, no colour');
    $e = $D::recentEntry(['status' => 'failed'] + $row);
    check($e['icon'] === 'ti ti-x' && $e['color'] === '#d63939', 'a failed one: a cross, in red');
    $e = $D::recentEntry(['status' => 'expired'] + $row);
    check($e['icon'] === 'ti ti-x', 'anything that is not a success is marked');
    $e = $D::recentEntry(['username' => '', 'asset_name' => ''] + $row);
    check($e['label'] === 'session_share_join - -@-', 'missing names show a dash instead of nothing');
    $e = $D::recentEntry(['date_creation' => 'garbage'] + $row);
    check($e['number'] === '-', 'an unreadable time shows a dash');

    // Text from JumpServer is data. The widget escapes the label, so it must arrive raw:
    // encoding it here as well would show "&lt;b&gt;" on screen, and trusting it would be worse.
    $evil = '<img src=x onerror=alert(1)>';
    $e = $D::recentEntry(['username' => $evil] + $row);
    check(str_contains($e['label'], $evil), 'a user name with markup arrives untouched, for the widget to escape');
    check(!str_contains($e['label'], '&lt;'), 'and is not encoded twice');

    $DB->requests = [];
    $DB->rows = [$row, ['status' => 'failed'] + $row];
    $r = $D::provideRecentEvents([]);
    check(count($r['data']) === 2 && $r['label'] === 'JumpServer Recent Events', 'the list has one entry per row');
    $q = $DB->requests[0];
    check($q['LIMIT'] === 10 && $q['ORDER'] === 'date_creation DESC', 'at most ten, newest first');
    check($q['WHERE']['entities_id'] === [6, 1], 'only the viewer\'s entities');

    // --- registration --------------------------------------------------------------------
    $cards = $D::getCardTypes([]);
    check(isset($cards['jumpserver_recent_events']) && $cards['jumpserver_recent_events']['widgettype'] === ['multipleNumber'],
          'the list is offered in the Add widget panel as a multipleNumber');
    check($cards['jumpserver_recent_events']['provider'] === 'PluginIntegrasipamanDashboard::provideRecentEvents',
          'its provider is a "Class::method" string (core explodes it; a Closure would fatal)');
    check(count($cards) === 7, 'beside the six that were there (4.3.0 added login lockouts)');

    echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
    exit($fails === 0 ? 0 : 1);
}
