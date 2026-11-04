<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The User and Group sub-tabs of User access, built once per pull.
 *
 * The entitlement cron stores the snapshot in pieces: rules with their
 * members as JSON, which rules reach which host (ruleassets), host names and
 * protocols, groups and their members, and every user with a system role.
 * Answering "what can this person reach" from those pieces on every page
 * view would decode every rule's JSON and expand every group per request.
 * This class does it once, after the pull, and writes two report tables:
 * one row per person and one row per group.
 *
 * compose() is pure - no database, no session, no clock - so the rules it
 * applies are tested on an invented fixture. rebuild() is the only part
 * that touches the database, and it touches only the current connection's
 * rows.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

// Not a CommonGLPI subclass: this registers no tab and is no itemtype.
require_once __DIR__ . '/connection.class.php';
require_once __DIR__ . '/entitlement.class.php';

class PluginIntegrasipamanAccessIndex
{
    public const USERS_TABLE  = 'glpi_plugin_jumpserver_accessusers';
    public const GROUPS_TABLE = 'glpi_plugin_jumpserver_accessgroups';

    private const STATE_LIVE     = 'live';
    private const STATE_EXPIRED  = 'expired';
    private const STATE_INACTIVE = 'inactive';

    /**
     * The snapshot as one row per person and one per group.
     *
     * A person is a row when a rule that reaches at least one host names
     * them, directly or through a group they are in. The rule's state does
     * not matter: a dead rule still makes the row, because "this person once
     * had access and it is now expired" is exactly what an audit reads for.
     *
     * @param array<string,array<string,mixed>> $rules       jms_id => {name, users, groups, accounts, actions, protocols, is_valid, is_expired}
     * @param list<array<string,mixed>>         $rule_assets {permrules_jms_id, jms_asset_id}
     * @param array<string,array<string,mixed>> $hosts       uuid => {name, protocols}
     * @param array<string,int>                 $links       uuid => computers_id
     * @param array<string,array<string,mixed>> $groups      jms_id => {name, comment, users_amount}
     * @param list<array<string,mixed>>         $members     {user_groups_jms_id, user_jms_id, user_login, user_display}
     * @param array<string,array<string,mixed>> $users       jms_id => {username, name, system_role}
     * @return array{users: list<array<string,mixed>>, groups: list<array<string,mixed>>}
     */
    public static function compose(
        array $rules,
        array $rule_assets,
        array $hosts,
        array $links,
        array $groups,
        array $members,
        array $users
    ): array {
        // rule id => [asset uuid => true]. A mapping row for a rule the rule
        // cache does not hold is skipped, as forComputer() skips it: the two
        // come from separate calls, and a row naming access we cannot
        // describe is worse than no row.
        $rule_hosts = [];
        foreach ($rule_assets as $ra) {
            $rid  = (string) ($ra['permrules_jms_id'] ?? '');
            $uuid = (string) ($ra['jms_asset_id'] ?? '');
            if ($rid !== '' && $uuid !== '' && isset($rules[$rid])) {
                $rule_hosts[$rid][$uuid] = true;
            }
        }

        // Group membership, both directions, plus the identity a membership
        // row carries for a user the user list does not know.
        $group_users = [];
        $user_groups = [];
        $fallback    = [];
        foreach ($members as $m) {
            $gid = (string) ($m['user_groups_jms_id'] ?? '');
            $uid = (string) ($m['user_jms_id'] ?? '');
            if ($gid === '' || $uid === '') {
                continue;
            }
            $group_users[$gid][$uid] = true;
            $user_groups[$uid][$gid] = true;
            $fallback[$uid]['login']   ??= (string) ($m['user_login'] ?? '');
            $fallback[$uid]['display'] ??= (string) ($m['user_display'] ?? '');
        }

        $group_name = static function (string $gid, string $display) use ($groups): string {
            $name = (string) ($groups[$gid]['name'] ?? '');
            if ($name !== '') {
                return $name;
            }
            return $display !== '' ? $display : $gid;
        };

        // uid => uuid => "rid|via" => entry
        $grants         = [];
        $relation_names = [];
        $group_rules    = [];
        $group_hosts    = [];

        foreach ($rules as $rid => $rule) {
            $rid = (string) $rid;

            // Groups count every rule that names them, with or without hosts.
            foreach ((array) ($rule['groups'] ?? []) as $g) {
                $gid = is_array($g) ? (string) ($g['id'] ?? '') : '';
                if ($gid === '') {
                    continue;
                }
                $group_rules[$gid][$rid] = true;
                foreach (array_keys($rule_hosts[$rid] ?? []) as $uuid) {
                    $group_hosts[$gid][$uuid] = true;
                }
            }

            if (empty($rule_hosts[$rid])) {
                continue;
            }

            // [uid, via]
            $paths = [];
            foreach ((array) ($rule['users'] ?? []) as $u) {
                $uid = is_array($u) ? (string) ($u['id'] ?? '') : '';
                if ($uid === '') {
                    continue;
                }
                $paths[] = [$uid, ''];
                $display = (string) ($u['display'] ?? '');
                if ($display !== '' && !isset($relation_names[$uid])) {
                    $relation_names[$uid] = $display;
                }
            }
            foreach ((array) ($rule['groups'] ?? []) as $g) {
                $gid = is_array($g) ? (string) ($g['id'] ?? '') : '';
                if ($gid === '') {
                    continue;
                }
                $via = $group_name($gid, (string) ($g['display'] ?? ''));
                foreach (array_keys($group_users[$gid] ?? []) as $uid) {
                    $paths[] = [(string) $uid, $via];
                }
            }

            $state = self::state($rule);
            foreach (array_keys($rule_hosts[$rid]) as $uuid) {
                $uuid  = (string) $uuid;
                $entry = [
                    'name'      => (string) ($rule['name'] ?? ''),
                    'state'     => $state,
                    'via'       => '',
                    'actions'   => (string) ($rule['actions'] ?? ''),
                    'accounts'  => (string) ($rule['accounts'] ?? ''),
                    'protocols' => self::effectiveProtocols(
                        (string) ($rule['protocols'] ?? ''),
                        (string) ($hosts[$uuid]['protocols'] ?? '')
                    ),
                ];
                foreach ($paths as [$uid, $via]) {
                    // Keyed by rule AND path: the same rule reaching a person
                    // directly and through G is two entries, and one person
                    // listed twice on one path is one.
                    $grants[$uid][$uuid][$rid . '|' . $via] = ['via' => $via] + $entry;
                }
            }
        }

        $user_rows = [];
        foreach ($grants as $uid => $assets) {
            $uid = (string) $uid;

            $detail   = [];
            $accounts = [];
            foreach ($assets as $uuid => $entries) {
                $uuid  = (string) $uuid;
                $list  = array_values($entries);
                usort($list, static function (array $a, array $b): int {
                    return strcasecmp($a['name'], $b['name']) ?: strcmp($a['via'], $b['via']);
                });
                $live = false;
                foreach ($list as $e) {
                    $live = $live || $e['state'] === self::STATE_LIVE;
                    foreach (explode(',', $e['accounts']) as $account) {
                        $account = trim($account);
                        if ($account !== '') {
                            $accounts[$account] = true;
                        }
                    }
                }
                $name = (string) ($hosts[$uuid]['name'] ?? '');
                $detail[] = [
                    'id'           => $uuid,
                    'name'         => $name !== '' ? $name : $uuid,
                    'computers_id' => (int) ($links[$uuid] ?? 0),
                    'live'         => $live,
                    'rules'        => $list,
                ];
            }
            usort($detail, static function (array $a, array $b): int {
                return strcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']);
            });

            $group_list = [];
            foreach (array_keys($user_groups[$uid] ?? []) as $gid) {
                $group_list[] = $group_name((string) $gid, '');
            }
            $group_list = self::sortedUnique($group_list);

            $known = $users[$uid] ?? null;
            $name  = is_array($known) ? (string) ($known['name'] ?? '') : '';
            if ($name === '') {
                $name = $relation_names[$uid] ?? ($fallback[$uid]['display'] ?? '');
            }
            $username = is_array($known) ? (string) ($known['username'] ?? '') : '';
            if ($username === '') {
                $username = $fallback[$uid]['login'] ?? '';
            }

            $user_rows[] = [
                'user_jms_id'   => $uid,
                // The column widths of the report table: a longer value from
                // the server would be a SQL error on strict MySQL.
                'name'          => mb_substr($name, 0, 191),
                'username'      => mb_substr($username, 0, 191),
                'system_role'   => mb_substr(is_array($known) ? (string) ($known['system_role'] ?? '') : '', 0, 191),
                'group_names'   => implode(', ', $group_list),
                'asset_names'   => implode(', ', array_column($detail, 'name')),
                'account_names' => implode(', ', self::sortedUnique(array_keys($accounts))),
                'assets_detail' => (string) json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'assets_count'  => count($detail),
            ];
        }
        usort($user_rows, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']) ?: strcmp($a['user_jms_id'], $b['user_jms_id']);
        });

