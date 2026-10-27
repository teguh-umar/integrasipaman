<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One JumpServer terminal session, as a GLPI itemtype (v4.7.0).
 *
 * This exists so the Log Session tab can be rendered by GLPI's own Search
 * engine instead of a hand-rolled table. What that buys, and why it was
 * worth a new itemtype:
 *
 *   - a per-user column picker (core stores it in glpi_displaypreferences);
 *   - real pagination. The hand-rolled table read at most 5000 rows and
 *     rendered all of them; this instance holds 39,800 sessions, so an
 *     unwindowed page showed 12.5% of the data and its PHP-side text filter
 *     searched only that slice (v4.6.0);
 *   - sorting, per-column criteria, saved searches and core's own exports.
 *
 * It owns no table of its own: glpi_plugin_jumpserver_sessions is filled by
 * PluginIntegrasipamanTerminalPull::cronPull_terminal_activity() for the
 * command-audit work. Nothing here writes to it.
 *
 * READ-ONLY on purpose. The rows mirror JumpServer's audit record; editing
 * one in GLPI would create a second, divergent version of an audit trail
 * whose authority lives elsewhere. Hence canCreate()/canUpdate()/canPurge()
 * all return false, and no form controller is shipped.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanSession extends CommonDBTM implements \Glpi\Search\DefaultSearchRequestInterface
{
    /**
     * Open the list newest-first (v4.7.0-rc2).
     *
     * Without this, rc1 on the VM opened on 1 August while the newest session
     * was 25 October: core falls back to its own ordering, which here follows
     * insertion, and a back-filled month is the most recently inserted batch.
     * For a list whose question is "who connected recently", the first page
     * must answer it before anyone touches Sort.
     *
     * The interface is not decoration: core reads this method ONLY from a
     * class that implements it (Glpi\Search\Input\QueryBuilder:729, verified
     * against 11.0/bugfixes). Declaring the method alone does nothing.
     *
     * A user's own saved default still wins over this - core merges
     * SavedSearch_User::getDefault() on top.
     *
     * @return array{sort: int, order: string}
     */
    public static function getDefaultSearchRequest(): array
    {
        return [
            'sort'  => 1,       // date_start
            'order' => 'DESC',
        ];
    }

    /**
     * The plugin's own report right, not core `config`.
     *
     * The four older searchable itemtypes here (usermap, assetmap, nodemap,
     * platformmap) use core `config` because their pages are config-gated.
     * This one is on the report page, which a report-only profile must be
     * able to open, so it would be locked out by `config`.
     */
    public static $rightname = PluginIntegrasipamanProfile::RIGHT_REPORT;

    /**
     * The class name does not pluralise to the table name, so core's
     * DbUtils::getExpectedTableNameForClass() would derive
     * `glpi_plugin_integrasipamansessions` and the first core call touching
     * this itemtype would die with "Table doesn't exist". The table was
     * named for the data (jumpserver), the class for the plugin.
     */
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_jumpserver_sessions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('JumpServer session', 'JumpServer sessions', $nb, 'integrasipaman');
    }

    public static function getIcon()
    {
        return 'ti ti-terminal';
    }

    public static function canView(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);
    }

    /** Rows come from JumpServer's audit API; nothing in GLPI may author one. */
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

    /**
     * Search options.
     *
     * No column uses the `itemlink` datatype: that renders a link to the
     * item's form, and this itemtype deliberately ships no form controller,
     * so every such link would land on core's generic-route fallback - a
     * bare page with no sidebar (the trap documented in the suite hub for
     * assetconsolidation's ExportProfile).
     *
     * `duration`, `is_success` and `is_finished` are `specific`: an INT of
     * seconds and two tinyints would otherwise print as "2697" and "0"/"1".
     * See getSpecificValueToDisplay().
     *
     * Ids are stable once published: core stores a user's chosen columns in
     * glpi_displaypreferences BY SEARCH OPTION ID, so renumbering them later
     * silently rearranges everyone's saved layout.
     */
    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'date_start',
            'name'          => __('Start Date', 'integrasipaman'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'date_end',
            'name'          => __('End Date', 'integrasipaman'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'duration',
            'name'          => __('Time spent', 'integrasipaman'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'contains'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 4,
            'table'         => self::getTable(),
            'field'         => 'jms_user',
            'name'          => __('User', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 5,
            'table'         => self::getTable(),
            'field'         => 'asset',
            'name'          => __('Asset', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 6,
            'table'         => self::getTable(),
            'field'         => 'account',
            'name'          => __('Account', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 7,
            'table'         => self::getTable(),
            'field'         => 'protocol',
            'name'          => __('Protocol', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        // v4.8.0: Login Source reads as "Web Terminal(WT)" where the label is
        // known, and falls back to the bare code where it is not - which is
        // every row imported before 4.8.0 until
        // front/backfillsessionmeta.php has run. It stays keyed on
        // `login_from`, the stable value, so a criterion still filters on
        // the code and a saved search survives a label change.
        $tab[] = [
            'id'            => 8,
            'table'         => self::getTable(),
            'field'         => 'login_from',
            'name'          => __('Login Source', 'integrasipaman'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'contains'],
            'additionalfields' => ['login_from_label'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 9,
            'table'         => self::getTable(),
            'field'         => 'remote_addr',
            'name'          => __('Remote Address', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 10,
            'table'         => self::getTable(),
            'field'         => 'command_amount',
            'name'          => __('Commands', 'integrasipaman'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 11,
            'table'         => self::getTable(),
            'field'         => 'is_success',
            'name'          => __('Result', 'integrasipaman'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 12,
            'table'         => self::getTable(),
            'field'         => 'is_finished',
            'name'          => __('State', 'integrasipaman'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 13,
            'table'         => self::getTable(),
            'field'         => 'terminal_name',
            'name'          => __('Terminal', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 14,
            'table'         => self::getTable(),
            'field'         => 'error_reason',
            'name'          => __('Error reason', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => 15,
            'table'         => self::getTable(),
            'field'         => 'jms_id',
            'name'          => __('Session ID', 'integrasipaman'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];
        // v4.8.0. Keyed on `type`, the stable value ("normal"), and rendered
        // with its label ("Normal(normal)") the way JumpServer's own export
        // shows it. Empty on every row imported before 4.8.0 until
        // front/backfillsessionmeta.php has run - and empty must read as
        // "not known", never as a type.
        $tab[] = [
            'id'               => 16,
            'table'            => self::getTable(),
            'field'            => 'type',
            'name'             => __('Type', 'integrasipaman'),
            'datatype'         => 'specific',
            'searchtype'       => ['equals', 'contains'],
            'additionalfields' => ['type_label'],
            'massiveaction'    => false,
        ];
        $tab[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    /**
     * Render the three columns whose stored form is not their readable form.
     *
     * Core calls this statically for every `specific` search option, in the
     * list AND in an export, so formatting here is the only place it has to
     * be done.
     *
     * @param string $field   The column name.
     * @param mixed  $values  The row, or the bare value.
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'duration':
                include_once(__DIR__ . '/sessionlog.class.php');

                // One formatter for both renderings of this data, so the
                // Search list and the Session Sharing tab cannot drift.
                return PluginIntegrasipamanSessionLog::formatDuration((int) ($values[$field] ?? 0));

            case 'is_success':
                return empty($values[$field])
                    ? '<span class="badge bg-red-lt">' . htmlescape(__('Failed', 'integrasipaman')) . '</span>'
                    : '<span class="badge bg-green-lt">' . htmlescape(__('Success', 'integrasipaman')) . '</span>';

            case 'is_finished':
                return empty($values[$field])
                    ? '<span class="badge bg-blue-lt">' . htmlescape(__('Running', 'integrasipaman')) . '</span>'
                    : htmlescape(__('Finished', 'integrasipaman'));

            // v4.8.0. Both render "Label(value)", as JumpServer's own export
            // does. An empty value means the row predates 4.8.0 and the
            // one-shot backfill has not reached it; it shows a dash, because
            // "not known" must not look like a type or a source.
            case 'type':
                return self::labelledValue(
                    (string) ($values[$field] ?? ''),
                    (string) ($values['type_label'] ?? '')
                );

            case 'login_from':
                return self::labelledValue(
                    (string) ($values[$field] ?? ''),
                    (string) ($values['login_from_label'] ?? '')
                );
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * A {value,label} pair as one cell: "Label(value)" when both halves are
     * known, the value alone when the label is not, and a dash when there is
     * nothing (v4.8.0).
     *
     * The dash matters. Every session imported before 4.8.0 has these
     * columns empty until front/backfillsessionmeta.php has run, and some
     * will stay empty for good because JumpServer rotates its own history
     * out of reach. An empty cell must read as "not known", never as a type
     * or a source that was actually recorded.
     */
    private static function labelledValue(string $value, string $label): string
    {
        if ($value === '' && $label === '') {
            return '<span class="text-muted">&mdash;</span>';
        }
        if ($label === '') {
            return htmlescape($value);
        }
        if ($value === '') {
            return htmlescape($label);
        }

        return htmlescape($label . '(' . $value . ')');
    }

    /**
     * The dropdown offered when searching on one of the `specific` columns.
     *
     * Without this, a criterion on Result or State gives a free-text box
     * against a tinyint, where "Failed" matches nothing.
     *
     * @param string $field
     * @param string $name
     * @param mixed  $values
     * @param array<string, mixed> $options
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;

        switch ($field) {
            case 'is_success':
                return Dropdown::showFromArray($name, [
                    0 => __('Failed', 'integrasipaman'),
                    1 => __('Success', 'integrasipaman'),
                ], ['value' => $values[$field], 'display' => false]);

            case 'is_finished':
                return Dropdown::showFromArray($name, [
                    0 => __('Running', 'integrasipaman'),
                    1 => __('Finished', 'integrasipaman'),
                ], ['value' => $values[$field], 'display' => false]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
