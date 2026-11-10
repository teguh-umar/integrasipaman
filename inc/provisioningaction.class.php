<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The only place in this plugin that writes to PAMAN. Every method here
 * runs because a person with the right bit pressed a button - never from a
 * hook, never from a cron.
 *
 * The decision helpers (interpretLookup, methodFor, interpretResponse) are
 * pure and static so tests/ can exercise them without a GLPI bootstrap or
 * a live bastion.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/jmsapi.class.php';
require_once __DIR__ . '/provisioning.class.php';

require_once __DIR__ . '/serverlabel.class.php';

class PluginIntegrasipamanProvisioningAction
{
    public const HOSTS_PATH = '/api/v1/assets/hosts/';

    /** files/_log/jumpserver_sync.log - the mirror of last_error for anyone tailing logs. */
    private const LOG_FILE = 'jumpserver_sync.log';

    /**
     * Decide what to do with the result of looking up an address in PAMAN.
     *
     * Production PAMAN holds assets created by hand over several years, so
     * the first bulk send would duplicate dozens of hosts without this.
     *
     * Two matches is a refusal, not a choice: adopting the first is how the
     * wrong production host quietly acquires GLPI's idea of its name.
     *
     * @param array<int, mixed> $hosts The `results` of a GET by address.
     * @return array{action:string, asset_id:string, error:string}
     */
    public static function interpretLookup(array $hosts): array
    {
        $ids = [];

        foreach ($hosts as $host) {
            if (is_array($host) && isset($host['id'])) {
                $ids[] = (string) $host['id'];
            }
        }

        if ($ids === []) {
            return ['action' => 'create', 'asset_id' => '', 'error' => ''];
        }

        if (count($ids) === 1) {
            return ['action' => 'adopt', 'asset_id' => $ids[0], 'error' => ''];
        }

        return [
            'action'   => 'refuse',
            'asset_id' => '',
            'error'    => sprintf(
                PluginIntegrasipamanServerLabel::in(__('%1$d hosts in {server} already carry this address (%2$s). Resolve the duplicate in {server} first.', 'integrasipaman')),
                count($ids),
                implode(', ', $ids)
            ),
        ];
    }

    /**
     * Create or update? A row that already carries an asset id is updated
     * in place. A resend that POSTed would create the second host this
     * whole design exists to prevent.
     *
     * @return array{method:string, path:string}
     */
    public static function methodFor(string $existing_asset_id): array
    {
        $existing_asset_id = trim($existing_asset_id);

        if ($existing_asset_id === '') {
            return ['method' => 'POST', 'path' => self::HOSTS_PATH];
        }

        return ['method' => 'PATCH', 'path' => self::HOSTS_PATH . $existing_asset_id . '/'];
    }

    /**
     * Turn one API response into a verdict plus a sentence worth showing.
     *
     * PAMAN's own validation errors are the most useful thing on the queue
     * page: "platform: This field is required." tells the admin exactly
     * which mapping is missing. A generic "failed to send asset" tells them
     * to open a log file.
     *
     * @return array{ok:bool, message:string}
     */
    public static function interpretResponse(int $code, mixed $body, string $curl_error): array
    {
        if ($code === 0) {
            return [
                'ok'      => false,
                'message' => sprintf(PluginIntegrasipamanServerLabel::in(__('Could not reach {server}: %s', 'integrasipaman')), $curl_error),
            ];
        }

        if ($code >= 200 && $code < 300) {
            return ['ok' => true, 'message' => ''];
        }

        return [
            'ok'      => false,
            'message' => sprintf('HTTP %d: %s', $code, self::flatten($body)),
        ];
    }

    /**
     * Flatten a DRF error body into one readable line. DRF nests per field
     * ({"platform": ["This field is required."]}), and the field name is
     * the half that matters.
     */
    private static function flatten(mixed $body): string
    {
        if (is_string($body)) {
            return mb_substr(trim($body), 0, 500);
        }

        if (!is_array($body)) {
            return '';
        }

        $parts = [];

        foreach ($body as $field => $messages) {
            $text = is_array($messages) ? implode(' ', array_map('strval', $messages)) : (string) $messages;
            $parts[] = is_string($field) ? "{$field}: {$text}" : $text;
        }

        return mb_substr(implode(' | ', $parts), 0, 500);
    }

