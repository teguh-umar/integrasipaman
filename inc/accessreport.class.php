<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * User access management, Phase 1: which users can reach which assets and
 * which group grants it.
 *
 * The rule cache stores its members as JSON, which answers "who may reach
 * this Computer" but not "which assets may this person reach through a
 * group". This class reads that cache AND the membership snapshot the
 * entitlement cron stores beside it, and keeps the two questions apart:
 *
 *   - build() lists GRANT PATHS - one row per (asset, rule, member), where
 *     the member is a user or a group. It therefore reproduces the Computer
 *     tab's count exactly: Computer 46 is 4 rows, 2 of them a group. It does
 *     NOT expand a group into its members, because that would answer a
 *     different question (11 paths on 46) and silently inflate every total.
 *   - forUser() answers the other direction for one GLPI user. It expands
 *     the groups that person is in and collapses the result to one row per
 *     asset, with every path kept in the row.
 *
 * A group row names the group and, when membership was collected, how many
 * people are behind it. When it was not collected the row says so: an
 * unknown member list must never render as an empty one.
 *
 * Attribution in forUser() is deliberately narrow. A JumpServer membership
 * is this person's only when its login matches a mapped account's login (or
 * its display matches an account that carries no login at all). A similar
 * display name is never enough - the cost of guessing is showing someone
 * access they do not have, which is exactly what a report like this is read
 * to rule out.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/connection.class.php';
require_once __DIR__ . '/entitlement.class.php';

class PluginIntegrasipamanAccessReport
{
    public const RULES_TABLE   = PluginIntegrasipamanEntitlement::RULES_TABLE;
    public const MAP_TABLE     = PluginIntegrasipamanEntitlement::MAP_TABLE;
    public const GROUPS_TABLE  = PluginIntegrasipamanEntitlement::GROUPS_TABLE;
    public const MEMBERS_TABLE = PluginIntegrasipamanEntitlement::MEMBERS_TABLE;

    private const USERMAP_TABLE   = 'glpi_plugin_jumpserver_usermap';
    private const HOSTS_TABLE     = PluginIntegrasipamanEntitlement::HOSTS_TABLE;
    private const COMPUTERS_TABLE = 'glpi_computers';

    /**
     * Everything the report page draws, from three reads.
     *
     * Filters are applied to the grant rows and to the coverage warning, but
     * never to the group list's rule/asset counts: a filter answers "show me
     * this slice", not "this group only reaches this slice".
     *
     * @param array{computer?:string,user?:string,group?:string} $filters
     * @return array{
     *   grants: list<array<string,mixed>>,
     *   groups: list<array<string,mixed>>,
     *   summary: array{grants:int,direct:int,group:int,assets:int,groups:int},
     *   membership: array{collected:bool,at:?string,stale:bool},
     *   coverage: array{granted:int,known:int,missing:list<string>,mismatch:list<string>}
     * }
     */
    public static function build(array $filters = []): array
    {
        [$groups, $membership] = self::groupSnapshot();
        $all                   = self::grantRows($groups);
        $grants                = self::filterRows($all, $filters);

        return [
            'grants'     => $grants,
            'groups'     => self::groupTable($groups, $all),
            'summary'    => self::summarise($grants),
            'membership' => $membership,
            'coverage'   => self::coverage($grants, $groups),
        ];
    }

