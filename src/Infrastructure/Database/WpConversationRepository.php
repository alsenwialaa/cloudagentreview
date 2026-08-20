<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Application\Contract\Clock;
use YassinStore\AiAssistant\Application\Contract\ConversationBusy;
use YassinStore\AiAssistant\Application\Contract\ConversationRepository;
use YassinStore\AiAssistant\Application\Contract\TurnLeaseLost;
use YassinStore\AiAssistant\Domain\Conversation\ConversationCredentials;
use YassinStore\AiAssistant\Domain\Shared\Uuid;
use YassinStore\AiAssistant\Infrastructure\Security\TokenHasher;

final class WpConversationRepository implements ConversationRepository
{
    private const PURGE_MAX_PER_RUN = 5000;
    private const DELETE_MAX_PROCESSING_TURNS = 10000;
    private const EXPORT_PAGE_SIZE = 200;
    private const EXPORT_MAX_MESSAGES = 5000;
    private const EXPORT_MAX_SOURCE_BYTES = 4194304; // 4 MiB before JSON encoding.
    private const MESSAGE_CONTENT_MAX_BYTES = 32768;
    private const MESSAGE_PAYLOAD_MAX_BYTES = 2097152; // 2 MiB.
    private const HISTORY_MAX_SOURCE_BYTES = 8388608; // 8 MiB across the selected history window.
    private const MEMORY_MAX_BYTES = 65536; // 64 KiB.

    public function __construct(
        private readonly Clock $clock,
        private readonly TokenHasher $hasher
    ) {
    }

    public function create(int $retentionDays): array
    {
        $this->assertRetentionDays($retentionDays);
        global $wpdb;
        $credentials = ConversationCredentials::issue();
        $now = $this->clock->now();
        $expires = $now->modify('+' . $retentionDays . ' days');
        $inserted = $wpdb->insert(
            Schema::conversations(),
            array(
                'id' => $credentials->id,
                'token_hash' => $this->hasher->hash($credentials->token),
                'lifecycle_state' => 'active',
                'memory_json' => '{}',
                'created_at' => $this->date($now),
                'session_started_at' => $this->date($now),
                'last_activity_at' => $this->date($now),
                'expires_at' => $this->date($expires),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        if ($inserted !== 1) {
            throw new \RuntimeException('Unable to create a conversation.');
        }

        return array('credentials' => $credentials, 'expires_at' => $expires->format(DATE_ATOM));
    }

    public function authenticate(string $conversationId, string $token): ?array
    {
        $this->assertConversationId($conversationId);
        $this->assertToken($token);
        global $wpdb;
        $table = Schema::conversations();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,token_hash,lifecycle_state,created_at,session_started_at,last_activity_at,expires_at
                 FROM {$table} WHERE id = %s AND lifecycle_state = 'active' LIMIT 1",
                $conversationId
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to authenticate the conversation.');
            }
            return null;
        }
        $storedId = $row['id'] ?? null;
        $expected = $row['token_hash'] ?? null;
        $lifecycleState = $row['lifecycle_state'] ?? null;
        if (!is_string($storedId)
            || !hash_equals($conversationId, $storedId)
            || !is_string($expected)
            || $lifecycleState !== 'active'
            || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || !hash_equals($expected, $this->hasher->hash($token))) {
            return null;
        }
        try {
            $created = $this->databaseDate((string) ($row['created_at'] ?? ''));
            $sessionStarted = $this->databaseDate((string) ($row['session_started_at'] ?? ''));
            $lastActivity = $this->databaseDate((string) ($row['last_activity_at'] ?? ''));
            $expires = $this->databaseDate((string) ($row['expires_at'] ?? ''));
        } catch (\RuntimeException) {
            return null;
        }
        if ($expires <= $this->clock->now()
            || $sessionStarted < $created
            || $lastActivity < $created
            || $expires < $created) {
            return null;
        }
        return array(
            'id' => $conversationId,
            'created_at' => $created->format(DATE_ATOM),
            'session_started_at' => $sessionStarted->format(DATE_ATOM),
            'last_activity_at' => $lastActivity->format(DATE_ATOM),
            'expires_at' => $expires->format(DATE_ATOM),
        );
    }

