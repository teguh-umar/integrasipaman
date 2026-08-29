<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Config-gated "Re-evaluate now" trigger for the command risk evaluation.
 * Runs PluginIntegrasipamanRiskEval::evaluateStale() in a ~25-second
 * budget so it cannot hit the nginx/php-fpm request timeout; the
 * evaluate_commands cron (every 15 min) finishes whatever is left.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/riskeval.class.php');

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

Html::header(
    __('Re-evaluate JumpServer commands', 'integrasipaman'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$deadline  = microtime(true) + 25.0;
$evaluated = 0;
$remaining = 0;

do {
    $r          = PluginIntegrasipamanRiskEval::evaluateStale(2000);
    $evaluated += $r['evaluated'];
    $remaining  = $r['stale_remaining'];
} while ($r['evaluated'] > 0 && $remaining > 0 && microtime(true) < $deadline);

echo '<div class="card"><div class="card-body">';
echo '<p>' . htmlescape(sprintf(
    __('Evaluated %1$d command row(s). %2$d still stale.', 'integrasipaman'),
    $evaluated,
    $remaining
)) . '</p>';

if ($remaining > 0) {
    echo '<p class="text-muted">' . htmlescape(__('The "Classify JumpServer command records" cron (every 15 minutes) will finish the rest.', 'integrasipaman')) . '</p>';
}

echo '<a class="btn btn-primary" href="' . Plugin::getWebDir('integrasipaman') . '/front/config.form.php">'
    . htmlescape(__('Back to configuration', 'integrasipaman')) . '</a>';
echo '</div></div>';

Html::footer();
