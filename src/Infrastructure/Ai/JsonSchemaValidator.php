<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Ai;

/**
 * Small, fail-closed validator for the JSON Schema subset used by Gemini
 * structured responses. Provider-side schema enforcement is not treated as a
 * trust boundary; every decoded value is validated again locally.
 */
final class JsonSchemaValidator
{
    private const MAX_SCHEMA_DEPTH = 24;
    private const MAX_VALUE_DEPTH = 48;
    private const MAX_SCHEMA_NODES = 2048;
    private const MAX_VALUE_NODES = 20000;
    private const MAX_PROPERTIES = 256;
    private const MAX_ENUM_ITEMS = 256;
    private const MAX_PATTERN_BYTES = 512;

    /** @var list<string> */
    private const TYPES = array('object', 'array', 'string', 'integer', 'number', 'boolean', 'null');

    /** @var list<string> */
    private const KEYWORDS = array(
        'type',
        'title',
        'description',
        'format',
        'enum',
        'minimum',
        'maximum',
        'minLength',
        'maxLength',
        'pattern',
        'properties',
        'required',
        'additionalProperties',
        'minProperties',
        'maxProperties',
        'items',
        'minItems',
        'maxItems',
    );

    /**
     * @param array<string,mixed> $schema
     * @throws \UnexpectedValueException when the value does not satisfy the schema
     * @throws \InvalidArgumentException when the local schema is malformed
     */
    public function assertSchema(array $schema): void
    {
        $schemaNodes = 0;
        $this->validateSchema($schema, 0, $schemaNodes);
    }

    public function assertValid(mixed $value, array $schema): void
    {
        $this->assertSchema($schema);

        $valueNodes = 0;
        $this->validateValue($value, $schema, '$', 0, $valueNodes);
    }

