<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Chat;

final readonly class IntentDecision
{
    public function __construct(
        public bool $authorized,
        public bool $requiresClarification,
        public string $reason
    ) {
    }
}
