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
Session::checkRight('config', UPDATE);

$map = new PluginIntegrasipamanAssetMap();

if (isset($_POST['update'])) {
    $map->check((int) $_POST['id'], UPDATE);
    $map->update($_POST);
    Html::back();
}

Html::header(
    PluginIntegrasipamanAssetMap::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$map->showForm((int) ($_GET['id'] ?? 0));

Html::footer();
