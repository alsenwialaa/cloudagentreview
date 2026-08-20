<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

use YassinStore\AiAssistant\Application\Catalog\CatalogSearchResult;
use YassinStore\AiAssistant\Application\Contract\CatalogGateway;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;

final class WooCatalogGateway implements CatalogGateway
{
    private const MAX_QUERY_LENGTH = 240;
    private const MAX_CATEGORY_LENGTH = 160;
    private const MAX_CATALOG_PRICE = 1_000_000_000_000.0;
    private const MAX_PRODUCT_NAME = 300;
    private const MAX_SKU_LENGTH = 120;
    private const MAX_PRICE_TEXT = 200;
    private const MAX_URL_LENGTH = 2048;
    private const MAX_PROJECT_ATTRIBUTES = 24;
    private const MAX_PROJECT_OPTIONS = 40;
    private const MAX_PROJECT_CATEGORIES = 8;
    private const MAX_IDENTITY_ATTRIBUTES = 100;
    private const MAX_IDENTITY_OPTIONS = 500;
    private const MAX_IDENTITY_TEXT_LENGTH = 4096;
    private const MAX_VARIATION_ATTRIBUTES = 30;
    private const MAX_VARIATION_OPTIONS = 500;
    private const MAX_VARIATION_CHILDREN = 1000;
    private const PRODUCT_QUERY_PAGE_SIZE = 24;
    private const MAX_SEARCH_CANDIDATES = 240;

    private readonly VariationAttributeMatcher $variationMatcher;

    public function __construct(
        private readonly SameOriginUrl $urls,
        ?VariationAttributeMatcher $variationMatcher = null
    ) {
        $this->variationMatcher = $variationMatcher ?? new VariationAttributeMatcher();
    }

    public function search(string $query, int $limit, array $filters = array()): CatalogSearchResult
    {
        $query = Text::plain($query, self::MAX_QUERY_LENGTH);
        $limit = max(1, min(12, $limit));
        $filters = $this->normalizeSearchFilters($filters);

        $categorySlug = null;
        if (isset($filters['category'])) {
            $categorySlug = $this->categorySlug($filters['category']);
            if ($categorySlug === null) {
                return $this->emptySearchResult();
            }
        }

        if ($query !== '') {
            [$candidateIds, $sourceTruncated] = $this->searchCandidateIds($query);
            return $this->searchCandidateProducts(
                $candidateIds,
                $sourceTruncated,
                $limit,
                $filters,
                $categorySlug
            );
        }

        return $this->browseCandidateProducts($limit, $filters, $categorySlug);
    }


    /** @param list<object> $products @return list<object> */
    public function sortProducts(array $products, string $sort): array
    {
        $allowed = array('relevance', 'price_low', 'price_high', 'newest', 'best_selling', 'rating');
        if (!in_array($sort, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported catalog sort.');
        }
        $products = array_values(array_filter($products, 'is_object'));
        if ($sort === 'relevance') {
            return $products;
        }
        usort($products, function (object $left, object $right) use ($sort): int {
            $comparison = match ($sort) {
                'price_low' => $this->compareCatalogPrice($left, $right, false),
                'price_high' => $this->compareCatalogPrice($left, $right, true),
                'newest' => $this->productTimestamp($right) <=> $this->productTimestamp($left),
                'best_selling' => $this->productSales($right) <=> $this->productSales($left),
                'rating' => $this->productRating($right) <=> $this->productRating($left),
                default => 0,
            };
            if ($comparison !== 0) {
                return $comparison;
            }
            $leftId = method_exists($left, 'get_id') ? (int) $left->get_id() : 0;
            $rightId = method_exists($right, 'get_id') ? (int) $right->get_id() : 0;
            return $leftId <=> $rightId;
        });
        return $products;
    }

    public function product(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        $product = wc_get_product($id);
        return is_object($product) && $this->isAccessible($product) ? $product : null;
    }

    public function bySku(string $sku): ?object
    {
        $sku = Text::plain($sku, self::MAX_SKU_LENGTH);
        if ($sku === '') {
            return null;
        }
        $id = (int) wc_get_product_id_by_sku($sku);
        return $id > 0 ? $this->product($id) : null;
    }

    public function related(int $id, int $limit): array
    {
        $source = $this->product($id);
        if ($source === null) {
            return array();
        }
        $limit = max(1, min(12, $limit));
        $catalogId = $source->is_type('variation')
            ? (int) $source->get_parent_id()
            : (int) $source->get_id();
        $ids = wc_get_related_products($catalogId, self::MAX_SEARCH_CANDIDATES);
        return array_slice($this->productsFromIds((array) $ids), 0, $limit);
    }

    public function alternatives(int $id, int $limit): array
    {
        $source = $this->product($id);
        if ($source === null) {
            return array();
        }
        $limit = max(1, min(12, $limit));
        $catalogId = $source->is_type('variation')
            ? (int) $source->get_parent_id()
            : (int) $source->get_id();
        $categoryIds = wc_get_product_term_ids($catalogId, 'product_cat');
        $args = array(
            'status' => 'publish',
            'exclude' => array_values(array_unique(array_filter(array(
                $catalogId,
                (int) $source->get_id(),
            )))),
            // Use only documented WC_Product_Query ordering values.
            // Related-product relevance is handled separately by WooCommerce;
            // category alternatives are ordered by current publication date.
            'orderby' => 'date',
            'order' => 'DESC',
        );

        if (is_array($categoryIds) && $categoryIds !== array()) {
            $slugs = array();
            foreach (array_slice($categoryIds, 0, 3) as $termId) {
                $term = get_term((int) $termId, 'product_cat');
                if (!$term instanceof \WP_Term) {
                    continue;
                }
                $slug = Text::plain((string) $term->slug, self::MAX_CATEGORY_LENGTH);
                if ($slug !== '') {
                    $slugs[] = $slug;
                }
            }
            if ($slugs !== array()) {
                $args['category'] = array_values(array_unique($slugs));
            }
        }

        return $this->pagedAccessibleProducts($args, $limit);
    }

    public function categories(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'number' => $limit,
            'orderby' => 'count',
            'order' => 'DESC',
        ));
        if (is_wp_error($terms) || !is_array($terms)) {
            return array();
        }

