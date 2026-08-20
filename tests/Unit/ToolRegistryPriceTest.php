<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Tool\ToolRegistry;

test('ToolRegistry places unavailable prices after known prices in both directions', static function (): void {
    $method = new ReflectionMethod(ToolRegistry::class, 'comparePrice');
    $knownLow = array('price' => 5.0, 'price_available' => true);
    $knownHigh = array('price' => 20.0, 'price_available' => true);
    $unknown = array('price' => null, 'price_available' => false);

    assert_true($method->invoke(null, $knownLow, $unknown, false) < 0);
    assert_true($method->invoke(null, $knownHigh, $unknown, true) < 0);
    assert_true($method->invoke(null, $unknown, $knownLow, false) > 0);
    assert_true($method->invoke(null, $unknown, $knownHigh, true) > 0);
});

test('ToolRegistry excludes unavailable prices from value ranking while preserving free products', static function (): void {
    $method = new ReflectionMethod(ToolRegistry::class, 'valueScore');

    assert_same(-INF, $method->invoke(null, array(
        'price' => null,
        'price_available' => false,
        'rating' => 5.0,
    )));
    assert_same(PHP_FLOAT_MAX, $method->invoke(null, array(
        'price' => 0.0,
        'price_available' => true,
        'rating' => 4.0,
    )));
    assert_same(0.2, $method->invoke(null, array(
        'price' => 20.0,
        'price_available' => true,
        'rating' => 4.0,
    )));
});
