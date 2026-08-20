<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Catalog;

use YassinStore\AiAssistant\Application\Support\Text;

/** Creates a bounded set of recall-only live-catalog queries. */
final class CatalogRecallPlanner
{
    private const MAX_QUERIES = 8;

    public function __construct(private readonly CatalogTextNormalizer $normalizer)
    {
    }

    /**
     * @return list<array{query:string,normalized:string,weight:float,source:string}>
     */
    public function plan(string $query, string $synonymSource = ''): array
    {
        $query = Text::plain($query, 240);
        if ($query === '') {
            return array(array('query' => '', 'normalized' => '', 'weight' => 1.0, 'source' => 'browse'));
        }

        $planned = array();
        $this->append($planned, $query, 1.0, 'original');

        $normalized = $this->normalizer->normalize($query);
        if ($normalized !== '' && $normalized !== $query) {
            $this->append($planned, $normalized, 0.96, 'normalized');
        }

        $synonyms = new CatalogSynonymMap($synonymSource, $this->normalizer, false);
        foreach ($synonyms->expansions($query) as $expansion) {
            $this->append($planned, $expansion, 0.90, 'synonym');
        }

        foreach ($this->normalizer->transliterationVariants($query) as $variant) {
            $this->append($planned, $variant, 0.74, 'transliteration');
        }

        return array_slice(array_values($planned), 0, self::MAX_QUERIES);
    }

    /** @param array<string,array{query:string,normalized:string,weight:float,source:string}> $planned */
    private function append(array &$planned, string $query, float $weight, string $source): void
    {
        if (count($planned) >= self::MAX_QUERIES) {
            return;
        }
        $query = Text::plain($query, 240);
        $normalized = $this->normalizer->normalize($query);
        if ($query === '' || $normalized === '' || isset($planned[$normalized])) {
            return;
        }
        $planned[$normalized] = array(
            'query' => $query,
            'normalized' => $normalized,
            'weight' => max(0.0, min(1.0, $weight)),
            'source' => Text::plain($source, 40),
        );
    }
}
