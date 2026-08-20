<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use YassinStore\AiAssistant\Application\Contract\CartGateway;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Commerce\CartAction;
use YassinStore\AiAssistant\Domain\Commerce\CartOperationRolledBack;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartQuantityMode;
use YassinStore\AiAssistant\Domain\Commerce\CartQuantityPolicy;
use YassinStore\AiAssistant\Domain\Commerce\CartReceipt;
use YassinStore\AiAssistant\Domain\Commerce\CartStateUncertain;
use YassinStore\AiAssistant\Domain\Shared\Uuid;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;

final class WooCartGateway implements CartGateway
{
    private const IDEMPOTENCY_SESSION_KEY = 'ysai_v2_cart_receipts';
    /** @var list<string> */
    private const DURABLE_SESSION_KEYS = array('cart', 'applied_coupons', self::IDEMPOTENCY_SESSION_KEY);
    private const IDEMPOTENCY_HISTORY_LIMIT = 20;
    private const MAX_OPERATION_KEY_BYTES = 256;
    private const MAX_JOURNAL_ENTRY_BYTES = 1310720; // 1.25 MiB.
    private const MAX_JOURNAL_JSON_BYTES = 4194304; // 4 MiB.
    private const MAX_VIEW_LINES = 100;
    private const MAX_SNAPSHOT_LINES = 500;
    private const MAX_COUPONS = 100;
    private const MAX_COUPON_LENGTH = 200;
    private const MAX_VARIATION_ATTRIBUTES = 100;
    private const MAX_VARIATION_KEY_BYTES = 256;
    private const MAX_VARIATION_VALUE_BYTES = 512;
    private const MAX_EXTENSION_DEPTH = 12;
    private const MAX_EXTENSION_NODES = 20000;
    private const MAX_EXTENSION_ARRAY_ITEMS = 5000;
    private const MAX_EXTENSION_KEY_BYTES = 1024;
    private const MAX_EXTENSION_STRING_BYTES = 1048576;
    private const MAX_SNAPSHOT_JSON_BYTES = 8388608;
    private const MAX_LINE_NAME_LENGTH = 300;
    private const MAX_SKU_LENGTH = 120;
    private const MAX_DISPLAY_TEXT_LENGTH = 200;
    private const MAX_VARIATION_LABELS = 12;
    private const MAX_VARIATION_TEXT_LENGTH = 120;
    private const MAX_IMAGE_URL_LENGTH = 2048;

    public function __construct(
        private readonly WooCatalogGateway $catalog,
        private readonly CartLock $lock,
        private readonly CartSessionPersistence $sessionPersistence,
        private readonly Logger $logger,
        private readonly SameOriginUrl $urls
    ) {
    }

    public function view(ToolContext $context): array
    {
        $cart = $this->cart();
        $snapshotSignature = $this->snapshotSignature($this->snapshot($cart));
        $context->beginCartSnapshot($snapshotSignature);

        $mutationNotice = '';
        $mutationsAllowed = false;
        try {
            $persistedSignature = $this->persistedCartSignature($this->freshSessionData());
            if (!hash_equals($persistedSignature, $snapshotSignature)) {
                throw new \RuntimeException(
                    'The loaded cart does not match its latest persisted session state. Refresh the request before using the cart.'
                );
            }
            $context->bindCartPersistence($persistedSignature);

            // A durable cart row is not sufficient ownership authority. The
            // lock must also be scoped to an authenticated user or a validated
            // WooCommerce customer session. Never fall back to IP addresses,
            // forwarding headers, cookie names, or User-Agent values.
            $this->lock->prepareMutationIdentity();
            $mutationsAllowed = true;
        } catch (\Throwable $error) {
            // Reading remains useful for stores with an unsupported session
            // handler, but mutations fail closed unless a persistence adapter
            // can prove a direct durable read.
            $this->sessionPersistence->invalidateCache();
            $mutationNotice = 'تعذّر إثبات جلسة سلة حديثة ومملوكة للزائر؛ العرض متاح لكن التعديل عبر المحادثة متوقف مؤقتًا.';
            $this->logger->error('Unable to bind a cart view to durable, session-owned WooCommerce state.', array(
                'exception' => $error::class,
            ));
        }

        $items = array();
        $lineCount = 0;
        foreach ($cart->get_cart() as $key => $item) {
            if (!is_array($item) || !isset($item['data']) || !is_object($item['data'])) {
                continue;
            }
            $variation = $this->variationFromItem($item);
            ++$lineCount;
            if (count($items) >= self::MAX_VIEW_LINES) {
                continue;
            }
            $product = $item['data'];
            $name = $this->lineName($product, $variation);
            $imageId = (int) $product->get_image_id();
            $image = $imageId > 0 ? wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail') : false;
            $lineTotal = $this->safeMoney($item['line_total'] ?? 0);
            $presentation = array(
                'name' => $name,
                'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
                'unit_price' => $this->safeMoney($product->get_price()),
                'line_total' => $lineTotal,
                'line_total_text' => Text::plain((string) wc_price($lineTotal), self::MAX_DISPLAY_TEXT_LENGTH),
                'image' => is_string($image) ? Text::slice($image, 0, self::MAX_IMAGE_URL_LENGTH) : '',
                'variation' => $this->variationLabels($variation),
                'sku' => method_exists($product, 'get_sku')
                    ? Text::plain((string) $product->get_sku(), self::MAX_SKU_LENGTH)
                    : '',
            );
            $authority = array(
                'key' => (string) $key,
                'product_id' => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'variation' => $variation,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'fingerprint' => $this->lineFingerprint((string) $key, $item),
            );
            $presentation['ref'] = $context->registerLine($authority, $presentation);
            $items[] = $presentation;
        }

        return array(
            'items' => $items,
            'line_count' => $lineCount,
            'items_truncated' => $lineCount > self::MAX_VIEW_LINES,
            'item_count' => max(0, (int) $cart->get_cart_contents_count()),
            'total' => $this->safeMoney($cart->get_total('edit')),
            'total_text' => Text::plain((string) $cart->get_total(), self::MAX_DISPLAY_TEXT_LENGTH),
            'currency' => get_woocommerce_currency(),
            'cart_url' => $this->urls->sanitize(wc_get_cart_url()),
            'checkout_url' => $this->urls->sanitize(wc_get_checkout_url()),
            'cart_hash' => (string) $cart->get_cart_hash(),
            'mutations_allowed' => $mutationsAllowed,
            'mutation_notice' => $mutationNotice,
        );
    }

