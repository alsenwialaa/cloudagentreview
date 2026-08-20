<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

/**
 * Durable adapter for WooCommerce's built-in database session handler.
 *
 * WooCommerce's public get_session_data() method can be served from the object
 * cache, and save_data() does not expose the database query result. This
 * adapter therefore keeps WooCommerce responsible for assembling and writing
 * its complete session payload. Verification is a deliberately separate
 * read() operation against the canonical woocommerce_sessions row.
 */
final class WooDatabaseCartSessionPersistence implements CartSessionPersistence
{
    private const MAX_SESSION_ROW_BYTES = 16777216; // 16 MiB.
    private const MAX_SESSION_KEYS = 2048;
    private const MAX_SESSION_KEY_BYTES = 191;
    private const MAX_REQUESTED_KEYS = 16;
    private const MAX_UNSERIALIZE_DEPTH = 64;

    private ?string $lastCustomerId = null;

    public function __construct(private readonly Logger $logger)
    {
    }

    public function configurationStatus(): ?bool
    {
        if (!class_exists('WC_Session_Handler')) {
            return null;
        }

        $handler = \WC_Session_Handler::class;
        if (function_exists('apply_filters')) {
            $handler = apply_filters('woocommerce_session_handler', $handler);
        }

        if (is_object($handler)) {
            return $this->isBuiltInHandlerClass($handler::class);
        }
        return is_string($handler) && $this->isBuiltInHandlerClass($handler);
    }

    public function read(array $keys): array
    {
        $keys = $this->normalizeRequestedKeys($keys);
        $session = $this->session();
        return $this->readForSession($session, $keys);
    }

    public function persist(): void
    {
        $session = $this->session();
        $this->ensureWritableSession($session);
        $database = $this->database();
        $customerId = $this->customerId($session);
        $this->lastCustomerId = $customerId;

        // WPDB exposes the last database error as mutable request state. Clear
        // an unrelated earlier error before delegating to WooCommerce so a
        // successful no-op or write cannot be misclassified.
        if (property_exists($database, 'last_error')) {
            $database->last_error = '';
        }
        try {
            $session->save_data();
        } catch (\Throwable $error) {
            $this->invalidateCache();
            $this->logger->error('The built-in WooCommerce session write did not complete cleanly.', array(
                'exception' => $error::class,
            ));
            throw new \RuntimeException(
                'WooCommerce could not establish a durable cart-session write result.',
                0,
                $error
            );
        }
        $writeError = trim((string) ($database->last_error ?? ''));
        if ($writeError !== '') {
            $this->invalidateCache();
            $this->logger->error('The built-in WooCommerce session database write reported an error.', array(
                'database_error_hash' => hash('sha256', $writeError),
                'query_hash' => hash('sha256', (string) ($database->last_query ?? '')),
            ));
            throw new \RuntimeException('WooCommerce could not durably save the cart session.');
        }
    }

