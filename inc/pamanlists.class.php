<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Feeds the two mapping forms their PAMAN-side dropdowns. Cached in GLPI's
 * config store for ten minutes so that opening the mapping list half a
 * dozen times does not hammer the API, with a "Reload" button on the forms
 * for the admin who has just created a node in PAMAN.
 *
 * Every method returns an empty array rather than throwing when PAMAN is
 * unreachable; the forms degrade to text inputs (see nodemap.class.php).
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanPamanLists
{
    private const CACHE_SECONDS = 600;

    private const NODES_PATH     = '/api/v1/assets/nodes/';
    private const PLATFORMS_PATH = '/api/v1/assets/platforms/';

    /** @return array<string, string> node id => full path label */
    public static function nodes(bool $force_refresh = false): array
    {
        $raw = self::fetch(self::NODES_PATH, 'provisioning_cache_nodes', $force_refresh);

        $out = [];
        foreach ($raw as $node) {
            if (!is_array($node) || !isset($node['id'])) {
                continue;
            }
            $out[(string) $node['id']] = (string) ($node['full_value'] ?? $node['value'] ?? $node['name'] ?? $node['id']);
        }
        asort($out);

        return $out;
    }

    /** @return array<int, string> platform id => name */
    public static function platforms(bool $force_refresh = false): array
    {
        $raw = self::fetch(self::PLATFORMS_PATH, 'provisioning_cache_platforms', $force_refresh);

        $out = [];
        foreach ($raw as $platform) {
            if (!is_array($platform) || !isset($platform['id'])) {
                continue;
            }
            $out[(int) $platform['id']] = (string) ($platform['name'] ?? $platform['id']);
        }
        asort($out);

        return $out;
    }

    /**
     * @return array<int, mixed> Raw API entries, or [] when PAMAN cannot be reached.
     */
    private static function fetch(string $path, string $cache_key, bool $force_refresh): array
    {
        $config = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);

        // v4.0.0: one cache per connection. Two JumpServers have different
        // nodes and platforms; a shared cache would offer one's list on the
        // other's form for the cache lifetime.
        $connection = PluginIntegrasipamanConnection::current();
        if ($connection !== null) {
            $cache_key .= '_' . $connection->id();
        }

        $cached = json_decode((string) ($config[$cache_key] ?? ''), true);

        if (!$force_refresh
            && is_array($cached)
            && (int) ($cached['at'] ?? 0) > (time() - self::CACHE_SECONDS)
            && is_array($cached['rows'] ?? null)
        ) {
            return $cached['rows'];
        }

        [$base_url, $access_key_id, $access_key_secret, $org_id] = PluginIntegrasipamanConnection::currentCredentials();

        if ($base_url === '' || $access_key_id === '' || $access_key_secret === '') {
            return is_array($cached['rows'] ?? null) ? $cached['rows'] : [];
        }

        require_once __DIR__ . '/jmsapi.class.php';

        $rows = PluginIntegrasipamanJmsApi::signedGet(
            $path, $base_url, $access_key_id, $access_key_secret, $org_id
        );

        if ($rows === null) {
            // Unreachable: serve the stale cache if there is one. A stale
            // node list is far better than an empty dropdown.
            return is_array($cached['rows'] ?? null) ? $cached['rows'] : [];
        }

        Config::setConfigurationValues(
            PluginIntegrasipamanConfig::CONFIG_CONTEXT,
            [$cache_key => json_encode(['at' => time(), 'rows' => $rows])]
        );

        return $rows;
    }
}
