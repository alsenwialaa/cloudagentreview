<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Contract\TurnGuard;
use YassinStore\AiAssistant\Application\Contract\TurnLeaseLost;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationRolledBack;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartStateUncertain;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartLock;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\CartSessionPersistence;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCartGateway;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCatalogGateway;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

if (!function_exists('WC')) {
    function WC(): object
    {
        return $GLOBALS['ysai_fake_wc'];
    }
}
if (!function_exists('wc_price')) {
    function wc_price(float $price): string
    {
        return '$' . number_format($price, 2);
    }
}
if (!function_exists('get_woocommerce_currency')) {
    function get_woocommerce_currency(): string
    {
        return 'USD';
    }
}
if (!function_exists('wc_get_cart_url')) {
    function wc_get_cart_url(): string
    {
        return home_url('/cart');
    }
}
if (!function_exists('wc_get_checkout_url')) {
    function wc_get_checkout_url(): string
    {
        return home_url('/checkout');
    }
}
if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url(int $id, string $size): false
    {
        return false;
    }
}
if (!function_exists('wc_attribute_label')) {
    function wc_attribute_label(string $name): string
    {
        return $name;
    }
}
if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists(string $taxonomy): bool
    {
        return false;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 0;
    }
}

final class FakeWooProductForCart
{
    public function __construct(
        private readonly int $id,
        private readonly string $name = 'Cart product',
        private readonly float $price = 10.0
    ) {
    }

    public function get_name(): string { return $this->name; }
    public function get_image_id(): int { return 0; }
    public function get_price(): string { return (string) $this->price; }
    public function get_sku(): string { return 'SKU-' . $this->id; }
}

final class FakeWooSessionForCart
{
    /** @var array<string,mixed> */
    private array $values = array();
    /** @var array<string,mixed> */
    public array $persisted = array();
    public int $saveCalls = 0;
    public bool $freshReadsFail = false;
    public bool $dropSaves = false;
    public string $customerId = 'test-cart-customer';
    public bool $hasSession = true;
    public int $sessionCookieCalls = 0;
    /** @var list<int> */
    public array $dropOnSaveCalls = array();
    /** @var list<int> */
    public array $throwOnSaveCalls = array();
    /** @var list<int> */
    public array $throwAfterPersistOnSaveCalls = array();

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    /** @return array<string,mixed> */
    public function allValues(): array
    {
        return $this->values;
    }

    /** @return array<string,mixed> */
    public function get_session_data(): array
    {
        if ($this->freshReadsFail) {
            throw new RuntimeException('Fresh session reads are unavailable.');
        }
        return $this->persisted;
    }

    public function save_data(): void
    {
        ++$this->saveCalls;
        if (in_array($this->saveCalls, $this->throwOnSaveCalls, true)) {
            throw new RuntimeException('Session persistence failed.');
        }
        if ($this->dropSaves || in_array($this->saveCalls, $this->dropOnSaveCalls, true)) {
            return;
        }
        $this->persisted = $this->values;
        if (in_array($this->saveCalls, $this->throwAfterPersistOnSaveCalls, true)) {
            throw new RuntimeException('Session persistence response was lost after the durable write.');
        }
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
        ++$this->sessionCookieCalls;
        $this->hasSession = $set;
    }
}

final class FakeCartSessionPersistence implements CartSessionPersistence
{
    public int $invalidateCalls = 0;
    public int $persistCalls = 0;
    public int $readCalls = 0;

    public function __construct(private readonly FakeWooSessionForCart $session)
    {
    }

    public function configurationStatus(): ?bool
    {
        return true;
    }

    public function read(array $keys): array
    {
        ++$this->readCalls;
        $fresh = $this->session->get_session_data();
        return $this->select($fresh, $keys);
    }

    public function persist(): void
    {
        ++$this->persistCalls;
        $this->session->save_data();
    }

    public function invalidateCache(): void
    {
        ++$this->invalidateCalls;
    }

    /** @param list<string> $keys @return array<string,mixed> */
    private function select(array $source, array $keys): array
    {
        $selected = array();
        foreach ($keys as $key) {
            if (is_string($key) && array_key_exists($key, $source)) {
                $selected[$key] = $source[$key];
            }
        }
        return $selected;
    }
}

final class FakeWooCartForGateway
{
    /** @var array<string,array<string,mixed>> */
    public array $items = array();
    /** @var list<string> */
    public array $coupons = array();
    public int $totalsFailures = 0;
    public bool $failPresentationAfterSession = false;
    private bool $presentationFailure = false;
    private int $nextKey = 2;

