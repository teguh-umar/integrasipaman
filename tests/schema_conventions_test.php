<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/schema_conventions_test.php
//
// Guards the DDL in hook.php against the two GLPI 11 schema conventions this
// plugin has now broken once each, in v3.0.1:
//
//   - a key column (`id` or anything ending `_id`) must be UNSIGNED;
//   - date/time columns must be TIMESTAMP, not the deprecated DATETIME,
//     unless the column holds a vendor POLICY date that can legitimately
//     fall past 2038 - see rule 2 for the outage that established this.
//
// Both surfaced as warnings on the Setup page after deploy, not as errors at
// install time, which is why nothing here caught them: the table creates
// cleanly, the plugin works, and GLPI quietly counts the violation on a
// screen nobody opens during a release. A test that reads the CREATE TABLE
// text is the only cheap way to see it before the VM does.
//
// This file reads hook.php as TEXT on purpose. Executing the installer would
// need a live GLPI and a database; the conventions are properties of the
// statements themselves, so the statements are what get read.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$hook = (string) file_get_contents(__DIR__ . '/../hook.php');

// --- rule 1: every key column is UNSIGNED ---------------------------------
// GLPI counts ANY column named `id` or `*_id` as a key, regardless of what
// it actually holds. platform_id holds a PAMAN platform number, not a GLPI
// foreign key, and GLPI counts it anyway - so the rule is about the NAME.
//
// `{$default_key_sign}` is DBConnection::getDefaultPrimaryKeySignOption(),
// which supplies the sign, so a column using it is compliant.
$signed = [];
foreach (preg_split('/\R/', $hook) as $n => $line) {
    if (!preg_match('/`([a-z_]*_id|id)`\s+(TINYINT|INT|BIGINT)\b(.*)$/i', $line, $m)) {
        continue;
    }
    $rest = $m[3];
    if (str_contains($rest, 'key_sign') || stripos($rest, 'UNSIGNED') !== false) {
        continue;
    }
    $signed[] = ($n + 1) . ': ' . trim($line);
}
check($signed === [],
      'every `id` / `*_id` column in the literal DDL is UNSIGNED'
    . ($signed === [] ? '' : " - signed: \n    " . implode("\n    ", $signed)));

// The two map tables are built from a loop, so their key column's type is a
// variable, not literal DDL. The sweep above cannot see it.
preg_match_all('/\[\s*\'glpi_plugin_jumpserver_\w+\',[^\]]*?\'(INT[^\']*)\'/', $hook, $loop);
$loop_types = $loop[1] ?? [];
check($loop_types !== [], 'the map-table loop declares its target column type');
foreach ($loop_types as $t) {
    check(stripos($t, 'UNSIGNED') !== false,
          "the map-table loop's INT target type is UNSIGNED (got '$t')");
}

// Changing that literal to 'INT UNSIGNED' silently breaks the default-value
// branch next to it, which tests for exact equality with 'INT' and would
// otherwise fall through to '' - giving `INT UNSIGNED NOT NULL DEFAULT ''`.
check(!preg_match("/\\\$target_type\s*===\s*'INT'/", $hook),
      "the loop's default-value branch does not compare \$target_type to exactly 'INT' "
    . '- an UNSIGNED type would fall through and default a numeric column to an empty string');

