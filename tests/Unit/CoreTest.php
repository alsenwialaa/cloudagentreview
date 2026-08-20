<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Chat\PromptFactory;
use YassinStore\AiAssistant\Application\Contract\TurnGuard;
use YassinStore\AiAssistant\Application\Contract\TurnLeaseLost;
use YassinStore\AiAssistant\Application\Support\SensitiveData;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Application\Tool\ShoppingMemoryPolicy;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartQuantityPolicy;
use YassinStore\AiAssistant\Domain\Commerce\CartReceipt;
use YassinStore\AiAssistant\Domain\Conversation\ConversationCredentials;
use YassinStore\AiAssistant\Domain\Shared\Base64Url;
use YassinStore\AiAssistant\Domain\Shared\Uuid;
use YassinStore\AiAssistant\Infrastructure\Security\ImageInput;
use YassinStore\AiAssistant\Infrastructure\Security\RequestIdentity;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\Security\TokenHasher;
use YassinStore\AiAssistant\Infrastructure\Database\BoundedJson;
use YassinStore\AiAssistant\Infrastructure\Database\WpConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Database\WpTurnRepository;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;
use YassinStore\AiAssistant\Infrastructure\WooCommerce\VariationAttributeMatcher;

test('Base64Url round-trips arbitrary bytes and rejects malformed values', static function (): void {
    $bytes = random_bytes(48);
    $encoded = Base64Url::encode($bytes);
    assert_true(preg_match('/^[A-Za-z0-9_-]+$/', $encoded) === 1);
    assert_same($bytes, Base64Url::decode($encoded));
    assert_throws(InvalidArgumentException::class, static fn (): string => Base64Url::decode('bad=value'));
    assert_throws(InvalidArgumentException::class, static fn (): string => Base64Url::decode(''));
});

test('UUID and conversation credentials use bounded opaque capabilities', static function (): void {
    $uuid = Uuid::v4();
    assert_true(Uuid::isValid($uuid));
    assert_false(Uuid::isValid('00000000-0000-0000-0000-000000000000'));

    $credentials = ConversationCredentials::issue();
    assert_true(Uuid::isValid($credentials->id));
    assert_true(strlen($credentials->token) >= 40);
    assert_throws(InvalidArgumentException::class, static fn (): ConversationCredentials => new ConversationCredentials($uuid, 'short'));
});

test('Text normalization strips markup, control bytes, and applies Unicode limits', static function (): void {
    assert_same('مرحبًا عالم', Text::plain(" <b>مرحبًا</b>\x00 عالم ", 20));
    assert_true(Text::contains('اختيار المنتج المناسب', 'المنتج'));
    assert_false(Text::contains('abc', ''));
    assert_same('أبج', Text::slice('أبجده', 0, 3));
});

test('SensitiveData detects personal, payment, and credential material', static function (): void {
    assert_true(SensitiveData::detected('my email is shopper@example.com'));
    assert_true(SensitiveData::detected('رقم البطاقة 4111 1111 1111 1111'));
    assert_true(SensitiveData::detected('كلمة المرور هي secret'));
    assert_false(SensitiveData::detected('أفضل اللون الأزرق وميزانيتي 50 دولارًا'));
});

test('CartPlan enforces action-specific fields, bounds, and uniqueness', static function (): void {
    $plan = CartPlan::fromArray(array('commands' => array(
        array('action' => 'add', 'product_ref' => 'p_abcdefgh1234', 'quantity' => 2),
        array('action' => 'remove', 'target_ref' => 'l_abcdefgh1234'),
    )));
    assert_count_value(2, $plan->commands);

    assert_throws(InvalidArgumentException::class, static fn (): CartPlan => CartPlan::fromArray(array('commands' => array(
        array('action' => 'clear'),
        array('action' => 'remove', 'target_ref' => 'l_abcdefgh1234'),
    ))), 'Clear');
    assert_throws(InvalidArgumentException::class, static fn (): CartPlan => CartPlan::fromArray(array('commands' => array(
        array('action' => 'add', 'product_ref' => 'p_abcdefgh1234', 'quantity' => 0),
    ))), 'positive');
    assert_throws(InvalidArgumentException::class, static fn (): CartPlan => CartPlan::fromArray(array('commands' => array(
        array('action' => 'remove', 'target_ref' => 'l_abcdefgh1234'),
        array('action' => 'increment', 'target_ref' => 'l_abcdefgh1234', 'quantity' => 1),
    ))), 'targeted twice');


    assert_throws(InvalidArgumentException::class, static fn (): CartPlan => CartPlan::fromArray(array('commands' => array(
        array('action' => 'add', 'product_ref' => 'p_abcdefgh1234', 'quantity' => '2'),
    ))), 'integer');
    assert_throws(InvalidArgumentException::class, static fn (): CartPlan => CartPlan::fromArray(array('commands' => array(
        array('action' => 'remove', 'target_ref' => 'p_abcdefgh1234'),
    ))), 'reference');
    assert_throws(InvalidArgumentException::class, static fn (): CartPlan => CartPlan::fromArray(array('commands' => array(
        array('action' => true, 'product_ref' => 'p_abcdefgh1234', 'quantity' => 1),
    ))), 'string');
});

