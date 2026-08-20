<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartStateUncertain;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartLock;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCartGateway;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCatalogGateway;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooDatabaseCartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

/**
 * Destructive, isolated integration tests for the canonical WooCommerce cart
 * session boundary. Run only through scripts/verify-integration.sh on a
 * disposable local/development/staging WordPress installation.
 */

if (!defined('WP_CLI') || WP_CLI !== true || !class_exists('WP_CLI')) {
    throw new RuntimeException('This integration suite must run through WP-CLI.');
}
if (getenv('YSAI_INTEGRATION_ALLOW_DESTRUCTIVE') !== '1') {
    WP_CLI::error('Set YSAI_INTEGRATION_ALLOW_DESTRUCTIVE=1 to run the isolated session fault suite.');
}
$expectedVersion = trim((string) getenv('YSAI_EXPECTED_PLUGIN_VERSION'));
if ($expectedVersion === ''
    || !defined('YSAI_VERSION')
    || !hash_equals($expectedVersion, (string) YSAI_VERSION)) {
    WP_CLI::error('The active plugin version does not match the integration suite source.');
}
$expectedRoot = realpath((string) getenv('YSAI_EXPECTED_PLUGIN_ROOT'));
$activeRoot = defined('YSAI_PLUGIN_DIR') ? realpath((string) YSAI_PLUGIN_DIR) : false;
if (!is_string($expectedRoot)
    || !is_string($activeRoot)
    || !hash_equals(rtrim($expectedRoot, DIRECTORY_SEPARATOR), rtrim($activeRoot, DIRECTORY_SEPARATOR))) {
    WP_CLI::error('Run the integration suite from the exact active plugin directory being tested.');
}
$environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
if (!in_array($environment, array('local', 'development', 'staging'), true)) {
    WP_CLI::error('The WooCommerce fault suite refuses to run in the ' . $environment . ' environment.');
}
if (!defined('WC_VERSION') || version_compare((string) WC_VERSION, '11.0.1', '<')) {
    WP_CLI::error('WooCommerce 11.0.1 or later is required.');
}
foreach (array(
    'WC_Session_Handler',
    'WC_Cart',
    'WC_Product_Simple',
    WooDatabaseCartSessionPersistence::class,
    WooCartGateway::class,
) as $requiredClass) {
    if (!class_exists($requiredClass)) {
        WP_CLI::error('Required integration class is unavailable: ' . $requiredClass);
    }
}
if (!function_exists('WC') || !is_object(WC())) {
    WP_CLI::error('WooCommerce has not initialized its global container.');
}
if (getenv('YSAI_REQUIRE_EXTERNAL_OBJECT_CACHE') === '1'
    && (!function_exists('wp_using_ext_object_cache') || !wp_using_ext_object_cache())) {
    WP_CLI::error('YSAI_REQUIRE_EXTERNAL_OBJECT_CACHE=1 requires a persistent object-cache drop-in.');
}

$configuredHandler = apply_filters('woocommerce_session_handler', WC_Session_Handler::class);
if (!is_string($configuredHandler) || ltrim($configuredHandler, '\\') !== ltrim(WC_Session_Handler::class, '\\')) {
    WP_CLI::error('This suite targets the exact built-in WC_Session_Handler. Test custom handlers through their own adapter suite.');
}

global $wpdb;
if (!is_object($wpdb) || !method_exists($wpdb, 'query') || !method_exists($wpdb, 'replace')) {
    WP_CLI::error('A real WPDB database connection is required.');
}
$table = (string) $wpdb->prefix . 'woocommerce_sessions';
$tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
if (!is_string($tableExists) || $tableExists !== $table) {
    WP_CLI::error('The WooCommerce sessions table does not exist: ' . $table);
}

/** @var list<array{name:string,operation:Closure}> $tests */
$tests = array();

/** @param Closure():void $operation */
function ysai_it_test(string $name, Closure $operation): void
{
    $GLOBALS['ysai_it_tests'][] = array('name' => $name, 'operation' => $operation);
}
$GLOBALS['ysai_it_tests'] = array();

function ysai_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ysai_it_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' Expected ' . wp_json_encode($expected) . ', received ' . wp_json_encode($actual) . '.'
        );
    }
}

function ysai_it_property(object $object, string $name, mixed $value): void
{
    $reflection = new ReflectionObject($object);
    while ($reflection !== false) {
        if ($reflection->hasProperty($name)) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($object, $value);
            return;
        }
        $reflection = $reflection->getParentClass();
    }
    throw new RuntimeException('WooCommerce session property is unavailable: ' . $name);
}