// --- rule 2: TIMESTAMP, always - and the range handled at ingestion -------
// This rule cost an outage, and then the obvious fix for that outage cost a
// second one on paper before it shipped. Both are recorded here because the
// wrong answer is more attractive than the right one.
//
// v3.3.0 shipped `permrules.date_expired` as TIMESTAMP, obeying the rule.
// JumpServer sends year 2123 as its "never expires" sentinel, TIMESTAMP
// stops at 2038-01-19, MySQL rejected the row, and the entitlement cron died
// on the FIRST rule it tried to store - the whole feature inert on a live VM
// while every test here passed.
//
// v3.4.0 widened the two columns to DATETIME. That is WRONG on GLPI 11, and
// the three reasons are all in core, checked against 11.0.5:
//
//   - Central.php:562 calls getTzIncompatibleTables() with no argument, and
//     $exclude_plugins DEFAULTS TO FALSE (DBmysql.php:777). A plugin table
//     holding a DATETIME column raises the deprecated-datetime Setup warning
//     permanently - the warning v3.0.1 was cut to clear.
//   - migration:timestamps (TimestampsCommand.php:79) iterates that SAME
//     list and MODIFYs each column to TIMESTAMP. So the first person to
//     follow the warning's own advice converts the column back and
//     re-breaks the cron.
//   - DBmysql.php:2166 warns on a DATETIME in any CREATE once no core table
//     uses one (allow_datetime goes false at :2198) - this VM's state.
//
// So DATETIME is not an escape hatch on GLPI 11 at all, for any column. The
// range problem is real but belongs at ingestion: Entitlement::sqlDate()
// normalises anything outside the TIMESTAMP range to NULL, which for an
// expiry date says exactly what the sentinel says. tests/entitlement_pull_
// test.php feeds the real 2123 string through pullRules() end to end.
//
// The list below is therefore EMPTY, and that is the finding, not an
// oversight. It stays as a list so that a future exception has to be written
// down and argued rather than simply typed into the DDL.
$datetime_required = [];

// Keyed by table.column throughout, NOT by column name: `date_start` exists
// in two tables. They need the same type today, but a name-keyed rule cannot
// express a difference at all, and v3.4.0 proved the difference can arise.

// Resolve `CREATE TABLE `$foo_table`` to the literal table name assigned to
// $foo_table earlier in the file, then attribute each column to the CREATE
// it sits inside.
$vars = [];
// The quote around the literal is matched with `.` rather than an escaped
// quote: this file has already lost one backslash to a generator, and the
// pattern is unambiguous without it.
preg_match_all('/\$(\w+_table)\s*=\s*.(glpi_plugin_jumpserver_\w+)./', $hook, $vm, PREG_SET_ORDER);
foreach ($vm as $v) {
    $vars[$v[1]] = $v[2];
}
check($vars !== [], 'the table-name variables in the DDL resolve to literal names');

// The two map tables share ONE CREATE inside a foreach, so `$map_table`
// names both of them in turn. They are resolved from the loop's own list
// and the shared CREATE is attributed to BOTH, rather than exempted -
// an exemption here would be a hole in the rule big enough to hide a
// column in.
$ddl_only = substr($hook, 0, (int) strpos($hook, 'schema-convention migration'));
preg_match_all('/.(glpi_plugin_jumpserver_(?:node|platform)map)./', $ddl_only, $mm);
$vars['map_table'] = $mm[1] ?? [];
check(count($vars['map_table']) === 2,
      'both map tables are resolved from the loop list (got '
    . count($vars['map_table']) . ')');

$columns = [];   // "table.column" => [line, type]
$current = [];
foreach (preg_split('/\R/', $hook) as $n => $line) {
    if (preg_match('/CREATE TABLE `\$(\w+_table)`/', $line, $m)) {
        $resolved = $vars[$m[1]] ?? ('UNRESOLVED:' . $m[1]);
        $current  = is_array($resolved) ? $resolved : [$resolved];
        continue;
    }
    if ($current !== [] && preg_match('/`([a-z_]+)`\s+(DATETIME|TIMESTAMP)\b/i', $line, $m)) {
        foreach ($current as $t) {
            $columns[$t . '.' . $m[1]] = [$n + 1, strtoupper($m[2])];
        }
    }
}
check(!preg_grep('/^UNRESOLVED:/', array_keys($columns)),
      'every CREATE TABLE resolves to a known table name');

