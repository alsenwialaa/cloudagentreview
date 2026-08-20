<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Infrastructure\Support\StrictJson;

/**
 * Strict JSON document boundary for persisted application-owned arrays.
 *
 * The byte limit is enforced before decoding and after encoding. A structural
 * budget is enforced before encoding and after decoding so a deeply nested or
 * extremely fragmented value cannot consume unbounded CPU or memory even when
 * its serialized byte length is modest.
 */
final class BoundedJson
{
    private const MAX_DEPTH = 32;
    private const MAX_NODES = 20_000;
    private const MAX_ARRAY_ITEMS = 5_000;
    private const MAX_KEY_BYTES = 1_024;
    private const MAX_STRING_BYTES = 1_048_576;

    /** @param array<mixed> $value */
    public static function encode(array $value, int $maxBytes, string $message): string
    {
        self::assertLimit($maxBytes);
        $nodes = 0;
        self::assertValue($value, 0, $nodes, $maxBytes);

        $json = wp_json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            self::MAX_DEPTH + 2
        );
        if (!is_string($json)) {
            throw new \RuntimeException($message);
        }
        if (strlen($json) > $maxBytes) {
            throw new \LengthException($message . ' The JSON document exceeds its byte limit.');
        }
        return $json;
    }

    /** @return array<mixed> */
    public static function decode(
        string $json,
        int $maxBytes,
        string $message,
        bool $emptyAsArray = false
    ): array {
        self::assertLimit($maxBytes);
        if ($json === '' && $emptyAsArray) {
            return array();
        }
        if ($json === '' || strlen($json) > $maxBytes) {
            throw new \LengthException($message . ' The JSON document is empty or exceeds its byte limit.');
        }

        try {
            $decoded = StrictJson::decodeAssociative(
                $json,
                self::MAX_DEPTH + 2,
                self::MAX_NODES
            );
        } catch (\JsonException $error) {
            throw new \RuntimeException($message, 0, $error);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException($message);
        }

        $nodes = 0;
        self::assertValue($decoded, 0, $nodes, $maxBytes);
        return $decoded;
    }

    private static function assertLimit(int $maxBytes): void
    {
        if ($maxBytes < 2 || $maxBytes > 16_777_216) {
            throw new \InvalidArgumentException('A persisted JSON byte limit is invalid.');
        }
    }

    private static function assertValue(mixed $value, int $depth, int &$nodes, int $maxBytes): void
    {
        ++$nodes;
        if ($nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \LengthException('The JSON document exceeds its structural limit.');
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \UnexpectedValueException('The JSON document contains a non-finite number.');
            }
            return;
        }
        if (is_string($value)) {
            if (strlen($value) > min(self::MAX_STRING_BYTES, $maxBytes)
                || preg_match('//u', $value) !== 1) {
                throw new \LengthException('The JSON document contains invalid or oversized text.');
            }
            return;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException('The JSON document contains an unsupported value type.');
        }
        if (count($value) > self::MAX_ARRAY_ITEMS) {
            throw new \LengthException('The JSON document contains an oversized array.');
        }

        foreach ($value as $key => $item) {
            if (is_string($key)
                && (strlen($key) > self::MAX_KEY_BYTES || preg_match('//u', $key) !== 1)) {
                throw new \LengthException('The JSON document contains an invalid or oversized key.');
            }
            self::assertValue($item, $depth + 1, $nodes, $maxBytes);
        }
    }
}