    public static function withOneItem(): self
    {
        $cart = new self();
        $cart->items['line-1'] = array(
            'key' => 'line-1',
            'product_id' => 101,
            'variation_id' => 0,
            'variation' => array(),
            'quantity' => 2,
            'data' => new FakeWooProductForCart(101),
            'line_total' => 20.0,
        );
        return $cart;
    }

    /** @return array<string,array<string,mixed>> */
    public function get_cart(): array { return $this->items; }
    /** @return array<string,mixed> */
    public function get_cart_item(string $key): array { return $this->items[$key] ?? array(); }
    /** @return list<string> */
    public function get_applied_coupons(): array { return $this->coupons; }

    public function get_cart_contents_count(): int
    {
        return array_sum(array_map(static fn (array $item): int => (int) ($item['quantity'] ?? 0), $this->items));
    }

    public function get_total(string $context = 'view'): float|string
    {
        if ($this->presentationFailure) {
            throw new RuntimeException('Presentation failed after commit.');
        }
        $total = array_sum(array_map(
            static fn (array $item): float => (float) ($item['line_total'] ?? 0.0),
            $this->items
        ));
        return $context === 'edit' ? $total : wc_price($total);
    }

    public function get_cart_hash(): string
    {
        return hash('sha256', serialize(array_map(
            static fn (array $item): array => array(
                (int) ($item['product_id'] ?? 0),
                (int) ($item['variation_id'] ?? 0),
                (array) ($item['variation'] ?? array()),
                (int) ($item['quantity'] ?? 0),
            ),
            $this->items
        )));
    }

    public function empty_cart(bool $clearPersistent = true): void
    {
        $this->items = array();
        $this->coupons = array();
    }

    public function remove_cart_item(string $key): bool
    {
        if (!isset($this->items[$key])) {
            return false;
        }
        unset($this->items[$key]);
        return true;
    }

    public function set_quantity(string $key, int $quantity, bool $refreshTotals = true): bool
    {
        if (!isset($this->items[$key]) || $quantity < 0) {
            return false;
        }
        if ($quantity === 0) {
            unset($this->items[$key]);
            return true;
        }
        $this->items[$key]['quantity'] = $quantity;
        $this->items[$key]['line_total'] = 10.0 * $quantity;
        return true;
    }

    public function add_to_cart(
        int $productId,
        int $quantity,
        int $variationId = 0,
        array $variation = array(),
        array $data = array()
    ): string|false {
        $key = 'line-' . $this->nextKey++;
        $this->items[$key] = array_replace($data, array(
            'key' => $key,
            'product_id' => $productId,
            'variation_id' => $variationId,
            'variation' => $variation,
            'quantity' => $quantity,
            'data' => new FakeWooProductForCart($variationId > 0 ? $variationId : $productId),
            'line_total' => 10.0 * $quantity,
        ));
        return $key;
    }

    public function apply_coupon(string $coupon): bool
    {
        $this->coupons[] = $coupon;
        return true;
    }

    public function calculate_totals(): void
    {
        if ($this->totalsFailures > 0) {
            --$this->totalsFailures;
            throw new RuntimeException('Totals calculation failed.');
        }
        foreach ($this->items as &$item) {
            $item['line_total'] = 10.0 * (int) ($item['quantity'] ?? 0);
        }
        unset($item);
    }

    public function set_session(): void
    {
        $serialized = array();
        foreach ($this->items as $key => $item) {
            $stored = $item;
            unset($stored['data'], $stored['line_total']);
            $serialized[(string) $key] = $stored;
        }
        WC()->session->set('cart', $serialized);
        WC()->session->set('applied_coupons', $this->coupons);
        if ($this->failPresentationAfterSession) {
            $this->presentationFailure = true;
        }
    }
}

final class FakeWpdbCartLock
{
    /** @var list<string> */
    public array $queries = array();
    public int $releaseResult = 1;
    public bool $throwOnRelease = false;

    public function prepare(string $query, mixed ...$args): string
    {
        return $query . '|' . implode('|', array_map('strval', $args));
    }

    public function get_var(string $query): int
    {
        $this->queries[] = $query;
        if (str_contains($query, 'RELEASE_LOCK')) {
            if ($this->throwOnRelease) {
                throw new RuntimeException('Simulated lock release failure.');
            }
            return $this->releaseResult;
        }
        return 1;
    }
}

/** @return array<string,mixed> */
function valid_started_cart_journal_entry(int $index): array
{
    return array(
        'status' => 'started',
        'stored_at' => max(1, $index),
        'plan_fingerprint' => hash('sha256', 'plan-' . $index),
        'pre_state_signature' => hash('sha256', 'state-' . $index),
    );
}