// Without this, EVERY check below passes on an empty parse. The first run of
// this rewrite did exactly that: a stray control character in the pattern
// above matched nothing, $columns came back empty, and "every DATETIME is an
// argued exception" reported PASS because there were no DATETIMEs to find.
//
// A count floor alone would not have caught it either if the floor were set
// from a wrong run, so both TYPES must also be seen: the alternation in the
// pattern has two branches and a parse that matched only one is broken in a
// way a total would hide.
$seen = array_column($columns, 1);
check(count($columns) >= 20,
      'the DDL parse found the date columns it is about to judge (got '
    . count($columns) . ')');
// There is no DATETIME left in the DDL, so the alternation's other branch
// cannot be exercised by the file itself. It is exercised against a
// fabricated line instead: without this, a pattern that had silently
// stopped matching DATETIME would report a clean sweep forever.
check(preg_match('/`([a-z_]+)`\s+(DATETIME|TIMESTAMP)\b/i',
                 '            `probe_col` DATETIME NULL DEFAULT NULL,', $probe) === 1
      && strtoupper($probe[2]) === 'DATETIME',
      'the column pattern still detects a DATETIME when one is present');
check(in_array('TIMESTAMP', $seen, true),
      'and the parse actually read TIMESTAMP columns from the DDL');