    /**
     * Send (or resend) one queue row to PAMAN.
     *
     * Order of operations, and why:
     *  1. re-read the row and re-check the state machine - the page the
     *     admin clicked may be minutes old;
     *  2. resolve node/platform now, not from the page, so a mapping fixed
     *     since the page loaded is honoured;
     *  3. for a row with no asset id, look the address up in PAMAN first
     *     (see interpretLookup);
     *  4. POST or PATCH;
     *  5. store the id, the frozen targets, the payload hash, and who did it.
     *
     * Never throws: a bastion outage must leave the queue readable.
     *
     * @return array{ok:bool, message:string}
     */
    public static function send(int $provisioning_id, int $actor_id): array
    {
        try {
            return self::withRowConnection(
                $provisioning_id,
                static fn (): array => self::doSend($provisioning_id, $actor_id)
            );
        } catch (\Throwable $e) {
            self::recordFailure($provisioning_id, $actor_id, 'send', $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Run a write to the bastion as the connection the queue row belongs
     * to (v4.0.0). The row was stamped with it when the Computer became
     * eligible, and the host - if it was ever sent - lives in THAT
     * JumpServer: signing with any other connection's key would create or
     * delete a host in another subsidiary's bastion.
     *
     * Only a row that names NO connection (written before v3.11.0 and never
     * stamped) may fall back to "the only connection there is". A row whose
     * own connection is switched off or gone is refused: with one other
     * connection left active, falling back would sign with that one's key
     * (code review, 4.0.0). A missing row is left for the action to report.
     *
     * @param callable(): array{ok:bool, message:string} $work
     * @return array{ok:bool, message:string}
     */
    private static function withRowConnection(int $provisioning_id, callable $work): array
    {
        $row = new PluginIntegrasipamanProvisioning();
        if (!$row->getFromDB($provisioning_id)) {
            return $work();
        }

        $connection_id = (int) ($row->fields[PluginIntegrasipamanConnection::FK] ?? 0);
        $connection    = PluginIntegrasipamanConnection::byId($connection_id);

        if ($connection !== null) {
            return PluginIntegrasipamanConnection::using($connection, $work);
        }
        if ($connection_id === 0 && count(PluginIntegrasipamanConnection::all()) <= 1) {
            return $work();
        }

        return [
            'ok'      => false,
            'message' => __('This asset is not attached to an active JumpServer connection.', 'integrasipaman'),
        ];
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private static function doSend(int $provisioning_id, int $actor_id): array
    {
        $P = 'PluginIntegrasipamanProvisioning';

        // Step 1: re-read the row. The page the admin clicked may be
        // minutes old, so this is the state machine's source of truth, not
        // whatever the browser posted.
        $row = new $P();
        if (!$row->getFromDB($provisioning_id)) {
            return ['ok' => false, 'message' => __('Provisioning row not found', 'integrasipaman')];
        }

        $status            = (string) $row->fields['status'];
        $existing_asset_id = trim((string) $row->fields['jumpserver_asset_id']);
        $is_resend         = $existing_asset_id !== '';

        if (!$P::canTransition($status, $P::STATUS_PROVISIONED)) {
            $message = sprintf(
                __('Cannot send: row is %s and cannot move to provisioned', 'integrasipaman'),
                $status
            );
            self::recordFailure($provisioning_id, $actor_id, $is_resend ? 'resend' : 'send', $message);
            return ['ok' => false, 'message' => $message];
        }

        $computers_id = (int) $row->fields['computers_id'];
        $computer     = new Computer();
        if (!$computer->getFromDB($computers_id)) {
            $message = __('The GLPI Computer behind this row no longer exists', 'integrasipaman');
            self::recordFailure($provisioning_id, $actor_id, $is_resend ? 'resend' : 'send', $message);
            return ['ok' => false, 'message' => $message];
        }

        $fields  = $computer->fields;
        $address = $P::pickAddress($P::addressesFor($computers_id));

        if ($address === '') {
            $message = __('No usable IPv4 address in GLPI', 'integrasipaman');
            self::recordFailure($provisioning_id, $actor_id, $is_resend ? 'resend' : 'send', $message);
            return ['ok' => false, 'message' => $message];
        }

        // v4.14.0: a first Send for a host PAMAN already holds (pulled host
        // list) is refused, before any mapping or network step. Adopting it
        // would PATCH node/platform onto a host somebody built by hand. The
        // row is left as it is; the next scan drops it.
        if (!$is_resend) {
            $existing = $P::existingHost((string) ($fields['name'] ?? ''), $address);
            if ($existing !== null) {
                return ['ok' => false, 'message' => sprintf(
                    PluginIntegrasipamanServerLabel::in(__('%1$s is already in {server} as %2$s (%3$s); not sent again', 'integrasipaman')),
                    (string) ($fields['name'] ?? ''),
                    (string) $existing['name'],
                    (string) $existing['address']
                )];
            }
        }

        // Step 2: resolve node/platform now, not from anything the page
        // passed, so a mapping fixed since the page loaded is honoured.
        $node_lookup     = PluginIntegrasipamanNodeMap::asLookup();
        $platform_lookup = PluginIntegrasipamanPlatformMap::asLookup();

        $targets = $P::resolveTargets(
            $fields + [
                'type_name' => Dropdown::getDropdownName('glpi_computertypes', (int) ($fields['computertypes_id'] ?? 0)),
                'os_name'   => Dropdown::getDropdownName('glpi_operatingsystems', (int) ($fields['operatingsystems_id'] ?? 0)),
            ],
            $node_lookup,
            $platform_lookup
        );

        if ($targets['blocked'] !== '') {
            self::recordFailure($provisioning_id, $actor_id, $is_resend ? 'resend' : 'send', $targets['blocked']);
            return ['ok' => false, 'message' => $targets['blocked']];
        }

        [$base_url, $access_key_id, $access_key_secret, $org_id] = PluginIntegrasipamanConnection::currentCredentials();

        $payload = $P::buildPayload($fields, $address, $targets['node_id'], (int) $targets['platform_id']);

        $asset_id    = $existing_asset_id;
        $last_action = $is_resend ? 'resend' : 'send';

        // Step 3: for a row with no asset id, look the address up in PAMAN
        // first. Production PAMAN already holds hosts created by hand over
        // years; a blind POST would duplicate dozens of them.
        if (!$is_resend) {
            $lookup_path = self::HOSTS_PATH . '?address=' . urlencode($address);
            $lookup      = PluginIntegrasipamanJmsApi::signedRequest(
                'GET',
                $lookup_path,
                null,
                $base_url,
                $access_key_id,
                $access_key_secret,
                $org_id
            );

            $lookup_verdict = self::interpretResponse($lookup['code'], $lookup['body'], $lookup['error']);
            if (!$lookup_verdict['ok']) {
                self::recordFailure($provisioning_id, $actor_id, 'send', $lookup_verdict['message']);
                return ['ok' => false, 'message' => $lookup_verdict['message']];
            }

            $hosts_body = $lookup['body'];
            $hosts      = is_array($hosts_body) ? ($hosts_body['results'] ?? $hosts_body) : [];

            $decision = self::interpretLookup(is_array($hosts) ? $hosts : []);

            if ($decision['action'] === 'refuse') {
                self::recordFailure($provisioning_id, $actor_id, 'send', $decision['error']);
                return ['ok' => false, 'message' => $decision['error']];
            }

            if ($decision['action'] === 'adopt') {
                $asset_id    = $decision['asset_id'];
                $last_action = 'adopt';
            }
        }

        // Step 4: POST or PATCH.
        $method_info = self::methodFor($asset_id);
        $response    = PluginIntegrasipamanJmsApi::signedRequest(
            $method_info['method'],
            $method_info['path'],
            $payload,
            $base_url,
            $access_key_id,
            $access_key_secret,
            $org_id
        );

        $verdict = self::interpretResponse($response['code'], $response['body'], $response['error']);

        if (!$verdict['ok']) {
            self::recordFailure($provisioning_id, $actor_id, $last_action, $verdict['message']);
            return ['ok' => false, 'message' => $verdict['message']];
        }

        // A create's response body carries the new host's id; a resend or
        // an adopt already knows it.
        if ($asset_id === '') {
            $body    = $response['body'];
            $asset_id = is_array($body) && isset($body['id']) ? (string) $body['id'] : '';
        }

        // A create that comes back 2xx but with no id in the body is a
        // failure, not a success: writing this row `provisioned` with an
        // empty jumpserver_asset_id would make the NEXT send take the
        // non-resend path again - lookup, and possibly a second POST. That
        // second POST is the exact duplicate host this design exists to
        // prevent, so refuse to call this a success.
        if ($asset_id === '') {
            $message = PluginIntegrasipamanServerLabel::in(__('{server} accepted the create but returned no asset id in its response', 'integrasipaman'));
            self::recordFailure($provisioning_id, $actor_id, $last_action, $message);
            return ['ok' => false, 'message' => $message];
        }

        // The row was last read before the lookup and the POST/PATCH. A PAM
        // round-trip is not instant, and a concurrent action may have moved
        // this row meanwhile - to pending_removal, say, which may only be
        // followed by removed or failed. Re-read and re-check against the
        // status as it is NOW, not as it was before the network call.
        $fresh = new $P();

        if (!$fresh->getFromDB($provisioning_id)) {
            return ['ok' => false, 'message' => PluginIntegrasipamanServerLabel::in(__('The queue row disappeared while the asset was being sent to {server}.', 'integrasipaman'))];
        }

        $status_now = (string) $fresh->fields['status'];

        if (!$P::canTransition($status_now, $P::STATUS_PROVISIONED)) {
            // PAMAN already has the asset - losing that id would strand the
            // host. Record it and the error, but leave the status alone;
            // an automatic rollback here could delete a host somebody else
            // just legitimately created. A person has to judge this one.
            $message = sprintf(
                PluginIntegrasipamanServerLabel::in(__('The asset was written to {server} (id %1$s), but this row had moved to "%2$s" meanwhile, so its status was left alone. Check it before acting.', 'integrasipaman')),
                $asset_id,
                $status_now
            );

            $fresh->update([
                'id'                  => $provisioning_id,
                'jumpserver_asset_id' => $asset_id,
                'last_error'          => $message,
            ]);

            return ['ok' => false, 'message' => $message];
        }

        // Step 5: store the id, the frozen targets, the payload hash, and
        // who did it.
        $fresh->update([
            'id'                  => $provisioning_id,
            'status'              => $P::STATUS_PROVISIONED,
            'jumpserver_asset_id' => $asset_id,
            'node_id'             => $targets['node_id'],
            'node_label'          => $targets['node_label'],
            'platform_id'         => $targets['platform_id'],
            'platform_label'      => $targets['platform_label'],
            'payload_hash'        => $P::payloadHash($payload),
            'drift_fields'        => '',
            'last_actor_id'       => $actor_id,
            'last_action'         => $last_action,
            'last_action_date'    => date('Y-m-d H:i:s'),
            'last_error'          => '',
        ]);

        return ['ok' => true, 'message' => ''];
    }

    /**
     * Does this ticket authorise removing this asset from PAMAN?
     *
     * Three conditions, all required:
     *  1. the ticket exists and is not in the GLPI trash;
     *  2. its status is one the admin nominated as an approval;
     *  3. it is linked to the Computer being removed.
     *
     * The third is what makes this a control rather than paperwork: without
     * it, one old closed ticket number authorises removing any asset in the
     * estate.
     *
     * @param array{id?:int, status?:int, is_deleted?:int} $ticket Empty array if not found.
     * @param list<int> $linked_computer_ids Computers linked to the ticket via Item_Ticket.
     * @param list<int> $allowed_statuses
     * @return array{ok:bool, reason:string}
     */
    public static function validateTicket(
        array $ticket,
        array $linked_computer_ids,
        array $allowed_statuses,
        int $computers_id
    ): array {
        if ($ticket === [] || (int) ($ticket['id'] ?? 0) <= 0) {
            return ['ok' => false, 'reason' => __('Ticket not found.', 'integrasipaman')];
        }

        if ((int) ($ticket['is_deleted'] ?? 0) === 1) {
            return ['ok' => false, 'reason' => __('That ticket is in the trash.', 'integrasipaman')];
        }

        // An empty allow-list is a control nobody configured, not a control
        // that passes everything. Refuse and say so.
        if ($allowed_statuses === []) {
            return [
                'ok'     => false,
                'reason' => __('No ticket status has been configured as an approval. Set one in the plugin configuration first.', 'integrasipaman'),
            ];
        }

        if (!in_array((int) ($ticket['status'] ?? 0), array_map('intval', $allowed_statuses), true)) {
            return [
                'ok'     => false,
                'reason' => __('That ticket\'s status is not one of the statuses accepted as an approval.', 'integrasipaman'),
            ];
        }

        if (!in_array($computers_id, array_map('intval', $linked_computer_ids), true)) {
            return [
                'ok'     => false,
                'reason' => sprintf(
                    __('That ticket is not linked to Computer #%d. Link the ticket to the asset being removed, or cite the ticket that covers it.', 'integrasipaman'),
                    $computers_id
                ),
            ];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /**
     * A DELETE that comes back 404 is success: the host is not in PAMAN,
     * which is the end state that was asked for. It is reported honestly
     * rather than as a deletion this plugin performed.
     *
     * @return array{ok:bool, message:string}
     */
    public static function interpretRemoval(int $code, mixed $body, string $curl_error): array
    {
        if ($code === 404) {
            return [
                'ok'      => true,
                'message' => PluginIntegrasipamanServerLabel::in(__('The host was already absent from {server}; the link has been closed out.', 'integrasipaman')),
            ];
        }

        return self::interpretResponse($code, $body, $curl_error);
    }

    /**
     * Remove one queue row's asset from PAMAN, against a ticket that
     * covers it.
     *
     * Order of operations, and why:
     *  1. re-read the row and refuse unless it is pending_removal - not
     *     merely what the page said;
     *  2. load the ticket and its Item_Ticket links, then validateTicket();
     *  3. only then issue the DELETE;
     *  4. re-check canTransition() immediately before the status write,
     *     after the network call returns - a PAM round-trip is not instant
     *     and the row may have moved meanwhile.
     *
     * Never throws: a bastion outage must leave the queue readable. The row
     * is never deleted from the table - it is the permanent audit record of
     * what was taken out of the bastion, by whom, under which ticket.
     *
     * @return array{ok:bool, message:string}
     */
    public static function remove(int $provisioning_id, int $tickets_id, int $actor_id): array
    {
        try {
            return self::withRowConnection(
                $provisioning_id,
                static fn (): array => self::doRemove($provisioning_id, $tickets_id, $actor_id)
            );
        } catch (\Throwable $e) {
            self::recordFailure($provisioning_id, $actor_id, 'remove', $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private static function doRemove(int $provisioning_id, int $tickets_id, int $actor_id): array
    {
        $P = 'PluginIntegrasipamanProvisioning';

        // Step 1: re-read the row and refuse unless it is pending_removal -
        // not merely what the page said when the admin clicked.
        $row = new $P();
        if (!$row->getFromDB($provisioning_id)) {
            return ['ok' => false, 'message' => __('Provisioning row not found', 'integrasipaman')];
        }

        $status = (string) $row->fields['status'];

        // Refuse unless the row is pending_removal - not merely what the
        // page said when the admin clicked. pending_removal is the only
        // status that transitions to removed, so this check and a
        // canTransition() check against STATUS_REMOVED are equivalent; the
        // explicit equality check gives a clearer refusal message.
        if ($status !== $P::STATUS_PENDING_REMOVAL) {
            $message = sprintf(
                __('Cannot remove: row is "%s", not pending removal', 'integrasipaman'),
                $status
            );
            self::recordFailure($provisioning_id, $actor_id, 'remove', $message);
            return ['ok' => false, 'message' => $message];
        }

        $asset_id = trim((string) $row->fields['jumpserver_asset_id']);
        if ($asset_id === '') {
            $message = PluginIntegrasipamanServerLabel::in(__('This row has no {server} asset id to remove', 'integrasipaman'));
            self::recordFailure($provisioning_id, $actor_id, 'remove', $message);
            return ['ok' => false, 'message' => $message];
        }

        // Step 2: load the ticket and its Item_Ticket links, then validate.
        $computers_id = (int) $row->fields['computers_id'];

        $ticket = new Ticket();
        $ticket_fields = $ticket->getFromDB($tickets_id) ? $ticket->fields : [];

        $linked_computer_ids = [];
        global $DB;
        $iterator = $DB->request([
            'SELECT' => ['items_id'],
            'FROM'   => 'glpi_items_tickets',
            'WHERE'  => [
                'tickets_id' => $tickets_id,
                'itemtype'   => 'Computer',
            ],
        ]);
        foreach ($iterator as $link_row) {
            $linked_computer_ids[] = (int) $link_row['items_id'];
        }

        $allowed_statuses = $P::criteriaFromConfig()['ticket_statuses'];

        $validation = self::validateTicket($ticket_fields, $linked_computer_ids, $allowed_statuses, $computers_id);

        if (!$validation['ok']) {
            self::recordFailure($provisioning_id, $actor_id, 'remove', $validation['reason']);
            return ['ok' => false, 'message' => $validation['reason']];
        }

        // Step 3: only now issue the DELETE.
        [$base_url, $access_key_id, $access_key_secret, $org_id] = PluginIntegrasipamanConnection::currentCredentials();

        $response = PluginIntegrasipamanJmsApi::signedRequest(
            'DELETE',
            self::HOSTS_PATH . $asset_id . '/',
            null,
            $base_url,
            $access_key_id,
            $access_key_secret,
            $org_id
        );

        $verdict = self::interpretRemoval($response['code'], $response['body'], $response['error']);

        if (!$verdict['ok']) {
            self::recordFailure($provisioning_id, $actor_id, 'remove', $verdict['message']);
            return ['ok' => false, 'message' => $verdict['message']];
        }

        // Step 4: re-read and re-check canTransition() against the status as
        // it is NOW, not as it was before the network call - a PAM
        // round-trip is not instant, and a concurrent action may have moved
        // this row meanwhile.
        $fresh = new $P();

        if (!$fresh->getFromDB($provisioning_id)) {
            return ['ok' => false, 'message' => PluginIntegrasipamanServerLabel::in(__('The queue row disappeared while the asset was being removed from {server}.', 'integrasipaman'))];
        }

        $status_now = (string) $fresh->fields['status'];

        if (!$P::canTransition($status_now, $P::STATUS_REMOVED)) {
            $message = sprintf(
                PluginIntegrasipamanServerLabel::in(__('The asset was removed from {server}, but this row had moved to "%s" meanwhile, so its status was left alone. Check it before acting.', 'integrasipaman')),
                $status_now
            );

            $fresh->update([
                'id'         => $provisioning_id,
                'last_error' => $message,
            ]);

            return ['ok' => false, 'message' => $message];
        }

        $fresh->update([
            'id'               => $provisioning_id,
            'status'           => $P::STATUS_REMOVED,
            'tickets_id'       => $tickets_id,
            'last_actor_id'    => $actor_id,
            'last_action'      => 'remove',
            'last_action_date' => date('Y-m-d H:i:s'),
            'last_error'       => $verdict['message'],
        ]);

        return ['ok' => true, 'message' => $verdict['message']];
    }

    /**
     * Write a failed attempt to the row and mirror it to the sync log.
     * Never throws: a bastion outage must leave the queue readable.
     */
    private static function recordFailure(int $provisioning_id, int $actor_id, string $last_action, string $message): void
    {
        try {
            $P = 'PluginIntegrasipamanProvisioning';

            $row = new $P();
            if ($row->getFromDB($provisioning_id)) {
                $current = (string) $row->fields['status'];

                if ($P::canTransition($current, $P::STATUS_FAILED)) {
                    $row->update([
                        'id'               => $provisioning_id,
                        'status'           => $P::STATUS_FAILED,
                        // TEXT column, but keep log lines from ballooning.
                        'last_error'       => mb_substr($message, 0, 2000),
                        'last_actor_id'    => $actor_id,
                        'last_action'      => $last_action,
                        'last_action_date' => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    $row->update([
                        'id'         => $provisioning_id,
                        'last_error' => mb_substr($message, 0, 2000),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Swallow: recordFailure() must never throw, or a failed send
            // would take the queue page down with it.
        }

        try {
            $masked = $message;

            $secret = PluginIntegrasipamanConnection::currentCredentials()[2];

            // If the configured secret ever ends up inside a message (an
            // echoed request, a transport error), redact it before it
            // reaches the log file - never write it verbatim.
            if ($secret !== '' && strpos($masked, $secret) !== false) {
                $masked = str_replace($secret, PluginIntegrasipamanJmsApi::maskToken($secret), $masked);
            }

            Toolbox::logInFile(self::LOG_FILE, sprintf(
                'provisioning #%d: %s',
                $provisioning_id,
                $masked
            ));
        } catch (\Throwable $e) {
            // Swallow: logging must never be the thing that throws.
        }
    }
}
