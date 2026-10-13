<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/config_modes_test.php
//
// Guards the gap that shipped in v2.6.0: `hybrid_name` was added to the
// dropdown and to the resolver, but NOT to front/config.form.php's save
// whitelist. Choosing it reported success and stored 'ip_only' - no error,
// plausible behaviour, wrong result, and the whole feature unreachable.
//
// A new mode is three edits: the dropdown, the resolver, and the controller.
// Two of the three were covered by tests, so the suite stayed green. These
// assertions read the three call sites and require them to agree, which is
// what makes the next mode safe to add rather than this one safe to have
// fixed.
error_reporting(E_ALL & ~E_DEPRECATED);

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$inc   = __DIR__ . '/../inc/';
$front = __DIR__ . '/../front/';

$config_src     = (string) file_get_contents($inc . 'config.class.php');
$correlation_src = (string) file_get_contents($inc . 'correlation.class.php');
$form_src       = (string) file_get_contents($front . 'config.form.php');

// --- the constant itself ----------------------------------------------
preg_match('/const MATCH_MODES = \[(.*?)\];/s', $config_src, $m);
check($m !== [], 'PluginIntegrasipamanConfig::MATCH_MODES exists');

preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $mm);
$modes = $mm[1] ?? [];
sort($modes);
check($modes === ['hybrid', 'hybrid_name', 'ip_only', 'name_only'],
      'MATCH_MODES holds the four known modes (got ' . implode(',', $modes) . ')');

// --- the dropdown the user actually sees ------------------------------
$i = strpos($config_src, "Dropdown::showFromArray('correlation_match_mode'");
check($i !== false, 'the mode dropdown is rendered from showFromArray');
$block = substr($config_src, (int) $i, 900);
preg_match_all("/^\s*'([a-z_]+)'\s*=>\s*__\(/m", $block, $dm);
$offered = $dm[1] ?? [];
sort($offered);
check($offered === $modes,
      'every dropdown option is an accepted mode, and every accepted mode is offered (dropdown: '
      . implode(',', $offered) . ')');

// --- the save controller ----------------------------------------------
// This is the one that was wrong. It must not carry a literal list of its
// own; a second copy is what drifted.
check(str_contains($form_src, 'PluginIntegrasipamanConfig::MATCH_MODES'),
      'the save controller validates against MATCH_MODES');
check(!preg_match("/correlation_match_mode.{0,120}\[\s*'ip_only'/s", $form_src),
      'the save controller keeps NO literal mode list of its own');

// --- the resolver ------------------------------------------------------
check(str_contains($correlation_src, 'PluginIntegrasipamanConfig::MATCH_MODES'),
      'the resolver validates against MATCH_MODES');
check(!preg_match("/in_array\(\\\$mode,\s*\[/", $correlation_src),
      'the resolver keeps NO literal mode list of its own');

// --- the default must be one of them -----------------------------------
preg_match("/'correlation_match_mode'\s*=>\s*'([a-z_]+)'/", $config_src, $dmm);
check(in_array($dmm[1] ?? '', $modes, true),
      "the configured default ('" . ($dmm[1] ?? '?') . "') is an accepted mode");
check(($dmm[1] ?? '') === 'ip_only',
      'the default is still ip_only - a new mode must not change existing installs');

// --- the command-categories textarea on the Setup page -----------------
$form = $form_src;
$conf  = $config_src;

// Bounded to the textarea loop, not the whole file: the same quoted key
// appears in CONFIG_DEFAULTS, so a whole-file search says "rendered" about
// a line that renders nothing.
$ta_from = strpos($conf, "as \$key => [\$label, \$hint, \$rows]");
$ta_at   = $ta_from === false ? false : strrpos(substr($conf, 0, $ta_from), 'foreach ([');
$ta_loop = ($ta_at === false || $ta_from === false) ? '' : substr($conf, $ta_at, $ta_from - $ta_at);

check(strlen($ta_loop) > 200 && str_contains($ta_loop, 'jumpserver_risk_keywords'),
      'the Setup textarea loop was located, so the check below is not vacuous');
check(str_contains($ta_loop, "'jumpserver_command_categories'"),
      'the category textarea is rendered on the Setup page');
// Shape-specific, not a whole-file search: the POST read this same task
// added two lines above (line ~60) contains the identical quoted key, so a
// whole-file str_contains would still pass with the save-array entry below
// deleted - which is precisely the failure this assertion claims to catch.
// Matches only the save-array entry itself ('...' => $var).
check(preg_match('/\'jumpserver_command_categories\'\s*=>\s*\$jumpserver_command_categories/', $form) === 1,
      'the save controller persists it - matched on the save-array entry '
    . 'specifically, because the POST read above contains the same quoted '
    . 'key and a whole-file search would pass with the save line deleted');

