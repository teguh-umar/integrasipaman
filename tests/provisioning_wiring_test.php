<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/provisioning_wiring_test.php
//
// Guards the class of bug that shipped in v2.6.0: a feature that exists in
// one call site and not in the other two, reporting success and doing
// nothing. Here the call sites are the class constant, the CREATE TABLE in
// the installer, and the registration in setup.php - they must agree.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$root  = __DIR__ . '/..';
$hook  = (string) file_get_contents($root . '/hook.php');
$setup = (string) file_get_contents($root . '/setup.php');
$prov  = (string) file_get_contents($root . '/inc/provisioning.class.php');
$sections = (string) file_get_contents($root . '/inc/assetsections.class.php');

// --- the table is created, and named the same everywhere --------------
check(strpos($hook, "CREATE TABLE `\$provisioning_table`") !== false,
      'hook.php creates the provisioning table');
check(strpos($hook, "\$provisioning_table = 'glpi_plugin_jumpserver_provisioning';") !== false,
      'the installer uses the same table name as the class constant');
check(strpos($prov, "public const TABLE = 'glpi_plugin_jumpserver_provisioning';") !== false,
      'the class constant carries that table name');
check(strpos($hook, 'if (!$DB->tableExists($provisioning_table))') !== false,
      'the CREATE is guarded by tableExists - install is re-runnable');

// --- every column the code writes actually exists ---------------------
preg_match('/CREATE TABLE `\$provisioning_table` \((.*?)\) ENGINE=InnoDB/s', $hook, $m);
$ddl = $m[1] ?? '';
foreach ([
    'computers_id', 'jumpserver_asset_id', 'status', 'node_id', 'node_label',
    'platform_id', 'platform_label', 'payload_hash', 'drift_fields',
    'tickets_id', 'last_actor_id', 'last_action', 'last_action_date', 'last_error',
] as $col) {
    check(strpos($ddl, "`{$col}`") !== false, "the table has a `{$col}` column");
}
check(strpos($ddl, 'UNIQUE KEY `computers_id`') !== false,
      'computers_id is UNIQUE - one provisioning record per asset, which is what makes dedup possible');

// --- GLPI knows the class --------------------------------------------
check(strpos($setup, "Plugin::registerClass('PluginIntegrasipamanProvisioning'") !== false,
      'setup.php registers the provisioning class');

// --- the six settings exist at all three call sites -------------------
$config = (string) file_get_contents($root . '/inc/config.class.php');
$form   = (string) file_get_contents($root . '/front/config.form.php');

foreach ([
    'provisioning_eligible_types', 'provisioning_eligible_states',
    'provisioning_dispose_states', 'provisioning_eligible_entities',
    'provisioning_ip_prefixes', 'provisioning_ticket_statuses',
] as $key) {
    check(strpos($config, "'{$key}'") !== false,              "{$key} is in CONFIG_DEFAULTS / the form");
    check(preg_match('/\$' . $key . '\s*=/', $form) === 1,    "{$key} is read on save in config.form.php");
    check(preg_match("/'{$key}'\s*=>\s*\\\$" . $key . '/', $form) === 1,
          "{$key} is written back by setConfigurationValues()");
}

// A setting that renders and reads but is never stored is the v2.6.0 bug
// again: the page reports success and the value silently reverts.
check(strpos($form, 'static fn ($n) => $n >= 0') !== false,
      'the entity filter keeps id 0 - Root Entity is a legitimate selection');

// The save path keeps entity 0; so must the render path, or a saved Root
// Entity shows as unselected and is dropped by the next save.
preg_match("/Dropdown::showFromArray\('provisioning_eligible_entities'.*?\]\);/s", $config, $entity_block);
check(strpos($entity_block[0] ?? '', '$n >= 0') !== false,
      'the entities dropdown renders id 0 as selected - a bare array_filter() would drop Root Entity');

// --- the two mapping tables ------------------------------------------
foreach (['glpi_plugin_jumpserver_nodemap', 'glpi_plugin_jumpserver_platformmap'] as $map_table) {
    check(strpos($hook, "'{$map_table}'") !== false, "hook.php creates {$map_table}");
}
check(strpos($hook, 'UNIQUE KEY `{$source_col}`') !== false,
      'one mapping row per source - two rows for one type would make the node depend on row order');

foreach (['PluginIntegrasipamanNodeMap', 'PluginIntegrasipamanPlatformMap'] as $cls) {
    check(strpos($setup, "Plugin::registerClass('{$cls}')") !== false, "setup.php registers {$cls}");
}

// The API cache is not a setting: showing it on the config form would let
// an admin "save" a stale blob back over a fresh fetch.
$config = (string) file_get_contents($root . '/inc/config.class.php');
$form   = (string) file_get_contents($root . '/front/config.form.php');
foreach (['provisioning_cache_nodes', 'provisioning_cache_platforms'] as $cache_key) {
    check(strpos($config, "'{$cache_key}'") !== false,   "{$cache_key} has a default");
    check(strpos($form, $cache_key) === false,           "{$cache_key} is NOT in the config form save path - it is cache, not a setting");
}

// --- the old push is gone, root and branch ----------------------------
// Four separate faults lived in assetsync.class.php, and the worst was
// structural: it called PAMAN synchronously inside a Computer save, so a
// slow bastion slowed every save in GLPI. Nothing may reintroduce that.
check(!file_exists($root . '/inc/assetsync.class.php'),
      'inc/assetsync.class.php is deleted');
check(strpos($hook, 'PluginIntegrasipamanAssetSync') === false,
      'hook.php no longer references the old sync class');
check(strpos($hook, 'pushToJumpServer') === false,
      'nothing calls pushToJumpServer() any more');
check(strpos($setup, 'plugin_integrasipaman_add_computer') === false,
      'setup.php no longer registers the old add callback');

