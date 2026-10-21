<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One-shot back-fill of session-join records (v4.5.0).
 *
 * Why it is needed: the hourly pull only reaches back to the connection's
 * cursor. The day this endpoint was first pulled, every join older than that
 * moment was out of reach for good - on this instance, the entire history of
 * the feature. This page fetches the lot, once, by hand.
 *
 * Why it is cheap: /api/v1/terminal/session-join-records/ ignores date
 * filters and returns everything, 100 rows a page. The trait that makes the
 * incremental pull awkward is what makes the back-fill trivial.
 *
 * Safe to run more than once: rows are skipped by source_id.
 *
 * Deliberately GET + an explicit ?confirm=1, not a POST form. It writes rows
 * but creates nothing a second run would duplicate, and keeping it a plain
 * link matches front/backfill.php, the terminal back-fill this plugin
 * already ships. Gated on the plugin's own config right (UPDATE).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/connection.class.php');
include_once(__DIR__ . '/../inc/cron.class.php');

Session::checkLoginUser();
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

Html::header(
    __('JumpServer Session Join Backfill', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'setup'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'setup');

// Back-fill the JumpServer of the entity the admin is working in, exactly as
// front/backfill.php does. With several connections and none chosen, the
// pull would read no credentials and import nothing.
PluginIntegrasipamanConnection::choose(PluginIntegrasipamanConnection::forActiveEntity());
$connection = PluginIntegrasipamanConnection::current();

$self = Plugin::getWebDir('integrasipaman') . '/front/backfilljoins.php';

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

if (($_GET['confirm'] ?? '') !== '1') {
    echo '<p>' . htmlescape(__(
        'This imports every session-join record JumpServer still holds, including those older than the last pull. '
        . 'The hourly pull cannot reach them: it only ever looks forward from where it last stopped.',
        'integrasipaman'
    )) . '</p>';
    echo '<p class="text-muted">' . htmlescape(__(
        'Running it twice is harmless - a record already imported is skipped, not duplicated.',
        'integrasipaman'
    )) . '</p>';
    echo '<a class="btn btn-primary" href="' . htmlescape($self . '?confirm=1') . '">'
        . htmlescape(__('Back-fill session joins now', 'integrasipaman')) . '</a>';
    echo '</div></div>';
    Html::footer();
    return;
}

$result = PluginIntegrasipamanCron::backfillSessionJoins();

// Said in the order that matters to someone deciding whether to trust the
// numbers: whether the fetch finished at all comes before the totals.
if (!$result['complete']) {
    echo '<div class="alert alert-warning">' . htmlescape(__(
        'The fetch did not run to the end: a page failed, or the page limit was reached. '
        . 'The counts below are a partial result. Running this again picks up the rest; nothing is duplicated.',
        'integrasipaman'
    )) . '</div>';
} else {
    echo '<div class="alert alert-success">' . htmlescape(__('The fetch ran to the end.', 'integrasipaman')) . '</div>';
}

echo '<ul>';
echo '<li>' . htmlescape(sprintf(__('%d record(s) fetched from JumpServer', 'integrasipaman'), $result['fetched'])) . '</li>';
echo '<li>' . htmlescape(sprintf(__('%d imported', 'integrasipaman'), $result['imported'])) . '</li>';
echo '<li>' . htmlescape(sprintf(__('%d already present, skipped', 'integrasipaman'), $result['skipped'])) . '</li>';
echo '<li>' . htmlescape(sprintf(__('%d failed to insert', 'integrasipaman'), $result['failed'])) . '</li>';
echo '</ul>';

if ($result['failed'] > 0) {
    echo '<p class="text-muted">' . htmlescape(__(
        'Insert failures are in the GLPI SQL error log.',
        'integrasipaman'
    )) . '</p>';
}

echo '<p><a class="btn btn-outline-secondary" href="'
    . htmlescape(Plugin::getWebDir('integrasipaman') . '/front/sessionsharing.php') . '">'
    . htmlescape(__('See the session sharing page', 'integrasipaman')) . '</a></p>';

echo '</div></div>';

Html::footer();
