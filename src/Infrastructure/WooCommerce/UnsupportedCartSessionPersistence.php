<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

final class UnsupportedCartSessionPersistence implements CartSessionPersistence
{
    public function __construct(
        private readonly string $reason = 'The active WooCommerce session handler has no verified durable cart-session adapter.'
    ) {
    }

    public function configurationStatus(): ?bool
    {
        return false;
    }

    public function read(array $keys): array
    {
        throw new \RuntimeException($this->reason);
    }

    public function persist(): void
    {
        throw new \RuntimeException($this->reason);
    }

    public function invalidateCache(): void
    {
    }
}