// --- the hooks are local-only ----------------------------------------
foreach ([
    'item_add'    => 'plugin_integrasipaman_provisioning_item_add',
    'item_update' => 'plugin_integrasipaman_provisioning_item_update',
    'item_delete' => 'plugin_integrasipaman_provisioning_item_delete',
    'item_purge'  => 'plugin_integrasipaman_provisioning_item_purge',
] as $glpi_hook => $callback) {
    check(strpos($setup, "\$PLUGIN_HOOKS['{$glpi_hook}']['integrasipaman']") !== false,
          "setup.php registers the {$glpi_hook} hook");
    check(strpos($hook, "function {$callback}(") !== false,
          "hook.php defines {$callback}()");
}

// The hook bodies must not be able to reach the network. refreshOne() is
// the only thing they are allowed to call, and it is local by construction.
preg_match_all('/function plugin_integrasipaman_provisioning_item_\w+\(.*?\n\}/s', $hook, $bodies);
check(count($bodies[0]) === 4, 'all four hook callbacks were found for inspection');
foreach ($bodies[0] as $body) {
    check(strpos($body, 'signedRequest') === false && strpos($body, 'curl_') === false,
          'a hook callback makes no HTTP call - saving a Computer must never wait on PAMAN');
}

// The purge path cannot reuse the shared refresh() helper (the Computer row
// is already gone by the time item_purge fires), so it must call the
// purge-specific entry point instead - never the shared one, which would
// silently no-op on a Computer::getFromDB() that always fails here.
check(strpos($hook, 'PluginIntegrasipamanProvisioning::onComputerPurged(') !== false,
      'plugin_integrasipaman_provisioning_item_purge() calls onComputerPurged()');
preg_match('/function plugin_integrasipaman_provisioning_item_purge\(.*?\n\}/s', $hook, $purge_body_match);
$purge_body = $purge_body_match[0] ?? '';
check($purge_body !== '' && strpos($purge_body, 'plugin_integrasipaman_provisioning_refresh(') === false,
      'the purge callback does not delegate to the shared refresh() helper - the Computer is already gone');

// The four wrappers are one line each; the work is in the shared helper and
// in provisioning.class.php. Scanning only the wrappers is a test that
// cannot fail, so scan everything a hook can reach.
preg_match('/function plugin_integrasipaman_provisioning_refresh\(.*?\n\}/s', $hook, $helper);
check(($helper[0] ?? '') !== '', 'the shared refresh helper was found for inspection');

foreach (array_merge($bodies[0], [$helper[0] ?? '']) as $body) {
    check(strpos($body, 'signedRequest') === false
          && strpos($body, 'signedGet') === false
          && strpos($body, 'curl_') === false,
          'no hook callback or its helper makes an HTTP call - saving a Computer must never wait on PAMAN');
}

// provisioning.class.php is reachable from both the hooks and the cron, and
// neither is allowed to touch the network at all.
check(strpos($prov, 'signedRequest') === false
      && strpos($prov, 'signedGet') === false
      && strpos($prov, 'curl_') === false,
      'provisioning.class.php contains no transport call at all - the queue is computed locally or not at all');

// --- the cron is registered ------------------------------------------
check(strpos($hook, "'provisioning_scan'") !== false, 'hook.php registers the provisioning_scan cron');
check(strpos($hook, 'DAY_TIMESTAMP') !== false,        'it runs on a daily period');

$cron = (string) file_get_contents($root . '/inc/cron.class.php');
check(strpos($cron, 'function cronProvisioning_scan(') !== false, 'the cron entry point exists');
check(strpos($cron, "case 'provisioning_scan':") !== false,
      'cronInfo() describes it, so it is legible on the Automatic Actions page');

// The scan prepares work for a human; it must never do the work itself.
preg_match('/function cronProvisioning_scan\(.*?\n    \}/s', $cron, $m);
$scan_body = $m[0] ?? '';
check($scan_body !== '' && strpos($scan_body, 'signedRequest') === false,
      'the scan cron never writes to PAMAN - a missed cron run must not be able to become an incident');

// --- the Computer view ------------------------------------------------
// Until v3.1.0 this was its own Computer tab. It is now a section of the
// shared PGN Suite tab (suite contract v1 r1), so the assertion moved with
// it: the requirement was never "has addtabon", it was "is reachable from a
// Computer, and exactly once". All three halves are checked, because
// dropping the addtabon without wiring the section would leave the view
// unreachable with nothing failing.
check(strpos($setup, "Plugin::registerClass('PluginIntegrasipamanProvisioning', ['addtabon' => 'Computer'])") === false,
      'the provisioning class no longer registers its OWN Computer tab');
check(strpos($setup, "'PluginIntegrasipamanAssetSections::sections'") !== false,
      'the provisioning view is reachable from a Computer through the PGN Suite section provider');
check(strpos($sections, "'PluginIntegrasipamanProvisioning::displayTabContentForItem'") !== false,
      'the provider renders the provisioning view through this very class, not a copy');
check(strpos($prov, 'public function getTabNameForItem(') !== false,
      'getTabNameForItem() is an INSTANCE method - declaring it static is a fatal "Cannot make non static method static" '
    . 'the moment a Computer page loads (the lesson from inc/profile.class.php)');
check(strpos($prov, 'public static function displayTabContentForItem(') !== false,
      'displayTabContentForItem() is static, as GLPI declares it');

// The tab shows who can reach a machine through the bastion, so its
// audience is the provisioning right, not "can read this Computer".
preg_match('/function getTabNameForItem\(.*?\n    \}/s', $prov, $m);
check(strpos($m[0] ?? '', 'RIGHT_PROVISIONING') !== false,
      'the tab gates itself on the provisioning right');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
