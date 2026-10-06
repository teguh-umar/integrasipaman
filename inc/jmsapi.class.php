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
     * concatenated entries.
     *
     * Each request is signed with JumpServer's Access Key HTTP Signature
     * scheme (HMAC-SHA256 over request-target/accept/date/x-jms-org, see
     * signedGetPage()); pages are followed via DRF's "next" link until it is
     * null or $max_pages is reached. Buffers the whole result in memory - fine
     * for the low-volume audit-log crons; the high-volume terminal pull uses
     * signedGetEach() instead.
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

    /**
     * Like signedGet(), but hands each page of entries to $on_page and
     * discards it before fetching the next - bounded memory regardless of
     * how many rows the window holds. Used by the high-volume terminal
     * pull; signedGet() stays for the low-volume audit-log cron.
     *
     * $on_page may return false to stop paging immediately - used by the
     * date-windowed pulls, where PluginIntegrasipamanApiWindow has proven the
     * stream ran past the start of the requested window (see
     * inc/apiwindow.class.php). That is a deliberate, complete stop, so it
     * reports success, unlike running into $max_pages.
     *
     * @param callable(array<int,mixed>):(bool|void) $on_page Receives one page's
     *               "results" list; return false to stop paging.
     * @return bool  true if pagination ran to completion (or was stopped on
     *               purpose by $on_page); false if it was cut short by a page
     *               failure or by hitting $max_pages (i.e. the window may be
     *               incompletely fetched).
     */
    public static function signedGetEach(
        string $api_path,
        string $base_url,
        string $access_key_id,
        string $access_key_secret,
        string $org_id,
        callable $on_page,
        int $page_size = self::PAGE_SIZE,
        int $max_pages = self::MAX_PAGES
    ): bool {
        $path   = self::appendLimit($api_path, $page_size);
        $offset = 0;

        for ($page = 0; $page < $max_pages && $path !== null; $page++) {
            $body = self::signedGetPage($path, $base_url, $access_key_id, $access_key_secret, $org_id);

            if ($body === null) {
                return false; // page failure mid-window
            }

            $results = $body['results'] ?? $body;
            $count   = is_array($results) ? count($results) : 0;

            if (is_array($results) && $count > 0) {
                if ($on_page($results) === false) {
                    return true; // caller has everything it asked for
                }
            }

            $next = $body['next'] ?? null;

            if (is_string($next) && $next !== '') {
                $path = self::nextPath($next);
            } elseif ($count === $page_size) {
                // Some JumpServer command-storage backends (ES-backed) omit
                // "next"; the reference script drives offset manually and
                // stops on an empty page. Mirror that as a fallback.
                $offset += $page_size;
                $path = self::withOffset(self::appendLimit($api_path, $page_size), $offset);
            } else {
                $path = null; // short page => last page
            }
        }

        return $path === null; // null => finished; non-null => hit max_pages
    }

    /** Replace/add an offset= param on a path that already has a query string. */
    private static function withOffset(string $api_path, int $offset): string
    {
        $api_path = preg_replace('/([?&])offset=\d+/', '$1offset=' . $offset, $api_path, 1, $replaced);
        if ($replaced) {
            return $api_path;
        }
        return $api_path . '&offset=' . $offset;
    }

    /**
     * Append "limit={page_size}" to a path unless it already carries a
     * limit= param. First page of every paginated pull.
     */
    private static function appendLimit(string $api_path, int $page_size): string
    {
        if (strpos($api_path, 'limit=') !== false) {
            return $api_path;
        }

        $separator = strpos($api_path, '?') === false ? '?' : '&';

        return $api_path . $separator . 'limit=' . $page_size;
    }

    /**
     * Reduce a DRF absolute "next" URL to the path+query this signer needs
     * (the signature covers the request-target only). Null if unparseable.
     */
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

    /**
     * Build the Authorization header for one request.
     *
     * Pure and public so tests can pin the exact string-to-sign.
     *
     * The header list below is proven against live PAMAN for GET only. It
     * is ASSUMED to hold for POST/PATCH/DELETE - that assumption has never
     * been executed against the bastion. If PAMAN requires `digest` or
     * `content-type` to be signed on a body-carrying request, every send
     * returns 401 and this function is the only thing that changes.
     * scripts/probe_provisioning_write.php (uncommitted, gitignored) exists
     * to settle it and must be run before the first production send.
     * Do not add or reorder headers without re-probing.
     */
    public static function buildAuthHeader(
        string $method,
        string $api_path,
        string $date_header,
        string $org_id,
        string $access_key_id,
        string $access_key_secret
    ): string {
        $verb = strtolower($method);

        $string_to_sign = "(request-target): {$verb} {$api_path}\n"
            . "accept: application/json\n"
            . "date: {$date_header}\n"
            . "x-jms-org: {$org_id}";

        $signature = base64_encode(hash_hmac('sha256', $string_to_sign, $access_key_secret, true));

        return sprintf(
            'Signature keyId="%s",algorithm="hmac-sha256",headers="(request-target) accept date x-jms-org",signature="%s"',
            $access_key_id,
            $signature
        );
    }

    /**
     * Redact a secret (an access key secret, or any string it appears
     * inside) before it reaches a log line. Keeps just enough of the token
     * (first 4 characters) to help correlate log entries with a specific
     * configured credential without exposing the value itself.
     */
    public static function maskToken(string $token): string
    {
        $visible = mb_substr($token, 0, 4);

        return $visible . str_repeat('*', 8) . '[REDACTED]';
    }

    /**
     * One door for every write: POST (create), PATCH (resend), DELETE
     * (remove), and the GET of a single host.
     *
     * Unlike signedGet*(), this returns the HTTP code and decoded body even
     * on failure. The provisioning queue shows the admin what PAMAN itself
     * said - "platform: this field is required" is actionable, "failed to
     * send asset" is not - so the detail has to survive the call.
     *
     * @param array<string, mixed>|null $body JSON request body, or null for GET/DELETE.
     * @return array{code:int, body:mixed, error:string}
     */
    public static function signedRequest(
        string $method,
        string $api_path,
        ?array $body,
        string $base_url,
        string $access_key_id,
        string $access_key_secret,
        string $org_id
    ): array {
        $date_header = gmdate('D, d M Y H:i:s \G\M\T');

        $headers = [
            'Accept: application/json',
            'Date: ' . $date_header,
            'Authorization: ' . self::buildAuthHeader(
                $method, $api_path, $date_header, $org_id, $access_key_id, $access_key_secret
            ),
            'X-JMS-ORG: ' . $org_id,
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $curl_handle = curl_init();

        curl_setopt_array($curl_handle, [
            CURLOPT_URL            => rtrim($base_url, '/') . $api_path,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($body !== null) {
            curl_setopt($curl_handle, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response   = curl_exec($curl_handle);
        $http_code  = (int) curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl_handle);

        curl_close($curl_handle);

        if ($response === false) {
            return ['code' => 0, 'body' => null, 'error' => $curl_error];
        }

        $decoded = json_decode((string) $response, true);

        return [
            'code'  => $http_code,
            // A 204 has no body; keep the raw string for anything undecodable
            // so the queue page can quote PAMAN verbatim.
            'body'  => json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $response,
            'error' => '',
        ];
    }
}