        $group_rows = [];
        foreach ($groups as $gid => $group) {
            $gid = (string) $gid;
            if ($gid === PluginIntegrasipamanEntitlement::GROUP_PULL_MARKER) {
                continue;
            }
            $group_rows[] = [
                'jms_id'       => $gid,
                'name'         => mb_substr((string) ($group['name'] ?? ''), 0, 191),
                'comment'      => mb_substr((string) ($group['comment'] ?? ''), 0, 255),
                'users_amount' => (int) ($group['users_amount'] ?? 0),
                'rules_count'  => count($group_rules[$gid] ?? []),
                'assets_count' => count($group_hosts[$gid] ?? []),
            ];
        }
        usort($group_rows, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']) ?: strcmp($a['jms_id'], $b['jms_id']);
        });

        return ['users' => $user_rows, 'groups' => $group_rows];
    }

    /**
     * Read the current connection's snapshot, compose it, and replace that
     * connection's report rows.
     *
     * Without a current connection nothing is written: ownRows() is then [],
     * and a DELETE with an empty WHERE would wipe every connection's report.
     *
     * The DELETEs and INSERTs run in one transaction; on any failure it is
     * rolled back and the exception rethrown, so the previous report stays.
     *
     * @return array{users:int, groups:int} rows written
     * @throws \Throwable when a write fails (after the rollback)
     */
    public static function rebuild(): array
    {
        global $DB;

        $out = ['users' => 0, 'groups' => 0];
        $own = PluginIntegrasipamanConnection::ownRows();
        if ($own === []) {
            return $out;
        }

        $E = 'PluginIntegrasipamanEntitlement';

        $rules = [];
        foreach ($DB->request([
            'SELECT' => ['jms_id', 'name', 'users_json', 'user_groups_json', 'accounts', 'actions',
                         'protocols', 'is_valid', 'is_expired'],
            'FROM'   => $E::RULES_TABLE,
            'WHERE'  => $own,
        ]) as $row) {
            $rid = (string) ($row['jms_id'] ?? '');
            if ($rid === '') {
                continue;
            }
            $rules[$rid] = [
                'name'       => (string) ($row['name'] ?? ''),
                'users'      => self::decodeMembers($row['users_json'] ?? null),
                'groups'     => self::decodeMembers($row['user_groups_json'] ?? null),
                'accounts'   => (string) ($row['accounts'] ?? ''),
                'actions'    => (string) ($row['actions'] ?? ''),
                'protocols'  => (string) ($row['protocols'] ?? ''),
                'is_valid'   => (int) ($row['is_valid'] ?? 0),
                'is_expired' => (int) ($row['is_expired'] ?? 0),
            ];
        }

        $rule_assets = [];
        foreach ($DB->request([
            'SELECT' => ['permrules_jms_id', 'jms_asset_id'],
            'FROM'   => $E::RULEASSETS_TABLE,
            'WHERE'  => $own,
        ]) as $row) {
            $rule_assets[] = [
                'permrules_jms_id' => (string) ($row['permrules_jms_id'] ?? ''),
                'jms_asset_id'     => (string) ($row['jms_asset_id'] ?? ''),
            ];
        }

        $hosts     = [];
        $addresses = [];
        foreach ($DB->request([
            'SELECT' => ['jms_id', 'name', 'address', 'protocols'],
            'FROM'   => $E::HOSTS_TABLE,
            'WHERE'  => $own,
        ]) as $row) {
            $uuid = (string) ($row['jms_id'] ?? '');
            if ($uuid === '') {
                continue;
            }
            $hosts[$uuid] = [
                'name'      => (string) ($row['name'] ?? ''),
                'protocols' => (string) ($row['protocols'] ?? ''),
            ];
            $addresses[$uuid] = (string) ($row['address'] ?? '');
        }

        $links = self::links($hosts, $addresses, $own);

        $groups = [];
        foreach ($DB->request([
            'SELECT' => ['jms_id', 'name', 'comment', 'users_amount'],
            'FROM'   => $E::GROUPS_TABLE,
            'WHERE'  => $own,
        ]) as $row) {
            $gid = (string) ($row['jms_id'] ?? '');
            // The pull marker records that a pull ran; it is not a group.
            if ($gid === $E::GROUP_PULL_MARKER) {
                continue;
            }
            $groups[$gid] = [
                'name'         => (string) ($row['name'] ?? ''),
                'comment'      => (string) ($row['comment'] ?? ''),
                'users_amount' => (int) ($row['users_amount'] ?? 0),
            ];
        }

        $members = [];
        foreach ($DB->request([
            'SELECT' => ['user_groups_jms_id', 'user_jms_id', 'user_login', 'user_display'],
            'FROM'   => $E::MEMBERS_TABLE,
            'WHERE'  => $own,
        ]) as $row) {
            $members[] = [
                'user_groups_jms_id' => (string) ($row['user_groups_jms_id'] ?? ''),
                'user_jms_id'        => (string) ($row['user_jms_id'] ?? ''),
                'user_login'         => (string) ($row['user_login'] ?? ''),
                'user_display'       => (string) ($row['user_display'] ?? ''),
            ];
        }

        $users = [];
        foreach ($DB->request([
            'SELECT' => ['jms_id', 'username', 'name', 'system_role'],
            'FROM'   => $E::JMSUSERS_TABLE,
            'WHERE'  => $own,
        ]) as $row) {
            $uid = (string) ($row['jms_id'] ?? '');
            if ($uid === '') {
                continue;
            }
            $users[$uid] = [
                'username'    => (string) ($row['username'] ?? ''),
                'name'        => (string) ($row['name'] ?? ''),
                'system_role' => (string) ($row['system_role'] ?? ''),
            ];
        }

        $composed = self::compose($rules, $rule_assets, $hosts, $links, $groups, $members, $users);

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $tables = ['users' => self::USERS_TABLE, 'groups' => self::GROUPS_TABLE];
        $batches = [];
        foreach ($tables as $key => $table) {
            $rows = [];
            foreach ($composed[$key] as $row) {
                $rows[] = $row + ['date_built' => $now];
            }
            $batches[$key] = PluginIntegrasipamanConnection::stampRows($rows);
        }

        // All or nothing. On GLPI 11 a failed statement throws, so without a
        // transaction one bad row would leave the DELETE done and the report
        // half written; rolled back, the previous report stays whole. The
        // caller (pullFor) catches and logs what is rethrown here.
        $DB->beginTransaction();
        try {
            foreach ($tables as $key => $table) {
                $DB->delete($table, $own);
                foreach ($batches[$key] as $row) {
                    if (!$DB->insert($table, $row)) {
                        throw new \RuntimeException(sprintf('Unable to store a row in %s: %s', $table, $DB->error()));
                    }
                    $out[$key]++;
                }
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }

        return $out;
    }

    // --- the pages: User, Group and Asset sub-tabs ---------------------------

    /**
     * Which sub-tab a request to User access is for.
     *
     * Any asset-table parameter means the asset table: an old bookmark with
     * ?computer= and the CSV export (?format=csv) must keep landing on the
     * page that serves them, never be redirected away.
     *
     * @param array<string,mixed> $get
     * @return 'user'|'group'|'asset'
     */
    public static function tabFor(array $get): string
    {
        $tab = $get['tab'] ?? '';
        $tab = is_string($tab) ? $tab : '';

        if ($tab === 'asset') {
            return 'asset';
        }
        foreach (['computer', 'user', 'group', 'format'] as $key) {
            if (array_key_exists($key, $get)) {
                return 'asset';
            }
        }

        return $tab === 'group' ? 'group' : 'user';
    }

    /**
     * The three sub-tabs, inside the one 'useraccess' menu option.
     *
     * Nested in the page as front/session.php does: the suite sub-nav has no
     * second level, and lib/pgnsuite/Menu.php is shared byte-for-byte.
     */
    public static function renderTabs(string $active): void
    {
        $base = Plugin::getWebDir('integrasipaman') . '/front/';

        echo '<ul class="nav nav-tabs mb-3">';
        foreach ([
            'user'  => [__('User', 'integrasipaman'), 'ti-user', $base . 'accessuser.php'],
            'group' => [__('Group', 'integrasipaman'), 'ti-users-group', $base . 'accessgroup.php'],
            'asset' => [__('Asset', 'integrasipaman'), 'ti-device-desktop', $base . 'useraccess.php?tab=asset'],
        ] as $key => [$label, $icon, $url]) {
            echo '<li class="nav-item"><a class="nav-link' . ($active === $key ? ' active' : '') . '"'
                . ' href="' . htmlescape($url) . '">'
                . '<i class="ti ' . $icon . ' me-1"></i>' . htmlescape($label) . '</a></li>';
        }
        echo '</ul>';
    }

    /**
     * The membership warning of the User and Group tabs.
     *
     * The same two sentences the asset table's $warnings closure prints for
     * `collected` and `stale` (front/useraccess.php), word for word, so one
     * translation covers all three tabs. The coverage warnings stay on the
     * asset table: they are about its grant paths.
     *
     * @param array{collected:bool,at:?string,stale:bool} $membership PluginIntegrasipamanAccessReport::membershipState()
     */
    public static function renderMembershipBanner(array $membership): void
    {
        if (!($membership['collected'] ?? false)) {
            echo '<div class="alert alert-warning">';
            echo htmlescape(__(
                'Group membership has never been collected, so this report cannot count access granted through a group. The rows below are only the people and groups named directly on each rule. Group-granted access is missing until the entitlement cron runs.',
                'integrasipaman'
            ));
            echo '</div>';
            return;
        }

        if ($membership['stale'] ?? false) {
            echo '<div class="alert alert-warning">';
            echo htmlescape(sprintf(
                __('Group membership was last collected at %s. It is used as-is; the counts below may be out of date until the entitlement cron runs again.', 'integrasipaman'),
                (string) ($membership['at'] ?? __('an unknown time', 'integrasipaman'))
            ));
            echo '</div>';
        }
    }

    /**
     * Whether the viewer can see at least one row of a report table.
     *
     * Read through viewer(), the same entity restriction as membershipState()
     * and the rows Search lists, so "not built" is never said over rows the
     * viewer can see, nor hidden by rows of an entity they cannot.
     *
     * @param string $table self::USERS_TABLE or self::GROUPS_TABLE
     */
    public static function reportBuilt(string $table): bool
    {
        global $DB;

        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => $table,
            'WHERE'  => PluginIntegrasipamanConnection::viewer(),
            'LIMIT'  => 1,
        ]) as $row) {
            return true;
        }

        return false;
    }

    /**
     * The notice of the User and Group tabs between the upgrade and the first
     * entitlement run: the report tables are empty then, while the 4.10 group
     * table is already filled, so the membership banner says nothing and an
     * empty list would read as "nobody has access".
     *
     * @param string $table self::USERS_TABLE or self::GROUPS_TABLE
     */
    public static function renderNotBuiltNotice(string $table): void
    {
        if (self::reportBuilt($table)) {
            return;
        }

        echo '<div class="alert alert-info">';
        echo htmlescape(PluginIntegrasipamanServerLabel::in(__('This report has not been built yet. It is built by the entitlement pull from {server}, and this page fills after the next run.', 'integrasipaman')));
        echo '</div>';
    }

    /**
     * The one script behind both pop-ups, delegated on document.
     *
     * Search reloads its result over AJAX on every page and sort, and the
     * cells (AccessUser / AccessGroup) carry no script of their own, so a
     * handler bound to the cells would be gone after the first reload. Each
     * qtip is made on first use and flagged, so it is made once per element.
     * The options mirror Html::showToolTip().
     */
    public static function popupScript(): string
    {
        $loading = json_encode(
            __('Loading...', 'integrasipaman'),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        $js = <<<JS
\$(document).on('mouseenter', '.integrasipaman-chip', function () {
    var \$chip = \$(this);
    if (\$chip.data('integrasipamanQtip')) {
        return;
    }
    // A chip linked to its Computer sits inside an <a>; the pop-up follows
    // whichever of the two is outermost.
    var \$tip = \$chip.next('.tooltip-invisible');
    if (!\$tip.length) {
        \$tip = \$chip.parent('a').next('.tooltip-invisible');
    }
    if (!\$tip.length) {
        return;
    }
    \$chip.data('integrasipamanQtip', true);
    \$chip.qtip({
        position: { viewport: \$(window) },
        content: { text: \$tip },
        style: { classes: 'qtip-shadow qtip-bootstrap' },
        hide: { fixed: true, delay: 200 },
        show: { solo: true, ready: true }
    });
});
\$(document).on('click', '.integrasipaman-members', function (event) {
    event.preventDefault();
    var \$count = \$(this);
    if (\$count.data('integrasipamanQtip')) {
        return;
    }
    var url = \$count.attr('data-members-url');
    if (!url) {
        return;
    }
    \$count.data('integrasipamanQtip', true);
    \$count.qtip({
        position: { viewport: \$(window) },
        content: { text: {$loading}, ajax: { url: url, type: 'GET', data: {} } },
        style: { classes: 'qtip-shadow qtip-bootstrap' },
        hide: { fixed: true, delay: 200 },
        show: 'click'
    });
    // This click made the pop-up; open it now rather than on the next one.
    \$count.qtip('show');
});
JS;

        return Html::scriptBlock($js);
    }

    /**
     * Host UUID -> Computer, for hosts correlation has tied to one.
     *
     * The method the orphan-relink probe proved on the VM: the host's name,
     * then its address, lower-cased, matched to assetmap.parsed_name /
     * parsed_ip; then only Computers that still exist and are not deleted.
     *
     * @param array<string,array{name:string,protocols:string}> $hosts
     * @param array<string,string>                              $addresses uuid => address
     * @param array<string,int>                                 $own       ownRows()
     * @return array<string,int>
     */
    private static function links(array $hosts, array $addresses, array $own): array
    {
        global $DB;

        if ($hosts === []) {
            return [];
        }

        $by_key = [];
        foreach ($DB->request([
            'SELECT' => ['computers_id', 'parsed_name', 'parsed_ip'],
            'FROM'   => PluginIntegrasipamanEntitlement::MAP_SOURCE_TABLE,
            'WHERE'  => [['NOT' => ['computers_id' => 0]]] + $own,
        ]) as $row) {
            $cid = (int) ($row['computers_id'] ?? 0);
            foreach ([$row['parsed_name'] ?? '', $row['parsed_ip'] ?? ''] as $key) {
                $key = strtolower(trim((string) $key));
                if ($cid > 0 && $key !== '') {
                    $by_key[$key] = $cid;
                }
            }
        }
        if ($by_key === []) {
            return [];
        }

        $wanted = [];
        foreach ($hosts as $uuid => $host) {
            foreach ([$host['name'] ?? '', $addresses[$uuid] ?? ''] as $key) {
                $key = strtolower(trim((string) $key));
                if ($key !== '' && isset($by_key[$key])) {
                    $wanted[(string) $uuid] = $by_key[$key];
                    break;
                }
            }
        }
        if ($wanted === []) {
            return [];
        }

        $alive = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => ['id' => array_values(array_unique($wanted)), 'is_deleted' => 0],
        ]) as $row) {
            $alive[(int) ($row['id'] ?? 0)] = true;
        }

        $links = [];
        foreach ($wanted as $uuid => $cid) {
            if (isset($alive[$cid])) {
                $links[$uuid] = $cid;
            }
        }

        return $links;
    }

    /** is_expired wins over is_valid; anything else is live. */
    private static function state(array $rule): string
    {
        if ((int) ($rule['is_expired'] ?? 0) === 1) {
            return self::STATE_EXPIRED;
        }
        if ((int) ($rule['is_valid'] ?? 0) === 0) {
            return self::STATE_INACTIVE;
        }

        return self::STATE_LIVE;
    }

    /**
     * 'all' or nothing on the rule means whatever the asset offers;
     * otherwise the two lists' intersection, in the asset's order.
     *
     * @return list<string>
     */
    private static function effectiveProtocols(string $rule, string $asset): array
    {
        $asset_list = self::splitList($asset);
        $rule_list  = array_map('strtolower', self::splitList($rule));

        if ($rule_list === [] || in_array('all', $rule_list, true)) {
            return $asset_list;
        }

        return array_values(array_filter(
            $asset_list,
            static fn (string $p): bool => in_array(strtolower($p), $rule_list, true)
        ));
    }

    /** @return list<string> */
    private static function splitList(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sortedUnique(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn ($v): bool => $v !== '')));
        usort($values, static fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));

        return $values;
    }

    /**
     * A members blob as a list of {id, display}. Anything unparseable is no
     * members, as in Entitlement::members(): a truncated write must not stop
     * the rebuild.
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
}
