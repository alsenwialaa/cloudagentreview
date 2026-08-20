<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Database;

use YassinStore\AiAssistant\Application\Contract\Clock;
use YassinStore\AiAssistant\Application\Contract\RateLimiter;

final class WpRateLimiter implements RateLimiter
{
    private const MAX_IDENTIFIER_BYTES = 512;
    private const MAX_LIMIT = 1_000_000;
    private const MAX_WINDOW_SECONDS = 2_592_000;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool
    {
        $scope = $this->scope($scope);
        $identifier = $this->identifier($identifier);
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('The rate-limit threshold is outside the supported range.');
        }
        if ($windowSeconds < 1 || $windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new \InvalidArgumentException('The rate-limit window is outside the supported range.');
        }

        global $wpdb;
        $now = $this->clock->now();
        $slot = intdiv($now->getTimestamp(), $windowSeconds);
        $hash = hash('sha256', $this->bucketMaterial($scope, $identifier, $slot));
        $endsAt = ($slot + 1) * $windowSeconds;
        $ends = $now->setTimestamp($endsAt)->format('Y-m-d H:i:s');
        $table = Schema::rateLimits();

        // LAST_INSERT_ID(expr) is connection-local. It returns the count produced
        // by this exact atomic upsert, avoiding the race where a following SELECT
        // can observe increments made by later requests and reject every contender.
        $updated = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (bucket_hash,scope,window_ends_at,hits)
                 VALUES (%s,%s,%s,LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE
                    hits = LAST_INSERT_ID(IF(hits < 4294967295, hits + 1, hits))",
                $hash,
                $scope,
                $ends
            )
        );
        if ($updated === false) {
            return false;
        }

        $rawHits = $wpdb->get_var('SELECT LAST_INSERT_ID()');
        if (!is_int($rawHits) && !(is_string($rawHits) && preg_match('/^[0-9]+$/D', $rawHits) === 1)) {
            return false;
        }
        $hits = (int) $rawHits;
        if ($hits < 1) {
            return false;
        }

        return $hits <= $limit;
    }

    public function clear(): int
    {
        global $wpdb;
        $deleted = $wpdb->query('DELETE FROM ' . Schema::rateLimits());
        if ($deleted === false) {
            throw new \RuntimeException('Unable to delete rate-limit data.');
        }
        return (int) $deleted;
    }

    public function purge(): int
    {
        global $wpdb;
        $table = Schema::rateLimits();
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE window_ends_at < %s",
                $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s')
            )
        );
        if ($deleted === false) {
            throw new \RuntimeException('Unable to purge expired rate-limit data.');
        }
        return (int) $deleted;
    }

    private function scope(string $scope): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $scope) !== 1) {
            throw new \InvalidArgumentException('The rate-limit scope is invalid.');
        }
        return $scope;
    }

    private function identifier(string $identifier): string
    {
        $bytes = strlen($identifier);
        if ($bytes < 1
            || $bytes > self::MAX_IDENTIFIER_BYTES
            || preg_match('//u', $identifier) !== 1) {
            throw new \InvalidArgumentException('The rate-limit identifier is invalid.');
        }
        return $identifier;
    }

    private function bucketMaterial(string $scope, string $identifier, int $slot): string
    {
        return 'ysai-rate-v1'
            . pack('N', strlen($scope)) . $scope
            . pack('N', strlen($identifier)) . $identifier
            . ':' . $slot;
    }
}
