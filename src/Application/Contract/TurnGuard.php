<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

/**
 * Proves that the current worker still owns a processing turn and renews its
 * lease. Implementations must throw TurnLeaseLost after ownership changes.
 */
interface TurnGuard
{
    public function heartbeat(): void;

    /** @return array{turn_id:int,claim_version:int} */
    public function claim(): array;
}
