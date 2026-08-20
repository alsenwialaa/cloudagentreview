<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

interface TurnRepository
{
    /**
     * @return array{state:string,id:int,claim_version:int,response?:array<string,mixed>}
     */
    public function claim(string $conversationId, string $clientTurnId, string $requestHash, int $staleAfterSeconds): array;

    /**
     * Persist a recoverable response while the turn is still marked processing.
     * This checkpoint prevents a completed side effect from being executed twice.
     *
     * The claim version fences out a worker after another request reclaims the
     * turn. Implementations must throw TurnLeaseLost on an ownership mismatch.
     *
     * @param array<string,mixed> $response
     */
    public function checkpoint(int $turnId, int $claimVersion, array $response): void;

    /**
     * Renew a processing claim and prove that this worker still owns it.
     * Implementations must throw TurnLeaseLost when ownership has changed.
     */
    public function heartbeat(int $turnId, int $claimVersion): void;

    /**
     * Convert an abandoned processing turn with no checkpoint into one durable
     * failed result after its lease has expired. The claim version must be
     * incremented atomically so any late worker is fenced out.
     *
     * @param array<string,mixed> $response
     */
    public function expireStale(
        int $turnId,
        int $claimVersion,
        string $errorCode,
        array $response
    ): bool;

    /**
     * Atomically prove that an exact client turn is absent and seal that
     * identity as a durable pre-acceptance rejection while holding the same
     * conversation lock used by claim(). If a concurrent claim won first,
     * return that existing turn instead. This closes the read-then-claim race
     * where recovery could report "not found" immediately before delayed
     * work started under the same idempotency key.
     *
     * @param array<string,mixed> $response
     * @return array{id:int,claim_version:int,status:string,response:array<string,mixed>,error_code:string,updated_at:string}
     */
    public function sealMissingAsRejected(
        string $conversationId,
        string $clientTurnId,
        string $errorCode,
        array $response,
        int $leaseSeconds
    ): array;

    /**
     * Find one different unresolved turn that can be reconciled without
     * revoking live work. This includes checkpointed processing turns, expired
     * uncheckpointed turns, and terminal turns whose assistant presentation is
     * not yet durable. Any fresh uncheckpointed worker must produce null.
     *
     * @return array{status:string,id:int,client_turn_id:string,claim_version:int,response:array<string,mixed>}|null
     */
    public function blockingRecoveryCandidate(string $conversationId, string $excludingClientTurnId): ?array;

    /** @param array<string,mixed> $response */
    public function complete(int $turnId, int $claimVersion, array $response): void;

    /**
     * Attach the persisted assistant message to an already terminal response.
     * Both completed and accepted failed turns use the same presentation
     * identity. Implementations must reject any attempt to change another
     * response field or replace a different message identifier.
     */
    public function attachTerminalMessageId(int $turnId, int $messageId): void;

    /** @param array<string,mixed> $response */
    public function fail(int $turnId, int $claimVersion, string $errorCode, array $response): void;

    /** @return array<string,mixed>|null */
    public function find(string $conversationId, string $clientTurnId): ?array;
}
