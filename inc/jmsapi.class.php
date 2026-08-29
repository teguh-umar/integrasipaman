<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Transport layer for JumpServer's REST API: Access Key HTTP Signature
 * (HMAC-SHA256) request signing plus DRF "next"-link pagination. Holds no
 * domain logic - callers map the returned entries themselves. Extracted
 * from PluginIntegrasipamanCron so PluginIntegrasipamanTerminalPull can
 * reuse the exact same signing that is already proven against production
 * PAMAN for the audit-log endpoints.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanJmsApi
{
    /**
     * Default DRF page size and the default hard cap on pages followed per
     * call. Terminal endpoints override both (larger pages, more pages).
     */
    public const PAGE_SIZE = 100;
    public const MAX_PAGES = 100;

    /**
     * Fetch every page of a JumpServer list endpoint and return the
     * concatenated entries. See the original docblock in cron.class.php
     * for the signing/pagination rationale.
     *
     * @return array<int, mixed>|null All entries, or null on a first-page failure.
     */
    public static function signedGet(
        string $api_path,
        string $base_url,
        string $access_key_id,
        string $access_key_secret,
        string $org_id,
        int $page_size = self::PAGE_SIZE,
        int $max_pages = self::MAX_PAGES
    ): ?array {
        $entries = [];
        $path    = self::appendLimit($api_path, $page_size);

        for ($page = 0; $page < $max_pages && $path !== null; $page++) {
            $body = self::signedGetPage($path, $base_url, $access_key_id, $access_key_secret, $org_id);

            if ($body === null) {
                return $page === 0 ? null : $entries;
            }

            $results = $body['results'] ?? $body;

            if (is_array($results)) {
                foreach ($results as $entry) {
                    $entries[] = $entry;
                }
            }

            $next = $body['next'] ?? null;
            $path = is_string($next) && $next !== '' ? self::nextPath($next) : null;
        }

        return $entries;
    }

    private static function appendLimit(string $api_path, int $page_size): string
    {
        if (strpos($api_path, 'limit=') !== false) {
            return $api_path;
        }

        $separator = strpos($api_path, '?') === false ? '?' : '&';

        return $api_path . $separator . 'limit=' . $page_size;
    }

    private static function nextPath(string $next_url): ?string
    {
        $parts = parse_url($next_url);

        if ($parts === false || !isset($parts['path'])) {
            return null;
        }

        return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Fetch and decode one page. Body is returned whole (caller reads
     * "results" and "next"). Null on transport / HTTP / JSON failure.
     *
     * @return array<string, mixed>|null
     */
    private static function signedGetPage(
        string $api_path,
        string $base_url,
        string $access_key_id,
        string $access_key_secret,
        string $org_id
    ): ?array {
        $url = rtrim($base_url, '/') . $api_path;

        // RFC 1123 date, matching the exact format signed into the request
        // (JumpServer validates the Date header against the signature).
        $date_header = gmdate('D, d M Y H:i:s \G\M\T');

        $string_to_sign = "(request-target): get {$api_path}\naccept: application/json\ndate: {$date_header}\nx-jms-org: {$org_id}";
        $signature      = base64_encode(hash_hmac('sha256', $string_to_sign, $access_key_secret, true));

        $authorization = sprintf(
            'Signature keyId="%s",algorithm="hmac-sha256",headers="(request-target) accept date x-jms-org",signature="%s"',
            $access_key_id,
            $signature
        );

        $curl_handle = curl_init();

        curl_setopt_array($curl_handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Date: ' . $date_header,
                'Authorization: ' . $authorization,
                'X-JMS-ORG: ' . $org_id,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response   = curl_exec($curl_handle);
        $http_code  = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl_handle);

        curl_close($curl_handle);

        if ($response === false) {
            trigger_error('JumpServer API cURL error: ' . $curl_error, E_USER_WARNING);
            return null;
        }

        if ($http_code < 200 || $http_code >= 300) {
            trigger_error('JumpServer API returned HTTP ' . $http_code . ' for ' . $api_path, E_USER_WARNING);
            return null;
        }

        $data = json_decode((string) $response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            trigger_error('JumpServer API returned invalid JSON for ' . $api_path, E_USER_WARNING);
            return null;
        }

        // Return the full body: signedGet() reads both "results" (the page
        // of entries) and "next" (the following page URL, or null) off it.
        // A non-paginated endpoint returns a bare list, handled by the
        // caller's "$body['results'] ?? $body" fallback.
        return $data;
    }
}
