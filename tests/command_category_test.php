<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/command_category_test.php
// Pins the category config parser and the matcher. The trailing-space rule
// here is not cosmetic: `su ` trimmed to `su` matches sudo, sudoers, issue
// and resume, which would turn Privilege Escalation into a catch-all and
// make every command in it read as a privilege event.

error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
if (!function_exists('mb_substr'))     { function mb_substr($s, $start, $length = null, $e = null) { return $length === null ? substr((string) $s, $start) : substr((string) $s, $start, $length); } }

class CommonGLPI {}
class CommonDBTM {}
class Config { public static function getConfigurationValues($c) { return []; } }
// No PluginIntegrasipamanConfig stub here: riskeval.class.php require_once's
// the real inc/config.class.php (it only needs the CommonDBTM stub above),
// same as tests/riskeval_correlation_test.php and
// tests/correlation_helpers_test.php do. Declaring a stub class of the same
// name here would fatal with "Cannot redeclare class" once that require
// runs.
class PluginIntegrasipamanCorrelation {
    public static function assetClassMap(): array { return []; }
    public static function userAdminMap(): array { return []; }
    public static function mapsGeneration(): int { return 0; }
}

require __DIR__ . '/../inc/riskeval.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$R = 'PluginIntegrasipamanRiskEval';

// --- the trailing space, which is the whole reason this parser exists ----
$c = $R::parseCategories("Privilege Escalation: sudo, su , visudo");
check(($c['Privilege Escalation'] ?? []) === ['sudo', 'su ', 'visudo'],
      'a single trailing space on a keyword survives the comma split (got '
    . json_encode($c) . ')');

$c = $R::parseCategories("Backup:   rsync  ");
check(($c['Backup'] ?? []) === ['rsync'],
      'a trailing space is significant ONLY when there is exactly one of it - '
    . 'two or more collapse away entirely, so an accidental double space typed '
    . 'into the textarea does not silently create a keyword that can never '
    . 'match again (got ' . json_encode($c) . ')');

// --- line shape ---------------------------------------------------------
$c = $R::parseCategories("Windows Paths: c:\\windows, d:\\data");
check(($c['Windows Paths'] ?? []) === ['c:\\windows', 'd:\\data'],
      'a KEYWORD may contain a colon - commands do, constantly: C:\\ paths, '
    . 'scp host:/path, rsync host:path. A category NAME does not need one, '
    . 'so the split goes to the first colon and the keywords keep theirs');

$c = $R::parseCategories("Backup: nightly: tar, rsync");
check(array_key_exists('Backup', $c) && ($c['Backup'] ?? []) === ['nightly: tar', 'rsync'],
      'and a colon in the name is read as the separator - the operator writes '
    . '"Backup - nightly" instead, which costs nothing');

$c = $R::parseCategories("no colon at all\nReal: rm -rf");
check(!array_key_exists('no colon at all', $c) && count($c) === 1,
      'a line with no colon is DROPPED, not read as a name with no keywords');

$c = $R::parseCategories("   : orphan\nReal: rm -rf");
check(count($c) === 1 && array_key_exists('Real', $c),
      'an empty category name is dropped');

// Review Focus 3.
$c = $R::parseCategories("Empty:\nAlsoEmpty:   ,  , \nReal: rm -rf");
check(count($c) === 1 && array_key_exists('Real', $c),
      'a category with no usable keyword is dropped rather than left looking configured');

$c = $R::parseCategories("Dup: first\nDup: second");
check(($c['Dup'] ?? []) === ['first'],
      'a duplicate name keeps its FIRST occurrence, so one line cannot silently lose to another');

$c = $R::parseCategories("# a comment\n\n   \nReal: RM -RF");
check($c === ['Real' => ['rm -rf']],
      'comments and blank lines are dropped and keywords are lowercased (got ' . json_encode($c) . ')');

// --- matching -----------------------------------------------------------
$cats = $R::parseCategories("Broad: s\nNarrow: sudo");
check($R::categoryFor('sudo -i', $cats) === 'Broad',
      'file order decides: the first category that matches wins, even if a later one is more specific');

$cats = $R::parseCategories("Privilege Escalation: su ");
check($R::categoryFor('sudo -i', $cats) === $R::UNCATEGORISED,
      'the preserved trailing space stops "su " matching "sudo"');
check($R::categoryFor('su - root', $cats) === 'Privilege Escalation',
      'and it still matches a real su invocation');

check($R::categoryFor('ls -la', $R::parseCategories("Destructive: rm -rf")) === $R::UNCATEGORISED,
      'an unmatched command reads as Belum Dikategorikan, which is work, not a decision');

// Review Focus 4.
check($R::categoryFor('rm -rf /', []) === $R::UNCATEGORISED,
      'with every category deleted, everything is Belum Dikategorikan and nothing errors');

// --- the seed must mean exactly what the old constant meant -------------
// Frozen copy of CATEGORY_MAP as it stood in production v3.7.0
// (riskeval.class.php:40, 2026-09-29). Written out as a literal rather
// than read from the constant on purpose: the constant is deleted in a
// later task, and this assertion must keep working afterwards. What it
// pins is "the seed equals what production had", which is the property
// that makes the upgrade safe.
$expected_v370 = [
    'Account Management'   => ['passwd', 'useradd', 'userdel', 'usermod', 'groupadd', 'groupdel', 'gpasswd', 'chage'],
    'Privilege Escalation' => ['sudo', 'su ', 'visudo'],
    'Service Change'       => ['systemctl', 'service', 'iisreset', 'sc stop', 'sc config'],
    'Network Security'     => ['iptables', 'firewall-cmd', 'route', 'ip route'],
    'Destructive'          => ['rm -rf', 'mkfs', 'fdisk', 'parted', 'dd if=', 'format', 'diskpart', 'del /f', 'remove-item'],
];