    public function apply(CartPlan $plan, ToolContext $context): CartReceipt
    {
        return $this->lock->synchronized(function () use ($plan, $context): CartReceipt {
            $context->heartbeatTurn();
            $cart = $this->cart();
            if (!$context->hasFreshCartView() || !$context->hasCartPersistenceBinding()) {
                throw new \RuntimeException(
                    'This cart view is not bound to durable WooCommerce session state. Refresh the cart before applying changes.'
                );
            }
            $operationKey = trim($context->operationKey());
            if ($operationKey === '') {
                throw new \RuntimeException('A durable operation key is required for every cart mutation.');
            }
            $planFingerprint = $this->planFingerprint($plan);
            $freshSessionData = $this->freshSessionData();
            $persistedSignature = $this->persistedCartSignature($freshSessionData);
            $operationEntry = $this->operationEntry($freshSessionData, $operationKey);
            $prior = $this->priorReceipt(
                $operationEntry,
                $planFingerprint,
                $context,
                $cart,
                $persistedSignature
            );
            if ($prior !== null) {
                return $prior;
            }
            $this->assertStartedOperationSafe(
                $operationEntry,
                $planFingerprint,
                $persistedSignature,
                $context->operationKey()
            );

            $snapshot = $this->snapshot($cart);
            $snapshotSignature = $this->snapshotSignature($snapshot);
            if (!hash_equals($context->cartSnapshotSignature(), $snapshotSignature)
                || !hash_equals($context->cartPersistenceSignature(), $persistedSignature)
                || !hash_equals($snapshotSignature, $persistedSignature)) {
                throw new \RuntimeException(
                    'The cart changed after it was viewed. Refresh the cart before applying changes.'
                );
            }

            $resolved = $this->resolve($plan, $context, $cart);

            // Product resolution and WooCommerce extension hooks are allowed to
            // run before the write, but they are not allowed to change any part
            // of the cart behind the authorization snapshot.
            $preExecutionSnapshot = $this->snapshot($cart);
            $preExecutionSignature = $this->snapshotSignature($preExecutionSnapshot);
            if (!hash_equals($snapshotSignature, $preExecutionSignature)) {
                throw new \RuntimeException('The cart changed while the request was being prepared. Refresh the cart and try again.');
            }
            $snapshot = $preExecutionSnapshot;
            $context->heartbeatTurn();
            $startedEntry = is_array($operationEntry) && ($operationEntry['status'] ?? '') === 'started'
                ? $operationEntry
                : $this->rememberStartedOperation(
                    $operationKey,
                    $planFingerprint,
                    $preExecutionSignature
                );
            $before = $this->state($cart);

            try {
                $context->heartbeatTurn();
                $receiptLines = $this->execute($resolved, $cart);
                $cart->calculate_totals();
                $after = $this->state($cart);
                $this->verify($resolved, $before, $after, $cart);
                $postSnapshot = $this->snapshot($cart);
                $postStateSignature = $this->snapshotSignature($postSnapshot);

                // Verify the in-memory result before writing. Persistence and
                // proof are deliberately separate operations: a successful
                // write call cannot itself be treated as durable evidence.
                $cart->set_session();
                $context->heartbeatTurn();
                $durablePostSignature = $this->persistedCartSignature(
                    $this->persistAndReloadSessionData()
                );
                if (!hash_equals($postStateSignature, $durablePostSignature)) {
                    throw new \RuntimeException(
                        'The durable cart state does not match the authorized in-memory result.'
                    );
                }
            } catch (\Throwable $error) {
                $this->sessionPersistence->invalidateCache();
                if (!$this->restore($cart, $snapshot)) {
                    $this->logger->error('Cart rollback could not be durably verified.', array(
                        'exception' => $error::class,
                        'operation_hash' => hash('sha256', $context->operationKey()),
                    ));
                    throw new CartStateUncertain(
                        'The cart write was attempted, but neither the result nor a complete rollback from durable storage could be verified.',
                        0,
                        $error
                    );
                }
                try {
                    $this->rememberRolledBackOperation(
                        $operationKey,
                        $startedEntry,
                        $this->rollbackFailureCode($error)
                    );
                } catch (\Throwable $journalError) {
                    // The cart itself is known to be restored. A journal
                    // transition failure must not turn that verified state
                    // into a false cart-uncertainty warning or mask the
                    // original execution error. The existing durable started
                    // marker remains the conservative replay fallback.
                    $this->restoreOperationEntryInMemory($operationKey, $startedEntry);
                    $this->logger->error('Unable to persist a verified cart rollback in the operation journal.', array(
                        'exception' => $journalError::class,
                        'operation_hash' => hash('sha256', $operationKey),
                    ));
                }
                throw $error;
            }


            $message = $this->receiptMessage($receiptLines);
            $cartView = $this->viewAfterVerifiedCommit($cart, $context, $postSnapshot, $postStateSignature);
            $receipt = new CartReceipt(
                Uuid::v4(),
                $message,
                $receiptLines,
                $cartView
            );
            try {
                $this->rememberCompletedOperation(
                    $operationKey,
                    $startedEntry,
                    $receipt,
                    $postStateSignature
                );
            } catch (\Throwable $error) {
                $this->restoreOperationEntryInMemory($operationKey, $startedEntry);
                $this->logger->error('Unable to persist the completed cart operation journal.', array(
                    'exception' => $error::class,
                    'operation_hash' => hash('sha256', $operationKey),
                ));
                throw new CartStateUncertain(
                    'The cart change was durably verified, but its replay receipt was not durably recorded. Inspect the cart before retrying.',
                    0,
                    $error
                );
            }
            return $receipt;
        });
    }

    /**
     * A presentation failure after a verified commit must not convert a
     * successful write into a retryable failure. Return a bounded summary and
     * retain the independently computed post-state fingerprint instead.
     *
     * @param array{items:list<array<string,mixed>>,coupons:list<string>} $postSnapshot
     * @return array<string,mixed>
     */
    private function viewAfterVerifiedCommit(
        object $cart,
        ToolContext $context,
        array $postSnapshot,
        string $postStateSignature
    ): array {
        try {
            return $this->view($context);
        } catch (\Throwable $error) {
            $this->logger->error('Unable to build the full cart presentation after a verified commit.', array(
                'exception' => $error::class,
                'operation_hash' => hash('sha256', $context->operationKey()),
            ));
            $context->beginCartSnapshot($postStateSignature);
            $context->bindCartPersistence($postStateSignature);
            return $this->fallbackCartView($cart, $postSnapshot);
        }
    }

