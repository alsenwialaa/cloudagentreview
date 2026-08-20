<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Application\Contract\TurnGuard;
use YassinStore\AiAssistant\Application\Support\SensitiveData;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Domain\Shared\Base64Url;

final class ToolContext
{
    private const MAX_PRODUCT_REFERENCES = 120;
    private const MAX_RESTORED_REFERENCES = 80;
    private const MAX_PRODUCT_TYPE_LENGTH = 40;
    private const MAX_PRODUCT_CARD_BYTES = 32768;
    private const MAX_CONTINUATIONS = 12;
    private const MAX_CONTINUATION_SEEN_IDS = 240;
    private const MAX_CONTINUATION_BASE_EXCLUSIONS = 24;
    private const CONTINUATION_TTL_SECONDS = 1800;
    private const CLARIFICATION_TTL_SECONDS = 1800;

    /** @var array<string,array<string,mixed>> */
    private array $products = array();
    /** @var array<string,string> */
    private array $productFingerprintRefs = array();
    /** @var array<string,array<string,mixed>> */
    private array $lines = array();
    /** @var array<string,array<string,mixed>> */
    private array $content = array();
    /** @var list<string> */
    private array $lastProductRefs = array();
    private string $cartSnapshotSignature = '';
    private string $cartPersistenceSignature = '';
    private bool $cartMutationAttempted = false;
    private int $catalogGeneration = 0;
    /** @var array<string,array<string,mixed>> */
    private array $catalogContinuations = array();
    private bool $catalogContextRestored = false;
    /** @var array<string,mixed> */
    private array $cartClarification = array('status' => 'cleared', 'generation' => 0);
    private bool $cartClarificationRestored = false;

    public function __construct(
        private readonly string $operationKey = '',
        private readonly ?TurnGuard $turnGuard = null,
        private readonly int $fixedNow = 0
    ) {
    }

    /**
     * @param array{id:int,parent_id:int,type:string,fingerprint:string} $identity
     * @param array<string,mixed> $card
     */
    public function registerProduct(array $identity, array $card): string
    {
        $identity = $this->requireProductIdentity($identity);
        $this->requireProductCard($card);
        $fingerprint = $identity['fingerprint'];
        if (isset($this->productFingerprintRefs[$fingerprint])) {
            $existingRef = $this->productFingerprintRefs[$fingerprint];
            if (isset($this->products[$existingRef])) {
                $this->touchProduct($existingRef, array('identity' => $identity, 'card' => $card));
                return $existingRef;
            }
            unset($this->productFingerprintRefs[$fingerprint]);
        }
        $this->makeProductCapacity();
        $ref = $this->newRef('p');
        $this->products[$ref] = array('identity' => $identity, 'card' => $card);
        $this->productFingerprintRefs[$fingerprint] = $ref;
        return $ref;
    }

    /**
     * Restore a server-owned product reference from a prior assistant message.
     *
     * @param array{id:int,parent_id:int,type:string,fingerprint:string} $identity
     * @param array<string,mixed> $card
     */
    public function restoreProduct(string $ref, array $identity, array $card): void
    {
        if (preg_match('/^p_[A-Za-z0-9_-]{8,80}$/', $ref) !== 1) {
            return;
        }
        $identity = $this->validProductIdentity($identity);
        if ($identity === null || !$this->validProductCard($card)) {
            return;
        }
        $fingerprint = $identity['fingerprint'];
        if (isset($this->products[$ref])) {
            $existingFingerprint = (string) ($this->products[$ref]['identity']['fingerprint'] ?? '');
            if (!hash_equals($existingFingerprint, $fingerprint)) {
                return;
            }
            // History is restored newest-first. The first valid occurrence is
            // authoritative for both its card data and fingerprint mapping.
            return;
        }
        $this->makeProductCapacity();
        $this->products[$ref] = array('identity' => $identity, 'card' => $card);
        if (!isset($this->productFingerprintRefs[$fingerprint])) {
            $this->productFingerprintRefs[$fingerprint] = $ref;
        }
    }

