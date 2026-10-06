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

require_once __DIR__ . '/serverlabel.class.php';

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
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ);
    }

    public static function canCreate(): bool
    {
        // Rows are created only by the resolver's discovery pass, never
        // from the UI - the two front controllers do not handle 'add'.
        return false;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);
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

        // VARCHAR(20) takes any string, and front/assetmap.form.php hands
        // $_POST straight to update(). An out-of-domain value would be
        // stored AND would trip the lock below, so the resolver could never
        // correct it - an asset scoring as unclassified while absent from
        // the unclassified queue, permanently and silently.
        if (isset($input['asset_class'])
            && !in_array((string) $input['asset_class'], ['', 'production', 'development'], true)) {
            unset($input['asset_class']);
        }

        $reverting = isset($input['is_manual'])
            && (int) $input['is_manual'] === 0
            && (int) ($this->fields['is_manual'] ?? 0) === 1;

        // Two fields carry a verdict the resolver would otherwise recompute:
        // asset_class, and - since v3.5.0 - the Computer the asset points
        // at. Changing either locks the row.
        //
        // The lock is not a convenience. The form posts every field on every
        // save, and resolveAll() rewrites any row that is not locked, so an
        // operator who picks the right Computer and leaves Manual override
        // at No loses the pick at the next run with nothing on screen to say
        // it happened. Locking on change is what makes the control mean what
        // it appears to mean.
        $verdict_changed =
            // Clearing the link counts: "this asset is deliberately not
            // mapped" is a verdict, and an unlocked empty row is simply
            // re-mapped on the next run.
            (isset($input['computers_id'])
                && (int) $input['computers_id'] !== (int) ($this->fields['computers_id'] ?? 0))
            // Clearing back to '' counts: "this was looked at and is
            // genuinely neither" is a statement, and an unlocked empty row
            // is simply re-derived on the next run.
            || (isset($input['asset_class'])
                && (string) $input['asset_class'] !== (string) ($this->fields['asset_class'] ?? ''));

        if (!$reverting && $verdict_changed) {
            $input['is_manual']      = 1;
            $input['resolve_method'] = 'manual';
        }

        return $input;
    }

    public function post_updateItem($history = 1)
    {
        // An override that flips the verdict must re-evaluate the affected
        // command rows - bump the generation rulesHash() folds in.
        if (in_array('asset_class', $this->updates, true)) {
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
            'id'            => 4,
            'table'         => self::getTable(),
            'field'         => 'asset_class',
            'name'          => __('Classification', 'integrasipaman'),
            'datatype'      => 'string',
            // Free-text massive edit into a three-value domain has no
            // legitimate use: an operator typing e.g. "Prod" across 40 rows
            // would store an out-of-domain value and lock every row (see
            // prepareInputForUpdate()), permanently hiding them from the
            // unclassified queue.
            'massiveaction' => false,
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
        $tab[] = [
            'id'       => 12,
            'table'    => self::getTable(),
            'field'    => 'ip_mismatch',
            'name'     => __('Address disagrees with GLPI', 'integrasipaman'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => 13,
            'table'    => self::getTable(),
            'field'    => 'live_checked_at',
            'name'     => PluginIntegrasipamanServerLabel::in(__('Confirmed in {server}', 'integrasipaman')),
            'datatype' => 'datetime',
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

        // The one field on this form that the resolver also writes, and the
        // reason Manual override exists at all. Before v3.5.0 the override
        // could only FREEZE a wrong answer - there was no way to state the
        // right one, so the hint below promised a control that did not exist.
        //
        // Deleted Computers and templates are excluded: a mapping onto either
        // resolves to a Computer nobody can open, which reads as a broken
        // plugin rather than a stale pick.
        echo '<div class="mb-3"><label class="form-label" for="dropdown_computers_id">'
            . __('Computer', 'integrasipaman') . '</label>';
        Computer::dropdown([
            'name'      => 'computers_id',
            'value'     => (int) ($this->fields['computers_id'] ?? 0),
            'entity'    => $this->fields['entities_id'] ?? -1,
            'condition' => ['is_deleted' => 0, 'is_template' => 0],
            'width'     => '100%',
        ]);
        echo '<small class="form-hint d-block">' . htmlescape(
            __('Changing this locks the row so the resolver stops overwriting it.', 'integrasipaman')
        ) . '</small>';
        echo '</div>';

        echo '<div class="row">';
        echo '<div class="mb-3 col-md-6"><label class="form-label" for="dropdown_asset_class">'
            . __('Classification', 'integrasipaman') . '</label><br>';
        Dropdown::showFromArray('asset_class', [
            ''            => __('Not classified', 'integrasipaman'),
            'production'  => __('Production', 'integrasipaman'),
            'development' => __('Development', 'integrasipaman'),
        ], ['value' => (string) ($this->fields['asset_class'] ?? ''), 'width' => '100%']);
        echo '<small class="form-hint d-block">' . htmlescape(
            __('Changing this locks the row so the resolver stops overwriting it.', 'integrasipaman')
        ) . '</small>';
        echo '</div>';
        echo '<div class="mb-3 col-md-6"><label class="form-label" for="is_manual">' . __('Manual override', 'integrasipaman') . '</label><br>';
        Dropdown::showYesNo('is_manual', $this->fields['is_manual']);
        echo '<small class="form-hint d-block">' . htmlescape(__('Set to No to let the resolver manage this row again.', 'integrasipaman')) . '</small>';
        echo '</div>';
        echo '</div>';

        if ((int) ($this->fields['ip_mismatch'] ?? 0) === 1) {
            // v4.0.0: a locked row keeps its link when the Computer is moved
            // out of the connection's scope. Its addresses are that entity's
            // live data and are not shown to someone who cannot see it.
            $computer = new Computer();
            $ips      = ($computer->getFromDB((int) $this->fields['computers_id'])
                    && Session::haveAccessToEntity(
                        (int) $computer->fields['entities_id'],
                        (bool) ($computer->fields['is_recursive'] ?? false)
                    ))
                ? implode(', ', PluginIntegrasipamanCorrelation::computerAddresses((int) $this->fields['computers_id']))
                : '';

            // Both addresses, because the DIFFERENCE is the diagnosis: a
            // matching host octet with a different third tells an operator
            // the machine moved and the inventory did not follow.
            echo '<div class="alert alert-warning">';
            echo htmlescape(sprintf(
                PluginIntegrasipamanServerLabel::in(__('{server} reports %1$s; GLPI records %2$s. One of the two is out of date - this is a signal to investigate, and it does not affect the classification above.', 'integrasipaman')),
                (string) $this->fields['parsed_ip'],
                $ips !== '' ? $ips : __('no address', 'integrasipaman')
            ));
            echo '</div>';
        }

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
