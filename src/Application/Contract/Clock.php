<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
