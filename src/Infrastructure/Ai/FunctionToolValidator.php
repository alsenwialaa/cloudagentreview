<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Ai;

/**
 * Validates and canonicalizes the exact custom-function subset sent to Gemini.
 *
 * PHP represents both an empty JSON object and an empty JSON array as `[]` in
 * associative data. Tool schemas are therefore kept as logical PHP arrays and
 * converted to explicit stdClass objects only at the provider wire boundary.
 */
final class FunctionToolValidator
{
    private const MAX_TOOLS = 64;
    private const MAX_DESCRIPTION_BYTES = 4_000;

    private readonly GeminiSchemaProjector $schemaProjector;

    public function __construct(
        private readonly JsonSchemaValidator $schemaValidator = new JsonSchemaValidator(),
        ?GeminiSchemaProjector $schemaProjector = null
    ) {
        $this->schemaProjector = $schemaProjector ?? new GeminiSchemaProjector();
    }

    /**
     * @param list<array<string,mixed>> $tools
     * @return array{
     *   tools:list<array<string,mixed>>,
     *   argument_schemas:array<string,array<string,mixed>>
     * }
     */
    public function prepare(array $tools): array
    {
        if (!array_is_list($tools) || count($tools) > self::MAX_TOOLS) {
            throw new \InvalidArgumentException('Function tools must be a bounded JSON array.');
        }

        $wireTools = array();
        $argumentSchemas = array();
        foreach ($tools as $tool) {
            if (!is_array($tool) || $tool === array() || array_is_list($tool)) {
                throw new \InvalidArgumentException('Each function tool must be a JSON object.');
            }
            foreach (array_keys($tool) as $key) {
                if (!is_string($key) || !in_array($key, array('type', 'name', 'description', 'parameters'), true)) {
                    throw new \InvalidArgumentException('A function tool contains an unsupported field.');
                }
            }

            $name = $tool['name'] ?? null;
            $description = $tool['description'] ?? null;
            if (($tool['type'] ?? null) !== 'function'
                || !is_string($name)
                || preg_match('/^[a-z][a-z0-9_]{1,99}$/D', $name) !== 1
                || isset($argumentSchemas[$name])
                || !is_string($description)
                || trim($description) === ''
                || strlen($description) > self::MAX_DESCRIPTION_BYTES
                || preg_match('//u', $description) !== 1
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description) === 1) {
                throw new \InvalidArgumentException('A function tool declaration is invalid or duplicated.');
            }

            $parameters = $tool['parameters'] ?? array(
                'type' => 'object',
                'properties' => array(),
                'additionalProperties' => false,
            );
            if (!is_array($parameters)
                || $parameters === array()
                || array_is_list($parameters)) {
                throw new \InvalidArgumentException('Function parameters must be a JSON object schema.');
            }

            $this->schemaValidator->assertSchema($parameters);
            if (($parameters['type'] ?? null) !== 'object'
                || !array_key_exists('properties', $parameters)
                || !is_array($parameters['properties'])
                || ($parameters['properties'] !== array() && array_is_list($parameters['properties']))
                || ($parameters['additionalProperties'] ?? null) !== false) {
                throw new \InvalidArgumentException('Function parameters must be a closed object schema.');
            }

            $argumentSchemas[$name] = $parameters;
            $wireTools[] = array(
                'type' => 'function',
                'name' => $name,
                'description' => $description,
                'parameters' => $this->schemaProjector->project($parameters),
            );
        }

        return array(
            'tools' => $wireTools,
            'argument_schemas' => $argumentSchemas,
        );
    }

}