test('ToolContext owns opaque refs, restores product context, and tracks mutation guards', static function (): void {
    $guard = new class implements TurnGuard {
        public int $heartbeats = 0;

        /** @return array{turn_id:int,claim_version:int} */
        public function claim(): array
        {
            return array('turn_id' => 42, 'claim_version' => 1);
        }

        public function heartbeat(): void
        {
            ++$this->heartbeats;
        }
    };
    $context = new ToolContext('turn:42', $guard);
    $identity = array('id' => 7, 'parent_id' => 0, 'type' => 'simple', 'fingerprint' => str_repeat('a', 64));
    $card = array('name' => 'منتج');
    $ref = $context->registerProduct($identity, $card);
    assert_true(preg_match('/^p_[A-Za-z0-9_-]{8,80}$/', $ref) === 1);
    assert_same($ref, $context->registerProduct($identity, $card));
    assert_same('منتج', $context->product($ref)['card']['name']);
    assert_same('turn:42', $context->operationKey());
    $context->heartbeatTurn();
    assert_same(1, $guard->heartbeats);

    $snapshot = $context->productSnapshot();
    $restored = new ToolContext();
    $restored->restoreProducts($snapshot);
    assert_same('منتج', $restored->cards(array($ref), 1)[0]['name']);

    assert_false($context->hasFreshCartView());
    assert_false($context->hasCartPersistenceBinding());
    assert_throws(LogicException::class, static fn (): null => $context->bindCartPersistence(str_repeat('a', 64)));
    $context->beginCartSnapshot(str_repeat('b', 64));
    assert_true($context->hasFreshCartView());
    assert_false($context->hasCartPersistenceBinding());
    assert_same(str_repeat('b', 64), $context->cartSnapshotSignature());
    $context->bindCartPersistence(str_repeat('b', 64));
    assert_true($context->hasCartPersistenceBinding());
    assert_same(str_repeat('b', 64), $context->cartPersistenceSignature());
    $lineRef = $context->registerLine(array('key' => 'line-1'), array('name' => 'سطر'));
    assert_same('سطر', $context->line($lineRef)['presentation']['name']);
    $context->beginCartSnapshot(str_repeat('c', 64));
    assert_false($context->hasCartPersistenceBinding());
    assert_throws(InvalidArgumentException::class, static fn (): array => $context->line($lineRef), 'stale');
    assert_false($context->hasCartMutationAttempted());
    $context->markCartMutationAttempted();
    assert_true($context->hasCartMutationAttempted());
});

test('ShoppingMemoryPolicy binds Arabic and Western numeric preferences to exact evidence', static function (): void {
    $policy = new ShoppingMemoryPolicy();

    $arabicThousands = $policy->authorize(
        array('budget_max' => 1500),
        'أبحث عن حقيبة وميزانيتي ١٬٥٠٠ ريال.',
        'ميزانيتي ١٬٥٠٠ ريال'
    );
    assert_same(1500.0, $arabicThousands['budget_max']);

    $arabicDecimal = $policy->authorize(
        array('budget_max' => 1500.5),
        'الحد الأقصى ١٥٠٠٫٥٠ ريال.',
        'الحد الأقصى ١٥٠٠٫٥٠ ريال'
    );
    assert_same(1500.5, $arabicDecimal['budget_max']);

    $westernMixed = $policy->authorize(
        array('budget_max' => 1500.5),
        'My maximum is 1,500.50 SAR.',
        'maximum is 1,500.50 SAR'
    );
    assert_same(1500.5, $westernMixed['budget_max']);

    assert_throws(
        InvalidArgumentException::class,
        static fn (): array => $policy->authorize(
            array('budget_max' => 1.5),
            'My maximum is 1,500 SAR.',
            'maximum is 1,500 SAR'
        ),
        'not stated'
    );
});

test('ShoppingMemoryPolicy rejects inferred categories, sensitive notes, and non-current evidence', static function (): void {
    $policy = new ShoppingMemoryPolicy();

    assert_throws(
        InvalidArgumentException::class,
        static fn (): array => $policy->authorize(
            array('categories' => array('أحذية')),
            'أبحث عن حقيبة جلدية.',
            'أبحث عن حقيبة جلدية'
        ),
        'category is not stated'
    );
    assert_throws(
        InvalidArgumentException::class,
        static fn (): array => $policy->authorize(
            array('notes' => 'راسلني على buyer@example.com'),
            'تفضيلي: راسلني على buyer@example.com',
            'راسلني على buyer@example.com'
        ),
        'Sensitive'
    );
    assert_throws(
        InvalidArgumentException::class,
        static fn (): array => $policy->authorize(
            array('categories' => array('حقائب')),
            'أبحث الآن عن أحذية.',
            'أبحث عن حقائب'
        ),
        'exact quote'
    );
});



