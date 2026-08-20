<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

final class CartLock
{
    public function __construct(private readonly ?Logger $logger = null)
    {
    }

    /** @template T @param callable():T $operation @return T */
    public function synchronized(callable $operation): mixed
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
            throw new \RuntimeException('The database lock service is unavailable.');
        }
        $name = 'ysai:' . substr(hash('sha256', $this->identity()), 0, 48);
        $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
        if ($acquired !== 1) {
            throw new \RuntimeException('The cart is busy. Please retry.');
        }
        try {
            return $operation();
        } finally {
            try {
                $released = $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
                if ((int) $released !== 1) {
                    $this->logReleaseFailure('unexpected_result');
                }
            } catch (\Throwable $error) {
                // The protected operation may already have committed. Never
                // replace its result with a lock-release diagnostic, but make
                // the persistent-connection risk visible to operators.
                $this->logReleaseFailure($error::class);
            }
        }
    }

    public function prepareMutationIdentity(): void
    {
        $this->identity();
    }

    private function identity(): string
    {
        if (function_exists('get_current_user_id')) {
            $userId = (int) get_current_user_id();
            if ($userId > 0) {
                return 'user:' . $userId;
            }
        }
        if (!function_exists('WC')) {
            throw new \RuntimeException('A validated WooCommerce customer session is required for cart mutation locking.');
        }
        try {
            if (WC()->session === null && function_exists('wc_load_cart')) {
                wc_load_cart();
            }
            $session = WC()->session ?? null;
            if (!is_object($session)
                || !method_exists($session, 'get_customer_id')
                || !method_exists($session, 'has_session')) {
                throw new \RuntimeException('The WooCommerce customer-session contract is unavailable.');
            }
            if (!(bool) $session->has_session()) {
                if (headers_sent() || !method_exists($session, 'set_customer_session_cookie')) {
                    throw new \RuntimeException('A durable WooCommerce guest session cannot be initialized for cart locking.');
                }
                $session->set_customer_session_cookie(true);
            }
            if (!(bool) $session->has_session()) {
                throw new \RuntimeException('WooCommerce did not establish a customer session for cart locking.');
            }
            $customerId = trim((string) $session->get_customer_id());
            if ($customerId === '' || strlen($customerId) > 64
                || preg_match('/^[A-Za-z0-9_-]+$/D', $customerId) !== 1) {
                throw new \RuntimeException('The WooCommerce customer-session identifier is invalid for cart locking.');
            }
            return 'customer:' . hash('sha256', $customerId);
        } catch (\Throwable $error) {
            if ($error instanceof \RuntimeException) {
                throw $error;
            }
            throw new \RuntimeException(
                'A validated WooCommerce customer session is required for cart mutation locking.',
                0,
                $error
            );
        }
    }

    private function logReleaseFailure(string $failure): void
    {
        try {
            $this->logger?->error('Cart database lock release failed.', array(
                'failure' => $failure,
            ));
        } catch (\Throwable) {
            // Diagnostics must never mask the already-completed cart path.
        }
    }
}
