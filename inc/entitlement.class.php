<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Who is PERMITTED to reach an asset through PAMAN, as opposed to who
 * already has. The access tab answers the second question from session
 * history and cannot answer the first - and a permission nobody has
 * exercised is exactly the one an audit wants to see.
 *
 * JumpServer resolves node inheritance itself: ?asset_id=<uuid> returns
 * rules that reach the asset through a node, not only rules naming it
 * directly. That was established by probe round 4 with every link read
 * from a record. This class therefore CACHES the server's answer and
 * never re-derives it; re-implementing inheritance would risk a list that
 * looks complete while missing a whole class of access.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

// Not a CommonGLPI subclass: this registers no tab and is no itemtype.
// It is a data/cron class, like PluginIntegrasipamanProvisioningAction.
require_once __DIR__ . '/serverlabel.class.php';

class PluginIntegrasipamanEntitlement
{
    public const RULES_TABLE = 'glpi_plugin_jumpserver_permrules';
    public const MAP_TABLE   = 'glpi_plugin_jumpserver_assetperms';
    public const GROUPS_TABLE  = 'glpi_plugin_jumpserver_usergroups';
    public const MEMBERS_TABLE = 'glpi_plugin_jumpserver_groupmembers';

    /**
     * jms_id of the row that records "a complete membership pull ran".
     * Empty, so it can never equal a real group id (the NONE_MARKER idea
     * assetperms already uses).
     */
    public const GROUP_PULL_MARKER = '';

    /** Members per page. The largest group on the probe instance has 5124. */
    public const MEMBER_PAGE_SIZE = 500;

    /**
     * Twice the weekly cron interval. One missed run is not worth alarming
     * about; a cron that has stopped must become visible.
     */
    public const STALE_DAYS = 14;

    /** Last instant MySQL TIMESTAMP can hold: 2038-01-19 03:14:07 UTC. */
    public const TIMESTAMP_MAX = 2147483647;

    /** The correlation map this cron stamps liveness onto. */
    public const MAP_SOURCE_TABLE = 'glpi_plugin_jumpserver_assetmap';

    private const RULES_PATH  = '/api/v1/perms/asset-permissions/?limit=500';
    private const USERREL_PATH = '/api/v1/perms/asset-permissions-users-relations/?limit=1000';
    private const GROUPREL_PATH = '/api/v1/perms/asset-permissions-user-groups-relations/?limit=500';
    private const GROUPS_PATH   = '/api/v1/users/groups/?limit=500';
    private const GROUP_USERS_PATH = '/api/v1/users/users/';

    /**
     * Group relation rows by the rule they belong to.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string, list<array{id:string,display:string}>>
     */
    public static function groupMembers(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $rule = (string) ($r['assetpermission'] ?? '');
            if ($rule === '') {
                continue;
            }
            $id      = (string) ($r['user'] ?? $r['usergroup'] ?? '');
            $display = (string) ($r['user_display'] ?? $r['usergroup_display'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$rule][] = ['id' => $id, 'display' => $display];
        }

        return $out;
    }

    /**
     * Pull every rule and merge its members.
     *
     * The rule list endpoint returns users_amount / user_groups_amount and
     * never the members themselves, so the relation tables are not an
     * optimisation - they are the only bulk source.
     *
     * @param array{0:string,1:string,2:string,3:string} $credentials
     */
    public static function pullRules(array $credentials): int
    {
        global $DB;

        // signedGet() returns null on failure and a list on success, so an
        // empty list is a real answer and null is not one. Coercing null to
        // [] here would write users_json='[]' onto every rule over a FRESH
        // date_refreshed: each Computer would then render "No JumpServer
        // permission reaches this Computer" with no stale warning to
        // contradict it. Spec section 6: a failed pull leaves the previous
        // data in place.
        $user_rows  = PluginIntegrasipamanJmsApi::signedGet(self::USERREL_PATH, ...$credentials);
        $group_rows = PluginIntegrasipamanJmsApi::signedGet(self::GROUPREL_PATH, ...$credentials);
        if (!is_array($user_rows) || !is_array($group_rows)) {
            return 0;
        }

        $users  = self::groupMembers($user_rows);
        $groups = self::groupMembers($group_rows);
        $rules  = PluginIntegrasipamanJmsApi::signedGet(self::RULES_PATH, ...$credentials);

        if (!is_array($rules)) {
            return 0;
        }

        $stored = 0;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $jms_id = (string) ($rule['id'] ?? '');
            if ($jms_id === '') {
                continue;
            }

            $DB->updateOrInsert(self::RULES_TABLE, [
                'name'             => (string) ($rule['name'] ?? ''),
                'users_json'       => json_encode($users[$jms_id] ?? []),
                'user_groups_json' => json_encode($groups[$jms_id] ?? []),
                'accounts'         => self::flatten($rule['accounts'] ?? ''),
                'actions'          => self::flatten($rule['actions'] ?? ''),
                'is_active'        => !empty($rule['is_active']) ? 1 : 0,
                'is_valid'         => !empty($rule['is_valid']) ? 1 : 0,
                'is_expired'       => !empty($rule['is_expired']) ? 1 : 0,
                'date_start'       => self::sqlDate($rule['date_start'] ?? null),
                'date_expired'     => self::sqlDate($rule['date_expired'] ?? null),
                'date_mod'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ] + PluginIntegrasipamanConnection::stamp(), ['jms_id' => $jms_id] + PluginIntegrasipamanConnection::ownRows());

            $stored++;
        }

