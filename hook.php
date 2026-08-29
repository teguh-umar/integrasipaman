<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Install / uninstall hooks.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

/**
 * Plugin install process.
 *
 * @return boolean
 */
function plugin_integrasipaman_install(): bool
{
    global $DB;

    $table = 'glpi_plugin_jumpserver_logs';

    if (!$DB->tableExists($table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // Table creation is DDL, which has no array-based abstraction in
        // GLPI's DB layer, so it is defined via a guarded raw CREATE TABLE.
        // All subsequent reads/writes to this table use $DB->insert() /
        // $DB->update() / iterator methods instead of raw SQL.
        //
        // The timestamp column is named `date_creation` (GLPI's own
        // convention, not the more common `created_at`) deliberately:
        // GLPI 11's native dashboard "date range" filter
        // (Glpi\Dashboard\Filters\DatesFilter) auto-detects applicability
        // via $DB->fieldExists($table, 'date_creation') - matching that
        // exact name is what lets our dashboard cards support the native
        // per-dashboard date filter with zero custom filter-class code.
        // `source_id` holds the JumpServer-side UUID of the record this
        // row came from (operating-log entry, login-log entry, or
        // session-sharing entry), used to skip re-importing the same
        // event on a later cron run - critical for
        // /api/v1/terminal/session-sharings/, which (unlike the other two
        // endpoints) has no date_from/date_to filter, so every cron run
        // re-fetches its entire result set. Nullable + non-unique: older
        // rows imported before this column existed have no source_id, and
        // a plain index (not a UNIQUE constraint) keeps a rare hash
        // collision or a JumpServer-side ID reuse from ever hard-failing
        // an insert - the plugin's own pre-insert existence check (see
        // PluginIntegrasipamanCron::rowExistsForSource()) is what actually
        // prevents duplicates.
        $query = "CREATE TABLE `$table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `event_type` VARCHAR(50) NOT NULL DEFAULT '',
            `username` VARCHAR(100) NOT NULL DEFAULT '',
            `asset_name` VARCHAR(100) NOT NULL DEFAULT '',
            `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
            `status` VARCHAR(20) NOT NULL DEFAULT '',
            `message` TEXT NULL,
            `source_id` VARCHAR(64) NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `source_id` (`source_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('Unable to create table %s: %s', $table, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }
    } else {
        if ($DB->fieldExists($table, 'created_at') && !$DB->fieldExists($table, 'date_creation')) {
            // Upgrade path from plugin versions <1.4.0: rename the
            // existing column in place (data preserved) rather than
            // dropping/recreating.
            if (!$DB->doQuery("ALTER TABLE `$table` CHANGE COLUMN `created_at` `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP")) {
                trigger_error(
                    sprintf('Unable to rename created_at to date_creation on %s: %s', $table, $DB->error()),
                    E_USER_WARNING
                );
                return false;
            }
        }

        if (!$DB->fieldExists($table, 'source_id')) {
            // Upgrade path from plugin versions <1.7.0: existing rows get
            // NULL (they predate per-event dedup and are never
            // re-fetched anyway, since operating-logs/login-logs are
            // scoped by date_from).
            if (!$DB->doQuery("ALTER TABLE `$table` ADD COLUMN `source_id` VARCHAR(64) NULL DEFAULT NULL, ADD KEY `source_id` (`source_id`)")) {
                trigger_error(
                    sprintf('Unable to add source_id to %s: %s', $table, $DB->error()),
                    E_USER_WARNING
                );
                return false;
            }
        }
    }

    include_once(__DIR__ . '/inc/cron.class.php');

    CronTask::Register(
        'PluginIntegrasipamanCron',
        'pull_historical_logs',
        HOUR_TIMESTAMP,
        [
            'comment' => 'Pull historical audit logs from the JumpServer REST API',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    include_once(__DIR__ . '/inc/config.class.php');

    // Seed default (empty) configuration values on first install only, so
    // Config::getConfigurationValues() always returns predictable keys.
    $existing_config = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);

    if (empty($existing_config)) {
        Config::setConfigurationValues(
            PluginIntegrasipamanConfig::CONFIG_CONTEXT,
            PluginIntegrasipamanConfig::CONFIG_DEFAULTS
        );
    }

    return true;
}

/**
 * Plugin uninstall process.
 *
 * @return boolean
 */
function plugin_integrasipaman_uninstall(): bool
{
    include_once(__DIR__ . '/inc/cron.class.php');
    include_once(__DIR__ . '/inc/config.class.php');

    CronTask::Unregister('PluginIntegrasipamanCron');

    Config::deleteConfigurationValues(
        PluginIntegrasipamanConfig::CONFIG_CONTEXT,
        array_keys(PluginIntegrasipamanConfig::CONFIG_DEFAULTS)
    );

    // The event table (glpi_plugin_jumpserver_logs) is deliberately NOT
    // dropped on uninstall. Its rows are pulled from JumpServer's audit
    // API and JumpServer rotates its own audit history out over time, so a
    // drop-and-reinstall would permanently lose every event older than
    // whatever JumpServer still retains at re-pull time. Keeping the table
    // means a reinstall's first cron run (which requests full history -
    // see PluginIntegrasipamanCron::computeStartDate()) only back-fills
    // the gap, with source_id dedup skipping everything already stored.
    // A real purge is a manual DROP TABLE, which is not this hook's job.

    return true;
}

/**
 * 'item_add' hook target (registered in setup.php's plugin_init function).
 * GLPI calls this after any item is created; only Computer assets are
 * pushed on to JumpServer as the GLPI -> JumpServer source-of-truth sync.
 *
 * @param CommonDBTM $item The item that was just created.
 *
 * @return void
 */
function plugin_integrasipaman_add_computer(CommonDBTM $item): void
{
    if (!($item instanceof Computer)) {
        return;
    }

    include_once(__DIR__ . '/inc/assetsync.class.php');

    $sync = new PluginIntegrasipamanAssetSync();
    $sync->pushToJumpServer($item, 'add');
}

/**
 * 'item_update' hook target (registered in setup.php's plugin_init
 * function). GLPI calls this after any item is updated; only Computer
 * assets are pushed on to JumpServer, and only when the update actually
 * touched a field that is part of the JumpServer payload (name, comment,
 * operatingsystems_id) - this avoids an API call for every unrelated
 * Computer field edit (e.g. inventory metadata).
 *
 * Note: an IP-only change (glpi_networkports/glpi_ipaddresses) is not
 * visible here, since those live outside Computer::$updates; it will not
 * by itself re-trigger a sync.
 *
 * @param CommonDBTM $item The item that was just updated.
 *
 * @return void
 */
function plugin_integrasipaman_update_computer(CommonDBTM $item): void
{
    if (!($item instanceof Computer)) {
        return;
    }

    $synced_fields  = ['name', 'comment', 'operatingsystems_id'];
    $changed_fields = is_array($item->updates ?? null) ? $item->updates : [];

    if (empty(array_intersect($synced_fields, $changed_fields))) {
        return;
    }

    include_once(__DIR__ . '/inc/assetsync.class.php');

    $sync = new PluginIntegrasipamanAssetSync();
    $sync->pushToJumpServer($item, 'update');
}
