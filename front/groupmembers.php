<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The members of one group, as an HTML fragment for the pop-up behind the
 * member count on User access > Group. Renders no GLPI chrome: qtip loads
 * it over AJAX into the pop-up.
 *
 * The group is loaded by id, so its entity is checked here, against the
 * row: without that, anyone holding the report right could read another
 * entity's members by guessing ids. A missing row and a row of another
 * entity answer the same 403, so the answer does not reveal which ids exist.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);

include_once(__DIR__ . '/../inc/entitlement.class.php');
include_once(__DIR__ . '/../inc/serverlabel.class.php');

global $DB;

$FK = PluginIntegrasipamanConnection::FK;

$row = null;
foreach ($DB->request([
    'SELECT' => ['id', 'jms_id', 'entities_id', $FK],
    'FROM'   => PluginIntegrasipamanAccessIndex::GROUPS_TABLE,
    'WHERE'  => ['id' => (int) ($_GET['id'] ?? 0)],
    'LIMIT'  => 1,
]) as $found) {
    $row = $found;
}

header('Content-Type: text/html; charset=UTF-8');

if ($row === null || !Session::haveAccessToEntity((int) $row['entities_id'])) {
    http_response_code(403);
    echo htmlescape(__('You are not allowed to see this group.', 'integrasipaman'));
    exit;
}

// Membership is pulled with the group list, per connection; the empty-jms_id
// marker records a pull that found no group. No row at all for this
// connection means nobody ever asked - not "nobody is in this group".
$collected = count($DB->request([
    'SELECT' => ['id'],
    'FROM'   => PluginIntegrasipamanEntitlement::GROUPS_TABLE,
    'WHERE'  => [$FK => (int) $row[$FK]],
    'LIMIT'  => 1,
])) > 0;

if (!$collected) {
    echo '<span class="text-muted">'
        . htmlescape(PluginIntegrasipamanServerLabel::in(__('Group membership has not been collected from {server} yet.', 'integrasipaman')))
        . '</span>';
    exit;
}

$members = [];
foreach ($DB->request([
    'SELECT' => ['user_login', 'user_display'],
    'FROM'   => PluginIntegrasipamanEntitlement::MEMBERS_TABLE,
    'WHERE'  => [
        $FK                  => (int) $row[$FK],
        'user_groups_jms_id' => (string) $row['jms_id'],
    ],
]) as $member) {
    $login   = trim((string) ($member['user_login'] ?? ''));
    $display = trim((string) ($member['user_display'] ?? ''));
    if ($display === '') {
        $display = $login;
    }
    if ($display === '') {
        continue;
    }
    $members[] = [
        'display' => $display,
        'label'   => ($login !== '' && $login !== $display) ? $display . ' (' . $login . ')' : $display,
    ];
}

usort($members, static function (array $a, array $b): int {
    return strcasecmp($a['display'], $b['display']) ?: strcmp($a['label'], $b['label']);
});

if ($members === []) {
    echo '<span class="text-muted">' . htmlescape(__('This group has no members.', 'integrasipaman')) . '</span>';
    exit;
}

echo '<ul class="list-unstyled mb-0">';
foreach ($members as $member) {
    echo '<li>' . htmlescape($member['label']) . '</li>';
}
echo '</ul>';
