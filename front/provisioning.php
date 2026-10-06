<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The provisioning queue: which GLPI assets are eligible for PAMAN, which
 * are live there, which have drifted, and which are waiting to be removed.
 * Every change to PAMAN in this plugin starts with a button on this page.
 *
 * POSTs render their own result inline rather than redirecting - a plugin
 * front file's $_SERVER['PHP_SELF'] under GLPI 11's Symfony routing is not
 * the real path (see front/correlation.php).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, READ);

include_once(__DIR__ . '/../inc/provisioning.class.php');
include_once(__DIR__ . '/../inc/provisioningaction.class.php');
include_once(__DIR__ . '/../inc/nodemap.class.php');
include_once(__DIR__ . '/../inc/platformmap.class.php');

$P = 'PluginIntegrasipamanProvisioning';

$self = Plugin::getWebDir('integrasipaman') . '/front/provisioning.php';

$can_send   = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, CREATE);
$can_remove = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, PURGE);

// Statuses on which Send/Resend is ever offered. `provisioned` is
// deliberately excluded: the transition table has no provisioned ->
// provisioned move, so a click there would mark a healthy asset `failed`.
const SENDABLE_STATUSES = [
    PluginIntegrasipamanProvisioning::STATUS_ELIGIBLE,
    PluginIntegrasipamanProvisioning::STATUS_DRIFT,
    PluginIntegrasipamanProvisioning::STATUS_FAILED,
];

$tab = (string) ($_GET['tab'] ?? PluginIntegrasipamanProvisioning::STATUS_ELIGIBLE);
if (!in_array($tab, $P::allStatuses(), true)) {
    $tab = PluginIntegrasipamanProvisioning::STATUS_ELIGIBLE;
}

$results     = [];
$confirm_ids = null;

/**
 * v4.0.0. Load a queue row the signed-in user may act on, or null.
 *
 * Holding the provisioning right says the user may work the queue
 * somewhere; it does not say this row is theirs. Every branch below that
 * takes a row id from the request goes through here, so an id from another
 * subsidiary's queue is simply "not found" - not sent, not removed, and
 * not named in the result list.
 */
function jumpserver_provisioning_row(int $row_id): ?PluginIntegrasipamanProvisioning
{
    $row = new PluginIntegrasipamanProvisioning();
    if ($row_id <= 0
        || !$row->getFromDB($row_id)
        || !PluginIntegrasipamanConnection::canSee((int) ($row->fields['entities_id'] ?? 0))
    ) {
        return null;
    }

    return $row;
}

/**
 * v4.0.0. The node and platform mappings of the connection a queue row
 * belongs to. They used to be read once for the page; a node id now means
 * something only inside one JumpServer.
 *
 * @param array<string,mixed> $row_fields
 * @return array{0: array, 1: array} [node lookup, platform lookup]
 */
function jumpserver_provisioning_lookups(array $row_fields): array
{
    static $cache = [];

    $id = (int) ($row_fields[PluginIntegrasipamanConnection::FK] ?? 0);
    if (!isset($cache[$id])) {
        $read       = static fn (): array => [PluginIntegrasipamanNodeMap::asLookup(), PluginIntegrasipamanPlatformMap::asLookup()];
        $connection = PluginIntegrasipamanConnection::byId($id);
        $cache[$id] = $connection === null ? $read() : PluginIntegrasipamanConnection::using($connection, $read);
    }

    return $cache[$id];
}

/**
 * @param list<int> $ids
 * @return list<array{id:int, name:string, node_label:string, platform_label:string, blocked:string}>
 */
function jumpserver_provisioning_load_confirm_rows(array $ids): array
{
    $P = 'PluginIntegrasipamanProvisioning';
    $out = [];

    foreach ($ids as $row_id) {
        $row = jumpserver_provisioning_row((int) $row_id);
        if ($row === null) {
            continue;
        }
        [$node_lookup, $platform_lookup] = jumpserver_provisioning_lookups($row->fields);

        $computer = new Computer();
        if (!$computer->getFromDB((int) $row->fields['computers_id'])) {
            continue;
        }

        $fields = $computer->fields;
        $targets = $P::resolveTargets(
            $fields + [
                'type_name' => Dropdown::getDropdownName('glpi_computertypes', (int) ($fields['computertypes_id'] ?? 0)),
                'os_name'   => Dropdown::getDropdownName('glpi_operatingsystems', (int) ($fields['operatingsystems_id'] ?? 0)),
            ],
            $node_lookup,
            $platform_lookup
        );

        $out[] = [
            'id'             => $row_id,
            'name'           => (string) ($fields['name'] ?? ''),
            'node_label'     => $targets['node_label'],
            'platform_label' => $targets['platform_label'],
            'blocked'        => $targets['blocked'],
        ];
    }

    return $out;
}

