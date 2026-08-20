<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Catalog\CatalogBestMatchRanker;
use YassinStore\AiAssistant\Application\Catalog\CatalogRecallPlanner;
use YassinStore\AiAssistant\Application\Catalog\CatalogSearchResult;
use YassinStore\AiAssistant\Application\Catalog\CatalogSynonymMap;
use YassinStore\AiAssistant\Application\Catalog\CatalogTextNormalizer;
use YassinStore\AiAssistant\Application\Chat\IntentVerifier;
use YassinStore\AiAssistant\Application\Contract\CatalogGateway;
use YassinStore\AiAssistant\Application\Tool\ShoppingMemoryPolicy;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Application\Tool\ToolRegistry;

final class PhaseOnePagedCatalog implements CatalogGateway
{
    /** @var array<int,TestProduct> */
    private array $products = array();
    public ?int $failProjectionId = null;

    /** @param list<TestProduct> $products */
    public function __construct(array $products)
    {
        foreach ($products as $product) {
            $this->products[$product->id] = $product;
        }
    }

    public function search(string $query, int $limit, array $filters = array()): CatalogSearchResult
    {
        $excluded = array_fill_keys(is_array($filters['exclude_ids'] ?? null) ? $filters['exclude_ids'] : array(), true);
        $products = array_values(array_filter(
            $this->products,
            static fn (TestProduct $product): bool => !isset($excluded[$product->id])
                && (!isset($filters['min_price']) || $product->price >= (float) $filters['min_price'])
                && (!isset($filters['max_price']) || $product->price <= (float) $filters['max_price'])
        ));
        $products = $this->sortProducts($products, is_string($filters['sort'] ?? null) ? $filters['sort'] : 'relevance');
        $limit = max(1, min(12, $limit));
        $truncated = count($products) > $limit;
        return new CatalogSearchResult(
            array_slice($products, 0, $limit),
            $truncated,
            !$truncated,
            min(count($products), $limit),
            240
        );
    }

    public function sortProducts(array $products, string $sort): array
    {
        $products = array_values(array_filter($products, static fn (mixed $product): bool => $product instanceof TestProduct));
        if (!in_array($sort, array('relevance', 'price_low', 'price_high', 'newest', 'best_selling', 'rating'), true)) {
            throw new InvalidArgumentException('Unsupported phase-one test sort.');
        }
        if ($sort === 'price_low' || $sort === 'price_high') {
            usort($products, static function (TestProduct $left, TestProduct $right) use ($sort): int {
                $order = $left->price <=> $right->price;
                return $sort === 'price_high' ? -$order : $order;
            });
        } elseif ($sort !== 'relevance') {
            usort($products, static fn (TestProduct $left, TestProduct $right): int => $right->id <=> $left->id);
        }
        return $products;
    }

    public function product(int $id): ?object { return $this->products[$id] ?? null; }
    public function bySku(string $sku): ?object { return ctype_digit($sku) ? $this->product((int) $sku) : null; }
    public function related(int $id, int $limit): array { return array_slice(array_values($this->products), 0, $limit); }
    public function alternatives(int $id, int $limit): array { return $this->related($id, $limit); }
    public function categories(int $limit): array { return array(array('name' => 'Test', 'slug' => 'test', 'count' => count($this->products))); }
    public function resolveVariation(int $parentId, array $attributes): ?object { return $this->product($parentId); }

