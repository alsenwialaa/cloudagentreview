<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Chat;

use YassinStore\AiAssistant\Application\Contract\TurnGuard;
use YassinStore\AiAssistant\Application\Contract\TurnRepository;

final readonly class RepositoryTurnGuard implements TurnGuard
{
    public function __construct(
        private TurnRepository $turns,
        private int $turnId,
        private int $claimVersion
    ) {
        if ($this->turnId <= 0 || $this->claimVersion <= 0) {
            throw new \InvalidArgumentException('Turn guard identifiers must be positive.');
        }
    }

    public function heartbeat(): void
    {
        $this->turns->heartbeat($this->turnId, $this->claimVersion);
    }

    public function claim(): array
    {
        return array(
            'turn_id' => $this->turnId,
            'claim_version' => $this->claimVersion,
        );
    }
}
