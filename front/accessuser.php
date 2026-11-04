<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * User access > User: one row per JumpServer user and the assets they can
 * reach, as GLPI's own searchable list over the report table that
 * PluginIntegrasipamanAccessIndex rebuilds after every pull. Read-only;
 * gated on the report right, and core restricts the rows to the entities
 * the signed-in user is working in (the table carries entities_id).
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);

include_once(__DIR__ . '/../inc/accessreport.class.php');
include_once(__DIR__ . '/../inc/accessindex.class.php');

Html::header(
    __('User access', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'useraccess'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'useraccess');

PluginIntegrasipamanAccessIndex::renderTabs('user');
PluginIntegrasipamanAccessIndex::renderMembershipBanner(PluginIntegrasipamanAccessReport::membershipState());
PluginIntegrasipamanAccessIndex::renderNotBuiltNotice(PluginIntegrasipamanAccessIndex::USERS_TABLE);
echo PluginIntegrasipamanAccessIndex::popupScript();

Search::show(PluginIntegrasipamanAccessUser::class);

Html::footer();
