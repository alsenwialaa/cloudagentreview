<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

interface RateLimiter
{
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool;

    public function purge(): int;

    public function clear(): int;
}
