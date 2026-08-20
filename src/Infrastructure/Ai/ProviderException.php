<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Ai;

final class ProviderException extends \RuntimeException
{
    public const RETRY_NONE = 'none';
    public const RETRY_SAME_TURN = 'same_turn';
    public const RETRY_NEW_TURN = 'new_turn';

    public function __construct(
        string $message,
        public readonly string $publicCode = 'provider_error',
        public readonly int $httpStatus = 502,
        public readonly string $retryMode = self::RETRY_NONE,
        public readonly ?int $retryAfterSeconds = null
    ) {
        if (!in_array($retryMode, array(self::RETRY_NONE, self::RETRY_SAME_TURN, self::RETRY_NEW_TURN), true)) {
            throw new \InvalidArgumentException('The provider retry mode is invalid.');
        }
        if ($retryAfterSeconds !== null
            && ($retryMode === self::RETRY_NONE || $retryAfterSeconds < 1 || $retryAfterSeconds > 86_400)) {
            throw new \InvalidArgumentException('The provider retry delay is invalid.');
        }
        parent::__construct($message);
    }
}
