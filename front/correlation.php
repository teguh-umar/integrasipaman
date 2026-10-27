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
// v2.0.0: gated on the plugin's own right (UPDATE) instead of core `config`
// UPDATE - see inc/profile.class.php.
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

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
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'correlation'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'correlation');

global $DB;

/**
 * @return array{total:int, manual:int, resolved:int, prefix_fallback:int, name_fallback:int, unresolved:int}
 */
$counts = static function (string $table): array {
    global $DB;
    $out = ['total' => 0, 'manual' => 0, 'resolved' => 0, 'prefix_fallback' => 0, 'name_fallback' => 0, 'unresolved' => 0];
    foreach ($DB->request(['SELECT' => ['resolve_method', 'is_manual'], 'FROM' => $table, 'WHERE' => PluginIntegrasipamanConnection::viewer()]) as $r) {
        $out['total']++;
        if ((int) $r['is_manual'] === 1) {
            $out['manual']++;
            continue;
        }
        $m = (string) $r['resolve_method'];
        if (isset($out[$m])) {
            $out[$m]++;
            // 'login': the JumpServer account was matched to a GLPI user and
            // that user is not an admin. Resolved, just not privileged.
        } elseif (in_array($m, ['ipnet', 'addr_range', 'computer_ip', 'group', 'login'], true)) {
            $out['resolved']++;
        }
    }
    return $out;
};

$a = $counts(PluginIntegrasipamanCorrelation::ASSETMAP_TABLE);
$u = $counts(PluginIntegrasipamanCorrelation::USERMAP_TABLE);

// Unclassified means asset_class = '', full stop - including locked rows.
// $a['unresolved'] (resolve_method-based, from $counts() above) excludes
// anything manually locked, so an operator who deliberately marks an asset
// "genuinely neither" would vanish from that number. This is a second,
// one-query COUNT against the indexed asset_class column (KEY asset_class),
// cheap on a ~645-row table, and it is the only place that reports the true
// unclassified population.
//
// Also excludes rows aged out past the PAMAN confirmation window: the
// go/no-go this number feeds is measured against assets still live in
// PAMAN, not against a historical superset that discoverAssets() only ever
// adds to. NULL live_checked_at stays IN - nobody has asked yet, which is
// our own ignorance, not a finding that the asset is gone.
// One cutoff for all three counts below. Not for the skew - three
// strtotime() calls microseconds apart cannot straddle a 14-day boundary in
// any way that matters - but so a future change to the window has one place
// to change instead of three that must be found.
$paman_cutoff = date('Y-m-d H:i:s', strtotime('-' . PluginIntegrasipamanEntitlement::STALE_DAYS . ' days'));

$a_unclassified = (int) $DB->request([
    'COUNT' => 'cnt',
    'FROM'  => PluginIntegrasipamanCorrelation::ASSETMAP_TABLE,
    'WHERE' => [
        'asset_class' => '',
        'OR'          => [
            ['live_checked_at' => null],
            ['live_checked_at' => ['>=', $paman_cutoff]],
        ],
    ] + PluginIntegrasipamanConnection::viewer(),
])->current()['cnt'];

// Signals, not verdicts. Counted separately from the unclassified figure
// because they answer a different question: not "what is this asset" but
// "is what we recorded about it still true".
//
// Windowed the same way the unclassified count is, and for the reason spec
// section 5 gives: comparing an address PAMAN no longer lists is noise, so
// an aged-out row does not belong under a number labelled "investigate" -
// nobody can investigate a machine that is gone. refreshIpMismatch()
// already forces those rows to 0, and this clause says so out loud rather
// than depending on the cron having run since the row aged out. NULL stays
// IN: nobody has asked yet, which is our ignorance, not a finding.
$a_mismatch = (int) $DB->request([
    'COUNT' => 'cnt',
    'FROM'  => PluginIntegrasipamanCorrelation::ASSETMAP_TABLE,
    'WHERE' => [
        'ip_mismatch' => 1,
        'OR'          => [
            ['live_checked_at' => null],
            ['live_checked_at' => ['>=', $paman_cutoff]],
        ],
    ] + PluginIntegrasipamanConnection::viewer(),
])->current()['cnt'];

// Aged out = confirmed present once, and not since. NULL is NOT counted
// here: it means nobody has asked yet, which is our own ignorance and not
// a finding about the asset. discoverAssets() adds a row the moment a
// command reaches a new asset while the host list is pulled weekly, so
// counting NULL would report every new asset as missing for seven days.
$a_stale = (int) $DB->request([
    'COUNT' => 'cnt',
    'FROM'  => PluginIntegrasipamanCorrelation::ASSETMAP_TABLE,
    'WHERE' => [
        'NOT'              => ['live_checked_at' => null],
        'live_checked_at'  => ['<', $paman_cutoff],
    ] + PluginIntegrasipamanConnection::viewer(),
])->current()['cnt'];

echo '<div class="card"><div class="card-body">';

if ($flash !== null) {
    echo '<div class="alert alert-' . ($flash_error ? 'danger' : 'success') . '">' . htmlescape($flash) . '</div>';
}

echo '<h3>' . htmlescape(__('Asset mappings', 'integrasipaman')) . '</h3>';
echo '<p>' . htmlescape(sprintf(
    // States the EXCLUSION, not a confirmation. "seen in PAMAN within the
    // last N days" is false until the first weekly host pull has run: on day
    // one live_checked_at is NULL everywhere, nothing has been seen in PAMAN
    // at all, and the number printed is the unfiltered total. Naming what is
    // left out is true on day one and true afterwards.
    PluginIntegrasipamanServerLabel::in(__('%1$d total - %2$d resolved via GLPI - %3$d on the fallback prefix list - %4$d manual override - %5$d unclassified, excluding assets not seen in {server} for over %6$d days.', 'integrasipaman')),
    $a['total'], $a['resolved'], $a['prefix_fallback'], $a['manual'], $a_unclassified, PluginIntegrasipamanEntitlement::STALE_DAYS
)) . ' <a href="' . PluginIntegrasipamanAssetMap::getSearchURL() . '">' . htmlescape(__('open list', 'integrasipaman')) . '</a></p>';
echo '<p>' . htmlescape(sprintf(
    __('%d asset(s) whose address disagrees with GLPI - investigate.', 'integrasipaman'),
    $a_mismatch
)) . '</p>';
echo '<p>' . htmlescape(sprintf(
    PluginIntegrasipamanServerLabel::in(__('%1$d asset(s) not seen in {server} for over %2$d days.', 'integrasipaman')),
    $a_stale, PluginIntegrasipamanEntitlement::STALE_DAYS
)) . '</p>';

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
