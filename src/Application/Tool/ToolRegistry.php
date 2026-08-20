<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Tool;

use YassinStore\AiAssistant\Application\Chat\IntentVerifier;
use YassinStore\AiAssistant\Application\Catalog\CatalogBestMatchRanker;
use YassinStore\AiAssistant\Application\Catalog\CatalogRecallPlanner;
use YassinStore\AiAssistant\Application\Catalog\CatalogTextNormalizer;
use YassinStore\AiAssistant\Application\Contract\CartGateway;
use YassinStore\AiAssistant\Application\Contract\CatalogGateway;
use YassinStore\AiAssistant\Application\Contract\ContentGateway;
use YassinStore\AiAssistant\Application\Contract\ConversationRepository;
use YassinStore\AiAssistant\Application\Contract\RuntimeSettings;
use YassinStore\AiAssistant\Application\Contract\TurnLeaseLost;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartStateUncertain;

final class ToolRegistry
{
    private const SAFE_INVALID_ARGUMENT_MESSAGE = 'The tool request is invalid or references stale live state. Refresh authoritative product or cart data, then retry using the declared schema without guessing identifiers.';
    private const MAX_RECALL_QUERIES = 8;
    private const MAX_DISCOVERY_EXCLUSIONS = 240;

    private readonly CatalogRecallPlanner $recallPlanner;
    private readonly CatalogBestMatchRanker $bestMatchRanker;

    public function __construct(
        private readonly CatalogGateway $catalog,
        private readonly CartGateway $cart,
        private readonly ContentGateway $content,
        private readonly ConversationRepository $conversations,
        private readonly IntentVerifier $intentVerifier,
        private readonly RuntimeSettings $settings,
        private readonly ShoppingMemoryPolicy $memoryPolicy,
        ?CatalogRecallPlanner $recallPlanner = null,
        ?CatalogBestMatchRanker $bestMatchRanker = null
    ) {
        $normalizer = new CatalogTextNormalizer();
        $this->recallPlanner = $recallPlanner ?? new CatalogRecallPlanner($normalizer);
        $this->bestMatchRanker = $bestMatchRanker ?? new CatalogBestMatchRanker($normalizer);
    }