    /**
     * The same report, read from one GLPI user's side.
     *
     * Returns one row per asset, not per path: the same person can reach one
     * machine directly and through two groups, and three rows for one asset
     * reads as three machines. `paths` keeps every way in, so collapsing
     * hides nothing.
     *
     * @param array{accounts:list<string>,logins:list<string>,displays:list<string>,display_only:list<string>}|null $identities
     * @return array{
     *   identities: array<string,list<string>>,
     *   groups: list<array<string,mixed>>,
     *   rows: list<array<string,mixed>>,
     *   membership: array{collected:bool,at:?string,stale:bool}
     * }
     */
    public static function forUser(int $users_id, ?array $identities = null): array
    {
        [$groups, $membership] = self::groupSnapshot();
        $identities          ??= self::identitiesFor($users_id);

        if ($identities['accounts'] === []) {
            return [
                'identities' => $identities,
                'groups'     => [],
                'rows'       => [],
                'membership' => $membership,
            ];
        }

        $group_ids = [];
        $user_ids  = [];
        foreach ($groups as $group_key => $group) {
            foreach ($group['members'] as $member) {
                if (!self::membershipMatches($member, $identities)) {
                    continue;
                }
                $group_ids[$group_key] = true;
                if ($member['jms_id'] !== '') {
                    $user_ids[$member['jms_id']] = true;
                }
            }
        }

        $paths = [];
        foreach (self::grantRows($groups) as $row) {
            if ($row['type'] === 'group') {
                $key = self::groupKey((int) ($row['connection_id'] ?? 0), (string) $row['group_jms_id']);
                if (!isset($group_ids[$key])) {
                    continue;
                }
            } elseif (!self::directMatches($row, $identities, $user_ids)) {
                continue;
            }
            $paths[] = $row;
        }

        return [
            'identities' => $identities,
            'groups'     => array_values(array_intersect_key($groups, $group_ids)),
            'rows'       => self::collapse($paths),
            'membership' => $membership,
        ];
    }

    /**
     * The mapped JumpServer accounts of one GLPI user, split into the forms a
     * membership row can be matched against.
     *
     * NOT viewer-restricted, on purpose and for the same reason
     * PluginIntegrasipamanUserAccess::jmsUserStringsFor() is not: a usermap
     * row lives in the entity that owns the connection, so a viewer working
     * in a child entity would find no name here and the tab would be empty
     * although that entity holds this person's access. The names disclose
     * nothing by themselves; every grant row read below is viewer-restricted.
     *
     * @return array{accounts:list<string>,logins:list<string>,displays:list<string>,display_only:list<string>}
     */
    public static function identitiesFor(int $users_id): array
    {
        global $DB;

        $out = ['accounts' => [], 'logins' => [], 'displays' => [], 'display_only' => []];
        if ($users_id <= 0) {
            return $out;
        }

        foreach ($DB->request([
            'SELECT' => ['jms_user', 'parsed_login'],
            'FROM'   => self::USERMAP_TABLE,
            'WHERE'  => ['users_id' => $users_id],
            'ORDER'  => 'jms_user ASC',
        ]) as $row) {
            $jms_user = trim((string) ($row['jms_user'] ?? ''));
            if ($jms_user === '') {
                continue;
            }
            $login = trim((string) ($row['parsed_login'] ?? ''));

            // The same string can arrive as "Name (login)" or as a bare
            // login. Keep both halves: the login is the strong key, the
            // display half is only a fallback for an account with no login
            // at all.
            $display = trim((string) preg_replace('/\([^()]*\)\s*$/', '', $jms_user));
            if ($display === '') {
                $display = $jms_user;
            }

            self::push($out['accounts'], $jms_user);
            self::push($out['displays'], $display);

            if ($login === '') {
                self::push($out['logins'], $jms_user);
                self::push($out['display_only'], $display);
            } else {
                self::push($out['logins'], $login);
            }
        }

        return $out;
    }

    /**
     * A group's identity is (connection, JumpServer UUID). Two bastions may
     * issue the same UUID, and their member lists are not interchangeable.
     */
    private static function groupKey(int $connection_id, string $group_jms_id): string
    {
        return $connection_id . '|' . $group_jms_id;
    }

    /**
     * Whether group membership has been collected, when, and whether that is
     * stale - for the User and Group tabs, which read the per-person and
     * per-group report tables and so never call build().
     *
     * One read of the group table only, restricted to the viewer's
     * entities. The decision itself is membershipOf(), the same function
     * groupSnapshot() applies to the rows it reads, so the banner on those
     * tabs and the one on the asset table cannot disagree.
     *
     * @return array{collected:bool,at:?string,stale:bool}
     */
    public static function membershipState(): array
    {
        $rows = [];
        foreach (self::groupTableRows(['jms_id', 'date_pulled']) as $row) {
            $rows[] = $row;
        }

        return self::membershipOf($rows);
    }

