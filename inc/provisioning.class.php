<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One row per Computer that has ever been judged eligible for PAMAN.
 * Holds the link to the PAMAN host, the lifecycle status, and the audit
 * trail of who pressed what, when, against which ticket.
 *
 * This class never speaks to PAMAN. Every method here is either a pure
 * function over arrays (so tests/ can exercise it without a GLPI
 * bootstrap) or a local DB read/write. All HTTP lives in
 * PluginIntegrasipamanProvisioningAction.
 *
 * See docs/superpowers/specs/2026-09-23-asset-provisioning-design.md.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/serverlabel.class.php';
require_once __DIR__ . '/rulesettings.class.php';

class PluginIntegrasipamanProvisioning extends CommonDBTM
{
    public static $rightname = 'plugin_integrasipaman_provisioning';

    public const TABLE = 'glpi_plugin_jumpserver_provisioning';

    /** Eligible for PAMAN, never sent. */
    /** The PAMAN host list the entitlement pull stores (= PluginIntegrasipamanEntitlement::HOSTS_TABLE). */
    private const HOSTS_TABLE = 'glpi_plugin_jumpserver_hosts';

    public const STATUS_ELIGIBLE = 'eligible';
    /** Live in PAMAN, and GLPI agrees with what was sent. */
    public const STATUS_PROVISIONED = 'provisioned';
    /** Live in PAMAN, but the GLPI data has moved on. Flagged, never auto-applied. */
    public const STATUS_DRIFT = 'drift';
    /** Computer went to a dispose status (or was deleted in GLPI). Awaiting a ticketed removal. */
    public const STATUS_PENDING_REMOVAL = 'pending_removal';
    /** Removed from PAMAN. Terminal: the row stays as the audit record. */
    public const STATUS_REMOVED = 'removed';
    /** The last attempt errored. Always retryable; last_error carries PAMAN's own words. */
    public const STATUS_FAILED = 'failed';

    /**
     * The only journeys a row may take. Read as from => [allowed destinations].
     *
     * Everything in the queue trusts this column, so an illegal transition
     * is not cosmetic - `eligible -> removed` would mean issuing a DELETE
     * for an asset id that was never obtained, and `removed -> anything`
     * would mean rewriting an audit record.
     */
    private const TRANSITIONS = [
        self::STATUS_ELIGIBLE        => [self::STATUS_PROVISIONED, self::STATUS_PENDING_REMOVAL, self::STATUS_FAILED],
        self::STATUS_PROVISIONED     => [self::STATUS_DRIFT, self::STATUS_PENDING_REMOVAL, self::STATUS_FAILED],
        self::STATUS_DRIFT           => [self::STATUS_PROVISIONED, self::STATUS_PENDING_REMOVAL, self::STATUS_FAILED],
        self::STATUS_PENDING_REMOVAL => [self::STATUS_REMOVED, self::STATUS_FAILED],
        // A failure is only ever a failed attempt at one of the above, so it
        // may return to any of their destinations - never to `drift`, which
        // is decided by the hash, not by an attempt.
        self::STATUS_FAILED          => [self::STATUS_PROVISIONED, self::STATUS_PENDING_REMOVAL, self::STATUS_REMOVED],
        self::STATUS_REMOVED         => [],
    ];

    /** @return list<string> */
    public static function allStatuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** A terminal status has nowhere left to go. */
    public static function isTerminal(string $status): bool
    {
        return (self::TRANSITIONS[$status] ?? []) === [];
    }

    /**
     * Addresses that must never be sent to PAMAN, as prefixes.
     *
     * A host in the bastion carrying a loopback or APIPA address is worse
     * than no host at all: it looks provisioned and can never be reached.
     */
    private const UNUSABLE_PREFIXES = ['127.', '0.0.0.0', '169.254.'];

    /**
     * Choose the one address to send to PAMAN out of everything GLPI holds
     * for this Computer.
     *
     * Deterministic on purpose. The old code did LIMIT 1 with no ORDER BY,
     * so a Computer with several network ports sent whichever row the
     * database happened to return first - and a choice that changes between
     * runs makes every scan report drift.
     *
     * @param list<string> $ips Raw address strings, any order.
     */
    public static function pickAddress(array $ips): string
    {
        $usable = [];

        foreach ($ips as $ip) {
            $ip = trim((string) $ip);

            if ($ip === '' || strpos($ip, ':') !== false) {
                continue; // empty, or IPv6: PAMAN hosts here are addressed by IPv4
            }

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            foreach (self::UNUSABLE_PREFIXES as $bad) {
                if (strpos($ip, $bad) === 0) {
                    continue 2;
                }
            }

            $usable[] = $ip;
        }

        if ($usable === []) {
            return '';
        }

        // Sort so the choice never depends on row order.
        sort($usable, SORT_NATURAL);

        return $usable[0];
    }

