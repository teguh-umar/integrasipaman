<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Piece 3: correlate the display strings on
 * glpi_plugin_jumpserver_commands (asset "NAME(IP)", user
 * "Full Name(login)") to GLPI-native data, into the assetmap / usermap
 * tables, so PluginIntegrasipamanRiskEval can decide "production asset"
 * and "sanctioned admin" from GLPI instead of flat config text. The two
 * config lists (jumpserver_production_ip_prefixes, jumpserver_admin_users)
 * stay as the fallback. Runs at the top of the evaluate_commands cron.
 * See docs/superpowers/specs/2026-08-31-pam-command-audit-piece3-correlation-design.md.
 *
 * Does NOT require_once riskeval.class.php (and vice versa) - the two
 * classes reference each other only inside method bodies, resolved by
 * GLPI's plugin autoloader at call time.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/config.class.php';

class PluginIntegrasipamanCorrelation extends CommonGLPI
{
    public const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    public const ASSETMAP_TABLE = 'glpi_plugin_jumpserver_assetmap';
    public const USERMAP_TABLE  = 'glpi_plugin_jumpserver_usermap';

    /** Text before the last "(" in an asset string, trimmed. */
    public static function parseAssetName(string $asset): string
    {
        $pos = mb_strrpos($asset, '(');
        return trim($pos === false ? $asset : mb_substr($asset, 0, $pos));
    }

    /** Text inside the final "(...)" group of a user string, or ''. */
    public static function parseLogin(string $jms_user): string
    {
        return preg_match('/\(([^()]*)\)\s*$/', $jms_user, $m) ? trim($m[1]) : '';
    }