test('Logger redacts message, query, trace, credential, and payload fields while preserving safe fingerprints', static function (): void {
    $logger = new Logger(new Settings(new SecretBox()));
    $method = new ReflectionMethod($logger, 'sanitize');
    $method->setAccessible(true);
    $safe = $method->invoke($logger, array(
        'message' => 'database detail that must not leave the process',
        'exception_message' => 'extension detail',
        'trace' => 'stack trace',
        'stack' => 'another stack',
        'sql' => 'SELECT secret FROM table',
        'query' => 'UPDATE private_table',
        'api_key' => 'AIza-not-a-real-key',
        'token' => 'conversation-capability',
        'request_body' => array('private' => true),
        'fingerprint' => '0123456789abcdef',
        'exception' => RuntimeException::class,
        'turn_id' => 42,
    ));

    foreach (array('message', 'exception_message', 'trace', 'stack', 'sql', 'query', 'api_key', 'token', 'request_body') as $key) {
        assert_same('[redacted]', $safe[$key] ?? null);
    }
    assert_same('0123456789abcdef', $safe['fingerprint'] ?? null);
    assert_same(RuntimeException::class, $safe['exception'] ?? null);
    assert_same(42, $safe['turn_id'] ?? null);
});

test('SecretBox encrypts, decrypts, and rejects tampering', static function (): void {
    $box = new SecretBox();
    $ciphertext = $box->encrypt('AIza-test-secret');
    assert_true($box->isEncrypted($ciphertext));
    assert_same('AIza-test-secret', $box->decrypt($ciphertext));
    assert_same('legacy-plaintext', $box->decrypt('legacy-plaintext'));

    $offset = intdiv(strlen($ciphertext), 2);
    $current = $ciphertext[$offset];
    $replacement = $current === 'A' ? 'B' : 'A';
    $tampered = substr($ciphertext, 0, $offset) . $replacement . substr($ciphertext, $offset + 1);
    assert_throws(Throwable::class, static fn (): string => $box->decrypt($tampered));
});

test('ImageInput validates content MIME, size, dimensions, and stores metadata only', static function (): void {
    $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Wl9sAAAAASUVORK5CYII=';
    $image = ImageInput::fromRequest(array('mime_type' => 'image/png', 'data' => $base64), true);
    assert_true($image instanceof ImageInput);
    assert_same(1, $image->width);
    assert_same(1, $image->height);
    assert_same(array('mime_type' => 'image/png', 'bytes' => $image->bytes, 'width' => 1, 'height' => 1), $image->metadata());

    assert_throws(InvalidArgumentException::class, static fn (): ?ImageInput => ImageInput::fromRequest(array(
        'mime_type' => 'image/jpeg',
        'data' => $base64,
    ), true), 'does not match');
    assert_throws(InvalidArgumentException::class, static fn (): ?ImageInput => ImageInput::fromRequest(array(
        'mime_type' => 'image/png',
        'data' => $base64,
    ), false), 'disabled');
});

test('ImageInput canonicalizes equivalent bytes and rejects ambiguous nested fields', static function (): void {
    $canonical = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Wl9sAAAAASUVORK5CYII=';
    $withWhitespace = chunk_split($canonical, 16, "\n");
    $first = ImageInput::fromRequest(array('mime_type' => 'image/png', 'data' => $canonical), true);
    $second = ImageInput::fromRequest(array('mime_type' => 'image/png', 'data' => $withWhitespace), true);
    assert_true($first instanceof ImageInput && $second instanceof ImageInput);
    assert_same($first->base64, $second->base64);
    assert_same($first->sha256, $second->sha256);
    assert_same(64, strlen($first->sha256));

    assert_throws(InvalidArgumentException::class, static fn (): ?ImageInput => ImageInput::fromRequest(array(
        'mime_type' => 'image/png',
        'data' => $canonical,
        'filename' => 'untrusted.png',
    ), true), 'unsupported field');
});

test('CartReceipt restores only bounded, valid server receipts', static function (): void {
    $id = Uuid::v4();
    $receipt = CartReceipt::fromArray(array(
        'id' => $id,
        'message' => 'تم.',
        'lines' => array(array('action' => 'add')),
        'cart' => array('item_count' => 1),
    ));
    assert_true($receipt instanceof CartReceipt);
    assert_same($id, $receipt->id);
    assert_same(null, CartReceipt::fromArray(array(
        'id' => 'not-a-uuid',
        'message' => 'تم.',
        'lines' => array(),
        'cart' => array(),
    )));
    assert_same(null, CartReceipt::fromArray(array(
        'id' => $id,
        'message' => array('not text'),
        'lines' => array(),
        'cart' => array(),
    )));
    assert_same(null, CartReceipt::fromArray(array(
        'id' => $id,
        'message' => 'تم.',
        'lines' => array(array('extension' => str_repeat('x', 70000))),
        'cart' => array(),
    )));
    assert_same(null, CartReceipt::fromArray(array(
        'id' => $id,
        'message' => 'تم.',
        'lines' => array(),
        'cart' => array('extension' => str_repeat('x', 600000)),
    )));
    assert_same(null, CartReceipt::fromArray(array(
        'id' => $id,
        'message' => 'تم.',
        'lines' => array(),
        'cart' => array(),
        'unexpected' => true,
    )));
});


