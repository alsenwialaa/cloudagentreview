<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Ai\GeminiSchemaProjector;
use YassinStore\AiAssistant\Infrastructure\Ai\JsonSchemaValidator;

test('Gemini schema projector emits only the portable wire subset and preserves object maps', static function (): void {
    $projector = new GeminiSchemaProjector();
    $wire = $projector->project(array(
        'type' => 'object',
        'description' => 'Portable provider schema.',
        'minProperties' => 1,
        'maxProperties' => 2,
        'properties' => array(
            'product_ref' => array(
                'type' => 'string',
                'description' => 'Opaque product reference.',
                'minLength' => 10,
                'maxLength' => 82,
                'pattern' => '^p_[A-Za-z0-9_-]{8,80}$',
            ),
            'enabled' => array(
                'type' => 'boolean',
                'enum' => array(true),
                'format' => 'unsupported-for-boolean',
            ),
            'items' => array(
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 2,
                'items' => array(
                    'type' => 'object',
                    'properties' => array(),
                    'additionalProperties' => false,
                    'maxProperties' => 0,
                ),
            ),
        ),
        'required' => array('product_ref'),
        'additionalProperties' => false,
    ));

    assert_true(is_array($wire));
    assert_true(($wire['properties'] ?? null) instanceof stdClass);
    $properties = $wire['properties'];
    $productRef = $properties->product_ref ?? null;
    assert_true(is_array($productRef));
    assert_same('Opaque product reference.', $productRef['description']);
    foreach (array('minLength', 'maxLength', 'pattern') as $keyword) {
        assert_false(array_key_exists($keyword, $productRef));
    }
    foreach (array('minProperties', 'maxProperties') as $keyword) {
        assert_false(array_key_exists($keyword, $wire));
    }

    $enabled = $properties->enabled ?? null;
    assert_true(is_array($enabled));
    assert_false(array_key_exists('enum', $enabled));
    assert_false(array_key_exists('format', $enabled));

    $items = $properties->items ?? null;
    assert_true(is_array($items));
    assert_same(1, $items['minItems']);
    assert_same(2, $items['maxItems']);
    assert_true(is_array($items['items']));
    assert_true(($items['items']['properties'] ?? null) instanceof stdClass);
    assert_same(array(), get_object_vars($items['items']['properties']));
    assert_false(array_key_exists('maxProperties', $items['items']));

    $encoded = json_encode($wire, JSON_THROW_ON_ERROR);
    foreach (array('minLength', 'maxLength', 'pattern', 'minProperties', 'maxProperties') as $keyword) {
        assert_false(str_contains($encoded, '"' . $keyword . '"'));
    }
});

test('Gemini schema projection does not weaken strict local validation', static function (): void {
    $schema = array(
        'type' => 'object',
        'properties' => array(
            'product_ref' => array(
                'type' => 'string',
                'minLength' => 10,
                'maxLength' => 82,
                'pattern' => '^p_[A-Za-z0-9_-]{8,80}$',
            ),
        ),
        'required' => array('product_ref'),
        'additionalProperties' => false,
    );

    $projector = new GeminiSchemaProjector();
    $wire = $projector->project($schema);
    assert_true(is_array($wire));
    assert_true(($wire['properties'] ?? null) instanceof stdClass);
    assert_false(array_key_exists('pattern', $wire['properties']->product_ref));

    $validator = new JsonSchemaValidator();
    $validator->assertValid((object) array('product_ref' => 'p_12345678'), $schema);
    assert_throws(
        UnexpectedValueException::class,
        static function () use ($validator, $schema): void {
            $validator->assertValid((object) array('product_ref' => 'not-a-reference'), $schema);
        }
    );
    assert_throws(
        UnexpectedValueException::class,
        static function () use ($validator, $schema): void {
            $validator->assertValid((object) array('product_ref' => 'p_short'), $schema);
        }
    );
});

test('Gemini schema projector rejects array-shaped schema maps', static function (): void {
    $projector = new GeminiSchemaProjector();
    assert_throws(
        InvalidArgumentException::class,
        static fn (): array|stdClass => $projector->project(array(array('type' => 'string')))
    );
});
