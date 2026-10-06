<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Search list for PluginIntegrasipamanAssetMap (GLPI search engine -
 * filter / sort / paginate / CSV+PDF export for free).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

// v2.0.0: gated on the plugin's own right (READ) instead of core `config`
// UPDATE, which used to hand the whole of Setup > General to anyone who
// needed these reports.
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ);

Html::header(
    PluginIntegrasipamanAssetMap::getTypeName(2),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'correlation'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'correlation');

Search::show('PluginIntegrasipamanAssetMap');

Html::footer();
