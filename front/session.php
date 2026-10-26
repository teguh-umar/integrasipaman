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
include_once(__DIR__ . '/../inc/sessionlog.class.php');
include_once(__DIR__ . '/../inc/exporter.class.php');

$S = 'PluginIntegrasipamanSessionSharing';
$L = 'PluginIntegrasipamanSessionLog';

// Request input is only ever a filter: two dates and a search text, both
// validated or trimmed by find(); nothing here reaches SQL.
$filters = [
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to'   => trim((string) ($_GET['to'] ?? '')),
    'q'    => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
];

// v4.6.0-rc3: open on the last 30 days, on BOTH tabs, when the visitor has
// chosen no window of their own.
//
// The test is whether the form has been submitted at all, not whether the
// dates are empty: the form always posts `from` and `to`, so once it has
// been used, two empty dates are a deliberate request for the whole history
// and must be honoured. Only a first arrival - no `from` key in the query -
// gets the default.
//
// v4.7.0: this now applies to the Session Sharing tab only. Log Session is
// rendered by the Search engine, which paginates - so the truncation the
// default was introduced to soften (39,800 sessions against a 5,000 read
// cap) no longer exists there, and Search carries its own criteria.
$window_defaulted = !isset($_GET['from']) && !isset($_GET['to']);
if ($window_defaulted) {
    $filters = $L::defaultWindow() + $filters;
}

// Which tab (v4.6.0). Resolved before Html::header() because it sets the
// page title, and before any query because the two tabs read two different
// tables - loading both would double the work on every view.
//
// "log" leads: it is the sessions themselves, the only dataset here with a
// real asset on every row. Sharing is the narrower question underneath it.
$tab = (($_GET['tab'] ?? 'log') === 'sharing') ? 'sharing' : 'log';

// v4.7.0: the Log Session tab is rendered by GLPI's Search engine, which
// runs its own query, its own criteria and its own paging - so this page
// loads nothing for it. Only the Session Sharing tab still reads here.
$shares = $tab === 'sharing' ? $S::find($filters)      : [];
$joins  = $tab === 'sharing' ? $S::findJoins($filters) : [];

$status_label = [
    $S::STATUS_ACTIVE   => __('Active', 'integrasipaman'),
    $S::STATUS_EXPIRED  => __('Expired', 'integrasipaman'),
    $S::STATUS_INACTIVE => __('Revoked', 'integrasipaman'),
];

// --- CSV: before any HTML, from the very same lists the page shows --------
// Two tables, so two exports. `what` picks one; the joins are the default
if ($tab === 'sharing' && ($_GET['format'] ?? '') === 'csv' && ($_GET['what'] ?? 'joins') === 'joins') {
    $rows = [];
    foreach ($joins as $join) {
        $rows[] = [
            $join['joined_at'],
            $join['left_at'],
            $join['joiner'],
            $join['from_ip'],
            $join['via'],
            $join['session_asset'],
            $join['session_user'],
            $join['session_id'],
            $join['succeeded'] ? __('Joined', 'integrasipaman') : __('Refused', 'integrasipaman'),
            $join['reason'],
        ];
    }

    PluginIntegrasipamanExporter::sendCsv(
        'JumpServer_session_joins_' . date('Ymd') . '.csv',
        [
            __('Joined at', 'integrasipaman'),
            __('Left at', 'integrasipaman'),
            __('Who joined', 'integrasipaman'),
            __('From address', 'integrasipaman'),
            __('Joined via', 'integrasipaman'),
            __('Session asset', 'integrasipaman'),
            __('Session owner', 'integrasipaman'),
            __('Session ID', 'integrasipaman'),
            __('Result', 'integrasipaman'),
            __('Reason', 'integrasipaman'),
        ],
        $rows
    );
    exit;
}

