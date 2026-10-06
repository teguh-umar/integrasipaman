<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The name the UI calls the JumpServer instance by (v3.10.0, user decision
 * 2026-10-04). Until 3.9.0 every screen said "PAMAN", the name of the one
 * instance this plugin was written against (paman.example.com). It is now the
 * first DNS label of the configured JumpServer URL, in capitals:
 *
 *   https://paman.example.com        -> PAMAN
 *   bastion.somedomain.com:8443    -> BASTION
 *   https://10.1.2.3 / empty / bad -> JumpServer
 *
 * Only the displayed word moved. The plugin key `integrasipaman`, class,
 * table, right and config key names are identity and stay as they are, and
 * text already stored in the database (ticket content, logs, the registered
 * cron comment) keeps the name it was written with.
 *
 * Reads the connection (v3.11.0; glpi_configs before that), never the
 * session, so cron and CLI get the same word. Kept free of CommonDBTM on
 * purpose: every class that prints a message uses it.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/connection.class.php';

class PluginIntegrasipamanServerLabel
{
    /** Used when the URL names no host a label can be taken from. */
    public const FALLBACK = 'JumpServer';

    /** What a translatable string carries in place of the name. */
    public const TOKEN = '{server}';

    /** @var array<int,string> connection id (0 = none) => label */
    private static array $labels = [];

    /**
     * The label for the connection's JumpServer URL, cached per request AND
     * per connection: a cron run that loops over the connections, or a
     * batch of sends across two of them, must name each by its own word
     * (code review, 4.0.0 - one cached label named them all by the first).
     */
    public static function get(): string
    {
        // v4.0.0: the connection the code is working on; on a page that
        // chose none, the one serving the entity the user is in. With
        // neither, the neutral word.
        $connection = PluginIntegrasipamanConnection::current() ?? PluginIntegrasipamanConnection::forActiveEntity();
        $key        = $connection === null ? 0 : $connection->id();

        if (!isset(self::$labels[$key])) {
            self::$labels[$key] = self::fromUrl($connection === null ? '' : $connection->get('url'));
        }

        return self::$labels[$key];
    }

    /**
     * Put the label into a translated string:
     *   ServerLabel::in(__('Reload from {server}', 'integrasipaman'))
     *
     * A token rather than a sprintf argument, so the many strings that
     * already take numbered arguments keep their format untouched. Safe to
     * pass on to sprintf(): a label is letters, digits and hyphens only.
     */
    public static function in(string $text): string
    {
        return str_replace(self::TOKEN, self::get(), $text);
    }

    /**
     * First DNS label of the host in $url, upper-cased; FALLBACK when there
     * is none. Accepts a value without a scheme, with a port, with a path.
     */
    public static function fromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return self::FALLBACK;
        }
        if (!str_contains($url, '://')) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return self::FALLBACK;
        }
        $host = rtrim(trim($host, '[]'), '.');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::FALLBACK;
        }

        $label = explode('.', $host)[0];
        // A hostname label, and not a bare number: "10" out of a truncated
        // address is not a name anybody calls the server by.
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $label) !== 1 || ctype_digit($label)) {
            return self::FALLBACK;
        }

        return strtoupper($label);
    }

    /** Forget the cached labels (after a URL is saved, and between tests). */
    public static function reset(): void
    {
        self::$labels = [];
    }
}
