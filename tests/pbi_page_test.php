<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/pbi_page_test.php
//
// v4.5.0: when the PBI reporting database is unreachable, the Insights page
// said so four times - once inside each of four cards - and the KPI figures
// above, which come from this plugin's own event log and are perfectly fine,
// were pushed off the screen by four identical red boxes.
//
// One fact is now stated once, as a plain line with no card and no alert
// colour, and the detail cards are not drawn at all. What is pinned here:
//   - the page can tell an unreachable DATABASE from an empty VIEW: the
//     per-section message still exists for the second case;
//   - all sections are fetched before anything is drawn, because that is
//     what makes the distinction possible;
//   - the single notice is not a card and not an alert;
//   - it still tells the reader where to go and that the figures above
//     are unaffected - hiding the error must not hide the cause.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$page = file_get_contents(__DIR__ . '/../front/pbi.php');
$conn = file_get_contents(__DIR__ . '/../inc/pbiconnection.class.php');

check($page !== false && $conn !== false, 'both files are readable');

// The accessor that makes "said once" possible.
check(str_contains($conn, 'public function failedToConnect(): bool'),
      'the connection exposes whether it already failed to connect');

// Fetch everything, then decide. The order matters: deciding per section is
// exactly what produced four copies of one message.
$fetch_at  = strpos($page, '$fetched[] = [$title,');
$decide_at = strpos($page, '$pbi_connection->failedToConnect()');
check($fetch_at !== false, 'the page collects every section before drawing');
check($decide_at !== false, 'and then asks once whether the database was reachable');
check($fetch_at < $decide_at, 'the fetch happens before the decision, not inside each card');

// The single notice: no card, no alert colouring.
$notice_at = strpos($page, 'ti-database-off');
check($notice_at !== false, 'the unreachable-database notice exists');
check(str_contains(substr($page, $notice_at - 200, 260), 'text-muted'),
      'it is a muted line');
check(!str_contains(substr($page, $notice_at - 200, 400), 'alert-danger'),
      'it is not a red alert');
check(!str_contains(substr($page, $notice_at - 200, 400), '<div class="card'),
      'and it is not wrapped in a card');

// Hiding the noise must not hide the cause.
check(str_contains($page, "front/config.form.php"), 'the notice links to the configuration');
check(str_contains($page, 'are unaffected'), 'and says the figures above are still good');

// The per-section message survives, for a view that fails while the database
// itself is reachable. Deleting it would turn a broken view into silence.
check(str_contains($page, 'Could not connect to the PBI reporting database'),
      'the per-section failure message is still there for a single failing view');
check(preg_match('/if \(\$rows === null\) \{/', $page) === 1,
      'and the section renderer still handles a null result');

// The four sections are still all present after the restructure.
foreach (['VIEW_SESSIONS', 'VIEW_COMMANDS', 'VIEW_LOGIN_FAILURES', 'VIEW_SESSION_SHARING'] as $view) {
    check(str_contains($page, $view), "section $view is still on the page");
}

// Rendering happens through the one helper, in a loop - not four hand-written
// calls that could drift apart again.
check(preg_match('/foreach \(\$fetched as \[\$title, \$rows, \$columns\]\) \{\s*jumpserver_pbi_render_section/', $page) === 1,
      'the sections are drawn by one loop over the fetched results');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
