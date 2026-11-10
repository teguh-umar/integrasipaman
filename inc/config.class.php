<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Plugin configuration: JumpServer base URL/IP and Access Key credentials
 * (outbound REST API auth), stored in GLPI's core config table
 * (glpi_configs) under a dedicated context.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/serverlabel.class.php';
require_once __DIR__ . '/connection.class.php';

class PluginIntegrasipamanConfig extends CommonDBTM
{

    /**
     * Every accepted value of correlation_match_mode, in dropdown order.
     *
     * Single source of truth on purpose: v2.6.0 shipped with 'hybrid_name'
     * in the dropdown and in the resolver but NOT in front/config.form.php's
     * save whitelist, so choosing it reported success and stored 'ip_only'.
     * A new mode is three edits - dropdown, resolver, controller - and only
     * the first two were covered by tests. The controller now reads this
     * list, so there is nothing left to forget; tests/config_modes_test.php
     * asserts the dropdown and this constant stay the same set.
     */
    public const MATCH_MODES = ['ip_only', 'name_only', 'hybrid', 'hybrid_name'];

    /**
     * Context key this plugin's settings are stored under in
     * GLPI's core Config class (glpi_configs.context).
     */
    public const CONFIG_CONTEXT = 'plugin_integrasipaman';

    /**
     * Default JumpServer organization ID (matches JumpServer's own default
     * "Default" org), used to seed jumpserver_org_id if left blank.
     */
    private const DEFAULT_ORG_ID = '00000000-0000-0000-0000-000000000002';

    /**
     * Configuration field names and their default values.
     *
     * jumpserver_access_key_id/secret authenticate outbound calls this
     * plugin makes to the JumpServer REST API (cron pull - see
     * inc/cron.class.php), via HTTP Signature (HMAC-SHA256) request
     * signing.
     *
     * @var array<string, string>
     */
    /**
     * Seed values for the risk-evaluation config textareas (one entry per
     * line). Ported verbatim from jumpserver_monthly_audit.py's
     * RISK_KEYWORDS, and from ADMIN_USERS.txt / IP_PRODUCTION.txt in the
     * same "AUTO ANALISA PAM" folder. Admins edit these freely afterward.
     */
    private const SEED_RISK_KEYWORDS =
        "passwd\nuseradd\nuserdel\nusermod\ngroupadd\ngroupdel\ngpasswd\n"
      . "sudo\nsu \nvisudo\nchmod 777\nchown\nsystemctl\nservice\n"
      . "journalctl\nshutdown\nreboot\nrm -rf\nmkfs\nfdisk\nparted\n"
      . "dd if=\niptables\nfirewall-cmd\nroute\nip route\nchage\n"
      . "net user\nnet localgroup\nnet group\nsc stop\nsc config\n"
      . "iisreset\ngpupdate\ngpresult\nauditpol\nformat\ndiskpart\n"
      . "del /f\nremove-item";

    private const SEED_ADMIN_USERS =
        "Sysadmin One(sysadmin.one-e)\nSysadmin Two(sysadmin.two)\n"
      . "Sysadmin Three(sysadmin.three-e)\nSysadmin Four(sysadmin.four-e)\n"
      . "Sysadmin Six(sysadmin.six)\nSysadmin Five(sysadmin.five-e)\nSysadmin Seven(sysadmin.seven-e)\n"
      . "Sysadmin Eight(sysadmin.eight)\nSysadmin Nine(sysadmin.nine-e)\nSysadmin Ten(sysadmin.ten-e)\n"
      . "Sysadmin Eleven(sysadmin.eleven-e)\nSysadmin Twelve(sysadmin.twelve-e)\n"
      . "Sysadmin Thirteen(sysadmin.thirteen-e)\nSysadmin Fourteen(sysadmin.fourteen-e)\n"
      . "Sysadmin Fifteen(sysadmin.fifteen-e)";

    private const SEED_PROD_PREFIXES = "10.0.0.\n10.0.1.";

    /**
     * Seeded byte-identical in meaning to v3.7.0's CATEGORY_MAP constant,
     * so the upgrade re-categorises every historical row to exactly the
     * category it already had. Line order is match order.
     *
     * Note `su ` keeps a trailing space: without it the keyword matches
     * sudo, sudoers, issue and resume.
     */
    private const SEED_COMMAND_CATEGORIES =
        "Account Management: passwd, useradd, userdel, usermod, groupadd, groupdel, gpasswd, chage\n"
      . "Privilege Escalation: sudo, su , visudo\n"
      . "Service Change: systemctl, service, iisreset, sc stop, sc config\n"
      . "Network Security: iptables, firewall-cmd, route, ip route\n"
      . "Destructive: rm -rf, mkfs, fdisk, parted, dd if=, format, diskpart, del /f, remove-item";

    /**
     * What the connection fields of the Setup form show before a connection
     * exists. The connection itself - URL, Access Key, Organization ID,
     * Command Storage ID, the PBI reporting database - is a row of
     * glpi_plugin_jumpserver_connections since v3.11.0, NOT config: see
     * PluginIntegrasipamanConnection. Keyed by the form's field names.
     *
     * The PBI database is a separate MySQL server hosting the
     * jumpserver_pbi_views schema built by a separate ETL project - see
     * docs/superpowers/specs/2026-08-26-jumpserver-pbi-insights-tab-design.md.
     * Not every subsidiary has one, so it is optional per connection.
     */
    public const CONNECTION_FORM_DEFAULTS = [
        'jumpserver_url'                => '',
        'jumpserver_access_key_id'      => '',
        'jumpserver_org_id'             => self::DEFAULT_ORG_ID,
        'jumpserver_command_storage_id' => '',
        'jumpserver_pbi_db_host'        => '',
        'jumpserver_pbi_db_port'        => '3306',
        'jumpserver_pbi_db_name'        => 'jumpserver_pbi_views',
        'jumpserver_pbi_db_user'        => '',
    ];

