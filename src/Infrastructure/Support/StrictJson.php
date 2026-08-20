<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Support;

/**
 * Strict JSON boundary that rejects duplicate object member names.
 *
 * PHP's native decoder accepts duplicate keys and silently keeps the last
 * value. That behavior is unsafe at authentication, authorization, provider,
 * and persistence boundaries because different components can interpret the
 * same byte stream differently. This scanner validates the JSON grammar and
 * compares decoded key names (so "name" and "\u006eame" conflict) before the
 * document is handed to json_decode().
 */
final class StrictJson
{
    private int $offset = 0;
    private int $nodes = 0;
    private readonly int $length;

    private function __construct(
        private readonly string $json,
        private readonly int $maxDepth,
        private readonly int $maxNodes
    ) {
        $this->length = strlen($json);
    }

    /**
     * Decode the same validated document as both native JSON containers and
     * associative arrays. The native representation preserves the distinction
     * between an empty object and an empty array.
     *
     * @return array{raw:mixed,associative:mixed}
     * @throws \JsonException
     */
    public static function decodePair(string $json, int $maxDepth = 128, int $maxNodes = 100_000): array
    {
        self::assertLimits($maxDepth, $maxNodes);
        (new self($json, $maxDepth, $maxNodes))->parseDocument();

        return array(
            'raw' => json_decode($json, false, $maxDepth, JSON_THROW_ON_ERROR),
            'associative' => json_decode($json, true, $maxDepth, JSON_THROW_ON_ERROR),
        );
    }

    /** @throws \JsonException */
    public static function decodeAssociative(string $json, int $maxDepth = 128, int $maxNodes = 100_000): mixed
    {
        return self::decodePair($json, $maxDepth, $maxNodes)['associative'];
    }

    /** @throws \JsonException */
    public static function assertValid(string $json, int $maxDepth = 128, int $maxNodes = 100_000): void
    {
        self::assertLimits($maxDepth, $maxNodes);
        (new self($json, $maxDepth, $maxNodes))->parseDocument();
    }

    private static function assertLimits(int $maxDepth, int $maxNodes): void
    {
        if ($maxDepth < 2 || $maxDepth > 512 || $maxNodes < 1 || $maxNodes > 1_000_000) {
            throw new \InvalidArgumentException('The strict JSON limits are invalid.');
        }
    }

    /** @throws \JsonException */
    private function parseDocument(): void
    {
        $this->skipWhitespace();
        if ($this->offset >= $this->length) {
            $this->invalid();
        }
        $this->parseValue(1);
        $this->skipWhitespace();
        if ($this->offset !== $this->length) {
            $this->invalid();
        }
    }

    /** @throws \JsonException */
    private function parseValue(int $depth): void
    {
        ++$this->nodes;
        if ($this->nodes > $this->maxNodes || $depth > $this->maxDepth) {
            throw new \JsonException('The JSON document exceeds its structural limit.');
        }
        if ($this->offset >= $this->length) {
            $this->invalid();
        }

        $byte = $this->json[$this->offset];
        if ($byte === '{') {
            $this->parseObject($depth);
            return;
        }
        if ($byte === '[') {
            $this->parseArray($depth);
            return;
        }
        if ($byte === '"') {
            $this->parseString();
            return;
        }
        if ($byte === 't') {
            $this->parseLiteral('true');
            return;
        }
        if ($byte === 'f') {
            $this->parseLiteral('false');
            return;
        }
        if ($byte === 'n') {
            $this->parseLiteral('null');
            return;
        }
        if ($byte === '-' || ($byte >= '0' && $byte <= '9')) {
            $this->parseNumber();
            return;
        }
        $this->invalid();
    }

    /** @throws \JsonException */
    private function parseObject(int $depth): void
    {
        ++$this->offset;
        $this->skipWhitespace();
        if ($this->consumeIf('}')) {
            return;
        }

        /** @var array<string,true> $seen */
        $seen = array();
        while (true) {
            if ($this->offset >= $this->length || $this->json[$this->offset] !== '"') {
                $this->invalid();
            }
            $key = $this->parseString();
            // Prefixing prevents PHP from coercing numeric-looking JSON keys to
            // integer array keys. Every valid Unicode key remains distinguishable.
            $identity = "k\0" . $key;
            if (isset($seen[$identity])) {
                throw new \JsonException('The JSON document contains a duplicate object key.');
            }
            $seen[$identity] = true;

            $this->skipWhitespace();
            $this->consume(':');
            $this->skipWhitespace();
            $this->parseValue($depth + 1);
            $this->skipWhitespace();

            if ($this->consumeIf('}')) {
                return;
            }
            $this->consume(',');
            $this->skipWhitespace();
        }
    }