    public function invalidateCache(): void
    {
        $customerId = $this->lastCustomerId;
        if ($customerId === null || $customerId === '') {
            try {
                $session = $this->session();
                $customerId = $this->customerId($session);
            } catch (\Throwable) {
                return;
            }
        }

        if (!defined('WC_SESSION_CACHE_GROUP')
            || !class_exists('WC_Cache_Helper')
            || !method_exists('WC_Cache_Helper', 'get_cache_prefix')
            || !function_exists('wp_cache_delete')) {
            return;
        }

        try {
            $group = (string) constant('WC_SESSION_CACHE_GROUP');
            $prefix = (string) \WC_Cache_Helper::get_cache_prefix($group);
            if ($group !== '' && $prefix !== '') {
                wp_cache_delete($prefix . $customerId, $group);
            }
        } catch (\Throwable) {
            // Cache invalidation is best-effort. Durable verification never
            // falls back to the cache, so failure here cannot create success.
        }
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private function readForSession(object $session, array $keys): array
    {
        $database = $this->database();
        $customerId = $this->customerId($session);
        $this->lastCustomerId = $customerId;
        $table = $this->sessionTable($database);

        // Avoid misclassifying an otherwise successful canonical read because
        // a previous unrelated query left request-global WPDB error state.
        if (property_exists($database, 'last_error')) {
            $database->last_error = '';
        }

        $query = $database->prepare(
            'SELECT `session_value`, `session_expiry` FROM %i WHERE `session_key` = %s LIMIT 1',
            $table,
            $customerId
        );
        if (!is_string($query) || $query === '') {
            throw new \RuntimeException('The WooCommerce session query could not be prepared.');
        }

        $row = $database->get_row($query, 'ARRAY_A');
        $readError = trim((string) ($database->last_error ?? ''));
        if ($readError !== '') {
            $this->logger->error('The built-in WooCommerce session database read reported an error.', array(
                'database_error_hash' => hash('sha256', $readError),
                'query_hash' => hash('sha256', $query),
            ));
            throw new \RuntimeException('WooCommerce durable cart-session state could not be read.');
        }
        if ($row === null) {
            return array();
        }
        if (!is_array($row)
            || !array_key_exists('session_value', $row)
            || !array_key_exists('session_expiry', $row)
            || !is_string($row['session_value'])) {
            throw new \RuntimeException('WooCommerce returned a malformed durable session row.');
        }

        $expiry = $this->positiveInteger($row['session_expiry']);
        if ($expiry < time()) {
            throw new \RuntimeException('The durable WooCommerce cart session has expired.');
        }

        return $this->decodeSelectedValues($row['session_value'], $keys);
    }

    private function session(): object
    {
        if (!function_exists('WC')) {
            throw new \RuntimeException('WooCommerce is not available.');
        }
        $session = WC()->session ?? null;
        if (!is_object($session)) {
            throw new \RuntimeException('The WooCommerce session is unavailable.');
        }
        if (!$this->isBuiltInHandlerClass($session::class)) {
            throw new \RuntimeException(
                'The active WooCommerce session handler has no verified database persistence adapter.'
            );
        }
        foreach (array('get_customer_id', 'has_session', 'set_customer_session_cookie', 'save_data') as $method) {
            if (!method_exists($session, $method)) {
                throw new \RuntimeException('The built-in WooCommerce session contract is incomplete.');
            }
        }
        return $session;
    }

    private function ensureWritableSession(object $session): void
    {
        if ((bool) $session->has_session()) {
            return;
        }
        if (headers_sent()) {
            throw new \RuntimeException(
                'A durable WooCommerce guest session cannot be created after response headers were sent.'
            );
        }
        $session->set_customer_session_cookie(true);
        if (!(bool) $session->has_session()) {
            throw new \RuntimeException('WooCommerce could not initialize a durable guest cart session.');
        }
    }

    private function customerId(object $session): string
    {
        $customerId = trim((string) $session->get_customer_id());
        if ($customerId === ''
            || strlen($customerId) > 64
            || preg_match('/^[A-Za-z0-9_-]+$/D', $customerId) !== 1) {
            throw new \RuntimeException('The WooCommerce customer-session identifier is invalid.');
        }
        return $customerId;
    }

    private function database(): object
    {
        global $wpdb;
        if (!is_object($wpdb)
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_row')) {
            throw new \RuntimeException('The WooCommerce session database service is unavailable.');
        }
        return $wpdb;
    }

    private function sessionTable(object $database): string
    {
        $prefix = (string) ($database->prefix ?? '');
        if ($prefix === '' || preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
            throw new \RuntimeException('The WordPress database prefix is invalid for durable session access.');
        }
        return $prefix . 'woocommerce_sessions';
    }

    /** @param list<string> $keys @return list<string> */
    private function normalizeRequestedKeys(array $keys): array
    {
        if (!array_is_list($keys) || count($keys) === 0 || count($keys) > self::MAX_REQUESTED_KEYS) {
            throw new \InvalidArgumentException('The durable session key request is malformed or oversized.');
        }
        $normalized = array();
        foreach ($keys as $key) {
            if (!is_string($key)
                || $key === ''
                || strlen($key) > self::MAX_SESSION_KEY_BYTES
                || preg_match('/^[a-z0-9_-]+$/D', $key) !== 1) {
                throw new \InvalidArgumentException('A durable session key is invalid.');
            }
            $normalized[$key] = true;
        }
        return array_keys($normalized);
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private function decodeSelectedValues(string $encodedRow, array $keys): array
    {
        if ($encodedRow === '' || strlen($encodedRow) > self::MAX_SESSION_ROW_BYTES) {
            throw new \RuntimeException('The durable WooCommerce session row is empty or oversized.');
        }

        $outer = $this->decodeSerialized($encodedRow, 'session row');
        if (!is_array($outer)
            || count($outer) > self::MAX_SESSION_KEYS
            || ($outer !== array() && array_is_list($outer))) {
            throw new \RuntimeException('The durable WooCommerce session payload is malformed or oversized.');
        }
        foreach (array_keys($outer) as $storedKey) {
            if (!is_string($storedKey)
                || $storedKey === ''
                || strlen($storedKey) > self::MAX_SESSION_KEY_BYTES
                || preg_match('//u', $storedKey) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $storedKey) === 1) {
                throw new \RuntimeException('The durable WooCommerce session contains an invalid key.');
            }
        }

        $selected = array();
        foreach ($keys as $key) {
            if (!array_key_exists($key, $outer)) {
                continue;
            }
            $selected[$key] = $this->decodeMaybeSerialized($outer[$key], $key);
        }
        return $selected;
    }

    private function decodeMaybeSerialized(mixed $value, string $key): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (strlen($value) > self::MAX_SESSION_ROW_BYTES) {
            throw new \RuntimeException('A durable WooCommerce session value is oversized.');
        }
        $trimmed = trim($value);
        if (!$this->looksSerialized($trimmed)) {
            return $value;
        }
        return $this->decodeSerialized($trimmed, 'session value ' . $key);
    }

