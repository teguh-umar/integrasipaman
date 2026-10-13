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

define('PLUGIN_INTEGRASIPAMAN_VERSION', '4.3.0');

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

    // The JumpServer connection (v3.11.0): URL, credentials and owning
    // entity, a row of its own table. Loaded for every request, cron and CLI
    // included, and before the label below, which reads its URL.
    //
    // secured_fields only tells `security:changekey` to re-encrypt the two
    // columns when the GLPI key changes. The class encrypts them itself.
    include_once(__DIR__ . '/inc/connection.class.php');
    $PLUGIN_HOOKS['secured_fields']['integrasipaman'] = PluginIntegrasipamanConnection::SECURED_FIELDS;

    // The name the UI calls the JumpServer instance by (v3.10.0). Loaded for
    // every request, cron and CLI included: any class that prints a message
    // may use it.
    include_once(__DIR__ . '/inc/serverlabel.class.php');

    if (Plugin::isPluginActive('integrasipaman')) {
        // Make the plugin's classes known to GLPI's class registry.
        Plugin::registerClass('PluginIntegrasipamanCron');
        Plugin::registerClass('PluginIntegrasipamanConfig');
        Plugin::registerClass('PluginIntegrasipamanDashboard');
        // Provider of this plugin's entry in the shared suite menu; loaded
        // here because the shared Menu class calls it by name.
        include_once(__DIR__ . '/inc/menu.class.php');
        Plugin::registerClass('PluginIntegrasipamanMenu');
        // PluginIntegrasipamanJmsApi is a pure transport class, not a GLPI
        // itemtype - it is loaded via require_once where used, not registered.
        Plugin::registerClass('PluginIntegrasipamanTerminalPull');
        Plugin::registerClass('PluginIntegrasipamanRiskEval');
        Plugin::registerClass('PluginIntegrasipamanAssetMap');
        Plugin::registerClass('PluginIntegrasipamanUserMap');
        // "PAMAN" tab on the core Computer itemtype: is this machine in the
        // bastion, and does PAMAN still agree with GLPI about it. Same rule
        // as the tabs below - load before registerClass(), which resolves
        // the class by name.
        //
        // The tab gates itself on RIGHT_PROVISIONING rather than on Computer
        // READ: whether a machine is reachable through the bastion is
        // privileged information, so "can read this Computer" is the wrong
        // audience. See inc/provisioning.class.php.
        // No 'addtabon' => 'Computer' any more: this is a section of the
        // shared PGN Suite tab (see the AssetTab block below). The class is
        // still registered so GLPI knows the itemtype; only the tab moved.
        include_once(__DIR__ . '/inc/provisioning.class.php');
        Plugin::registerClass('PluginIntegrasipamanProvisioning');
        include_once(__DIR__ . '/inc/nodemap.class.php');
        Plugin::registerClass('PluginIntegrasipamanNodeMap');
        include_once(__DIR__ . '/inc/platformmap.class.php');
        Plugin::registerClass('PluginIntegrasipamanPlatformMap');

        // Rights matrix tab on Administration > Profiles. The class must be
        // loaded before registerClass(), which resolves it by name.
        include_once(__DIR__ . '/inc/profile.class.php');
        Plugin::registerClass('PluginIntegrasipamanProfile', ['addtabon' => 'Profile']);

        // "JumpServer access" tab on the core Computer itemtype: who reached
        // this machine through the PAM, and which of their commands were
        // flagged. Same rule as above - load before registerClass(), which
        // resolves the class by name.
        //
        // The tab gates itself on RIGHT_REPORT rather than on Computer READ:
        // it shows privileged usernames and executed commands, so "can read
        // this Computer" is the wrong audience. See inc/accesshistory.class.php.
        // As above, no 'addtabon' - it is a section of the PGN Suite tab.
        //
        // PluginIntegrasipamanEntitlement (the "Who has access" sub-pane's
        // data source) is NOT registered here: like ProvisioningAction and
        // JmsApi, it is a plain data/cron class, not a CommonDBTM/CommonGLPI
        // itemtype, so Plugin::registerClass() on it is a silent no-op. It
        // is require_once'd by accesshistory.class.php itself, the one file
        // that actually consumes it.
        include_once(__DIR__ . '/inc/accesshistory.class.php');
        Plugin::registerClass('PluginIntegrasipamanAccessHistory');

        // The same history from the other side: "this person - which PAMAN
        // assets did they reach, with what commands, from where". A plain
        // addtabon, NOT the PGN Suite AssetTab: that contract covers asset
        // itemtypes (Computer, NetworkEquipment, Security, Storage) and a
        // User is not an asset.
        //
        // Gated on RIGHT_REPORT like its mirror. A User record is readable
        // by far more people than should see that person's command history.
        include_once(__DIR__ . '/inc/useraccess.class.php');
        Plugin::registerClass('PluginIntegrasipamanUserAccess', ['addtabon' => 'User']);

        // Shared "PGN Suite" tab on Setup > General (suite contract v1). The
        // class ships byte-identical in every participating plugin; whichever
        // active plugin loads first supplies it. class_exists(..., false) on
        // purpose - the autoloader would go looking for a plugins/pgnsuite/
        // that does not exist.
        if (!class_exists(\GlpiPlugin\Pgnsuite\ConfigTab::class, false)) {
            require_once __DIR__ . '/lib/pgnsuite/ConfigTab.php';
        }
        \GlpiPlugin\Pgnsuite\ConfigTab::register();
        $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\ConfigTab::HOOK]['integrasipaman'] =
            'PluginIntegrasipamanConfig::suiteSection';

        // Shared "PGN Suite" tab on asset forms (suite contract v1 r1, user
        // decision 2026-09-24): the plugin tabs that used to sit side by side
        // on a Computer become horizontal sub-tabs of one tab. Same shipping
        // rules as ConfigTab above - byte-identical copy, first active plugin
        // to load it wins, class_exists(..., false) so the autoloader does not
        // hunt for a plugins/pgnsuite/ that does not exist.
        //
        // This replaces the two 'addtabon' => 'Computer' registrations above.
        // Both sections still gate on their own right inside sections(), so a
        // user with neither sees no tab at all rather than an empty one.
        if (!class_exists(\GlpiPlugin\Pgnsuite\AssetTab::class, false)) {
            require_once __DIR__ . '/lib/pgnsuite/AssetTab.php';
        }
        include_once(__DIR__ . '/inc/assetsections.class.php');
        \GlpiPlugin\Pgnsuite\AssetTab::register(PluginIntegrasipamanAssetSections::ITEMTYPES);
        $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\AssetTab::HOOK]['integrasipaman'] =
            'PluginIntegrasipamanAssetSections::sections';

        // Expose a "Configure" link for this plugin under Setup > Plugins.
        // Gated on the plugin's own right now, so a PAM administrator without
        // core Setup access still gets the link.
        if (Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ)) {
            $PLUGIN_HOOKS['config_page']['integrasipaman'] = plugin_integrasipaman_options()['page'];
        }

        // Register this plugin's native dashboard cards (Big Number / Pie)
        // so they appear in GLPI's own "Add widget" panel.
        $PLUGIN_HOOKS['dashboard_cards']['integrasipaman'] = [
            'PluginIntegrasipamanDashboard',
            'getCardTypes',
        ];

        // One entry in the shared suite sidebar menu (hub §5, v3.9.0), instead
        // of this plugin's own top-level sector. Same shipping rules as
        // ConfigTab above: byte-identical copy, first active plugin to load
        // it wins, class_exists(..., false) so the autoloader does not hunt
        // for a plugins/pgnsuite/ that does not exist. The provider is a
        // string because the shared class calls it by name; see
        // inc/menu.class.php.
        if (!class_exists(\GlpiPlugin\Pgnsuite\Menu::class, false)) {
            require_once __DIR__ . '/lib/pgnsuite/Menu.php';
        }
        \GlpiPlugin\Pgnsuite\Menu::register('integrasipaman');
        $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\Menu::HOOK]['integrasipaman'] =
            'PluginIntegrasipamanMenu::entry';

        // Keep the provisioning queue current. These callbacks only write to
        // the plugin's own table - see hook.php. Provisioning itself is a
        // button on front/provisioning.php, never a side effect of a save.
        $PLUGIN_HOOKS['item_add']['integrasipaman']    = 'plugin_integrasipaman_provisioning_item_add';
        $PLUGIN_HOOKS['item_update']['integrasipaman'] = 'plugin_integrasipaman_provisioning_item_update';
        $PLUGIN_HOOKS['item_delete']['integrasipaman'] = 'plugin_integrasipaman_provisioning_item_delete';
        $PLUGIN_HOOKS['item_purge']['integrasipaman']  = 'plugin_integrasipaman_provisioning_item_purge';
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
        'author'         => 'ICT',
        'license'        => 'GPL-3.0-or-later',
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
