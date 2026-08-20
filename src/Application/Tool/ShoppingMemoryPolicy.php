<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Application\Support\SensitiveData;
use YassinStore\AiAssistant\Application\Support\Text;

/**
 * Converts a model-proposed memory update into preferences that can be
 * deterministically tied to an exact quote from the current shopper message.
 */
final class ShoppingMemoryPolicy
{
    private const MAX_BUDGET = 1_000_000_000.0;

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function authorize(array $raw, string $currentMessage, string $evidence): array
    {
        $evidence = Text::plain($evidence, 300);
        if (!$this->isExactEvidence($currentMessage, $evidence)) {
            throw new \InvalidArgumentException(
                'Shopping-memory evidence must be an exact quote from the current user message.'
            );
        }

        $allowed = array_flip(array('budget_min', 'budget_max', 'categories', 'attributes', 'notes'));
        foreach (array_keys($raw) as $key) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new \InvalidArgumentException('Shopping memory contains an unsupported preference field.');
            }
        }

        $clean = array();
        foreach (array('budget_min', 'budget_max') as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            if (!is_int($raw[$key]) && !is_float($raw[$key])) {
                throw new \InvalidArgumentException('A shopping-memory budget must be a JSON number.');
            }
            $value = (float) $raw[$key];
            if (!is_finite($value) || $value < 0.0 || $value > self::MAX_BUDGET) {
                throw new \InvalidArgumentException('A shopping-memory budget is outside the supported range.');
            }
            if (!$this->evidenceSupportsNumber($evidence, $value)) {
                throw new \InvalidArgumentException('A proposed budget is not stated in the quoted evidence.');
            }
            $clean[$key] = $value;
        }

        if (array_key_exists('categories', $raw)) {
            if (!is_array($raw['categories']) || !array_is_list($raw['categories']) || count($raw['categories']) > 12) {
                throw new \InvalidArgumentException('Shopping-memory categories must be a bounded list.');
            }
            $categories = array();
            foreach ($raw['categories'] as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('A shopping-memory category must be text.');
                }
                $category = Text::plain($value, 80);
                if ($category === '' || !$this->evidenceSupportsText($evidence, $category)) {
                    throw new \InvalidArgumentException('A proposed category is not stated in the quoted evidence.');
                }
                $this->assertSafe($category);
                $categories[$this->fold($category)] = $category;
            }
            if ($categories === array()) {
                throw new \InvalidArgumentException('At least one stated category is required.');
            }
            $clean['categories'] = array_values($categories);
        }

        if (array_key_exists('attributes', $raw)) {
            if (!is_array($raw['attributes']) || array_is_list($raw['attributes']) || count($raw['attributes']) > 20) {
                throw new \InvalidArgumentException('Shopping-memory attributes must be a bounded object.');
            }
            $attributes = array();
            foreach ($raw['attributes'] as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new \InvalidArgumentException('A shopping-memory attribute must contain text keys and values.');
                }
                $name = Text::plain($key, 60);
                $choice = Text::plain($value, 120);
                if ($name === '' || $choice === '' || !$this->evidenceSupportsText($evidence, $choice)) {
                    throw new \InvalidArgumentException('A proposed attribute value is not stated in the quoted evidence.');
                }
                $this->assertSafe($name);
                $this->assertSafe($choice);
                $attributes[$name] = $choice;
            }
            if ($attributes === array()) {
                throw new \InvalidArgumentException('At least one stated attribute is required.');
            }
            $clean['attributes'] = $attributes;
        }

        if (array_key_exists('notes', $raw)) {
            if (!is_string($raw['notes'])) {
                throw new \InvalidArgumentException('Shopping-memory notes must be text.');
            }
            $notes = Text::plain($raw['notes'], 500);
            if ($notes === '' || !$this->evidenceSupportsText($evidence, $notes)) {
                throw new \InvalidArgumentException('Proposed shopping notes are not stated in the quoted evidence.');
            }
            $this->assertSafe($notes);
            $clean['notes'] = $notes;
        }

        if ($clean === array()) {
            throw new \InvalidArgumentException('No supported shopping preference was provided.');
        }
        if (isset($clean['budget_min'], $clean['budget_max'])
            && $clean['budget_min'] > $clean['budget_max']) {
            throw new \InvalidArgumentException('The saved minimum budget cannot exceed the maximum budget.');
        }

        return $clean;
    }

    private function isExactEvidence(string $message, string $evidence): bool
    {
        return Text::length($evidence) >= 3
            && Text::length($evidence) <= 300
            && preg_match('/[\p{L}\p{N}]/u', $evidence) === 1
            && Text::containsExact($message, $evidence);
    }

    private function evidenceSupportsText(string $evidence, string $value): bool
    {
        if (Text::length($value) >= 3) {
            return Text::contains($evidence, $value);
        }

        return preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu',
            $evidence
        ) === 1;
    }

    private function evidenceSupportsNumber(string $evidence, float $expected): bool
    {
        foreach ($this->numbers($evidence) as $candidate) {
            $scale = max(1.0, abs($expected), abs($candidate));
            if (abs($candidate - $expected) <= $scale * 0.000001) {
                return true;
            }
        }
        return false;
    }

    /** @return list<float> */
    private function numbers(string $value): array
    {
        $value = strtr($value, array(
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            "\u{00A0}" => ' ', "\u{202F}" => ' ',
        ));
        $matched = preg_match_all('/\d(?:[\d\s,.٫٬]*\d)?/u', $value, $groups);
        if ($matched === false || $matched === 0) {
            return array();
        }

        $numbers = array();
        foreach ($groups[0] as $token) {
            $candidate = $this->parseNumberToken((string) $token);
            if ($candidate !== null && is_finite($candidate)) {
                $numbers[] = $candidate;
            }
        }

        return array_values(array_unique($numbers, SORT_REGULAR));
    }

    private function parseNumberToken(string $token): ?float
    {
        $token = preg_replace('/\s+/u', '', trim($token)) ?? '';
        if ($token === '' || preg_match('/\d/u', $token) !== 1) {
            return null;
        }

        // Arabic decimal and thousands separators have explicit semantics.
        if (str_contains($token, '٫')) {
            if (substr_count($token, '٫') !== 1) {
                return null;
            }
            [$integer, $fraction] = explode('٫', $token, 2);
            $integer = str_replace(array('٬', ',', '.'), '', $integer);
            $fraction = str_replace(array('٬', ',', '.'), '', $fraction);
            return $this->decimalParts($integer, $fraction);
        }
        $token = str_replace('٬', '', $token);

        $dotCount = substr_count($token, '.');
        $commaCount = substr_count($token, ',');
        if ($dotCount > 0 && $commaCount > 0) {
            $lastDot = strrpos($token, '.');
            $lastComma = strrpos($token, ',');
            $separator = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);
            if ($separator < 1) {
                return null;
            }
            $integer = str_replace(array('.', ','), '', substr($token, 0, $separator));
            $fraction = str_replace(array('.', ','), '', substr($token, $separator + 1));
            return $this->decimalParts($integer, $fraction);
        }

        $separator = $dotCount > 0 ? '.' : ($commaCount > 0 ? ',' : '');
        if ($separator === '') {
            return ctype_digit($token) ? (float) $token : null;
        }

        $parts = explode($separator, $token);
        if (count($parts) > 2) {
            if ($parts[0] === '' || !ctype_digit(implode('', $parts))) {
                return null;
            }
            foreach (array_slice($parts, 1) as $group) {
                if (strlen($group) !== 3) {
                    return null;
                }
            }
            return (float) implode('', $parts);
        }

        [$integer, $fraction] = $parts;
        if ($integer === '' || $fraction === '' || !ctype_digit($integer . $fraction)) {
            return null;
        }
        // A single separator followed by exactly three digits is treated as a
        // thousands separator, except for a leading zero where decimal intent
        // is conventional (for example 0.125).
        if (strlen($fraction) === 3 && ltrim($integer, '0') !== '') {
            return (float) ($integer . $fraction);
        }
        return $this->decimalParts($integer, $fraction);
    }

    private function decimalParts(string $integer, string $fraction): ?float
    {
        if ($integer === '' || $fraction === ''
            || !ctype_digit($integer . $fraction)
            || strlen($fraction) > 6) {
            return null;
        }
        return (float) ($integer . '.' . $fraction);
    }

    private function assertSafe(string $value): void
    {
        if (SensitiveData::detected($value)) {
            throw new \InvalidArgumentException(
                'Sensitive personal or credential data cannot be saved in shopping memory.'
            );
        }
    }

    private function fold(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
