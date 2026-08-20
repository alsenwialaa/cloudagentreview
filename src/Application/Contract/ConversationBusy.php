<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

/**
 * Raised when a destructive conversation operation would race active work.
 */
final class ConversationBusy extends \RuntimeException
{
}
