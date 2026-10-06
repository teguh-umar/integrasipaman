<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Create / edit one PluginIntegrasipamanNodeMap row (GLPI computer type ->
 * PAMAN node). Config-gated. Unlike assetmap, these rows are created by
 * hand, so this controller also handles 'add'.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

// Not a registered GLPI itemtype - loaded explicitly at every use site,
// same precedent as PluginIntegrasipamanJmsApi (see setup.php's comment).
include_once(__DIR__ . '/../inc/pamanlists.class.php');

$map = new PluginIntegrasipamanNodeMap();

// v4.0.0: a mapping belongs to one JumpServer. An existing row names its
// connection (and check() verifies the row's entity before anything is
// shown or changed); a new one is for the connection that serves the entity
// the admin is working in. The rest of the request runs as that connection,
// so the node / platform list offered is that JumpServer's own.
$map_id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($map_id > 0) {
    $map->check($map_id, READ);
    PluginIntegrasipamanConnection::choose(
        PluginIntegrasipamanConnection::byId((int) ($map->fields[PluginIntegrasipamanConnection::FK] ?? 0))
    );
} else {
    PluginIntegrasipamanConnection::choose(PluginIntegrasipamanConnection::forActiveEntity());
}

if (isset($_POST['add'])) {
    $map->check(-1, CREATE);
    $map->add($_POST);
    Html::back();
}

if (isset($_POST['update'])) {
    $map->check((int) $_POST['id'], UPDATE);
    $map->update($_POST);
    Html::back();
}

if (isset($_POST['reload_lists'])) {
    $fresh = PluginIntegrasipamanPamanLists::nodes(true);

    if ($fresh === []) {
        Session::addMessageAfterRedirect(
            PluginIntegrasipamanServerLabel::in(__('{server} could not be reached; the stored list is unchanged.', 'integrasipaman')),
            false,
            WARNING
        );
    } else {
        Session::addMessageAfterRedirect(
            sprintf(PluginIntegrasipamanServerLabel::in(__('%d nodes reloaded from {server}.', 'integrasipaman')), count($fresh)),
            false,
            INFO
        );
    }

    Html::back();
}

Html::header(
    PluginIntegrasipamanNodeMap::getTypeName(1),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'provisioning'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'provisioning');

$map->showForm((int) ($_GET['id'] ?? 0));

echo '<form method="post" action="' . htmlescape(Plugin::getWebDir('integrasipaman') . '/front/nodemap.form.php') . '">';
echo Html::submit(PluginIntegrasipamanServerLabel::in(__('Reload from {server}', 'integrasipaman')), ['name' => 'reload_lists', 'class' => 'btn btn-outline-secondary']);
Html::closeForm();

Html::footer();
