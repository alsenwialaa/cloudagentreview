<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Support;

final class SensitiveData
{
    public static function detected(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        $patterns = array(
            '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/iu',
            '/(?:\+|00)?\d[\d\s().\-]{6,}\d/u',
            '/\b(?:\d[ -]*?){13,19}\b/u',
            '/\b(?:password|passcode|api[ _-]?key|secret|otp|cvv|cvc|iban|account[ _-]?number)\b/iu',
            '/(?:كلمة\s*المرور|رمز\s*التحقق|رقم\s*البطاقة|رقم\s*الحساب|مفتاح\s*واجهة)/u',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }
        return false;
    }
}
