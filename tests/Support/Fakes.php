<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Catalog\CatalogSearchResult;
use YassinStore\AiAssistant\Application\Contract\AiProvider;
use YassinStore\AiAssistant\Application\Contract\CartGateway;
use YassinStore\AiAssistant\Application\Contract\CatalogGateway;
use YassinStore\AiAssistant\Application\Contract\Clock;
use YassinStore\AiAssistant\Application\Contract\ContentGateway;
use YassinStore\AiAssistant\Application\Contract\ConversationBusy;
use YassinStore\AiAssistant\Application\Contract\ConversationRepository;
use YassinStore\AiAssistant\Application\Contract\RateLimiter;
use YassinStore\AiAssistant\Application\Contract\RuntimeSettings;
use YassinStore\AiAssistant\Application\Contract\TurnLeaseLost;
use YassinStore\AiAssistant\Application\Contract\TurnRepository;
use YassinStore\AiAssistant\Application\Contract\TurnRequestConflict;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartReceipt;
use YassinStore\AiAssistant\Domain\Conversation\ConversationCredentials;
use YassinStore\AiAssistant\Domain\Shared\Uuid;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

final class TestSettings implements RuntimeSettings
{
    /** @param array<string,mixed> $values */
    public function __construct(private array $values = array())
    {
        $this->values = array_replace(array(
            'enabled' => true,
            'allow_images' => true,
            'max_tool_rounds' => 6,
            'max_display_cards' => 6,
            'rate_limit_turns' => 40,
            'daily_ai_turn_limit' => 1200,
            'daily_conversation_limit' => 5000,
            'conversation_retention_days' => 45,
            'assistant_session_minutes' => 0,
            'store_guidance' => '',
            'catalog_synonyms' => '',
        ), $this->values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function apiKey(): string
    {
        return (string) ($this->values['api_key'] ?? 'test-key');
    }
}

final class TestClock implements Clock
{
    public function __construct(public \DateTimeImmutable $value = new \DateTimeImmutable('2026-08-14T12:00:00+00:00'))
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->value;
    }
}

final class ScriptedAiProvider implements AiProvider
{
    /** @var list<array<string,mixed>|callable> */
    public array $responses;
    /** @var list<list<array<string,mixed>>> */
    public array $histories = array();
    public int $interactCalls = 0;
    public int $structuredCalls = 0;
    public mixed $structuredResult;

    /** @param list<array<string,mixed>|callable> $responses */
    public function __construct(array $responses = array())
    {
        $this->responses = $responses;
        $this->structuredResult = static function (string $input): array {
            $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
            return array(
                'authorized' => true,
                'requires_clarification' => false,
                'reason' => 'Explicit current-message authorization.',
                'authorization_fingerprint' => (string) ($decoded['authorization_fingerprint'] ?? ''),
            );
        };
    }

    public function interact(array $history, array $tools, string $systemInstruction): array
    {
        ++$this->interactCalls;
        $this->histories[] = $history;
        if ($this->responses === array()) {
            throw new RuntimeException('No scripted AI response remains.');
        }
        $next = array_shift($this->responses);
        $result = is_callable($next) ? $next($history, $tools, $systemInstruction, $this) : $next;
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!is_array($result)) {
            throw new RuntimeException('Scripted AI response must be an array.');
        }
        return $result;
    }

    public function structured(string $input, array $schema, string $systemInstruction, string $thinkingLevel = 'low'): array
    {
        ++$this->structuredCalls;
        $result = is_callable($this->structuredResult)
            ? ($this->structuredResult)($input, $schema, $systemInstruction, $thinkingLevel)
            : $this->structuredResult;
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!is_array($result)) {
            throw new RuntimeException('Scripted structured response must be an array.');
        }
        return $result;
    }

    public function readinessCheck(array $tools = array()): array
    {
        return array('ready' => true, 'message' => 'ready', 'model' => 'test');
    }
}

final readonly class TestProduct
{
    public function __construct(public int $id, public string $name, public float $price = 10.0)
    {
    }
}

final class TestCatalog implements CatalogGateway
{
    /** @var array<int,TestProduct> */
    public array $products = array();
    /** @var array<int,array<string,mixed>> */
    public array $identityOverrides = array();
    /** @var array<int,array<string,mixed>> */
    public array $projectionOverrides = array();

    /** @param list<TestProduct> $products */
    public function __construct(array $products = array())
    {
        foreach ($products as $product) {
            $this->products[$product->id] = $product;
        }
    }

    public function search(string $query, int $limit, array $filters = array()): CatalogSearchResult
    {
        $products = array_slice(array_values($this->products), 0, $limit);
        $truncated = count($this->products) > $limit;
        return new CatalogSearchResult(
            $products,
            $truncated,
            !$truncated,
            min(count($this->products), $limit),
            240
        );
    }

