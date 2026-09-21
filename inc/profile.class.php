<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The plugin's own right (plugin_integrasipaman_config) and the standard
 * GLPI rights matrix for it on Administration > Profiles.
 *
 * Before v2.0.0 every page of this plugin was gated on core `config`
 * UPDATE, which means giving a SecOps/PAM operator access to the JumpServer
 * reports also handed them the whole of GLPI's Setup > General. The suite
 * decision of 2026-09-14 is that each PGN plugin carries its own right:
 *   READ   - PBI Insights, Command Audit Report, activity log, exports,
 *            asset/user mapping lists.
 *   UPDATE - settings, re-evaluate, backfill, editing a mapping.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanProfile extends Profile
{
    /** The single right this plugin owns. */
    public const RIGHT = 'plugin_integrasipaman_config';

    /**
     * Every right name this plugin installs, for
     * ProfileRight::addProfileRights()/deleteProfileRights().
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::RIGHT];
    }

    public static function getTypeName($nb = 0)
    {
        return __('JumpServer Integration', 'integrasipaman');
    }

    /**
     * Non-static on purpose: \Profile declares getTabNameForItem() as an
     * instance method while keeping displayTabContentForItem() static.
     * Declaring this one static is a fatal "Cannot make non static method
     * static" the moment a profile page loads.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Profile) || $item->getID() <= 0) {
            return '';
        }

        return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-terminal-2');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Profile)) {
            return false;
        }

        // getFromDB() is what loads the profile's rights into ->fields; the
        // $item handed to a tab has them only for the profile being edited.
        $profile = new Profile();
        if (!$profile->getFromDB($item->getID())) {
            return false;
        }

        $canedit = Profile::canUpdate();

        if ($canedit) {
            echo '<form method="post" action="' . htmlescape(Profile::getFormURL()) . '">';
        }

        // No save handler of our own: core Profile::prepareInputForUpdate()
        // reads _<right> for every right in ProfileRight::getAllPossibleRights(),
        // which includes this plugin's right once installed.
        $profile->displayRightsChoiceMatrix(
            [
                [
                    'itemtype' => '',
                    'rights'   => [
                        READ   => __('Read'),
                        UPDATE => __('Update'),
                    ],
                    'label'    => __('Configuration & reports', 'integrasipaman'),
                    'field'    => self::RIGHT,
                ],
            ],
            [
                'canedit'       => $canedit,
                'default_class' => 'tab_bg_2',
                'title'         => self::getTypeName(),
            ]
        );

        if ($canedit) {
            // Html::hidden()/submit() RETURN their markup in GLPI 11 - without
            // echo the form renders with no Save button and no profile id.
            echo Html::hidden('id', ['value' => $item->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            Html::closeForm();
        }

        return true;
    }

    /**
     * Install/upgrade seeding.
     *
     * plugin:install --force (the only reliable upgrade path on glpi-server)
     * re-runs the install hook on every upgrade, so this must not clobber
     * what an admin set in the rights matrix. Two rules, from the suite
     * contract:
     *   - rights that already exist in this instance are seeded for NOBODY;
     *     only a right created in *this* run is seeded.
     *   - a super-admin profile (identified by holding UPDATE on core
     *     `config`, never by name - names are renameable and localised) is
     *     re-granted the full mask on every run, so an upgrade can never
     *     leave the instance with nobody able to configure the plugin.
     *     Accepted consequence: narrowing this plugin's rights on a
     *     config/UPDATE profile does not survive the next upgrade; narrow a
     *     profile without core config UPDATE instead.
     *
     * Seeding a brand-new right mirrors the profile's core `config` mask, so
     * everyone who could reach these pages before the right existed keeps
     * exactly the access they had.
     */
    public static function seedRights(): void
    {
        $existing = array_keys(ProfileRight::getAllPossibleRights());
        $to_add   = array_values(array_diff(self::names(), $existing));

        if ($to_add !== []) {
            // Blind INSERT: passing an already-existing name is a UNIQUE key fatal.
            ProfileRight::addProfileRights($to_add);
        }

        $all = new Profile();
        foreach ($all->find() as $row) {
            $profile = new Profile();
            if (!$profile->getFromDB((int) $row['id'])) {
                continue;
            }

            $core_config = (int) ($profile->fields['config'] ?? 0);
            $is_admin    = (bool) ($core_config & UPDATE);

            if ($is_admin) {
                // Every run, not just $to_add - see the docblock.
                ProfileRight::updateProfileRights((int) $row['id'], [self::RIGHT => READ | UPDATE]);
                continue;
            }

            if ($to_add === []) {
                continue;
            }

            $mask = ($core_config & READ) ? READ : 0;
            ProfileRight::updateProfileRights((int) $row['id'], [self::RIGHT => $mask]);
        }
    }

    /** Uninstall: drop the right from every profile. */
    public static function dropRights(): void
    {
        ProfileRight::deleteProfileRights(self::names());
    }
}
