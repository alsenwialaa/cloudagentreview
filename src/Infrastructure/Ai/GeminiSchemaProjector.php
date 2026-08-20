<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Ai;

/**
 * Projects the plugin's stricter local JSON Schema subset onto the smaller,
 * portable subset accepted by Gemini's stable Interactions API.
 *
 * Local validation remains authoritative. Constraints omitted from the wire
 * schema (for example regular expressions and string lengths) are still
 * enforced against every structured result and function-call argument before
 * application code can trust or execute it.
 */
final class GeminiSchemaProjector
{
    /** @var list<string> */
    private const WIRE_KEYWORDS = array(
        'type',
        'title',
        'description',
        'format',
        'enum',
        'minimum',
        'maximum',
        'properties',
        'required',
        'additionalProperties',
        'items',
        'minItems',
        'maxItems',
    );

    /** @var list<string> */
    private const LOCAL_ONLY_KEYWORDS = array(
        'minLength',
        'maxLength',
        'pattern',
        'minProperties',
        'maxProperties',
    );

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>|\stdClass
     */
    public function project(array $schema): array|\stdClass
    {
        if ($schema === array()) {
            return new \stdClass();
        }
        if (array_is_list($schema)) {
            throw new \InvalidArgumentException('A provider schema must be a JSON object.');
        }

        $wire = array();
        foreach ($schema as $keyword => $value) {
            if (!is_string($keyword)) {
                throw new \InvalidArgumentException('A provider schema keyword is invalid.');
            }
            if (in_array($keyword, self::LOCAL_ONLY_KEYWORDS, true)) {
                continue;
            }
            if ($keyword === 'enum' && !$this->portableEnum($schema, $value)) {
                continue;
            }
            if ($keyword === 'format' && !$this->declaresType($schema, 'string')) {
                continue;
            }
            if (!in_array($keyword, self::WIRE_KEYWORDS, true)) {
                throw new \InvalidArgumentException('A provider schema contains an unsupported wire keyword.');
            }

            if ($keyword === 'properties') {
                if (!is_array($value) || ($value !== array() && array_is_list($value))) {
                    throw new \InvalidArgumentException('Provider schema properties must be an object map.');
                }
                $properties = new \stdClass();
                foreach ($value as $name => $childSchema) {
                    if (!is_string($name) || !is_array($childSchema)) {
                        throw new \InvalidArgumentException('A provider property schema is invalid.');
                    }
                    $properties->{$name} = $this->project($childSchema);
                }
                $wire[$keyword] = $properties;
                continue;
            }

            if ($keyword === 'items') {
                if (!is_array($value)) {
                    throw new \InvalidArgumentException('Provider array items must be a schema object.');
                }
                $wire[$keyword] = $this->project($value);
                continue;
            }

            if ($keyword === 'additionalProperties' && is_array($value)) {
                $wire[$keyword] = $this->project($value);
                continue;
            }

            $wire[$keyword] = $value;
        }

        return $wire;
    }

    /** @param array<string,mixed> $schema */
    private function portableEnum(array $schema, mixed $values): bool
    {
        if (!is_array($values) || !array_is_list($values) || $values === array()) {
            return false;
        }
        if ($this->declaresType($schema, 'string')) {
            return count(array_filter($values, 'is_string')) === count($values);
        }
        if ($this->declaresType($schema, 'integer')) {
            return count(array_filter($values, 'is_int')) === count($values);
        }
        if ($this->declaresType($schema, 'number')) {
            foreach ($values as $value) {
                if (!is_int($value) && (!is_float($value) || !is_finite($value))) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $schema */
    private function declaresType(array $schema, string $expected): bool
    {
        $type = $schema['type'] ?? null;
        return is_string($type)
            ? $type === $expected
            : is_array($type) && array_is_list($type) && in_array($expected, $type, true);
    }
}
