<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * User access > Group: one row per JumpServer user group, its member count
 * (click for the members) and what it reaches, as GLPI's own searchable list
 * over the report table that PluginIntegrasipamanAccessIndex rebuilds after
 * every pull. Read-only; gated on the report right, and core restricts the
 * rows to the entities the signed-in user is working in.
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

PluginIntegrasipamanAccessIndex::renderTabs('group');
PluginIntegrasipamanAccessIndex::renderMembershipBanner(PluginIntegrasipamanAccessReport::membershipState());
PluginIntegrasipamanAccessIndex::renderNotBuiltNotice(PluginIntegrasipamanAccessIndex::GROUPS_TABLE);
echo PluginIntegrasipamanAccessIndex::popupScript();

Search::show(PluginIntegrasipamanAccessGroup::class);

Html::footer();