// --- Step: send / resend, after confirmation --------------------------
// Checked here, in the branch that acts - not only where the button is
// drawn. A hidden button is a courtesy; this is the control.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['send'])) {
    if (!Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, CREATE)) {
        Html::displayRightError();
        exit;
    }

    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['rows'] ?? [])), static fn ($n) => $n > 0));

    foreach ($ids as $row_id) {
        // Re-check the row's CURRENT status before calling send(), not just
        // trust that the browser only posted what the table offered a
        // checkbox for. A hand-crafted POST naming an already-`provisioned`
        // row would otherwise reach send(), which has no provisioned ->
        // provisioned transition and would mark a healthy asset `failed`.
        $status_check = jumpserver_provisioning_row($row_id);
        if ($status_check === null) {
            $results[$row_id] = ['ok' => false, 'message' => __('Provisioning row not found', 'integrasipaman')];
            continue;
        }
        if (!in_array($status_check->fields['status'], SENDABLE_STATUSES, true)) {
            $results[$row_id] = [
                'ok'      => false,
                'message' => sprintf(
                    __('Skipped: row is already "%s" and is not offered for send/resend.', 'integrasipaman'),
                    (string) $status_check->fields['status']
                ),
            ];
            continue;
        }

        // One asset's failure must not abort the rest of the batch: an
        // admin who selected twelve assets wants the eleven that can go.
        $results[$row_id] = PluginIntegrasipamanProvisioningAction::send(
            $row_id,
            (int) Session::getLoginUserID()
        );
    }
}

// --- Step: the confirmation step, before anything is sent -------------
// Bulk-creating hosts in a production bastion takes two deliberate
// actions, not one: this renders a summary and re-poses the same row ids
// as hidden inputs, and only the Confirm button's POST carries `send`.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['confirm_send']) && empty($_POST['send'])) {
    if (!Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, CREATE)) {
        Html::displayRightError();
        exit;
    }

    $confirm_ids = array_values(array_filter(array_map('intval', (array) ($_POST['rows'] ?? [])), static fn ($n) => $n > 0));
}

// --- Step: remove, against a ticket that covers the asset --------------
// Deliberately one row at a time - there is no bulk remove. Sending a
// batch of assets into a bastion is routine; taking a batch out is not,
// and each removal is meant to cite its own ticket.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['remove'])) {
    if (!$can_remove) {
        Html::displayRightError();
        exit;
    }

    $results[(int) $_POST['row']] = jumpserver_provisioning_row((int) $_POST['row']) === null
        ? ['ok' => false, 'message' => __('Provisioning row not found', 'integrasipaman')]
        : PluginIntegrasipamanProvisioningAction::remove(
            (int) $_POST['row'],
            (int) ($_POST['tickets_id'] ?? 0),
            (int) Session::getLoginUserID()
        );
}

