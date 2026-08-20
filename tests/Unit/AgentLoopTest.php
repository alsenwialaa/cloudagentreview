<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Chat\AgentLoop;
use YassinStore\AiAssistant\Application\Chat\IntentVerifier;
use YassinStore\AiAssistant\Application\Chat\PromptFactory;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Application\Tool\ShoppingMemoryPolicy;
use YassinStore\AiAssistant\Application\Tool\ToolRegistry;

/** @return array{agent:AgentLoop,registry:ToolRegistry,cart:TestCart,conversations:InMemoryConversationRepository} */
function build_test_agent(ScriptedAiProvider $provider, ?TestCatalog $catalog = null, ?TestSettings $settings = null): array
{
    $settings ??= new TestSettings();
    $catalog ??= new TestCatalog(array(new TestProduct(1, 'Test product', 10.0)));
    $cart = new TestCart();
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $credentials = $conversations->seed();
    $registry = new ToolRegistry(
        $catalog,
        $cart,
        new TestContent(),
        $conversations,
        new IntentVerifier($provider),
        $settings,
        new ShoppingMemoryPolicy()
    );
    $agent = new AgentLoop(
        $provider,
        $registry,
        new PromptFactory($settings),
        $conversations,
        $settings
    );
    return compact('agent', 'registry', 'cart', 'conversations') + array('credentials' => $credentials);
}

test('AgentLoop fails safely when the model does not issue a tool call', static function (): void {
    $provider = new ScriptedAiProvider(array(array(
        'status' => 'completed',
        'steps' => array(array(
            'type' => 'model_output',
            'content' => array(array('type' => 'text', 'text' => 'Unsupported raw prose.')),
        )),
    )));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $result = $built['agent']->run($credentials->id, array(), 'ساعدني', null, null, new ToolContext());
    assert_same('safe_failure', $result['kind']);
    assert_same(1, $provider->interactCalls);
    assert_true(array_key_exists('_product_context', $result));
});

test('AgentLoop accepts one terminal response call and ignores raw model prose', static function (): void {
    $provider = new ScriptedAiProvider(array(array(
        'status' => 'completed',
        'steps' => array(
            array('type' => 'model_output', 'content' => array(array('type' => 'text', 'text' => 'Do not render me.'))),
            array(
                'type' => 'function_call',
                'id' => 'call-terminal',
                'name' => 'respond_answer',
                'arguments' => array('message' => '<b>الإجابة الموثوقة</b>'),
            ),
        ),
    )));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $result = $built['agent']->run($credentials->id, array(), 'سؤال', null, null, new ToolContext());
    assert_same('answer', $result['kind']);
    assert_same('الإجابة الموثوقة', $result['message']);
    assert_false(str_contains($result['message'], 'Do not render'));
});

test('AgentLoop rejects non-object function arguments at the application boundary', static function (): void {
    $provider = new ScriptedAiProvider(array(array(
        'steps' => array(array(
            'type' => 'function_call',
            'id' => 'call-list-arguments',
            'name' => 'store_info',
            'arguments' => array('unexpected-list-item'),
        )),
    )));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];

    $result = $built['agent']->run($credentials->id, array(), 'ما اسم المتجر؟', null, null, new ToolContext());

    assert_same('safe_failure', $result['kind']);
    assert_contains('غير صالح', $result['message']);
    assert_same(1, $provider->interactCalls);
});

test('AgentLoop rejects terminal calls mixed with other calls before continuing', static function (): void {
    $provider = new ScriptedAiProvider(array(
        array('steps' => array(
            array('type' => 'function_call', 'id' => 'c1', 'name' => 'store_info', 'arguments' => array()),
            array('type' => 'function_call', 'id' => 'c2', 'name' => 'respond_answer', 'arguments' => array('message' => 'bad mix')),
        )),
        array('steps' => array(
            array('type' => 'function_call', 'id' => 'c3', 'name' => 'respond_safe_failure', 'arguments' => array('message' => 'تم رفض الاستجابة المختلطة.')),
        )),
    ));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $result = $built['agent']->run($credentials->id, array(), 'سؤال', null, null, new ToolContext());
    assert_same('safe_failure', $result['kind']);
    assert_same(2, $provider->interactCalls);

    $secondHistory = $provider->histories[1];
    $results = array_values(array_filter($secondHistory, static fn (array $step): bool => ($step['type'] ?? '') === 'function_result'));
    assert_count_value(2, $results);
    assert_contains('terminal response must be the only', (string) $results[0]['result'][0]['text']);
});