// Read through CONFIG_DEFAULTS, not the seed constant directly: the
// default is what an install actually gets. A correct seed that is not
// wired into CONFIG_DEFAULTS would ship an empty default, and reading the
// constant would stay green through that - reading the key pins the value
// that really reaches the database.
check(array_key_exists('jumpserver_command_categories', PluginIntegrasipamanConfig::CONFIG_DEFAULTS),
      'the defaults map actually carries the jumpserver_command_categories key - '
    . 'a seed constant that exists but is not wired into CONFIG_DEFAULTS would '
    . 'ship an install with an empty default, and nothing below this line would catch it');

$seeded = $R::parseCategories(PluginIntegrasipamanConfig::CONFIG_DEFAULTS['jumpserver_command_categories']);

// Anti-vacuity FIRST. A parser that returned [] against an unreachable
// constant would satisfy "they are equal" perfectly, and this project has
// shipped an assertion that passed over an empty parse before.
check(count($seeded) === 5 && ($seeded['Privilege Escalation'][1] ?? '') === 'su ',
      'the seed parsed into 5 categories and kept the trailing space, so the '
    . 'comparison below is not comparing two empty things (got ' . count($seeded) . ')');

check($seeded === $expected_v370,
      'the seed parses to EXACTLY the v3.7.0 category map - same names, same '
    . 'keywords, same order - so the upgrade changes the meaning of no row');

// --- the hash folds the PARSED list, not the textarea -------------------
// Reformatting must not sweep the entire command history. On this estate
// that is tens of thousands of rows re-evaluated for a cosmetic edit.
$base = ['keywords' => ['sudo'], 'admins' => [], 'prefixes' => [], 'dev_prefixes' => []];

$tidy  = $base + ['categories' => $R::parseCategories("Destructive: rm -rf, mkfs")];
$messy = $base + ['categories' => $R::parseCategories("# note\n\n  Destructive:   rm -rf,  mkfs  \n")];
check($R::rulesHash($tidy) === $R::rulesHash($messy),
      'whitespace and comments do not change the hash, so tidying the textarea '
    . 'does not re-evaluate the whole history');

$reordered = $base + ['categories' => $R::parseCategories("Destructive: mkfs, rm -rf")];
check($R::rulesHash($tidy) !== $R::rulesHash($reordered),
      'reordering keywords DOES change the hash - order decides which keyword '
    . 'matches first, so it is a real rule change');

$renamed = $base + ['categories' => $R::parseCategories("Merusak: rm -rf, mkfs")];
check($R::rulesHash($tidy) !== $R::rulesHash($renamed),
      'renaming a category changes the hash, so history is re-labelled');

// A name or keyword containing the separator must not be able to impersonate
// the structure. Not contrived: the seed itself ships "dd if=".
//
// Lowercase throughout, deliberately: parseCategories() lowercases keywords
// but not names, so a mixed-case version of this pair (e.g. "A: B=C" vs
// "A=B: C") does NOT collide under the old hand-joined serialisation - the
// "B"/"b" case mismatch masks it. That is a confound of the repro, not a
// property of the bug: the bug is the missing escaping, and an all-lowercase
// pair isolates it.
$a = $base + ['categories' => $R::parseCategories("a: b=c")];
$b = $base + ['categories' => $R::parseCategories("a=b: c")];
check($R::rulesHash($a) !== $R::rulesHash($b),
      'two different configurations cannot serialise to the same hash input - '
    . 'a name or keyword containing the separator must not be able to '
    . 'impersonate the structure (our own seed ships "dd if=")');

// json_encode() RETURNS FALSE on invalid UTF-8, and false concatenates into
// the hash input as ''. Without JSON_INVALID_UTF8_SUBSTITUTE every config
// carrying a broken byte hashes to the same value as every other one, so a
// real category edit produces no hash change, the sweep never runs, and the
// report keeps showing categories the configuration no longer holds.
//
// The two sides differ ONLY in invalid bytes, and in the LENGTH of the
// invalid run, deliberately: SUBSTITUTE maps each bad byte to U+FFFD, so two
// equal-length invalid runs still fold together. That residue is bounded to
// the bad bytes themselves; the defect being pinned here is the total
// collapse of the whole hash, which the flag removes.
// chr(), not a "\xB1" escape in the source - an editor or a tool that
// rewrites this file as UTF-8 would silently repair the escape into a
// valid two-byte sequence, and the assertion would then pass with the
// flag removed. chr(0xB1) is a lone continuation byte no matter what
// encoding the file is saved in.
$bad1 = $base + ['categories' => $R::parseCategories('a: x' . chr(0xB1))];
$bad2 = $base + ['categories' => $R::parseCategories('a: x' . chr(0xB1) . chr(0xB2))];
check($R::rulesHash($bad1) !== $R::rulesHash($bad2),
      'two configurations differing only in invalid UTF-8 bytes hash '
    . 'differently - a bare json_encode() returns false on those bytes and '
    . 'collapses every broken config to one hash, freezing the sweep');

// --- the constant is gone, not merely unused ----------------------------
$src = file_get_contents(__DIR__ . '/../inc/riskeval.class.php');
check(!str_contains($src, 'CATEGORY_MAP'),
      'CATEGORY_MAP and CATEGORY_MAP_VERSION are deleted - a hand-bumped '
    . 'version the operator cannot see is worse than none once the list hashes itself');
check(!preg_match("/return\s+'General'/", $src),
      'nothing returns the old General bucket any more');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
