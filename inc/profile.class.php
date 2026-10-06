<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The plugin's own rights and the standard GLPI rights matrix for them on
 * Administration > Profiles.
 *
 * Before v2.0.0 every page of this plugin was gated on core `config`
 * UPDATE, which means giving a SecOps/PAM operator access to the JumpServer
 * reports also handed them the whole of GLPI's Setup > General. The suite
 * decision of 2026-09-14 is that each PGN plugin carries its own right, so
 * v2.0.0 introduced plugin_integrasipaman_config covering both halves.
 *
 * v2.4.0 splits that single right in two, because "can read the PAM audit
 * trail" and "can change how it is collected" are different jobs - and the
 * report side is the sensitive one, carrying privileged usernames and the
 * commands they ran:
 *
 *   plugin_integrasipaman_config  READ   - see the settings and the mappings
 *                                 UPDATE - change them; backfill, re-evaluate,
 *                                          correlation
 *   plugin_integrasipaman_report  READ   - PBI Insights, Command Audit Report
 *                                          and its exports, activity log,
 *                                          dashboard card
 *
 * Mappings sit under config rather than report: editing one changes what the
 * correlation produces, which is administration, not reading. The report
 * right has no UPDATE - there is nothing in a report to update.
 *
 * seedRights() below documents how existing installs migrate; the short
 * version is that an upgrade never revokes anything.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/serverlabel.class.php';

class PluginIntegrasipamanProfile extends Profile
{
    /**
     * Settings, mappings, and the operations that rewrite derived data
     * (backfill / re-evaluate / correlation). READ = see them, UPDATE =
     * change them.
     */
    public const RIGHT_CONFIG = 'plugin_integrasipaman_config';

    /**
     * The reports: PBI Insights, the Command Audit Report and its exports,
     * the activity log, the dashboard card. READ only - there is nothing to
     * update in a report, and holding it must not imply access to the
     * settings or the mapping tables.
     */
    public const RIGHT_REPORT = 'plugin_integrasipaman_report';

    /**
     * Asset provisioning: the queue page and the PAMAN tab on Computer.
     *
     * READ  = see the queue and the tab, with no buttons at all.
     * CREATE = send and resend assets to PAMAN.
     * PURGE  = remove an asset from PAMAN against a ticket.
     *
     * CREATE and PURGE are split because "may add an asset to the bastion"
     * and "may revoke access to a production asset" are not the same
     * authority, and a site that wants them held by different people must
     * be able to say so.
     */
    public const RIGHT_PROVISIONING = 'plugin_integrasipaman_provisioning';

