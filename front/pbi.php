<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * PBI Insights page: reads JumpServer session/command/login/sharing detail
 * directly from the jumpserver_pbi_views MySQL schema (a separate ETL
 * project's reporting views - see
 * docs/superpowers/specs/2026-08-26-jumpserver-pbi-insights-tab-design.md),
 * via inc/pbiconnection.class.php's dedicated read-only connection.
 * Rendered through GLPI's own Html::header()/Html::footer() (unlike
 * front/export.php's standalone-CSS view mode) so it inherits GLPI's
 * active theme/dark mode for free.
 *
 * Access is deliberately gated to config-right holders (not merely any
 * authenticated user like front/export.php): this page surfaces PAM
 * audit detail (executed commands, privileged usernames, login-failure
 * detail) from a separate database, not just this plugin's own thin
 * event log - see PluginIntegrasipamanDashboard::getMenuContent(), which
 * hides the Tools-menu entry under the same right check.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/config.class.php');
include_once(__DIR__ . '/../inc/pbiconnection.class.php');
include_once(__DIR__ . '/../inc/report.class.php');

Session::checkLoginUser();
// v2.0.0: gated on the plugin's own right (READ) instead of core `config`
// UPDATE, which used to hand the whole of Setup > General to anyone who
// needed these reports.
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT, READ);

/**
 * Render one PBI section: a heading, a one-line row-count KPI, and a
 * table - or, if $rows is null, a plain inline error message instead of
 * attempting to render a table.
 *
 * @param array<int, array<string, mixed>>|null $rows    Rows to render, or null on failure.
 * @param array<string, string>                 $columns Map of row key => column label, in display order.
 */