    /** @return list<array<string,mixed>> */
    public function schemas(): array
    {
        $productRef = array(
            'type' => 'string',
            'pattern' => '^p_[A-Za-z0-9_-]{8,80}$',
            'description' => 'Opaque product reference copied exactly from an authoritative catalog tool result.',
        );
        $lineRef = array(
            'type' => 'string',
            'pattern' => '^l_[A-Za-z0-9_-]{8,80}$',
            'description' => 'Opaque cart-line reference copied exactly from the latest authoritative cart_view result.',
        );
        $contentRef = array(
            'type' => 'string',
            'pattern' => '^c_[A-Za-z0-9_-]{8,80}$',
            'description' => 'Opaque content reference copied exactly from an authoritative content_search result.',
        );
        $continuationRef = array(
            'type' => 'string',
            'pattern' => '^n_[A-Za-z0-9_-]{8,80}$',
            'description' => 'Opaque one-use continuation reference copied exactly from the immediately preceding catalog discovery result.',
        );
        $productRefs = array(
            'type' => 'array',
            'items' => $productRef,
            'minItems' => 0,
            'maxItems' => 12,
            'description' => 'Optional displayed-product references. Use an empty array for no cards; omit the field only to reuse the latest authoritative shortlist.',
        );

        return array(
            $this->tool('catalog_discover', 'Search or browse the live product catalog. Use continuation_ref alone to request the next non-repeating page.', array(
                'query' => array('type' => 'string', 'maxLength' => 240, 'description' => 'Optional natural-language catalog query. Omit it only for an explicit browse request or continuation.'),
                'browse' => array('type' => 'boolean', 'description' => 'Set true only when the shopper explicitly asks to browse without a search phrase.'),
                'continuation_ref' => $continuationRef,
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12),
                'category' => array('type' => 'string', 'maxLength' => 160, 'description' => 'Optional product-category name, slug, or identifier from live catalog context.'),
                'min_price' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1000000000000),
                'max_price' => array('type' => 'number', 'minimum' => 0, 'maximum' => 1000000000000),
                'in_stock' => array('type' => 'boolean'),
                'on_sale' => array('type' => 'boolean'),
                'sort' => array('type' => 'string', 'enum' => array('relevance', 'price_low', 'price_high', 'newest', 'best_selling', 'rating')),
                'exclude_product_refs' => array('type' => 'array', 'items' => $productRef, 'minItems' => 0, 'maxItems' => 24),
            )),
            $this->tool('catalog_get_details', 'Get current authoritative details for one product reference.', array(
                'product_ref' => $productRef,
            ), array('product_ref')),
            $this->tool('catalog_compare', 'Get comparable current facts for two to five product references.', array(
                'product_refs' => array('type' => 'array', 'items' => $productRef, 'minItems' => 2, 'maxItems' => 5),
            ), array('product_refs')),
            $this->tool('catalog_rank_candidates', 'Sort known product candidates using a simple explicit criterion.', array(
                'product_refs' => array('type' => 'array', 'items' => $productRef, 'minItems' => 2, 'maxItems' => 12),
                'criterion' => array(
                    'type' => 'string',
                    'enum' => array('price_low', 'price_high', 'rating', 'value', 'in_stock', 'best_match'),
                ),
            ), array('product_refs', 'criterion')),
            $this->tool('catalog_find_alternatives', 'Find current alternatives in similar catalog categories.', array(
                'product_ref' => $productRef,
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12),
            ), array('product_ref')),
            $this->tool('catalog_get_product_by_sku', 'Find one current product or variation by exact SKU.', array(
                'sku' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 120, 'description' => 'Exact non-empty SKU copied from the shopper request or authoritative catalog context.'),
            ), array('sku')),
            $this->tool('catalog_resolve_variation', 'Resolve exactly one purchasable variation from a variable product and requested attributes.', array(
                'product_ref' => $productRef,
                'attributes' => array(
                    'type' => 'object',
                    'additionalProperties' => array('type' => 'string'),
                ),
            ), array('product_ref', 'attributes')),
            $this->tool('catalog_related', 'Get current WooCommerce related products.', array(
                'product_ref' => $productRef,
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 12),
            ), array('product_ref')),
            $this->tool('catalog_list_categories', 'List populated product categories.', array(
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50),
            )),
            $this->tool('content_search', 'Search published store pages and posts for store-specific information.', array(
                'query' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 240, 'description' => 'Non-empty natural-language query for published store content.'),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 10),
            ), array('query')),
            $this->tool('content_get', 'Read one published content result by opaque reference.', array(
                'content_ref' => $contentRef,
            ), array('content_ref')),
            $this->tool('store_policy', 'Get configured links for shipping, returns, terms, and contact policies.', array()),
            $this->tool('store_info', 'Get authoritative store name, description, currency, and configured information links.', array()),
            $this->tool('shopping_memory_update', 'Save only non-sensitive shopping preferences clearly stated by the shopper.', array(
                'preferences' => array(
                    'type' => 'object',
                    'properties' => array(
                        'budget_min' => array('type' => 'number', 'minimum' => 0),
                        'budget_max' => array('type' => 'number', 'minimum' => 0),
                        'categories' => array('type' => 'array', 'items' => array('type' => 'string'), 'maxItems' => 12),
                        'attributes' => array('type' => 'object', 'additionalProperties' => array('type' => 'string')),
                        'notes' => array('type' => 'string'),
                    ),
                    'additionalProperties' => false,
                ),
                'evidence' => array('type' => 'string', 'description' => 'An exact quote copied from the current user message that states these preferences.'),
            ), array('preferences', 'evidence')),
            $this->tool('cart_view', 'Read the current live cart and obtain fresh opaque line references. Call this before every cart mutation.', array()),
            $this->tool('cart_apply', 'Immediately apply one fully specified cart plan after independent authorization. This call must be the only function call in its model step.', array(
                'commands' => array(
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'action' => array('type' => 'string', 'enum' => array('add', 'set_quantity', 'increment', 'decrement', 'remove', 'replace', 'clear')),
                            'target_ref' => $lineRef,
                            'product_ref' => $productRef,
                            'quantity' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 1000),
                            'quantity_mode' => array('type' => 'string', 'enum' => array('explicit', 'preserve_source'), 'description' => 'Use preserve_source only for replacement when the shopper explicitly asks to keep the source line quantity.'),
                        ),
                        'required' => array('action'),
                        'additionalProperties' => false,
                    ),
                ),
                'evidence' => array('type' => 'string', 'description' => 'An exact quote copied from the current user message that authorizes all proposed changes.'),
            ), array('commands', 'evidence')),
            $this->tool('checkout_get_url', 'Get the native same-store checkout URL. This does not submit an order or payment.', array()),
            $this->tool('respond_answer', 'Return the final answer for this turn. Use after sufficient authoritative tool results.', array(
                'message' => array('type' => 'string', 'maxLength' => 3000, 'description' => 'Shopper-facing Arabic response, limited locally to 3000 Unicode characters.'),
                'product_refs' => $productRefs,
            ), array('message')),
            $this->tool('respond_follow_up', 'Ask one focused follow-up question when a material shopper choice is missing.', array(
                'message' => array('type' => 'string', 'maxLength' => 3000, 'description' => 'Shopper-facing Arabic response, limited locally to 3000 Unicode characters.'),
                'product_refs' => $productRefs,
                'cart_clarification' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array('type' => 'string', 'enum' => array('add', 'set_quantity', 'increment', 'decrement', 'remove', 'replace')),
                        'missing' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => array('product', 'target', 'variation', 'quantity')), 'minItems' => 1, 'maxItems' => 4),
                        'product_ref' => $productRef,
                        'quantity_mode' => array('type' => 'string', 'enum' => array('explicit', 'preserve_source')),
                        'requested_quantity' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 1000),
                        'target_description' => array('type' => 'string', 'maxLength' => 160),
                    ),
                    'required' => array('action', 'missing'),
                    'additionalProperties' => false,
                    'description' => 'Optional typed, expiring server-owned cart intent. It preserves unresolved context only and never authorizes a future mutation.',
                ),
            ), array('message')),
            $this->tool('respond_safe_failure', 'Return a safe, honest failure message when the request cannot be completed reliably.', array(
                'message' => array('type' => 'string', 'maxLength' => 3000, 'description' => 'Shopper-facing Arabic response, limited locally to 3000 Unicode characters.'),
            ), array('message')),
        );
    }

    public function isTerminal(string $name): bool
    {
        return in_array($name, array('respond_answer', 'respond_follow_up', 'respond_safe_failure'), true);
    }

    public function execute(
        string $name,
        array $arguments,
        ToolContext $context,
        string $conversationId,
        string $currentMessage,
        ?string $replyContext
    ): ToolExecution {
        // The provider call may outlive a reclaimed lease. Recheck ownership
        // before any tool result or application-side effect is produced.
        $context->heartbeatTurn();
        try {
            return match ($name) {
                'catalog_discover' => new ToolExecution($this->discover($arguments, $context)),
                'catalog_get_details' => new ToolExecution($this->details($arguments, $context)),
                'catalog_compare' => new ToolExecution($this->compare($arguments, $context)),
                'catalog_rank_candidates' => new ToolExecution($this->rank($arguments, $context, $conversationId, $currentMessage)),
                'catalog_find_alternatives' => new ToolExecution($this->alternatives($arguments, $context)),
                'catalog_get_product_by_sku' => new ToolExecution($this->bySku($arguments, $context)),
                'catalog_resolve_variation' => new ToolExecution($this->resolveVariation($arguments, $context)),
                'catalog_related' => new ToolExecution($this->related($arguments, $context)),
                'catalog_list_categories' => new ToolExecution(array('categories' => $this->catalog->categories($this->int($arguments, 'limit', 1, 50, 20)))),
                'content_search' => new ToolExecution($this->contentSearch($arguments, $context)),
                'content_get' => new ToolExecution($this->contentGet($arguments, $context)),
                'store_policy' => new ToolExecution($this->content->policies()),
                'store_info' => new ToolExecution($this->content->storeInfo()),
                'shopping_memory_update' => new ToolExecution($this->updateMemory(
                    $arguments,
                    $conversationId,
                    $currentMessage,
                    $context
                )),
                'cart_view' => new ToolExecution(array('cart' => $this->viewCart($context))),
                'cart_apply' => $this->applyCart($arguments, $context, $currentMessage, $replyContext),
                'checkout_get_url' => new ToolExecution($this->checkout($context)),
                default => new ToolExecution(array('ok' => false, 'error' => 'Unknown tool.')),
            };
        } catch (TurnLeaseLost $error) {
            throw $error;
        } catch (CartStateUncertain) {
            $context->clearCartClarification();
            $cart = null;
            try {
                $cart = $this->cart->view($context);
            } catch (\Throwable) {
                // The terminal warning remains valid without a cart preview.
            }
            $message = 'تعذّر التحقق من الحالة النهائية للسلة بعد محاولة التعديل. لا تُعِد الطلب الآن؛ حدّث الصفحة وافحص السلة قبل أي تعديل آخر.';
            return new ToolExecution(
                array(
                    'ok' => false,
                    'error' => $message,
                    'error_type' => 'cart_state_uncertain',
                    'requires_cart_review' => true,
                ),
                array(
                    'kind' => 'cart_uncertain',
                    'message' => $message,
                    'products' => array(),
                    'cart' => $cart,
                )
            );
        } catch (\InvalidArgumentException) {
            // Never put exception text from domain, gateway, extension, or
            // repository boundaries into model-visible tool history. Even when
            // a message looks harmless, the provider can repeat it verbatim to
            // the shopper. Only this fixed remediation guidance crosses the
            // application-to-model boundary.
            return new ToolExecution(array(
                'ok' => false,
                'error' => self::SAFE_INVALID_ARGUMENT_MESSAGE,
                'error_type' => 'invalid_arguments',
            ));
        } catch (\Throwable $error) {
            return new ToolExecution(array(
                'ok' => false,
                'error' => 'تعذّر تنفيذ الأداة بأمان. حدّث معلومات المنتج أو السلة ثم حاول من جديد.',
                'error_type' => 'tool_failure',
            ));
        }
    }

    /** @return array<string,mixed> */
    public function terminal(string $name, array $arguments, ToolContext $context): array
    {
        $rawMessage = $arguments['message'] ?? null;
        $message = is_string($rawMessage) ? Text::plain($rawMessage, 3000) : '';
        if ($message === '') {
            $message = $name === 'respond_follow_up'
                ? 'ما الخيار الذي تفضله حتى أتمكن من المتابعة بدقة؟'
                : 'تعذّر إعداد إجابة موثوقة الآن.';
        }
        $refsProvided = array_key_exists('product_refs', $arguments);
        $refs = $refsProvided ? $this->refs($arguments['product_refs'], 12, 'p') : null;
        $products = $name === 'respond_safe_failure'
            ? array()
            : $context->cards($refs, (int) $this->settings->get('max_display_cards', 6));
        $clarification = null;
        if ($name === 'respond_follow_up' && is_array($arguments['cart_clarification'] ?? null)) {
            $clarification = $context->setCartClarification($arguments['cart_clarification']);
        } else {
            // A terminal response without a typed pending cart request is a
            // tombstone. Older pending state must never reappear on a later turn.
            $context->clearCartClarification();
        }
        $result = array(
            'kind' => match ($name) {
                'respond_follow_up' => 'follow_up',
                'respond_safe_failure' => 'safe_failure',
                default => 'answer',
            },
            'message' => $message,
            'products' => $products,
        );
        if ($clarification !== null) {
            $result['clarification_ref'] = (string) ($clarification['ref'] ?? '');
            $result['clarification_expires_at'] = (int) ($clarification['expires_at'] ?? 0);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function discover(array $arguments, ToolContext $context): array
    {
        $limit = $this->int($arguments, 'limit', 1, 12, 6);
        $continuationRef = array_key_exists('continuation_ref', $arguments)
            ? $this->reference($arguments, 'continuation_ref', 'n')
            : '';
        if ($continuationRef !== '') {
            foreach (array('query', 'browse', 'category', 'min_price', 'max_price', 'in_stock', 'on_sale', 'sort', 'exclude_product_refs') as $forbidden) {
                if (array_key_exists($forbidden, $arguments)) {
                    throw new \InvalidArgumentException('A continuation cannot override its original catalog request.');
                }
            }
            $state = $context->catalogContinuation($continuationRef);
            $query = (string) ($state['query'] ?? '');
            $filters = is_array($state['filters'] ?? null) ? $state['filters'] : array();
            $sort = (string) ($state['sort'] ?? 'relevance');
            $plan = is_array($state['plan'] ?? null) ? $state['plan'] : array();
            $seenIds = is_array($state['seen_ids'] ?? null) ? $state['seen_ids'] : array();
        } else {
            if (array_key_exists('query', $arguments) && !is_string($arguments['query'])) {
                throw new \InvalidArgumentException('query must be a string.');
            }
            $query = $this->optionalText($arguments, 'query', 240);
            $browse = ($arguments['browse'] ?? false) === true;
            if (array_key_exists('browse', $arguments) && !is_bool($arguments['browse'])) {
                throw new \InvalidArgumentException('browse must be a boolean.');
            }
            if (($query === '') === !$browse) {
                throw new \InvalidArgumentException('Provide either a non-empty catalog query or an explicit browse request, but not both.');
            }
            $filters = $this->catalogFilters($arguments, $context);
            $sort = array_key_exists('sort', $arguments)
                ? $this->catalogSort($arguments['sort'])
                : ($browse ? 'newest' : 'relevance');
            $plan = $this->recallPlanner->plan(
                $query,
                (string) $this->settings->get('catalog_synonyms', '')
            );
            $seenIds = array();
        }

        $baseExcludedIds = array_values(array_unique(
            is_array($filters['exclude_ids'] ?? null) ? $filters['exclude_ids'] : array()
        ));
        if (count($baseExcludedIds) > 24) {
            throw new \InvalidArgumentException('Catalog discovery accepts at most 24 explicit exclusions.');
        }
        $seenIds = array_values(array_unique($seenIds));
        $seenCapacity = self::MAX_DISCOVERY_EXCLUSIONS - count($baseExcludedIds);
        if (count($seenIds) > $seenCapacity) {
            throw new \InvalidArgumentException('The catalog continuation exceeds its bounded traversal capacity.');
        }
        $remainingCapacity = max(0, $seenCapacity - count($seenIds));
        $pageLimit = min($limit, $remainingCapacity);

        // Explicit shopper exclusions are permanent for the traversal. Seen
        // product IDs consume only the remaining bounded no-repeat capacity;
        // neither set is ever truncated or allowed to evict the other.
        $allExcluded = array_values(array_unique(array_merge($baseExcludedIds, $seenIds)));
        $executionFilters = $filters;
        $executionFilters['exclude_ids'] = $allExcluded;
        $executionFilters['sort'] = $sort;

        $merged = array();
        $sourceMeta = array();
        $candidateLimit = 12;
        if ($pageLimit > 0) {
            foreach (array_slice($plan, 0, self::MAX_RECALL_QUERIES) as $planIndex => $planned) {
                if (!is_array($planned)) {
                    continue;
                }
                $plannedQuery = is_string($planned['query'] ?? null) ? Text::plain($planned['query'], 240) : '';
                if ($query !== '' && $plannedQuery === '') {
                    continue;
                }
                $weight = is_int($planned['weight'] ?? null) || is_float($planned['weight'] ?? null)
                    ? max(0.0, min(1.0, (float) $planned['weight']))
                    : 0.0;
                $search = $this->catalog->search($plannedQuery, $candidateLimit, $executionFilters);
                $sourceMeta[] = array(
                    'source' => (string) ($planned['source'] ?? 'catalog'),
                    'results_truncated' => $search->resultsTruncated,
                    'scan_exhausted' => $search->scanExhausted,
                    'scanned_candidates' => $search->scannedCandidates,
                );
                foreach ($search->products as $position => $product) {
                    if (!is_object($product)) {
                        continue;
                    }
                    try {
                        $identity = $this->productIdentity($product);
                    } catch (\UnexpectedValueException) {
                        // A single pathological catalog record must not discard
                        // an otherwise valid bounded result page.
                        continue;
                    }
                    $id = $identity['id'];
                    if (in_array($id, $allExcluded, true)) {
                        continue;
                    }
                    $score = ($weight * 1000.0) - ($planIndex * 20.0) - (int) $position;
                    if (!isset($merged[$id]) || $score > (float) $merged[$id]['score']) {
                        $merged[$id] = array('product' => $product, 'score' => $score, 'id' => $id);
                    }
                }
            }
        }

        $rows = array_values($merged);
        if ($sort === 'relevance') {
            usort($rows, static function (array $left, array $right): int {
                $score = ((float) $right['score']) <=> ((float) $left['score']);
                if ($score !== 0) {
                    return $score;
                }
                return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            });
            $products = array_map(static fn (array $row): object => $row['product'], $rows);
        } else {
            $products = $this->catalog->sortProducts(
                array_map(static fn (array $row): object => $row['product'], $rows),
                $sort
            );
        }

        $pageProducts = array_slice($products, 0, $pageLimit);
        $returnedIds = array();
        foreach ($pageProducts as $product) {
            $returnedIds[] = $this->productIdentity($product)['id'];
        }
        $newSeenIds = array_values(array_unique(array_merge($seenIds, $returnedIds)));
        if (count($newSeenIds) > $seenCapacity) {
            throw new \LogicException('Catalog discovery exceeded its no-repeat capacity.');
        }

        $sourceHasMore = $pageLimit === 0 && $continuationRef !== '';
        $scanned = 0;
        foreach ($sourceMeta as $meta) {
            $sourceHasMore = $sourceHasMore
                || ($meta['results_truncated'] ?? false) === true
                || ($meta['scan_exhausted'] ?? true) !== true;
            $scanned += max(0, (int) ($meta['scanned_candidates'] ?? 0));
        }
        $resultsTruncated = count($products) > $pageLimit || $sourceHasMore;
        $remainingAfterPage = max(0, $seenCapacity - count($newSeenIds));
        $canContinue = $returnedIds !== array()
            && $resultsTruncated
            && $remainingAfterPage > 0;
        $continuationLimitReached = $resultsTruncated && $remainingAfterPage === 0;

        // Malformed bounded catalog records are skipped and treated as traversed
        // so a continuation cannot loop over them. Unexpected or transient
        // projection failures still propagate before the one-use reference is consumed.
        $cards = $this->projectProducts($pageProducts, $context);
        $nextRef = $continuationRef === ''
            ? $context->beginCatalogContinuation($query, $filters, $sort, $plan, $newSeenIds, $canContinue)
            : $context->advanceCatalogContinuation($continuationRef, $newSeenIds, $canContinue);

        return array(
            'products' => $cards,
            'continuation_ref' => $nextRef,
            'has_more' => $nextRef !== null,
            // Preserve the established search_meta contract. Phase-one
            // traversal details live beside it so existing consumers are not
            // forced to reinterpret a previously stable object.
            'search_meta' => array(
                'results_truncated' => $resultsTruncated,
                'scan_exhausted' => !$sourceHasMore,
                'scanned_candidates' => $scanned,
                'scan_limit' => self::MAX_DISCOVERY_EXCLUSIONS * max(1, count($sourceMeta)),
            ),
            'discovery_meta' => array(
                'queries_executed' => count($sourceMeta),
                'sort' => $sort,
                'bounded_recall' => true,
                'seen_products' => count($newSeenIds),
                'explicit_exclusions' => count($baseExcludedIds),
                'continuation_limit_reached' => $continuationLimitReached,
            ),
        );
    }

    /** @return array<string,mixed> */
    private function details(array $arguments, ToolContext $context): array
    {
        $product = $this->productFromRef($this->reference($arguments, 'product_ref', 'p'), $context);
        return array('product' => $this->projectProduct($product, $context));
    }

    /** @return array<string,mixed> */
    private function compare(array $arguments, ToolContext $context): array
    {
        $refs = $this->refs($arguments['product_refs'] ?? array(), 5, 'p');
        if (count($refs) < 2) {
            throw new \InvalidArgumentException('Comparison requires at least two valid product references.');
        }
        $products = array_map(fn (string $ref): object => $this->productFromRef($ref, $context), $refs);
        return array('products' => $this->projectProducts($products, $context));
    }

    /** @return array<string,mixed> */
    private function rank(
        array $arguments,
        ToolContext $context,
        string $conversationId,
        string $currentMessage
    ): array {
        $refs = $this->refs($arguments['product_refs'] ?? array(), 12, 'p');
        if (count($refs) < 2) {
            throw new \InvalidArgumentException('Ranking requires at least two product references.');
        }
        $cards = array();
        foreach ($refs as $ref) {
            $cards[] = $this->projectProduct($this->productFromRef($ref, $context), $context);
        }
        $criterion = $this->requiredText($arguments, 'criterion', 40, 'The ranking criterion is required.');
        $criteria = array('price_low', 'price_high', 'rating', 'value', 'in_stock', 'best_match');
        if (!in_array($criterion, $criteria, true)) {
            throw new \InvalidArgumentException('The ranking criterion is not supported.');
        }
        $ranking = array();
        if ($criterion === 'best_match') {
            $ranked = $this->bestMatchRanker->rank(
                $cards,
                $currentMessage,
                $this->conversations->memory($conversationId)
            );
            $cards = $ranked['cards'];
            $ranking = $ranked['ranking'];
        } else {
            $rows = array_map(
                static fn (array $card, int $index): array => array('card' => $card, 'index' => $index),
                $cards,
                array_keys($cards)
            );
            usort($rows, static function (array $leftRow, array $rightRow) use ($criterion): int {
                $left = $leftRow['card'];
                $right = $rightRow['card'];
                $comparison = match ($criterion) {
                    'price_low' => self::comparePrice($left, $right, false),
                    'price_high' => self::comparePrice($left, $right, true),
                    'rating' => ((float) ($right['rating'] ?? 0)) <=> ((float) ($left['rating'] ?? 0)),
                    'value' => self::valueScore($right) <=> self::valueScore($left),
                    'in_stock' => ((int) (($right['in_stock'] ?? false) === true)) <=> ((int) (($left['in_stock'] ?? false) === true)),
                    default => 0,
                };
                return $comparison !== 0
                    ? $comparison
                    : ((int) $leftRow['index']) <=> ((int) $rightRow['index']);
            });
            $cards = array_map(static fn (array $row): array => $row['card'], $rows);
        }
        $context->rememberProducts(array_values(array_filter(array_map(
            static fn (array $card): string => (string) ($card['ref'] ?? ''),
            $cards
        ))));
        $result = array('criterion' => $criterion, 'products' => $cards);
        if ($ranking !== array()) {
            $result['ranking'] = $ranking;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function alternatives(array $arguments, ToolContext $context): array
    {
        $source = $this->productFromRef($this->reference($arguments, 'product_ref', 'p'), $context);
        $identity = $this->productIdentity($source);
        return array('products' => $this->projectProducts(
            $this->catalog->alternatives((int) $identity['id'], $this->int($arguments, 'limit', 1, 12, 6)),
            $context
        ));
    }

    /** @return array<string,mixed> */
    private function bySku(array $arguments, ToolContext $context): array
    {
        $sku = $this->requiredText($arguments, 'sku', 120, 'A SKU is required.');
        $product = $this->catalog->bySku($sku);
        return array('found' => $product !== null, 'product' => $product === null ? null : $this->projectProduct($product, $context));
    }

    /** @return array<string,mixed> */
    private function resolveVariation(array $arguments, ToolContext $context): array
    {
        $source = $this->productFromRef($this->reference($arguments, 'product_ref', 'p'), $context);
        $identity = $this->productIdentity($source);
        $parentId = (int) ($identity['parent_id'] > 0 ? $identity['parent_id'] : $identity['id']);
        $attributes = $this->variationAttributes($arguments['attributes'] ?? null);
        $variation = $this->catalog->resolveVariation($parentId, $attributes);
        return array(
            'resolved' => $variation !== null,
            'product' => $variation === null ? null : $this->projectProduct($variation, $context),
        );
    }

    /** @return array<string,mixed> */
    private function related(array $arguments, ToolContext $context): array
    {
        $source = $this->productFromRef($this->reference($arguments, 'product_ref', 'p'), $context);
        $identity = $this->productIdentity($source);
        return array('products' => $this->projectProducts(
            $this->catalog->related((int) $identity['id'], $this->int($arguments, 'limit', 1, 12, 6)),
            $context
        ));
    }


    /** @return array<string,mixed> */
    private function catalogFilters(array $arguments, ToolContext $context): array
    {
        $filters = array();
        if (array_key_exists('category', $arguments)) {
            $filters['category'] = $this->requiredText($arguments, 'category', 160, 'A valid category is required.');
        }
        foreach (array('min_price', 'max_price') as $key) {
            if (array_key_exists($key, $arguments)) {
                $filters[$key] = $this->number($arguments, $key, 0.0);
            }
        }
        foreach (array('in_stock', 'on_sale') as $key) {
            if (array_key_exists($key, $arguments)) {
                if (!is_bool($arguments[$key])) {
                    throw new \InvalidArgumentException($key . ' must be a boolean.');
                }
                $filters[$key] = $arguments[$key];
            }
        }
        if (isset($filters['min_price'], $filters['max_price'])
            && (float) $filters['min_price'] > (float) $filters['max_price']) {
            throw new \InvalidArgumentException('The minimum price cannot exceed the maximum price.');
        }
        $refs = $this->refs($arguments['exclude_product_refs'] ?? array(), 24, 'p');
        $ids = array();
        foreach ($refs as $ref) {
            $entry = $context->product($ref);
            $identity = is_array($entry['identity'] ?? null) ? $entry['identity'] : array();
            $id = $identity['id'] ?? null;
            if (is_int($id) && $id > 0) {
                $ids[$id] = true;
            }
        }
        if ($ids !== array()) {
            $filters['exclude_ids'] = array_keys($ids);
        }
        return $filters;
    }

    private function catalogSort(mixed $value): string
    {
        if (!is_string($value)
            || !in_array($value, array('relevance', 'price_low', 'price_high', 'newest', 'best_selling', 'rating'), true)) {
            throw new \InvalidArgumentException('The catalog sort is invalid.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function contentSearch(array $arguments, ToolContext $context): array
    {
        $query = $this->requiredText($arguments, 'query', 240, 'A content query is required.');
        $results = array();
        foreach ($this->content->search($query, $this->int($arguments, 'limit', 1, 10, 5)) as $document) {
            $ref = $context->registerContent($document);
            unset($document['id']);
            $document['ref'] = $ref;
            $results[] = $document;
        }
        return array('results' => $results);
    }

    /** @return array<string,mixed> */
    private function contentGet(array $arguments, ToolContext $context): array
    {
        $entry = $context->content($this->reference($arguments, 'content_ref', 'c'));
        $document = $this->content->get((int) ($entry['id'] ?? 0));
        if ($document === null) {
            return array('found' => false);
        }
        unset($document['id']);
        return array('found' => true, 'document' => $document);
    }

    /** @return array<string,mixed> */
    private function updateMemory(
        array $arguments,
        string $conversationId,
        string $currentMessage,
        ToolContext $context
    ): array
    {
        $raw = is_array($arguments['preferences'] ?? null) ? $arguments['preferences'] : array();
        $clean = $this->memoryPolicy->authorize(
            $raw,
            $currentMessage,
            is_string($arguments['evidence'] ?? null) ? $arguments['evidence'] : ''
        );
        $memory = $this->conversations->memory($conversationId);
        $updated = array_replace($memory, $clean);
        if (isset($updated['budget_min'], $updated['budget_max'])
            && (float) $updated['budget_min'] > (float) $updated['budget_max']) {
            throw new \InvalidArgumentException('The saved minimum budget cannot exceed the maximum budget.');
        }
        $claim = $context->guardedTurnClaim();
        $this->conversations->updateMemoryForTurn(
            $conversationId,
            $claim['turn_id'],
            $claim['claim_version'],
            $updated
        );
        return array('saved' => true, 'memory' => $updated);
    }

    /** @return array<string,mixed> */
    private function viewCart(ToolContext $context): array
    {
        return $this->cart->view($context);
    }

    private function applyCart(
        array $arguments,
        ToolContext $context,
        string $currentMessage,
        ?string $replyContext
    ): ToolExecution {
        if ($context->hasCartMutationAttempted()) {
            return new ToolExecution(array(
                'ok' => false,
                'authorized' => false,
                'requires_new_turn' => true,
                'error' => 'لا يمكن محاولة تعديل السلة أكثر من مرة في الطلب نفسه.',
            ));
        }

        if (!$context->hasFreshCartView()) {
            return new ToolExecution(array(
                'ok' => false,
                'authorized' => false,
                'requires_cart_refresh' => true,
                'error' => 'يجب قراءة السلة الحالية في هذه المحادثة قبل أي تعديل.',
            ));
        }

        if (!$context->hasCartPersistenceBinding()) {
            return new ToolExecution(array(
                'ok' => false,
                'authorized' => false,
                'requires_cart_refresh' => true,
                'mutations_unavailable' => true,
                'error' => 'تعذّر التحقق من حداثة جلسة السلة، لذلك لن تُنفّذ أي تعديلات عبر المحادثة الآن.',
            ));
        }

        $evidence = $this->optionalText($arguments, 'evidence', 300);
        if (!$this->isExactCurrentMessageEvidence($currentMessage, $evidence)) {
            return new ToolExecution(array(
                'ok' => false,
                'authorized' => false,
                'requires_clarification' => true,
                'error' => 'دليل التفويض ليس اقتباسًا مطابقًا من رسالة المستخدم الحالية.',
            ));
        }

        $plan = CartPlan::fromArray($arguments);
        $context->markCartMutationAttempted();
        $context->heartbeatTurn();
        $decision = $this->intentVerifier->authorize($currentMessage, $replyContext, $evidence, $plan, $context);
        if (!$decision->authorized) {
            return new ToolExecution(array(
                'ok' => false,
                'authorized' => false,
                'requires_clarification' => $decision->requiresClarification,
                'error' => $decision->reason === '' ? 'طلب تعديل السلة غير واضح بما يكفي.' : $decision->reason,
            ));
        }

        $context->heartbeatTurn();
        $receipt = $this->cart->apply($plan, $context);
        $context->clearCartClarification();
        $terminal = array(
            'kind' => 'cart_receipt',
            'message' => $receipt->message,
            'products' => array(),
            'receipt' => $receipt->toArray(),
            'cart' => $receipt->cart,
        );
        return new ToolExecution(array(
            'ok' => true,
            'receipt' => $receipt->toArray(),
        ), $terminal);
    }

    /** @return array<string,mixed> */
    private function checkout(ToolContext $context): array
    {
        $cart = $this->cart->view($context);
        return array(
            'checkout_url' => (string) ($cart['checkout_url'] ?? ''),
            'item_count' => (int) ($cart['item_count'] ?? 0),
            'total_text' => (string) ($cart['total_text'] ?? ''),
        );
    }

    private function productFromRef(string $ref, ToolContext $context): object
    {
        $entry = $context->product($ref);
        $identity = is_array($entry['identity'] ?? null) ? $entry['identity'] : array();
        $product = $this->catalog->product((int) ($identity['id'] ?? 0));
        if ($product === null) {
            throw new \InvalidArgumentException('The product is no longer available.');
        }
        return $product;
    }

    /** @param list<object> $products @return list<array<string,mixed>> */
    private function projectProducts(array $products, ToolContext $context): array
    {
        $cards = array();
        foreach (array_slice($products, 0, 12) as $product) {
            if (!is_object($product)) {
                continue;
            }
            try {
                $cards[] = $this->projectProduct($product, $context);
            } catch (\LengthException|\UnexpectedValueException) {
                // Skip pathological catalog records whose identity or public
                // projection cannot satisfy the bounded application contract.
            }
        }
        $context->rememberProducts(array_values(array_filter(array_map(
            static fn (array $card): string => (string) ($card['ref'] ?? ''),
            $cards
        ))));
        return $cards;
    }

    /** @return array<string,mixed> */
    private function projectProduct(object $product, ToolContext $context): array
    {
        $identity = $this->productIdentity($product);
        $card = $this->catalog->project($product);
        $card['ref'] = $context->registerProduct($identity, $card);
        return $card;
    }


    /** @return array{id:int,parent_id:int,type:string,fingerprint:string} */
    private function productIdentity(object $product): array
    {
        $identity = $this->catalog->identity($product);
        $id = $identity['id'] ?? null;
        $parentId = $identity['parent_id'] ?? null;
        $type = $identity['type'] ?? null;
        $fingerprint = $identity['fingerprint'] ?? null;
        if (!is_int($id) || $id < 1
            || !is_int($parentId) || $parentId < 0
            || !is_string($type)
            || $type === ''
            || strlen($type) > 40
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $type) !== 1
            || !is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \UnexpectedValueException('The catalog returned an invalid product identity.');
        }
        return array(
            'id' => $id,
            'parent_id' => $parentId,
            'type' => $type,
            'fingerprint' => $fingerprint,
        );
    }

    /** @param mixed $value @return list<string> */
    private function refs(mixed $value, int $limit, string $prefix): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return array();
        }
        $refs = array();
        $pattern = '/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9_-]{8,80}$/';
        foreach (array_slice($value, 0, $limit) as $ref) {
            if (is_string($ref) && preg_match($pattern, $ref) === 1) {
                $refs[] = $ref;
            }
        }
        return array_values(array_unique($refs));
    }

    /** @param array<string,mixed> $arguments */
    private function int(array $arguments, string $key, int $minimum, int $maximum, int $default): int
    {
        if (!array_key_exists($key, $arguments)) {
            return $default;
        }
        $value = $arguments[$key];
        if (!is_int($value)) {
            throw new \InvalidArgumentException($key . ' must be an integer.');
        }
        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException($key . ' is outside the allowed range.');
        }
        return $value;
    }

    /** @param array<string,mixed> $arguments */
    private function requiredText(array $arguments, string $key, int $limit, string $message): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException($message);
        }
        $value = Text::plain($value, $limit);
        if ($value === '') {
            throw new \InvalidArgumentException($message);
        }
        return $value;
    }

    /** @param array<string,mixed> $arguments */
    private function optionalText(array $arguments, string $key, int $limit): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value)) {
            return '';
        }
        return Text::plain($value, $limit);
    }

    /** @param array<string,mixed> $arguments */
    private function reference(array $arguments, string $key, string $prefix): string
    {
        $value = $arguments[$key] ?? null;
        $pattern = '/^' . preg_quote($prefix, '/') . '_[A-Za-z0-9_-]{8,80}$/';
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new \InvalidArgumentException('Invalid opaque ' . $key . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $arguments */
    private function number(array $arguments, string $key, float $minimum, float $maximum = 1_000_000_000_000.0): float
    {
        $value = $arguments[$key] ?? null;
        if ((!is_int($value) && !is_float($value))
            || !is_finite((float) $value)
            || (float) $value < $minimum
            || (float) $value > $maximum) {
            throw new \InvalidArgumentException($key . ' must be a finite number in the allowed range.');
        }
        return (float) $value;
    }

    /** @return array<string,string> */
    private function variationAttributes(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) || count($value) < 1 || count($value) > 30) {
            throw new \InvalidArgumentException('Variation attributes must be a bounded object.');
        }
        $attributes = array();
        foreach ($value as $name => $option) {
            if (!is_string($name) || !is_string($option)) {
                throw new \InvalidArgumentException('Variation attribute names and values must be strings.');
            }
            $name = Text::plain($name, 160);
            $option = Text::plain($option, 200);
            if ($name === '' || $option === '') {
                throw new \InvalidArgumentException('Variation attributes cannot be empty.');
            }
            $attributes[$name] = $option;
        }
        return $attributes;
    }

    /** @param array<string,array<string,mixed>> $properties @param list<string> $required @return array<string,mixed> */
    private function tool(string $name, string $description, array $properties, array $required = array()): array
    {
        $parameters = array(
            'type' => 'object',
            // Keep the logical map as a PHP array. The Gemini adapter converts
            // an empty map to a genuine JSON object immediately before encoding.
            'properties' => $properties,
            'additionalProperties' => false,
        );
        if ($required !== array()) {
            $parameters['required'] = $required;
        }

        return array(
            'type' => 'function',
            'name' => $name,
            'description' => $description,
            'parameters' => $parameters,
        );
    }


    private function isExactCurrentMessageEvidence(string $message, string $evidence): bool
    {
        if (Text::length($evidence) < 3 || Text::length($evidence) > 300) {
            return false;
        }
        if (preg_match('/[\p{L}\p{N}]/u', $evidence) !== 1) {
            return false;
        }
        return Text::containsExact($message, $evidence);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function comparePrice(array $left, array $right, bool $descending): int
    {
        $leftPrice = self::availablePrice($left);
        $rightPrice = self::availablePrice($right);
        if ($leftPrice === null || $rightPrice === null) {
            if ($leftPrice === $rightPrice) {
                return 0;
            }
            // Unknown prices always follow known prices, independent of direction.
            return $leftPrice === null ? 1 : -1;
        }
        return $descending ? $rightPrice <=> $leftPrice : $leftPrice <=> $rightPrice;
    }

    /** @param array<string,mixed> $card */
    private static function availablePrice(array $card): ?float
    {
        if (($card['price_available'] ?? false) !== true) {
            return null;
        }
        $value = $card['price'] ?? null;
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $price = (float) $value;
        return is_finite($price) && $price >= 0 ? $price : null;
    }

    /** @param array<string,mixed> $card */
    private static function valueScore(array $card): float
    {
        $price = self::availablePrice($card);
        if ($price === null) {
            return -INF;
        }
        $rating = max(0.0, (float) ($card['rating'] ?? 0));
        if ($price === 0.0) {
            return $rating > 0.0 ? PHP_FLOAT_MAX : 0.0;
        }
        return $rating / $price;
    }
}
