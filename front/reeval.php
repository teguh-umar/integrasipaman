<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Config-gated "Re-evaluate now" trigger for the command risk evaluation.
 * POST-only (triggered from the button on the configuration page). Runs
 * PluginIntegrasipamanRiskEval::evaluateStale() with a ~20-second wall-clock
 * budget so it cannot hit the nginx/php-fpm request timeout; the
 * evaluate_commands cron (every 15 min) finishes whatever is left.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/correlation.class.php');
include_once(__DIR__ . '/../inc/riskeval.class.php');

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Html::displayErrorAndDie(__('This action must be triggered from the "Re-evaluate now" button on the configuration page.', 'integrasipaman'));
}

// No manual Session::checkCSRF() here: GLPI's Kernel-level CheckCsrfListener
// already validated and single-use-consumed the token for this POST before
// this legacy file loaded; a second check would always fail.

Html::header(
    __('Re-evaluate JumpServer commands', 'integrasipaman'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

try {
    PluginIntegrasipamanCorrelation::resolveAll(10.0);
} catch (\Throwable $e) {
    trigger_error('reeval: correlation resolve failed: ' . $e->getMessage(), E_USER_WARNING);
}

$r         = PluginIntegrasipamanRiskEval::evaluateStale(2000, 20.0);
$evaluated = $r['evaluated'];
$remaining = $r['stale_remaining'];

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
