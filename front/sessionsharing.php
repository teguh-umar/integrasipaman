<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Compatibility redirect to front/session.php (v4.7.0).
 *
 * The page moved because the Log Session tab is now rendered by GLPI's
 * Search engine, which needs an itemtype - and core derives an itemtype's
 * URLs from the CLASS name, never from the controller's filename
 * (Toolbox::getItemTypeFormURL()). `PluginIntegrasipamanSession` therefore
 * means `front/session.php`, whatever the file used to be called. A sibling
 * plugin shipped a mismatch here and every core-generated link landed on
 * core's bare generic-route fallback; the suite hub records it.
 *
 * This file stays only so links made before 4.7.0 keep working: bookmarks,
 * anything pasted into a ticket or an email. Everything inside the plugin
 * points at the new URL directly, so nothing here is on a normal path.
 *
 * The query string is carried over verbatim, so a saved link to
 * ?tab=sharing&from=…&to=… still lands where it meant to.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

Session::checkLoginUser();

$target = Plugin::getWebDir('integrasipaman') . '/front/session.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') {
    $target .= '?' . $query;
}

Html::redirect($target);
