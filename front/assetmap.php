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

Session::checkRight('config', UPDATE);

Html::header(
    PluginIntegrasipamanAssetMap::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

Search::show('PluginIntegrasipamanAssetMap');

Html::footer();
