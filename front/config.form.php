<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Configuration form controller. Reachable from Setup > Plugins for
 * administrators, and handles saving the JumpServer URL/IP, Access Key
 * credentials, organization ID, and PBI reporting DB credentials into
 * GLPI's core config store.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');

// Explicit, strict access control: a real authenticated session first,
// then this plugin's own right. v2.0.0 moved off core `config` UPDATE so a
// PAM administrator no longer needs access to all of GLPI's Setup.
// READ opens the page; saving needs UPDATE (checked on the POST branch).
Session::checkLoginUser();
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, READ);

include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/correlation.class.php');

// v4.0.0: choose which connection the Setup form edits. Only a choice is
// stored; editedConnection() ignores an id that is not the viewer's.
if (isset($_GET['select_connection'])) {
    // 'defaults' is the form for what every connection inherits (4.4.0);
    // editingDefaults() checks the viewer's access when it is used.
    $_SESSION[PluginIntegrasipamanConfig::SESSION_CONNECTION] = in_array($_GET['select_connection'], ['new', 'defaults'], true)
        ? $_GET['select_connection']
        : (int) $_GET['select_connection'];

    if (!empty($_GET['_from_pgnsuite'])) {
        Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('integrasipaman'));
    }
    Html::redirect(Plugin::getWebDir('integrasipaman') . '/front/config.form.php');
}