/** @return array<string,mixed> */
function valid_completed_cart_journal_entry(int $index): array
{
    $entry = valid_started_cart_journal_entry($index);
    $entry['status'] = 'completed';
    $entry['receipt'] = array(
        'id' => sprintf('00000000-0000-4000-8000-%012x', $index),
        'message' => 'Completed cart operation ' . $index,
        'lines' => array(),
        'cart' => array('item_count' => 0),
    );
    $entry['post_state_signature'] = hash('sha256', 'post-state-' . $index);
    return $entry;
}

/** @return array<string,mixed> */
function valid_rolled_back_cart_journal_entry(int $index): array
{
    $entry = valid_started_cart_journal_entry($index);
    $entry['status'] = 'rolled_back';
    $entry['failure_code'] = 'cart_execution_failed';
    return $entry;
}

/** @return array{gateway:WooCartGateway,cart:FakeWooCartForGateway,session:FakeWooSessionForCart,persistence:FakeCartSessionPersistence} */
function build_woo_cart_gateway(FakeWooCartForGateway $cart): array
{
    $session = new FakeWooSessionForCart();
    $GLOBALS['ysai_fake_wc'] = (object) array('cart' => $cart, 'session' => $session);
    $cart->set_session();
    $session->save_data();
    $session->saveCalls = 0;
    $persistence = new FakeCartSessionPersistence($session);
    $GLOBALS['wpdb'] = new FakeWpdbCartLock();
    $urls = new SameOriginUrl(home_url('/'));
    $gateway = new WooCartGateway(
        new WooCatalogGateway($urls),
        new CartLock(),
        $persistence,
        new Logger(new Settings(new SecretBox())),
        $urls
    );
    return array(
        'gateway' => $gateway,
        'cart' => $cart,
        'session' => $session,
        'persistence' => $persistence,
    );
}

test('WooCartGateway rechecks turn ownership immediately before the cart write', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $guard = new class implements TurnGuard {
        public int $heartbeats = 0;

        /** @return array{turn_id:int,claim_version:int} */
        public function claim(): array
        {
            return array('turn_id' => 1, 'claim_version' => 1);
        }

        public function heartbeat(): void
        {
            ++$this->heartbeats;
            if ($this->heartbeats === 3) {
                throw new TurnLeaseLost('The turn was reclaimed before cart execution.');
            }
        }
    };
    $context = new ToolContext('turn:lease-before-cart-write', $guard);
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    assert_throws(TurnLeaseLost::class, static fn () => $built['gateway']->apply($plan, $context));

    assert_same(3, $guard->heartbeats);
    assert_count_value(1, $built['cart']->items);
    $restoredItem = array_values($built['cart']->items)[0];
    assert_same(2, $restoredItem['quantity']);
    assert_same(101, $restoredItem['product_id']);
    $journal = $built['session']->persisted['ysai_v2_cart_receipts'] ?? array();
    $entry = is_array($journal) ? ($journal[hash('sha256', 'turn:lease-before-cart-write')] ?? null) : null;
    assert_true(is_array($entry));
    assert_same('rolled_back', $entry['status']);
    assert_same('turn_lease_lost', $entry['failure_code']);
});

test('WooCartGateway passes the complete WooCommerce add-validation contract', static function (): void {
    reset_catalog_test_state();
    $product = new FakeWooCatalogProduct(202);
    $GLOBALS['ysai_catalog_products'][202] = $product;
    $built = build_woo_cart_gateway(new FakeWooCartForGateway());
    $catalog = catalog_gateway_for_test();
    $context = new ToolContext('turn:add-validation-contract');
    $productRef = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array(
        'action' => 'add',
        'product_ref' => $productRef,
        'quantity' => 2,
    ))));

    $observed = null;
    $GLOBALS['ysai_test_filters']['woocommerce_add_to_cart_validation'] = array(
        static function (
            bool $approved,
            int $productId,
            int $quantity,
            int $variationId,
            array $variation,
            array $cartItemData
        ) use (&$observed): bool {
            $observed = compact('approved', 'productId', 'quantity', 'variationId', 'variation', 'cartItemData');
            return $approved;
        },
    );

    try {
        $receipt = $built['gateway']->apply($plan, $context);
    } finally {
        unset($GLOBALS['ysai_test_filters']['woocommerce_add_to_cart_validation']);
    }

    assert_same(array(
        'approved' => true,
        'productId' => 202,
        'quantity' => 2,
        'variationId' => 0,
        'variation' => array(),
        'cartItemData' => array(),
    ), $observed);
    assert_same(2, $receipt->cart['item_count']);
});

