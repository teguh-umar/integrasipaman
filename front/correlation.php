<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Correlation dashboard: mapping counts + a POST "Re-resolve now" button +
 * links to the two mapping search lists. On POST it does the work and
 * renders the result inline (no redirect) - a plugin front file's
 * $_SERVER['PHP_SELF'] under GLPI 11's Symfony routing is not the real
 * path, so a form that POSTs to PHP_SELF / Html::back()'d to the referer
 * lands on /index.php and is mis-handled by the inventory request layer.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/correlation.class.php');

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$self = Plugin::getWebDir('integrasipaman') . '/front/correlation.php';

$flash       = null;
$flash_error = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['reresolve'])) {
    // No manual Session::checkCSRF(): GLPI's Kernel CheckCsrfListener has
    // already validated and consumed the token for this POST.
    try {
        $c = PluginIntegrasipamanCorrelation::resolveAll(20.0);
        $flash = sprintf(
            __('Re-resolved: %1$d assets (%2$d GLPI-native, %3$d prefix fallback, %4$d manual, %5$d unresolved); %6$d users (%7$d group, %8$d admin-list fallback).', 'integrasipaman'),
            $c['assets']['total'], $c['assets']['resolved'], $c['assets']['prefix_fallback'],
            $c['assets']['manual'], $c['assets']['unresolved'],
            $c['users']['total'], $c['users']['resolved'], $c['users']['name_fallback']
        );
    } catch (\Throwable $e) {
        $flash       = __('Re-resolve failed: ', 'integrasipaman') . $e->getMessage();
        $flash_error = true;
    }
}

Html::header(
    __('JumpServer correlation', 'integrasipaman'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

global $DB;

/**
 * @return array{total:int, manual:int, resolved:int, prefix_fallback:int, name_fallback:int, unresolved:int}
 */
$counts = static function (string $table): array {
    global $DB;
    $out = ['total' => 0, 'manual' => 0, 'resolved' => 0, 'prefix_fallback' => 0, 'name_fallback' => 0, 'unresolved' => 0];
    foreach ($DB->request(['SELECT' => ['resolve_method', 'is_manual'], 'FROM' => $table]) as $r) {
        $out['total']++;
        if ((int) $r['is_manual'] === 1) {
            $out['manual']++;
            continue;
        }
        $m = (string) $r['resolve_method'];
        if (isset($out[$m])) {
            $out[$m]++;
        } elseif (in_array($m, ['ipnet', 'addr_range', 'computer_ip', 'group'], true)) {
            $out['resolved']++;
        }
    }
    return $out;
};

$a = $counts(PluginIntegrasipamanCorrelation::ASSETMAP_TABLE);
$u = $counts(PluginIntegrasipamanCorrelation::USERMAP_TABLE);

echo '<div class="card"><div class="card-body">';

if ($flash !== null) {
    echo '<div class="alert alert-' . ($flash_error ? 'danger' : 'success') . '">' . htmlescape($flash) . '</div>';
}

echo '<h3>' . htmlescape(__('Asset mappings', 'integrasipaman')) . '</h3>';
echo '<p>' . htmlescape(sprintf(
    __('%1$d total - %2$d resolved via GLPI - %3$d on the fallback prefix list - %4$d manual override - %5$d unresolved.', 'integrasipaman'),
    $a['total'], $a['resolved'], $a['prefix_fallback'], $a['manual'], $a['unresolved']
)) . ' <a href="' . PluginIntegrasipamanAssetMap::getSearchURL() . '">' . htmlescape(__('open list', 'integrasipaman')) . '</a></p>';

echo '<h3 class="mt-4">' . htmlescape(__('User mappings', 'integrasipaman')) . '</h3>';
echo '<p>' . htmlescape(sprintf(
    __('%1$d total - %2$d resolved via GLPI group - %3$d on the fallback admin list - %4$d manual override - %5$d unresolved.', 'integrasipaman'),
    $u['total'], $u['resolved'], $u['name_fallback'], $u['manual'], $u['unresolved']
)) . ' <a href="' . PluginIntegrasipamanUserMap::getSearchURL() . '">' . htmlescape(__('open list', 'integrasipaman')) . '</a></p>';

echo '<form method="post" action="' . htmlescape($self) . '" class="mt-4">';
echo Html::hidden('reresolve', ['value' => 1]);
echo Html::submit(__('Re-resolve now', 'integrasipaman'), ['name' => 'go', 'class' => 'btn btn-primary']);
echo '<small class="form-hint d-block">' . htmlescape(__('Discovers new assets/users and re-derives every non-manual row. The classification cron does this automatically every 15 minutes.', 'integrasipaman')) . '</small>';
Html::closeForm();

echo '</div></div>';

Html::footer();
