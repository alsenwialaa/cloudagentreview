<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

/**
 * Raised when a worker attempts to persist a turn after another worker has
 * reclaimed that turn's processing lease.
 */
final class TurnLeaseLost extends \RuntimeException
{
}
