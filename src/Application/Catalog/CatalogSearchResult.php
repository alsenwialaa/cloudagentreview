<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Catalog;

/**
 * Bounded catalog-search outcome.
 *
 * The result distinguishes a fully exhausted candidate source from a search
 * that stopped because the requested result count or the safety scan ceiling
 * was reached. Callers can disclose that limitation instead of implying that
 * a bounded scan covered the entire catalog.
 */
final readonly class CatalogSearchResult
{
    /**
     * @param list<object> $products
     */
    public function __construct(
        public array $products,
        public bool $resultsTruncated,
        public bool $scanExhausted,
        public int $scannedCandidates,
        public int $scanLimit
    ) {
        if (!array_is_list($products)) {
            throw new \InvalidArgumentException('Catalog search products must be a list.');
        }
        foreach ($products as $product) {
            if (!is_object($product)) {
                throw new \InvalidArgumentException('Catalog search products must contain objects.');
            }
        }
        if ($scannedCandidates < 0 || $scanLimit < 1 || $scannedCandidates > $scanLimit) {
            throw new \InvalidArgumentException('Catalog search scan counters are invalid.');
        }
        if ($resultsTruncated === $scanExhausted) {
            throw new \InvalidArgumentException('Catalog search truncation and exhaustion flags are inconsistent.');
        }
    }

    /** @return array{results_truncated:bool,scan_exhausted:bool,scanned_candidates:int,scan_limit:int} */
    public function metadata(): array
    {
        return array(
            'results_truncated' => $this->resultsTruncated,
            'scan_exhausted' => $this->scanExhausted,
            'scanned_candidates' => $this->scannedCandidates,
            'scan_limit' => $this->scanLimit,
        );
    }
}
