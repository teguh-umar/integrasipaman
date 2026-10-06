<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/config_layout_test.php
//
// v3.8.1 regrouped the Setup page into section cards. A layout change moves
// every field, and a field that falls out of showFormConfig() fails silently:
// the POST has no such key, `?? ''` yields '', and the save writes an empty
// string over the stored value. So the first half of this file requires every
// key the save controller persists to still be rendered on the page.
//
// The second half pins the layout itself: six cards, the jump bar that links
// to them, and a sticky action bar whose Re-evaluate button reaches its own
// form through the HTML5 form= attribute (forms cannot nest).
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$config_src = (string) file_get_contents(__DIR__ . '/../inc/config.class.php');
$form_src   = (string) file_get_contents(__DIR__ . '/../front/config.form.php');

// Bounded to showFormConfig(), not the whole file: every key also appears in
// CONFIG_DEFAULTS, so a whole-file search says "rendered" about a default.
$at   = strpos($config_src, 'public function showFormConfig(');
$body = $at === false ? '' : substr($config_src, $at);
check(strlen($body) > 2000, 'showFormConfig() was located, so the checks below are not vacuous');

// --- every persisted key is rendered -----------------------------------
// Two lists since v3.11.0: the settings that go to glpi_configs, and the
// connection fields that go to the connection row. Both must be on the form.
preg_match('/\$config_values\s*=\s*\[(.*?)\];/s', $form_src, $m);
preg_match('/foreach \(\[(.*?)\] as \$field => \$value\)/s', $form_src, $cm);
check(isset($cm[1]), 'the connection field list in the save controller was located');
preg_match_all("/'([a-z_]+)'\s*=>/", ($m[1] ?? '') . ($cm[1] ?? ''), $km);
$keys = $km[1] ?? [];
check(count($keys) >= 25, 'the save array was parsed (' . count($keys) . ' keys)');

// Matched on the call that emits the field's NAME, not on the quoted key: the
// key also appears as the field's id and label for=, so a loose search still
// passes with the input itself renamed or deleted.
foreach ($keys as $key) {
    $q = preg_quote($key, '/');
    check(preg_match("/(Html::input|Dropdown::showFromArray)\(\s*'$q'/", $body) === 1
          || str_contains($body, 'name="' . $key . '"')
          || preg_match("/^\s*'$q'\s*=>\s*\[__\(/m", $body) === 1,   // the textarea loop
          "persisted key '$key' is still rendered as a form field on the Setup page");
}

// --- the section cards --------------------------------------------------
$sections = ['connection', 'pbi', 'terminal', 'risk', 'correlation', 'provisioning'];
preg_match_all("/'(connection|pbi|terminal|risk|correlation|provisioning)'\s*=>\s*\[/", $body, $sm);
check(array_values(array_unique($sm[1] ?? [])) === $sections,
      'the six sections are declared once each, in page order (got '
    . implode(',', array_unique($sm[1] ?? [])) . ')');
check(str_contains($body, "'integrasipaman-sec-' . \$"),
      'each card and its jump link share one generated id, so a link cannot point at nothing');
check(substr_count($body, 'self::openSection(') === count($sections),
      'every section is opened through the one card helper');
check(substr_count($body, 'self::closeSection(') === count($sections),
      'and closed through its counterpart, so no card is left open');

// --- one form, sticky actions -------------------------------------------
// Counted as a statement: the comments in showFormConfig() name it too.
check(substr_count($body, "Html::closeForm();") === 2,
      'still exactly two forms: the settings form and the separate Re-evaluate form');
check(preg_match('/id="integrasipaman-reeval-form"/', $body) === 1
      && preg_match('/form="integrasipaman-reeval-form"/', $body) === 1,
      'the Re-evaluate button in the action bar targets its own form by id');
check(str_contains($body, 'integrasipaman-actions'),
      'the action bar is rendered');
check(preg_match('/if\s*\(\$canedit\)\s*\{[^}]*integrasipaman-actions/s', $body) === 1,
      'the action bar is only rendered for users who may save');

// --- v3.8.2: tabs, like opmanager's Setup ------------------------------
check(str_contains($body, 'nav nav-tabs') && str_contains($body, 'data-bs-toggle="tab"'),
      'the sections are Bootstrap tabs');
check(str_contains($body, 'tab-pane'),
      'each section renders as a tab pane');
check(!str_contains($body, 'position:sticky'),
      'the v3.8.1 sticky bar is gone (tabs keep the page short)');
// Access Key ID / Secret / Org ID are `required`. With the Connection tab
// hidden, the browser refuses the submit and cannot show its bubble on a
// hidden control - Save looks dead. The invalid listener opens the tab.
check(preg_match("/addEventListener\(\s*'invalid'/", $body) === 1,
      'an invalid field in a hidden tab opens that tab instead of a silent no-op Save');
check(str_contains($body, 'sessionStorage'),
      'the last opened tab is restored after Save, as on opmanager');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
