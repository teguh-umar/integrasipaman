<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The user manual (v4.15.0, suite rule 2026-11-09). Anyone who may see the
 * plugin may read it; see inc/manual.class.php for how it is rendered.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

if (!PluginIntegrasipamanMenu::canView()) {
    Html::displayRightError();
}

include_once(__DIR__ . '/../inc/manual.class.php');

Html::header(
    __('Manual', 'integrasipaman'),
    '',
    \GlpiPlugin\Pgnsuite\Menu::SECTOR,
    'integrasipaman',
    'manual'
);
\GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'manual');

echo '<div class="card"><div class="card-body">';
echo PluginIntegrasipamanManual::body(PluginIntegrasipamanManual::read(), PLUGIN_INTEGRASIPAMAN_VERSION);
echo '</div></div>';

Html::footer();
