<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooDatabaseCartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

if (!defined('WC_SESSION_CACHE_GROUP')) {
    define('WC_SESSION_CACHE_GROUP', 'woocommerce_sessions');
}
if (!class_exists('WC_Cache_Helper')) {
    final class WC_Cache_Helper
    {
        public static function get_cache_prefix(string $group): string
        {
            return 'wc-prefix-';
        }
    }
}
if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        $GLOBALS['ysai_test_deleted_cache_entries'][] = array($key, $group);
        unset($GLOBALS['ysai_test_wc_session_cache'][$group][$key]);
        return true;
    }
}

/** @return array<string,mixed> */
function encode_nested_wc_session_values(array $logicalValues): array
{
    $stored = array();
    foreach ($logicalValues as $key => $value) {
        $stored[(string) $key] = (is_array($value) || is_object($value)) ? serialize($value) : $value;
    }
    return $stored;
}

final class FakeWpdbForDurableWooSession
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public string $last_query = '';
    public int $rows_affected = 0;
    public bool $failWrites = false;
    public bool $failReads = false;
    /** @var array<string,array{session_value:string,session_expiry:string}> */
    public array $rows = array();
    private string $preparedCustomerId = '';

    public function prepare(string $query, mixed ...$arguments): string
    {
        if (count($arguments) !== 2 || !is_string($arguments[0]) || !is_string($arguments[1])) {
            return '';
        }
        $this->preparedCustomerId = $arguments[1];
        return 'DIRECT_SESSION_READ|' . $arguments[0] . '|' . $arguments[1];
    }

    public function get_row(string $query, string $output): ?array
    {
        $this->last_query = $query;
        if ($this->failReads) {
            $this->last_error = 'simulated durable read failure';
            return null;
        }
        $this->last_error = '';
        return $this->rows[$this->preparedCustomerId] ?? null;
    }

    /** @param array<string,mixed> $logicalValues */
    public function seed(string $customerId, array $logicalValues, ?int $expiry = null): void
    {
        $this->rows[$customerId] = array(
            'session_value' => serialize(encode_nested_wc_session_values($logicalValues)),
            'session_expiry' => (string) ($expiry ?? (time() + 3600)),
        );
    }

    /** @param array<string,mixed> $storedValues */
    public function write(string $customerId, array $storedValues, int $expiry): void
    {
        $this->last_query = 'INSERT_OR_UPDATE_SESSION|' . $customerId;
        if ($this->failWrites) {
            $this->last_error = 'simulated durable write failure';
            $this->rows_affected = 0;
            return;
        }
        $this->last_error = '';
        $this->rows_affected = 1;
        $this->rows[$customerId] = array(
            'session_value' => serialize($storedValues),
            'session_expiry' => (string) $expiry,
        );
    }
}

if (!class_exists('WC_Session_Handler')) {
    class WC_Session_Handler
    {
        /** @var array<string,mixed> */
        private array $data = array();
        private bool $hasSession = true;
        private int $expiry;
        public int $saveCalls = 0;
        public bool $throwAfterWrite = false;

        public function __construct(private string $customerId = 'durable-test-customer')
        {
            $this->expiry = time() + 3600;
        }

        public function get_customer_id(): string
        {
            return $this->customerId;
        }

        public function has_session(): bool
        {
            return $this->hasSession;
        }

        public function set_customer_session_cookie(bool $set): void
        {
            $this->hasSession = $set;
        }

        public function set(string $key, mixed $value): void
        {
            $this->data[$key] = (is_array($value) || is_object($value)) ? serialize($value) : $value;
        }

        /** @param array<string,mixed> $logicalValues */
        public function replaceLogicalValues(array $logicalValues): void
        {
            $this->data = encode_nested_wc_session_values($logicalValues);
        }

        public function save_data(): void
        {
            ++$this->saveCalls;
            $database = $GLOBALS['wpdb'];
            if (!$database instanceof FakeWpdbForDurableWooSession) {
                throw new RuntimeException('Unexpected test database.');
            }
            $database->write($this->customerId, $this->data, $this->expiry);

            // Mirror the default-handler failure mode: request-external cache
            // is populated even when the database write failed.
            $group = (string) WC_SESSION_CACHE_GROUP;
            $cacheKey = WC_Cache_Helper::get_cache_prefix($group) . $this->customerId;
            $GLOBALS['ysai_test_wc_session_cache'][$group][$cacheKey] = $this->data;
            if ($this->throwAfterWrite) {
                throw new RuntimeException('simulated lost write response');
            }
        }
    }
}

