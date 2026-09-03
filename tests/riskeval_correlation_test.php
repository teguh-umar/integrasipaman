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
// Correlation stub: one asset known non-production, one user known admin.
class PluginIntegrasipamanCorrelation {
    public static function assetProdMap(): array { return ['known(10.0.0.9)' => false]; }
    public static function userAdminMap(): array { return ['Known Admin(known.admin)' => true]; }
    public static function mapsGeneration(): int { return 3; }
    public static function resolveAll(float $s = 0.0): array { return ['assets' => [], 'users' => []]; }
}

require __DIR__ . '/../inc/riskeval.class.php';

$fails = 0;
function check($cond, $msg) { global $fails; echo ($cond ? "PASS " : "FAIL ") . $msg . "\n"; if (!$cond) $fails++; }
$R = 'PluginIntegrasipamanRiskEval';

$rules = $R::loadRules();
check(array_key_exists('asset_prod', $rules) && array_key_exists('user_admin', $rules),
    'loadRules exposes asset_prod / user_admin');
check(($rules['asset_prod']['known(10.0.0.9)'] ?? 'x') === false, 'asset_prod carries the map value');

$hash1 = $R::rulesHash($rules);
check(is_string($hash1) && strlen($hash1) === 40, 'rulesHash still 40 chars');
check($R::rulesHash($rules) === $hash1, 'rulesHash stable for same inputs');

$row = ['id' => 1, 'risk_level' => 0, 'command' => 'sudo su', 'asset' => 'known(10.0.0.9)', 'jms_user' => 'Someone(nobody)'];
$e = $R::evaluateRow($row, $rules, $hash1);
check($e['asset_is_production'] === 0, 'map non-production beats the fallback prefix');
check($e['priority'] === 'LOW', 'priority LOW for high-risk on non-production');

$row2 = ['id' => 2, 'risk_level' => 0, 'command' => 'sudo su', 'asset' => 'unmapped(10.0.0.50)', 'jms_user' => 'Someone(nobody)'];
$e2 = $R::evaluateRow($row2, $rules, $hash1);
check($e2['asset_is_production'] === 1, 'unmapped asset falls back to the prefix list');

$row3 = ['id' => 3, 'risk_level' => 5, 'command' => 'x', 'asset' => 'unmapped(10.0.0.50)', 'jms_user' => 'Known Admin(known.admin)'];
$e3 = $R::evaluateRow($row3, $rules, $hash1);
check($e3['user_is_admin'] === 1 && $e3['priority'] === 'MEDIUM', 'correlation admin map -> MEDIUM on production');

$row4 = ['id' => 4, 'risk_level' => 5, 'command' => 'x', 'asset' => 'unmapped(10.0.0.50)', 'jms_user' => 'Legacy Admin(legacy.admin)'];
$e4 = $R::evaluateRow($row4, $rules, $hash1);
check($e4['user_is_admin'] === 1, 'unmapped user falls back to the admin list');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