    public const CONFIG_DEFAULTS = [
        // Terminal command/session pull (inc/terminalpull.class.php +
        // the pull_terminal_activity cron). The two *_months values are
        // read through self::clampMonths().
        'jumpserver_terminal_retention_months' => '13',
        'jumpserver_terminal_backfill_months'  => '13',
        // Command risk evaluation (inc/riskeval.class.php + the
        // evaluate_commands cron). One entry per line; blank lines and
        // lines starting "#" are ignored on load. Piece 3 will replace the
        // admin-user and prod-prefix lookups with GLPI-native sources.
        'jumpserver_risk_keywords'           => self::SEED_RISK_KEYWORDS,
        'jumpserver_admin_users'             => self::SEED_ADMIN_USERS,
        'jumpserver_production_ip_prefixes'  => self::SEED_PROD_PREFIXES,
        // Seeded EMPTY, unlike its production sibling. An invented default
        // here would classify assets nobody has looked at, which is the
        // exact failure this feature exists to remove.
        'jumpserver_development_ip_prefixes' => '',
        // Command categories: one line per category, `Nama: kata1, kata2`.
        // Line order is match order. Seeded with exactly the five categories
        // that were compiled into the plugin up to v3.7.0, so an upgrade
        // re-derives the category every row already had.
        'jumpserver_command_categories'      => self::SEED_COMMAND_CATEGORIES,
        // v4.13.0: catatan of a HIGH row run inside a shared session.
        'jumpserver_shared_session_note'     => 'Shared session',
        // v4.13.0: catatan of every other HIGH row (was "Butuh Tindak Lanjut").
        'jumpserver_direct_access_note'      => 'Akses Langsung Non-Admin',
        // Asset & user correlation (inc/correlation.class.php + the
        // resolver run at the top of the evaluate_commands cron - Piece 3).
        // With both selections empty the resolver routes everything
        // through the two fallback lists above, i.e. identical verdicts
        // to Piece 2. correlation_match_mode is ip_only | name_only |
        // hybrid | hybrid_name (showFormConfig documents each);
        // hybrid; the other two are comma-separated glpi_ipnetworks.id /
        // glpi_groups.id.
        'correlation_match_mode'             => 'ip_only',
        'correlation_production_ipnetworks'  => '',
        'correlation_production_addressings' => '',
        'correlation_admin_groups'           => '',
        // Bumped by the resolver / an admin override only when an
        // asset_class / is_admin verdict actually changes; folded into
        // PluginIntegrasipamanRiskEval::rulesHash() so a plain new asset
        // string does not force a full re-evaluation.
        'correlation_maps_generation'        => '0',
        // Asset provisioning (inc/provisioning.class.php). Comma-separated
        // id lists, matching the correlation_* convention above; the IP
        // prefixes are one per line, matching jumpserver_production_ip_prefixes.
        // Every one empty = that filter does not filter. See the spec.
        'provisioning_eligible_types'     => '',
        'provisioning_eligible_states'    => '',
        'provisioning_dispose_states'     => '',
        'provisioning_eligible_entities'  => '',
        'provisioning_ip_prefixes'        => '',
        // v4.14.0: OS -> platform by family, after the exact per-OS mapping.
        // Kubernetes / SKE are absent on purpose: in PAMAN those are Cloud
        // assets, and provisioning only creates Hosts.
        'provisioning_platform_families'  => "Linux: linux, ubuntu, debian, centos, red hat, rocky, alma, openeuler, oracle\nWindows: windows",
        // Ticket statuses that count as an approval for removing an asset
        // from PAMAN. 5 = Solved, 6 = Closed in core GLPI.
        'provisioning_ticket_statuses'    => '5,6',
        // Cache, not settings: JSON {"at": <timestamp>, "rows": [...]} of
        // the last successful PAMAN nodes/platforms fetch (see
        // inc/pamanlists.class.php). Never shown on showFormConfig() or
        // saved from front/config.form.php - an admin "saving" this page
        // would overwrite a fresh fetch with a stale copy.
        'provisioning_cache_nodes'       => '',
        'provisioning_cache_platforms'   => '',
    ];

    public static function getTypeName($nb = 0)
    {
        return __('JumpServer Integration', 'integrasipaman');
    }

    /** Where the Setup page remembers which connection is being edited. */
    public const SESSION_CONNECTION = 'plugin_integrasipaman_connection';

    /**
     * The connection the Setup form is editing (v4.0.0), among those owned
     * by an entity the admin is working in - switched-off ones included, or
     * there would be no way to switch one back on.
     *
     * In order: the one chosen from the list on the form, the one serving
     * the entity the admin is in, the first one they may see. Otherwise the
     * form is the "new connection" form.
     *
     * @return array{0: bool, 1: ?PluginIntegrasipamanConnection} [new?, connection]
     */
    public static function editedConnection(): array
    {
        $visible = PluginIntegrasipamanConnection::visible(true);
        $chosen  = $_SESSION[self::SESSION_CONNECTION] ?? null;

        if ($chosen === 'new') {
            return [true, null];
        }
        if (is_int($chosen) && isset($visible[$chosen])) {
            return [false, $visible[$chosen]];
        }

        $serving = PluginIntegrasipamanConnection::forActiveEntity();
        if ($serving !== null && isset($visible[$serving->id()])) {
            return [false, $visible[$serving->id()]];
        }
        if ($visible !== []) {
            return [false, reset($visible)];
        }

        return [true, null];
    }

    /**
     * Read a "months" setting defensively: a non-numeric or non-positive
     * value falls back to $default; anything above $max is capped so a typo
     * ("1300") can never trigger an unbounded backfill / retention window.
     */
    public static function clampMonths($raw, int $default = 13, int $max = 60): int
    {
        $n = (int) $raw;

        if ($n < 1) {
            return $default;
        }

        return min($n, $max);
    }

    /**
     * Render the plugin's configuration form.
     * Displayed by front/config.form.php, linked from Setup > Plugins.
     *
     * @return void
     */
    /**
     * Section provider for the shared "PGN Suite" tab on Setup > General.
     * Returns null when the current user may not see the section - the host
     * page only enforces core `config` READ, so the plugin right is checked
     * here (suite contract v1, hub file section 5).
     *
     * @return array{title:string,icon:string,order:int,render:string}|null
     */
    public static function suiteSection(): ?array
    {
        if (!Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ)) {
            return null;
        }