    /**
     * @param array{items:list<array<string,mixed>>,coupons:list<string>} $snapshot
     * @return array<string,mixed>
     */
    private function fallbackCartView(object $cart, array $snapshot): array
    {
        $items = array();
        $itemCount = 0;
        foreach ($snapshot['items'] as $item) {
            $itemCount += max(0, (int) ($item['quantity'] ?? 0));
        }
        foreach (array_slice($snapshot['items'], 0, self::MAX_VIEW_LINES) as $item) {
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $productId = max(0, (int) ($item['variation_id'] ?? 0));
            if ($productId === 0) {
                $productId = max(0, (int) ($item['product_id'] ?? 0));
            }
            $items[] = array(
                'name' => $productId > 0 ? sprintf('منتج #%d', $productId) : 'منتج في السلة',
                'quantity' => $quantity,
                'unit_price' => 0.0,
                'line_total' => 0.0,
                'line_total_text' => '',
                'image' => '',
                'variation' => array(),
                'sku' => '',
                'ref' => '',
            );
        }

        $total = 0.0;
        $totalText = '';
        $cartHash = '';
        try {
            $total = (float) $cart->get_total('edit');
        } catch (\Throwable) {
            // The structural snapshot above remains authoritative.
        }
        try {
            $totalText = html_entity_decode(
                wp_strip_all_tags((string) $cart->get_total()),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        } catch (\Throwable) {
            // Leave the display total blank rather than guessing.
        }
        try {
            $cartHash = (string) $cart->get_cart_hash();
        } catch (\Throwable) {
            // A cart hash is presentation-only at this point.
        }

        $currency = '';
        $cartUrl = '';
        $checkoutUrl = '';
        try {
            $currency = function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
            $cartUrl = function_exists('wc_get_cart_url') ? $this->urls->sanitize((string) wc_get_cart_url()) : '';
            $checkoutUrl = function_exists('wc_get_checkout_url') ? $this->urls->sanitize((string) wc_get_checkout_url()) : '';
        } catch (\Throwable) {
            // Keep optional links and currency empty.
        }

        return array(
            'items' => $items,
            'line_count' => count($snapshot['items']),
            'items_truncated' => count($snapshot['items']) > self::MAX_VIEW_LINES,
            'item_count' => $itemCount,
            'total' => $total,
            'total_text' => $totalText,
            'currency' => $currency,
            'cart_url' => $cartUrl,
            'checkout_url' => $checkoutUrl,
            'cart_hash' => $cartHash,
            'presentation_incomplete' => true,
            'notice' => 'تم التحقق من تعديل السلة، لكن تعذّر تحميل بعض تفاصيل العرض. حدّث الصفحة لعرضها كاملة.',
            'mutations_allowed' => true,
            'mutation_notice' => '',
        );
    }

    /** @return \WC_Cart */
    private function cart(): object
    {
        if (!function_exists('WC')) {
            throw new \RuntimeException('WooCommerce is not available.');
        }
        if ((WC()->session === null || WC()->cart === null) && function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (WC()->session === null || WC()->cart === null) {
            throw new \RuntimeException('The WooCommerce cart session is unavailable.');
        }
        if (!is_object(WC()->session)
            || !method_exists(WC()->session, 'get')
            || !method_exists(WC()->session, 'set')) {
            throw new \RuntimeException('The WooCommerce session does not provide the required public contract.');
        }
        return WC()->cart;
    }

    /** @return list<array<string,mixed>> */
    private function resolve(CartPlan $plan, ToolContext $context, object $cart): array
    {
        $resolved = array();
        $sourceIdentities = array();
        $destinationIdentities = array();

        foreach ($plan->commands as $command) {
            $entry = array(
                'action' => $command->action->value,
                'quantity' => $command->quantity,
                'quantity_mode' => $command->quantityMode->value,
            );

            if ($command->action === CartAction::Clear) {
                $resolved[] = $entry;
                continue;
            }

            if ($command->targetRef !== null) {
                $target = $context->line($command->targetRef);
                $authority = (array) $target['authority'];
                $key = (string) ($authority['key'] ?? '');
                $current = $cart->get_cart_item($key);
                if (!is_array($current) || $current === array()) {
                    throw new \RuntimeException('The selected cart line no longer exists.');
                }
                $fingerprint = $this->lineFingerprint($key, $current);
                if (!hash_equals((string) ($authority['fingerprint'] ?? ''), $fingerprint)) {
                    throw new \RuntimeException('The cart changed before the request could be applied.');
                }
                $entry['target_key'] = $key;
                $entry['target_name'] = (string) ($target['presentation']['name'] ?? '');
                $entry['source_identity'] = $this->identityKeyFromItem($current);
                $entry['source_quantity'] = (int) ($current['quantity'] ?? 0);
                if ($command->action === CartAction::Replace
                    && $command->quantityMode === CartQuantityMode::PreserveSource) {
                    if ($entry['source_quantity'] < 1 || $entry['source_quantity'] > 1000) {
                        throw new \RuntimeException('The source cart quantity cannot be preserved within the supported bounds.');
                    }
                    // Resolve the quantity from the canonical source line only
                    // after the named cart lock and freshness checks are held.
                    $entry['quantity'] = $entry['source_quantity'];
                }
                $this->assertQuantityChangeAllowed($command->action, (int) ($entry['quantity'] ?? 0), $key, $current);
                $sourceIdentities[$entry['source_identity']] = true;
            }

            if ($command->productRef !== null) {
                $productEntry = $context->product($command->productRef);
                $identity = (array) $productEntry['identity'];
                $product = $this->catalog->product((int) ($identity['id'] ?? 0));
                if ($product === null) {
                    throw new \RuntimeException('The selected product is no longer available.');
                }
                $currentIdentity = $this->catalog->identity($product);
                if (!hash_equals((string) ($identity['fingerprint'] ?? ''), $currentIdentity['fingerprint'])) {
                    throw new \RuntimeException('The product changed before the request could be applied.');
                }
                $productId = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
                $variationId = $product->is_type('variation') ? (int) $product->get_id() : 0;
                $variation = $variationId > 0 ? array_map('strval', (array) $product->get_variation_attributes()) : array();
                $destinationIdentity = $this->identityKey($productId, $variationId, $variation);
                $existingQuantity = $this->identityQuantity($cart, $destinationIdentity);
                $add = $this->addParameters($product, (int) ($entry['quantity'] ?? 1), $existingQuantity);
                $entry['product'] = $product;
                $entry['product_name'] = (string) ($productEntry['card']['name'] ?? $product->get_name());
                $entry['add'] = $add;
                $entry['destination_identity'] = $destinationIdentity;
                if (isset($destinationIdentities[$entry['destination_identity']])) {
                    throw new \RuntimeException('The same destination product cannot be changed twice in one request.');
                }
                $destinationIdentities[$entry['destination_identity']] = true;
            }

            if (($entry['action'] ?? '') === CartAction::Replace->value
                && ($entry['source_identity'] ?? '') === ($entry['destination_identity'] ?? '')) {
                throw new \RuntimeException('A replacement must select a different product or variation.');
            }

            $resolved[] = $entry;
        }

        if (count($resolved) > 1 && array_intersect_key($sourceIdentities, $destinationIdentities) !== array()) {
            throw new \RuntimeException('This cart plan is order-dependent. Use one replacement command for each source line.');
        }

        return $resolved;
    }

    /** @param array<string,mixed> $item */
    private function assertQuantityChangeAllowed(
        CartAction $action,
        int $deltaOrQuantity,
        string $cartItemKey,
        array $item
    ): void {
        if (!in_array($action, array(CartAction::SetQuantity, CartAction::Increment, CartAction::Decrement), true)) {
            return;
        }

        $current = (int) ($item['quantity'] ?? 0);
        $requested = match ($action) {
            CartAction::SetQuantity => $deltaOrQuantity,
            CartAction::Increment => $current + $deltaOrQuantity,
            CartAction::Decrement => max(0, $current - $deltaOrQuantity),
            default => $current,
        };
        if ($requested === 0) {
            return;
        }

        $product = $item['data'] ?? null;
        if (!is_object($product)) {
            throw new \RuntimeException('The cart product is unavailable for quantity validation.');
        }

        $minimum = method_exists($product, 'get_min_purchase_quantity')
            ? max(1, (int) $product->get_min_purchase_quantity())
            : 1;
        $maximum = method_exists($product, 'get_max_purchase_quantity')
            ? (int) $product->get_max_purchase_quantity()
            : -1;
        $increasing = $requested > $current;
        $increaseAllowed = !$increasing || (
            method_exists($product, 'is_purchasable')
            && method_exists($product, 'is_in_stock')
            && method_exists($product, 'has_enough_stock')
            && $product->is_purchasable()
            && $product->is_in_stock()
            && $product->has_enough_stock($requested)
        );
        $extensionApproved = function_exists('apply_filters')
            ? (bool) apply_filters('woocommerce_update_cart_validation', true, $cartItemKey, $item, $requested)
            : true;

        CartQuantityPolicy::assertAllowed(
            $current,
            $requested,
            method_exists($product, 'is_sold_individually') && $product->is_sold_individually(),
            $minimum,
            $maximum,
            $increaseAllowed,
            $extensionApproved
        );
    }

    /** @return array{product_id:int,variation_id:int,variation:array<string,string>,quantity:int} */
    private function addParameters(object $product, int $quantity, int $existingQuantity): array
    {
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            throw new \RuntimeException('The selected product cannot currently be purchased.');
        }
        if ($product->is_type('variable')) {
            throw new \RuntimeException('Choose an exact variation before adding this product.');
        }
        if ($product->is_type(array('external', 'grouped'))) {
            throw new \RuntimeException('This product type cannot be added through chat.');
        }
        $finalQuantity = $existingQuantity + $quantity;
        if (!$product->has_enough_stock($finalQuantity)) {
            throw new \RuntimeException('The requested quantity is not available.');
        }

        $variationId = $product->is_type('variation') ? (int) $product->get_id() : 0;
        $productId = $variationId > 0 ? (int) $product->get_parent_id() : (int) $product->get_id();
        $variation = $variationId > 0 ? array_map('strval', (array) $product->get_variation_attributes()) : array();
        $minimum = method_exists($product, 'get_min_purchase_quantity')
            ? max(1, (int) $product->get_min_purchase_quantity())
            : 1;
        $maximum = method_exists($product, 'get_max_purchase_quantity')
            ? (int) $product->get_max_purchase_quantity()
            : -1;
        $extensionApproved = function_exists('apply_filters')
            ? (bool) apply_filters(
                'woocommerce_add_to_cart_validation',
                true,
                $productId,
                $quantity,
                $variationId,
                $variation,
                array()
            )
            : true;

        CartQuantityPolicy::assertAllowed(
            $existingQuantity,
            $finalQuantity,
            $product->is_sold_individually(),
            $minimum,
            $maximum,
            true,
            $extensionApproved
        );

        return array(
            'product_id' => $productId,
            'variation_id' => $variationId,
            'variation' => $variation,
            'quantity' => $quantity,
        );
    }

    /** @param list<array<string,mixed>> $resolved @return list<array<string,mixed>> */
    private function execute(array $resolved, object $cart): array
    {
        $lines = array();
        foreach ($resolved as $command) {
            $action = CartAction::from((string) $command['action']);
            $quantity = (int) ($command['quantity'] ?? 0);
            switch ($action) {
                case CartAction::Add:
                    $this->add($cart, (array) $command['add']);
                    $lines[] = array('action' => $action->value, 'name' => (string) $command['product_name'], 'quantity' => $quantity);
                    break;

                case CartAction::SetQuantity:
                    if (!$cart->set_quantity((string) $command['target_key'], $quantity, false)) {
                        throw new \RuntimeException('WooCommerce rejected the quantity change.');
                    }
                    $lines[] = array('action' => $action->value, 'name' => (string) $command['target_name'], 'quantity' => $quantity);
                    break;

                case CartAction::Increment:
                    $next = (int) $command['source_quantity'] + $quantity;
                    if (!$cart->set_quantity((string) $command['target_key'], $next, false)) {
                        throw new \RuntimeException('WooCommerce rejected the quantity increase.');
                    }
                    $lines[] = array('action' => $action->value, 'name' => (string) $command['target_name'], 'quantity' => $quantity, 'final_quantity' => $next);
                    break;

                case CartAction::Decrement:
                    $next = max(0, (int) $command['source_quantity'] - $quantity);
                    if ($next === 0) {
                        if (!$cart->remove_cart_item((string) $command['target_key'])) {
                            throw new \RuntimeException('WooCommerce rejected the line removal.');
                        }
                    } elseif (!$cart->set_quantity((string) $command['target_key'], $next, false)) {
                        throw new \RuntimeException('WooCommerce rejected the quantity reduction.');
                    }
                    $lines[] = array('action' => $action->value, 'name' => (string) $command['target_name'], 'quantity' => $quantity, 'final_quantity' => $next);
                    break;

                case CartAction::Remove:
                    if (!$cart->remove_cart_item((string) $command['target_key'])) {
                        throw new \RuntimeException('WooCommerce rejected the line removal.');
                    }
                    $lines[] = array('action' => $action->value, 'name' => (string) $command['target_name']);
                    break;

                case CartAction::Replace:
                    if (!$cart->remove_cart_item((string) $command['target_key'])) {
                        throw new \RuntimeException('WooCommerce rejected the replacement source removal.');
                    }
                    $this->add($cart, (array) $command['add']);
                    $lines[] = array(
                        'action' => $action->value,
                        'name' => (string) $command['target_name'],
                        'replacement' => (string) $command['product_name'],
                        'quantity' => $quantity,
                    );
                    break;

                case CartAction::Clear:
                    $cart->empty_cart(false);
                    $lines[] = array('action' => $action->value);
                    break;
            }
        }
        return $lines;
    }

    /** @param array<string,mixed> $add */
    private function add(object $cart, array $add): void
    {
        $key = $cart->add_to_cart(
            (int) $add['product_id'],
            (int) $add['quantity'],
            (int) $add['variation_id'],
            (array) $add['variation'],
            array()
        );
        if (!is_string($key) || $key === '') {
            throw new \RuntimeException('WooCommerce rejected the product addition.');
        }
    }

    /** @param list<array<string,mixed>> $resolved @param array<string,mixed> $before @param array<string,mixed> $after */
    private function verify(array $resolved, array $before, array $after, object $cart): void
    {
        $expected = $before['quantities'];
        $cleared = false;
        foreach ($resolved as $command) {
            $action = CartAction::from((string) $command['action']);
            $quantity = (int) ($command['quantity'] ?? 0);
            if ($action === CartAction::Clear) {
                $expected = array();
                $cleared = true;
                continue;
            }

            $targetKey = (string) ($command['target_key'] ?? '');
            $sourceIdentity = (string) ($command['source_identity'] ?? '');
            $sourceQuantity = (int) ($command['source_quantity'] ?? 0);
            if ($action === CartAction::Remove || $action === CartAction::Replace) {
                if (isset($after['lines'][$targetKey])) {
                    throw new \RuntimeException('A removed cart line is still present.');
                }
            }
            if ($action === CartAction::SetQuantity) {
                if ((int) ($after['lines'][$targetKey]['quantity'] ?? -1) !== $quantity) {
                    throw new \RuntimeException('The cart quantity verification failed.');
                }
                $expected[$sourceIdentity] = (int) ($expected[$sourceIdentity] ?? 0) - $sourceQuantity + $quantity;
            }
            if ($action === CartAction::Increment) {
                $expectedLineQuantity = $sourceQuantity + $quantity;
                if ((int) ($after['lines'][$targetKey]['quantity'] ?? -1) !== $expectedLineQuantity) {
                    throw new \RuntimeException('The cart quantity increase verification failed.');
                }
                $expected[$sourceIdentity] = (int) ($expected[$sourceIdentity] ?? 0) + $quantity;
            }
            if ($action === CartAction::Decrement) {
                $expectedLineQuantity = max(0, $sourceQuantity - $quantity);
                $actualLineQuantity = isset($after['lines'][$targetKey]) ? (int) $after['lines'][$targetKey]['quantity'] : 0;
                if ($actualLineQuantity !== $expectedLineQuantity) {
                    throw new \RuntimeException('The cart quantity reduction verification failed.');
                }
                $expected[$sourceIdentity] = (int) ($expected[$sourceIdentity] ?? 0) - min($sourceQuantity, $quantity);
            }
            if ($action === CartAction::Remove) {
                $expected[$sourceIdentity] = (int) ($expected[$sourceIdentity] ?? 0) - $sourceQuantity;
            }
            if ($action === CartAction::Add || $action === CartAction::Replace) {
                $identity = (string) $command['destination_identity'];
                $expectedDestination = (int) ($before['quantities'][$identity] ?? 0) + $quantity;
                if ((int) ($after['quantities'][$identity] ?? 0) !== $expectedDestination) {
                    throw new \RuntimeException('The added product quantity verification failed.');
                }
                $expected[$identity] = (int) ($expected[$identity] ?? 0) + $quantity;
                if ($action === CartAction::Replace) {
                    $expected[$sourceIdentity] = (int) ($expected[$sourceIdentity] ?? 0) - $sourceQuantity;
                }
            }
        }

        $expected = array_filter($expected, static fn (int $quantity): bool => $quantity > 0);
        $actual = array_filter($after['quantities'], static fn (int $quantity): bool => $quantity > 0);
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new \RuntimeException('The cart changed outside the authorized plan.');
        }

        $beforeCoupons = $before['coupons'];
        $afterCoupons = $after['coupons'];
        sort($beforeCoupons);
        sort($afterCoupons);
        if (($cleared && $afterCoupons !== array()) || (!$cleared && $beforeCoupons !== $afterCoupons)) {
            throw new \RuntimeException('The cart coupon state changed outside the authorized plan.');
        }
        if ($cleared && $cart->get_cart_contents_count() !== 0) {
            throw new \RuntimeException('The cart did not reach the verified empty state.');
        }
    }

