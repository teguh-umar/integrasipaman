<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Read-only connection to the jumpserver_pbi_views MySQL schema, hosted on
 * a separate MySQL server from GLPI's own database (see
 * docs/superpowers/specs/2026-08-26-jumpserver-pbi-insights-tab-design.md).
 * Used only by front/pbi.php. This class must never run anything but
 * SELECT: the credentials it is configured with are only ever granted
 * SELECT on this schema.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanPbiConnection
{
    /**
     * View names this class is allowed to query. Enforced in fetchRows()
     * regardless of caller - $view is interpolated into raw SQL (PDO has
     * no placeholder syntax for identifiers), so it must never be
     * anything other than one of these four known-safe strings.
     */
    public const VIEW_SESSIONS        = 'v_session_detail';
    public const VIEW_COMMANDS        = 'v_command_audit_detail';
    public const VIEW_LOGIN_FAILURES  = 'v_login_failures';
    public const VIEW_SESSION_SHARING = 'v_session_sharing';

    private const ALLOWED_VIEWS = [
        self::VIEW_SESSIONS,
        self::VIEW_COMMANDS,
        self::VIEW_LOGIN_FAILURES,
        self::VIEW_SESSION_SHARING,
    ];

    /**
     * The column each view should be ordered by (newest first) so
     * "the most recent N rows" (see front/pbi.php) is actually true -
     * without this, `LIMIT` alone returns an arbitrary N rows, not the
     * newest ones. Each key's column is one of that same view's own
     * columns (see the "Key columns shown" table in the design spec),
     * so no new allow-list surface is introduced.
     */
    private const ORDER_COLUMNS = [
        self::VIEW_SESSIONS        => 'date_start',
        self::VIEW_COMMANDS        => 'executed_at',
        self::VIEW_LOGIN_FAILURES  => 'login_time',
        self::VIEW_SESSION_SHARING => 'shared_at',
    ];

    /**
     * Log file name under GLPI's internal log directory
     * (files/_log/jumpserver_pbi.log).
     */
    private const LOG_FILE = 'jumpserver_pbi.log';

    private ?PDO $pdo = null;

    private bool $connectionFailed = false;

    public function __construct(
        private readonly string $host,
        private readonly string $port,
        private readonly string $database,
        private readonly string $user,
        private readonly string $password
    ) {
    }

    /**
     * Fetch up to $limit rows from one of the four allow-listed views.
     * Never throws: any failure (unknown view, connection refused, auth
     * failure, query error) is caught, logged, and turned into null so a
     * PBI-side outage can never break the GLPI page calling this.
     *
     * @return array<int, array<string, mixed>>|null
     */
    /**
     * Whether a call on this instance has already failed to reach the
     * database. Lets a page that asks for several views say "the database
     * is unreachable" once, instead of repeating it under every section
     * heading (v4.5.0).
     */
    public function failedToConnect(): bool
    {
        return $this->connectionFailed;
    }

    public function fetchRows(string $view, int $limit): ?array
    {
        if (!in_array($view, self::ALLOWED_VIEWS, true)) {
            Toolbox::logInFile(self::LOG_FILE, sprintf('Rejected unknown view name: %s', $view));
            return null;
        }

        if ($this->connectionFailed) {
            // A previous call on this same instance already failed to
            // connect - don't make the caller (front/pbi.php calls this
            // 4 times per page load) wait out the connection timeout
            // again for every remaining section.
            return null;
        }

        try {
            $pdo = $this->getConnection();
        } catch (\PDOException $e) {
            // A true connection failure (bad host/credentials): persists
            // for the rest of this instance's life, so the remaining
            // sections on the page short-circuit immediately above
            // instead of each waiting out their own connection timeout.
            $this->connectionFailed = true;
            $this->logError(sprintf('Failed to connect: %s', $e->getMessage()));
            return null;
        }

        $order_column = self::ORDER_COLUMNS[$view];

        try {
            // $view and $order_column are never raw user input - $view is
            // one of the four class constants checked against the
            // allow-list above, and $order_column is looked up from that
            // same trusted $view via ORDER_COLUMNS, never caller-supplied.
            $statement = $pdo->prepare(
                'SELECT * FROM `' . $view . '` ORDER BY `' . $order_column . '` DESC LIMIT :limit'
            );
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll();
        } catch (\PDOException $e) {
            // A query-level failure (e.g. this one view's schema
            // drifted) only fails this one call - it deliberately does
            // NOT set $connectionFailed, since the connection itself is
            // healthy and the other views' queries should still be
            // attempted independently.
            $this->logError(sprintf('Failed to query %s: %s', $view, $e->getMessage()));
            return null;
        }
    }

    /**
     * Lazily open the PDO connection and cache it on this instance, so a
     * page rendering all 4 views (see front/pbi.php) opens at most one
     * real connection, not one per view.
     */
    private function getConnection(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $this->host,
                $this->port,
                $this->database
            );

            $this->pdo = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $this->pdo;
    }

    /**
     * Write a message to GLPI's internal file log
     * (files/_log/jumpserver_pbi.log). The DB password, if non-empty, is
     * never written verbatim - masked first, same pattern as
     * inc/assetsync.class.php::maskToken().
     */
    private function logError(string $message): void
    {
        if ($this->password !== '') {
            $message = str_replace($this->password, self::maskSecret($this->password), $message);
        }

        Toolbox::logInFile(self::LOG_FILE, $message);
    }

    /**
     * Produce a redacted stand-in for a secret, keeping just enough of it
     * (first 4 characters) to help correlate log entries with a specific
     * configured credential without exposing the value itself.
     */
    private static function maskSecret(string $secret): string
    {
        $visible = mb_substr($secret, 0, 4);

        return $visible . str_repeat('*', 8) . '[REDACTED]';
    }
}