if (!class_exists('FakeCustomWooSessionHandler')) {
    final class FakeCustomWooSessionHandler extends WC_Session_Handler
    {
    }
}

if (!class_exists('FakeSerializedSessionWakeupProbe')) {
    final class FakeSerializedSessionWakeupProbe
    {
        public static int $wakeups = 0;
        public string $value = 'bounded';

        public function __wakeup(): void
        {
            ++self::$wakeups;
        }
    }
}

/** @param array<string,mixed> $durableValues @return array{adapter:WooDatabaseCartSessionPersistence,database:FakeWpdbForDurableWooSession,session:WC_Session_Handler} */
function build_database_cart_session_persistence(array $durableValues = array()): array
{
    unset($GLOBALS['ysai_test_filters']['woocommerce_session_handler']);
    $GLOBALS['ysai_test_deleted_cache_entries'] = array();
    $GLOBALS['ysai_test_wc_session_cache'] = array();
    $database = new FakeWpdbForDurableWooSession();
    $session = new WC_Session_Handler();
    if ($durableValues !== array()) {
        $database->seed($session->get_customer_id(), $durableValues);
    }
    $GLOBALS['wpdb'] = $database;
    $GLOBALS['ysai_fake_wc'] = (object) array('session' => $session, 'cart' => null);
    $adapter = new WooDatabaseCartSessionPersistence(
        new Logger(new Settings(new SecretBox()))
    );
    return array('adapter' => $adapter, 'database' => $database, 'session' => $session);
}

test('WooDatabaseCartSessionPersistence bypasses object cache and decodes nested WooCommerce values', static function (): void {
    $durableCart = array('line-1' => array('product_id' => 101, 'quantity' => 2, 'variation' => array()));
    $built = build_database_cart_session_persistence(array(
        'cart' => $durableCart,
        'applied_coupons' => array('SAVE10'),
        'ysai_v2_cart_receipts' => array(hash('sha256', 'turn') => array('status' => 'started')),
        'extension.namespace:key' => array('ignored' => true),
    ));
    $group = (string) WC_SESSION_CACHE_GROUP;
    $cacheKey = WC_Cache_Helper::get_cache_prefix($group) . $built['session']->get_customer_id();
    $GLOBALS['ysai_test_wc_session_cache'][$group][$cacheKey] = encode_nested_wc_session_values(array(
        'cart' => array('line-1' => array('product_id' => 101, 'quantity' => 99)),
    ));

    assert_same(true, $built['adapter']->configurationStatus());
    $fresh = $built['adapter']->read(array('cart', 'applied_coupons', 'ysai_v2_cart_receipts'));

    assert_same($durableCart, $fresh['cart']);
    assert_same(array('SAVE10'), $fresh['applied_coupons']);
    assert_true(isset($fresh['ysai_v2_cart_receipts'][hash('sha256', 'turn')]));
});

test('WooDatabaseCartSessionPersistence rejects a database write failure even when cache contains the new cart', static function (): void {
    $oldCart = array('line-1' => array('product_id' => 101, 'quantity' => 2, 'variation' => array()));
    $built = build_database_cart_session_persistence(array(
        'cart' => $oldCart,
        'applied_coupons' => array(),
        'ysai_v2_cart_receipts' => array(),
    ));
    $built['session']->replaceLogicalValues(array(
        'cart' => array(),
        'applied_coupons' => array(),
        'ysai_v2_cart_receipts' => array(),
    ));
    $built['database']->failWrites = true;

    assert_throws(
        RuntimeException::class,
        static fn () => $built['adapter']->persist(),
        'durably save'
    );

    $durable = $built['adapter']->read(array('cart'));
    assert_same($oldCart, $durable['cart']);
    assert_count_value(1, $GLOBALS['ysai_test_deleted_cache_entries']);
    assert_same(array(
        'wc-prefix-' . $built['session']->get_customer_id(),
        (string) WC_SESSION_CACHE_GROUP,
    ), $GLOBALS['ysai_test_deleted_cache_entries'][0]);
});

