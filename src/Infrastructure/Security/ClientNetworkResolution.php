<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

final class ClientNetworkResolution
{
    public function __construct(
        public readonly ?string $clientAddress,
        public readonly ?string $peerAddress,
        public readonly string $source,
        public readonly bool $peerTrusted,
        public readonly bool $forwardingHeaderPresent,
        public readonly bool $forwardingAccepted,
        public readonly bool $configurationValid,
        public readonly bool $proxiesConfigured,
        public readonly string $configuredHeader,
        public readonly ?string $issue = null
    ) {
    }
}