test('AgentLoop never executes cart_apply when it is mixed with another model call', static function (): void {
    $provider = new ScriptedAiProvider(array(
        array('steps' => array(
            array('type' => 'function_call', 'id' => 'cart', 'name' => 'cart_apply', 'arguments' => array()),
            array('type' => 'function_call', 'id' => 'info', 'name' => 'store_info', 'arguments' => array()),
        )),
        array('steps' => array(
            array('type' => 'function_call', 'id' => 'done', 'name' => 'respond_safe_failure', 'arguments' => array('message' => 'لم تُنفذ أي سلة.')),
        )),
    ));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $result = $built['agent']->run($credentials->id, array(), 'أضف', null, null, new ToolContext('turn:1'));
    assert_same('safe_failure', $result['kind']);
    assert_same(0, $built['cart']->applyCalls);
    $results = array_values(array_filter(
        $provider->histories[1],
        static fn (array $step): bool => ($step['type'] ?? '') === 'function_result'
    ));
    assert_count_value(2, $results);
    assert_contains('No cart change was executed', (string) $results[0]['result'][0]['text']);
});

test('AgentLoop appends exact tool results to stateless history before the next model call', static function (): void {
    $provider = new ScriptedAiProvider(array(
        array('steps' => array(
            array('type' => 'function_call', 'id' => 'store', 'name' => 'store_info', 'arguments' => array()),
        )),
        array('steps' => array(
            array('type' => 'function_call', 'id' => 'answer', 'name' => 'respond_answer', 'arguments' => array('message' => 'اسم المتجر Test Store.')),
        )),
    ));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $result = $built['agent']->run($credentials->id, array(), 'ما اسم المتجر؟', null, null, new ToolContext());
    assert_same('answer', $result['kind']);
    assert_same(2, $provider->interactCalls);

    $secondHistory = $provider->histories[1];
    $last = $secondHistory[array_key_last($secondHistory)];
    assert_same('function_result', $last['type']);
    assert_same('store_info', $last['name']);
    assert_contains('Test Store', (string) $last['result'][0]['text']);
});

test('AgentLoop replays provider wire steps exactly during a stateless tool round', static function (): void {
    $rawStep = json_decode(
        '{"type":"function_call","id":"wire-empty","name":"store_info","arguments":{}}',
        false,
        16,
        JSON_THROW_ON_ERROR
    );
    assert_true($rawStep instanceof stdClass);
    $provider = new ScriptedAiProvider(array(
        array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'wire-empty',
                'name' => 'store_info',
                'arguments' => array(),
            )),
            '_wire_steps' => array($rawStep),
        ),
        array('steps' => array(
            array(
                'type' => 'function_call',
                'id' => 'wire-answer',
                'name' => 'respond_answer',
                'arguments' => array('message' => 'تم.'),
            ),
        )),
    ));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $result = $built['agent']->run($credentials->id, array(), 'ما اسم المتجر؟', null, null, new ToolContext());

    assert_same('answer', $result['kind']);
    $secondHistory = $provider->histories[1];
    assert_true(($secondHistory[1] ?? null) instanceof stdClass);
    assert_true($secondHistory[1]->arguments instanceof stdClass);
    assert_same(array(), get_object_vars($secondHistory[1]->arguments));
});