// Review Focus 2. The established POST handling is trim($_POST[...]), and
// on this key that silently eats a trailing space on the LAST line - so a
// category ending `..., su ` loses it on save and becomes a catch-all.
// Same failure as the parser trap, through a different door.
//
// NOTE: the brief's first regex here ('/\$jumpserver_command_categories\s*=
// \s*trim\(/') is a strict substring of its second regex (both start with
// the same "= trim(" prefix), so any code satisfying the second (a real
// trim(..., "\r\n") call) necessarily also matches - and fails - the first.
// The two could never pass together. Tightened the first to match only the
// specific single-argument shape of the bug (trim($_POST[...] ?? '') with
// nothing else before the closing paren), which is what it was meant to
// catch, per Task 4 brief Step 6 mutation 1.
check(!preg_match('/\$jumpserver_command_categories\s*=\s*trim\(\s*\$_POST\[[^\]]+\]\s*\?\?\s*\'\'\s*\)\s*;/', $form),
      'the category textarea is NOT saved through a bare trim(), which would '
    . 'strip the significant trailing space from the last line');
check(preg_match('/\$jumpserver_command_categories\s*=\s*trim\([^;]*"\\\\r\\\\n"/', $form) === 1,
      'it trims only line endings, leaving a trailing space on the last keyword intact');

// Review Focus 1. risk_category is VARCHAR(32) (hook.php:210) and
// evaluateRow() writes mb_substr(..., 0, 32). A longer name is stored
// truncated while the config and the report card carry it in full, so a
// drill-down on the full name matches nothing and the category reads empty.
// Bound to the same textarea-loop slice as above, not the whole file: '32'
// is a common number (widths, ports, versions) and a whole-file search
// would still pass if the hint text lost its 32-character mention as long
// as an unrelated '32' existed anywhere else in the file.
// Fix 2. The fifth door onto the same trailing-space trap, on the list that
// sets is_high_risk. jumpserver_risk_keywords' seed ships `su ` WITH its
// space and loadRules() reads it with parseList(..., true) to keep it. A
// bare trim() eats that space the moment `su ` lands on the last line - it
// is only the 9th of ~40 today - and `su` then matches sudo, sudoers, issue
// and resume, ESCALATING all four to high risk rather than mislabelling
// them. Same two-assertion shape as the categories pair above.
check(!preg_match('/\$jumpserver_risk_keywords\s*=\s*trim\(\s*\$_POST\[[^\]]+\]\s*\?\?\s*\'\'\s*\)\s*;/', $form),
      'the risk-keyword textarea is NOT saved through a bare trim(), which '
    . 'would strip the significant trailing space from `su ` on the last line '
    . 'and escalate sudo, sudoers, issue and resume to high risk');
check(preg_match('/\$jumpserver_risk_keywords\s*=\s*trim\([^;]*"\\\\r\\\\n"/', $form) === 1,
      'it trims only line endings, matching the fix seven lines below it');

// Fix 3. A Setup page rendered BEFORE this release carries no categories
// field, so the POST has no such key, ?? '' yields '', and the save writes
// an empty string over the seed - after which the next cron sweeps the whole
// history into Belum Dikategorikan with no error and no warning.
//
// The boundary is array_key_exists, NOT emptiness: absent means "this form
// does not know about the key", empty means "the operator cleared it", and
// Task 1 pinned the second as a real instruction (clear every category ->
// everything uncategorised). A CONFIG_DEFAULTS fallback on empty would
// contradict the spec by making zero categories unreachable.
check(preg_match('/!\s*array_key_exists\(\s*\'jumpserver_command_categories\'\s*,\s*\$_POST\s*\)/', $form) === 1,
      'the save controller distinguishes an ABSENT categories field from an '
    . 'empty one, so a POST from a pre-upgrade Setup tab leaves the stored '
    . 'list alone instead of wiping it');
// 4.4.0: the rule lists are in $rule_values (they go to a connection, or to the defaults).
check(preg_match('/unset\(\s*\$rule_values\[\'jumpserver_command_categories\'\]\s*\)/', $form) === 1,
      'and it acts on that by dropping the key from the save array, which is '
    . 'the only way the stored value survives - Config::setConfigurationValues '
    . 'writes every key it is given');
// The empty half. No emptiness test may gate this key, or an operator who
// deliberately clears every category silently gets the seed back.
check(!preg_match('/\$jumpserver_command_categories\s*===\s*\'\'/', $form)
      && !preg_match('/\$jumpserver_command_categories\s*=[^;]*CONFIG_DEFAULTS/', $form),
      'an explicitly submitted EMPTY categories value is honoured as empty - '
    . 'no equality-to-empty test and no CONFIG_DEFAULTS fallback gate this '
    . 'key, because zero categories must stay reachable');

check(str_contains($ta_loop, '32'),
      'the Setup hint states the 32-character limit on a category name');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
