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

class PluginIntegrasipamanConfig extends CommonDBTM
{
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

    public const CONFIG_DEFAULTS = [
        'jumpserver_url'               => '',
        'jumpserver_access_key_id'     => '',
        'jumpserver_access_key_secret' => '',
        'jumpserver_org_id'            => self::DEFAULT_ORG_ID,
        // Separate MySQL server (confirmed different from GLPI's own DB
        // server) hosting the jumpserver_pbi_views reporting schema built
        // by a separate ETL project - see
        // docs/superpowers/specs/2026-08-26-jumpserver-pbi-insights-tab-design.md.
        // Read-only credentials, used only by inc/pbiconnection.class.php.
        'jumpserver_pbi_db_host'       => '',
        'jumpserver_pbi_db_port'       => '3306',
        'jumpserver_pbi_db_name'       => 'jumpserver_pbi_views',
        'jumpserver_pbi_db_user'       => '',
        'jumpserver_pbi_db_password'   => '',
        // Terminal command/session pull (inc/terminalpull.class.php +
        // the pull_terminal_activity cron). command_storage_id is a
        // required query param on /api/v1/terminal/commands/. The two
        // *_months values are read through self::clampMonths().
        'jumpserver_command_storage_id'        => '',
        'jumpserver_terminal_retention_months' => '13',
        'jumpserver_terminal_backfill_months'  => '13',
        // Command risk evaluation (inc/riskeval.class.php + the
        // evaluate_commands cron). One entry per line; blank lines and
        // lines starting "#" are ignored on load. Piece 3 will replace the
        // admin-user and prod-prefix lookups with GLPI-native sources.
        'jumpserver_risk_keywords'           => self::SEED_RISK_KEYWORDS,
        'jumpserver_admin_users'             => self::SEED_ADMIN_USERS,
        'jumpserver_production_ip_prefixes'  => self::SEED_PROD_PREFIXES,
    ];

    public static function getTypeName($nb = 0)
    {
        return __('JumpServer Integration', 'integrasipaman');
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
    public function showFormConfig(): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            return;
        }

        $config_values = array_merge(
            self::CONFIG_DEFAULTS,
            Config::getConfigurationValues(self::CONFIG_CONTEXT)
        );

