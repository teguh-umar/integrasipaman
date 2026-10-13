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
require_once __DIR__ . '/rulesettings.class.php';

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

    /**
     * Whether an asset name marks it as a staging box.
     *
     * Substring, case-insensitive, on the operator's own convention. It is
     * deliberately not a regex with word boundaries: the names in this estate
     * carry the marker in several shapes (APP-STG-01, db-stag-7), and a
     * false positive costs a staging-looking box being called development,
     * which is what it would be called anyway if nothing else matched.
     */
    public static function isStagingName(string $name): bool
    {
        return stripos($name, 'STG') !== false || stripos($name, 'STAG') !== false;
    }

    /**
     * The three-valued classification for one address.
     *
     * Production wins a tie deliberately: for an audit tool, calling a
     * production box "development" understates every command run on it,
     * while the reverse only overstates. Production is also established
     * from three sources (GLPI ipnetworks, addressings, prefixes) and
     * development from one, so a development match is the weaker claim.
     *
     * Returns '' rather than guessing. An empty string here means "nobody
     * has stated this yet", which is a work-queue entry and NOT a class.
     *
     * @param array<int,string> $prod_prefixes
     * @param array<int,string> $dev_prefixes
     */
    public static function classFor(string $ip, array $prod_prefixes, array $dev_prefixes): string
    {
        if (self::prefixMatch($ip, $prod_prefixes)) {
            return 'production';
        }
        if (self::prefixMatch($ip, $dev_prefixes)) {
            return 'development';
        }

        return '';
    }

    /**
     * Whether a first resolve's verdict really differs from what the prefix
     * fallback would have said at evaluation time.
     *
     * A function rather than an inline comparison because the inline one it
     * replaces was a boolean inequality. Left as `!==` on strings it is
     * still "correct" and still wrong in effect: it fires on every run for
     * rows the fallback could never have classified, bumping the generation
     * forever and re-evaluating the whole command history each time.
     */
    public static function firstResolveDiffers(string $resolved, string $fallback): bool
    {
        return $resolved !== $fallback;
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

        // v4.0.0: one pass per connection. An asset string or a user name
        // means something only inside the JumpServer it came from, so each
        // connection has its own map rows and matches only the Computers in
        // its own scope. The budget is shared: a connection the deadline
        // cuts short is picked up by the next run, like leftover rows.
        if (PluginIntegrasipamanConnection::all() === []) {
            return self::resolveCurrent($deadline);
        }

        $totals = [
            'assets' => ['total' => 0, 'resolved' => 0, 'prefix_fallback' => 0, 'manual' => 0, 'unresolved' => 0],
            'users'  => ['total' => 0, 'resolved' => 0, 'name_fallback' => 0, 'manual' => 0, 'unresolved' => 0],
        ];
        $runs = PluginIntegrasipamanConnection::each(static fn () => self::resolveCurrent($deadline));
        foreach ($runs as $run) {
            if ($run['error'] !== '') {
                trigger_error(
                    sprintf('correlation: connection "%s" failed: %s', $run['connection']->label(), $run['error']),
                    E_USER_WARNING
                );
                continue;
            }
            foreach ($run['result'] as $kind => $stats) {
                foreach ($stats as $key => $value) {
                    $totals[$kind][$key] = ($totals[$kind][$key] ?? 0) + $value;
                }
            }
        }

        return $totals;
    }

    /**
     * resolveAll() for the current connection - or, on an instance with no
     * connection at all, for whatever rows there are.
     *
     * @return array{assets: array<string,int>, users: array<string,int>}
     */
    private static function resolveCurrent(float $deadline): array
    {
        self::discoverAssets();
        self::discoverUsers();

        // v4.4.0: this connection's rules (its own, inherited, or the defaults).
        $cfg  = PluginIntegrasipamanRuleSettings::current();
        $mode = (string) ($cfg['correlation_match_mode'] ?? 'ip_only');
        // Same list the dropdown and the save controller use - see
        // PluginIntegrasipamanConfig::MATCH_MODES.
        if (!in_array($mode, PluginIntegrasipamanConfig::MATCH_MODES, true)) {
            $mode = 'ip_only';
        }

        $nets      = self::loadProductionNetworks(self::idList((string) ($cfg['correlation_production_ipnetworks'] ?? '')));
        $ranges    = self::loadProductionRanges(self::idList((string) ($cfg['correlation_production_addressings'] ?? '')));
        $group_ids = self::idList((string) ($cfg['correlation_admin_groups'] ?? ''));
        $prefixes  = PluginIntegrasipamanRiskEval::parseList((string) ($cfg['jumpserver_production_ip_prefixes'] ?? ''));
        $dev_prefixes = PluginIntegrasipamanRiskEval::parseList((string) ($cfg['jumpserver_development_ip_prefixes'] ?? ''));
        $admins    = PluginIntegrasipamanRiskEval::parseList((string) ($cfg['jumpserver_admin_users'] ?? ''));

        // A classification-relevant change (asset_class / is_admin flip,
        // or a first resolve whose verdict differs from what the prefix /
        // admin fallback would have said) bumps the maps generation, which
        // is what rulesHash() folds in - so a plain new asset string does
        // NOT re-evaluate all history, only a real verdict change does.
        $bump   = false;
        self::repairDanglingLinks();

        $assets = self::resolveAssetRows($nets, $ranges, $prefixes, $dev_prefixes, $mode, $deadline, $bump);
        $users  = self::resolveUserRows($group_ids, $admins, $deadline, $bump);

        if ($bump) {
            self::bumpGeneration();
        }

        // Its own pass, after resolveAssetRows() so it sees both the
        // repairDanglingLinks() repair above and the links freshly resolved
        // this run - and deliberately NOT folded into the row loop, which
        // skips is_manual rows before this could ever run for them.
        self::refreshIpMismatch();

        // v3.11.0: a map row follows the Computer it is linked to, so after
        // the links have settled, the rows that carry one take its entity
        // (inside the connection's scope; the connection's own otherwise).
        PluginIntegrasipamanConnection::syncLinked();

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

    /** @return array<string,string> asset string => asset_class, every row. */
    public static function assetClassMap(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['asset', 'asset_class'], 'FROM' => self::ASSETMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $r) {
            $out[(string) $r['asset']] = (string) ($r['asset_class'] ?? '');
        }
        return $out;
    }

    /** @return array<string,bool> jms_user string => is_admin, every row. */
    public static function userAdminMap(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['jms_user', 'is_admin'], 'FROM' => self::USERMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $r) {
            $out[(string) $r['jms_user']] = (bool) $r['is_admin'];
        }
        return $out;
    }

    // --- discovery -------------------------------------------------------

    private static function discoverAssets(): void
    {
        global $DB;

        $known = [];
        foreach ($DB->request(['SELECT' => ['asset'], 'FROM' => self::ASSETMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $r) {
            $known[self::dedupKey((string) $r['asset'])] = true;
        }
        foreach ($DB->request(['SELECT' => ['asset'], 'DISTINCT' => true, 'FROM' => self::COMMANDS_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $r) {
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
                ] + PluginIntegrasipamanConnection::stamp())) {
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
        foreach ($DB->request(['SELECT' => ['jms_user'], 'FROM' => self::USERMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $r) {
            $known[self::dedupKey((string) $r['jms_user'])] = true;
        }
        foreach ($DB->request(['SELECT' => ['jms_user'], 'DISTINCT' => true, 'FROM' => self::COMMANDS_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $r) {
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
                ] + PluginIntegrasipamanConnection::stamp())) {
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
     * Clear any assetmap link whose Computer no longer exists.
     *
     * Its own pass, and deliberately NOT a branch inside resolveAssetRow():
     * that function is never reached for is_manual = 1 rows, and the id it
     * would test was just computed by findComputer(), which cannot return
     * an id that does not exist. Locked rows are the only place a dangling
     * link can survive - nothing else revisits them - so a repair that
     * skipped them would repair only the rows that were never broken.
     *
     * Clearing to 0 is honest. Re-pointing the link at a plausible-looking
     * Computer would be a guess wearing a fact's clothes.
     *
     * One query for the live ids, then membership - not a query per row.
     * This runs at the top of every resolveAll(), which the cron calls
     * every 15 minutes; a per-row existence check would be ~500 queries a
     * run on this estate, the same regression the mismatch pass beside this
     * one already rejected once.
     */
    public static function repairDanglingLinks(): void
    {
        global $DB;

        // EXISTENCE ONLY - is_deleted and is_template are deliberately
        // absent here, while findComputer() deliberately filters on both.
        // The two are different jobs and the spec names them separately
        // (section 7): the join in findComputer() stops a bad BINDING being
        // made; this pass repairs a binding whose target was PURGED. The
        // evidence behind it is an id that returned "Empty set", not one in
        // the trash.
        //
        // A soft delete is routine and reversible; a purge is not. Add
        // is_deleted back and a five-second trip through the GLPI trash
        // silently destroys an operator's hand-picked link on a LOCKED row -
        // and because nothing ever revisits a locked row, restoring the
        // Computer does not restore the link. No note, no log line, gone.
        $live = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_computers',
        ]) as $r) {
            $live[(int) $r['id']] = true;
        }

        foreach ($DB->request(['FROM' => self::ASSETMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $row) {
            $computers_id = (int) ($row['computers_id'] ?? 0);
            if ($computers_id > 0 && !isset($live[$computers_id])) {
                $DB->update(self::ASSETMAP_TABLE, ['computers_id' => 0], ['id' => (int) $row['id']]);
            }
        }
    }

    /**
     * Recompute ip_mismatch for every row, LOCKED ROWS INCLUDED.
     *
     * Deliberately its own pass rather than a branch inside
     * resolveAssetRow(), which the is_manual early return never reaches.
     * A lock is a decision about CLASSIFICATION; it says nothing about
     * whether the linked Computer still carries this address.
     *
     * The flag is a SIGNAL. It never touches asset_class, priority or
     * catatan - both inventories go stale in different cases, so the only
     * honest statement is "these disagree, look", and letting that move a
     * score would let a blocked agent port change a risk verdict.
     *
     * AGED-OUT ROWS ARE FORCED TO 0, not skipped. Spec section 5: the flag
     * is skipped "only for aged-out rows - comparing an address that PAMAN
     * no longer lists produces noise". Leaving the stored value alone would
     * FREEZE whatever it last held, and a flag that outlives the check that
     * set it is the sticky flag section 9 forbids and section 4 rules out -
     * within a few weeks the count labelled "investigate" would fill with
     * machines that are gone and whose addresses cannot agree.
     *
     * NULL is computed normally. NULL means nobody has asked yet, which is
     * our own ignorance, not a finding - and discoverAssets() adds a row the
     * moment a command reaches a new asset while the host list is pulled
     * weekly, so a genuinely live, genuinely mismatched asset would be
     * silenced for up to seven days.
     */
    public static function refreshIpMismatch(): void
    {
        global $DB;

        $aged_out_before = date(
            'Y-m-d H:i:s',
            strtotime('-' . PluginIntegrasipamanEntitlement::STALE_DAYS . ' days')
        );

        // Every address recorded against any Computer, in ONE query.
        // (Scoped by mainitemtype only - it is not narrowed to the Computers
        // assetmap happens to link, because narrowing it would cost the
        // per-row work this single query exists to avoid.)
        //
        // computerIps() costs a query per Computer, and this pass visits
        // every row on a table of ~615 - so calling it per row would put
        // ~500 queries on a path that runs every 15 minutes. That is a
        // performance regression shipped in the name of reuse.
        $ips_by_computer = [];
        foreach ($DB->request([
            'SELECT' => ['mainitems_id', 'name'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => ['mainitemtype' => 'Computer'],
        ]) as $r) {
            $addr = (string) ($r['name'] ?? '');
            if ($addr !== '') {
                $ips_by_computer[(int) $r['mainitems_id']][] = $addr;
            }
        }

        foreach ($DB->request(['FROM' => self::ASSETMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $row) {
            $id  = (int) ($row['id'] ?? 0);
            $cid = (int) ($row['computers_id'] ?? 0);
            $ip  = (string) ($row['parsed_ip'] ?? '');

            // Aged out of the PAMAN host list: PAMAN no longer lists this
            // asset at all, so its address cannot agree with anything and
            // the comparison is noise. Forced to 0, never left alone - see
            // the note above about sticky flags.
            $checked  = (string) ($row['live_checked_at'] ?? '');
            $aged_out = $checked !== '' && $checked < $aged_out_before;

            // No link or no address means there is nothing to disagree
            // about - not that the two agree.
            $mismatch = (!$aged_out && $cid > 0 && $ip !== '' && !in_array($ip, $ips_by_computer[$cid] ?? [], true)) ? 1 : 0;

            if ($mismatch !== (int) ($row['ip_mismatch'] ?? 0)) {
                $DB->update(self::ASSETMAP_TABLE, ['ip_mismatch' => $mismatch], ['id' => $id]);
            }
        }
    }

    /**
     * @param array<int, array{id:int,net:int,mask:int}>  $nets
     * @param array<int, array{id:int,begin:int,end:int}> $ranges
     * @param array<int,string>                           $prefixes
     * @return array<string,int>
     */
    private static function resolveAssetRows(array $nets, array $ranges, array $prefixes, array $dev_prefixes, string $mode, float $deadline, bool &$bump): array
    {
        global $DB;
        $stats = ['total' => 0, 'resolved' => 0, 'prefix_fallback' => 0, 'manual' => 0, 'unresolved' => 0];

        foreach ($DB->request(['FROM' => self::ASSETMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $row) {
            $stats['total']++;
            if ((int) ($row['is_manual'] ?? 0) === 1) {
                $stats['manual']++;
                continue;
            }
            if ($deadline > 0 && microtime(true) > $deadline) {
                break;
            }
            try {
                $vals = self::resolveAssetRow($row, $nets, $ranges, $prefixes, $dev_prefixes, $mode);
            } catch (\Throwable $e) {
                trigger_error(sprintf('correlation: asset row %d failed: %s', (int) $row['id'], $e->getMessage()), E_USER_WARNING);
                continue;
            }

            $first           = ((string) ($row['last_resolved_at'] ?? '')) === '';
            // writeRow() compares with (string) casts, so a string verdict
            // column needs no change there.
            $verdict_changed = self::writeRow(self::ASSETMAP_TABLE, (int) $row['id'], $vals, $row, 'asset_class');
            if ($verdict_changed && !$first) {
                // An established row's verdict flipped -> re-evaluate.
                $bump = true;
            } elseif ($first
                && self::firstResolveDiffers(
                    (string) $vals['asset_class'],
                    self::classFor((string) ($row['parsed_ip'] ?? ''), $prefixes, $dev_prefixes)
                )) {
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
    private static function resolveAssetRow(array $row, array $nets, array $ranges, array $prefixes, array $dev_prefixes, string $mode): array
    {
        $ip   = (string) ($row['parsed_ip'] ?? '');
        $name = (string) ($row['parsed_name'] ?? '');

        // hybrid_name looks the Computer up by NAME first and stops there if
        // it finds one - the parsed IP is never consulted for the match. This
        // exists because findComputer()'s IP-first order silently mis-maps
        // whenever two Computers share an IP in GLPI: it takes the lowest id
        // and never checks whether the asset's own name matches the other one.
        $computers_id = $mode === 'hybrid_name'
            ? self::findComputer($ip, $name, true)
            : self::findComputer($ip, $name);

        if ($mode === 'name_only' || ($mode === 'hybrid_name' && $computers_id > 0)) {
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
            return self::assetVals($computers_id, $ref_id, 'production', $via);
        }

        // No GLPI-native production evidence. Fall back to the prefix
        // lists, which is the only source development has at all.
        $class = self::classFor($ip, $prefixes, $dev_prefixes);
        if ($class === 'production') {
            return self::assetVals($computers_id, 0, 'production', 'prefix_fallback');
        }
        if ($class === 'development') {
            return self::assetVals($computers_id, 0, 'development', 'prefix_fallback');
        }

        // Staging boxes, by name, and ONLY where nothing else decided.
        // They are deleted after UAT and deleting them needs no approval, so
        // they should generate neither mapping work nor alerts (user, 2026-09-27).
        //
        // Stating them as development rather than leaving them unmapped is the
        // point: an asset with no class scores MEDIUM, so "just don't map them"
        // would make staging the loudest thing in the report instead of the
        // quietest. A staging box genuinely IS non-production, so this is a
        // statement, not a convenience.
        //
        // Deliberately last: production evidence wins. A production box whose
        // name happens to carry STG would score LOW if the name won, and a
        // production asset scored LOW is blindness - the exact failure this
        // feature exists to remove. A staging box wrongly called production
        // only costs noise. Same asymmetry that decides the prefix tie-break.
        if (self::isStagingName($name)) {
            return self::assetVals($computers_id, 0, 'development', 'staging_name');
        }

        return self::assetVals($computers_id, 0, '', 'unresolved');
    }

    /** @return array<string,mixed> */
    private static function assetVals(int $computers_id, int $ipnetworks_id, string $asset_class, string $method): array
    {
        return [
            'computers_id'     => $computers_id,
            'ipnetworks_id'    => $ipnetworks_id,
            'asset_class'      => $asset_class,
            'resolve_method'   => $method,
            'last_resolved_at' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];
    }

    /**
     * GLPI Computer by IP, else by name (lowest id). 0 = none.
     *
     * $name_first flips the order: name, then IP. Prefer it when the
     * instance has Computers sharing an IP - an IP-first match there picks
     * the lowest id and never notices that the asset's name identifies a
     * different Computer exactly. Either order still falls back to the
     * other, so an asset whose name matches nothing is unaffected.
     *
     * Both lookups take the lowest id when several rows match, so duplicate
     * NAMES have the same failure mode as duplicate IPs, just more rarely.
     */
    private static function findComputer(string $ip, string $name, bool $name_first = false): int
    {
        global $DB;

        // v4.0.0: only a Computer inside the connection's scope can be the
        // match. Two subsidiaries reuse private address ranges and host
        // names; without this, another company's Computer with the same IP
        // would be linked, and its entity's users would then be shown this
        // JumpServer's sessions on it. Unrestricted only where no connection
        // is current (an instance with none).
        $connection      = PluginIntegrasipamanConnection::current();
        $in_scope        = $connection === null ? [] : ['entities_id' => $connection->scope()];
        $in_scope_joined = $connection === null ? [] : ['glpi_computers.entities_id' => $connection->scope()];

        if ($name_first && $name !== '') {
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['name' => $name, 'is_deleted' => 0, 'is_template' => 0] + $in_scope,
                'ORDER'  => 'id ASC',
                'LIMIT'  => 1,
            ]) as $r) {
                return (int) $r['id'];
            }
        }

        if ($ip !== '') {
            foreach ($DB->request([
                'SELECT'     => ['glpi_ipaddresses.mainitems_id'],
                'FROM'       => 'glpi_ipaddresses',
                'INNER JOIN' => [
                    'glpi_computers' => ['ON' => [
                        'glpi_computers'  => 'id',
                        'glpi_ipaddresses' => 'mainitems_id',
                        ['AND' => [
                            'glpi_computers.is_deleted'  => 0,
                            'glpi_computers.is_template' => 0,
                        ]],
                    ]],
                ],
                'WHERE'  => ['glpi_ipaddresses.mainitemtype' => 'Computer', 'glpi_ipaddresses.name' => $ip] + $in_scope_joined,
                'ORDER'  => 'glpi_ipaddresses.mainitems_id ASC',
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
                'WHERE'  => ['name' => $name, 'is_deleted' => 0, 'is_template' => 0] + $in_scope,
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

    /** @return list<string> the Computer's addresses, for display. */
    public static function computerAddresses(int $computers_id): array
    {
        return self::computerIps($computers_id);
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

        foreach ($DB->request(['FROM' => self::USERMAP_TABLE, 'WHERE' => PluginIntegrasipamanConnection::ownRows()]) as $row) {
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
     * @param string              $verdict_col asset_class | is_admin
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
