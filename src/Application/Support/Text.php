<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Support;

final class Text
{
    public static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $matched = preg_match_all('/./us', $value, $characters);
        return $matched === false ? strlen($value) : $matched;
    }

    public static function slice(string $value, int $start, int $length): string
    {
        $length = max(0, $length);
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }
        if (preg_match_all('/./us', $value, $characters) === false) {
            return substr($value, $start, $length);
        }
        return implode('', array_slice($characters[0], $start, $length));
    }

    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        $matched = preg_match('/' . preg_quote($needle, '/') . '/iu', $haystack);
        return $matched === 1;
    }

    public static function containsExact(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains($haystack, $needle);
    }

    public static function plain(string $value, int $maxLength): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);
        return self::slice($value, 0, max(0, $maxLength));
    }
}
