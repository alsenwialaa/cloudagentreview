<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Catalog;

use YassinStore\AiAssistant\Application\Support\Text;

/** Deterministic, fact-only ranking for the model-visible best_match tool. */
final class CatalogBestMatchRanker
{
    public function __construct(private readonly CatalogTextNormalizer $normalizer = new CatalogTextNormalizer())
    {
    }

    /**
     * @param list<array<string,mixed>> $cards
     * @param array<string,mixed> $memory
     * @return array{cards:list<array<string,mixed>>,ranking:list<array<string,mixed>>}
     */
    public function rank(array $cards, string $shopperRequest, array $memory = array()): array
    {
        $request = $this->normalizer->normalize(Text::plain($shopperRequest, 1000));
        $requestTokens = $this->normalizer->tokens($request);
        $memoryTokens = $this->memoryTokens($memory);
        $budgetMin = $this->boundedNumber($memory['budget_min'] ?? null);
        $budgetMax = $this->boundedNumber($memory['budget_max'] ?? null);
        $preferredCategories = $this->normalizedStrings($memory['categories'] ?? array(), 12);
        $preferredAttributes = $this->normalizedAttributeValues($memory['attributes'] ?? array());

        $rows = array();
        foreach (array_values($cards) as $index => $card) {
            if (!is_array($card)) {
                continue;
            }
            $name = $this->normalizer->normalize(is_string($card['name'] ?? null) ? $card['name'] : '');
            $haystack = $this->cardText($card);
            $score = 0.0;
            $reasons = array();

            if ($request !== '' && $this->containsPhrase($haystack, $request)) {
                $score += 48.0;
                $reasons[] = 'exact_request_phrase';
            }
            $matchedRequestTokens = 0;
            foreach ($requestTokens as $token) {
                if ($this->containsPhrase($name, $token)) {
                    $score += Text::length($token) >= 5 ? 13.0 : 9.0;
                    ++$matchedRequestTokens;
                } elseif ($this->containsPhrase($haystack, $token)) {
                    $score += Text::length($token) >= 5 ? 8.0 : 5.0;
                    ++$matchedRequestTokens;
                }
            }
            if ($matchedRequestTokens > 0) {
                $reasons[] = 'request_terms:' . $matchedRequestTokens;
            }

            $matchedMemoryTokens = 0;
            foreach ($memoryTokens as $token) {
                if ($this->containsPhrase($haystack, $token)) {
                    $score += 2.5;
                    ++$matchedMemoryTokens;
                }
            }
            if ($matchedMemoryTokens > 0) {
                $reasons[] = 'remembered_preferences:' . $matchedMemoryTokens;
            }

            $categoryText = $this->normalizer->normalize($this->encode($card['categories'] ?? array()));
            $categoryMatches = 0;
            foreach ($preferredCategories as $category) {
                if ($this->containsPhrase($categoryText, $category)) {
                    $score += 10.0;
                    ++$categoryMatches;
                }
            }
            if ($categoryMatches > 0) {
                $reasons[] = 'preferred_categories:' . $categoryMatches;
            }

            $attributeText = $this->normalizer->normalize($this->encode($card['attributes'] ?? array()));
            $attributeMatches = 0;
            foreach ($preferredAttributes as $value) {
                if ($this->containsPhrase($attributeText, $value)) {
                    $score += 8.0;
                    ++$attributeMatches;
                }
            }
            if ($attributeMatches > 0) {
                $reasons[] = 'preferred_attributes:' . $attributeMatches;
            }

            $price = $this->cardPrice($card);
            if ($price !== null) {
                if ($budgetMax !== null) {
                    if ($price <= $budgetMax) {
                        $score += 8.0;
                        $reasons[] = 'within_budget_max';
                    } else {
                        $score -= 35.0 + min(25.0, (($price - $budgetMax) / max(1.0, $budgetMax)) * 25.0);
                        $reasons[] = 'over_budget_max';
                    }
                }
                if ($budgetMin !== null && $price >= $budgetMin) {
                    $score += 2.0;
                    $reasons[] = 'within_budget_min';
                }
            }

            if (($card['in_stock'] ?? false) === true) {
                $score += 6.0;
                $reasons[] = 'in_stock';
            } else {
                $score -= 12.0;
                $reasons[] = 'out_of_stock';
            }
            if (($card['purchasable'] ?? false) === true) {
                $score += 2.0;
            }

            $rating = $this->boundedNumber($card['rating'] ?? null, 0.0, 5.0) ?? 0.0;
            if ($rating > 0.0) {
                $score += $rating * 1.4;
                $reasons[] = 'rating';
            }
            $reviews = is_int($card['review_count'] ?? null)
                ? max(0, min(2_000_000_000, (int) $card['review_count']))
                : 0;
            if ($reviews > 0) {
                $score += min(4.0, log10($reviews + 1.0));
            }

            $regularPrice = $this->boundedNumber($card['regular_price'] ?? null);
            if ($price !== null && $regularPrice !== null && $regularPrice > $price) {
                $score += 2.0;
                $reasons[] = 'on_sale';
            }

            $score = round($score, 4);
            $card['match_score'] = $score;
            $card['match_reasons'] = array_slice(array_values(array_unique($reasons)), 0, 10);
            $rows[] = array(
                'card' => $card,
                'score' => $score,
                'ref' => is_string($card['ref'] ?? null) ? $card['ref'] : '',
                // Opaque references are intentionally random. They may be
                // returned to the model, but they must never influence the
                // ordering of otherwise tied catalog facts.
                'stable_key' => $this->stableCardKey($card),
                'index' => $index,
            );
        }

        usort($rows, static function (array $left, array $right): int {
            $scoreOrder = $right['score'] <=> $left['score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }
            $factOrder = strcmp($left['stable_key'], $right['stable_key']);
            return $factOrder !== 0 ? $factOrder : ($left['index'] <=> $right['index']);
        });

        $rankedCards = array();
        $ranking = array();
        foreach ($rows as $position => $row) {
            $rankedCards[] = $row['card'];
            $ranking[] = array(
                'position' => $position + 1,
                'product_ref' => $row['ref'],
                'score' => $row['score'],
                'reasons' => $row['card']['match_reasons'] ?? array(),
            );
        }
        return array('cards' => $rankedCards, 'ranking' => $ranking);
    }

    /** @param array<string,mixed> $card */
    private function cardText(array $card): string
    {
        return $this->normalizer->normalize($this->encode(array(
            $card['name'] ?? '',
            $card['sku'] ?? '',
            $card['short_description'] ?? '',
            $card['type'] ?? '',
            $card['categories'] ?? array(),
            $card['attributes'] ?? array(),
            $card['variation_options'] ?? array(),
        )));
    }

    /** @param array<string,mixed> $card */
    private function stableCardKey(array $card): string
    {
        $facts = array(
            'name' => $this->normalizer->normalize(is_string($card['name'] ?? null) ? $card['name'] : ''),
            'sku' => $this->normalizer->normalize(is_string($card['sku'] ?? null) ? $card['sku'] : ''),
            'type' => is_string($card['type'] ?? null) ? $card['type'] : '',
            'url' => is_string($card['url'] ?? null) ? $card['url'] : '',
            'price' => $this->cardPrice($card),
            'regular_price' => $this->boundedNumber($card['regular_price'] ?? null),
            'in_stock' => ($card['in_stock'] ?? false) === true,
            'purchasable' => ($card['purchasable'] ?? false) === true,
            'rating' => $this->boundedNumber($card['rating'] ?? null, 0.0, 5.0),
            'review_count' => is_int($card['review_count'] ?? null)
                ? max(0, min(2_000_000_000, (int) $card['review_count']))
                : 0,
            'categories' => $this->normalizer->normalize($this->encode($card['categories'] ?? array())),
            'attributes' => $this->normalizer->normalize($this->encode($card['attributes'] ?? array())),
        );
        try {
            return hash('sha256', json_encode(
                $facts,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        } catch (\JsonException) {
            // All inputs above are reduced to bounded scalar values. This is a
            // final deterministic fallback, not a model-visible error path.
            return hash('sha256', serialize($facts));
        }
    }

    /** @param array<string,mixed> $memory @return list<string> */
    private function memoryTokens(array $memory): array
    {
        $parts = array();
        if (is_string($memory['notes'] ?? null)) {
            $parts[] = $memory['notes'];
        }
        $parts[] = $memory['categories'] ?? array();
        $parts[] = $memory['attributes'] ?? array();
        return array_slice($this->normalizer->tokens($this->encode($parts)), 0, 24);
    }

    /** @return list<string> */
    private function normalizedStrings(mixed $value, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return array();
        }
        $out = array();
        foreach (array_slice($value, 0, $limit) as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = $this->normalizer->normalize($item);
            if ($item !== '') {
                $out[$item] = true;
            }
        }
        return array_keys($out);
    }

    /** @return list<string> */
    private function normalizedAttributeValues(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return array();
        }
        $out = array();
        foreach (array_slice($value, 0, 24, true) as $name => $attribute) {
            if (!is_string($name) || !is_string($attribute)) {
                continue;
            }
            foreach (array($name, $attribute) as $part) {
                $part = $this->normalizer->normalize($part);
                if ($part !== '') {
                    $out[$part] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** @param array<string,mixed> $card */
    private function cardPrice(array $card): ?float
    {
        if (array_key_exists('price_available', $card) && ($card['price_available'] ?? false) !== true) {
            return null;
        }
        return $this->boundedNumber($card['price'] ?? null);
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        if ($text === '' || $phrase === '') {
            return false;
        }
        return preg_match('/(?:^| )' . preg_quote($phrase, '/') . '(?= |$)/u', $text) === 1;
    }

    private function boundedNumber(mixed $value, float $min = 0.0, float $max = 1_000_000_000_000.0): ?float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
            return null;
        }
        $number = (float) $value;
        return $number >= $min && $number <= $max ? $number : null;
    }

    private function encode(mixed $value): string
    {
        try {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            return is_string($encoded) ? $encoded : '';
        } catch (\JsonException) {
            return '';
        }
    }
}
