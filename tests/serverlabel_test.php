<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/serverlabel_test.php
//
// v3.10.0: the UI no longer says "PAMAN" by name. The word is the first DNS
// label of the configured JumpServer URL (user decision 2026-10-04:
// paman.example.com -> PAMAN, bastion.somedomain.com -> BASTION).
//
// Two things can go wrong silently. The parser can hand back something that
// is not a name (an octet of an IP address, an empty string), and it then
// appears in fifty sentences. And a new message can be written with the
// literal again, which reads correctly on the one production instance and
// wrongly everywhere else. Both are pinned here.
error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

// v3.11.0: the URL is read from the connection row, not from glpi_configs.
// Any read of config for it is a regression, so the stand-in counts them.
$GLOBALS['CFG_READS'] = 0;

class Config
{
    public static function getConfigurationValues($context, $keys = [])
    {
        $GLOBALS['CFG_READS']++;
        return [];
    }
}

require __DIR__ . '/../inc/serverlabel.class.php';

function useUrl($url): void
{
    PluginIntegrasipamanConnection::setCurrent($url === false ? null : ['id' => 1, 'url' => $url]);
}

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }

$L = 'PluginIntegrasipamanServerLabel';

// --- the parser -------------------------------------------------------------
$cases = [
    // the production value, in the shapes an admin may have typed it
    'https://paman.example.com'            => 'PAMAN',
    'paman.example.com'                    => 'PAMAN',
    'https://paman.example.com:8443/api'   => 'PAMAN',
    'https://paman.example.com/'           => 'PAMAN',
    '  https://PAMAN.example.com  '        => 'PAMAN',
    'http://paman.example.com./'           => 'PAMAN',
    // the user's own example
    'bastion.somedomain.com'             => 'BASTION',
    'bastion.somedomain.com:8443'        => 'BASTION',
    'https://jump-01.example.org'        => 'JUMP-01',
    'https://user:pw@vault.example.org'  => 'VAULT',
    'jumphost'                           => 'JUMPHOST',
    // no name to take
    ''                                   => 'JumpServer',
    '   '                                => 'JumpServer',
    'https://10.1.2.3'                   => 'JumpServer',
    '10.1.2.3:8080'                      => 'JumpServer',
    'https://[2001:db8::1]:443/'         => 'JumpServer',
    'https://10.1.2'                     => 'JumpServer',
    'https://'                           => 'JumpServer',
    'not a url at all'                   => 'JumpServer',
    'https://-bad-.example.org'          => 'JumpServer',
    'https://<script>.example.org'       => 'JumpServer',
    '://'                                => 'JumpServer',
];
foreach ($cases as $url => $want) {
    $got = $L::fromUrl($url);
    check($got === $want, "fromUrl('" . $url . "') -> $want (got $got)");
}
foreach (array_keys($cases) as $url) {
    $got = $L::fromUrl($url);
    check($got === 'JumpServer' || preg_match('/^[A-Z0-9]([A-Z0-9-]*[A-Z0-9])?$/', $got) === 1,
          "fromUrl('" . $url . "') is the fallback or a bare upper-case label - safe in HTML and in sprintf()");
}

// --- get(): reads the connection's URL, once per request ---------------------
useUrl('https://paman.example.com');
$L::reset();
check($L::get() === 'PAMAN', 'get() derives the label from the connection URL');
check($GLOBALS['CFG_READS'] === 0, 'and does not look for it in glpi_configs any more');
useUrl('https://other.example.org');
check($L::get() === 'PAMAN', 'get() is cached: a page with fifty messages resolves the label once');
$L::reset();
check($L::get() === 'OTHER', 'reset() picks up a URL saved during the request');

useUrl(null);
$L::reset();
check($L::get() === 'JumpServer', 'a URL that is not a string falls back, without a TypeError');

useUrl(false);
$L::reset();
check($L::get() === 'JumpServer', 'no connection at all (a fresh install) falls back too');

// --- one label per connection (code review, 4.0.0) ----------------------------
// A cron run loops over the connections and a batch of sends can span two of
// them. One cached label named every one of them by the first.
PluginIntegrasipamanConnection::setCurrent(['id' => 1, 'url' => 'https://paman.example.com']);
$L::reset();
check($L::get() === 'PAMAN', 'the first connection is named by its own URL');
PluginIntegrasipamanConnection::setCurrent(['id' => 2, 'url' => 'https://bastion.subsidiary.example']);
check($L::get() === 'BASTION', 'switching to another connection switches the word, without a reset');
check($L::in('Could not reach {server}') === 'Could not reach BASTION', 'and in() follows');
PluginIntegrasipamanConnection::setCurrent(['id' => 1, 'url' => 'https://paman.example.com']);
check($L::get() === 'PAMAN', 'and back');
// --- in(): the token --------------------------------------------------------
useUrl('bastion.somedomain.com');
$L::reset();
check($L::in('Reload from {server}') === 'Reload from BASTION', 'in() replaces the token');
check($L::in('%1$d hosts in {server} already carry this address (%2$s). Resolve the duplicate in {server} first.')
      === '%1$d hosts in BASTION already carry this address (%2$s). Resolve the duplicate in BASTION first.',
      'in() replaces every token and leaves sprintf arguments alone');
check($L::in('nothing to replace') === 'nothing to replace', 'in() leaves a string without the token unchanged');
check(sprintf($L::in('%d assets will be created or updated in {server}.'), 3)
      === '3 assets will be created or updated in BASTION.',
      'the result is still a valid sprintf format');

// --- no literal left in the code --------------------------------------------
// Comments may name PAMAN (they describe the instance this was built against).
// Code may not: token_get_all() separates the two, which a grep cannot.
$root  = __DIR__ . '/..';
$files = array_merge(glob("$root/*.php"), glob("$root/inc/*.php"), glob("$root/front/*.php"));
check(count($files) > 40, 'the plugin sources were found (' . count($files) . ' files)');
$literal = [];
$tokens  = 0;
foreach ($files as $file) {
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        if (!is_array($t)) {
            continue;
        }
        if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            if (preg_match('/\bPAMAN\b/', $t[1]) === 1) {
                $literal[] = basename($file) . ':' . $t[2];
            }
            $tokens += substr_count($t[1], '{server}');
        }
    }
}
// hook.php's cron-task 'comment' is the one exception: it is written to
// glpi_crontasks at install and is stored text from then on.
$allowed = array_values(array_filter($literal, static fn($l) => str_starts_with($l, 'hook.php:')));
$other   = array_values(array_diff($literal, $allowed));
check($other === [], 'no string literal names PAMAN outside the stored cron comment (' . implode(', ', $other) . ')');
check(count($allowed) === 1, 'and that exception is exactly one line in hook.php');
check($tokens >= 48, "the messages carry the token instead ($tokens occurrences)");

// Every token must pass through in(): a bare '{server}' would be printed as is.
$bare = [];
foreach ($files as $file) {
    if (basename($file) === 'serverlabel.class.php') {
        continue;
    }
    foreach (file($file) as $i => $line) {
        if (str_contains($line, '{server}') && !str_contains($line, 'PluginIntegrasipamanServerLabel::in(')) {
            $bare[] = basename($file) . ':' . ($i + 1);
        }
    }
}
check($bare === [], 'every line with the token calls ServerLabel::in() (' . implode(', ', $bare) . ')');

$setup = (string) file_get_contents("$root/setup.php");
check(preg_match("/include_once\(__DIR__ \. '\/inc\/serverlabel\.class\.php'\);.*if \(Plugin::isPluginActive/s", $setup) === 1,
      'setup.php loads the class before anything that prints a message');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
