<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

/**
 * Signals that an earlier attempt for the same durable operation key failed,
 * but the gateway conclusively restored and re-read the authorized pre-state.
 * The operation is terminal and must not be executed again under that key.
 */
final class CartOperationRolledBack extends \RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('The cart operation failed previously and its rollback was durably verified.');
    }
}
