<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

/**
 * Raised when a conversation disappears or enters deletion during a request.
 */
final class ConversationUnavailable extends \RuntimeException
{
}
