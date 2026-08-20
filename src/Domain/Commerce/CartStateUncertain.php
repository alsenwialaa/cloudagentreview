<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

/**
 * Signals that a cart write was attempted but the gateway could not prove
 * either the committed result or a complete rollback. Callers must stop the
 * turn and require the shopper to inspect a freshly loaded cart.
 */
final class CartStateUncertain extends \RuntimeException
{
}
