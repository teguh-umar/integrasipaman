<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/session_itemtype_test.php
//
// v4.7.0: PluginIntegrasipamanSession, the itemtype that lets GLPI's own
// Search engine render the Log Session tab - for a per-user column picker and
// real pagination, which the hand-rolled table could not give (it read at most
// 5000 of this instance's 39,800 sessions and searched only that slice).
//
// What can go wrong quietly, and is pinned here:
//   - getTable() left to core, which derives it from the CLASS name:
//     `glpi_plugin_integrasipamansessions` does not exist, and the failure is
//     a MySQL error on the first core call, not at install time;
//   - the itemtype gated on core `config` (as the four older searchable
//     classes here are), locking out the report-only profile the page is for;
//   - a column left writable, so an audit record mirrored from JumpServer
//     becomes editable in GLPI and the two versions diverge;
//   - `duration` rendered raw: 2697 where the user's own JumpServer export
//     reads 0:44:57, and 0/1 where it reads Success/Running;
//   - an `itemlink` column, which links to a form this itemtype deliberately
//     does not ship - core's generic-route fallback, a bare page with no
//     sidebar (the hub's documented assetconsolidation trap);
//   - search option ids renumbered later: core stores each user's chosen
//     columns in glpi_displaypreferences BY ID, so renumbering rearranges
//     everyone's saved layout silently.
namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    define('GLPI_ROOT', __DIR__);
    define('READ', 1);
    define('UPDATE', 2);

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
    class Entity { public static function getTypeName($n = 0) { return 'Entity'; } }
    class Dropdown
    {
        public static function showFromArray($name, $values, $options = [])
        {
            return 'DROPDOWN:' . implode('|', $values);
        }
    }
    // Core only consults getDefaultSearchRequest() when the itemtype
    // implements this interface (Glpi\Search\Input\QueryBuilder:729). Declaring
    // the method alone is a silent no-op, so the interface is what the test
    // below actually checks for.
    eval('namespace Glpi\Search; interface DefaultSearchRequestInterface { public static function getDefaultSearchRequest(): array; }');

    class CommonDBTM
    {
        public array $fields = [];
        public static function getSpecificValueToDisplay($field, $values, array $options = []) { return 'PARENT'; }
        public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = []) { return 'PARENT'; }
    }

    require __DIR__ . '/../inc/session.class.php';

    $fails = 0;
    function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

    $S = 'PluginIntegrasipamanSession';

    // --- the table, which core would otherwise guess wrong --------------------------
    check($S::getTable() === 'glpi_plugin_jumpserver_sessions',
          'getTable() is overridden to the real table, not derived from the class name');

    // --- rights ---------------------------------------------------------------------
    check($S::$rightname === PluginIntegrasipamanProfile::RIGHT_REPORT,
          'the itemtype is gated on the plugin report right, not core config');

    $GLOBALS['RIGHTS'] = [];
    check($S::canView() === false, 'without the report right, nothing is viewable');
    $GLOBALS['RIGHTS'] = [PluginIntegrasipamanProfile::RIGHT_CONFIG => true];
    check($S::canView() === false, 'the config right alone does not open it');
    $GLOBALS['RIGHTS'] = [PluginIntegrasipamanProfile::RIGHT_REPORT => true];
    check($S::canView() === true, 'the report right does');

    // Read-only: these rows mirror JumpServer's audit trail.
    check($S::canCreate() === false, 'nothing in GLPI may author a session row');
    check($S::canUpdate() === false, 'nor edit one');
    check($S::canPurge() === false, 'nor purge one');
    check($S::canDelete() === false, 'nor delete one');

    // --- the default ordering ----------------------------------------------------------
    // Seen on the VM, 4.7.0-rc1: the list opened on 1 August while the newest
    // session was 25 October - core's fallback order, not newest-first. For a
    // list whose whole purpose is "who connected recently", the first page
    // must answer that question without the reader touching Sort.
    check(is_subclass_of($S, 'Glpi\Search\DefaultSearchRequestInterface'),
          'the itemtype implements DefaultSearchRequestInterface - core ignores the method without it');

    $def = $S::getDefaultSearchRequest();
    check(($def['sort'] ?? null) === 1, 'the default sort is search option 1, date_start');
    check(($def['order'] ?? '') === 'DESC', 'descending, so the newest session is the first row');

    // --- search options ---------------------------------------------------------------
    $opts = (new $S())->rawSearchOptions();

    $by_id = [];
    foreach ($opts as $o) {
        if (isset($o['id']) && is_int($o['id'])) {
            $by_id[$o['id']] = $o;
        }
    }

    check(count($by_id) === count(array_unique(array_keys($by_id))), 'every search option id is unique');
    check(isset($by_id[1], $by_id[5], $by_id[11]), 'the ids are the stable ones the display preferences key on');

    // The nine columns the hand-rolled table showed must all still be offered.
    $fields = array_column($by_id, 'field');
    foreach (['date_start', 'date_end', 'duration', 'jms_user', 'asset', 'account', 'protocol', 'login_from', 'remote_addr'] as $f) {
        check(in_array($f, $fields, true), "the Log Session column `$f` is offered");
    }
    // ...plus what the hand-rolled table could not show at all.
    foreach (['command_amount', 'terminal_name', 'error_reason', 'jms_id'] as $f) {
        check(in_array($f, $fields, true), "and `$f`, which the hand-rolled table had no room for");
    }

    foreach ($by_id as $id => $o) {
        check(($o['table'] ?? '') !== '', "option $id names a table");
        check(($o['datatype'] ?? '') !== 'itemlink',
              "option $id is not an itemlink - this itemtype ships no form to link to");
    }

    check(($by_id[80]['table'] ?? '') === 'glpi_entities',
          'the entity column is offered, since the table carries entities_id');

    foreach ([3 => 'duration', 11 => 'is_success', 12 => 'is_finished'] as $id => $f) {
        check(($by_id[$id]['datatype'] ?? '') === 'specific' && $by_id[$id]['field'] === $f,
              "`$f` is `specific`, or it would render as a raw integer");
    }

    // --- the three renderings ----------------------------------------------------------
    check($S::getSpecificValueToDisplay('duration', ['duration' => 2697]) === '0:44:57',
          'duration renders as JumpServer shows it, not as 2697');
    check($S::getSpecificValueToDisplay('duration', 2697) === '0:44:57',
          'and accepts the bare value core sometimes passes');
    check($S::getSpecificValueToDisplay('duration', ['duration' => 0]) === '0:00:00',
          'a zero duration is still a clock');

    $r = $S::getSpecificValueToDisplay('is_success', ['is_success' => 0]);
    check(str_contains($r, 'Failed') && str_contains($r, 'bg-red-lt'), 'a failed session is marked in red');
    $r = $S::getSpecificValueToDisplay('is_success', ['is_success' => 1]);
    check(str_contains($r, 'Success'), 'a successful one says so');

    $r = $S::getSpecificValueToDisplay('is_finished', ['is_finished' => 0]);
    check(str_contains($r, 'Running'), 'an unfinished session reads as running, not as 0');
    $r = $S::getSpecificValueToDisplay('is_finished', ['is_finished' => 1]);
    check(str_contains($r, 'Finished'), 'a finished one reads as finished');

    check($S::getSpecificValueToDisplay('asset', ['asset' => 'x']) === 'PARENT',
          'any other column falls through to core');

    // --- v4.8.0: Type and Login Source as "Label(value)" --------------------------------
    check($S::getSpecificValueToDisplay('type', ['type' => 'normal', 'type_label' => 'Normal']) === 'Normal(normal)',
          'Type reads as JumpServer\'s own export shows it');
    check($S::getSpecificValueToDisplay('login_from', ['login_from' => 'WT', 'login_from_label' => 'Web Terminal']) === 'Web Terminal(WT)',
          'and so does Login Source, instead of the bare code');

    // Every row imported before 4.8.0 looks like this until the one-shot
    // backfill reaches it - and some never will, because JumpServer rotates
    // its own history. Empty must read as "not known", not as a type.
    $r = $S::getSpecificValueToDisplay('type', ['type' => '', 'type_label' => '']);
    check(str_contains($r, 'mdash') && !str_contains($r, '()'), 'an unknown Type is a dash, not an empty pair of brackets');
    $r = $S::getSpecificValueToDisplay('login_from', ['login_from' => '', 'login_from_label' => '']);
    check(str_contains($r, 'mdash'), 'an unknown Login Source likewise');

    // Half a pair: show what is known, invent nothing.
    check($S::getSpecificValueToDisplay('login_from', ['login_from' => 'WT', 'login_from_label' => '']) === 'WT',
          'a value with no label shows the value alone');
    check($S::getSpecificValueToDisplay('type', ['type' => '', 'type_label' => 'Normal']) === 'Normal',
          'a label with no value shows the label alone');

    // These come from JumpServer, so they are escaped here - unlike the card,
    // Search does not escape a `specific` renderer's output.
    $r = $S::getSpecificValueToDisplay('type', ['type' => 'x', 'type_label' => '<b>N</b>']);
    check(!str_contains($r, '<b>'), 'markup in a label is escaped');

    // Both columns must declare the label as an additional field, or Search
    // never selects it and every cell silently falls back to the bare value.
    $by_field = [];
    foreach ($opts as $o) { if (isset($o['field'])) { $by_field[$o['field']] = $o; } }
    check(in_array('type_label', $by_field['type']['additionalfields'] ?? [], true),
          'the Type option pulls type_label, or the label is never fetched');
    check(in_array('login_from_label', $by_field['login_from']['additionalfields'] ?? [], true),
          'and the Login Source option pulls login_from_label');

    // --- the criteria dropdowns --------------------------------------------------------
    // Without these, a criterion on Result is a free-text box against a
    // tinyint, where typing "Failed" matches nothing.
    $r = $S::getSpecificValueToSelect('is_success', 'n', 1);
    check(str_contains($r, 'Failed') && str_contains($r, 'Success'), 'Result is searched through a two-value dropdown');
    $r = $S::getSpecificValueToSelect('is_finished', 'n', 1);
    check(str_contains($r, 'Running') && str_contains($r, 'Finished'), 'so is State');
    check($S::getSpecificValueToSelect('asset', 'n', 'x') === 'PARENT', 'other columns fall through to core');

    echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
    exit($fails === 0 ? 0 : 1);
}
