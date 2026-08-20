<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Catalog;

use YassinStore\AiAssistant\Application\Support\Text;

/**
 * Small, bounded and dependency-free catalog query normalizer.
 *
 * This class intentionally does not attempt linguistic stemming. It produces
 * deterministic Arabic/Latin/digit forms that can be composed with live
 * WooCommerce search while keeping the catalog gateway authoritative.
 */
final class CatalogTextNormalizer
{
    private const MAX_TEXT_LENGTH = 240;
    private const MAX_TOKENS = 24;

    public function normalize(string $text): string
    {
        $text = Text::plain($text, self::MAX_TEXT_LENGTH);
        if ($text === '') {
            return '';
        }

        $text = strtr($text, array(
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ـ' => '',
        ));
        $text = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text) ?? $text;
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim(Text::slice($text, 0, self::MAX_TEXT_LENGTH));
    }

    /** @return list<string> */
    public function tokens(string $text): array
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return array();
        }
        $parts = preg_split('/\s+/u', $normalized) ?: array();
        $tokens = array();
        foreach ($parts as $part) {
            if (!is_string($part) || $part === '' || Text::length($part) > 80) {
                continue;
            }
            if (!isset($tokens[$part])) {
                $tokens[$part] = true;
            }
            if (count($tokens) >= self::MAX_TOKENS) {
                break;
            }
        }
        return array_keys($tokens);
    }

    /** @return list<string> */
    public function transliterationVariants(string $text): array
    {
        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return array();
        }

        $variants = array();
        if (preg_match('/\p{Arabic}/u', $normalized) === 1) {
            $latin = $this->arabicToLatin($normalized);
            if ($latin !== '' && $latin !== $normalized) {
                $variants[] = $latin;
            }
        }
        // Latin-to-Arabic expansion is limited to common Arabizi digit
        // markers. Blindly transliterating ordinary English words creates
        // noisy catalog queries and weakens deterministic recall.
        if (preg_match('/[a-z]/', $normalized) === 1
            && preg_match('/[2356789]/', $normalized) === 1) {
            $arabic = $this->latinToArabic($normalized);
            if ($arabic !== '' && $arabic !== $normalized) {
                $variants[] = $arabic;
            }
        }

        return array_slice(array_values(array_unique($variants)), 0, 2);
    }

    private function arabicToLatin(string $text): string
    {
        $mapped = strtr($text, array(
            'ء' => 'a', 'ا' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th',
            'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh',
            'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's',
            'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh',
            'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm',
            'ن' => 'n', 'ه' => 'h', 'ة' => 'h', 'و' => 'w', 'ي' => 'y',
        ));
        return $this->normalize($mapped);
    }

    private function latinToArabic(string $text): string
    {
        // Longest sequences are replaced first. This is an intentionally rough
        // recall variant, never an authoritative interpretation of shopper text.
        $mapped = strtr($text, array(
            'kh' => 'خ', 'gh' => 'غ', 'sh' => 'ش', 'th' => 'ث', 'dh' => 'ذ',
            'ch' => 'تش', 'aa' => 'ا', 'ee' => 'ي', 'oo' => 'و',
            '2' => 'ء', '3' => 'ع', '5' => 'خ', '6' => 'ط', '7' => 'ح',
            '8' => 'ق', '9' => 'ص',
        ));
        $mapped = strtr($mapped, array(
            'a' => 'ا', 'b' => 'ب', 'c' => 'ك', 'd' => 'د', 'e' => 'ي',
            'f' => 'ف', 'g' => 'ج', 'h' => 'ه', 'i' => 'ي', 'j' => 'ج',
            'k' => 'ك', 'l' => 'ل', 'm' => 'م', 'n' => 'ن', 'o' => 'و',
            'p' => 'ب', 'q' => 'ق', 'r' => 'ر', 's' => 'س', 't' => 'ت',
            'u' => 'و', 'v' => 'ف', 'w' => 'و', 'x' => 'كس', 'y' => 'ي',
            'z' => 'ز',
        ));
        return $this->normalize($mapped);
    }
}
