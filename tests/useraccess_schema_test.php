<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/useraccess_schema_test.php
//
// Reads hook.php as TEXT on purpose (same as schema_conventions_test.php):
// executing the installer needs a live GLPI and database.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$hook = (string) file_get_contents(__DIR__ . '/../hook.php');

// --- new columns, each guarded by fieldExists() ----------------------------
foreach ([
    'permrules'  => 'protocols',
    'hosts'      => 'protocols',
    'usergroups' => 'comment',
] as $tbl => $col) {
    check(
        (bool) preg_match(
            '/fieldExists\(\s*\$' . $tbl . '_table\s*,\s*\x27' . $col . '\x27\s*\)[^;]*?ADD COLUMN `' . $col
            . '` VARCHAR\(255\) NOT NULL DEFAULT \x27\x27/s',
            $hook
        ),
        "$tbl.$col: fieldExists-guarded ADD COLUMN, VARCHAR(255) NOT NULL DEFAULT ''"
    );
}

// --- new tables -------------------------------------------------------------
$tables = [
    'jmsusers' => [
        'jms_id' => 'VARCHAR(64)', 'username' => 'VARCHAR(191)', 'name' => 'VARCHAR(191)',
        'system_role' => 'VARCHAR(191)', 'date_pulled' => 'TIMESTAMP NULL',
    ],
    'ruleassets' => [
        'permrules_jms_id' => 'VARCHAR(64)', 'jms_asset_id' => 'VARCHAR(64)',
        'date_refreshed' => 'TIMESTAMP NULL',
    ],
    'accessusers' => [
        'user_jms_id' => 'VARCHAR(64)', 'name' => 'VARCHAR(191)', 'username' => 'VARCHAR(191)',
        'system_role' => 'VARCHAR(191)', 'group_names' => 'TEXT', 'asset_names' => 'TEXT',
        'account_names' => 'TEXT', 'assets_detail' => 'MEDIUMTEXT',
        'assets_count' => 'INT UNSIGNED', 'date_built' => 'TIMESTAMP NULL',
    ],
    'accessgroups' => [
        'jms_id' => 'VARCHAR(64)', 'name' => 'VARCHAR(191)', 'comment' => 'VARCHAR(255)',
        'users_amount' => 'INT UNSIGNED', 'rules_count' => 'INT UNSIGNED',
        'assets_count' => 'INT UNSIGNED', 'date_built' => 'TIMESTAMP NULL',
    ],
];
$uniques = [
    'jmsusers'     => '(`plugin_jumpserver_connections_id`, `jms_id`)',
    'ruleassets'   => '(`plugin_jumpserver_connections_id`, `jms_asset_id`, `permrules_jms_id`)',
    'accessusers'  => '(`plugin_jumpserver_connections_id`, `user_jms_id`)',
    'accessgroups' => '(`plugin_jumpserver_connections_id`, `jms_id`)',
];
foreach ($tables as $t => $cols) {
    $full = "glpi_plugin_jumpserver_$t";
    check((bool) preg_match('/if \(!\$DB->tableExists\(\$' . $t . '_table\)\)/', $hook)
        && strpos($hook, "\${$t}_table = '$full'") !== false,
        "$t: guarded by tableExists");
    $start = strpos($hook, "CREATE TABLE `\${$t}_table`");
    $end   = $start === false ? false : strpos($hook, 'ENGINE=InnoDB', $start);
    $ddl   = $start === false ? '' : substr($hook, $start, $end - $start);
    check($ddl !== '', "$t: has CREATE TABLE");
    foreach ($cols as $c => $type) {
        check(strpos($ddl, "`$c` $type") !== false, "$t.$c is $type");
    }
    check(strpos($ddl, 'UNIQUE KEY') !== false && strpos($ddl, $uniques[$t]) !== false, "$t: UNIQUE $uniques[$t]");
    check(strpos($ddl, 'KEY `entities_id` (`entities_id`)') !== false, "$t: KEY entities_id");
    check(substr_count($ddl, '{$default_key_sign}') >= 3, "$t: id, FK and entities_id use default_key_sign");
    check(!preg_match('/`groups`/', $ddl), "$t: no column named groups");
}
check(strpos($hook, 'KEY `permrules_jms_id`') !== false, 'ruleassets: KEY permrules_jms_id');
check(strpos($hook, 'KEY `name` (`name`)') !== false, 'accessusers: KEY name');

// --- default display preferences -------------------------------------------
check(strpos($hook, 'glpi_displaypreferences') !== false && preg_match("/'users_id'\s*=>\s*0/", $hook) === 1,
    'displaypreferences rows are written for users_id 0');
check((bool) preg_match('/countElementsInTable\(\s*\'glpi_displaypreferences\'/', $hook)
    || (bool) preg_match('/request\(.*glpi_displaypreferences/s', $hook),
    'an "already has rows" check precedes the insert');
check(strpos($hook, 'PluginIntegrasipamanAccessUser') !== false
    && strpos($hook, 'PluginIntegrasipamanAccessGroup') !== false, 'both itemtypes get defaults');

echo $fails ? "\n$fails FAILED\n" : "\nOK\n";
exit($fails ? 1 : 0);
