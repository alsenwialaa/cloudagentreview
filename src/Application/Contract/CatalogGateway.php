<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

use YassinStore\AiAssistant\Application\Catalog\CatalogSearchResult;

interface CatalogGateway
{
    public function search(string $query, int $limit, array $filters = array()): CatalogSearchResult;

    /** @param list<object> $products @return list<object> */
    public function sortProducts(array $products, string $sort): array;

    public function product(int $id): ?object;

    public function bySku(string $sku): ?object;

    /** @return list<object> */
    public function related(int $id, int $limit): array;

    /** @return list<object> */
    public function alternatives(int $id, int $limit): array;

    /** @return list<array<string,mixed>> */
    public function categories(int $limit): array;

    public function resolveVariation(int $parentId, array $attributes): ?object;

    /** @return array{id:int,parent_id:int,type:string,fingerprint:string} */
    public function identity(object $product): array;

    /** @return array<string,mixed> */
    public function project(object $product): array;
}
