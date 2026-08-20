<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Chat;

final class PublicException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $publicCode = 'request_failed',
        public readonly int $httpStatus = 400,
        public readonly ?int $retryAfterSeconds = null
    ) {
        if ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 86_400)) {
            throw new \InvalidArgumentException('The public retry delay is outside the supported range.');
        }
        parent::__construct($message);
    }
}