test('ToolRegistry requires a fresh cart view, exact current-message evidence, and one mutation attempt', static function (): void {
    $provider = new ScriptedAiProvider();
    $built = build_test_agent($provider);
    $registry = $built['registry'];
    $cart = $built['cart'];
    $credentials = $built['credentials'];
    $context = new ToolContext('turn:99');
    $catalog = new TestCatalog(array(new TestProduct(5, 'Blue item', 10.0)));

    // Build a dedicated registry with the same context-owned product identity.
    $product = $catalog->product(5);
    assert_true($product instanceof TestProduct);
    $ref = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    $registry = new ToolRegistry(
        $catalog,
        $cart,
        new TestContent(),
        $built['conversations'],
        new IntentVerifier($provider),
        new TestSettings(),
        new ShoppingMemoryPolicy()
    );

    $arguments = array(
        'commands' => array(array('action' => 'add', 'product_ref' => $ref, 'quantity' => 2)),
        'evidence' => 'أضف المنتج بكمية 2',
    );

    $withoutView = $registry->execute('cart_apply', $arguments, $context, $credentials->id, 'أضف المنتج بكمية 2', null);
    assert_same(true, $withoutView->result['requires_cart_refresh']);
    assert_same(0, $cart->applyCalls);

    $context->beginCartSnapshot(hash('sha256', 'logical-only'));
    $withoutDurableBinding = $registry->execute('cart_apply', $arguments, $context, $credentials->id, 'أضف المنتج بكمية 2', null);
    assert_same(true, $withoutDurableBinding->result['requires_cart_refresh']);
    assert_same(true, $withoutDurableBinding->result['mutations_unavailable']);
    assert_same(0, $provider->structuredCalls);
    assert_same(0, $cart->applyCalls);

    $registry->execute('cart_view', array(), $context, $credentials->id, 'أضف المنتج بكمية 2', null);
    $badEvidence = $registry->execute('cart_apply', array_replace($arguments, array('evidence' => 'نص غير موجود')), $context, $credentials->id, 'أضف المنتج بكمية 2', null);
    assert_same(true, $badEvidence->result['requires_clarification']);
    assert_same(0, $provider->structuredCalls);

    $applied = $registry->execute('cart_apply', $arguments, $context, $credentials->id, 'أضف المنتج بكمية 2', null);
    assert_true(is_array($applied->terminal));
    assert_same('cart_receipt', $applied->terminal['kind']);
    assert_same(1, $cart->applyCalls);
    assert_same('turn:99', $cart->lastOperationKey);
    assert_same(1, $provider->structuredCalls);

    $second = $registry->execute('cart_apply', $arguments, $context, $credentials->id, 'أضف المنتج بكمية 2', null);
    assert_same(true, $second->result['requires_new_turn']);
    assert_same(1, $cart->applyCalls);
});


test('ToolRegistry rejects model scalar coercion and cross-type opaque references', static function (): void {
    $provider = new ScriptedAiProvider();
    $built = build_test_agent($provider);
    $registry = $built['registry'];
    $credentials = $built['credentials'];
    $context = new ToolContext('turn:strict-tools');

    $wrongLimit = $registry->execute(
        'catalog_discover',
        array('query' => 'product', 'limit' => '6'),
        $context,
        $credentials->id,
        'product',
        null
    );
    assert_same('invalid_arguments', $wrongLimit->result['error_type']);
    assert_same('The tool request is invalid or references stale live state. Refresh authoritative product or cart data, then retry using the declared schema without guessing identifiers.', $wrongLimit->result['error']);
    assert_false(str_contains($wrongLimit->result['error'], 'integer'));

    $wrongQuery = $registry->execute(
        'catalog_discover',
        array('query' => array('product')),
        $context,
        $credentials->id,
        'product',
        null
    );
    assert_same('invalid_arguments', $wrongQuery->result['error_type']);

    $wrongRef = $registry->execute(
        'catalog_get_details',
        array('product_ref' => 'l_abcdefgh1234'),
        $context,
        $credentials->id,
        'product',
        null
    );
    assert_same('invalid_arguments', $wrongRef->result['error_type']);

    $variation = $registry->execute(
        'catalog_resolve_variation',
        array('product_ref' => 'p_abcdefgh1234', 'attributes' => array('size' => 42)),
        $context,
        $credentials->id,
        'product',
        null
    );
    assert_same('invalid_arguments', $variation->result['error_type']);
});

