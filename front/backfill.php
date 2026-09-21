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
Session::checkRight(PluginIntegrasipamanProfile::RIGHT, UPDATE);

Html::header(
    __('JumpServer Terminal Backfill', 'integrasipaman'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginIntegrasipamanDashboard'
);

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