        return [
            'title'  => __('JumpServer Integration', 'integrasipaman'),
            'icon'   => 'ti ti-terminal-2',
            'order'  => 40,
            'render' => self::class . '::renderSuiteSection',
        ];
    }

    /** Body of the PGN Suite section: the same form as the standalone page. */
    public static function renderSuiteSection(): void
    {
        (new self())->showFormConfig(true);
    }

    /**
     * @param bool $from_pgnsuite True when rendered inside the shared PGN
     *                            Suite tab. Emits the _from_pgnsuite marker
     *                            so front/config.form.php knows to redirect
     *                            back to the tab instead of Html::back().
     */
    public function showFormConfig(bool $from_pgnsuite = false): void
    {
        global $DB;

        if (!Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ)) {
            return;
        }

        $canedit = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

        $config_values = array_merge(
            self::CONFIG_DEFAULTS,
            Config::getConfigurationValues(self::CONFIG_CONTEXT)
        );

        // v3.11.0: the connection fields come from the connection row. The
        // form keeps its field names, so this is the only place that maps
        // them. The two secrets are never read into the page.
        // v4.0.0: there can be several. The form edits one of them - see
        // editedConnection() - or is the form for a new one.
        [$connection_is_new, $connection] = self::editedConnection();

        // v4.4.0: the form is for one connection, for a new one, or for the
        // defaults every connection inherits. In the last case there is no
        // connection on the form at all.
        $defaults_mode = self::editingDefaults();
        if ($defaults_mode) {
            $connection        = null;
            $connection_is_new = false;
        }
        foreach (PluginIntegrasipamanConnection::LEGACY_KEYS as $field => $column) {
            $config_values[$field] = $connection === null
                ? self::CONNECTION_FORM_DEFAULTS[$field]
                : $connection->get($column);
        }

        // The rule tabs show what applies to the connection being edited: its
        // own values, else the ones it inherits. The defaults, for the defaults
        // form and for a connection that does not exist yet.
        $config_values = array_merge(
            $config_values,
            ($defaults_mode || $connection === null)
                ? PluginIntegrasipamanRuleSettings::defaults()
                : PluginIntegrasipamanRuleSettings::forConnection($connection)
        );

        // One tab per section, in page order (v3.8.2, matching opmanager's
        // Setup). Each entry is [full title, Tabler icon, description, short
        // tab label]; the tab bar is built from the same list, so a tab
        // cannot point at a pane that does not exist.
        $sections = [
            'connection' => [
                __('JumpServer Connection', 'integrasipaman'),
                'ti ti-plug-connected',
                __('Outbound REST API access, signed with the Access Key (HMAC-SHA256).', 'integrasipaman'),
                __('Connection', 'integrasipaman'),
            ],
            'pbi' => [
                __('PBI Reporting Database (read-only)', 'integrasipaman'),
                'ti ti-database',
                __('Separate MySQL server hosting the jumpserver_pbi_views reporting schema, used by the JumpServer PBI Insights page under Tools.', 'integrasipaman'),
                __('PBI DB', 'integrasipaman'),
            ],
            'terminal' => [
                __('Terminal Command Audit', 'integrasipaman'),
                'ti ti-terminal-2',
                __('Pull of JumpServer terminal command & session records into GLPI (daily cron "Pull JumpServer terminal command & session records"). Feeds the command-audit reports.', 'integrasipaman'),
                __('Terminal Audit', 'integrasipaman'),
            ],
            'risk' => [
                __('Command Risk Evaluation', 'integrasipaman'),
                'ti ti-alert-triangle',
                __('Rules for classifying terminal commands (cron "Classify JumpServer command records", every 15 min). One entry per line; blank lines and lines starting with # are ignored. Editing any list re-evaluates all history automatically.', 'integrasipaman'),
                __('Risk Rules', 'integrasipaman'),
            ],
            'correlation' => [
                __('Asset & User Correlation', 'integrasipaman'),
                'ti ti-link',
                __('Resolve each command\'s asset and user against GLPI instead of the fallback lists on the Risk Rules tab. Runs at the start of the "Classify JumpServer command records" cron and whenever you save this page.', 'integrasipaman'),
                __('Correlation', 'integrasipaman'),
            ],
            'provisioning' => [
                __('Asset provisioning', 'integrasipaman'),
                'ti ti-server-bolt',
                PluginIntegrasipamanServerLabel::in(__('Which GLPI assets may be sent to {server}. A filter left empty does not filter: set at least the eligible types, or every Computer in GLPI turns up in the queue.', 'integrasipaman')),
                __('Provisioning', 'integrasipaman'),
            ],
        ];

        // The connection and PBI tabs describe a JumpServer; the defaults form
        // has none, so they are not there.
        if ($defaults_mode) {
            unset($sections['connection'], $sections['pbi']);
        }

        // Scoped to .integrasipaman-config so it cannot restyle a sibling
        // section in the shared PGN Suite tab. Tabler variables only, so the
        // page follows the GLPI palette and dark mode.
        echo '<style>'
            . '.integrasipaman-config .form-hint{display:block;margin-top:.25rem}'
            . '.integrasipaman-config textarea{font-family:var(--tblr-font-monospace);font-size:.85rem}'
            . '</style>';

        echo '<div class="integrasipaman-config">';

        echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">';
        echo '<h3 class="mb-0"><i class="ti ti-terminal-2 me-2"></i>' . htmlescape(__('JumpServer Integration Settings')) . '</h3>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="'
            . Plugin::getWebDir('integrasipaman') . '/front/export.php" target="_blank" rel="noopener">'
            . '<i class="ti ti-list-search me-1"></i>' . htmlescape(__('View Activity Log', 'integrasipaman'))
            . '</a>';
        echo '</div>';

        if (!$canedit) {
            echo '<div class="alert alert-info">' . htmlescape(__('You have read-only access to this configuration.', 'integrasipaman')) . '</div>';
        }

        // The opening tag is written directly (GLPI core has no dedicated
        // "open form" counterpart to Html::closeForm()).
        echo '<form name="jumpserver_config_form" id="jumpserver_config_form" method="post" action="'
            . Plugin::getWebDir('integrasipaman') . '/front/config.form.php">';

        // No manual CSRF token here: GLPI's Kernel-level CheckCsrfListener
        // already validates (and single-use-consumes) the token embedded by
        // Html::closeForm() below, before front/config.form.php even runs.
        // Embedding/checking a token a second time would consume it twice
        // and always fail the second check.
        echo Html::hidden('update', ['value' => '1']);

        if ($from_pgnsuite) {
            // Only the tab's copy carries this; the standalone page's form
            // does not, so a plugin-right-only admin is never redirected to
            // Setup > General, which they cannot open.
            echo Html::hidden('_from_pgnsuite', ['value' => '1']);
        }

        // Which connection the form is for, above the tabs: every tab but the
        // plain settings of the instance belongs to it.
        self::renderConnectionPicker($from_pgnsuite, $canedit, $connection_is_new, $defaults_mode, $connection);

        // Says the rule tabs carry their "keeps its own rules" switches, so a
        // POST that lacks them (a page from before 4.4.0) does not release
        // every connection's rules to inheritance.
        if (!$defaults_mode && $connection !== null) {
            echo Html::hidden('rule_own_shown', ['value' => '1']);
        }

        // Every pane stays in this one form whichever tab is showing, so a
        // hidden tab's fields are still posted - the absent-vs-empty guard
        // on the categories list in front/config.form.php depends on it.
        echo '<ul class="nav nav-tabs" role="tablist">';
        foreach ($sections as $key => [$title, $icon, , $tab]) {
            echo '<li class="nav-item" role="presentation">'
                . '<a class="nav-link' . ($key === array_key_first($sections) ? ' active' : '') . '" data-bs-toggle="tab" role="tab"'
                . ' href="#' . 'integrasipaman-sec-' . $key . '" title="' . htmlescape($title) . '">'
                . '<i class="' . htmlescape($icon) . ' me-1"></i>' . htmlescape($tab) . '</a></li>';
        }
        echo '</ul>';
        echo '<div class="tab-content border border-top-0 rounded-bottom p-3 mb-3">';

        // --- JumpServer connection -----------------------------------------
        if (!$defaults_mode) {
        // v4.5.0: the one-shot session-join back-fill lives here because the
        // audit-log pull it completes belongs to this connection. Without a
        // link it was reachable only by typing the URL, which is how a tool
        // stops existing. Not offered in defaults mode: there is no one
        // connection to back-fill there.
        $join_backfill = $defaults_mode ? '' :
            '<a class="btn btn-sm btn-outline-secondary text-nowrap" href="'
            . Plugin::getWebDir('integrasipaman') . '/front/backfilljoins.php">'
            . '<i class="ti ti-history me-1"></i>'
            . htmlescape(__('Back-fill session joins', 'integrasipaman')) . '</a>';

        self::openSection('connection', $sections['connection'], $join_backfill, true);

        // v3.11.0: the entity that owns this JumpServer, and whether its
        // child entities are covered. What is pulled through the connection
        // is stored in this entity, or in the entity of the Computer it is
        // matched to when that lies inside this scope. The standard GLPI
        // fields, never a hidden entities_id (suite entity rule, point 4).
        //
        // v4.0.0: several connections. The list on top chooses which one the
        // rest of this tab, the PBI tab and the Command Storage ID belong to.
        // Only connections owned by an entity the admin is working in are
        // listed; a new one starts in that entity, never in a guessed one.
        // (4.4.0: the list of connections is above the tabs - see
        // renderConnectionPicker() - because the rule tabs belong to the
        // highlighted connection too, and it has to stay visible on them.)
        echo '<div class="col-md-8">';
        echo '<label class="form-label" for="connection_name">' . htmlescape(__('Name')) . '</label>';
        echo Html::input('connection_name', [
            'id'          => 'connection_name',
            'class'       => 'form-control',
            'value'       => $connection === null ? '' : $connection->get('name'),
            'placeholder' => __('For example the subsidiary this JumpServer serves', 'integrasipaman'),
        ]);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . htmlescape(__('Active')) . '</label>';
        Dropdown::showYesNo('connection_is_active', ($connection === null || $connection->isActive()) ? 1 : 0);
        echo '<small class="form-hint">' . htmlescape(__('Switched off: nothing is pulled and no asset is matched through it. What it already stored is kept.', 'integrasipaman')) . '</small>';
        echo '</div>';

        $connection_entity = $connection === null
            ? (int) ($_SESSION['glpiactive_entity'] ?? 0)
            : $connection->entity();
        echo '<div class="col-md-8">';
        echo '<label class="form-label">' . htmlescape(__('Entity')) . '</label>';
        Entity::dropdown([
            'name'   => 'connection_entities_id',
            'value'  => $connection_entity,
            'width'  => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(__('The entity this JumpServer belongs to. Changing it moves every stored record of this connection to the new entity.', 'integrasipaman')) . '</small>';
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label">' . htmlescape(__('Child entities')) . '</label>';
        Dropdown::showYesNo('connection_is_recursive', ($connection === null || $connection->isRecursive()) ? 1 : 0);
        echo '</div>';

        // Entities that use this JumpServer without being under its owner -
        // a sibling that shares the bastion and so cannot have a connection
        // of its own. Offered: the entities the admin has access to.
        //
        // Never the root entity (4.4.2). Covering the root with "child entities"
        // on is covering everything, so it is not a choice this list can offer;
        // and its presence made it the entity a multi-select with nothing
        // selected picked by itself, which saved it without anyone choosing it.
        $coverable = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'ORDER' => 'completename ASC']) as $e) {
            if ((int) $e['id'] > 0 && Session::haveAccessToEntity((int) $e['id'])) {
                $coverable[(int) $e['id']] = (string) $e['completename'];
            }
        }
        echo '<div class="col-12">';
        echo '<label class="form-label">' . htmlescape(__('Additional entities', 'integrasipaman')) . '</label>';
        Dropdown::showFromArray('connection_extra_entities', $coverable, [
            'values'   => $connection === null ? [] : $connection->extraEntities(),
            'multiple' => true,
            'width'    => '100%',
        ]);
        // An emptied multi-select posts nothing; this says the field was on the form.
        echo Html::hidden('connection_extra_entities_shown', ['value' => '1']);
        // Covered entities this admin cannot see are not in the list above, so
        // they are not posted by it. Kept here so that Save does not remove them
        // (4.0.2): only the entities the admin can see can be added or removed.
        if ($connection !== null) {
            foreach ($connection->extraEntities() as $covered) {
                if (!isset($coverable[$covered])) {
                    echo Html::hidden('connection_extra_entities_kept[]', ['value' => $covered]);
                }
            }
        }
        echo '<small class="form-hint">' . htmlescape(__('Other entities whose Computers are reached through this JumpServer. A record matched to one of their Computers is stored in that entity; an unmatched record stays in the owning entity. "Child entities" applies to these too. An entity with a connection of its own is served by that one.', 'integrasipaman')) . '</small>';
        echo '</div>';

        echo '<div class="col-12">';
        echo '<label class="form-label" for="jumpserver_url">' . __('JumpServer URL / IP') . '</label>';
        echo Html::input('jumpserver_url', [
            'id'          => 'jumpserver_url',
            'class'       => 'form-control',
            'value'       => $config_values['jumpserver_url'],
            'placeholder' => 'https://jumpserver.example.com',
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label required" for="jumpserver_access_key_id">' . __('Access Key ID') . '</label>';
        echo Html::input('jumpserver_access_key_id', [
            'id'       => 'jumpserver_access_key_id',
            'class'    => 'form-control',
            'value'    => $config_values['jumpserver_access_key_id'],
            'required' => 'required',
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        // v3.10.1: the stored secret is never sent to the page. The field is
        // rendered empty; a blank submit keeps what is stored, so it is only
        // `required` while nothing is.
        $secret_stored = $connection !== null && $connection->hasSecret('access_key_secret');
        echo '<label class="form-label required" for="jumpserver_access_key_secret">' . __('Access Key Secret') . '</label>';
        echo Html::input('jumpserver_access_key_secret', [
            'id'           => 'jumpserver_access_key_secret',
            'type'         => 'password',
            'class'        => 'form-control',
            'placeholder'  => $secret_stored
                ? __('Stored - leave blank to keep it', 'integrasipaman')
                : __('Not set', 'integrasipaman'),
            // Without this, Chromium-based browsers may silently offer to
            // autofill this field with an unrelated saved password on page
            // load - invisible since the field masks its contents, and only
            // noticed later when JumpServer starts rejecting the signature.
            'autocomplete' => 'new-password',
            // Not `'required' => false`: Html::input() prints every key, and
            // required="" is still required.
            ...($secret_stored ? [] : ['required' => 'required']),
        ]);
        echo '</div>';

        echo '<div class="col-12">';
        echo '<label class="form-label required" for="jumpserver_org_id">' . __('Organization ID') . '</label>';
        echo Html::input('jumpserver_org_id', [
            'id'       => 'jumpserver_org_id',
            'class'    => 'form-control font-monospace',
            'value'    => $config_values['jumpserver_org_id'],
            'required' => 'required',
        ]);
        echo '</div>';

        self::closeSection();
        } // !$defaults_mode

        // --- PBI reporting database ----------------------------------------
        if (!$defaults_mode) {
        self::openSection('pbi', $sections['pbi']);

        echo '<div class="col-md-6">';
        echo '<label class="form-label" for="jumpserver_pbi_db_host">' . __('PBI DB Host', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_host', [
            'id'    => 'jumpserver_pbi_db_host',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_host'],
        ]);
        echo '</div>';

        echo '<div class="col-md-2">';
        echo '<label class="form-label" for="jumpserver_pbi_db_port">' . __('PBI DB Port', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_port', [
            'id'    => 'jumpserver_pbi_db_port',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_port'],
        ]);
        echo '</div>';

        echo '<div class="col-md-4">';
        echo '<label class="form-label" for="jumpserver_pbi_db_name">' . __('PBI Database Name', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_name', [
            'id'    => 'jumpserver_pbi_db_name',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_name'],
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label" for="jumpserver_pbi_db_user">' . __('PBI DB Username', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_user', [
            'id'    => 'jumpserver_pbi_db_user',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_user'],
        ]);
        echo '</div>';

        echo '<div class="col-md-6">';
        $pbi_password_stored = $connection !== null && $connection->hasSecret('pbi_db_password');
        echo '<label class="form-label" for="jumpserver_pbi_db_password">' . __('PBI DB Password', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_password', [
            'id'           => 'jumpserver_pbi_db_password',
            'type'         => 'password',
            'class'        => 'form-control',
            'placeholder'  => $pbi_password_stored
                ? __('Stored - leave blank to keep it', 'integrasipaman')
                : __('Not set', 'integrasipaman'),
            'autocomplete' => 'new-password',
        ]);
        // Blank means "keep", so clearing an optional password needs its own control.
        if ($pbi_password_stored && $canedit) {
            echo '<label class="form-check mt-2">';
            echo '<input type="checkbox" class="form-check-input" name="_clear_jumpserver_pbi_db_password" value="1">';
            echo '<span class="form-check-label">' . __('Remove the stored password', 'integrasipaman') . '</span>';
            echo '</label>';
        }
        echo '</div>';

        self::closeSection();
        } // !$defaults_mode

        // --- Terminal command audit ----------------------------------------
        // The first pane is the one shown on load: the connection's, or here the
        // first the form has.
        // v4.5.1: the terminal back-fill gets a way in too. It had been
        // reachable only by typing its URL since it was written, which is why
        // the session-join one was built the same way without anyone noticing.
        // v4.8.0: the session-metadata one-shot sits beside it, for the same
        // reason - a page nobody can reach is a page nobody runs, and this
        // one has to be run once or every session older than 4.8.0 keeps an
        // empty Type for ever.
        $terminal_backfill = $defaults_mode ? '' :
            '<a class="btn btn-sm btn-outline-secondary text-nowrap" href="'
            . Plugin::getWebDir('integrasipaman') . '/front/backfill.php">'
            . '<i class="ti ti-calendar-down me-1"></i>'
            . htmlescape(__('Backfill a month', 'integrasipaman')) . '</a>'
            . ' <a class="btn btn-sm btn-outline-secondary text-nowrap" href="'
            . Plugin::getWebDir('integrasipaman') . '/front/backfillsessionmeta.php">'
            . '<i class="ti ti-tag me-1"></i>'
            . htmlescape(__('Backfill session type', 'integrasipaman')) . '</a>';

        self::openSection('terminal', $sections['terminal'], $terminal_backfill, $defaults_mode);

        if (!$defaults_mode) {
        echo '<div class="col-12">';
        echo '<label class="form-label" for="jumpserver_command_storage_id">' . __('Command Storage ID', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_command_storage_id', [
            'id'    => 'jumpserver_command_storage_id',
            'class' => 'form-control font-monospace',
            'value' => $config_values['jumpserver_command_storage_id'],
        ]);
        echo '<small class="form-hint">' . __('Required. The command_storage_id from JumpServer (Console > Command Storage).', 'integrasipaman') . '</small>';
        echo '</div>';
        }

        echo '<div class="col-md-6">';
        echo '<label class="form-label" for="jumpserver_terminal_retention_months">' . __('Retention (months)', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_terminal_retention_months', [
            'id'    => 'jumpserver_terminal_retention_months',
            'type'  => 'number',
            'min'   => '1',
            'max'   => '60',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_terminal_retention_months'],
        ]);
        echo '<small class="form-hint">' . __('Command & session rows older than this are deleted on each cron run. Default 13.', 'integrasipaman') . '</small>';
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label" for="jumpserver_terminal_backfill_months">' . __('First-run backfill (months)', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_terminal_backfill_months', [
            'id'    => 'jumpserver_terminal_backfill_months',
            'type'  => 'number',
            'min'   => '1',
            'max'   => '60',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_terminal_backfill_months'],
        ]);
        echo '<small class="form-hint">' . __('How many months of history the first cron run pulls. Default 13.', 'integrasipaman') . '</small>';
        echo '</div>';

        self::closeSection();

        // --- Command risk evaluation ---------------------------------------
        self::openSection('risk', $sections['risk']);
        self::renderRuleToggle(PluginIntegrasipamanRuleSettings::SECTION_RISK, $connection, $defaults_mode, $canedit);

        foreach ([
            'jumpserver_risk_keywords'          => [__('High-risk keywords', 'integrasipaman'), __('A command containing any of these (or JumpServer risk level >= 4) is flagged high-risk.', 'integrasipaman'), 8],
            'jumpserver_admin_users'            => [__('Fallback: admin users', 'integrasipaman'), __('Used only when a command\'s user has no GLPI match. Format: Full Name(login). High-risk actions by these users on production assets are MEDIUM, not HIGH.', 'integrasipaman'), 8],
            'jumpserver_production_ip_prefixes' => [__('Fallback: production IP prefixes', 'integrasipaman'), __('Used only when a command\'s asset resolves to no GLPI IPNetwork or Computer. An asset whose IP starts with one of these is "production". e.g. 10.0.0.', 'integrasipaman'), 3],
            'jumpserver_development_ip_prefixes' => [__('Fallback: development IP prefixes', 'integrasipaman'), __('An asset whose IP starts with one of these is "development". An asset matching neither list is left unclassified and scores MEDIUM until someone classifies it on the correlation form.', 'integrasipaman'), 3],
            'jumpserver_command_categories'     => [__('Command categories', 'integrasipaman'), __('One per line, "Name: word1, word2". Line order decides which category wins when two match. A name over 32 characters is stored shortened to 32 characters. Keep the trailing space in "su " - without it the keyword also matches sudo and sudoers. A command matching nothing reads as Belum Dikategorikan. Categories group commands only; they never change a risk level.', 'integrasipaman'), 6],
            'jumpserver_shared_session_note'    => [__('Catatan for a shared session', 'integrasipaman'), __('A HIGH row (non-admin, production, high-risk) typed by someone joined to another user\'s shared session. At most 48 characters; empty means "Shared session".', 'integrasipaman'), 1],
            'jumpserver_direct_access_note'     => [__('Catatan for direct non-admin access', 'integrasipaman'), __('Every other HIGH row: a non-admin on production outside any share - a wrong grant, a user not known as admin, or a join not pulled yet. At most 48 characters; empty means "Akses Langsung Non-Admin".', 'integrasipaman'), 1],
        ] as $key => [$label, $hint, $rows]) {
            // The category list is one wide line per category; the four
            // short lists pair up two per row.
            echo '<div class="' . ($key === 'jumpserver_command_categories' ? 'col-12' : 'col-md-6') . '">';
            echo '<label class="form-label" for="' . $key . '">' . htmlescape($label) . '</label>';
            echo '<textarea class="form-control" id="' . $key . '" name="' . $key . '" rows="' . (int) $rows . '" spellcheck="false">'
                . htmlescape($config_values[$key]) . '</textarea>';
            echo '<small class="form-hint">' . htmlescape($hint) . '</small>';
            echo '</div>';
        }

        self::closeSection();

        // --- Asset & user correlation --------------------------------------
        self::openSection(
            'correlation',
            $sections['correlation'],
            '<a class="btn btn-sm btn-outline-secondary text-nowrap" href="' . Plugin::getWebDir('integrasipaman') . '/front/correlation.php">'
                . '<i class="ti ti-arrows-exchange me-1"></i>' . htmlescape(__('Manage asset & user mappings', 'integrasipaman')) . '</a>'
        );
        self::renderRuleToggle(PluginIntegrasipamanRuleSettings::SECTION_CORRELATION, $connection, $defaults_mode, $canedit);


        echo '<div class="col-12">';
        echo '<label class="form-label" for="correlation_match_mode">' . __('Asset match mode', 'integrasipaman') . '</label>';
        Dropdown::showFromArray('correlation_match_mode', [
            'ip_only'   => __('IP only - test the IP parsed from the asset string', 'integrasipaman'),
            'name_only' => __('Computer name only - test the GLPI Computer\'s IP address(es)', 'integrasipaman'),
            'hybrid'    => __('Hybrid - parsed IP first, then the matched Computer\'s IPs', 'integrasipaman'),
            'hybrid_name' => __('Hybrid - Computer name first, then the parsed IP', 'integrasipaman'),
        ], ['value' => $config_values['correlation_match_mode'], 'width' => '100%']);
        echo '<div class="form-hint">' . __('Pick the last mode when several Computers share an IP address in GLPI: it matches the asset by name first and only falls back to the IP when no Computer carries that name. The other three always match by IP first, which on a shared IP silently takes the lowest Computer id.', 'integrasipaman') . '</div>';
        echo '</div>';

        if (Plugin::isPluginActive('addressing')) {
            echo '<div class="col-12">';
            echo '<label class="form-label">' . __('Production IP ranges (Tools > IP Addressing)', 'integrasipaman') . '</label>';
            $addr = [];
            foreach ($DB->request([
                'FROM'  => 'glpi_plugin_addressing_addressings',
                'WHERE' => ['is_deleted' => 0],
                'ORDER' => 'name ASC',
            ]) as $a) {
                $label = trim((string) ($a['name'] ?? '')) ?: ('#' . $a['id']);
                if ((string) ($a['begin_ip'] ?? '') !== '') {
                    $label .= ' (' . $a['begin_ip'] . ' - ' . $a['end_ip'] . ')';
                }
                $addr[(int) $a['id']] = $label;
            }
            Dropdown::showFromArray('correlation_production_addressings', $addr, [
                'values'   => array_values(array_filter(array_map('intval', explode(',', (string) $config_values['correlation_production_addressings'])))),
                'multiple' => true,
                'width'    => '100%',
            ]);
            echo '<small class="form-hint">' . htmlescape(__('An asset whose tested IP falls inside any selected range is "production". This is the recommended source.', 'integrasipaman')) . '</small>';
            echo '</div>';
        }

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Production networks (core IPNetwork)', 'integrasipaman') . '</label>';
        $networks = [];
        // glpi_ipnetworks is a tree dropdown - no is_deleted column.
        foreach ($DB->request(['FROM' => 'glpi_ipnetworks', 'ORDER' => 'name ASC']) as $n) {
            $label = trim((string) ($n['completename'] ?: $n['name']));
            if ((string) $n['address'] !== '' && (string) $n['netmask'] !== '') {
                $label .= ' (' . $n['address'] . ' / ' . $n['netmask'] . ')';
            }
            $networks[(int) $n['id']] = $label !== '' ? $label : ('#' . $n['id']);
        }
        Dropdown::showFromArray('correlation_production_ipnetworks', $networks, [
            'values'   => array_values(array_filter(array_map('intval', explode(',', (string) $config_values['correlation_production_ipnetworks'])))),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(__('An asset whose tested IP falls inside any selected network is "production". Leave empty if you use the IP Addressing ranges above (core IPNetwork is often auto-populated).', 'integrasipaman')) . '</small>';
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Admin groups', 'integrasipaman') . '</label>';
        $groups = [];
        foreach ($DB->request(['FROM' => 'glpi_groups', 'WHERE' => ['is_usergroup' => 1], 'ORDER' => 'name ASC']) as $g) {
            $groups[(int) $g['id']] = trim((string) ($g['completename'] ?: $g['name'])) ?: ('#' . $g['id']);
        }
        Dropdown::showFromArray('correlation_admin_groups', $groups, [
            'values'   => array_values(array_filter(array_map('intval', explode(',', (string) $config_values['correlation_admin_groups'])))),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(__('A command\'s user who is a direct member of any selected group is a sanctioned admin (their high-risk production actions are MEDIUM, not HIGH). Child groups are not included - select each group explicitly.', 'integrasipaman')) . '</small>';
        echo '</div>';

        self::closeSection();

        // --- Asset provisioning --------------------------------------------
        self::openSection('provisioning', $sections['provisioning']);
        self::renderRuleToggle(PluginIntegrasipamanRuleSettings::SECTION_PROVISIONING, $connection, $defaults_mode, $canedit);

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Eligible computer types', 'integrasipaman') . '</label>';
        $prov_types = [];
        foreach ($DB->request(['FROM' => 'glpi_computertypes', 'ORDER' => 'name ASC']) as $t) {
            $prov_types[(int) $t['id']] = (string) $t['name'];
        }
        Dropdown::showFromArray('provisioning_eligible_types', $prov_types, [
            'values'   => array_values(array_filter(array_map('intval', explode(',', (string) $config_values['provisioning_eligible_types'])))),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(PluginIntegrasipamanServerLabel::in(__('Also the source of the {server} node: each eligible type is mapped to a node on the Node mapping list.', 'integrasipaman'))) . '</small>';
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Eligible entities', 'integrasipaman') . '</label>';
        $prov_entities = [];
        foreach ($DB->request(['FROM' => 'glpi_entities', 'ORDER' => 'completename ASC']) as $e) {
            $prov_entities[(int) $e['id']] = trim((string) ($e['completename'] ?: $e['name'])) ?: ('#' . $e['id']);
        }
        Dropdown::showFromArray('provisioning_eligible_entities', $prov_entities, [
            // Entity 0 is the Root Entity and a legitimate selection. A bare
            // array_filter() drops it as falsy, so a saved Root Entity would
            // render as unselected and be lost on the next save - which is
            // why the save path in front/config.form.php uses >= 0 too.
            'values'   => array_values(array_filter(
                array_map('intval', explode(',', (string) $config_values['provisioning_eligible_entities'])),
                static fn ($n) => $n >= 0
            )),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(__('Leave empty to accept every entity.', 'integrasipaman')) . '</small>';
        echo '</div>';

        $prov_states = [];
        foreach ($DB->request(['FROM' => 'glpi_states', 'ORDER' => 'name ASC']) as $s) {
            $prov_states[(int) $s['id']] = (string) $s['name'];
        }

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Eligible statuses', 'integrasipaman') . '</label>';
        Dropdown::showFromArray('provisioning_eligible_states', $prov_states, [
            'values'   => array_values(array_filter(array_map('intval', explode(',', (string) $config_values['provisioning_eligible_states'])))),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(__('Only Computers in one of these statuses are offered for sending.', 'integrasipaman')) . '</small>';
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Dispose statuses', 'integrasipaman') . '</label>';
        Dropdown::showFromArray('provisioning_dispose_states', $prov_states, [
            'values'   => array_values(array_filter(array_map('intval', explode(',', (string) $config_values['provisioning_dispose_states'])))),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(PluginIntegrasipamanServerLabel::in(__('A Computer that reaches one of these statuses is marked for removal from {server}. Marking is automatic; the removal itself still needs a button and a ticket.', 'integrasipaman'))) . '</small>';
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label">' . __('Approval ticket statuses', 'integrasipaman') . '</label>';
        Dropdown::showFromArray('provisioning_ticket_statuses', [
            1 => __('New'),
            2 => __('Processing (assigned)'),
            3 => __('Processing (planned)'),
            4 => __('Pending'),
            5 => __('Solved'),
            6 => __('Closed'),
        ], [
            'values'   => array_values(array_filter(array_map('intval', explode(',', (string) $config_values['provisioning_ticket_statuses'])))),
            'multiple' => true,
            'width'    => '100%',
        ]);
        echo '<small class="form-hint">' . htmlescape(__('A removal may only cite a ticket in one of these statuses. A ticket still being worked on is not an approval.', 'integrasipaman')) . '</small>';
        echo '</div>';

        echo '<div class="col-md-6">';
        echo '<label class="form-label" for="provisioning_ip_prefixes">' . __('Eligible IP prefixes', 'integrasipaman') . '</label>';
        echo '<textarea class="form-control" id="provisioning_ip_prefixes" name="provisioning_ip_prefixes" rows="3" spellcheck="false">'
            . htmlescape($config_values['provisioning_ip_prefixes']) . '</textarea>';
        echo '<small class="form-hint">' . htmlescape(__('One prefix per line, e.g. 10.0.0. - a Computer whose address starts with any of them passes. Leave empty to accept any address.', 'integrasipaman')) . '</small>';
        echo '</div>';

        echo '<div class="col-12">';
        echo '<label class="form-label" for="provisioning_platform_families">' . __('Platform by OS family', 'integrasipaman') . '</label>';
        echo '<textarea class="form-control" id="provisioning_platform_families" name="provisioning_platform_families" rows="4" spellcheck="false">'
            . htmlescape($config_values['provisioning_platform_families']) . '</textarea>';
        echo '<small class="form-hint">' . htmlescape(PluginIntegrasipamanServerLabel::in(__('One per line, "Platform: keyword, keyword". Used when an OS has no exact platform mapping: the first line with a keyword the OS name contains wins (case-insensitive), and the platform is looked up by name in {server}. An exact mapping always wins. Kubernetes clusters are Cloud assets in {server}, not Hosts, so leave them out.', 'integrasipaman'))) . '</small>';
        echo '</div>';

        self::closeSection();

        echo '</div>'; // .tab-content

        // One Save for every tab, as on opmanager. Re-evaluate sits beside it
        // but submits its own form (below) through the HTML5 form= attribute -
        // forms cannot nest.
        if ($canedit) {
            echo '<div class="integrasipaman-actions d-flex flex-wrap align-items-center gap-2">';
            // Html::submit() escapes its caption; the icon goes through 'icon'.
            echo Html::submit(__('Save'), ['name' => 'update_btn', 'class' => 'btn btn-primary', 'icon' => 'ti ti-device-floppy']);
            echo '<button type="submit" name="reeval" value="1" form="integrasipaman-reeval-form" class="btn btn-outline-secondary">'
                . '<i class="ti ti-refresh me-1"></i>' . htmlescape(__('Re-evaluate now', 'integrasipaman')) . '</button>';
            echo '<small class="text-muted">' . htmlescape(__('Save first if you changed a rule list. Runs for up to ~20 seconds; the cron finishes anything left.', 'integrasipaman')) . '</small>';
            echo '</div>';
        }

        Html::closeForm();

        // The Re-evaluate trigger's own POST form, left empty: its button lives
        // next to Save above. Html::closeForm() emits the _glpi_csrf_token
        // hidden field that GLPI's Kernel-level CheckCsrfListener validates.
        if ($canedit) {
            echo '<form id="integrasipaman-reeval-form" method="post" action="' . Plugin::getWebDir('integrasipaman') . '/front/reeval.php">';
            Html::closeForm();
        }

        // 1. Reopen the last tab after Save / reload (per browser tab; harmless
        //    when storage is blocked) - the same behaviour as opmanager's Setup.
        // 2. Access Key ID / Secret / Org ID are `required`. When their tab is
        //    hidden the browser refuses the submit and cannot show its message
        //    on a hidden control, so Save looks dead. The capture-phase
        //    'invalid' listener opens the tab holding the first invalid field;
        //    the panes have no fade, so it is visible before the browser
        //    focuses it.
        echo Html::scriptBlock(<<<'JS'
(function () {
    var root = document.querySelector('.integrasipaman-config');
    var form = document.getElementById('jumpserver_config_form');
    if (!root || !form) { return; }
    var key = 'integrasipaman-setup-tab';
    function show(link) {
        if (link && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(link).show(); }
    }
    try {
        var saved = sessionStorage.getItem(key);
        if (saved) { show(root.querySelector('a[data-bs-toggle="tab"][href="' + saved + '"]')); }
    } catch (e) {}
    root.querySelectorAll('a[data-bs-toggle="tab"]').forEach(function (link) {
        link.addEventListener('shown.bs.tab', function () {
            try { sessionStorage.setItem(key, link.getAttribute('href')); } catch (e) {}
        });
    });
    form.addEventListener('invalid', function (ev) {
        var pane = ev.target.closest('.tab-pane');
        if (pane && !pane.classList.contains('active')) {
            show(root.querySelector('a[data-bs-toggle="tab"][href="#' + pane.id + '"]'));
        }
    }, true);

    // A rule tab whose connection follows the one above it shows the values it
    // follows, greyed out: they are not posted, and Save would ignore them.
    // Switching "keeps its own rules" on makes them editable.
    function lock(pane, locked, keep) {
        pane.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (el === keep || el.type === 'hidden') { return; }
            el.disabled = locked;
        });
    }
    root.querySelectorAll('[data-rule-toggle]').forEach(function (box) {
        var pane = box.closest('.tab-pane');
        if (!pane) { return; }
        box.addEventListener('change', function () { lock(pane, !box.checked, box); });
        lock(pane, !box.checked, box);
    });
    // A connection that does not exist yet has nothing of its own to keep.
    root.querySelectorAll('[data-rule-locked]').forEach(function (note) {
        var pane = note.closest('.tab-pane');
        if (pane) { lock(pane, true, null); }
    });
})();
JS);

        echo '</div>'; // .integrasipaman-config
    }

    /**
     * Open one section's tab pane and its field grid.
     *
     * @param array{0:string,1:string,2:string,3:string} $section [title, icon, description, tab label]
     * @param string $action Pre-escaped HTML shown beside the description.
     * @param bool   $active True for the pane shown on load (the first one).
     */
    private static function openSection(string $key, array $section, string $action = '', bool $active = false): void
    {
        [$title, , $description] = $section;

        echo '<div class="tab-pane' . ($active ? ' active show' : '') . '" role="tabpanel" id="' . 'integrasipaman-sec-' . $key . '">';
        echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">';
        echo '<div>';
        echo '<h4 class="mb-1">' . htmlescape($title) . '</h4>';
        echo '<div class="text-muted small">' . htmlescape($description) . '</div>';
        echo '</div>';
        echo $action;
        echo '</div>';
        echo '<div class="row g-3">';
    }

    /** Close the grid and pane opened by openSection(). */
    private static function closeSection(): void
    {
        echo '</div></div>';
    }

    /**
     * Whether the signed-in user may edit the defaults every connection
     * inherits (4.4.0). They are instance-wide: changing them changes every
     * subsidiary that has not kept its own, so it takes the plugin's UPDATE
     * right and access to the root entity.
     */
    public static function canEditDefaults(): bool
    {
        return Session::haveRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE)
            && Session::haveAccessToEntity(0);
    }

    /** True when the Setup form was switched to the defaults, and may be. */
    public static function editingDefaults(): bool
    {
        return ($_SESSION[self::SESSION_CONNECTION] ?? null) === 'defaults'
            && Session::haveAccessToEntity(0);
    }

    /**
     * The connections the form can be for, above the tabs: one chip per
     * connection, "Add a connection", and the defaults every connection
     * inherits. Also says which row the POST is for. Never inferred on save.
     */
    private static function renderConnectionPicker(
        bool $from_pgnsuite,
        bool $canedit,
        bool $connection_is_new,
        bool $defaults_mode,
        ?PluginIntegrasipamanConnection $connection
    ): void {
        $select_url = Plugin::getWebDir('integrasipaman') . '/front/config.form.php?'
            . ($from_pgnsuite ? '_from_pgnsuite=1&' : '') . 'select_connection=';

        echo '<div class="mb-3">';
        echo '<div class="d-flex flex-wrap align-items-center gap-2">';
        foreach (PluginIntegrasipamanConnection::visible(true) as $listed) {
            $is_edited = !$defaults_mode && !$connection_is_new && $connection !== null && $listed->id() === $connection->id();
            echo '<a class="btn btn-sm ' . ($is_edited ? 'btn-primary' : 'btn-outline-secondary') . '" href="'
                . htmlescape($select_url . $listed->id()) . '">'
                . '<i class="ti ti-plug-connected me-1"></i>'
                // The edited connection's label is updated as the form changes
                // (see the script below). It shows the typed URL's host, or the
                // typed Name; nothing is saved by it.
                . ($is_edited
                    ? '<span data-live-chip="1">' . htmlescape($listed->label()) . '</span>'
                    : htmlescape($listed->label()))
                . ' <span class="opacity-75">- ' . htmlescape((string) Dropdown::getDropdownName('glpi_entities', $listed->entity())) . '</span>'
                . ($listed->isActive() ? '' : ' <span class="badge bg-secondary ms-1">' . htmlescape(__('off', 'integrasipaman')) . '</span>')
                . '</a>';
        }
        if ($canedit) {
            echo '<a class="btn btn-sm ' . ($connection_is_new ? 'btn-primary' : 'btn-outline-primary') . '" href="'
                . htmlescape($select_url . 'new') . '">'
                . '<i class="ti ti-plus me-1"></i>' . htmlescape(__('Add a connection', 'integrasipaman')) . '</a>';
        }
        // The defaults are a separate form: only for those who may change them
        // for everyone, but anyone who can see the root entity can read them.
        if (Session::haveAccessToEntity(0)) {
            echo '<a class="btn btn-sm ' . ($defaults_mode ? 'btn-primary' : 'btn-outline-secondary') . '" href="'
                . htmlescape($select_url . 'defaults') . '">'
                . '<i class="ti ti-adjustments me-1"></i>' . htmlescape(__('Defaults for all connections', 'integrasipaman')) . '</a>';
        }
        echo '</div>';
        // The chip is a preview of what is in the form, not of what is saved:
        // the host of the URL typed in the form, or the Name if one is typed.
        // The chip shows the saved value until the form changes, and Save is
        // what stores it. Only the displayed text moves.
        echo '<script>document.addEventListener("DOMContentLoaded", function () {'
            . 'var chip = document.querySelector("[data-live-chip]");'
            . 'if (!chip) { return; }'
            . 'var original = chip.textContent;'
            . 'var url = document.getElementById("jumpserver_url");'
            . 'var name = document.getElementById("connection_name");'
            . 'function host(v) {'
            . '  v = (v || "").trim();'
            . '  if (v === "") { return ""; }'
            . '  try { return new URL(v.indexOf("://") === -1 ? "https://" + v : v).hostname.replace(/\.$/, ""); }'
            . '  catch (e) { return v; }'
            . '}'
            . 'function refresh() {'
            . '  var typedName = name ? name.value.trim() : "";'
            . '  var typedHost = url ? host(url.value) : "";'
            . '  chip.textContent = typedName !== "" ? typedName : (typedHost !== "" ? typedHost : original);'
            . '}'
            . 'if (url) { url.addEventListener("input", refresh); }'
            . 'if (name) { name.addEventListener("input", refresh); }'
            . '});</script>';
        if ($defaults_mode) {
            $hint = __('The defaults every connection inherits. A connection keeps its own rules by switching that on its Risk Rules, Correlation or Provisioning tab; until then it follows these.', 'integrasipaman');
        } elseif ($connection_is_new) {
            $hint = __('New connection. Each entity can have one JumpServer of its own; fill in the Connection tab and Save. It follows the defaults until you give it rules of its own.', 'integrasipaman');
        } else {
            $hint = __('One JumpServer per entity. The Connection, PBI DB and Terminal Audit tabs belong to the highlighted connection, and so do the three rule tabs: each follows the connection above it, or the defaults, until this connection keeps its own.', 'integrasipaman');
        }
        echo '<small class="form-hint">' . htmlescape($hint) . '</small>';
        echo '</div>';

        // Which row the POST is for: an id, 'new' or 'defaults'.
        echo Html::hidden('connection_id', [
            'value' => $defaults_mode ? 'defaults' : ($connection_is_new || $connection === null ? 'new' : (string) $connection->id()),
        ]);
    }

    /**
     * The switch at the top of a rule tab: this connection keeps its own
     * rules, or follows the connection above it (4.4.0). On the defaults form
     * there is no switch, and for a connection that does not exist yet the
     * fields only show what it will inherit.
     */
    private static function renderRuleToggle(string $section, ?PluginIntegrasipamanConnection $connection, bool $defaults_mode, bool $canedit): void
    {
        echo '<div class="col-12">';

        if ($defaults_mode) {
            echo '<div class="alert alert-info mb-0 py-2">'
                . htmlescape(__('These are the defaults. Every connection follows them unless it keeps its own rules on this tab.', 'integrasipaman')) . '</div>';
        } elseif ($connection === null) {
            echo '<div class="alert alert-info mb-0 py-2" data-rule-locked="1">'
                . htmlescape(__('A new connection follows the defaults shown here. Save it first; then it can keep its own rules on this tab.', 'integrasipaman')) . '</div>';
        } else {
            $source = PluginIntegrasipamanRuleSettings::sourceOf($connection, $section);
            echo '<label class="form-check form-switch mb-1">'
                . '<input type="checkbox" class="form-check-input" name="rule_own[' . htmlescape($section) . ']" value="1" data-rule-toggle="1"'
                . ($source['own'] ? ' checked' : '') . ($canedit ? '' : ' disabled') . '>'
                . '<span class="form-check-label fw-bold">'
                . htmlescape(sprintf(__('%s keeps its own rules on this tab', 'integrasipaman'), $connection->label())) . '</span></label>';
            if ($source['own']) {
                $note = __('The values below are this connection\'s own. Connections under it follow them.', 'integrasipaman');
            } elseif ($source['from'] !== null) {
                $note = sprintf(__('Following %s. Switch this on to give the connection rules of its own, starting from these values.', 'integrasipaman'), $source['from']->label());
            } else {
                $note = __('Following the defaults for all connections. Switch this on to give the connection rules of its own, starting from the defaults.', 'integrasipaman');
            }
            echo '<div class="form-hint">' . htmlescape($note) . '</div>';
        }

        echo '</div>';
    }
}