    /** Unsigned 32-bit form of a dotted-quad IPv4 address, or null. */
    public static function ipv4ToLong(string $ip): ?int
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        return (int) sprintf('%u', ip2long($ip));
    }

    public static function ipInNetwork(int $ip, int $net, int $mask): bool
    {
        return ($ip & $mask) === ($net & $mask);
    }

    /**
     * Fallback production check - byte-for-byte the Piece 2
     * PluginIntegrasipamanRiskEval::isProduction() behaviour, kept here so
     * the resolver has it without a cross-class call.
     *
     * @param array<int, string> $prefixes
     */
    public static function prefixMatch(string $ip, array $prefixes): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            if ($prefix !== '' && str_starts_with($ip, str_replace('xx', '', $prefix))) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Resolver
    // ---------------------------------------------------------------------

    /**
     * Discover new distinct asset / user strings, then re-resolve every
     * non-manual map row against the current config + GLPI data.
     *
     * @param float $max_seconds soft wall-clock budget for the row loops
     *              (0 = unlimited, for the cron; a few seconds for the web
     *              callers so they cannot 504). Leftover rows are picked up
     *              by the next run, exactly like evaluateStale().
     * @return array{assets: array<string,int>, users: array<string,int>}
     */
    public static function resolveAll(float $max_seconds = 0.0): array
    {
        $deadline = $max_seconds > 0 ? microtime(true) + $max_seconds : 0.0;

        self::discoverAssets();
        self::discoverUsers();

        $cfg  = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
        $mode = (string) ($cfg['correlation_match_mode'] ?? 'ip_only');
        if (!in_array($mode, ['ip_only', 'name_only', 'hybrid'], true)) {
            $mode = 'ip_only';
        }

        $nets      = self::loadProductionNetworks(self::idList((string) ($cfg['correlation_production_ipnetworks'] ?? '')));
        $ranges    = self::loadProductionRanges(self::idList((string) ($cfg['correlation_production_addressings'] ?? '')));
        $group_ids = self::idList((string) ($cfg['correlation_admin_groups'] ?? ''));
        $prefixes  = PluginIntegrasipamanRiskEval::parseList((string) ($cfg['jumpserver_production_ip_prefixes'] ?? ''));
        $admins    = PluginIntegrasipamanRiskEval::parseList((string) ($cfg['jumpserver_admin_users'] ?? ''));

        // A classification-relevant change (is_production / is_admin flip,
        // or a first resolve whose verdict differs from what the prefix /
        // admin fallback would have said) bumps the maps generation, which
        // is what rulesHash() folds in - so a plain new asset string does
        // NOT re-evaluate all history, only a real verdict change does.
        $bump   = false;
        $assets = self::resolveAssetRows($nets, $ranges, $prefixes, $mode, $deadline, $bump);
        $users  = self::resolveUserRows($group_ids, $admins, $deadline, $bump);

        if ($bump) {
            self::bumpGeneration();
        }

        return ['assets' => $assets, 'users' => $users];
    }

    /** Monotonic counter; any classification-relevant map change bumps it. */
    public static function mapsGeneration(): int
    {
        $cfg = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
        return (int) ($cfg['correlation_maps_generation'] ?? 0);
    }

    public static function bumpGeneration(): void
    {
        Config::setConfigurationValues(
            PluginIntegrasipamanConfig::CONFIG_CONTEXT,
            ['correlation_maps_generation' => (string) (self::mapsGeneration() + 1)]
        );
    }

    private static function dedupKey(string $s): string
    {
        // Match MySQL's *_ci / trailing-space-insensitive UNIQUE semantics
        // so the PHP-side "already known" check agrees with the DB and we
        // don't retry a doomed INSERT on every run.
        return mb_strtolower(rtrim($s));
    }

    /** @return array<string,bool> asset string => is_production, every row. */
    public static function assetProdMap(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['asset', 'is_production'], 'FROM' => self::ASSETMAP_TABLE]) as $r) {
            $out[(string) $r['asset']] = (bool) $r['is_production'];
        }
        return $out;
    }

    /** @return array<string,bool> jms_user string => is_admin, every row. */
    public static function userAdminMap(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['jms_user', 'is_admin'], 'FROM' => self::USERMAP_TABLE]) as $r) {
            $out[(string) $r['jms_user']] = (bool) $r['is_admin'];
        }
        return $out;
    }

    // --- discovery -------------------------------------------------------

    private static function discoverAssets(): void
    {
        global $DB;

        $known = [];
        foreach ($DB->request(['SELECT' => ['asset'], 'FROM' => self::ASSETMAP_TABLE]) as $r) {
            $known[self::dedupKey((string) $r['asset'])] = true;
        }
        foreach ($DB->request(['SELECT' => ['asset'], 'DISTINCT' => true, 'FROM' => self::COMMANDS_TABLE]) as $r) {
            $asset = (string) $r['asset'];
            $key   = self::dedupKey($asset);
            if ($asset === '' || isset($known[$key])) {
                continue;
            }
            $known[$key] = true;
            try {
                if (!$DB->insert(self::ASSETMAP_TABLE, [
                    'asset'          => $asset,
                    'parsed_name'    => self::parseAssetName($asset),
                    'parsed_ip'      => PluginIntegrasipamanRiskEval::extractIp($asset),
                    'resolve_method' => 'unresolved',
                    'date_creation'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ])) {
                    trigger_error(sprintf('correlation: could not insert assetmap row for "%s": %s', $asset, $DB->error()), E_USER_WARNING);
                }
            } catch (\Throwable $e) {
                trigger_error(sprintf('correlation: assetmap insert threw for "%s": %s', $asset, $e->getMessage()), E_USER_WARNING);
            }
        }
    }

    private static function discoverUsers(): void
    {
        global $DB;

        $known = [];
        foreach ($DB->request(['SELECT' => ['jms_user'], 'FROM' => self::USERMAP_TABLE]) as $r) {
            $known[self::dedupKey((string) $r['jms_user'])] = true;
        }
        foreach ($DB->request(['SELECT' => ['jms_user'], 'DISTINCT' => true, 'FROM' => self::COMMANDS_TABLE]) as $r) {
            $jms_user = (string) $r['jms_user'];
            $key      = self::dedupKey($jms_user);
            if ($jms_user === '' || isset($known[$key])) {
                continue;
            }
            $known[$key] = true;
            try {
                if (!$DB->insert(self::USERMAP_TABLE, [
                    'jms_user'       => $jms_user,
                    'parsed_login'   => self::parseLogin($jms_user),
                    'resolve_method' => 'unresolved',
                    'date_creation'  => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ])) {
                    trigger_error(sprintf('correlation: could not insert usermap row for "%s": %s', $jms_user, $DB->error()), E_USER_WARNING);
                }
            } catch (\Throwable $e) {
                trigger_error(sprintf('correlation: usermap insert threw for "%s": %s', $jms_user, $e->getMessage()), E_USER_WARNING);
            }
        }
    }

    // --- config helpers ------------------------------------------------

    /** @return array<int,int> positive integer ids from a comma list. */
    private static function idList(string $csv): array
    {
        $ids = [];
        foreach (explode(',', $csv) as $part) {
            $n = (int) trim($part);
            if ($n > 0) {
                $ids[$n] = $n;
            }
        }
        return array_values($ids);
    }

    /**
     * @param array<int,int> $ids
     * @return array<int, array{id:int, net:int, mask:int}>
     */
    private static function loadProductionNetworks(array $ids): array
    {
        global $DB;
        if ($ids === []) {
            return [];
        }
        $nets = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'address', 'netmask'],
            'FROM'   => 'glpi_ipnetworks',
            'WHERE'  => ['id' => $ids, 'version' => 4],
        ]) as $r) {
            $net  = self::ipv4ToLong((string) $r['address']);
            $mask = self::ipv4ToLong((string) $r['netmask']);
            if ($net === null || $mask === null) {
                trigger_error(
                    sprintf('correlation: IPNetwork %d skipped - address/netmask not IPv4 (%s / %s)',
                        (int) $r['id'], $r['address'], $r['netmask']),
                    E_USER_WARNING
                );
                continue;
            }
            $nets[] = ['id' => (int) $r['id'], 'net' => $net, 'mask' => $mask];
        }
        if (count($nets) < count($ids)) {
            trigger_error(
                sprintf('correlation: %d of %d selected production IPNetwork(s) are missing or not IPv4 - those assets fall through to the prefix list',
                    count($ids) - count($nets), count($ids)),
                E_USER_WARNING
            );
        }
        return $nets;
    }

    /**
     * Named IP ranges from the "IP Addressing" plugin
     * (glpi_plugin_addressing_addressings, begin_ip .. end_ip). This is the
     * curated production-network source on instances that use that plugin;
     * core glpi_ipnetworks is often auto-populated junk. Empty (and no
     * warning) when the plugin is not installed.
     *
     * @param array<int,int> $ids
     * @return array<int, array{id:int, begin:int, end:int}>
     */
    private static function loadProductionRanges(array $ids): array
    {
        global $DB;
        if ($ids === [] || !$DB->tableExists('glpi_plugin_addressing_addressings')) {
            return [];
        }
        $ranges = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'begin_ip', 'end_ip'],
            'FROM'   => 'glpi_plugin_addressing_addressings',
            'WHERE'  => ['id' => $ids, 'is_deleted' => 0],
        ]) as $r) {
            $b = self::ipv4ToLong((string) $r['begin_ip']);
            $e = self::ipv4ToLong((string) $r['end_ip']);
            if ($b === null || $e === null || $b > $e) {
                trigger_error(
                    sprintf('correlation: addressing range %d skipped - bad begin/end IP (%s / %s)',
                        (int) $r['id'], $r['begin_ip'], $r['end_ip']),
                    E_USER_WARNING
                );
                continue;
            }
            $ranges[] = ['id' => (int) $r['id'], 'begin' => $b, 'end' => $e];
        }
        return $ranges;
    }

    // --- asset resolution ---------------------------------------------

    /**
     * @param array<int, array{id:int,net:int,mask:int}>  $nets
     * @param array<int, array{id:int,begin:int,end:int}> $ranges
     * @param array<int,string>                           $prefixes
     * @return array<string,int>
     */
    private static function resolveAssetRows(array $nets, array $ranges, array $prefixes, string $mode, float $deadline, bool &$bump): array
    {
        global $DB;
        $stats = ['total' => 0, 'resolved' => 0, 'prefix_fallback' => 0, 'manual' => 0, 'unresolved' => 0];

        foreach ($DB->request(['FROM' => self::ASSETMAP_TABLE]) as $row) {
            $stats['total']++;
            if ((int) ($row['is_manual'] ?? 0) === 1) {
                $stats['manual']++;
                continue;
            }
            if ($deadline > 0 && microtime(true) > $deadline) {
                break;
            }
            try {
                $vals = self::resolveAssetRow($row, $nets, $ranges, $prefixes, $mode);
            } catch (\Throwable $e) {
                trigger_error(sprintf('correlation: asset row %d failed: %s', (int) $row['id'], $e->getMessage()), E_USER_WARNING);
                continue;
            }

            $first           = ((string) ($row['last_resolved_at'] ?? '')) === '';
            $verdict_changed = self::writeRow(self::ASSETMAP_TABLE, (int) $row['id'], $vals, $row, 'is_production');
            if ($verdict_changed && !$first) {
                // An established row's verdict flipped -> re-evaluate.
                $bump = true;
            } elseif ($first
                && (int) $vals['is_production'] !== (self::prefixMatch((string) ($row['parsed_ip'] ?? ''), $prefixes) ? 1 : 0)) {
                // First real resolve whose verdict differs from what the
                // prefix fallback (what Piece 2 used at eval time) would
                // say. A first resolve that agrees with the fallback needs
                // no re-eval, so it must NOT bump.
                $bump = true;
            }

            $m = $vals['resolve_method'];
            if ($m === 'prefix_fallback') {
                $stats['prefix_fallback']++;
            } elseif ($m === 'unresolved') {
                $stats['unresolved']++;
            } else {
                $stats['resolved']++;
            }
        }
        return $stats;
    }

    /**
     * @param array<string,mixed>                         $row
     * @param array<int, array{id:int,net:int,mask:int}>   $nets
     * @param array<int, array{id:int,begin:int,end:int}>  $ranges
     * @param array<int,string>                            $prefixes
     * @return array<string,mixed> columns to write
     */
    private static function resolveAssetRow(array $row, array $nets, array $ranges, array $prefixes, string $mode): array
    {
        $ip   = (string) ($row['parsed_ip'] ?? '');
        $name = (string) ($row['parsed_name'] ?? '');

        $computers_id = self::findComputer($ip, $name);

        if ($mode === 'name_only') {
            [$prod, $ref_id, $src] = self::testProduction($computers_id > 0 ? self::computerIps($computers_id) : [], $nets, $ranges);
            $via = 'computer_ip';
        } else {
            [$prod, $ref_id, $src] = self::testProduction($ip !== '' ? [$ip] : [], $nets, $ranges);
            $via = $src;   // 'ipnet' or 'addr_range'
            if (!$prod && $mode === 'hybrid' && $computers_id > 0) {
                [$prod, $ref_id, $src] = self::testProduction(self::computerIps($computers_id), $nets, $ranges);
                $via = 'computer_ip';
            }
        }

        if ($prod) {
            return self::assetVals($computers_id, $ref_id, 1, $via);
        }
        if (self::prefixMatch($ip, $prefixes)) {
            return self::assetVals($computers_id, 0, 1, 'prefix_fallback');
        }
        return self::assetVals($computers_id, 0, 0, 'unresolved');
    }

    /** @return array<string,mixed> */
    private static function assetVals(int $computers_id, int $ipnetworks_id, int $is_production, string $method): array
    {
        return [
            'computers_id'     => $computers_id,
            'ipnetworks_id'    => $ipnetworks_id,
            'is_production'    => $is_production,
            'resolve_method'   => $method,
            'last_resolved_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /** GLPI Computer by IP, else by name (lowest id). 0 = none. */
    private static function findComputer(string $ip, string $name): int
    {
        global $DB;

        if ($ip !== '') {
            foreach ($DB->request([
                'SELECT' => ['mainitems_id'],
                'FROM'   => 'glpi_ipaddresses',
                'WHERE'  => ['mainitemtype' => 'Computer', 'name' => $ip],
                'ORDER'  => 'mainitems_id ASC',
                'LIMIT'  => 1,
            ]) as $r) {
                if ((int) $r['mainitems_id'] > 0) {
                    return (int) $r['mainitems_id'];
                }
            }
        }
        if ($name !== '') {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['name' => $name, 'is_deleted' => 0, 'is_template' => 0],
                'ORDER'  => 'id ASC',
                'LIMIT'  => 1,
            ]) as $r) {
                return (int) $r['id'];
            }
        }
        return 0;
    }

    /** @return array<int,string> */
    private static function computerIps(int $computers_id): array
    {
        global $DB;
        $ips = [];
        foreach ($DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => ['mainitemtype' => 'Computer', 'mainitems_id' => $computers_id],
        ]) as $r) {
            if ((string) $r['name'] !== '') {
                $ips[] = (string) $r['name'];
            }
        }
        return $ips;
    }

    /**
     * True if any of $ips falls in a configured production IPNetwork (core
     * glpi_ipnetworks) or a configured "IP Addressing" plugin range.
     *
     * @param array<int,string>                           $ips
     * @param array<int, array{id:int,net:int,mask:int}>   $nets
     * @param array<int, array{id:int,begin:int,end:int}>  $ranges
     * @return array{0:bool,1:int,2:string} [is production, matched id, method 'ipnet'|'addr_range']
     */
    private static function testProduction(array $ips, array $nets, array $ranges): array
    {
        foreach ($ips as $ipstr) {
            $ipl = self::ipv4ToLong($ipstr);
            if ($ipl === null) {
                continue;
            }
            foreach ($nets as $n) {
                if (self::ipInNetwork($ipl, $n['net'], $n['mask'])) {
                    return [true, $n['id'], 'ipnet'];
                }
            }
            foreach ($ranges as $r) {
                if ($ipl >= $r['begin'] && $ipl <= $r['end']) {
                    return [true, $r['id'], 'addr_range'];
                }
            }
        }
        return [false, 0, 'ipnet'];
    }

    // --- user resolution --------------------------------------------

    /**
     * @param array<int,int>    $group_ids
     * @param array<int,string> $admins
     * @return array<string,int>
     */
    private static function resolveUserRows(array $group_ids, array $admins, float $deadline, bool &$bump): array
    {
        global $DB;
        $stats = ['total' => 0, 'resolved' => 0, 'name_fallback' => 0, 'manual' => 0, 'unresolved' => 0];

        foreach ($DB->request(['FROM' => self::USERMAP_TABLE]) as $row) {
            $stats['total']++;
            if ((int) ($row['is_manual'] ?? 0) === 1) {
                $stats['manual']++;
                continue;
            }
            if ($deadline > 0 && microtime(true) > $deadline) {
                break;
            }
            try {
                $vals = self::resolveUserRow($row, $group_ids, $admins);
            } catch (\Throwable $e) {
                trigger_error(sprintf('correlation: user row %d failed: %s', (int) $row['id'], $e->getMessage()), E_USER_WARNING);
                continue;
            }

            $first           = ((string) ($row['last_resolved_at'] ?? '')) === '';
            $verdict_changed = self::writeRow(self::USERMAP_TABLE, (int) $row['id'], $vals, $row, 'is_admin');
            if ($verdict_changed && !$first) {
                $bump = true;
            } elseif ($first
                && (int) $vals['is_admin'] !== (in_array((string) ($row['jms_user'] ?? ''), $admins, true) ? 1 : 0)) {
                // First resolve that agrees with the admin-list fallback
                // needs no re-eval, so it must NOT bump.
                $bump = true;
            }

            $m = $vals['resolve_method'];
            if ($m === 'name_fallback') {
                $stats['name_fallback']++;
            } elseif ($m === 'unresolved') {
                $stats['unresolved']++;
            } else {
                $stats['resolved']++;
            }
        }
        return $stats;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,int>      $group_ids
     * @param array<int,string>   $admins
     * @return array<string,mixed>
     */
    private static function resolveUserRow(array $row, array $group_ids, array $admins): array
    {
        global $DB;

        $login    = (string) ($row['parsed_login'] ?? '');
        $jms_user = (string) ($row['jms_user'] ?? '');

        $users_id = 0;
        if ($login !== '') {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['name' => $login, 'is_deleted' => 0],
                'LIMIT'  => 1,
            ]) as $r) {
                $users_id = (int) $r['id'];
            }
        }

        $is_admin = 0;
        $method   = 'unresolved';

        if ($users_id > 0 && $group_ids !== []) {
            foreach ($DB->request([
                'COUNT' => 'c',
                'FROM'  => 'glpi_groups_users',
                'WHERE' => ['users_id' => $users_id, 'groups_id' => $group_ids],
            ]) as $r) {
                if ((int) $r['c'] > 0) {
                    $is_admin = 1;
                    $method   = 'group';
                }
            }
        }
        if ($is_admin === 0 && in_array($jms_user, $admins, true)) {
            $is_admin = 1;
            $method   = 'name_fallback';
        }

        return [
            'users_id'         => $users_id,
            'is_admin'         => $is_admin,
            'resolve_method'   => $method,
            'last_resolved_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
    }

    // --- write -----------------------------------------------------

    /**
     * Update a map row (caller guarantees it is not is_manual). Skips the
     * UPDATE entirely when nothing but last_resolved_at would change,
     * except on the row's first resolve (so last_resolved_at gets set once
     * and the "first resolve" divergence check stops re-firing).
     *
     * @param array<string,mixed> $vals        columns to write (incl. last_resolved_at)
     * @param array<string,mixed> $row         the row as currently stored
     * @param string              $verdict_col is_production | is_admin
     * @return bool whether $verdict_col actually changed
     */
    private static function writeRow(string $table, int $id, array $vals, array $row, string $verdict_col): bool
    {
        global $DB;

        $diff            = false;
        $verdict_changed = false;
        foreach ($vals as $k => $v) {
            if ($k === 'last_resolved_at') {
                continue;
            }
            if ((string) ($row[$k] ?? '') !== (string) $v) {
                $diff = true;
                if ($k === $verdict_col) {
                    $verdict_changed = true;
                }
            }
        }

        $first = ((string) ($row['last_resolved_at'] ?? '')) === '';
        if (!$diff && !$first) {
            return false;
        }

        if (!$DB->update($table, $vals, ['id' => $id])) {
            trigger_error(
                sprintf('correlation: update %s#%d failed: %s', $table, $id, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }
        return $verdict_changed;
    }
}