    private function decodeSerialized(string $value, string $label): mixed
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $decoded = unserialize($value, array(
                // Verification does not need executable PHP objects. Refusing
                // class instantiation avoids invoking extension wakeup hooks
                // while inspecting a durable session row. Carts that depend on
                // object semantics fail closed at signature comparison.
                'allowed_classes' => false,
                'max_depth' => self::MAX_UNSERIALIZE_DEPTH,
            ));
        } catch (\Throwable $error) {
            throw new \RuntimeException('The durable WooCommerce ' . $label . ' cannot be decoded.', 0, $error);
        } finally {
            restore_error_handler();
        }

        if ($warning !== null || ($decoded === false && $value !== 'b:0;')) {
            throw new \RuntimeException('The durable WooCommerce ' . $label . ' is not valid serialized data.');
        }
        return $decoded;
    }

    private function looksSerialized(string $value): bool
    {
        if ($value === 'N;') {
            return true;
        }
        $length = strlen($value);
        if ($length < 4 || $value[1] !== ':') {
            return false;
        }
        return match ($value[0]) {
            'a', 'O', 'C', 'E' => $value[$length - 1] === '}',
            'b', 'i', 'd' => $value[$length - 1] === ';',
            's' => $length >= 5 && $value[$length - 2] === '"' && $value[$length - 1] === ';',
            default => false,
        };
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $maximum = (string) PHP_INT_MAX;
            if (strlen($normalized) > strlen($maximum)
                || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
                throw new \RuntimeException('The durable WooCommerce session expiry is invalid.');
            }
            $integer = (int) $normalized;
        } else {
            throw new \RuntimeException('The durable WooCommerce session expiry is invalid.');
        }
        if ($integer <= 0) {
            throw new \RuntimeException('The durable WooCommerce session expiry is invalid.');
        }
        return $integer;
    }

    private function isBuiltInHandlerClass(string $class): bool
    {
        return ltrim($class, '\\') === ltrim(\WC_Session_Handler::class, '\\');
    }
}