if (isset($_POST['update'])) {
    Session::checkRight(PluginIntegrasipamanProfile::RIGHT_CONFIG, UPDATE);

    // No manual Session::checkCSRF() here: GLPI's Kernel-level
    // CheckCsrfListener already validates the token for every non-GET
    // request before this legacy file is loaded. Since CSRF tokens are
    // single-use, checking again here would always fail (the token was
    // already consumed by that earlier, automatic check).

    // Stored as-is (trimmed) rather than HTML-stripped: GLPI 11 sanitizes
    // on output, not on input (Html::clean()/cleanInputText() are gone/
    // deprecated). Html::input()'s 'value' attribute is already
    // htmlescape()'d wherever these fields are redisplayed in the config
    // form, so no separate stripping step is needed here.
    $jumpserver_url               = trim($_POST['jumpserver_url'] ?? '');
    $jumpserver_access_key_id     = trim($_POST['jumpserver_access_key_id'] ?? '');
    $jumpserver_access_key_secret = trim($_POST['jumpserver_access_key_secret'] ?? '');
    $jumpserver_org_id            = trim($_POST['jumpserver_org_id'] ?? '');
    $jumpserver_pbi_db_host       = trim($_POST['jumpserver_pbi_db_host'] ?? '');
    $jumpserver_pbi_db_port       = trim($_POST['jumpserver_pbi_db_port'] ?? '');
    $jumpserver_pbi_db_name       = trim($_POST['jumpserver_pbi_db_name'] ?? '');
    $jumpserver_pbi_db_user       = trim($_POST['jumpserver_pbi_db_user'] ?? '');
    $jumpserver_pbi_db_password   = trim($_POST['jumpserver_pbi_db_password'] ?? '');
    $jumpserver_command_storage_id        = trim($_POST['jumpserver_command_storage_id'] ?? '');
    $jumpserver_terminal_retention_months = (string) PluginIntegrasipamanConfig::clampMonths($_POST['jumpserver_terminal_retention_months'] ?? '13');
    $jumpserver_terminal_backfill_months  = (string) PluginIntegrasipamanConfig::clampMonths($_POST['jumpserver_terminal_backfill_months'] ?? '13');
    // NOT a bare trim(): this list's seed ships `su ` WITH its trailing
    // space, and loadRules() reads it with parseList(..., true) precisely to
    // keep it. A bare trim eats that space whenever `su ` happens to be the
    // last line - it is only the 9th of ~40 today - and `su` then matches
    // sudo, sudoers, issue and resume, escalating all four to high risk.
    // Same trap, same fix, as jumpserver_command_categories below.
    $jumpserver_risk_keywords          = trim($_POST['jumpserver_risk_keywords'] ?? '', "\r\n");
    $jumpserver_admin_users            = trim($_POST['jumpserver_admin_users'] ?? '');
    $jumpserver_production_ip_prefixes = trim($_POST['jumpserver_production_ip_prefixes'] ?? '');
    $jumpserver_development_ip_prefixes = trim($_POST['jumpserver_development_ip_prefixes'] ?? '');
    // NOT trim(): a bare trim eats the significant trailing space on the
    // last line, so a category ending "..., su " silently becomes "su" and
    // matches sudo, sudoers, issue and resume.
    $jumpserver_command_categories = trim($_POST['jumpserver_command_categories'] ?? '', "\r\n");

    $correlation_match_mode = in_array(($_POST['correlation_match_mode'] ?? ''), PluginIntegrasipamanConfig::MATCH_MODES, true)
        ? $_POST['correlation_match_mode']
        : 'ip_only';
    $correlation_production_ipnetworks = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['correlation_production_ipnetworks'] ?? [])),
        static fn ($n) => $n > 0
    ))));
    $correlation_production_addressings = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['correlation_production_addressings'] ?? [])),
        static fn ($n) => $n > 0
    ))));
    $correlation_admin_groups = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['correlation_admin_groups'] ?? [])),
        static fn ($n) => $n > 0
    ))));

    $provisioning_eligible_types = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['provisioning_eligible_types'] ?? [])),
        static fn ($n) => $n > 0
    ))));
    $provisioning_eligible_states = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['provisioning_eligible_states'] ?? [])),
        static fn ($n) => $n > 0
    ))));
    $provisioning_dispose_states = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['provisioning_dispose_states'] ?? [])),
        static fn ($n) => $n > 0
    ))));
    $provisioning_eligible_entities = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['provisioning_eligible_entities'] ?? [])),
        // Entity 0 is the root entity and is a legitimate choice; keep >= 0.
        static fn ($n) => $n >= 0
    ))));
    $provisioning_ip_prefixes     = trim($_POST['provisioning_ip_prefixes'] ?? '');
    $provisioning_ticket_statuses = implode(',', array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['provisioning_ticket_statuses'] ?? [])),
        static fn ($n) => $n > 0
    ))));

    if ($jumpserver_url !== '' && filter_var($jumpserver_url, FILTER_VALIDATE_URL) === false) {
        Session::addMessageAfterRedirect(
            __('JumpServer URL / IP is not a valid URL.'),
            false,
            ERROR
        );
        Html::back();
    }

    // The settings the form holds that are not about a JumpServer. The rule
    // lists are per connection (4.4.0): the connection keeps its own, or
    // follows the one above it, or the defaults below.
    $config_values = [
        'jumpserver_terminal_retention_months' => $jumpserver_terminal_retention_months,
        'jumpserver_terminal_backfill_months'  => $jumpserver_terminal_backfill_months,
    ];
    $rule_values = [
        'jumpserver_risk_keywords'          => $jumpserver_risk_keywords,
        'jumpserver_admin_users'            => $jumpserver_admin_users,
        'jumpserver_production_ip_prefixes' => $jumpserver_production_ip_prefixes,
        'jumpserver_development_ip_prefixes' => $jumpserver_development_ip_prefixes,
        'jumpserver_command_categories'     => $jumpserver_command_categories,
        'correlation_match_mode'            => $correlation_match_mode,
        'correlation_production_ipnetworks' => $correlation_production_ipnetworks,
        'correlation_production_addressings' => $correlation_production_addressings,
        'correlation_admin_groups'          => $correlation_admin_groups,
        'provisioning_eligible_types'    => $provisioning_eligible_types,
        'provisioning_eligible_states'   => $provisioning_eligible_states,
        'provisioning_dispose_states'    => $provisioning_dispose_states,
        'provisioning_eligible_entities' => $provisioning_eligible_entities,
        'provisioning_ip_prefixes'       => $provisioning_ip_prefixes,
        'provisioning_ticket_statuses'   => $provisioning_ticket_statuses,
    ];

    // A Setup page rendered before this release has no categories field, so a
    // POST from such a tab would save '' over the seeded list and the next
    // cron would sweep the whole history into Belum Dikategorikan. Absent and
    // empty are different: absent means "this form does not know about the
    // key", empty means "the operator cleared it", and only the second is an
    // instruction.
    //
    // Deliberately NOT a fallback to CONFIG_DEFAULTS when the value is empty:
    // an operator who clears every category must get everything in Belum
    // Dikategorikan, and a fallback on empty makes zero categories
    // unreachable.
    if (!array_key_exists('jumpserver_command_categories', $_POST)) {
        unset($rule_values['jumpserver_command_categories']);
    }

    // v4.4.0: the form for the defaults every connection inherits. There is no
    // connection in it: only the instance-wide settings are written, and only
    // by someone who may change them for everyone.
    if (($_POST['connection_id'] ?? null) === 'defaults') {
        if (!PluginIntegrasipamanConfig::canEditDefaults()) {
            Session::addMessageAfterRedirect(
                __('Changing the defaults for all connections needs access to the root entity.', 'integrasipaman'),
                false,
                ERROR
            );
            Html::back();
        }

        Config::setConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT, $config_values + $rule_values);
        Session::addMessageAfterRedirect(__('Configuration successfully updated.'));

        // Every connection that follows the defaults has new rules.
        try {
            PluginIntegrasipamanCorrelation::resolveAll(10.0);
        } catch (\Throwable $e) {
            trigger_error('config: post-save correlation resolve failed: ' . $e->getMessage(), E_USER_WARNING);
        }
        if (!empty($_POST['_from_pgnsuite'])) {
            Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('integrasipaman'));
        }
        Html::back();
    }

    // v3.10.1: the secret fields are rendered empty, so blank means "keep the
    // stored value". The Access Key Secret is only missing when nothing is
    // stored either.
    //
    // v4.0.0: which connection this POST is for comes from the form, never
    // from "the" connection. An id must be one of the connections owned by
    // an entity the admin is working in; 'new' creates one. A form rendered
    // before this release carries no id and edits what it showed: the
    // connection editedConnection() resolves.
    $posted_connection = $_POST['connection_id'] ?? null;
    if ($posted_connection === null) {
        [, $connection] = PluginIntegrasipamanConfig::editedConnection();
    } elseif ($posted_connection === 'new') {
        $connection = null;
    } else {
        $connection = PluginIntegrasipamanConnection::visible(true)[(int) $posted_connection] ?? null;
        if ($connection === null) {
            Session::addMessageAfterRedirect(
                __('That connection does not exist, or belongs to an entity you are not working in.', 'integrasipaman'),
                false,
                ERROR
            );
            Html::back();
        }
    }
    $secret_missing = $jumpserver_access_key_secret === ''
        && ($connection === null || !$connection->hasSecret('access_key_secret'));

    // v3.11.0: the owning entity. Absent means the form was rendered before
    // this release, and absent is "keep", not "root". An entity id from the
    // request is never trusted (suite entity rule, point 6).
    $connection_entity    = null;
    $connection_recursive = null;
    if (array_key_exists('connection_entities_id', $_POST)) {
        $connection_entity = (int) $_POST['connection_entities_id'];
        if ($connection_entity < 0 || !Session::haveAccessToEntity($connection_entity)) {
            Session::addMessageAfterRedirect(
                __('You do not have access to the selected entity.', 'integrasipaman'),
                false,
                ERROR
            );
            Html::back();
        }
    }
    if (array_key_exists('connection_is_recursive', $_POST)) {
        $connection_recursive = !empty($_POST['connection_is_recursive']);
    }

    // Additional entities: only when the field was on the form, and every
    // one of them an entity the admin has access to.
    $connection_extra = null;
    if (!empty($_POST['connection_extra_entities_shown'])) {
        $posted = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['connection_extra_entities'] ?? [])),
            static fn ($n) => $n >= 0
        )));
        // Only the entities the admin can see are checked and can be changed.
        foreach ($posted as $extra_entity) {
            if (!Session::haveAccessToEntity($extra_entity)) {
                Session::addMessageAfterRedirect(
                    __('You do not have access to one of the additional entities.', 'integrasipaman'),
                    false,
                    ERROR
                );
                Html::back();
            }
        }
        // The covered entities this admin cannot see were kept in the form as
        // hidden fields: they stay covered, and Save does not remove them
        // (4.0.2). An admin without access to OT cannot drop OT by saving.
        $kept = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['connection_extra_entities_kept'] ?? [])),
            static fn ($n) => $n >= 0
        )));
        // Only what was already covered can be kept: a posted id must not be
        // able to add an entity the admin may not add.
        $already = $connection === null ? [] : $connection->extraEntities();
        $kept    = array_values(array_intersect($kept, $already));
        $connection_extra = array_values(array_unique(array_merge($posted, $kept)));
    }
    $connection_active = array_key_exists('connection_is_active', $_POST)
        ? !empty($_POST['connection_is_active'])
        : null;

    if ($jumpserver_access_key_id === '' || $secret_missing || $jumpserver_org_id === '') {
        Session::addMessageAfterRedirect(
            __('Access Key ID, Access Key Secret and Organization ID are all required.', 'integrasipaman'),
            false,
            ERROR
        );
        Html::back();
    }

    // v3.11.0: the connection is a row of its own, not config. The form keeps
    // its field names; LEGACY_KEYS maps them to the columns.
    $connection_values = [];
    foreach ([
        'jumpserver_url'                => $jumpserver_url,
        'jumpserver_access_key_id'      => $jumpserver_access_key_id,
        'jumpserver_org_id'             => $jumpserver_org_id,
        'jumpserver_command_storage_id' => $jumpserver_command_storage_id,
        'jumpserver_pbi_db_host'        => $jumpserver_pbi_db_host,
        'jumpserver_pbi_db_port'        => $jumpserver_pbi_db_port,
        'jumpserver_pbi_db_name'        => $jumpserver_pbi_db_name,
        'jumpserver_pbi_db_user'        => $jumpserver_pbi_db_user,
    ] as $field => $value) {
        $connection_values[PluginIntegrasipamanConnection::LEGACY_KEYS[$field]] = $value;
    }
    if (array_key_exists('connection_name', $_POST)) {
        $connection_values['name'] = mb_substr(trim((string) $_POST['connection_name']), 0, 191);
    }

    // Only the instance-wide settings go to glpi_configs here. The rule lists
    // are the connection's (4.4.0): written to it below, once it exists.
    Config::setConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT, $config_values);

    // The connection row, the two secrets with it. Only a secret that was
    // typed is written; the class encrypts it. Nothing here goes through
    // Config, so nothing reaches the history of Setup > General.
    $secrets = [];
    if ($jumpserver_access_key_secret !== '') {
        $secrets['access_key_secret'] = $jumpserver_access_key_secret;
    }
    if (!empty($_POST['_clear_jumpserver_pbi_db_password'])) {
        $secrets['pbi_db_password'] = '';
    } elseif ($jumpserver_pbi_db_password !== '') {
        $secrets['pbi_db_password'] = $jumpserver_pbi_db_password;
    }
    $connection_error = PluginIntegrasipamanConnection::save(
        $connection?->id(),
        $connection_values,
        $secrets,
        $connection_entity,
        $connection_recursive,
        $connection_extra,
        $connection_active
    );
    if ($connection_error !== '') {
        Session::addMessageAfterRedirect(
            sprintf(__('The connection could not be saved (%s). The other settings were saved.', 'integrasipaman'), $connection_error),
            false,
            ERROR
        );
        Html::back();
    }
    // The label on every screen is the first part of the URL just saved.
    PluginIntegrasipamanServerLabel::reset();

    // Stay on the connection just saved - for a new one, the row its entity
    // now owns.
    $saved_entity = $connection_entity ?? $connection?->entity();
    foreach (PluginIntegrasipamanConnection::every() as $saved) {
        if ($saved->entity() === $saved_entity) {
            $_SESSION[PluginIntegrasipamanConfig::SESSION_CONNECTION] = $saved->id();
        }
    }

    // v4.4.0: the rules this connection keeps for itself. A group whose switch
    // is on takes the values posted for it; a group whose switch is off is
    // released, so the connection follows the one above it again. Only when the
    // form carried the switches: a page rendered before this release did not,
    // and would otherwise release every group. A connection that has just been
    // created follows the defaults until it is edited.
    if ($connection !== null && !empty($_POST['rule_own_shown'])) {
        $own_sections = array_values(array_intersect(
            array_keys(array_filter((array) ($_POST['rule_own'] ?? []))),
            array_keys(PluginIntegrasipamanRuleSettings::SECTIONS)
        ));
        $rule_error = PluginIntegrasipamanConnection::saveRules(
            $connection->id(),
            PluginIntegrasipamanRuleSettings::mapToStore($rule_values, $own_sections)
        );
        if ($rule_error !== '') {
            Session::addMessageAfterRedirect(
                sprintf(__('The rules of the connection could not be saved (%s).', 'integrasipaman'), $rule_error),
                false,
                ERROR
            );
            Html::back();
        }
    }

    Session::addMessageAfterRedirect(__('Configuration successfully updated.'));

    // Piece 3: re-resolve the correlation maps with the new settings
    // (budgeted so a large asset set cannot 504 this request; the cron
    // finishes any leftover rows).
    try {
        PluginIntegrasipamanCorrelation::resolveAll(10.0);
    } catch (\Throwable $e) {
        trigger_error('config: post-save correlation resolve failed: ' . $e->getMessage(), E_USER_WARNING);
    }

    // Suite contract: only a POST that came from the PGN Suite tab goes back
    // to the tab. Redirecting unconditionally would send a plugin-right-only
    // admin to Setup > General, which they cannot open.
    if (!empty($_POST['_from_pgnsuite'])) {
        Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('integrasipaman'));
    }

    Html::back();
} else {
    // The PGN Suite tab is the one visible entry point for anyone who can
    // open Setup > General; this page stays reachable by URL for admins who
    // hold only the plugin right.
    if (Session::haveRight('config', READ)) {
        Html::redirect(\GlpiPlugin\Pgnsuite\ConfigTab::getReturnUrl('integrasipaman'));
    }

    Html::header(
        PluginIntegrasipamanConfig::getTypeName(),
        '',
        \GlpiPlugin\Pgnsuite\Menu::SECTOR,
        'integrasipaman',
        'setup'
    );
    \GlpiPlugin\Pgnsuite\Menu::renderSubNav('integrasipaman', 'setup');

    $config = new PluginIntegrasipamanConfig();
    $config->showFormConfig();

    Html::footer();
}
