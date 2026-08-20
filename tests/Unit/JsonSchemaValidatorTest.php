<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Ai\JsonSchemaValidator;

function structured_decision_schema(): array
{
    return array(
        'type' => 'object',
        'properties' => array(
            'authorized' => array('type' => 'boolean'),
            'reason' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 300),
            'fingerprint' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
            'commands' => array(
                'type' => 'array',
                'minItems' => 1,
                'maxItems' => 3,
                'items' => array(
                    'type' => 'object',
                    'properties' => array(
                        'action' => array('type' => 'string', 'enum' => array('add', 'remove')),
                        'quantity' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 1000),
                    ),
                    'required' => array('action', 'quantity'),
                    'additionalProperties' => false,
                ),
            ),
        ),
        'required' => array('authorized', 'reason', 'fingerprint', 'commands'),
        'additionalProperties' => false,
    );
}

test('JsonSchemaValidator accepts the bounded nested decision shape', static function (): void {
    $validator = new JsonSchemaValidator();
    $value = array(
        'authorized' => true,
        'reason' => 'Explicit request.',
        'fingerprint' => str_repeat('a', 64),
        'commands' => array(array('action' => 'add', 'quantity' => 2)),
    );

    $validator->assertValid($value, structured_decision_schema());
    assert_true(true);
});

test('JsonSchemaValidator rejects missing, extra, and wrongly typed object properties', static function (): void {
    $validator = new JsonSchemaValidator();
    $base = array(
        'authorized' => true,
        'reason' => 'Explicit request.',
        'fingerprint' => str_repeat('a', 64),
        'commands' => array(array('action' => 'add', 'quantity' => 2)),
    );

    $missing = $base;
    unset($missing['authorized']);
    assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid($missing, structured_decision_schema()) ?? null));

    assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid(
        $base + array('unexpected' => true),
        structured_decision_schema()
    ) ?? null));

    $wrongType = $base;
    $wrongType['authorized'] = 1;
    assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid($wrongType, structured_decision_schema()) ?? null));
});

test('JsonSchemaValidator enforces patterns, Unicode lengths, enums, and numeric bounds', static function (): void {
    $validator = new JsonSchemaValidator();
    $schema = structured_decision_schema();
    $base = array(
        'authorized' => false,
        'reason' => 'واضح',
        'fingerprint' => str_repeat('b', 64),
        'commands' => array(array('action' => 'remove', 'quantity' => 1)),
    );
    $validator->assertValid($base, $schema);

    foreach (array(
        array_replace($base, array('fingerprint' => 'not-a-fingerprint')),
        array_replace($base, array('reason' => '')),
        array_replace($base, array('commands' => array(array('action' => 'clear', 'quantity' => 1)))),
        array_replace($base, array('commands' => array(array('action' => 'add', 'quantity' => 0)))),
        array_replace($base, array('commands' => array(array('action' => 'add', 'quantity' => 1.0)))),
    ) as $invalid) {
        assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid($invalid, $schema) ?? null));
    }
});

test('JsonSchemaValidator validates typed additional properties and empty JSON containers', static function (): void {
    $validator = new JsonSchemaValidator();
    $objectSchema = array(
        'type' => 'object',
        'additionalProperties' => array('type' => 'string', 'maxLength' => 20),
    );
    $validator->assertValid((object) array(), $objectSchema);
    $validator->assertValid(array('size' => 'large', 'color' => 'blue'), $objectSchema);
    assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid(
        array('size' => 42),
        $objectSchema
    ) ?? null));

    assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid(array(), $objectSchema) ?? null));

    $arraySchema = array('type' => 'array', 'items' => array('type' => 'boolean'), 'maxItems' => 2);
    $validator->assertValid(array(), $arraySchema);
    assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid((object) array(), $arraySchema) ?? null));
    $validator->assertValid(array(true, false), $arraySchema);
    assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid(array(true, 1), $arraySchema) ?? null));
});

test('JsonSchemaValidator preserves nested empty object and array identities', static function (): void {
    $validator = new JsonSchemaValidator();
    $schema = array(
        'type' => 'object',
        'properties' => array(
            'metadata' => array('type' => 'object', 'additionalProperties' => false),
            'items' => array('type' => 'array', 'maxItems' => 0),
        ),
        'required' => array('metadata', 'items'),
        'additionalProperties' => false,
    );

    $valid = json_decode('{"metadata":{},"items":[]}', false, 16, JSON_THROW_ON_ERROR);
    $validator->assertValid($valid, $schema);

    foreach (array(
        json_decode('{"metadata":[],"items":[]}', false, 16, JSON_THROW_ON_ERROR),
        json_decode('{"metadata":{},"items":{}}', false, 16, JSON_THROW_ON_ERROR),
    ) as $invalid) {
        assert_throws(UnexpectedValueException::class, static fn (): null => ($validator->assertValid($invalid, $schema) ?? null));
    }
});

test('JsonSchemaValidator rejects malformed local schemas before they become provider contracts', static function (): void {
    $validator = new JsonSchemaValidator();
    foreach (array(
        array('type' => 'mystery'),
        array('type' => 'object', 'required' => array('id', 'id')),
        array('type' => 'object', 'properties' => array(), 'required' => array('undeclared')),
        array('type' => 'string', 'pattern' => '['),
        array('type' => 'array', 'items' => 'not-a-schema'),
        array('type' => 'string', 'minLength' => 10, 'maxLength' => 2),
        array('type' => 'string', 'minimum' => 1),
        array('type' => 'object', 'unevaluatedProperties' => false),
        array('type' => 'string', 'enum' => array('same', 'same')),
        array('type' => 'boolean', 'enum' => array('true')),
    ) as $schema) {
        assert_throws(InvalidArgumentException::class, static fn (): null => ($validator->assertSchema($schema) ?? null));
    }
});
