<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One JumpServer user group and its reach, as a GLPI itemtype.
 *
 * Read-only searchable itemtype over a report table that
 * PluginIntegrasipamanAccessIndex rebuilds after every pull. Nothing here
 * writes to it. No option is itemlink: this itemtype ships no form (see the
 * note in session.class.php). Option ids are stable: core keys each user's
 * chosen columns on them in glpi_displaypreferences.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanAccessGroup extends CommonDBTM implements \Glpi\Search\DefaultSearchRequestInterface
{
    public static $rightname = PluginIntegrasipamanProfile::RIGHT_REPORT;

    public static function getDefaultSearchRequest(): array
    {
        return ['sort' => 1, 'order' => 'ASC'];
    }

    public static function getTable($classname = null)
    {
        return PluginIntegrasipamanAccessIndex::GROUPS_TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Group', 'Groups', $nb, 'integrasipaman');
    }

    public static function getIcon()
    {
        return 'ti ti-users-group';
    }

    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public function rawSearchOptions()
    {
        $t   = self::getTable();
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];
        $tab[] = [
            'id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Name', 'integrasipaman'),
            'datatype' => 'string', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 2, 'table' => $t, 'field' => 'users_amount', 'name' => __('Number of users', 'integrasipaman'),
            'datatype' => 'specific', 'additionalfields' => ['id'], 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 3, 'table' => $t, 'field' => 'comment', 'name' => __('Description', 'integrasipaman'),
            'datatype' => 'text', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => $t, 'field' => 'rules_count', 'name' => __('Rules', 'integrasipaman'),
            'datatype' => 'number', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 5, 'table' => $t, 'field' => 'assets_count', 'name' => __('Assets', 'integrasipaman'),
            'datatype' => 'number', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 6, 'table' => $t, 'field' => 'date_built', 'name' => __('Collected at', 'integrasipaman'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    /** No script tag: Task 7 adds one delegated handler per page for .integrasipaman-members. */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        if ($field === 'users_amount') {
            $id  = (int) ($values['id'] ?? 0);
            $url = Plugin::getWebDir('integrasipaman') . '/front/groupmembers.php?id=' . $id;

            return '<a href="#" class="integrasipaman-members" data-members-url="' . htmlescape($url) . '">'
                . htmlescape((string) (int) ($values['users_amount'] ?? 0)) . '</a>';
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }
}