    /** @param array<string,mixed> $snapshot */
    public function restoreProducts(array $snapshot): void
    {
        foreach (array_slice($snapshot, -self::MAX_RESTORED_REFERENCES, null, true) as $ref => $entry) {
            if (!is_string($ref) || !is_array($entry)) {
                continue;
            }
            $identity = is_array($entry['identity'] ?? null) ? $entry['identity'] : array();
            $card = is_array($entry['card'] ?? null) ? $entry['card'] : array();
            if (isset($identity['id'], $identity['parent_id'], $identity['type'], $identity['fingerprint'])) {
                $this->restoreProduct($ref, $identity, $card);
            }
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function productSnapshot(int $limit = 40): array
    {
        return array_slice($this->products, -max(1, min(self::MAX_RESTORED_REFERENCES, $limit)), null, true);
    }

    public function productCount(): int
    {
        return count($this->products);
    }

    /** @return array<string,mixed> */
    public function product(string $ref): array
    {
        if (!isset($this->products[$ref])) {
            throw new \InvalidArgumentException('The product reference is missing or stale.');
        }
        return $this->products[$ref];
    }

    /**
     * Start a new authoritative cart snapshot. Any line references from an
     * earlier view become stale immediately.
     */
    public function beginCartSnapshot(string $signature): void
    {
        $signature = strtolower(trim($signature));
        if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            throw new \InvalidArgumentException('A valid cart snapshot signature is required.');
        }
        $this->lines = array();
        $this->cartSnapshotSignature = $signature;
        $this->cartPersistenceSignature = '';
    }

    /**
     * Bind the cart view to a signature read from WooCommerce's durable
     * session storage. The gateway must verify this again while holding the
     * cart lock before it mutates anything.
     */
    public function bindCartPersistence(string $signature): void
    {
        $signature = strtolower(trim($signature));
        if (preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            throw new \InvalidArgumentException('A valid persisted cart signature is required.');
        }
        if (!$this->hasFreshCartView()) {
            throw new \LogicException('A logical cart snapshot must be started before persistence is bound.');
        }
        $this->cartPersistenceSignature = $signature;
    }

    public function hasCartPersistenceBinding(): bool
    {
        return $this->cartPersistenceSignature !== '';
    }

    public function cartPersistenceSignature(): string
    {
        return $this->cartPersistenceSignature;
    }

    public function hasFreshCartView(): bool
    {
        return $this->cartSnapshotSignature !== '';
    }

    public function cartSnapshotSignature(): string
    {
        return $this->cartSnapshotSignature;
    }

    public function markCartMutationAttempted(): void
    {
        $this->cartMutationAttempted = true;
    }

    public function hasCartMutationAttempted(): bool
    {
        return $this->cartMutationAttempted;
    }

    public function operationKey(): string
    {
        return $this->operationKey;
    }

    /**
     * Renew and verify the current processing-turn lease. Contexts created for
     * read-only boot/cart views have no guard and therefore need no heartbeat.
     */
    public function heartbeatTurn(): void
    {
        $this->turnGuard?->heartbeat();
    }

    /** @return array{turn_id:int,claim_version:int} */
    public function guardedTurnClaim(): array
    {
        if ($this->turnGuard === null) {
            throw new \LogicException('A processing-turn claim is required for this state change.');
        }
        return $this->turnGuard->claim();
    }

    /** @param array<string,mixed> $authority @param array<string,mixed> $presentation */
    public function registerLine(array $authority, array $presentation): string
    {
        $ref = $this->newRef('l');
        $this->lines[$ref] = array('authority' => $authority, 'presentation' => $presentation);
        return $ref;
    }

    /** @return array<string,mixed> */
    public function line(string $ref): array
    {
        if (!isset($this->lines[$ref])) {
            throw new \InvalidArgumentException('The cart-line reference is missing or stale.');
        }
        return $this->lines[$ref];
    }

    /** @param array<string,mixed> $document */
    public function registerContent(array $document): string
    {
        $ref = $this->newRef('c');
        $this->content[$ref] = $document;
        return $ref;
    }

    /** @return array<string,mixed> */
    public function content(string $ref): array
    {
        if (!isset($this->content[$ref])) {
            throw new \InvalidArgumentException('The content reference is missing or stale.');
        }
        return $this->content[$ref];
    }

    /** @param list<string> $refs */
    public function rememberProducts(array $refs): void
    {
        $this->lastProductRefs = array_values(array_filter(
            $refs,
            fn (string $ref): bool => isset($this->products[$ref])
        ));
    }

    /** @param list<string>|null $refs @return list<array<string,mixed>> */
    public function cards(?array $refs = null, int $limit = 12): array
    {
        $refs = $refs ?? $this->lastProductRefs;
        $cards = array();
        foreach (array_slice(array_values(array_unique($refs)), 0, max(1, $limit)) as $ref) {
            if (!isset($this->products[$ref])) {
                continue;
            }
            $card = $this->products[$ref]['card'];
            $card['ref'] = $ref;
            $cards[] = $card;
        }
        return $cards;
    }


    /**
     * Start a new catalog traversal. Every older active continuation is
     * invalidated before a replacement reference is issued.
     *
     * @param array<string,mixed> $filters
     * @param list<array{query:string,normalized:string,weight:float,source:string}> $plan
     * @param list<int> $seenIds
     */
    public function beginCatalogContinuation(
        string $query,
        array $filters,
        string $sort,
        array $plan,
        array $seenIds,
        bool $hasMore
    ): ?string {
        $this->catalogGeneration = $this->nextGeneration($this->catalogGeneration);
        foreach ($this->catalogContinuations as $ref => $entry) {
            if (($entry['status'] ?? '') === 'active') {
                $this->catalogContinuations[$ref] = $this->continuationTombstone($entry);
            }
        }
        $this->pruneCatalogContinuations();
        if (!$hasMore) {
            return null;
        }
        return $this->createCatalogContinuation($query, $filters, $sort, $plan, $seenIds, $this->catalogGeneration);
    }

    /** @return array<string,mixed> */
    public function catalogContinuation(string $ref): array
    {
        if (preg_match('/^n_[A-Za-z0-9_-]{8,80}$/D', $ref) !== 1) {
            throw new \InvalidArgumentException('The catalog continuation reference is invalid.');
        }
        $this->pruneCatalogContinuations();
        $entry = $this->catalogContinuations[$ref] ?? null;
        if (!is_array($entry)
            || ($entry['status'] ?? '') !== 'active'
            || (int) ($entry['expires_at'] ?? 0) <= $this->now()
            || (int) ($entry['generation'] ?? 0) !== $this->catalogGeneration) {
            throw new \InvalidArgumentException('The catalog continuation reference is missing, consumed, or expired.');
        }
        return $entry;
    }

    /**
     * Consume exactly one continuation reference and optionally create its
     * successor. Replaying the consumed reference always fails.
     *
     * @param list<int> $seenIds
     */
    public function advanceCatalogContinuation(string $ref, array $seenIds, bool $hasMore): ?string
    {
        $entry = $this->catalogContinuation($ref);
        $this->catalogContinuations[$ref] = $this->continuationTombstone($entry);
        if (!$hasMore) {
            $this->pruneCatalogContinuations();
            return null;
        }
        return $this->createCatalogContinuation(
            (string) ($entry['query'] ?? ''),
            is_array($entry['filters'] ?? null) ? $entry['filters'] : array(),
            (string) ($entry['sort'] ?? 'relevance'),
            is_array($entry['plan'] ?? null) ? $entry['plan'] : array(),
            $seenIds,
            (int) ($entry['generation'] ?? $this->catalogGeneration)
        );
    }

    /** @return array<string,mixed> */
    public function catalogContextSnapshot(): array
    {
        $this->pruneCatalogContinuations();
        return array(
            'version' => 1,
            'generation' => $this->catalogGeneration,
            'continuations' => array_slice($this->catalogContinuations, -self::MAX_CONTINUATIONS, null, true),
        );
    }

    /**
     * Return the single live continuation token in a deliberately reduced
     * model-facing form. The continuation remains untrusted, one-use browsing
     * context and never conveys cart or mutation authority.
     *
     * @return array{continuation_ref:string,expires_at:int,query:string,sort:string,seen_product_count:int}|null
     */
    public function activeCatalogContinuation(): ?array
    {
        $this->pruneCatalogContinuations();
        $active = array();
        foreach ($this->catalogContinuations as $ref => $entry) {
            if (($entry['status'] ?? '') !== 'active'
                || (int) ($entry['generation'] ?? -1) !== $this->catalogGeneration) {
                continue;
            }
            $active[$ref] = $entry;
        }

        // A valid runtime can have only one active token. Ambiguous persisted
        // state is treated as unavailable rather than choosing one arbitrarily.
        if (count($active) !== 1) {
            return null;
        }

        $ref = array_key_first($active);
        if (!is_string($ref)) {
            return null;
        }
        $entry = $active[$ref];
        return array(
            'continuation_ref' => $ref,
            'expires_at' => (int) ($entry['expires_at'] ?? 0),
            'query' => Text::plain((string) ($entry['query'] ?? ''), 240),
            'sort' => (string) ($entry['sort'] ?? 'relevance'),
            'seen_product_count' => count(is_array($entry['seen_ids'] ?? null) ? $entry['seen_ids'] : array()),
        );
    }

    /** @param array<string,mixed> $snapshot */
    public function restoreCatalogContext(array $snapshot): void
    {
        if ($this->catalogContextRestored) {
            return;
        }
        // History is scanned newest-first. Even a malformed newest envelope is
        // authoritative and must prevent resurrection from older messages.
        $this->catalogContextRestored = true;
        $generation = $snapshot['generation'] ?? null;
        $entries = $snapshot['continuations'] ?? null;
        if (($snapshot['version'] ?? null) !== 1
            || !is_int($generation) || $generation < 0 || $generation > 1_000_000_000
            || !is_array($entries) || array_is_list($entries)) {
            return;
        }
        $this->catalogGeneration = $generation;
        $validatedEntries = array();
        $activeEntries = array();
        foreach (array_slice($entries, -self::MAX_CONTINUATIONS, null, true) as $ref => $entry) {
            if (!is_string($ref) || !is_array($entry)) {
                continue;
            }
            $validated = $this->validContinuationEntry($ref, $entry);
            if ($validated !== null) {
                if (($validated['status'] ?? '') === 'active') {
                    $activeEntries[$ref] = $validated;
                } else {
                    $validatedEntries[$ref] = $validated;
                }
            }
        }

        // Exactly one active entry for the snapshot generation is the only
        // shape the runtime can emit. Reject all active entries when a stored
        // envelope is ambiguous or carries a stale-generation active token.
        if (count($activeEntries) === 1) {
            $activeRef = array_key_first($activeEntries);
            if (is_string($activeRef)
                && (int) ($activeEntries[$activeRef]['generation'] ?? -1) === $generation) {
                $validatedEntries[$activeRef] = $activeEntries[$activeRef];
            }
        }
        $this->catalogContinuations = $validatedEntries;
        $this->pruneCatalogContinuations();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function setCartClarification(array $input): array
    {
        $intent = $this->normalizeCartClarificationIntent($input, true);
        $productRef = is_string($intent['product_ref'] ?? null) ? $intent['product_ref'] : '';
        if ($productRef !== '' && isset($this->products[$productRef])) {
            // Product snapshots are intentionally bounded. Keep the authority
            // required by an active clarification inside the newest persisted
            // window so a valid pending request cannot clear itself next turn.
            $this->touchProduct($productRef, $this->products[$productRef]);
        }
        $generation = $this->nextGeneration(max(0, (int) ($this->cartClarification['generation'] ?? 0)));
        $this->cartClarification = array(
            'version' => 1,
            'status' => 'pending',
            'generation' => $generation,
            'ref' => $this->newRef('q'),
            'expires_at' => $this->now() + self::CLARIFICATION_TTL_SECONDS,
            'intent' => $intent,
        );
        return $this->cartClarification;
    }

    public function clearCartClarification(): void
    {
        $generation = $this->nextGeneration(max(0, (int) ($this->cartClarification['generation'] ?? 0)));
        $this->cartClarification = array(
            'version' => 1,
            'status' => 'cleared',
            'generation' => $generation,
            'cleared_at' => $this->now(),
        );
    }

    /** @return array<string,mixed>|null */
    public function pendingCartClarification(): ?array
    {
        if (($this->cartClarification['status'] ?? '') !== 'pending') {
            return null;
        }
        if ((int) ($this->cartClarification['expires_at'] ?? 0) <= $this->now()) {
            $this->clearCartClarification();
            return null;
        }
        $intent = is_array($this->cartClarification['intent'] ?? null)
            ? $this->cartClarification['intent']
            : array();
        $productRef = is_string($intent['product_ref'] ?? null)
            ? $intent['product_ref']
            : '';
        if ($productRef !== '' && !isset($this->products[$productRef])) {
            // A pending request may describe a missing product without a ref,
            // but it must never expose a stale opaque product reference to the
            // model after its authoritative product snapshot has disappeared.
            $this->clearCartClarification();
            return null;
        }
        return $this->cartClarification;
    }

    /** @return array<string,mixed> */
    public function cartClarificationSnapshot(): array
    {
        $this->pendingCartClarification();
        return $this->cartClarification;
    }

    /** @param array<string,mixed> $snapshot */
    public function restoreCartClarification(array $snapshot): void
    {
        if ($this->cartClarificationRestored) {
            return;
        }
        $this->cartClarificationRestored = true;
        $validated = $this->validCartClarification($snapshot);
        if ($validated !== null) {
            $this->cartClarification = $validated;
        }
        $this->pendingCartClarification();
    }


    /**
     * @param array<string,mixed> $identity
     * @return array{id:int,parent_id:int,type:string,fingerprint:string}
     */
    private function requireProductIdentity(array $identity): array
    {
        $validated = $this->validProductIdentity($identity);
        if ($validated === null) {
            throw new \UnexpectedValueException('The catalog returned an invalid product identity.');
        }
        return $validated;
    }

    /** @param array<string,mixed> $card */
    private function requireProductCard(array $card): void
    {
        if (!$this->validProductCard($card)) {
            throw new \UnexpectedValueException('The catalog returned an invalid or oversized product card.');
        }
    }

    /**
     * Product identities cross the catalog/application boundary and are later
     * persisted as authorization context. Accept only the exact bounded shape
     * required by that contract; PHP scalar coercion is deliberately rejected.
     *
     * @param array<string,mixed> $identity
     * @return array{id:int,parent_id:int,type:string,fingerprint:string}|null
     */
    private function validProductIdentity(array $identity): ?array
    {
        $id = $identity['id'] ?? null;
        $parentId = $identity['parent_id'] ?? null;
        $type = $identity['type'] ?? null;
        $fingerprint = $identity['fingerprint'] ?? null;
        if (!is_int($id) || $id < 1
            || !is_int($parentId) || $parentId < 0
            || !is_string($type)
            || $type === ''
            || strlen($type) > self::MAX_PRODUCT_TYPE_LENGTH
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $type) !== 1
            || !is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return null;
        }

        return array(
            'id' => $id,
            'parent_id' => $parentId,
            'type' => $type,
            'fingerprint' => $fingerprint,
        );
    }

    /** @param array<string,mixed> $card */
    private function validProductCard(array $card): bool
    {
        if (array_is_list($card)) {
            return false;
        }
        try {
            $encoded = json_encode(
                $card,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return false;
        }
        return strlen($encoded) <= self::MAX_PRODUCT_CARD_BYTES;
    }


    /**
     * @param array<string,mixed> $filters
     * @param list<array{query:string,normalized:string,weight:float,source:string}> $plan
     * @param list<int> $seenIds
     */
    private function createCatalogContinuation(
        string $query,
        array $filters,
        string $sort,
        array $plan,
        array $seenIds,
        int $generation
    ): string {
        $entry = $this->normalizeContinuationPayload(
            $query,
            $filters,
            $sort,
            $plan,
            $seenIds,
            $generation,
            $this->now() + self::CONTINUATION_TTL_SECONDS
        );
        $ref = $this->newRef('n');
        $this->catalogContinuations[$ref] = $entry;
        $this->pruneCatalogContinuations();
        return $ref;
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function continuationTombstone(array $entry): array
    {
        return array(
            'status' => 'consumed',
            'generation' => max(0, (int) ($entry['generation'] ?? 0)),
            'expires_at' => max($this->now() + self::CONTINUATION_TTL_SECONDS, (int) ($entry['expires_at'] ?? 0)),
        );
    }

    private function pruneCatalogContinuations(): void
    {
        $now = $this->now();
        foreach ($this->catalogContinuations as $ref => $entry) {
            if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) <= $now) {
                unset($this->catalogContinuations[$ref]);
            }
        }
        while (count($this->catalogContinuations) > self::MAX_CONTINUATIONS) {
            $oldest = array_key_first($this->catalogContinuations);
            if (!is_string($oldest)) {
                break;
            }
            unset($this->catalogContinuations[$oldest]);
        }
    }

    /** @param array<string,mixed> $entry @return array<string,mixed>|null */
    private function validContinuationEntry(string $ref, array $entry): ?array
    {
        if (preg_match('/^n_[A-Za-z0-9_-]{8,80}$/D', $ref) !== 1) {
            return null;
        }
        $status = $entry['status'] ?? null;
        $generation = $entry['generation'] ?? null;
        $expiresAt = $entry['expires_at'] ?? null;
        if (!is_string($status)
            || !in_array($status, array('active', 'consumed'), true)
            || !is_int($generation) || $generation < 0 || $generation > 1_000_000_000
            || !is_int($expiresAt) || $expiresAt <= $this->now()
            || $expiresAt > $this->now() + self::CONTINUATION_TTL_SECONDS + 60) {
            return null;
        }
        if ($status === 'consumed') {
            if (array_diff(array_keys($entry), array('status', 'generation', 'expires_at')) !== array()) {
                return null;
            }
            return array('status' => 'consumed', 'generation' => $generation, 'expires_at' => $expiresAt);
        }
        if (array_diff(
            array_keys($entry),
            array('status', 'generation', 'expires_at', 'query', 'filters', 'sort', 'plan', 'seen_ids')
        ) !== array()) {
            return null;
        }
        try {
            return $this->normalizeContinuationPayload(
                is_string($entry['query'] ?? null) ? $entry['query'] : '',
                is_array($entry['filters'] ?? null) ? $entry['filters'] : array(),
                is_string($entry['sort'] ?? null) ? $entry['sort'] : '',
                is_array($entry['plan'] ?? null) ? $entry['plan'] : array(),
                is_array($entry['seen_ids'] ?? null) ? $entry['seen_ids'] : array(),
                $generation,
                $expiresAt
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<array<string,mixed>> $plan
     * @param list<mixed> $seenIds
     * @return array<string,mixed>
     */
    private function normalizeContinuationPayload(
        string $query,
        array $filters,
        string $sort,
        array $plan,
        array $seenIds,
        int $generation,
        int $expiresAt
    ): array {
        $query = Text::plain($query, 240);
        if (!in_array($sort, array('relevance', 'price_low', 'price_high', 'newest', 'best_selling', 'rating'), true)) {
            throw new \InvalidArgumentException('The continuation sort is invalid.');
        }
        if ($generation < 0 || $generation > 1_000_000_000) {
            throw new \InvalidArgumentException('The continuation generation is invalid.');
        }
        if ($expiresAt <= $this->now()
            || $expiresAt > $this->now() + self::CONTINUATION_TTL_SECONDS + 60) {
            throw new \InvalidArgumentException('The continuation expiry is invalid.');
        }
        if (!array_is_list($plan) || count($plan) < 1 || count($plan) > 8) {
            throw new \InvalidArgumentException('The continuation query plan is invalid.');
        }

        $validatedPlan = array();
        foreach ($plan as $row) {
            if (!is_array($row) || array_is_list($row)
                || array_diff(array_keys($row), array('query', 'normalized', 'weight', 'source')) !== array()) {
                throw new \InvalidArgumentException('The continuation query plan is invalid.');
            }
            $plannedQuery = is_string($row['query'] ?? null) ? Text::plain($row['query'], 240) : '';
            $normalized = is_string($row['normalized'] ?? null) ? Text::plain($row['normalized'], 240) : '';
            $weight = $row['weight'] ?? null;
            $source = is_string($row['source'] ?? null) ? Text::plain($row['source'], 40) : '';
            $browsePlan = $plannedQuery === '' && $normalized === '' && $source === 'browse';
            if ((!$browsePlan && ($plannedQuery === '' || $normalized === ''))
                || (!is_int($weight) && !is_float($weight))
                || !is_finite((float) $weight)
                || (float) $weight < 0.0 || (float) $weight > 1.0
                || $source === '') {
                throw new \InvalidArgumentException('The continuation query plan is invalid.');
            }
            if (($query === '') !== $browsePlan) {
                throw new \InvalidArgumentException('The continuation query plan does not match its traversal type.');
            }
            $validatedPlan[] = array(
                'query' => $plannedQuery,
                'normalized' => $normalized,
                'weight' => (float) $weight,
                'source' => $source,
            );
        }

        $validatedFilters = $this->validContinuationFilters($filters);
        $validatedSeenIds = $this->validSeenIds($seenIds);
        $baseExcludedIds = is_array($validatedFilters['exclude_ids'] ?? null)
            ? $validatedFilters['exclude_ids']
            : array();
        $seenCapacity = self::MAX_CONTINUATION_SEEN_IDS - count($baseExcludedIds);
        if (count($validatedSeenIds) > $seenCapacity) {
            throw new \InvalidArgumentException('Catalog continuation history exceeds its bounded traversal capacity.');
        }

        return array(
            'status' => 'active',
            'generation' => $generation,
            'expires_at' => $expiresAt,
            'query' => $query,
            'filters' => $validatedFilters,
            'sort' => $sort,
            'plan' => $validatedPlan,
            'seen_ids' => $validatedSeenIds,
        );
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function validContinuationFilters(array $filters): array
    {
        if (array_is_list($filters) && $filters !== array()) {
            throw new \InvalidArgumentException('The continuation filters must be an object.');
        }
        $allowed = array('category', 'min_price', 'max_price', 'in_stock', 'on_sale', 'exclude_ids');
        $out = array();
        foreach ($filters as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('The continuation filter is invalid.');
            }
            if ($key === 'category') {
                if (!is_string($value) || Text::plain($value, 160) === '') {
                    throw new \InvalidArgumentException('The continuation category is invalid.');
                }
                $out[$key] = Text::plain($value, 160);
            } elseif ($key === 'min_price' || $key === 'max_price') {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                    || (float) $value < 0 || (float) $value > 1_000_000_000_000) {
                    throw new \InvalidArgumentException('The continuation price is invalid.');
                }
                $out[$key] = (float) $value;
            } elseif ($key === 'exclude_ids') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('The continuation exclusions are invalid.');
                }
                $out[$key] = $this->validExcludedIds($value);
            } else {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('The continuation availability filter is invalid.');
                }
                $out[$key] = $value;
            }
        }
        if (isset($out['min_price'], $out['max_price']) && $out['min_price'] > $out['max_price']) {
            throw new \InvalidArgumentException('The continuation price range is invalid.');
        }
        return $out;
    }

