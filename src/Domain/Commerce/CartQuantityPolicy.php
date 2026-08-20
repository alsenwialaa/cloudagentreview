<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

final class CartQuantityPolicy
{
    public static function assertAllowed(
        int $currentQuantity,
        int $requestedQuantity,
        bool $soldIndividually,
        int $minimumQuantity,
        int $maximumQuantity,
        bool $increaseAllowed,
        bool $extensionApproved
    ): void {
        if ($currentQuantity < 0 || $requestedQuantity < 1 || $requestedQuantity > 1000) {
            throw new \InvalidArgumentException('The requested cart quantity is outside the supported range.');
        }
        if ($soldIndividually && $requestedQuantity > 1) {
            throw new \InvalidArgumentException('This product can only be purchased one at a time.');
        }
        if ($minimumQuantity > 1 && $requestedQuantity < $minimumQuantity) {
            throw new \InvalidArgumentException('The requested quantity is below the product minimum.');
        }
        if ($maximumQuantity > 0 && $requestedQuantity > $maximumQuantity) {
            throw new \InvalidArgumentException('The requested quantity exceeds the product maximum.');
        }
        if ($requestedQuantity > $currentQuantity && !$increaseAllowed) {
            throw new \InvalidArgumentException('The requested additional quantity is not currently purchasable or in stock.');
        }
        if (!$extensionApproved) {
            throw new \InvalidArgumentException('A store rule rejected the requested quantity change.');
        }
    }
}
