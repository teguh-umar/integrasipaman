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

// v4.14.0: GLPI draws "+ Add" from the menu entry, and this page sits under
// "provisioning", which declares none - so the list had no way to add a row.
if (PluginIntegrasipamanNodeMap::canCreate()) {
    echo '<div class="mb-2"><a class="btn btn-primary" href="'
        . htmlescape(Plugin::getWebDir('integrasipaman') . '/front/nodemap.form.php') . '">'
        . '<i class="ti ti-plus"></i> ' . htmlescape(__('Add a node mapping', 'integrasipaman')) . '</a></div>';
}

Search::show('PluginIntegrasipamanNodeMap');

Html::footer();