    /**
     * The viewer's rows of the group table. The one place both readers of
     * that table (membershipState() and groupSnapshot()) build its WHERE, so
     * the banner and the report count the same rows.
     */
    private static function groupTableRows(array $select, string $order = ''): iterable
    {
        global $DB;

        $query = [
            'SELECT' => $select,
            'FROM'   => self::GROUPS_TABLE,
            'WHERE'  => PluginIntegrasipamanConnection::viewer(),
        ];
        if ($order !== '') {
            $query['ORDER'] = $order;
        }

        return $DB->request($query);
    }

    /**
     * `collected` is decided by the presence of any row, including the
     * empty-jms_id marker: an instance with zero groups still records that
     * the pull ran, and "nobody is in any group" must stay distinguishable
     * from "we never asked". `at` is the newest date_pulled.
     *
     * @param list<array<string,mixed>> $rows group-table rows
     * @return array{collected:bool,at:?string,stale:bool}
     */
    private static function membershipOf(array $rows): array
    {
        $at = null;
        foreach ($rows as $row) {
            $date = (string) ($row['date_pulled'] ?? '');
            if ($date !== '' && ($at === null || $date > $at)) {
                $at = $date;
            }
        }

        return [
            'collected' => $rows !== [],
            'at'        => $at,
            'stale'     => PluginIntegrasipamanEntitlement::isStale($at),
        ];
    }

    /**
     * Groups and their members, as the entitlement cron stored them.
     *
     * The membership state is membershipOf() over the same rows, so this
     * stays one read of the group table (see membershipState()).
     *
     * @return array{0: array<string,array<string,mixed>>, 1: array{collected:bool,at:?string,stale:bool}}
     */
    private static function groupSnapshot(): array
    {
        global $DB;

        $groups = [];
        $rows   = [];

        foreach (self::groupTableRows(
            ['jms_id', 'name', 'users_amount', 'date_pulled', PluginIntegrasipamanConnection::FK],
            'name ASC, jms_id ASC'
        ) as $row) {
            $rows[] = $row;

            $group_id = trim((string) ($row['jms_id'] ?? ''));
            if ($group_id === '') {
                continue; // the pull marker, not a group
            }

            // A group is per connection: two JumpServers may issue the same
            // UUID, and their members are not interchangeable.
            $connection = (int) ($row[PluginIntegrasipamanConnection::FK] ?? 0);
            $groups[self::groupKey($connection, $group_id)] = [
                'connection_id' => $connection,
                'jms_id'        => $group_id,
                'name'          => (string) ($row['name'] ?? ''),
                'users_amount'  => (int) ($row['users_amount'] ?? 0),
                'date_pulled'   => $row['date_pulled'] ?? null,
                'members'       => [],
            ];
        }

        foreach ($DB->request([
            'SELECT' => ['user_groups_jms_id', 'user_jms_id', 'user_login', 'user_display',
                         PluginIntegrasipamanConnection::FK],
            'FROM'   => self::MEMBERS_TABLE,
            'WHERE'  => PluginIntegrasipamanConnection::viewer(),
            'ORDER'  => 'user_login ASC, user_display ASC',
        ]) as $row) {
            $connection = (int) ($row[PluginIntegrasipamanConnection::FK] ?? 0);
            $group_id   = trim((string) ($row['user_groups_jms_id'] ?? ''));
            $key        = self::groupKey($connection, $group_id);
            if (!isset($groups[$key])) {
                // A member of a group the group list does not know. Ingest
                // writes both in one snapshot, so this is leftover data; it
                // cannot be attached to a group row without inventing one.
                continue;
            }
            $groups[$key]['members'][] = [
                'jms_id'  => trim((string) ($row['user_jms_id'] ?? '')),
                'login'   => (string) ($row['user_login'] ?? ''),
                'display' => (string) ($row['user_display'] ?? ''),
            ];
        }

        return [$groups, self::membershipOf($rows)];
    }

