<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

use YassinStore\AiAssistant\Domain\Shared\Uuid;

final readonly class CartReceipt
{
    private const MAX_RECEIPT_BYTES = 1048576; // 1 MiB.
    private const MAX_LINE_BYTES = 65536;
    private const MAX_CART_BYTES = 524288;
    private const MAX_DEPTH = 16;
    private const MAX_NODES = 10000;
    private const MAX_ARRAY_ITEMS = 1000;
    private const MAX_KEY_BYTES = 512;
    private const MAX_STRING_BYTES = 262144;

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $cart
     */
    public function __construct(
        public string $id,
        public string $message,
        public array $lines,
        public array $cart
    ) {
    }

    /** @param array<string,mixed> $value */
    public static function fromArray(array $value): ?self
    {
        if (array_diff(array_keys($value), array('id', 'message', 'lines', 'cart')) !== array()
            || !is_string($value['id'] ?? null)
            || !is_string($value['message'] ?? null)
            || !is_array($value['lines'] ?? null)
            || !is_array($value['cart'] ?? null)) {
            return null;
        }

        $id = $value['id'];
        $message = $value['message'];
        $lines = $value['lines'];
        $cart = $value['cart'];
        if (!Uuid::isValid($id)
            || $message === ''
            || strlen($message) > 3000
            || preg_match('//u', $message) !== 1
            || !array_is_list($lines)
            || count($lines) > 12) {
            return null;
        }

        try {
            if (self::encodedSize($value) > self::MAX_RECEIPT_BYTES
                || self::encodedSize($cart) > self::MAX_CART_BYTES) {
                return null;
            }
            foreach ($lines as $line) {
                if (!is_array($line) || self::encodedSize($line) > self::MAX_LINE_BYTES) {
                    return null;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return new self($id, $message, $lines, $cart);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array(
            'id' => $this->id,
            'message' => $this->message,
            'lines' => $this->lines,
            'cart' => $this->cart,
        );
    }

    private static function encodedSize(mixed $value): int
    {
        $nodes = 0;
        self::assertBoundedValue($value, 0, $nodes);
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            self::MAX_DEPTH + 2
        );
        return strlen($json);
    }

    private static function assertBoundedValue(mixed $value, int $depth, int &$nodes): void
    {
        ++$nodes;
        if ($nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \LengthException('The cart receipt exceeds its structural limit.');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \UnexpectedValueException('The cart receipt contains a non-finite number.');
            }
            return;
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_BYTES || preg_match('//u', $value) !== 1) {
                throw new \LengthException('The cart receipt contains invalid or oversized text.');
            }
            return;
        }
        if (!is_array($value) || count($value) > self::MAX_ARRAY_ITEMS) {
            throw new \UnexpectedValueException('The cart receipt contains an unsupported or oversized value.');
        }
        foreach ($value as $key => $item) {
            if (is_string($key)
                && (strlen($key) > self::MAX_KEY_BYTES || preg_match('//u', $key) !== 1)) {
                throw new \LengthException('The cart receipt contains an invalid or oversized key.');
            }
            self::assertBoundedValue($item, $depth + 1, $nodes);
        }
    }
}