if ($tab === 'sharing' && ($_GET['format'] ?? '') === 'csv') {
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

// Both tabs sit under ONE menu option, 'session'. The suite sub-nav has no
// second level, and lib/pgnsuite/Menu.php is byte-identical across every
// plugin, so giving it one for this page alone would force six other owners
// to re-ship it (orchestrator ruling, 2026-10-22). The nesting lives inside
// this page instead.
$page_title = $tab === 'log'
    ? __('Log Session', 'integrasipaman')
    : __('Session sharing', 'integrasipaman');

Html::header(
    $page_title,
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'session'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'session');

$self = Plugin::getWebDir('integrasipaman') . '/front/sessionsharing.php';

/** This page's URL on a given tab, carrying the active filters over. */
$tab_url = static function (string $to) use ($self, $filters): string {
    $query = array_filter($filters, static fn ($v) => $v !== '') + ['tab' => $to];

    return $self . '?' . http_build_query($query);
};

echo '<ul class="nav nav-tabs mb-3">';
foreach ([
    'log'     => [__('Log Session', 'integrasipaman'), 'ti-list-details'],
    'sharing' => [__('Session Sharing', 'integrasipaman'), 'ti-share'],
] as $key => [$label, $icon]) {
    echo '<li class="nav-item"><a class="nav-link' . ($tab === $key ? ' active' : '') . '"'
        . ' href="' . htmlescape($tab_url($key)) . '">'
        . '<i class="ti ' . $icon . ' me-1"></i>' . htmlescape($label) . '</a></li>';
}
echo '</ul>';

if ($tab === 'log') {
    // GLPI's own list: a per-user column picker (stored in
    // glpi_displaypreferences), sorting, per-column criteria, saved searches,
    // paging and core's exports - none of which the hand-rolled table had.
    //
    // It replaces that table outright, so the custom From/To/Search form and
    // the 30-day default below are not rendered here: Search has its own
    // criteria, and paging removes the truncation those existed to soften.
    // Entity restriction is core's too, because the table carries entities_id.
    Search::show(PluginIntegrasipamanSession::class);

    Html::footer();
    exit;
}

echo '<div class="card"><div class="card-body">';

echo '<form method="get" action="' . htmlescape($self) . '" class="row g-2 align-items-end mb-3">';
// The form is a GET to this same page, so the tab has to ride along or
// applying a filter would silently bounce the user back to the first tab.
echo '<input type="hidden" name="tab" value="' . htmlescape($tab) . '">';
echo '<div class="col-auto"><label class="form-label" for="ss-from">' . htmlescape(__('From', 'integrasipaman')) . '</label>'
    . '<input type="date" class="form-control" id="ss-from" name="from" value="' . htmlescape($filters['from']) . '"></div>';
echo '<div class="col-auto"><label class="form-label" for="ss-to">' . htmlescape(__('To', 'integrasipaman')) . '</label>'
    . '<input type="date" class="form-control" id="ss-to" name="to" value="' . htmlescape($filters['to']) . '"></div>';
echo '<div class="col-md-4"><label class="form-label" for="ss-q">' . htmlescape(__('Search', 'integrasipaman')) . '</label>'
    . '<input type="text" class="form-control" id="ss-q" name="q" maxlength="100" value="' . htmlescape($filters['q']) . '"'
    . ' placeholder="' . htmlescape(__('Who shared, with whom, asset or session', 'integrasipaman')) . '"></div>';
echo '<div class="col-auto"><button type="submit" class="btn btn-primary">' . htmlescape(__('Apply', 'integrasipaman')) . '</button> '
    // Reset clears the filters but stays on this tab - $tab_url() carries
    // them over, which is the opposite of what Reset means.
    . '<a class="btn btn-outline-secondary" href="' . htmlescape($self . '?' . http_build_query(['tab' => $tab])) . '">'
    . htmlescape(__('Reset', 'integrasipaman')) . '</a></div>';

// Each tab exports what it shows. `what=links` is the share-link export;
// the log tab needs no discriminator because it has only one table.
$csv_query = http_build_query(
    array_filter($filters, static fn ($v) => $v !== '')
    + ['tab' => $tab, 'format' => 'csv']
    + ($tab === 'sharing' ? ['what' => 'links'] : [])
);
echo '<div class="col-auto ms-auto"><a class="btn btn-outline-secondary" href="' . htmlescape($self . '?' . $csv_query) . '">'
    . '<i class="ti ti-download me-1"></i>' . htmlescape(__('Export CSV', 'integrasipaman')) . '</a></div>';
echo '</form>';

// The default window is prefilled above, so it is visible - but "clear both
// dates to see everything" is not something anyone should have to guess.
// This matters most on the Session Sharing tab: a share LINK is a standing
// permission that can be months old and reused ever since (on this instance,
// two links from February cover every join), so a 30-day window can hide the
// very links the joins below it refer to.
$all_time_url = $self . '?' . http_build_query([
    'tab'  => $tab,
    'from' => '',
    'to'   => '',
] + ($filters['q'] !== '' ? ['q' => $filters['q']] : []));

if ($window_defaulted) {
    echo '<p class="text-muted small mb-3">'
        . htmlescape(sprintf(
            __('Showing the last %d days by default.', 'integrasipaman'),
            $L::DEFAULT_WINDOW_DAYS
        ))
        . ' <a href="' . htmlescape($all_time_url) . '">'
        . htmlescape(__('Show the whole history instead', 'integrasipaman')) . '</a>'
        . '</p>';
}


// --- Who joined a shared session (v4.5.0) --------------------------------
// This table leads, because it is the activity. The share links below are
// the standing permission that made it possible: on this instance two links
// account for every join since February, so a page that showed only links
// showed almost nothing.
$by_link    = count(array_filter($shares, static fn (array $s): bool => $s['by_link']));
$still_open = count(array_filter($shares, static fn (array $s): bool => $s['status'] === $S::STATUS_ACTIVE));
$refused    = count(array_filter($joins, static fn (array $j): bool => !$j['succeeded']));

echo '<h3 class="mb-2">' . htmlescape(__('Who joined a shared session', 'integrasipaman')) . '</h3>';

echo '<p class="text-muted">' . htmlescape(sprintf(
    __('%1$d join(s), %2$d of them refused.', 'integrasipaman'),
    count($joins),
    $refused
)) . ' <a href="' . htmlescape($self . '?' . http_build_query(
    array_filter($filters, static fn ($v) => $v !== '') + ['tab' => 'sharing', 'format' => 'csv', 'what' => 'joins']
)) . '">' . htmlescape(__('Export CSV', 'integrasipaman')) . '</a></p>';

echo '<div class="table-responsive"><table class="table table-striped table-hover card-table">';
echo '<thead><tr>';
foreach ([
    __('Joined at', 'integrasipaman'), __('Who joined', 'integrasipaman'), __('From', 'integrasipaman'),
    __('Session', 'integrasipaman'), __('Left at', 'integrasipaman'), __('Result', 'integrasipaman'),
] as $heading) {
    echo '<th>' . htmlescape($heading) . '</th>';
}
echo '</tr></thead><tbody>';

if ($joins === []) {
    echo '<tr><td colspan="6">' . htmlescape(__('Nobody joined a shared session in this selection.', 'integrasipaman')) . '</td></tr>';
}

foreach ($joins as $join) {
    // Every value below is text that came from JumpServer. htmlescape() on all of it.
    echo '<tr>';

    echo '<td>' . htmlescape($join['joined_at']);
    if (!$join['joined_at_known'] && $join['joined_at'] !== '') {
        echo ' <span class="text-muted" title="' . htmlescape(__('JumpServer gave no time for this join; this is when it was imported.', 'integrasipaman')) . '">*</span>';
    }
    echo '</td>';

    echo '<td>' . htmlescape($join['joiner'] !== '' ? $join['joiner'] : '-') . '</td>';

    echo '<td>' . htmlescape($join['from_ip'] !== '' ? $join['from_ip'] : '-');
    if ($join['via'] !== '') {
        echo '<div class="text-muted small">' . htmlescape($join['via']) . '</div>';
    }
    echo '</td>';

    echo '<td>';
    if ($join['session_asset'] !== '' || $join['session_user'] !== '') {
        echo htmlescape($join['session_asset'] !== '' ? $join['session_asset'] : '-');
        echo '<div class="text-muted small">' . htmlescape($join['session_user'] !== '' ? $join['session_user'] : '-') . '</div>';
    } else {
        echo '<span class="text-muted small" title="' . htmlescape(__('The terminal pull does not hold this session (outside its window, or not yet pulled).', 'integrasipaman')) . '">'
            . htmlescape($join['session_id'] !== '' ? $join['session_id'] : '-') . '</span>';
    }
    echo '</td>';

    echo '<td>' . htmlescape($join['left_at'] !== '' ? $join['left_at'] : '-') . '</td>';

    if ($join['succeeded']) {
        echo '<td><span class="badge bg-green-lt">' . htmlescape(__('Joined', 'integrasipaman')) . '</span></td>';
    } else {
        echo '<td><span class="badge bg-red-lt"'
            . ($join['reason'] !== '' ? ' title="' . htmlescape($join['reason']) . '"' : '')
            . '>' . htmlescape(__('Refused', 'integrasipaman')) . '</span></td>';
    }

    echo '</tr>';
}

echo '</tbody></table></div>';

// --- The share links themselves ------------------------------------------
echo '<h3 class="mt-4 mb-2">' . htmlescape(__('Share links created', 'integrasipaman')) . '</h3>';

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
    // A share link is a standing permission, not an event: it can be months
    // old and still be the one every join below came through. An empty list
    // under the default window is therefore the expected case here, not a
    // finding - and saying "none" without saying "in the last 30 days" is
    // how the Session Sharing card came to read zero in the first place.
    echo '<tr><td colspan="6">';
    if ($window_defaulted) {
        echo htmlescape(sprintf(
            __('No share link was created in the last %d days. Links made earlier can still be in use by the joins above.', 'integrasipaman'),
            $L::DEFAULT_WINDOW_DAYS
        ))
            . ' <a href="' . htmlescape($all_time_url) . '">'
            . htmlescape(__('Show the whole history', 'integrasipaman')) . '</a>';
    } else {
        echo htmlescape(__('No session was shared in this selection.', 'integrasipaman'));
    }
    echo '</td></tr>';
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
    'Two different records, both kept by JumpServer. A share link is a standing permission: one link can be reused for months and never appears again below. '
    . 'Who actually came in is the join table above, including the ones that were refused.',
    'integrasipaman'
)) . '</p>';

echo '</div></div>';

Html::footer();
