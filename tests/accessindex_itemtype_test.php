<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/accessindex_itemtype_test.php
//
// Task 6: AccessUser and AccessGroup, the read-only Search itemtypes over the
// report tables. Pinned: stable option ids, no itemlink, read-only, escaping
// of every server string (Review Focus #1), no script tag in any output.
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    define('GLPI_ROOT', __DIR__);
    define('READ', 1);

    function __($s, $d = null) { return $s; }
    function _n($s, $p, $n, $d = null) { return $n > 1 ? $p : $s; }
    function htmlescape($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

    $GLOBALS['RIGHTS'] = [];
    class Session
    {
        public static function haveRight($name, $mask) { return !empty($GLOBALS['RIGHTS'][$name]); }
    }
    class PluginIntegrasipamanProfile
    {
        public const RIGHT_CONFIG = 'plugin_integrasipaman_config';
        public const RIGHT_REPORT = 'plugin_integrasipaman_report';
    }
    class PluginIntegrasipamanAccessIndex
    {
        public const USERS_TABLE  = 'glpi_plugin_jumpserver_accessusers';
        public const GROUPS_TABLE = 'glpi_plugin_jumpserver_accessgroups';
    }
    class Entity { public static function getTypeName($n = 0) { return 'Entity'; } }
    class Computer
    {
        public static function getFormURLWithID($id) { return '/front/computer.form.php?id=' . $id; }
    }
    class Plugin
    {
        public static function getWebDir($p) { return '/plugins/' . $p; }
    }
    eval('namespace Glpi\Search; interface DefaultSearchRequestInterface { public static function getDefaultSearchRequest(): array; }');

    class CommonDBTM
    {
        public array $fields = [];
        public static function getSpecificValueToDisplay($field, $values, array $options = []) { return 'PARENT'; }
    }

    require __DIR__ . '/../inc/accessuser.class.php';
    require __DIR__ . '/../inc/accessgroup.class.php';

    $fails = 0;
    function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

    $U = 'PluginIntegrasipamanAccessUser';
    $G = 'PluginIntegrasipamanAccessGroup';

    function opts_by_id($cls) {
        $out = [];
        foreach ((new $cls())->rawSearchOptions() as $o) {
            if (isset($o['id']) && is_int($o['id'])) { $out[$o['id']] = $o; }
        }
        return $out;
    }

    $uo = opts_by_id($U);
    $go = opts_by_id($G);
    check(array_keys($uo) === [1,2,3,4,5,6,7,8,80], 'AccessUser option ids are exactly 1-8 and 80');
    check(array_keys($go) === [1,2,3,4,5,6,80], 'AccessGroup option ids are exactly 1-6 and 80');
    foreach ([$U => $uo, $G => $go] as $cls => $set) {
        foreach ($set as $id => $o) {
            check(($o['datatype'] ?? '') !== 'itemlink', "$cls option $id is not an itemlink");
            check(($o['massiveaction'] ?? null) === false, "$cls option $id has no massive action");
        }
        check($cls::canCreate() === false && $cls::canUpdate() === false && $cls::canPurge() === false
              && $cls::canDelete() === false, "$cls is read-only");
        check(is_subclass_of($cls, 'Glpi\Search\DefaultSearchRequestInterface'), "$cls implements the default-request interface");
        check($cls::getDefaultSearchRequest() === ['sort' => 1, 'order' => 'ASC'], "$cls default request");
        check($cls::$rightname === PluginIntegrasipamanProfile::RIGHT_REPORT, "$cls gated on the report right");
    }
    check($U::getTable() === PluginIntegrasipamanAccessIndex::USERS_TABLE, 'AccessUser table');
    check($G::getTable() === PluginIntegrasipamanAccessIndex::GROUPS_TABLE, 'AccessGroup table');
    check($uo[4]['datatype'] === 'specific' && $uo[4]['additionalfields'] === ['assets_detail']
          && $uo[4]['searchtype'] === ['contains'], 'asset option is specific with assets_detail');
    check($uo[1]['additionalfields'] === ['username'], 'user option pulls username');
    check($go[2]['additionalfields'] === ['id'], 'members option pulls id');

    $GLOBALS['RIGHTS'] = [];
    check($U::canView() === false && $G::canView() === false, 'no report right, no view');
    $GLOBALS['RIGHTS'] = [PluginIntegrasipamanProfile::RIGHT_REPORT => true];
    check($U::canView() === true && $G::canView() === true, 'report right views');

    // --- asset rendering
    $detail = json_encode([
        ['id' => 'a1', 'name' => 'srv-one', 'computers_id' => 77, 'live' => true, 'rules' => [
            ['name' => 'r1', 'state' => 'live', 'via' => '', 'actions' => 'connect', 'accounts' => 'root', 'protocols' => ['ssh']]]],
        ['id' => 'a2', 'name' => 'srv-two', 'computers_id' => 0, 'live' => false, 'rules' => []],
    ]);
    $r = $U::getSpecificValueToDisplay('asset_names', ['assets_detail' => $detail]);
    check(str_contains($r, 'computer.form.php?id=77'), 'live asset links to its Computer');
    check(str_contains($r, 'tooltip-invisible'), 'popup present');
    check(str_contains($r, 'no live rule'), 'dead asset carries a visible marker');
    check(str_contains($r, 'Direct'), 'empty via reads as Direct');
    check(str_contains($r, 'visually-hidden'), 'chips are separated for export');
    check(!str_contains($r, '<script'), 'no script in asset output');

    // --- Review Focus #1
    $evil = json_encode([
        ['id' => 'a', 'name' => '<b>x</b>&"', 'computers_id' => 0, 'live' => true, 'rules' => [
            ['name' => '<img src=x>', 'state' => 'live', 'via' => '<i>v</i>', 'actions' => '<u>a</u>', 'accounts' => 'a&b', 'protocols' => ['<s>p</s>']]]],
    ]);
    $r = $U::getSpecificValueToDisplay('asset_names', ['assets_detail' => $evil]);
    check(!str_contains($r, '<b>x</b>') && !str_contains($r, '<img src=x>'), 'asset and rule names are escaped');
    check(str_contains($r, '&lt;b&gt;'), 'escaped form present');
    check(!str_contains($r, '<i>v</i>') && !str_contains($r, '<u>') && !str_contains($r, '<s>'), 'via, actions, protocols escaped');
    check(str_contains($r, 'a&amp;b'), 'account a&b is escaped');

    foreach (['', '[]', '{not json', 'null'] as $bad) {
        $r = $U::getSpecificValueToDisplay('asset_names', ['assets_detail' => $bad]);
        check(str_contains($r, '&mdash;') && str_contains($r, 'text-muted'), "bad or empty detail '$bad' renders a muted dash");
    }

    $r = $U::getSpecificValueToDisplay('name', ['name' => '<b>Ann</b>', 'username' => 'ann']);
    check($r === '&lt;b&gt;Ann&lt;/b&gt;(ann)', 'user name renders Name(username), escaped');

    // --- members
    $r = $G::getSpecificValueToDisplay('users_amount', ['id' => 12, 'users_amount' => 3]);
    check(str_contains($r, 'data-members-url="/plugins/integrasipaman/front/groupmembers.php?id=12"'), 'members url ends in groupmembers.php?id=12');
    check(str_contains($r, 'integrasipaman-members') && !str_contains($r, '<script'), 'members link class, no script');

    echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
    exit($fails === 0 ? 0 : 1);
}
