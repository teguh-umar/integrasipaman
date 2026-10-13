<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * A JumpServer connection: where one JumpServer is, how to sign requests to
 * it, and which GLPI entities it serves.
 *
 * Suite entity rule, point 3 (user decisions 2026-10-04/05): every
 * subsidiary has its own JumpServer, so a connection belongs to an entity,
 * and what is pulled through it belongs to that entity or, for a row matched
 * to a Computer, to the Computer's entity when that lies inside the
 * connection's scope. A row matched to nothing stays with the connection.
 *
 * v3.11.0 moved the connection out of glpi_configs into a row of
 * glpi_plugin_jumpserver_connections and gave every data table the
 * connection id and an entity. There was exactly one connection.
 *
 * v4.0.0 allows several:
 *   - one per owning entity (UNIQUE on entities_id);
 *   - a connection may also cover ADDITIONAL entities. Production needs it:
 *     OT is a sibling of ICT, uses ICT's JumpServer and ICT's JumpServer
 *     organisation, and so cannot have a connection of its own (user,
 *     2026-10-05);
 *   - "child entities" extends the owner and the additional entities to
 *     their descendants;
 *   - where two connections could both claim an entity, the more specific
 *     one wins - see forEntity(). A subsidiary under a covered parent that
 *     gets its own JumpServer takes its subtree out of the parent's scope.
 *
 * current() is the connection the running code works on: the one passed to
 * using() (the cron loop, a page that chose one), or the only one there is.
 * With several connections and none chosen it is null, which every caller
 * already treats as "not configured" - never a guess.
 *
 * The two secrets are encrypted here, explicitly. Registering the columns in
 * $PLUGIN_HOOKS['secured_fields'] only tells `security:changekey` to
 * re-encrypt them; core never encrypts a plugin column by itself.
 *
 * Kept free of CommonDBTM on purpose, like PluginIntegrasipamanServerLabel:
 * cron, CLI, the install function and the standalone tests all use it.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanConnection
{
    public const TABLE = 'glpi_plugin_jumpserver_connections';

    /** The column every data table points at its connection with. */
    public const FK = 'plugin_jumpserver_connections_id';

    /** Registered as $PLUGIN_HOOKS['secured_fields']['integrasipaman']. */
    public const SECURED_FIELDS = [
        self::TABLE . '.access_key_secret',
        self::TABLE . '.pbi_db_password',
    ];

    /** Plain columns the Setup form may write. */
    public const COLUMNS = [
        'name', 'url', 'access_key_id', 'org_id', 'command_storage_id',
        'pbi_db_host', 'pbi_db_port', 'pbi_db_name', 'pbi_db_user',
    ];

    /** Encrypted columns. */
    public const SECRETS = ['access_key_secret', 'pbi_db_password'];

    /** glpi_configs key (<= 3.10.1) => column. */
    public const LEGACY_KEYS = [
        'jumpserver_url'                => 'url',
        'jumpserver_access_key_id'      => 'access_key_id',
        'jumpserver_org_id'             => 'org_id',
        'jumpserver_command_storage_id' => 'command_storage_id',
        'jumpserver_pbi_db_host'        => 'pbi_db_host',
        'jumpserver_pbi_db_port'        => 'pbi_db_port',
        'jumpserver_pbi_db_name'        => 'pbi_db_name',
        'jumpserver_pbi_db_user'        => 'pbi_db_user',
    ];

    public const LEGACY_SECRETS = [
        'jumpserver_access_key_secret' => 'access_key_secret',
        'jumpserver_pbi_db_password'   => 'pbi_db_password',
    ];

    // Where those keys lived: everything in the first, and from 3.10.1 the
    // two secrets, already encrypted, in the second.
    private const LEGACY_CONTEXT         = 'plugin_integrasipaman';
    private const LEGACY_SECURED_CONTEXT = 'plugin:integrasipaman';

    /**
     * Every table that carries the connection id and an entity, and how its
     * rows find a Computer: a column of their own, the asset string (through
     * the asset map), or not at all.
     *
     * glpi_plugin_jumpserver_command_eval is absent on purpose: it is a
     * child of `commands` and inherits (suite entity rule, point 1).
     */
    public const STAMPED_TABLES = [
        'glpi_plugin_jumpserver_logs'         => null,
        'glpi_plugin_jumpserver_commands'     => 'asset',
        'glpi_plugin_jumpserver_sessions'     => 'asset',
        'glpi_plugin_jumpserver_assetmap'     => 'computers_id',
        'glpi_plugin_jumpserver_usermap'      => null,
        'glpi_plugin_jumpserver_provisioning' => 'computers_id',
        'glpi_plugin_jumpserver_nodemap'      => null,
        'glpi_plugin_jumpserver_platformmap'  => null,
        'glpi_plugin_jumpserver_permrules'    => null,
        'glpi_plugin_jumpserver_assetperms'   => 'computers_id',
    ];

    private const ASSETMAP_TABLE = 'glpi_plugin_jumpserver_assetmap';

    /** @var array<string,mixed> */
    private array $fields;

    /** @var array<int,self>|null active connections by id; null = not loaded */
    private static ?array $all = null;

    /** @var array<int,self>|null every connection, switched-off ones included */
    private static ?array $every = null;

    /** The connection chosen by using() / setCurrent(). */
    private static ?self $explicit = null;

    /** @var array<int,int|null>|null entity id => parent id (null for root) */
    private static ?array $parents = null;

    /** @param array<string,mixed> $fields */
    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    // --- which connection ---------------------------------------------------

    /**
     * Every active connection, by id. Cached per request. Never restricted
     * by the session: cron and CLI have none (suite entity rule, point 10).
     *
     * @return array<int,self>
     */
    public static function all(): array
    {
        global $DB;

        if (self::$all === null) {
            self::$all = [];
            // The files of a new version are in place before its install
            // function has created the table.
            if ($DB->tableExists(self::TABLE)) {
                $rows = $DB->request([
                    'FROM'  => self::TABLE,
                    'WHERE' => ['is_active' => 1],
                    'ORDER' => ['id ASC'],
                ]);
                foreach ($rows as $row) {
                    self::$all[(int) $row['id']] = new self($row);
                }
            }
        }

        return self::$all;
    }

    public static function byId(int $id): ?self
    {
        return self::all()[$id] ?? null;
    }

    /**
     * Every connection, the switched-off ones included. For the Setup page
     * only: nothing is pulled through, matched against or served by a
     * connection that is not in all().
     *
     * @return array<int,self>
     */
    public static function every(): array
    {
        global $DB;

        if (self::$every === null) {
            self::$every = [];
            if ($DB->tableExists(self::TABLE)) {
                foreach ($DB->request(['FROM' => self::TABLE, 'ORDER' => ['id ASC']]) as $row) {
                    self::$every[(int) $row['id']] = new self($row);
                }
            }
        }

        return self::$every;
    }

    public function isActive(): bool
    {
        return (int) ($this->fields['is_active'] ?? 1) === 1;
    }

    /**
     * The connection the running code works on: the one chosen with using(),
     * or the only one that exists. Null when there is none - and when there
     * are several and none was chosen, because a guess here would sign a
     * request with another subsidiary's key.
     */
    public static function current(): ?self
    {
        if (self::$explicit !== null) {
            return self::$explicit;
        }

        $all = self::all();

        return count($all) === 1 ? reset($all) : null;
    }

    /**
     * Run $work with $connection as the current one, then put back whatever
     * was current before - also when $work throws, so one connection's
     * failure cannot leave the next iteration signing with its key.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public static function using(self $connection, callable $work)
    {
        $before         = self::$explicit;
        self::$explicit = $connection;
        try {
            return $work($connection);
        } finally {
            self::$explicit = $before;
        }
    }

    /**
     * The connection that serves $entity, most specific first:
     *   1. the one that owns it;
     *   2. the one that lists it as an additional entity;
     *   3. going up the tree, the nearest ancestor that is owned or listed
     *      by a connection with "child entities" on.
     * Null when no connection reaches the entity.
     */
    public static function forEntity(int $entity): ?self
    {
        $all = self::all();
        if ($all === []) {
            return null;
        }

        foreach ($all as $connection) {
            if ($connection->entity() === $entity) {
                return $connection;
            }
        }
        foreach ($all as $connection) {
            if (in_array($entity, $connection->extraEntities(), true)) {
                return $connection;
            }
        }

        $parents = self::parents();
        $seen    = [$entity => true];
        $at      = $parents[$entity] ?? null;
        while ($at !== null && !isset($seen[$at])) {
            $seen[$at] = true;
            foreach ($all as $connection) {
                if ($connection->isRecursive()
                    && ($connection->entity() === $at || in_array($at, $connection->extraEntities(), true))) {
                    return $connection;
                }
            }
            $at = $parents[$at] ?? null;
        }

        return null;
    }

    /**
     * Run $work once per active connection, each as the current one.
     *
     * One connection's failure is that connection's failure: it is caught
     * and reported in its own entry, and the next connection still runs. A
     * subsidiary whose JumpServer is down must not stop the audit pull of
     * the others.
     *
     * @param callable(self): mixed $work
     * @return array<int, array{connection: self, result: mixed, error: string}> by connection id
     */
    public static function each(callable $work): array
    {
        $runs = [];
        foreach (self::all() as $id => $connection) {
            try {
                $runs[$id] = ['connection' => $connection, 'result' => self::using($connection, $work), 'error' => ''];
            } catch (\Throwable $e) {
                $runs[$id] = ['connection' => $connection, 'result' => null, 'error' => $e->getMessage()];
            }
        }

        return $runs;
    }

    /**
     * The WHERE fragment that keeps a query to the current connection's
     * rows: [FK => id]. Empty when no connection is current, so a caller
     * that merges it into its criteria is unchanged on an unconfigured
     * instance.
     *
     * For ingest, dedup and correlation - the code that works on ONE
     * connection. It is not the entity restriction of a screen.
     *
     * @return array<string,int>
     */
    public static function ownRows(string $alias = ''): array
    {
        $connection = self::current();
        if ($connection === null) {
            return [];
        }

        return [($alias === '' ? '' : $alias . '.') . self::FK => $connection->id()];
    }

    // --- what a signed-in user may see ---------------------------------------

    /**
     * The WHERE fragment that keeps a SCREEN to the rows of the entities the
     * signed-in user is working in: [entities_id => active entities].
     *
     * Suite entity rule, points 5 and 10: every list, tab, card, count and
     * export uses it, and a count comes from the same criteria as its rows;
     * cron and CLI are never restricted, or only root's data would be
     * pulled and resolved. A session with no active entity sees nothing -
     * [-1] matches no row - rather than everything.
     *
     * Not to be confused with ownRows(), which keeps the INGEST of one
     * connection to that connection's rows.
     *
     * @return array<string, int[]>
     */
    public static function viewer(string $alias = ''): array
    {
        if (self::isUnattended()) {
            return [];
        }

        $entities = array_values(array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [])));

        return [($alias === '' ? '' : $alias . '.') . 'entities_id' => $entities === [] ? [-1] : $entities];
    }

    /**
     * Whether the signed-in user may see a row of $entity that was loaded by
     * id rather than through viewer() (suite entity rule, point 17: holding
     * the plugin right somewhere says nothing about THIS row).
     */
    public static function canSee(int $entity): bool
    {
        if (self::isUnattended()) {
            return true;
        }

        return in_array($entity, array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [])), true);
    }

    /**
     * The connections whose owning entity the signed-in user is working in:
     * what a Setup page lists and what a page that needs "a" connection may
     * choose from.
     *
     * @param bool $with_inactive also the switched-off ones (the Setup list)
     * @return array<int,self>
     */
    public static function visible(bool $with_inactive = false): array
    {
        return array_filter(
            $with_inactive ? self::every() : self::all(),
            static fn (self $c): bool => self::canSee($c->entity())
        );
    }

    /**
     * The connection that serves the entity the signed-in user is working
     * in, or null. What a page uses when it needs one connection and no row
     * names it: the PBI page, the back-fill, a new mapping.
     *
     * Never current(): on an instance with one connection that would hand
     * the only JumpServer's data to a user of an entity it does not serve.
     */
    public static function forActiveEntity(): ?self
    {
        return self::forEntity((int) ($_SESSION['glpiactive_entity'] ?? -1));
    }

    /**
     * Make $connection the current one for the rest of the request (null:
     * back to "the only one, or none"). For a page controller, which has no
     * closure to hand to using().
     */
    public static function choose(?self $connection): void
    {
        self::$explicit = $connection;
    }

    /** Cron or CLI: nobody is looking, nothing is restricted. */
    private static function isUnattended(): bool
    {
        return (function_exists('isCommandLine') && isCommandLine())
            || (class_exists('Session', false) && method_exists('Session', 'isCron') && Session::isCron());
    }

    /** Forget everything cached (after a save, and between tests). */
    public static function reset(): void
    {
        self::$all      = null;
        self::$every    = null;
        self::$explicit = null;
        self::$parents  = null;
    }

    /**
     * Make $row the one connection, without reading the database; null means
     * "none". The standalone tests use it.
     *
     * @param array<string,mixed>|null $row
     */
    public static function setCurrent(?array $row): void
    {
        if ($row === null) {
            self::$all      = [];
            self::$every    = [];
            self::$explicit = null;
            return;
        }
        $connection     = new self($row);
        self::$all      = [$connection->id() => $connection];
        self::$every    = self::$all;
        self::$explicit = $connection;
    }

    // --- one connection -----------------------------------------------------

    public function id(): int
    {
        return (int) ($this->fields['id'] ?? 0);
    }

    public function entity(): int
    {
        return (int) ($this->fields['entities_id'] ?? 0);
    }

    public function isRecursive(): bool
    {
        return (int) ($this->fields['is_recursive'] ?? 0) === 1;
    }

    /**
     * The entities this connection covers beside its own, as stored: a comma
     * list of entity ids.
     *
     * @return int[]
     */
    public function extraEntities(): array
    {
        return self::idList((string) ($this->fields['extra_entities'] ?? ''), $this->entity());
    }

    /**
     * The rule settings this connection keeps for itself (v4.4.0): setting key
     * => value, as stored. A setting that is not here is inherited; see
     * PluginIntegrasipamanRuleSettings.
     *
     * @return array<string,string>
     */
    public function rules(): array
    {
        $decoded = json_decode((string) ($this->fields['rules'] ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * The connection this one inherits its rules from: the nearest active
     * connection above its owning entity that has "child entities" on and
     * owns, or lists as an additional entity, one of the entities on the way
     * up. Null when there is none, and then the instance defaults apply.
     */
    public function inheritsFrom(): ?self
    {
        $parents = self::parents();
        $seen    = [$this->entity() => true];
        $at      = $parents[$this->entity()] ?? null;

        while ($at !== null && !isset($seen[$at])) {
            $seen[$at] = true;
            // The owner of an entity first, then a connection that lists it.
            $listed = null;
            foreach (self::all() as $other) {
                if ($other->id() === $this->id() || !$other->isRecursive()) {
                    continue;
                }
                if ($other->entity() === $at) {
                    return $other;
                }
                if ($listed === null && in_array($at, $other->extraEntities(), true)) {
                    $listed = $other;
                }
            }
            if ($listed !== null) {
                return $listed;
            }
            $at = $parents[$at] ?? null;
        }

        return null;
    }

    /**
     * Replace the rule settings a connection keeps for itself. Only keys the
     * rule settings know are stored; an empty map makes it inherit everything.
     *
     * @param array<string,string> $rules setting key => value
     * @return string '' on success, otherwise why nothing was written
     */
    public static function saveRules(int $id, array $rules): string
    {
        global $DB;

        require_once __DIR__ . '/rulesettings.class.php';

        if ((self::every()[$id] ?? null) === null) {
            return 'no such connection';
        }

        $keep = [];
        foreach ($rules as $key => $value) {
            if (is_string($key) && in_array($key, PluginIntegrasipamanRuleSettings::keys(), true) && is_scalar($value)) {
                $keep[$key] = (string) $value;
            }
        }

        $encoded = $keep === [] ? null : json_encode($keep, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!$DB->update(self::TABLE, ['rules' => $encoded, 'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')], ['id' => $id])) {
            return 'the database refused the row: ' . $DB->error();
        }

        self::reset();

        return '';
    }

    /**
     * What to call this connection in a log line or a list: its name, or
     * failing that the host of its URL, or its id.
     */
    public function label(): string
    {
        $name = $this->get('name');
        if ($name !== '') {
            return $name;
        }
        $host = parse_url($this->get('url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '#' . $this->id();
    }

    /**
     * A per-connection cursor: when this connection last completed the work
     * the column names, or null when it never has.
     */
    public function cursor(string $column): ?string
    {
        $value = (string) ($this->fields[$column] ?? '');

        return $value === '' ? null : $value;
    }

    /** Move a cursor. Written at once, so a later failure cannot lose it. */
    public function setCursor(string $column, string $when): void
    {
        global $DB;

        if ($DB->update(self::TABLE, [$column => $when], ['id' => $this->id()])) {
            $this->fields[$column] = $when;
        }
    }

    /** A plain column, trimmed. Never a secret: those have their own readers. */
    public function get(string $column): string
    {
        if (in_array($column, self::SECRETS, true)) {
            return '';
        }

        return trim((string) ($this->fields[$column] ?? ''));
    }

    public function accessKeySecret(): string
    {
        return $this->decrypted('access_key_secret');
    }

    public function pbiDbPassword(): string
    {
        return $this->decrypted('pbi_db_password');
    }

    /** True when a value is stored; the Setup form shows this, never the value. */
    public function hasSecret(string $column): bool
    {
        return in_array($column, self::SECRETS, true) && (string) ($this->fields[$column] ?? '') !== '';
    }

    /**
     * What every signed call takes: [base_url, access_key_id, secret, org_id].
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    public function credentials(): array
    {
        return [$this->get('url'), $this->get('access_key_id'), $this->accessKeySecret(), $this->get('org_id')];
    }

    /**
     * credentials() of the current connection, four empty strings when there
     * is none - which every caller already treats as "not configured".
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    public static function currentCredentials(): array
    {
        $connection = self::current();

        return $connection === null ? ['', '', '', ''] : $connection->credentials();
    }

    /** A plain column of the current connection, '' when there is none. */
    public static function currentValue(string $column): string
    {
        $connection = self::current();

        return $connection === null ? '' : $connection->get($column);
    }

    // --- scope and stamping -------------------------------------------------

    /**
     * The entities this connection's data may land in: its own and the
     * additional ones, with their descendants when "child entities" is on -
     * minus every entity that a more specific connection serves.
     *
     * @return int[]
     */
    public function scope(): array
    {
        $declared = $this->declaredScope();

        // A connection that is not among the active ones (switched off, or
        // built by hand) has nobody to share with.
        if (!isset(self::all()[$this->id()])) {
            return $declared;
        }

        $scope = [];
        foreach ($declared as $entity) {
            $serving = self::forEntity($entity);
            if ($serving !== null && $serving->id() === $this->id()) {
                $scope[] = $entity;
            }
        }

        return $scope;
    }

    /**
     * What the connection claims by itself, before other connections are
     * taken into account.
     *
     * @return int[]
     */
    private function declaredScope(): array
    {
        $claimed = array_merge([$this->entity()], $this->extraEntities());

        if ($this->isRecursive()) {
            $children = [];
            foreach (self::parents() as $id => $parent) {
                if ($parent !== null) {
                    $children[$parent][] = $id;
                }
            }
            $queue = $claimed;
            $seen  = array_fill_keys($claimed, true);
            while ($queue !== []) {
                $at = array_shift($queue);
                foreach ($children[$at] ?? [] as $child) {
                    if (!isset($seen[$child])) {
                        $seen[$child] = true;
                        $claimed[]    = $child;
                        $queue[]      = $child;
                    }
                }
            }
        }

        return array_values(array_unique($claimed));
    }

    /**
     * entity id => parent id, the whole tree, read once per request. The
     * root's parent is null.
     *
     * @return array<int,int|null>
     */
    private static function parents(): array
    {
        global $DB;

        if (self::$parents === null) {
            self::$parents = [];
            foreach ($DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_entities']) as $row) {
                $id     = (int) $row['id'];
                $parent = $row['entities_id'];
                // The root is stored with a NULL parent, and must not become
                // its own parent through an (int) cast.
                self::$parents[$id] = ($parent === null || $id === 0) ? null : (int) $parent;
            }
        }

        return self::$parents;
    }

    /**
     * The two columns a new row gets: the connection, and its entity.
     *
     * @return array<string,int> empty when no connection is current, so the
     *                           row keeps the column defaults.
     */
    public static function stamp(): array
    {
        $connection = self::current();
        if ($connection === null) {
            return [];
        }

        return [self::FK => $connection->id(), 'entities_id' => $connection->entity()];
    }

    /**
     * Stamp rows about to be inserted. A row with an `asset` string takes the
     * entity of the Computer that asset is mapped to, when that Computer is
     * inside the scope; every other row takes the connection's entity.
     *
     * This is the moment the entity of a command or a session is decided.
     * It is not derived again at read time: moving the Computer to another
     * entity later must not hand its history to that entity.
     *
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    public static function stampRows(array $rows): array
    {
        $connection = self::current();
        if ($connection === null || $rows === []) {
            return $rows;
        }

        $assets = [];
        foreach ($rows as $row) {
            $asset = (string) ($row['asset'] ?? '');
            if ($asset !== '') {
                $assets[$asset] = true;
            }
        }
        $by_asset = $assets === [] ? [] : $connection->entitiesForAssets(array_keys($assets));

        $base = [self::FK => $connection->id(), 'entities_id' => $connection->entity()];
        foreach ($rows as $i => $row) {
            $stamp = $base;
            $asset = (string) ($row['asset'] ?? '');
            if ($asset !== '' && isset($by_asset[$asset])) {
                $stamp['entities_id'] = $by_asset[$asset];
            }
            $rows[$i] = $row + $stamp;
        }

        return $rows;
    }

    /**
     * asset string => entity of its mapped Computer, for this connection's
     * assets whose Computer is inside the scope. An asset that is absent
     * takes the connection's entity.
     *
     * @param string[] $assets
     * @return array<string,int>
     */
    public function entitiesForAssets(array $assets): array
    {
        global $DB;

        $rows = $DB->request([
            'SELECT'     => ['m.asset', 'c.entities_id'],
            'FROM'       => self::ASSETMAP_TABLE . ' AS m',
            'INNER JOIN' => [
                'glpi_computers AS c' => ['ON' => ['c' => 'id', 'm' => 'computers_id']],
            ],
            'WHERE'      => [
                'm.' . self::FK  => $this->id(),
                'm.asset'        => array_values($assets),
                'c.entities_id'  => $this->scope(),
            ],
        ]);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['asset']] = (int) $row['entities_id'];
        }

        return $out;
    }

    /**
     * The statements that give this connection's rows their entity, by the
     * same rule stampRows() applies to a new row.
     *
     * Each statement touches only the rows of this connection. A row never
     * changes connection: it was pulled through one JumpServer and stays
     * with it.
     *
     * @param bool $history false: only the three tables that link a Computer
     *                      directly (current state, cheap, run after every
     *                      correlation pass). true: every table, including
     *                      commands and sessions (install, and when a
     *                      connection's scope is changed).
     * @param bool $adopt   also take the rows that have no connection yet
     *                      (written before v3.11.0). Only the migration that
     *                      creates the first connection may ask for it.
     * @return string[]
     */
    public function restampQueries(bool $history = true, bool $adopt = false): array
    {
        $id     = $this->id();
        $entity = $this->entity();
        $scope  = implode(', ', array_map('intval', $this->scope() ?: [$entity]));
        $fk     = self::FK;
        $mine   = $adopt ? "IN (0, $id)" : "= $id";

        $queries = [];
        foreach (self::STAMPED_TABLES as $table => $link) {
            if ($link === 'computers_id') {
                $queries[] = "UPDATE `$table` t"
                    . " LEFT JOIN `glpi_computers` c ON c.`id` = t.`computers_id` AND c.`entities_id` IN ($scope)"
                    . " SET t.`$fk` = $id, t.`entities_id` = COALESCE(c.`entities_id`, $entity)"
                    . " WHERE t.`$fk` $mine";
                continue;
            }
            if (!$history) {
                continue;
            }
            if ($link === 'asset') {
                $map = self::ASSETMAP_TABLE;
                // m.FK IN (0, id): during the adopting migration the asset
                // map rows may still be unstamped when this statement runs.
                $queries[] = "UPDATE `$table` t"
                    . " LEFT JOIN `$map` m ON m.`asset` = t.`asset` AND m.`$fk` IN (0, $id)"
                    . " LEFT JOIN `glpi_computers` c ON c.`id` = m.`computers_id` AND c.`entities_id` IN ($scope)"
                    . " SET t.`$fk` = $id, t.`entities_id` = COALESCE(c.`entities_id`, $entity)"
                    . " WHERE t.`$fk` $mine";
                continue;
            }
            $queries[] = "UPDATE `$table` SET `$fk` = $id, `entities_id` = $entity WHERE `$fk` $mine";
        }

        return $queries;
    }

    /** Run restampQueries(). */
    public function restamp(bool $history = true, bool $adopt = false): void
    {
        global $DB;

        foreach ($this->restampQueries($history, $adopt) as $query) {
            if (!$DB->doQuery($query)) {
                trigger_error('integrasipaman: restamp failed: ' . $DB->error(), E_USER_WARNING);
            }
        }
    }

    /**
     * Keep the rows that link a Computer directly (asset map, provisioning,
     * asset permissions) in the entity of that Computer. They describe the
     * present, not history, so they follow the link. Called after every
     * correlation pass, for the connection that pass worked on.
     */
    public static function syncLinked(): void
    {
        $connection = self::current();
        if ($connection !== null) {
            $connection->restamp(false);
        }
    }

    // --- writing ------------------------------------------------------------

    /**
     * Create a connection ($id null) or update one.
     *
     * Changing who a connection serves can change what its neighbours serve
     * (a new, more specific connection takes entities out of a wider one),
     * so after any change of scope every connection's rows are decided again.
     *
     * @param array<string,string> $values  plain columns (COLUMNS)
     * @param array<string,string> $secrets column => typed value; '' clears,
     *                                      an absent column keeps what is stored
     * @param int|null             $entity       owner; null keeps (required on create)
     * @param bool|null            $is_recursive null keeps (true on create)
     * @param int[]|null           $extra        additional entities; null keeps
     * @param bool|null            $is_active    null keeps (true on create). A
     *                                           switched-off connection pulls
     *                                           nothing and serves no entity;
     *                                           its rows stay where they are.
     * @return string '' on success, otherwise why nothing was written
     */
    public static function save(
        ?int $id,
        array $values,
        array $secrets = [],
        ?int $entity = null,
        ?bool $is_recursive = null,
        ?array $extra = null,
        ?bool $is_active = null
    ): string {
        global $DB;

        // every(), not all(): a switched-off connection can still be edited
        // and switched back on, and still holds its entity.
        $existing = $id === null ? null : (self::every()[$id] ?? null);
        if ($id !== null && $existing === null) {
            return 'no such connection';
        }
        if ($existing === null && $entity === null) {
            return 'a new connection needs an owning entity';
        }

        $row = array_intersect_key($values, array_flip(self::COLUMNS));

        foreach ($secrets as $column => $plain) {
            if (!in_array($column, self::SECRETS, true)) {
                continue;
            }
            $encrypted = $plain === '' ? '' : (new GLPIKey())->encrypt($plain);
            if ($plain !== '' && $encrypted === '') {
                // GLPI key unreadable. Storing '' would silently drop the secret.
                return 'a secret could not be encrypted';
            }
            $row[$column] = $encrypted;
        }

        $owner = $entity ?? $existing->entity();
        foreach (self::every() as $other) {
            if ($other->entity() === $owner && ($existing === null || $other->id() !== $existing->id())) {
                return 'that entity already has a connection';
            }
        }

        $scope_changed = $existing === null;
        if ($existing === null || $owner !== $existing->entity()) {
            $row['entities_id'] = $owner;
            $scope_changed      = true;
        }
        $recursive = $is_recursive ?? ($existing === null ? true : $existing->isRecursive());
        if ($existing === null || $recursive !== $existing->isRecursive()) {
            $row['is_recursive'] = $recursive ? 1 : 0;
            $scope_changed       = true;
        }
        if ($extra !== null || $existing === null) {
            $list = self::idList(implode(',', array_map('intval', $extra ?? [])), $owner);
            if ($existing === null || $list !== $existing->extraEntities()) {
                $row['extra_entities'] = implode(',', $list);
                $scope_changed         = true;
            }
        }

        // Switching a connection on or off changes who serves its entities,
        // so it is a change of scope like any other.
        $active = $is_active ?? ($existing === null ? true : $existing->isActive());
        if ($existing === null || $active !== $existing->isActive()) {
            $row['is_active'] = $active ? 1 : 0;
            $scope_changed    = true;
        }

        $now             = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $row['date_mod'] = $now;

        if ($existing === null) {
            $row['date_creation'] = $now;
            if (!$DB->insert(self::TABLE, $row)) {
                return 'the database refused the row: ' . $DB->error();
            }
        } elseif (!$DB->update(self::TABLE, $row, ['id' => $existing->id()])) {
            return 'the database refused the row: ' . $DB->error();
        }

        self::reset();

        if ($scope_changed) {
            foreach (self::all() as $connection) {
                $connection->restamp();
            }
        }

        return '';
    }

    /**
     * Turn the glpi_configs keys of <= 3.10.1 into the first connection.
     * Runs from the install function, so on every upgrade; once any
     * connection exists it does nothing.
     *
     * Reads and deletes with $DB, never with Config item methods: those log
     * the value they purge to glpi_logs. A 3.10.1 secret is already
     * encrypted with the GLPI key and is copied as it is; a plain one from
     * <= 3.10.0 is encrypted here.
     *
     * The owner it picks is a guess, and v3.11.0 proved it can be wrong (it
     * chose "AD PGN Login", the lowest id under root, where ICT was meant).
     * It is kept only because an upgrade cannot ask; the connection is
     * shown on the Setup form to be corrected.
     *
     * @return string[] problems; when not empty nothing was changed
     */
    public static function migrate(): array
    {
        global $DB;

        self::reset();
        if ($DB->tableExists(self::TABLE)) {
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'LIMIT' => 1]) as $any) {
                return [];
            }
        }

        $plain = self::configRows(self::LEGACY_CONTEXT);
        $safe  = self::configRows(self::LEGACY_SECURED_CONTEXT);

        $row = [];
        foreach (self::LEGACY_KEYS as $key => $column) {
            if (isset($plain[$key])) {
                $row[$column] = trim($plain[$key]);
            }
        }
        foreach (self::LEGACY_SECRETS as $key => $column) {
            if (($safe[$key] ?? '') !== '') {
                $row[$column] = $safe[$key];
            } elseif (($plain[$key] ?? '') !== '') {
                $encrypted = (new GLPIKey())->encrypt($plain[$key]);
                if ($encrypted === '') {
                    return ["could not encrypt $key (GLPI security key unreadable)"];
                }
                $row[$column] = $encrypted;
            }
        }

        // Nothing was ever configured (a fresh install): the Setup form
        // creates the connection on its first save.
        if (($row['url'] ?? '') === '' && ($row['access_key_id'] ?? '') === '') {
            return [];
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $row += [
            'entities_id'   => self::defaultEntity(),
            'is_recursive'  => 1,
            'is_active'     => 1,
            'date_creation' => $now,
            'date_mod'      => $now,
        ];
        if (!$DB->insert(self::TABLE, $row)) {
            return ['could not create the connection row: ' . $DB->error()];
        }

        self::purgeLegacyConfig();
        self::reset();
        self::current()?->restamp(true, true);

        return [];
    }

    /**
     * Keep the links that exist when the resolver starts matching only
     * inside a connection's scope (v4.0.0).
     *
     * Until then an asset could be matched to a Computer in any entity. A
     * Computer already matched but outside the scope would lose its link at
     * the first pass after the upgrade - and with it the asset's class, its
     * risk scores and its entitlements. So the entities those Computers are
     * in become additional entities of the connection. That is not a guess
     * about who owns what: it records what the connection already reaches,
     * and it is shown on the Setup form to be reviewed.
     *
     * The root entity is never added: with "child entities" on it would
     * make the connection cover the whole instance. It is reported instead.
     *
     * For the upgrade of a single-connection instance only; the caller
     * guards it with a one-shot marker.
     *
     * @return array{added: int[], root: bool} what was added, and whether
     *         matched Computers in the root entity were left outside
     */
    public static function grandfatherScope(): array
    {
        global $DB;

        $report = ['added' => [], 'root' => false];

        self::reset();
        $all = self::all();
        if (count($all) !== 1) {
            return $report;
        }
        $connection = reset($all);

        $rows = $DB->request([
            'SELECT'     => ['c.entities_id'],
            'DISTINCT'   => true,
            'FROM'       => self::ASSETMAP_TABLE . ' AS m',
            'INNER JOIN' => [
                'glpi_computers AS c' => ['ON' => ['c' => 'id', 'm' => 'computers_id']],
            ],
            'WHERE'      => ['m.' . self::FK => $connection->id()],
        ]);

        $scope = $connection->scope();
        foreach ($rows as $row) {
            $entity = (int) $row['entities_id'];
            if (in_array($entity, $scope, true)) {
                continue;
            }
            if ($entity === 0) {
                $report['root'] = true;
                continue;
            }
            $report['added'][] = $entity;
        }

        if ($report['added'] !== []) {
            self::save(
                $connection->id(),
                [],
                [],
                null,
                null,
                array_merge($connection->extraEntities(), $report['added'])
            );
        }

        return $report;
    }

    /**
     * The owner migrate() falls back on: the first entity directly under the
     * root, and the root only on an instance that has no other. See
     * migrate() for why this is not to be trusted.
     */
    public static function defaultEntity(): int
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_entities',
            'WHERE'  => ['entities_id' => 0, 'id' => ['>', 0]],
            'ORDER'  => ['id ASC'],
            'LIMIT'  => 1,
        ]);
        foreach ($rows as $row) {
            return (int) $row['id'];
        }

        return 0;
    }

    /** Remove the connection keys from glpi_configs, without logging them. */
    public static function purgeLegacyConfig(): void
    {
        global $DB;

        $DB->delete('glpi_configs', [
            'context' => [self::LEGACY_CONTEXT, self::LEGACY_SECURED_CONTEXT],
            'name'    => array_merge(array_keys(self::LEGACY_KEYS), array_keys(self::LEGACY_SECRETS)),
        ]);
    }

    /** @return array<string,string> name => value */
    private static function configRows(string $context): array
    {
        global $DB;

        $out = [];
        foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => 'glpi_configs', 'WHERE' => ['context' => $context]]) as $row) {
            $out[(string) $row['name']] = (string) ($row['value'] ?? '');
        }

        return $out;
    }

    /**
     * Distinct non-negative ids from a comma list, in order, without $not
     * (a connection does not list its own entity as an additional one).
     * Entity 0 is the root and a legitimate id.
     *
     * @return int[]
     */
    private static function idList(string $csv, int $not): array
    {
        $out = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $id = (int) $part;
            if ($id !== $not && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function decrypted(string $column): string
    {
        $raw = (string) ($this->fields[$column] ?? '');

        return $raw === '' ? '' : (string) ((new GLPIKey())->decrypt($raw) ?? '');
    }
}
