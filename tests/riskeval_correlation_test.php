<?php
// Standalone. Run: C:\php\php.exe glpi/plugins/integrasipaman/tests/riskeval_correlation_test.php
// Verifies evaluateRow() reads the correlation maps first and falls back
// to the flat lists when a key is absent.

error_reporting(E_ALL & ~E_DEPRECATED);
define('GLPI_ROOT', __DIR__);

if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $e = null) { return strtolower((string) $s); } }
if (!function_exists('mb_substr'))     { function mb_substr($s, $start, $length = null, $e = null) { return $length === null ? substr((string) $s, $start) : substr((string) $s, $start, $length); } }

class CommonGLPI {}
class CommonDBTM {}
class Config {
    public static function getConfigurationValues($c) {
        return [
            'jumpserver_risk_keywords'          => "sudo\nrm -rf",
            'jumpserver_admin_users'            => "Legacy Admin(legacy.admin)",
            'jumpserver_production_ip_prefixes' => "10.0.0.",
        ];
    }
}
// Correlation stub: one asset known development, one user known admin.
// assetClassMap() records which method name it was called under, so a
// regression back to the old assetProdMap() call site is pinned by name,
// not merely by the map's contents (see the assertion right after
// loadRules() below).
class PluginIntegrasipamanCorrelation {
    public static $calledMethod = null;
    public static function assetClassMap(): array {
        self::$calledMethod = 'assetClassMap';
        return ['known(10.0.0.9)' => 'development'];
    }
    public static function userAdminMap(): array { return ['Known Admin(known.admin)' => true]; }
    public static function mapsGeneration(): int { return 3; }
    public static function resolveAll(float $s = 0.0): array { return ['assets' => [], 'users' => []]; }
    // Address-only classifier used by classFromIp() as the fallback when an
    // asset string has no row in assetClassMap(). Production wins a tie.
    public static function classFor(string $ip, array $prod_prefixes, array $dev_prefixes): string {
        if ($ip === '') {
            return '';
        }
        foreach ($prod_prefixes as $p) {
            if ($p !== '' && str_starts_with($ip, $p)) {
                return 'production';
            }
        }
        foreach ($dev_prefixes as $p) {
            if ($p !== '' && str_starts_with($ip, $p)) {
                return 'development';
            }
        }
        return '';
    }
}

require __DIR__ . '/../inc/riskeval.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$R = 'PluginIntegrasipamanRiskEval';

$rules = $R::loadRules();
check(array_key_exists('asset_class', $rules) && array_key_exists('dev_prefixes', $rules) && array_key_exists('user_admin', $rules),
    'loadRules exposes asset_class / dev_prefixes / user_admin');
check(($rules['asset_class']['known(10.0.0.9)'] ?? 'x') === 'development', 'asset_class carries the map value');
check(PluginIntegrasipamanCorrelation::$calledMethod === 'assetClassMap',
    'loadRules() asks the correlation class for assetClassMap() by name, not assetProdMap()');

$hash1 = $R::rulesHash($rules);
check(is_string($hash1) && strlen($hash1) === 40, 'rulesHash still 40 chars');
check($R::rulesHash($rules) === $hash1, 'rulesHash stable for same inputs');

$row = ['id' => 1, 'risk_level' => 0, 'command' => 'sudo su', 'asset' => 'known(10.0.0.9)', 'jms_user' => 'Someone(nobody)'];
$e = $R::evaluateRow($row, $rules, $hash1);
check($e['asset_class'] === 'development', 'map development beats the fallback prefix');
check($e['priority'] === 'LOW', 'priority LOW for high-risk on development');

$row2 = ['id' => 2, 'risk_level' => 0, 'command' => 'sudo su', 'asset' => 'unmapped(10.0.0.50)', 'jms_user' => 'Someone(nobody)'];
$e2 = $R::evaluateRow($row2, $rules, $hash1);
check($e2['asset_class'] === 'production', 'unmapped asset falls back to the prefix list');