    /**
     * Expand every mapped rule into grant paths.
     *
     * Mirrors PluginIntegrasipamanEntitlement::forComputer() on purpose: the
     * same connection-paired join, the same JSON decoding, the same
     * valid/expired rule. The direct rows of this report ARE that pane's
     * direct rows, which is what makes the acceptance comparison meaningful.
     *
     * Deduplication is by (asset, rule, type, member id): the same relation
     * listed twice is one grant, but the same group reaching an asset through
     * two rules stays two paths - and a person with a direct and a group path
     * stays two rows, because each is a different way in.
     *
     * @param array<string,array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private static function grantRows(array $groups): array
    {
        global $DB;

        $rows = [];
        $seen = [];

        foreach ($DB->request([
            'SELECT'     => ['m.computers_id', 'c.name AS computer_name', 'c.id AS computer_row_id',
                             'h.name AS jms_asset_name', 'm.jms_asset_id',
                             'r.jms_id AS rule_jms_id', 'r.name AS rule_name',
                             'r.' . PluginIntegrasipamanConnection::FK . ' AS connection_id',
                             'r.users_json', 'r.user_groups_json', 'r.accounts', 'r.actions',
                             'r.is_valid', 'r.is_expired', 'r.date_expired'],
            'FROM'       => self::MAP_TABLE . ' AS m',
            'INNER JOIN' => [self::RULES_TABLE . ' AS r' => ['ON' => [
                'r' => 'jms_id',
                'm' => 'permrules_jms_id',
                ['AND' => [new QueryExpression(
                    $DB->quoteName('r.' . PluginIntegrasipamanConnection::FK)
                    . ' = ' . $DB->quoteName('m.' . PluginIntegrasipamanConnection::FK)
                )]],
            ]]],
            'LEFT JOIN'  => [
                self::COMPUTERS_TABLE . ' AS c' => ['ON' => [
                    'c' => 'id',
                    'm' => 'computers_id',
                ]],
                // What PAMAN calls the asset. Joined on the UUID, which is
                // the only key the orphan rows still carry - and the pull
                // never visits them again, so nothing else could fill it.
                self::HOSTS_TABLE . ' AS h' => ['ON' => [
                    'h' => 'jms_id',
                    'm' => 'jms_asset_id',
                    ['AND' => [new QueryExpression(
                        $DB->quoteName('h.' . PluginIntegrasipamanConnection::FK)
                        . ' = ' . $DB->quoteName('m.' . PluginIntegrasipamanConnection::FK)
                    )]],
                ]],
            ],
            'WHERE'      => PluginIntegrasipamanConnection::viewer('m'),
            'ORDER'      => 'c.name ASC, r.is_valid DESC, r.name ASC',
        ]) as $row) {
            $computers_id = (int) ($row['computers_id'] ?? 0);
            $connection   = (int) ($row['connection_id'] ?? 0);
            $rule_id      = (string) ($row['rule_jms_id'] ?? '');
            $rule_name    = trim((string) ($row['rule_name'] ?? ''));
            if ($rule_name === '') {
                $rule_name = $rule_id;
            }

            $valid = ((int) ($row['is_valid'] ?? 0) === 1)
                && ((int) ($row['is_expired'] ?? 0) === 0);

            // Whether GLPI still holds the asset is decided by the LEFT JOIN, never
            // by the name: an existing computer with a blank name and a computer that
            // has been deleted both read as '' once the name is cast to string.
            $base = [
                'computers_id'   => $computers_id,
                'computer_name'  => (string) ($row['computer_name'] ?? ''),
                'computer_known' => ($row['computer_row_id'] ?? null) !== null,
                'jms_asset_name' => (string) ($row['jms_asset_name'] ?? ''),
                'jms_asset_id'   => (string) ($row['jms_asset_id'] ?? ''),
                'connection_id'  => $connection,
                'via'            => $rule_name,
                'rule_jms_id'    => $rule_id,
                'accounts'       => (string) ($row['accounts'] ?? ''),
                'actions'        => (string) ($row['actions'] ?? ''),
                'valid'          => $valid,
                'date_expired'   => $row['date_expired'] ?? null,
            ];

            foreach (self::decodeMembers($row['users_json'] ?? null) as $user) {
                $id      = trim((string) ($user['id'] ?? ''));
                $display = trim((string) ($user['display'] ?? ''));
                if ($display === '') {
                    $display = $id;
                }
                if ($id === '' && $display === '') {
                    continue;
                }

                $key = $connection . '|' . $computers_id . '|' . $rule_id . '|user|' . ($id !== '' ? $id : $display);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $rows[] = $base + [
                    'type'               => 'user',
                    'member_id'          => $id,
                    'member_display'     => $display,
                    'group_jms_id'       => '',
                    'group_known'        => false,
                    'group_users_amount' => null,
                    'group_members'      => [],
                ];
            }

            foreach (self::decodeMembers($row['user_groups_json'] ?? null) as $group) {
                $group_id = trim((string) ($group['id'] ?? ''));
                $display  = trim((string) ($group['display'] ?? ''));
                $group_key = self::groupKey($connection, $group_id);
                $known    = $group_id !== '' && isset($groups[$group_key]);
                if ($display === '') {
                    $display = $known ? $groups[$group_key]['name'] : $group_id;
                }
                if ($group_id === '' && $display === '') {
                    continue;
                }

                $key = $connection . '|' . $computers_id . '|' . $rule_id . '|group|' . ($group_id !== '' ? $group_id : $display);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $rows[] = $base + [
                    'type'               => 'group',
                    'member_id'          => $group_id,
                    'member_display'     => $display,
                    'group_jms_id'       => $group_id,
                    'group_known'        => $known,
                    'group_users_amount' => $known ? $groups[$group_key]['users_amount'] : null,
                    'group_members'      => $known ? $groups[$group_key]['members'] : [],
                ];
            }
        }

        return $rows;
    }

    /**
     * Does GLPI still hold the asset this grant points at?
     *
     * A row that predates the flag is treated as known. Labelling a live asset
     * as deleted is the worse of the two errors: it would discredit a row an
     * auditor has to trust.
     *
     * @param array<string,mixed> $row
     */
    public static function assetIsKnown(array $row): bool
    {
        return !array_key_exists('computer_known', $row) || !empty($row['computer_known']);
    }

