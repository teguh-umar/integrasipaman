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
Session::checkRight('config', UPDATE);

Html::header(
    __('JumpServer Terminal Backfill', 'integrasipaman'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginIntegrasipamanDashboard'
);

$month     = trim($_GET['month'] ?? '');
$config    = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
$retention = PluginIntegrasipamanConfig::clampMonths($config['jumpserver_terminal_retention_months'] ?? '13');

$oldest_allowed = date('Y-m', strtotime("first day of -{$retention} months"));
$newest_allowed = date('Y-m');

$valid = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
    && $month >= $oldest_allowed
    && $month <= $newest_allowed;

echo '<div class="card"><div class="card-body">';

if ($month === '') {
    echo '<p>' . htmlescape(__('Add ?month=YYYY-MM to backfill one month of terminal commands & sessions.', 'integrasipaman')) . '</p>';
    echo '<p class="text-muted">' . htmlescape(sprintf(
        __('Allowed range: %1$s to %2$s (retention = %3$d months).', 'integrasipaman'),
        $oldest_allowed,
        $newest_allowed,
        $retention
    )) . '</p>';
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
        . '</th><th>' . htmlescape(__('OK', 'integrasipaman')) . '</th></tr></thead><tbody>';

    foreach (['commands' => __('Commands', 'integrasipaman'), 'sessions' => __('Sessions', 'integrasipaman')] as $key => $label) {
        $c = $r[$key];
        echo '<tr><td>' . htmlescape($label) . '</td>'
            . '<td>' . (int) $c['fetched'] . '</td>'
            . '<td>' . (int) $c['inserted'] . '</td>'
            . '<td>' . (int) $c['skipped'] . '</td>'
            . '<td>' . ($c['ok'] ? '&#10003;' : '&#10007;') . '</td></tr>';
    }

    echo '</tbody></table>';
}

echo '</div></div>';

Html::footer();