test('WooDatabaseCartSessionPersistence requires an independent direct read after a successful write', static function (): void {
    $built = build_database_cart_session_persistence(array(
        'cart' => array(),
        'applied_coupons' => array(),
        'ysai_v2_cart_receipts' => array(),
    ));
    $expected = array('line-2' => array('product_id' => 202, 'quantity' => 3, 'variation' => array()));
    $built['session']->replaceLogicalValues(array(
        'cart' => $expected,
        'applied_coupons' => array('NEW'),
        'ysai_v2_cart_receipts' => array(),
    ));
    $built['database']->last_error = 'stale unrelated database error';

    $built['adapter']->persist();

    // The write operation does not return state. A separate read is the only
    // operation allowed to prove what reached the canonical session row.
    $persisted = $built['adapter']->read(array('cart', 'applied_coupons'));

    assert_same($expected, $persisted['cart']);
    assert_same(array('NEW'), $persisted['applied_coupons']);
    assert_same(1, $built['session']->saveCalls);
    assert_contains('DIRECT_SESSION_READ', $built['database']->last_query);
});

test('WooDatabaseCartSessionPersistence rejects a lost write response and invalidates cache', static function (): void {
    $built = build_database_cart_session_persistence(array(
        'cart' => array(),
        'applied_coupons' => array(),
        'ysai_v2_cart_receipts' => array(),
    ));
    $expected = array('line-3' => array('product_id' => 303, 'quantity' => 1, 'variation' => array()));
    $built['session']->replaceLogicalValues(array(
        'cart' => $expected,
        'applied_coupons' => array(),
        'ysai_v2_cart_receipts' => array(),
    ));
    $built['session']->throwAfterWrite = true;

    assert_throws(
        RuntimeException::class,
        static fn () => $built['adapter']->persist(),
        'durable cart-session write result'
    );

    // The adapter never reports success from an exception path, even when the
    // row happened to commit. Its caller must verify or roll back explicitly.
    $built['session']->throwAfterWrite = false;
    assert_same($expected, $built['adapter']->read(array('cart'))['cart']);
    assert_count_value(1, $GLOBALS['ysai_test_deleted_cache_entries']);
});

test('WooDatabaseCartSessionPersistence does not instantiate serialized session classes', static function (): void {
    FakeSerializedSessionWakeupProbe::$wakeups = 0;
    $built = build_database_cart_session_persistence(array(
        'cart' => array(
            'line-object' => array(
                'product_id' => 404,
                'quantity' => 1,
                'extension_value' => new FakeSerializedSessionWakeupProbe(),
            ),
        ),
    ));

    $cart = $built['adapter']->read(array('cart'))['cart'];

    assert_same(0, FakeSerializedSessionWakeupProbe::$wakeups);
    assert_same('__PHP_Incomplete_Class', get_debug_type($cart['line-object']['extension_value']));
});

test('WooDatabaseCartSessionPersistence treats a missing durable row as an empty session', static function (): void {
    $built = build_database_cart_session_persistence();

    assert_same(array(), $built['adapter']->read(array('cart', 'applied_coupons')));
});

test('WooDatabaseCartSessionPersistence rejects custom handlers without an explicit adapter', static function (): void {
    $built = build_database_cart_session_persistence(array('cart' => array()));
    $GLOBALS['ysai_test_filters']['woocommerce_session_handler'] = array(
        static fn (mixed $handler): string => FakeCustomWooSessionHandler::class,
    );
    try {
        assert_same(false, $built['adapter']->configurationStatus());
        $GLOBALS['ysai_fake_wc']->session = new FakeCustomWooSessionHandler();
        assert_throws(
            RuntimeException::class,
            static fn () => $built['adapter']->read(array('cart')),
            'no verified database persistence adapter'
        );
    } finally {
        unset($GLOBALS['ysai_test_filters']['woocommerce_session_handler']);
    }
});

test('WooDatabaseCartSessionPersistence rejects expired, malformed, and overflowing durable rows', static function (): void {
    $expired = build_database_cart_session_persistence();
    $expired['database']->seed(
        $expired['session']->get_customer_id(),
        array('cart' => array()),
        time() - 1
    );
    assert_throws(RuntimeException::class, static fn () => $expired['adapter']->read(array('cart')), 'expired');

    $malformed = build_database_cart_session_persistence();
    $malformed['database']->rows[$malformed['session']->get_customer_id()] = array(
        'session_value' => 'not-serialized',
        'session_expiry' => (string) (time() + 3600),
    );
    assert_throws(RuntimeException::class, static fn () => $malformed['adapter']->read(array('cart')), 'valid serialized data');

    $overflow = build_database_cart_session_persistence();
    $overflow['database']->rows[$overflow['session']->get_customer_id()] = array(
        'session_value' => serialize(array()),
        'session_expiry' => str_repeat('9', 40),
    );
    assert_throws(RuntimeException::class, static fn () => $overflow['adapter']->read(array('cart')), 'expiry is invalid');
});