test('WooCartGateway returns a verified receipt even when post-commit presentation fails', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $built['cart']->failPresentationAfterSession = true;
    $context = new ToolContext('turn:verified-presentation-fallback');
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $receipt = $built['gateway']->apply($plan, $context);

    assert_same('تم إفراغ السلة.', $receipt->message);
    assert_same(true, $receipt->cart['presentation_incomplete']);
    assert_same(0, $receipt->cart['item_count']);
    assert_same(array(), $built['cart']->items);
    assert_true($built['session']->saveCalls >= 1);
});

test('WooCartGateway rolls back a failed write and reports the original failure when rollback is verified', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:verified-rollback');
    $built['gateway']->view($context);
    $built['cart']->totalsFailures = 1;
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $error = assert_throws(RuntimeException::class, static fn () => $built['gateway']->apply($plan, $context));

    assert_false($error instanceof CartStateUncertain);
    assert_contains('Totals calculation failed', $error->getMessage());
    assert_same(2, $built['cart']->get_cart_contents_count());
    $operationHash = hash('sha256', 'turn:verified-rollback');
    assert_same('rolled_back', $built['session']->persisted['ysai_v2_cart_receipts'][$operationHash]['status']);
    assert_same(
        'cart_execution_failed',
        $built['session']->persisted['ysai_v2_cart_receipts'][$operationHash]['failure_code']
    );
});

test('WooCartGateway replays a verified rollback as a terminal failure without executing the cart again', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));
    $firstContext = new ToolContext('turn:rolled-back-replay');
    $built['gateway']->view($firstContext);
    $built['cart']->totalsFailures = 1;

    assert_throws(RuntimeException::class, static fn () => $built['gateway']->apply($plan, $firstContext));
    $saveCalls = $built['session']->saveCalls;
    $secondContext = new ToolContext('turn:rolled-back-replay');
    $built['gateway']->view($secondContext);
    $error = assert_throws(
        CartOperationRolledBack::class,
        static fn () => $built['gateway']->apply($plan, $secondContext)
    );

    assert_same('cart_execution_failed', $error->failureCode);
    assert_same($saveCalls, $built['session']->saveCalls);
    assert_same(2, $built['cart']->get_cart_contents_count());
});

test('WooCartGateway raises an explicit uncertain-state error when rollback cannot be verified', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:uncertain-rollback');
    $built['gateway']->view($context);
    $built['cart']->totalsFailures = 2;
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $error = assert_throws(CartStateUncertain::class, static fn () => $built['gateway']->apply($plan, $context));

    assert_contains('neither the result nor a complete rollback', $error->getMessage());
});

test('WooCartGateway detects a silently dropped durable mutation and verifies the rollback before returning failure', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:dropped-durable-mutation');
    $built['gateway']->view($context);
    // Save 1 starts the operation journal. Save 2 is the cart mutation and is
    // silently dropped. Save 3 durably restores and proves the original cart.
    $built['session']->dropOnSaveCalls = array(2);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $error = assert_throws(RuntimeException::class, static fn () => $built['gateway']->apply($plan, $context));

    assert_false($error instanceof CartStateUncertain);
    assert_contains('durable cart state does not match', $error->getMessage());
    assert_same(2, $built['cart']->get_cart_contents_count());
    assert_same(2, array_values($built['session']->persisted['cart'])[0]['quantity']);
    assert_true($built['persistence']->invalidateCalls >= 1);
});

test('WooCartGateway never reports rollback success when only the request-local cart was restored', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:lost-write-response-and-rollback');
    $built['gateway']->view($context);
    // Save 2 commits the empty cart but loses the response. The gateway then
    // restores the request-local cart. Save 3 silently drops that rollback,
    // leaving durable storage empty. This must be classified as uncertain.
    $built['session']->throwAfterPersistOnSaveCalls = array(2);
    $built['session']->dropOnSaveCalls = array(3);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $error = assert_throws(CartStateUncertain::class, static fn () => $built['gateway']->apply($plan, $context));

    assert_contains('complete rollback from durable storage', $error->getMessage());
    assert_same(2, $built['cart']->get_cart_contents_count());
    assert_same(array(), $built['session']->persisted['cart']);
    assert_true($built['persistence']->invalidateCalls >= 2);
    assert_true($built['persistence']->readCalls >= 4);
});

test('WooCartGateway proves rollback with a separate canonical read after the rollback write', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:independent-rollback-read');
    $built['gateway']->view($context);
    $built['cart']->totalsFailures = 1;
    $readsBefore = $built['persistence']->readCalls;
    $writesBefore = $built['persistence']->persistCalls;
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    assert_throws(RuntimeException::class, static fn () => $built['gateway']->apply($plan, $context));

    assert_same($writesBefore + 3, $built['persistence']->persistCalls);
    assert_true($built['persistence']->readCalls >= $readsBefore + 4);
    assert_same(2, array_values($built['session']->persisted['cart'])[0]['quantity']);
});