        return $stored;
    }

    /**
     * Pull every user group and its members.
     *
     * Group membership is the leg the rule cache cannot provide: rule JSON
     * says "group G may reach this asset", not "who is in G". The probe
     * proved exactly one working source, `GET /users/users/?group_id=<uuid>`,
     * and proved it by the returned count matching the group list's
     * users_amount. That count is therefore the cheap guard against a filter
     * that silently comes back global - the failure mode this plugin has
     * already paid for twice.
     *
     * NOTHING is written until every group has come back with a complete,
     * count-matching member list. A partial snapshot replacing a complete one
     * would render as a real answer, which is worse than the previous one
     * being one pull old. On any failure the old rows stay and the report
     * ages them.
     *
     * @param array{0:string,1:string,2:string,3:string} $credentials
     * @return int memberships stored, or -1 when the previous snapshot was
     *             left in place
     */
    public static function pullGroupMembers(array $credentials, ?CronTask $task = null): int
    {
        global $DB;

        $say = static function (string $message) use ($task): void {
            if ($task !== null) {
                $task->log($message);
            }
        };

        [$base_url, $access_key_id, $access_key_secret, $org_id] = $credentials;

        // null is a transport/HTTP failure; [] is a real "this JumpServer has
        // no user groups". Coercing the first to [] would wipe a good
        // snapshot and render "nobody is in any group" as a fact.
        $groups = PluginIntegrasipamanJmsApi::signedGet(self::GROUPS_PATH, ...$credentials);
        if (!is_array($groups)) {
            $say('The user-group list could not be read - the previous membership snapshot was left in place.');
            return -1;
        }

        $now         = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $group_rows  = [];
        $member_rows = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                $say('A user group was not an object - the previous membership snapshot was left in place.');
                return -1;
            }

            $group_id = trim((string) ($group['id'] ?? ''));
            if ($group_id === '') {
                // Skipping it would silently drop every member behind it.
                $say('A user group had no id - the previous membership snapshot was left in place.');
                return -1;
            }
            if (!array_key_exists('users_amount', $group) || !is_numeric($group['users_amount'])) {
                $say(sprintf('Group "%s" had no users_amount to check its member list against - the previous membership snapshot was left in place.', $group_id));
                return -1;
            }

            $expected  = (int) $group['users_amount'];
            $members   = [];
            $malformed = false;

            // Read 500 at a time rather than the default 100: the largest
            // group is 5124, and signedGetEach() follows "next" and falls
            // back to explicit offsets when a backend omits it.
            $complete = PluginIntegrasipamanJmsApi::signedGetEach(
                self::GROUP_USERS_PATH . '?group_id=' . urlencode($group_id),
                $base_url,
                $access_key_id,
                $access_key_secret,
                $org_id,
                static function (array $page) use (&$members, &$malformed, $group_id, $now): void {
                    foreach ($page as $user) {
                        if (!is_array($user)) {
                            $malformed = true;
                            continue;
                        }
                        $user_id = trim((string) ($user['id'] ?? ''));
                        if ($user_id === '') {
                            $malformed = true;
                            continue;
                        }
                        // Keyed by JumpServer user id: the same person twice
                        // in one group is one membership, and the count must
                        // be compared AFTER that collapse.
                        $members[$user_id] = [
                            'user_jms_id'       => $user_id,
                            'user_login'        => (string) ($user['username'] ?? ''),
                            'user_display'      => (string) ($user['name'] ?? ''),
                            'user_groups_jms_id' => $group_id,
                            'date_pulled'       => $now,
                        ];
                    }
                },
                self::MEMBER_PAGE_SIZE
            );

            if (!$complete || $malformed) {
                $say(sprintf('The member list of group "%s" was incomplete - the previous membership snapshot was left in place.', $group_id));
                return -1;
            }

            if (count($members) !== $expected) {
                // The probe's proof: the RIGHT source returns exactly
                // users_amount. A filter the server ignored returns the
                // global user list, and this is where that is caught.
                $say(sprintf(
                    'Group "%s" reports %d member(s) but returned %d - the previous membership snapshot was left in place.',
                    $group_id,
                    $expected,
                    count($members)
                ));
                return -1;
            }

            $group_rows[] = [
                'jms_id'        => $group_id,
                'name'          => (string) ($group['name'] ?? ''),
                'users_amount'  => $expected,
                'date_pulled'   => $now,
            ];
            foreach ($members as $member) {
                $member_rows[] = $member;
            }
        }

        // The marker is the only way an instance with zero groups can say
        // "collected, and there are none" rather than "never collected".
        $group_rows[] = [
            'jms_id'       => self::GROUP_PULL_MARKER,
            'name'         => '',
            'users_amount' => 0,
            'date_pulled'  => $now,
        ];

        $group_rows  = PluginIntegrasipamanConnection::stampRows($group_rows);
        $member_rows = PluginIntegrasipamanConnection::stampRows($member_rows);

        $DB->delete(self::GROUPS_TABLE, PluginIntegrasipamanConnection::ownRows());
        $DB->delete(self::MEMBERS_TABLE, PluginIntegrasipamanConnection::ownRows());

        foreach ($group_rows as $row) {
            if (!$DB->insert(self::GROUPS_TABLE, $row)) {
                trigger_error('Unable to store a JumpServer user group: ' . $DB->error(), E_USER_WARNING);
                return -1;
            }
        }
        foreach ($member_rows as $row) {
            if (!$DB->insert(self::MEMBERS_TABLE, $row)) {
                trigger_error('Unable to store a JumpServer group membership: ' . $DB->error(), E_USER_WARNING);
                return -1;
            }
        }

        $say(sprintf('%d group(s), %d membership row(s) collected.', count($groups), count($member_rows)));

        return count($member_rows);
    }

    /** JumpServer returns some of these as lists of scalars or of objects. */
    private static function flatten($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $v) {
            if (is_scalar($v)) {
                $parts[] = (string) $v;
            } elseif (is_array($v)) {
                $parts[] = (string) ($v['label'] ?? $v['value'] ?? $v['name'] ?? '');
            }
        }

        return implode(', ', array_filter($parts, static fn($p) => $p !== ''));
    }

    /** ISO-8601 from the API -> SQL datetime, or null. */
    private static function sqlDate($value): ?string
    {
        $value = (string) $value;
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        // TIMESTAMP spans 1970-01-01 00:00:01 to 2038-01-19 03:14:07 UTC.
        // JumpServer writes 2123-06-25 to mean "never expires", and MySQL
        // REJECTS that outright - which is how v3.3.0 came to install, render
        // its pane and collect nothing: the cron threw on the first rule it
        // read, while glpi_crontasks.lastrun filled in as if it had run.
        //
        // NULL is not a fallback here, it is the same statement: no expiry
        // date. The pane prints date_expired only for a rule JumpServer has
        // already marked invalid, so a never-expiring rule reads "Active"
        // either way and nothing on screen changes.
        //
        // Widening the column to DATETIME was tried in v3.4.0 and is wrong on
        // GLPI 11: Central.php:562 counts plugin tables in the deprecated-
        // datetime warning, so it would come back permanently; and
        // migration:timestamps (TimestampsCommand.php:79) walks that same
        // list and converts the column straight back, re-breaking the cron
        // for whoever runs the command the warning tells them to run.
        if ($ts < 1 || $ts > self::TIMESTAMP_MAX) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Computers that match a JumpServer host -> that host's asset UUIDs.
     *
     * THE CANDIDATE SET FOR THE WHOLE FEATURE. It must NOT be drawn from
     * glpi_plugin_jumpserver_assetmap: Correlation::discoverAssets() fills
     * that table from DISTINCT asset in the COMMANDS table, i.e. only
     * machines someone has already typed on. Restricted to those, this
     * feature answers "who may reach machines that already have session
     * history" - the question the existing access pane already answers -
     * and misses the dormant machine whose permission nobody has
     * exercised, which is the one an audit wants to see (spec section 1).
     *
     * Matching is name-or-address, the same pair
     * PluginIntegrasipamanCorrelation::findComputer() matches on and from
     * the same two tables it reads (glpi_computers.name, and
     * glpi_ipaddresses where mainitemtype = Computer). assetmap is still
     * read as a THIRD source, because correlation may have resolved an
     * asset string to a Computer whose GLPI name matches no host at all -
     * dropping it would lose mappings that work today.
     *
     * Three estate-wide reads per cron run, not three per Computer; pass
     * $computers_id to scope them when answering for a single Computer.
     *
     * @param array<string,string> $lookup name/address (lowercased) -> UUID
     * @return array<int, list<string>> computers_id -> asset UUIDs
     */
    public static function candidateAssets(array $lookup, ?int $computers_id = null): array
    {
        global $DB;

        if ($lookup === [] || ($computers_id !== null && $computers_id <= 0)) {
            return [];
        }

        $out = [];
        $add = static function ($cid, $key) use (&$out, $lookup): void {
            $cid = (int) $cid;
            $key = strtolower(trim((string) $key));
            if ($cid > 0 && $key !== '' && isset($lookup[$key])) {
                // Keyed by UUID: a Computer whose name AND address both
                // resolve to one host must not carry it twice, or it gets
                // two identical API calls.
                $out[$cid][$lookup[$key]] = true;
            }
        };

        // v4.0.0: a host of this JumpServer can only be a Computer inside
        // the connection's scope. Another subsidiary's Computer that happens
        // to share the name or the private address is not this host, and
        // must not be shown this JumpServer's permission rules.
        $connection = PluginIntegrasipamanConnection::current();
        $in_scope   = null;

        // Its own read, without the is_deleted / is_template filter of the
        // name match below: the address and asset-map matches never had
        // that filter, and scoping must not quietly add it.
        if ($connection !== null) {
            $in_scope = [];
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_computers',
                'WHERE'  => ['entities_id' => $connection->scope()]
                    + ($computers_id !== null ? ['id' => $computers_id] : []),
            ]) as $row) {
                $in_scope[(int) ($row['id'] ?? 0)] = true;
            }
        }

        $where = ['is_deleted' => 0, 'is_template' => 0];
        if ($computers_id !== null) {
            $where['id'] = $computers_id;
        }
        if ($connection !== null) {
            $where['entities_id'] = $connection->scope();
        }
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => $where,
        ]) as $row) {
            $add($row['id'] ?? 0, $row['name'] ?? '');
        }

        $where = ['mainitemtype' => 'Computer'];
        if ($computers_id !== null) {
            $where['mainitems_id'] = $computers_id;
        }
        foreach ($DB->request([
            'SELECT' => ['mainitems_id', 'name'],
            'FROM'   => 'glpi_ipaddresses',
            'WHERE'  => $where,
        ]) as $row) {
            if ($in_scope !== null && !isset($in_scope[(int) ($row['mainitems_id'] ?? 0)])) {
                continue;
            }
            $add($row['mainitems_id'] ?? 0, $row['name'] ?? '');
        }

        $where = ($computers_id !== null
            ? ['computers_id' => $computers_id]
            : [['NOT' => ['computers_id' => 0]]]) + PluginIntegrasipamanConnection::ownRows();
        foreach ($DB->request([
            'SELECT' => ['computers_id', 'parsed_name', 'parsed_ip'],
            'FROM'   => 'glpi_plugin_jumpserver_assetmap',
            'WHERE'  => $where,
        ]) as $row) {
            if ($in_scope !== null && !isset($in_scope[(int) ($row['computers_id'] ?? 0)])) {
                continue;
            }
            $add($row['computers_id'] ?? 0, $row['parsed_name'] ?? '');
            $add($row['computers_id'] ?? 0, $row['parsed_ip'] ?? '');
        }

        foreach ($out as $cid => $ids) {
            $out[$cid] = array_keys($ids);
        }

        return $out;
    }

    /**
     * Every JumpServer asset UUID that reaches this Computer.
     *
     * A Computer can be reached under more than one JumpServer asset: a
     * short name and an FQDN form resolve separately, and 73 Computers on
     * this instance are mapped more than once. Reading one of them yields
     * a shorter entitlement list that looks complete - the same trap
     * PluginIntegrasipamanAccessHistory::assetStringsFor() exists for.
     *
     * Delegates to candidateAssets() rather than matching again: two
     * matchers would be free to diverge, and a divergence here presents
     * as "the list looks complete but is missing a class of access".
     *
     * @return list<string>
     */
    public static function assetIdsFor(int $computers_id, array $lookup): array
    {
        if ($computers_id <= 0 || $lookup === []) {
            return [];
        }

        return self::candidateAssets($lookup, $computers_id)[$computers_id] ?? [];
    }

    /**
     * name/address -> JumpServer asset UUID, for every host in the org.
     *
     * Pulled whole rather than per asset: 645 hosts is one paginated call,
     * and it is the only source that covers assets with NO session history
     * - the dormant ones this feature exists for.
     *
     * @param array{0:string,1:string,2:string,3:string} $credentials
     * @return array<string,string>
     */
    public static function hostLookup(array $credentials): array
    {
        $hosts = PluginIntegrasipamanJmsApi::signedGet('/api/v1/assets/hosts/?limit=500', ...$credentials);

        $lookup = [];
        foreach ((array) $hosts as $h) {
            if (!is_array($h)) {
                continue;
            }
            $id = (string) ($h['id'] ?? '');
            if ($id === '') {
                continue;
            }
            foreach ([$h['name'] ?? '', $h['address'] ?? ''] as $key) {
                $key = strtolower(trim((string) $key));
                if ($key !== '') {
                    $lookup[$key] = $id;
                }
            }
        }

        return $lookup;
    }

    /**
     * Stamp live_checked_at on every assetmap row the PAMAN host list
     * confirms. Returns the number confirmed, or -1 when the lookup is
     * unusable.
     *
     * Absence is NEVER written. A row that is not in the list keeps the
     * timestamp it had, and ages; its age is the signal. An asset renamed
     * in PAMAN this morning is not dead, it is differently named, and a
     * hard "dead" flag could not tell the two apart.
     *
     * @param array<string,string> $lookup lowercased name AND address => UUID
     */
    public static function markLiveAssets(array $lookup): int
    {
        global $DB;

        // An empty lookup means the pull failed or returned nothing usable.
        // Treating it as "nothing is live" would age the whole estate out
        // in one run and empty the unclassified queue.
        if ($lookup === []) {
            return -1;
        }

        $now       = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $confirmed = 0;

        foreach ($DB->request([
            'SELECT' => ['id', 'parsed_name', 'parsed_ip'],
            'FROM'   => self::MAP_SOURCE_TABLE,
            // This connection's host list says nothing about another's assets.
            'WHERE'  => PluginIntegrasipamanConnection::ownRows(),
        ]) as $row) {
            foreach ([$row['parsed_name'] ?? '', $row['parsed_ip'] ?? ''] as $key) {
                $key = strtolower(trim((string) $key));
                if ($key !== '' && isset($lookup[$key])) {
                    $DB->update(self::MAP_SOURCE_TABLE, ['live_checked_at' => $now], ['id' => (int) $row['id']]);
                    $confirmed++;
                    continue 2;
                }
            }
        }

        return $confirmed;
    }

    /**
     * Re-resolve this Computer's entitlements from JumpServer.
     *
     * One call per mapped asset, unioned. The old mapping is deleted so a
     * permission that was revoked upstream disappears here too - leaving
     * stale rows would make a removed entitlement look live.
     *
     * The delete happens only once EVERY asset call has come back with a
     * list. signedGet() returns null on failure, so a bastion outage used
     * to wipe the cache and leave the pane announcing "Entitlements have
     * never been collected" - a transient fault rendered as a state that
     * never existed. A partial failure is worse still: the union silently
     * narrows and overwrites. An empty list IS a real answer and does
     * clear the rows; null is not.
     *
     * @param array{0:string,1:string,2:string,3:string} $credentials
     * @param list<string>|null $asset_ids pre-resolved by the cron, which
     *        already built the whole estate's map; null resolves here.
     * @return int rules mapped, or -1 if the pull failed and the previous
     *         data was left in place.
     */
    public static function refreshComputer(int $computers_id, array $credentials, array $lookup, ?array $asset_ids = null): int
    {
        global $DB;

        $asset_ids ??= self::assetIdsFor($computers_id, $lookup);
        if ($asset_ids === []) {
            return 0;
        }

        $rule_ids = [];
        foreach ($asset_ids as $asset_id) {
            $rules = PluginIntegrasipamanJmsApi::signedGet(
                '/api/v1/perms/asset-permissions/?limit=200&asset_id=' . urlencode($asset_id),
                ...$credentials
            );
            if (!is_array($rules)) {
                // Nothing has been written yet, so returning here leaves
                // the whole previous mapping for this Computer intact.
                return -1;
            }
            foreach ($rules as $rule) {
                $rid = is_array($rule) ? (string) ($rule['id'] ?? '') : '';
                if ($rid !== '') {
                    // Keyed by rule id: a rule reaching the Computer through
                    // two of its assets must be counted once.
                    $rule_ids[$rid] = $asset_id;
                }
            }
        }

        $DB->delete(self::MAP_TABLE, ['computers_id' => $computers_id]);

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // "Collected, and no rule reaches it" must leave a trace (code
        // review, 4.0.0). With no row at all the pane said "never been
        // collected", and batchOrder() put the Computer first on every run -
        // BATCH_LIMIT such Computers would starve the rest for good. The
        // marker is a row with no rule id: forComputer() joins on the rule
        // and so never lists it, lastRefreshed() and batchOrder() read its
        // date.
        if ($rule_ids === []) {
            $DB->updateOrInsert(self::MAP_TABLE, [
                'jms_asset_id'   => '',
                'date_refreshed' => $now,
            ] + PluginIntegrasipamanConnection::stamp(), [
                'computers_id'     => $computers_id,
                'permrules_jms_id' => self::NONE_MARKER,
            ]);
        }
        foreach ($rule_ids as $rid => $asset_id) {
            $DB->updateOrInsert(self::MAP_TABLE, [
                'jms_asset_id'   => $asset_id,
                'date_refreshed' => $now,
                // The connection's entity; syncLinked() moves the row to its
                // Computer's entity after the next correlation pass.
            ] + PluginIntegrasipamanConnection::stamp(), [
                'computers_id'     => $computers_id,
                'permrules_jms_id' => (string) $rid,
            ]);
        }

        return count($rule_ids);
    }

    /**
     * The rows the "Who has access" pane renders.
     *
     * An INNER JOIN on purpose: a rule id present in the mapping but not
     * in the rules cache is skipped. Those two come from separate API
     * calls, so a rule created between them exists in one and not the
     * other. Skipping is right - the alternative is a row naming access we
     * cannot describe.
     *
     * @return list<array<string,mixed>>
     */
    public static function forComputer(int $computers_id): array
    {
        global $DB;

        if ($computers_id <= 0) {
            return [];
        }

        $out = [];
        foreach ($DB->request([
            'SELECT'     => ['m.permrules_jms_id', 'r.name', 'r.users_json', 'r.user_groups_json',
                             'r.accounts', 'r.actions', 'r.is_valid', 'r.is_expired', 'r.date_expired'],
            'FROM'       => self::MAP_TABLE . ' AS m',
            // Same connection on both sides: two JumpServers may issue the
            // same rule id, and a mapping must describe its own rule.
            'INNER JOIN' => [self::RULES_TABLE . ' AS r' => ['ON' => [
                'r' => 'jms_id',
                'm' => 'permrules_jms_id',
                ['AND' => [new QueryExpression(
                    $DB->quoteName('r.' . PluginIntegrasipamanConnection::FK) . ' = ' . $DB->quoteName('m.' . PluginIntegrasipamanConnection::FK)
                )]],
            ]]],
            'WHERE'      => ['m.computers_id' => $computers_id] + PluginIntegrasipamanConnection::viewer('m'),
            'ORDER'      => 'r.is_valid DESC, r.name ASC',
        ]) as $row) {
            $valid = ((int) ($row['is_valid'] ?? 0) === 1) && ((int) ($row['is_expired'] ?? 0) === 0);

            foreach (self::members($row['users_json'] ?? null) as $u) {
                $out[] = [
                    'display'      => (string) ($u['display'] ?? $u['id'] ?? ''),
                    'type'         => 'user',
                    'via'          => (string) ($row['name'] ?? ''),
                    'accounts'     => (string) ($row['accounts'] ?? ''),
                    'actions'      => (string) ($row['actions'] ?? ''),
                    'valid'        => $valid,
                    'date_expired' => $row['date_expired'] ?? null,
                ];
            }

            foreach (self::members($row['user_groups_json'] ?? null) as $g) {
                $label = (string) ($g['display'] ?? $g['id'] ?? '');
                if (isset($g['users_amount'])) {
                    // The group is NOT expanded to members - say how many it
                    // holds so the reader knows what is behind the name.
                    $label .= sprintf(' (%d)', (int) $g['users_amount']);
                }
                $out[] = [
                    'display'      => $label,
                    'type'         => 'group',
                    'via'          => (string) ($row['name'] ?? ''),
                    'accounts'     => (string) ($row['accounts'] ?? ''),
                    'actions'      => (string) ($row['actions'] ?? ''),
                    'valid'        => $valid,
                    'date_expired' => $row['date_expired'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * Decode a members blob. Anything unparseable becomes no members: a
     * truncated write must not fatal a Computer form.
     *
     * @return list<array<string,mixed>>
     */
    private static function members($json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** When this Computer's mapping was last rewritten. */
    public static function lastRefreshed(int $computers_id): ?string
    {
        global $DB;

        if ($computers_id <= 0) {
            return null;
        }

        foreach ($DB->request([
            'SELECT' => ['date_refreshed'],
            'FROM'   => self::MAP_TABLE,
            'WHERE'  => ['computers_id' => $computers_id] + PluginIntegrasipamanConnection::viewer(),
            'ORDER'  => 'date_refreshed DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            return $row['date_refreshed'] ?? null;
        }

        return null;
    }

    /** Older than twice the cron interval, or never refreshed at all. */
    public static function isStale(?string $refreshed): bool
    {
        if ($refreshed === null || $refreshed === '') {
            return true;
        }
        $ts = strtotime($refreshed);

        return $ts === false || $ts < strtotime('-' . self::STALE_DAYS . ' days');
    }

    /**
     * One run refreshes at most this many Computers. Step 6 of the pull is
     * one API call per Computer; a far larger estate must degrade into a
     * slower full refresh, not into a run that never finishes.
     * Oldest-first, so every Computer comes round eventually.
     *
     * It must stay above the estate size (645 JumpServer hosts today), and
     * that is a correctness constraint, not headroom. The candidate set is
     * now every Computer matching a host, not just the ones with session
     * history. At 150 per weekly run, 645 Computers take five weeks to
     * come round - far past STALE_DAYS - so every pane would carry a
     * staleness warning permanently and the warning would stop meaning
     * anything. A full pass has to fit inside the staleness window.
     */
    public const BATCH_LIMIT = 700;

    /**
     * permrules_jms_id of the row that records "refreshed, no rule reaches
     * this Computer". Empty, so it can never equal a real rule id.
     */
    public const NONE_MARKER = '';

    /**
     * Candidates in refresh order: never refreshed first, then oldest
     * first, capped at $limit.
     *
     * Deliberately not a SQL ORDER BY on the batch query any more. The
     * old form was `SELECT DISTINCT a.computers_id ... ORDER BY
     * m.date_refreshed ASC`, which orders on a column outside the DISTINCT
     * select list: MySQL 8 rejects that under its default
     * ONLY_FULL_GROUP_BY (ER 3065) and the cron would simply never run,
     * while MariaDB accepts it - so the bug is invisible on one host and
     * total on another. Adding date_refreshed to that select list is not
     * the fix either: it changes what DISTINCT collapses, since a
     * Computer with several map rows carrying different timestamps would
     * come back once per timestamp. The candidate set is matched in PHP
     * against the host list now, so the ordering is one plain
     * single-table read plus a sort, with nothing to reject.
     *
     * @param list<int> $candidates
     * @return list<int>
     */
    private static function batchOrder(array $candidates, int $limit): array
    {
        global $DB;

        // First row per Computer under date_refreshed ASC is its oldest.
        $refreshed = [];
        foreach ($DB->request([
            'SELECT' => ['computers_id', 'date_refreshed'],
            'FROM'   => self::MAP_TABLE,
            'WHERE'  => PluginIntegrasipamanConnection::ownRows(),
            'ORDER'  => 'date_refreshed ASC',
        ]) as $row) {
            $cid = (int) ($row['computers_id'] ?? 0);
            if ($cid > 0 && !isset($refreshed[$cid])) {
                $refreshed[$cid] = true;
            }
        }

        $candidates = array_values(array_unique(array_filter(
            array_map('intval', $candidates),
            static fn(int $c): bool => $c > 0
        )));
        $wanted = array_flip($candidates);

        // Never refreshed goes first: those have no timestamp to sort by,
        // and they are exactly the dormant machines this feature is for.
        $order = array_values(array_filter(
            $candidates,
            static fn(int $c): bool => !isset($refreshed[$c])
        ));
        foreach (array_keys($refreshed) as $cid) {
            if (isset($wanted[$cid])) {
                $order[] = $cid;
            }
        }

        return array_slice($order, 0, $limit);
    }

    public static function cronInfo(string $name): array
    {
        if ($name === 'entitlement_pull') {
            return ['description' => __('Collect JumpServer asset permissions (who may reach which asset)', 'integrasipaman')];
        }

        return [];
    }

    public static function cronEntitlement_pull(CronTask $task): int
    {
        // v4.0.0: one collection per connection; one that fails or throws is
        // logged and skipped, the others still run.
        $connections = PluginIntegrasipamanConnection::all();
        if ($connections === []) {
            $task->log('JumpServer credentials are not configured - nothing collected.');
            return 0;
        }

        $several = count($connections) > 1;
        $any_ok  = false;

        $runs = PluginIntegrasipamanConnection::each(
            static function (PluginIntegrasipamanConnection $connection) use ($task, $several): int {
                if ($several) {
                    $task->log(sprintf('--- connection "%s" (entity %d) ---', $connection->label(), $connection->entity()));
                }
                return self::pullFor($task, $connection);
            }
        );

        foreach ($runs as $run) {
            if ($run['error'] !== '') {
                $task->log(sprintf('Connection "%s": the collection stopped: %s', $run['connection']->label(), $run['error']));
                continue;
            }
            $any_ok = $any_ok || $run['result'] === 1;
        }

        return $any_ok ? 1 : 0;
    }

    /**
     * The entitlement collection for one connection, which is the current
     * one.
     *
     * @return int 1 when something was collected, 0 otherwise.
     */
    private static function pullFor(CronTask $task, PluginIntegrasipamanConnection $connection): int
    {
        $credentials = $connection->credentials();
        foreach ($credentials as $v) {
            if ($v === '') {
                $task->log('JumpServer credentials are not configured - nothing collected.');
                return 0;
            }
        }

        $rules = self::pullRules($credentials);
        $task->log(sprintf('%d permission rule(s) cached.', $rules));

        // The report's other leg. A failure here does not stop the rule
        // pull or the per-Computer refresh: the previous snapshot stays and
        // the report says how old it is. A partial snapshot is never
        // written, so "old" is the only degradation possible.
        $members = self::pullGroupMembers($credentials, $task);
        if ($members < 0) {
            $task->log('Group membership could not be collected; the previous snapshot was left in place.');
        }

        // One host pull per run, shared by every Computer below. Resolving
        // per Computer would add BATCH_LIMIT calls for data that cannot
        // change within a single run.
        $lookup = self::hostLookup($credentials);
        $task->log(sprintf('%d host name/address key(s) resolved.', count($lookup)));

        $live = self::markLiveAssets($lookup);
        $task->log($live < 0
            ? 'Host list unusable - liveness left untouched, nothing aged out.'
            : sprintf(PluginIntegrasipamanServerLabel::in('%d asset(s) confirmed present in {server}.'), $live));

        // Every Computer that matches a host, dormant ones included.
        $candidates = self::candidateAssets($lookup);
        $task->log(sprintf('%d Computer(s) match a JumpServer host.', count($candidates)));

        // Oldest-first, so a batched estate still comes round in full.
        $computers = self::batchOrder(array_keys($candidates), self::BATCH_LIMIT);

        $mapped = 0;
        $failed = 0;
        foreach ($computers as $cid) {
            // The asset UUIDs are already resolved for the whole estate;
            // re-resolving per Computer would be three more reads each.
            $n = self::refreshComputer($cid, $credentials, $lookup, $candidates[$cid] ?? null);
            // -1: the bastion did not answer for that Computer. Its cached
            // rows were deliberately left in place, so it is a skip, not a
            // zero - counting it as one would report an outage as "nobody
            // has access".
            if ($n < 0) {
                $failed++;
                continue;
            }
            $mapped += $n;
        }

        $task->log(sprintf('%d Computer(s) refreshed, %d entitlement row(s) mapped.', count($computers) - $failed, $mapped));
        if ($failed > 0) {
            $task->log(sprintf('%d Computer(s) could not be refreshed; their previous entitlements were left in place.', $failed));
        }

        return ($rules > 0 || $mapped > 0 || $members > 0) ? 1 : 0;
    }
}