    /** @param list<mixed> $ids @return list<int> */
    private function validSeenIds(array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > self::MAX_CONTINUATION_SEEN_IDS) {
            throw new \InvalidArgumentException('Catalog continuation IDs must be a bounded list.');
        }
        $seen = array();
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Catalog continuation IDs contain an invalid value.');
            }
            $seen[$id] = true;
        }
        return array_keys($seen);
    }

    /** @param list<mixed> $ids @return list<int> */
    private function validExcludedIds(array $ids): array
    {
        if (!array_is_list($ids) || count($ids) > self::MAX_CONTINUATION_BASE_EXCLUSIONS) {
            throw new \InvalidArgumentException('Catalog continuation exclusions must be a bounded list.');
        }
        $seen = array();
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('Catalog continuation exclusions contain an invalid value.');
            }
            $seen[$id] = true;
        }
        return array_keys($seen);
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed>|null */
    private function validCartClarification(array $snapshot): ?array
    {
        $status = $snapshot['status'] ?? null;
        $generation = $snapshot['generation'] ?? null;
        if (($snapshot['version'] ?? null) !== 1
            || !is_string($status) || !in_array($status, array('pending', 'cleared'), true)
            || !is_int($generation) || $generation < 0 || $generation > 1_000_000_000) {
            return null;
        }
        if ($status === 'cleared') {
            if (array_diff(array_keys($snapshot), array('version', 'status', 'generation', 'cleared_at')) !== array()) {
                return null;
            }
            $clearedAt = is_int($snapshot['cleared_at'] ?? null) ? $snapshot['cleared_at'] : $this->now();
            return array(
                'version' => 1,
                'status' => 'cleared',
                'generation' => $generation,
                'cleared_at' => min($this->now() + 60, max(0, $clearedAt)),
            );
        }
        if (array_diff(
            array_keys($snapshot),
            array('version', 'status', 'generation', 'ref', 'expires_at', 'intent')
        ) !== array()) {
            return null;
        }
        $ref = $snapshot['ref'] ?? null;
        $expiresAt = $snapshot['expires_at'] ?? null;
        $intent = $snapshot['intent'] ?? null;
        if (!is_string($ref) || preg_match('/^q_[A-Za-z0-9_-]{8,80}$/D', $ref) !== 1
            || !is_int($expiresAt) || $expiresAt <= $this->now()
            || $expiresAt > $this->now() + self::CLARIFICATION_TTL_SECONDS + 60
            || !is_array($intent) || array_is_list($intent)) {
            return null;
        }
        try {
            $intent = $this->normalizeCartClarificationIntent($intent, false);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return array(
            'version' => 1,
            'status' => 'pending',
            'generation' => $generation,
            'ref' => $ref,
            'expires_at' => $expiresAt,
            'intent' => $intent,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeCartClarificationIntent(array $input, bool $requireKnownProduct): array
    {
        if (array_is_list($input)
            || array_diff(
                array_keys($input),
                array('action', 'missing', 'product_ref', 'quantity_mode', 'requested_quantity', 'target_description')
            ) !== array()) {
            throw new \InvalidArgumentException('The pending cart clarification contains unsupported fields.');
        }
        $actions = array('add', 'set_quantity', 'increment', 'decrement', 'remove', 'replace');
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        if (!in_array($action, $actions, true)) {
            throw new \InvalidArgumentException('The pending cart action is invalid.');
        }
        $rawMissing = $input['missing'] ?? null;
        if (!is_array($rawMissing) || !array_is_list($rawMissing) || count($rawMissing) < 1 || count($rawMissing) > 4) {
            throw new \InvalidArgumentException('Pending cart clarification requires a bounded missing-fields list.');
        }
        $allowedMissing = array('product', 'target', 'variation', 'quantity');
        $missing = array();
        foreach ($rawMissing as $value) {
            if (!is_string($value) || !in_array($value, $allowedMissing, true) || isset($missing[$value])) {
                throw new \InvalidArgumentException('The pending cart missing-fields list is invalid.');
            }
            $missing[$value] = true;
        }
        $missing = array_keys($missing);

        $productRef = $this->boundedRef($input['product_ref'] ?? null, 'p');
        if ($requireKnownProduct && $productRef !== null && !isset($this->products[$productRef])) {
            throw new \InvalidArgumentException('The pending cart product reference is stale.');
        }
        $quantityMode = is_string($input['quantity_mode'] ?? null)
            ? $input['quantity_mode']
            : 'explicit';
        if (!in_array($quantityMode, array('explicit', 'preserve_source'), true)) {
            throw new \InvalidArgumentException('The pending cart quantity mode is invalid.');
        }
        $requestedQuantity = $input['requested_quantity'] ?? null;
        if ($requestedQuantity !== null
            && (!is_int($requestedQuantity) || $requestedQuantity < 1 || $requestedQuantity > 1000)) {
            throw new \InvalidArgumentException('The pending cart quantity is invalid.');
        }
        if ($quantityMode === 'preserve_source'
            && ($action !== 'replace' || $requestedQuantity !== null || in_array('quantity', $missing, true))) {
            throw new \InvalidArgumentException('Quantity preservation is valid only for a replacement with no unresolved quantity.');
        }
        if (in_array('quantity', $missing, true) && $requestedQuantity !== null) {
            throw new \InvalidArgumentException('A known quantity cannot also be marked missing.');
        }
        if ($productRef !== null && in_array('product', $missing, true)) {
            throw new \InvalidArgumentException('A known product cannot also be marked missing.');
        }
        if ($action === 'remove' && array_diff($missing, array('target')) !== array()) {
            throw new \InvalidArgumentException('A removal clarification can only be missing its cart target.');
        }
        if (in_array($action, array('set_quantity', 'increment', 'decrement'), true)
            && array_diff($missing, array('target', 'quantity')) !== array()) {
            throw new \InvalidArgumentException('A quantity clarification contains an unrelated missing field.');
        }

        if ($action === 'remove') {
            if ($quantityMode !== 'explicit' || $requestedQuantity !== null || $productRef !== null) {
                throw new \InvalidArgumentException('A removal clarification cannot carry product or quantity state.');
            }
        } elseif ($quantityMode === 'explicit') {
            $quantityMissing = in_array('quantity', $missing, true);
            if ($quantityMissing !== ($requestedQuantity === null)) {
                throw new \InvalidArgumentException('An explicit quantity must be either known or marked missing, but not both.');
            }
        }

        if ($action === 'add') {
            if (in_array('target', $missing, true)) {
                throw new \InvalidArgumentException('An add clarification cannot be missing a cart target.');
            }
            if (($productRef === null) !== in_array('product', $missing, true)) {
                throw new \InvalidArgumentException('An add clarification must consistently identify or request its product.');
            }
        } elseif ($action === 'replace') {
            if (($productRef === null) !== in_array('product', $missing, true)) {
                throw new \InvalidArgumentException('A replacement clarification must consistently identify or request its replacement product.');
            }
        } elseif ($productRef !== null) {
            throw new \InvalidArgumentException('This cart clarification does not accept a product reference.');
        }

        $targetDescription = is_string($input['target_description'] ?? null)
            ? Text::plain($input['target_description'], 160)
            : '';
        if ($targetDescription !== '' && SensitiveData::detected($targetDescription)) {
            throw new \InvalidArgumentException('Pending cart clarification cannot retain sensitive data.');
        }
        if ($action === 'add' && $targetDescription !== '') {
            throw new \InvalidArgumentException('An add clarification cannot carry an unrelated cart target description.');
        }
        return array(
            'action' => $action,
            'missing' => $missing,
            'product_ref' => $productRef,
            'quantity_mode' => $quantityMode,
            'requested_quantity' => $requestedQuantity,
            'target_description' => $targetDescription,
        );
    }

    private function boundedRef(mixed $value, string $prefix): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9_-]{8,80}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('The stored opaque reference is invalid.');
        }
        return $value;
    }

    private function now(): int
    {
        return $this->fixedNow > 0 ? $this->fixedNow : time();
    }

    private function nextGeneration(int $current): int
    {
        return $current >= 1_000_000_000 ? 1 : $current + 1;
    }

    private function newRef(string $prefix): string
    {
        do {
            $ref = $prefix . '_' . Base64Url::encode(random_bytes(12));
        } while (isset($this->products[$ref])
            || isset($this->lines[$ref])
            || isset($this->content[$ref])
            || isset($this->catalogContinuations[$ref])
            || (($this->cartClarification['ref'] ?? null) === $ref));
        return $ref;
    }


    /** @param array<string,mixed> $entry */
    private function touchProduct(string $ref, array $entry): void
    {
        unset($this->products[$ref]);
        $this->products[$ref] = $entry;
    }

    private function makeProductCapacity(): void
    {
        while (count($this->products) >= self::MAX_PRODUCT_REFERENCES) {
            $oldestRef = array_key_first($this->products);
            if (!is_string($oldestRef)) {
                break;
            }
            $oldest = $this->products[$oldestRef];
            $fingerprint = (string) ($oldest['identity']['fingerprint'] ?? '');
            unset($this->products[$oldestRef]);
            if ($fingerprint !== '' && ($this->productFingerprintRefs[$fingerprint] ?? null) === $oldestRef) {
                unset($this->productFingerprintRefs[$fingerprint]);
            }
            $this->lastProductRefs = array_values(array_filter(
                $this->lastProductRefs,
                static fn (string $ref): bool => $ref !== $oldestRef
            ));
        }
    }
}