/** @param array<string,mixed> $data */
function ysai_it_session(string $customerId, array $data): WC_Session_Handler
{
    $session = new WC_Session_Handler();
    ysai_it_property($session, '_customer_id', $customerId);
    ysai_it_property($session, '_data', $data);
    ysai_it_property($session, '_dirty', true);
    ysai_it_property($session, '_has_cookie', true);
    ysai_it_property($session, '_session_expiring', time() + 1800);
    ysai_it_property($session, '_session_expiration', time() + 3600);
    return $session;
}

/** @param array<string,mixed> $data */
function ysai_it_replace_session_data(WC_Session_Handler $session, array $data): void
{
    ysai_it_property($session, '_data', $data);
    ysai_it_property($session, '_dirty', true);
}

/** @param array<string,mixed> $data */
function ysai_it_seed_row(string $table, string $customerId, array $data): void
{
    global $wpdb;
    $result = $wpdb->replace(
        $table,
        array(
            'session_key' => $customerId,
            'session_value' => maybe_serialize($data),
            'session_expiry' => time() + 3600,
        ),
        array('%s', '%s', '%d')
    );
    if ($result === false) {
        throw new RuntimeException('Unable to seed the isolated WooCommerce session row: ' . (string) $wpdb->last_error);
    }
    ysai_it_delete_cache($customerId);
}

function ysai_it_cache_key(string $customerId): string
{
    if (!defined('WC_SESSION_CACHE_GROUP') || !class_exists('WC_Cache_Helper')) {
        throw new RuntimeException('WooCommerce session cache services are unavailable.');
    }
    return WC_Cache_Helper::get_cache_prefix((string) WC_SESSION_CACHE_GROUP) . $customerId;
}

function ysai_it_delete_cache(string $customerId): void
{
    wp_cache_delete(ysai_it_cache_key($customerId), (string) WC_SESSION_CACHE_GROUP);
}

/**
 * Replace exactly one WC session INSERT with either a database error or a
 * successful no-op. The no-op reproduces a silent write drop while allowing
 * WooCommerce to populate its object cache and clear its dirty flag.
 *
 * @template T
 * @param Closure():T $operation
 * @return T
 */
function ysai_it_with_write_fault(
    string $table,
    string $customerId,
    string $mode,
    Closure $operation
): mixed {
    $fired = false;
    $filter = static function (string $query) use ($table, $customerId, $mode, &$fired): string {
        if ($fired
            || stripos($query, 'INSERT INTO') === false
            || stripos($query, $table) === false
            || strpos($query, $customerId) === false) {
            return $query;
        }
        $fired = true;
        if ($mode === 'error') {
            return 'SELECT `ysai_missing_fault_column` FROM `' . str_replace('`', '``', $table) . '` LIMIT 1';
        }
        if ($mode === 'drop') {
            return 'SELECT 1 AS `ysai_silently_dropped_session_write`';
        }
        throw new RuntimeException('Unknown integration write-fault mode.');
    };

    add_filter('query', $filter, PHP_INT_MAX);
    try {
        return $operation();
    } finally {
        remove_filter('query', $filter, PHP_INT_MAX);
        if (!$fired) {
            throw new RuntimeException('The expected WooCommerce session write was not intercepted.');
        }
    }
}

function ysai_it_adapter(): WooDatabaseCartSessionPersistence
{
    return new WooDatabaseCartSessionPersistence(
        new Logger(new Settings(new SecretBox()))
    );
}

function ysai_it_delete_row(string $table, string $customerId): void
{
    global $wpdb;
    $wpdb->delete($table, array('session_key' => $customerId), array('%s'));
    ysai_it_delete_cache($customerId);
}

final class YsaiIntegrationRollbackFaultPersistence implements CartSessionPersistence
{
    public int $persistCalls = 0;

    public function __construct(
        private readonly CartSessionPersistence $delegate,
        private readonly string $table,
        private readonly string $customerId
    ) {
    }

    public function configurationStatus(): ?bool
    {
        return $this->delegate->configurationStatus();
    }

    public function read(array $keys): array
    {
        return $this->delegate->read($keys);
    }

