<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

/**
 * Central policy for browser-visible navigation URLs.
 *
 * Product, cart, checkout, and merchant-configured links are authority-bearing
 * navigation targets. Only absolute HTTP(S) URLs on the configured WordPress
 * origin are allowed to cross the public API boundary.
 */
final readonly class SameOriginUrl
{
    /** @var array{scheme:string,host:string,port:int} */
    private array $baseOrigin;

    public function __construct(string $baseUrl)
    {
        $origin = self::origin($baseUrl);
        if ($origin === null) {
            throw new \InvalidArgumentException('The WordPress home URL does not have a valid HTTP(S) origin.');
        }
        $this->baseOrigin = $origin;
    }

    public function sanitize(string|false $url): string
    {
        if (!is_string($url) || $url === '' || !$this->isSameOrigin($url)) {
            return '';
        }

        $sanitized = esc_url_raw($url, array('http', 'https'));
        return is_string($sanitized) && $sanitized !== '' && $this->isSameOrigin($sanitized)
            ? $sanitized
            : '';
    }

    public function isSameOrigin(string $url): bool
    {
        if ($url === ''
            || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return false;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return false;
        }

        $candidate = self::origin($url);
        return $candidate !== null
            && hash_equals($this->baseOrigin['scheme'], $candidate['scheme'])
            && hash_equals($this->baseOrigin['host'], $candidate['host'])
            && $this->baseOrigin['port'] === $candidate['port'];
    }

    /** @return array{scheme:string,host:string,port:int}|null */
    private static function origin(string $url): ?array
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, array('http', 'https'), true) || $host === '') {
            return null;
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return array('scheme' => $scheme, 'host' => $host, 'port' => $port);
    }
}