    /**
     * Every right name this plugin installs, for
     * ProfileRight::addProfileRights()/deleteProfileRights().
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::RIGHT_CONFIG, self::RIGHT_REPORT, self::RIGHT_PROVISIONING];
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
                    'label'    => __('Configuration', 'integrasipaman'),
                    'field'    => self::RIGHT_CONFIG,
                ],
                [
                    'itemtype' => '',
                    // READ only on purpose: a report has nothing to update.
                    'rights'   => [
                        READ => __('Read'),
                    ],
                    'label'    => __('Reports', 'integrasipaman'),
                    'field'    => self::RIGHT_REPORT,
                ],
                [
                    'itemtype' => '',
                    'rights'   => [
                        READ   => __('Read'),
                        CREATE => PluginIntegrasipamanServerLabel::in(__('Send to {server}')),
                        PURGE  => PluginIntegrasipamanServerLabel::in(__('Remove from {server}')),
                    ],
                    'label'    => __('Asset provisioning', 'integrasipaman'),
                    'field'    => self::RIGHT_PROVISIONING,
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
     * plugin:install --force (the only reliable upgrade path on GLPI-SERVER)
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
     *
     * The v2.4.0 split of the single right into config + report seeds the two
     * cases differently, because they are not the same question:
     *
     *   - **Fresh install** (both names created in this run): the intended
     *     separation from the start - core `config` READ buys the reports,
     *     not the settings.
     *   - **Upgrade** (only the report name is new): the report right mirrors
     *     whatever the combined right already held, and RIGHT_CONFIG is NOT
     *     written at all. Nobody loses access on upgrade, and a mask an admin
     *     narrowed by hand survives. Narrowing is then a deliberate one-off in
     *     the matrix instead of something an upgrade does silently - and it
     *     has to survive, because plugin:install --force re-runs this on every
     *     upgrade and would otherwise re-apply the same revocation forever.
     *
     * Note the gate is per right name, not `$to_add !== []`: once a second
     * name exists, a run that creates only the new one must not take the
     * seeding path for the old one as well.
     */
    public static function seedRights(): void
    {
        $existing = array_keys(ProfileRight::getAllPossibleRights());
        $to_add   = array_values(array_diff(self::names(), $existing));

        if ($to_add !== []) {
            // Blind INSERT: passing an already-existing name is a UNIQUE key fatal.
            ProfileRight::addProfileRights($to_add);
        }

        // Which of the two names this run actually created decides how a
        // non-admin profile is seeded - see the docblock.
        $fresh      = in_array(self::RIGHT_CONFIG, $to_add, true);
        $new_report = in_array(self::RIGHT_REPORT, $to_add, true);

        $all = new Profile();
        foreach ($all->find() as $row) {
            $profile = new Profile();
            if (!$profile->getFromDB((int) $row['id'])) {
                continue;
            }

            $core_config = (int) ($profile->fields['config'] ?? 0);
            $is_admin    = (bool) ($core_config & UPDATE);

            // RIGHT_PROVISIONING is absent from the NON-admin branches
            // below: addProfileRights() above creates it with a zero mask,
            // and for everyone except Super-Admin it stays zero until an
            // admin grants it in the matrix.
            //
            // Super-Admin is different, by the user's decision of
            // 2026-09-24. This plugin argued for the opposite - the right
            // authorises writes to the production bastion, so nobody should
            // receive it as a side effect of an upgrade - and the user
            // rejected that: the suite rule "Super-Admin selalu penuh"
            // (hub section 7, 2026-09-16) applies here like everywhere else.
            // A Super-Admin who should not provision is handled by not
            // being Super-Admin, not by narrowing one plugin's right.
            //
            // The ticket gate on removal is untouched by this and still
            // applies to Super-Admin: holding PURGE is not the same as
            // being allowed to remove a host that no ticket covers.

            if ($is_admin) {
                // Every run, not just $to_add - see the docblock. That is
                // why a mask narrowed by hand on THIS profile comes back on
                // the next upgrade; it is the documented trade-off for the
                // suite rule, and it now covers the provisioning right too.
                ProfileRight::updateProfileRights((int) $row['id'], [
                    self::RIGHT_CONFIG       => READ | UPDATE,
                    self::RIGHT_REPORT       => READ,
                    self::RIGHT_PROVISIONING => READ | CREATE | PURGE,
                ]);
                continue;
            }

            if (!$new_report) {
                // Nothing was created this run that a non-admin can inherit.
                continue;
            }

            if ($fresh) {
                ProfileRight::updateProfileRights((int) $row['id'], [
                    self::RIGHT_CONFIG => 0,
                    self::RIGHT_REPORT => ($core_config & READ) ? READ : 0,
                ]);
                continue;
            }

            // Upgrade: inherit from the combined right, and do not touch
            // RIGHT_CONFIG - that mask is the admin's, not ours.
            $combined = (int) ($profile->fields[self::RIGHT_CONFIG] ?? 0);
            ProfileRight::updateProfileRights((int) $row['id'], [
                self::RIGHT_REPORT => ($combined & READ) ? READ : 0,
            ]);
        }
    }

    /** Uninstall: drop the right from every profile. */
    public static function dropRights(): void
    {
        ProfileRight::deleteProfileRights(self::names());
    }
}
