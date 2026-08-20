<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\WooCommerce\WooCatalogGateway;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;

if (!class_exists('WP_Term')) {
    final class WP_Term
    {
        public function __construct(
            public int $term_id,
            public string $name,
            public string $slug,
            public int $count = 0
        ) {
        }
    }
}
if (!class_exists('WP_Post')) {
    final class WP_Post
    {
        public function __construct(public string $post_password = '')
        {
        }
    }
}
if (!class_exists('WP_Query')) {
    final class WP_Query
    {
        /** @var list<int> */
        public array $posts;

        /** @param array<string,mixed> $args */
        public function __construct(array $args)
        {
            $GLOBALS['ysai_catalog_last_query_args'] = $args;
            $this->posts = array_values((array) ($GLOBALS['ysai_catalog_query_posts'] ?? array()));
        }
    }
}


if (!class_exists('WC_Data_Store')) {
    final class WC_Data_Store
    {
        public static function load(string $name): object
        {
            if ($name !== 'product') {
                throw new RuntimeException('Unexpected test data-store name.');
            }
            return new class {
                public function search_products(
                    string $term,
                    string $type = '',
                    bool $includeVariations = false,
                    bool $allStatuses = false,
                    ?int $limit = null
                ): array {
                    $GLOBALS['ysai_catalog_search_request'] = compact(
                        'term',
                        'type',
                        'includeVariations',
                        'allStatuses',
                        'limit'
                    );
                    return array_values((array) ($GLOBALS['ysai_catalog_search_ids'] ?? array()));
                }
            };
        }
    }
}

if (!function_exists('wc_get_product')) {
    function wc_get_product(int $id): object|false
    {
        return $GLOBALS['ysai_catalog_products'][$id] ?? false;
    }
}
if (!function_exists('wc_get_product_id_by_sku')) {
    function wc_get_product_id_by_sku(string $sku): int
    {
        return (int) ($GLOBALS['ysai_catalog_skus'][$sku] ?? 0);
    }
}
if (!function_exists('wc_get_related_products')) {
    function wc_get_related_products(int $id, int $limit = 5, array $excludeIds = array()): array
    {
        $GLOBALS['ysai_catalog_related_request'] = compact('id', 'limit', 'excludeIds');
        return array_values((array) ($GLOBALS['ysai_catalog_related_ids'][$id] ?? array()));
    }
}
if (!function_exists('wc_get_product_term_ids')) {
    function wc_get_product_term_ids(int $id, string $taxonomy): array
    {
        return array_values((array) ($GLOBALS['ysai_catalog_product_terms'][$id][$taxonomy] ?? array()));
    }
}
if (!function_exists('wc_get_products')) {
    function wc_get_products(array $args): array|object
    {
        $GLOBALS['ysai_catalog_last_product_query'] = $args;
        $GLOBALS['ysai_catalog_product_queries'][] = $args;

        if (isset($args['include'])) {
            $products = array();
            foreach ((array) $args['include'] as $rawId) {
                $id = (int) $rawId;
                $product = $GLOBALS['ysai_catalog_products'][$id] ?? null;
                if (!is_object($product)) {
                    continue;
                }
                $allowedCategories = (array) ($GLOBALS['ysai_catalog_product_category_slugs'][$id] ?? array());
                if (isset($args['category'])
                    && array_intersect((array) $args['category'], $allowedCategories) === array()) {
                    continue;
                }
                $products[] = $product;
            }
            return $products;
        }

        $source = isset($args['exclude'])
            ? (array) ($GLOBALS['ysai_catalog_alternatives'] ?? array())
            : (array) ($GLOBALS['ysai_catalog_browse_products'] ?? array());
        $excluded = array_fill_keys(array_map('intval', (array) ($args['exclude'] ?? array())), true);
        $filtered = array();
        foreach ($source as $product) {
            if (!is_object($product) || !method_exists($product, 'get_id')) {
                $filtered[] = $product;
                continue;
            }
            $id = (int) $product->get_id();
            if (isset($excluded[$id])) {
                continue;
            }
            $allowedCategories = (array) ($GLOBALS['ysai_catalog_product_category_slugs'][$id] ?? array());
            if (isset($args['category'])
                && array_intersect((array) $args['category'], $allowedCategories) === array()) {
                continue;
            }
            $filtered[] = $product;
        }

        if (($args['paginate'] ?? false) !== true) {
            return array_slice($filtered, 0, max(0, (int) ($args['limit'] ?? count($filtered))));
        }
        $limit = max(1, (int) ($args['limit'] ?? 10));
        $page = max(1, (int) ($args['page'] ?? 1));
        $total = count($filtered);
        return (object) array(
            'products' => array_values(array_slice($filtered, ($page - 1) * $limit, $limit)),
            'total' => $total,
            'max_num_pages' => $total === 0 ? 0 : (int) ceil($total / $limit),
        );
    }
}
if (!function_exists('get_term')) {
    function get_term(int $id, string $taxonomy = ''): WP_Term|false
    {
        return $GLOBALS['ysai_catalog_terms'][$taxonomy][$id] ?? false;
    }
}
if (!function_exists('get_term_by')) {
    function get_term_by(string $field, string $value, string $taxonomy): WP_Term|false
    {
        foreach ((array) ($GLOBALS['ysai_catalog_terms'][$taxonomy] ?? array()) as $term) {
            if ($term instanceof WP_Term && (string) ($term->{$field} ?? '') === $value) {
                return $term;
            }
        }
        return false;
    }
}
if (!function_exists('get_terms')) {
    function get_terms(array $args): array|WP_Error
    {
        $GLOBALS['ysai_catalog_last_terms_query'] = $args;
        return $GLOBALS['ysai_catalog_term_results'] ?? array();
    }
}
if (!function_exists('get_the_terms')) {
    function get_the_terms(int $id, string $taxonomy): array|false|WP_Error
    {
        return $GLOBALS['ysai_catalog_the_terms'][$id][$taxonomy] ?? false;
    }
}
if (!function_exists('get_post')) {
    function get_post(int $id): WP_Post|false
    {
        return $GLOBALS['ysai_catalog_posts'][$id] ?? new WP_Post();
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink(int $id): string
    {
        return 'https://shop.example.test/product/' . $id;
    }
}
if (!function_exists('wc_get_product_terms')) {
    function wc_get_product_terms(int $id, string $taxonomy, array $args = array()): array|WP_Error
    {
        return $GLOBALS['ysai_catalog_attribute_terms'][$id][$taxonomy] ?? array();
    }
}
if (!function_exists('wc_get_formatted_variation')) {
    function wc_get_formatted_variation(object $product, bool $flat = true, bool $includeNames = false, bool $skipAttributesInName = true): string
    {
        return 'variation';
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title(string $value): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '', '-'));
    }
}