    public function messages(string $conversationId, int $limit = 80, ?int $beforeTurnId = null): array
    {
        $this->assertConversationId($conversationId);
        if ($beforeTurnId !== null && $beforeTurnId <= 0) {
            throw new \InvalidArgumentException('The message boundary must be positive.');
        }
        global $wpdb;
        $table = Schema::messages();
        $limit = max(1, min(200, $limit));
        $boundary = $beforeTurnId === null ? '' : ' AND turn_id < %d';
        $publicRoles = " AND role IN ('user','assistant')";
        $arguments = array($conversationId);
        if ($beforeTurnId !== null) {
            $arguments[] = $beforeTurnId;
        }
        $arguments[] = $limit;

        // Select a stable newest suffix using only bounded metadata first. A row
        // count alone is not a safe memory boundary because one persisted tool
        // payload may be up to two MiB. The second query is pinned to the exact
        // selected identifiers so concurrent inserts cannot expand the window.
        $metadataSql = $wpdb->prepare(
            "SELECT id,turn_id,OCTET_LENGTH(content) AS content_bytes,
                    OCTET_LENGTH(COALESCE(payload_json, '{}')) AS payload_bytes
             FROM {$table}
             WHERE conversation_id = %s{$boundary}{$publicRoles}
             ORDER BY id DESC LIMIT %d",
            ...$arguments
        );
        $metadata = $wpdb->get_results($metadataSql, ARRAY_A);
        if (!is_array($metadata) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Unable to establish a bounded conversation history window.');
        }
        if ($metadata === array()) {
            return array();
        }

        $selectedIds = array();
        $selectedMetadata = array();
        $sourceBytes = 0;
        $previousId = null;
        foreach ($metadata as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('The conversation history metadata is invalid.');
            }
            try {
                $id = $this->databaseInteger($row['id'] ?? null);
                $turnId = $this->databaseInteger($row['turn_id'] ?? null);
                $contentBytes = $this->databaseInteger($row['content_bytes'] ?? null, true);
                $payloadBytes = $this->databaseInteger($row['payload_bytes'] ?? null, true);
            } catch (\RuntimeException) {
                throw new \RuntimeException('The conversation history metadata is invalid.');
            }
            if (($previousId !== null && $id >= $previousId)
                || $turnId <= 0
                || $contentBytes < 1
                || $contentBytes > self::MESSAGE_CONTENT_MAX_BYTES
                || $payloadBytes > self::MESSAGE_PAYLOAD_MAX_BYTES) {
                throw new \RuntimeException('The conversation history metadata is invalid or exceeds its safe bounds.');
            }
            $rowBytes = $contentBytes + $payloadBytes;
            if ($selectedIds !== array()
                && $rowBytes > self::HISTORY_MAX_SOURCE_BYTES - $sourceBytes) {
                break;
            }
            $selectedIds[] = $id;
            $selectedMetadata[] = array(
                'id' => $id,
                'turn_id' => $turnId,
                'content_bytes' => $contentBytes,
                'payload_bytes' => $payloadBytes,
            );
            $sourceBytes += $rowBytes;
            $previousId = $id;
        }
        if ($selectedIds === array()) {
            throw new \RuntimeException('Unable to select a safe conversation history window.');
        }

        $upperId = $selectedIds[0];
        $lowerId = $selectedIds[array_key_last($selectedIds)];
        $columns = $this->messageSelectColumns();
        $fullArguments = array($conversationId);
        if ($beforeTurnId !== null) {
            $fullArguments[] = $beforeTurnId;
        }
        $fullArguments[] = $lowerId;
        $fullArguments[] = $upperId;
        $fullArguments[] = count($selectedIds);
        $rowsSql = $wpdb->prepare(
            "SELECT {$columns} FROM {$table}
             WHERE conversation_id = %s{$boundary}{$publicRoles} AND id >= %d AND id <= %d
             ORDER BY id DESC LIMIT %d",
            ...$fullArguments
        );
        $rows = $wpdb->get_results($rowsSql, ARRAY_A);
        if (!is_array($rows)
            || (string) ($wpdb->last_error ?? '') !== ''
            || count($rows) !== count($selectedIds)) {
            throw new \RuntimeException('The bounded conversation history changed while it was being read.');
        }

        foreach ($rows as $index => $row) {
            if (!is_array($row) || !isset($selectedMetadata[$index])) {
                throw new \RuntimeException('The bounded conversation history changed while it was being read.');
            }
            try {
                $actual = array(
                    'id' => $this->databaseInteger($row['id'] ?? null),
                    'turn_id' => $this->databaseInteger($row['turn_id'] ?? null),
                    'content_bytes' => $this->databaseInteger($row['content_bytes'] ?? null, true),
                    'payload_bytes' => $this->databaseInteger($row['payload_bytes'] ?? null, true),
                );
            } catch (\RuntimeException) {
                throw new \RuntimeException('The bounded conversation history changed while it was being read.');
            }
            if ($actual !== $selectedMetadata[$index]) {
                throw new \RuntimeException('The bounded conversation history changed while it was being read.');
            }
        }