test('ToolRegistry redacts internal invalid-argument details before they enter model-visible history', static function (): void {
    $provider = new ScriptedAiProvider();
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $context = new ToolContext('turn:redacted-tool-error');
    $catalog = new TestCatalog(array(new TestProduct(91, 'Sensitive item', 18.0)));
    $product = $catalog->product(91);
    assert_true($product instanceof TestProduct);
    $ref = $context->registerProduct($catalog->identity($product), $catalog->project($product));

    $built['cart']->applyError = new \InvalidArgumentException('INTERNAL_SENTINEL_DATABASE_AND_EXTENSION_DETAIL');
    $registry = new ToolRegistry(
        $catalog,
        $built['cart'],
        new TestContent(),
        $built['conversations'],
        new IntentVerifier($provider),
        new TestSettings(),
        new ShoppingMemoryPolicy()
    );
    $registry->execute('cart_view', array(), $context, $credentials->id, 'أضف المنتج', null);

    $execution = $registry->execute(
        'cart_apply',
        array(
            'commands' => array(array('action' => 'add', 'product_ref' => $ref, 'quantity' => 1)),
            'evidence' => 'أضف المنتج',
        ),
        $context,
        $credentials->id,
        'أضف المنتج',
        null
    );

    assert_same('invalid_arguments', $execution->result['error_type']);
    assert_same('The tool request is invalid or references stale live state. Refresh authoritative product or cart data, then retry using the declared schema without guessing identifiers.', $execution->result['error']);
    $encoded = json_encode($execution->result, JSON_THROW_ON_ERROR);
    assert_false(str_contains($encoded, 'INTERNAL_SENTINEL'));
    assert_false(str_contains($encoded, 'DATABASE_AND_EXTENSION_DETAIL'));
});

test('ToolRegistry rejects unsupported ranking criteria even when the provider violates its schema', static function (): void {
    $provider = new ScriptedAiProvider();
    $built = build_test_agent($provider);
    $context = new ToolContext('turn:invalid-ranking');
    $productA = new TestProduct(1, 'First product', 10.0);
    $productB = new TestProduct(2, 'Second product', 30.0);
    $catalog = new TestCatalog(array($productA, $productB));
    $refA = $context->registerProduct($catalog->identity($productA), $catalog->project($productA));
    $refB = $context->registerProduct($catalog->identity($productB), $catalog->project($productB));
    $registry = new ToolRegistry(
        $catalog,
        $built['cart'],
        new TestContent(),
        $built['conversations'],
        new IntentVerifier($provider),
        new TestSettings(),
        new ShoppingMemoryPolicy()
    );

    $result = $registry->execute(
        'catalog_rank_candidates',
        array('product_refs' => array($refA, $refB), 'criterion' => 'merchant_margin'),
        $context,
        $built['credentials']->id,
        'رتب المنتجات',
        null
    );

    assert_same('invalid_arguments', $result->result['error_type']);
    assert_same('The tool request is invalid or references stale live state. Refresh authoritative product or cart data, then retry using the declared schema without guessing identifiers.', $result->result['error']);
    assert_false(str_contains($result->result['error'], 'merchant_margin'));
});

test('AgentLoop rejects a model step that exceeds the function-call budget before executing tools', static function (): void {
    $calls = array();
    for ($index = 1; $index <= 9; ++$index) {
        $calls[] = array('type' => 'function_call', 'id' => 'budget-' . $index, 'name' => 'store_info', 'arguments' => array());
    }
    $provider = new ScriptedAiProvider(array(array('steps' => $calls)));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];

    $result = $built['agent']->run($credentials->id, array(), 'ما معلومات المتجر؟', null, null, new ToolContext());

    assert_same('safe_failure', $result['kind']);
    assert_contains('حد الأدوات', $result['message']);
    assert_same(1, $provider->interactCalls);
});