final class FakeWooCatalogAttribute
{
    /** @param list<mixed> $options */
    public function __construct(
        private readonly string $name,
        private readonly array $options,
        private readonly bool $taxonomy = false
    ) {
    }

    public function get_name(): string { return $this->name; }
    public function get_options(): array { return $this->options; }
    public function is_taxonomy(): bool { return $this->taxonomy; }
    public function get_variation(): bool { return true; }
    public function get_visible(): bool { return true; }
}

final class FakeWooCatalogProduct
{
    /** @param array<string,mixed> $overrides */
    public function __construct(private readonly int $id, private readonly array $overrides = array())
    {
    }

    private function value(string $key, mixed $default): mixed
    {
        return array_key_exists($key, $this->overrides) ? $this->overrides[$key] : $default;
    }

    public function get_id(): int { return $this->id; }
    public function get_parent_id(): int { return (int) $this->value('parent_id', 0); }
    public function get_type(): string { return (string) $this->value('type', 'simple'); }
    public function is_type(string|array $type): bool { return is_array($type) ? in_array($this->get_type(), $type, true) : $this->get_type() === $type; }
    public function get_status(): string { return (string) $this->value('status', 'publish'); }
    public function is_visible(): bool { return (bool) $this->value('visible', true); }
    public function is_purchasable(): bool { return (bool) $this->value('purchasable', true); }
    public function is_in_stock(): bool { return (bool) $this->value('in_stock', true); }
    public function is_on_sale(): bool { return (bool) $this->value('on_sale', false); }
    public function get_price(): string { return (string) $this->value('price', '10'); }
    public function get_regular_price(): string { return (string) $this->value('regular_price', '10'); }
    public function get_sale_price(): string { return (string) $this->value('sale_price', ''); }
    public function get_price_html(): string { return (string) $this->value('price_html', '<b>$10</b>'); }
    public function get_stock_quantity(): mixed { return $this->value('stock_quantity', null); }
    public function get_stock_status(): string { return (string) $this->value('stock_status', 'instock'); }
    public function managing_stock(): bool { return (bool) $this->value('managing_stock', false); }
    public function get_attributes(): array { return (array) $this->value('attributes', array()); }
    public function get_variation_attributes(): array { return (array) $this->value('variation_attributes', array()); }
    public function get_children(): array { return (array) $this->value('children', array()); }
    public function get_date_modified(): ?object { return null; }
    public function get_name(): string { return (string) $this->value('name', 'Product ' . $this->id); }
    public function get_sku(): string { return (string) $this->value('sku', 'SKU-' . $this->id); }
    public function get_image_id(): int { return 0; }
    public function get_average_rating(): string { return (string) $this->value('rating', '4.5'); }
    public function get_review_count(): int { return (int) $this->value('review_count', 3); }
    public function get_short_description(): string { return (string) $this->value('short_description', 'Description'); }
    public function has_enough_stock(int $quantity): bool { return (bool) $this->value('enough_stock', true); }
    public function is_sold_individually(): bool { return (bool) $this->value('sold_individually', false); }
    public function get_min_purchase_quantity(): int { return (int) $this->value('minimum_quantity', 1); }
    public function get_max_purchase_quantity(): int { return (int) $this->value('maximum_quantity', -1); }
}