        $hydrated = array_map(array($this, 'hydrateMessage'), $rows);
        return array_reverse($hydrated);
    }

    public function message(string $conversationId, int $messageId): ?array
    {
        $this->assertConversationId($conversationId);
        global $wpdb;
        if ($messageId <= 0) {
            return null;
        }
        $table = Schema::messages();
        $columns = $this->messageSelectColumns();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$columns} FROM {$table} WHERE conversation_id = %s AND id = %d LIMIT 1",
                $conversationId,
                $messageId
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to read the referenced conversation message.');
            }
            return null;
        }
        return $this->hydrateMessage($row);
    }

    public function messageForTurn(string $conversationId, int $turnId, string $role): ?array
    {
        $this->assertConversationId($conversationId);
        if ($turnId <= 0 || !in_array($role, array('user', 'assistant', 'system'), true)) {
            throw new \InvalidArgumentException('The turn-message lookup boundary is invalid.');
        }

        global $wpdb;
        $table = Schema::messages();
        $columns = $this->messageSelectColumns();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT {$columns} FROM {$table}
                 WHERE conversation_id = %s AND turn_id = %d AND role = %s LIMIT 1",
                $conversationId,
                $turnId,
                $role
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to read the turn conversation message.');
            }
            return null;
        }
        return $this->hydrateMessage($row);
    }

    public function appendMessage(string $conversationId, int $turnId, string $role, string $content, array $payload = array()): int
    {
        $this->assertConversationId($conversationId);
        if ($turnId <= 0) {
            throw new \InvalidArgumentException('The message turn identifier must be positive.');
        }
        global $wpdb;
        if (!in_array($role, array('user', 'assistant', 'system'), true)) {
            throw new \InvalidArgumentException('Invalid message role.');
        }
        if ($content === ''
            || strlen($content) > self::MESSAGE_CONTENT_MAX_BYTES
            || preg_match('//u', $content) !== 1) {
            throw new \LengthException('The conversation message content is invalid or exceeds its byte limit.');
        }
        $json = BoundedJson::encode(
            $payload,
            self::MESSAGE_PAYLOAD_MAX_BYTES,
            'Unable to encode the conversation message.'
        );
        $table = Schema::messages();
        $conversations = Schema::conversations();
        $turns = Schema::turns();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$table}
                (conversation_id,turn_id,role,content,payload_json,created_at)
                SELECT c.id,t.id,%s,%s,%s,%s
                FROM {$conversations} c
                INNER JOIN {$turns} t ON t.conversation_id = c.id
                WHERE c.id = %s AND c.lifecycle_state = 'active' AND t.id = %d",
                $role,
                $content,
                $json,
                $this->date($this->clock->now()),
                $conversationId,
                $turnId
            )
        );
        if ($inserted === false) {
            throw new \RuntimeException('Unable to store the conversation message.');
        }
        if ($inserted === 1) {
            return (int) $wpdb->insert_id;
        }
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT m.id,m.conversation_id,
                    CASE WHEN OCTET_LENGTH(m.content) <= %d THEN m.content ELSE NULL END AS content,
                    OCTET_LENGTH(m.content) AS content_bytes,
                    CASE WHEN OCTET_LENGTH(COALESCE(m.payload_json, '{}')) <= %d
                         THEN COALESCE(m.payload_json, '{}') ELSE NULL END AS payload_json,
                    OCTET_LENGTH(COALESCE(m.payload_json, '{}')) AS payload_bytes
             FROM {$table} m INNER JOIN {$conversations} c ON c.id = m.conversation_id
             WHERE m.turn_id = %d AND m.role = %s LIMIT 1",
            self::MESSAGE_CONTENT_MAX_BYTES,
            self::MESSAGE_PAYLOAD_MAX_BYTES,
            $turnId,
            $role
        ), ARRAY_A);
        if (!is_array($existing)) {
            throw new \RuntimeException('The idempotent conversation message conflicts with stored data.');
        }
        try {
            $existingId = $this->databaseInteger($existing['id'] ?? null);
            $contentBytes = $this->databaseInteger($existing['content_bytes'] ?? null, true);
            $payloadBytes = $this->databaseInteger($existing['payload_bytes'] ?? null, true);
        } catch (\RuntimeException) {
            throw new \RuntimeException('The idempotent conversation message conflicts with stored data.');
        }
        if ($contentBytes > self::MESSAGE_CONTENT_MAX_BYTES
            || $payloadBytes > self::MESSAGE_PAYLOAD_MAX_BYTES
            || !hash_equals($conversationId, (string) ($existing['conversation_id'] ?? ''))
            || !hash_equals($content, (string) ($existing['content'] ?? ''))
            || !hash_equals($json, (string) ($existing['payload_json'] ?? ''))) {
            throw new \RuntimeException('The idempotent conversation message conflicts with stored data.');
        }
        return $existingId;
    }

    public function appendUserMessageForTurn(
        string $conversationId,
        int $turnId,
        int $claimVersion,
        string $content,
        array $payload,
        int $retentionDays
    ): int {
        $this->assertConversationId($conversationId);
        $this->assertRetentionDays($retentionDays);
        if ($turnId <= 0 || $claimVersion <= 0) {
            throw new \InvalidArgumentException('The accepted user-message claim is invalid.');
        }
        if ($content === ''
            || strlen($content) > self::MESSAGE_CONTENT_MAX_BYTES
            || preg_match('//u', $content) !== 1) {
            throw new \LengthException('The conversation message content is invalid or exceeds its byte limit.');
        }
        // Validate the payload before opening a transaction so malformed input
        // never acquires lifecycle or turn locks.
        BoundedJson::encode(
            $payload,
            self::MESSAGE_PAYLOAD_MAX_BYTES,
            'Unable to encode the conversation message.'
        );

        global $wpdb;
        $conversations = Schema::conversations();
        $turns = Schema::turns();
        $nowValue = $this->clock->now();
        $now = $this->date($nowValue);
        $expires = $this->date($nowValue->modify('+' . $retentionDays . ' days'));

        $this->begin();
        try {
            // Match the lock order used by turn claiming and deletion:
            // conversation capability first, then the exact turn row.
            $lockedConversation = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$conversations}
                 WHERE id = %s AND lifecycle_state = 'active'
                 LIMIT 1 FOR UPDATE",
                $conversationId
            ));
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to lock the accepted conversation.');
            }
            if (!is_string($lockedConversation) || !hash_equals($conversationId, $lockedConversation)) {
                throw new TurnLeaseLost('The conversation is no longer active for this turn.');
            }

            $ownedTurn = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$turns}
                 WHERE id = %d
                   AND conversation_id = %s
                   AND claim_version = %d
                   AND status = 'processing'
                   AND (response_json IS NULL OR response_json = '')
                   AND lease_seconds BETWEEN 30 AND 3600
                   AND TIMESTAMPADD(SECOND, lease_seconds, updated_at) > %s
                 LIMIT 1 FOR UPDATE",
                $turnId,
                $conversationId,
                $claimVersion,
                $now
            ));
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to verify the accepted turn lease.');
            }
            if ($this->databaseIntegerOrNull($ownedTurn) !== $turnId) {
                throw new TurnLeaseLost('The processing turn no longer owns the user-message write.');
            }

            $messageId = $this->appendMessage(
                $conversationId,
                $turnId,
                'user',
                $content,
                $payload
            );
            if ($messageId <= 0) {
                throw new \RuntimeException('The accepted user message has no durable identifier.');
            }

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$conversations}
                 SET last_activity_at = %s, expires_at = %s
                 WHERE id = %s AND lifecycle_state = 'active'",
                $now,
                $expires,
                $conversationId
            ));
            if ($updated === false) {
                throw new \RuntimeException('Unable to extend the accepted conversation activity.');
            }

            $this->commit();
            return $messageId;
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public function touch(string $conversationId, int $retentionDays): void
    {
        $this->assertConversationId($conversationId);
        $this->assertRetentionDays($retentionDays);
        global $wpdb;
        $now = $this->clock->now();
        $updated = $wpdb->update(
            Schema::conversations(),
            array(
                'last_activity_at' => $this->date($now),
                'expires_at' => $this->date($now->modify('+' . $retentionDays . ' days')),
            ),
            array('id' => $conversationId, 'lifecycle_state' => 'active'),
            array('%s', '%s'),
            array('%s', '%s')
        );
        if ($updated === false) {
            throw new \RuntimeException('Unable to update conversation activity.');
        }
        if ($updated === 0) {
            $this->assertActiveConversationExists($conversationId, 'Unable to update conversation activity.');
        }
    }

    public function memory(string $conversationId): array
    {
        $this->assertConversationId($conversationId);
        global $wpdb;
        $table = Schema::conversations();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT CASE WHEN OCTET_LENGTH(COALESCE(memory_json, '{}')) <= %d
                             THEN COALESCE(memory_json, '{}') ELSE NULL END AS memory_json,
                        OCTET_LENGTH(COALESCE(memory_json, '{}')) AS memory_bytes
                 FROM {$table} WHERE id = %s AND lifecycle_state = 'active' LIMIT 1",
                self::MEMORY_MAX_BYTES,
                $conversationId
            ),
            ARRAY_A
        );
        if (!is_array($row) || !is_string($row['memory_json'] ?? null)) {
            throw new \RuntimeException('Unable to read shopping memory.');
        }
        try {
            $memoryBytes = $this->databaseInteger($row['memory_bytes'] ?? null, true);
        } catch (\RuntimeException) {
            throw new \RuntimeException('Unable to read shopping memory.');
        }
        if ($memoryBytes > self::MEMORY_MAX_BYTES
            || strlen((string) $row['memory_json']) !== $memoryBytes) {
            throw new \RuntimeException('Unable to read shopping memory.');
        }
        return BoundedJson::decode(
            (string) $row['memory_json'],
            self::MEMORY_MAX_BYTES,
            'Stored shopping memory is invalid.'
        );
    }

    public function updateMemoryForTurn(
        string $conversationId,
        int $turnId,
        int $claimVersion,
        array $memory
    ): void
    {
        $this->assertConversationId($conversationId);
        if ($turnId <= 0 || $claimVersion <= 0) {
            throw new \InvalidArgumentException('The guarded shopping-memory turn boundary is invalid.');
        }
        global $wpdb;
        $json = BoundedJson::encode(
            $memory,
            self::MEMORY_MAX_BYTES,
            'Unable to encode shopping memory.'
        );
        $conversations = Schema::conversations();
        $turns = Schema::turns();
        $now = $this->date($this->clock->now());
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$conversations} c
                 INNER JOIN {$turns} t ON t.conversation_id = c.id
                 SET c.memory_json = %s
                 WHERE c.id = %s
                   AND c.lifecycle_state = 'active'
                   AND c.expires_at > %s
                   AND t.id = %d
                   AND t.status = 'processing'
                   AND t.claim_version = %d
                   AND TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at) > %s",
                $json,
                $conversationId,
                $now,
                $turnId,
                $claimVersion,
                $now
            )
        );
        if ($updated === false) {
            throw new \RuntimeException('Unable to update shopping memory.');
        }
        if ($updated === 1) {
            return;
        }

        // MySQL reports zero affected rows when the exact JSON value was
        // already stored. Prove that the same fresh claim still owns the write
        // before treating that no-op as success. This second read cannot grant
        // authority to another generation because it includes the original
        // claim version and live lease predicates.
        $stored = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT c.memory_json
                 FROM {$conversations} c
                 INNER JOIN {$turns} t ON t.conversation_id = c.id
                 WHERE c.id = %s
                   AND c.lifecycle_state = 'active'
                   AND c.expires_at > %s
                   AND t.id = %d
                   AND t.status = 'processing'
                   AND t.claim_version = %d
                   AND TIMESTAMPADD(SECOND, t.lease_seconds, t.updated_at) > %s
                 LIMIT 1",
                $conversationId,
                $now,
                $turnId,
                $claimVersion,
                $now
            )
        );
        if ((string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Unable to verify the guarded shopping-memory write.');
        }
        if (is_string($stored) && hash_equals($json, $stored)) {
            return;
        }
        throw new TurnLeaseLost('The processing turn no longer owns the shopping-memory write.');
    }

    public function exportPage(
        string $conversationId,
        int $afterMessageId = 0,
        int $upperMessageId = 0,
        int $limit = 200
    ): array {
        $this->assertConversationId($conversationId);
        global $wpdb;
        $table = Schema::messages();
        if ($afterMessageId < 0
            || $upperMessageId < 0
            || ($afterMessageId === 0 && $upperMessageId !== 0)
            || ($afterMessageId !== 0 && $upperMessageId === 0)
            || ($upperMessageId > 0 && $afterMessageId > $upperMessageId)) {
            throw new \InvalidArgumentException('The export cursor is invalid.');
        }
        $limit = max(1, min(self::EXPORT_PAGE_SIZE, $limit));

        $currentUpperRaw = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(id) FROM {$table} WHERE conversation_id = %s AND role IN ('user','assistant')",
            $conversationId
        ));
        if ((string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Unable to establish a stable conversation export boundary.');
        }
        if ($currentUpperRaw === null) {
            $currentUpper = 0;
        } else {
            try {
                $currentUpper = $this->databaseInteger($currentUpperRaw, true);
            } catch (\RuntimeException) {
                throw new \RuntimeException('The conversation export boundary is invalid.');
            }
        }

        if ($upperMessageId <= 0) {
            $upperMessageId = $currentUpper;
        } elseif ($upperMessageId > $currentUpper) {
            throw new \InvalidArgumentException('The export boundary is beyond the current conversation.');
        }

        if ($afterMessageId > $upperMessageId && $upperMessageId > 0) {
            throw new \InvalidArgumentException('The export cursor is beyond the export boundary.');
        }

        $aggregate = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS message_count,
                        COALESCE(SUM(OCTET_LENGTH(content) + OCTET_LENGTH(COALESCE(payload_json, ''))), 0) AS source_bytes
                 FROM {$table}
                 WHERE conversation_id = %s AND role IN ('user','assistant') AND id <= %d",
                $conversationId,
                $upperMessageId
            ),
            ARRAY_A
        );
        if (!is_array($aggregate) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Unable to verify conversation export bounds.');
        }
        try {
            $messageCount = $this->databaseInteger($aggregate['message_count'] ?? null, true);
            $sourceBytes = $this->databaseInteger($aggregate['source_bytes'] ?? null, true);
        } catch (\RuntimeException) {
            throw new \RuntimeException('Conversation export bounds are invalid.');
        }
        if ($messageCount > self::EXPORT_MAX_MESSAGES || $sourceBytes > self::EXPORT_MAX_SOURCE_BYTES) {
            throw new \LengthException('The conversation exceeds the safe export limit.');
        }

        $rows = array();
        if ($upperMessageId > 0 && $afterMessageId < $upperMessageId) {
            $columns = $this->messageSelectColumns();
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$columns} FROM {$table}
                    WHERE conversation_id = %s AND role IN ('user','assistant')
                      AND id > %d AND id <= %d
                    ORDER BY id ASC LIMIT %d",
                    $conversationId,
                    $afterMessageId,
                    $upperMessageId,
                    $limit + 1
                ),
                ARRAY_A
            );
            if (!is_array($rows)) {
                throw new \RuntimeException('Unable to export conversation messages.');
            }
        }

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $messages = array_map(array($this, 'hydrateMessage'), $rows);
        $last = $messages === array() ? $afterMessageId : (int) ($messages[array_key_last($messages)]['id'] ?? $afterMessageId);

        return array(
            'conversation_id' => $conversationId,
            'exported_at' => $this->clock->now()->format(DATE_ATOM),
            'upper_message_id' => $upperMessageId,
            'next_after_message_id' => $hasMore ? $last : null,
            'complete' => !$hasMore,
            'message_count' => $messageCount,
            'messages' => $messages,
            'memory' => $this->memory($conversationId),
        );
    }

    public function delete(string $conversationId): void
    {
        $this->assertConversationId($conversationId);
        global $wpdb;
        $conversationTable = Schema::conversations();
        $turnTable = Schema::turns();

        $this->begin();
        try {
            // Enter a database-backed deletion state first. New turn claims and
            // message writes require an active conversation, so they block on
            // this row and then fail closed if deletion commits.
            $marked = $wpdb->update(
                $conversationTable,
                array('lifecycle_state' => 'deleting'),
                array('id' => $conversationId, 'lifecycle_state' => 'active'),
                array('%s'),
                array('%s', '%s')
            );
            if ($marked === false) {
                throw new \RuntimeException('Unable to begin conversation deletion.');
            }
            if ($marked === 0) {
                $state = $wpdb->get_var($wpdb->prepare(
                    "SELECT lifecycle_state FROM {$conversationTable} WHERE id = %s LIMIT 1 FOR UPDATE",
                    $conversationId
                ));
                if ((string) ($wpdb->last_error ?? '') !== '') {
                    throw new \RuntimeException('Unable to verify conversation deletion state.');
                }
                if ($state === null) {
                    $this->commit();
                    return;
                }
                throw new ConversationBusy('The conversation is already being deleted.');
            }

            $this->assertNoFreshProcessingTurns($conversationId);

            $this->assertQuery(
                $wpdb->delete(Schema::messages(), array('conversation_id' => $conversationId), array('%s')),
                'Unable to delete conversation messages.'
            );
            $this->assertQuery(
                $wpdb->delete($turnTable, array('conversation_id' => $conversationId), array('%s')),
                'Unable to delete conversation turns.'
            );
            $deleted = $wpdb->delete(
                $conversationTable,
                array('id' => $conversationId, 'lifecycle_state' => 'deleting'),
                array('%s', '%s')
            );
            if ($deleted !== 1) {
                throw new \RuntimeException('Unable to delete the conversation authorization record.');
            }
            $this->commit();
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public function stats(): array
    {
        global $wpdb;
        return array(
            'conversations' => $this->count(Schema::conversations()),
            'messages' => $this->count(Schema::messages()),
            'turns' => $this->count(Schema::turns()),
        );
    }

    public function deleteAll(): void
    {
        global $wpdb;
        $conversationTable = Schema::conversations();
        $turnTable = Schema::turns();
        $this->begin();
        try {
            // Lock every active capability by transitioning it before checking
            // for work. Concurrent claims require active rows and therefore
            // cannot appear between the check and deletion.
            $this->assertQuery(
                $wpdb->query("UPDATE {$conversationTable} SET lifecycle_state = 'deleting' WHERE lifecycle_state = 'active'"),
                'Unable to begin deletion of all conversations.'
            );
            $invalidState = $wpdb->get_var(
                "SELECT id FROM {$conversationTable} WHERE lifecycle_state <> 'deleting' LIMIT 1 FOR UPDATE"
            );
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException('Unable to verify conversation lifecycle state.');
            }
            if ($invalidState !== null) {
                throw new \RuntimeException('A conversation has an invalid lifecycle state.');
            }
            $this->assertNoFreshProcessingTurns();

            $this->assertQuery($wpdb->query('DELETE FROM ' . Schema::messages()), 'Unable to delete all messages.');
            $this->assertQuery($wpdb->query('DELETE FROM ' . $turnTable), 'Unable to delete all turns.');
            $this->assertQuery($wpdb->query('DELETE FROM ' . $conversationTable), 'Unable to delete all conversations.');
            $this->commit();
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public function purgeExpired(): int
    {
        global $wpdb;
        $conversationTable = Schema::conversations();
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$conversationTable}
                 WHERE lifecycle_state = 'active' AND expires_at < %s
                 ORDER BY expires_at ASC LIMIT %d",
                $this->date($this->clock->now()),
                self::PURGE_MAX_PER_RUN
            )
        );
        if (!is_array($ids)) {
            throw new \RuntimeException('Unable to identify expired conversations.');
        }

        $count = 0;
        foreach ($ids as $id) {
            try {
                $this->delete((string) $id);
                ++$count;
            } catch (ConversationBusy) {
                // A live turn retains its recovery capability. A later cleanup
                // pass deletes it after the turn reaches a terminal state.
            }
        }
        return $count;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateMessage(array $row): array
    {
        $id = $this->databaseInteger($row['id'] ?? null);
        $turnId = $this->databaseInteger($row['turn_id'] ?? null);
        $role = is_string($row['role'] ?? null) ? (string) $row['role'] : '';
        try {
            $contentBytes = $this->databaseInteger($row['content_bytes'] ?? null, true);
            $payloadBytes = $this->databaseInteger($row['payload_bytes'] ?? null, true);
        } catch (\RuntimeException) {
            throw new \RuntimeException('A stored conversation message is invalid or exceeds its safe bounds.');
        }
        $content = $row['content'] ?? null;
        $payloadJson = $row['payload_json'] ?? null;
        if ($id <= 0
            || $turnId <= 0
            || !in_array($role, array('user', 'assistant', 'system'), true)
            || $contentBytes < 0
            || $contentBytes > self::MESSAGE_CONTENT_MAX_BYTES
            || !is_string($content)
            || strlen($content) !== $contentBytes
            || preg_match('//u', $content) !== 1
            || $payloadBytes < 0
            || $payloadBytes > self::MESSAGE_PAYLOAD_MAX_BYTES
            || !is_string($payloadJson)
            || strlen($payloadJson) !== $payloadBytes) {
            throw new \RuntimeException('A stored conversation message is invalid or exceeds its safe bounds.');
        }
        $payload = BoundedJson::decode(
            $payloadJson,
            self::MESSAGE_PAYLOAD_MAX_BYTES,
            'A stored conversation message payload is invalid.'
        );
        return array(
            'id' => $id,
            'turn_id' => $turnId,
            'role' => $role,
            'content' => $content,
            'payload' => $payload,
            'created_at' => $this->databaseDateToAtom((string) ($row['created_at'] ?? '')),
        );
    }

    private function begin(): void
    {
        global $wpdb;
        $this->assertQuery($wpdb->query('START TRANSACTION'), 'Unable to start a database transaction.');
    }

    private function commit(): void
    {
        global $wpdb;
        $this->assertQuery($wpdb->query('COMMIT'), 'Unable to commit a database transaction.');
    }

    private function assertQuery(mixed $result, string $message): void
    {
        if ($result === false) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Lock and inspect processing turns before destructive deletion. A worker
     * that heartbeated within its persisted lease is still live and blocks the
     * operation. An abandoned worker is fenced by deleting its turn while the
     * owning conversation is already locked in the deleting state.
     */
    private function assertNoFreshProcessingTurns(?string $conversationId = null): void
    {
        global $wpdb;
        $table = Schema::turns();
        $limit = self::DELETE_MAX_PROCESSING_TURNS + 1;
        if ($conversationId === null) {
            $sql = "SELECT id,lease_seconds,updated_at FROM {$table}
                    WHERE status = 'processing' ORDER BY id ASC LIMIT {$limit} FOR UPDATE";
        } else {
            $sql = $wpdb->prepare(
                "SELECT id,lease_seconds,updated_at FROM {$table}
                 WHERE conversation_id = %s AND status = 'processing'
                 ORDER BY id ASC LIMIT %d FOR UPDATE",
                $conversationId,
                $limit
            );
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Unable to verify active conversation work.');
        }
        if (count($rows) > self::DELETE_MAX_PROCESSING_TURNS) {
            throw new \RuntimeException('Too many processing turns exist for a bounded deletion.');
        }

        $now = $this->clock->now();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('A processing turn has malformed lease data.');
            }
            try {
                $this->databaseInteger($row['id'] ?? null);
                $leaseSeconds = $this->databaseInteger($row['lease_seconds'] ?? null);
                $updatedAt = $this->databaseDate(is_string($row['updated_at'] ?? null) ? $row['updated_at'] : '');
            } catch (\RuntimeException) {
                throw new \RuntimeException('A processing turn has malformed lease data.');
            }
            if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
                throw new \RuntimeException('A processing turn has an invalid persisted lease.');
            }
            if ($updatedAt->getTimestamp() > $now->getTimestamp() - $leaseSeconds) {
                throw new ConversationBusy('At least one conversation turn still owns a live lease.');
            }
        }
    }

    private function assertActiveConversationExists(string $conversationId, string $message): void
    {
        global $wpdb;
        $table = Schema::conversations();
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %s AND lifecycle_state = 'active' LIMIT 1",
            $conversationId
        ));
        if ((string) ($wpdb->last_error ?? '') !== ''
            || !is_string($value)
            || !hash_equals($conversationId, $value)) {
            throw new \RuntimeException($message);
        }
    }

    private function count(string $table): int
    {
        global $wpdb;
        $value = $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
        try {
            return $this->databaseInteger($value, true);
        } catch (\RuntimeException) {
            throw new \RuntimeException('Unable to read assistant statistics.');
        }
    }

    private function messageSelectColumns(): string
    {
        return 'id,conversation_id,turn_id,role,'
            . 'CASE WHEN OCTET_LENGTH(content) <= ' . self::MESSAGE_CONTENT_MAX_BYTES
            . ' THEN content ELSE NULL END AS content,'
            . 'OCTET_LENGTH(content) AS content_bytes,'
            . "CASE WHEN OCTET_LENGTH(COALESCE(payload_json, '{}')) <= " . self::MESSAGE_PAYLOAD_MAX_BYTES
            . " THEN COALESCE(payload_json, '{}') ELSE NULL END AS payload_json,"
            . "OCTET_LENGTH(COALESCE(payload_json, '{}')) AS payload_bytes,created_at";
    }

    private function databaseDateToAtom(string $value): string
    {
        return $this->databaseDate($value)->format(DATE_ATOM);
    }

    private function databaseDate(string $value): \DateTimeImmutable
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            throw new \RuntimeException('A stored conversation timestamp is invalid.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \RuntimeException('A stored conversation timestamp is invalid.');
        }
        return $date;
    }

    private function databaseIntegerOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        try {
            return $this->databaseInteger($value);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function databaseInteger(mixed $value, bool $allowZero = false): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($filtered)) {
                throw new \RuntimeException('A stored conversation integer is outside the supported range.');
            }
            $integer = $filtered;
        } else {
            throw new \RuntimeException('A stored conversation integer is invalid.');
        }
        if ($integer < ($allowZero ? 0 : 1)) {
            throw new \RuntimeException('A stored conversation integer is invalid.');
        }
        return $integer;
    }

    private function assertConversationId(string $conversationId): void
    {
        if (!Uuid::isValid($conversationId)) {
            throw new \InvalidArgumentException('The conversation identifier is invalid.');
        }
    }

    private function assertToken(string $token): void
    {
        if (strlen($token) < 40 || strlen($token) > 100 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new \InvalidArgumentException('The conversation token is invalid.');
        }
    }

    private function assertRetentionDays(int $retentionDays): void
    {
        if ($retentionDays < 1 || $retentionDays > 365) {
            throw new \InvalidArgumentException('Conversation retention is outside the supported range.');
        }
    }

    private function date(\DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
