<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Session sharing (v4.2.0): who shared a session, with whom, which session and
 * until when. Read-only; gated on the report right, and the rows are those of
 * the entities the signed-in user is working in.
 * See inc/sessionsharing.class.php for where each column comes from.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);

include_once(__DIR__ . '/../inc/sessionsharing.class.php');
include_once(__DIR__ . '/../inc/exporter.class.php');

$S = 'PluginIntegrasipamanSessionSharing';

// Request input is only ever a filter: two dates and a search text, both
// validated or trimmed by find(); nothing here reaches SQL.
$filters = [
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to'   => trim((string) ($_GET['to'] ?? '')),
    'q'    => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

$shares = $S::find($filters);

$status_label = [
    $S::STATUS_ACTIVE   => __('Active', 'integrasipaman'),
    $S::STATUS_EXPIRED  => __('Expired', 'integrasipaman'),
    $S::STATUS_INACTIVE => __('Revoked', 'integrasipaman'),
];

// --- CSV: before any HTML, from the very same list the page shows ----------
if (($_GET['format'] ?? '') === 'csv') {
    $rows = [];
    foreach ($shares as $share) {
        $rows[] = [
            $share['shared_at'],
            $share['shared_by'],
            $S::recipientsText($share),
            $share['session_asset'],
            $share['session_user'],
            $share['session_id'],
            $share['expires_at'],
            $status_label[$share['status']] ?? $share['status'],
        ];
    }

    PluginIntegrasipamanExporter::sendCsv(
        'JumpServer_session_sharing_' . date('Ymd') . '.csv',
        [
            __('Shared at', 'integrasipaman'),
            __('Shared by', 'integrasipaman'),
            __('Shared with', 'integrasipaman'),
            __('Session asset', 'integrasipaman'),
            __('Session owner', 'integrasipaman'),
            __('Session ID', 'integrasipaman'),
            __('Expires at', 'integrasipaman'),
            __('Status', 'integrasipaman'),
        ],
        $rows
    );
    exit;
}

Html::header(
    __('Session sharing', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'sessionsharing'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'sessionsharing');

$self       = Plugin::getWebDir('integrasipaman') . '/front/sessionsharing.php';
$by_link    = count(array_filter($shares, static fn (array $s): bool => $s['by_link']));
$still_open = count(array_filter($shares, static fn (array $s): bool => $s['status'] === $S::STATUS_ACTIVE));
$csv_query  = http_build_query(array_filter($filters, static fn ($v) => $v !== '') + ['format' => 'csv']);

echo '<div class="card"><div class="card-body">';

echo '<form method="get" action="' . htmlescape($self) . '" class="row g-2 align-items-end mb-3">';
echo '<div class="col-auto"><label class="form-label" for="ss-from">' . htmlescape(__('From', 'integrasipaman')) . '</label>'
    . '<input type="date" class="form-control" id="ss-from" name="from" value="' . htmlescape($filters['from']) . '"></div>';
echo '<div class="col-auto"><label class="form-label" for="ss-to">' . htmlescape(__('To', 'integrasipaman')) . '</label>'
    . '<input type="date" class="form-control" id="ss-to" name="to" value="' . htmlescape($filters['to']) . '"></div>';
echo '<div class="col-md-4"><label class="form-label" for="ss-q">' . htmlescape(__('Search', 'integrasipaman')) . '</label>'
    . '<input type="text" class="form-control" id="ss-q" name="q" maxlength="100" value="' . htmlescape($filters['q']) . '"'
    . ' placeholder="' . htmlescape(__('Who shared, with whom, asset or session', 'integrasipaman')) . '"></div>';
echo '<div class="col-auto"><button type="submit" class="btn btn-primary">' . htmlescape(__('Apply', 'integrasipaman')) . '</button> '
    . '<a class="btn btn-outline-secondary" href="' . htmlescape($self) . '">' . htmlescape(__('Reset', 'integrasipaman')) . '</a></div>';
echo '<div class="col-auto ms-auto"><a class="btn btn-outline-secondary" href="' . htmlescape($self . '?' . $csv_query) . '">'
    . '<i class="ti ti-download me-1"></i>' . htmlescape(__('Export CSV', 'integrasipaman')) . '</a></div>';
echo '</form>';

echo '<p class="text-muted">' . htmlescape(sprintf(
    __('%1$d share(s): %2$d still open, %3$d made by link and code.', 'integrasipaman'),
    count($shares),
    $still_open,
    $by_link
)) . '</p>';

echo '<div class="table-responsive"><table class="table table-striped table-hover card-table">';
echo '<thead><tr>';
foreach ([
    __('Shared at', 'integrasipaman'), __('Shared by', 'integrasipaman'), __('Shared with', 'integrasipaman'),
    __('Session', 'integrasipaman'), __('Expires at', 'integrasipaman'), __('Status', 'integrasipaman'),
] as $heading) {
    echo '<th>' . htmlescape($heading) . '</th>';
}
echo '</tr></thead><tbody>';

if ($shares === []) {
    echo '<tr><td colspan="6">' . htmlescape(__('No session was shared in this selection.', 'integrasipaman')) . '</td></tr>';
}

foreach ($shares as $share) {
    // Every value below is text that came from JumpServer. htmlescape() on all of it.
    echo '<tr>';

    echo '<td>' . htmlescape($share['shared_at']);
    if (!$share['shared_at_known'] && $share['shared_at'] !== '') {
        echo ' <span class="text-muted" title="' . htmlescape(__('JumpServer gave no time for this share; this is when it was imported.', 'integrasipaman')) . '">*</span>';
    }
    echo '</td>';

    echo '<td>' . htmlescape($share['shared_by']) . '</td>';

    echo '<td>';
    if ($share['by_link']) {
        echo '<span class="badge bg-yellow-lt">' . htmlescape($S::recipientsText($share)) . '</span>';
    } else {
        echo htmlescape($S::recipientsText($share));
    }
    echo '</td>';

    echo '<td>';
    if ($share['session_asset'] !== '' || $share['session_user'] !== '') {
        echo htmlescape($share['session_asset'] !== '' ? $share['session_asset'] : '-');
        echo '<div class="text-muted small">' . htmlescape($share['session_user'] !== '' ? $share['session_user'] : '-') . '</div>';
    } else {
        echo '<span class="text-muted small" title="' . htmlescape(__('The terminal pull does not hold this session (outside its window, or not yet pulled).', 'integrasipaman')) . '">'
            . htmlescape($share['session_id'] !== '' ? $share['session_id'] : '-') . '</span>';
    }
    echo '</td>';

    echo '<td>' . htmlescape($share['expires_at'] !== '' ? $share['expires_at'] : '-') . '</td>';

    $badge = [
        $S::STATUS_ACTIVE   => 'bg-green-lt',
        $S::STATUS_EXPIRED  => 'bg-secondary-lt',
        $S::STATUS_INACTIVE => 'bg-red-lt',
    ][$share['status']] ?? 'bg-secondary-lt';
    echo '<td><span class="badge ' . $badge . '">' . htmlescape($status_label[$share['status']] ?? $share['status']) . '</span></td>';

    echo '</tr>';
}

echo '</tbody></table></div>';

echo '<p class="text-muted small mt-3 mb-0">' . htmlescape(__(
    'Taken from the share records JumpServer keeps. A share made by link and code has no named recipient in them: who actually joined is not recorded here.',
    'integrasipaman'
)) . '</p>';

echo '</div></div>';

Html::footer();
