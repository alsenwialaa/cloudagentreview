<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

use YassinStore\AiAssistant\Domain\Conversation\ConversationCredentials;

interface ConversationRepository
{
    /** @return array{credentials:ConversationCredentials,expires_at:string} */
    public function create(int $retentionDays): array;

    /** @return array<string,mixed>|null */
    public function authenticate(string $conversationId, string $token): ?array;

    /** @return list<array<string,mixed>> */
    public function messages(string $conversationId, int $limit = 80, ?int $beforeTurnId = null): array;

    /** @return array<string,mixed>|null */
    public function message(string $conversationId, int $messageId): ?array;

    /** @return array<string,mixed>|null */
    public function messageForTurn(string $conversationId, int $turnId, string $role): ?array;

    /** @param array<string,mixed> $payload */
    public function appendMessage(string $conversationId, int $turnId, string $role, string $content, array $payload = array()): int;

    /**
     * Accept the shopper's message only while the exact processing-turn
     * generation still owns a fresh lease, and extend conversation activity at
     * that same durable boundary. Implementations must fence stale workers and
     * throw TurnLeaseLost after ownership changes.
     *
     * @param array<string,mixed> $payload
     */
    public function appendUserMessageForTurn(
        string $conversationId,
        int $turnId,
        int $claimVersion,
        string $content,
        array $payload,
        int $retentionDays
    ): int;

    public function touch(string $conversationId, int $retentionDays): void;

    /** @return array<string,mixed> */
    public function memory(string $conversationId): array;

    /**
     * Persist shopping memory only while the exact processing-turn generation
     * still owns a fresh lease. Implementations must perform the ownership
     * check and memory write atomically and throw TurnLeaseLost when authority
     * has changed.
     *
     * @param array<string,mixed> $memory
     */
    public function updateMemoryForTurn(
        string $conversationId,
        int $turnId,
        int $claimVersion,
        array $memory
    ): void;

    /**
     * Export one stable, bounded page of messages. The first page fixes an
     * upper message identifier so messages appended during an export are not
     * mixed into that export.
     *
     * @return array{conversation_id:string,exported_at:string,upper_message_id:int,next_after_message_id:?int,complete:bool,message_count:int,messages:list<array<string,mixed>>,memory:array<string,mixed>}
     */
    public function exportPage(
        string $conversationId,
        int $afterMessageId = 0,
        int $upperMessageId = 0,
        int $limit = 200
    ): array;

    public function delete(string $conversationId): void;

    /** @return array<string,int> */
    public function stats(): array;

    public function deleteAll(): void;

    public function purgeExpired(): int;
}