    public function identity(object $product): array
    {
        if (!$product instanceof TestProduct) {
            throw new InvalidArgumentException('Unexpected phase-one test product.');
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
            throw new InvalidArgumentException('Unexpected phase-one test product.');
        }
        if ($this->failProjectionId === $product->id) {
            throw new RuntimeException('Synthetic projection failure.');
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
            'short_description' => 'Phase-one catalog product',
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

/** @return array{registry:ToolRegistry,credentials:YassinStore\AiAssistant\Domain\Conversation\ConversationCredentials} */
function phase_one_catalog_registry(CatalogGateway $catalog, ?TestSettings $settings = null): array
{
    $provider = new ScriptedAiProvider();
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $credentials = $conversations->seed();
    return array(
        'registry' => new ToolRegistry(
            $catalog,
            new TestCart(),
            new TestContent(),
            $conversations,
            new IntentVerifier($provider),
            $settings ?? new TestSettings(),
            new ShoppingMemoryPolicy()
        ),
        'credentials' => $credentials,
    );
}

test('Phase one catalog normalization handles Arabic forms and bounded Arabizi variants without transliterating ordinary English', static function (): void {
    $normalizer = new CatalogTextNormalizer();
    assert_same('اكسسوارات 123', $normalizer->normalize('إِكْسِسُوَارَات ١٢٣'));
    assert_same(array(), $normalizer->transliterationVariants('product'));
    $arabizi = $normalizer->transliterationVariants('7ijab');
    assert_count_value(1, $arabizi);
    assert_true(preg_match('/\p{Arabic}/u', $arabizi[0]) === 1);
    $latin = $normalizer->transliterationVariants('حجاب');
    assert_count_value(1, $latin);
    assert_true(preg_match('/^[a-z ]+$/', $latin[0]) === 1);
});

test('Phase one merchant synonyms are canonical, bounded, and reject duplicate-only groups in strict mode', static function (): void {
    $normalizer = new CatalogTextNormalizer();
    $map = new CatalogSynonymMap("# comment\nحجاب | hijab | scarf\nshoe = sneaker", $normalizer, true);
    assert_same("حجاب | hijab | scarf\nshoe | sneaker", $map->canonicalText());
    assert_true(in_array('red scarf', $map->expansions('red hijab'), true));
    assert_throws(
        InvalidArgumentException::class,
        static fn () => new CatalogSynonymMap('أ | ا', $normalizer, true),
        'at least two distinct'
    );
    assert_throws(
        InvalidArgumentException::class,
        static fn () => new CatalogSynonymMap(str_repeat('a', 12001), $normalizer, true),
        'size limit'
    );
});

test('Phase one recall planner merges original, normalized, synonym, and transliteration queries within a strict envelope', static function (): void {
    $planner = new CatalogRecallPlanner(new CatalogTextNormalizer());
    $plan = $planner->plan('red hijab', 'hijab | scarf | حجاب');
    assert_true(count($plan) >= 2 && count($plan) <= 8);
    assert_same('original', $plan[0]['source']);
    assert_true(in_array('red scarf', array_column($plan, 'query'), true));
    $normalized = array_column($plan, 'normalized');
    assert_same(count($normalized), count(array_unique($normalized)));
});

test('Phase one best_match performs deterministic shopper-grounded ranking instead of preserving input order', static function (): void {
    $ranker = new CatalogBestMatchRanker(new CatalogTextNormalizer());
    $generic = array(
        'ref' => 'p_AAAAAAAA', 'name' => 'Generic bag', 'price' => 25.0,
        'price_available' => true, 'regular_price' => 25.0, 'in_stock' => true,
        'purchasable' => true, 'rating' => 4.8, 'review_count' => 100,
        'categories' => array('Bags'), 'attributes' => array(),
    );
    $matching = array(
        'ref' => 'p_BBBBBBBB', 'name' => 'Red running shoe', 'price' => 60.0,
        'price_available' => true, 'regular_price' => 60.0, 'in_stock' => true,
        'purchasable' => true, 'rating' => 4.1, 'review_count' => 20,
        'categories' => array('Shoes'), 'attributes' => array('color' => array('Red')),
    );
    $ranked = $ranker->rank(
        array($generic, $matching),
        'I need a red running shoe',
        array('budget_max' => 80.0, 'categories' => array('Shoes'))
    );
    assert_same('p_BBBBBBBB', $ranked['cards'][0]['ref']);
    assert_true($ranked['cards'][0]['match_score'] > $ranked['cards'][1]['match_score']);
    assert_same(1, $ranked['ranking'][0]['position']);
    assert_same('p_BBBBBBBB', $ranked['ranking'][0]['product_ref']);
});

test('Phase one best_match tie ordering is independent of random opaque references', static function (): void {
    $ranker = new CatalogBestMatchRanker(new CatalogTextNormalizer());
    $firstFacts = array(
        'name' => 'Alpha', 'sku' => 'A', 'price' => 20.0, 'regular_price' => 20.0,
        'in_stock' => true, 'purchasable' => true, 'rating' => 0.0, 'review_count' => 0,
        'categories' => array(), 'attributes' => array(), 'url' => 'https://shop.example.test/a',
    );
    $secondFacts = array(
        'name' => 'Beta', 'sku' => 'B', 'price' => 20.0, 'regular_price' => 20.0,
        'in_stock' => true, 'purchasable' => true, 'rating' => 0.0, 'review_count' => 0,
        'categories' => array(), 'attributes' => array(), 'url' => 'https://shop.example.test/b',
    );
    $firstRun = $ranker->rank(array(
        array_merge($firstFacts, array('ref' => 'p_ZZZZZZZZ')),
        array_merge($secondFacts, array('ref' => 'p_AAAAAAAA')),
    ), 'unrelated request');
    $secondRun = $ranker->rank(array(
        array_merge($firstFacts, array('ref' => 'p_AAAAAAAA')),
        array_merge($secondFacts, array('ref' => 'p_ZZZZZZZZ')),
    ), 'unrelated request');

    assert_same(
        array_column($firstRun['cards'], 'name'),
        array_column($secondRun['cards'], 'name')
    );
});

test('Phase one catalog continuation is opaque, one-use, non-repeating, and invalidated by a new traversal', static function (): void {
    $products = array();
    for ($id = 1; $id <= 10; ++$id) {
        $products[] = new TestProduct($id, 'Item ' . $id, (float) $id);
    }
    $built = phase_one_catalog_registry(new PhaseOnePagedCatalog($products));
    $context = new ToolContext('turn:phase-one-continuation', null, 1_800_000_000);

    $first = $built['registry']->execute(
        'catalog_discover',
        array('query' => 'item', 'limit' => 3),
        $context,
        $built['credentials']->id,
        'اعرض المنتجات',
        null
    )->result;
    assert_count_value(3, $first['products']);
    assert_true(is_string($first['continuation_ref']) && preg_match('/^n_[A-Za-z0-9_-]{8,80}$/D', $first['continuation_ref']) === 1);
    $firstIds = array_map(static fn (array $card): string => (string) $card['sku'], $first['products']);

    $second = $built['registry']->execute(
        'catalog_discover',
        array('continuation_ref' => $first['continuation_ref'], 'limit' => 3),
        $context,
        $built['credentials']->id,
        'المزيد',
        null
    )->result;
    assert_count_value(3, $second['products']);
    $secondIds = array_map(static fn (array $card): string => (string) $card['sku'], $second['products']);
    assert_same(array(), array_values(array_intersect($firstIds, $secondIds)));

    $replay = $built['registry']->execute(
        'catalog_discover',
        array('continuation_ref' => $first['continuation_ref'], 'limit' => 3),
        $context,
        $built['credentials']->id,
        'المزيد',
        null
    )->result;
    assert_same('invalid_arguments', $replay['error_type']);

    $oldActiveRef = $second['continuation_ref'];
    assert_true(is_string($oldActiveRef));
    $fresh = $built['registry']->execute(
        'catalog_discover',
        array('browse' => true, 'limit' => 2),
        $context,
        $built['credentials']->id,
        'تصفح المنتجات',
        null
    )->result;
    assert_same(array('10', '9'), array_map(static fn (array $card): string => (string) $card['sku'], $fresh['products']));
    $stale = $built['registry']->execute(
        'catalog_discover',
        array('continuation_ref' => $oldActiveRef, 'limit' => 2),
        $context,
        $built['credentials']->id,
        'المزيد',
        null
    )->result;
    assert_same('invalid_arguments', $stale['error_type']);
});

test('Phase one discovery rejects malformed query types instead of silently treating them as browsing', static function (): void {
    $built = phase_one_catalog_registry(new PhaseOnePagedCatalog(array(
        new TestProduct(1, 'Item 1', 10.0),
    )));
    $context = new ToolContext('turn:phase-one-query-type', null, 1_800_000_000);
    $result = $built['registry']->execute(
        'catalog_discover',
        array('query' => array('item'), 'browse' => true),
        $context,
        $built['credentials']->id,
        'تصفح المنتجات',
        null
    )->result;
    assert_same('invalid_arguments', $result['error_type']);
});

test('Phase one explicit ranking criteria preserve requested input order when authoritative facts tie', static function (): void {
    $catalog = new PhaseOnePagedCatalog(array(
        new TestProduct(1, 'Alpha', 20.0),
        new TestProduct(2, 'Beta', 20.0),
    ));
    $built = phase_one_catalog_registry($catalog);
    $context = new ToolContext('turn:phase-one-stable-explicit-rank', null, 1_800_000_000);
    $refs = array();
    foreach (array(1, 2) as $id) {
        $product = $catalog->product($id);
        assert_true(is_object($product));
        $refs[] = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    }
    rsort($refs, SORT_STRING);

    $ranked = $built['registry']->execute(
        'catalog_rank_candidates',
        array('product_refs' => $refs, 'criterion' => 'price_low'),
        $context,
        $built['credentials']->id,
        'رتب حسب السعر',
        null
    )->result;
    assert_same($refs, array_column($ranked['products'], 'ref'));
});

test('Phase one continuation snapshots reject tampering and expire without resurrecting older state', static function (): void {
    $now = 1_800_000_000;
    $context = new ToolContext('turn:snapshot', null, $now);
    $ref = $context->beginCatalogContinuation(
        'shoe',
        array('in_stock' => true),
        'relevance',
        array(array('query' => 'shoe', 'normalized' => 'shoe', 'weight' => 1.0, 'source' => 'original')),
        array(1, 2),
        true
    );
    assert_true(is_string($ref));
    $snapshot = $context->catalogContextSnapshot();

    $restored = new ToolContext('turn:restored', null, $now + 10);
    $restored->restoreCatalogContext($snapshot);
    assert_same(array(1, 2), $restored->catalogContinuation($ref)['seen_ids']);

    $tampered = $snapshot;
    $tampered['continuations'][$ref]['seen_ids'][] = '2';
    $invalid = new ToolContext('turn:tampered', null, $now + 10);
    $invalid->restoreCatalogContext($tampered);
    assert_throws(InvalidArgumentException::class, static fn () => $invalid->catalogContinuation($ref));

    $expired = new ToolContext('turn:expired', null, $now + 1801);
    $expired->restoreCatalogContext($snapshot);
    assert_throws(InvalidArgumentException::class, static fn () => $expired->catalogContinuation($ref));
});

test('Phase one continuation never evicts explicit exclusions and stops cleanly at the no-repeat bound', static function (): void {
    $products = array();
    for ($id = 1; $id <= 300; ++$id) {
        $products[] = new TestProduct($id, 'Item ' . $id, (float) $id);
    }
    $catalog = new PhaseOnePagedCatalog($products);
    $built = phase_one_catalog_registry($catalog);
    $context = new ToolContext('turn:phase-one-long-continuation', null, 1_800_000_000);

    $excludedRefs = array();
    for ($id = 1; $id <= 24; ++$id) {
        $product = $catalog->product($id);
        assert_true(is_object($product));
        $excludedRefs[] = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    }

    $arguments = array(
        'query' => 'item',
        'limit' => 12,
        'exclude_product_refs' => $excludedRefs,
    );
    $collected = array();
    $last = null;
    for ($page = 0; $page < 30; ++$page) {
        $last = $built['registry']->execute(
            'catalog_discover',
            $arguments,
            $context,
            $built['credentials']->id,
            $page === 0 ? 'اعرض المنتجات' : 'المزيد',
            null
        )->result;
        foreach ($last['products'] as $card) {
            $collected[] = (int) $card['sku'];
        }
        if (($last['continuation_ref'] ?? null) === null) {
            break;
        }
        $arguments = array('continuation_ref' => $last['continuation_ref'], 'limit' => 12);
    }

    assert_count_value(216, $collected);
    assert_same(216, count(array_unique($collected)));
    assert_same(array(), array_values(array_intersect(range(1, 24), $collected)));
    assert_same(range(25, 240), $collected);
    assert_same(false, $last['has_more']);
    assert_same(true, $last['search_meta']['results_truncated']);
    assert_same(true, $last['discovery_meta']['continuation_limit_reached']);
    assert_same(24, $last['discovery_meta']['explicit_exclusions']);
});

test('Phase one continuation is not consumed when complete product projection fails', static function (): void {
    $products = array();
    for ($id = 1; $id <= 6; ++$id) {
        $products[] = new TestProduct($id, 'Item ' . $id, (float) $id);
    }
    $catalog = new PhaseOnePagedCatalog($products);
    $built = phase_one_catalog_registry($catalog);
    $context = new ToolContext('turn:phase-one-projection-retry', null, 1_800_000_000);
    $first = $built['registry']->execute(
        'catalog_discover',
        array('query' => 'item', 'limit' => 2),
        $context,
        $built['credentials']->id,
        'اعرض المنتجات',
        null
    )->result;
    $ref = $first['continuation_ref'];
    assert_true(is_string($ref));

    $catalog->failProjectionId = 3;
    $failed = $built['registry']->execute(
        'catalog_discover',
        array('continuation_ref' => $ref, 'limit' => 2),
        $context,
        $built['credentials']->id,
        'المزيد',
        null
    )->result;
    assert_same('tool_failure', $failed['error_type']);

    $catalog->failProjectionId = null;
    $retried = $built['registry']->execute(
        'catalog_discover',
        array('continuation_ref' => $ref, 'limit' => 2),
        $context,
        $built['credentials']->id,
        'المزيد',
        null
    )->result;
    assert_same(array('3', '4'), array_map(static fn (array $card): string => (string) $card['sku'], $retried['products']));
});

test('Phase one model-facing continuation exposes only one reduced active token', static function (): void {
    $now = 1_800_000_000;
    $context = new ToolContext('turn:model-continuation', null, $now);
    $ref = $context->beginCatalogContinuation(
        'running shoe',
        array('in_stock' => true, 'exclude_ids' => array(7)),
        'price_low',
        array(array('query' => 'running shoe', 'normalized' => 'running shoe', 'weight' => 1.0, 'source' => 'original')),
        array(1, 2, 3),
        true
    );
    assert_true(is_string($ref));

    $active = $context->activeCatalogContinuation();
    assert_true(is_array($active));
    assert_same(array('continuation_ref', 'expires_at', 'query', 'sort', 'seen_product_count'), array_keys($active));
    assert_same($ref, $active['continuation_ref']);
    assert_same('running shoe', $active['query']);
    assert_same('price_low', $active['sort']);
    assert_same(3, $active['seen_product_count']);
    assert_false(array_key_exists('filters', $active));
    assert_false(array_key_exists('plan', $active));

    $context->advanceCatalogContinuation($ref, array(1, 2, 3), false);
    assert_same(null, $context->activeCatalogContinuation());
});

test('Phase one restoration rejects ambiguous or stale-generation active continuations', static function (): void {
    $now = 1_800_000_000;
    $source = new ToolContext('turn:source-ambiguous', null, $now);
    $ref = $source->beginCatalogContinuation(
        'shoe',
        array(),
        'relevance',
        array(array('query' => 'shoe', 'normalized' => 'shoe', 'weight' => 1.0, 'source' => 'original')),
        array(1),
        true
    );
    assert_true(is_string($ref));
    $snapshot = $source->catalogContextSnapshot();
    $duplicateRef = 'n_' . str_repeat('d', 16);
    $snapshot['continuations'][$duplicateRef] = $snapshot['continuations'][$ref];

    $ambiguous = new ToolContext('turn:ambiguous', null, $now + 10);
    $ambiguous->restoreCatalogContext($snapshot);
    assert_same(null, $ambiguous->activeCatalogContinuation());
    assert_throws(InvalidArgumentException::class, static fn () => $ambiguous->catalogContinuation($ref));
    assert_throws(InvalidArgumentException::class, static fn () => $ambiguous->catalogContinuation($duplicateRef));

    $stale = $source->catalogContextSnapshot();
    $stale['continuations'][$ref]['generation'] = $stale['generation'] - 1;
    $staleOnly = new ToolContext('turn:stale-active', null, $now + 10);
    $staleOnly->restoreCatalogContext($stale);
    assert_same(null, $staleOnly->activeCatalogContinuation());
    assert_throws(InvalidArgumentException::class, static fn () => $staleOnly->catalogContinuation($ref));
});