test('CartQuantityPolicy permits safe reductions and rejects invalid increases', static function (): void {
    CartQuantityPolicy::assertAllowed(
        currentQuantity: 5,
        requestedQuantity: 2,
        soldIndividually: false,
        minimumQuantity: 1,
        maximumQuantity: 0,
        increaseAllowed: false,
        extensionApproved: true
    );

    assert_throws(InvalidArgumentException::class, static fn (): null => CartQuantityPolicy::assertAllowed(
        currentQuantity: 1,
        requestedQuantity: 2,
        soldIndividually: true,
        minimumQuantity: 1,
        maximumQuantity: 0,
        increaseAllowed: true,
        extensionApproved: true
    ), 'one at a time');
    assert_throws(InvalidArgumentException::class, static fn (): null => CartQuantityPolicy::assertAllowed(
        currentQuantity: 4,
        requestedQuantity: 2,
        soldIndividually: false,
        minimumQuantity: 3,
        maximumQuantity: 0,
        increaseAllowed: true,
        extensionApproved: true
    ), 'minimum');
    assert_throws(InvalidArgumentException::class, static fn (): null => CartQuantityPolicy::assertAllowed(
        currentQuantity: 2,
        requestedQuantity: 5,
        soldIndividually: false,
        minimumQuantity: 1,
        maximumQuantity: 4,
        increaseAllowed: true,
        extensionApproved: true
    ), 'maximum');
    assert_throws(InvalidArgumentException::class, static fn (): null => CartQuantityPolicy::assertAllowed(
        currentQuantity: 2,
        requestedQuantity: 3,
        soldIndividually: false,
        minimumQuantity: 1,
        maximumQuantity: 0,
        increaseAllowed: false,
        extensionApproved: true
    ), 'not currently purchasable');
    assert_throws(InvalidArgumentException::class, static fn (): null => CartQuantityPolicy::assertAllowed(
        currentQuantity: 2,
        requestedQuantity: 3,
        soldIndividually: false,
        minimumQuantity: 1,
        maximumQuantity: 0,
        increaseAllowed: true,
        extensionApproved: false
    ), 'store rule');
});

test('Settings preserve Unicode boundaries, reject unsupported thinking, and encrypt secrets', static function (): void {
    $GLOBALS['ysai_test_options'] = array();
    $box = new SecretBox();
    $settings = new Settings($box);
    $title = str_repeat('ع', 120);
    $guidance = str_repeat('م', 20020);

    $sanitized = $settings->sanitize(array(
        'gemini_api_key' => 'AIza-test-secret',
        'gemini_thinking_level' => 'minimal',
        'widget_title' => $title,
        'store_guidance' => $guidance,
    ));

    assert_same('low', $sanitized['gemini_thinking_level']);
    assert_same(100, Text::length((string) $sanitized['widget_title']));
    assert_same(20000, Text::length((string) $sanitized['store_guidance']));
    assert_same(1, preg_match('//u', (string) $sanitized['widget_title']));
    assert_same(1, preg_match('//u', (string) $sanitized['store_guidance']));
    assert_true($box->isEncrypted((string) $sanitized['gemini_api_key']));
    assert_same('AIza-test-secret', $settings->apiKey());
});

test('Settings canonicalize bounded catalog synonyms and preserve the last valid value on rejection', static function (): void {
    $GLOBALS['ysai_test_options'] = array();
    $settings = new Settings(new SecretBox());

    $valid = $settings->sanitize(array(
        'catalog_synonyms' => "حجاب, hijab\nshoe=sneaker",
    ));
    assert_same("حجاب | hijab\nshoe | sneaker", $valid['catalog_synonyms']);

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = $valid;
    $settings = new Settings(new SecretBox());
    $rejected = $settings->sanitize(array(
        'catalog_synonyms' => 'أ | ا',
    ));
    assert_same($valid['catalog_synonyms'], $rejected['catalog_synonyms']);
});

test('Settings fail closed when persisted catalog synonyms are malformed', static function (): void {
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = array(
        'catalog_synonyms' => "أ | ا\nsingle",
    );
    $settings = new Settings(new SecretBox());
    assert_same('', $settings->get('catalog_synonyms'));
});

test('Settings normalize a previously stored minimal thinking level to low', static function (): void {
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = array('gemini_thinking_level' => 'minimal');
    $settings = new Settings(new SecretBox());
    assert_same('low', $settings->get('gemini_thinking_level'));
});


test('Settings refuse to use an unencrypted database API key', static function (): void {
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = array('gemini_api_key' => 'legacy-plaintext-key');
    $settings = new Settings(new SecretBox());
    assert_throws(RuntimeException::class, static fn (): string => $settings->apiKey(), 'not encrypted');
});


test('PromptFactory prevents bounded catalog scans from being presented as exhaustive', static function (): void {
    $system = (new PromptFactory(new TestSettings()))->system(array());
    assert_true(str_contains($system, 'search_meta.results_truncated=true'));
    assert_true(str_contains($system, 'search_meta.scan_exhausted=false'));
    assert_true(str_contains($system, 'bounded shortlist'));
});

test('PromptFactory sends only the current image bytes and never reconstructs old uploads', static function (): void {
    $image = ImageInput::fromRequest(array(
        'mime_type' => 'image/png',
        'data' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Wl9sAAAAASUVORK5CYII=',
    ), true);
    assert_true($image instanceof ImageInput);

    $factory = new PromptFactory(new TestSettings());
    $history = $factory->history(array(array(
        'role' => 'user',
        'content' => 'صورة سابقة',
        'payload' => array(
            'image' => array(
                'mime_type' => 'image/png',
                'bytes' => 999,
                'width' => 10,
                'height' => 10,
                'data' => 'old-image-bytes-must-not-return',
            ),
        ),
    )), 'حلل هذه الصورة', null, $image);

    $encoded = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    assert_false(str_contains($encoded, 'old-image-bytes-must-not-return'));
    assert_same(1, substr_count($encoded, $image->base64));
    $last = $history[array_key_last($history)];
    assert_same('user_input', $last['type']);
    assert_same('image', $last['content'][1]['type']);
});