function reset_catalog_test_state(): void
{
    $GLOBALS['ysai_catalog_products'] = array();
    $GLOBALS['ysai_catalog_skus'] = array();
    $GLOBALS['ysai_catalog_query_posts'] = array();
    $GLOBALS['ysai_catalog_search_ids'] = array();
    $GLOBALS['ysai_catalog_browse_products'] = array();
    $GLOBALS['ysai_catalog_product_category_slugs'] = array();
    $GLOBALS['ysai_catalog_product_queries'] = array();
    $GLOBALS['ysai_catalog_related_ids'] = array();
    $GLOBALS['ysai_catalog_product_terms'] = array();
    $GLOBALS['ysai_catalog_terms'] = array();
    $GLOBALS['ysai_catalog_alternatives'] = array();
    $GLOBALS['ysai_catalog_term_results'] = array();
    $GLOBALS['ysai_catalog_the_terms'] = array();
    $GLOBALS['ysai_catalog_attribute_terms'] = array();
    $GLOBALS['ysai_catalog_posts'] = array();
    unset(
        $GLOBALS['ysai_catalog_last_query_args'],
        $GLOBALS['ysai_catalog_search_request'],
        $GLOBALS['ysai_catalog_related_request'],
        $GLOBALS['ysai_catalog_last_product_query'],
        $GLOBALS['ysai_catalog_last_terms_query']
    );
}

function catalog_gateway_for_test(): WooCatalogGateway
{
    return new WooCatalogGateway(new SameOriginUrl('https://shop.example.test/'));
}

test('WooCatalogGateway uses WooCommerce search and applies live sale, stock, and price truth', static function (): void {
    reset_catalog_test_state();
    $GLOBALS['ysai_catalog_products'] = array(
        1 => new FakeWooCatalogProduct(1, array('on_sale' => false, 'price' => '9')),
        2 => new FakeWooCatalogProduct(2, array('on_sale' => true, 'in_stock' => true, 'price' => '12')),
        3 => new FakeWooCatalogProduct(3, array('on_sale' => true, 'in_stock' => false, 'price' => '13')),
        4 => new FakeWooCatalogProduct(4, array('on_sale' => true, 'in_stock' => true, 'price' => '18')),
    );
    $GLOBALS['ysai_catalog_search_ids'] = array(1, 2, 2, 3, 4);

    $result = catalog_gateway_for_test()->search('sale', 2, array(
        'on_sale' => true,
        'in_stock' => true,
        'min_price' => 10.0,
        'max_price' => 20.0,
    ));

    assert_same(array(2, 4), array_map(static fn (object $product): int => $product->get_id(), $result->products));
    assert_same(false, $result->resultsTruncated);
    assert_same(true, $result->scanExhausted);
    assert_same(4, $result->scannedCandidates);
    assert_same('sale', $GLOBALS['ysai_catalog_search_request']['term']);
    assert_same(241, $GLOBALS['ysai_catalog_search_request']['limit']);
    assert_false(isset($GLOBALS['ysai_catalog_last_query_args']));
    foreach ($GLOBALS['ysai_catalog_product_queries'] as $queryArgs) {
        assert_false(isset($queryArgs['meta_query']));
    }
    assert_throws(InvalidArgumentException::class, static fn (): object => catalog_gateway_for_test()->search(
        'sale',
        2,
        array('max_price' => 1_000_000_000_001.0)
    ));
});

