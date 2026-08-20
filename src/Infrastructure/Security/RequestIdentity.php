<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

final class RequestIdentity
{
    public function __construct(private readonly ?TrustedProxyResolver $networkResolver = null)
    {
    }

    public function browserBucket(): string
    {
        $resolution = ($this->networkResolver ?? new TrustedProxyResolver())->resolve();
        $network = $this->canonicalNetwork($resolution->clientAddress ?? '');
        $secret = function_exists('wp_salt') ? wp_salt('nonce') : 'ysai-rate-limit';
        return hash_hmac('sha256', 'network-v2|' . $network, $secret);
    }

    private function canonicalNetwork(string $address): string
    {
        $packed = $address === '' ? false : @inet_pton(trim($address));
        if (!is_string($packed)) {
            return 'unknown';
        }
        if (strlen($packed) === 4) {
            $canonical = @inet_ntop($packed);
            return is_string($canonical) ? 'ipv4:' . $canonical : 'unknown';
        }
        if (strlen($packed) !== 16) {
            return 'unknown';
        }
        if (substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xff\xff") {
            $canonical = @inet_ntop(substr($packed, 12, 4));
            return is_string($canonical) ? 'ipv4:' . $canonical : 'unknown';
        }
        $network = substr($packed, 0, 8) . str_repeat("\0", 8);
        $canonical = @inet_ntop($network);
        return is_string($canonical) ? 'ipv6-64:' . strtolower($canonical) : 'unknown';
    }
}
