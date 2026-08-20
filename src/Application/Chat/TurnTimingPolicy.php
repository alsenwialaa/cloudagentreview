<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Chat;

/**
 * One timing contract for provider work, browser aborts, and durable turn leases.
 *
 * The browser must stop before the persisted lease can expire. Otherwise a retry
 * could reclaim work while the original tab still believes its request is live.
 */
final class TurnTimingPolicy
{
    public const RECOVERY_MARGIN_SECONDS = 15;

    public static function leaseSeconds(int $providerTimeoutSeconds, int $maxToolRounds): int
    {
        $budget = self::providerBudgetSeconds($providerTimeoutSeconds, $maxToolRounds);
        return max(120, min(1200, $budget + 60));
    }

    public static function browserTimeoutSeconds(int $providerTimeoutSeconds, int $maxToolRounds): int
    {
        $budget = self::providerBudgetSeconds($providerTimeoutSeconds, $maxToolRounds);
        $lease = self::leaseSeconds($providerTimeoutSeconds, $maxToolRounds);
        $browser = max(60, min(1185, $budget + 45));

        // Keep the invariant explicit even if bounds are changed in a future release.
        return min($browser, $lease - self::RECOVERY_MARGIN_SECONDS);
    }

    private static function providerBudgetSeconds(int $providerTimeoutSeconds, int $maxToolRounds): int
    {
        $timeout = max(10, min(90, $providerTimeoutSeconds));
        $rounds = max(2, min(10, $maxToolRounds));

        // Every agent round can call the provider, and a cart plan can require one
        // independent structured authorization call before the mutation boundary.
        return $timeout * ($rounds + 1);
    }
}
