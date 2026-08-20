<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Support\StrictJson;

test('StrictJson preserves container identity and accepts unique decoded keys', static function (): void {
    $pair = StrictJson::decodePair(
        '{"plain":1,"escaped\\u005fkey":{"items":[true,false,null,"😀"]},"01":2,"1":3}',
        32,
        100
    );

    assert_true($pair['raw'] instanceof stdClass);
    assert_true($pair['raw']->escaped_key instanceof stdClass);
    assert_same(array(true, false, null, '😀'), $pair['associative']['escaped_key']['items']);
    assert_same(2, $pair['associative']['01']);
    assert_same(3, $pair['associative'][1]);

    $emptyObject = StrictJson::decodePair('{}');
    $emptyList = StrictJson::decodePair('[]');
    assert_true($emptyObject['raw'] instanceof stdClass);
    assert_same(array(), $emptyObject['associative']);
    assert_true(is_array($emptyList['raw']));
    assert_same(array(), $emptyList['associative']);
});

test('StrictJson rejects duplicate object names including escaped equivalents', static function (): void {
    foreach (array(
        '{"token":"first","token":"second"}',
        '{"name":1,"\\u006eame":2}',
        '{"outer":{"id":1,"\\u0069d":2}}',
        '[{"x":1,"x":2}]',
        '{"\\uD83D\\uDE00":1,"😀":2}',
    ) as $json) {
        assert_throws(JsonException::class, static fn (): array => StrictJson::decodePair($json));
    }
});

test('StrictJson rejects malformed and structurally excessive documents', static function (): void {
    foreach (array('01', '[1,]', '{"a":}', 'true false', '{"bad":"\\uD800"}') as $json) {
        assert_throws(JsonException::class, static fn (): array => StrictJson::decodePair($json));
    }

    assert_throws(
        JsonException::class,
        static fn (): array => StrictJson::decodePair('{"a":[1,2,3]}', 32, 3),
        'structural limit'
    );
});

test('StrictJson rejects numeric overflow instead of admitting non-finite PHP floats', static function (): void {
    foreach (array('1e400', '-1e400', '{"value":1e999999}') as $json) {
        assert_throws(JsonException::class, static fn (): mixed => StrictJson::decodeAssociative($json));
    }

    $decoded = StrictJson::decodeAssociative('{"value":1e308}');
    assert_true(is_float($decoded['value'] ?? null));
    assert_true(is_finite($decoded['value']));
});