test('WooCatalogGateway pages related and alternative candidates before access filtering', static function (): void {
    reset_catalog_test_state();
    $source = new FakeWooCatalogProduct(10);
    $hiddenOne = new FakeWooCatalogProduct(11, array('visible' => false));
    $hiddenTwo = new FakeWooCatalogProduct(12, array('visible' => false));
    $visibleOne = new FakeWooCatalogProduct(13);
    $visibleTwo = new FakeWooCatalogProduct(14);
    $GLOBALS['ysai_catalog_products'] = array(
        10 => $source,
        11 => $hiddenOne,
        12 => $hiddenTwo,
        13 => $visibleOne,
        14 => $visibleTwo,
    );
    $GLOBALS['ysai_catalog_related_ids'][10] = array(11, 12, 13, 14);

    $related = catalog_gateway_for_test()->related(10, 2);
    assert_same(array(13, 14), array_map(static fn (object $product): int => $product->get_id(), $related));
    assert_same(240, $GLOBALS['ysai_catalog_related_request']['limit']);

    $hidden = array();
    for ($id = 20; $id < 44; ++$id) {
        $hidden[] = new FakeWooCatalogProduct($id, array('visible' => false));
    }
    $GLOBALS['ysai_catalog_alternatives'] = array_merge($hidden, array($visibleOne, $visibleTwo));
    $alternatives = catalog_gateway_for_test()->alternatives(10, 2);
    assert_same(array(13, 14), array_map(static fn (object $product): int => $product->get_id(), $alternatives));
    assert_same(2, count($GLOBALS['ysai_catalog_product_queries']));
    assert_same(24, $GLOBALS['ysai_catalog_product_queries'][0]['limit']);
    assert_same('date', $GLOBALS['ysai_catalog_product_queries'][0]['orderby']);
    assert_same('DESC', $GLOBALS['ysai_catalog_product_queries'][0]['order']);
    assert_same(1, $GLOBALS['ysai_catalog_product_queries'][0]['page']);
    assert_same(2, $GLOBALS['ysai_catalog_product_queries'][1]['page']);
    assert_same(true, $GLOBALS['ysai_catalog_product_queries'][1]['paginate']);
});

test('WooCatalogGateway browses bounded WooCommerce pages until enough live products are found', static function (): void {
    reset_catalog_test_state();
    $products = array();
    for ($id = 1; $id <= 24; ++$id) {
        $products[] = new FakeWooCatalogProduct($id, array('visible' => false));
    }
    $products[] = new FakeWooCatalogProduct(25);
    $products[] = new FakeWooCatalogProduct(26);
    $GLOBALS['ysai_catalog_browse_products'] = $products;

    $result = catalog_gateway_for_test()->search('', 2);

    assert_same(array(25, 26), array_map(static fn (object $product): int => $product->get_id(), $result->products));
    assert_same(false, $result->resultsTruncated);
    assert_same(true, $result->scanExhausted);
    assert_same(26, $result->scannedCandidates);
    assert_same(2, count($GLOBALS['ysai_catalog_product_queries']));
    assert_same(1, $GLOBALS['ysai_catalog_product_queries'][0]['page']);
    assert_same(2, $GLOBALS['ysai_catalog_product_queries'][1]['page']);
    foreach ($GLOBALS['ysai_catalog_product_queries'] as $queryArgs) {
        assert_same(true, $queryArgs['paginate']);
        assert_false(isset($queryArgs['meta_query']));
    }
});

test('WooCatalogGateway reports the bounded search ceiling instead of implying complete coverage', static function (): void {
    reset_catalog_test_state();
    $ids = range(1000, 1240);
    foreach ($ids as $id) {
        $GLOBALS['ysai_catalog_products'][$id] = new FakeWooCatalogProduct($id, array('visible' => false));
    }
    $GLOBALS['ysai_catalog_search_ids'] = $ids;

    $result = catalog_gateway_for_test()->search('hidden', 3);

    assert_same(array(), $result->products);
    assert_same(true, $result->resultsTruncated);
    assert_same(false, $result->scanExhausted);
    assert_same(240, $result->scannedCandidates);
    assert_same(240, $result->scanLimit);
    assert_same(10, count($GLOBALS['ysai_catalog_product_queries']));
});

test('WooCatalogGateway resolves category filters to WooCommerce slugs without raw taxonomy queries', static function (): void {
    reset_catalog_test_state();
    $GLOBALS['ysai_catalog_terms']['product_cat'][7] = new WP_Term(7, 'Shoes', 'shoes', 2);
    $GLOBALS['ysai_catalog_products'] = array(
        1 => new FakeWooCatalogProduct(1),
        2 => new FakeWooCatalogProduct(2),
    );
    $GLOBALS['ysai_catalog_search_ids'] = array(1, 2);
    $GLOBALS['ysai_catalog_product_category_slugs'] = array(2 => array('shoes'));

    $result = catalog_gateway_for_test()->search('runner', 1, array('category' => '7'));

    assert_same(array(2), array_map(static fn (object $product): int => $product->get_id(), $result->products));
    assert_same(array('shoes'), $GLOBALS['ysai_catalog_product_queries'][0]['category']);
    assert_false(isset($GLOBALS['ysai_catalog_last_query_args']));
});