test('WooCartGateway binds a readable cart to fresh durable session state', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:durable-view');

    $view = $built['gateway']->view($context);

    assert_true($context->hasFreshCartView());
    assert_true($context->hasCartPersistenceBinding());
    assert_same($context->cartSnapshotSignature(), $context->cartPersistenceSignature());
    assert_same(true, $view['mutations_allowed']);
    assert_same('', $view['mutation_notice']);
});

test('WooCartGateway rejects a stale in-memory cart after another request changes durable state', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:stale-durable-cart');
    $built['gateway']->view($context);
    $built['session']->persisted['cart']['line-1']['quantity'] = 7;
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    assert_throws(
        RuntimeException::class,
        static fn () => $built['gateway']->apply($plan, $context),
        'changed after it was viewed'
    );
    assert_same(2, $built['cart']->get_cart_contents_count());
});

test('WooCartGateway keeps cart reading available but fails mutations closed without fresh session reads', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $built['session']->freshReadsFail = true;
    $context = new ToolContext('turn:no-durable-read');

    $view = $built['gateway']->view($context);

    assert_same(false, $view['mutations_allowed']);
    assert_true((string) $view['mutation_notice'] !== '');
    assert_false($context->hasCartPersistenceBinding());
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));
    assert_throws(
        RuntimeException::class,
        static fn () => $built['gateway']->apply($plan, $context),
        'not bound to durable'
    );
    assert_same(2, $built['cart']->get_cart_contents_count());
});

test('WooCartGateway aborts before mutation when the operation marker is not durable', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext('turn:dropped-operation-start');
    $built['gateway']->view($context);
    $built['session']->dropSaves = true;
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $error = assert_throws(RuntimeException::class, static fn () => $built['gateway']->apply($plan, $context));

    assert_contains('journal could not be durably started', $error->getMessage());
    assert_same(2, $built['cart']->get_cart_contents_count());
});

test('WooCartGateway preserves replay receipts that exist only in fresh durable session state', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $foreignKey = hash('sha256', 'turn:other-request');
    $built['session']->persisted['ysai_v2_cart_receipts'] = array(
        $foreignKey => array(
            'status' => 'started',
            'stored_at' => 1,
            'plan_fingerprint' => str_repeat('a', 64),
            'pre_state_signature' => str_repeat('b', 64),
        ),
    );
    $context = new ToolContext('turn:preserve-fresh-journal');
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $built['gateway']->apply($plan, $context);

    assert_true(isset($built['session']->persisted['ysai_v2_cart_receipts'][$foreignKey]));
    assert_same(
        'completed',
        $built['session']->persisted['ysai_v2_cart_receipts'][hash('sha256', 'turn:preserve-fresh-journal')]['status']
    );
});

test('WooCartGateway replays a completed operation without applying it again', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));
    $firstContext = new ToolContext('turn:replay-completed');
    $built['gateway']->view($firstContext);

    $first = $built['gateway']->apply($plan, $firstContext);
    $saveCallsAfterFirst = $built['session']->saveCalls;
    $secondContext = new ToolContext('turn:replay-completed');
    $built['gateway']->view($secondContext);
    $second = $built['gateway']->apply($plan, $secondContext);

    assert_same($first->id, $second->id);
    assert_same($saveCallsAfterFirst, $built['session']->saveCalls);
    assert_same(0, $built['cart']->get_cart_contents_count());
});

test('WooCartGateway blocks a retry when a verified change lacks a completed replay receipt', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));
    $firstContext = new ToolContext('turn:completion-journal-failure');
    $built['gateway']->view($firstContext);
    $built['session']->throwOnSaveCalls = array(3);

    $firstError = assert_throws(
        CartStateUncertain::class,
        static fn () => $built['gateway']->apply($plan, $firstContext)
    );

    assert_contains('replay receipt was not durably recorded', $firstError->getMessage());
    assert_same(0, $built['cart']->get_cart_contents_count());
    $operationHash = hash('sha256', 'turn:completion-journal-failure');
    assert_same('started', $built['session']->persisted['ysai_v2_cart_receipts'][$operationHash]['status']);

    $secondContext = new ToolContext('turn:completion-journal-failure');
    $built['gateway']->view($secondContext);
    $secondError = assert_throws(
        CartStateUncertain::class,
        static fn () => $built['gateway']->apply($plan, $secondContext)
    );
    assert_contains('may already have changed', $secondError->getMessage());
    assert_same(0, $built['cart']->get_cart_contents_count());
});

