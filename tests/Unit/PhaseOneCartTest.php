<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartQuantityMode;

test('Phase one cart plan permits preserve_source only for replacement without an explicit quantity', static function (): void {
    $plan = CartPlan::fromArray(array('commands' => array(array(
        'action' => 'replace',
        'target_ref' => 'l_AAAAAAAA',
        'product_ref' => 'p_BBBBBBBB',
        'quantity_mode' => 'preserve_source',
    ))));
    assert_same(CartQuantityMode::PreserveSource, $plan->commands[0]->quantityMode);
    assert_same(null, $plan->commands[0]->quantity);

    assert_throws(InvalidArgumentException::class, static fn () => CartPlan::fromArray(array('commands' => array(array(
        'action' => 'add',
        'product_ref' => 'p_BBBBBBBB',
        'quantity_mode' => 'preserve_source',
    )))));
    assert_throws(InvalidArgumentException::class, static fn () => CartPlan::fromArray(array('commands' => array(array(
        'action' => 'replace',
        'target_ref' => 'l_AAAAAAAA',
        'product_ref' => 'p_BBBBBBBB',
        'quantity' => 2,
        'quantity_mode' => 'preserve_source',
    )))));
});

test('Phase one typed cart clarification is bounded, expiring, and tombstone-safe', static function (): void {
    $now = 1_800_000_000;
    $context = new ToolContext('turn:clarification', null, $now);
    $ref = $context->registerProduct(
        array('id' => 7, 'parent_id' => 0, 'type' => 'simple', 'fingerprint' => hash('sha256', 'product-7')),
        array('name' => 'Replacement product')
    );
    $pending = $context->setCartClarification(array(
        'action' => 'replace',
        'missing' => array('target'),
        'product_ref' => $ref,
        'quantity_mode' => 'preserve_source',
        'target_description' => 'the current blue item',
    ));
    assert_same('pending', $pending['status']);
    assert_same('preserve_source', $pending['intent']['quantity_mode']);
    assert_true(preg_match('/^q_[A-Za-z0-9_-]{8,80}$/D', $pending['ref']) === 1);

    $restored = new ToolContext('turn:clarification-restored', null, $now + 10);
    $restored->restoreProducts($context->productSnapshot());
    $restored->restoreCartClarification($context->cartClarificationSnapshot());
    assert_same($pending['ref'], $restored->pendingCartClarification()['ref']);

    $missingAuthority = new ToolContext('turn:clarification-missing-product', null, $now + 10);
    $missingAuthority->restoreCartClarification($context->cartClarificationSnapshot());
    assert_same(null, $missingAuthority->pendingCartClarification());
    assert_same('cleared', $missingAuthority->cartClarificationSnapshot()['status']);

    $context->clearCartClarification();
    $clearedSnapshot = $context->cartClarificationSnapshot();
    $tombstoned = new ToolContext('turn:clarification-cleared', null, $now + 10);
    $tombstoned->restoreCartClarification($clearedSnapshot);
    $tombstoned->restoreCartClarification($pending);
    assert_same(null, $tombstoned->pendingCartClarification());
    assert_same('cleared', $tombstoned->cartClarificationSnapshot()['status']);

    $expired = new ToolContext('turn:clarification-expired', null, $now + 1801);
    $expired->restoreCartClarification($pending);
    assert_same(null, $expired->pendingCartClarification());

    assert_throws(InvalidArgumentException::class, static fn () => $context->setCartClarification(array(
        'action' => 'replace',
        'missing' => array('target'),
        'quantity_mode' => 'preserve_source',
        'requested_quantity' => 2,
    )));
    assert_throws(InvalidArgumentException::class, static fn () => $context->setCartClarification(array(
        'action' => 'remove',
        'missing' => array('product'),
        'unexpected' => true,
    )));
    assert_throws(InvalidArgumentException::class, static fn () => $context->setCartClarification(array(
        'action' => 'replace',
        'missing' => array('target'),
        'product_ref' => $ref,
        'quantity_mode' => 'explicit',
    )), 'quantity');
    assert_throws(InvalidArgumentException::class, static fn () => $context->setCartClarification(array(
        'action' => 'remove',
        'missing' => array('target'),
        'requested_quantity' => 1,
    )), 'quantity');
    assert_throws(InvalidArgumentException::class, static fn () => $context->setCartClarification(array(
        'action' => 'replace',
        'missing' => array('target'),
        'product_ref' => $ref,
        'quantity_mode' => 'preserve_source',
        'target_description' => 'customer@example.test',
    )), 'sensitive');
});


test('Phase one clarification keeps its product authority inside the bounded persistence window', static function (): void {
    $now = 1_800_000_000;
    $context = new ToolContext('turn:clarification-window', null, $now);
    $firstRef = '';
    for ($id = 1; $id <= 45; ++$id) {
        $ref = $context->registerProduct(
            array('id' => $id, 'parent_id' => 0, 'type' => 'simple', 'fingerprint' => hash('sha256', 'window-product-' . $id)),
            array('name' => 'Window product ' . $id)
        );
        if ($id === 1) {
            $firstRef = $ref;
        }
    }
    assert_true($firstRef !== '');

    $pending = $context->setCartClarification(array(
        'action' => 'replace',
        'missing' => array('target'),
        'product_ref' => $firstRef,
        'quantity_mode' => 'preserve_source',
        'target_description' => 'the current item',
    ));
    $products = $context->productSnapshot();
    assert_true(isset($products[$firstRef]));
    assert_count_value(40, $products);

    $restored = new ToolContext('turn:clarification-window-restored', null, $now + 10);
    $restored->restoreProducts($products);
    $restored->restoreCartClarification($context->cartClarificationSnapshot());
    assert_same($pending['ref'], $restored->pendingCartClarification()['ref']);
});
