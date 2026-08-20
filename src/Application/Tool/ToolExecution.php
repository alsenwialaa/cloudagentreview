<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

final readonly class ToolExecution
{
    /** @param array<string,mixed> $result @param array<string,mixed>|null $terminal */
    public function __construct(
        public array $result,
        public ?array $terminal = null
    ) {
    }
}