function jumpserver_pbi_render_section(string $title, ?array $rows, array $columns, int $row_limit): void
{
    echo '<div class="card mb-4">';
    echo '<div class="card-header"><h3 class="card-title">' . htmlescape($title) . '</h3></div>';
    echo '<div class="card-body">';

    if ($rows === null) {
        echo '<div class="alert alert-danger mb-0">'
            . htmlescape(__('Could not connect to the PBI reporting database. Check the configuration in Setup > Plugins > JumpServer Integration.', 'integrasipaman'))
            . '</div>';
        echo '</div></div>';
        return;
    }

    if (count($rows) >= $row_limit) {
        echo '<p class="text-muted">' . htmlescape(sprintf(
            __('Showing the most recent %d rows (there may be more in the last 30 days).', 'integrasipaman'),
            $row_limit
        )) . '</p>';
    } else {
        echo '<p class="text-muted">' . htmlescape(sprintf(
            _n('%d row in the last 30 days.', '%d rows in the last 30 days.', count($rows), 'integrasipaman'),
            count($rows)
        )) . '</p>';
    }

    if (empty($rows)) {
        echo '</div></div>';
        return;
    }

    echo '<div class="table-responsive"><table class="table table-striped table-hover card-table">';
    echo '<thead><tr>';

    foreach ($columns as $label) {
        echo '<th>' . htmlescape($label) . '</th>';
    }

    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        echo '<tr>';

        foreach (array_keys($columns) as $key) {
            $value = (string) ($row[$key] ?? '');

            if ($key === 'command_text') {
                // Long shell commands would otherwise blow out the table
                // width; full text is available in the underlying view
                // for anyone querying it directly.
                $value = mb_substr($value, 0, 80);
            }

            echo '<td>' . htmlescape($value) . '</td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '</div></div>';
}

Html::header(
    __('JumpServer PBI Insights', 'integrasipaman'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginIntegrasipamanDashboard'
);

$tab      = (($_GET['tab'] ?? 'insights') === 'report') ? 'report' : 'insights';
$base_url = Plugin::getWebDir('integrasipaman') . '/front/pbi.php';

echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link' . ($tab === 'insights' ? ' active' : '')
    . '" href="' . htmlescape($base_url . '?tab=insights') . '">'
    . htmlescape(__('PBI Insights', 'integrasipaman')) . '</a></li>';
echo '<li class="nav-item"><a class="nav-link' . ($tab === 'report' ? ' active' : '')
    . '" href="' . htmlescape($base_url . '?tab=report') . '">'
    . htmlescape(__('Command Audit Report', 'integrasipaman')) . '</a></li>';
echo '</ul>';

if ($tab === 'report') {
    PluginIntegrasipamanReport::renderReportTab($_GET);
    Html::footer();
    return;
}

include_once(__DIR__ . '/../inc/dashboard.class.php');

// KPI strip (this plugin's own event-log totals, last 30 days) above the
// PBI-database detail tables - see PluginIntegrasipamanDashboard::renderOverview().
PluginIntegrasipamanDashboard::renderOverview();

$config = array_merge(
    PluginIntegrasipamanConfig::CONFIG_DEFAULTS,
    Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT)
);

$pbi_host     = trim($config['jumpserver_pbi_db_host'] ?? '');
$pbi_port     = trim($config['jumpserver_pbi_db_port'] ?? '');
$pbi_database = trim($config['jumpserver_pbi_db_name'] ?? '');
$pbi_user     = trim($config['jumpserver_pbi_db_user'] ?? '');
$pbi_password = trim($config['jumpserver_pbi_db_password'] ?? '');

$row_limit = 100;

if ($pbi_host === '') {
    echo '<div class="alert alert-warning">'
        . htmlescape(__('PBI reporting database is not configured. Set it up in Setup > Plugins > JumpServer Integration.', 'integrasipaman'))
        . '</div>';
} else {
    $pbi_connection = new PluginIntegrasipamanPbiConnection(
        $pbi_host,
        $pbi_port,
        $pbi_database,
        $pbi_user,
        $pbi_password
    );

    jumpserver_pbi_render_section(
        __('Sessions', 'integrasipaman'),
        $pbi_connection->fetchRows(PluginIntegrasipamanPbiConnection::VIEW_SESSIONS, $row_limit),
        [
            'date_start'      => __('Date', 'integrasipaman'),
            'user_display'    => __('User', 'integrasipaman'),
            'asset'           => __('Asset', 'integrasipaman'),
            'protocol'        => __('Protocol', 'integrasipaman'),
            'duration_bucket' => __('Duration', 'integrasipaman'),
            'session_status'  => __('Status', 'integrasipaman'),
            'client_ip'       => __('IP Address', 'integrasipaman'),
        ],
        $row_limit
    );

    jumpserver_pbi_render_section(
        __('Commands', 'integrasipaman'),
        $pbi_connection->fetchRows(PluginIntegrasipamanPbiConnection::VIEW_COMMANDS, $row_limit),
        [
            'executed_at'  => __('Date', 'integrasipaman'),
            'user'         => __('User', 'integrasipaman'),
            'asset'        => __('Asset', 'integrasipaman'),
            'command_text' => __('Command', 'integrasipaman'),
            'risk_label'   => __('Risk', 'integrasipaman'),
        ],
        $row_limit
    );

    jumpserver_pbi_render_section(
        __('Login Monitor', 'integrasipaman'),
        $pbi_connection->fetchRows(PluginIntegrasipamanPbiConnection::VIEW_LOGIN_FAILURES, $row_limit),
        [
            'login_time' => __('Date', 'integrasipaman'),
            'username'   => __('Username', 'integrasipaman'),
            'ip_address' => __('IP Address', 'integrasipaman'),
            'ip_type'    => __('IP Type', 'integrasipaman'),
            'mfa_type'   => __('MFA', 'integrasipaman'),
            'reason'     => __('Reason', 'integrasipaman'),
        ],
        $row_limit
    );

    jumpserver_pbi_render_section(
        __('Session Sharing', 'integrasipaman'),
        $pbi_connection->fetchRows(PluginIntegrasipamanPbiConnection::VIEW_SESSION_SHARING, $row_limit),
        [
            'shared_at'      => __('Shared At', 'integrasipaman'),
            'sharer_display' => __('Sharer', 'integrasipaman'),
            'session_owner'  => __('Session Owner', 'integrasipaman'),
            'asset_name'     => __('Asset', 'integrasipaman'),
            'access_mode'    => __('Access Mode', 'integrasipaman'),
            'share_status'   => __('Status', 'integrasipaman'),
            'risk_level'     => __('Risk', 'integrasipaman'),
        ],
        $row_limit
    );
}

Html::footer();
