<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Connects GLPI to Fit2Cloud JumpServer via a scheduled REST API pull
 * (historical audit logs, Access Key HTTP Signature auth), storing it into
 * a dedicated plugin table for reporting. An inbound-webhook design was
 * considered early on, but JumpServer (upstream, open source) has no
 * webhook/outbound-event feature at all - confirmed directly against the
 * jumpserver/jumpserver source (2026-08-26) - so front/webhook.php was
 * removed rather than kept as unreachable dead code.
 * -------------------------------------------------------------------------
 */

// No GLPI_ROOT guard here: GLPI itself is the only thing that ever
// includes setup.php, including during its scan for newly-added plugin
// directories - a pass that may run before GLPI_ROOT is defined. A guard
// that die()s at that point silently aborts discovery (die() is not a
// PHP error, so nothing appears in any log) and the plugin never gets a
// row in glpi_plugins. Confirmed by comparison against a working
// reference plugin on the target instance, which has no such guard.

define('PLUGIN_INTEGRASIPAMAN_VERSION', '1.8.0');

// Minimum GLPI version, inclusive
define('PLUGIN_INTEGRASIPAMAN_MIN_GLPI', '10.0.0');
// Maximum GLPI version, inclusive
define('PLUGIN_INTEGRASIPAMAN_MAX_GLPI', '11.99.99');

/**
 * Init hooks of the plugin.
 * Called on every page load for active plugins.
 *
 * @return void
 */
function plugin_init_integrasipaman(): void
{
    global $PLUGIN_HOOKS;

    // Only pulled in here - for active plugins, on a normal fully-
    // bootstrapped page load - rather than unconditionally at the top of
    // this file, so it never runs during GLPI's lightweight scan for
    // newly-added plugin directories (see the comment at the top of this
    // file). This defines the item_add/item_update callbacks registered
    // below.
    include_once(__DIR__ . '/hook.php');

    if (Plugin::isPluginActive('integrasipaman')) {
        // Make the plugin's classes known to GLPI's class registry.
        Plugin::registerClass('PluginIntegrasipamanCron');
        Plugin::registerClass('PluginIntegrasipamanConfig');
        Plugin::registerClass('PluginIntegrasipamanDashboard');
        Plugin::registerClass('PluginIntegrasipamanJmsApi');
        Plugin::registerClass('PluginIntegrasipamanTerminalPull');

        // Expose a "Configure" link for this plugin under Setup > Plugins.
        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['config_page']['integrasipaman'] = plugin_integrasipaman_options()['page'];
        }

        // Register this plugin's native dashboard cards (Big Number / Pie)
        // so they appear in GLPI's own "Add widget" panel.
        $PLUGIN_HOOKS['dashboard_cards']['integrasipaman'] = [
            'PluginIntegrasipamanDashboard',
            'getCardTypes',
        ];

        // Add a "JumpServer Integration" entry under GLPI's existing
        // Tools menu category, pointing at front/pbi.php via
        // PluginIntegrasipamanDashboard::getMenuContent(). menu_toadd
        // appends into an *existing* top-level category's own 'types'
        // array (see Html::generateMenuSession() in GLPI core) - it
        // cannot create a brand new top-level category by itself.
        $PLUGIN_HOOKS['menu_toadd']['integrasipaman'] = [
            'tools' => 'PluginIntegrasipamanDashboard',
        ];

        // One-way asset sync: GLPI is the source of truth, so every
        // Computer create/update is pushed on to JumpServer. These
        // callbacks fire for every itemtype and filter to Computer
        // internally (see hook.php).
        $PLUGIN_HOOKS['item_add']['integrasipaman']    = 'plugin_integrasipaman_add_computer';
        $PLUGIN_HOOKS['item_update']['integrasipaman'] = 'plugin_integrasipaman_update_computer';
    }
}

/**
 * Define the plugin's configuration options, notably the page used as the
 * "Configure" link for this plugin under Setup > Plugins.
 *
 * @return array<string, string>
 */
function plugin_integrasipaman_options(): array
{
    return [
        'page' => 'front/config.form.php',
    ];
}

/**
 * Return plugin metadata, used by GLPI on the Plugins page.
 *
 * @return array<string, mixed>
 */
function plugin_version_integrasipaman(): array
{
    return [
        'name'           => 'JumpServer Integration Dashboard',
        'version'        => PLUGIN_INTEGRASIPAMAN_VERSION,
        'author'         => 'Internal IT Team',
        'license'        => 'GPLv2+',
        'homepage'       => '',
        // Flat minGlpiVersion/maxGlpiVersion is the key format GLPI's own
        // plugin-directory scan actually reads to register a newly-found
        // plugin (confirmed against a working reference plugin on the
        // target instance - the nested 'requirements' shape used here
        // previously was never picked up, so the plugin never got a row
        // in glpi_plugins at all). The nested form is kept alongside it in
        // case a newer GLPI release also reads it; extra unrecognized
        // keys are otherwise harmless.
        'minGlpiVersion' => PLUGIN_INTEGRASIPAMAN_MIN_GLPI,
        'maxGlpiVersion' => PLUGIN_INTEGRASIPAMAN_MAX_GLPI,
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_INTEGRASIPAMAN_MIN_GLPI,
                'max' => PLUGIN_INTEGRASIPAMAN_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install.
 *
 * @return boolean
 */
function plugin_integrasipaman_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_INTEGRASIPAMAN_MIN_GLPI, '<')
        || version_compare(GLPI_VERSION, PLUGIN_INTEGRASIPAMAN_MAX_GLPI, '>')) {
        echo sprintf(
            'This plugin requires GLPI >= %s and <= %s',
            PLUGIN_INTEGRASIPAMAN_MIN_GLPI,
            PLUGIN_INTEGRASIPAMAN_MAX_GLPI
        );
        return false;
    }

    if (!function_exists('curl_init')) {
        echo 'This plugin requires the PHP cURL extension to be installed.';
        return false;
    }

    return true;
}

/**
 * Check plugin configuration.
 *
 * @param boolean $verbose Whether to display detailed messages.
 *
 * @return boolean
 */
function plugin_integrasipaman_check_config(bool $verbose = false): bool
{
    return true;
}
