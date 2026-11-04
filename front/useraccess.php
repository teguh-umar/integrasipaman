<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * User access management, Phase 1: which users can reach which assets, and
 * which group grants it. Read-only; gated on the report right, and every row
 * is limited to the entities the signed-in user is working in.
 * See inc/accessreport.class.php for where each count comes from.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);

// v4.11.0: this page is the Asset sub-tab. Without an asset-table parameter
// (or ?format=csv) the request is for User or Group, which live elsewhere.
include_once(__DIR__ . '/../inc/accessindex.class.php');
$ua_tab = PluginIntegrasipamanAccessIndex::tabFor($_GET);
if ($ua_tab !== 'asset') {
    Html::redirect(Plugin::getWebDir('integrasipaman') . '/front/' . ($ua_tab === 'group' ? 'accessgroup.php' : 'accessuser.php'));
}

include_once(__DIR__ . '/../inc/accessreport.class.php');
include_once(__DIR__ . '/../inc/exporter.class.php');

$R = 'PluginIntegrasipamanAccessReport';

// Request input is only ever a filter; each value is trimmed and capped by
// the report, and none of it reaches SQL as a fragment.
$filters = [
    'computer' => mb_substr(trim((string) ($_GET['computer'] ?? '')), 0, 100),
    'user'     => mb_substr(trim((string) ($_GET['user'] ?? '')), 0, 100),
    'group'    => mb_substr(trim((string) ($_GET['group'] ?? '')), 0, 100),
];

$report = $R::build($filters);

// --- CSV: before any HTML, from the very same rows the page shows ----------
if (($_GET['format'] ?? '') === 'csv') {
    $rows = [];
    foreach ($report['grants'] as $grant) {
        $members = $grant['type'] === 'group'
            ? ($grant['group_known']
                ? sprintf(__('%d member(s)', 'integrasipaman'), count($grant['group_members']))
                : __('unknown', 'integrasipaman'))
            : '';

        $rows[] = [
            (int) $grant['computers_id'],
            $R::assetIsKnown($grant)
                ? (string) $grant['computer_name']
                : $R::assetName($grant) . ' (' . $R::assetNote($grant) . ')',
            $grant['type'] === 'group'
                ? __('Group', 'integrasipaman')
                : __('Direct', 'integrasipaman'),
            (string) $grant['member_display'],
            $members,
            (string) $grant['via'],
            (string) $grant['accounts'],
            (string) $grant['actions'],
            $grant['valid'] ? __('Yes', 'integrasipaman') : __('No', 'integrasipaman'),
        ];
    }

    PluginIntegrasipamanExporter::sendCsv(
        'JumpServer_user_access_' . date('Ymd') . '.csv',
        [
            __('Computer ID', 'integrasipaman'),
            __('Computer', 'integrasipaman'),
            __('Granted through', 'integrasipaman'),
            __('Member', 'integrasipaman'),
            __('Group members', 'integrasipaman'),
            __('Rule', 'integrasipaman'),
            __('Accounts', 'integrasipaman'),
            __('Actions', 'integrasipaman'),
            __('Valid', 'integrasipaman'),
        ],
        $rows
    );
    exit;
}

