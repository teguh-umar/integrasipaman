<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Configuration form controller. Reachable from Setup > Plugins for
 * administrators, and handles saving the JumpServer URL/IP, Access Key
 * credentials, organization ID, and PBI reporting DB credentials into
 * GLPI's core config store.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

// Explicit, strict access control: a real authenticated session first,
// then the specific right needed to change plugin configuration.
Session::checkLoginUser();
Session::checkRight('config', UPDATE);

include_once(__DIR__ . '/../inc/config.class.php');

if (isset($_POST['update'])) {
    // No manual Session::checkCSRF() here: GLPI's Kernel-level
    // CheckCsrfListener already validates the token for every non-GET
    // request before this legacy file is loaded. Since CSRF tokens are
    // single-use, checking again here would always fail (the token was
    // already consumed by that earlier, automatic check).

    // Stored as-is (trimmed) rather than HTML-stripped: GLPI 11 sanitizes
    // on output, not on input (Html::clean()/cleanInputText() are gone/
    // deprecated). Html::input()'s 'value' attribute is already
    // htmlescape()'d wherever these fields are redisplayed in the config
    // form, so no separate stripping step is needed here.
    $jumpserver_url               = trim($_POST['jumpserver_url'] ?? '');
    $jumpserver_access_key_id     = trim($_POST['jumpserver_access_key_id'] ?? '');
    $jumpserver_access_key_secret = trim($_POST['jumpserver_access_key_secret'] ?? '');
    $jumpserver_org_id            = trim($_POST['jumpserver_org_id'] ?? '');
    $jumpserver_pbi_db_host       = trim($_POST['jumpserver_pbi_db_host'] ?? '');
    $jumpserver_pbi_db_port       = trim($_POST['jumpserver_pbi_db_port'] ?? '');
    $jumpserver_pbi_db_name       = trim($_POST['jumpserver_pbi_db_name'] ?? '');
    $jumpserver_pbi_db_user       = trim($_POST['jumpserver_pbi_db_user'] ?? '');
    $jumpserver_pbi_db_password   = trim($_POST['jumpserver_pbi_db_password'] ?? '');

    if ($jumpserver_url !== '' && filter_var($jumpserver_url, FILTER_VALIDATE_URL) === false) {
        Session::addMessageAfterRedirect(
            __('JumpServer URL / IP is not a valid URL.'),
            false,
            ERROR
        );
        Html::back();
    }

    Config::setConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT, [
        'jumpserver_url'               => $jumpserver_url,
        'jumpserver_access_key_id'     => $jumpserver_access_key_id,
        'jumpserver_access_key_secret' => $jumpserver_access_key_secret,
        'jumpserver_org_id'            => $jumpserver_org_id,
        'jumpserver_pbi_db_host'       => $jumpserver_pbi_db_host,
        'jumpserver_pbi_db_port'       => $jumpserver_pbi_db_port,
        'jumpserver_pbi_db_name'       => $jumpserver_pbi_db_name,
        'jumpserver_pbi_db_user'       => $jumpserver_pbi_db_user,
        'jumpserver_pbi_db_password'   => $jumpserver_pbi_db_password,
    ]);

    Session::addMessageAfterRedirect(__('Configuration successfully updated.'));

    Html::back();
} else {
    Html::header(
        PluginIntegrasipamanConfig::getTypeName(),
        $_SERVER['PHP_SELF'],
        'config',
        'plugins'
    );

    $config = new PluginIntegrasipamanConfig();
    $config->showFormConfig();

    Html::footer();
}
