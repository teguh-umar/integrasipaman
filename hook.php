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
 * Fill `date_creation` with the time each event actually happened on
 * JumpServer, read out of the JSON the row already carries in `message`.
 * One-shot, run by the v4.5.0 migration after `date_import` has taken a copy
 * of the old values.
 *
 * Why the JSON and not another API call: every row kept the whole JumpServer
 * record, so the event time has been sitting in the table all along - it was
 * only never promoted to a column. No network, no credentials, no
 * JumpServer-side retention limit to run into.
 *
 * Walks by `id` in batches so the migration holds no large result set and
 * takes no long lock, which matters on an audit table that is years deep.
 * A row whose JSON has no usable time is left exactly as it was.
 *
 * @param string $table The logs table.
 * @return bool False only on a write failure, which aborts the migration.
 */
function plugin_integrasipaman_backfill_event_dates(string $table): bool
{
    global $DB;

    require_once __DIR__ . '/inc/apiwindow.class.php';

    // Which JSON key holds the event time, by event_type. Anything else is an
    // operate-log entry, whose key is 'datetime'.
    $field_for = [
        'session_share'     => 'date_created',
        'session_join'      => 'date_joined',
        'user_login_failed' => 'datetime',
    ];

    $batch   = 500;
    $last_id = 0;
    $updated = 0;

    while (true) {
        $rows = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'event_type', 'message', 'date_creation'],
            'FROM'   => $table,
            'WHERE'  => ['id' => ['>', $last_id]],
            'ORDER'  => 'id ASC',
            'LIMIT'  => $batch,
        ]) as $row) {
            $rows[] = $row;
        }

        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $last_id = (int) $row['id'];

            $data = json_decode((string) ($row['message'] ?? ''), true);
            if (!is_array($data)) {
                continue;
            }

            $key   = $field_for[(string) $row['event_type']] ?? 'datetime';
            $value = $data[$key] ?? null;
            $when  = PluginIntegrasipamanApiWindow::sqlTime(is_scalar($value) ? (string) $value : null);

            if ($when === null || $when === (string) $row['date_creation']) {
                continue;
            }

            if (!$DB->update($table, ['date_creation' => $when], ['id' => $last_id])) {
                trigger_error(
                    sprintf('Unable to backfill date_creation on %s row %d: %s', $table, $last_id, $DB->error()),
                    E_USER_WARNING
                );
                return false;
            }

            $updated++;
        }
    }

    // Said out loud: the numbers on every card move when this runs, and an
    // upgrade that silently rewrites a production column is the kind of thing
    // that should leave a trace someone can find later.
    Toolbox::logInFile(
        'integrasipaman',
        sprintf(
            "v4.5.0 migration: %d of the rows in %s had their date_creation set to the JumpServer event time; "
            . "the previous value of every row is kept in date_import.\n",
            $updated,
            $table
        )
    );

    return true;
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
        // v4.5.0 - what the two timestamps mean, because they were one
        // column until this version and the difference is the whole point:
        //   `date_creation` is WHEN THE EVENT HAPPENED on JumpServer,
        //   converted from its +0800 offset into GLPI's timezone.
        //   `date_import`   is when this row was written here.
        // They are not the same. A first full-history pull writes months of
        // events in one minute, and until v4.5.0 every card counted the
        // minute instead of the months - which is why the Session Sharing
        // card could read 0 for a period that had real activity.
        // `date_creation` keeps that name (rather than the more obvious
        // `event_date`) for the reason spelled out above: core's DatesFilter
        // only offers itself on a table that has a column by that name, and
        // the event time is what every card, filter and export should be
        // windowing on. When JumpServer gives no usable time for an entry,
        // the insert falls back to the import time, so the column is never
        // empty and a row is never invisible to a date filter.
        //
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
            `date_import` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `source_id` (`source_id`),
            KEY `event_type_date` (`event_type`, `date_creation`)
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

        // Upgrade path from plugin versions <4.5.0: split the one timestamp
        // into event time (`date_creation`) and import time (`date_import`).
        // Ordered so that nothing is lost at any point: the original value is
        // COPIED into the new column first, and only then is `date_creation`
        // rewritten from each row's stored JSON. A row whose JSON holds no
        // usable time keeps the value it already had. `date_import` is
        // therefore a complete record of the column as it stood before this
        // migration, and restoring it is one UPDATE.
        if (!$DB->fieldExists($table, 'date_import')) {
            if (!$DB->doQuery("ALTER TABLE `$table` ADD COLUMN `date_import` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP")) {
                trigger_error(
                    sprintf('Unable to add date_import to %s: %s', $table, $DB->error()),
                    E_USER_WARNING
                );
                return false;
            }

            if (!$DB->doQuery("UPDATE `$table` SET `date_import` = `date_creation`")) {
                trigger_error(
                    sprintf('Unable to seed date_import on %s: %s', $table, $DB->error()),
                    E_USER_WARNING
                );
                return false;
            }

            if (!plugin_integrasipaman_backfill_event_dates($table)) {
                return false;
            }
        }

        // function_exists() guard: a missing core helper must not fatal an
        // install over an index, which is a speed matter and nothing more.
        if (!function_exists('isIndex') || !isIndex($table, 'event_type_date')) {
            // Every card counts one event_type inside a date window, and
            // after the backfill that window is a range scan on a column the
            // rows are no longer inserted in order of.
            if (!$DB->doQuery("ALTER TABLE `$table` ADD KEY `event_type_date` (`event_type`, `date_creation`)")) {
                trigger_error(
                    sprintf('Unable to add event_type_date index to %s: %s', $table, $DB->error()),
                    E_USER_WARNING
                );
                // Not fatal: an index is a speed matter, not a correctness one.
            }
        }
    }

    $commands_table = 'glpi_plugin_jumpserver_commands';

    if (!$DB->tableExists($commands_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // Lean copy of JumpServer terminal command records (no command
        // "output" - see the Piece 1 design doc). `jms_id` is JumpServer's
        // own command id; UNIQUE so a concurrent cron + backfill-page run
        // for the same month cannot double-insert (insertDedup's app-level
        // check can still lose the race).
        $query = "CREATE TABLE `$commands_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `jms_id` VARCHAR(64) NOT NULL DEFAULT '',
            `executed_at` TIMESTAMP NULL DEFAULT NULL,
            `jms_user` VARCHAR(191) NOT NULL DEFAULT '',
            `asset` VARCHAR(191) NOT NULL DEFAULT '',
            `account` VARCHAR(191) NOT NULL DEFAULT '',
            `command` TEXT NULL,
            `risk_level` TINYINT NOT NULL DEFAULT 0,
            `risk_label` VARCHAR(32) NOT NULL DEFAULT '',
            `session_jms_id` VARCHAR(64) NOT NULL DEFAULT '',
            `remote_addr` VARCHAR(45) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `jms_id` (`jms_id`),
            KEY `executed_at` (`executed_at`),
            KEY `asset` (`asset`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('Unable to create table %s: %s', $commands_table, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }
    }

    $sessions_table = 'glpi_plugin_jumpserver_sessions';

    if (!$DB->tableExists($sessions_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // One row per JumpServer terminal session. `jms_id` = JumpServer
        // session id, dedup key; UNIQUE for the same race reason as the
        // commands table above.
        $query = "CREATE TABLE `$sessions_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `jms_id` VARCHAR(64) NOT NULL DEFAULT '',
            `jms_user` VARCHAR(191) NOT NULL DEFAULT '',
            `user_jms_id` VARCHAR(64) NOT NULL DEFAULT '',
            `asset` VARCHAR(191) NOT NULL DEFAULT '',
            `asset_jms_id` VARCHAR(64) NOT NULL DEFAULT '',
            `account` VARCHAR(191) NOT NULL DEFAULT '',
            `protocol` VARCHAR(32) NOT NULL DEFAULT '',
            `login_from` VARCHAR(32) NOT NULL DEFAULT '',
            `remote_addr` VARCHAR(45) NOT NULL DEFAULT '',
            `duration` INT NOT NULL DEFAULT 0,
            `command_amount` INT NOT NULL DEFAULT 0,
            `is_success` TINYINT(1) NOT NULL DEFAULT 0,
            `is_finished` TINYINT(1) NOT NULL DEFAULT 0,
            `date_start` TIMESTAMP NULL DEFAULT NULL,
            `date_end` TIMESTAMP NULL DEFAULT NULL,
            `error_reason` VARCHAR(128) NOT NULL DEFAULT '',
            `terminal_name` VARCHAR(128) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `jms_id` (`jms_id`),
            KEY `date_start` (`date_start`),
            KEY `asset` (`asset`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('Unable to create table %s: %s', $sessions_table, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }
    }

    $eval_table = 'glpi_plugin_jumpserver_command_eval';

    if (!$DB->tableExists($eval_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // One row per glpi_plugin_jumpserver_commands row - the risk
        // classification (see inc/riskeval.class.php). UNIQUE(commands_id)
        // is the 1:1 link and the upsert key; the FK cascade keeps this
        // table consistent when Piece 1's retention prune deletes commands.
        // asset_class here is the classification as it stood when the
        // command was evaluated. It lags the asset's current class on
        // purpose - that gap is what the generation mechanism closes.
        $query = "CREATE TABLE `$eval_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `commands_id` INT {$default_key_sign} NOT NULL,
            `is_high_risk` TINYINT(1) NOT NULL DEFAULT 0,
            `keyword_hit` VARCHAR(64) NOT NULL DEFAULT '',
            `risk_category` VARCHAR(32) NOT NULL DEFAULT '',
            `asset_ip` VARCHAR(45) NOT NULL DEFAULT '',
            `asset_class` VARCHAR(20) NOT NULL DEFAULT '',
            `user_is_admin` TINYINT(1) NOT NULL DEFAULT 0,
            `priority` VARCHAR(8) NOT NULL DEFAULT '',
            `catatan` VARCHAR(48) NOT NULL DEFAULT '',
            `rules_hash` CHAR(40) NOT NULL DEFAULT '',
            `date_eval` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `commands_id` (`commands_id`),
            KEY `is_high_risk` (`is_high_risk`),
            KEY `risk_category` (`risk_category`),
            KEY `priority` (`priority`),
            KEY `rules_hash` (`rules_hash`),
            CONSTRAINT `fk_jumpserver_command_eval_commands`
                FOREIGN KEY (`commands_id`)
                REFERENCES `glpi_plugin_jumpserver_commands` (`id`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            // If the target instance rejects the FK (some GLPI schema
            // tooling strips FKs), retry without it - Piece 1's prune()
            // deletes matching eval rows explicitly as a fallback (Task 3).
            $query_nofk = preg_replace('/,\s*CONSTRAINT `fk_jumpserver_command_eval_commands`.*?ON DELETE CASCADE/s', '', $query);

            if (!$DB->doQuery($query_nofk)) {
                trigger_error(
                    sprintf('Unable to create table %s: %s', $eval_table, $DB->error()),
                    E_USER_WARNING
                );
                return false;
            }
        }
    }

    $assetmap_table = 'glpi_plugin_jumpserver_assetmap';

    if (!$DB->tableExists($assetmap_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // One row per DISTINCT `asset` string in
        // glpi_plugin_jumpserver_commands. Pure derived data written by
        // PluginIntegrasipamanCorrelation - no FK to `commands` (the key
        // is the string; many command rows share one asset, and a
        // retention prune of some of them must not drop the mapping).
        // asset_class is production | development | '' (not yet classified).
        // Empty is a work-queue entry, NOT a third class and NOT a falsy
        // production - see the spec, section 3.
        //
        // Two DERIVED columns, recomputed every run and never set by hand.
        // ip_mismatch: a Computer is linked but the asset's address is not
        // among that Computer's. live_checked_at: when the PAMAN host list
        // last confirmed this asset - NULL means never asked, which is our
        // ignorance and NOT a finding, so consumers include it.
        $query = "CREATE TABLE `$assetmap_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `asset` VARCHAR(191) NOT NULL DEFAULT '',
            `parsed_name` VARCHAR(191) NOT NULL DEFAULT '',
            `parsed_ip` VARCHAR(45) NOT NULL DEFAULT '',
            `computers_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `ipnetworks_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `asset_class` VARCHAR(20) NOT NULL DEFAULT '',
            `ip_mismatch` TINYINT(1) NOT NULL DEFAULT 0,
            `live_checked_at` TIMESTAMP NULL DEFAULT NULL,
            `resolve_method` VARCHAR(20) NOT NULL DEFAULT 'unresolved',
            `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
            `note` VARCHAR(255) NOT NULL DEFAULT '',
            `last_resolved_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `asset` (`asset`),
            KEY `computers_id` (`computers_id`),
            KEY `asset_class` (`asset_class`),
            KEY `resolve_method` (`resolve_method`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('Unable to create table %s: %s', $assetmap_table, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }
    }

    $usermap_table = 'glpi_plugin_jumpserver_usermap';

    if (!$DB->tableExists($usermap_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // One row per DISTINCT `jms_user` string. Same rationale as
        // glpi_plugin_jumpserver_assetmap - derived data, no FK.
        $query = "CREATE TABLE `$usermap_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `jms_user` VARCHAR(191) NOT NULL DEFAULT '',
            `parsed_login` VARCHAR(191) NOT NULL DEFAULT '',
            `users_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
            `resolve_method` VARCHAR(20) NOT NULL DEFAULT 'unresolved',
            `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
            `note` VARCHAR(255) NOT NULL DEFAULT '',
            `last_resolved_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `jms_user` (`jms_user`),
            KEY `users_id` (`users_id`),
            KEY `is_admin` (`is_admin`),
            KEY `resolve_method` (`resolve_method`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('Unable to create table %s: %s', $usermap_table, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }
    }

    $provisioning_table = 'glpi_plugin_jumpserver_provisioning';

    if (!$DB->tableExists($provisioning_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // One row per Computer ever judged eligible for PAMAN. UNIQUE on
        // computers_id: the whole dedup story rests on there being exactly
        // one provisioning record per asset. `removed` rows are kept
        // forever - they are the audit evidence that an asset was in the
        // bastion and was taken out on a date, by a person, against a
        // ticket.
        $query = "CREATE TABLE `$provisioning_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `computers_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `jumpserver_asset_id` VARCHAR(36) NOT NULL DEFAULT '',
            `status` VARCHAR(20) NOT NULL DEFAULT 'eligible',
            `node_id` VARCHAR(36) NOT NULL DEFAULT '',
            `node_label` VARCHAR(191) NOT NULL DEFAULT '',
            `platform_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `platform_label` VARCHAR(191) NOT NULL DEFAULT '',
            `payload_hash` CHAR(64) NOT NULL DEFAULT '',
            `drift_fields` VARCHAR(255) NOT NULL DEFAULT '',
            `tickets_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `last_actor_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `last_action` VARCHAR(20) NOT NULL DEFAULT '',
            `last_action_date` TIMESTAMP NULL DEFAULT NULL,
            `last_error` TEXT NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `computers_id` (`computers_id`),
            KEY `status` (`status`),
            KEY `jumpserver_asset_id` (`jumpserver_asset_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('Unable to create table %s: %s', $provisioning_table, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }
    }

    foreach ([
        ['glpi_plugin_jumpserver_nodemap',     'computertypes_id',    'node_id',     'VARCHAR(36)', 'node_label'],
        ['glpi_plugin_jumpserver_platformmap', 'operatingsystems_id', 'platform_id', 'INT UNSIGNED', 'platform_label'],
    ] as [$map_table, $source_col, $target_col, $target_type, $label_col]) {
        if ($DB->tableExists($map_table)) {
            continue;
        }

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // Source id 0 is the default row: the fallback for any type/OS the
        // admin has not mapped explicitly. UNIQUE on the source column
        // keeps one mapping per source - two rows for one type would make
        // the resolved node depend on row order.
        // str_starts_with, NOT === 'INT': the type is now 'INT UNSIGNED',
        // and an exact comparison falls through to the string branch,
        // producing `platform_id INT UNSIGNED NOT NULL DEFAULT ''`.
        $default_target = str_starts_with($target_type, 'INT') ? '0' : "''";

        $query = "CREATE TABLE `$map_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `{$source_col}` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `{$target_col}` {$target_type} NOT NULL DEFAULT {$default_target},
            `{$label_col}` VARCHAR(191) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `{$source_col}` (`{$source_col}`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(sprintf('Unable to create table %s: %s', $map_table, $DB->error()), E_USER_WARNING);
            return false;
        }
    }

    $permrules_table = 'glpi_plugin_jumpserver_permrules';

    if (!$DB->tableExists($permrules_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // `date_start` and `date_expired` are TIMESTAMP, and the values that
        // do not fit are normalised to NULL at ingestion rather than the
        // column being widened. JumpServer writes year 2123 to mean "never
        // expires"; TIMESTAMP stops at 2038-01-19, so MySQL rejected the
        // INSERT and v3.3.0's cron died on the first rule it read.
        //
        // v3.4.0 widened these to DATETIME and that was WRONG on GLPI 11,
        // verified against 11.0.5 core: Central.php:562 calls
        // getTzIncompatibleTables() with exclude_plugins defaulting to FALSE,
        // so a plugin table with a DATETIME column raises the deprecated-
        // datetime Setup warning permanently - the same warning v3.0.1 was
        // cut to clear. Worse, migration:timestamps (TimestampsCommand.php:79)
        // walks that identical list and MODIFYs the column back to TIMESTAMP,
        // so the person who follows the warning's own advice re-breaks the
        // cron. DBmysql.php:2166 also warns on any DATETIME in a CREATE once
        // no core table uses one, which is this VM's state.
        //
        // The normalisation lives in Entitlement::sqlDate(). NULL here means
        // exactly what the sentinel means: no expiry.
        // One row per JumpServer asset-permission rule. Members are stored
        // as JSON rather than a join table: 540 user-relations across 219
        // rules is ~2.5 members each, cheap to decode for the ~4 rules an
        // asset carries. The trade-off is that "which assets can user U
        // reach" cannot be queried - see the spec, section 4.
        $query = "CREATE TABLE `$permrules_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `jms_id` VARCHAR(64) NOT NULL DEFAULT '',
            `name` VARCHAR(191) NOT NULL DEFAULT '',
            `users_json` TEXT NULL,
            `user_groups_json` TEXT NULL,
            `accounts` VARCHAR(255) NOT NULL DEFAULT '',
            `actions` VARCHAR(255) NOT NULL DEFAULT '',
            `is_active` TINYINT(1) NOT NULL DEFAULT 0,
            `is_valid` TINYINT(1) NOT NULL DEFAULT 0,
            `is_expired` TINYINT(1) NOT NULL DEFAULT 0,
            `date_start` TIMESTAMP NULL DEFAULT NULL,
            `date_expired` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `jms_id` (`jms_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(sprintf('Unable to create table %s: %s', $permrules_table, $DB->error()), E_USER_WARNING);
            return false;
        }
    }

    $assetperms_table = 'glpi_plugin_jumpserver_assetperms';

    if (!$DB->tableExists($assetperms_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // Which rules reach which Computer, as JumpServer resolved it.
        // UNIQUE(computers_id, permrules_jms_id) is what makes a
        // multi-mapped Computer union rather than duplicate: two JumpServer
        // assets granting the same rule collapse to one row.
        $query = "CREATE TABLE `$assetperms_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `computers_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `jms_asset_id` VARCHAR(64) NOT NULL DEFAULT '',
            `permrules_jms_id` VARCHAR(64) NOT NULL DEFAULT '',
            `date_refreshed` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `computer_rule` (`computers_id`, `permrules_jms_id`),
            KEY `computers_id` (`computers_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(sprintf('Unable to create table %s: %s', $assetperms_table, $DB->error()), E_USER_WARNING);
            return false;
        }
    }

    // --- schema-convention migration (v3.2.0) -----------------------------
    // v3.0.1 created three columns that GLPI 11 counts as violations on the
    // Setup page: two `*_id` columns without UNSIGNED, and one DATETIME.
    // They install and work fine - the warning is the only symptom, which is
    // why nothing caught them until the VM showed it.
    //
    // The corrected CREATE TABLE above only helps a FRESH install. Anything
    // already deployed keeps the old column until it is altered here, which
    // is exactly the instance that reported the warning.
    foreach ([
        ['glpi_plugin_jumpserver_provisioning', 'platform_id'],
        ['glpi_plugin_jumpserver_platformmap',  'platform_id'],
    ] as [$fix_table, $fix_col]) {
        if (!$DB->tableExists($fix_table) || !$DB->fieldExists($fix_table, $fix_col)) {
            continue;
        }

        // A negative value cannot survive conversion to UNSIGNED - MySQL
        // clamps it to 0 or errors depending on strict mode, and either way
        // the row silently stops pointing at the platform it pointed at.
        // PAMAN platform ids are positive, so this should never fire; if it
        // does, leave the column alone and say so rather than damage data.
        $negatives = $DB->request([
            'SELECT' => [new QueryExpression('COUNT(*) AS ' . $DB->quoteName('n'))],
            'FROM'   => $fix_table,
            'WHERE'  => [$fix_col => ['<', 0]],
        ])->current();

        if ((int) ($negatives['n'] ?? 0) > 0) {
            trigger_error(
                sprintf(
                    '%s.%s holds %d negative value(s); left as SIGNED. Correct the data, '
                    . 'then reinstall the plugin to complete the migration.',
                    $fix_table,
                    $fix_col,
                    (int) $negatives['n']
                ),
                E_USER_WARNING
            );
            continue;
        }

        if (!$DB->doQuery("ALTER TABLE `$fix_table` MODIFY `$fix_col` INT UNSIGNED NOT NULL DEFAULT 0")) {
            trigger_error(sprintf('Unable to migrate %s.%s to UNSIGNED: %s', $fix_table, $fix_col, $DB->error()), E_USER_WARNING);
        }
    }

    // DATETIME -> TIMESTAMP. Safe here in a way it would NOT be on the audit
    // tables: this column is written only by the provisioning queue, which
    // has never run against production, so there are no stored values whose
    // displayed time could shift. As of v3.4.0 the five older
    // DATETIME columns are TIMESTAMP in the CREATE above, so a fresh install
    // is born compliant. They are NOT altered on an existing install: those
    // hold ~280k rows of audit history, and TIMESTAMP is timezone-converted
    // on read while DATETIME is not, so an ALTER could shift displayed times
    // in an audit log. Production was checked on 2026-09-24 and has no
    // DATETIME column at all - GLPI's own migration:timestamps converted
    // them already - so there is nothing there to alter in any case.
    if ($DB->tableExists('glpi_plugin_jumpserver_provisioning')
        && $DB->fieldExists('glpi_plugin_jumpserver_provisioning', 'last_action_date')) {
        if (!$DB->doQuery("ALTER TABLE `glpi_plugin_jumpserver_provisioning` MODIFY `last_action_date` TIMESTAMP NULL DEFAULT NULL")) {
            trigger_error('Unable to migrate last_action_date to TIMESTAMP: ' . $DB->error(), E_USER_WARNING);
        }
    }

    // --- v3.4.1: undo v3.4.0's DATETIME widening -------------------------
    // v3.4.0 widened these two to DATETIME to hold JumpServer's year-2123
    // sentinel. That was wrong on GLPI 11 - see the CREATE above for the
    // three core call sites - and v3.4.1 normalises the value at ingestion
    // instead. v3.4.0 was never deployed, so in practice this ALTER finds
    // the column already TIMESTAMP and is a no-op.
    //
    // It runs anyway, because "the bad zip was deleted so nobody can have
    // installed it" is precisely the reasoning 2.6.0 disproved. The ALTER is
    // idempotent and costs one statement on an empty table.
    if ($DB->tableExists('glpi_plugin_jumpserver_permrules')) {
        foreach (['date_start', 'date_expired'] as $policy_col) {
            if (!$DB->fieldExists('glpi_plugin_jumpserver_permrules', $policy_col)) {
                continue;
            }
            if (!$DB->doQuery("ALTER TABLE `glpi_plugin_jumpserver_permrules` MODIFY `$policy_col` TIMESTAMP NULL DEFAULT NULL")) {
                trigger_error(
                    sprintf('Unable to migrate permrules.%s to TIMESTAMP: %s', $policy_col, $DB->error()),
                    E_USER_WARNING
                );
            }
        }

        // Clear the cache ONCE, not on every upgrade.
        //
        // install() runs on each plugin update, so an unconditional DELETE
        // here empties the cache every time. entitlement_pull is WEEKLY, so
        // the pane would then read "Entitlements have never been collected"
        // on every Computer for up to seven days - the exact symptom this
        // whole line of work has been chasing, recreated by its own
        // migration. v3.4.1 wrote that DELETE correctly for its own case and
        // did not account for the next release calling install() again.
        //
        // The marker is read before the delete and written after it, so a
        // failed clear is retried on the next upgrade rather than being
        // recorded as done.
        $cfg = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);

        if (($cfg['entitlement_cache_cleared'] ?? '') !== '1') {
            // What v3.4.1 found here was written before the year-2123
            // sentinel was handled, so the run that wrote it aborted partway
            // and the set is silently partial. A partial rule set carries no
            // marker saying so - it renders as a complete answer to "who can
            // reach this machine".
            $cleared = $DB->doQuery("DELETE FROM `glpi_plugin_jumpserver_permrules`");
            if (!$cleared) {
                trigger_error('Unable to clear the permrules cache: ' . $DB->error(), E_USER_WARNING);
            }

            // The mapping rows point at rule ids that are now gone. Left
            // behind, a Computer would look mapped to nothing - which the
            // section cannot tell apart from "no permission reaches it".
            if ($DB->tableExists('glpi_plugin_jumpserver_assetperms')
                && !$DB->doQuery("DELETE FROM `glpi_plugin_jumpserver_assetperms`")) {
                trigger_error('Unable to clear the assetperms cache: ' . $DB->error(), E_USER_WARNING);
            }

            if ($cleared) {
                // Emptying a WEEKLY cache without rescheduling leaves the
                // pane blank until the next due run - up to seven days of a
                // screen that says the data was never collected. Whatever
                // empties the cache must also let the cron refill it, so the
                // hole is one tick wide instead of one week.
                if (!$DB->doQuery("UPDATE `glpi_crontasks` SET `lastrun` = NULL WHERE `name` = 'entitlement_pull'")) {
                    trigger_error('Unable to reschedule entitlement_pull: ' . $DB->error(), E_USER_WARNING);
                }

                Config::setConfigurationValues(
                    PluginIntegrasipamanConfig::CONFIG_CONTEXT,
                    ['entitlement_cache_cleared' => '1']
                );
            }
        }
    }

    // --- v3.6.0: is_production becomes a three-state classification -------
    // One-shot. install() runs on every plugin upgrade, so an unguarded
    // UPDATE here would overwrite an operator's classification with the old
    // boolean's mapping on every future release - and write a legal value
    // while doing it, so nothing would look wrong.
    $class_cfg = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);

    if (($class_cfg['asset_class_migrated'] ?? '') !== '1') {
        $class_ok = true;

        foreach ([
            ['glpi_plugin_jumpserver_assetmap',     'is_production'],
            ['glpi_plugin_jumpserver_command_eval', 'asset_is_production'],
        ] as [$tbl, $old_col]) {
            if (!$DB->tableExists($tbl)) {
                continue;
            }

            if (!$DB->fieldExists($tbl, 'asset_class')
                && !$DB->doQuery("ALTER TABLE `$tbl` ADD COLUMN `asset_class` VARCHAR(20) NOT NULL DEFAULT ''")) {
                trigger_error(sprintf('Unable to add %s.asset_class: %s', $tbl, $DB->error()), E_USER_WARNING);
                $class_ok = false;
                continue;
            }

            if (!$DB->fieldExists($tbl, $old_col)) {
                continue;
            }

            // assetmap keeps a third answer the boolean could not express.
            // resolve_method='unresolved' means no classification was ever
            // derived for that row, which is '' - not development. Mapping
            // it to 'development' would be a positive claim nobody made.
            //
            // This is not pedantry: resolveAll() SKIPS locked rows, so on
            // this VM 25 rows carrying unresolved + is_manual=1 would hold
            // that invented 'development' permanently and never appear in
            // the unclassified work queue. Found in production, after five
            // reviews passed.
            //
            // command_eval deliberately keeps the two-way mapping: it has
            // no resolve_method column, and every eval row is re-scored
            // after the generation bump below, so its rows self-correct.
            // assetmap's locked rows never do.
            $fill = $tbl === 'glpi_plugin_jumpserver_assetmap'
                ? "UPDATE `$tbl` SET `asset_class` = CASE"
                    . " WHEN `$old_col` = 1 THEN 'production'"
                    . " WHEN `resolve_method` = 'unresolved' THEN ''"
                    . " ELSE 'development' END"
                : "UPDATE `$tbl` SET `asset_class` = IF(`$old_col` = 1, 'production', 'development')";

            if (!$DB->doQuery($fill)) {
                trigger_error(sprintf('Unable to fill %s.asset_class: %s', $tbl, $DB->error()), E_USER_WARNING);
                $class_ok = false;
                continue;
            }

            // Dropping is what makes this irreversible, so it happens only
            // after the copy above reported success. MySQL drops the index
            // with the column; assetmap's replacement is added next.
            if (!$DB->doQuery("ALTER TABLE `$tbl` DROP COLUMN `$old_col`")) {
                trigger_error(sprintf('Unable to drop %s.%s: %s', $tbl, $old_col, $DB->error()), E_USER_WARNING);
                $class_ok = false;
            }
        }

        if ($class_ok && $DB->tableExists('glpi_plugin_jumpserver_assetmap')) {
            // A fresh install's CREATE TABLE already carries KEY asset_class
            // (see hook.php's schema block), so an unconditional ADD KEY
            // would raise MySQL error 1061 (duplicate key name) and log an
            // E_USER_NOTICE on every clean install - this only needs to run
            // for an upgrade from a table that predates the column.
            $idx_check = $DB->doQuery(
                "SHOW INDEX FROM `glpi_plugin_jumpserver_assetmap` WHERE Key_name = 'asset_class'"
            );
            $has_index = $idx_check && $DB->numrows($idx_check) > 0;

            if (!$has_index
                && !$DB->doQuery("ALTER TABLE `glpi_plugin_jumpserver_assetmap` ADD KEY `asset_class` (`asset_class`)")) {
                trigger_error('Unable to index assetmap.asset_class: ' . $DB->error(), E_USER_NOTICE);
            }
        }

        if ($class_ok) {
            // priorityNote() now returns different answers for the same
            // inputs, so every stored score was derived from a rule that no
            // longer exists. The generation is what makes the eval cron
            // redo them.
            //
            // $class_ok is shared across BOTH legs of the foreach above
            // (assetmap and command_eval), so if the assetmap leg succeeds
            // but the command_eval leg fails, this bump - and the
            // asset_class_migrated flag below - never fire, even though the
            // assetmap leg is what the bump is for. This is safe to leave:
            // the whole block re-runs on the next install() (the flag was
            // never set), fieldExists()/tableExists() make every step a
            // no-op where it already succeeded, and bumpGeneration() is
            // itself idempotent (it only ever advances a counter). No data
            // is harmed by re-running; the cost is a delayed bump, not a
            // wrong one.
            PluginIntegrasipamanCorrelation::bumpGeneration();

            Config::setConfigurationValues(
                PluginIntegrasipamanConfig::CONFIG_CONTEXT,
                ['asset_class_migrated' => '1']
            );
        }
    }

    // --- correlation data quality: add the two derived columns ------------
    // One-shot: install() runs on every upgrade. Nothing is backfilled -
    // both columns are recomputed by the crons that own them, and a
    // migration that guessed a value would be writing a verdict it has not
    // computed.
    $cq_cfg = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);

    // $assetmap_table, not the literal: the literal appeared four times
    // here, and one rename away this migration becomes a silent no-op -
    // fieldExists() on a table that no longer has that name returns false,
    // the ALTER fails, and the only trace is a trigger_error in a log nobody
    // reads during an upgrade. That is a different argument from tidiness.
    //
    // AFTER on both ALTERs so an upgraded install lands the two columns in
    // the same positions the CREATE above gives a fresh one - asset_class,
    // ip_mismatch, live_checked_at - and
    // glpi:database:check_schema_integrity --plugin=integrasipaman stays
    // quiet. live_checked_at goes AFTER `ip_mismatch`, not after
    // `asset_class`: the two ALTERs run in this order, so anchoring both to
    // asset_class would land live_checked_at BETWEEN asset_class and
    // ip_mismatch and reverse the pair against the CREATE - the opposite of
    // the point.
    if (($cq_cfg['corr_quality_migrated'] ?? '') !== '1'
        && $DB->tableExists($assetmap_table)) {
        $cq_ok = true;

        if (!$DB->fieldExists($assetmap_table, 'ip_mismatch')
            && !$DB->doQuery("ALTER TABLE `$assetmap_table` ADD COLUMN `ip_mismatch` TINYINT(1) NOT NULL DEFAULT 0 AFTER `asset_class`")) {
            trigger_error('Unable to add assetmap.ip_mismatch: ' . $DB->error(), E_USER_WARNING);
            $cq_ok = false;
        }

        if (!$DB->fieldExists($assetmap_table, 'live_checked_at')
            && !$DB->doQuery("ALTER TABLE `$assetmap_table` ADD COLUMN `live_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `ip_mismatch`")) {
            trigger_error('Unable to add assetmap.live_checked_at: ' . $DB->error(), E_USER_WARNING);
            $cq_ok = false;
        }

        if ($cq_ok) {
            Config::setConfigurationValues(
                PluginIntegrasipamanConfig::CONFIG_CONTEXT,
                ['corr_quality_migrated' => '1']
            );
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

    include_once(__DIR__ . '/inc/terminalpull.class.php');

    CronTask::Register(
        'PluginIntegrasipamanTerminalPull',
        'pull_terminal_activity',
        DAY_TIMESTAMP,
        [
            'comment' => 'Pull JumpServer terminal command & session records, then prune past the retention window',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    include_once(__DIR__ . '/inc/riskeval.class.php');

    CronTask::Register(
        'PluginIntegrasipamanRiskEval',
        'evaluate_commands',
        15 * MINUTE_TIMESTAMP,
        [
            'comment' => 'Classify JumpServer command records (high-risk, category, priority) into the command-eval table',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    CronTask::Register(
        'PluginIntegrasipamanCron',
        'provisioning_scan',
        DAY_TIMESTAMP,
        [
            'comment' => 'Refresh the PAMAN provisioning queue (eligibility, drift, assets marked for removal)',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    include_once(__DIR__ . '/inc/entitlement.class.php');

    CronTask::Register(
        'PluginIntegrasipamanEntitlement',
        'entitlement_pull',
        WEEK_TIMESTAMP,
        [
            'comment' => 'Collect JumpServer asset permissions (who may reach which asset)',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    include_once(__DIR__ . '/inc/profile.class.php');

    // Create plugin_integrasipaman_config and seed it per profile. Safe to
    // re-run: an existing right is seeded for nobody but a super-admin
    // profile (see PluginIntegrasipamanProfile::seedRights()).
    PluginIntegrasipamanProfile::seedRights();

    include_once(__DIR__ . '/inc/config.class.php');
    include_once(__DIR__ . '/inc/connection.class.php');

    // --- v3.11.0: the connection becomes a row, owned by an entity ---------
    // Suite entity rule, point 3: one JumpServer per subsidiary. The
    // connection leaves glpi_configs for a table of its own, and every data
    // table gets the connection id and an entity. See
    // inc/connection.class.php for what 3.11.0 deliberately leaves to 4.0.0.
    //
    // Written as one block for fresh installs and upgrades alike, after the
    // CREATEs above rather than inside them: those statements are pinned
    // line by line in tests/schema_conventions_test.php.
    $connections_table = 'glpi_plugin_jumpserver_connections';

    if (!$DB->tableExists($connections_table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        // access_key_secret and pbi_db_password hold GLPIKey ciphertext
        // (base64), never plain text; TEXT because its length follows the
        // secret's. UNIQUE on entities_id: one connection per entity.
        $query = "CREATE TABLE `$connections_table` (
            `id` INT {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL DEFAULT '',
            `entities_id` INT {$default_key_sign} NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `extra_entities` VARCHAR(255) NOT NULL DEFAULT '',
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `last_logs_pull` TIMESTAMP NULL DEFAULT NULL,
            `url` VARCHAR(255) NOT NULL DEFAULT '',
            `access_key_id` VARCHAR(191) NOT NULL DEFAULT '',
            `access_key_secret` TEXT NULL,
            `org_id` VARCHAR(64) NOT NULL DEFAULT '',
            `command_storage_id` VARCHAR(64) NOT NULL DEFAULT '',
            `pbi_db_host` VARCHAR(255) NOT NULL DEFAULT '',
            `pbi_db_port` VARCHAR(8) NOT NULL DEFAULT '',
            `pbi_db_name` VARCHAR(64) NOT NULL DEFAULT '',
            `pbi_db_user` VARCHAR(191) NOT NULL DEFAULT '',
            `pbi_db_password` TEXT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation}";

        if (!$DB->doQuery($query)) {
            trigger_error(sprintf('Unable to create table %s: %s', $connections_table, $DB->error()), E_USER_WARNING);
            return false;
        }
    }

    // v4.0.0: three columns the v3.11.0 table does not have. `name` labels a
    // connection now that there can be several; `extra_entities` is a comma
    // list of entity ids the connection covers beside its owner (production:
    // OT, which shares ICT's JumpServer); `last_logs_pull` is this
    // connection's own cursor for the audit-log pull, which until now was
    // the cron task's single `lastrun`.
    foreach ([
        'name'           => "VARCHAR(191) NOT NULL DEFAULT ''",
        'extra_entities' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'last_logs_pull' => 'TIMESTAMP NULL DEFAULT NULL',
        // v4.4.0: the rule settings this connection keeps for itself, as JSON
        // {setting key: value}. A setting not in it is inherited (see
        // inc/rulesettings.class.php). NULL = it overrides nothing.
        'rules'          => 'LONGTEXT NULL',
    ] as $connection_col => $connection_type) {
        if ($DB->fieldExists($connections_table, $connection_col, false)) {
            continue;
        }
        if (!$DB->doQuery("ALTER TABLE `$connections_table` ADD COLUMN `$connection_col` $connection_type")) {
            trigger_error(sprintf('Unable to add %s.%s: %s', $connections_table, $connection_col, $DB->error()), E_USER_WARNING);
            return false;
        }
    }

    // v4.4.2: the root entity is not an additional entity. 4.4.0 stored one on
    // production ("0": an empty multi-select selected the root by itself), and on
    // a connection with child entities that is the whole installation. Taken out
    // wherever it is, once; what the connection covers beside it is kept.
    foreach ($DB->request(['SELECT' => ['id', 'extra_entities'], 'FROM' => $connections_table]) as $extra_row) {
        $kept_ids = [];
        foreach (explode(',', (string) $extra_row['extra_entities']) as $extra_part) {
            $extra_part = trim($extra_part);
            if ($extra_part !== '' && ctype_digit($extra_part) && (int) $extra_part > 0) {
                $kept_ids[] = (int) $extra_part;
            }
        }
        $cleaned = implode(',', array_values(array_unique($kept_ids)));
        if ($cleaned !== (string) $extra_row['extra_entities']) {
            $DB->update($connections_table, ['extra_entities' => $cleaned], ['id' => (int) $extra_row['id']]);
        }
    }

    $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();
    $connection_fk    = PluginIntegrasipamanConnection::FK;

    foreach (array_keys(PluginIntegrasipamanConnection::STAMPED_TABLES) as $stamped) {
        // $usecache = false: fieldExists() caches SHOW COLUMNS per request,
        // and a --force install runs this twice in one.
        if (!$DB->tableExists($stamped) || $DB->fieldExists($stamped, $connection_fk, false)) {
            continue;
        }

        // Both default to 0 and are filled by the restamp below: 0 never
        // means "root" here, it means "not stamped yet".
        if (!$DB->doQuery(
            "ALTER TABLE `$stamped`"
            . " ADD COLUMN `$connection_fk` INT {$default_key_sign} NOT NULL DEFAULT 0,"
            . " ADD COLUMN `entities_id` INT {$default_key_sign} NOT NULL DEFAULT 0,"
            . " ADD KEY `$connection_fk` (`$connection_fk`),"
            . " ADD KEY `entities_id` (`entities_id`)"
        )) {
            trigger_error(sprintf('Unable to add the connection columns to %s: %s', $stamped, $DB->error()), E_USER_WARNING);
            return false;
        }
    }

    // The glpi_configs keys of <= 3.10.1 become the connection row, the two
    // secrets with them (still encrypted, never through Config: its item
    // methods log the value), and every existing row is stamped. Before the
    // seeding below; the keys are no longer in CONFIG_DEFAULTS, so nothing
    // puts them back.
    $problems = PluginIntegrasipamanConnection::migrate();
    if ($problems !== []) {
        trigger_error(
            'integrasipaman: the connection was not migrated: ' . implode('; ', $problems)
            . '. The old settings are untouched and the plugin reads as not configured;'
            . ' fix the cause and run the install again.',
            E_USER_WARNING
        );
    }

    // --- v4.0.0: several connections --------------------------------------
    // What identified a row across the whole table identifies it only
    // inside one JumpServer now: two bastions issue their own command,
    // session and rule ids, name their assets and users independently, and
    // each has its own node and platform ids. Every unique key therefore
    // gains the connection as its first column.
    //
    // After the migration above, so the rows already carry their connection.
    // A key that already has two columns is left alone, which is what makes
    // this safe to re-run.
    foreach ([
        ['glpi_plugin_jumpserver_commands',    'jms_id',              'jms_id'],
        ['glpi_plugin_jumpserver_sessions',    'jms_id',              'jms_id'],
        ['glpi_plugin_jumpserver_permrules',   'jms_id',              'jms_id'],
        ['glpi_plugin_jumpserver_assetmap',    'asset',               'asset'],
        ['glpi_plugin_jumpserver_usermap',     'jms_user',            'jms_user'],
        ['glpi_plugin_jumpserver_nodemap',     'computertypes_id',    'computertypes_id'],
        ['glpi_plugin_jumpserver_platformmap', 'operatingsystems_id', 'operatingsystems_id'],
    ] as [$scoped_table, $scoped_key, $scoped_col]) {
        if (!$DB->tableExists($scoped_table)) {
            continue;
        }

        $key_parts = $DB->doQuery("SHOW KEYS FROM `$scoped_table` WHERE Key_name = '$scoped_key'");
        if (!$key_parts || $DB->numrows($key_parts) !== 1) {
            continue;
        }

        if (!$DB->doQuery(
            "ALTER TABLE `$scoped_table`"
            . " DROP INDEX `$scoped_key`,"
            . " ADD UNIQUE KEY `$scoped_key` (`$connection_fk`, `$scoped_col`)"
        )) {
            trigger_error(sprintf('Unable to scope the unique key of %s to the connection: %s', $scoped_table, $DB->error()), E_USER_WARNING);
            return false;
        }
    }

    // From this release an asset is matched only to a Computer inside its
    // connection's scope. The entities of the Computers ALREADY matched
    // become additional entities of the connection, so no existing link is
    // dropped by the upgrade. Once: after that the scope is the admin's.
    $scope_cfg = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
    if (($scope_cfg['connection_scope_grandfathered'] ?? '') !== '1') {
        $grandfathered = PluginIntegrasipamanConnection::grandfatherScope();
        if ($grandfathered['root']) {
            trigger_error(
                'integrasipaman: some matched Computers are in the root entity, which no connection covers.'
                . ' Their assets will lose the link at the next correlation pass. Move those Computers to the'
                . ' owning entity, or make the root entity the connection\'s owner.',
                E_USER_WARNING
            );
        }
        Config::setConfigurationValues(
            PluginIntegrasipamanConfig::CONFIG_CONTEXT,
            ['connection_scope_grandfathered' => '1']
        );
    }

    // Seed defaults on a fresh install; on upgrade, add only keys absent
    // from the stored config (never overwrite an admin's value).
    $existing_config = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
    $missing_config  = array_diff_key(PluginIntegrasipamanConfig::CONFIG_DEFAULTS, $existing_config);

    if ($missing_config !== []) {
        // Seeds every default on a fresh install, and tops up only the
        // newly-added keys on an upgrade (e.g. the terminal-audit keys),
        // without overwriting anything the admin has already set.
        Config::setConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT, $missing_config);
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
    global $DB;

    include_once(__DIR__ . '/inc/cron.class.php');
    include_once(__DIR__ . '/inc/config.class.php');
    include_once(__DIR__ . '/inc/profile.class.php');

    PluginIntegrasipamanProfile::dropRights();

    CronTask::Unregister('PluginIntegrasipamanCron');
    CronTask::Unregister('PluginIntegrasipamanTerminalPull');
    CronTask::Unregister('PluginIntegrasipamanRiskEval');

    Config::deleteConfigurationValues(
        PluginIntegrasipamanConfig::CONFIG_CONTEXT,
        array_keys(PluginIntegrasipamanConfig::CONFIG_DEFAULTS)
    );

    // The connection holds credentials, so it does not outlive the plugin.
    // Any connection key still in glpi_configs goes with $DB: Config's
    // purge logs what it deletes.
    include_once(__DIR__ . '/inc/connection.class.php');
    PluginIntegrasipamanConnection::purgeLegacyConfig();
    $DB->doQuery('DROP TABLE IF EXISTS `' . PluginIntegrasipamanConnection::TABLE . '`');

    // The event & derived tables (glpi_plugin_jumpserver_logs,
    // glpi_plugin_jumpserver_commands, glpi_plugin_jumpserver_sessions,
    // glpi_plugin_jumpserver_command_eval, glpi_plugin_jumpserver_assetmap,
    // glpi_plugin_jumpserver_usermap) are
    // deliberately NOT dropped on uninstall. Their rows are pulled from
    // JumpServer's audit API and JumpServer rotates its own audit history
    // out over time, so a drop-and-reinstall would permanently lose every
    // event older than whatever JumpServer still retains at re-pull time.
    // Keeping the tables means a reinstall's first cron run (which requests
    // full history - see PluginIntegrasipamanCron::computeStartDate()) only
    // back-fills the gap, with source_id/jms_id dedup skipping everything
    // already stored. A real purge is a manual DROP TABLE, which is not
    // this hook's job.

    return true;
}

/**
 * item_add / item_update / item_delete / item_purge hook targets.
 *
 * These only ever touch the plugin's own table. The previous
 * implementation called the JumpServer REST API synchronously from inside
 * the Computer save, so a slow or unreachable bastion slowed - and could
 * fail - every Computer save in GLPI. Provisioning is now something a
 * person does from front/provisioning.php; these callbacks merely keep the
 * queue current so that page is accurate the moment it is opened.
 *
 * @param CommonDBTM $item The item GLPI just wrote.
 */
function plugin_integrasipaman_provisioning_item_add(CommonDBTM $item): void
{
    plugin_integrasipaman_provisioning_refresh($item);
}

function plugin_integrasipaman_provisioning_item_update(CommonDBTM $item): void
{
    plugin_integrasipaman_provisioning_refresh($item);
}

function plugin_integrasipaman_provisioning_item_delete(CommonDBTM $item): void
{
    plugin_integrasipaman_provisioning_refresh($item);
}

/**
 * item_purge fires after GLPI has already removed the row from
 * glpi_computers, so the shared refresh() helper cannot be reused here:
 * its first act is a Computer::getFromDB() that would now fail, and the
 * daily scan would never see this id again either (it sweeps
 * glpi_computers). If the asset already reached PAMAN it must still be
 * taken out, so this goes to PluginIntegrasipamanProvisioning::onComputerPurged()
 * instead, which works from the plugin's own table alone. Still local-only,
 * still wrapped so a failure here cannot break the purge.
 */
function plugin_integrasipaman_provisioning_item_purge(CommonDBTM $item): void
{
    if (!($item instanceof Computer)) {
        return;
    }

    include_once(__DIR__ . '/inc/provisioning.class.php');

    try {
        PluginIntegrasipamanProvisioning::onComputerPurged((int) ($item->fields['id'] ?? 0));
    } catch (\Throwable $e) {
        trigger_error(
            'Provisioning queue purge-handling failed for computer #'
            . (int) ($item->fields['id'] ?? 0) . ': ' . $e->getMessage(),
            E_USER_WARNING
        );
    }
}

/**
 * Shared body: Computers only, never throws, never touches the network.
 * A failure here must not be able to break saving a Computer.
 */
function plugin_integrasipaman_provisioning_refresh(CommonDBTM $item): void
{
    if (!($item instanceof Computer)) {
        return;
    }

    include_once(__DIR__ . '/inc/provisioning.class.php');
    include_once(__DIR__ . '/inc/nodemap.class.php');
    include_once(__DIR__ . '/inc/platformmap.class.php');

    try {
        PluginIntegrasipamanProvisioning::refreshOne((int) ($item->fields['id'] ?? 0));
    } catch (\Throwable $e) {
        trigger_error(
            'Provisioning queue refresh failed for computer #'
            . (int) ($item->fields['id'] ?? 0) . ': ' . $e->getMessage(),
            E_USER_WARNING
        );
    }
}