    /**
     * Is this Computer eligible to be provisioned into PAMAN?
     *
     * Every filter that is left empty filters nothing. Note what that means
     * on a fresh install: with no filters set, every Computer with a usable
     * address is eligible. That is deliberate - the brakes on this feature
     * are the mapping requirement and the button, not this function - but it
     * is why the Config page tells the admin to set at least a type filter.
     *
     * @param array{computertypes_id?:int, states_id?:int, entities_id?:int, is_deleted?:int} $computer
     * @param array{types?:list<int>, states?:list<int>, entities?:list<int>, ip_prefixes?:list<string>} $criteria
     * @return array{eligible:bool, reason:string}
     */
    public static function isEligible(array $computer, string $address, array $criteria): array
    {
        if ((int) ($computer['is_deleted'] ?? 0) === 1) {
            return ['eligible' => false, 'reason' => __('Deleted in GLPI', 'integrasipaman')];
        }

        if ($address === '') {
            return [
                'eligible' => false,
                'reason'   => __('No usable IPv4 address in GLPI', 'integrasipaman'),
            ];
        }

        $types = $criteria['types'] ?? [];
        if ($types !== [] && !in_array((int) ($computer['computertypes_id'] ?? 0), array_map('intval', $types), true)) {
            return ['eligible' => false, 'reason' => __('Computer type is not in the eligible list', 'integrasipaman')];
        }

        $states = $criteria['states'] ?? [];
        if ($states !== [] && !in_array((int) ($computer['states_id'] ?? 0), array_map('intval', $states), true)) {
            return ['eligible' => false, 'reason' => __('Status is not in the eligible list', 'integrasipaman')];
        }

        $entities = $criteria['entities'] ?? [];
        if ($entities !== [] && !in_array((int) ($computer['entities_id'] ?? 0), array_map('intval', $entities), true)) {
            return ['eligible' => false, 'reason' => __('Entity is not in the eligible list', 'integrasipaman')];
        }

        $prefixes = array_filter(array_map('trim', $criteria['ip_prefixes'] ?? []), static fn ($p) => $p !== '');
        if ($prefixes !== []) {
            $matched = false;
            foreach ($prefixes as $prefix) {
                if (strpos($address, $prefix) === 0) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return ['eligible' => false, 'reason' => __('Address is outside every eligible IP prefix', 'integrasipaman')];
            }
        }

        return ['eligible' => true, 'reason' => ''];
    }

    /**
     * Has this Computer left service? Either its status is one the admin
     * nominated as dispose/dismantle/decommissioned, or it was deleted in
     * GLPI. Both mean the same thing for the bastion: it should not still
     * be reachable there.
     *
     * Marking is automatic; removal never is.
     *
     * @param array{states_id?:int, is_deleted?:int} $computer
     * @param array{dispose_states?:list<int>} $criteria
     */
    /**
     * The PAMAN host this Computer already is, out of a pulled host list
     * (v4.13.1). Identity only - the address, or the name, case-insensitive -
     * never the type/OS mapping, which matters only for a new Send. When the
     * two point at different hosts the address wins: it is what a Send would
     * collide on. Null when neither matches.
     *
     * @param list<array{jms_id:string,name:string,address:string}> $hosts
     * @return array{jms_id:string,name:string,address:string}|null
     */
    public static function matchExisting(string $name, string $address, array $hosts): ?array
    {
        $name    = mb_strtolower(trim($name));
        $address = trim($address);
        $by_name = null;
        foreach ($hosts as $host) {
            if ($address !== '' && trim((string) ($host['address'] ?? '')) === $address) {
                return $host;
            }
            if ($by_name === null && $name !== '' && mb_strtolower(trim((string) ($host['name'] ?? ''))) === $name) {
                $by_name = $host;
            }
        }

        return $by_name;
    }

    /**
     * matchExisting() against the hosts the entitlement pull stored for the
     * CURRENT connection. A host added by hand since the last pull is not
     * seen here; the Send path still looks the address up live and adopts.
     *
     * @return array{jms_id:string,name:string,address:string}|null
     */
    public static function existingHost(string $name, string $address): ?array
    {
        global $DB;

        $name    = trim($name);
        $address = trim($address);
        if ($name === '' && $address === '') {
            return null;
        }

        $or = [];
        if ($address !== '') {
            $or[] = ['address' => $address];
        }
        if ($name !== '') {
            $or[] = ['name' => $name];
        }

        $hosts = [];
        foreach ($DB->request([
            'SELECT' => ['jms_id', 'name', 'address'],
            'FROM'   => self::HOSTS_TABLE,
            'WHERE'  => ['OR' => $or] + PluginIntegrasipamanConnection::ownRows(),
        ]) as $host) {
            $hosts[] = $host;
        }

        return self::matchExisting($name, $address, $hosts);
    }

    /** existingHost() as the connection that serves the Computer's entity. */
    private static function existingHostFor(Computer $item, string $address): ?array
    {
        $connection = PluginIntegrasipamanConnection::forEntity((int) ($item->fields['entities_id'] ?? 0));
        $name       = (string) ($item->fields['name'] ?? '');

        return $connection === null
            ? self::existingHost($name, $address)
            : PluginIntegrasipamanConnection::using($connection, static fn () => self::existingHost($name, $address));
    }