    public function persist(): void
    {
        ++$this->persistCalls;
        if ($this->persistCalls === 2) {
            // The authorized mutation reaches the database, but the caller
            // loses the response and must attempt a rollback.
            $this->delegate->persist();
            throw new RuntimeException('Simulated lost response after the durable mutation write.');
        }
        if ($this->persistCalls === 3) {
            // The request-local cart is restored, but its rollback write is
            // replaced by a successful SELECT. Cache can contain the restored
            // value while the canonical row still contains the mutation.
            ysai_it_with_write_fault(
                $this->table,
                $this->customerId,
                'drop',
                fn (): mixed => $this->delegate->persist()
            );
            return;
        }
        $this->delegate->persist();
    }

    public function invalidateCache(): void
    {
        $this->delegate->invalidateCache();
    }
}

ysai_it_test('canonical read bypasses a divergent WooCommerce object-cache entry', static function () use ($table): void {
    $customerId = 't_' . bin2hex(random_bytes(15));
    $oldCart = array('old-line' => array('product_id' => 101, 'quantity' => 2, 'variation' => array()));
    $newCart = array('new-line' => array('product_id' => 202, 'quantity' => 9, 'variation' => array()));
    $session = ysai_it_session($customerId, array('cart' => $oldCart, 'applied_coupons' => array()));
    $previousSession = WC()->session;
    WC()->session = $session;
    try {
        ysai_it_seed_row($table, $customerId, array('cart' => $oldCart, 'applied_coupons' => array()));
        wp_cache_set(
            ysai_it_cache_key($customerId),
            array('cart' => $newCart, 'applied_coupons' => array()),
            (string) WC_SESSION_CACHE_GROUP,
            3600
        );

        ysai_it_same($newCart, $session->get_session_data()['cart'] ?? null, 'WooCommerce did not expose the divergent cache value.');
        $durable = ysai_it_adapter()->read(array('cart', 'applied_coupons'));
        ysai_it_same($oldCart, $durable['cart'] ?? null, 'The canonical adapter trusted object-cache state.');
    } finally {
        ysai_it_delete_row($table, $customerId);
        WC()->session = $previousSession;
    }
});

ysai_it_test('database write failure is rejected even after WooCommerce writes the new value to cache', static function () use ($table): void {
    $customerId = 't_' . bin2hex(random_bytes(15));
    $oldCart = array('line' => array('product_id' => 101, 'quantity' => 2, 'variation' => array()));
    $newCart = array();
    $session = ysai_it_session($customerId, array('cart' => $newCart, 'applied_coupons' => array()));
    $previousSession = WC()->session;
    WC()->session = $session;
    try {
        ysai_it_seed_row($table, $customerId, array('cart' => $oldCart, 'applied_coupons' => array()));
        $adapter = ysai_it_adapter();
        $caught = null;
        try {
            ysai_it_with_write_fault(
                $table,
                $customerId,
                'error',
                static fn (): mixed => $adapter->persist()
            );
        } catch (Throwable $error) {
            $caught = $error;
        }
        ysai_it_assert($caught instanceof RuntimeException, 'A real database write error was not rejected.');
        ysai_it_assert(
            wp_cache_get(ysai_it_cache_key($customerId), (string) WC_SESSION_CACHE_GROUP) === false,
            'The uncertain WooCommerce session cache entry was not invalidated.'
        );
        ysai_it_same($oldCart, $adapter->read(array('cart'))['cart'] ?? null, 'The failed write changed canonical session state.');
    } finally {
        ysai_it_delete_row($table, $customerId);
        WC()->session = $previousSession;
    }
});

ysai_it_test('silent database write drop is exposed by an independent canonical read', static function () use ($table): void {
    $customerId = 't_' . bin2hex(random_bytes(15));
    $oldCart = array('line' => array('product_id' => 101, 'quantity' => 2, 'variation' => array()));
    $newCart = array();
    $session = ysai_it_session($customerId, array('cart' => $newCart, 'applied_coupons' => array()));
    $previousSession = WC()->session;
    WC()->session = $session;
    try {
        ysai_it_seed_row($table, $customerId, array('cart' => $oldCart, 'applied_coupons' => array()));
        $adapter = ysai_it_adapter();
        ysai_it_with_write_fault(
            $table,
            $customerId,
            'drop',
            static fn (): mixed => $adapter->persist()
        );

        ysai_it_same($newCart, $session->get_session_data()['cart'] ?? null, 'WooCommerce cache did not receive the silently dropped value.');
        $adapter->invalidateCache();
        ysai_it_same($oldCart, $adapter->read(array('cart'))['cart'] ?? null, 'The separate canonical read failed to expose the dropped write.');
    } finally {
        ysai_it_delete_row($table, $customerId);
        WC()->session = $previousSession;
    }
});

