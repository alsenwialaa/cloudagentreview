<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

final readonly class CartCommand
{
    public function __construct(
        public CartAction $action,
        public ?string $targetRef,
        public ?string $productRef,
        public ?int $quantity,
        public CartQuantityMode $quantityMode = CartQuantityMode::Explicit
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $rawAction = $input['action'] ?? null;
        if (!is_string($rawAction)) {
            throw new \InvalidArgumentException('Cart action must be a string.');
        }
        $action = CartAction::tryFrom($rawAction);
        if ($action === null) {
            throw new \InvalidArgumentException('Unsupported cart action.');
        }

        $targetRef = self::nullableRef($input['target_ref'] ?? null, 'l');
        $productRef = self::nullableRef($input['product_ref'] ?? null, 'p');
        $quantity = self::nullableQuantity($input['quantity'] ?? null);
        $rawQuantityMode = $input['quantity_mode'] ?? CartQuantityMode::Explicit->value;
        if (!is_string($rawQuantityMode)) {
            throw new \InvalidArgumentException('Quantity mode must be a string.');
        }
        $quantityMode = CartQuantityMode::tryFrom($rawQuantityMode);
        if ($quantityMode === null) {
            throw new \InvalidArgumentException('Unsupported cart quantity mode.');
        }

        return new self($action, $targetRef, $productRef, $quantity, $quantityMode);
    }

    private static function nullableRef(mixed $value, string $prefix): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9_-]{8,80}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid opaque cart reference.');
        }
        return $value;
    }

    private static function nullableQuantity(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new \InvalidArgumentException('Quantity must be an integer.');
        }
        return $value;
    }
}
