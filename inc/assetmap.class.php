<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One row per DISTINCT `asset` string seen in
 * glpi_plugin_jumpserver_commands. Rows are created and auto-resolved by
 * PluginIntegrasipamanCorrelation; an admin can override any row's verdict
 * on the GLPI search/edit UI, which locks it (is_manual = 1) so the
 * resolver stops touching it. See
 * docs/superpowers/specs/2026-08-31-pam-command-audit-piece3-correlation-design.md.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanAssetMap extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_jumpserver_assetmap';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('JumpServer asset mapping', 'JumpServer asset mappings', $nb, 'integrasipaman');
    }

    public static function canView(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canCreate(): bool
    {
        // Rows are created only by the resolver's discovery pass, never
        // from the UI - the two front controllers do not handle 'add'.
        return false;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /**
     * The resolver owns asset / parsed_* / (auto) resolve_method - never
     * accept those from the browser (the form renders them readonly, but
     * readonly inputs still POST). An edit that changes the resolved
     * verdict locks the row (is_manual = 1) so the resolver stops
     * overwriting it; clearing the lock (is_manual: 1 -> 0) is honoured
     * and the next resolveAll() re-derives the row.
     */
    public function prepareInputForUpdate($input)
    {
        unset($input['asset'], $input['parsed_name'], $input['parsed_ip'], $input['resolve_method']);

        $reverting = isset($input['is_manual'])
            && (int) $input['is_manual'] === 0
            && (int) ($this->fields['is_manual'] ?? 0) === 1;

        if (!$reverting
            && isset($input['is_production'])
            && (int) $input['is_production'] !== (int) ($this->fields['is_production'] ?? 0)) {
            $input['is_manual']      = 1;
            $input['resolve_method'] = 'manual';
        }

        return $input;
    }

    public function post_updateItem($history = 1)
    {
        // An override that flips the verdict must re-evaluate the affected
        // command rows - bump the generation rulesHash() folds in.
        if (in_array('is_production', $this->updates, true)) {
            PluginIntegrasipamanCorrelation::bumpGeneration();
        }
    }

    public function rawSearchOptions()
    {
        // Not calling parent::rawSearchOptions() on purpose - its option
        // ids 1/2/... would collide with the explicit ids below. This is a
        // small config-gated list, not a full GLPI asset; the columns
        // here are all it needs.
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => 9,
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'asset',
            'name'          => __('Asset string', 'integrasipaman'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => 2,
            'table'    => self::getTable(),
            'field'    => 'parsed_name',
            'name'     => __('Parsed name', 'integrasipaman'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 3,
            'table'    => self::getTable(),
            'field'    => 'parsed_ip',
            'name'     => __('Parsed IP', 'integrasipaman'),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 4,
            'table'    => self::getTable(),
            'field'    => 'is_production',
            'name'     => __('Production', 'integrasipaman'),
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
            'table'    => 'glpi_computers',
            'field'    => 'name',
            'name'     => Computer::getTypeName(1),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => 11,
            'table'    => 'glpi_ipnetworks',
            'field'    => 'completename',
            'name'     => IPNetwork::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $tab;
    }

    /**
     * Hand-rolled Bootstrap form (GLPI 11 moved CommonDBTM form rendering
     * to Twig; every other form in this plugin is built this way - see
     * PluginIntegrasipamanConfig::showFormConfig()). display()
     * (front/assetmap.form.php) has already run getFromDB($ID).
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

        echo '<div class="mb-3"><label class="form-label">' . __('Asset string', 'integrasipaman') . '</label>';
        echo Html::input('asset_display', ['value' => $this->fields['asset']] + $ro) . '</div>';

        echo '<div class="row">';
        echo '<div class="mb-3 col-md-6"><label class="form-label">' . __('Parsed name', 'integrasipaman') . '</label>';
        echo Html::input('parsed_name_display', ['value' => $this->fields['parsed_name']] + $ro) . '</div>';
        echo '<div class="mb-3 col-md-6"><label class="form-label">' . __('Parsed IP', 'integrasipaman') . '</label>';
        echo Html::input('parsed_ip_display', ['value' => $this->fields['parsed_ip']] + $ro) . '</div>';
        echo '</div>';

        echo '<div class="mb-3"><label class="form-label">' . __('Resolved by', 'integrasipaman') . '</label>';
        echo Html::input('resolve_method_display', ['value' => $this->fields['resolve_method']] + $ro) . '</div>';

        echo '<div class="row">';
        echo '<div class="mb-3 col-md-6"><label class="form-label" for="is_production">' . __('Production', 'integrasipaman') . '</label><br>';
        Dropdown::showYesNo('is_production', $this->fields['is_production']);
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