Html::header(
    __('User access', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'useraccess'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'useraccess');
PluginIntegrasipamanAccessIndex::renderTabs('asset');

$self      = Plugin::getWebDir('integrasipaman') . '/front/useraccess.php';
$csv_query = http_build_query(array_filter($filters, static fn ($v): bool => $v !== '') + ['tab' => 'asset', 'format' => 'csv']);

/** A membership warning that a reader can act on, or nothing. */
$warnings = static function (array $report): void {
    $membership = $report['membership'];

    if (!$membership['collected']) {
        echo '<div class="alert alert-warning">';
        echo htmlescape(__(
            'Group membership has never been collected, so this report cannot count access granted through a group. The rows below are only the people and groups named directly on each rule. Group-granted access is missing until the entitlement cron runs.',
            'integrasipaman'
        ));
        echo '</div>';
        return;
    }

    if ($membership['stale']) {
        echo '<div class="alert alert-warning">';
        echo htmlescape(sprintf(
            __('Group membership was last collected at %s. It is used as-is; the counts below may be out of date until the entitlement cron runs again.', 'integrasipaman'),
            (string) ($membership['at'] ?? __('an unknown time', 'integrasipaman'))
        ));
        echo '</div>';
    }

    if ($report['coverage']['missing'] !== []) {
        echo '<div class="alert alert-warning">';
        echo htmlescape(__('Membership is unknown for these granting groups:', 'integrasipaman'));
        echo ' <strong>' . htmlescape(implode(', ', $report['coverage']['missing'])) . '</strong>. ';
        echo htmlescape(__('They are shown as unknown, not as empty.', 'integrasipaman'));
        echo '</div>';
    }

    if ($report['coverage']['mismatch'] !== []) {
        echo '<div class="alert alert-warning">';
        echo htmlescape(__('The stored member count disagrees with the count JumpServer reports for:', 'integrasipaman'));
        echo ' <strong>' . htmlescape(implode(', ', $report['coverage']['mismatch'])) . '</strong>. ';
        echo htmlescape(__('The stored membership is used, and the disagreement is shown beside the count.', 'integrasipaman'));
        echo '</div>';
    }
};

echo '<div class="card"><div class="card-body">';

echo '<form method="get" action="' . htmlescape($self) . '" class="row g-2 align-items-end mb-3">';
echo '<input type="hidden" name="tab" value="asset">';
echo '<div class="col-md-3"><label class="form-label" for="ua-computer">' . htmlescape(__('Computer', 'integrasipaman')) . '</label>'
    . '<input type="text" class="form-control" id="ua-computer" name="computer" maxlength="100" value="' . htmlescape($filters['computer']) . '"'
    . ' placeholder="' . htmlescape(__('ID or name', 'integrasipaman')) . '"></div>';
echo '<div class="col-md-3"><label class="form-label" for="ua-user">' . htmlescape(__('User', 'integrasipaman')) . '</label>'
    . '<input type="text" class="form-control" id="ua-user" name="user" maxlength="100" value="' . htmlescape($filters['user']) . '"'
    . ' placeholder="' . htmlescape(__('Account or login', 'integrasipaman')) . '"></div>';
echo '<div class="col-md-3"><label class="form-label" for="ua-group">' . htmlescape(__('Group', 'integrasipaman')) . '</label>'
    . '<input type="text" class="form-control" id="ua-group" name="group" maxlength="100" value="' . htmlescape($filters['group']) . '"'
    . ' placeholder="' . htmlescape(__('ID or name', 'integrasipaman')) . '"></div>';
echo '<div class="col-auto"><button type="submit" class="btn btn-primary">' . htmlescape(__('Apply', 'integrasipaman')) . '</button> '
    . '<a class="btn btn-outline-secondary" href="' . htmlescape($self . '?tab=asset') . '">' . htmlescape(__('Reset', 'integrasipaman')) . '</a></div>';
echo '<div class="col-auto ms-auto"><a class="btn btn-outline-secondary" href="' . htmlescape($self . '?' . $csv_query) . '">'
    . '<i class="ti ti-download me-1"></i>' . htmlescape(__('Export CSV', 'integrasipaman')) . '</a></div>';
echo '</form>';

$warnings($report);

echo '<p class="text-muted">' . htmlescape(sprintf(
    __('%1$d grant path(s): %2$d direct, %3$d through a group. They reach %4$d asset(s) through %5$d group(s).', 'integrasipaman'),
    $report['summary']['grants'],
    $report['summary']['direct'],
    $report['summary']['group'],
    $report['summary']['assets'],
    $report['summary']['groups']
)) . '</p>';

// An asset GLPI no longer holds is still reachable through the rule that names
// it, so the row stays and is counted here. Saying the number out loud is the
// point: 166 of 2650 cached map rows on this instance are in that state, and a
// reader who does not know that reads every "#1635" as a glitch.
if ($report['summary']['gone_paths'] > 0) {
    echo '<p class="text-muted">' . htmlescape(sprintf(
        PluginIntegrasipamanServerLabel::in(__('%1$d of these path(s) grant access to %2$d asset(s) that GLPI no longer holds. The rule was never revoked, so the access is still real - only the asset record is gone. Those rows are named from the host list of {server}, with the GLPI id kept beside the name so the rule can be chased; a row shows the bare id when {server} does not list the asset either.', 'integrasipaman')),
        $report['summary']['gone_paths'],
        $report['summary']['gone_assets']
    )) . '</p>';
}

echo '<p class="form-hint">' . htmlescape(__(
    'A grant path is one way in: a rule that names a person, or a rule that names a group. The same group reaching one asset through two rules is two paths. The members of a group are not expanded into separate rows here - that would count people rather than ways in.',
    'integrasipaman'
)) . '</p>';

// --- grant paths ------------------------------------------------------------
echo '<h3 class="h4">' . htmlescape(__('Who can reach what', 'integrasipaman')) . '</h3>';

// --- paging (v4.9.0-rc2) --------------------------------------------------
// 4002 grant paths on this instance, rendered in one page, is a wall nobody
// reads and a slow one to draw. The slice is DISPLAY ONLY: build() still
// computes every path, the counts above still describe the whole filtered
// set, and the CSV above still exports all of it. Paging an export would
// quietly hand someone one page and call it the data.
//
// Labels and markup copy PluginIntegrasipamanReport::renderPager() on
// purpose - that is the pager this plugin already has. It is private there,
// so it is matched rather than called; one pager that looks like two would
// be worse than two that look like one.
$per_page     = 100;
$grants_all   = $report['grants'];
$grants_total = count($grants_all);
$pages        = max(1, (int) ceil($grants_total / $per_page));
$page         = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$grants_page  = array_slice($grants_all, ($page - 1) * $per_page, $per_page);

/** This page's URL on a given page number, carrying the active filters. */
$page_url = static function (int $p) use ($self, $filters): string {
    return $self . '?' . http_build_query(
        array_filter($filters, static fn ($v) => $v !== '') + ['tab' => 'asset', 'page' => $p]
    );
};

$render_pager = static function () use ($page, $pages, $grants_total, $page_url): void {
    if ($pages <= 1) {
        return;
    }

    echo '<div class="d-flex gap-2 align-items-center mb-2">';
    echo '<span class="text-muted">' . htmlescape(sprintf(
        __('Page %1$d of %2$d (%3$d rows)', 'integrasipaman'),
        $page,
        $pages,
        $grants_total
    )) . '</span>';

    if ($page > 1) {
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . htmlescape($page_url($page - 1)) . '">'
            . htmlescape(__('Previous', 'integrasipaman')) . '</a>';
    }
    if ($page < $pages) {
        echo '<a class="btn btn-sm btn-outline-secondary" href="' . htmlescape($page_url($page + 1)) . '">'
            . htmlescape(__('Next', 'integrasipaman')) . '</a>';
    }
    echo '</div>';
};

$render_pager();
echo '<div class="table-responsive mb-4"><table class="table table-sm table-striped card-table"><thead><tr>';
foreach ([
    __('Computer', 'integrasipaman'),
    __('Granted through', 'integrasipaman'),
    __('Member', 'integrasipaman'),
    __('Rule', 'integrasipaman'),
    __('Accounts', 'integrasipaman'),
    __('Actions', 'integrasipaman'),
    __('Valid', 'integrasipaman'),
] as $heading) {
    echo '<th>' . htmlescape($heading) . '</th>';
}
echo '</tr></thead><tbody>';

if ($report['grants'] === []) {
    echo '<tr><td colspan="7" class="text-muted">'
        . htmlescape(__('No grant path matches this selection.', 'integrasipaman'))
        . '</td></tr>';
}

foreach ($grants_page as $grant) {
    $note = $R::assetNote($grant);

    echo '<tr><td>' . htmlescape($R::assetName($grant));
    if ($note !== '') {
        echo ' <span class="text-muted">(' . htmlescape($note) . ')</span>';
    }
    echo '</td>';

    echo '<td>';
    if ($grant['type'] === 'group') {
        echo '<span class="badge bg-blue-lt">' . htmlescape(__('Group', 'integrasipaman')) . '</span>';
    } else {
        echo '<span class="badge bg-secondary-lt">' . htmlescape(__('Direct', 'integrasipaman')) . '</span>';
    }
    echo '</td>';

    echo '<td>' . htmlescape((string) $grant['member_display']);
    if ($grant['type'] === 'group') {
        if ($grant['group_known']) {
            // A known group with zero members is a real zero; an unknown one
            // must never render as zero.
            echo ' <span class="text-muted">(' . htmlescape(sprintf(
                _n('%d member', '%d members', count($grant['group_members']), 'integrasipaman'),
                count($grant['group_members'])
            )) . ')</span>';

            $names = [];
            foreach (array_slice($grant['group_members'], 0, 5) as $member) {
                $label = trim((string) $member['display']);
                if ($label === '') {
                    $label = (string) $member['login'];
                }
                if ($label !== '') {
                    $names[] = $label;
                }
            }
            if ($names !== []) {
                echo ' <span class="text-muted">' . htmlescape(implode(', ', $names));
                if (count($grant['group_members']) > 5) {
                    echo ' ' . htmlescape(sprintf(__('+%d more', 'integrasipaman'), count($grant['group_members']) - 5));
                }
                echo '</span>';
            }
        } else {
            echo ' <span class="badge bg-yellow-lt">' . htmlescape(__('membership unknown', 'integrasipaman')) . '</span>';
        }
    }
    echo '</td>';

    echo '<td>' . htmlescape((string) $grant['via']) . '</td>'
        . '<td>' . htmlescape((string) $grant['accounts']) . '</td>'
        . '<td>' . htmlescape((string) $grant['actions']) . '</td>'
        . '<td>' . ($grant['valid']
            ? htmlescape(__('Yes', 'integrasipaman'))
            : '<span class="badge bg-red-lt">' . htmlescape(__('Expired', 'integrasipaman')) . '</span>')
        . '</td></tr>';
}
echo '</tbody></table></div>';

// The same pager under the table: after a hundred rows the top one has
// scrolled away, and a reader who reached the bottom is exactly the reader
// who wants the next page.
$render_pager();

// The group list lives on the Group sub-tab (front/accessgroup.php) since
// 4.11.0; a second copy under this table only repeated it.

echo '</div></div>';

Html::footer();