    /**
     * What goes in the asset cell: GLPI's name for the asset, else the name the
     * bastion has for it, else the bare id.
     *
     * The bastion's name is the only name left for an asset GLPI has deleted,
     * and it is why the host list is stored at all. GLPI's own name still wins
     * where there is one: that is the name the rest of GLPI shows.
     *
     * @param array<string,mixed> $row
     */
    public static function assetName(array $row): string
    {
        $name = trim((string) ($row['computer_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $jms = trim((string) ($row['jms_asset_name'] ?? ''));
        if ($jms !== '') {
            return $jms;
        }

        return '#' . (int) ($row['computers_id'] ?? 0);
    }

    /**
     * The note that follows the asset cell: the id for an asset GLPI still has,
     * and for one it no longer has, why the id is all that is left.
     *
     * The row is never dropped. A permission rule that still names a deleted
     * computer is live access to an asset nobody is maintaining - which is the
     * finding, not a blemish on the report.
     *
     * @param array<string,mixed> $row
     */
    public static function assetNote(array $row): string
    {
        $id = '#' . (int) ($row['computers_id'] ?? 0);

        // One rule for every case: the note carries the id unless the cell is
        // already the id, so a row never prints the same number twice - and
        // the id is never dropped just because some name was available.
        $parts = self::assetName($row) === $id ? [] : [$id];

        if (!self::assetIsKnown($row)) {
            $parts[] = __('asset no longer in GLPI', 'integrasipaman');
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function filterRows(array $rows, array $filters): array
    {
        $computer = trim((string) ($filters['computer'] ?? ''));
        $user     = trim((string) ($filters['user'] ?? ''));
        $group    = trim((string) ($filters['group'] ?? ''));

        if ($computer === '' && $user === '' && $group === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($computer, $user, $group): bool {
            if ($computer !== '') {
                $by_id = (string) ((int) ($row['computers_id'] ?? 0));
                if ($by_id !== $computer && !self::contains((string) ($row['computer_name'] ?? ''), $computer)) {
                    return false;
                }
            }

            if ($group !== '') {
                if (($row['type'] ?? '') !== 'group') {
                    return false;
                }
                $group_id = (string) ($row['group_jms_id'] ?? '');
                if ($group_id !== $group && !self::contains((string) ($row['member_display'] ?? ''), $group)) {
                    return false;
                }
            }

            if ($user !== '') {
                if (($row['type'] ?? '') === 'user') {
                    if ((string) ($row['member_id'] ?? '') !== $user
                        && !self::contains((string) ($row['member_display'] ?? ''), $user)) {
                        return false;
                    }
                } else {
                    $hit = false;
                    foreach ((array) ($row['group_members'] ?? []) as $member) {
                        if ((string) ($member['jms_id'] ?? '') === $user
                            || self::contains((string) ($member['login'] ?? ''), $user)
                            || self::contains((string) ($member['display'] ?? ''), $user)) {
                            $hit = true;
                            break;
                        }
                    }
                    if (!$hit) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /** @param list<array<string,mixed>> $grants */
    private static function summarise(array $grants): array
    {
        $assets = [];
        $groups = [];
        $direct = 0;
        $group  = 0;

        $gone        = [];
        $gone_paths  = 0;

        foreach ($grants as $row) {
            $assets[(int) ($row['computers_id'] ?? 0)] = true;
            if (!self::assetIsKnown($row)) {
                $gone[(int) ($row['computers_id'] ?? 0)] = true;
                $gone_paths++;
            }
            if (($row['type'] ?? '') === 'group') {
                $group++;
                if (($row['group_jms_id'] ?? '') !== '') {
                    $groups[(string) $row['group_jms_id']] = true;
                }
            } else {
                $direct++;
            }
        }

        return [
            'grants'      => count($grants),
            'direct'      => $direct,
            'group'       => $group,
            'assets'      => count($assets),
            'groups'      => count($groups),
            // Counted, not hidden: a rule still granting access to an asset
            // GLPI has lost is a finding, and a total that quietly excluded it
            // would be the wrong total.
            'gone_paths'  => $gone_paths,
            'gone_assets' => count($gone),
        ];
    }

    /**
     * The group list. Counts come from EVERY grant, never from the filtered
     * rows: a group's reach does not shrink because the page is filtered to
     * one Computer.
     *
     * @param array<string,array<string,mixed>> $groups
     * @param list<array<string,mixed>> $all_grants
     * @return list<array<string,mixed>>
     */
    private static function groupTable(array $groups, array $all_grants): array
    {
        $rules  = [];
        $assets = [];

        foreach ($all_grants as $row) {
            if (($row['type'] ?? '') !== 'group') {
                continue;
            }
            $group_id = (string) ($row['group_jms_id'] ?? '');
            if ($group_id === '') {
                continue;
            }
            $key = self::groupKey((int) ($row['connection_id'] ?? 0), $group_id);
            $rules[$key][(string) ($row['rule_jms_id'] ?? '')] = true;
            $assets[$key][(int) ($row['computers_id'] ?? 0)] = true;
        }

        $out = [];
        foreach ($groups as $group_key => $group) {
            $members = count($group['members']);
            $out[]   = [
                'connection_id' => (int) $group['connection_id'],
                'jms_id'        => (string) $group['jms_id'],
                'name'          => $group['name'],
                'members'       => $members,
                'users_amount'  => $group['users_amount'],
                'member_list'   => $group['members'],
                'rules'         => count($rules[$group_key] ?? []),
                'assets'        => count($assets[$group_key] ?? []),
                'date_pulled'   => $group['date_pulled'],
                'stale'         => PluginIntegrasipamanEntitlement::isStale($group['date_pulled'] ?? null),
                'mismatch'      => $members !== $group['users_amount'],
            ];
        }

        usort($out, static function (array $a, array $b): int {
            $left  = $a['name'] !== '' ? $a['name'] : $a['jms_id'];
            $right = $b['name'] !== '' ? $b['name'] : $b['jms_id'];

            return strcasecmp($left, $right);
        });

        return $out;
    }

    /**
     * Which granting groups this page can actually count members for.
     *
     * `missing` names groups the rules use but the membership snapshot does
     * not contain, so a reader is never left with "0 members" where the
     * truth is "not collected".
     *
     * @param list<array<string,mixed>> $grants
     * @param array<string,array<string,mixed>> $groups
     * @return array{granted:int,known:int,missing:list<string>,mismatch:list<string>}
     */
    private static function coverage(array $grants, array $groups): array
    {
        $granted = [];
        $missing = [];

        foreach ($grants as $row) {
            if (($row['type'] ?? '') !== 'group') {
                continue;
            }
            $group_id = (string) ($row['group_jms_id'] ?? '');
            if ($group_id === '') {
                continue;
            }
            $key = self::groupKey((int) ($row['connection_id'] ?? 0), $group_id);
            $granted[$key] = true;
            if (empty($row['group_known'])) {
                $missing[$key] = (string) ($row['member_display'] ?? $group_id);
            }
        }

        $known = 0;
        foreach (array_keys($granted) as $key) {
            if (isset($groups[$key])) {
                $known++;
            }
        }

        $mismatch = [];
        foreach ($groups as $key => $group) {
            if (count($group['members']) !== $group['users_amount']) {
                $mismatch[] = $group['name'] !== '' ? $group['name'] : (string) $group['jms_id'];
            }
        }

        sort($missing);
        sort($mismatch);

        return [
            'granted'  => count($granted),
            'known'    => $known,
            'missing'  => array_values($missing),
            'mismatch' => $mismatch,
        ];
    }

    /**
     * One row per asset, every path kept.
     *
     * @param list<array<string,mixed>> $paths
     * @return list<array<string,mixed>>
     */
    private static function collapse(array $paths): array
    {
        $by = [];

        foreach ($paths as $path) {
            $computers_id = (int) ($path['computers_id'] ?? 0);
            if (!isset($by[$computers_id])) {
                $by[$computers_id] = [
                    'computers_id'   => $computers_id,
                    'computer_name'  => (string) ($path['computer_name'] ?? ''),
                    'computer_known' => self::assetIsKnown($path),
                    'jms_asset_name' => (string) ($path['jms_asset_name'] ?? ''),
                    'jms_asset_id'   => (string) ($path['jms_asset_id'] ?? ''),
                    'paths'          => [],
                    'groups'         => [],
                    'accounts'       => [],
                    'actions'        => [],
                    'valid'          => false,
                    'expired_paths'  => 0,
                    'date_expired'   => null,
                ];
            }

            $by[$computers_id]['paths'][] = $path;

            if (($path['type'] ?? '') === 'group') {
                self::push($by[$computers_id]['groups'], (string) ($path['member_display'] ?? ''));
            }
            foreach (explode(',', (string) ($path['accounts'] ?? '')) as $account) {
                self::push($by[$computers_id]['accounts'], trim($account));
            }
            foreach (explode(',', (string) ($path['actions'] ?? '')) as $action) {
                self::push($by[$computers_id]['actions'], trim($action));
            }

            if (!empty($path['valid'])) {
                $by[$computers_id]['valid'] = true;
            } else {
                $by[$computers_id]['expired_paths']++;
                $date = (string) ($path['date_expired'] ?? '');
                if ($date !== '' && ($by[$computers_id]['date_expired'] === null || $date > $by[$computers_id]['date_expired'])) {
                    $by[$computers_id]['date_expired'] = $date;
                }
            }
        }

        $out = [];
        foreach ($by as $row) {
            $row['path_count'] = count($row['paths']);
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            $by_name = strcasecmp((string) $a['computer_name'], (string) $b['computer_name']);
            if ($by_name !== 0) {
                return $by_name;
            }

            return (int) $a['computers_id'] <=> (int) $b['computers_id'];
        });

        return $out;
    }

    /**
     * Does this JumpServer membership belong to the mapped person?
     *
     * Login first, and the display name only for an account that carries no
     * login. A display-name match against an account that DOES have a login
     * would attach a different account with a similar name - the one mistake
     * this report must not make.
     *
     * @param array{jms_id:string,login:string,display:string} $member
     * @param array{accounts:list<string>,logins:list<string>,displays:list<string>,display_only:list<string>} $identities
     */
    private static function membershipMatches(array $member, array $identities): bool
    {
        $login = (string) ($member['login'] ?? '');
        if ($login !== '' && self::inList($login, $identities['logins'])) {
            return true;
        }

        $display = (string) ($member['display'] ?? '');
        if ($display !== '' && self::inList($display, $identities['display_only'])) {
            return true;
        }

        return false;
    }

    /**
     * Does this rule's direct member belong to the mapped person?
     *
     * The UUID is strongest (it comes from a membership row already matched
     * by login). The full account string is next, then the login half of the
     * rule's display string. A display-only fallback is used only when the
     * person has no mapped login at all.
     *
     * @param array<string,mixed> $row
     * @param array{accounts:list<string>,logins:list<string>,displays:list<string>,display_only:list<string>} $identities
     * @param array<string,bool> $user_ids
     */
    private static function directMatches(array $row, array $identities, array $user_ids): bool
    {
        $id = (string) ($row['member_id'] ?? '');
        if ($id !== '' && isset($user_ids[$id])) {
            return true;
        }

        $display = trim((string) ($row['member_display'] ?? ''));
        if ($display === '') {
            return false;
        }
        if (self::inList($display, $identities['accounts'])) {
            return true;
        }

        $login = self::loginOf($display);
        if ($login !== '' && self::inList($login, $identities['logins'])) {
            return true;
        }

        return $identities['logins'] === [] && self::inList($display, $identities['displays']);
    }

    /** The final "(...)" group of a display string, or the string itself. */
    private static function loginOf(string $display): string
    {
        return preg_match('/\(([^()]*)\)\s*$/', $display, $m) ? trim($m[1]) : trim($display);
    }

    /**
     * Decode a members blob. Anything unparseable becomes no members: a
     * truncated write must not fatal the report.
     *
     * @return list<array<string,mixed>>
     */
    private static function decodeMembers($json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * Case-insensitive membership test, mirroring the collation GLPI uses
     * to resolve a parsed login against glpi_users.name. A case difference
     * must not lose a person's access, and must not invent one either.
     *
     * @param list<string> $list
     */
    private static function inList(string $needle, array $list): bool
    {
        $fold = function_exists('mb_strtolower') ? 'mb_strtolower' : 'strtolower';
        $left = $fold($needle);

        foreach ($list as $value) {
            if ($fold((string) $value) === $left) {
                return true;
            }
        }

        return false;
    }

    /** Case-insensitive substring test that tolerates a missing mbstring. */
    private static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        $pos = function_exists('mb_stripos')
            ? mb_stripos($haystack, $needle)
            : stripos($haystack, $needle);

        return $pos !== false;
    }

    /** @param list<string> $list */
    private static function push(array &$list, string $value): void
    {
        if ($value !== '' && !in_array($value, $list, true)) {
            $list[] = $value;
        }
    }
}
