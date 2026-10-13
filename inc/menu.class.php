<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * This plugin's entry in the shared suite sidebar menu (hub §5,
 * lib/pgnsuite/Menu.php; v3.9.0). Until 3.8.x the plugin had its own
 * top-level sector, 'integrasipaman'; the suite now shows one sector with one
 * entry per plugin.
 *
 * Wired in plugin_init_integrasipaman() via
 *   \GlpiPlugin\Pgnsuite\Menu::register('integrasipaman');
 *   $PLUGIN_HOOKS[\GlpiPlugin\Pgnsuite\Menu::HOOK]['integrasipaman'] =
 *       'PluginIntegrasipamanMenu::entry';
 *
 * The sidebar shows only the entry. Its `options` are the plugin's pages:
 * they feed the breadcrumb and the horizontal sub-nav that every front
 * controller prints with Menu::renderSubNav() right after Html::header().
 *
 * Front controllers mark the active page with Html::header()'s 3rd arg
 * (Menu::SECTOR), 4th arg ('integrasipaman') and 5th arg (an option key
 * below - 'insights', 'report', 'correlation', 'provisioning', 'setup').
 *
 * `page` values are root-relative paths; GLPI adds its own prefix.
 *
 * Not a persisted itemtype - no table, no CommonDBTM.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanMenu extends CommonGLPI
{
    private const BASE = '/plugins/integrasipaman/front';

    /** Position among the suite's entries; allocated in the hub (§7 2026-10-03). */
    private const ORDER = 10;

    public static function getTypeName($nb = 0)
    {
        return __('JumpServer Integration', 'integrasipaman');
    }

    public static function getIcon()
    {
        return 'ti ti-terminal-2';
    }

    /**
     * Any one of the three rights shows the entry - a provisioning-only
     * holder (e.g. someone who may only send/remove PAMAN assets, with
     * neither the report nor the config right) still gets it, just with
     * only the provisioning page behind it.
     */
    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ)
            || Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ)
            || Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, READ);
    }

    /**
     * Provider for \GlpiPlugin\Pgnsuite\Menu::HOOK.
     *
     * Returning null hides the entry, which is what a user without any
     * plugin right gets - every page below would only deny them anyway.
     *
     * @return array<string, mixed>|null
     */
    public static function entry(): ?array
    {
        if (!self::canView()) {
            return null;
        }

        $can_report = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);
        $can_config = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ);
        $can_write  = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

        $insights = self::BASE . '/pbi.php';
        $report   = self::BASE . '/pbi.php?tab=report';
        $setup    = self::BASE . '/config.form.php';

        // Both pages are gated on the report right by their controllers;
        // listing them for someone who lacks it would only offer a link to
        // an access-denied page.
        $options = [];
        if ($can_report) {
            $options['insights'] = [
                'title' => __('PBI Insights', 'integrasipaman'),
                'page'  => $insights,
                'icon'  => 'ti ti-chart-histogram',
                'links' => ['search' => $insights],
            ];
            $options['report'] = [
                'title' => __('Command Audit Report', 'integrasipaman'),
                'page'  => $report,
                'icon'  => 'ti ti-file-analytics',
                'links' => ['search' => $report],
            ];
            // 4.3.0: why logins were refused, lockouts, accounts that keep failing.
            $options['loginfailures'] = [
                'title' => __('Login failures', 'integrasipaman'),
                'page'  => self::BASE . '/loginfailures.php',
                'icon'  => 'ti ti-lock-exclamation',
                'links' => ['search' => self::BASE . '/loginfailures.php'],
            ];
            // 4.2.0: who shared a session, with whom. Same right as the report.
            $options['sessionsharing'] = [
                'title' => __('Session sharing', 'integrasipaman'),
                'page'  => self::BASE . '/sessionsharing.php',
                'icon'  => 'ti ti-share',
                'links' => ['search' => self::BASE . '/sessionsharing.php'],
            ];
        }

        // front/export.php (Activity Log) is deliberately NOT a menu option.
        // It renders its own standalone <!doctype html> page with hand-rolled
        // CSS and no GLPI chrome - it was built as a new-tab popup opened from
        // the Setup page, so reaching it from the menu would drop the user
        // on a page with no navigation back. It stays reachable exactly as
        // before: the "View Activity Log" button on the Setup page. Giving it
        // an option means converting that view to Html::header()/footer()
        // first.

        // Correlation is where mappings are edited, so it needs UPDATE - the
        // controller enforces the same right.
        if ($can_write) {
            $correlation = self::BASE . '/correlation.php';
            $options['correlation'] = [
                'title' => __('Asset & user correlation', 'integrasipaman'),
                'page'  => $correlation,
                'icon'  => 'ti ti-link',
                'links' => ['search' => $correlation],
            ];
        }

        // Gated on its own right, separate from config/report: a person
        // who may only send/remove PAMAN assets holds neither of the above.
        if (Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, READ)) {
            $provisioning = self::BASE . '/provisioning.php';
            $options['provisioning'] = [
                'title' => __('Asset provisioning', 'integrasipaman'),
                'page'  => $provisioning,
                'icon'  => 'ti ti-cloud-upload',
                'links' => ['search' => $provisioning],
            ];
        }

        // Kept as a page rather than dropped like opmanager did: a holder of
        // this plugin's right who lacks core `config` cannot open
        // Setup > General, so for them front/config.form.php is the only way
        // to reach these settings. Users who do hold core `config` READ are
        // redirected from there to the shared suite tab.
        if ($can_config) {
            $options['setup'] = [
                'title' => __('Setup', 'integrasipaman'),
                'page'  => $setup,
                'icon'  => 'ti ti-settings',
                'links' => ['search' => $setup],
            ];
        }

        // canView() passes on any of three rights, while each page above has
        // its own gate. If a combination ever opens none of them: no page,
        // no entry - not a warning on reset()'s false (code review, 4.0.0).
        if ($options === []) {
            return null;
        }

        // The entry opens the first page this user may see, so a
        // provisioning-only or config-only holder is not sent to a page that
        // denies them.
        return [
            'title'   => self::getTypeName(),
            'icon'    => self::getIcon(),
            'page'    => reset($options)['page'],
            'order'   => self::ORDER,
            'options' => $options,
        ];
    }
}
