<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Conversation;

use YassinStore\AiAssistant\Domain\Shared\Base64Url;
use YassinStore\AiAssistant\Domain\Shared\Uuid;

final readonly class ConversationCredentials
{
    public function __construct(
        public string $id,
        public string $token
    ) {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Invalid conversation identifier.');
        }
        if (strlen($token) < 40 || strlen($token) > 100) {
            throw new \InvalidArgumentException('Invalid conversation token.');
        }
    }

    public static function issue(): self
    {
        return new self(Uuid::v4(), Base64Url::encode(random_bytes(32)));
    }
}