test('ToolContext keeps the most recently used bounded product references', static function (): void {
    $context = new ToolContext();
    $refs = array();
    for ($index = 0; $index < 120; $index += 1) {
        $ref = 'p_' . str_pad((string) $index, 12, 'a', STR_PAD_LEFT);
        $refs[$index] = $ref;
        $context->restoreProduct($ref, array(
            'id' => $index + 1,
            'parent_id' => 0,
            'type' => 'simple',
            'fingerprint' => hash('sha256', 'product-' . $index),
        ), array('name' => 'Product ' . $index));
    }

    // Registering an already known product is current-turn use, so the
    // existing opaque reference is refreshed and moved to the LRU tail.
    assert_same($refs[0], $context->registerProduct(array(
        'id' => 1,
        'parent_id' => 0,
        'type' => 'simple',
        'fingerprint' => hash('sha256', 'product-0'),
    ), array('name' => 'Product 0 refreshed')));

    for ($index = 120; $index < 130; $index += 1) {
        $ref = 'p_' . str_pad((string) $index, 12, 'a', STR_PAD_LEFT);
        $refs[$index] = $ref;
        $context->restoreProduct($ref, array(
            'id' => $index + 1,
            'parent_id' => 0,
            'type' => 'simple',
            'fingerprint' => hash('sha256', 'product-' . $index),
        ), array('name' => 'Product ' . $index));
    }

    assert_same(120, $context->productCount());
    assert_same('Product 0 refreshed', $context->product($refs[0])['card']['name']);
    assert_throws(InvalidArgumentException::class, static fn (): array => $context->product($refs[1]), 'stale');
    assert_same('Product 11', $context->product($refs[11])['card']['name']);
    assert_same(array_values(array_slice($refs, 125, 5, true)), array_keys($context->productSnapshot(5)));
});


test('VariationAttributeMatcher supports WooCommerce Any wildcards without accepting incomplete selections', static function (): void {
    $matcher = new VariationAttributeMatcher();
    $requested = array('color' => 'blue', 'size' => 'large');

    assert_true($matcher->matches($requested, array('color' => 'blue', 'size' => 'large')));
    assert_true($matcher->matches($requested, array('color' => '', 'size' => 'large')));
    assert_true($matcher->matches($requested, array('color' => '', 'size' => '')));
    assert_false($matcher->matches($requested, array('color' => 'red', 'size' => 'large')));
    assert_false($matcher->matches($requested, array('color' => 'blue')));
    assert_false($matcher->matches(array('color' => ''), array('color' => '')));
});

test('Settings reject scalar coercion and foreign merchant links', static function (): void {
    $GLOBALS['ysai_test_options'] = array();
    $settings = new Settings(new SecretBox());

    $sanitized = $settings->sanitize(array(
        'enabled' => array('1'),
        'allow_images' => 'true',
        'gemini_api_key' => array('AIza-should-not-coerce'),
        'gemini_model' => array('gemini-untrusted'),
        'widget_title' => array('Injected title'),
        'contact_url' => 'https://attacker.example/contact',
        'shipping_url' => 'https://shop.example.test/shipping',
    ));

    assert_same(0, $sanitized['enabled']);
    assert_same(0, $sanitized['allow_images']);
    assert_same('', $sanitized['gemini_api_key']);
    assert_same('gemini-3.7-flash', $sanitized['gemini_model']);
    assert_same('مساعدة متجر ياسين', $sanitized['widget_title']);
    assert_same('', $sanitized['contact_url']);
    assert_same('https://shop.example.test/shipping', $sanitized['shipping_url']);
});

test('Settings canonicalize trusted proxy networks and preserve the last valid configuration on rejection', static function (): void {
    $GLOBALS['ysai_test_options'] = array();
    $settings = new Settings(new SecretBox());

    $sanitized = $settings->sanitize(array(
        'trusted_proxy_header' => 'forwarded',
        'trusted_proxy_cidrs' => "10.1.2.3/8, 10.0.0.0/8\n2001:db8:abcd:1234::1/48",
    ));
    assert_same('forwarded', $sanitized['trusted_proxy_header']);
    assert_same("10.0.0.0/8\n2001:db8:abcd::/48", $sanitized['trusted_proxy_cidrs']);

    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = $sanitized;
    $settings = new Settings(new SecretBox());
    $rejected = $settings->sanitize(array(
        'trusted_proxy_header' => 'x-client-ip',
        'trusted_proxy_cidrs' => 'not-a-cidr',
    ));
    assert_same('forwarded', $rejected['trusted_proxy_header']);
    assert_same("10.0.0.0/8\n2001:db8:abcd::/48", $rejected['trusted_proxy_cidrs']);
});

