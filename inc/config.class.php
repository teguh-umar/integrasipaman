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
    ];

    public static function getTypeName($nb = 0)
    {
        return __('JumpServer Integration', 'integrasipaman');
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
            'id'    => 'jumpserver_access_key_id',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_access_key_id'],
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
        ]);
        echo '</div>';

        echo '<div class="mb-3">';
        echo '<label class="form-label" for="jumpserver_org_id">' . __('Organization ID') . '</label>';
        echo Html::input('jumpserver_org_id', [
            'id'    => 'jumpserver_org_id',
            'class' => 'form-control',
            'value' => $config_values['jumpserver_org_id'],
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

        echo '<div class="mb-3">';
        echo Html::submit(__('Save'), ['name' => 'update_btn', 'class' => 'btn btn-primary']);
        echo '</div>';

        Html::closeForm();

        echo '</div>'; // card-body
        echo '</div>'; // card
    }
}