$row3 = ['id' => 3, 'risk_level' => 5, 'command' => 'x', 'asset' => 'unmapped(10.0.0.50)', 'jms_user' => 'Known Admin(known.admin)'];
$e3 = $R::evaluateRow($row3, $rules, $hash1);
check($e3['user_is_admin'] === 1 && $e3['priority'] === 'MEDIUM', 'correlation admin map -> MEDIUM on production');

$row4 = ['id' => 4, 'risk_level' => 5, 'command' => 'x', 'asset' => 'unmapped(10.0.0.50)', 'jms_user' => 'Legacy Admin(legacy.admin)'];
$e4 = $R::evaluateRow($row4, $rules, $hash1);
check($e4['user_is_admin'] === 1, 'unmapped user falls back to the admin list');

// --- v3.6.0: three-valued scoring ---------------------------------------
$R = 'PluginIntegrasipamanRiskEval';

check($R::priorityNote(true, 'production', false) === ['HIGH', 'Butuh Tindak Lanjut'],
      'production + non-admin is HIGH');
check($R::priorityNote(true, 'production', true) === ['MEDIUM', 'Aktivitas Admin'],
      'production + admin is MEDIUM');
check($R::priorityNote(true, 'development', false) === ['LOW', 'Aset Development'],
      'development is LOW and says so - not "Non-Production"');
check($R::priorityNote(true, '', false) === ['MEDIUM', 'Aset Belum Diklasifikasi'],
      'an UNCLASSIFIED asset is MEDIUM - the whole point: a gap must not look safe');
check($R::priorityNote(false, 'production', false) === ['', ''],
      'a command that is not high-risk is still scored at all priorities blank');

// An unclassified asset must not be scored as development just because
// neither is production. These two are different answers - already proven
// by the two check()s above (MEDIUM/"Aset Belum Diklasifikasi" vs. LOW/"Aset
// Development"); a third check() here could not fail unless one of those
// two failed first.

// Review Focus 2: PHP coerces a bool to a string silently, so a call site
// missed during the signature change yields '1' or '' and lands in a branch
// with no error. '1' is not a class.
check($R::priorityNote(true, '1', false) === ['MEDIUM', 'Aset Belum Diklasifikasi'],
      'an unknown class string falls to the unclassified branch, not to production');

// Review Focus 3: the map lookup. `??` falls through on absent/null ONLY, so
// an asset explicitly resolved to '' must NOT fall back to the prefix lists
// - the prefix lists are what produced the '' in the first place.
$rules = [
    'keywords' => [], 'admins' => [], 'prefixes' => ['192.0.2.'],
    'dev_prefixes' => ['198.51.100.'],
    'asset_class' => ['known(192.0.2.5)' => ''],
    'user_admin' => [],
];
$row = $R::evaluateRow(['id' => 1, 'command' => 'ls', 'asset' => 'known(192.0.2.5)',
                        'jms_user' => 'u', 'risk_level' => 5], $rules, 'h');
check(($row['asset_class'] ?? null) === '',
      'an explicit empty class is kept, not re-derived from the prefix list');

// A string with no map row at all DOES fall back.
$row = $R::evaluateRow(['id' => 2, 'command' => 'ls', 'asset' => 'unknown(192.0.2.5)',
                        'jms_user' => 'u', 'risk_level' => 5], $rules, 'h');
check(($row['asset_class'] ?? null) === 'production',
      'an asset with no map row falls back to the prefix derivation');

// rulesHash must move when the development list moves, or the form shows a
// new classification while stored scores keep the old one.
$a = ['keywords' => [], 'admins' => [], 'prefixes' => ['192.0.2.'], 'dev_prefixes' => ['198.51.100.']];
$b = ['keywords' => [], 'admins' => [], 'prefixes' => ['192.0.2.'], 'dev_prefixes' => ['203.0.113.']];
check($R::rulesHash($a) !== $R::rulesHash($b),
      'rulesHash folds the development list, so editing it re-evaluates');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