test('Turn repository claim versions fence checkpoint, completion, and failure writes', static function (): void {
    $turns = new InMemoryTurnRepository();
    $claim = $turns->claim(
        '00000000-0000-4000-8000-000000000001',
        'turn_repository_fence_01',
        hash('sha256', 'request'),
        30
    );
    assert_same(1, $claim['claim_version']);
    assert_same(2, $turns->reclaim((int) $claim['id']));

    foreach (array(
        static fn (): null => $turns->checkpoint((int) $claim['id'], 1, array('ok' => true)),
        static fn (): null => $turns->complete((int) $claim['id'], 1, array('ok' => true)),
        static fn (): null => $turns->fail((int) $claim['id'], 1, 'failed', array('ok' => false)),
    ) as $write) {
        assert_throws(TurnLeaseLost::class, $write);
    }

    assert_same('processing', $turns->turns[(int) $claim['id']]['status']);
    assert_same(array(), $turns->turns[(int) $claim['id']]['response']);
});

test('Turn leases heartbeat, expire from their persisted duration, and fence late workers', static function (): void {
    $now = new DateTimeImmutable('2026-08-14T12:00:00+00:00');
    $turns = new InMemoryTurnRepository($now);
    $claim = $turns->claim(
        '00000000-0000-4000-8000-000000000001',
        'turn_persisted_lease_01',
        hash('sha256', 'persisted-lease'),
        300
    );
    $turnId = (int) $claim['id'];

    $turns->turns[$turnId]['updated_at'] = $now->modify('-299 seconds')->format(DATE_ATOM);
    assert_false($turns->expireStale($turnId, 1, 'turn_abandoned', array('ok' => false)));

    $turns->turns[$turnId]['updated_at'] = $now->modify('-301 seconds')->format(DATE_ATOM);
    assert_true($turns->expireStale(
        $turnId,
        1,
        'turn_abandoned',
        array('ok' => false, 'error' => array('code' => 'turn_abandoned'))
    ));
    assert_same('failed', $turns->turns[$turnId]['status']);
    assert_same(2, $turns->turns[$turnId]['claim_version']);
    assert_same('turn_abandoned', $turns->turns[$turnId]['error_code']);
    assert_throws(TurnLeaseLost::class, static fn (): null => $turns->heartbeat($turnId, 1));

    $fresh = $turns->claim(
        '00000000-0000-4000-8000-000000000001',
        'turn_heartbeat_lease_01',
        hash('sha256', 'heartbeat-lease'),
        120
    );
    $freshId = (int) $fresh['id'];
    $turns->turns[$freshId]['updated_at'] = $now->modify('-119 seconds')->format(DATE_ATOM);
    $turns->heartbeat($freshId, 1);
    assert_same($now->format(DATE_ATOM), $turns->turns[$freshId]['updated_at']);
    assert_false($turns->expireStale($freshId, 1, 'turn_abandoned', array('ok' => false)));
});


test('Expired turn owners cannot heartbeat, checkpoint, directly complete, or fail', static function (): void {
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $now = new DateTimeImmutable('2026-08-14T12:00:00+00:00');

    foreach (array('heartbeat', 'checkpoint', 'complete', 'fail') as $operation) {
        $turns = new InMemoryTurnRepository($now);
        $claim = $turns->claim(
            $conversationId,
            'expired_owner_' . $operation . '_01',
            hash('sha256', 'expired-owner-' . $operation),
            120
        );
        $turnId = (int) $claim['id'];
        $turns->turns[$turnId]['updated_at'] = $now->modify('-120 seconds')->format(DATE_ATOM);

        $write = match ($operation) {
            'heartbeat' => static fn (): null => $turns->heartbeat($turnId, 1),
            'checkpoint' => static fn (): null => $turns->checkpoint($turnId, 1, array('ok' => true)),
            'complete' => static fn (): null => $turns->complete($turnId, 1, array('ok' => true)),
            'fail' => static fn (): null => $turns->fail(
                $turnId,
                1,
                'request_failed',
                array('ok' => false, 'error' => array('code' => 'request_failed'))
            ),
        };

        assert_throws(TurnLeaseLost::class, $write, 'expired');
        assert_same('processing', $turns->turns[$turnId]['status']);
        assert_same(array(), $turns->turns[$turnId]['response']);
    }
});

