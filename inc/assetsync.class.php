<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * One-way asset synchronization: GLPI is the source of truth, so every
 * Computer create/update is pushed on to JumpServer's Host REST API.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

/**
 * Plain service/helper class (no GLPI DB table of its own), instantiated
 * on demand from the item_add/item_update hook callbacks in hook.php.
 */
class PluginIntegrasipamanAssetSync
{
    /**
     * JumpServer REST API path for host assets, appended to the
     * configured base URL.
     */
    private const API_PATH = '/api/v1/assets/hosts/';

    /**
     * Bounded timeout so a slow/unreachable JumpServer never blocks a
     * GLPI Computer save for long.
     */
    private const REQUEST_TIMEOUT = 10;

    /**
     * Log file name under GLPI's internal log directory.
     */
    private const LOG_FILE = 'jumpserver_sync.log';

    /**
     * Push a Computer's current state to JumpServer.
     *
     * This method never throws: any configuration, query or HTTP failure
     * is caught, logged via Toolbox::logInFile(), and reported back as a
     * false return, so a JumpServer outage can never break saving a
     * Computer in GLPI.
     *
     * @param Computer $computer The computer to synchronize.
     * @param string   $action   'add' to POST a new host, 'update' to PUT an existing one.
     *
     * @return boolean True on a successful (2xx) push, false otherwise.
     */
    public function pushToJumpServer(Computer $computer, string $action): bool
    {
        try {
            return $this->doPush($computer, $action);
        } catch (\Throwable $e) {
            $this->logError(sprintf(
                'Unexpected error while syncing computer #%d to JumpServer: %s',
                (int) ($computer->fields['id'] ?? 0),
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * Actual push logic, isolated so pushToJumpServer() can wrap it in a
     * single catch-all.
     */
    private function doPush(Computer $computer, string $action): bool
    {
        $config            = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);
        $base_url          = trim($config['jumpserver_url'] ?? '');
        $access_key_id     = trim($config['jumpserver_access_key_id'] ?? '');
        $access_key_secret = trim($config['jumpserver_access_key_secret'] ?? '');
        $org_id            = trim($config['jumpserver_org_id'] ?? '');

        if ($base_url === '' || $access_key_id === '' || $access_key_secret === '') {
            $this->logError(sprintf(
                'Skipped sync for computer #%d: JumpServer URL/Access Key ID/Secret is not configured.',
                (int) ($computer->fields['id'] ?? 0)
            ));

            return false;
        }

        $payload = $this->buildPayload($computer);
        // JumpServer's REST convention favors PATCH for partial updates;
        // PUT is used here as a safe default full-resource replace.
        $method  = $action === 'add' ? 'POST' : 'PUT';

        $result = $this->sendRequest(
            $method,
            $base_url,
            self::API_PATH,
            $access_key_id,
            $access_key_secret,
            $org_id,
            $payload
        );

        if ($result === null) {
            return false;
        }

        return true;
    }

    /**
     * Map GLPI Computer fields to the JumpServer Host payload shape.
     *
     * @param Computer $computer
     *
     * @return array<string, string>
     */
    private function buildPayload(Computer $computer): array
    {
        $computer_id = (int) ($computer->fields['id'] ?? 0);
        $name        = (string) ($computer->fields['name'] ?? '');

        $ip = $this->getMainIpAddress($computer_id);

        if ($ip === '') {
            // Network port/IP data is not always present in GLPI; fall
            // back to the computer's own name, which commonly doubles as
            // a resolvable hostname.
            $ip = $name;
        }

        return [
            'name'    => $name,
            'ip'      => $ip,
            'os'      => $this->getOperatingSystemName((int) ($computer->fields['operatingsystems_id'] ?? 0)),
            'comment' => (string) ($computer->fields['comment'] ?? ''),
        ];
    }

    /**
     * Resolve an operatingsystems_id dropdown id to its display name.
     *
     * @param integer $os_id
     *
     * @return string Empty string if unset or not found.
     */
    private function getOperatingSystemName(int $os_id): string
    {
        if ($os_id <= 0) {
            return '';
        }

        $operating_system = new OperatingSystem();

        if (!$operating_system->getFromDB($os_id)) {
            return '';
        }

        return (string) ($operating_system->fields['name'] ?? '');
    }

    /**
     * Fetch the first IP address associated with the computer through
     * GLPI's network port tables (Computer -> NetworkPort -> NetworkName
     * -> IPAddress), using a safe array-based query only.
     *
     * @param integer $computer_id
     *
     * @return string Empty string if no IP address is found.
     */
    private function getMainIpAddress(int $computer_id): string
    {
        global $DB;

        if ($computer_id <= 0) {
            return '';
        }

        $iterator = $DB->request([
            'SELECT'     => ['glpi_ipaddresses.name AS ip'],
            'FROM'       => 'glpi_networkports',
            'INNER JOIN' => [
                'glpi_networknames' => [
                    'FKEY' => [
                        'glpi_networkports' => 'id',
                        'glpi_networknames' => 'items_id',
                    ],
                ],
                'glpi_ipaddresses' => [
                    'FKEY' => [
                        'glpi_networknames' => 'id',
                        'glpi_ipaddresses'  => 'items_id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_networkports.itemtype' => 'Computer',
                'glpi_networkports.items_id' => $computer_id,
                'glpi_networknames.itemtype' => 'NetworkPort',
                'glpi_ipaddresses.itemtype'  => 'NetworkName',
                'glpi_ipaddresses.name'      => ['<>', ''],
            ],
            'LIMIT' => 1,
        ]);

        $row = $iterator->current();

        return (string) ($row['ip'] ?? '');
    }

    /**
     * Send the actual HTTP request to JumpServer via cURL.
     *
     * Authenticates via JumpServer's Access Key HTTP Signature scheme
     * (HMAC-SHA256 request signing) - see inc/cron.class.php for the same
     * scheme applied to a GET request; the signing logic here is identical,
     * only the request-target method verb changes (post/put instead of
     * get). The GET case was verified directly against production PAMAN
     * (2026-08-26, HTTP 200 on a live curl test) with the header list
     * "(request-target) accept date x-jms-org" - the POST/PUT case follows
     * the same header list but has not been separately verified against a
     * live instance.
     *
     * @param string               $method            'POST' or 'PUT'.
     * @param string               $base_url          Configured JumpServer base URL/IP.
     * @param string               $api_path           Relative API path (e.g. self::API_PATH).
     * @param string               $access_key_id     Configured JumpServer Access Key ID.
     * @param string               $access_key_secret Configured JumpServer Access Key Secret.
     * @param string               $org_id            Configured JumpServer organization ID (X-JMS-ORG header).
     * @param array<string, mixed> $payload           Request body, JSON-encoded.
     *
     * @return array<string, mixed>|null Decoded response body on success, null on any failure.
     */
    private function sendRequest(
        string $method,
        string $base_url,
        string $api_path,
        string $access_key_id,
        string $access_key_secret,
        string $org_id,
        array $payload
    ): ?array {
        $url = rtrim($base_url, '/') . $api_path;

        // RFC 1123 date, matching the exact format signed into the request
        // (JumpServer validates the Date header against the signature).
        $date_header = gmdate('D, d M Y H:i:s \G\M\T');

        $string_to_sign = sprintf(
            "(request-target): %s %s\naccept: application/json\ndate: %s\nx-jms-org: %s",
            strtolower($method),
            $api_path,
            $date_header,
            $org_id
        );
        $signature = base64_encode(hash_hmac('sha256', $string_to_sign, $access_key_secret, true));

        $authorization = sprintf(
            'Signature keyId="%s",algorithm="hmac-sha256",headers="(request-target) accept date x-jms-org",signature="%s"',
            $access_key_id,
            $signature
        );

        $curl_handle = curl_init();

        curl_setopt_array($curl_handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            // json_encode() itself safely escapes every string value
            // (quotes, backslashes, control characters), so GLPI-sourced
            // free text such as the OS name or Computer comment can never
            // break out of the JSON payload sent to JumpServer.
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Date: ' . $date_header,
                'Authorization: ' . $authorization,
                'X-JMS-ORG: ' . $org_id,
            ],
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response   = curl_exec($curl_handle);
        $http_code  = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl_handle);

        curl_close($curl_handle);

        if ($response === false) {
            // $curl_error is transport-level text from cURL itself, not
            // from JumpServer, but it is still masked defensively below in
            // case it ever echoes back part of the request (e.g. a proxy
            // error page including request headers).
            $this->logError(sprintf(
                'cURL error while calling JumpServer (%s %s): %s',
                $method,
                $url,
                $curl_error
            ), $access_key_secret);

            return null;
        }

        if ($http_code < 200 || $http_code >= 300) {
            // The response body comes from JumpServer and could, on a
            // misconfigured/verbose-debug instance, echo request details
            // back (including the Authorization header); mask defensively.
            $this->logError(sprintf(
                'JumpServer API returned HTTP %d for %s %s. Response: %s',
                $http_code,
                $method,
                $url,
                mb_substr((string) $response, 0, 500)
            ), $access_key_secret);

            return null;
        }

        $decoded = json_decode((string) $response, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Write a message to GLPI's internal file log
     * (files/_log/jumpserver_sync.log). The Access Key Secret, if provided,
     * is never written verbatim - it is redacted from the message first so
     * it can never leak into on-disk logs even if it appears in a cURL
     * error string or an echoed-back API response body. (The Access Key
     * Secret itself is never sent on the wire either - only its
     * HMAC-derived signature is - but it is still redacted here as
     * defense in depth.)
     *
     * @param string $message           Text to log.
     * @param string $access_key_secret Current Access Key Secret to redact from $message, if present.
     */
    private function logError(string $message, string $access_key_secret = ''): void
    {
        if ($access_key_secret !== '') {
            $message = str_replace($access_key_secret, self::maskToken($access_key_secret), $message);
        }

        Toolbox::logInFile(self::LOG_FILE, $message);
    }

    /**
     * Produce a redacted stand-in for a secret token, keeping just enough
     * of it (first 4 characters) to help correlate log entries with a
     * specific configured secret without exposing the value itself.
     */
    private static function maskToken(string $token): string
    {
        $visible = mb_substr($token, 0, 4);

        return $visible . str_repeat('*', 8) . '[REDACTED]';
    }
}
