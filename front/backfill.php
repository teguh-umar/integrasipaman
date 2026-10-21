<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Manual one-month backfill trigger for the terminal command/session pull.
 * A stopgap for Piece 1 ops/testing - Piece 4's reporting page replaces it
 * with a proper month picker. The real interface is
 * PluginIntegrasipamanTerminalPull::backfillMonth(); this file is a thin,
 * config-gated wrapper.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/terminalpull.class.php');

Session::checkLoginUser();
// v2.0.0: gated on the plugin's own right (UPDATE) instead of core `config`
// UPDATE - see inc/profile.class.php.
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

Html::header(
    __('JumpServer Terminal Backfill', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'setup'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'setup');

// v4.0.0: back-fill the JumpServer of the entity the admin is working in.
// pullMonth() reads the current connection; with several connections and
// none chosen it would (rightly) pull nothing.
PluginIntegrasipamanConnection::choose(PluginIntegrasipamanConnection::forActiveEntity());

$month     = trim($_GET['month'] ?? '');
$config    = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
$retention = PluginIntegrasipamanConfig::clampMonths($config['jumpserver_terminal_retention_months'] ?? '13');

// retention - 1 so the oldest offered month is not pruned by the very next
// cron run (prune floor is "first day of -{retention} months").
$oldest_allowed = date('Y-m', strtotime('first day of -' . max(0, $retention - 1) . ' months'));
$newest_allowed = date('Y-m');

$valid = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
    && $month >= $oldest_allowed
    && $month <= $newest_allowed;

echo '<p><a href="' . htmlescape(Plugin::getWebDir('integrasipaman')) . '/front/config.form.php">' . htmlescape(__('Back to configuration', 'integrasipaman')) . '</a></p>';

echo '<div class="card"><div class="card-body">';

// v4.5.1: a month picker. This page took the month only as a hand-typed
// `?month=YYYY-MM`, so it was usable solely by whoever had the deploy
// document open - a tool nobody could find and nobody could drive.
// Rendered on every branch, so after a run the next month is one click away.
$self = Plugin::getWebDir('integrasipaman') . '/front/backfill.php';

echo '<form method="get" action="' . htmlescape($self) . '" class="row g-2 align-items-end mb-3">';
echo '<div class="col-auto">';
echo '<label class="form-label" for="bf-month">' . htmlescape(__('Month to backfill', 'integrasipaman')) . '</label>';

// A native month input would be neater, but it offers no way to stop the
// user picking a month outside the retention window - the next prune would
// simply delete what was just pulled. A list of the allowed months cannot
// express an impossible choice.
echo '<select class="form-select" id="bf-month" name="month">';
for ($m = $newest_allowed; $m >= $oldest_allowed; $m = date('Y-m', strtotime($m . '-01 -1 month'))) {
    echo '<option value="' . htmlescape($m) . '"' . ($m === $month ? ' selected' : '') . '>'
        . htmlescape(date('F Y', (int) strtotime($m . '-01'))) . '</option>';
}
echo '</select>';
echo '</div>';
echo '<div class="col-auto"><button type="submit" class="btn btn-primary">'
    . '<i class="ti ti-download me-1"></i>' . htmlescape(__('Backfill this month', 'integrasipaman')) . '</button></div>';
echo '</form>';

echo '<p class="text-muted small">' . htmlescape(sprintf(
    __('Only the last %1$d months can be offered: the retention prune deletes anything older on its next run, so pulling it would be wasted work.', 'integrasipaman'),
    $retention
)) . '</p>';

if ($month === '') {
    echo '<p class="text-muted">' . htmlescape(__('Pick a month and press the button. Running the same month twice imports nothing new: records already pulled are skipped, not duplicated.', 'integrasipaman')) . '</p>';
} elseif (!$valid) {
    echo '<div class="alert alert-danger">' . htmlescape(sprintf(
        __('"%1$s" is not a valid month in the allowed range %2$s .. %3$s.', 'integrasipaman'),
        $month,
        $oldest_allowed,
        $newest_allowed
    )) . '</div>';
} else {
    $r = PluginIntegrasipamanTerminalPull::backfillMonth($month);

    echo '<h3>' . htmlescape(sprintf(__('Backfill %s', 'integrasipaman'), $month)) . '</h3>';
    echo '<table class="table"><thead><tr><th></th><th>' . htmlescape(__('Fetched', 'integrasipaman'))
        . '</th><th>' . htmlescape(__('Inserted', 'integrasipaman'))
        . '</th><th>' . htmlescape(__('Skipped', 'integrasipaman'))
        . '</th><th>' . htmlescape(__('Empty-id', 'integrasipaman'))
        . '</th><th>' . htmlescape(__('Null-date', 'integrasipaman'))
        . '</th><th>' . htmlescape(__('Complete', 'integrasipaman'))
        . '</th><th>' . htmlescape(__('OK', 'integrasipaman')) . '</th></tr></thead><tbody>';

    foreach (['commands' => __('Commands', 'integrasipaman'), 'sessions' => __('Sessions', 'integrasipaman')] as $key => $label) {
        $c = $r[$key];
        echo '<tr><td>' . htmlescape($label) . '</td>'
            . '<td>' . (int) $c['fetched'] . '</td>'
            . '<td>' . (int) $c['inserted'] . '</td>'
            . '<td>' . (int) $c['skipped'] . '</td>'
            . '<td>' . (int) ($c['empty_id'] ?? 0) . '</td>'
            . '<td>' . (int) ($c['null_date'] ?? 0) . '</td>'
            . '<td>' . (!empty($c['complete']) ? '&#10003;' : '&#10007;') . '</td>'
            . '<td>' . ($c['ok'] ? '&#10003;' : '&#10007;') . '</td></tr>';
    }

    echo '</tbody></table>';
}

echo '</div></div>';

Html::footer();