test('A stale checkpoint can only finalize by adding its exact assistant message identifier', static function (): void {
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $now = new DateTimeImmutable('2026-08-14T12:00:00+00:00');
    $checkpoint = array(
        'ok' => true,
        'conversation_id' => $conversationId,
        'client_turn_id' => 'checkpoint_finalize_01',
        'turn_id' => 1,
        'turn_finalized' => false,
        'kind' => 'answer',
        'message' => 'إجابة محفوظة.',
        'products' => array(),
        'cart' => null,
        'receipt' => null,
        '_message_payload' => array('kind' => 'answer'),
        '_http_status' => 200,
    );

    $turns = new InMemoryTurnRepository($now);
    $claim = $turns->claim(
        $conversationId,
        'checkpoint_finalize_01',
        hash('sha256', 'checkpoint-finalize'),
        120
    );
    $turnId = (int) $claim['id'];
    $checkpoint['turn_id'] = $turnId;
    $turns->checkpoint($turnId, 1, $checkpoint);
    $turns->turns[$turnId]['updated_at'] = $now->modify('-600 seconds')->format(DATE_ATOM);

    $final = $checkpoint;
    $final['message_id'] = 77;
    $final['turn_finalized'] = true;
    assert_throws(
        TurnLeaseLost::class,
        static fn (): null => $turns->complete($turnId, 1, $final),
        'cannot be replaced'
    );
    assert_same('processing', $turns->turns[$turnId]['status']);
    assert_same($checkpoint, $turns->turns[$turnId]['response']);

    $final['turn_finalized'] = false;
    $turns->complete($turnId, 1, $final);
    assert_same('completed', $turns->turns[$turnId]['status']);
    assert_same($final, $turns->turns[$turnId]['response']);

    $immutable = new InMemoryTurnRepository($now);
    $other = $immutable->claim(
        $conversationId,
        'checkpoint_immutable_01',
        hash('sha256', 'checkpoint-immutable'),
        120
    );
    $otherId = (int) $other['id'];
    $otherCheckpoint = $checkpoint;
    $otherCheckpoint['client_turn_id'] = 'checkpoint_immutable_01';
    $otherCheckpoint['turn_id'] = $otherId;
    $immutable->checkpoint($otherId, 1, $otherCheckpoint);
    $immutable->turns[$otherId]['updated_at'] = $now->modify('-600 seconds')->format(DATE_ATOM);

    $mutated = $otherCheckpoint;
    $mutated['message'] = 'تم تغيير الإجابة.';
    $mutated['message_id'] = 88;
    assert_throws(
        TurnLeaseLost::class,
        static fn (): null => $immutable->complete($otherId, 1, $mutated),
        'cannot be replaced'
    );
    assert_throws(
        TurnLeaseLost::class,
        static fn (): null => $immutable->fail(
            $otherId,
            1,
            'request_failed',
            array('ok' => false, 'error' => array('code' => 'request_failed'))
        ),
        'checkpointed'
    );
    assert_same('processing', $immutable->turns[$otherId]['status']);
    assert_same($otherCheckpoint, $immutable->turns[$otherId]['response']);
});

test('WpTurnRepository rejects malformed turn identities before touching storage', static function (): void {
    $repository = new WpTurnRepository(new TestClock());
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $clientTurnId = 'turn_repository_valid_01';
    $requestHash = hash('sha256', 'request');

    foreach (array(
        static fn (): array => $repository->claim('not-a-uuid', $clientTurnId, $requestHash, 120),
        static fn (): array => $repository->claim($conversationId, 'short', $requestHash, 120),
        static fn (): array => $repository->claim($conversationId, $clientTurnId, strtoupper($requestHash), 120),
        static fn (): array => $repository->claim($conversationId, $clientTurnId, $requestHash, 29),
        static fn (): ?array => $repository->find($conversationId, 'bad'),
    ) as $operation) {
        assert_throws(InvalidArgumentException::class, $operation);
    }
});

test('WpConversationRepository rejects malformed boundaries before touching storage', static function (): void {
    $repository = new WpConversationRepository(new TestClock(), new TokenHasher());
    $conversationId = '00000000-0000-4000-8000-000000000001';
    $token = str_repeat('A', 43);

    foreach (array(
        static fn (): array => $repository->create(0),
        static fn (): array => $repository->create(366),
        static fn (): ?array => $repository->authenticate('not-a-uuid', $token),
        static fn (): ?array => $repository->authenticate($conversationId, 'short'),
        static fn (): array => $repository->messages('not-a-uuid'),
        static fn (): array => $repository->messages($conversationId, 80, 0),
        static fn (): ?array => $repository->message('not-a-uuid', 1),
        static fn (): int => $repository->appendMessage('not-a-uuid', 1, 'user', 'valid'),
        static fn (): int => $repository->appendMessage($conversationId, 0, 'user', 'valid'),
        static fn (): int => $repository->appendMessage($conversationId, 1, 'invalid', 'valid'),
        static fn (): null => $repository->touch($conversationId, 0),
        static fn (): array => $repository->memory('not-a-uuid'),
        static fn (): null => $repository->updateMemoryForTurn('not-a-uuid', 1, 1, array()),
        static fn (): array => $repository->exportPage('not-a-uuid'),
        static fn (): null => $repository->delete('not-a-uuid'),
    ) as $operation) {
        assert_throws(InvalidArgumentException::class, $operation);
    }
    assert_throws(
        LengthException::class,
        static fn (): int => $repository->appendMessage($conversationId, 1, 'user', '')
    );
});

test('RequestIdentity cannot be bypassed by rotating User-Agent or IPv6 privacy addresses', static function (): void {
    $previous = $_SERVER;
    try {
        $identity = new RequestIdentity();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SERVER['HTTP_USER_AGENT'] = 'agent-one';
        $ipv4 = $identity->browserBucket();
        $_SERVER['HTTP_USER_AGENT'] = 'agent-two';
        assert_same($ipv4, $identity->browserBucket());

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        assert_false(hash_equals($ipv4, $identity->browserBucket()));

        $_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12::1';
        $ipv6 = $identity->browserBucket();
        $_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12:ffff:eeee:dddd:cccc';
        assert_same($ipv6, $identity->browserBucket());
        $_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:13::1';
        assert_false(hash_equals($ipv6, $identity->browserBucket()));

        $_SERVER['REMOTE_ADDR'] = '::ffff:203.0.113.9';
        assert_same($ipv4, $identity->browserBucket());
    } finally {
        $_SERVER = $previous;
    }
});

