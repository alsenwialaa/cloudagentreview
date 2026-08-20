<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Application\Contract\Clock;
use YassinStore\AiAssistant\Application\Contract\ConversationBusy;
use YassinStore\AiAssistant\Application\Contract\ConversationUnavailable;
use YassinStore\AiAssistant\Application\Contract\TurnLeaseLost;
use YassinStore\AiAssistant\Application\Contract\TurnRepository;
use YassinStore\AiAssistant\Application\Contract\TurnRequestConflict;
use YassinStore\AiAssistant\Domain\Shared\Uuid;

final class WpTurnRepository implements TurnRepository
{
    private const RESPONSE_MAX_BYTES = 2097152; // 2 MiB.

    public function __construct(private readonly Clock $clock)
    {
    }

    public function claim(string $conversationId, string $clientTurnId, string $requestHash, int $staleAfterSeconds): array
    {
        $this->assertTurnKey($conversationId, $clientTurnId);
        $this->assertRequestHash($requestHash);
        if ($staleAfterSeconds < 30 || $staleAfterSeconds > 3600) {
            throw new \InvalidArgumentException('The turn lease duration is outside the supported range.');
        }

        global $wpdb;
        $this->begin();
        try {
            $this->lockActiveConversation($conversationId);

            // Replaying or recovering an exact durable turn is read-only with
            // respect to other work in the conversation. Only a genuinely new
            // client turn must satisfy the one-processing-turn invariant.
            if (!$this->exactTurnExists($conversationId, $clientTurnId)) {
                $this->assertNoOtherUnresolvedTurn($conversationId, $clientTurnId);
            }
            $claim = $this->claimLocked(
                $conversationId,
                $clientTurnId,
                $requestHash,
                $staleAfterSeconds
            );
            $this->commit();
            return $claim;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    /**
     * Claim or inspect an idempotent turn while the owning conversation row is
     * locked. The lock serializes new turn creation with deletion and with any
     * other new turn for the same conversation.
     *
     * @return array{state:string,id:int,claim_version:int,response?:array<string,mixed>}
     */
    private function claimLocked(
        string $conversationId,
        string $clientTurnId,
        string $requestHash,
        int $staleAfterSeconds
    ): array {
        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $nowValue = $this->clock->now();
        $now = $this->date($nowValue);
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                (conversation_id,client_turn_id,request_hash,status,claim_version,lease_seconds,created_at,updated_at)
                SELECT %s,%s,%s,'processing',1,%d,%s,%s FROM {$conversations}
                WHERE id = %s AND lifecycle_state = 'active' AND expires_at > %s",
                $conversationId,
                $clientTurnId,
                $requestHash,
                $staleAfterSeconds,
                $now,
                $now,
                $conversationId,
                $now
            )
        );

        if ($inserted === false) {
            throw new \RuntimeException('Unable to claim the conversation turn.');
        }

        $row = $this->rowForConversation($conversationId, $clientTurnId);
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to claim the conversation turn.');
            }
            throw new ConversationUnavailable('The conversation is no longer active.');
        }
        $row = $this->validatedTurnRow($row);
        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            throw new TurnRequestConflict('The turn identifier was reused with different canonical request content.');
        }

        $status = (string) $row['status'];
        $id = (int) $row['id'];
        $claimVersion = (int) $row['claim_version'];
        $leaseSeconds = (int) $row['lease_seconds'];
        $response = $this->responseFromRow($row);
        if ($status === 'completed' || $status === 'failed') {
            return array(
                'state' => $status,
                'id' => $id,
                'claim_version' => $claimVersion,
                'response' => $response,
            );
        }

        if ($response !== array()) {
            return array(
                'state' => 'checkpointed',
                'id' => $id,
                'claim_version' => $claimVersion,
                'response' => $response,
            );
        }

        if ($inserted === 1) {
            return array('state' => 'new', 'id' => $id, 'claim_version' => $claimVersion);
        }

        $updatedAt = $this->databaseDate((string) $row['updated_at']);
        if ($updatedAt->getTimestamp() > $nowValue->getTimestamp() - $leaseSeconds) {
            return array('state' => 'processing', 'id' => $id, 'claim_version' => $claimVersion);
        }

        // Reclaiming stale work creates a new live owner. Reassert the global
        // per-conversation invariant at that exact boundary so legacy or corrupt
        // duplicate processing rows cannot be revived alongside another worker.
        $this->assertNoOtherUnresolvedTurn($conversationId, $clientTurnId);

        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET updated_at = %s, claim_version = claim_version + 1, lease_seconds = %d
                 WHERE id = %d
                   AND claim_version = %d
                   AND status = 'processing'
                   AND updated_at = %s
                   AND (response_json IS NULL OR response_json = '')",
                $now,
                $staleAfterSeconds,
                $id,
                $claimVersion,
                (string) $row['updated_at']
            )
        );
        if ($claimed === false) {
            throw new \RuntimeException('Unable to reclaim the stale conversation turn.');
        }
        return array(
            'state' => $claimed === 1 ? 'retry' : 'processing',
            'id' => $id,
            'claim_version' => $claimed === 1 ? $claimVersion + 1 : $claimVersion,
        );
    }

    public function checkpoint(int $turnId, int $claimVersion, array $response): void
    {
        $this->assertClaimIdentifiers($turnId, $claimVersion);
        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $json = $this->encodeResponse($response, 'Unable to encode the turn checkpoint.');
        $now = $this->date($this->clock->now());
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} t
                 INNER JOIN {$conversations} c
                    ON c.id = t.conversation_id AND c.lifecycle_state = 'active'
                 SET t.response_json = %s, t.updated_at = %s
                 WHERE t.id = %d
                   AND t.claim_version = %d
                   AND t.status = 'processing'
                   AND (t.response_json IS NULL OR t.response_json = '')
                   AND TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at) > %s",
                $json,
                $now,
                $turnId,
                $claimVersion,
                $now
            )
        );
        if ($updated === 1) {
            return;
        }
        if ($updated === false) {
            throw new \RuntimeException('Unable to checkpoint the conversation turn.');
        }

        $row = $this->rowById($turnId);
        if (is_array($row)) {
            $row = $this->validatedTurnRow($row);
        }
        if (is_array($row)
            && (int) ($row['claim_version'] ?? 0) === $claimVersion
            && (string) ($row['status'] ?? '') === 'processing'
            && hash_equals($json, $this->responseJsonFromRow($row))) {
            return;
        }
        throw new TurnLeaseLost('The conversation turn lease was lost before checkpointing.');
    }

    public function heartbeat(int $turnId, int $claimVersion): void
    {
        $this->assertClaimIdentifiers($turnId, $claimVersion);
        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $nowValue = $this->clock->now();
        $now = $this->date($nowValue);
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} t
                 INNER JOIN {$conversations} c
                    ON c.id = t.conversation_id AND c.lifecycle_state = 'active'
                 SET t.updated_at = %s
                 WHERE t.id = %d
                   AND t.claim_version = %d
                   AND t.status = 'processing'
                   AND TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at) > %s",
                $now,
                $turnId,
                $claimVersion,
                $now
            )
        );
        if ($updated === 1) {
            return;
        }
        if ($updated === false) {
            throw new \RuntimeException('Unable to renew the conversation turn lease.');
        }

        // MySQL reports zero changed rows when multiple heartbeats fall within
        // the same second. Accept only the exact still-owned processing claim.
        $row = $this->rowById($turnId);
        if (is_array($row)) {
            $row = $this->validatedTurnRow($row);
        }
        if (is_array($row)
            && (int) ($row['claim_version'] ?? 0) === $claimVersion
            && (string) ($row['status'] ?? '') === 'processing'
            && $this->leaseIsFresh($row, $nowValue)) {
            return;
        }
        throw new TurnLeaseLost('The conversation turn lease was lost before the heartbeat.');
    }

    public function expireStale(
        int $turnId,
        int $claimVersion,
        string $errorCode,
        array $response
    ): bool {
        $this->assertClaimIdentifiers($turnId, $claimVersion);
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('The stale-turn error code is invalid.');
        }

        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $row = $this->rowById($turnId);
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to inspect the abandoned conversation turn.');
            }
            return false;
        }
        $row = $this->validatedTurnRow($row);
        if ((int) $row['claim_version'] !== $claimVersion
            || (string) $row['status'] !== 'processing'
            || $this->responseFromRow($row) !== array()) {
            return false;
        }

        $now = $this->clock->now();
        $updatedAt = $this->databaseDate((string) $row['updated_at']);
        $leaseSeconds = (int) $row['lease_seconds'];
        if ($updatedAt->getTimestamp() > $now->getTimestamp() - $leaseSeconds) {
            return false;
        }

        $json = $this->encodeResponse($response, 'Unable to encode the abandoned turn response.');
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} t
                 INNER JOIN {$conversations} c
                    ON c.id = t.conversation_id AND c.lifecycle_state = 'active'
                 SET t.status = 'failed',
                     t.claim_version = t.claim_version + 1,
                     t.response_json = %s,
                     t.error_code = %s,
                     t.updated_at = %s
                 WHERE t.id = %d
                   AND t.claim_version = %d
                   AND t.lease_seconds = %d
                   AND t.status = 'processing'
                   AND t.updated_at = %s
                   AND (t.response_json IS NULL OR t.response_json = '')",
                $json,
                $errorCode,
                $this->date($now),
                $turnId,
                $claimVersion,
                $leaseSeconds,
                (string) $row['updated_at']
            )
        );
        if ($updated === false) {
            throw new \RuntimeException('Unable to finalize the abandoned conversation turn.');
        }
        return $updated === 1;
    }

    public function sealMissingAsRejected(
        string $conversationId,
        string $clientTurnId,
        string $errorCode,
        array $response,
        int $leaseSeconds
    ): array {
        $this->assertTurnKey($conversationId, $clientTurnId);
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $errorCode) !== 1) {
            throw new \InvalidArgumentException('The missing-turn rejection code is invalid.');
        }
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('The missing-turn lease duration is outside the supported range.');
        }
        if (($response['ok'] ?? null) !== false
            || ($response['turn_finalized'] ?? null) !== true
            || ($response['request_accepted'] ?? null) !== false
            || array_key_exists('message_id', $response)) {
            throw new \InvalidArgumentException('The missing-turn rejection response is invalid.');
        }

        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $json = $this->encodeResponse($response, 'Unable to encode the missing-turn rejection.');
        $requestHash = hash('sha256', "ysai-missing-turn-v1\0" . $conversationId . "\0" . $clientTurnId);
        $now = $this->date($this->clock->now());

        $this->begin();
        try {
            $this->lockActiveConversation($conversationId);
            $inserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table}
                    (conversation_id,client_turn_id,request_hash,status,claim_version,lease_seconds,response_json,error_code,created_at,updated_at)
                    SELECT %s,%s,%s,'failed',1,%d,%s,%s,%s,%s FROM {$conversations}
                    WHERE id = %s AND lifecycle_state = 'active' AND expires_at > %s",
                    $conversationId,
                    $clientTurnId,
                    $requestHash,
                    $leaseSeconds,
                    $json,
                    $errorCode,
                    $now,
                    $now,
                    $conversationId,
                    $now
                )
            );
            if ($inserted === false) {
                throw new \RuntimeException('Unable to seal the missing conversation turn.');
            }

            // claim() holds this same conversation row lock before inserting.
            // Therefore either this rejection is inserted first and permanently
            // fences a delayed chat request by request-hash conflict, or the
            // concurrent real claim is already visible and is returned here.
            $row = $this->rowForConversation($conversationId, $clientTurnId);
            if (!is_array($row)) {
                throw new \RuntimeException('The sealed conversation turn could not be read.');
            }
            $row = $this->validatedTurnRow($row);
            $result = $this->publicTurnRecord($row);
            $this->commit();
            return $result;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public function blockingRecoveryCandidate(string $conversationId, string $excludingClientTurnId): ?array
    {
        $this->assertTurnKey($conversationId, $excludingClientTurnId);
        global $wpdb;
        $table = Schema::turns();
        $messages = Schema::messages();
        $conversations = Schema::conversations();
        $columns = $this->turnSelectColumns('t');
        $now = $this->clock->now();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$columns} FROM {$table} t
                 INNER JOIN {$conversations} c ON c.id = t.conversation_id
                 WHERE t.conversation_id = %s
                   AND t.client_turn_id <> %s
                   AND c.lifecycle_state = 'active'
                   AND c.expires_at > %s
                   AND (
                        t.status = 'processing'
                        OR (
                            t.status = 'completed'
                            AND NOT EXISTS (
                                SELECT 1 FROM {$messages} am
                                WHERE am.turn_id = t.id AND am.role = 'assistant'
                            )
                        )
                        OR (
                            t.status = 'failed'
                            AND EXISTS (
                                SELECT 1 FROM {$messages} um
                                WHERE um.turn_id = t.id AND um.role = 'user'
                            )
                            AND NOT EXISTS (
                                SELECT 1 FROM {$messages} fm
                                WHERE fm.turn_id = t.id AND fm.role = 'assistant'
                            )
                        )
                   )
                 ORDER BY t.id ASC LIMIT 21",
                $conversationId,
                $excludingClientTurnId,
                $this->date($now)
            ),
            ARRAY_A
        );
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Unable to inspect a blocking conversation turn.');
        }
        if (count($rows) > 20) {
            throw new \RuntimeException('The conversation has too many unresolved turns.');
        }

        $candidate = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('A blocking conversation turn is malformed.');
            }
            $row = $this->validatedTurnRow($row);
            $status = (string) $row['status'];
            $response = $this->responseFromRow($row);
            $current = array(
                'status' => $status,
                'id' => (int) $row['id'],
                'client_turn_id' => (string) $row['client_turn_id'],
                'claim_version' => (int) $row['claim_version'],
                'response' => $response,
            );

            if ($status !== 'processing') {
                $candidate ??= $current;
                continue;
            }
            if ($response !== array()) {
                $candidate ??= $current;
                continue;
            }

            $updatedAt = $this->databaseDate((string) $row['updated_at']);
            $leaseSeconds = (int) $row['lease_seconds'];
            if ($updatedAt->getTimestamp() > $now->getTimestamp() - $leaseSeconds) {
                // Never reconcile another row while a genuinely live worker owns
                // the conversation. Its next boundary may still create the
                // presentation that an older retry is trying to inspect.
                return null;
            }
            $candidate ??= $current;
        }

        return $candidate;
    }

    public function complete(int $turnId, int $claimVersion, array $response): void
    {
        $this->finish($turnId, $claimVersion, 'completed', null, $response);
    }

    public function attachTerminalMessageId(int $turnId, int $messageId): void
    {
        if ($turnId <= 0 || $messageId <= 0) {
            throw new \InvalidArgumentException('Terminal turn message identifiers must be positive.');
        }

        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $row = $this->rowById($turnId);
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to read the terminal turn response.');
            }
            throw new \RuntimeException('The terminal turn response cannot be enriched.');
        }
        $row = $this->validatedTurnRow($row);
        $status = (string) $row['status'];
        if (!in_array($status, array('completed', 'failed'), true)) {
            throw new \RuntimeException('The terminal turn response cannot be enriched.');
        }

        $storedJson = $this->responseJsonFromRow($row);
        $stored = $this->decodeResponse($storedJson);
        if ($status === 'failed' && ($stored['request_accepted'] ?? null) === false) {
            throw new \RuntimeException('A rejected turn does not require an assistant message.');
        }
        if (array_key_exists('message_id', $stored)) {
            if ($stored['message_id'] === $messageId) {
                return;
            }
            throw new \RuntimeException('The terminal turn already references another assistant message.');
        }

        $enriched = $stored;
        $enriched['message_id'] = $messageId;
        $json = $this->encodeResponse($enriched, 'Unable to encode the terminal turn response.');

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} t
                 INNER JOIN {$conversations} c
                    ON c.id = t.conversation_id AND c.lifecycle_state = 'active'
                 SET t.response_json = %s, t.updated_at = %s
                 WHERE t.id = %d
                   AND t.status IN ('completed','failed')
                   AND BINARY t.response_json = BINARY %s",
                $json,
                $this->date($this->clock->now()),
                $turnId,
                $storedJson
            )
        );
        if ($updated === 1) {
            return;
        }
        if ($updated === false) {
            throw new \RuntimeException('Unable to attach the assistant message to the terminal turn.');
        }

        // A concurrent retry may have attached the same message between the read
        // and compare-and-set. Accept only that exact idempotent outcome while the
        // conversation remains active.
        $current = $this->rowById($turnId);
        if (is_array($current)) {
            $current = $this->validatedTurnRow($current);
        }
        if (is_array($current)
            && in_array((string) ($current['status'] ?? ''), array('completed', 'failed'), true)
            && ($this->responseFromRow($current)['message_id'] ?? null) === $messageId) {
            return;
        }
        throw new \RuntimeException('The terminal turn changed while attaching its assistant message.');
    }

    public function fail(int $turnId, int $claimVersion, string $errorCode, array $response): void
    {
        $this->finish($turnId, $claimVersion, 'failed', $errorCode, $response);
    }

    public function find(string $conversationId, string $clientTurnId): ?array
    {
        $this->assertTurnKey($conversationId, $clientTurnId);
        global $wpdb;
        $row = $this->rowForConversation($conversationId, $clientTurnId);
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to read the conversation turn.');
            }
            return null;
        }
        $row = $this->validatedTurnRow($row);
        return $this->publicTurnRecord($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,claim_version:int,status:string,response:array<string,mixed>,error_code:string,updated_at:string}
     */
    private function publicTurnRecord(array $row): array
    {
        return array(
            'id' => (int) $row['id'],
            'claim_version' => (int) $row['claim_version'],
            'status' => (string) $row['status'],
            'response' => $this->responseFromRow($row),
            'error_code' => (string) ($row['error_code'] ?? ''),
            'updated_at' => $this->databaseDateToAtom((string) ($row['updated_at'] ?? '')),
        );
    }

    /** @param array<string,mixed> $response */
    private function finish(
        int $turnId,
        int $claimVersion,
        string $status,
        ?string $errorCode,
        array $response
    ): void {
        $this->assertClaimIdentifiers($turnId, $claimVersion);
        if (!in_array($status, array('completed', 'failed'), true)
            || ($status === 'completed' && $errorCode !== null)
            || ($status === 'failed'
                && (!is_string($errorCode)
                    || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $errorCode) !== 1))) {
            throw new \InvalidArgumentException('The turn finalization state is invalid.');
        }

        global $wpdb;
        $json = $this->encodeResponse($response, 'Unable to encode the finalized turn response.');
        $row = $this->rowById($turnId);
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to inspect the conversation turn before finalization.');
            }
            throw new TurnLeaseLost('The conversation turn is no longer active.');
        }
        $row = $this->validatedTurnRow($row);

        if ($this->finalizedRowMatches($row, $claimVersion, $status, $errorCode, $json)) {
            return;
        }
        if ((int) $row['claim_version'] !== $claimVersion
            || (string) $row['status'] !== 'processing') {
            throw new TurnLeaseLost('The conversation turn lease was lost before finalization.');
        }

        $storedJson = $this->responseJsonFromRow($row);
        $storedResponse = $this->responseFromRow($row);
        $checkpointed = $storedResponse !== array();
        $nowValue = $this->clock->now();
        if ($checkpointed) {
            if ($status !== 'completed' || !$this->checkpointCanFinalize($storedResponse, $response)) {
                throw new TurnLeaseLost('The stored turn checkpoint cannot be replaced during finalization.');
            }
        } elseif (!$this->leaseIsFresh($row, $nowValue)) {
            throw new TurnLeaseLost('The conversation turn lease expired before finalization.');
        }

        $table = Schema::turns();
        $conversations = Schema::conversations();
        $now = $this->date($nowValue);
        $errorAssignment = $status === 'failed' ? 't.error_code = %s' : 't.error_code = NULL';
        $freshness = $checkpointed
            ? ''
            : ' AND TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at) > %s';
        $query = "UPDATE {$table} t
                  INNER JOIN {$conversations} c
                     ON c.id = t.conversation_id AND c.lifecycle_state = 'active'
                  SET t.status = %s,
                      t.response_json = %s,
                      {$errorAssignment},
                      t.updated_at = %s
                  WHERE t.id = %d
                    AND t.claim_version = %d
                    AND t.status = 'processing'
                    AND BINARY COALESCE(t.response_json, '') = BINARY %s{$freshness}";
        $arguments = array($status, $json);
        if ($status === 'failed') {
            $arguments[] = (string) $errorCode;
        }
        array_push($arguments, $now, $turnId, $claimVersion, $storedJson);
        if (!$checkpointed) {
            $arguments[] = $now;
        }

        $updated = $wpdb->query($wpdb->prepare($query, ...$arguments));
        if ($updated === 1) {
            return;
        }
        if ($updated === false) {
            throw new \RuntimeException('Unable to finalize the conversation turn.');
        }

        $current = $this->rowById($turnId);
        if (is_array($current)) {
            $current = $this->validatedTurnRow($current);
        }
        if (is_array($current)
            && $this->finalizedRowMatches($current, $claimVersion, $status, $errorCode, $json)) {
            return;
        }
        throw new TurnLeaseLost('The conversation turn lease was lost before finalization.');
    }

    /** @param array<string,mixed> $row */
    private function finalizedRowMatches(
        array $row,
        int $claimVersion,
        string $status,
        ?string $errorCode,
        string $json
    ): bool {
        return (int) ($row['claim_version'] ?? 0) === $claimVersion
            && (string) ($row['status'] ?? '') === $status
            && hash_equals($json, $this->responseJsonFromRow($row))
            && hash_equals((string) ($errorCode ?? ''), (string) ($row['error_code'] ?? ''));
    }

    /**
     * A checkpoint may become a completed response only by adding the durable
     * assistant-message identifier. Every other checkpoint field is immutable.
     *
     * @param array<string,mixed> $checkpoint
     * @param array<string,mixed> $response
     */
    private function checkpointCanFinalize(array $checkpoint, array $response): bool
    {
        if (($checkpoint['ok'] ?? null) !== true
            || !is_int($response['message_id'] ?? null)
            || $response['message_id'] <= 0) {
            return false;
        }
        unset($response['message_id']);
        return $response === $checkpoint;
    }

    /** @param array<string,mixed> $row */
    private function leaseIsFresh(array $row, \DateTimeImmutable $now): bool
    {
        $updatedAt = $this->databaseDate(is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '');
        $leaseSeconds = (int) ($row['lease_seconds'] ?? 0);
        return $leaseSeconds >= 30
            && $leaseSeconds <= 3600
            && $updatedAt->getTimestamp() > $now->getTimestamp() - $leaseSeconds;
    }

    /** @return array<string,mixed>|null */
    private function rowForConversation(string $conversationId, string $clientTurnId): ?array
    {
        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $columns = $this->turnSelectColumns('t');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$columns} FROM {$table} t
                 INNER JOIN {$conversations} c ON c.id = t.conversation_id
                 WHERE t.conversation_id = %s
                   AND t.client_turn_id = %s
                   AND c.lifecycle_state = 'active'
                 LIMIT 1",
                $conversationId,
                $clientTurnId
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function rowById(int $turnId): ?array
    {
        global $wpdb;
        $table = Schema::turns();
        $conversations = Schema::conversations();
        $columns = $this->turnSelectColumns('t');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$columns} FROM {$table} t
                 INNER JOIN {$conversations} c ON c.id = t.conversation_id
                 WHERE t.id = %d AND c.lifecycle_state = 'active' LIMIT 1",
                $turnId
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    private function assertClaimIdentifiers(int $turnId, int $claimVersion): void
    {
        if ($turnId <= 0 || $claimVersion <= 0) {
            throw new \InvalidArgumentException('Turn claim identifiers must be positive.');
        }
    }

    /** @param array<string,mixed> $response */
    private function encodeResponse(array $response, string $message): string
    {
        return BoundedJson::encode($response, self::RESPONSE_MAX_BYTES, $message);
    }

    /** @return array<string,mixed> */
    private function decodeResponse(string $json): array
    {
        return BoundedJson::decode(
            $json,
            self::RESPONSE_MAX_BYTES,
            'The stored turn response is invalid.',
            true
        );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function responseFromRow(array $row): array
    {
        return $this->decodeResponse($this->responseJsonFromRow($row));
    }

    /** @param array<string,mixed> $row */
    private function responseJsonFromRow(array $row): string
    {
        $bytes = $this->databaseInteger($row['response_bytes'] ?? null, true);
        $json = $row['response_json'] ?? null;
        if ($bytes > self::RESPONSE_MAX_BYTES
            || !is_string($json)
            || strlen($json) !== $bytes) {
            throw new \RuntimeException('The stored turn response exceeds its safe bounds.');
        }
        return $json;
    }

    private function turnSelectColumns(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        return $prefix . 'id,'
            . $prefix . 'conversation_id,'
            . $prefix . 'client_turn_id,'
            . $prefix . 'request_hash,'
            . $prefix . 'status,'
            . $prefix . 'claim_version,'
            . $prefix . 'lease_seconds,'
            . 'CASE WHEN OCTET_LENGTH(COALESCE(' . $prefix . "response_json, '')) <= " . self::RESPONSE_MAX_BYTES
            . ' THEN COALESCE(' . $prefix . "response_json, '') ELSE NULL END AS response_json,"
            . 'OCTET_LENGTH(COALESCE(' . $prefix . "response_json, '')) AS response_bytes,"
            . $prefix . 'error_code,'
            . $prefix . 'created_at,'
            . $prefix . 'updated_at';
    }

    private function databaseDateToAtom(string $value): string
    {
        return $this->databaseDate($value)->format(DATE_ATOM);
    }

    private function databaseDate(string $value): \DateTimeImmutable
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            throw new \RuntimeException('The stored turn timestamp is invalid.');
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \RuntimeException('The stored turn timestamp is invalid.');
        }
        return $date;
    }

    private function assertTurnKey(string $conversationId, string $clientTurnId): void
    {
        if (!Uuid::isValid($conversationId)) {
            throw new \InvalidArgumentException('The conversation identifier is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientTurnId) !== 1) {
            throw new \InvalidArgumentException('The client turn identifier is invalid.');
        }
    }

    private function assertRequestHash(string $requestHash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $requestHash) !== 1) {
            throw new \InvalidArgumentException('The turn request hash is invalid.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function validatedTurnRow(array $row): array
    {
        $id = $this->databaseInteger($row['id'] ?? null);
        $claimVersion = $this->databaseInteger($row['claim_version'] ?? null);
        $leaseSeconds = $this->databaseInteger($row['lease_seconds'] ?? null);
        $conversationId = $row['conversation_id'] ?? null;
        $clientTurnId = $row['client_turn_id'] ?? null;
        $requestHash = $row['request_hash'] ?? null;
        $status = $row['status'] ?? null;
        $errorCode = $row['error_code'] ?? null;

        if (!is_string($conversationId)
            || !is_string($clientTurnId)
            || !is_string($requestHash)
            || !is_string($status)) {
            throw new \RuntimeException('The stored turn identity is invalid.');
        }
        $this->assertTurnKey($conversationId, $clientTurnId);
        $this->assertRequestHash($requestHash);
        if (!in_array($status, array('processing', 'completed', 'failed'), true)) {
            throw new \RuntimeException('The stored turn status is invalid.');
        }
        if ($errorCode !== null && !is_string($errorCode)) {
            throw new \RuntimeException('The stored turn error code is invalid.');
        }
        $errorCode = (string) ($errorCode ?? '');
        if (($status !== 'failed' && $errorCode !== '')
            || ($status === 'failed' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $errorCode) !== 1)) {
            throw new \RuntimeException('The stored turn error state is invalid.');
        }
        $response = $this->responseFromRow($row);
        if ($status !== 'processing' && $response === array()) {
            throw new \RuntimeException('A finalized turn is missing its durable response.');
        }
        $this->databaseDateToAtom(is_string($row['created_at'] ?? null) ? $row['created_at'] : '');
        $this->databaseDateToAtom(is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '');

        $row['id'] = $id;
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw new \RuntimeException('The stored turn lease duration is invalid.');
        }
        $row['claim_version'] = $claimVersion;
        $row['lease_seconds'] = $leaseSeconds;
        $row['error_code'] = $errorCode;
        return $row;
    }

    private function databaseInteger(mixed $value, bool $allowZero = false): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($filtered)) {
                throw new \RuntimeException('A stored turn integer is outside the supported range.');
            }
            $integer = $filtered;
        } else {
            throw new \RuntimeException('A stored turn integer is invalid.');
        }
        if ($integer < ($allowZero ? 0 : 1)) {
            throw new \RuntimeException('A stored turn integer is invalid.');
        }
        return $integer;
    }

    private function lockActiveConversation(string $conversationId): void
    {
        global $wpdb;
        $table = Schema::conversations();
        $now = $this->date($this->clock->now());
        $locked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE id = %s AND lifecycle_state = 'active' AND expires_at > %s
             LIMIT 1 FOR UPDATE",
            $conversationId,
            $now
        ));
        if ($locked === null) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to lock the conversation before claiming its turn.');
            }
            throw new ConversationUnavailable('The conversation is missing, expired, or no longer active.');
        }
        if (!is_string($locked) || !hash_equals($conversationId, $locked)) {
            throw new \RuntimeException('The conversation turn lock returned an invalid identity.');
        }
    }

    private function exactTurnExists(string $conversationId, string $clientTurnId): bool
    {
        global $wpdb;
        $table = Schema::turns();
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE conversation_id = %s AND client_turn_id = %s LIMIT 1",
            $conversationId,
            $clientTurnId
        ));
        if ($value === null) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to inspect the exact conversation turn.');
            }
            return false;
        }
        try {
            $this->databaseInteger($value);
        } catch (\RuntimeException $error) {
            throw new \RuntimeException('The exact conversation turn has an invalid identifier.', 0, $error);
        }
        return true;
    }

    private function assertNoOtherUnresolvedTurn(string $conversationId, string $clientTurnId): void
    {
        global $wpdb;
        $table = Schema::turns();
        $messages = Schema::messages();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.client_turn_id,t.status FROM {$table} t
             WHERE t.conversation_id = %s
               AND t.client_turn_id <> %s
               AND (
                    t.status = 'processing'
                    OR (
                        t.status = 'completed'
                        AND NOT EXISTS (
                            SELECT 1 FROM {$messages} am
                            WHERE am.turn_id = t.id AND am.role = 'assistant'
                        )
                    )
                    OR (
                        t.status = 'failed'
                        AND EXISTS (
                            SELECT 1 FROM {$messages} um
                            WHERE um.turn_id = t.id AND um.role = 'user'
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM {$messages} fm
                            WHERE fm.turn_id = t.id AND fm.role = 'assistant'
                        )
                    )
               )
             ORDER BY t.id ASC LIMIT 1 FOR UPDATE",
            $conversationId,
            $clientTurnId
        ), ARRAY_A);
        if ((string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Unable to inspect unresolved conversation turns.');
        }
        if ($row === null) {
            return;
        }
        if (!is_array($row)
            || !is_string($row['client_turn_id'] ?? null)
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', (string) $row['client_turn_id']) !== 1
            || !in_array((string) ($row['status'] ?? ''), array('processing', 'completed', 'failed'), true)) {
            throw new \RuntimeException('An unresolved conversation turn has an invalid identity.');
        }
        throw new ConversationBusy('Another conversation turn must be recovered before new work can start.');
    }

    private function begin(): void
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Unable to start a turn-claim transaction.');
        }
    }

    private function commit(): void
    {
        global $wpdb;
        if ($wpdb->query('COMMIT') === false) {
            throw new \RuntimeException('Unable to commit a turn-claim transaction.');
        }
    }

    private function date(\DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
