<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The rule settings of a connection (v4.4.0).
 *
 * Until 4.3.x the rules that decide what a command, an asset or a Computer
 * means - the risk keywords, who is an administrator, which addresses are
 * production, how assets are matched, which Computers may be sent to the
 * bastion - were one set for the whole installation. Two subsidiaries on two
 * JumpServers do not share an administrator list or a production network, so
 * each connection can now keep its own.
 *
 * A setting is looked up in this order, the first that has it wins:
 *   1. the connection's own value (its `rules` column);
 *   2. the connection it inherits from - the nearest one above it with "child
 *      entities" on (PluginIntegrasipamanConnection::inheritsFrom()), and so
 *      on up that chain;
 *   3. the instance defaults: glpi_configs, over CONFIG_DEFAULTS.
 *
 * A connection that stored nothing therefore behaves exactly as it did before
 * this release, which is what makes the upgrade a no-op.
 *
 * The settings come in three groups, and the Setup form lets a connection take
 * over one whole group at a time: it either has its own risk rules, or
 * inherits all of them. The storage is per key, so nothing here stops a finer
 * split later.
 *
 * Cron and CLI call this with no session: nothing here reads one.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/connection.class.php';

class PluginIntegrasipamanRuleSettings
{
    public const SECTION_RISK         = 'risk';
    public const SECTION_CORRELATION  = 'correlation';
    public const SECTION_PROVISIONING = 'provisioning';

    /**
     * The per-connection settings, by group. The same keys as in glpi_configs
     * and CONFIG_DEFAULTS, so a connection's value and the default are read
     * the same way. Cache keys, the retention months, the maps generation and
     * everything about the connection itself stay instance-wide.
     */
    public const SECTIONS = [
        self::SECTION_RISK => [
            'jumpserver_risk_keywords',
            'jumpserver_admin_users',
            'jumpserver_production_ip_prefixes',
            'jumpserver_development_ip_prefixes',
            'jumpserver_command_categories',
            'jumpserver_shared_session_note',
            'jumpserver_direct_access_note',
        ],
        self::SECTION_CORRELATION => [
            'correlation_match_mode',
            'correlation_production_ipnetworks',
            'correlation_production_addressings',
            'correlation_admin_groups',
        ],
        self::SECTION_PROVISIONING => [
            'provisioning_eligible_types',
            'provisioning_eligible_states',
            'provisioning_dispose_states',
            'provisioning_eligible_entities',
            'provisioning_ip_prefixes',
            'provisioning_platform_families',
            'provisioning_ticket_statuses',
        ],
    ];

    /** @return list<string> every per-connection setting key */
    public static function keys(): array
    {
        return array_merge(...array_values(self::SECTIONS));
    }

    /** The group a key belongs to, or null for a key that is instance-wide. */
    public static function sectionOf(string $key): ?string
    {
        foreach (self::SECTIONS as $section => $keys) {
            if (in_array($key, $keys, true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * The instance defaults for the per-connection settings: what the admin
     * saved as "for all connections", else what the plugin shipped.
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $stored = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
        $out    = [];
        foreach (self::keys() as $key) {
            $out[$key] = (string) ($stored[$key] ?? PluginIntegrasipamanConfig::CONFIG_DEFAULTS[$key] ?? '');
        }

        return $out;
    }

    /**
     * The connections whose values apply to $connection, nearest first:
     * itself, then the one it inherits from, and so on. A loop in the data
     * (A inherits from B inherits from A) ends the walk instead of spinning.
     *
     * @return list<PluginIntegrasipamanConnection>
     */
    public static function chain(PluginIntegrasipamanConnection $connection): array
    {
        $chain = [];
        $seen  = [];
        for ($at = $connection; $at !== null && !isset($seen[$at->id()]); $at = $at->inheritsFrom()) {
            $seen[$at->id()] = true;
            $chain[]         = $at;
        }

        return $chain;
    }

    /**
     * Every per-connection setting as it applies to $connection. Null is the
     * instance defaults: no connection, or several with none chosen.
     *
     * @return array<string,string>
     */
    public static function forConnection(?PluginIntegrasipamanConnection $connection): array
    {
        $values = self::defaults();
        if ($connection === null) {
            return $values;
        }

        // Farthest first, so a nearer connection overwrites what is above it.
        foreach (array_reverse(self::chain($connection)) as $link) {
            foreach ($link->rules() as $key => $value) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = $value;
                }
            }
        }

        return $values;
    }

    /** forConnection() of the connection the running code works on. */
    public static function current(): array
    {
        return self::forConnection(PluginIntegrasipamanConnection::current());
    }

    /**
     * Where a group's values come from for $connection: its own, or inherited.
     * A group is its own when the connection stores any of its keys.
     *
     * @return array{own: bool, from: ?PluginIntegrasipamanConnection} from is
     *         the connection that supplies it when inherited, null for the defaults
     */
    public static function sourceOf(PluginIntegrasipamanConnection $connection, string $section): array
    {
        $keys = self::SECTIONS[$section] ?? [];
        $own  = array_intersect_key($connection->rules(), array_flip($keys)) !== [];
        if ($own) {
            return ['own' => true, 'from' => null];
        }

        foreach (array_slice(self::chain($connection), 1) as $link) {
            if (array_intersect_key($link->rules(), array_flip($keys)) !== []) {
                return ['own' => false, 'from' => $link];
            }
        }

        return ['own' => false, 'from' => null];
    }

    /**
     * The map to store for a connection after the form was saved: what it
     * keeps now, with the groups in $own_sections taking the posted values
     * and every other group released to inheritance.
     *
     * @param array<string,string> $posted      setting key => cleaned value
     * @param list<string>         $own_sections groups the connection keeps for itself
     * @return array<string,string>
     */
    public static function mapToStore(array $posted, array $own_sections): array
    {
        $map = [];
        foreach ($own_sections as $section) {
            foreach (self::SECTIONS[$section] ?? [] as $key) {
                if (array_key_exists($key, $posted)) {
                    $map[$key] = $posted[$key];
                }
            }
        }

        return $map;
    }
}
