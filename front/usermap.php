<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Search list for PluginIntegrasipamanUserMap (GLPI search engine -
 * filter / sort / paginate / CSV+PDF export for free).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

Html::header(
    PluginIntegrasipamanUserMap::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

Search::show('PluginIntegrasipamanUserMap');

Html::footer();