Html::header(
    __('Asset provisioning', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'provisioning'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'provisioning');

echo '<div class="card"><div class="card-body">';

// --- Per-asset results from a just-completed batch ---------------------
// One line per asset, never a batch total - "3 of 12 succeeded" sends the
// admin hunting for the other nine.
if ($results !== []) {
    echo '<div class="alert alert-info">';
    echo '<strong>' . htmlescape(__('Results', 'integrasipaman')) . '</strong>';
    echo '<ul class="mb-0">';
    foreach ($results as $row_id => $result) {
        // Not named unless the row is the viewer's: a result line must not
        // turn a guessed id into another subsidiary's Computer name.
        $row  = jumpserver_provisioning_row((int) $row_id) ?? new $P();
        $name = isset($row->fields['computers_id']) ? Dropdown::getDropdownName('glpi_computers', (int) $row->fields['computers_id']) : ('#' . $row_id);

        if ($result['ok']) {
            $last_action = $row->fields['last_action'] ?? '';
            if ($last_action === 'adopt') {
                $verdict = PluginIntegrasipamanServerLabel::in(__('already in {server}, linked', 'integrasipaman'));
            } elseif ($last_action === 'remove') {
                // interpretRemoval() distinguishes an actual DELETE from a
                // 404 that was already the end state - show that message
                // honestly rather than a generic "removed".
                $verdict = $result['message'] !== '' ? $result['message'] : PluginIntegrasipamanServerLabel::in(__('removed from {server}', 'integrasipaman'));
            } else {
                $verdict = PluginIntegrasipamanServerLabel::in(__('created in {server}', 'integrasipaman'));
            }
        } else {
            $verdict = $result['message'];
        }

        echo '<li>' . htmlescape((string) $name) . ': ' . htmlescape($verdict) . '</li>';
    }
    echo '</ul></div>';
}

// --- Confirmation summary ----------------------------------------------
if ($confirm_ids !== null) {
    $confirm_rows    = jumpserver_provisioning_load_confirm_rows($confirm_ids);
    $sendable        = array_values(array_filter($confirm_rows, static fn ($r) => $r['blocked'] === ''));

    echo '<div class="alert alert-warning">';
    echo '<h4>' . htmlescape(PluginIntegrasipamanServerLabel::in(__('Confirm sending to {server}', 'integrasipaman'))) . '</h4>';
    echo '<p>' . htmlescape(sprintf(
        PluginIntegrasipamanServerLabel::in(_n('%d asset will be created or updated in {server}.', '%d assets will be created or updated in {server}.', count($sendable), 'integrasipaman')),
        count($sendable)
    )) . '</p>';
    echo '<ul>';
    foreach ($confirm_rows as $r) {
        if ($r['blocked'] !== '') {
            echo '<li>' . htmlescape($r['name']) . ' - ' . htmlescape(__('skipped: ', 'integrasipaman')) . htmlescape($r['blocked']) . '</li>';
            continue;
        }
        echo '<li>' . htmlescape($r['name']) . ' - ' . htmlescape(sprintf(
            __('node %1$s, platform %2$s', 'integrasipaman'),
            $r['node_label'],
            $r['platform_label']
        )) . '</li>';
    }
    echo '</ul>';

    if ($sendable !== []) {
        // Html::closeForm(), not a hand-written </form>: it is what injects
        // the CSRF hidden field. Without it, GLPI's Kernel CheckCsrfListener
        // rejects this POST and Confirm would never work.
        echo '<form method="post" action="' . htmlescape($self) . '" class="d-inline">';
        foreach ($sendable as $r) {
            echo Html::hidden('rows[]', ['value' => $r['id']]);
        }
        echo Html::hidden('send', ['value' => 1]);
        echo Html::submit(__('Confirm', 'integrasipaman'), ['name' => 'go', 'class' => 'btn btn-danger']);
        Html::closeForm();
    }
    echo '<a class="btn btn-secondary" href="' . htmlescape($self . '?tab=' . urlencode($tab)) . '">' . htmlescape(__('Cancel', 'integrasipaman')) . '</a>';
    echo '</div>';
}

// --- Tab counts ----------------------------------------------------------
global $DB;

$counts = array_fill_keys($P::allStatuses(), 0);
foreach ($DB->request([
    'SELECT'  => ['status', new QueryExpression('COUNT(*) AS ' . $DB->quoteName('c'))],
    'FROM'    => $P::TABLE,
    // The same restriction as the table below: a tab's count is the number
    // of rows that tab will list.
    'WHERE'   => PluginIntegrasipamanConnection::viewer(),
    'GROUPBY' => 'status',
]) as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']] = (int) $r['c'];
    }
}

$tab_labels = [
    $P::STATUS_ELIGIBLE        => __('Eligible', 'integrasipaman'),
    $P::STATUS_PROVISIONED     => __('Provisioned', 'integrasipaman'),
    $P::STATUS_DRIFT           => __('Drift', 'integrasipaman'),
    $P::STATUS_PENDING_REMOVAL => __('Pending removal', 'integrasipaman'),
    $P::STATUS_REMOVED         => __('Removed', 'integrasipaman'),
    $P::STATUS_FAILED          => __('Failed', 'integrasipaman'),
];

echo '<ul class="nav nav-tabs mb-3">';
foreach ($tab_labels as $status => $label) {
    $active = $status === $tab ? ' active' : '';
    echo '<li class="nav-item"><a class="nav-link' . $active . '" href="' . htmlescape($self . '?tab=' . urlencode($status)) . '">'
        . htmlescape($label) . ' (' . (int) $counts[$status] . ')</a></li>';
}
echo '</ul>';