test('AgentLoop rejects duplicate function call identifiers before executing tools', static function (): void {
    $provider = new ScriptedAiProvider(array(array('steps' => array(
        array('type' => 'function_call', 'id' => 'duplicate-call', 'name' => 'store_info', 'arguments' => array()),
        array('type' => 'function_call', 'id' => 'duplicate-call', 'name' => 'store_policy', 'arguments' => array()),
    ))));
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];

    $result = $built['agent']->run($credentials->id, array(), 'اعرض المعلومات والسياسات', null, null, new ToolContext());

    assert_same('safe_failure', $result['kind']);
    assert_contains('مكرر', $result['message']);
    assert_same(1, $provider->interactCalls);
});

test('A denied cart authorization consumes the only mutation attempt for the turn', static function (): void {
    $provider = new ScriptedAiProvider();
    $provider->structuredResult = static function (string $input): array {
        $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        return array(
            'authorized' => false,
            'requires_clarification' => true,
            'reason' => 'Ambiguous request.',
            'authorization_fingerprint' => (string) $decoded['authorization_fingerprint'],
        );
    };
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $context = new ToolContext('turn:denied');
    $catalog = new TestCatalog(array(new TestProduct(8, 'Denied item', 10.0)));
    $product = $catalog->product(8);
    assert_true($product instanceof TestProduct);
    $ref = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    $registry = new ToolRegistry(
        $catalog,
        $built['cart'],
        new TestContent(),
        $built['conversations'],
        new IntentVerifier($provider),
        new TestSettings(),
        new ShoppingMemoryPolicy()
    );
    $registry->execute('cart_view', array(), $context, $credentials->id, 'أضف المنتج', null);
    $arguments = array(
        'commands' => array(array('action' => 'add', 'product_ref' => $ref, 'quantity' => 1)),
        'evidence' => 'أضف المنتج',
    );

    $denied = $registry->execute('cart_apply', $arguments, $context, $credentials->id, 'أضف المنتج', null);
    assert_same(true, $denied->result['requires_clarification']);
    assert_same(1, $provider->structuredCalls);
    assert_same(0, $built['cart']->applyCalls);

    $second = $registry->execute('cart_apply', $arguments, $context, $credentials->id, 'أضف المنتج', null);
    assert_same(true, $second->result['requires_new_turn']);
    assert_same(1, $provider->structuredCalls);
    assert_same(0, $built['cart']->applyCalls);
});

test('IntentVerifier fails closed when the structured decision is not bound to the current cart plan', static function (): void {
    $provider = new ScriptedAiProvider();
    $provider->structuredResult = array(
        'authorized' => true,
        'requires_clarification' => false,
        'reason' => 'Approved.',
        'authorization_fingerprint' => str_repeat('0', 64),
    );
    $context = new ToolContext('turn:fingerprint');
    $catalog = new TestCatalog(array(new TestProduct(31, 'Bound product', 12.0)));
    $product = $catalog->product(31);
    assert_true($product instanceof TestProduct);
    $ref = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    $context->beginCartSnapshot(hash('sha256', 'current-cart'));
    $plan = \YassinStore\AiAssistant\Domain\Commerce\CartPlan::fromArray(array('commands' => array(
        array('action' => 'add', 'product_ref' => $ref, 'quantity' => 1),
    )));

    $decision = (new IntentVerifier($provider))->authorize('أضف المنتج', null, 'أضف المنتج', $plan, $context);

    assert_false($decision->authorized);
    assert_true($decision->requiresClarification);
    assert_contains('ربط', $decision->reason);
});