// Every DATETIME in the DDL must be on the list, with its reason.
$unlisted = [];
foreach ($columns as $key => [$line, $type]) {
    if ($type === 'DATETIME' && !isset($datetime_required[$key])) {
        $unlisted[] = "$line: $key";
    }
}
check($unlisted === [],
      'every DATETIME column is an argued exception, not a leftover'
    . ($unlisted === [] ? '' : " - unlisted: 
    " . implode("
    ", $unlisted)));

// And the reverse: a listed column that is no longer DATETIME means someone
// "fixed" it back to TIMESTAMP. For date_expired that re-breaks the cron on
// the first rule it reads, exactly as it did in v3.3.0 - so this assertion
// is the regression guard for that outage, not bookkeeping.
foreach ($datetime_required as $key => $why) {
    $type = $columns[$key][1] ?? null;
    check($type === 'DATETIME',
          "`$key` is DATETIME - $why (got " . ($type ?? 'no such column') . ')');
}

// That loop is currently empty and so asserts nothing. These do not: the two
// columns the whole incident turned on are named, and named by table, so a
// re-widening to DATETIME fails here rather than on the VM's Setup page.
foreach ([
    'glpi_plugin_jumpserver_permrules.date_start',
    'glpi_plugin_jumpserver_permrules.date_expired',
] as $key) {
    $type = $columns[$key][1] ?? null;
    check($type === 'TIMESTAMP',
          "`$key` is TIMESTAMP, with the range handled in sqlDate() (got "
        . ($type ?? 'no such column') . ')');
}

// And the normaliser has to exist, or the columns above are back to v3.3.0.
$ent = (string) file_get_contents(__DIR__ . '/../inc/entitlement.class.php');
check(str_contains($ent, 'TIMESTAMP_MAX'),
      'Entitlement declares the TIMESTAMP ceiling it normalises against');
check(preg_match('/\$ts > self::TIMESTAMP_MAX/', $ent) === 1,
      'and sqlDate() compares the parsed timestamp against it');

// The five columns the v3.4.0 conversion was queued for. Named individually
// so that converting them cannot be claimed by a rule that would also be
// satisfied by deleting them.
foreach ([
    'glpi_plugin_jumpserver_commands.executed_at',
    'glpi_plugin_jumpserver_sessions.date_start',
    'glpi_plugin_jumpserver_sessions.date_end',
    'glpi_plugin_jumpserver_assetmap.last_resolved_at',
    'glpi_plugin_jumpserver_usermap.last_resolved_at',
] as $key) {
    $type = $columns[$key][1] ?? null;
    check($type === 'TIMESTAMP',
          "`$key` was converted to TIMESTAMP (got " . ($type ?? 'no such column') . ')');
}

// --- the upgrade path for installs that already have the wrong types ------
// A fresh install gets the corrected CREATE TABLE. Everything already on a
// VM keeps the old column until something alters it, so the warning would
// persist on exactly the instance that reported it.
// Match WITHIN one statement: [^\n]* and no /s flag. The first version of
// these two used '/ALTER TABLE.*last_action_date/is', where .* with /s spans
// the whole file - any ALTER TABLE anywhere, followed eventually by that
// string, satisfied it. It passed while no migration existed at all.
check(preg_match('/ALTER TABLE[^\n]*MODIFY[^\n]*INT UNSIGNED NOT NULL DEFAULT 0/i', $hook) === 1,
      'an ALTER migrates the key column to INT UNSIGNED on existing installs');
check(preg_match('/ALTER TABLE[^\n]*last_action_date[^\n]*TIMESTAMP/i', $hook) === 1,
      'an ALTER migrates last_action_date to TIMESTAMP on existing installs');

// v3.4.0. The corrected CREATE TABLE only reaches a FRESH install. The VM
// that reported this bug already has `permrules` with the TIMESTAMP columns,
// and tableExists() makes the installer skip the CREATE entirely - so
// without these ALTERs the deploy changes nothing on the only instance where
// the cron is actually dying.
//
// Matched within one statement, no /s flag - see the note above the UNSIGNED
// checks for the version of this that passed while no migration existed.
// The column name is a variable in the loop, so the statement cannot carry
// it - the same shape as the UNSIGNED migration above. Two assertions stand
// in for one: the ALTER must target permrules with DATETIME, and the loop it
// runs in must name both columns. Either alone would pass while the other
// half was wrong.
check(preg_match('/ALTER TABLE[^\n]*permrules[^\n]*MODIFY[^\n]*TIMESTAMP/i', $hook) === 1,
      'an ALTER puts a permrules column back to TIMESTAMP on any v3.4.0 install');
$policy_loop = "foreach (['date_start', 'date_expired'] as \$policy_col)";
check(str_contains($hook, $policy_loop),
      'and that ALTER runs for both policy columns, in that loop');

// The rows cached under the old type are unusable for the very reason the
// type is changing: any rule whose expiry is past 2038 was REJECTED, so the
// table holds a silently partial set and a partial set with no marker reads
// as a complete one. Widening the column does not bring those rows back, so
// the migration clears the cache and lets the next cron rebuild it whole.
check(preg_match('/(TRUNCATE|DELETE FROM)[^\n]*permrules/i', $hook) === 1,
      'the migration clears the partially-populated rule cache so the cron refills it');

// v3.4.2. That clear must happen EXACTLY ONCE. install() runs on every
// upgrade, so an unconditional DELETE wipes the cache each time the plugin
// is updated - and because entitlement_pull is WEEKLY, the pane then reads
// "Entitlements have never been collected" on every Computer for up to seven
// days. That is the same symptom the entitlement work has been chasing all
// along, reintroduced by the fix's own migration.
//
// Caught before v3.4.2 shipped, from the live numbers: 2512 rows collected
// at 13:00 would have been destroyed by the very upgrade meant to display
// them.
check(str_contains($hook, 'entitlement_cache_cleared'),
      'the cache clear is keyed to a one-shot marker, not run on every upgrade');
$clear_at = strpos($hook, 'DELETE FROM `glpi_plugin_jumpserver_permrules`');
check($clear_at !== false, 'the clear is present at all');
$before = substr($hook, 0, (int) $clear_at);
check(str_contains($before, 'entitlement_cache_cleared'),
      'and the marker is read BEFORE the delete, not merely written after it');

// Clearing without rescheduling leaves exactly the hole described above, so
// the two belong together: whatever empties the cache must also let the
// weekly cron refill it at the next tick.
check(preg_match('/lastrun[^\n]*NULL|NULL[^\n]*lastrun/i', $hook) === 1,
      'clearing the cache also resets entitlement_pull so it refills at the next tick');

// The ALTER above runs inside a loop, so the column name is a variable and
// the table coverage lives in the loop's list, not in the statement.
//
// Slice from the migration's own marker first. An earlier version matched
// /foreach\s*\(\[(.*?)\]\s*as\s*\[\$fix_table/s against the whole file:
// non-greedy shrinks the capture but the START still lands on the FIRST
// `foreach ([` in hook.php - the map-table loop - so the capture swallowed
// that loop too and counted its 'platform_id' as well.
$mig_at = strpos($hook, 'schema-convention migration');
check($mig_at !== false, 'the migration block is present and marked');
$mig = substr($hook, (int) $mig_at);

preg_match('/foreach\s*\(\[(.*?)\]\s*as\s*\[\$fix_table/s', $mig, $fix_loop);
$fix_list = $fix_loop[1] ?? '';
check(str_contains($fix_list, 'glpi_plugin_jumpserver_provisioning'),
      'the UNSIGNED migration covers the provisioning table');
check(str_contains($fix_list, 'glpi_plugin_jumpserver_platformmap'),
      'the UNSIGNED migration covers the platformmap table');
check(substr_count($fix_list, "'platform_id'") === 2,
      'exactly the two platform_id columns are listed (got ' . substr_count($fix_list, "'platform_id'") . ')');

// Converting a negative value to UNSIGNED silently repoints the row, so the
// migration must look before it leaps.
check(str_contains($mig, "['<', 0]"),
      'the migration counts negative values BEFORE converting, rather than trusting the data');
check(preg_match('/left as SIGNED/i', $mig) === 1,
      'and leaves the column alone, saying so, when it finds any');

// An ALTER against a table that does not exist yet is a fatal on a fresh
// install, where the corrected CREATE has already produced the right types.
check(preg_match('/tableExists[^\n]*\n[^\n]*ALTER TABLE/i', $hook) === 1
      || substr_count($hook, 'tableExists') >= 3,
      'the migration is guarded by a table-existence check');

// --- v3.6.0: is_production becomes a three-state classification ----------
// The old column is migrated and DROPPED in the same release. A column that
// still exists but is no longer authoritative is the "looks right, is stale"
// hazard this plugin has paid for three times.
check(!str_contains($hook, '`is_production` TINYINT'),
      'the assetmap DDL no longer declares is_production');
check(!str_contains($hook, '`asset_is_production` TINYINT'),
      'the eval DDL no longer declares asset_is_production');
check(substr_count($hook, "            `asset_class` VARCHAR(20) NOT NULL DEFAULT '',") === 2,
      'both CREATE statements declare asset_class (got '
    . substr_count($hook, "            `asset_class` VARCHAR(20) NOT NULL DEFAULT '',") . ')');
check(str_contains($hook, 'KEY `asset_class` (`asset_class`)'),
      'assetmap keeps an index on the column the unclassified sweep filters on');

// install() runs on EVERY upgrade. Without a marker the UPDATE below re-runs
// each time and overwrites an operator's classification with the old
// boolean's mapping - silently, because the value it writes is a legal one.
check(str_contains($hook, 'asset_class_migrated'),
      'the classification migration is keyed to a one-shot marker');
$mig_at = strpos($hook, "UPDATE `\$tbl` SET `asset_class`");
check($mig_at !== false, 'the migration UPDATE is present');
check(str_contains(substr($hook, 0, (int) $mig_at), 'asset_class_migrated'),
      'and the marker is read BEFORE the UPDATE, not only written after it');

// Dropping the old column is the step that makes this irreversible, so it
// must be the LAST thing, after the value has been copied.
// Matches BOTH legs. It used to search for "SET `asset_class` = IF(",
// which after the three-way CASE below exists only on the command_eval
// branch - so the ordering check silently covered one table instead of
// two while still reporting PASS.
$copy_at = strpos($hook, "SET `asset_class` =");
$drop_at = strpos($hook, 'DROP COLUMN');
check($copy_at !== false && $drop_at !== false && $copy_at < $drop_at,
      'the value is copied before the old column is dropped');

// priorityNote() changes, so every stored score is now derived from a rule
// that no longer exists. Without a bump the report keeps the old numbers.
check(str_contains($hook, 'bumpGeneration'),
      'the migration bumps the evaluation generation so stored scores are redone');

// --- an unresolved row is NOT development ------------------------------
// Found in production, not in review: 25 assetmap rows carry
// resolve_method='unresolved' AND is_manual=1. resolveAll() skips locked
// rows, so whatever the migration writes to them is permanent. Mapping
// is_production=0 to 'development' would turn 25 assets that explicitly
// say "nobody could determine this" into a positive claim that they are
// development - and keep them out of the unclassified work queue for good.
//
// 'unresolved' is precisely the marker that no classification was ever
// derived, and that means '' - which is a state the old boolean could not
// express, so this is not preserving meaning, it is recovering it.
//
// Only assetmap needs this. command_eval has no resolve_method, and every
// eval row is re-scored after the generation bump anyway, so its rows
// self-correct; assetmap's locked rows never do.
$mig_from = strpos($hook, 'v3.6.0: is_production becomes a three-state');
check($mig_from !== false, 'the classification migration block is present and marked');
$mig_block = substr($hook, (int) $mig_from, 3000);

check(str_contains($mig_block, "WHEN `resolve_method` = 'unresolved' THEN ''"),
      'the assetmap migration maps an unresolved row to the empty class, not to development');
check(preg_match('/CASE[^;]*WHEN[^;]*THEN[^;]*ELSE[^;]*END/s', $mig_block) === 1,
      'it does so with a three-way CASE rather than the two-way IF');

// The eval table must NOT get the same clause: it has no resolve_method
// column, so the statement would fail outright.
check(substr_count($mig_block, "`resolve_method` = 'unresolved'") === 1,
      'and only assetmap gets it - command_eval has no resolve_method column (got '
    . substr_count($mig_block, "`resolve_method` = 'unresolved'") . ')');


// --- correlation data quality: two derived columns ----------------------
// Scoped to the assetmap CREATE, not the whole file: the migration's
// ALTER carries the same literal, so a whole-file search would stay
// green with the column gone from the CREATE - an assertion passing for
// a reason unrelated to its own message.
$cq_from   = strpos($hook, 'CREATE TABLE `$assetmap_table`');
$cq_end    = $cq_from === false ? false : strpos($hook, '";', $cq_from);
$cq_create = ($cq_from === false || $cq_end === false) ? '' : substr($hook, $cq_from, $cq_end - $cq_from);
check(strlen($cq_create) > 200 && str_contains($cq_create, '`asset_class`'),
      'the assetmap CREATE was located, so the two checks below are not vacuous');

check(str_contains($cq_create, '`ip_mismatch` TINYINT(1) NOT NULL DEFAULT 0'),
      'assetmap declares ip_mismatch');
check(str_contains($cq_create, '`live_checked_at` TIMESTAMP NULL DEFAULT NULL'),
      'assetmap declares live_checked_at');

// live_checked_at is a TIMESTAMP and not a boolean ON PURPOSE: a boolean
// cannot tell "absent from PAMAN" from "never asked", and those must never
// be confused - the first is a finding, the second is our own ignorance.
check(!str_contains($hook, '`is_live`'),
      'there is no is_live boolean - the timestamp carries both states');

check(str_contains($hook, 'corr_quality_migrated'),
      'the column migration is keyed to a one-shot marker');
$cq_at = strpos($hook, 'ADD COLUMN `ip_mismatch`');
check($cq_at !== false, 'the migration adds ip_mismatch to existing installs');
check(str_contains(substr($hook, 0, (int) $cq_at), 'corr_quality_migrated'),
      'and the marker is read BEFORE the ALTER, not only written after it');

// Both are derived every run, so there is nothing to backfill. A migration
// that tried would be writing a verdict it has not computed.
check(!preg_match('/UPDATE[^\n]*SET[^\n]*`ip_mismatch`\s*=\s*1/i', $hook),
      'the migration does not invent a mismatch value it has not computed');

// --- v4.8.0: group membership, the Phase 1 gap ---------------------------
// Two tables, not one: an empty group still needs a row (name and count), and
// five groups on the instance the probe ran against have no members at all.
// A join table alone would make those five disappear from the group list.
//
// The marker for "membership has been collected" lives in `usergroups` with
// an empty `jms_id`, the same NONE_MARKER idea assetperms uses. Without it an
// empty-but-successful pull would be indistinguishable from a pull that never
// ran, and the report would have to warn forever on an instance with no
// groups.
foreach ([
    'usergroups'   => 'glpi_plugin_jumpserver_usergroups',
    'groupmembers' => 'glpi_plugin_jumpserver_groupmembers',
] as $var => $table_name) {
    check(preg_match(
        '/\$' . $var . "_table\s*=\s*'" . preg_quote($table_name, '/') . "';\s*\n\s*"
        . 'if \(!\$DB->tableExists\(\$' . $var . '_table\)\)/',
        $hook
    ) === 1, "$table_name is created inside its own tableExists() guard");
}

$groups_create = '';
$groups_at     = strpos($hook, 'CREATE TABLE `$usergroups_table`');
if ($groups_at !== false) {
    $groups_end    = strpos($hook, '";', $groups_at);
    $groups_create = $groups_end === false ? '' : substr($hook, $groups_at, $groups_end - $groups_at);
}
check($groups_create !== '', 'the user-group CREATE was located');
check(str_contains($groups_create, '`users_amount` INT UNSIGNED NOT NULL DEFAULT 0'),
      'a group stores the member count JumpServer reports, so an empty group can still be shown');
check(str_contains($groups_create, '`date_pulled` TIMESTAMP NULL DEFAULT NULL'),
      'a group carries the pull time as TIMESTAMP, never DATETIME');
check(str_contains($groups_create, '`plugin_jumpserver_connections_id` INT {$default_key_sign}'),
      'a group carries the connection FK');
check(str_contains($groups_create, '`entities_id` INT {$default_key_sign}'),
      'a group carries an entity');
check(str_contains($groups_create, 'UNIQUE KEY `connection_group` (`plugin_jumpserver_connections_id`, `jms_id`)'),
      'a group id is unique within its connection, not across two JumpServers');

$members_create = '';
$members_at     = strpos($hook, 'CREATE TABLE `$groupmembers_table`');
if ($members_at !== false) {
    $members_end    = strpos($hook, '";', $members_at);
    $members_create = $members_end === false ? '' : substr($hook, $members_at, $members_end - $members_at);
}
check($members_create !== '', 'the group-member CREATE was located');
foreach ([
    '`user_groups_jms_id` VARCHAR(64) NOT NULL DEFAULT \'\'',
    '`user_jms_id` VARCHAR(64) NOT NULL DEFAULT \'\'',
    '`user_login` VARCHAR(191) NOT NULL DEFAULT \'\'',
    '`user_display` VARCHAR(191) NOT NULL DEFAULT \'\'',
] as $column) {
    check(str_contains($members_create, $column),
          'a membership stores the JumpServer identifiers the report matches on: ' . $column);
}
check(str_contains($members_create, '`date_pulled` TIMESTAMP NULL DEFAULT NULL'),
      'a membership carries the pull time as TIMESTAMP, never DATETIME');
check(str_contains($members_create, '`plugin_jumpserver_connections_id` INT {$default_key_sign}'),
      'a membership carries the connection FK');
check(str_contains($members_create, '`entities_id` INT {$default_key_sign}'),
      'a membership carries an entity');
check(str_contains($members_create, 'UNIQUE KEY `connection_group_user` (`plugin_jumpserver_connections_id`, `user_groups_jms_id`, `user_jms_id`)'),
      'a member appears once per group within its connection');
check(str_contains($members_create, 'KEY `user_jms_id` (`user_jms_id`)'),
      'members are indexable by JumpServer user id, the per-user report path');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