    /** The tab's line for a Computer PAMAN already holds, Send withheld. */
    private static function renderExisting(array $host): void
    {
        echo '<div class="alert alert-info mb-0">' . htmlescape(sprintf(
            PluginIntegrasipamanServerLabel::in(__('This Computer is already in {server} as %1$s (%2$s). It is not queued for provisioning, so it cannot be sent a second time.', 'integrasipaman')),
            (string) ($host['name'] ?? ''),
            (string) ($host['address'] ?? '')
        )) . '</div>';
    }

    public static function isDisposed(array $computer, array $criteria): bool
    {
        if ((int) ($computer['is_deleted'] ?? 0) === 1) {
            return true;
        }

        $dispose = array_map('intval', $criteria['dispose_states'] ?? []);

        return $dispose !== [] && in_array((int) ($computer['states_id'] ?? 0), $dispose, true);
    }

    /**
     * Resolve the PAMAN node and platform for one Computer from the two
     * mapping tables. Source id 0 in either lookup is the default row.
     *
     * A missing mapping is NOT a failure: it returns a `blocked` sentence
     * naming what the admin has to fill in, and the queue page renders the
     * row with its checkbox disabled. Marking it failed would bury a
     * configuration gap in an error state that looks like an outage.
     *
     * @param array{computertypes_id?:int, type_name?:string, operatingsystems_id?:int, os_name?:string} $computer
     * @param array<int, array{id:string, label:string}> $node_lookup
     * @param array<int, array{id:int, label:string}>    $platform_lookup
     * @return array{node_id:string, node_label:string, platform_id:int, platform_label:string, blocked:string}
     */
    public static function resolveTargets(array $computer, array $node_lookup, array $platform_lookup): array
    {
        $type_id = (int) ($computer['computertypes_id'] ?? 0);
        $os_id   = (int) ($computer['operatingsystems_id'] ?? 0);

        $node     = $node_lookup[$type_id]   ?? $node_lookup[0]   ?? null;
        $platform = $platform_lookup[$os_id] ?? $platform_lookup[0] ?? null;

        $node_id     = trim((string) ($node['id'] ?? ''));
        $platform_id = (int) ($platform['id'] ?? 0);

        $blocked = [];

        if ($node_id === '') {
            $blocked[] = sprintf(
                PluginIntegrasipamanServerLabel::in(__('Computer type %s is not mapped to a {server} node', 'integrasipaman')),
                (string) ($computer['type_name'] ?? '#' . $type_id)
            );
        }

        if ($platform_id <= 0) {
            $blocked[] = sprintf(
                PluginIntegrasipamanServerLabel::in(__('OS %s is not mapped to a {server} platform', 'integrasipaman')),
                (string) ($computer['os_name'] ?? '#' . $os_id)
            );
        }

        return [
            'node_id'        => $node_id,
            'node_label'     => (string) ($node['label'] ?? ''),
            'platform_id'    => $platform_id,
            'platform_label' => (string) ($platform['label'] ?? ''),
            'blocked'        => implode('; ', $blocked),
        ];
    }

    public static function getTable($classname = null)
    {
        return self::TABLE;
    }


    public static function getTypeName($nb = 0)
    {
        return PluginIntegrasipamanServerLabel::in(_n('{server} provisioning', '{server} provisioning', $nb, 'integrasipaman'));
    }

    /**
     * Sort array keys at every level, so a fingerprint or a comparison
     * depends on content alone.
     *
     * A top-level ksort() is not enough: the payload nests `platform` and
     * `nodes`, and PHP's !== is order-sensitive for arrays. Without this,
     * the first nested object to gain a second key would flag every
     * provisioned asset in the estate as drifted overnight.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeDeep(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::normalizeDeep($item);
        }

        // A list keeps its order - the order of `nodes` is meaningful.
        // Only associative keys are sorted.
        if (!array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    /**
     * The JumpServer v4.10.19 host payload.
     *
     * `address`, not `ip`: `ip` is the v2 field name, and sending it is one
     * of the four reasons the previous implementation never created a
     * single host. `platform` and `nodes` are required by v4 and were
     * absent entirely.
     *
     * @param array{id?:int, name?:string, comment?:string} $computer
     * @return array<string, mixed>
     */
    public static function buildPayload(array $computer, string $address, string $node_id, int $platform_id): array
    {
        $comment = trim((string) ($computer['comment'] ?? ''));
        $origin  = sprintf(__('GLPI Computer #%d', 'integrasipaman'), (int) ($computer['id'] ?? 0));

        return [
            'name'      => (string) ($computer['name'] ?? ''),
            'address'   => $address,
            'platform'  => ['id' => $platform_id],
            'nodes'     => [['id' => $node_id]],
            // The origin marker is always present: someone looking at a host
            // in PAMAN should be able to find the GLPI record behind it.
            'comment'   => $comment === '' ? $origin : $comment . ' (' . $origin . ')',
            'is_active' => true,
        ];
    }

