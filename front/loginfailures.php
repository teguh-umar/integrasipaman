<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Login failures (v4.3.0): why logins were refused, which accounts were locked
 * out and which keep failing. Read-only; gated on the report right, and the
 * rows are those of the entities the signed-in user is working in.
 * See inc/loginfailures.class.php for where each column comes from.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);

include_once(__DIR__ . '/../inc/loginfailures.class.php');
include_once(__DIR__ . '/../inc/exporter.class.php');

$L = 'PluginIntegrasipamanLoginFailures';

// Request input is only ever a filter: two dates, a search text and one switch,
// each validated or trimmed by find(); nothing here reaches SQL.
$filters = [
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to'   => trim((string) ($_GET['to'] ?? '')),
    'q'    => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
    'only' => ($_GET['only'] ?? '') === 'locked' ? 'locked' : '',
    // One reason, picked from the Reasons table (compared without its numbers).
    'reason' => mb_substr(trim((string) ($_GET['reason'] ?? '')), 0, 300),
];

$items = $L::find($filters);

// --- CSV: before any HTML, from the very same list the page shows ----------
if (($_GET['format'] ?? '') === 'csv') {
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [
            $item['at'],
            $item['username'],
            $item['ip'],
            $item['city'],
            $item['reason'],
            $item['locked'] ? __('Yes', 'integrasipaman') : __('No', 'integrasipaman'),
            $item['backend'],
            $item['mfa'],
        ];
    }

    PluginIntegrasipamanExporter::sendCsv(
        'JumpServer_login_failures_' . date('Ymd') . '.csv',
        [
            __('Time', 'integrasipaman'),
            __('Username', 'integrasipaman'),
            __('IP address', 'integrasipaman'),
            __('City', 'integrasipaman'),
            __('Reason', 'integrasipaman'),
            __('Locked out', 'integrasipaman'),
            __('Backend', 'integrasipaman'),
            __('MFA', 'integrasipaman'),
        ],
        $rows
    );
    exit;
}

