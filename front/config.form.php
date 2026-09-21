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
// then this plugin's own right. v2.0.0 moved off core `config` UPDATE so a
// PAM administrator no longer needs access to all of GLPI's Setup.
// READ opens the page; saving needs UPDATE (checked on the POST branch).
Session::checkLoginUser();
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT, READ);

include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/correlation.class.php');

if (isset($_POST['update'])) {
    Session::checkRight(PluginIntegrasipamanProfile::RIGHT, UPDATE);

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
    $jumpserver_command_storage_id        = trim($_POST['jumpserver_command_storage_id'] ?? '');
    $jumpserver_terminal_retention_months = (string) PluginIntegrasipamanConfig::clampMonths($_POST['jumpserver_terminal_retention_months'] ?? '13');
    $jumpserver_terminal_backfill_months  = (string) PluginIntegrasipamanConfig::clampMonths($_POST['jumpserver_terminal_backfill_months'] ?? '13');
    $jumpserver_risk_keywords          = trim($_POST['jumpserver_risk_keywords'] ?? '');
    $jumpserver_admin_users            = trim($_POST['jumpserver_admin_users'] ?? '');
    $jumpserver_production_ip_prefixes = trim($_POST['jumpserver_production_ip_prefixes'] ?? '');

    $correlation_match_mode = in_array(($_POST['correlation_match_mode'] ?? ''), ['ip_only', 'name_only', 'hybrid'], true)
        ? $_POST['correlation_match_mode']
        : 'ip_only';
    $correlation_production_ipnetworks = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['correlation_production_ipnetworks'] ?? [])),
        static fn ($n) => $n > 0
    ))));
    $correlation_production_addressings = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['correlation_production_addressings'] ?? [])),
        static fn ($n) => $n > 0
    ))));
    $correlation_admin_groups = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['correlation_admin_groups'] ?? [])),
        static fn ($n) => $n > 0
    ))));

    if ($jumpserver_url !== '' && filter_var($jumpserver_url, FILTER_VALIDATE_URL) === false) {
        Session::addMessageAfterRedirect(
            __('JumpServer URL / IP is not a valid URL.'),
            false,
            ERROR
        );
        Html::back();
    }

    if ($jumpserver_access_key_id === '' || $jumpserver_access_key_secret === '' || $jumpserver_org_id === '') {
        Session::addMessageAfterRedirect(
            __('Access Key ID, Access Key Secret and Organization ID are all required.', 'integrasipaman'),
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
        'jumpserver_command_storage_id'        => $jumpserver_command_storage_id,
        'jumpserver_terminal_retention_months' => $jumpserver_terminal_retention_months,
        'jumpserver_terminal_backfill_months'  => $jumpserver_terminal_backfill_months,
        'jumpserver_risk_keywords'          => $jumpserver_risk_keywords,
        'jumpserver_admin_users'            => $jumpserver_admin_users,
        'jumpserver_production_ip_prefixes' => $jumpserver_production_ip_prefixes,
        'correlation_match_mode'            => $correlation_match_mode,
        'correlation_production_ipnetworks' => $correlation_production_ipnetworks,
        'correlation_production_addressings' => $correlation_production_addressings,
        'correlation_admin_groups'          => $correlation_admin_groups,
    ]);

    Session::addMessageAfterRedirect(__('Configuration successfully updated.'));

    // Piece 3: re-resolve the correlation maps with the new settings
    // (budgeted so a large asset set cannot 504 this request; the cron
    // finishes any leftover rows).
    try {
        PluginIntegrasipamanCorrelation::resolveAll(10.0);
    } catch (\Throwable $e) {
        trigger_error('config: post-save correlation resolve failed: ' . $e->getMessage(), E_USER_WARNING);
    }

    // Suite contract: only a POST that came from the PGN Suite tab goes back
    // to the tab. Redirecting unconditionally would send a plugin-right-only
    // admin to Setup > General, which they cannot open.
    if (!empty($_POST['_from_pgnsuite'])) {
        Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('integrasipaman'));
    }

    Html::back();
} else {
    // The PGN Suite tab is the one visible entry point for anyone who can
    // open Setup > General; this page stays reachable by URL for admins who
    // hold only the plugin right.
    if (Session::haveRight('config', READ)) {
        Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('integrasipaman'));
    }

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