test('WooCartGateway bounds public line projection while preserving authoritative totals', static function (): void {
    $cart = new FakeWooCartForGateway();
    for ($index = 1; $index <= 101; ++$index) {
        $key = 'line-' . $index;
        $cart->items[$key] = array(
            'key' => $key,
            'product_id' => 1000 + $index,
            'variation_id' => 0,
            'variation' => array(),
            'quantity' => 1,
            'data' => new FakeWooProductForCart(1000 + $index, str_repeat('P', 350)),
            'line_total' => 10.0,
        );
    }

    $built = build_woo_cart_gateway($cart);
    $view = $built['gateway']->view(new ToolContext('turn:bounded-cart-view'));

    assert_count_value(100, $view['items']);
    assert_same(101, $view['line_count']);
    assert_same(101, $view['item_count']);
    assert_same(true, $view['items_truncated']);
    assert_same(300, strlen($view['items'][0]['name']));
    assert_same(1010.0, $view['total']);
});

test('WooCartGateway rejects malformed and oversized durable cart journals before mutation', static function (): void {
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $malformed = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $malformed['session']->persisted['ysai_v2_cart_receipts'] = array(
        'not-a-sha256-key' => valid_started_cart_journal_entry(1),
    );
    $malformedContext = new ToolContext('turn:malformed-journal');
    $malformed['gateway']->view($malformedContext);
    assert_throws(
        RuntimeException::class,
        static fn () => $malformed['gateway']->apply($plan, $malformedContext),
        'journal'
    );
    assert_same(2, $malformed['cart']->get_cart_contents_count());

    $oversized = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $history = array();
    for ($index = 1; $index <= 21; ++$index) {
        $history[hash('sha256', 'operation-' . $index)] = valid_started_cart_journal_entry($index);
    }
    $oversized['session']->persisted['ysai_v2_cart_receipts'] = $history;
    $oversizedContext = new ToolContext('turn:oversized-journal');
    $oversized['gateway']->view($oversizedContext);
    assert_throws(
        RuntimeException::class,
        static fn () => $oversized['gateway']->apply($plan, $oversizedContext),
        'oversized'
    );
    assert_same(2, $oversized['cart']->get_cart_contents_count());
});

test('WooCartGateway preserves unresolved journal markers while evicting completed history', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $unresolvedHash = hash('sha256', 'unresolved-operation');
    $history = array($unresolvedHash => valid_started_cart_journal_entry(1));
    for ($index = 2; $index <= 20; ++$index) {
        $history[hash('sha256', 'completed-operation-' . $index)] = valid_completed_cart_journal_entry($index);
    }
    $built['session']->persisted['ysai_v2_cart_receipts'] = $history;

    $context = new ToolContext('turn:journal-completed-eviction');
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));
    $built['gateway']->apply($plan, $context);

    $persisted = $built['session']->persisted['ysai_v2_cart_receipts'];
    assert_count_value(20, $persisted);
    assert_true(isset($persisted[$unresolvedHash]));
    assert_same('started', $persisted[$unresolvedHash]['status']);
    assert_false(isset($persisted[hash('sha256', 'completed-operation-2')]));
    assert_same(
        'completed',
        $persisted[hash('sha256', 'turn:journal-completed-eviction')]['status']
    );
});

test('WooCartGateway refuses a new mutation when unresolved journal capacity is exhausted', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $history = array();
    for ($index = 1; $index <= 20; ++$index) {
        $history[hash('sha256', 'unresolved-operation-' . $index)] = valid_started_cart_journal_entry($index);
    }
    $built['session']->persisted['ysai_v2_cart_receipts'] = $history;

    $context = new ToolContext('turn:journal-unresolved-capacity');
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    assert_throws(
        RuntimeException::class,
        static fn () => $built['gateway']->apply($plan, $context),
        'full of unresolved operations'
    );
    assert_same(2, $built['cart']->get_cart_contents_count());
    assert_count_value(20, $built['session']->persisted['ysai_v2_cart_receipts']);
    assert_false(isset(
        $built['session']->persisted['ysai_v2_cart_receipts'][hash('sha256', 'turn:journal-unresolved-capacity')]
    ));
});

