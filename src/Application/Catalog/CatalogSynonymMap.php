<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Catalog;

use YassinStore\AiAssistant\Application\Support\Text;

/**
 * Canonical, bounded merchant-managed catalog synonym groups.
 *
 * One non-comment line defines one group. Terms are separated with |, =,
 * comma, or Arabic comma. The strict mode is used for admin writes; tolerant
 * mode is used while reading previously stored settings so malformed legacy
 * values cannot break the storefront runtime.
 */
final class CatalogSynonymMap
{
    private const MAX_SOURCE_LENGTH = 12000;
    private const MAX_GROUPS = 50;
    private const MAX_TERMS_PER_GROUP = 12;
    private const MAX_TERM_LENGTH = 80;
    private const MAX_EXPANSIONS = 8;

    /** @var list<list<string>> */
    private array $groups = array();

    public function __construct(
        string $source,
        private readonly CatalogTextNormalizer $normalizer,
        bool $strict = false
    ) {
        $this->groups = $this->parse($source, $strict);
    }

    public static function sanitize(string $source): string
    {
        return (new self($source, new CatalogTextNormalizer(), false))->canonicalText();
    }

    /** @return list<list<string>> */
    public function groups(): array
    {
        return $this->groups;
    }

    public function canonicalText(): string
    {
        return implode("\n", array_map(
            static fn (array $group): string => implode(' | ', $group),
            $this->groups
        ));
    }

    /**
     * Return bounded query replacements for groups mentioned by the shopper.
     * The original surrounding words are preserved, so "red shoes" can expand
     * to "red sneakers" instead of losing the shopper's other constraints.
     *
     * @return list<string>
     */
    public function expansions(string $query): array
    {
        $query = $this->normalizer->normalize($query);
        if ($query === '') {
            return array();
        }

        $out = array();
        foreach ($this->groups as $group) {
            foreach ($group as $matched) {
                if (!$this->containsTerm($query, $matched)) {
                    continue;
                }
                foreach ($group as $replacement) {
                    if ($replacement === $matched) {
                        continue;
                    }
                    $variant = $this->replaceTerm($query, $matched, $replacement);
                    $variant = $this->normalizer->normalize($variant);
                    if ($variant === '' || $variant === $query || isset($out[$variant])) {
                        continue;
                    }
                    $out[$variant] = true;
                    if (count($out) >= self::MAX_EXPANSIONS) {
                        return array_keys($out);
                    }
                }
            }
        }
        return array_keys($out);
    }

    /** @return list<list<string>> */
    private function parse(string $source, bool $strict): array
    {
        $source = str_replace(array("\r\n", "\r"), "\n", $source);
        if (Text::length($source) > self::MAX_SOURCE_LENGTH) {
            if ($strict) {
                throw new \InvalidArgumentException('Catalog synonyms exceed the safe size limit.');
            }
            $source = Text::slice($source, 0, self::MAX_SOURCE_LENGTH);
        }

        $groups = array();
        $groupKeys = array();
        foreach (explode("\n", $source) as $rawLine) {
            $line = trim((string) $rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (count($groups) >= self::MAX_GROUPS) {
                if ($strict) {
                    throw new \InvalidArgumentException('Catalog synonyms contain too many groups.');
                }
                break;
            }

            $parts = preg_split('/\s*(?:\||=|,|،)\s*/u', $line) ?: array();
            if (count($parts) > self::MAX_TERMS_PER_GROUP && $strict) {
                throw new \InvalidArgumentException('A catalog synonym group contains too many terms.');
            }

            $terms = array();
            foreach (array_slice($parts, 0, self::MAX_TERMS_PER_GROUP) as $rawTerm) {
                $plain = Text::plain((string) $rawTerm, self::MAX_TERM_LENGTH + 1);
                if ($plain === '') {
                    continue;
                }
                if (Text::length($plain) > self::MAX_TERM_LENGTH) {
                    if ($strict) {
                        throw new \InvalidArgumentException('A catalog synonym term is too long.');
                    }
                    continue;
                }
                $term = $this->normalizer->normalize($plain);
                if ($term === '' || Text::length($term) > self::MAX_TERM_LENGTH) {
                    if ($strict) {
                        throw new \InvalidArgumentException('A catalog synonym term is invalid.');
                    }
                    continue;
                }
                $terms[$term] = true;
            }

            if (count($terms) < 2) {
                if ($strict) {
                    throw new \InvalidArgumentException('Each catalog synonym group needs at least two distinct terms.');
                }
                continue;
            }

            $group = array_keys($terms);
            $keyTerms = $group;
            sort($keyTerms, SORT_STRING);
            $groupKey = implode("\0", $keyTerms);
            if (isset($groupKeys[$groupKey])) {
                continue;
            }
            $groupKeys[$groupKey] = true;
            $groups[] = $group;
        }
        return $groups;
    }

    private function containsTerm(string $query, string $term): bool
    {
        return preg_match('/(?:^| )' . preg_quote($term, '/') . '(?= |$)/u', $query) === 1;
    }

    private function replaceTerm(string $query, string $term, string $replacement): string
    {
        $result = preg_replace(
            '/(^| )' . preg_quote($term, '/') . '(?= |$)/u',
            '$1' . str_replace(array('\\', '$'), array('\\\\', '\\$'), $replacement),
            $query,
            1
        );
        return is_string($result) ? $result : $query;
    }
}
