<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One-shot: fill `type`, `type_label` and `login_from_label` on terminal
 * sessions imported before v4.8.0.
 *
 * Why a page of its own, rather than letting the hourly pull sort it out:
 * the sessions table keeps no raw JSON, so there is nothing local to
 * back-fill from; and insertDedup() skips any jms_id it already has, so a
 * re-pull imports nothing and refreshes nothing. Without this, every row
 * older than 4.8.0 keeps an empty Type and an empty Login Source label for
 * ever. See PluginIntegrasipamanTerminalPull::refreshSessionMeta().
 *
 * Safe to run more than once: a row whose three columns already match is
 * counted as unchanged and not written again.
 *
 * Deliberately GET + an explicit ?confirm=1, matching front/backfill.php and
 * front/backfilljoins.php, the two one-shots this plugin already ships.
 * Gated on the plugin's own config right (UPDATE).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/connection.class.php');
include_once(__DIR__ . '/../inc/terminalpull.class.php');

Session::checkLoginUser();
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

Html::header(
    __('JumpServer Session Metadata Backfill', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'setup'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'setup');

// Refresh the JumpServer of the entity the admin is working in, exactly as
// the other two one-shots do. With several connections and none chosen, the
// run would read no credentials and update nothing.
PluginIntegrasipamanConnection::choose(PluginIntegrasipamanConnection::forActiveEntity());
$connection = PluginIntegrasipamanConnection::current();

$self = Plugin::getWebDir('integrasipaman') . '/front/backfillsessionmeta.php';

echo '<p><a href="' . htmlescape(Plugin::getWebDir('integrasipaman')) . '/front/config.form.php">'
    . htmlescape(__('Back to configuration', 'integrasipaman')) . '</a></p>';

echo '<div class="card"><div class="card-body">';

if ($connection === null) {
    echo '<div class="alert alert-danger">' . htmlescape(__(
        'No JumpServer connection serves the entity you are working in. Switch entity, or configure a connection first.',
        'integrasipaman'
    )) . '</div>';
    echo '</div></div>';
    Html::footer();
    return;
}

echo '<p>' . htmlescape(sprintf(
    __('Connection: %1$s (entity %2$d).', 'integrasipaman'),
    $connection->label(),
    $connection->entity()
)) . '</p>';

// How much is actually outstanding, so the page states the work before doing
// it rather than after.
global $DB;
$pending = (int) ($DB->request([
    'COUNT' => 'cnt',
    'FROM'  => 'glpi_plugin_jumpserver_sessions',
    'WHERE' => ['type' => ''] + PluginIntegrasipamanConnection::ownRows(),
])->current()['cnt'] ?? 0);

if (($_GET['confirm'] ?? '') !== '1') {
    echo '<p>' . htmlescape(sprintf(
        __('%d session(s) of this connection have no Type yet. They were imported before v4.8.0, when JumpServer\'s type and login-source labels were not stored.', 'integrasipaman'),
        $pending
    )) . '</p>';

    echo '<p class="text-muted">' . htmlescape(__(
        'This re-reads the sessions from JumpServer and updates those rows in place. It writes nothing else, adds no rows, and is safe to run again. Sessions JumpServer has already rotated out of its own history cannot be filled and will stay empty.',
        'integrasipaman'
    )) . '</p>';

    echo '<a class="btn btn-primary" href="' . htmlescape($self . '?confirm=1') . '">'
        . htmlescape(__('Run the backfill', 'integrasipaman')) . '</a>';

    echo '</div></div>';
    Html::footer();
    return;
}

$r = PluginIntegrasipamanTerminalPull::refreshSessionMeta();

if (!$r['ok']) {
    echo '<div class="alert alert-danger">' . htmlescape(__(
        'The refresh could not reach JumpServer. Check the connection settings; nothing was changed.',
        'integrasipaman'
    )) . '</div>';
} elseif (!$r['complete']) {
    echo '<div class="alert alert-warning">' . htmlescape(__(
        'JumpServer returned more pages than the page limit allows, so the oldest sessions were not reached. What was read has been updated; run this again if rows remain without a Type.',
        'integrasipaman'
    )) . '</div>';
} else {
    echo '<div class="alert alert-success">' . htmlescape(__('The whole available history was read.', 'integrasipaman')) . '</div>';
}

echo '<table class="table table-striped card-table"><tbody>';
foreach ([
    __('Sessions read from JumpServer', 'integrasipaman') => $r['fetched'],
    __('Rows updated', 'integrasipaman')                  => $r['updated'],
    __('Already correct', 'integrasipaman')               => $r['unchanged'],
    __('Not held locally', 'integrasipaman')              => $r['nomatch'],
] as $label => $value) {
    echo '<tr><td>' . htmlescape($label) . '</td><td><strong>' . htmlescape((string) $value) . '</strong></td></tr>';
}
echo '</tbody></table>';

$left = (int) ($DB->request([
    'COUNT' => 'cnt',
    'FROM'  => 'glpi_plugin_jumpserver_sessions',
    'WHERE' => ['type' => ''] + PluginIntegrasipamanConnection::ownRows(),
])->current()['cnt'] ?? 0);

echo '<p>' . htmlescape(sprintf(
    __('Sessions still without a Type: %1$d (was %2$d).', 'integrasipaman'),
    $left,
    $pending
)) . '</p>';

if ($left > 0) {
    echo '<p class="text-muted">' . htmlescape(__(
        'A remainder is expected: JumpServer rotates its own session history, so the oldest rows here can no longer be described by it. Those keep an empty Type, which reads as "not known" rather than as a type.',
        'integrasipaman'
    )) . '</p>';
}

echo '</div></div>';

Html::footer();