test('IntentVerifier treats contradictory authorized-and-clarify output as a denial', static function (): void {
    $provider = new ScriptedAiProvider();
    $provider->structuredResult = static function (string $input): array {
        $decoded = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        return array(
            'authorized' => true,
            'requires_clarification' => true,
            'reason' => 'Contradictory.',
            'authorization_fingerprint' => (string) $decoded['authorization_fingerprint'],
        );
    };
    $context = new ToolContext('turn:contradiction');
    $catalog = new TestCatalog(array(new TestProduct(32, 'Clarify product', 13.0)));
    $product = $catalog->product(32);
    assert_true($product instanceof TestProduct);
    $ref = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    $context->beginCartSnapshot(hash('sha256', 'current-cart-2'));
    $plan = \YassinStore\AiAssistant\Domain\Commerce\CartPlan::fromArray(array('commands' => array(
        array('action' => 'add', 'product_ref' => $ref, 'quantity' => 1),
    )));

    $decision = (new IntentVerifier($provider))->authorize('أضف المنتج', null, 'أضف المنتج', $plan, $context);

    assert_false($decision->authorized);
    assert_true($decision->requiresClarification);
    assert_contains('توضيح', $decision->reason);
});

test('ToolRegistry ends the turn when a cart write leaves state uncertain', static function (): void {
    $provider = new ScriptedAiProvider();
    $built = build_test_agent($provider);
    $credentials = $built['credentials'];
    $cart = $built['cart'];
    $cart->applyError = new \YassinStore\AiAssistant\Domain\Commerce\CartStateUncertain('uncertain');
    $context = new ToolContext('turn:uncertain');
    $catalog = new TestCatalog(array(new TestProduct(91, 'Uncertain item', 14.0)));
    $product = $catalog->product(91);
    assert_true($product instanceof TestProduct);
    $ref = $context->registerProduct($catalog->identity($product), $catalog->project($product));
    $registry = new ToolRegistry(
        $catalog,
        $cart,
        new TestContent(),
        $built['conversations'],
        new IntentVerifier($provider),
        new TestSettings(),
        new ShoppingMemoryPolicy()
    );
    $registry->execute('cart_view', array(), $context, $credentials->id, 'أضف المنتج', null);

    $execution = $registry->execute('cart_apply', array(
        'commands' => array(array('action' => 'add', 'product_ref' => $ref, 'quantity' => 1)),
        'evidence' => 'أضف المنتج',
    ), $context, $credentials->id, 'أضف المنتج', null);

    assert_same('cart_state_uncertain', $execution->result['error_type']);
    assert_same(true, $execution->result['requires_cart_review']);
    assert_true(is_array($execution->terminal));
    assert_same('cart_uncertain', $execution->terminal['kind']);
    assert_contains('لا تُعِد الطلب', $execution->terminal['message']);
    assert_same(1, $cart->applyCalls);

    $second = $registry->execute('cart_apply', array(
        'commands' => array(array('action' => 'add', 'product_ref' => $ref, 'quantity' => 1)),
        'evidence' => 'أضف المنتج',
    ), $context, $credentials->id, 'أضف المنتج', null);
    assert_same(true, $second->result['requires_new_turn']);
    assert_same(1, $cart->applyCalls);
});

