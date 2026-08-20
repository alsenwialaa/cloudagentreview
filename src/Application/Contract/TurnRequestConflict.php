<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

/**
 * The same client turn identifier was presented with different canonical
 * request content. This is a durable idempotency conflict, not a transient
 * repository failure, and must never be inferred from exception wording.
 */
final class TurnRequestConflict extends \RuntimeException
{
}
