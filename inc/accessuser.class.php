<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One JumpServer user and what they can reach, as a GLPI itemtype.
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

class PluginIntegrasipamanAccessUser extends CommonDBTM implements \Glpi\Search\DefaultSearchRequestInterface
{
    public static $rightname = PluginIntegrasipamanProfile::RIGHT_REPORT;

    public static function getDefaultSearchRequest(): array
    {
        return ['sort' => 1, 'order' => 'ASC'];
    }

    public static function getTable($classname = null)
    {
        return PluginIntegrasipamanAccessIndex::USERS_TABLE;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('User', 'Users', $nb, 'integrasipaman');
    }

    public static function getIcon()
    {
        return 'ti ti-user';
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
            'id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('User', 'integrasipaman'),
            'datatype' => 'specific', 'additionalfields' => ['username'], 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 2, 'table' => $t, 'field' => 'username', 'name' => __('Username', 'integrasipaman'),
            'datatype' => 'string', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 3, 'table' => $t, 'field' => 'group_names', 'name' => __('Group', 'integrasipaman'),
            'datatype' => 'text', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 4, 'table' => $t, 'field' => 'asset_names', 'name' => __('Asset', 'integrasipaman'),
            'datatype' => 'specific', 'additionalfields' => ['assets_detail'],
            'searchtype' => ['contains'], 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 5, 'table' => $t, 'field' => 'system_role', 'name' => __('Role', 'integrasipaman'),
            'datatype' => 'string', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 6, 'table' => $t, 'field' => 'account_names', 'name' => __('Account', 'integrasipaman'),
            'datatype' => 'text', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 7, 'table' => $t, 'field' => 'assets_count', 'name' => __('Number of assets', 'integrasipaman'),
            'datatype' => 'number', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 8, 'table' => $t, 'field' => 'date_built', 'name' => __('Collected at', 'integrasipaman'),
            'datatype' => 'datetime', 'massiveaction' => false,
        ];
        $tab[] = [
            'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => Entity::getTypeName(1),
            'datatype' => 'dropdown', 'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * Called by core for the list AND the export. No script tag here: results
     * load over AJAX and the export would carry the script text.
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'name':
                $name = (string) ($values['name'] ?? '');
                $user = (string) ($values['username'] ?? '');
                if ($user === '' || $user === $name) {
                    return htmlescape($name);
                }

                return htmlescape($name . '(' . $user . ')');

            case 'asset_names':
                return self::renderAssets((string) ($values['assets_detail'] ?? ''));
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    private static function dash(): string
    {
        return '<span class="text-muted">&mdash;</span>';
    }

    private static function renderAssets(string $json): string
    {
        $assets = json_decode($json, true);
        if (!is_array($assets) || $assets === []) {
            return self::dash();
        }

        $chips = [];
        foreach ($assets as $a) {
            if (!is_array($a)) {
                continue;
            }
            $live = ($a['live'] ?? true) !== false;
            $chip = '<span class="badge ' . ($live ? 'bg-blue-lt' : 'bg-secondary-lt')
                . ' integrasipaman-chip">' . htmlescape((string) ($a['name'] ?? ''));
            if (!$live) {
                $chip .= ' &mdash; ' . htmlescape(__('no live rule', 'integrasipaman'));
            }
            $chip .= '</span>';

            $cid = (int) ($a['computers_id'] ?? 0);
            if ($cid > 0) {
                $chip = '<a href="' . htmlescape(Computer::getFormURLWithID($cid)) . '">' . $chip . '</a>';
            }

            $chips[] = $chip . self::popup($a['rules'] ?? []);
        }
        if ($chips === []) {
            return self::dash();
        }

        return implode('<span class="visually-hidden">, </span>', $chips);
    }

    private static function popup($rules): string
    {
        $html = '<div class="tooltip-invisible">';
        if (!is_array($rules) || $rules === []) {
            return $html . htmlescape(__('no live rule', 'integrasipaman')) . '</div>';
        }
        foreach ($rules as $r) {
            if (!is_array($r)) {
                continue;
            }
            $via       = (string) ($r['via'] ?? '');
            $protocols = $r['protocols'] ?? [];
            $protocols = is_array($protocols) ? implode(', ', array_map('strval', $protocols)) : (string) $protocols;
            $html .= '<div class="integrasipaman-rule"><strong>' . htmlescape((string) ($r['name'] ?? '')) . '</strong>'
                . ' (' . htmlescape((string) ($r['state'] ?? '')) . ')<br>'
                . htmlescape(__('Via', 'integrasipaman')) . ': '
                . ($via === '' ? htmlescape(__('Direct', 'integrasipaman')) : htmlescape($via)) . '<br>'
                . htmlescape(__('Actions', 'integrasipaman')) . ': ' . htmlescape((string) ($r['actions'] ?? '')) . '<br>'
                . htmlescape(__('Account', 'integrasipaman')) . ': ' . htmlescape((string) ($r['accounts'] ?? '')) . '<br>'
                . htmlescape(__('Protocol', 'integrasipaman')) . ': ' . htmlescape($protocols)
                . '</div>';
        }

        return $html . '</div>';
    }
}
