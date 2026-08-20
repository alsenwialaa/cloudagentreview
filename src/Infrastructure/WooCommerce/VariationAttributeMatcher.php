<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

/**
 * Matches one fully specified shopper selection against a normalized
 * WooCommerce variation. An empty variation value is WooCommerce's explicit
 * "Any" wildcard; missing attributes are not treated as wildcards.
 */
final class VariationAttributeMatcher
{
    /**
     * @param array<string,string> $requested
     * @param array<string,string> $actual
     */
    public function matches(array $requested, array $actual): bool
    {
        if ($requested === array() || count($requested) !== count($actual)) {
            return false;
        }

        foreach ($requested as $attribute => $requestedValue) {
            if (!is_string($attribute)
                || $attribute === ''
                || !is_string($requestedValue)
                || $requestedValue === ''
                || !array_key_exists($attribute, $actual)
                || !is_string($actual[$attribute])) {
                return false;
            }
            $actualValue = $actual[$attribute];
            if ($actualValue !== '' && !hash_equals($requestedValue, $actualValue)) {
                return false;
            }
        }

        return true;
    }
}