test('WooCatalogGateway distinguishes unavailable prices from legitimate free products', static function (): void {
    reset_catalog_test_state();
    $gateway = catalog_gateway_for_test();

    $unavailable = $gateway->project(new FakeWooCatalogProduct(70, array(
        'price' => 'contact-us',
        'regular_price' => '-1',
        'sale_price' => 'not-a-number',
        'price_html' => 'Contact us',
    )));
    assert_same(null, $unavailable['price']);
    assert_same(false, $unavailable['price_available']);
    assert_same('unavailable', $unavailable['price_kind']);
    assert_same(null, $unavailable['regular_price']);
    assert_same(null, $unavailable['sale_price']);
    assert_same('Contact us', $unavailable['price_text']);

    $free = $gateway->project(new FakeWooCatalogProduct(71, array(
        'price' => '0',
        'regular_price' => '0',
        'sale_price' => '',
        'price_html' => 'Free',
    )));
    assert_same(0.0, $free['price']);
    assert_same(true, $free['price_available']);
    assert_same('fixed', $free['price_kind']);
    assert_same(0.0, $free['regular_price']);
    assert_same(null, $free['sale_price']);

    $variable = $gateway->project(new FakeWooCatalogProduct(72, array(
        'type' => 'variable',
        'price' => '12.50',
        'regular_price' => '15',
        'price_html' => 'From $12.50',
    )));
    assert_same(12.5, $variable['price']);
    assert_same(true, $variable['price_available']);
    assert_same('from', $variable['price_kind']);
});

test('WooCatalogGateway bounds merchant-controlled projection fields and reports truncation', static function (): void {
    reset_catalog_test_state();
    $attributes = array();
    for ($group = 0; $group < 30; ++$group) {
        $options = array();
        for ($option = 0; $option < 45; ++$option) {
            $options[] = 'option-' . $option . '-' . str_repeat('v', 210);
        }
        $attributes['attribute-' . $group] = new FakeWooCatalogAttribute('attribute-' . $group, $options);
    }
    $product = new FakeWooCatalogProduct(30, array(
        'name' => str_repeat('N', 400),
        'sku' => str_repeat('S', 180),
        'attributes' => $attributes,
        'price_html' => '<span>' . str_repeat('$', 250) . '</span>',
    ));
    $GLOBALS['ysai_catalog_products'][30] = $product;
    $GLOBALS['ysai_catalog_the_terms'][30]['product_cat'] = array_map(
        static fn (int $id): WP_Term => new WP_Term($id, 'Category ' . $id, 'category-' . $id, 1),
        range(1, 10)
    );

    $card = catalog_gateway_for_test()->project($product);

    assert_same(300, strlen($card['name']));
    assert_same(120, strlen($card['sku']));
    assert_same(200, strlen($card['price_text']));
    assert_count_value(8, $card['categories']);
    assert_same(true, $card['categories_truncated']);
    assert_count_value(24, $card['attributes']);
    assert_same(true, $card['attributes_truncated']);
    assert_count_value(40, reset($card['attributes']));
    assert_true(strlen(reset($card['attributes'])[0]) <= 200);
    assert_same('https://shop.example.test/product/30', $card['url']);
});

test('WooCatalogGateway fingerprints bounded identities and rejects pathological metadata', static function (): void {
    reset_catalog_test_state();
    $gateway = catalog_gateway_for_test();
    $normal = new FakeWooCatalogProduct(40, array(
        'attributes' => array('size' => new FakeWooCatalogAttribute('size', array('small', 'large'))),
    ));
    $first = $gateway->identity($normal);
    $second = $gateway->identity($normal);
    assert_same($first, $second);
    assert_same(64, strlen($first['fingerprint']));

    $tooMany = array();
    for ($index = 0; $index < 101; ++$index) {
        $tooMany['attribute-' . $index] = 'value';
    }
    assert_throws(LengthException::class, static fn (): array => $gateway->identity(
        new FakeWooCatalogProduct(41, array('attributes' => $tooMany))
    ));
    assert_throws(LengthException::class, static fn (): array => $gateway->identity(
        new FakeWooCatalogProduct(42, array('sku' => str_repeat('x', 4097)))
    ));
});
