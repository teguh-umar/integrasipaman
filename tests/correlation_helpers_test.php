<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/correlation_helpers_test.php

error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

// This CLI PHP has no mbstring; the GLPI server does. Shim the few used.
if (!function_exists('mb_strrpos')) { function mb_strrpos($h, $n, $o = 0, $e = null) { return strrpos($h, $n, $o); } }
if (!function_exists('mb_substr'))  { function mb_substr($s, $start, $length = null, $e = null) { return $length === null ? substr((string) $s, $start) : substr((string) $s, $start, $length); } }

class CommonGLPI {}
class CommonDBTM {}   // correlation.class.php require_once's the real config.class.php (extends CommonDBTM)
class PluginIntegrasipamanRiskEval {
    public static function extractIp(string $s): string {
        return preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $s, $m) ? $m[1] : '';
    }
    public static function parseList(string $raw, bool $k = false): array {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) {
            $p = trim($l);
            if ($p === '' || $p[0] === '#') continue;
            $out[] = $p;
        }
        return $out;
    }
}

require __DIR__ . '/../inc/correlation.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$C = 'PluginIntegrasipamanCorrelation';

check($C::parseAssetName('web01(10.0.0.5)') === 'web01', 'parseAssetName basic');
check($C::parseAssetName('db (prod)(10.0.0.1)') === 'db (prod)', 'parseAssetName inner parens');
check($C::parseAssetName('plainname') === 'plainname', 'parseAssetName no parens');
check($C::parseAssetName('') === '', 'parseAssetName empty');

check($C::parseLogin('Sysadmin One(sysadmin.one-e)') === 'sysadmin.one-e', 'parseLogin basic');
check($C::parseLogin('NoLogin') === '', 'parseLogin none');
check($C::parseLogin('A(b)(c)') === 'c', 'parseLogin last group');

check($C::ipv4ToLong('10.0.0.5') === 167772165, 'ipv4ToLong value');
check($C::ipv4ToLong('10.0.0.999') === null, 'ipv4ToLong out of range');
check($C::ipv4ToLong('2001:db8::1') === null, 'ipv4ToLong ipv6');
check($C::ipv4ToLong('') === null, 'ipv4ToLong empty');

$net  = $C::ipv4ToLong('10.0.0.0');
$m24  = $C::ipv4ToLong('255.255.255.0');
check($C::ipInNetwork($C::ipv4ToLong('10.0.0.5'), $net, $m24) === true, 'ipInNetwork in range');
check($C::ipInNetwork($C::ipv4ToLong('10.0.1.5'), $net, $m24) === false, 'ipInNetwork out of range');
$m32 = $C::ipv4ToLong('255.255.255.255');
check($C::ipInNetwork($C::ipv4ToLong('10.0.0.5'), $C::ipv4ToLong('10.0.0.5'), $m32) === true, 'ipInNetwork /32 exact');
check($C::ipInNetwork($C::ipv4ToLong('10.0.0.5'), 0, 0) === true, 'ipInNetwork /0 always');

check($C::prefixMatch('10.0.0.129', ['10.0.0.', '10.0.1.']) === true, 'prefixMatch hit (Piece 2 parity)');
check($C::prefixMatch('10.200.0.1', ['10.0.0.']) === false, 'prefixMatch miss');
check($C::prefixMatch('', ['10.0.0.']) === false, 'prefixMatch empty ip');
check($C::prefixMatch('10.0.0.5', ['10.0.0.xx']) === true, 'prefixMatch xx-strip');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