    /** @return array<string,mixed> */
    private function freshSessionData(): array
    {
        return $this->sessionPersistence->read(self::DURABLE_SESSION_KEYS);
    }

    /** @param array<string,mixed> $sessionData */
    private function persistedCartSignature(array $sessionData): string
    {
        $rawCart = $sessionData['cart'] ?? array();
        $rawCoupons = $sessionData['applied_coupons'] ?? array();
        if (!is_array($rawCart) || !is_array($rawCoupons)) {
            throw new \RuntimeException('The persisted WooCommerce cart state is malformed.');
        }
        if (count($rawCart) > self::MAX_SNAPSHOT_LINES) {
            throw new \RuntimeException('The persisted cart exceeds the safe line limit.');
        }

        $items = array();
        foreach ($rawCart as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('A persisted WooCommerce cart line is malformed.');
            }
            $variation = $this->variationFromItem($item);
            $data = $item;
            foreach (array(
                'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash',
                'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax'
            ) as $key) {
                unset($data[$key]);
            }
            $items[] = array(
                'product_id' => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'variation' => $variation,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'data' => $data,
            );
        }

        return $this->snapshotSignature(array(
            'items' => $items,
            'coupons' => $this->normalizeCoupons($rawCoupons),
        ));
    }

    /** @return array{lines:array<string,array<string,mixed>>,quantities:array<string,int>,coupons:list<string>} */
    private function state(object $cart): array
    {
        $lines = array();
        $quantities = array();
        foreach ($cart->get_cart() as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $identity = $this->identityKeyFromItem($item);
            $quantity = (int) ($item['quantity'] ?? 0);
            $lines[(string) $key] = array('quantity' => $quantity, 'identity' => $identity);
            $quantities[$identity] = ($quantities[$identity] ?? 0) + $quantity;
        }
        return array(
            'lines' => $lines,
            'quantities' => $quantities,
            'coupons' => array_values(array_unique(array_map('strval', $cart->get_applied_coupons()))),
        );
    }

    /** @return array{items:list<array<string,mixed>>,coupons:list<string>} */
    private function snapshot(object $cart): array
    {
        $rawItems = $cart->get_cart();
        if (!is_array($rawItems)) {
            throw new \RuntimeException('WooCommerce returned a malformed cart.');
        }
        if (count($rawItems) > self::MAX_SNAPSHOT_LINES) {
            throw new \RuntimeException('The cart exceeds the safe line limit for verified assistant operations.');
        }

        $items = array();
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $variation = $this->variationFromItem($item);
            $data = $item;
            foreach (array('key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax') as $key) {
                unset($data[$key]);
            }
            $items[] = array(
                'product_id' => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'variation' => $variation,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'data' => $data,
            );
        }
        $coupons = $cart->get_applied_coupons();
        if (!is_array($coupons)) {
            throw new \RuntimeException('WooCommerce returned malformed coupon state.');
        }
        return array('items' => $items, 'coupons' => $this->normalizeCoupons($coupons));
    }

    /** @param array{items:list<array<string,mixed>>,coupons:list<string>} $snapshot */
    private function restore(object $cart, array $snapshot): bool
    {
        try {
            $expectedSignature = $this->snapshotSignature($snapshot);
            $cart->empty_cart(false);
            foreach ($snapshot['items'] as $item) {
                $key = $cart->add_to_cart(
                    (int) $item['product_id'],
                    (int) $item['quantity'],
                    (int) $item['variation_id'],
                    (array) $item['variation'],
                    (array) $item['data']
                );
                if (!is_string($key) || $key === '') {
                    return false;
                }
            }
            foreach ($snapshot['coupons'] as $coupon) {
                if (!$cart->apply_coupon($coupon)) {
                    return false;
                }
            }
            $cart->calculate_totals();
            if (!hash_equals($expectedSignature, $this->snapshotSignature($this->snapshot($cart)))) {
                return false;
            }

            $cart->set_session();
            $durableSignature = $this->persistedCartSignature(
                $this->persistAndReloadSessionData()
            );
            if (!hash_equals($expectedSignature, $durableSignature)) {
                $this->sessionPersistence->invalidateCache();
                return false;
            }
            return true;
        } catch (\Throwable) {
            $this->sessionPersistence->invalidateCache();
            return false;
        }
    }

    /** @param array{items:list<array<string,mixed>>,coupons:list<string>} $snapshot */
    private function snapshotSignature(array $snapshot): string
    {
        if (count($snapshot['items']) > self::MAX_SNAPSHOT_LINES) {
            throw new \RuntimeException('The cart snapshot exceeds the safe line limit.');
        }

        $nodes = 0;
        $sortable = array();
        foreach ($snapshot['items'] as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('The cart snapshot contains a malformed line.');
            }
            $variation = $item['variation'] ?? null;
            if (!is_array($variation)) {
                throw new \RuntimeException('The cart snapshot contains malformed variation data.');
            }
            $normalized = array(
                'product_id' => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'variation' => $this->normalizeVariation($variation),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'data' => $this->normalizeStableValue((array) ($item['data'] ?? array()), 0, $nodes),
            );
            $sortKey = json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            if (strlen($sortKey) > self::MAX_SNAPSHOT_JSON_BYTES) {
                throw new \RuntimeException('A cart line exceeds the safe verification size.');
            }
            $sortable[] = array('key' => $sortKey, 'value' => $normalized);
        }
        usort($sortable, static fn (array $left, array $right): int => strcmp($left['key'], $right['key']));
        $items = array_map(static fn (array $entry): array => $entry['value'], $sortable);
        $coupons = $this->normalizeCoupons($snapshot['coupons']);
        $encoded = wp_json_encode(array('items' => $items, 'coupons' => $coupons), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_SNAPSHOT_JSON_BYTES) {
            throw new \RuntimeException('The cart state exceeds the safe verification size.');
        }
        return hash('sha256', $encoded);
    }

    /** @param array<string,mixed> $freshSessionData @return array<string,mixed>|null */
    private function operationEntry(array $freshSessionData, string $operationKey): ?array
    {
        $history = $this->validatedOperationHistory(
            $freshSessionData[self::IDEMPOTENCY_SESSION_KEY] ?? array()
        );
        $entry = $history[$this->operationHash($operationKey)] ?? null;
        if ($entry === null) {
            return null;
        }
        return $entry;
    }

    /** @param array<string,mixed>|null $entry */
    private function priorReceipt(
        ?array $entry,
        string $planFingerprint,
        ToolContext $context,
        object $cart,
        string $persistedSignature
    ): ?CartReceipt {
        if ($entry === null || ($entry['status'] ?? '') !== 'completed') {
            return null;
        }
        $storedPlanFingerprint = $entry['plan_fingerprint'] ?? null;
        if (!is_string($storedPlanFingerprint) || !hash_equals($planFingerprint, $storedPlanFingerprint)) {
            throw new \RuntimeException('The cart operation identifier was reused with a different plan.');
        }
        if (!is_array($entry['receipt'] ?? null)) {
            throw new \RuntimeException('The completed cart operation receipt is malformed.');
        }
        $receipt = CartReceipt::fromArray($entry['receipt']);
        if ($receipt === null) {
            throw new \RuntimeException('The completed cart operation receipt is invalid.');
        }
        $currentSignature = $this->snapshotSignature($this->snapshot($cart));
        $postSignature = $entry['post_state_signature'] ?? null;
        if (!is_string($postSignature) || !$this->isSha256($postSignature)) {
            throw new \RuntimeException('The completed cart operation state signature is invalid.');
        }
        $message = $receipt->message;
        $cartView = $receipt->cart;
        if (!hash_equals($postSignature, $persistedSignature)) {
            $message .= ' نُفّذ هذا الطلب سابقًا، لكن السلة تغيّرت لاحقًا؛ حدّث السلة لعرض حالتها الحالية.';
        } elseif (hash_equals($currentSignature, $persistedSignature)) {
            try {
                $cartView = $this->view($context);
            } catch (\Throwable) {
                // The durable receipt remains safe to return on an idempotent replay.
            }
        }
        return new CartReceipt($receipt->id, $message, $receipt->lines, $cartView);
    }

    /** @param array<string,mixed>|null $entry */
    private function assertStartedOperationSafe(
        ?array $entry,
        string $planFingerprint,
        string $persistedSignature,
        string $operationKey
    ): void {
        if ($entry === null) {
            return;
        }
        $status = $entry['status'] ?? '';
        if (!in_array($status, array('started', 'rolled_back'), true)) {
            throw new \RuntimeException('The persisted cart operation has an unsupported state.');
        }
        $storedPlanFingerprint = $entry['plan_fingerprint'] ?? null;
        $preStateSignature = $entry['pre_state_signature'] ?? null;
        if (!is_string($storedPlanFingerprint)
            || !is_string($preStateSignature)
            || !$this->isSha256($storedPlanFingerprint)
            || !$this->isSha256($preStateSignature)
            || !hash_equals($planFingerprint, $storedPlanFingerprint)) {
            throw new \RuntimeException('The cart operation identifier was reused with a different plan.');
        }
        if ($status === 'rolled_back') {
            $failureCode = is_string($entry['failure_code'] ?? null)
                ? $entry['failure_code']
                : 'cart_execution_failed';
            throw new CartOperationRolledBack($failureCode);
        }
        if (!hash_equals($preStateSignature, $persistedSignature)) {
            $this->logger->error('A previously started cart operation has an ambiguous durable result.', array(
                'operation_hash' => hash('sha256', $operationKey),
            ));
            throw new CartStateUncertain(
                'A previous attempt may already have changed the cart, but no durable replay receipt exists. Inspect the cart before retrying.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function rememberStartedOperation(
        string $operationKey,
        string $planFingerprint,
        string $preStateSignature
    ): array {
        if (!$this->isSha256($planFingerprint) || !$this->isSha256($preStateSignature)) {
            throw new \InvalidArgumentException('A cart operation start contains an invalid state fingerprint.');
        }
        $entry = array(
            'status' => 'started',
            'stored_at' => microtime(true),
            'plan_fingerprint' => $planFingerprint,
            'pre_state_signature' => $preStateSignature,
        );
        $persistedData = $this->persistOperationEntry($operationKey, $entry);
        $persisted = $this->operationEntry($persistedData, $operationKey);
        if (!is_array($persisted)
            || ($persisted['status'] ?? '') !== 'started'
            || !is_string($persisted['plan_fingerprint'] ?? null)
            || !hash_equals($planFingerprint, $persisted['plan_fingerprint'])
            || !is_string($persisted['pre_state_signature'] ?? null)
            || !hash_equals($preStateSignature, $persisted['pre_state_signature'])) {
            throw new \RuntimeException('The cart operation journal could not be durably started.');
        }
        return $persisted;
    }

    /** @param array<string,mixed> $startedEntry */
    private function rememberCompletedOperation(
        string $operationKey,
        array $startedEntry,
        CartReceipt $receipt,
        string $postStateSignature
    ): void {
        if (!$this->isSha256($postStateSignature)) {
            throw new \InvalidArgumentException('A completed cart operation contains an invalid state fingerprint.');
        }
        $entry = $this->validateOperationEntry($startedEntry) + array();
        $entry['status'] = 'completed';
        $entry['stored_at'] = microtime(true);
        $entry['receipt'] = $receipt->toArray();
        $entry['post_state_signature'] = $postStateSignature;
        $persistedData = $this->persistOperationEntry($operationKey, $entry);

        $persisted = $this->operationEntry($persistedData, $operationKey);
        if (!is_array($persisted)
            || ($persisted['status'] ?? '') !== 'completed'
            || !is_array($persisted['receipt'] ?? null)
            || (string) ($persisted['receipt']['id'] ?? '') !== $receipt->id
            || !is_string($persisted['post_state_signature'] ?? null)
            || !hash_equals($postStateSignature, $persisted['post_state_signature'])) {
            throw new \RuntimeException('The completed cart operation journal could not be durably verified.');
        }
    }

    /** @param array<string,mixed> $startedEntry */
    private function rememberRolledBackOperation(
        string $operationKey,
        array $startedEntry,
        string $failureCode
    ): void {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $failureCode) !== 1) {
            throw new \InvalidArgumentException('A rolled-back cart operation contains an invalid failure code.');
        }

        $entry = $this->validateOperationEntry($startedEntry) + array();
        if (($entry['status'] ?? '') !== 'started') {
            throw new \RuntimeException('Only a started cart operation can transition to rolled back.');
        }
        $entry['status'] = 'rolled_back';
        $entry['stored_at'] = microtime(true);
        $entry['failure_code'] = $failureCode;

        try {
            $persistedData = $this->persistOperationEntry($operationKey, $entry);
        } catch (\Throwable $error) {
            // A persistence adapter may throw after the database accepted the
            // write. Re-read canonical storage before deciding the transition
            // failed, so a proven terminal marker is not discarded.
            $this->sessionPersistence->invalidateCache();
            try {
                $persistedData = $this->freshSessionData();
            } catch (\Throwable) {
                throw $error;
            }
            $persisted = $this->operationEntry($persistedData, $operationKey);
            if (!$this->matchesRolledBackEntry($persisted, $entry)) {
                throw $error;
            }
            return;
        }

        $persisted = $this->operationEntry($persistedData, $operationKey);
        if (!$this->matchesRolledBackEntry($persisted, $entry)) {
            throw new \RuntimeException('The rolled-back cart operation journal could not be durably verified.');
        }
    }

    /** @param array<string,mixed>|null $persisted @param array<string,mixed> $expected */
    private function matchesRolledBackEntry(?array $persisted, array $expected): bool
    {
        return is_array($persisted)
            && ($persisted['status'] ?? '') === 'rolled_back'
            && is_string($persisted['plan_fingerprint'] ?? null)
            && is_string($persisted['pre_state_signature'] ?? null)
            && is_string($persisted['failure_code'] ?? null)
            && hash_equals((string) $expected['plan_fingerprint'], $persisted['plan_fingerprint'])
            && hash_equals((string) $expected['pre_state_signature'], $persisted['pre_state_signature'])
            && hash_equals((string) $expected['failure_code'], $persisted['failure_code']);
    }

    private function rollbackFailureCode(\Throwable $error): string
    {
        if ($error instanceof \YassinStore\AiAssistant\Application\Contract\TurnLeaseLost) {
            return 'turn_lease_lost';
        }
        if ($error instanceof \InvalidArgumentException) {
            return 'invalid_arguments';
        }
        return 'cart_execution_failed';
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function persistOperationEntry(string $operationKey, array $entry): array
    {
        $session = WC()->session ?? null;
        if (!is_object($session) || !method_exists($session, 'set')) {
            throw new \RuntimeException('The WooCommerce session cannot persist the cart operation journal.');
        }
        // The request-local session object may have been hydrated before a
        // previous assistant request committed its journal entry. Merge from a
        // direct durable read while the cart lock is held so we never erase a
        // valid replay receipt with stale in-memory journal data.
        $fresh = $this->freshSessionData();
        $history = $this->validatedOperationHistory(
            $fresh[self::IDEMPOTENCY_SESSION_KEY] ?? array()
        );
        $operationHash = $this->operationHash($operationKey);
        $history = $this->historyWithEntry($history, $operationHash, $this->validateOperationEntry($entry));
        $session->set(self::IDEMPOTENCY_SESSION_KEY, $history);
        return $this->persistAndReloadSessionData();
    }

    /** @return array<string,mixed> */
    private function persistAndReloadSessionData(): array
    {
        $this->sessionPersistence->persist();

        // A persistent object cache may contain the request-local value even
        // when the database write failed or was silently dropped. Remove that
        // possible source of disagreement, then require a new canonical read.
        $this->sessionPersistence->invalidateCache();
        return $this->sessionPersistence->read(self::DURABLE_SESSION_KEYS);
    }

    /** @param array<string,mixed> $entry */
    private function restoreOperationEntryInMemory(string $operationKey, array $entry): void
    {
        try {
            if (WC()->session === null) {
                return;
            }
            $history = $this->validatedOperationHistory(
                WC()->session->get(self::IDEMPOTENCY_SESSION_KEY, array())
            );
            $operationHash = $this->operationHash($operationKey);
            $history = $this->historyWithEntry(
                $history,
                $operationHash,
                $this->validateOperationEntry($entry)
            );
            WC()->session->set(self::IDEMPOTENCY_SESSION_KEY, $history);
        } catch (\Throwable) {
            // The already-persisted started marker remains the durable fallback.
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function validatedOperationHistory(mixed $raw): array
    {
        if (!is_array($raw)
            || ($raw !== array() && array_is_list($raw))
            || count($raw) > self::IDEMPOTENCY_HISTORY_LIMIT) {
            throw new \RuntimeException('The persisted cart operation journal is malformed or oversized.');
        }

        $history = array();
        foreach ($raw as $operationHash => $entry) {
            if (!is_string($operationHash)
                || !$this->isSha256($operationHash)
                || !is_array($entry)) {
                throw new \RuntimeException('The persisted cart operation journal contains an invalid entry.');
            }
            $history[$operationHash] = $this->validateOperationEntry($entry);
        }
        if ($this->journalEncodedSize($history) > self::MAX_JOURNAL_JSON_BYTES) {
            throw new \RuntimeException('The persisted cart operation journal exceeds the safe byte limit.');
        }
        return $history;
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function validateOperationEntry(array $entry): array
    {
        if (array_is_list($entry) || !is_string($entry['status'] ?? null)) {
            throw new \RuntimeException('The persisted cart operation entry is malformed.');
        }
        $status = $entry['status'];
        $allowed = match ($status) {
            'started' => array('status', 'stored_at', 'plan_fingerprint', 'pre_state_signature'),
            'rolled_back' => array('status', 'stored_at', 'plan_fingerprint', 'pre_state_signature', 'failure_code'),
            'completed' => array('status', 'stored_at', 'plan_fingerprint', 'pre_state_signature', 'receipt', 'post_state_signature'),
            default => array(),
        };
        if (!in_array($status, array('started', 'rolled_back', 'completed'), true)
            || array_diff(array_keys($entry), $allowed) !== array()) {
            throw new \RuntimeException('The persisted cart operation entry has unsupported fields or state.');
        }

        $storedAt = $entry['stored_at'] ?? null;
        $plan = $entry['plan_fingerprint'] ?? null;
        $pre = $entry['pre_state_signature'] ?? null;
        if ((!is_int($storedAt) && !is_float($storedAt))
            || !is_finite((float) $storedAt)
            || (float) $storedAt <= 0.0
            || !is_string($plan)
            || !is_string($pre)
            || !$this->isSha256($plan)
            || !$this->isSha256($pre)) {
            throw new \RuntimeException('The persisted cart operation entry contains invalid metadata.');
        }

        $normalized = array(
            'status' => $status,
            'stored_at' => (float) $storedAt,
            'plan_fingerprint' => $plan,
            'pre_state_signature' => $pre,
        );
        if ($status === 'completed') {
            $post = $entry['post_state_signature'] ?? null;
            $receiptValue = $entry['receipt'] ?? null;
            $receipt = is_array($receiptValue) ? CartReceipt::fromArray($receiptValue) : null;
            if (!is_string($post) || !$this->isSha256($post) || $receipt === null) {
                throw new \RuntimeException('The completed cart operation entry is invalid.');
            }
            $normalized['receipt'] = $receipt->toArray();
            $normalized['post_state_signature'] = $post;
        } elseif ($status === 'rolled_back') {
            $failureCode = $entry['failure_code'] ?? null;
            if (!is_string($failureCode)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $failureCode) !== 1) {
                throw new \RuntimeException('The rolled-back cart operation entry is invalid.');
            }
            $normalized['failure_code'] = $failureCode;
        }

        if ($this->journalEncodedSize($normalized) > self::MAX_JOURNAL_ENTRY_BYTES) {
            throw new \RuntimeException('The persisted cart operation entry exceeds the safe byte limit.');
        }
        return $normalized;
    }

    /**
     * @param array<string,array<string,mixed>> $history
     * @param array<string,mixed> $entry
     * @return array<string,array<string,mixed>>
     */
    private function historyWithEntry(array $history, string $operationHash, array $entry): array
    {
        $history[$operationHash] = $entry;
        uksort($history, static function (string $leftKey, string $rightKey) use ($history): int {
            $leftTime = (float) $history[$leftKey]['stored_at'];
            $rightTime = (float) $history[$rightKey]['stored_at'];
            $timeComparison = $leftTime <=> $rightTime;
            return $timeComparison !== 0 ? $timeComparison : strcmp($leftKey, $rightKey);
        });

        while (count($history) > self::IDEMPOTENCY_HISTORY_LIMIT
            || $this->journalEncodedSize($history) > self::MAX_JOURNAL_JSON_BYTES) {
            $removed = false;
            foreach ($history as $candidate => $candidateEntry) {
                if ($candidate === $operationHash
                    || !in_array(($candidateEntry['status'] ?? ''), array('completed', 'rolled_back'), true)) {
                    continue;
                }
                unset($history[$candidate]);
                $removed = true;
                break;
            }
            if (!$removed) {
                throw new \RuntimeException(
                    'The cart operation journal is full of unresolved operations and cannot safely accept another entry.'
                );
            }
        }
        return $history;
    }

    private function operationHash(string $operationKey): string
    {
        if ($operationKey === ''
            || strlen($operationKey) > self::MAX_OPERATION_KEY_BYTES
            || preg_match('//u', $operationKey) !== 1) {
            throw new \InvalidArgumentException('The cart operation key is invalid or oversized.');
        }
        return hash('sha256', $operationKey);
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    private function journalEncodedSize(array $value): int
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 32);
        if (!is_string($encoded)) {
            throw new \RuntimeException('The cart operation journal cannot be encoded safely.');
        }
        return strlen($encoded);
    }

    private function planFingerprint(CartPlan $plan): string
    {
        $commands = array_map(
            static fn ($command): array => array(
                'action' => $command->action->value,
                'target_ref' => $command->targetRef,
                'product_ref' => $command->productRef,
                'quantity' => $command->quantity,
                'quantity_mode' => $command->quantityMode->value,
            ),
            $plan->commands
        );
        return hash('sha256', json_encode(
            array('commands' => $commands),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string,mixed> $item */
    private function lineFingerprint(string $key, array $item): string
    {
        if (strlen($key) > self::MAX_EXTENSION_KEY_BYTES || preg_match('//u', $key) !== 1) {
            throw new \RuntimeException('The cart line key is invalid or exceeds the safe verification size.');
        }
        $custom = $item;
        foreach (array('data', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax') as $remove) {
            unset($custom[$remove]);
        }
        $nodes = 0;
        $data = array(
            'key' => $key,
            'product_id' => (int) ($item['product_id'] ?? 0),
            'variation_id' => (int) ($item['variation_id'] ?? 0),
            'variation' => $this->variationFromItem($item),
            'quantity' => (int) ($item['quantity'] ?? 0),
            'custom' => $this->normalizeStableValue($custom, 0, $nodes),
        );
        $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_SNAPSHOT_JSON_BYTES) {
            throw new \RuntimeException('The current cart line exceeds the safe fingerprint size.');
        }
        return hash('sha256', $encoded);
    }

    private function normalizeStableValue(mixed $value, int $depth, int &$nodes): mixed
    {
        ++$nodes;
        if ($nodes > self::MAX_EXTENSION_NODES) {
            throw new \RuntimeException('Cart extension data exceeds the supported node limit.');
        }
        if ($depth > self::MAX_EXTENSION_DEPTH) {
            throw new \RuntimeException('Cart extension data exceeds the supported nesting depth.');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (strlen($value) > self::MAX_EXTENSION_STRING_BYTES || preg_match('//u', $value) !== 1) {
                throw new \RuntimeException('Cart extension data contains invalid or oversized UTF-8 text.');
            }
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \RuntimeException('Cart extension data contains a non-finite number.');
            }
            return $value;
        }
        if (is_array($value)) {
            if (count($value) > self::MAX_EXTENSION_ARRAY_ITEMS) {
                throw new \RuntimeException('Cart extension data contains an oversized array.');
            }
            $normalized = array();
            foreach ($value as $key => $item) {
                $normalizedKey = (string) $key;
                if (strlen($normalizedKey) > self::MAX_EXTENSION_KEY_BYTES
                    || preg_match('//u', $normalizedKey) !== 1) {
                    throw new \RuntimeException('Cart extension data contains an invalid or oversized UTF-8 key.');
                }
                $normalized[$normalizedKey] = $this->normalizeStableValue($item, $depth + 1, $nodes);
            }
            if (!array_is_list($value)) {
                ksort($normalized);
            } else {
                $normalized = array_values($normalized);
            }
            return $normalized;
        }
        if ($value instanceof \DateTimeInterface) {
            return array('object_class' => $value::class, 'value' => $value->format(DATE_ATOM));
        }
        if ($value instanceof \JsonSerializable) {
            return array(
                'object_class' => $value::class,
                'json' => $this->normalizeStableValue($value->jsonSerialize(), $depth + 1, $nodes),
            );
        }
        if ($value instanceof \Stringable) {
            $string = (string) $value;
            if (strlen($string) > self::MAX_EXTENSION_STRING_BYTES || preg_match('//u', $string) !== 1) {
                throw new \RuntimeException('Cart extension data contains an invalid or oversized UTF-8 stringable value.');
            }
            return array('object_class' => $value::class, 'value' => $string);
        }
        if (is_object($value)) {
            $properties = get_object_vars($value);
            if ($properties !== array()) {
                return array(
                    'object_class' => $value::class,
                    'properties' => $this->normalizeStableValue($properties, $depth + 1, $nodes),
                );
            }
            throw new \RuntimeException(
                'Cart extension data contains an opaque object that cannot be verified safely.'
            );
        }
        throw new \RuntimeException('Cart extension data contains an unsupported value.');
    }

    /** @param array<string,mixed> $variation @return array<string,string> */
    private function normalizeVariation(array $variation): array
    {
        if (count($variation) > self::MAX_VARIATION_ATTRIBUTES) {
            throw new \RuntimeException('Cart variation data exceeds the safe attribute limit.');
        }
        $normalized = array();
        foreach ($variation as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \RuntimeException('Cart variation data contains an unsupported value.');
            }
            if ($name === ''
                || strlen($name) > self::MAX_VARIATION_KEY_BYTES
                || strlen($value) > self::MAX_VARIATION_VALUE_BYTES
                || preg_match('//u', $name) !== 1
                || preg_match('//u', $value) !== 1) {
                throw new \RuntimeException('Cart variation data exceeds the safe size limit.');
            }
            $normalized[$name] = $value;
        }
        ksort($normalized);
        return $normalized;
    }

    /** @param array<mixed> $coupons @return list<string> */
    private function normalizeCoupons(array $coupons): array
    {
        if (count($coupons) > self::MAX_COUPONS) {
            throw new \RuntimeException('The cart coupon state exceeds the safe limit.');
        }
        $normalized = array();
        foreach ($coupons as $coupon) {
            if (!is_string($coupon)) {
                throw new \RuntimeException('The cart coupon state contains an invalid value.');
            }
            if ($coupon === ''
                || strlen($coupon) > self::MAX_COUPON_LENGTH
                || preg_match('//u', $coupon) !== 1) {
                throw new \RuntimeException('The cart coupon state contains an invalid code.');
            }
            $normalized[] = $coupon;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /** @param array<string,mixed> $item */
    private function identityKeyFromItem(array $item): string
    {
        return $this->identityKey(
            (int) ($item['product_id'] ?? 0),
            (int) ($item['variation_id'] ?? 0),
            $this->variationFromItem($item)
        );
    }

    /** @param array<string,mixed> $item @return array<string,string> */
    private function variationFromItem(array $item): array
    {
        $variation = $item['variation'] ?? array();
        if (!is_array($variation)) {
            throw new \RuntimeException('A WooCommerce cart line contains malformed variation data.');
        }
        return $this->normalizeVariation($variation);
    }

    private function identityQuantity(object $cart, string $identity): int
    {
        $quantity = 0;
        foreach ($cart->get_cart() as $item) {
            if (is_array($item) && hash_equals($identity, $this->identityKeyFromItem($item))) {
                $quantity += (int) ($item['quantity'] ?? 0);
            }
        }
        return $quantity;
    }

    /** @param array<string,mixed> $variation */
    private function identityKey(int $productId, int $variationId, array $variation): string
    {
        $variation = $this->normalizeVariation($variation);
        $encoded = wp_json_encode(array($productId, $variationId, $variation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_SNAPSHOT_JSON_BYTES) {
            throw new \RuntimeException('Unable to fingerprint the cart product identity safely.');
        }
        return hash('sha256', $encoded);
    }

    /** @param array<string,mixed> $variation */
    private function lineName(object $product, array $variation): string
    {
        $name = method_exists($product, 'get_name') ? (string) $product->get_name() : '';
        $labels = $this->variationLabels($variation);
        if ($labels !== array()) {
            $name .= ' — ' . implode('، ', array_map(
                static fn (string $key, string $value): string => $key . ': ' . $value,
                array_keys($labels),
                array_values($labels)
            ));
        }
        return Text::plain($name, self::MAX_LINE_NAME_LENGTH);
    }

    /** @param array<string,mixed> $variation @return array<string,string> */
    private function variationLabels(array $variation): array
    {
        $labels = array();
        foreach (array_slice($variation, 0, self::MAX_VARIATION_LABELS, true) as $name => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $attribute = preg_replace('/^attribute_/', '', (string) $name) ?? (string) $name;
            $label = Text::plain((string) wc_attribute_label($attribute), self::MAX_VARIATION_TEXT_LENGTH);
            $term = taxonomy_exists($attribute) ? get_term_by('slug', (string) $value, $attribute) : false;
            $resolved = $term instanceof \WP_Term ? (string) $term->name : (string) $value;
            $resolved = Text::plain($resolved, self::MAX_VARIATION_TEXT_LENGTH);
            if ($label !== '' && $resolved !== '') {
                $labels[$label] = $resolved;
            }
        }
        return $labels;
    }

    private function safeMoney(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }
        $number = (float) $value;
        return is_finite($number) && $number >= 0.0 && $number <= 1_000_000_000_000.0
            ? $number
            : 0.0;
    }

    /** @param list<array<string,mixed>> $lines */
    private function receiptMessage(array $lines): string
    {
        if (count($lines) !== 1) {
            return sprintf('تم تنفيذ %d تغييرات في السلة بنجاح.', count($lines));
        }
        $line = $lines[0];
        $name = (string) ($line['name'] ?? '');
        return match ((string) $line['action']) {
            'add' => sprintf('تمت إضافة «%s» إلى السلة بكمية %d.', $name, (int) $line['quantity']),
            'set_quantity' => sprintf('تم تحديث كمية «%s» إلى %d.', $name, (int) $line['quantity']),
            'increment' => sprintf('تمت زيادة كمية «%s» بمقدار %d.', $name, (int) $line['quantity']),
            'decrement' => (int) ($line['final_quantity'] ?? 0) === 0
                ? sprintf('تمت إزالة «%s» من السلة.', $name)
                : sprintf('تم خفض كمية «%s» إلى %d.', $name, (int) $line['final_quantity']),
            'remove' => sprintf('تمت إزالة «%s» من السلة.', $name),
            'replace' => sprintf(
                'تم استبدال «%s» بـ «%s» بكمية %d.',
                $name,
                (string) ($line['replacement'] ?? ''),
                (int) $line['quantity']
            ),
            'clear' => 'تم إفراغ السلة.',
            default => 'تم تحديث السلة بنجاح.',
        };
    }
}
