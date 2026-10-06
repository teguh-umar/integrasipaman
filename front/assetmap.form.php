<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Edit one PluginIntegrasipamanAssetMap row (override the resolved
 * verdict + note). Config-gated; no create / delete from here.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
// v2.0.0: gated on the plugin's own right (UPDATE) instead of core `config`
// UPDATE - see inc/profile.class.php.
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

$map = new PluginIntegrasipamanAssetMap();

if (isset($_POST['update'])) {
    $map->check((int) $_POST['id'], UPDATE);
    $map->update($_POST);
    Html::back();
}

Html::header(
    PluginIntegrasipamanAssetMap::getTypeName(1),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'correlation'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'correlation');

// v4.0.0: the row, not just the right. check() also verifies the row's
// entity, now that the table has one; without it this page would open any
// subsidiary's mapping by id.
if ((int) ($_GET['id'] ?? 0) > 0) {
    $map->check((int) $_GET['id'], READ);
}
$map->showForm((int) ($_GET['id'] ?? 0));

Html::footer();