    /** @param list<object> $products @return list<object> */
    public function sortProducts(array $products, string $sort): array
    {
        if (!in_array($sort, array('relevance', 'price_low', 'price_high', 'newest', 'best_selling', 'rating'), true)) {
            throw new InvalidArgumentException('Unsupported test catalog sort.');
        }
        $products = array_values($products);
        if ($sort === 'price_low' || $sort === 'price_high') {
            usort($products, static function (object $left, object $right) use ($sort): int {
                $comparison = ((float) ($left->price ?? 0)) <=> ((float) ($right->price ?? 0));
                return $sort === 'price_high' ? -$comparison : $comparison;
            });
        } elseif ($sort === 'newest' || $sort === 'best_selling') {
            usort($products, static fn (object $left, object $right): int => ((int) ($right->id ?? 0)) <=> ((int) ($left->id ?? 0)));
        }
        return $products;
    }

    public function product(int $id): ?object
    {
        return $this->products[$id] ?? null;
    }

    public function bySku(string $sku): ?object
    {
        $id = filter_var($sku, FILTER_VALIDATE_INT);
        return is_int($id) ? $this->product($id) : null;
    }

    public function related(int $id, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->products,
            static fn (TestProduct $product): bool => $product->id !== $id
        )), 0, $limit);
    }

    public function alternatives(int $id, int $limit): array
    {
        return $this->related($id, $limit);
    }

    public function categories(int $limit): array
    {
        return array(array('name' => 'Test', 'slug' => 'test', 'count' => count($this->products)));
    }

    public function resolveVariation(int $parentId, array $attributes): ?object
    {
        return $this->product($parentId);
    }

    public function identity(object $product): array
    {
        if (!$product instanceof TestProduct) {
            throw new InvalidArgumentException('Unexpected product object.');
        }
        if (isset($this->identityOverrides[$product->id])) {
            return $this->identityOverrides[$product->id];
        }
        return array(
            'id' => $product->id,
            'parent_id' => 0,
            'type' => 'simple',
            'fingerprint' => hash('sha256', $product->id . '|' . $product->name . '|' . $product->price),
        );
    }

    public function project(object $product): array
    {
        if (!$product instanceof TestProduct) {
            throw new InvalidArgumentException('Unexpected product object.');
        }
        if (isset($this->projectionOverrides[$product->id])) {
            return $this->projectionOverrides[$product->id];
        }
        return array(
            'name' => $product->name,
            'sku' => (string) $product->id,
            'type' => 'simple',
            'price' => $product->price,
            'price_available' => true,
            'price_kind' => 'fixed',
            'regular_price' => $product->price,
            'sale_price' => null,
            'price_text' => '$' . number_format($product->price, 2),
            'currency' => 'USD',
            'in_stock' => true,
            'stock_status' => 'instock',
            'stock_quantity' => null,
            'rating' => 4.5,
            'review_count' => 10,
            'short_description' => 'Test product',
            'image' => 'https://shop.example.test/product.png',
            'url' => 'https://shop.example.test/product/' . $product->id,
            'purchasable' => true,
            'requires_options' => false,
            'categories' => array('Test'),
            'attributes' => array(),
            'variation_options' => array(),
        );
    }
}

final class TestCart implements CartGateway
{
    public int $viewCalls = 0;
    public int $applyCalls = 0;
    public ?CartPlan $lastPlan = null;
    public string $lastOperationKey = '';
    public bool $failView = false;
    public ?Throwable $applyError = null;

    public function view(ToolContext $context): array
    {
        ++$this->viewCalls;
        if ($this->failView) {
            throw new RuntimeException('Test cart view failed.');
        }
        $signature = hash('sha256', 'test-cart');
        $context->beginCartSnapshot($signature);
        $context->bindCartPersistence($signature);
        return array(
            'items' => array(),
            'item_count' => 0,
            'total' => 0.0,
            'total_text' => '$0.00',
            'currency' => 'USD',
            'cart_url' => 'https://shop.example.test/cart',
            'checkout_url' => 'https://shop.example.test/checkout',
            'cart_hash' => 'test-cart',
            'mutations_allowed' => true,
            'mutation_notice' => '',
        );
    }

    public function apply(CartPlan $plan, ToolContext $context): CartReceipt
    {
        ++$this->applyCalls;
        if ($this->applyError instanceof Throwable) {
            throw $this->applyError;
        }
        $this->lastPlan = $plan;
        $this->lastOperationKey = $context->operationKey();
        $cart = $this->view($context);
        $cart['item_count'] = 1;
        $cart['total'] = 20.0;
        $cart['total_text'] = '$20.00';
        return new CartReceipt(
            Uuid::v4(),
            'تم تحديث السلة بنجاح.',
            array(array('action' => 'add', 'quantity' => 2, 'name' => 'Test product')),
            $cart
        );
    }
}