// --- The table for $tab --------------------------------------------------
$rows = [];
foreach ($DB->request([
    'FROM'  => $P::TABLE,
    'WHERE' => ['status' => $tab] + PluginIntegrasipamanConnection::viewer(),
    'ORDER' => 'id ASC',
]) as $row) {
    $rows[] = $row;
}

$show_checkbox = $can_send && in_array($tab, SENDABLE_STATUSES, true);

if ($show_checkbox) {
    echo '<form method="post" action="' . htmlescape($self) . '">';
}

echo '<div class="table-responsive"><table class="table table-striped table-hover card-table">';
echo '<thead><tr>';
if ($show_checkbox) {
    echo '<th></th>';
}
echo '<th>' . htmlescape(__('Computer', 'integrasipaman')) . '</th>';
echo '<th>' . htmlescape(__('Address', 'integrasipaman')) . '</th>';
echo '<th>' . htmlescape(__('Node', 'integrasipaman')) . '</th>';
echo '<th>' . htmlescape(__('Platform', 'integrasipaman')) . '</th>';
echo '<th>' . htmlescape(__('Status', 'integrasipaman')) . '</th>';
if ($tab === $P::STATUS_DRIFT) {
    echo '<th>' . htmlescape(__('What changed', 'integrasipaman')) . '</th>';
}
echo '<th>' . htmlescape(__('Last action', 'integrasipaman')) . '</th>';
$show_remove_column = $can_remove && $tab === $P::STATUS_PENDING_REMOVAL;
if ($show_remove_column) {
    echo '<th>' . htmlescape(__('Remove', 'integrasipaman')) . '</th>';
}
echo '</tr></thead><tbody>';

if ($rows === []) {
    $colspan = 6 + ($show_checkbox ? 1 : 0) + ($tab === $P::STATUS_DRIFT ? 1 : 0) + ($show_remove_column ? 1 : 0);
    echo '<tr><td colspan="' . $colspan . '">' . htmlescape(__('No assets in this status.', 'integrasipaman')) . '</td></tr>';
}