    /**
     * Fingerprint of a payload, used as the drift baseline.
     *
     * Keys are sorted at every level so the hash depends on content only. Without
     * that, reordering a key in buildPayload() would flag every provisioned
     * asset in the estate as drifted overnight.
     *
     * @param array<string, mixed> $payload
     */
    public static function payloadHash(array $payload): string
    {
        $normalized = self::normalizeDeep($payload);

        return hash('sha256', (string) json_encode($normalized));
    }

    /**
     * Which payload fields have moved since the asset was last sent.
     *
     * Returns names, not a boolean, because "VM-HQ-WEB-03: name, address"
     * tells an admin whether to resend; "this asset has drifted" does not.
     *
     * @param array<string, mixed> $sent    The payload as last pushed to PAMAN.
     * @param array<string, mixed> $current The payload GLPI would send now.
     * @return list<string>
     */
    public static function driftFields(array $sent, array $current): array
    {
        $fields = array_unique(array_merge(array_keys($sent), array_keys($current)));
        sort($fields);

        $drifted = [];

        foreach ($fields as $field) {
            if (self::normalizeDeep($sent[$field] ?? null) !== self::normalizeDeep($current[$field] ?? null)) {
                $drifted[] = $field;
            }
        }

        return $drifted;
    }

    /**
     * Read the six provisioning settings into the shape the pure
     * evaluators expect.
     *
     * @return array{types:list<int>, states:list<int>, dispose_states:list<int>, entities:list<int>, ip_prefixes:list<string>, ticket_statuses:list<int>}
     */
    public static function criteriaFromConfig(?PluginIntegrasipamanConnection $connection = null): array
    {
        // v4.4.0: the settings of a connection - its own, inherited, or the
        // instance defaults. With none given, the one the running code works
        // on (refreshOne() runs as the connection that serves the Computer).
        $config = PluginIntegrasipamanRuleSettings::forConnection($connection ?? PluginIntegrasipamanConnection::current());

        $ids = static fn (string $key): array => array_values(array_filter(
            array_map('intval', explode(',', (string) ($config[$key] ?? ''))),
            static fn ($n) => $n >= 0
        ));

        return [
            'types'           => $ids('provisioning_eligible_types'),
            'states'          => $ids('provisioning_eligible_states'),
            'dispose_states'  => $ids('provisioning_dispose_states'),
            'entities'        => $ids('provisioning_eligible_entities'),
            'ip_prefixes'     => array_values(array_filter(array_map(
                'trim',
                preg_split('/\R/', (string) ($config['provisioning_ip_prefixes'] ?? '')) ?: []
            ), static fn ($p) => $p !== '')),
            'ticket_statuses' => $ids('provisioning_ticket_statuses'),
        ];
    }