final class TestContent implements ContentGateway
{
    public function search(string $query, int $limit): array
    {
        return array(array('id' => 1, 'title' => 'Shipping', 'excerpt' => 'Ships quickly.'));
    }

    public function get(int $id): ?array
    {
        return $id === 1 ? array('id' => 1, 'title' => 'Shipping', 'content' => 'Ships quickly.') : null;
    }

    public function storeInfo(): array
    {
        return array('name' => 'Test Store', 'currency' => 'USD');
    }

    public function policies(): array
    {
        return array('shipping_url' => 'https://shop.example.test/shipping');
    }
}

final class InMemoryConversationRepository implements ConversationRepository
{
    /** @var array<string,array<string,mixed>> */
    public array $conversations = array();
    /** @var list<array<string,mixed>> */
    public array $storedMessages = array();
    public bool $failAssistantAppend = false;
    public bool $failMessageForTurn = false;
    public bool $throwAfterUserMessageWrite = false;
    public bool $failExportTooLarge = false;
    public bool $busy = false;
    public int $touchCalls = 0;
    public ?\Closure $beforeUserMessageWrite = null;
    public ?\Closure $beforeMemoryWrite = null;
    public ?InMemoryTurnRepository $turnRepository = null;
    private int $nextMessageId = 1;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function seed(?ConversationCredentials $credentials = null): ConversationCredentials
    {
        $credentials ??= ConversationCredentials::issue();
        $this->conversations[$credentials->id] = array(
            'id' => $credentials->id,
            'token' => $credentials->token,
            'memory' => array(),
            'created_at' => $this->clock->now()->format(DATE_ATOM),
            'last_activity_at' => $this->clock->now()->format(DATE_ATOM),
            'expires_at' => $this->clock->now()->modify('+45 days')->format(DATE_ATOM),
        );
        return $credentials;
    }

    public function create(int $retentionDays): array
    {
        $credentials = $this->seed();
        $expires = $this->clock->now()->modify('+' . max(1, $retentionDays) . ' days')->format(DATE_ATOM);
        $this->conversations[$credentials->id]['expires_at'] = $expires;
        return array('credentials' => $credentials, 'expires_at' => $expires);
    }

    public function authenticate(string $conversationId, string $token): ?array
    {
        $row = $this->conversations[$conversationId] ?? null;
        if (!is_array($row) || !hash_equals((string) $row['token'], $token)) {
            return null;
        }
        return $row;
    }