foreach ($rows as $row) {
    $computers_id = (int) $row['computers_id'];
    $computer     = new Computer();
    $exists       = $computer->getFromDB($computers_id);

    // v4.0.0. A queue row that reached the bastion stays in the queue when
    // its Computer is transferred out of the connection's scope: the row
    // then sits in the connection's entity and points at a Computer that
    // entity can no longer see. Nothing of that Computer is shown here - no
    // name, no address, no link - only that it has moved (suite entity
    // rule, point 15: never another entity's live data).
    $elsewhere    = $exists && !Session::haveAccessToEntity(
        (int) $computer->fields['entities_id'],
        (bool) ($computer->fields['is_recursive'] ?? false)
    );
    $has_computer = $exists && !$elsewhere;

    $computer_fields = $has_computer ? $computer->fields : [
        'id'         => $computers_id,
        'name'       => sprintf(
            $elsewhere ? __('#%d (now in another entity)', 'integrasipaman') : __('#%d (missing in GLPI)', 'integrasipaman'),
            $computers_id
        ),
        'is_deleted' => 1,
    ];

    $address = $has_computer ? PluginIntegrasipamanProvisioning::pickAddress(PluginIntegrasipamanProvisioning::addressesFor($computers_id)) : '';

    [$node_lookup, $platform_lookup] = jumpserver_provisioning_lookups($row);

    $targets = PluginIntegrasipamanProvisioning::resolveTargets(
        $computer_fields + [
            'type_name' => $has_computer ? Dropdown::getDropdownName('glpi_computertypes', (int) ($computer_fields['computertypes_id'] ?? 0)) : '',
            'os_name'   => $has_computer ? Dropdown::getDropdownName('glpi_operatingsystems', (int) ($computer_fields['operatingsystems_id'] ?? 0)) : '',
        ],
        $node_lookup,
        $platform_lookup
    );

    // A missing Computer is blocked too, for the same reason a missing
    // mapping is: listed with the reason in words, never silently hidden.
    $blocked_reason = !$has_computer
        ? ($elsewhere
            ? __('The GLPI Computer behind this row is now in an entity you are not working in', 'integrasipaman')
            : __('The GLPI Computer behind this row no longer exists', 'integrasipaman'))
        : $targets['blocked'];

    echo '<tr>';

    if ($show_checkbox) {
        if ($blocked_reason !== '') {
            echo '<td><input type="checkbox" disabled title="' . htmlescape($blocked_reason) . '"></td>';
        } else {
            echo '<td><input type="checkbox" name="rows[]" value="' . (int) $row['id'] . '"></td>';
        }
    }

    $name_display = $has_computer ? Computer::getFormURLWithID($computers_id) : '';
    echo '<td>';
    if ($has_computer) {
        echo '<a href="' . htmlescape($name_display) . '">' . htmlescape((string) $computer_fields['name']) . '</a>';
    } else {
        echo htmlescape((string) $computer_fields['name']);
    }
    echo '</td>';

    echo '<td>' . htmlescape($address) . '</td>';

    if ($blocked_reason === '') {
        echo '<td>' . htmlescape($targets['node_label'] !== '' ? $targets['node_label'] : (string) $row['node_label']) . '</td>';
        echo '<td>' . htmlescape($targets['platform_label'] !== '' ? $targets['platform_label'] : (string) $row['platform_label']) . '</td>';
    } else {
        echo '<td colspan="2" class="text-warning">' . htmlescape($blocked_reason) . '</td>';
    }

    echo '<td>' . htmlescape((string) ($tab_labels[$row['status']] ?? $row['status'])) . '</td>';

    if ($tab === $P::STATUS_DRIFT) {
        $changes = [];

        $stored_node     = (string) $row['node_label'];
        $stored_platform = (string) $row['platform_label'];

        if ($has_computer && $stored_node !== $targets['node_label']) {
            $changes[] = sprintf(
                __('node: "%1$s" -> "%2$s"', 'integrasipaman'),
                $stored_node,
                $targets['node_label']
            );
        }

        if ($has_computer && $stored_platform !== $targets['platform_label']) {
            $changes[] = sprintf(
                __('platform: "%1$s" -> "%2$s"', 'integrasipaman'),
                $stored_platform,
                $targets['platform_label']
            );
        }

        // The provisioning row has no record of the name/address actually
        // sent (refreshOne() records only that the hash moved, not the
        // payload) - so name/address cannot be diffed. Say so honestly
        // instead of fabricating a before value.
        if ($changes === [] && $has_computer) {
            $changes[] = sprintf(
                __('name/address/comment may have changed since this was sent (the sent value was not recorded) - currently "%1$s" / "%2$s"', 'integrasipaman'),
                (string) $computer_fields['name'],
                $address
            );
        }

        if (!$has_computer) {
            $changes[] = __('The GLPI Computer behind this row no longer exists.', 'integrasipaman');
        }

        echo '<td>' . htmlescape(implode('; ', $changes)) . '</td>';
    }

    $last_bits = [];
    if (!empty($row['last_action'])) {
        $last_bits[] = (string) $row['last_action'];
    }
    if (!empty($row['last_action_date'])) {
        $last_bits[] = (string) $row['last_action_date'];
    }
    $last_line = implode(' - ', $last_bits);
    if (!empty($row['last_error'])) {
        $last_line .= ($last_line !== '' ? ': ' : '') . (string) $row['last_error'];
    }

    echo '<td>' . htmlescape($last_line) . '</td>';

    // --- Remove dialog, one row at a time -------------------------------
    // Sending a batch into PAMAN is routine; taking a batch out is not, and
    // each removal is meant to cite its own ticket, so this is a per-row
    // form rather than a checkbox joining a bulk action.
    if ($show_remove_column) {
        echo '<td>';
        echo '<form method="post" action="' . htmlescape($self) . '" class="d-flex align-items-center gap-1">';
        echo Html::hidden('row', ['value' => (int) $row['id']]);
        echo '<input type="number" name="tickets_id" min="1" required placeholder="'
            . htmlescape(__('Ticket #', 'integrasipaman')) . '" class="form-control form-control-sm" style="width:7em;">';
        echo Html::submit(__('Remove', 'integrasipaman'), ['name' => 'remove', 'class' => 'btn btn-sm btn-danger']);
        Html::closeForm();
        echo '</td>';
    }

    echo '</tr>';
}

echo '</tbody></table></div>';

if ($show_checkbox) {
    echo Html::hidden('confirm_send', ['value' => 1]);
    echo Html::submit(__('Send selected', 'integrasipaman'), ['name' => 'go', 'class' => 'btn btn-primary mt-2']);
    Html::closeForm();
}

echo '</div></div>';

Html::footer();