        echo '<div class="card">';
        echo '<div class="card-header d-flex align-items-center justify-content-between">';
        echo '<h3 class="card-title">' . __('JumpServer Integration Settings') . '</h3>';
        echo '<a class="btn btn-sm btn-outline-secondary" href="'
            . Plugin::getWebDir('integrasipaman') . '/front/export.php" target="_blank" rel="noopener">'
            . __('View Activity Log', 'integrasipaman')
            . '</a>';
        echo '</div>';
        echo '<div class="card-body">';

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

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_url">' . __('JumpServer URL / IP') . '</label>';
        echo Html::input('jumpserver_url', [
            'id'          => 'jumpserver_url',
            'class'       => 'form-control',
            'value'       => $config_values['jumpserver_url'],
            'placeholder' => 'https://jumpserver.example.com',
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_access_key_id">' . __('Access Key ID') . '</label>';
        echo Html::input('jumpserver_access_key_id', [
            'id'       => 'jumpserver_access_key_id',
            'class'    => 'form-control',
            'value'    => $config_values['jumpserver_access_key_id'],
            'required' => 'required',
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_access_key_secret">' . __('Access Key Secret') . '</label>';
        echo Html::input('jumpserver_access_key_secret', [
            'id'           => 'jumpserver_access_key_secret',
            'type'         => 'password',
            'class'        => 'form-control',
            'value'        => $config_values['jumpserver_access_key_secret'],
            // Without this, Chromium-based browsers may silently offer to
            // autofill this field with an unrelated saved password on page
            // load - invisible since the field masks its contents, and only
            // noticed later when JumpServer starts rejecting the signature.
            'autocomplete' => 'new-password',
            'required'     => 'required',
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_org_id">' . __('Organization ID') . '</label>';
        echo Html::input('jumpserver_org_id', [
            'id'       => 'jumpserver_org_id',
            'class'    => 'form-control',
            'value'    => $config_values['jumpserver_org_id'],
            'required' => 'required',
        ]);
        echo '</div>';

        echo '<hr class="my-4">';
        echo '<h4>' . __('PBI Reporting Database (read-only)', 'integrasipaman') . '</h4>';
        echo '<p class="text-muted">' . __('Separate MySQL server hosting the jumpserver_pbi_views reporting schema, used by the JumpServer PBI Insights page under Tools.', 'integrasipaman') . '</p>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_pbi_db_host">' . __('PBI DB Host', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_host', [
            'id'    => 'jumpserver_pbi_db_host',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_host'],
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_pbi_db_port">' . __('PBI DB Port', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_port', [
            'id'    => 'jumpserver_pbi_db_port',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_port'],
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_pbi_db_name">' . __('PBI Database Name', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_name', [
            'id'    => 'jumpserver_pbi_db_name',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_name'],
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_pbi_db_user">' . __('PBI DB Username', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_user', [
            'id'    => 'jumpserver_pbi_db_user',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_pbi_db_user'],
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_pbi_db_password">' . __('PBI DB Password', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_pbi_db_password', [
            'id'           => 'jumpserver_pbi_db_password',
            'type'         => 'password',
            'class'        => 'form-control',
            'value'        => $config_values['jumpserver_pbi_db_password'],
            'autocomplete' => 'new-password',
        ]);
        echo '</div>';

        echo '<hr class="my-4">';
        echo '<h4>' . __('Terminal Command Audit', 'integrasipaman') . '</h4>';
        echo '<p class="text-muted">' . __('Pull of JumpServer terminal command & session records into GLPI (daily cron "Pull JumpServer terminal command & session records"). Feeds the command-audit reports.', 'integrasipaman') . '</p>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_command_storage_id">' . __('Command Storage ID', 'integrasipaman') . '</label>';
        echo Html::input('jumpserver_command_storage_id', [
            'id'    => 'jumpserver_command_storage_id',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_command_storage_id'],
        ]);
        echo '<small class="form-hint">' . __('Required. The command_storage_id from JumpServer (Console > Command Storage).', 'integrasipaman') . '</small>';
        echo '</div>';

        echo '<div class="mb-3">';
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

        echo '<div class="mb-3">';
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

        echo '<hr class="my-4">';
        echo '<h4>' . __('Command Risk Evaluation', 'integrasipaman') . '</h4>';
        echo '<p class="text-muted">' . __('Rules for classifying terminal commands (cron "Classify JumpServer command records", every 15 min). One entry per line; blank lines and lines starting with # are ignored. Editing any list re-evaluates all history automatically.', 'integrasipaman') . '</p>';

        foreach ([
            'jumpserver_risk_keywords'          => [__('High-risk keywords', 'integrasipaman'), __('A command containing any of these (or JumpServer risk level >= 4) is flagged high-risk.', 'integrasipaman'), 8],
            'jumpserver_admin_users'            => [__('Admin users', 'integrasipaman'), __('Format: Full Name(login) - exactly as shown in the command "User" column. High-risk actions by these users on production assets are MEDIUM, not HIGH.', 'integrasipaman'), 6],
            'jumpserver_production_ip_prefixes' => [__('Production IP prefixes', 'integrasipaman'), __('An asset whose IP starts with one of these is "production". e.g. 10.0.0.', 'integrasipaman'), 3],
        ] as $key => [$label, $hint, $rows]) {
            echo '<div class="mb-3">';
            echo '<label class="form-label" for="' . $key . '">' . htmlescape($label) . '</label>';
            echo '<textarea class="form-control" id="' . $key . '" name="' . $key . '" rows="' . (int) $rows . '">'
                . htmlescape($config_values[$key]) . '</textarea>';
            echo '<small class="form-hint">' . htmlescape($hint) . '</small>';
            echo '</div>';
        }

        echo '<div class="mb-3">';
        echo '<a class="btn btn-outline-secondary" href="' . Plugin::getWebDir('integrasipaman') . '/front/reeval.php">'
            . htmlescape(__('Re-evaluate now', 'integrasipaman')) . '</a>';
        echo '<small class="form-hint d-block">' . htmlescape(__('Save first if you changed a list above. Runs for up to 25 seconds; the cron finishes anything left.', 'integrasipaman')) . '</small>';
        echo '</div>';

        echo '<div class="mb-3">';
        echo Html::submit(__('Save'), ['name' => 'update_btn', 'class' => 'btn btn-primary']);
        echo '</div>';

        Html::closeForm();

        echo '</div>'; // card-body
        echo '</div>'; // card
    }
}