    public function messages(string $conversationId, int $limit = 80, ?int $beforeTurnId = null): array
    {
        $rows = array_values(array_filter(
            $this->storedMessages,
            static fn (array $message): bool => $message['conversation_id'] === $conversationId
                && in_array($message['role'], array('user', 'assistant'), true)
                && ($beforeTurnId === null || (int) $message['turn_id'] < $beforeTurnId)
        ));
        usort($rows, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        return array_slice($rows, -max(1, $limit));
    }

    public function message(string $conversationId, int $messageId): ?array
    {
        foreach ($this->storedMessages as $message) {
            if ($message['conversation_id'] === $conversationId && (int) $message['id'] === $messageId) {
                return $message;
            }
        }
        return null;
    }

    public function messageForTurn(string $conversationId, int $turnId, string $role): ?array
    {
        if ($this->failMessageForTurn) {
            throw new RuntimeException('Turn message lookup failed.');
        }
        foreach ($this->storedMessages as $message) {
            if ($message['conversation_id'] === $conversationId
                && (int) $message['turn_id'] === $turnId
                && $message['role'] === $role) {
                return $message;
            }
        }
        return null;
    }

    public function appendMessage(string $conversationId, int $turnId, string $role, string $content, array $payload = array()): int
    {
        if ($this->failAssistantAppend && $role === 'assistant') {
            throw new RuntimeException('Assistant append failed.');
        }
        foreach ($this->storedMessages as $message) {
            if ((int) $message['turn_id'] === $turnId && $message['role'] === $role) {
                if ($message['conversation_id'] !== $conversationId
                    || $message['content'] !== $content
                    || $message['payload'] !== $payload) {
                    throw new RuntimeException('Message idempotency conflict.');
                }
                return (int) $message['id'];
            }
        }
        $id = $this->nextMessageId++;
        $this->storedMessages[] = array(
            'id' => $id,
            'conversation_id' => $conversationId,
            'turn_id' => $turnId,
            'role' => $role,
            'content' => $content,
            'payload' => $payload,
            'created_at' => $this->clock->now()->format(DATE_ATOM),
        );
        return $id;
    }

    public function appendUserMessageForTurn(
        string $conversationId,
        int $turnId,
        int $claimVersion,
        string $content,
        array $payload,
        int $retentionDays
    ): int {
        if ($this->beforeUserMessageWrite instanceof \Closure) {
            ($this->beforeUserMessageWrite)();
        }
        if (!isset($this->conversations[$conversationId])) {
            throw new RuntimeException('Conversation not found.');
        }
        $turn = $this->turnRepository?->turns[$turnId] ?? null;
        if (!is_array($turn)
            || ($turn['conversation_id'] ?? '') !== $conversationId
            || ($turn['status'] ?? '') !== 'processing'
            || (int) ($turn['claim_version'] ?? 0) !== $claimVersion
            || ($turn['response'] ?? array()) !== array()) {
            throw new TurnLeaseLost('The processing turn no longer owns the user-message write.');
        }
        $updatedAt = new DateTimeImmutable((string) ($turn['updated_at'] ?? ''));
        $leaseSeconds = (int) ($turn['lease_seconds'] ?? 0);
        if ($leaseSeconds < 30
            || $updatedAt <= $this->turnRepository->now->modify('-' . $leaseSeconds . ' seconds')) {
            throw new TurnLeaseLost('The processing turn lease expired before the user-message write.');
        }
        $messageId = $this->appendMessage($conversationId, $turnId, 'user', $content, $payload);
        $this->touch($conversationId, $retentionDays);
        if ($this->throwAfterUserMessageWrite) {
            throw new RuntimeException('The user-message write response was lost.');
        }
        return $messageId;
    }

    public function touch(string $conversationId, int $retentionDays): void
    {
        ++$this->touchCalls;
        if (!isset($this->conversations[$conversationId])) {
            throw new RuntimeException('Conversation not found.');
        }
        $this->conversations[$conversationId]['last_activity_at'] = $this->clock->now()->format(DATE_ATOM);
        $this->conversations[$conversationId]['expires_at'] = $this->clock->now()->modify('+' . max(1, $retentionDays) . ' days')->format(DATE_ATOM);
    }

    public function memory(string $conversationId): array
    {
        if (!isset($this->conversations[$conversationId])) {
            throw new RuntimeException('Conversation not found.');
        }
        return (array) $this->conversations[$conversationId]['memory'];
    }

    public function updateMemoryForTurn(
        string $conversationId,
        int $turnId,
        int $claimVersion,
        array $memory
    ): void
    {
        if ($this->beforeMemoryWrite instanceof \Closure) {
            ($this->beforeMemoryWrite)();
        }
        if (!isset($this->conversations[$conversationId])) {
            throw new RuntimeException('Conversation not found.');
        }
        $turn = $this->turnRepository?->turns[$turnId] ?? null;
        if (!is_array($turn)
            || ($turn['conversation_id'] ?? '') !== $conversationId
            || ($turn['status'] ?? '') !== 'processing'
            || (int) ($turn['claim_version'] ?? 0) !== $claimVersion) {
            throw new TurnLeaseLost('The processing turn no longer owns the shopping-memory write.');
        }
        $updatedAt = new DateTimeImmutable((string) ($turn['updated_at'] ?? ''));
        $leaseSeconds = (int) ($turn['lease_seconds'] ?? 0);
        if ($leaseSeconds < 30
            || $updatedAt <= $this->turnRepository->now->modify('-' . $leaseSeconds . ' seconds')) {
            throw new TurnLeaseLost('The processing turn lease expired before the shopping-memory write.');
        }
        $this->conversations[$conversationId]['memory'] = $memory;
    }

    public function exportPage(
        string $conversationId,
        int $afterMessageId = 0,
        int $upperMessageId = 0,
        int $limit = 200
    ): array {
        if ($afterMessageId < 0
            || $upperMessageId < 0
            || ($afterMessageId === 0 && $upperMessageId !== 0)
            || ($afterMessageId !== 0 && $upperMessageId === 0)
            || ($upperMessageId > 0 && $afterMessageId > $upperMessageId)) {
            throw new InvalidArgumentException('The export cursor is invalid.');
        }
        if ($this->failExportTooLarge) {
            throw new LengthException('The conversation exceeds the safe export limit.');
        }
        $limit = max(1, min(200, $limit));
        $eligible = array_values(array_filter(
            $this->storedMessages,
            static fn (array $message): bool => $message['conversation_id'] === $conversationId
                && in_array($message['role'], array('user', 'assistant'), true)
        ));
        usort($eligible, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $currentUpper = $eligible === array() ? 0 : (int) $eligible[array_key_last($eligible)]['id'];
        if ($upperMessageId <= 0) {
            $upperMessageId = $currentUpper;
        } elseif ($upperMessageId > $currentUpper) {
            throw new InvalidArgumentException('The export boundary is beyond the current conversation.');
        }
        $messageCount = count(array_filter(
            $eligible,
            static fn (array $message): bool => (int) $message['id'] <= $upperMessageId
        ));
        if ($afterMessageId > $upperMessageId && $upperMessageId > 0) {
            throw new InvalidArgumentException('The export cursor is beyond the export boundary.');
        }
        $eligible = array_values(array_filter(
            $eligible,
            static fn (array $message): bool => (int) $message['id'] > $afterMessageId
                && (int) $message['id'] <= $upperMessageId
        ));
        $hasMore = count($eligible) > $limit;
        $messages = array_slice($eligible, 0, $limit);
        $last = $messages === array() ? $afterMessageId : (int) $messages[array_key_last($messages)]['id'];
        return array(
            'conversation_id' => $conversationId,
            'exported_at' => $this->clock->now()->format(DATE_ATOM),
            'upper_message_id' => $upperMessageId,
            'next_after_message_id' => $hasMore ? $last : null,
            'complete' => !$hasMore,
            'message_count' => $messageCount,
            'messages' => $messages,
            'memory' => $this->memory($conversationId),
        );
    }

    public function delete(string $conversationId): void
    {
        if ($this->busy) {
            throw new ConversationBusy('Conversation has active work.');
        }
        unset($this->conversations[$conversationId]);
        $this->storedMessages = array_values(array_filter(
            $this->storedMessages,
            static fn (array $message): bool => $message['conversation_id'] !== $conversationId
        ));
    }

    public function stats(): array
    {
        return array(
            'conversations' => count($this->conversations),
            'messages' => count($this->storedMessages),
            'turns' => count(array_unique(array_column($this->storedMessages, 'turn_id'))),
        );
    }

    public function deleteAll(): void
    {
        if ($this->busy) {
            throw new ConversationBusy('Conversation has active work.');
        }
        $this->conversations = array();
        $this->storedMessages = array();
    }

    public function purgeExpired(): int
    {
        return 0;
    }
}

final class InMemoryTurnRepository implements TurnRepository
{
    /** @var array<int,array<string,mixed>> */
    public array $turns = array();
    public bool $failCheckpoint = false;
    public bool $failComplete = false;
    public bool $failFail = false;
    public int $claimCalls = 0;
    public int $checkpointCalls = 0;
    public int $heartbeatCalls = 0;
    public int $expireCalls = 0;
    public int $sealMissingCalls = 0;
    public ?\Closure $beforeSealMissing = null;
    public int $completeCalls = 0;
    public int $attachTerminalMessageIdCalls = 0;
    public bool $failAttachTerminalMessageId = false;
    public int $failCalls = 0;
    private int $nextId = 1;

    public function __construct(
        public \DateTimeImmutable $now = new \DateTimeImmutable('2026-08-14T12:00:00+00:00')
    ) {
    }

    public function claim(string $conversationId, string $clientTurnId, string $requestHash, int $staleAfterSeconds): array
    {
        ++$this->claimCalls;
        foreach ($this->turns as $turn) {
            if ($turn['conversation_id'] !== $conversationId || $turn['client_turn_id'] !== $clientTurnId) {
                continue;
            }
            if (!hash_equals((string) $turn['request_hash'], $requestHash)) {
                throw new TurnRequestConflict('Conflicting canonical request content.');
            }
            $base = array(
                'id' => (int) $turn['id'],
                'claim_version' => (int) $turn['claim_version'],
            );
            if (in_array($turn['status'], array('completed', 'failed'), true)) {
                return $base + array('state' => $turn['status'], 'response' => $turn['response']);
            }
            if ($turn['response'] !== array()) {
                return $base + array('state' => 'checkpointed', 'response' => $turn['response']);
            }
            $updatedAt = new DateTimeImmutable((string) ($turn['updated_at'] ?? ''));
            $leaseSeconds = (int) ($turn['lease_seconds'] ?? 0);
            if ($leaseSeconds >= 30
                && $updatedAt <= $this->now->modify('-' . $leaseSeconds . ' seconds')) {
                foreach ($this->turns as $other) {
                    if ((int) ($other['id'] ?? 0) !== (int) $turn['id']
                        && ($other['conversation_id'] ?? '') === $conversationId
                        && $this->turnBlocksNewWork($other)) {
                        throw new ConversationBusy('Another turn must be recovered for this conversation.');
                    }
                }
                ++$this->turns[(int) $turn['id']]['claim_version'];
                $this->turns[(int) $turn['id']]['lease_seconds'] = $staleAfterSeconds;
                $this->turns[(int) $turn['id']]['updated_at'] = $this->now->format(DATE_ATOM);
                return array(
                    'state' => 'retry',
                    'id' => (int) $turn['id'],
                    'claim_version' => (int) $this->turns[(int) $turn['id']]['claim_version'],
                );
            }
            return $base + array('state' => 'processing');
        }

        foreach ($this->turns as $turn) {
            if (($turn['conversation_id'] ?? '') === $conversationId
                && $this->turnBlocksNewWork($turn)) {
                throw new ConversationBusy('Another turn must be recovered for this conversation.');
            }
        }

        $id = $this->nextId++;
        $this->turns[$id] = array(
            'id' => $id,
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
            'request_hash' => $requestHash,
            'status' => 'processing',
            'claim_version' => 1,
            'lease_seconds' => $staleAfterSeconds,
            'response' => array(),
            'error_code' => '',
            'updated_at' => $this->now->format(DATE_ATOM),
        );
        return array('state' => 'new', 'id' => $id, 'claim_version' => 1);
    }

    public function checkpoint(int $turnId, int $claimVersion, array $response): void
    {
        ++$this->checkpointCalls;
        if ($this->failCheckpoint) {
            throw new RuntimeException('Checkpoint failed.');
        }
        $turn = $this->ownedProcessingTurn($turnId, $claimVersion, 'checkpointed');
        if ($turn['response'] !== array()) {
            if ($turn['response'] === $response) {
                return;
            }
            throw new TurnLeaseLost('The turn checkpoint changed.');
        }
        $this->assertFreshLease($turn, 'checkpointed');
        $this->turns[$turnId]['response'] = $response;
        $this->turns[$turnId]['updated_at'] = $this->now->format(DATE_ATOM);
    }

    public function heartbeat(int $turnId, int $claimVersion): void
    {
        ++$this->heartbeatCalls;
        $turn = $this->ownedProcessingTurn($turnId, $claimVersion, 'heartbeated');
        $this->assertFreshLease($turn, 'heartbeated');
        $this->turns[$turnId]['updated_at'] = $this->now->format(DATE_ATOM);
    }

    public function expireStale(
        int $turnId,
        int $claimVersion,
        string $errorCode,
        array $response
    ): bool {
        ++$this->expireCalls;
        if ($turnId <= 0 || $claimVersion <= 0) {
            throw new InvalidArgumentException('Invalid stale turn boundary.');
        }
        $turn = $this->turns[$turnId] ?? null;
        if (!is_array($turn)
            || (int) ($turn['claim_version'] ?? 0) !== $claimVersion
            || ($turn['status'] ?? '') !== 'processing'
            || ($turn['response'] ?? array()) !== array()) {
            return false;
        }
        $updatedAt = new DateTimeImmutable((string) ($turn['updated_at'] ?? ''));
        $leaseSeconds = (int) ($turn['lease_seconds'] ?? 0);
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw new RuntimeException('Stored turn lease is invalid.');
        }
        if ($updatedAt > $this->now->modify('-' . $leaseSeconds . ' seconds')) {
            return false;
        }
        $this->turns[$turnId]['status'] = 'failed';
        $this->turns[$turnId]['claim_version'] = $claimVersion + 1;
        $this->turns[$turnId]['response'] = $response;
        $this->turns[$turnId]['error_code'] = $errorCode;
        $this->turns[$turnId]['updated_at'] = $this->now->format(DATE_ATOM);
        return true;
    }

    public function sealMissingAsRejected(
        string $conversationId,
        string $clientTurnId,
        string $errorCode,
        array $response,
        int $leaseSeconds
    ): array {
        ++$this->sealMissingCalls;
        if ($this->beforeSealMissing instanceof \Closure) {
            ($this->beforeSealMissing)();
        }
        foreach ($this->turns as $turn) {
            if (($turn['conversation_id'] ?? '') === $conversationId
                && ($turn['client_turn_id'] ?? '') === $clientTurnId) {
                return $this->publicTurnRecord($turn);
            }
        }
        if ($leaseSeconds < 30 || $leaseSeconds > 3600
            || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $errorCode) !== 1
            || ($response['ok'] ?? null) !== false
            || ($response['turn_finalized'] ?? null) !== true
            || ($response['request_accepted'] ?? null) !== false
            || array_key_exists('message_id', $response)) {
            throw new InvalidArgumentException('Invalid missing-turn rejection.');
        }
        $id = $this->nextId++;
        $this->turns[$id] = array(
            'id' => $id,
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
            'request_hash' => hash('sha256', "ysai-missing-turn-v1\0" . $conversationId . "\0" . $clientTurnId),
            'status' => 'failed',
            'claim_version' => 1,
            'lease_seconds' => $leaseSeconds,
            'response' => $response,
            'error_code' => $errorCode,
            'updated_at' => $this->now->format(DATE_ATOM),
        );
        return $this->publicTurnRecord($this->turns[$id]);
    }

