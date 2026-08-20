<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

final class TokenHasher
{
    public function hash(string $token): string
    {
        $secret = function_exists('wp_salt')
            ? wp_salt('secure_auth')
            : (defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : 'ysai-development-token-key');

        return hash_hmac('sha256', $token, $secret);
    }
}
