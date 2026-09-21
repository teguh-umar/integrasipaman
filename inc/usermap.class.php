<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One row per DISTINCT `jms_user` string seen in
 * glpi_plugin_jumpserver_commands. Auto-resolved by
 * PluginIntegrasipamanCorrelation (GLPI user by login -> group
 * membership); admin-overridable, which locks the row.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanUserMap extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_jumpserver_usermap';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('JumpServer user mapping', 'JumpServer user mappings', $nb, 'integrasipaman');
    }

    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT, READ);
    }

    public static function canCreate(): bool
    {
        // Rows are created only by the resolver's discovery pass.
        return false;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT, UPDATE);
    }

    /**
     * The resolver owns jms_user / parsed_login / (auto) resolve_method -
     * never accept those from the browser. An edit that flips is_admin
     * locks the row; clearing the lock (is_manual: 1 -> 0) is honoured.
     */
    public function prepareInputForUpdate($input)
    {
        unset($input['jms_user'], $input['parsed_login'], $input['resolve_method']);

        $reverting = isset($input['is_manual'])
            && (int) $input['is_manual'] === 0
            && (int) ($this->fields['is_manual'] ?? 0) === 1;

        if (!$reverting
            && isset($input['is_admin'])
            && (int) $input['is_admin'] !== (int) ($this->fields['is_admin'] ?? 0)) {
            $input['is_manual']      = 1;
            $input['resolve_method'] = 'manual';
        }

        return $input;
    }

    public function post_updateItem($history = 1)
    {
        if (in_array('is_admin', $this->updates, true)) {
            PluginIntegrasipamanCorrelation::bumpGeneration();
        }
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'jms_user',
            'name'          => __('User string', 'integrasipaman'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => 2,
            'table'    => self::getTable(),
            'field'    => 'parsed_login',
            'name'     => __('Parsed login', 'integrasipaman'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 4,
            'table'    => self::getTable(),
            'field'    => 'is_admin',
            'name'     => __('Admin', 'integrasipaman'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 5,
            'table'    => self::getTable(),
            'field'    => 'resolve_method',
            'name'     => __('Resolved by', 'integrasipaman'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 6,
            'table'    => self::getTable(),
            'field'    => 'is_manual',
            'name'     => __('Manual override', 'integrasipaman'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 7,
            'table'    => self::getTable(),
            'field'    => 'note',
            'name'     => __('Note', 'integrasipaman'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id'       => 8,
            'table'    => self::getTable(),
            'field'    => 'last_resolved_at',
            'name'     => __('Last resolved', 'integrasipaman'),
            'datatype' => 'datetime',
        ];
        $tab[] = [
            'id'       => 10,
            'table'    => 'glpi_users',
            'field'    => 'name',
            'name'     => User::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    /**
     * Hand-rolled Bootstrap form (see PluginIntegrasipamanAssetMap::showForm
     * and PluginIntegrasipamanConfig::showFormConfig for the rationale).
     */
    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB((int) $ID)) {
            echo '<div class="alert alert-warning">' . htmlescape(__('Mapping not found.', 'integrasipaman')) . '</div>';
            return false;
        }

        $ro = ['class' => 'form-control', 'readonly' => 'readonly'];

        echo '<form method="post" action="' . htmlescape(self::getFormURL()) . '">';
        echo Html::hidden('id', ['value' => (int) $this->fields['id']]);
        echo '<div class="card"><div class="card-body">';

        echo '<div class="row">';
        echo '<div class="mb-3 col-md-6"><label class="form-label">' . __('User string', 'integrasipaman') . '</label>';
        echo Html::input('jms_user_display', ['value' => $this->fields['jms_user']] + $ro) . '</div>';
        echo '<div class="mb-3 col-md-6"><label class="form-label">' . __('Parsed login', 'integrasipaman') . '</label>';
        echo Html::input('parsed_login_display', ['value' => $this->fields['parsed_login']] + $ro) . '</div>';
        echo '</div>';

        echo '<div class="mb-3"><label class="form-label">' . __('Resolved by', 'integrasipaman') . '</label>';
        echo Html::input('resolve_method_display', ['value' => $this->fields['resolve_method']] + $ro) . '</div>';

        echo '<div class="row">';
        echo '<div class="mb-3 col-md-6"><label class="form-label" for="is_admin">' . __('Admin', 'integrasipaman') . '</label><br>';
        Dropdown::showYesNo('is_admin', $this->fields['is_admin']);
        echo '</div>';
        echo '<div class="mb-3 col-md-6"><label class="form-label" for="is_manual">' . __('Manual override', 'integrasipaman') . '</label><br>';
        Dropdown::showYesNo('is_manual', $this->fields['is_manual']);
        echo '<small class="form-hint d-block">' . htmlescape(__('Set to No to let the resolver manage this row again.', 'integrasipaman')) . '</small>';
        echo '</div>';
        echo '</div>';

        echo '<div class="mb-3"><label class="form-label" for="note">' . __('Note', 'integrasipaman') . '</label>';
        echo '<textarea class="form-control" id="note" name="note" rows="2">' . htmlescape($this->fields['note']) . '</textarea></div>';

        echo '<div class="mb-3">';
        echo Html::submit(__('Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        echo '</div>';

        echo '</div></div>';
        Html::closeForm();

        return true;
    }
}