ysai_it_test('gateway classifies a dropped durable rollback as CartStateUncertain', static function () use ($table): void {
    $customerId = 't_' . bin2hex(random_bytes(15));
    $session = ysai_it_session($customerId, array());
    $previousSession = WC()->session;
    $previousCart = WC()->cart;
    $previousCustomer = WC()->customer;
    $productId = 0;
    WC()->session = $session;
    try {
        if (!is_object(WC()->customer)) {
            WC()->customer = new WC_Customer(0, true);
        }
        WC()->cart = new WC_Cart();

        $product = new WC_Product_Simple();
        $product->set_name('YSAI isolated rollback integration product');
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_regular_price('10.00');
        $product->set_price('10.00');
        $product->set_tax_status('none');
        $product->set_virtual(true);
        $productId = (int) $product->save();
        ysai_it_assert($productId > 0, 'The isolated WooCommerce product could not be created.');

        $lineKey = WC()->cart->add_to_cart($productId, 2);
        ysai_it_assert(is_string($lineKey) && $lineKey !== '', 'The isolated product could not be added to the real WooCommerce cart.');
        WC()->cart->calculate_totals();
        WC()->cart->set_session();

        $delegate = ysai_it_adapter();
        $delegate->persist();
        $delegate->invalidateCache();
        $initial = $delegate->read(array('cart', 'applied_coupons', 'ysai_v2_cart_receipts'));
        ysai_it_assert(isset($initial['cart']) && is_array($initial['cart']) && count($initial['cart']) === 1, 'The initial real cart was not durable.');

        $persistence = new YsaiIntegrationRollbackFaultPersistence($delegate, $table, $customerId);
        $urls = new SameOriginUrl(home_url('/'));
        $gateway = new WooCartGateway(
            new WooCatalogGateway($urls),
            new CartLock(),
            $persistence,
            new Logger(new Settings(new SecretBox())),
            $urls
        );
        $context = new ToolContext('integration:rollback:' . bin2hex(random_bytes(8)));
        $view = $gateway->view($context);
        ysai_it_same(true, $view['mutations_allowed'] ?? null, 'The isolated real cart did not establish mutation authority.');

        $caught = null;
        try {
            $gateway->apply(
                CartPlan::fromArray(array('commands' => array(array('action' => 'clear')))),
                $context
            );
        } catch (Throwable $error) {
            $caught = $error;
        }

        ysai_it_assert($caught instanceof CartStateUncertain, 'A dropped durable rollback was not classified as CartStateUncertain.');
        ysai_it_same(2, WC()->cart->get_cart_contents_count(), 'The request-local cart was not reconstructed for safe presentation.');
        $durable = $delegate->read(array('cart'));
        ysai_it_same(array(), $durable['cart'] ?? null, 'The fault did not leave the canonical row at the committed mutation state.');
        ysai_it_same(3, $persistence->persistCalls, 'The expected journal, mutation, and rollback writes did not occur.');
    } finally {
        ysai_it_delete_row($table, $customerId);
        if ($productId > 0) {
            wp_delete_post($productId, true);
        }
        WC()->session = $previousSession;
        WC()->cart = $previousCart;
        WC()->customer = $previousCustomer;
    }
});

$passed = 0;
$failed = 0;
WP_CLI::log('Yassin AI Assistant WooCommerce session fault integration');
WP_CLI::log('Environment: ' . $environment);
WP_CLI::log('WooCommerce: ' . (string) WC_VERSION);
WP_CLI::log('Database: ' . (string) $wpdb->db_server_info());
WP_CLI::log('External object cache: ' . ((function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) ? 'yes' : 'no (WordPress runtime cache still exercised)'));

foreach ($GLOBALS['ysai_it_tests'] as $test) {
    try {
        ($test['operation'])();
        ++$passed;
        WP_CLI::log('PASS  ' . $test['name']);
    } catch (Throwable $error) {
        ++$failed;
        WP_CLI::warning('FAIL  ' . $test['name'] . ' — ' . $error->getMessage());
    }
}

if ($failed > 0) {
    WP_CLI::error($passed . ' passed, ' . $failed . ' failed.');
}
WP_CLI::success($passed . ' real WooCommerce/database/object-cache fault tests passed.');