test('WooCartGateway evicts terminal rolled-back history instead of exhausting unresolved capacity', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $history = array();
    for ($index = 1; $index <= 20; ++$index) {
        $history[hash('sha256', 'rolled-back-operation-' . $index)] = valid_rolled_back_cart_journal_entry($index);
    }
    $built['session']->persisted['ysai_v2_cart_receipts'] = $history;

    $context = new ToolContext('turn:journal-rolled-back-capacity');
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    $receipt = $built['gateway']->apply($plan, $context);

    assert_same(0, $receipt->cart['item_count']);
    $persisted = $built['session']->persisted['ysai_v2_cart_receipts'];
    assert_count_value(20, $persisted);
    assert_false(isset($persisted[hash('sha256', 'rolled-back-operation-1')]));
    assert_same(
        'completed',
        $persisted[hash('sha256', 'turn:journal-rolled-back-capacity')]['status']
    );
});

test('WooCartGateway validates completed journal receipts and signatures before replay', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $operationKey = 'turn:invalid-completed-journal';
    $entry = valid_started_cart_journal_entry(1);
    $entry['status'] = 'completed';
    $entry['post_state_signature'] = array('not', 'a', 'hash');
    $entry['receipt'] = array(
        'id' => YassinStore\AiAssistant\Domain\Shared\Uuid::v4(),
        'message' => 'تم.',
        'lines' => array(),
        'cart' => array('item_count' => 0),
    );
    $built['session']->persisted['ysai_v2_cart_receipts'] = array(
        hash('sha256', $operationKey) => $entry,
    );
    $context = new ToolContext($operationKey);
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    assert_throws(
        RuntimeException::class,
        static fn () => $built['gateway']->apply($plan, $context),
        'completed cart operation entry'
    );
    assert_same(2, $built['cart']->get_cart_contents_count());
});

test('WooCartGateway rejects pathological extension, variation, coupon, and line-count state', static function (): void {
    $extensionCart = FakeWooCartForGateway::withOneItem();
    $extensionCart->items['line-1']['extension'] = str_repeat('x', 1048577);
    $extension = build_woo_cart_gateway($extensionCart);
    assert_throws(
        RuntimeException::class,
        static fn () => $extension['gateway']->view(new ToolContext('turn:oversized-extension')),
        'oversized UTF-8 text'
    );

    $variationCart = FakeWooCartForGateway::withOneItem();
    $variationCart->items['line-1']['variation'] = array('attribute_color' => true);
    $variation = build_woo_cart_gateway($variationCart);
    assert_throws(
        RuntimeException::class,
        static fn () => $variation['gateway']->view(new ToolContext('turn:invalid-variation')),
        'variation data'
    );

    $couponCart = FakeWooCartForGateway::withOneItem();
    $couponCart->coupons = array(123);
    $coupon = build_woo_cart_gateway($couponCart);
    assert_throws(
        RuntimeException::class,
        static fn () => $coupon['gateway']->view(new ToolContext('turn:invalid-coupon')),
        'coupon state'
    );

    $manyLines = new FakeWooCartForGateway();
    for ($index = 1; $index <= 501; ++$index) {
        $key = 'line-' . $index;
        $manyLines->items[$key] = array(
            'key' => $key,
            'product_id' => $index,
            'variation_id' => 0,
            'variation' => array(),
            'quantity' => 1,
            'data' => new FakeWooProductForCart($index),
            'line_total' => 10.0,
        );
    }
    $bounded = build_woo_cart_gateway($manyLines);
    assert_throws(
        RuntimeException::class,
        static fn () => $bounded['gateway']->view(new ToolContext('turn:too-many-lines')),
        'safe line limit'
    );
});

test('WooCartGateway rejects invalid UTF-8 cart identities and extension state', static function (): void {
    $invalidValueCart = FakeWooCartForGateway::withOneItem();
    $invalidValueCart->items['line-1']['extension'] = "bad\xC3\x28";
    $invalidValue = build_woo_cart_gateway($invalidValueCart);
    assert_throws(
        RuntimeException::class,
        static fn () => $invalidValue['gateway']->view(new ToolContext('turn:invalid-utf8-value')),
        'UTF-8 text'
    );

    $invalidKeyCart = FakeWooCartForGateway::withOneItem();
    $line = $invalidKeyCart->items['line-1'];
    unset($invalidKeyCart->items['line-1']);
    $invalidKeyCart->items["line-\xC3\x28"] = $line;
    $invalidKey = build_woo_cart_gateway($invalidKeyCart);
    assert_throws(
        RuntimeException::class,
        static fn () => $invalidKey['gateway']->view(new ToolContext('turn:invalid-utf8-key')),
        'cart line key is invalid'
    );
});

