<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Search list for PluginIntegrasipamanNodeMap (GLPI search engine -
 * filter / sort / paginate / CSV+PDF export for free).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ);

// Not a registered GLPI itemtype - loaded explicitly at every use site,
// same precedent as PluginIntegrasipamanJmsApi (see setup.php's comment).
include_once(__DIR__ . '/../inc/pamanlists.class.php');

Html::header(
    PluginIntegrasipamanNodeMap::getTypeName(2),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'provisioning'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'provisioning');

Search::show('PluginIntegrasipamanNodeMap');

Html::footer();