test('ToolRegistry uses only the catalog abstraction for alternatives, related products, and variations', static function (): void {
    $provider = new ScriptedAiProvider();
    $catalog = new TestCatalog(array(
        new TestProduct(1, 'Source product', 20.0),
        new TestProduct(2, 'Valid alternative', 18.0),
        new TestProduct(3, 'Malformed identity', 17.0),
        new TestProduct(4, 'Oversized projection', 16.0),
    ));
    $catalog->identityOverrides[3] = array(
        'id' => '3',
        'parent_id' => 0,
        'type' => 'simple',
        'fingerprint' => hash('sha256', 'invalid-scalar-id'),
    );
    $catalog->projectionOverrides[4] = array('name' => str_repeat('x', 40_000));

    $built = build_test_agent($provider, $catalog);
    $registry = $built['registry'];
    $credentials = $built['credentials'];
    $context = new ToolContext('turn:catalog-abstraction');

    $discovered = $registry->execute(
        'catalog_discover',
        array('query' => 'product', 'limit' => 4),
        $context,
        $credentials->id,
        'اعرض المنتجات',
        null
    );
    assert_same(2, count($discovered->result['products']));
    assert_same(array(
        'results_truncated' => false,
        'scan_exhausted' => true,
        'scanned_candidates' => 4,
        'scan_limit' => 240,
    ), $discovered->result['search_meta']);
    $sourceRef = (string) $discovered->result['products'][0]['ref'];

    $alternatives = $registry->execute(
        'catalog_find_alternatives',
        array('product_ref' => $sourceRef, 'limit' => 4),
        $context,
        $credentials->id,
        'اعرض البدائل',
        null
    );
    assert_count_value(1, $alternatives->result['products']);
    assert_same('Valid alternative', $alternatives->result['products'][0]['name']);

    $related = $registry->execute(
        'catalog_related',
        array('product_ref' => $sourceRef, 'limit' => 4),
        $context,
        $credentials->id,
        'اعرض المنتجات المرتبطة',
        null
    );
    assert_count_value(1, $related->result['products']);
    assert_same('Valid alternative', $related->result['products'][0]['name']);

    $variation = $registry->execute(
        'catalog_resolve_variation',
        array('product_ref' => $sourceRef, 'attributes' => array('size' => 'medium')),
        $context,
        $credentials->id,
        'اختر المقاس المتوسط',
        null
    );
    assert_same(true, $variation->result['resolved']);
    assert_same('Source product', $variation->result['product']['name']);
});

test('ToolRegistry never replaces explicit stale terminal product references with unrelated cards', static function (): void {
    $built = build_test_agent(new ScriptedAiProvider(array()));
    $context = new ToolContext();
    $discovery = $built['registry']->execute(
        'catalog_discover',
        array('query' => 'test'),
        $context,
        $built['credentials']->id,
        'test',
        null
    );
    assert_count_value(1, $discovery->result['products']);
    $validRef = (string) $discovery->result['products'][0]['ref'];

    $omitted = $built['registry']->terminal('respond_answer', array('message' => 'Answer'), $context);
    assert_count_value(1, $omitted['products']);
    assert_same($validRef, $omitted['products'][0]['ref']);

    $explicitValid = $built['registry']->terminal('respond_answer', array(
        'message' => 'Answer',
        'product_refs' => array($validRef),
    ), $context);
    assert_count_value(1, $explicitValid['products']);

    foreach (array(
        array(),
        array('p_' . str_repeat('z', 12)),
        array('not-a-reference'),
        'not-a-list',
    ) as $explicitInvalid) {
        $terminal = $built['registry']->terminal('respond_answer', array(
            'message' => 'Answer',
            'product_refs' => $explicitInvalid,
        ), $context);
        assert_same(array(), $terminal['products']);
    }
});


test('AgentLoop preserves provider-issued opaque function-call identifiers exactly', static function (): void {
    $provider = new ScriptedAiProvider(array(
        array(
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'fc/opaque=part+1',
                'name' => 'store_info',
                'arguments' => array(),
            )),
        ),
        static function (array $history): array {
            $result = $history[array_key_last($history)] ?? array();
            assert_same('function_result', $result['type'] ?? null);
            assert_same('fc/opaque=part+1', $result['call_id'] ?? null);
            return array(
                'steps' => array(array(
                    'type' => 'function_call',
                    'id' => 'terminal/opaque=part+2',
                    'name' => 'respond_answer',
                    'arguments' => array('message' => 'تم.'),
                )),
            );
        },
    ));
    $conversations = new InMemoryConversationRepository(new TestClock());
    $credentials = $conversations->seed();
    $settings = new TestSettings();
    $registry = new ToolRegistry(
        new TestCatalog(),
        new TestCart(),
        new TestContent(),
        $conversations,
        new IntentVerifier($provider),
        $settings,
        new ShoppingMemoryPolicy()
    );
    $loop = new AgentLoop($provider, $registry, new PromptFactory($settings), $conversations, $settings);

    $result = $loop->run(
        $credentials->id,
        array(),
        'معلومات المتجر',
        null,
        null,
        new ToolContext()
    );

    assert_same('answer', $result['kind']);
    assert_same('تم.', $result['message']);
});