test('ImageInput rejects decompression-heavy images above the total pixel budget', static function (): void {
    $canonical = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Wl9sAAAAASUVORK5CYII=';
    $bytes = base64_decode($canonical, true);
    assert_true(is_string($bytes));
    $bytes = substr_replace($bytes, pack('N', 4000), 16, 4);
    $bytes = substr_replace($bytes, pack('N', 4000), 20, 4);

    assert_throws(InvalidArgumentException::class, static fn (): ?ImageInput => ImageInput::fromRequest(array(
        'mime_type' => 'image/png',
        'data' => base64_encode($bytes),
    ), true), 'dimensions');
});

test('BoundedJson rejects oversized, fragmented, deeply nested, and non-finite persistence payloads', static function (): void {
    $value = array('message' => 'مرحبًا', 'items' => array(1, true, null));
    $json = BoundedJson::encode($value, 1024, 'encode failed');
    assert_same($value, BoundedJson::decode($json, 1024, 'decode failed'));
    assert_same(array(), BoundedJson::decode('', 1024, 'decode failed', true));

    assert_throws(LengthException::class, static fn (): string => BoundedJson::encode(
        array('value' => str_repeat('x', 200)),
        64,
        'oversized'
    ));
    assert_throws(LengthException::class, static fn (): array => BoundedJson::decode(
        str_repeat('x', 65),
        64,
        'oversized'
    ));

    $deep = 'leaf';
    for ($depth = 0; $depth < 40; ++$depth) {
        $deep = array('next' => $deep);
    }
    assert_throws(LengthException::class, static fn (): string => BoundedJson::encode(
        array('deep' => $deep),
        4096,
        'deep'
    ));
    assert_throws(UnexpectedValueException::class, static fn (): string => BoundedJson::encode(
        array('number' => INF),
        1024,
        'number'
    ));
    assert_throws(UnexpectedValueException::class, static fn (): string => BoundedJson::encode(
        array('object' => new stdClass()),
        1024,
        'object'
    ));
});

test('BoundedJson rejects duplicate persisted object keys before hydration', static function (): void {
    foreach (array(
        '{"message":"first","message":"second"}',
        '{"memory":{"budget":10,"\u0062udget":20}}',
    ) as $json) {
        assert_throws(
            RuntimeException::class,
            static fn (): array => BoundedJson::decode($json, 1024, 'duplicate persisted JSON'),
            'duplicate persisted JSON'
        );
    }
});

test('Settings normalize corrupt persisted values before application code reads them', static function (): void {
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = array(
        'enabled' => array('1'),
        'allow_images' => 'true',
        'rate_limit_turns' => '999999',
        'daily_ai_turn_limit' => '1500',
        'daily_conversation_limit' => '7000',
        'gemini_model' => array('malformed'),
        'gemini_thinking_level' => 'unsupported',
        'widget_position' => array('left'),
        'widget_title' => array('Injected'),
        'contact_url' => 'https://attacker.example/contact',
        'shipping_url' => 'https://shop.example.test/shipping',
        'trusted_proxy_header' => 'x-client-ip',
        'trusted_proxy_cidrs' => 'not-a-cidr',
    );

    $settings = new Settings(new SecretBox());
    assert_same(1, $settings->get('enabled'));
    assert_same(1, $settings->get('allow_images'));
    assert_same(40, $settings->get('rate_limit_turns'));
    assert_same(1500, $settings->get('daily_ai_turn_limit'));
    assert_same(7000, $settings->get('daily_conversation_limit'));
    assert_same('gemini-3.7-flash', $settings->get('gemini_model'));
    assert_same('medium', $settings->get('gemini_thinking_level'));
    assert_same('right', $settings->get('widget_position'));
    assert_same('مساعدة متجر ياسين', $settings->get('widget_title'));
    assert_same('', $settings->get('contact_url'));
    assert_same('https://shop.example.test/shipping', $settings->get('shipping_url'));
    assert_same('x-forwarded-for', $settings->get('trusted_proxy_header'));
    assert_same('', $settings->get('trusted_proxy_cidrs'));
});

test('TurnTimingPolicy keeps browser aborts inside the exact durable lease', static function (): void {
    $policy = YassinStore\AiAssistant\Application\Chat\TurnTimingPolicy::class;
    foreach (array(
        array(10, 2, 120, 75),
        array(35, 6, 305, 290),
        array(90, 10, 1050, 1035),
        array(-1, -1, 120, 75),
        array(999, 999, 1050, 1035),
    ) as [$providerTimeout, $rounds, $expectedLease, $expectedBrowser]) {
        $lease = $policy::leaseSeconds($providerTimeout, $rounds);
        $browser = $policy::browserTimeoutSeconds($providerTimeout, $rounds);
        assert_same($expectedLease, $lease);
        assert_same($expectedBrowser, $browser);
        assert_true($browser <= $lease - $policy::RECOVERY_MARGIN_SECONDS);
    }
});