        $categories = array();
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $name = Text::plain((string) $term->name, 200);
            $slug = Text::plain((string) $term->slug, self::MAX_CATEGORY_LENGTH);
            if ($name === '' || $slug === '') {
                continue;
            }
            $categories[] = array(
                'name' => $name,
                'slug' => $slug,
                'count' => max(0, (int) $term->count),
            );
            if (count($categories) >= $limit) {
                break;
            }
        }
        return $categories;
    }

    public function resolveVariation(int $parentId, array $attributes): ?object
    {
        $parent = $this->product($parentId);
        if ($parent === null
            || !$parent->is_type('variable')
            || $attributes === array()
            || array_is_list($attributes)
            || count($attributes) > self::MAX_VARIATION_ATTRIBUTES) {
            return null;
        }

        $definitions = $this->variationDefinitions($parent);
        if ($definitions === array() || count($attributes) !== count($definitions)) {
            return null;
        }
        $requested = $this->normalizeRequestedVariationAttributes($attributes, $definitions);
        if ($requested === null
            || $requested === array()
            || count($requested) !== count($definitions)
            || array_keys($requested) !== array_keys($definitions)) {
            return null;
        }

        $children = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) $parent->get_children()
        ))));
        if (count($children) > self::MAX_VARIATION_CHILDREN) {
            // Never resolve a prefix when uniqueness across all children cannot
            // be proven within the safety bound.
            return null;
        }

        $matches = array();
        foreach ($children as $childId) {
            $variation = wc_get_product((int) $childId);
            if (!is_object($variation)
                || !$this->isAccessible($variation)
                || !$variation->is_purchasable()
                || !$variation->is_in_stock()) {
                continue;
            }
            $actual = $this->normalizeActualVariationAttributes(
                (array) $variation->get_attributes(),
                $definitions
            );
            if (array_keys($actual) === array_keys($definitions)
                && $this->variationMatcher->matches($requested, $actual)) {
                $matches[] = $variation;
                if (count($matches) > 1) {
                    return null;
                }
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function identity(object $product): array
    {
        $id = (int) $product->get_id();
        $parentId = (int) $product->get_parent_id();
        if ($id <= 0 || $parentId < 0) {
            throw new \RuntimeException('The product identity is invalid.');
        }

        $modified = $product->get_date_modified();
        $modifiedTimestamp = is_object($modified) && method_exists($modified, 'getTimestamp')
            ? (int) $modified->getTimestamp()
            : 0;
        $data = array(
            'id' => $id,
            'parent_id' => $parentId,
            'type' => Text::plain((string) $product->get_type(), 40),
            'sku_hash' => $this->identityScalar((string) $product->get_sku()),
            'modified' => $modifiedTimestamp,
            'price_hash' => $this->identityScalar((string) $product->get_price()),
            'regular_price_hash' => $this->identityScalar((string) $product->get_regular_price()),
            'sale_price_hash' => $this->identityScalar((string) $product->get_sale_price()),
            'stock' => is_scalar($product->get_stock_quantity()) || $product->get_stock_quantity() === null
                ? $product->get_stock_quantity()
                : null,
            'stock_status' => Text::plain((string) $product->get_stock_status(), 40),
            'purchasable' => (bool) $product->is_purchasable(),
            'in_stock' => (bool) $product->is_in_stock(),
            'attributes' => $this->identityAttributes($product),
        );
        $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to fingerprint the product.');
        }
        return array(
            'id' => $id,
            'parent_id' => $parentId,
            'type' => (string) $data['type'],
            'fingerprint' => hash('sha256', $encoded),
        );
    }

    public function project(object $product): array
    {
        $catalogId = (int) ($product->get_parent_id() ?: $product->get_id());
        $imageId = (int) $product->get_image_id();
        $image = $imageId > 0
            ? wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail')
            : false;

        $categories = array();
        $categoriesTruncated = false;
        $categoryTerms = get_the_terms($catalogId, 'product_cat');
        if (is_array($categoryTerms)) {
            foreach ($categoryTerms as $term) {
                if (!$term instanceof \WP_Term) {
                    continue;
                }
                $name = Text::plain((string) $term->name, 200);
                if ($name === '' || in_array($name, $categories, true)) {
                    continue;
                }
                if (count($categories) >= self::MAX_PROJECT_CATEGORIES) {
                    $categoriesTruncated = true;
                    break;
                }
                $categories[] = $name;
            }
        }

        [$attributes, $attributesTruncated] = $this->projectAttributes($product, $catalogId);
        [$variationOptions, $variationOptionsTruncated] = $this->projectVariationOptions($product);

        $name = (string) $product->get_name();
        if ($product->is_type('variation')) {
            $parent = wc_get_product((int) $product->get_parent_id());
            if (is_object($parent)) {
                $name = (string) $parent->get_name()
                    . ' — '
                    . (string) wc_get_formatted_variation($product, true, false, true);
            }
        }

        $price = $this->catalogPrice($product->get_price());
        $regularPrice = $this->catalogPrice($product->get_regular_price());
        $salePrice = $this->catalogPrice($product->get_sale_price());
        $stockQuantity = $product->managing_stock() ? $product->get_stock_quantity() : null;
        $priceText = html_entity_decode(
            wp_strip_all_tags((string) $product->get_price_html()),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return array(
            'name' => Text::plain($name, self::MAX_PRODUCT_NAME),
            'sku' => Text::plain((string) $product->get_sku(), self::MAX_SKU_LENGTH),
            'type' => Text::plain((string) $product->get_type(), 40),
            'price' => $price,
            'price_available' => $price !== null,
            'price_kind' => $price === null
                ? 'unavailable'
                : ($product->is_type('variable') ? 'from' : 'fixed'),
            'regular_price' => $regularPrice,
            'sale_price' => $salePrice,
            'price_text' => Text::plain($priceText, self::MAX_PRICE_TEXT),
            'currency' => Text::plain((string) get_woocommerce_currency(), 12),
            'in_stock' => (bool) $product->is_in_stock(),
            'stock_status' => Text::plain((string) $product->get_stock_status(), 40),
            'stock_quantity' => is_numeric($stockQuantity)
                ? max(-2_000_000_000, min(2_000_000_000, (int) $stockQuantity))
                : null,
            'rating' => min(5.0, $this->safeNumber($product->get_average_rating(), 0.0)),
            'review_count' => max(0, min(2_000_000_000, (int) $product->get_review_count())),
            'short_description' => Text::plain((string) $product->get_short_description(), 500),
            'image' => $this->publicHttpUrl(is_string($image) ? $image : ''),
            'url' => $this->urls->sanitize(get_permalink($catalogId)),
            'purchasable' => (bool) $product->is_purchasable(),
            'requires_options' => (bool) $product->is_type('variable'),
            'categories' => $categories,
            'categories_truncated' => $categoriesTruncated,
            'attributes' => $attributes,
            'attributes_truncated' => $attributesTruncated,
            'variation_options' => $variationOptions,
            'variation_options_truncated' => $variationOptionsTruncated,
        );
    }

    private function isAccessible(object $product): bool
    {
        if (!method_exists($product, 'get_status') || $product->get_status() !== 'publish') {
            return false;
        }
        $catalogId = method_exists($product, 'is_type') && $product->is_type('variation')
            ? (int) $product->get_parent_id()
            : (int) $product->get_id();
        if ($catalogId <= 0) {
            return false;
        }
        $post = get_post($catalogId);
        if ($post instanceof \WP_Post && trim((string) $post->post_password) !== '') {
            return false;
        }
        if (method_exists($product, 'is_type') && $product->is_type('variation')) {
            $parent = wc_get_product((int) $product->get_parent_id());
            return is_object($parent)
                && method_exists($parent, 'get_status')
                && $parent->get_status() === 'publish'
                && method_exists($parent, 'is_visible')
                && $parent->is_visible();
        }
        return method_exists($product, 'is_visible') && $product->is_visible();
    }

    /** @return array{count:int,hash:string} */
    private function identityAttributes(object $product): array
    {
        $source = (array) $product->get_attributes();
        if (count($source) > self::MAX_IDENTITY_ATTRIBUTES) {
            throw new \LengthException('The product has too many attributes to fingerprint safely.');
        }

        $groups = array();
        foreach ($source as $name => $attribute) {
            $attributeName = is_object($attribute) && method_exists($attribute, 'get_name')
                ? (string) $attribute->get_name()
                : (string) $name;
            $values = is_object($attribute) && method_exists($attribute, 'get_options')
                ? (array) $attribute->get_options()
                : (is_array($attribute) ? $attribute : array($attribute));
            if (count($values) > self::MAX_IDENTITY_OPTIONS) {
                throw new \LengthException('A product attribute has too many options to fingerprint safely.');
            }

            $valueHashes = array();
            foreach ($values as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new \RuntimeException('A product attribute contains an invalid identity value.');
                }
                $valueHashes[] = $this->identityScalar(get_debug_type($value) . "\0" . (string) $value);
            }
            sort($valueHashes, SORT_STRING);
            $group = array(
                'name_hash' => $this->identityScalar($attributeName),
                'value_count' => count($valueHashes),
                'value_hashes' => $valueHashes,
                'variation' => is_object($attribute) && method_exists($attribute, 'get_variation')
                    ? (bool) $attribute->get_variation()
                    : false,
                'visible' => is_object($attribute) && method_exists($attribute, 'get_visible')
                    ? (bool) $attribute->get_visible()
                    : false,
            );
            $encoded = wp_json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                throw new \RuntimeException('Unable to fingerprint a product attribute group.');
            }
            $groups[] = hash('sha256', $encoded);
        }
        sort($groups, SORT_STRING);
        $encoded = wp_json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to fingerprint product attributes.');
        }
        return array('count' => count($groups), 'hash' => hash('sha256', $encoded));
    }

    /** @return array{0:array<string,list<string>>,1:bool} */
    private function projectAttributes(object $product, int $catalogId): array
    {
        $projected = array();
        $truncated = false;
        foreach ((array) $product->get_attributes() as $name => $attribute) {
            if (count($projected) >= self::MAX_PROJECT_ATTRIBUTES) {
                $truncated = true;
                break;
            }
            $rawName = is_object($attribute) && method_exists($attribute, 'get_name')
                ? (string) $attribute->get_name()
                : (string) $name;
            $label = Text::plain((string) wc_attribute_label($rawName), 160);
            if ($label === '') {
                continue;
            }

            $rawOptions = array();
            if (is_object($attribute) && method_exists($attribute, 'get_options')) {
                $taxonomy = method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy();
                $rawOptions = $taxonomy
                    ? wc_get_product_terms($catalogId, $attribute->get_name(), array('fields' => 'names'))
                    : $attribute->get_options();
                if (is_wp_error($rawOptions)) {
                    $rawOptions = array();
                }
            } elseif (is_array($attribute)) {
                $rawOptions = $attribute;
            } elseif (is_scalar($attribute) || $attribute === null) {
                $rawOptions = array($attribute);
            } else {
                $truncated = true;
            }

            [$options, $optionsTruncated] = $this->boundedAttributeValues($rawName, (array) $rawOptions);
            $truncated = $truncated || $optionsTruncated;
            if ($options !== array()) {
                $projected[$this->uniqueLabel($label, $projected)] = $options;
            }
        }
        return array($projected, $truncated);
    }

    /** @return array{0:array<string,list<string>>,1:bool} */
    private function projectVariationOptions(object $product): array
    {
        if (!$product->is_type('variable') || !method_exists($product, 'get_variation_attributes')) {
            return array(array(), false);
        }

        $projected = array();
        $truncated = false;
        foreach ((array) $product->get_variation_attributes() as $name => $values) {
            if (count($projected) >= self::MAX_PROJECT_ATTRIBUTES) {
                $truncated = true;
                break;
            }
            $rawName = Text::plain((string) $name, 160);
            $label = Text::plain((string) wc_attribute_label($rawName), 160);
            if ($rawName === '' || $label === '') {
                continue;
            }
            [$options, $optionsTruncated] = $this->boundedAttributeValues($rawName, (array) $values);
            $truncated = $truncated || $optionsTruncated;
            if ($options !== array()) {
                $projected[$this->uniqueLabel($label, $projected)] = $options;
            }
        }
        return array($projected, $truncated);
    }

    /** @param array<mixed> $values @return array{0:list<string>,1:bool} */
    private function boundedAttributeValues(string $name, array $values): array
    {
        $result = array();
        $truncated = count($values) > self::MAX_PROJECT_OPTIONS;
        foreach (array_slice($values, 0, self::MAX_PROJECT_OPTIONS) as $value) {
            if (!is_scalar($value) && $value !== null) {
                $truncated = true;
                continue;
            }
            $label = Text::plain($this->attributeValueLabel($name, (string) $value), 200);
            if ($label !== '' && !in_array($label, $result, true)) {
                $result[] = $label;
            }
        }
        return array($result, $truncated);
    }

    /** @param array<string,mixed> $existing */
    private function uniqueLabel(string $label, array $existing): string
    {
        if (!array_key_exists($label, $existing)) {
            return $label;
        }
        for ($suffix = 2; $suffix <= 99; ++$suffix) {
            $candidate = Text::plain($label . ' (' . $suffix . ')', 160);
            if (!array_key_exists($candidate, $existing)) {
                return $candidate;
            }
        }
        return hash('sha256', $label);
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    private function normalizeSearchFilters(array $filters): array
    {
        $allowed = array('category', 'min_price', 'max_price', 'in_stock', 'on_sale', 'exclude_ids', 'sort');
        foreach (array_keys($filters) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported catalog filter.');
            }
        }

        $normalized = array();
        if (array_key_exists('category', $filters)) {
            if (!is_string($filters['category'])) {
                throw new \InvalidArgumentException('The catalog category filter must be text.');
            }
            $category = Text::plain($filters['category'], self::MAX_CATEGORY_LENGTH);
            if ($category === '') {
                throw new \InvalidArgumentException('The catalog category filter cannot be empty.');
            }
            $normalized['category'] = $category;
        }
        foreach (array('min_price', 'max_price') as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            $value = $filters[$key];
            if ((!is_int($value) && !is_float($value))
                || !is_finite((float) $value)
                || (float) $value < 0
                || (float) $value > self::MAX_CATALOG_PRICE) {
                throw new \InvalidArgumentException('The catalog price filter is invalid.');
            }
            $normalized[$key] = (float) $value;
        }
        if (isset($normalized['min_price'], $normalized['max_price'])
            && $normalized['min_price'] > $normalized['max_price']) {
            throw new \InvalidArgumentException('The minimum catalog price cannot exceed the maximum price.');
        }
        foreach (array('in_stock', 'on_sale') as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            if (!is_bool($filters[$key])) {
                throw new \InvalidArgumentException('The catalog availability filters must be booleans.');
            }
            $normalized[$key] = $filters[$key];
        }
        if (array_key_exists('exclude_ids', $filters)) {
            if (!is_array($filters['exclude_ids']) || !array_is_list($filters['exclude_ids'])) {
                throw new \InvalidArgumentException('Catalog exclusions must be a list.');
            }
            $exclude = array();
            foreach (array_slice($filters['exclude_ids'], 0, self::MAX_SEARCH_CANDIDATES) as $rawId) {
                if (!is_int($rawId) || $rawId < 1) {
                    throw new \InvalidArgumentException('Catalog exclusions contain an invalid product ID.');
                }
                $exclude[$rawId] = true;
            }
            $normalized['exclude_ids'] = array_keys($exclude);
        }
        if (array_key_exists('sort', $filters)) {
            if (!is_string($filters['sort'])
                || !in_array($filters['sort'], array('relevance', 'price_low', 'price_high', 'newest', 'best_selling', 'rating'), true)) {
                throw new \InvalidArgumentException('The catalog sort is invalid.');
            }
            $normalized['sort'] = $filters['sort'];
        }
        return $normalized;
    }

    /** @param array<string,mixed> $filters */
    private function matchesLiveFilters(object $product, array $filters): bool
    {
        $productId = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        if ($productId > 0 && in_array($productId, (array) ($filters['exclude_ids'] ?? array()), true)) {
            return false;
        }
        if (($filters['in_stock'] ?? false) === true
            && (!method_exists($product, 'is_in_stock') || !$product->is_in_stock())) {
            return false;
        }
        if (($filters['on_sale'] ?? false) === true
            && (!method_exists($product, 'is_on_sale') || !$product->is_on_sale())) {
            return false;
        }
        if (isset($filters['min_price']) || isset($filters['max_price'])) {
            if (!method_exists($product, 'get_price')) {
                return false;
            }
            $price = $this->catalogPrice($product->get_price());
            if ($price === null) {
                return false;
            }
            if (isset($filters['min_price']) && $price < (float) $filters['min_price']) {
                return false;
            }
            if (isset($filters['max_price']) && $price > (float) $filters['max_price']) {
                return false;
            }
        }
        return true;
    }

    private function attributeValueLabel(string $name, string $value): string
    {
        $taxonomy = preg_replace('/^attribute_/', '', $name) ?? $name;
        if (taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $value, $taxonomy);
            if ($term instanceof \WP_Term) {
                return (string) $term->name;
            }
        }
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param list<int> $ids @return list<object> */
    private function productsFromIds(array $ids): array
    {
        $products = array();
        $seen = array();
        foreach (array_slice($ids, 0, self::MAX_SEARCH_CANDIDATES) as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $product = $this->product($id);
            if ($product !== null) {
                $products[] = $product;
            }
        }
        return $products;
    }


    private function compareCatalogPrice(object $left, object $right, bool $descending): int
    {
        $leftPrice = method_exists($left, 'get_price') ? $this->catalogPrice($left->get_price()) : null;
        $rightPrice = method_exists($right, 'get_price') ? $this->catalogPrice($right->get_price()) : null;
        if ($leftPrice === null || $rightPrice === null) {
            if ($leftPrice === $rightPrice) {
                return 0;
            }
            return $leftPrice === null ? 1 : -1;
        }
        return $descending ? $rightPrice <=> $leftPrice : $leftPrice <=> $rightPrice;
    }

    private function productTimestamp(object $product): int
    {
        if (!method_exists($product, 'get_date_created')) {
            return 0;
        }
        $date = $product->get_date_created();
        return is_object($date) && method_exists($date, 'getTimestamp') ? max(0, (int) $date->getTimestamp()) : 0;
    }

    private function productSales(object $product): int
    {
        return method_exists($product, 'get_total_sales') ? max(0, (int) $product->get_total_sales()) : 0;
    }

    private function productRating(object $product): float
    {
        if (!method_exists($product, 'get_average_rating')) {
            return 0.0;
        }
        $rating = (float) $product->get_average_rating();
        return is_finite($rating) ? max(0.0, min(5.0, $rating)) : 0.0;
    }

    private function wooOrderBy(string $sort): string
    {
        return match ($sort) {
            'price_low', 'price_high' => 'price',
            'best_selling' => 'popularity',
            'rating' => 'rating',
            default => 'date',
        };
    }

    private function wooOrder(string $sort): string
    {
        return $sort === 'price_low' ? 'ASC' : 'DESC';
    }

    private function categorySlug(string $value): ?string
    {
        $value = Text::plain($value, self::MAX_CATEGORY_LENGTH);
        if ($value === '') {
            return null;
        }

        $term = null;
        $numeric = filter_var($value, FILTER_VALIDATE_INT);
        if (is_int($numeric) && $numeric > 0) {
            $candidate = get_term($numeric, 'product_cat');
            $term = $candidate instanceof \WP_Term ? $candidate : null;
        }
        if (!$term instanceof \WP_Term) {
            $candidate = get_term_by('slug', sanitize_title($value), 'product_cat');
            $term = $candidate instanceof \WP_Term ? $candidate : null;
        }
        if (!$term instanceof \WP_Term) {
            $candidate = get_term_by('name', $value, 'product_cat');
            $term = $candidate instanceof \WP_Term ? $candidate : null;
        }
        if (!$term instanceof \WP_Term) {
            return null;
        }
        $slug = Text::plain((string) $term->slug, self::MAX_CATEGORY_LENGTH);
        return $slug !== '' ? $slug : null;
    }

    private function emptySearchResult(): CatalogSearchResult
    {
        return new CatalogSearchResult(
            array(),
            false,
            true,
            0,
            self::MAX_SEARCH_CANDIDATES
        );
    }

    /** @return array{0:list<int>,1:bool} */
    private function searchCandidateIds(string $query): array
    {
        if (!class_exists('WC_Data_Store')) {
            throw new \RuntimeException('WooCommerce product search is unavailable.');
        }
        $store = \WC_Data_Store::load('product');
        if (!is_object($store) || !method_exists($store, 'search_products')) {
            throw new \RuntimeException('WooCommerce product search is unavailable.');
        }

        $rawIds = $store->search_products(
            $query,
            '',
            false,
            false,
            self::MAX_SEARCH_CANDIDATES + 1
        );
        if (!is_array($rawIds)) {
            throw new \UnexpectedValueException('WooCommerce returned an invalid product-search result.');
        }

        $sourceTruncated = count($rawIds) > self::MAX_SEARCH_CANDIDATES;
        $ids = array();
        $seen = array();
        foreach (array_slice($rawIds, 0, self::MAX_SEARCH_CANDIDATES) as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if (!is_int($id) || $id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
        return array($ids, $sourceTruncated);
    }

    /**
     * @param list<int> $candidateIds
     * @param array<string,mixed> $filters
     */
    private function searchCandidateProducts(
        array $candidateIds,
        bool $sourceTruncated,
        int $limit,
        array $filters,
        ?string $categorySlug
    ): CatalogSearchResult {
        if ($candidateIds === array()) {
            return new CatalogSearchResult(
                array(),
                $sourceTruncated,
                !$sourceTruncated,
                0,
                self::MAX_SEARCH_CANDIDATES
            );
        }

        $products = array();
        $scanned = 0;
        $candidateCount = count($candidateIds);
        $sort = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'relevance';
        $requiresFullScan = $sort !== 'relevance';
        for ($offset = 0; $offset < $candidateCount; $offset += self::PRODUCT_QUERY_PAGE_SIZE) {
            $chunk = array_slice($candidateIds, $offset, self::PRODUCT_QUERY_PAGE_SIZE);
            $args = array(
                'status' => 'publish',
                'include' => $chunk,
                'limit' => count($chunk),
                'return' => 'objects',
            );
            if ($categorySlug !== null) {
                $args['category'] = array($categorySlug);
            }

            $loaded = wc_get_products($args);
            if (!is_array($loaded) || !array_is_list($loaded) || count($loaded) > count($chunk)) {
                throw new \UnexpectedValueException('WooCommerce returned an invalid product collection.');
            }
            $byId = array();
            foreach ($loaded as $product) {
                if (!is_object($product) || !method_exists($product, 'get_id')) {
                    throw new \UnexpectedValueException('WooCommerce returned an invalid product object.');
                }
                $productId = (int) $product->get_id();
                if ($productId > 0 && !isset($byId[$productId])) {
                    $byId[$productId] = $product;
                }
            }

            foreach ($chunk as $candidateId) {
                ++$scanned;
                $product = $byId[$candidateId] ?? null;
                if (is_object($product)
                    && $this->isAccessible($product)
                    && $this->matchesLiveFilters($product, $filters)) {
                    $products[] = $product;
                }

                if (!$requiresFullScan && count($products) >= $limit) {
                    $exhausted = !$sourceTruncated && $scanned >= $candidateCount;
                    return new CatalogSearchResult(
                        array_slice($products, 0, $limit),
                        !$exhausted,
                        $exhausted,
                        $scanned,
                        self::MAX_SEARCH_CANDIDATES
                    );
                }
            }
        }

        if ($requiresFullScan) {
            $products = $this->sortProducts($products, $sort);
        }
        $hasUnreturnedMatches = count($products) > $limit;
        $exhausted = !$sourceTruncated && !$hasUnreturnedMatches;
        return new CatalogSearchResult(
            array_slice($products, 0, $limit),
            !$exhausted,
            $exhausted,
            $scanned,
            self::MAX_SEARCH_CANDIDATES
        );
    }

    /** @param array<string,mixed> $filters */
    private function browseCandidateProducts(
        int $limit,
        array $filters,
        ?string $categorySlug
    ): CatalogSearchResult {
        $products = array();
        $seen = array();
        $scanned = 0;
        $page = 1;

        while ($scanned < self::MAX_SEARCH_CANDIDATES) {
            $args = array(
                'status' => 'publish',
                'limit' => self::PRODUCT_QUERY_PAGE_SIZE,
                'page' => $page,
                'paginate' => true,
                'return' => 'objects',
                'orderby' => $this->wooOrderBy((string) ($filters['sort'] ?? 'newest')),
                'order' => $this->wooOrder((string) ($filters['sort'] ?? 'newest')),
            );
            if (($filters['exclude_ids'] ?? array()) !== array()) {
                $args['exclude'] = $filters['exclude_ids'];
            }
            if ($categorySlug !== null) {
                $args['category'] = array($categorySlug);
            }
            [$pageProducts, $maxPages] = $this->productPage($args);
            if ($pageProducts === array()) {
                return new CatalogSearchResult(
                    $products,
                    false,
                    true,
                    $scanned,
                    self::MAX_SEARCH_CANDIDATES
                );
            }

            $pageCount = count($pageProducts);
            foreach ($pageProducts as $index => $product) {
                ++$scanned;
                $productId = (int) $product->get_id();
                if ($productId > 0 && !isset($seen[$productId])) {
                    $seen[$productId] = true;
                    if ($this->isAccessible($product)
                        && $this->matchesLiveFilters($product, $filters)) {
                        $products[] = $product;
                    }
                }

                $hasMore = $index + 1 < $pageCount || $page < $maxPages;
                if (count($products) >= $limit || $scanned >= self::MAX_SEARCH_CANDIDATES) {
                    $exhausted = !$hasMore;
                    return new CatalogSearchResult(
                        $products,
                        !$exhausted,
                        $exhausted,
                        $scanned,
                        self::MAX_SEARCH_CANDIDATES
                    );
                }
            }

            if ($page >= $maxPages) {
                return new CatalogSearchResult(
                    $products,
                    false,
                    true,
                    $scanned,
                    self::MAX_SEARCH_CANDIDATES
                );
            }
            ++$page;
        }

        return new CatalogSearchResult(
            $products,
            true,
            false,
            self::MAX_SEARCH_CANDIDATES,
            self::MAX_SEARCH_CANDIDATES
        );
    }

    /**
     * @param array<string,mixed> $baseArgs
     * @return list<object>
     */
    private function pagedAccessibleProducts(array $baseArgs, int $limit): array
    {
        $products = array();
        $seen = array();
        $scanned = 0;
        $page = 1;
        while ($scanned < self::MAX_SEARCH_CANDIDATES) {
            [$pageProducts, $maxPages] = $this->productPage(array_replace($baseArgs, array(
                'limit' => self::PRODUCT_QUERY_PAGE_SIZE,
                'page' => $page,
                'paginate' => true,
                'return' => 'objects',
            )));
            if ($pageProducts === array()) {
                break;
            }
            foreach ($pageProducts as $product) {
                ++$scanned;
                $productId = (int) $product->get_id();
                if ($productId <= 0 || isset($seen[$productId])) {
                    continue;
                }
                $seen[$productId] = true;
                if (!$this->isAccessible($product)) {
                    continue;
                }
                $products[] = $product;
                if (count($products) >= $limit || $scanned >= self::MAX_SEARCH_CANDIDATES) {
                    return $products;
                }
            }
            if ($page >= $maxPages) {
                break;
            }
            ++$page;
        }
        return $products;
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0:list<object>,1:int}
     */
    private function productPage(array $args): array
    {
        $result = wc_get_products($args);
        if (!is_object($result)
            || !is_array($result->products ?? null)
            || !is_int($result->total ?? null)
            || !is_int($result->max_num_pages ?? null)
            || $result->total < 0
            || $result->total > 2_000_000_000
            || $result->max_num_pages < 0
            || $result->max_num_pages > 1_000_000
            || count($result->products) > self::PRODUCT_QUERY_PAGE_SIZE) {
            throw new \UnexpectedValueException('WooCommerce returned an invalid paginated product result.');
        }
        if (!array_is_list($result->products)) {
            throw new \UnexpectedValueException('WooCommerce returned a non-list product page.');
        }
        foreach ($result->products as $product) {
            if (!is_object($product) || !method_exists($product, 'get_id')) {
                throw new \UnexpectedValueException('WooCommerce returned an invalid product object.');
            }
        }
        $page = isset($args['page']) && is_int($args['page']) ? $args['page'] : 1;
        if ($result->products === array() && $page < $result->max_num_pages) {
            throw new \UnexpectedValueException('WooCommerce returned an inconsistent empty product page.');
        }
        return array(
            $result->products,
            max($result->products === array() ? 0 : 1, $result->max_num_pages),
        );
    }

    /**
     * @return array<string,array{raw:string,key_aliases:list<string>,value_aliases:array<string,string>}>
     */
    private function variationDefinitions(object $parent): array
    {
        $source = (array) $parent->get_variation_attributes();
        if (count($source) < 1 || count($source) > self::MAX_VARIATION_ATTRIBUTES) {
            return array();
        }

        $definitions = array();
        foreach ($source as $rawName => $values) {
            if (!is_array($values) || count($values) < 1 || count($values) > self::MAX_VARIATION_OPTIONS) {
                return array();
            }
            $raw = Text::plain((string) $rawName, 160);
            $canonical = $this->attributeKeyToken($raw);
            if ($canonical === '' || isset($definitions[$canonical])) {
                return array();
            }

            $keyAliases = array_values(array_filter(array_unique(array(
                $canonical,
                $this->attributeKeyToken((string) preg_replace('/^pa_/', '', $raw)),
                $this->attributeKeyToken((string) wc_attribute_label($raw)),
            ))));
            $valueAliases = array();
            foreach ($values as $value) {
                if (!is_scalar($value) && $value !== null) {
                    return array();
                }
                $rawValue = Text::plain((string) $value, 200);
                $canonicalValue = $this->canonicalAttributeValue($raw, $rawValue);
                if ($canonicalValue === '') {
                    continue;
                }
                foreach (array(
                    $rawValue,
                    $canonicalValue,
                    $this->attributeValueLabel($raw, $rawValue),
                ) as $alias) {
                    $token = $this->attributeValueToken((string) $alias);
                    if ($token === '') {
                        continue;
                    }
                    if (isset($valueAliases[$token]) && $valueAliases[$token] !== $canonicalValue) {
                        $valueAliases[$token] = '';
                    } else {
                        $valueAliases[$token] = $canonicalValue;
                    }
                }
            }
            if ($valueAliases === array()) {
                return array();
            }

            $definitions[$canonical] = array(
                'raw' => $raw,
                'key_aliases' => $keyAliases,
                'value_aliases' => $valueAliases,
            );
        }
        ksort($definitions, SORT_STRING);
        return $definitions;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,array{raw:string,key_aliases:list<string>,value_aliases:array<string,string>}> $definitions
     * @return array<string,string>|null
     */
    private function normalizeRequestedVariationAttributes(array $attributes, array $definitions): ?array
    {
        if (array_is_list($attributes) || count($attributes) !== count($definitions)) {
            return null;
        }
        $normalized = array();
        foreach ($attributes as $inputKey => $inputValue) {
            if (!is_string($inputKey) || !is_string($inputValue)) {
                return null;
            }
            $keyToken = $this->attributeKeyToken(Text::plain($inputKey, 160));
            $matches = array();
            foreach ($definitions as $canonical => $definition) {
                if (in_array($keyToken, $definition['key_aliases'], true)) {
                    $matches[] = $canonical;
                }
            }
            if (count($matches) !== 1) {
                return null;
            }

            $canonical = $matches[0];
            $valueToken = $this->attributeValueToken(Text::plain($inputValue, 200));
            $canonicalValue = $definitions[$canonical]['value_aliases'][$valueToken] ?? null;
            if (!is_string($canonicalValue) || $canonicalValue === '' || isset($normalized[$canonical])) {
                return null;
            }
            $normalized[$canonical] = $canonicalValue;
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,array{raw:string,key_aliases:list<string>,value_aliases:array<string,string>}> $definitions
     * @return array<string,string>
     */
    private function normalizeActualVariationAttributes(array $attributes, array $definitions): array
    {
        $normalized = array();
        foreach ($attributes as $rawKey => $rawValue) {
            if (!is_string($rawKey) || (!is_scalar($rawValue) && $rawValue !== null)) {
                continue;
            }
            $keyToken = $this->attributeKeyToken(Text::plain($rawKey, 160));
            foreach ($definitions as $canonical => $definition) {
                if (!in_array($keyToken, $definition['key_aliases'], true)) {
                    continue;
                }
                $normalized[$canonical] = $this->canonicalAttributeValue(
                    $definition['raw'],
                    Text::plain((string) $rawValue, 200)
                );
                break;
            }
        }
        ksort($normalized, SORT_STRING);
        return $normalized;
    }

    private function canonicalAttributeValue(string $attribute, string $value): string
    {
        if (taxonomy_exists($attribute)) {
            $term = get_term_by('slug', $value, $attribute);
            if (!$term instanceof \WP_Term) {
                $term = get_term_by('name', $value, $attribute);
            }
            if ($term instanceof \WP_Term) {
                return $this->attributeValueToken((string) $term->slug);
            }
        }
        return $this->attributeValueToken($value);
    }

    private function attributeKeyToken(string $value): string
    {
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $value = preg_replace('/^attribute[\s_-]+/u', '', trim($value)) ?? trim($value);
        return $this->normalizedToken($value);
    }

    private function attributeValueToken(string $value): string
    {
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        return $this->normalizedToken($value);
    }

    private function normalizedToken(string $value): string
    {
        $value = preg_replace('/[\s_]+/u', '-', trim($value)) ?? trim($value);
        $value = preg_replace('/[^\p{L}\p{N}-]+/u', '-', $value) ?? $value;
        $value = preg_replace('/-+/u', '-', $value) ?? $value;
        return trim($value, '-');
    }

    private function catalogPrice(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number)
            && $number >= 0
            && $number <= self::MAX_CATALOG_PRICE
                ? $number
                : null;
    }

    private function safeNumber(mixed $value, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        $number = (float) $value;
        return is_finite($number)
            && $number >= 0
            && $number <= self::MAX_CATALOG_PRICE
                ? $number
                : $fallback;
    }

    private function identityScalar(string $value): string
    {
        if (Text::length($value) > self::MAX_IDENTITY_TEXT_LENGTH) {
            throw new \LengthException('A product identity value exceeds the safe size limit.');
        }
        return hash('sha256', $value);
    }

    private function publicHttpUrl(string $url): string
    {
        if ($url === '' || Text::length($url) > self::MAX_URL_LENGTH) {
            return '';
        }
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return '';
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true)
            || trim((string) ($parts['host'] ?? '')) === '') {
            return '';
        }
        $sanitized = esc_url_raw($url, array('http', 'https'));
        return is_string($sanitized) && $sanitized !== '' ? $sanitized : '';
    }
}