    public function blockingRecoveryCandidate(string $conversationId, string $excludingClientTurnId): ?array
    {
        $candidate = null;
        foreach ($this->turns as $turn) {
            if (($turn['conversation_id'] ?? '') !== $conversationId
                || ($turn['client_turn_id'] ?? '') === $excludingClientTurnId
                || !$this->turnBlocksNewWork($turn)) {
                continue;
            }

            $status = (string) ($turn['status'] ?? '');
            $current = array(
                'status' => $status,
                'id' => (int) $turn['id'],
                'client_turn_id' => (string) $turn['client_turn_id'],
                'claim_version' => (int) $turn['claim_version'],
                'response' => is_array($turn['response'] ?? null) ? $turn['response'] : array(),
            );
            if ($status !== 'processing') {
                $candidate ??= $current;
                continue;
            }
            if ($current['response'] !== array()) {
                $candidate ??= $current;
                continue;
            }

            $updatedAt = new DateTimeImmutable((string) ($turn['updated_at'] ?? ''));
            $leaseSeconds = (int) ($turn['lease_seconds'] ?? 0);
            if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
                throw new RuntimeException('Stored turn lease is invalid.');
            }
            if ($updatedAt > $this->now->modify('-' . $leaseSeconds . ' seconds')) {
                return null;
            }
            $candidate ??= $current;
        }
        return $candidate;
    }

    public function complete(int $turnId, int $claimVersion, array $response): void
    {
        ++$this->completeCalls;
        if ($this->failComplete) {
            throw new RuntimeException('Complete failed.');
        }
        if (isset($this->turns[$turnId])
            && (int) $this->turns[$turnId]['claim_version'] === $claimVersion
            && $this->turns[$turnId]['status'] === 'completed'
            && $this->turns[$turnId]['response'] === $response) {
            return;
        }
        $turn = $this->ownedProcessingTurn($turnId, $claimVersion, 'completed');
        if (($turn['response'] ?? array()) !== array()) {
            $checkpoint = $turn['response'];
            $final = $response;
            $messageId = $final['message_id'] ?? null;
            unset($final['message_id']);
            if (($checkpoint['ok'] ?? null) !== true
                || !is_int($messageId)
                || $messageId <= 0
                || $final !== $checkpoint) {
                throw new TurnLeaseLost('The stored turn checkpoint cannot be replaced during finalization.');
            }
        } else {
            $this->assertFreshLease($turn, 'completed');
        }
        $this->turns[$turnId]['status'] = 'completed';
        $this->turns[$turnId]['response'] = $response;
        $this->turns[$turnId]['updated_at'] = $this->now->format(DATE_ATOM);
    }

    public function attachTerminalMessageId(int $turnId, int $messageId): void
    {
        ++$this->attachTerminalMessageIdCalls;
        if ($this->failAttachTerminalMessageId) {
            throw new RuntimeException('Terminal message attachment failed.');
        }
        if ($messageId <= 0
            || !isset($this->turns[$turnId])
            || !in_array($this->turns[$turnId]['status'], array('completed', 'failed'), true)) {
            throw new RuntimeException('Terminal turn cannot be enriched.');
        }
        if ($this->turns[$turnId]['status'] === 'failed'
            && ($this->turns[$turnId]['response']['request_accepted'] ?? null) === false) {
            throw new RuntimeException('Rejected turn does not require a message.');
        }
        if (array_key_exists('message_id', $this->turns[$turnId]['response'])) {
            if ($this->turns[$turnId]['response']['message_id'] === $messageId) {
                return;
            }
            throw new RuntimeException('Terminal turn references another message.');
        }
        $this->turns[$turnId]['response']['message_id'] = $messageId;
    }

    public function fail(int $turnId, int $claimVersion, string $errorCode, array $response): void
    {
        ++$this->failCalls;
        if ($this->failFail) {
            throw new RuntimeException('Fail failed.');
        }
        if (isset($this->turns[$turnId])
            && (int) $this->turns[$turnId]['claim_version'] === $claimVersion
            && $this->turns[$turnId]['status'] === 'failed'
            && $this->turns[$turnId]['error_code'] === $errorCode
            && $this->turns[$turnId]['response'] === $response) {
            return;
        }
        $turn = $this->ownedProcessingTurn($turnId, $claimVersion, 'failed');
        if (($turn['response'] ?? array()) !== array()) {
            throw new TurnLeaseLost('A checkpointed turn cannot be replaced by a failed response.');
        }
        $this->assertFreshLease($turn, 'failed');
        $this->turns[$turnId]['status'] = 'failed';
        $this->turns[$turnId]['error_code'] = $errorCode;
        $this->turns[$turnId]['response'] = $response;
        $this->turns[$turnId]['updated_at'] = $this->now->format(DATE_ATOM);
    }

    public function find(string $conversationId, string $clientTurnId): ?array
    {
        foreach ($this->turns as $turn) {
            if ($turn['conversation_id'] === $conversationId && $turn['client_turn_id'] === $clientTurnId) {
                return $this->publicTurnRecord($turn);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $turn @return array<string,mixed> */
    private function publicTurnRecord(array $turn): array
    {
        return array(
            'id' => (int) $turn['id'],
            'claim_version' => (int) $turn['claim_version'],
            'status' => (string) $turn['status'],
            'response' => is_array($turn['response'] ?? null) ? $turn['response'] : array(),
            'error_code' => (string) ($turn['error_code'] ?? ''),
            'updated_at' => (string) ($turn['updated_at'] ?? $this->now->format(DATE_ATOM)),
        );
    }

    /** Simulate a stale-turn reclaim by a newer worker. */
    public function reclaim(int $turnId): int
    {
        if (!isset($this->turns[$turnId]) || $this->turns[$turnId]['status'] !== 'processing') {
            throw new RuntimeException('Only a processing turn can be reclaimed.');
        }
        ++$this->turns[$turnId]['claim_version'];
        $this->turns[$turnId]['updated_at'] = $this->now->format(DATE_ATOM);
        return (int) $this->turns[$turnId]['claim_version'];
    }

    /** @param array<string,mixed> $turn */
    private function turnBlocksNewWork(array $turn): bool
    {
        $status = (string) ($turn['status'] ?? '');
        if ($status === 'processing') {
            return true;
        }
        if (!in_array($status, array('completed', 'failed'), true)) {
            return false;
        }
        $response = is_array($turn['response'] ?? null) ? $turn['response'] : array();
        if (is_int($response['message_id'] ?? null) && $response['message_id'] > 0) {
            return false;
        }
        return $status === 'completed' || ($response['request_accepted'] ?? null) === true;
    }

    /** @return array<string,mixed> */
    private function ownedProcessingTurn(int $turnId, int $claimVersion, string $operation): array
    {
        if ($turnId <= 0 || $claimVersion <= 0) {
            throw new InvalidArgumentException('Turn claim identifiers must be positive.');
        }
        $turn = $this->turns[$turnId] ?? null;
        if (!is_array($turn)
            || (int) ($turn['claim_version'] ?? 0) !== $claimVersion
            || ($turn['status'] ?? '') !== 'processing') {
            throw new TurnLeaseLost('The stale worker cannot mark the turn as ' . $operation . '.');
        }
        return $turn;
    }

    /** @param array<string,mixed> $turn */
    private function assertFreshLease(array $turn, string $operation): void
    {
        $updatedAt = new DateTimeImmutable((string) ($turn['updated_at'] ?? ''));
        $leaseSeconds = (int) ($turn['lease_seconds'] ?? 0);
        if ($leaseSeconds < 30
            || $leaseSeconds > 3600
            || $updatedAt <= $this->now->modify('-' . $leaseSeconds . ' seconds')) {
            throw new TurnLeaseLost('The turn lease expired before it could be marked as ' . $operation . '.');
        }
    }
}

final class AllowAllRateLimiter implements RateLimiter
{
    public int $consumeCalls = 0;
    /** @var list<array{scope:string,identifier:string,limit:int,window_seconds:int}> */
    public array $calls = array();
    /** @var list<string> */
    public array $deniedScopes = array();

    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds): bool
    {
        ++$this->consumeCalls;
        $this->calls[] = array(
            'scope' => $scope,
            'identifier' => $identifier,
            'limit' => $limit,
            'window_seconds' => $windowSeconds,
        );
        return !in_array($scope, $this->deniedScopes, true);
    }

    public function purge(): int
    {
        return 0;
    }

    public function clear(): int
    {
        return 0;
    }
}

function test_logger(): Logger
{
    $hadOption = array_key_exists(Settings::OPTION_KEY, $GLOBALS['ysai_test_options']);
    $previous = $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] ?? null;
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = array('diagnostic_logging' => 0);
    $settings = new Settings(new SecretBox());
    // Prime this logger's immutable test view, then restore the calling test's
    // option state so constructing a logger cannot erase provider credentials.
    $settings->all();
    if ($hadOption) {
        $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = $previous;
    } else {
        unset($GLOBALS['ysai_test_options'][Settings::OPTION_KEY]);
    }
    return new Logger($settings);
}
