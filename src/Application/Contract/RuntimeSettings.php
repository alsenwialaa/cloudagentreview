<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

interface RuntimeSettings
{
    public function get(string $key, mixed $default = null): mixed;

    /** @return array<string,mixed> */
    public function all(): array;

    public function apiKey(): string;
}
