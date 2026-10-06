<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Maps a GLPI operating system to the PAMAN platform that assets running
 * it land under. One row per operating system, plus one row with
 * operatingsystems_id = 0 acting as the default for anything unmapped.
 *
 * The PAMAN side is chosen from a dropdown fed live by
 * PluginIntegrasipamanPamanLists; the id is what is stored, the label is
 * kept alongside purely so the list still reads sensibly if PAMAN later
 * renames or deletes the platform.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/serverlabel.class.php';

class PluginIntegrasipamanPlatformMap extends CommonDBTM
{
    public static $rightname = 'plugin_integrasipaman_config';

    public const TABLE = 'glpi_plugin_jumpserver_platformmap';

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }


    public static function getTypeName($nb = 0)
    {
        return PluginIntegrasipamanServerLabel::in(_n('{server} platform mapping', '{server} platform mappings', $nb, 'integrasipaman'));
    }

    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);
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
     * The whole table as the lookup PluginIntegrasipamanProvisioning
     * expects: operatingsystems_id => {id, label}, with key 0 as the
     * default.
     *
     * @return array<int, array{id:int, label:string}>
     */
    public static function asLookup(): array
    {
        global $DB;

        $out = [];

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $row) {
            $out[(int) $row['operatingsystems_id']] = [
                'id'    => (int) $row['platform_id'],
                'label' => (string) $row['platform_label'],
            ];
        }

        return $out;
    }

    /**
     * The dropdown only POSTs the chosen platform's id; the label is
     * looked up server-side from the same live list so asLookup() readers
     * never have to guess it. When PAMAN cannot be reached (the form
     * degraded to a text input), whatever label was already stored is
     * kept as-is.
     */
    public function prepareInputForAdd($input)
    {
        // A platform id belongs to one JumpServer (v3.11.0), so a mapping with
        // no connection would never be read by anything. The controller
        // chose the connection that serves the admin's entity; where none
        // does, the row is refused rather than stored as nobody's.
        $stamp = PluginIntegrasipamanConnection::stamp();
        if ($stamp === [] && PluginIntegrasipamanConnection::all() !== []) {
            Session::addMessageAfterRedirect(
                __('No JumpServer connection serves the entity you are working in.', 'integrasipaman'),
                false,
                ERROR
            );
            return false;
        }

        return $this->fillPlatformLabel($input) + $stamp;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->fillPlatformLabel($input);
    }

    private function fillPlatformLabel(array $input): array
    {
        if (isset($input['platform_id'])) {
            require_once __DIR__ . '/pamanlists.class.php';
            $platforms = PluginIntegrasipamanPamanLists::platforms();
            $pid       = (int) $input['platform_id'];
            if (isset($platforms[$pid])) {
                $input['platform_label'] = $platforms[$pid];
            }
        }

        return $input;
    }

    public function rawSearchOptions()
    {
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
            'id'       => 1,
            'table'    => self::getTable(),
            'field'    => 'operatingsystems_id',
            'name'     => __('Operating system', 'integrasipaman'),
            'datatype' => 'dropdown',
            'itemtype' => 'OperatingSystem',
        ];
        $tab[] = [
            'id'       => 2,
            'table'    => self::getTable(),
            'field'    => 'platform_id',
            'name'     => PluginIntegrasipamanServerLabel::in(__('{server} platform id', 'integrasipaman')),
            'datatype' => 'number',
        ];
        $tab[] = [
            'id'       => 3,
            'table'    => self::getTable(),
            'field'    => 'platform_label',
            'name'     => PluginIntegrasipamanServerLabel::in(__('{server} platform', 'integrasipaman')),
            'datatype' => 'string',
        ];
        $tab[] = [
            'id'       => 8,
            'table'    => self::getTable(),
            'field'    => 'date_mod',
            'name'     => __('Last update'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }

    /**
     * Hand-rolled Bootstrap form (GLPI 11 moved CommonDBTM form rendering
     * to Twig; every other form in this plugin is built this way - see
     * PluginIntegrasipamanConfig::showFormConfig()). Unlike assetmap,
     * these rows are created by hand, so this form also serves 'add' (see
     * front/platformmap.form.php).
     */
    public function showForm($ID, array $options = [])
    {
        // Not a registered GLPI itemtype - loaded explicitly at every use
        // site, same precedent as PluginIntegrasipamanJmsApi (see
        // pamanlists.class.php's own require_once of jmsapi.class.php).
        require_once __DIR__ . '/pamanlists.class.php';

        $add = ((int) $ID) <= 0;

        if (!$add && !$this->getFromDB((int) $ID)) {
            echo '<div class="alert alert-warning">' . htmlescape(__('Mapping not found.', 'integrasipaman')) . '</div>';
            return false;
        }

        if ($add) {
            $this->getEmpty();
        }

        echo '<form method="post" action="' . htmlescape(self::getFormURL()) . '">';
        if (!$add) {
            echo Html::hidden('id', ['value' => (int) $this->fields['id']]);
        }
        echo '<div class="card"><div class="card-body">';

        echo '<div class="mb-3"><label class="form-label">' . __('Operating system', 'integrasipaman') . '</label>';
        echo '<small class="form-hint d-block">' . htmlescape(__('Leave at "-----" (id 0) to make this the default row - the fallback used for any operating system with no explicit mapping.', 'integrasipaman')) . '</small>';
        Dropdown::show('OperatingSystem', [
            'name'  => 'operatingsystems_id',
            'value' => (int) ($this->fields['operatingsystems_id'] ?? 0),
            'width' => '100%',
        ]);
        echo '</div>';

        $platforms = PluginIntegrasipamanPamanLists::platforms();

        echo '<div class="mb-3"><label class="form-label">' . PluginIntegrasipamanServerLabel::in(__('{server} platform', 'integrasipaman')) . '</label>';
        if ($platforms === []) {
            // PAMAN unreachable: degrade to a text input rather than an
            // empty dropdown. An admin checking mappings during an outage
            // must not find the form unusable - that is exactly when they
            // need it.
            echo Html::input('platform_id', ['value' => $this->fields['platform_id'] ?? '', 'class' => 'form-control']);
            echo '<small class="form-hint text-warning">'
                . htmlescape(PluginIntegrasipamanServerLabel::in(__('{server} could not be reached; showing the stored value. Save carefully.', 'integrasipaman')))
                . '</small>';
        } else {
            Dropdown::showFromArray('platform_id', $platforms, [
                'value' => (int) ($this->fields['platform_id'] ?? 0),
                'width' => '100%',
            ]);
        }
        echo '</div>';

        echo '<div class="mb-3">';
        echo Html::submit(__('Save'), ['name' => $add ? 'add' : 'update', 'class' => 'btn btn-primary']);
        echo '</div>';

        echo '</div></div>';
        Html::closeForm();

        return true;
    }
}