    /** @throws \JsonException */
    private function parseArray(int $depth): void
    {
        ++$this->offset;
        $this->skipWhitespace();
        if ($this->consumeIf(']')) {
            return;
        }

        while (true) {
            $this->parseValue($depth + 1);
            $this->skipWhitespace();
            if ($this->consumeIf(']')) {
                return;
            }
            $this->consume(',');
            $this->skipWhitespace();
        }
    }

    /** @throws \JsonException */
    private function parseString(): string
    {
        $start = $this->offset;
        ++$this->offset;

        while ($this->offset < $this->length) {
            $value = ord($this->json[$this->offset]);
            if ($value === 0x22) {
                ++$this->offset;
                $token = substr($this->json, $start, $this->offset - $start);
                $decoded = json_decode($token, false, 2, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) {
                    $this->invalid();
                }
                return $decoded;
            }
            if ($value < 0x20) {
                $this->invalid();
            }
            if ($value !== 0x5C) {
                ++$this->offset;
                continue;
            }

            ++$this->offset;
            if ($this->offset >= $this->length) {
                $this->invalid();
            }
            $escape = $this->json[$this->offset];
            if (str_contains('"\\/bfnrt', $escape)) {
                ++$this->offset;
                continue;
            }
            if ($escape !== 'u' || $this->offset + 4 >= $this->length) {
                $this->invalid();
            }
            for ($index = 1; $index <= 4; ++$index) {
                if (!ctype_xdigit($this->json[$this->offset + $index])) {
                    $this->invalid();
                }
            }
            $this->offset += 5;
        }

        $this->invalid();
    }

    /** @throws \JsonException */
    private function parseLiteral(string $literal): void
    {
        if (substr($this->json, $this->offset, strlen($literal)) !== $literal) {
            $this->invalid();
        }
        $this->offset += strlen($literal);
    }

    /** @throws \JsonException */
    private function parseNumber(): void
    {
        $start = $this->offset;
        if ($this->consumeIf('-') && $this->offset >= $this->length) {
            $this->invalid();
        }

        if (!$this->consumeIf('0')) {
            if ($this->offset >= $this->length
                || $this->json[$this->offset] < '1'
                || $this->json[$this->offset] > '9') {
                $this->invalid();
            }
            ++$this->offset;
            $this->consumeDigits();
        }

        if ($this->consumeIf('.') && !$this->consumeDigits(true)) {
            $this->invalid();
        }

        if ($this->offset < $this->length
            && ($this->json[$this->offset] === 'e' || $this->json[$this->offset] === 'E')) {
            ++$this->offset;
            if ($this->offset < $this->length
                && ($this->json[$this->offset] === '+' || $this->json[$this->offset] === '-')) {
                ++$this->offset;
            }
            if (!$this->consumeDigits(true)) {
                $this->invalid();
            }
        }

        // PHP accepts overflowing JSON numbers as +/-INF. Infinity is not a
        // JSON number and must never cross request, provider, or persistence
        // boundaries as a value that different components can interpret
        // differently.
        $decoded = json_decode(
            substr($this->json, $start, $this->offset - $start),
            false,
            2,
            JSON_THROW_ON_ERROR
        );
        if (!is_int($decoded) && (!is_float($decoded) || !is_finite($decoded))) {
            throw new \JsonException('The JSON document contains a non-finite number.');
        }
    }

    private function consumeDigits(bool $requireOne = false): bool
    {
        $start = $this->offset;
        while ($this->offset < $this->length
            && $this->json[$this->offset] >= '0'
            && $this->json[$this->offset] <= '9') {
            ++$this->offset;
        }
        return !$requireOne || $this->offset > $start;
    }

    private function skipWhitespace(): void
    {
        while ($this->offset < $this->length) {
            $byte = $this->json[$this->offset];
            if ($byte !== ' ' && $byte !== "\t" && $byte !== "\n" && $byte !== "\r") {
                return;
            }
            ++$this->offset;
        }
    }

    /** @throws \JsonException */
    private function consume(string $expected): void
    {
        if (!$this->consumeIf($expected)) {
            $this->invalid();
        }
    }

    private function consumeIf(string $expected): bool
    {
        if ($this->offset < $this->length && $this->json[$this->offset] === $expected) {
            ++$this->offset;
            return true;
        }
        return false;
    }

    /** @throws \JsonException */
    private function invalid(): never
    {
        throw new \JsonException('The JSON document is invalid or ambiguous.');
    }
}