test('WooCartGateway rejects an oversized operation identity before applying a cart change', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $context = new ToolContext(str_repeat('x', 257));
    $built['gateway']->view($context);
    $plan = CartPlan::fromArray(array('commands' => array(array('action' => 'clear'))));

    assert_throws(
        InvalidArgumentException::class,
        static fn () => $built['gateway']->apply($plan, $context),
        'operation key'
    );
    assert_same(2, $built['cart']->get_cart_contents_count());
});

test('CartLock refuses a mutation when no validated WooCommerce ownership identity exists', static function (): void {
    $cart = FakeWooCartForGateway::withOneItem();
    $built = build_woo_cart_gateway($cart);
    $built['session']->customerId = '';
    $built['session']->hasSession = false;
    $wpdb = new FakeWpdbCartLock();
    $GLOBALS['wpdb'] = $wpdb;
    $lock = new CartLock();

    $_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:42:1111:2222:3333:4444';
    $_SERVER['HTTP_USER_AGENT'] = 'attacker-agent-one';
    $_COOKIE['wp_woocommerce_session_forged'] = 'forged-one';
    assert_throws(
        RuntimeException::class,
        static fn () => $lock->synchronized(static fn (): string => 'unsafe'),
        'invalid for cart locking'
    );

    $getLocks = array_values(array_filter(
        $wpdb->queries,
        static fn (string $query): bool => str_contains($query, 'GET_LOCK')
    ));
    assert_count_value(0, $getLocks);

    unset($_COOKIE['wp_woocommerce_session_forged']);
});

test('CartLock initializes and uses a validated WooCommerce guest session identity', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $built['session']->hasSession = false;
    $built['session']->customerId = 'guest_session_123';
    $wpdb = new FakeWpdbCartLock();
    $GLOBALS['wpdb'] = $wpdb;
    $lock = new CartLock();

    $_SERVER['REMOTE_ADDR'] = '203.0.113.20';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.50';
    $_SERVER['HTTP_USER_AGENT'] = 'attacker-controlled';
    $_COOKIE['wp_woocommerce_session_forged'] = 'forged';

    assert_same('locked', $lock->synchronized(static fn (): string => 'locked'));
    assert_same(1, $built['session']->sessionCookieCalls);

    $getLocks = array_values(array_filter(
        $wpdb->queries,
        static fn (string $query): bool => str_contains($query, 'GET_LOCK')
    ));
    assert_count_value(1, $getLocks);
    $expectedLockName = 'ysai:' . substr(
        hash('sha256', 'customer:' . hash('sha256', 'guest_session_123')),
        0,
        48
    );
    assert_contains($expectedLockName, $getLocks[0]);

    unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_COOKIE['wp_woocommerce_session_forged']);
});

test('CartLock validates release results without masking a completed protected operation', static function (): void {
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $wpdb = new FakeWpdbCartLock();
    $wpdb->releaseResult = 0;
    $GLOBALS['wpdb'] = $wpdb;
    $lock = new CartLock();

    assert_same('committed', $lock->synchronized(static fn (): string => 'committed'));
    assert_count_value(2, $wpdb->queries);
    assert_contains('GET_LOCK', $wpdb->queries[0]);
    assert_contains('RELEASE_LOCK', $wpdb->queries[1]);

    $wpdb = new FakeWpdbCartLock();
    $wpdb->throwOnRelease = true;
    $GLOBALS['wpdb'] = $wpdb;
    assert_same('committed', $lock->synchronized(static fn (): string => 'committed'));
    assert_count_value(2, $wpdb->queries);
});


test('WooCartGateway resolves preserve_source replacement quantity from the fresh locked source line', static function (): void {
    reset_catalog_test_state();
    $replacement = new FakeWooCatalogProduct(202);
    $GLOBALS['ysai_catalog_products'][202] = $replacement;
    $built = build_woo_cart_gateway(FakeWooCartForGateway::withOneItem());
    $catalog = catalog_gateway_for_test();
    $context = new ToolContext('turn:preserve-source-replacement');
    $productRef = $context->registerProduct($catalog->identity($replacement), $catalog->project($replacement));
    $view = $built['gateway']->view($context);
    $targetRef = (string) $view['items'][0]['ref'];
    $plan = CartPlan::fromArray(array('commands' => array(array(
        'action' => 'replace',
        'target_ref' => $targetRef,
        'product_ref' => $productRef,
        'quantity_mode' => 'preserve_source',
    ))));

    $receipt = $built['gateway']->apply($plan, $context);

    assert_count_value(1, $built['cart']->items);
    $line = array_values($built['cart']->items)[0];
    assert_same(202, $line['product_id']);
    assert_same(2, $line['quantity']);
    assert_same(2, $receipt->lines[0]['quantity']);
    assert_same(2, $receipt->cart['item_count']);
});