Html::header(
    __('Login failures', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'loginfailures'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'loginfailures');

$self      = Plugin::getWebDir('integrasipaman') . '/front/loginfailures.php';
$locked    = count(array_filter($items, static fn (array $i): bool => $i['locked']));
$repeated  = $L::repeated($items);
$reasons   = $L::byReason($items);
$csv_query = http_build_query(array_filter($filters, static fn ($v) => $v !== '') + ['format' => 'csv']);
$yes       = __('Yes', 'integrasipaman');

echo '<div class="card"><div class="card-body">';

echo '<form method="get" action="' . htmlescape($self) . '" class="row g-2 align-items-end mb-3">';
echo '<div class="col-auto"><label class="form-label" for="lf-from">' . htmlescape(__('From', 'integrasipaman')) . '</label>'
    . '<input type="date" class="form-control" id="lf-from" name="from" value="' . htmlescape($filters['from']) . '"></div>';
echo '<div class="col-auto"><label class="form-label" for="lf-to">' . htmlescape(__('To', 'integrasipaman')) . '</label>'
    . '<input type="date" class="form-control" id="lf-to" name="to" value="' . htmlescape($filters['to']) . '"></div>';
echo '<div class="col-md-3"><label class="form-label" for="lf-q">' . htmlescape(__('Search', 'integrasipaman')) . '</label>'
    . '<input type="text" class="form-control" id="lf-q" name="q" maxlength="100" value="' . htmlescape($filters['q']) . '"'
    . ' placeholder="' . htmlescape(__('Username, address or reason', 'integrasipaman')) . '"></div>';
echo '<div class="col-auto"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="lf-only" name="only" value="locked"'
    . ($filters['only'] === 'locked' ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="lf-only">' . htmlescape(__('Lockouts only', 'integrasipaman')) . '</label></div></div>';
if ($filters['reason'] !== '') {
    echo '<input type="hidden" name="reason" value="' . htmlescape($filters['reason']) . '">';
    echo '<div class="col-auto"><span class="badge bg-blue-lt">' . htmlescape(__('One reason selected', 'integrasipaman')) . '</span></div>';
}
echo '<div class="col-auto"><button type="submit" class="btn btn-primary">' . htmlescape(__('Apply', 'integrasipaman')) . '</button> '
    . '<a class="btn btn-outline-secondary" href="' . htmlescape($self) . '">' . htmlescape(__('Reset', 'integrasipaman')) . '</a></div>';
echo '<div class="col-auto ms-auto"><a class="btn btn-outline-secondary" href="' . htmlescape($self . '?' . $csv_query) . '">'
    . '<i class="ti ti-download me-1"></i>' . htmlescape(__('Export CSV', 'integrasipaman')) . '</a></div>';
echo '</form>';

echo '<p class="text-muted">' . htmlescape(sprintf(
    __('%1$d failed login(s): %2$d refused for too many or too frequent attempts, %3$d account(s) with %4$d or more failures.', 'integrasipaman'),
    count($items),
    $locked,
    count($repeated),
    $L::REPEAT_THRESHOLD
)) . '</p>';

// --- reasons: every reason, so a lockout the keyword list misses is still visible
echo '<h3 class="h4">' . htmlescape(__('Reasons', 'integrasipaman')) . '</h3>';
echo '<div class="table-responsive mb-4"><table class="table table-sm table-striped card-table"><thead><tr>'
    . '<th>' . htmlescape(__('Reason', 'integrasipaman')) . '</th><th>' . htmlescape(__('Count', 'integrasipaman')) . '</th>'
    . '<th>' . htmlescape(__('Accounts', 'integrasipaman')) . '</th><th></th></tr></thead><tbody>';
if ($reasons === []) {
    echo '<tr><td colspan="4">' . htmlescape(__('No failed login in this selection.', 'integrasipaman')) . '</td></tr>';
}
$shown_accounts = 5;
foreach ($reasons as $reason) {
    // Who: the accounts behind this reason, most failures first, each a link to that
    // account's attempts. The reason itself links to the attempts with this reason.
    $who = [];
    foreach (array_slice($reason['who'], 0, $shown_accounts) as $w) {
        $who[] = '<a href="' . htmlescape($self . '?' . http_build_query(['q' => $w['username']])) . '">' . htmlescape($w['username']) . '</a>'
            . ' <span class="text-muted">(' . (int) $w['count'] . ')</span>';
    }
    $more = count($reason['who']) - $shown_accounts;
    echo '<tr><td><a href="' . htmlescape($self . '?' . http_build_query(['reason' => $reason['reason']])) . '">'
        . htmlescape($reason['reason'] !== '' ? $reason['reason'] : __('Unknown', 'integrasipaman')) . '</a></td>'
        . '<td>' . (int) $reason['count'] . '</td><td>' . implode(', ', $who)
        . ($more > 0 ? ' <span class="text-muted">' . htmlescape(sprintf(__('+%d more', 'integrasipaman'), $more)) . '</span>' : '')
        . '</td><td>'
        . ($reason['locked'] ? '<span class="badge bg-red-lt">' . htmlescape(__('Lockout', 'integrasipaman')) . '</span>' : '')
        . '</td></tr>';
}
echo '</tbody></table></div>';

// --- accounts that keep failing ---------------------------------------------
echo '<h3 class="h4">' . htmlescape(sprintf(__('Accounts with %d or more failures', 'integrasipaman'), $L::REPEAT_THRESHOLD)) . '</h3>';
echo '<div class="table-responsive mb-4"><table class="table table-sm table-striped card-table"><thead><tr>'
    . '<th>' . htmlescape(__('Username', 'integrasipaman')) . '</th><th>' . htmlescape(__('Failures', 'integrasipaman')) . '</th>'
    . '<th>' . htmlescape(__('Addresses', 'integrasipaman')) . '</th><th>' . htmlescape(__('Last attempt', 'integrasipaman')) . '</th><th></th></tr></thead><tbody>';
if ($repeated === []) {
    echo '<tr><td colspan="5">' . htmlescape(__('No account reached that number in this selection.', 'integrasipaman')) . '</td></tr>';
}
foreach ($repeated as $account) {
    echo '<tr><td><a href="' . htmlescape($self . '?' . http_build_query(['q' => $account['username']])) . '">' . htmlescape($account['username']) . '</a></td>'
        . '<td>' . (int) $account['count'] . '</td><td>' . (int) $account['ips'] . '</td><td>' . htmlescape($account['last']) . '</td><td>'
        . ($account['locked'] ? '<span class="badge bg-red-lt">' . htmlescape(__('Lockout', 'integrasipaman')) . '</span>' : '')
        . '</td></tr>';
}
echo '</tbody></table></div>';

// --- every failed login -------------------------------------------------------
echo '<h3 class="h4">' . htmlescape(__('Failed logins', 'integrasipaman')) . '</h3>';
echo '<div class="table-responsive"><table class="table table-striped table-hover card-table"><thead><tr>';
foreach ([
    __('Time', 'integrasipaman'), __('Username', 'integrasipaman'), __('IP address', 'integrasipaman'),
    __('City', 'integrasipaman'), __('Reason', 'integrasipaman'),
] as $heading) {
    echo '<th>' . htmlescape($heading) . '</th>';
}
echo '</tr></thead><tbody>';
if ($items === []) {
    echo '<tr><td colspan="5">' . htmlescape(__('No failed login in this selection.', 'integrasipaman')) . '</td></tr>';
}
foreach ($items as $item) {
    // Every value below is text that came from JumpServer. htmlescape() on all of it.
    echo '<tr><td>' . htmlescape($item['at']);
    if (!$item['at_known'] && $item['at'] !== '') {
        echo ' <span class="text-muted" title="' . htmlescape(__('JumpServer gave no time for this attempt; this is when it was imported.', 'integrasipaman')) . '">*</span>';
    }
    echo '</td><td>' . htmlescape($item['username']) . '</td>'
        . '<td>' . htmlescape($item['ip']) . '</td>'
        . '<td>' . htmlescape($item['city']) . '</td>'
        . '<td>' . htmlescape($item['reason']);
    if ($item['locked']) {
        echo ' <span class="badge bg-red-lt">' . htmlescape(__('Lockout', 'integrasipaman')) . '</span>';
    }
    echo '</td></tr>';
}
echo '</tbody></table></div>';

echo '<p class="text-muted small mt-3 mb-0">' . htmlescape(__(
    'A lockout is a refusal whose reason says the account is or has been locked or blocked, or that there were too many or too frequent attempts. The warning "the account will be temporarily locked" on a wrong password is not one.',
    'integrasipaman'
)) . '</p>';

echo '</div></div>';

Html::footer();
