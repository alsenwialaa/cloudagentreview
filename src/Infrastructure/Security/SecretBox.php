<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use YassinStore\AiAssistant\Domain\Shared\Base64Url;

final class SecretBox
{
    private const SODIUM_PREFIX = 'enc:s1:';
    private const OPENSSL_PREFIX = 'enc:o1:';

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = $this->key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return self::SODIUM_PREFIX . Base64Url::encode($nonce . $ciphertext);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );
            if (!is_string($ciphertext) || strlen($tag) !== 16) {
                throw new \RuntimeException('Unable to encrypt the API key.');
            }
            return self::OPENSSL_PREFIX . Base64Url::encode($iv . $tag . $ciphertext);
        }

        throw new \RuntimeException('Sodium or OpenSSL is required to store the API key securely.');
    }

    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (str_starts_with($stored, self::SODIUM_PREFIX)) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new \RuntimeException('Sodium is required to decrypt the stored API key.');
            }
            $raw = Base64Url::decode(substr($stored, strlen(self::SODIUM_PREFIX)));
            $nonceBytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($raw) <= $nonceBytes) {
                throw new \RuntimeException('The stored API key is corrupt.');
            }
            $plaintext = sodium_crypto_secretbox_open(
                substr($raw, $nonceBytes),
                substr($raw, 0, $nonceBytes),
                $this->key()
            );
            if (!is_string($plaintext)) {
                throw new \RuntimeException('The stored API key could not be decrypted.');
            }
            return $plaintext;
        }

        if (str_starts_with($stored, self::OPENSSL_PREFIX)) {
            if (!function_exists('openssl_decrypt')) {
                throw new \RuntimeException('OpenSSL is required to decrypt the stored API key.');
            }
            $raw = Base64Url::decode(substr($stored, strlen(self::OPENSSL_PREFIX)));
            if (strlen($raw) <= 28) {
                throw new \RuntimeException('The stored API key is corrupt.');
            }
            $plaintext = openssl_decrypt(
                substr($raw, 28),
                'aes-256-gcm',
                $this->key(),
                OPENSSL_RAW_DATA,
                substr($raw, 0, 12),
                substr($raw, 12, 16)
            );
            if (!is_string($plaintext)) {
                throw new \RuntimeException('The stored API key could not be decrypted.');
            }
            return $plaintext;
        }

        // One-way compatibility bridge for the previous release's plaintext option.
        // New saves are always encrypted.
        return $stored;
    }

    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::SODIUM_PREFIX)
            || str_starts_with($stored, self::OPENSSL_PREFIX);
    }

    private function key(): string
    {
        $material = function_exists('wp_salt')
            ? wp_salt('auth')
            : (defined('AUTH_KEY') ? (string) AUTH_KEY : 'ysai-development-only-key');

        return hash('sha256', $material . '|yassin-ai-assistant|api-key', true);
    }
}