    /**
     * Every IP address GLPI holds for this Computer, unfiltered.
     * pickAddress() decides which one is sent.
     *
     * Carried over from the deleted assetsync.class.php, which is the one
     * part of it that was correct - except that it took LIMIT 1.
     *
     * @return list<string>
     */
    public static function addressesFor(int $computers_id): array
    {
        global $DB;

        if ($computers_id <= 0) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT'     => ['glpi_ipaddresses.name AS ip'],
            'FROM'       => 'glpi_networkports',
            'INNER JOIN' => [
                'glpi_networknames' => [
                    'FKEY' => ['glpi_networkports' => 'id', 'glpi_networknames' => 'items_id'],
                ],
                'glpi_ipaddresses' => [
                    'FKEY' => ['glpi_networknames' => 'id', 'glpi_ipaddresses' => 'items_id'],
                ],
            ],
            'WHERE' => [
                'glpi_networkports.itemtype' => 'Computer',
                'glpi_networkports.items_id' => $computers_id,
                'glpi_networknames.itemtype' => 'NetworkPort',
                'glpi_ipaddresses.itemtype'  => 'NetworkName',
                'glpi_ipaddresses.name'      => ['<>', ''],
            ],
        ]);

        $out = [];
        foreach ($iterator as $row) {
            $out[] = (string) $row['ip'];
        }

        return $out;
    }

    /**
     * Recompute one Computer's queue row from GLPI. Local only: this is
     * called from the item_* hooks, and a Computer save must never wait on
     * the network.
     *
     * Rules, in order:
     *  - disposed (dispose status, or deleted in GLPI) and already in PAMAN
     *    => pending_removal;
     *  - disposed and never sent => the row is dropped, there is nothing to
     *    take out;
     *  - no longer eligible and never sent => dropped;
     *  - no longer eligible but already in PAMAN => left alone. A criteria
     *    change must never quietly orphan an asset that is live in the
     *    bastion;
     *  - eligible and never sent => eligible;
     *  - provisioned and the payload hash moved => drift.
     *
     * $criteria/$node_lookup/$platform_lookup are optional pre-loaded
     * inputs: the item_* hooks call this once per Computer and can leave
     * them null (fetched here), while scanAll() loads them once for the
     * whole sweep and passes them in, so a full-estate scan is one config
     * read and two mapping-table reads, not one per Computer.
     */
    public static function refreshOne(
        int $computers_id,
        ?array $criteria = null,
        ?array $node_lookup = null,
        ?array $platform_lookup = null
    ): void {
        global $DB;

        if ($computers_id <= 0) {
            return;
        }

        $computer = new Computer();
        if (!$computer->getFromDB($computers_id)) {
            return;
        }

        // v4.0.0: a Computer goes to the JumpServer that serves its entity.
        // The rest runs as that connection, so the queue row is stamped
        // with it and the node / platform mappings read are its own.
        $connection = PluginIntegrasipamanConnection::forEntity((int) ($computer->fields['entities_id'] ?? 0));
        if ($connection !== null) {
            PluginIntegrasipamanConnection::using(
                $connection,
                static fn () => self::refreshFor($computer, true, $criteria, $node_lookup, $platform_lookup)
            );
            return;
        }

        // No connection serves this entity. With no connection at all the
        // instance behaves as before (an unconfigured plugin still keeps a
        // queue); otherwise the Computer has no bastion to be sent to.
        self::refreshFor($computer, PluginIntegrasipamanConnection::all() === [], $criteria, $node_lookup, $platform_lookup);
    }

    /**
     * refreshOne() for a loaded Computer, as the current connection.
     *
     * @param bool $served false when connections exist and none serves the
     *                     Computer's entity: it is then treated like an
     *                     ineligible one - a queue row that never reached
     *                     the bastion is dropped, one that did is left alone.
     */
    private static function refreshFor(
        Computer $computer,
        bool $served,
        ?array $criteria,
        ?array $node_lookup,
        ?array $platform_lookup
    ): void {
        $computers_id = (int) $computer->fields['id'];

        $criteria = $criteria ?? self::criteriaFromConfig();
        $fields   = $computer->fields;
        $address  = self::pickAddress(self::addressesFor($computers_id));

        $row = new self();
        $has_row = $row->getFromDBByCrit(['computers_id' => $computers_id]);
        $status  = $has_row ? (string) $row->fields['status'] : '';
        $in_paman = $has_row && trim((string) $row->fields['jumpserver_asset_id']) !== '';

        if ($has_row && $status !== '' && !in_array($status, self::allStatuses(), true)) {
            trigger_error(
                'Provisioning row for computer #' . $computers_id
                . ' has an unrecognised status "' . $status . '"; leaving it alone.',
                E_USER_WARNING
            );
            return;
        }

        if (self::isDisposed($fields, $criteria)) {
            if (!$in_paman) {
                if ($has_row && $status === self::STATUS_ELIGIBLE) {
                    $row->delete(['id' => (int) $row->fields['id']], true);
                }
                return;
            }

            if ($status !== self::STATUS_REMOVED
                && self::canTransition($status, self::STATUS_PENDING_REMOVAL)
            ) {
                $row->update([
                    'id'     => (int) $row->fields['id'],
                    'status' => self::STATUS_PENDING_REMOVAL,
                ]);
            }
            return;
        }

        // v4.13.1: a host PAMAN already holds - created by hand, or by an
        // earlier tool - is not a candidate for a Send, whatever the type/OS
        // mapping says. It is treated like an ineligible Computer: a queue
        // row that never reached PAMAN through us is dropped.
        $existing = (!$in_paman && $served)
            ? self::existingHost((string) ($fields['name'] ?? ''), $address)
            : null;

        $verdict = $served
            ? self::isEligible($fields, $address, $criteria)
            : ['eligible' => false];
        if ($existing !== null) {
            $verdict = ['eligible' => false];
        }

        if (!$verdict['eligible']) {
            if ($has_row && !$in_paman && $status === self::STATUS_ELIGIBLE) {
                $row->delete(['id' => (int) $row->fields['id']], true);
            }
            // An ineligible row that IS in PAMAN is left exactly as it is.
            return;
        }

        if (!$has_row) {
            $fresh = new self();
            $fresh->add([
                'computers_id' => $computers_id,
                'status'       => self::STATUS_ELIGIBLE,
                // The connection's entity; syncLinked() moves the row to its
                // Computer's entity after the next correlation pass.
            ] + PluginIntegrasipamanConnection::stamp());
            return;
        }

        if ($status === self::STATUS_PROVISIONED) {
            $node_lookup     = $node_lookup ?? PluginIntegrasipamanNodeMap::asLookup();
            $platform_lookup = $platform_lookup ?? PluginIntegrasipamanPlatformMap::asLookup();

            $targets = self::resolveTargets(
                $fields + [
                    'type_name' => Dropdown::getDropdownName('glpi_computertypes', (int) ($fields['computertypes_id'] ?? 0)),
                    'os_name'   => Dropdown::getDropdownName('glpi_operatingsystems', (int) ($fields['operatingsystems_id'] ?? 0)),
                ],
                $node_lookup,
                $platform_lookup
            );

            $current = self::buildPayload($fields, $address, $targets['node_id'], $targets['platform_id']);

            if (self::payloadHash($current) !== (string) $row->fields['payload_hash']
                && self::canTransition($status, self::STATUS_DRIFT)
            ) {
                $row->update([
                    'id'           => (int) $row->fields['id'],
                    'status'       => self::STATUS_DRIFT,
                    // The stored hash is the baseline of what was SENT, so
                    // the field list is recomputed from the sent payload the
                    // queue page rebuilds - here we only record that it moved.
                    'drift_fields' => '',
                ]);
            }
        }
    }

    /**
     * Sweep every Computer in GLPI - including rows with is_deleted = 1,
     * since a Computer deleted in GLPI that is still live in PAMAN needs
     * marking for removal - and recompute its provisioning queue row via
     * refreshOne().
     *
     * Local only, like refreshOne(): this never writes to PAMAN. The
     * criteria and the two mapping lookups are read once here rather than
     * once per Computer inside refreshOne().
     *
     * @return array{seen:int, added:int, dropped:int, drifted:int, pending_removal:int}
     */
    public static function scanAll(): array
    {
        global $DB;

        // v4.4.0: the criteria belong to a connection too, so they are read
        // once per connection below, not once for the sweep.
        $criteria_for = [];

        // v4.0.0: the two mapping lookups belong to a connection, so they
        // can no longer be read once for the whole sweep. They are read once
        // per CONNECTION instead, here, and handed to refreshOne() for the
        // Computers that connection serves.
        $lookups = [];
        foreach (PluginIntegrasipamanConnection::each(static fn () => [
            PluginIntegrasipamanNodeMap::asLookup(),
            PluginIntegrasipamanPlatformMap::asLookup(),
        ]) as $id => $run) {
            if ($run['error'] === '') {
                $lookups[$id] = $run['result'];
            }
        }
        if (PluginIntegrasipamanConnection::all() === []) {
            $lookups[0] = [PluginIntegrasipamanNodeMap::asLookup(), PluginIntegrasipamanPlatformMap::asLookup()];
        }

        $summary = ['seen' => 0, 'added' => 0, 'dropped' => 0, 'drifted' => 0, 'pending_removal' => 0];

        $iterator = $DB->request([
            'SELECT' => ['id', 'entities_id'],
            'FROM'   => 'glpi_computers',
        ]);

        foreach ($iterator as $computer_row) {
            $computers_id = (int) $computer_row['id'];
            $summary['seen']++;

            $before = new self();
            $had_before = $before->getFromDBByCrit(['computers_id' => $computers_id]);
            $status_before = $had_before ? (string) $before->fields['status'] : null;

            $serving = PluginIntegrasipamanConnection::forEntity((int) ($computer_row['entities_id'] ?? 0));
            [$node_lookup, $platform_lookup] = $lookups[$serving === null ? 0 : $serving->id()] ?? [null, null];

            $criteria_key = $serving === null ? 0 : $serving->id();
            $criteria_for[$criteria_key] ??= self::criteriaFromConfig($serving);

            self::refreshOne($computers_id, $criteria_for[$criteria_key], $node_lookup, $platform_lookup);

            $after = new self();
            $has_after = $after->getFromDBByCrit(['computers_id' => $computers_id]);
            $status_after = $has_after ? (string) $after->fields['status'] : null;

            if (!$had_before && $has_after) {
                $summary['added']++;
            } elseif ($had_before && !$has_after) {
                $summary['dropped']++;
            } elseif ($status_before !== $status_after) {
                if ($status_after === self::STATUS_DRIFT) {
                    $summary['drifted']++;
                } elseif ($status_after === self::STATUS_PENDING_REMOVAL) {
                    $summary['pending_removal']++;
                }
            }
        }

        return $summary;
    }

    /**
     * A Computer was purged from GLPI - the row is already gone from
     * glpi_computers, so refreshOne() cannot help here: its first act is a
     * getFromDB() that now fails, and the daily scan will never see this id
     * again either.
     *
     * If the asset reached PAMAN, it must still be taken out, so the row is
     * marked for a ticketed removal like any other disposal. If it never
     * reached PAMAN there is nothing to remove and the queue row is dropped.
     */
    public static function onComputerPurged(int $computers_id): void
    {
        if ($computers_id <= 0) {
            return;
        }

        $row = new self();

        if (!$row->getFromDBByCrit(['computers_id' => $computers_id])) {
            return;
        }

        $status   = (string) $row->fields['status'];
        $in_paman = trim((string) $row->fields['jumpserver_asset_id']) !== '';

        if (!in_array($status, self::allStatuses(), true)) {
            trigger_error(
                sprintf('Provisioning row #%d carries an unknown status "%s"; left untouched.',
                    (int) $row->fields['id'], $status),
                E_USER_WARNING
            );
            return;
        }

        if (!$in_paman) {
            $row->delete(['id' => (int) $row->fields['id']], true);
            return;
        }

        if (self::canTransition($status, self::STATUS_PENDING_REMOVAL)) {
            $row->update([
                'id'     => (int) $row->fields['id'],
                'status' => self::STATUS_PENDING_REMOVAL,
            ]);
        }
    }

    /**
     * "PAMAN" tab on Computer: is this machine in the bastion, and does
     * PAMAN still agree with GLPI about it - without leaving the Computer
     * the admin is already looking at.
     *
     * Non-static on purpose: CommonGLPI declares getTabNameForItem() as an
     * instance method while keeping displayTabContentForItem() static.
     * Declaring this one static is a fatal "Cannot make non static method
     * static" the moment a Computer page loads. Same trap as
     * inc/profile.class.php and inc/accesshistory.class.php.
     *
     * Gated on the provisioning right, not Computer READ: whether a
     * machine is reachable through the bastion is privileged information,
     * and "can read this Computer" is the wrong audience for it - see
     * inc/accesshistory.class.php for the same reasoning applied to its
     * own tab.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof Computer) || $item->getID() <= 0) {
            return '';
        }

        if (!Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, READ)) {
            return '';
        }

        return self::createTabEntry(PluginIntegrasipamanServerLabel::in(__('{server}', 'integrasipaman')), 0, $item::class, 'ti ti-cloud-upload');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!($item instanceof Computer)) {
            return false;
        }

        if (!Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, READ)) {
            return false;
        }

        $computers_id = (int) $item->getID();

        $row = new self();
        $has_row = $computers_id > 0 && $row->getFromDBByCrit(['computers_id' => $computers_id]);

        echo '<div class="p-3">';

        if (!$has_row) {
            self::renderNoRow($item);
        } else {
            self::renderRow($item, $row);
        }

        echo '</div>';

        return true;
    }

    /**
     * No queue row for this Computer - the common case, since most
     * Computers are never eligible. Say why, rather than leaving a blank
     * tab that teaches nothing: either isEligible() rejects it, or it is
     * eligible and simply has not been picked up by a scan yet.
     */
    private static function renderNoRow(Computer $item): void
    {
        $computers_id = (int) $item->getID();
        $address      = self::pickAddress(self::addressesFor($computers_id));
        // Judged by the rules of the connection that serves the Computer's entity.
        $criteria     = self::criteriaFromConfig(PluginIntegrasipamanConnection::forEntity((int) ($item->fields['entities_id'] ?? 0)));
        $existing     = self::existingHostFor($item, $address);
        if ($existing !== null) {
            self::renderExisting($existing);
            return;
        }
        $verdict      = self::isEligible($item->fields, $address, $criteria);

        echo '<div class="alert alert-info mb-0">';
        if (!$verdict['eligible']) {
            echo htmlescape(sprintf(
                PluginIntegrasipamanServerLabel::in(__('This Computer is not in {server} and is not eligible for it: %s.', 'integrasipaman')),
                $verdict['reason']
            ));
        } else {
            echo htmlescape(PluginIntegrasipamanServerLabel::in(__('This Computer is eligible for {server} but has not been picked up by a scan yet. It will appear on the Asset provisioning queue once the next scan runs.', 'integrasipaman')));
        }
        echo '</div>';
    }

    /**
     * A queue row exists: show what it knows about this asset's bastion
     * state, and the same Send / Resend / Remove buttons the queue page
     * offers - each gated on its own right bit.
     */
    private static function renderRow(Computer $item, self $row): void
    {
        static $status_labels = null;
        if ($status_labels === null) {
            $status_labels = [
                self::STATUS_ELIGIBLE        => __('Eligible', 'integrasipaman'),
                self::STATUS_PROVISIONED     => __('Provisioned', 'integrasipaman'),
                self::STATUS_DRIFT           => __('Drift', 'integrasipaman'),
                self::STATUS_PENDING_REMOVAL => __('Pending removal', 'integrasipaman'),
                self::STATUS_REMOVED         => __('Removed', 'integrasipaman'),
                self::STATUS_FAILED          => __('Failed', 'integrasipaman'),
            ];
        }

        $status = (string) $row->fields['status'];

        // An old Eligible row for a host PAMAN already holds: the next scan
        // drops it; until then, say so and withhold Send.
        if ($status === self::STATUS_ELIGIBLE && trim((string) $row->fields['jumpserver_asset_id']) === '') {
            $existing = self::existingHostFor($item, self::pickAddress(self::addressesFor((int) $item->getID())));
            if ($existing !== null) {
                self::renderExisting($existing);
                return;
            }
        }

        $node_lookup     = PluginIntegrasipamanNodeMap::asLookup();
        $platform_lookup = PluginIntegrasipamanPlatformMap::asLookup();

        $targets = self::resolveTargets(
            $item->fields + [
                'type_name' => Dropdown::getDropdownName('glpi_computertypes', (int) ($item->fields['computertypes_id'] ?? 0)),
                'os_name'   => Dropdown::getDropdownName('glpi_operatingsystems', (int) ($item->fields['operatingsystems_id'] ?? 0)),
            ],
            $node_lookup,
            $platform_lookup
        );

        echo '<table class="table">';
        echo '<tr><th>' . htmlescape(__('Status', 'integrasipaman')) . '</th><td>'
            . htmlescape($status_labels[$status] ?? $status) . '</td></tr>';

        if ($targets['blocked'] !== '') {
            echo '<tr><th>' . htmlescape(__('Node / platform', 'integrasipaman')) . '</th>'
                . '<td class="text-warning">' . htmlescape($targets['blocked']) . '</td></tr>';
        } else {
            $node_label     = $targets['node_label'] !== '' ? $targets['node_label'] : (string) $row->fields['node_label'];
            $platform_label = $targets['platform_label'] !== '' ? $targets['platform_label'] : (string) $row->fields['platform_label'];
            echo '<tr><th>' . htmlescape(__('Node', 'integrasipaman')) . '</th><td>' . htmlescape($node_label) . '</td></tr>';
            echo '<tr><th>' . htmlescape(__('Platform', 'integrasipaman')) . '</th><td>' . htmlescape($platform_label) . '</td></tr>';
        }

        $last_bits = [];
        if (!empty($row->fields['last_action'])) {
            $last_bits[] = (string) $row->fields['last_action'];
        }
        if ((int) ($row->fields['last_actor_id'] ?? 0) > 0) {
            $last_bits[] = (string) Dropdown::getDropdownName('glpi_users', (int) $row->fields['last_actor_id']);
        }
        if (!empty($row->fields['last_action_date'])) {
            $last_bits[] = (string) $row->fields['last_action_date'];
        }
        if ($last_bits !== []) {
            echo '<tr><th>' . htmlescape(__('Last action', 'integrasipaman')) . '</th><td>'
                . htmlescape(implode(' - ', $last_bits)) . '</td></tr>';
        }

        if (!empty($row->fields['last_error'])) {
            echo '<tr><th>' . htmlescape(__('Last error', 'integrasipaman')) . '</th>'
                . '<td class="text-danger">' . htmlescape((string) $row->fields['last_error']) . '</td></tr>';
        }

        echo '</table>';

        self::renderButtons((int) $row->fields['id'], $status);
    }

    /**
     * Send/Resend on CREATE, Remove on PURGE, both absent with READ alone.
     *
     * These POST straight to front/provisioning.php, using the same field
     * names its POST branches already read (`rows[]` + `confirm_send` for
     * send/resend, `row` + `tickets_id` + `remove` for removal). Every
     * server-side check - the right bits, the status guards, the ticket
     * validation, the confirmation step for sends - already lives there;
     * this tab does not reimplement any of it.
     */
    private static function renderButtons(int $row_id, string $status): void
    {
        $can_send   = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, CREATE);
        $can_remove = Session::haveRight(PluginIntegrasipamanProfile::RIGHT_PROVISIONING, PURGE);

        if (!$can_send && !$can_remove) {
            return;
        }

        $target = Plugin::getWebDir('integrasipaman') . '/front/provisioning.php';

        // Mirrors SENDABLE_STATUSES in front/provisioning.php: `provisioned`
        // has no provisioned -> provisioned transition, so it is never offered.
        $sendable = [self::STATUS_ELIGIBLE, self::STATUS_DRIFT, self::STATUS_FAILED];

        echo '<div class="d-flex gap-2 mt-2">';

        if ($can_send && in_array($status, $sendable, true)) {
            $label = $status === self::STATUS_ELIGIBLE
                ? __('Send', 'integrasipaman')
                : __('Resend', 'integrasipaman');

            echo '<form method="post" action="' . htmlescape($target) . '" class="d-inline">';
            echo Html::hidden('rows[]', ['value' => $row_id]);
            echo Html::hidden('confirm_send', ['value' => 1]);
            echo Html::submit($label, ['name' => 'go', 'class' => 'btn btn-primary']);
            Html::closeForm();
        }

        if ($can_remove && $status === self::STATUS_PENDING_REMOVAL) {
            echo '<form method="post" action="' . htmlescape($target) . '" class="d-flex align-items-center gap-1">';
            echo Html::hidden('row', ['value' => $row_id]);
            echo '<input type="number" name="tickets_id" min="1" required placeholder="'
                . htmlescape(__('Ticket #', 'integrasipaman')) . '" class="form-control form-control-sm" style="width:7em;">';
            echo Html::submit(__('Remove', 'integrasipaman'), ['name' => 'remove', 'class' => 'btn btn-sm btn-danger']);
            Html::closeForm();
        }

        echo '</div>';
    }
}