    /** @param array<string,mixed> $schema */
    private function validateSchema(array $schema, int $depth, int &$nodes): void
    {
        if ($depth > self::MAX_SCHEMA_DEPTH || ++$nodes > self::MAX_SCHEMA_NODES) {
            throw new \InvalidArgumentException('The structured-output schema exceeds safe complexity limits.');
        }
        if ($schema !== array() && array_is_list($schema)) {
            throw new \InvalidArgumentException('A structured-output schema must be a JSON object.');
        }
        foreach (array_keys($schema) as $keyword) {
            if (!is_string($keyword) || !in_array($keyword, self::KEYWORDS, true)) {
                throw new \InvalidArgumentException('A structured-output schema contains an unsupported keyword.');
            }
        }

        $types = array();
        if (array_key_exists('type', $schema)) {
            $types = $this->schemaTypes($schema['type']);
            if ($types === array()) {
                throw new \InvalidArgumentException('A structured-output schema type is invalid.');
            }
        }

        if (array_key_exists('enum', $schema)) {
            if (!is_array($schema['enum'])
                || !array_is_list($schema['enum'])
                || $schema['enum'] === array()
                || count($schema['enum']) > self::MAX_ENUM_ITEMS) {
                throw new \InvalidArgumentException('A structured-output enum is invalid.');
            }
            $seenEnum = array();
            foreach ($schema['enum'] as $enumValue) {
                if ((!is_scalar($enumValue) && $enumValue !== null)
                    || (is_float($enumValue) && !is_finite($enumValue))) {
                    throw new \InvalidArgumentException('A structured-output enum value is invalid.');
                }
                $identity = get_debug_type($enumValue) . ':' . serialize($enumValue);
                if (isset($seenEnum[$identity])) {
                    throw new \InvalidArgumentException('A structured-output enum contains duplicate values.');
                }
                $seenEnum[$identity] = true;
                if ($types !== array()) {
                    $matches = false;
                    foreach ($types as $type) {
                        if ($this->matchesType($enumValue, $type)) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        throw new \InvalidArgumentException('A structured-output enum value conflicts with its declared type.');
                    }
                }
            }
        }

        $this->assertKeywordTypes($schema, $types);

        foreach (array('title' => 256, 'description' => 4000, 'format' => 80) as $keyword => $maximumBytes) {
            if (array_key_exists($keyword, $schema)
                && (!is_string($schema[$keyword]) || strlen($schema[$keyword]) > $maximumBytes)) {
                throw new \InvalidArgumentException('A structured-output descriptive constraint is invalid.');
            }
        }

        foreach (array('minimum', 'maximum') as $keyword) {
            if (array_key_exists($keyword, $schema) && !$this->finiteNumber($schema[$keyword])) {
                throw new \InvalidArgumentException('A structured-output numeric constraint is invalid.');
            }
        }
        if (isset($schema['minimum'], $schema['maximum'])
            && (float) $schema['minimum'] > (float) $schema['maximum']) {
            throw new \InvalidArgumentException('A structured-output numeric range is invalid.');
        }

        foreach (array('minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties') as $keyword) {
            if (array_key_exists($keyword, $schema)
                && (!is_int($schema[$keyword]) || $schema[$keyword] < 0)) {
                throw new \InvalidArgumentException('A structured-output size constraint is invalid.');
            }
        }
        foreach (array(
            array('minLength', 'maxLength'),
            array('minItems', 'maxItems'),
            array('minProperties', 'maxProperties'),
        ) as [$minimum, $maximum]) {
            if (isset($schema[$minimum], $schema[$maximum]) && $schema[$minimum] > $schema[$maximum]) {
                throw new \InvalidArgumentException('A structured-output size range is invalid.');
            }
        }

        if (array_key_exists('pattern', $schema)) {
            if (!is_string($schema['pattern'])
                || strlen($schema['pattern']) > self::MAX_PATTERN_BYTES
                || !$this->validPattern($schema['pattern'])) {
                throw new \InvalidArgumentException('A structured-output pattern is invalid.');
            }
        }

        if (array_key_exists('properties', $schema)) {
            $properties = $schema['properties'];
            if (!is_array($properties)
                || ($properties !== array() && array_is_list($properties))
                || count($properties) > self::MAX_PROPERTIES) {
                throw new \InvalidArgumentException('Structured-output object properties are invalid.');
            }
            foreach ($properties as $name => $childSchema) {
                if (!is_string($name)
                    || $name === ''
                    || strlen($name) > 128
                    || !is_array($childSchema)) {
                    throw new \InvalidArgumentException('A structured-output property schema is invalid.');
                }
                $this->validateSchema($childSchema, $depth + 1, $nodes);
            }
        }

        if (array_key_exists('required', $schema)) {
            $required = $schema['required'];
            if (!is_array($required)
                || !array_is_list($required)
                || count($required) > self::MAX_PROPERTIES) {
                throw new \InvalidArgumentException('Structured-output required properties are invalid.');
            }
            $seen = array();
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : array();
            foreach ($required as $name) {
                if (!is_string($name)
                    || $name === ''
                    || strlen($name) > 128
                    || isset($seen[$name])
                    || !array_key_exists($name, $properties)) {
                    throw new \InvalidArgumentException('A structured-output required property is invalid.');
                }
                $seen[$name] = true;
            }
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $additional = $schema['additionalProperties'];
            if (!is_bool($additional) && !is_array($additional)) {
                throw new \InvalidArgumentException('Structured-output additionalProperties is invalid.');
            }
            if (is_array($additional)) {
                $this->validateSchema($additional, $depth + 1, $nodes);
            }
        }

        if (array_key_exists('items', $schema)) {
            if (!is_array($schema['items'])) {
                throw new \InvalidArgumentException('Structured-output array items are invalid.');
            }
            $this->validateSchema($schema['items'], $depth + 1, $nodes);
        }
    }

