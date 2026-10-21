<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/apiwindow_test.php
//
// PluginIntegrasipamanApiWindow is the safety net that makes a period-scoped
// pull correct even when JumpServer ignores the date_from/date_to we send
// (only /terminal/commands/ documents them). The early stop it controls is
// the part that can lose data if it fires on a stream that is not really
// newest-first, so that is what most of these assertions are about.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);
date_default_timezone_set('UTC');

require __DIR__ . '/../inc/apiwindow.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$FROM = '2026-09-01T00:00:00Z';
$TO   = '2026-10-01T00:00:00Z';

/** Entries with 'datetime', newest first. */
function desc_page(array $days): array {
    return array_map(static fn($d) => ['id' => $d, 'datetime' => $d], $days);
}

// --- window filtering ------------------------------------------------------
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$kept = $w->filter(desc_page([
    '2026-10-05T10:00:00Z',  // after the window
    '2026-09-20T10:00:00Z',  // inside
    '2026-09-01T00:00:00Z',  // exactly the lower bound -> inside (inclusive)
    '2026-08-31T23:59:59Z',  // before the window
]));
check(count($kept) === 2, 'keeps only the in-window entries');
check($kept[0]['id'] === '2026-09-20T10:00:00Z', 'first kept entry is the in-window one');
check($kept[1]['id'] === '2026-09-01T00:00:00Z', 'lower bound is inclusive');

$w2 = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$kept2 = $w2->filter(desc_page(['2026-10-01T00:00:00Z']));
check($kept2 === [], 'upper bound is exclusive');

// --- the early stop, on genuinely descending data --------------------------
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$w->filter(desc_page(['2026-09-20T10:00:00Z', '2026-09-10T10:00:00Z']));
check(!$w->canStop(), 'does not stop while still inside the window');
$w->filter(desc_page(['2026-08-20T10:00:00Z']));
check($w->canStop(), 'stops once an entry older than the window start is seen');

// --- the early stop must NOT fire when ordering is not newest-first --------
// This is the failure that would silently drop data: `order` is just another
// query param DRF may ignore, so ascending data must never trigger a stop.
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$w->filter(desc_page([
    '2026-08-01T10:00:00Z',   // older than the window, and it comes FIRST
    '2026-08-15T10:00:00Z',
    '2026-09-05T10:00:00Z',
]));
check(!$w->canStop(), 'ascending stream never stops early, even past the start');
check($w->stats()['ordered_desc'] === false, 'ascending stream is not reported as ordered');

// A single old entry is no evidence of ordering either.
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$w->filter(desc_page(['2026-08-01T10:00:00Z']));
check(!$w->canStop(), 'one old entry alone is not enough evidence to stop');

// All-equal timestamps give no decrease, so no stop.
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$w->filter(desc_page(['2026-08-01T10:00:00Z', '2026-08-01T10:00:00Z']));
check(!$w->canStop(), 'equal timestamps are not evidence of descending order');

// An increase anywhere disables the stop permanently, even if it later descends.
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$w->filter(desc_page(['2026-09-20T10:00:00Z', '2026-09-25T10:00:00Z', '2026-09-10T10:00:00Z', '2026-08-01T10:00:00Z']));
check(!$w->canStop(), 'one ascent disables the early stop for the whole run');

// --- no lower bound => never stop (full-history pull) ----------------------
$w = new PluginIntegrasipamanApiWindow('datetime', null, $TO);
$w->filter(desc_page(['2020-01-01T10:00:00Z', '2019-01-01T10:00:00Z']));
check(!$w->canStop(), 'open-ended pull never stops early');

// --- undated rows are kept, and stay out of the ordering logic -------------
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$kept = $w->filter([
    ['id' => 'a', 'datetime' => '2026-09-10T10:00:00Z'],
    ['id' => 'b'],                                  // field missing
    ['id' => 'c', 'datetime' => ''],                // empty
    ['id' => 'd', 'datetime' => 'not a date'],      // unparseable
]);
check(count($kept) === 4, 'rows with no usable timestamp are kept, never dropped');
check($w->stats()['undated'] === 3, 'undated rows are counted');
check(!$w->canStop(), 'undated rows cannot trigger an early stop');

// --- detecting that the server ignored the filter --------------------------
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$w->filter(desc_page(['2026-09-10T10:00:00Z']));
check(!$w->serverFilterIgnored(), 'no out-of-window row => filter looks honoured');
$w->filter(desc_page(['2026-01-10T10:00:00Z']));
check($w->serverFilterIgnored(), 'an out-of-window row proves the server did not filter');

// --- a different timestamp field (sessions use date_start) -----------------
$w = new PluginIntegrasipamanApiWindow('date_start', $FROM, $TO);
$kept = $w->filter([
    ['id' => 's1', 'date_start' => '2026-09-10T10:00:00Z'],
    ['id' => 's2', 'date_start' => '2026-07-10T10:00:00Z'],
]);
check(count($kept) === 1 && $kept[0]['id'] === 's1', 'works on the sessions date_start field');

// --- offsets and fractional seconds parse -----------------------------------
$w = new PluginIntegrasipamanApiWindow('datetime', $FROM, $TO);
$kept = $w->filter([
    ['id' => 'x', 'datetime' => '2026-09-10T17:00:00.123456+07:00'],
    ['id' => 'y', 'datetime' => '2026-09-01T06:59:59+07:00'],  // = 2026-08-31T23:59:59Z, outside
]);
check(count($kept) === 1 && $kept[0]['id'] === 'x', 'timezone offsets and microseconds are honoured');

$s = $w->stats();
check(isset($s['kept'], $s['dropped'], $s['undated'], $s['ordered_desc']), 'stats() exposes the counters');

// --- sqlTime(): the event time that v4.5.0 writes into date_creation ------
// The trap this guards: JumpServer reports +0800 while GLPI runs +0700. A
// value copied across instead of converted lands an hour late, which throws
// anything near midnight onto the wrong day. This suite runs in UTC, so the
// live sample below must come back 8 hours earlier than its face value.
$W = 'PluginIntegrasipamanApiWindow';

check($W::sqlTime('2026/10/16 23:35:45 +0800') === '2026-10-16 15:35:45',
    'a +0800 JumpServer time is converted to the local zone, not copied');
check($W::sqlTime('2026/10/16 00:30:00 +0800') === '2026-10-15 16:30:00',
    'a time near midnight crosses to the previous day when the offset says so');
check($W::sqlTime('2026-10-16T23:35:45Z') === '2026-10-16 23:35:45',
    'the ISO form the audit endpoints use is accepted too');

check($W::sqlTime(null) === null, 'null is not a time');
check($W::sqlTime('') === null, 'empty is not a time');
check($W::sqlTime('   ') === null, 'blank is not a time');
check($W::sqlTime('not a date') === null, 'unparseable text is not a time');

// Out of TIMESTAMP range -> null, never a clamp to 1970 (suite rule 2e).
check($W::sqlTime('1850-01-01T00:00:00Z') === null, 'a pre-epoch date is rejected, not clamped');
check($W::sqlTime('2099-01-01T00:00:00Z') === null, 'a post-2038 date is rejected, not clamped');
check($W::sqlTime('1970-01-01T00:00:00Z') === null, 'the epoch itself is treated as no time at all');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
