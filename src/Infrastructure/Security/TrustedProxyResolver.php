<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

use YassinStore\AiAssistant\Application\Contract\RuntimeSettings;

/** Resolve a client address only through explicitly trusted proxy networks. */
final class TrustedProxyResolver
{
    public const HEADER_FORWARDED = 'forwarded';
    public const HEADER_X_FORWARDED_FOR = 'x-forwarded-for';

    private const MAX_CONFIG_BYTES = 8192;
    private const MAX_CIDRS = 64;
    private const MAX_HEADER_BYTES = 4096;
    private const MAX_HOPS = 16;

    /** @var array{valid:bool,configured:bool,header:string,networks:list<array{packed:string,prefix:int,length:int}>,constant_override:bool}|null */
    private ?array $configuration = null;

    public function __construct(private readonly ?RuntimeSettings $settings = null)
    {
    }

    public static function normalizeCidrText(mixed $value): string
    {
        if (is_array($value)) {
            $entries = array();
            foreach ($value as $entry) {
                if (!is_string($entry)) {
                    throw new \InvalidArgumentException('Trusted proxy CIDRs must be strings.');
                }
                $entries[] = $entry;
            }
            $value = implode("\n", $entries);
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Trusted proxy CIDRs must be text.');
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > self::MAX_CONFIG_BYTES
            || preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $value) === 1) {
            throw new \InvalidArgumentException('Trusted proxy CIDRs are malformed or oversized.');
        }
        $tokens = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || count($tokens) > self::MAX_CIDRS) {
            throw new \InvalidArgumentException('Too many trusted proxy CIDRs were supplied.');
        }
        $normalized = array();
        foreach ($tokens as $token) {
            $network = self::parseCidrToken((string) $token);
            $normalized[$network['canonical']] = true;
        }
        return implode("\n", array_keys($normalized));
    }

    public static function normalizeHeader(mixed $value): string
    {
        return $value === self::HEADER_FORWARDED
            ? self::HEADER_FORWARDED
            : self::HEADER_X_FORWARDED_FOR;
    }

    /** @param array<string,mixed>|null $server */
    public function resolve(?array $server = null): ClientNetworkResolution
    {
        $server ??= $_SERVER;
        $configuration = $this->configuration();
        $peer = self::normalizeIp(is_string($server['REMOTE_ADDR'] ?? null)
            ? (string) $server['REMOTE_ADDR']
            : '');
        $forwardedPresent = $this->hasForwardingHeader($server);

        if (!$configuration['valid']) {
            return new ClientNetworkResolution(
                $peer,
                $peer,
                'remote-addr',
                false,
                $forwardedPresent,
                false,
                false,
                $configuration['configured'],
                $configuration['header'],
                'invalid_trusted_proxy_configuration'
            );
        }
        if ($peer === null) {
            return new ClientNetworkResolution(
                null,
                null,
                'unknown',
                false,
                $forwardedPresent,
                false,
                true,
                $configuration['configured'],
                $configuration['header'],
                'remote_address_invalid'
            );
        }
        if (!$configuration['configured']) {
            return new ClientNetworkResolution(
                $peer,
                $peer,
                'remote-addr',
                false,
                $forwardedPresent,
                false,
                true,
                false,
                $configuration['header'],
                $forwardedPresent ? 'forwarding_headers_unconfigured' : null
            );
        }

        $peerTrusted = $this->matchesAny($peer, $configuration['networks']);
        if (!$peerTrusted) {
            return new ClientNetworkResolution(
                $peer,
                $peer,
                'remote-addr',
                false,
                $forwardedPresent,
                false,
                true,
                true,
                $configuration['header'],
                $forwardedPresent ? 'untrusted_peer_forwarding_ignored' : null
            );
        }

        $serverKey = $configuration['header'] === self::HEADER_FORWARDED
            ? 'HTTP_FORWARDED'
            : 'HTTP_X_FORWARDED_FOR';
        $raw = is_string($server[$serverKey] ?? null) ? trim((string) $server[$serverKey]) : '';
        if ($raw === '') {
            return new ClientNetworkResolution(
                $peer,
                $peer,
                'remote-addr',
                true,
                $forwardedPresent,
                false,
                true,
                true,
                $configuration['header'],
                $forwardedPresent ? 'trusted_proxy_header_mismatch' : 'trusted_proxy_header_missing'
            );
        }

        try {
            $hops = $configuration['header'] === self::HEADER_FORWARDED
                ? $this->parseForwarded($raw)
                : $this->parseXForwardedFor($raw);
            $client = $this->selectClient($hops, $peer, $configuration['networks']);
        } catch (\InvalidArgumentException) {
            return new ClientNetworkResolution(
                $peer,
                $peer,
                'remote-addr',
                true,
                true,
                false,
                true,
                true,
                $configuration['header'],
                'invalid_forwarding_header'
            );
        }

        return new ClientNetworkResolution(
            $client,
            $peer,
            $configuration['header'],
            true,
            true,
            true,
            true,
            true,
            $configuration['header']
        );
    }

    /** @param array<string,mixed>|null $server @return array{status:string,code:string,configured:bool,source:string,constant_override:bool} */
    public function diagnostic(?array $server = null): array
    {
        $resolution = $this->resolve($server);
        $configuration = $this->configuration();
        $code = $resolution->issue ?? ($resolution->forwardingAccepted
            ? 'trusted_proxy_forwarding_active'
            : 'direct_peer_identity');
        $status = in_array($code, array('invalid_trusted_proxy_configuration', 'remote_address_invalid'), true)
            ? 'error'
            : ($resolution->issue === null ? 'ok' : 'warning');
        return array(
            'status' => $status,
            'code' => $code,
            'configured' => $resolution->proxiesConfigured,
            'source' => $resolution->source,
            'constant_override' => $configuration['constant_override'],
        );
    }

    /** @return array{valid:bool,configured:bool,header:string,networks:list<array{packed:string,prefix:int,length:int}>,constant_override:bool} */
    private function configuration(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }
        $constantOverride = defined('YSAI_TRUSTED_PROXY_CIDRS');
        $rawCidrs = $constantOverride
            ? constant('YSAI_TRUSTED_PROXY_CIDRS')
            : ($this->settings?->get('trusted_proxy_cidrs', '') ?? '');
        $rawHeader = defined('YSAI_TRUSTED_PROXY_HEADER')
            ? constant('YSAI_TRUSTED_PROXY_HEADER')
            : ($this->settings?->get('trusted_proxy_header', self::HEADER_X_FORWARDED_FOR)
                ?? self::HEADER_X_FORWARDED_FOR);
        try {
            $canonical = self::normalizeCidrText($rawCidrs);
            if (!is_string($rawHeader)
                || !in_array($rawHeader, array(self::HEADER_FORWARDED, self::HEADER_X_FORWARDED_FOR), true)) {
                throw new \InvalidArgumentException('The trusted proxy header selection is invalid.');
            }
            $networks = array();
            foreach ($canonical === '' ? array() : explode("\n", $canonical) as $token) {
                $parsed = self::parseCidrToken($token);
                $networks[] = array(
                    'packed' => $parsed['packed'],
                    'prefix' => $parsed['prefix'],
                    'length' => $parsed['length'],
                );
            }
            return $this->configuration = array(
                'valid' => true,
                'configured' => $networks !== array(),
                'header' => $rawHeader,
                'networks' => $networks,
                'constant_override' => $constantOverride || defined('YSAI_TRUSTED_PROXY_HEADER'),
            );
        } catch (\InvalidArgumentException) {
            return $this->configuration = array(
                'valid' => false,
                'configured' => trim(is_string($rawCidrs) ? $rawCidrs : '') !== '',
                'header' => self::normalizeHeader($rawHeader),
                'networks' => array(),
                'constant_override' => $constantOverride || defined('YSAI_TRUSTED_PROXY_HEADER'),
            );
        }
    }

    /** @param array<string,mixed> $server */
    private function hasForwardingHeader(array $server): bool
    {
        foreach (array('HTTP_FORWARDED', 'HTTP_X_FORWARDED_FOR') as $key) {
            if (is_string($server[$key] ?? null) && trim((string) $server[$key]) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function parseXForwardedFor(string $value): array
    {
        $this->assertHeaderBounds($value);
        $parts = explode(',', $value);
        if (count($parts) === 0 || count($parts) > self::MAX_HOPS) {
            throw new \InvalidArgumentException('The forwarding chain is malformed or oversized.');
        }
        $hops = array();
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || str_contains($part, '"') || str_contains($part, "'")) {
                throw new \InvalidArgumentException('The forwarding chain contains an invalid address.');
            }
            $hops[] = $this->parseNodeAddress($part);
        }
        return $hops;
    }

    /** @return list<string> */
    private function parseForwarded(string $value): array
    {
        $this->assertHeaderBounds($value);
        $elements = $this->splitQuoted($value, ',');
        if (count($elements) === 0 || count($elements) > self::MAX_HOPS) {
            throw new \InvalidArgumentException('The Forwarded chain is malformed or oversized.');
        }
        $hops = array();
        foreach ($elements as $element) {
            $forValue = null;
            foreach ($this->splitQuoted($element, ';') as $parameter) {
                $separator = strpos($parameter, '=');
                if ($separator === false) {
                    throw new \InvalidArgumentException('A Forwarded parameter is malformed.');
                }
                $name = strtolower(trim(substr($parameter, 0, $separator)));
                $raw = trim(substr($parameter, $separator + 1));
                if ($name !== 'for') {
                    continue;
                }
                if ($forValue !== null || $raw === '') {
                    throw new \InvalidArgumentException('A Forwarded element is ambiguous.');
                }
                $forValue = $this->unquoteForwardedValue($raw);
            }
            if ($forValue === null) {
                throw new \InvalidArgumentException('A Forwarded element has no client address.');
            }
            $hops[] = $this->parseNodeAddress($forValue);
        }
        return $hops;
    }

    private function assertHeaderBounds(string $value): void
    {
        if ($value === '' || strlen($value) > self::MAX_HEADER_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('The forwarding header is malformed or oversized.');
        }
    }

    /** @return list<string> */
    private function splitQuoted(string $value, string $delimiter): array
    {
        $parts = array();
        $current = '';
        $quoted = false;
        $escaped = false;
        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            $character = $value[$index];
            if ($escaped) {
                $current .= $character;
                $escaped = false;
                continue;
            }
            if ($quoted && $character === '\\') {
                $current .= $character;
                $escaped = true;
                continue;
            }
            if ($character === '"') {
                $quoted = !$quoted;
                $current .= $character;
                continue;
            }
            if (!$quoted && $character === $delimiter) {
                $part = trim($current);
                if ($part === '') {
                    throw new \InvalidArgumentException('The forwarding header contains an empty element.');
                }
                $parts[] = $part;
                $current = '';
                continue;
            }
            $current .= $character;
        }
        if ($quoted || $escaped || trim($current) === '') {
            throw new \InvalidArgumentException('The forwarding header contains an incomplete element.');
        }
        $parts[] = trim($current);
        return $parts;
    }

    private function unquoteForwardedValue(string $value): string
    {
        if ($value[0] !== '"') {
            if (str_contains($value, '"') || str_contains($value, '\\')) {
                throw new \InvalidArgumentException('The Forwarded address is malformed.');
            }
            return $value;
        }
        if (strlen($value) < 2 || $value[strlen($value) - 1] !== '"') {
            throw new \InvalidArgumentException('The Forwarded address quote is incomplete.');
        }
        $inner = substr($value, 1, -1);
        $decoded = '';
        $escaped = false;
        for ($index = 0, $length = strlen($inner); $index < $length; ++$index) {
            $character = $inner[$index];
            if ($escaped) {
                if ($character !== '"' && $character !== '\\') {
                    throw new \InvalidArgumentException('The Forwarded address contains an invalid escape.');
                }
                $decoded .= $character;
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
                continue;
            }
            if ($character === '"') {
                throw new \InvalidArgumentException('The Forwarded address contains an unescaped quote.');
            }
            $decoded .= $character;
        }
        if ($escaped || $decoded === '') {
            throw new \InvalidArgumentException('The Forwarded address is malformed.');
        }
        return $decoded;
    }

    private function parseNodeAddress(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'unknown' || str_starts_with($value, '_')) {
            throw new \InvalidArgumentException('The forwarding node is not an address.');
        }
        if ($value[0] === '[') {
            $close = strpos($value, ']');
            if ($close === false) {
                throw new \InvalidArgumentException('The bracketed forwarding address is malformed.');
            }
            $address = substr($value, 1, $close - 1);
            $suffix = substr($value, $close + 1);
            if ($suffix !== '' && ($suffix[0] !== ':' || !$this->validPort(substr($suffix, 1)))) {
                throw new \InvalidArgumentException('The forwarding address port is invalid.');
            }
            $normalized = self::normalizeIp($address);
            if ($normalized === null || !str_contains($normalized, ':')) {
                throw new \InvalidArgumentException('The bracketed forwarding address is invalid.');
            }
            return $normalized;
        }
        $normalized = self::normalizeIp($value);
        if ($normalized !== null) {
            return $normalized;
        }
        if (substr_count($value, ':') === 1) {
            [$address, $port] = explode(':', $value, 2);
            $normalized = self::normalizeIp($address);
            if ($normalized !== null && !str_contains($normalized, ':') && $this->validPort($port)) {
                return $normalized;
            }
        }
        throw new \InvalidArgumentException('The forwarding node address is invalid.');
    }

    private function validPort(string $port): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,4})$/D', $port) === 1 && (int) $port <= 65535;
    }

    /** @param list<string> $hops @param list<array{packed:string,prefix:int,length:int}> $networks */
    private function selectClient(array $hops, string $peer, array $networks): string
    {
        $candidate = $peer;
        for ($index = count($hops) - 1; $index >= 0; --$index) {
            if (!$this->matchesAny($candidate, $networks)) {
                break;
            }
            $candidate = $hops[$index];
        }
        return $candidate;
    }

    /** @param list<array{packed:string,prefix:int,length:int}> $networks */
    private function matchesAny(string $address, array $networks): bool
    {
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            return false;
        }
        foreach ($networks as $network) {
            if (strlen($packed) === $network['length']
                && self::matchesPackedNetwork($packed, $network['packed'], $network['prefix'])) {
                return true;
            }
        }
        return false;
    }

    private static function normalizeIp(string $address): ?string
    {
        $address = trim($address);
        if ($address === '' || str_contains($address, '%')) {
            return null;
        }
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            return null;
        }
        if (strlen($packed) === 16
            && substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xff\xff") {
            $packed = substr($packed, 12, 4);
        }
        $canonical = @inet_ntop($packed);
        return is_string($canonical) ? strtolower($canonical) : null;
    }

    /** @return array{canonical:string,packed:string,prefix:int,length:int} */
    private static function parseCidrToken(string $token): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 80 || preg_match('/^[0-9A-Fa-f:.\/]+$/D', $token) !== 1) {
            throw new \InvalidArgumentException('A trusted proxy CIDR is invalid.');
        }
        $parts = explode('/', $token, 2);
        $address = self::normalizeIp($parts[0]);
        if ($address === null) {
            throw new \InvalidArgumentException('A trusted proxy CIDR address is invalid.');
        }
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            throw new \InvalidArgumentException('A trusted proxy CIDR address is invalid.');
        }
        $length = strlen($packed);
        $maximum = $length === 4 ? 32 : 128;
        $prefix = $maximum;
        if (isset($parts[1])) {
            if ($parts[1] === '' || preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $parts[1]) !== 1) {
                throw new \InvalidArgumentException('A trusted proxy CIDR prefix is invalid.');
            }
            $prefix = (int) $parts[1];
            if ($prefix > $maximum) {
                throw new \InvalidArgumentException('A trusted proxy CIDR prefix is out of range.');
            }
        }
        if ($prefix === 0) {
            throw new \InvalidArgumentException(
                'A trust-all proxy CIDR is not permitted because it would authenticate forwarding headers from every peer.'
            );
        }
        $network = self::maskPacked($packed, $prefix);
        $canonicalAddress = @inet_ntop($network);
        if (!is_string($canonicalAddress)) {
            throw new \InvalidArgumentException('A trusted proxy CIDR cannot be normalized.');
        }
        return array(
            'canonical' => strtolower($canonicalAddress) . '/' . $prefix,
            'packed' => $network,
            'prefix' => $prefix,
            'length' => $length,
        );
    }

    private static function maskPacked(string $packed, int $prefix): string
    {
        $bytes = array_values(unpack('C*', $packed) ?: array());
        $remaining = $prefix;
        foreach ($bytes as $index => $byte) {
            if ($remaining >= 8) {
                $remaining -= 8;
                continue;
            }
            if ($remaining <= 0) {
                $bytes[$index] = 0;
                continue;
            }
            $bytes[$index] = $byte & (0xFF << (8 - $remaining));
            $remaining = 0;
        }
        return pack('C*', ...$bytes);
    }

    private static function matchesPackedNetwork(string $address, string $network, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && !hash_equals(substr($network, 0, $fullBytes), substr($address, 0, $fullBytes))) {
            return false;
        }
        $remaining = $prefix % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = 0xFF << (8 - $remaining);
        return (ord($address[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