    /** @param array<string,mixed> $schema */
    private function validateValue(
        mixed $value,
        array $schema,
        string $path,
        int $depth,
        int &$nodes
    ): void {
        if ($depth > self::MAX_VALUE_DEPTH || ++$nodes > self::MAX_VALUE_NODES) {
            throw new \UnexpectedValueException('Structured output exceeds safe complexity limits.');
        }

        if (array_key_exists('enum', $schema) && !in_array($value, $schema['enum'], true)) {
            throw new \UnexpectedValueException("Structured output at {$path} is outside the allowed enum.");
        }

        if (array_key_exists('type', $schema)) {
            $types = $this->schemaTypes($schema['type']);
            $matched = false;
            foreach ($types as $type) {
                if ($this->matchesType($value, $type)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \UnexpectedValueException("Structured output at {$path} has the wrong type.");
            }
        }

        if (is_string($value)) {
            $length = $this->characterLength($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                throw new \UnexpectedValueException("Structured output at {$path} is too short.");
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                throw new \UnexpectedValueException("Structured output at {$path} is too long.");
            }
            if (isset($schema['pattern']) && !$this->matchesPattern($schema['pattern'], $value)) {
                throw new \UnexpectedValueException("Structured output at {$path} does not match the required pattern.");
            }
        }

        if ($this->finiteNumber($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                throw new \UnexpectedValueException("Structured output at {$path} is below the allowed minimum.");
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                throw new \UnexpectedValueException("Structured output at {$path} exceeds the allowed maximum.");
            }
        }

        $objectProperties = $this->jsonObjectProperties($value);
        if ($objectProperties !== null) {
            $count = count($objectProperties);
            if (isset($schema['minProperties']) && $count < $schema['minProperties']) {
                throw new \UnexpectedValueException("Structured output at {$path} has too few properties.");
            }
            if (isset($schema['maxProperties']) && $count > $schema['maxProperties']) {
                throw new \UnexpectedValueException("Structured output at {$path} has too many properties.");
            }

            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : array();
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : array();
            foreach ($required as $name) {
                if (!array_key_exists($name, $objectProperties)) {
                    throw new \UnexpectedValueException("Structured output at {$path} is missing a required property.");
                }
            }

            $additional = $schema['additionalProperties'] ?? true;
            foreach ($objectProperties as $name => $childValue) {
                $propertyName = (string) $name;
                if (array_key_exists($propertyName, $properties)) {
                    $this->validateValue(
                        $childValue,
                        $properties[$propertyName],
                        $this->childPath($path, $propertyName),
                        $depth + 1,
                        $nodes
                    );
                    continue;
                }
                if ($additional === false) {
                    throw new \UnexpectedValueException("Structured output at {$path} contains an unexpected property.");
                }
                if (is_array($additional)) {
                    $this->validateValue(
                        $childValue,
                        $additional,
                        $this->childPath($path, $propertyName),
                        $depth + 1,
                        $nodes
                    );
                }
            }
        }

        if ($this->isJsonArray($value)) {
            $count = count($value);
            if (isset($schema['minItems']) && $count < $schema['minItems']) {
                throw new \UnexpectedValueException("Structured output at {$path} has too few items.");
            }
            if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
                throw new \UnexpectedValueException("Structured output at {$path} has too many items.");
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $childValue) {
                    $this->validateValue(
                        $childValue,
                        $schema['items'],
                        $path . '[' . $index . ']',
                        $depth + 1,
                        $nodes
                    );
                }
            }
        }
    }

    /** @param array<string,mixed> $schema @param list<string> $types */
    private function assertKeywordTypes(array $schema, array $types): void
    {
        $groups = array(
            'string' => array('minLength', 'maxLength', 'pattern'),
            'number' => array('minimum', 'maximum'),
            'object' => array('properties', 'required', 'additionalProperties', 'minProperties', 'maxProperties'),
            'array' => array('items', 'minItems', 'maxItems'),
        );
        foreach ($groups as $expectedType => $keywords) {
            $present = false;
            foreach ($keywords as $keyword) {
                if (array_key_exists($keyword, $schema)) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                continue;
            }
            $compatible = $expectedType === 'number'
                ? array_intersect($types, array('integer', 'number')) !== array()
                : in_array($expectedType, $types, true);
            if (!$compatible) {
                throw new \InvalidArgumentException('A structured-output keyword is incompatible with its declared type.');
            }
        }
    }

    /** @return list<string> */
    private function schemaTypes(mixed $type): array
    {
        $types = is_string($type) ? array($type) : $type;
        if (!is_array($types) || !array_is_list($types) || $types === array()) {
            return array();
        }
        $validated = array();
        foreach ($types as $candidate) {
            if (!is_string($candidate)
                || !in_array($candidate, self::TYPES, true)
                || in_array($candidate, $validated, true)) {
                return array();
            }
            $validated[] = $candidate;
        }
        return $validated;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => $this->isJsonObject($value),
            'array' => $this->isJsonArray($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => $this->finiteNumber($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private function isJsonObject(mixed $value): bool
    {
        return $this->jsonObjectProperties($value) !== null;
    }

    /** @return array<string,mixed>|null */
    private function jsonObjectProperties(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }
        return is_array($value) && $value !== array() && !array_is_list($value)
            ? $value
            : null;
    }

    private function isJsonArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private function finiteNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value, $unused);
        return is_int($count) && $count >= 0 ? $count : strlen($value);
    }

    private function validPattern(string $pattern): bool
    {
        return @preg_match($this->regex($pattern), '') !== false;
    }

    private function matchesPattern(string $pattern, string $value): bool
    {
        return @preg_match($this->regex($pattern), $value) === 1;
    }

    private function regex(string $pattern): string
    {
        return '~' . str_replace('~', '\\~', $pattern) . '~u';
    }

    private function childPath(string $path, string $property): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $property) === 1
            ? $path . '.' . $property
            : $path . '[' . json_encode($property, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ']';
    }
}
