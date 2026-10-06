<?php

/**
 * This plugin's sections inside the shared "PGN Suite" asset tab.
 *
 * Suite contract v1 r1 (hub section 5, user decision 2026-09-24): every PGN
 * plugin tab on an asset form collapses into one tab with horizontal
 * sub-tabs, so a Computer stops carrying five or six plugin tabs. nvd stays
 * out by the user's decision.
 *
 * integrasipaman contributes TWO sections, because it had two Computer tabs:
 *
 *   10  JumpServer access    read-only session/command history
 *   15  PAMAN provisioning   the provisioning state, with Send/Resend/Remove
 *
 * Both numbers sit inside integrasipaman's allocated band (10-19), so adding
 * a third later needs no coordination.
 *
 * This class is a ROUTER, not a renderer. Each section's `render` points at
 * the same displayTabContentForItem() the standalone tab used, which is why
 * migrating cost no change to either tab body: the contract passes only the
 * item, and both methods take ($item, $tabnum = 1, $withtemplate = 0). They
 * re-check their own right, so the gate below is defence in depth rather
 * than the only guard.
 */
require_once __DIR__ . '/serverlabel.class.php';

class PluginIntegrasipamanAssetSections
{
    /**
     * The itemtypes this plugin puts sections on.
     *
     * The suite's scope is Computer, NetworkEquipment, Security and Storage,
     * but integrasipaman's data is keyed on Computer alone: both the asset
     * map and the provisioning link table carry a computers_id. Registering
     * for more would mean a tab that never has anything to show.
     */
    public const ITEMTYPES = ['Computer'];

    /**
     * @return list<array{key: string, title: string, icon: string, order: int, render: string}>
     */
    public static function sections(CommonDBTM $item): array
    {
        // Not a Computer -> no sections. Returning [] rather than a section
        // that renders nothing is what lets AssetTab hide the whole tab when
        // no plugin has anything for this item.
        if (!($item instanceof Computer)) {
            return [];
        }

        $sections = [];

        if (PluginIntegrasipamanAccessHistory::canView()) {
            $sections[] = [
                'key'    => 'access',
                'title'  => __('JumpServer access', 'integrasipaman'),
                'icon'   => 'ti ti-history',
                'order'  => 10,
                'render' => 'PluginIntegrasipamanAccessHistory::displayTabContentForItem',
            ];
        }

        if (Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, READ)) {
            $sections[] = [
                'key'    => 'provisioning',
                'title'  => PluginIntegrasipamanServerLabel::in(__('{server} provisioning', 'integrasipaman')),
                'icon'   => 'ti ti-cloud-upload',
                'order'  => 15,
                'render' => 'PluginIntegrasipamanProvisioning::displayTabContentForItem',
            ];
        }

        return $sections;
    }

    /*
     * Contract rule 2 - rememberSection() before Html::back() - does NOT
     * apply to this plugin, and deliberately so.
     *
     * The provisioning section's Send/Resend/Remove buttons POST to
     * front/provisioning.php, the queue page, which renders the result
     * inline and never redirects back to the Computer form. There is no
     * Html::back() to precede, and the operator does not return to the
     * sub-tab - they land on the queue with the full result in front of
     * them.
     *
     * That is not an oversight to fix here. The queue page avoids
     * redirecting because a plugin front file's $_SERVER['PHP_SELF'] under
     * GLPI 11's Symfony routing is not the real path - the trap that broke
     * front/correlation.php once already. Adding a redirect to satisfy the
     * rule would reintroduce a known defect to solve a problem this plugin
     * does not have.
     *
     * If those POSTs are ever changed to return to the asset form, the rule
     * applies from that moment and the call belongs on every path,
     * including the failure paths.
     */
}
