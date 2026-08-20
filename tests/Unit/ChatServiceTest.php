<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Chat\AgentLoop;
use YassinStore\AiAssistant\Application\Chat\ChatService;
use YassinStore\AiAssistant\Application\Chat\IntentVerifier;
use YassinStore\AiAssistant\Application\Chat\PromptFactory;
use YassinStore\AiAssistant\Application\Chat\PublicException;
use YassinStore\AiAssistant\Application\Contract\AiProvider;
use YassinStore\AiAssistant\Application\Contract\CatalogGateway;
use YassinStore\AiAssistant\Application\Contract\RuntimeSettings;
use YassinStore\AiAssistant\Application\Tool\ShoppingMemoryPolicy;
use YassinStore\AiAssistant\Application\Tool\ToolRegistry;
use YassinStore\AiAssistant\Infrastructure\Ai\ProviderException;
use YassinStore\AiAssistant\Infrastructure\Security\RequestIdentity;

/**
 * @return array{
 *   service:ChatService,
 *   provider:AiProvider,
 *   conversations:InMemoryConversationRepository,
 *   turns:InMemoryTurnRepository,
 *   cart:TestCart,
 *   rate_limiter:AllowAllRateLimiter,
 *   credentials:\YassinStore\AiAssistant\Domain\Conversation\ConversationCredentials
 * }
 */
function build_chat_service_test(
    ?AiProvider $provider = null,
    ?InMemoryTurnRepository $turns = null,
    ?InMemoryConversationRepository $conversations = null,
    ?RuntimeSettings $settings = null,
    ?AllowAllRateLimiter $rateLimiter = null,
    ?CatalogGateway $catalog = null
): array {
    $provider ??= new ScriptedAiProvider(array(array(
        'steps' => array(array(
            'type' => 'function_call',
            'id' => 'answer',
            'name' => 'respond_answer',
            'arguments' => array('message' => 'إجابة موثوقة.'),
        )),
    )));
    $settings ??= new TestSettings();
    $rateLimiter ??= new AllowAllRateLimiter();
    $clock = new TestClock();
    $conversations ??= new InMemoryConversationRepository($clock);
    $credentials = $conversations->seed();
    $turns ??= new InMemoryTurnRepository();
    $conversations->turnRepository = $turns;
    $cart = new TestCart();
    $catalog ??= new TestCatalog(array(new TestProduct(1, 'Test product', 10.0)));
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
    $service = new ChatService(
        $conversations,
        $turns,
        $rateLimiter,
        $cart,
        $agent,
        $settings,
        new RequestIdentity(),
        $clock,
        test_logger()
    );

    return array_merge(
        compact('service', 'provider', 'conversations', 'turns', 'cart', 'credentials'),
        array('rate_limiter' => $rateLimiter)
    );
}

/** @return array<string,mixed> */
function chat_request_for(object $credentials, string $clientTurnId = 'turn_1234567890123456', string $message = 'ساعدني في الاختيار'): array
{
    return array(
        'conversation_id' => $credentials->id,
        'token' => $credentials->token,
        'client_turn_id' => $clientTurnId,
        'message' => $message,
    );
}


test('ChatService exposes actionable provider categories instead of the blanket AI failure', static function (): void {
    $cases = array(
        array(
            'provider_configuration_error',
            'إعداد أدوات المحادثة في خدمة الذكاء الاصطناعي غير صالح. أبلغ إدارة المتجر.',
            503,
        ),
        array(
            'provider_request_rejected',
            'رفضت خدمة الذكاء الاصطناعي إعداد طلب المحادثة. أبلغ إدارة المتجر للتحقق من النموذج والاتصال.',
            502,
        ),
        array(
            'provider_access_denied',
            'لا يملك مشروع أو مفتاح خدمة الذكاء الاصطناعي صلاحية استخدام الخدمة. أبلغ إدارة المتجر.',
            502,
        ),
        array(
            'provider_location_restricted',
            'خدمة الذكاء الاصطناعي غير متاحة للمشروع أو موقع الطلب الحالي. أبلغ إدارة المتجر.',
            502,
        ),
        array(
            'provider_quota_exhausted',
            'تم استهلاك حصة خدمة الذكاء الاصطناعي أو حدها المؤقت. أعد المحاولة لاحقًا أو أبلغ إدارة المتجر.',
            503,
        ),
        array(
            'provider_model_unavailable',
            'النموذج المحدد لخدمة الذكاء الاصطناعي غير متاح. أبلغ إدارة المتجر للتحقق من اسم النموذج.',
            502,
        ),
        array(
            'provider_protocol_error',
            'أعادت خدمة الذكاء الاصطناعي استجابة غير متوافقة. لم يُنفّذ أي إجراء غير موثّق.',
            502,
        ),
        array(
            'provider_error',
            'رفضت خدمة الذكاء الاصطناعي الطلب لسبب غير مصنّف. أبلغ إدارة المتجر.',
            502,
        ),
    );

    foreach ($cases as [$code, $message, $status]) {
        $provider = new ScriptedAiProvider(array(new ProviderException('internal provider detail', $code, $status)));
        $built = build_chat_service_test(provider: $provider);
        $result = $built['service']->chat(chat_request_for(
            $built['credentials'],
            'turn_provider_category_' . substr(hash('sha256', $code), 0, 12)
        ));

        assert_same($status, $result['status']);
        assert_same(false, $result['body']['ok']);
        assert_same($code, $result['body']['error']['code']);
        assert_same($message, $result['body']['error']['message']);
        assert_false(str_contains($result['body']['error']['message'], 'تعذّر إكمال الرد عبر خدمة الذكاء الاصطناعي'));
        assert_same(true, $result['body']['turn_finalized']);
    }
});

test('ChatService replays a completed turn without invoking the provider again', static function (): void {
    $built = build_chat_service_test();
    $request = chat_request_for($built['credentials']);

    $first = $built['service']->chat($request);
    $second = $built['service']->chat($request);

    assert_same(200, $first['status']);
    assert_same(true, $first['body']['ok']);
    assert_true((int) ($first['body']['message_id'] ?? 0) > 0);
    assert_false(array_key_exists('replayed', $first['body']));
    assert_same(200, $second['status']);
    assert_same(true, $second['body']['replayed']);
    assert_same($first['body']['message_id'], $second['body']['message_id']);
    assert_same(1, $built['provider']->interactCalls);
    assert_count_value(2, $built['conversations']->storedMessages);
    assert_same('user', $built['conversations']->storedMessages[0]['role']);
    assert_same('assistant', $built['conversations']->storedMessages[1]['role']);
    assert_same('completed', $built['turns']->turns[1]['status']);
    assert_same(2, $built['conversations']->touchCalls);
});

test('ChatService falls back to direct completion when checkpoint persistence fails', static function (): void {
    $turns = new InMemoryTurnRepository();
    $turns->failCheckpoint = true;
    $built = build_chat_service_test(turns: $turns);
    $request = chat_request_for($built['credentials'], 'turn_checkpoint_fail_01');

    $first = $built['service']->chat($request);
    $second = $built['service']->chat($request);

    assert_same(200, $first['status']);
    assert_same(true, $first['body']['ok']);
    assert_same(1, $turns->checkpointCalls);
    assert_same(1, $turns->completeCalls);
    assert_same(1, $turns->attachTerminalMessageIdCalls);
    assert_same('completed', $turns->turns[1]['status']);
    assert_true((int) ($first['body']['message_id'] ?? 0) > 0);
    assert_same($first['body']['message_id'], $second['body']['message_id']);
    assert_same(true, $second['body']['replayed']);
    assert_same(1, $built['provider']->interactCalls);
});

test('ChatService never publishes an undurable success when checkpoint and completion both fail', static function (): void {
    $turns = new InMemoryTurnRepository();
    $turns->failCheckpoint = true;
    $turns->failComplete = true;
    $built = build_chat_service_test(turns: $turns);
    $request = chat_request_for($built['credentials'], 'turn_persistence_uncertain_01');

    $result = $built['service']->chat($request);

    assert_same(503, $result['status']);
    assert_same(false, $result['body']['ok']);
    assert_same('turn_persistence_uncertain', $result['body']['error']['code']);
    assert_same(true, $result['body']['error']['retryable']);
    assert_same('same_turn', $result['body']['error']['retry_mode']);
    assert_same(false, $result['body']['turn_finalized']);
    assert_same($request['client_turn_id'], $result['body']['client_turn_id']);
    assert_same(1, $result['body']['turn_id']);
    assert_same(1, $turns->checkpointCalls);
    assert_same(2, $turns->completeCalls);
    assert_same('processing', $turns->turns[1]['status']);
    assert_same(array(), $turns->turns[1]['response']);
    assert_count_value(1, $built['conversations']->storedMessages);
    assert_same('user', $built['conversations']->storedMessages[0]['role']);
    assert_same(1, $built['provider']->interactCalls);

    $recovery = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );
    assert_same(202, $recovery['status']);
    assert_same('processing', $recovery['body']['status']);
});

test('ChatService recovers a checkpointed success after final completion initially fails', static function (): void {
    $turns = new InMemoryTurnRepository();
    $turns->failComplete = true;
    $built = build_chat_service_test(turns: $turns);
    $request = chat_request_for($built['credentials'], 'turn_complete_fail_001');

    $first = $built['service']->chat($request);
    assert_same(503, $first['status']);
    assert_same(false, $first['body']['ok']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);
    assert_same(true, $first['body']['error']['retryable']);
    assert_same(false, $first['body']['turn_finalized']);
    assert_same($request['client_turn_id'], $first['body']['client_turn_id']);
    assert_same(1, $first['body']['turn_id']);
    assert_same('processing', $turns->turns[1]['status']);
    assert_true($turns->turns[1]['response'] !== array());
    assert_count_value(2, $built['conversations']->storedMessages);

    $turns->failComplete = false;
    $recovered = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(200, $recovered['status']);
    assert_same(true, $recovered['body']['replayed']);
    assert_same('completed', $turns->turns[1]['status']);
    assert_count_value(2, $built['conversations']->storedMessages);
    assert_same(1, $built['provider']->interactCalls);
});

test('ChatService retains a checkpointed turn until its assistant message is durable', static function (): void {
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $conversations->failAssistantAppend = true;
    $built = build_chat_service_test(conversations: $conversations);
    $request = chat_request_for($built['credentials'], 'turn_message_pending01');

    $first = $built['service']->chat($request);

    assert_same(503, $first['status']);
    assert_same(false, $first['body']['ok']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);
    assert_same(false, $first['body']['turn_finalized']);
    assert_same($request['client_turn_id'], $first['body']['client_turn_id']);
    assert_same(1, $first['body']['turn_id']);
    assert_same('processing', $built['turns']->turns[1]['status']);
    assert_true($built['turns']->turns[1]['response'] !== array());
    assert_count_value(1, $conversations->storedMessages);
    assert_same('user', $conversations->storedMessages[0]['role']);

    $conversations->failAssistantAppend = false;
    $recovered = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(200, $recovered['status']);
    assert_same(true, $recovered['body']['ok']);
    assert_same(true, $recovered['body']['replayed']);
    assert_same(true, $recovered['body']['turn_finalized']);
    assert_true((int) ($recovered['body']['message_id'] ?? 0) > 0);
    assert_same('completed', $built['turns']->turns[1]['status']);
    assert_count_value(2, $conversations->storedMessages);
    assert_same(1, $built['provider']->interactCalls);
});

test('ChatService converts an unexpected provider failure into a durable generic failure', static function (): void {
    $provider = new ScriptedAiProvider(array(new RuntimeException('secret provider detail')));
    $built = build_chat_service_test(provider: $provider);
    $request = chat_request_for($built['credentials'], 'turn_provider_failure1');

    $result = $built['service']->chat($request);

    assert_same(500, $result['status']);
    assert_same(false, $result['body']['ok']);
    assert_same('request_failed', $result['body']['error']['code']);
    assert_false(str_contains((string) $result['body']['error']['message'], 'secret provider detail'));
    assert_same(false, $result['body']['error']['retryable']);
    assert_same('none', $result['body']['error']['retry_mode']);
    assert_same('failed', $built['turns']->turns[1]['status']);
    assert_same(1, $built['turns']->failCalls);
    assert_true((int) ($result['body']['message_id'] ?? 0) > 0);
    assert_same($result['body']['message_id'], $built['turns']->turns[1]['response']['message_id']);
    assert_count_value(2, $built['conversations']->storedMessages);
    assert_same('assistant', $built['conversations']->storedMessages[1]['role']);
    assert_same(2, $built['conversations']->touchCalls);

    $replayed = $built['service']->chat($request);
    assert_same(500, $replayed['status']);
    assert_same(true, $replayed['body']['replayed']);
    assert_same($result['body']['message_id'], $replayed['body']['message_id']);
    assert_same(1, $provider->interactCalls);
    assert_count_value(2, $built['conversations']->storedMessages);
    assert_same(2, $built['conversations']->touchCalls);
});

test('ChatService preserves the original turn when failure persistence is uncertain', static function (): void {
    $provider = new ScriptedAiProvider(array(new RuntimeException('secret provider detail')));
    $turns = new InMemoryTurnRepository();
    $turns->failFail = true;
    $built = build_chat_service_test(provider: $provider, turns: $turns);
    $request = chat_request_for($built['credentials'], 'turn_failure_uncertain1');

    $result = $built['service']->chat($request);

    assert_same(503, $result['status']);
    assert_same(false, $result['body']['ok']);
    assert_same('turn_persistence_uncertain', $result['body']['error']['code']);
    assert_same(true, $result['body']['error']['retryable']);
    assert_same('same_turn', $result['body']['error']['retry_mode']);
    assert_same(false, $result['body']['turn_finalized']);
    assert_same($request['client_turn_id'], $result['body']['client_turn_id']);
    assert_same(1, $result['body']['turn_id']);
    assert_same(1, $turns->failCalls);
    assert_same('processing', $turns->turns[1]['status']);
    assert_same(array(), $turns->turns[1]['response']);
    assert_count_value(1, $built['conversations']->storedMessages);
    assert_same('user', $built['conversations']->storedMessages[0]['role']);
    assert_same(1, $provider->interactCalls);

    $recovery = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );
    assert_same(202, $recovery['status']);
    assert_same('processing', $recovery['body']['status']);
    assert_same(1, $provider->interactCalls);
});

test('ChatService rejects reuse of a turn ID for different request content', static function (): void {
    $built = build_chat_service_test();
    $request = chat_request_for($built['credentials'], 'turn_conflict_1234567', 'الطلب الأول');
    $built['service']->chat($request);

    $error = assert_throws(PublicException::class, static function () use ($built, $request): void {
        $changed = $request;
        $changed['message'] = 'طلب مختلف';
        $built['service']->chat($changed);
    });

    assert_true($error instanceof PublicException);
    assert_same('turn_id_conflict', $error->publicCode);
    assert_same(409, $error->httpStatus);
    assert_same(1, $built['provider']->interactCalls);
});

test('ChatService exports a stable conversation through bounded cursor pages', static function (): void {
    $built = build_chat_service_test();
    for ($index = 1; $index <= 205; $index += 1) {
        $built['conversations']->appendMessage(
            $built['credentials']->id,
            10_000 + $index,
            'user',
            'message-' . $index
        );
    }

    $first = $built['service']->export(
        $built['credentials']->id,
        $built['credentials']->token,
        0,
        0,
        200
    );
    assert_same(200, $first['status']);
    assert_same(false, $first['body']['complete']);
    assert_count_value(200, $first['body']['messages']);
    assert_same(205, $first['body']['upper_message_id']);
    assert_same(200, $first['body']['next_after_message_id']);

    $built['conversations']->appendMessage(
        $built['credentials']->id,
        20_000,
        'user',
        'message-added-during-export'
    );

    $second = $built['service']->export(
        $built['credentials']->id,
        $built['credentials']->token,
        (int) $first['body']['next_after_message_id'],
        (int) $first['body']['upper_message_id'],
        200
    );
    assert_same(true, $second['body']['complete']);
    assert_same(null, $second['body']['next_after_message_id']);
    assert_count_value(5, $second['body']['messages']);
    assert_same('message-205', $second['body']['messages'][4]['content']);
});

test('ChatService rejects an export cursor beyond its fixed boundary', static function (): void {
    $built = build_chat_service_test();
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->export(
        $built['credentials']->id,
        $built['credentials']->token,
        11,
        10,
        200
    ));
    assert_same('invalid_export_cursor', $error->publicCode);
});

test('ChatService boot rejects malformed saved credentials instead of silently replacing them', static function (): void {
    $built = build_chat_service_test();
    $conversationCount = count($built['conversations']->conversations);

    $invalidId = assert_throws(PublicException::class, static fn (): array => $built['service']->boot(
        'not-a-conversation-id',
        $built['credentials']->token
    ));
    assert_true($invalidId instanceof PublicException);
    assert_same('invalid_conversation_id', $invalidId->publicCode);
    assert_same(422, $invalidId->httpStatus);

    $invalidToken = assert_throws(PublicException::class, static fn (): array => $built['service']->boot(
        $built['credentials']->id,
        'short'
    ));
    assert_true($invalidToken instanceof PublicException);
    assert_same('invalid_conversation_token', $invalidToken->publicCode);
    assert_same(422, $invalidToken->httpStatus);
    assert_same($conversationCount, count($built['conversations']->conversations));
});

test('ChatService applies a bounded site-wide quota only to new conversation creation', static function (): void {
    $limiter = new AllowAllRateLimiter();
    $built = build_chat_service_test(rateLimiter: $limiter);

    $created = $built['service']->boot(null, null);
    assert_same(200, $created['status']);
    assert_false(hash_equals($built['credentials']->id, (string) $created['body']['conversation']['id']));

    $creationCalls = array_values(array_filter(
        $limiter->calls,
        static fn (array $call): bool => $call['scope'] === 'global_daily_conversation_creations'
    ));
    assert_count_value(1, $creationCalls);
    assert_same('2026-08-14', $creationCalls[0]['identifier']);
    assert_same(5000, $creationCalls[0]['limit']);
    assert_same(86400, $creationCalls[0]['window_seconds']);

    $limiter->calls = array();
    $restored = $built['service']->boot($built['credentials']->id, $built['credentials']->token);
    assert_same(200, $restored['status']);
    assert_count_value(0, array_filter(
        $limiter->calls,
        static fn (array $call): bool => $call['scope'] === 'global_daily_conversation_creations'
    ));
});

test('ChatService fails closed before storage when the daily conversation quota is exhausted', static function (): void {
    $limiter = new AllowAllRateLimiter();
    $limiter->deniedScopes = array('global_daily_conversation_creations');
    $built = build_chat_service_test(rateLimiter: $limiter);
    $conversationCount = count($built['conversations']->conversations);

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->boot(null, null));

    assert_same('rate_limited', $error->publicCode);
    assert_same(429, $error->httpStatus);
    assert_true(is_int($error->retryAfterSeconds));
    assert_true($error->retryAfterSeconds >= 1 && $error->retryAfterSeconds <= 86_400);
    assert_same($conversationCount, count($built['conversations']->conversations));
});

test('ChatService boot remains usable when the WooCommerce cart session cannot be read', static function (): void {
    $built = build_chat_service_test();
    $built['cart']->failView = true;

    $result = $built['service']->boot($built['credentials']->id, $built['credentials']->token);

    assert_same(200, $result['status']);
    assert_same(true, $result['body']['ok']);
    assert_same(false, $result['body']['cart_available']);
    assert_same(null, $result['body']['cart']);
    assert_true((string) $result['body']['cart_notice'] !== '');
});

test('ChatService reports malformed image input as a rejected client request', static function (): void {
    $built = build_chat_service_test();
    $request = chat_request_for($built['credentials'], 'turn_invalid_image01');
    $request['image'] = array('mime_type' => 'image/png', 'data' => 'not-an-image');

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($request));

    assert_same('invalid_image', $error->publicCode);
    assert_same(422, $error->httpStatus);
    assert_same(0, $built['turns']->claimCalls);
});

test('ChatService applies the coarse browser limit before reply lookup or image decoding', static function (): void {
    $rateLimiter = new AllowAllRateLimiter();
    $rateLimiter->deniedScopes[] = 'browser_requests';
    $built = build_chat_service_test(rateLimiter: $rateLimiter);
    $request = chat_request_for($built['credentials'], 'turn_coarse_limit_first_01');
    $request['reply'] = array('message_id' => 999999, 'product_ref' => 'p_invalidinvalid1');
    $request['image'] = array('mime' => 'image/png', 'data' => 'not-base64');

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($request));

    assert_same('rate_limited', $error->publicCode);
    assert_same(429, $error->httpStatus);
    assert_true(is_int($error->retryAfterSeconds));
    assert_true($error->retryAfterSeconds >= 1 && $error->retryAfterSeconds <= 300);
    assert_same('browser_requests', $rateLimiter->calls[0]['scope']);
    assert_same(0, $built['turns']->claimCalls);
    assert_count_value(0, $built['conversations']->storedMessages);
});

test('ChatService rejects unbound reply payloads before claiming a turn', static function (): void {
    $built = build_chat_service_test();

    $invalidMessage = chat_request_for($built['credentials'], 'turn_invalid_message1');
    $invalidMessage['message'] = array('unexpected');
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($invalidMessage));
    assert_same('invalid_message', $error->publicCode);

    $textOnly = chat_request_for($built['credentials'], 'turn_invalid_reply_01');
    $textOnly['reply'] = array('text' => 'سياق اخترعه المتصفح');
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($textOnly));
    assert_same('invalid_reply_context', $error->publicCode);

    $stringMessageId = chat_request_for($built['credentials'], 'turn_invalid_reply_02');
    $stringMessageId['reply'] = array('message_id' => '12');
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($stringMessageId));
    assert_same('invalid_reply_context', $error->publicCode);

    $missingMessage = chat_request_for($built['credentials'], 'turn_invalid_reply_03');
    $missingMessage['reply'] = array('message_id' => 999);
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($missingMessage));
    assert_same('invalid_reply_context', $error->publicCode);

    $userMessageId = $built['conversations']->appendMessage(
        $built['credentials']->id,
        900,
        'user',
        'رسالة مستخدم لا تصلح كسياق رد'
    );
    $userMessage = chat_request_for($built['credentials'], 'turn_invalid_reply_04');
    $userMessage['reply'] = array('message_id' => $userMessageId);
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($userMessage));
    assert_same('invalid_reply_context', $error->publicCode);

    assert_same(0, $built['turns']->claimCalls);
});

test('ChatService resolves reply text and product identity from the stored assistant message', static function (): void {
    $provider = new ScriptedAiProvider(array(array(
        'steps' => array(array(
            'type' => 'function_call',
            'id' => 'reply-answer',
            'name' => 'respond_answer',
            'arguments' => array('message' => 'تم فهم الرد.'),
        )),
    )));
    $built = build_chat_service_test(provider: $provider);
    $productRef = 'p_abcdefgh12345678';
    $messageId = $built['conversations']->appendMessage(
        $built['credentials']->id,
        901,
        'assistant',
        'هل تريد إضافة المنتج بكمية واحدة؟',
        array(
            'products' => array(array('ref' => $productRef, 'name' => 'منتج موثوق')),
            '_product_context' => array($productRef => array(
                'identity' => array('id' => 1, 'parent_id' => 0, 'type' => 'simple', 'fingerprint' => str_repeat('a', 64)),
                'card' => array('name' => 'منتج موثوق'),
            )),
        )
    );

    $request = chat_request_for($built['credentials'], 'turn_bound_reply_001', 'نعم، أضفه');
    $request['reply'] = array('message_id' => $messageId, 'product_ref' => $productRef);
    $result = $built['service']->chat($request);

    assert_same(200, $result['status']);
    $history = $provider->histories[0];
    $current = $history[array_key_last($history)];
    $payload = json_decode((string) $current['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR);
    assert_same($messageId, $payload['reply_context']['message_id']);
    assert_same('هل تريد إضافة المنتج بكمية واحدة؟', $payload['reply_context']['text']);
    assert_same($productRef, $payload['reply_context']['product_ref']);
});

test('ChatService rejects a product reference not displayed by the referenced assistant message', static function (): void {
    $built = build_chat_service_test();
    $messageId = $built['conversations']->appendMessage(
        $built['credentials']->id,
        902,
        'assistant',
        'اختر منتجًا من القائمة.',
        array('products' => array(array('ref' => 'p_abcdefgh12345678')))
    );
    $request = chat_request_for($built['credentials'], 'turn_wrong_product_01');
    $request['reply'] = array('message_id' => $messageId, 'product_ref' => 'p_zxywvuts12345678');

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($request));
    assert_same('invalid_reply_context', $error->publicCode);
    assert_same(0, $built['turns']->claimCalls);
});

test('ChatService requires a fixed export boundary after the first page', static function (): void {
    $built = build_chat_service_test();
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->export(
        $built['credentials']->id,
        $built['credentials']->token,
        1,
        0,
        200
    ));
    assert_same('invalid_export_cursor', $error->publicCode);
});

test('ChatService preserves exact pending-turn recovery after inactivity without reopening ordinary conversation use', static function (): void {
    $turns = new InMemoryTurnRepository();
    $turns->failCheckpoint = true;
    $conversations = new InMemoryConversationRepository(new TestClock());
    $conversations->failAssistantAppend = true;
    $built = build_chat_service_test(
        turns: $turns,
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $request = chat_request_for($built['credentials'], 'turn_session_expiry_01');
    $first = $built['service']->chat($request);
    assert_same(503, $first['status']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);

    $conversationId = $built['credentials']->id;
    $expiredAt = '2026-08-14T11:29:59+00:00';
    $conversations->conversations[$conversationId]['last_activity_at'] = $expiredAt;
    $touchCalls = $conversations->touchCalls;
    $conversations->failAssistantAppend = false;

    $recovered = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(200, $recovered['status']);
    assert_same(true, $recovered['body']['turn_finalized']);
    assert_same(true, $recovered['body']['replayed']);
    assert_true((int) ($recovered['body']['message_id'] ?? 0) > 0);
    assert_same($recovered['body']['message_id'], $turns->turns[1]['response']['message_id']);
    assert_same($touchCalls, $conversations->touchCalls);
    assert_same($expiredAt, $conversations->conversations[$conversationId]['last_activity_at']);

    foreach (array(
        static fn (): array => $built['service']->chat(chat_request_for(
            $built['credentials'],
            'turn_after_inactivity_001',
            'طلب جديد بعد انتهاء نافذة النشاط'
        )),
        static fn (): array => $built['service']->export($conversationId, $built['credentials']->token),
        static fn (): array => $built['service']->delete($conversationId, $built['credentials']->token),
    ) as $operation) {
        $error = assert_throws(PublicException::class, $operation);
        assert_same('conversation_unauthorized', $error->publicCode);
        assert_same(401, $error->httpStatus);
    }

    assert_true(isset($conversations->conversations[$conversationId]));
});


test('ChatService replays a fully presented completed turn after inactivity', static function (): void {
    $conversations = new InMemoryConversationRepository(new TestClock());
    $built = build_chat_service_test(
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $request = chat_request_for(
        $built['credentials'],
        'turn_finalized_after_expiry_01',
        'طلب اكتمل قبل إغلاق الصفحة'
    );

    $first = $built['service']->chat($request);
    assert_same(200, $first['status']);
    assert_same(true, $first['body']['turn_finalized']);
    $messageId = (int) ($first['body']['message_id'] ?? 0);
    assert_true($messageId > 0);

    $conversationId = $built['credentials']->id;
    $expiredAt = '2026-08-14T11:29:59+00:00';
    $conversations->conversations[$conversationId]['last_activity_at'] = $expiredAt;
    $touchCalls = $conversations->touchCalls;

    $replayed = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(200, $replayed['status']);
    assert_same(true, $replayed['body']['turn_finalized']);
    assert_same(true, $replayed['body']['replayed']);
    assert_same($messageId, $replayed['body']['message_id']);
    assert_same($touchCalls, $conversations->touchCalls);
    assert_same($expiredAt, $conversations->conversations[$conversationId]['last_activity_at']);
});

test('ChatService allows exact processing turn after inactivity without reactivating the conversation', static function (): void {
    $clock = new TestClock();
    $turns = new InMemoryTurnRepository();
    $conversations = new InMemoryConversationRepository($clock);
    $built = build_chat_service_test(
        turns: $turns,
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $conversationId = $built['credentials']->id;
    $clientTurnId = 'turn_processing_after_expiry_01';
    $turns->claim($conversationId, $clientTurnId, str_repeat('a', 64), 300);
    $expiredAt = '2026-08-14T11:29:59+00:00';
    $conversations->conversations[$conversationId]['last_activity_at'] = $expiredAt;
    $touchCalls = $conversations->touchCalls;

    $recovered = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        $clientTurnId
    );

    assert_same(202, $recovered['status']);
    assert_same('processing', $recovered['body']['status']);
    assert_same(false, $recovered['body']['turn_finalized']);
    assert_same($touchCalls, $conversations->touchCalls);
    assert_same($expiredAt, $conversations->conversations[$conversationId]['last_activity_at']);
});

test('ChatService completes an accepted failed presentation after inactivity without reopening the session', static function (): void {
    $provider = new ScriptedAiProvider(array(new RuntimeException('provider failed privately')));
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $conversations->failAssistantAppend = true;
    $built = build_chat_service_test(
        provider: $provider,
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $request = chat_request_for($built['credentials'], 'turn_failed_pending_expiry_01');
    $first = $built['service']->chat($request);
    assert_same(503, $first['status']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);
    assert_same(true, $built['turns']->turns[1]['response']['request_accepted']);

    $conversationId = $built['credentials']->id;
    $expiredAt = '2026-08-14T11:29:59+00:00';
    $conversations->conversations[$conversationId]['last_activity_at'] = $expiredAt;
    $touchCalls = $conversations->touchCalls;
    $conversations->failAssistantAppend = false;

    $recovered = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(500, $recovered['status']);
    assert_same(true, $recovered['body']['turn_finalized']);
    assert_same(true, $recovered['body']['request_accepted']);
    assert_true((int) ($recovered['body']['message_id'] ?? 0) > 0);
    assert_same($touchCalls, $conversations->touchCalls);
    assert_same($expiredAt, $conversations->conversations[$conversationId]['last_activity_at']);
});

test('ChatService replays a fully presented accepted failure after inactivity', static function (): void {
    $provider = new ScriptedAiProvider(array(new RuntimeException('provider failed privately')));
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $built = build_chat_service_test(
        provider: $provider,
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $request = chat_request_for($built['credentials'], 'turn_failed_final_expiry_01');
    $first = $built['service']->chat($request);
    assert_same(500, $first['status']);
    assert_same(true, $first['body']['turn_finalized']);
    assert_same(true, $first['body']['request_accepted']);
    $messageId = (int) ($first['body']['message_id'] ?? 0);
    assert_true($messageId > 0);

    $conversationId = $built['credentials']->id;
    $expiredAt = '2026-08-14T11:29:59+00:00';
    $conversations->conversations[$conversationId]['last_activity_at'] = $expiredAt;
    $touchCalls = $conversations->touchCalls;
    $replayed = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(500, $replayed['status']);
    assert_same(true, $replayed['body']['turn_finalized']);
    assert_same(true, $replayed['body']['request_accepted']);
    assert_same(true, $replayed['body']['replayed']);
    assert_same($messageId, $replayed['body']['message_id']);
    assert_same($touchCalls, $conversations->touchCalls);
    assert_same($expiredAt, $conversations->conversations[$conversationId]['last_activity_at']);
});

test('ChatService replays a rejected failed turn after inactivity', static function (): void {
    $rateLimiter = new AllowAllRateLimiter();
    $rateLimiter->deniedScopes[] = 'conversation_turns';
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $built = build_chat_service_test(
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30)),
        rateLimiter: $rateLimiter
    );
    $request = chat_request_for($built['credentials'], 'turn_rejected_after_expiry_01');
    $first = $built['service']->chat($request);
    assert_same(429, $first['status']);
    assert_same(true, $first['body']['turn_finalized']);
    assert_same(false, $first['body']['request_accepted']);
    assert_same(true, $first['body']['error']['retryable']);
    assert_same(ProviderException::RETRY_NEW_TURN, $first['body']['error']['retry_mode']);
    assert_same(300, $first['body']['error']['retry_after_seconds']);
    assert_false(array_key_exists('message_id', $first['body']));

    $conversationId = $built['credentials']->id;
    $expiredAt = '2026-08-14T11:29:59+00:00';
    $conversations->conversations[$conversationId]['last_activity_at'] = $expiredAt;
    $touchCalls = $conversations->touchCalls;
    $replayed = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(429, $replayed['status']);
    assert_same(true, $replayed['body']['turn_finalized']);
    assert_same(false, $replayed['body']['request_accepted']);
    assert_same(true, $replayed['body']['replayed']);
    assert_same(ProviderException::RETRY_NEW_TURN, $replayed['body']['error']['retry_mode']);
    assert_same(300, $replayed['body']['error']['retry_after_seconds']);
    assert_false(array_key_exists('message_id', $replayed['body']));
    assert_same($touchCalls, $conversations->touchCalls);
    assert_same($expiredAt, $conversations->conversations[$conversationId]['last_activity_at']);
});

test('ChatService exact inactive replay does not depend on a separate presentation-status lookup', static function (): void {
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $built = build_chat_service_test(
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $request = chat_request_for($built['credentials'], 'turn_lookup_failure_expiry_01');
    $first = $built['service']->chat($request);
    assert_same(200, $first['status']);

    $conversationId = $built['credentials']->id;
    $conversations->conversations[$conversationId]['last_activity_at'] = '2026-08-14T11:29:59+00:00';
    $conversations->failMessageForTurn = true;
    $replayed = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(200, $replayed['status']);
    assert_same(true, $replayed['body']['turn_finalized']);
    assert_same(true, $replayed['body']['replayed']);
    assert_same($first['body']['message_id'], $replayed['body']['message_id']);
});

test('ChatService heals a directly completed response whose assistant message initially failed', static function (): void {
    $turns = new InMemoryTurnRepository();
    $turns->failCheckpoint = true;
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $conversations->failAssistantAppend = true;
    $built = build_chat_service_test(turns: $turns, conversations: $conversations);
    $request = chat_request_for($built['credentials'], 'turn_direct_heal_0001');

    $first = $built['service']->chat($request);

    assert_same(503, $first['status']);
    assert_same(false, $first['body']['ok']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);
    assert_same(true, $first['body']['error']['retryable']);
    assert_same(false, $first['body']['turn_finalized']);
    assert_false(array_key_exists('message_id', $first['body']));
    assert_same('completed', $turns->turns[1]['status']);
    assert_false(array_key_exists('message_id', $turns->turns[1]['response']));
    assert_count_value(1, $conversations->storedMessages);

    $conversations->failAssistantAppend = false;
    $replayed = $built['service']->chat($request);

    assert_same(200, $replayed['status']);
    assert_same(true, $replayed['body']['replayed']);
    assert_same(true, $replayed['body']['turn_finalized']);
    assert_true((int) ($replayed['body']['message_id'] ?? 0) > 0);
    assert_same($replayed['body']['message_id'], $turns->turns[1]['response']['message_id']);
    assert_count_value(2, $conversations->storedMessages);
    assert_same('assistant', $conversations->storedMessages[1]['role']);
    assert_same(1, $built['provider']->interactCalls);
});

test('ChatService reconciles an earlier completed presentation before accepting a later turn', static function (): void {
    $answer = static fn (string $message): array => array('steps' => array(array(
        'type' => 'function_call',
        'id' => 'answer_' . substr(hash('sha256', $message), 0, 12),
        'name' => 'respond_answer',
        'arguments' => array('message' => $message),
    )));
    $provider = new ScriptedAiProvider(array(
        $answer('نتيجة الطلب الأول.'),
        $answer('نتيجة الطلب الثاني.'),
    ));
    $turns = new InMemoryTurnRepository();
    $turns->failCheckpoint = true;
    $conversations = new InMemoryConversationRepository(new TestClock());
    $conversations->failAssistantAppend = true;
    $built = build_chat_service_test($provider, $turns, $conversations);

    $first = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_completed_pending_001',
        'الطلب الأول'
    ));
    assert_same(503, $first['status']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);
    assert_same('completed', $turns->turns[1]['status']);
    assert_false(array_key_exists('message_id', $turns->turns[1]['response']));

    $conversations->failAssistantAppend = false;
    $second = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_after_completed_pending_01',
        'الطلب الثاني'
    ));

    assert_same(200, $second['status']);
    assert_same(2, $provider->interactCalls);
    assert_true((int) ($turns->turns[1]['response']['message_id'] ?? 0) > 0);
    assert_same('completed', $turns->turns[2]['status']);
    assert_count_value(4, $conversations->storedMessages);
    assert_same(array('user', 'assistant', 'user', 'assistant'), array_column($conversations->storedMessages, 'role'));
    assert_same(1, $conversations->storedMessages[0]['turn_id']);
    assert_same(1, $conversations->storedMessages[1]['turn_id']);
    assert_same(2, $conversations->storedMessages[2]['turn_id']);
    assert_same(2, $conversations->storedMessages[3]['turn_id']);
});

test('ChatService reconciles an earlier accepted failure before accepting a later turn', static function (): void {
    $provider = new ScriptedAiProvider(array(
        new RuntimeException('first provider failure'),
        array('steps' => array(array(
            'type' => 'function_call',
            'id' => 'answer_second_after_failure',
            'name' => 'respond_answer',
            'arguments' => array('message' => 'تم تنفيذ الطلب الثاني.'),
        ))),
    ));
    $conversations = new InMemoryConversationRepository(new TestClock());
    $conversations->failAssistantAppend = true;
    $built = build_chat_service_test($provider, conversations: $conversations);

    $first = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_failed_pending_000001',
        'الطلب الأول الذي سيفشل'
    ));
    assert_same(503, $first['status']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);
    assert_same('failed', $built['turns']->turns[1]['status']);
    assert_same(true, $built['turns']->turns[1]['response']['request_accepted']);
    assert_false(array_key_exists('message_id', $built['turns']->turns[1]['response']));

    $conversations->failAssistantAppend = false;
    $second = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_after_failed_pending_0001',
        'الطلب الثاني بعد عرض الفشل الأول'
    ));

    assert_same(200, $second['status']);
    assert_same(2, $provider->interactCalls);
    assert_true((int) ($built['turns']->turns[1]['response']['message_id'] ?? 0) > 0);
    assert_same('completed', $built['turns']->turns[2]['status']);
    assert_count_value(4, $conversations->storedMessages);
    assert_same(array('user', 'assistant', 'user', 'assistant'), array_column($conversations->storedMessages, 'role'));
    assert_same('safe_failure', $conversations->storedMessages[1]['payload']['kind']);
});

test('ChatService fences a stale worker after another request reclaims its turn', static function (): void {
    $turns = new InMemoryTurnRepository();
    $provider = new ScriptedAiProvider(array(static function () use ($turns): array {
        assert_same(2, $turns->reclaim(1));
        return array('steps' => array(array(
            'type' => 'function_call',
            'id' => 'stale-answer',
            'name' => 'respond_answer',
            'arguments' => array('message' => 'رد العامل القديم لا يجوز نشره.'),
        )));
    }));
    $built = build_chat_service_test(provider: $provider, turns: $turns);
    $request = chat_request_for($built['credentials'], 'turn_lease_fence_001');

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($request));

    assert_same('turn_processing', $error->publicCode);
    assert_same(409, $error->httpStatus);
    assert_same(2, $turns->turns[1]['claim_version']);
    assert_same('processing', $turns->turns[1]['status']);
    assert_same(array(), $turns->turns[1]['response']);
    assert_count_value(1, $built['conversations']->storedMessages);
    assert_same('user', $built['conversations']->storedMessages[0]['role']);
    assert_same(1, $provider->interactCalls);
});

test('ChatService does not authorize a reply product that existed only in hidden context', static function (): void {
    $built = build_chat_service_test();
    $hiddenRef = 'p_hiddenref12345678';
    $messageId = $built['conversations']->appendMessage(
        $built['credentials']->id,
        903,
        'assistant',
        'تم عرض منتج واحد فقط.',
        array(
            'products' => array(array('ref' => 'p_visible_12345678')),
            '_product_context' => array($hiddenRef => array(
                'identity' => array('id' => 7),
                'card' => array('name' => 'منتج غير معروض'),
            )),
        )
    );
    $request = chat_request_for($built['credentials'], 'turn_hidden_reply_001');
    $request['reply'] = array('message_id' => $messageId, 'product_ref' => $hiddenRef);

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($request));
    assert_same('invalid_reply_context', $error->publicCode);
    assert_same(0, $built['turns']->claimCalls);
});

test('ChatService durably abandons an expired processing turn and releases browser recovery', static function (): void {
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(turns: $turns);
    $conversationId = $built['credentials']->id;
    $clientTurnId = 'turn_abandoned_recovery_01';
    $claim = $turns->claim(
        $conversationId,
        $clientTurnId,
        hash('sha256', 'abandoned-request'),
        300
    );
    $turnId = (int) $claim['id'];
    $built['conversations']->appendMessage(
        $conversationId,
        $turnId,
        'user',
        'طلب لم يكتمل.',
        array()
    );
    $turns->turns[$turnId]['updated_at'] = $turns->now->modify('-301 seconds')->format(DATE_ATOM);

    $result = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        $clientTurnId
    );

    assert_same(409, $result['status']);
    assert_same(false, $result['body']['ok']);
    assert_same('turn_abandoned', $result['body']['error']['code']);
    assert_same($clientTurnId, $result['body']['client_turn_id']);
    assert_same($turnId, $result['body']['turn_id']);
    assert_same(true, $result['body']['turn_finalized']);
    assert_same(true, $result['body']['replayed']);
    assert_same('failed', $turns->turns[$turnId]['status']);
    assert_same(2, $turns->turns[$turnId]['claim_version']);
    assert_same(0, $built['provider']->interactCalls);
    assert_count_value(2, $built['conversations']->storedMessages);
    assert_same('safe_failure', $built['conversations']->storedMessages[1]['payload']['kind']);

    $replayed = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        $clientTurnId
    );
    assert_same(409, $replayed['status']);
    assert_same(true, $replayed['body']['replayed']);
    assert_count_value(2, $built['conversations']->storedMessages);
});

test('ChatService keeps a fresh processing turn pending until its persisted lease expires', static function (): void {
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(turns: $turns);
    $clientTurnId = 'turn_fresh_recovery_0001';
    $claim = $turns->claim(
        $built['credentials']->id,
        $clientTurnId,
        hash('sha256', 'fresh-request'),
        300
    );

    $result = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        $clientTurnId
    );

    assert_same(202, $result['status']);
    assert_same('processing', $result['body']['status']);
    assert_same('processing', $turns->turns[(int) $claim['id']]['status']);
    assert_same(1, $turns->expireCalls);
});

test('ChatService rejects a second unresolved turn before invoking the provider', static function (): void {
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(turns: $turns);
    $turns->claim(
        $built['credentials']->id,
        'turn_existing_processing_01',
        hash('sha256', 'existing-processing-request'),
        300
    );

    $request = chat_request_for(
        $built['credentials'],
        'turn_second_processing_0001',
        'طلب ثانٍ لا يجب أن يبدأ'
    );
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($request));

    assert_same('conversation_busy', $error->publicCode);
    assert_same(409, $error->httpStatus);
    assert_same(0, $built['provider']->interactCalls);
    assert_count_value(1, $turns->turns);
});

test('ChatService durably rejects the current id after abandoning an ambiguous stale browser turn', static function (): void {
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(turns: $turns);
    $conversationId = $built['credentials']->id;
    $oldClientTurnId = 'turn_lost_stale_browser_01';
    $old = $turns->claim(
        $conversationId,
        $oldClientTurnId,
        hash('sha256', 'lost stale browser request'),
        300
    );
    $oldTurnId = (int) $old['id'];
    $built['conversations']->appendMessage(
        $conversationId,
        $oldTurnId,
        'user',
        'طلب قديم فُقد سجل استعادته من المتصفح.',
        array()
    );
    $turns->turns[$oldTurnId]['updated_at'] = $turns->now->modify('-301 seconds')->format(DATE_ATOM);

    $request = chat_request_for(
        $built['credentials'],
        'turn_after_lost_stale_001',
        'ابدأ طلبي الجديد بعد إنهاء الطلب القديم'
    );
    $result = $built['service']->chat($request);

    assert_same(409, $result['status']);
    assert_same(false, $result['body']['ok']);
    assert_same('previous_turn_abandoned', $result['body']['error']['code']);
    assert_same(true, $result['body']['turn_finalized']);
    assert_same('failed', $turns->turns[$oldTurnId]['status']);
    assert_same('turn_abandoned', $turns->turns[$oldTurnId]['error_code']);
    assert_same('failed', $turns->turns[$oldTurnId + 1]['status']);
    assert_same('previous_turn_abandoned', $turns->turns[$oldTurnId + 1]['error_code']);
    assert_same(0, $built['provider']->interactCalls);
    assert_same(4, $turns->claimCalls);
    assert_same(1, $turns->expireCalls);
    assert_same(false, $result['body']['request_accepted']);
    assert_false(isset($result['body']['message_id']));
    assert_count_value(2, $built['conversations']->storedMessages);
    assert_same('assistant', $built['conversations']->storedMessages[1]['role']);
    assert_same('safe_failure', $built['conversations']->storedMessages[1]['payload']['kind']);

    // A concurrent or manual duplicate of the same current identity replays the
    // durable rejection and can never race into provider or cart execution.
    $replay = $built['service']->chat($request);
    assert_same(409, $replay['status']);
    assert_same('previous_turn_abandoned', $replay['body']['error']['code']);
    assert_same(true, $replay['body']['replayed']);
    assert_same(0, $built['provider']->interactCalls);

    // After inspecting the cart, a genuinely new idempotency key may proceed.
    $next = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_after_cart_review_0001',
        'تابع بعد أن تحققت من السلة'
    ));
    assert_same(200, $next['status']);
    assert_same(true, $next['body']['turn_finalized']);
    assert_same(1, $built['provider']->interactCalls);
});

test('ChatService finalizes a lost checkpointed browser turn before continuing the new request', static function (): void {
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(turns: $turns);
    $conversationId = $built['credentials']->id;
    $oldClientTurnId = 'turn_lost_checkpoint_0001';
    $old = $turns->claim(
        $conversationId,
        $oldClientTurnId,
        hash('sha256', 'lost checkpointed browser request'),
        300
    );
    $oldTurnId = (int) $old['id'];
    $built['conversations']->appendMessage(
        $conversationId,
        $oldTurnId,
        'user',
        'طلب قديم اكتمل منطقيًا وفُقد سجل المتصفح.',
        array()
    );
    $turns->checkpoint($oldTurnId, 1, array(
        'ok' => true,
        'conversation_id' => $conversationId,
        'client_turn_id' => $oldClientTurnId,
        'turn_id' => $oldTurnId,
        'kind' => 'answer',
        'message' => 'نتيجة الطلب السابق محفوظة بأمان.',
        'products' => array(),
        'cart' => null,
        'receipt' => null,
        '_message_payload' => array(
            'kind' => 'answer',
            'message' => 'نتيجة الطلب السابق محفوظة بأمان.',
            'products' => array(),
        ),
        '_http_status' => 200,
    ));

    $result = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_after_checkpoint_001',
        'تابع الآن بطلبي الجديد'
    ));

    assert_same(200, $result['status']);
    assert_same(true, $result['body']['ok']);
    assert_same('completed', $turns->turns[$oldTurnId]['status']);
    assert_true((int) ($turns->turns[$oldTurnId]['response']['message_id'] ?? 0) > 0);
    assert_same('completed', $turns->turns[$oldTurnId + 1]['status']);
    assert_same(1, $built['provider']->interactCalls);
    assert_same(3, $turns->claimCalls);
    assert_same(0, $turns->expireCalls);
    assert_count_value(4, $built['conversations']->storedMessages);
    assert_same('assistant', $built['conversations']->storedMessages[1]['role']);
    assert_same('نتيجة الطلب السابق محفوظة بأمان.', $built['conversations']->storedMessages[1]['content']);
});

test('ChatService refuses conversation deletion while durable work is active', static function (): void {
    $built = build_chat_service_test();
    $conversationId = $built['credentials']->id;
    $built['conversations']->busy = true;

    $error = assert_throws(
        PublicException::class,
        static fn (): array => $built['service']->delete($conversationId, $built['credentials']->token)
    );

    assert_same('conversation_busy', $error->publicCode);
    assert_same(409, $error->httpStatus);
    assert_true(isset($built['conversations']->conversations[$conversationId]));
});

test('A reclaimed worker cannot update shopping memory after the provider returns', static function (): void {
    $turns = new InMemoryTurnRepository();
    $provider = new ScriptedAiProvider(array(static function () use ($turns): array {
        assert_same(2, $turns->reclaim(1));
        return array('steps' => array(array(
            'type' => 'function_call',
            'id' => 'stale-memory-write',
            'name' => 'shopping_memory_update',
            'arguments' => array(
                'preferences' => array('notes' => 'أفضل اللون الأزرق'),
                'evidence' => 'أفضل اللون الأزرق',
            ),
        )));
    }));
    $built = build_chat_service_test(provider: $provider, turns: $turns);
    $request = chat_request_for(
        $built['credentials'],
        'turn_stale_memory_0001',
        'أفضل اللون الأزرق'
    );

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat($request));

    assert_same('turn_processing', $error->publicCode);
    assert_same(array(), $built['conversations']->memory($built['credentials']->id));
    assert_true($turns->heartbeatCalls >= 2);
});

test('Chat service never exposes generic internal validation messages to shoppers', static function (): void {
    $built = build_chat_service_test();
    $method = new ReflectionMethod($built['service'], 'exceptionInternal');
    $result = $method->invoke(
        $built['service'],
        new InvalidArgumentException('private-invariant-marker and internal path'),
        $built['credentials']->id,
        'turn_public_validation_1234567890'
    );

    assert_same(false, $result['ok']);
    assert_same('invalid_request', $result['error']['code']);
    assert_same(422, $result['_http_status']);
    assert_false(str_contains($result['error']['message'], 'private-invariant-marker'));
    assert_false(str_contains($result['error']['message'], 'internal path'));
});

test('ChatService fences a stale worker before accepting or displaying the user message', static function (): void {
    $turns = new InMemoryTurnRepository();
    $conversations = new InMemoryConversationRepository(new TestClock());
    $built = build_chat_service_test(turns: $turns, conversations: $conversations);
    $conversations->beforeUserMessageWrite = static function () use ($turns): void {
        $turns->turns[1]['claim_version'] = 2;
        $turns->turns[1]['updated_at'] = $turns->now->format(DATE_ATOM);
    };

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat(
        chat_request_for($built['credentials'], 'turn_user_write_fenced_01')
    ));

    assert_same('turn_processing', $error->publicCode);
    assert_count_value(0, $conversations->storedMessages);
    assert_same(0, $conversations->touchCalls);
    assert_same(0, $built['provider']->interactCalls);
});

test('ChatService extends accepted conversation activity before provider work begins', static function (): void {
    $provider = new ScriptedAiProvider();
    $built = build_chat_service_test(provider: $provider);
    $provider->responses[] = static function () use ($built): array {
        assert_same(1, $built['conversations']->touchCalls);
        assert_count_value(1, $built['conversations']->storedMessages);
        assert_same('user', $built['conversations']->storedMessages[0]['role']);
        return array('steps' => array(array(
            'type' => 'function_call',
            'id' => 'answer-after-acceptance',
            'name' => 'respond_answer',
            'arguments' => array('message' => 'تم قبول الطلب ثم الرد عليه.'),
        )));
    };

    $result = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_activity_before_ai_01'
    ));

    assert_same(200, $result['status']);
    assert_same(true, $result['body']['ok']);
    // One touch accepts the user message before provider work; the second
    // extends the completed conversation after durable assistant delivery.
    assert_same(2, $built['conversations']->touchCalls);
});

test('A reclaimed worker cannot update shopping memory between its heartbeat and the atomic write', static function (): void {
    $turns = new InMemoryTurnRepository();
    $provider = new ScriptedAiProvider(array(array('steps' => array(array(
        'type' => 'function_call',
        'id' => 'memory-race-after-heartbeat',
        'name' => 'shopping_memory_update',
        'arguments' => array(
            'preferences' => array('notes' => 'أفضل اللون الأزرق'),
            'evidence' => 'أفضل اللون الأزرق',
        ),
    )))));
    $built = build_chat_service_test(provider: $provider, turns: $turns);
    $built['conversations']->beforeMemoryWrite = static function () use ($turns): void {
        assert_same(2, $turns->reclaim(1));
    };

    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat(
        chat_request_for(
            $built['credentials'],
            'turn_memory_atomic_race_01',
            'أفضل اللون الأزرق'
        )
    ));

    assert_same('turn_processing', $error->publicCode);
    assert_same(array(), $built['conversations']->memory($built['credentials']->id));
    assert_true($turns->heartbeatCalls >= 2);
});

test('An accepted failed turn remains pending until its assistant failure message is durable', static function (): void {
    $provider = new ScriptedAiProvider(array(new RuntimeException('provider exploded')));
    $conversations = new InMemoryConversationRepository(new TestClock());
    $conversations->failAssistantAppend = true;
    $built = build_chat_service_test(provider: $provider, conversations: $conversations);
    $request = chat_request_for($built['credentials'], 'turn_failure_message_pending_01');

    $first = $built['service']->chat($request);

    assert_same(503, $first['status']);
    assert_same(false, $first['body']['ok']);
    assert_same('turn_finalization_pending', $first['body']['error']['code']);
    assert_same(false, $first['body']['turn_finalized']);
    assert_same('failed', $built['turns']->turns[1]['status']);
    assert_count_value(1, $conversations->storedMessages);
    assert_same('user', $conversations->storedMessages[0]['role']);
    assert_same(1, $provider->interactCalls);

    $conversations->failAssistantAppend = false;
    $recovered = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        (string) $request['client_turn_id']
    );

    assert_same(500, $recovered['status']);
    assert_same(false, $recovered['body']['ok']);
    assert_same('request_failed', $recovered['body']['error']['code']);
    assert_same(true, $recovered['body']['turn_finalized']);
    assert_same(true, $recovered['body']['request_accepted']);
    assert_true((int) ($recovered['body']['message_id'] ?? 0) > 0);
    assert_same(true, $recovered['body']['replayed']);
    assert_count_value(2, $conversations->storedMessages);
    assert_same(2, $conversations->touchCalls);
    assert_same(1, $provider->interactCalls);
});

test('ChatService resolves a lost user-message write response as an accepted durable failure', static function (): void {
    $conversations = new InMemoryConversationRepository(new TestClock());
    $conversations->throwAfterUserMessageWrite = true;
    $built = build_chat_service_test(conversations: $conversations);
    $request = chat_request_for(
        $built['credentials'],
        'turn_user_write_lost_response_01',
        'رسالة حُفظت لكن فُقد رد قاعدة البيانات'
    );

    $first = $built['service']->chat($request);

    assert_same(500, $first['status']);
    assert_same(false, $first['body']['ok']);
    assert_same('request_failed', $first['body']['error']['code']);
    assert_same(true, $first['body']['turn_finalized']);
    assert_same(true, $first['body']['request_accepted']);
    assert_true((int) ($first['body']['message_id'] ?? 0) > 0);
    assert_same('failed', $built['turns']->turns[1]['status']);
    assert_same(0, $built['provider']->interactCalls);
    assert_count_value(2, $conversations->storedMessages);
    assert_same('user', $conversations->storedMessages[0]['role']);
    assert_same('assistant', $conversations->storedMessages[1]['role']);

    $replayed = $built['service']->chat($request);
    assert_same(500, $replayed['status']);
    assert_same(true, $replayed['body']['replayed']);
    assert_same($first['body']['message_id'], $replayed['body']['message_id']);
    assert_same(0, $built['provider']->interactCalls);
    assert_count_value(2, $conversations->storedMessages);
});

test('ChatService keeps the exact turn pending when user-message acceptance cannot be verified', static function (): void {
    $conversations = new InMemoryConversationRepository(new TestClock());
    $conversations->beforeUserMessageWrite = static function (): void {
        throw new RuntimeException('The user-message write outcome is unknown.');
    };
    $conversations->failMessageForTurn = true;
    $built = build_chat_service_test(conversations: $conversations);
    $request = chat_request_for(
        $built['credentials'],
        'turn_user_acceptance_unknown_01',
        'لا تصنّف حالة الإرسال دون دليل'
    );

    $result = $built['service']->chat($request);

    assert_same(503, $result['status']);
    assert_same(false, $result['body']['ok']);
    assert_same('turn_persistence_uncertain', $result['body']['error']['code']);
    assert_same(false, $result['body']['turn_finalized']);
    assert_same($request['client_turn_id'], $result['body']['client_turn_id']);
    assert_false(array_key_exists('request_accepted', $result['body']));
    assert_same('processing', $built['turns']->turns[1]['status']);
    assert_same(array(), $built['turns']->turns[1]['response']);
    assert_same(0, $built['provider']->interactCalls);
    assert_count_value(0, $conversations->storedMessages);
});

test('ChatService canonicalizes historical assistant payloads before browser boot', static function (): void {
    $built = build_chat_service_test();
    $conversationId = $built['credentials']->id;
    $built['conversations']->appendMessage(
        $conversationId,
        41,
        'user',
        'رسالة تاريخية',
        array()
    );
    $built['conversations']->appendMessage(
        $conversationId,
        42,
        'assistant',
        'بطاقة وسلة من إصدار سابق.',
        array(
            'kind' => 'answer',
            'products' => array(array(
                'ref' => 'p_legacycard01',
                'name' => 'منتج تاريخي',
                'price' => '10.50',
                'in_stock' => true,
                'short_description' => 'وصف قديم',
                'image' => '',
                'url' => '',
            )),
            'cart' => array(
                'items' => array(array(
                    'name' => 'منتج تاريخي',
                    'quantity' => '2',
                    'unit_price' => '10.50',
                    'line_total' => '21.00',
                    'line_total_text' => '$21.00',
                    'image' => '',
                    'variation' => array(),
                    'sku' => 'OLD-1',
                    'ref' => 'l_legacyline01',
                )),
                'item_count' => '2',
                'total' => '21.00',
                'total_text' => '$21.00',
                'checkout_url' => '',
            ),
        )
    );

    $boot = $built['service']->boot($conversationId, $built['credentials']->token);
    assert_same(200, $boot['status']);
    assert_count_value(2, $boot['body']['messages']);
    $assistant = $boot['body']['messages'][1];
    assert_same('assistant', $assistant['role']);
    assert_same('answer', $assistant['kind']);
    assert_count_value(1, $assistant['products']);
    $product = $assistant['products'][0];
    assert_same('p_legacycard01', $product['ref']);
    assert_same(10.5, $product['price']);
    assert_same(true, $product['price_available']);
    assert_same('fixed', $product['price_kind']);
    assert_same(null, $product['regular_price']);
    assert_same(null, $product['sale_price']);
    assert_same(array(), $product['categories']);
    assert_same(array(), $product['attributes']);
    assert_same(array(), $product['variation_options']);

    // Historical assistant messages are inert transcript entries. The current
    // cart is loaded independently during boot and stale message payloads never
    // overwrite it.
    assert_false(array_key_exists('cart', $assistant));
});

test('ChatService downgrades malformed active and historical cart receipts to cart uncertainty', static function (): void {
    $built = build_chat_service_test();
    $conversationId = $built['credentials']->id;
    $legacyCart = array(
        'items' => array(),
        'item_count' => 0,
        'total' => 0,
        'total_text' => '$0.00',
        'checkout_url' => '',
    );

    $method = new ReflectionMethod($built['service'], 'successInternal');
    $internal = $method->invoke(
        $built['service'],
        $conversationId,
        'turn_malformed_receipt_0001',
        77,
        array(
            'kind' => 'cart_receipt',
            'message' => 'ادعاء إيصال غير صالح',
            'products' => array(array(
                'ref' => 'p_shouldnotrender1',
                'name' => 'بطاقة غير مسموح بها',
                'price' => 1,
                'in_stock' => true,
            )),
            'cart' => $legacyCart,
            'receipt' => array('id' => 'invalid', 'message' => 'bad', 'lines' => array(), 'cart' => $legacyCart),
        )
    );
    assert_same('cart_uncertain', $internal['kind']);
    assert_same(array(), $internal['products']);
    assert_same(null, $internal['cart']);
    assert_same(null, $internal['receipt']);
    assert_same(null, $internal['_message_payload']['cart']);
    assert_same(null, $internal['_message_payload']['receipt']);

    $built['conversations']->appendMessage(
        $conversationId,
        78,
        'assistant',
        'إيصال تاريخي غير صالح',
        array(
            'kind' => 'cart_receipt',
            'products' => array(),
            'cart' => $legacyCart,
            'receipt' => array('id' => 'invalid', 'message' => 'bad', 'lines' => array(), 'cart' => $legacyCart),
        )
    );
    $boot = $built['service']->boot($conversationId, $built['credentials']->token);
    $historical = $boot['body']['messages'][0];
    assert_same('cart_uncertain', $historical['kind']);
    assert_false(array_key_exists('cart', $historical));
    assert_false(array_key_exists('receipt', $historical));
    assert_false(array_key_exists('products', $historical));
});

test('ChatService exposes the durable client turn identity on accepted user history', static function (): void {
    $built = build_chat_service_test();
    $request = chat_request_for(
        $built['credentials'],
        'turn_reload_history_identity_01',
        'رسالة يجب أن تبقى ظاهرة بعد إعادة التحميل'
    );

    $result = $built['service']->chat($request);
    assert_same(200, $result['status']);
    $boot = $built['service']->boot($built['credentials']->id, $built['credentials']->token);
    $user = $boot['body']['messages'][0];
    assert_same('user', $user['role']);
    assert_same($request['client_turn_id'], $user['client_turn_id']);
    assert_same($request['client_turn_id'], $built['conversations']->storedMessages[0]['payload']['client_turn_id']);
});

test('ChatService excludes internal roles and treats unknown assistant kinds as inert history', static function (): void {
    $built = build_chat_service_test();
    $conversationId = $built['credentials']->id;
    $built['conversations']->appendMessage($conversationId, 10, 'user', 'رسالة عامة', array());
    $built['conversations']->appendMessage($conversationId, 11, 'system', 'تعليمات داخلية سرية', array(
        'secret' => 'must-not-render',
    ));
    $built['conversations']->appendMessage($conversationId, 12, 'assistant', 'رسالة من نوع مستقبلي', array(
        'kind' => 'future_cart_authority',
        'products' => array(array('ref' => 'p_shouldnotrender1', 'name' => 'مخفي')),
        'cart' => array('items' => array(), 'line_count' => 0, 'item_count' => 0, 'total' => 0),
        'receipt' => array('id' => 'not-valid'),
    ));

    $boot = $built['service']->boot($conversationId, $built['credentials']->token);
    assert_count_value(2, $boot['body']['messages']);
    assert_same('user', $boot['body']['messages'][0]['role']);
    $assistant = $boot['body']['messages'][1];
    assert_same('assistant', $assistant['role']);
    assert_same('answer', $assistant['kind']);
    assert_false(array_key_exists('products', $assistant));
    assert_false(array_key_exists('cart', $assistant));
    assert_false(array_key_exists('receipt', $assistant));
    assert_false(str_contains(json_encode($boot['body'], JSON_UNESCAPED_UNICODE), 'تعليمات داخلية سرية'));
});

test('ChatService exports only public messages and canonical bounded shopping memory', static function (): void {
    $built = build_chat_service_test();
    $conversationId = $built['credentials']->id;
    $built['conversations']->appendMessage($conversationId, 20, 'user', 'رسالة قابلة للتصدير', array(
        'client_turn_id' => 'turn_export_public_0001',
    ));
    $built['conversations']->appendMessage($conversationId, 21, 'system', 'لا تصدّرني', array());
    $built['conversations']->appendMessage($conversationId, 22, 'assistant', 'إجابة قابلة للتصدير', array(
        'kind' => 'answer',
        'products' => array(),
    ));
    $built['conversations']->conversations[$conversationId]['memory'] = array(
        'budget_min' => '10.50',
        'budget_max' => 100,
        'categories' => array('هواتف', '', 7, 'هواتف'),
        'attributes' => array('اللون' => 'أزرق', 'invalid' => 7),
        'notes' => '<b>أفضل المنتجات الخفيفة</b>',
        'internal_secret' => 'do-not-export',
    );

    $export = $built['service']->export($conversationId, $built['credentials']->token);
    assert_same(200, $export['status']);
    assert_same(2, $export['body']['message_count']);
    assert_count_value(2, $export['body']['messages']);
    assert_same('turn_export_public_0001', $export['body']['messages'][0]['client_turn_id']);
    assert_same(array(
        'budget_min' => 10.5,
        'budget_max' => 100.0,
        'categories' => array('هواتف'),
        'attributes' => array('اللون' => 'أزرق'),
        'notes' => 'أفضل المنتجات الخفيفة',
    ), $export['body']['shopping_memory']);
    assert_false(str_contains(json_encode($export['body'], JSON_UNESCAPED_UNICODE), 'لا تصدّرني'));
    assert_false(str_contains(json_encode($export['body'], JSON_UNESCAPED_UNICODE), 'do-not-export'));
});

test('ChatService whitelists stored terminal response envelopes during replay', static function (): void {
    $built = build_chat_service_test();
    $method = new ReflectionMethod($built['service'], 'resultFromInternal');
    $internal = array(
        'ok' => true,
        'conversation_id' => $built['credentials']->id,
        'client_turn_id' => 'turn_replay_whitelist_0001',
        'turn_id' => 90,
        'message_id' => 91,
        'turn_finalized' => true,
        'kind' => 'answer',
        'message' => 'إجابة آمنة',
        'products' => array(),
        'cart' => null,
        'receipt' => null,
        'unexpected_secret' => 'must-not-leak',
        '_message_payload' => array('private' => 'hidden'),
        '_http_status' => 299,
    );

    $result = $method->invoke($built['service'], $internal, true);
    assert_same(200, $result['status']);
    assert_same(array(
        'ok', 'conversation_id', 'client_turn_id', 'turn_id', 'message_id',
        'turn_finalized', 'kind', 'message', 'products', 'cart', 'receipt', 'replayed',
    ), array_keys($result['body']));
    assert_false(str_contains(json_encode($result['body'], JSON_UNESCAPED_UNICODE), 'must-not-leak'));
});

test('ChatService whitelists stored failed-turn envelopes during replay', static function (): void {
    $built = build_chat_service_test();
    $method = new ReflectionMethod($built['service'], 'resultFromInternal');
    $internal = array(
        'ok' => false,
        'conversation_id' => $built['credentials']->id,
        'client_turn_id' => 'turn_failed_whitelist_001',
        'turn_id' => 92,
        'turn_finalized' => true,
        'request_accepted' => false,
        'kind' => 'safe_failure',
        'error' => array(
            'code' => 'request_failed',
            'message' => 'تعذّر إكمال الطلب.',
            'retryable' => false,
            'internal_detail' => 'must-not-leak',
        ),
        'unexpected_secret' => 'must-not-leak',
        '_http_status' => 422,
    );

    $result = $method->invoke($built['service'], $internal, true);
    assert_same(422, $result['status']);
    assert_same(array(
        'ok', 'conversation_id', 'client_turn_id', 'error', 'turn_finalized',
        'turn_id', 'request_accepted', 'kind', 'replayed',
    ), array_keys($result['body']));
    assert_same(array('code', 'message', 'retryable', 'retry_mode'), array_keys($result['body']['error']));
    assert_false(str_contains(json_encode($result['body'], JSON_UNESCAPED_UNICODE), 'must-not-leak'));
});

test('ChatService never tells a non-finalized turn to start a new identity', static function (): void {
    $built = build_chat_service_test();
    $method = new ReflectionMethod(ChatService::class, 'resultFromInternal');
    $method->setAccessible(true);
    $internal = array(
        'ok' => false,
        'conversation_id' => $built['credentials']->id,
        'client_turn_id' => 'turn_nonfinal_retry_mode_01',
        'turn_finalized' => false,
        'error' => array(
            'code' => 'provider_unavailable',
            'message' => 'تعذّر إكمال الطلب مؤقتًا.',
            'retryable' => true,
            'retry_mode' => ProviderException::RETRY_NEW_TURN,
        ),
        '_http_status' => 503,
    );

    $result = $method->invoke($built['service'], $internal);

    assert_same(503, $result['status']);
    assert_same(true, $result['body']['error']['retryable']);
    assert_same(ProviderException::RETRY_SAME_TURN, $result['body']['error']['retry_mode']);
});

test('ChatService exposes a new-turn retry only for a durably finalized transient provider failure', static function (): void {
    $provider = new ScriptedAiProvider(array(new ProviderException(
        'temporary provider outage',
        'provider_unavailable',
        503,
        ProviderException::RETRY_NEW_TURN,
        60
    )));
    $built = build_chat_service_test(provider: $provider);
    $request = chat_request_for($built['credentials'], 'turn_provider_retry_mode_01');

    $first = $built['service']->chat($request);

    assert_same(503, $first['status']);
    assert_same(false, $first['body']['ok']);
    assert_same(true, $first['body']['turn_finalized']);
    assert_same(true, $first['body']['request_accepted']);
    assert_same(true, $first['body']['error']['retryable']);
    assert_same('new_turn', $first['body']['error']['retry_mode']);
    assert_same(60, $first['body']['error']['retry_after_seconds']);
    assert_same(1, $provider->interactCalls);

    $replay = $built['service']->chat($request);
    assert_same(503, $replay['status']);
    assert_same(true, $replay['body']['replayed']);
    assert_same('new_turn', $replay['body']['error']['retry_mode']);
    assert_same(60, $replay['body']['error']['retry_after_seconds']);
    assert_same(1, $provider->interactCalls);
});

test('ChatService atomically seals a missing recovery identity before reporting it as not accepted', static function (): void {
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(turns: $turns);
    $clientTurnId = 'turn_missing_sealed_0001';

    $result = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        $clientTurnId
    );

    assert_same(404, $result['status']);
    assert_same(false, $result['body']['ok']);
    assert_same('turn_not_found', $result['body']['error']['code']);
    assert_same(true, $result['body']['turn_finalized']);
    assert_same(false, $result['body']['request_accepted']);
    assert_true((int) ($result['body']['turn_id'] ?? 0) > 0);
    assert_same(1, $turns->sealMissingCalls);
    assert_same('failed', $turns->turns[1]['status']);
    assert_same(false, $turns->turns[1]['response']['request_accepted']);

    // A delayed original HTTP request cannot execute after the browser has
    // received the durable not-accepted result. Its canonical request hash is
    // different from the sealed absence tombstone and must conflict.
    $error = assert_throws(PublicException::class, static fn (): array => $built['service']->chat(
        chat_request_for($built['credentials'], $clientTurnId, 'طلب وصل متأخرًا')
    ));
    assert_same('turn_id_conflict', $error->publicCode);
    assert_same(0, $built['provider']->interactCalls);
    assert_count_value(0, $built['conversations']->storedMessages);
});

test('ChatService recovery returns a concurrent real claim instead of sealing a false absence', static function (): void {
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(turns: $turns);
    $clientTurnId = 'turn_missing_race_real_01';
    $turns->beforeSealMissing = static function () use ($turns, $built, $clientTurnId): void {
        $turns->beforeSealMissing = null;
        $turns->claim(
            $built['credentials']->id,
            $clientTurnId,
            hash('sha256', 'concurrent-real-request'),
            300
        );
    };

    $result = $built['service']->recover(
        $built['credentials']->id,
        $built['credentials']->token,
        $clientTurnId
    );

    assert_same(202, $result['status']);
    assert_same(true, $result['body']['ok']);
    assert_same('processing', $result['body']['status']);
    assert_same(false, $result['body']['turn_finalized']);
    assert_same(1, $turns->sealMissingCalls);
    assert_same(1, $turns->claimCalls);
    assert_same('processing', $turns->turns[1]['status']);
    assert_same(array(), $turns->turns[1]['response']);
});

test('ChatService seals an inactive missing turn so an already-authenticated delayed request cannot execute', static function (): void {
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $turns = new InMemoryTurnRepository();
    $built = build_chat_service_test(
        turns: $turns,
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $conversationId = $built['credentials']->id;
    $clientTurnId = 'turn_inactive_missing_001';
    $conversations->conversations[$conversationId]['last_activity_at'] = '2026-08-14T11:29:59+00:00';
    $touchCalls = $conversations->touchCalls;

    $result = $built['service']->recover(
        $conversationId,
        $built['credentials']->token,
        $clientTurnId
    );

    assert_same(404, $result['status']);
    assert_same('turn_not_found', $result['body']['error']['code']);
    assert_same(true, $result['body']['turn_finalized']);
    assert_same(false, $result['body']['request_accepted']);
    assert_same($touchCalls, $conversations->touchCalls);

    // Simulate the older request after it already passed application-level
    // authentication but before it reached the repository claim. The sealed
    // synthetic hash fences that delayed execution even though inactivity is
    // not itself a database claim predicate.
    assert_throws(
        \YassinStore\AiAssistant\Application\Contract\TurnRequestConflict::class,
        static fn (): array => $turns->claim(
            $conversationId,
            $clientTurnId,
            hash('sha256', 'delayed authenticated request'),
            300
        )
    );
    assert_same('failed', $turns->turns[1]['status']);
    assert_same(0, $built['provider']->interactCalls);
});

test('ChatService restores the one-use catalog continuation into the next model turn', static function (): void {
    $issuedRef = '';
    $firstSkus = array();
    $provider = new ScriptedAiProvider(array(
        array('steps' => array(array(
            'type' => 'function_call',
            'id' => 'discover-first-page',
            'name' => 'catalog_discover',
            'arguments' => array('query' => 'shoe', 'limit' => 3),
        ))),
        static function (array $history) use (&$issuedRef, &$firstSkus): array {
            $resultStep = $history[array_key_last($history)] ?? array();
            $decoded = json_decode((string) ($resultStep['result'][0]['text'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $issuedRef = is_string($decoded['continuation_ref'] ?? null) ? $decoded['continuation_ref'] : '';
            $firstSkus = array_map(
                static fn (array $card): string => (string) ($card['sku'] ?? ''),
                is_array($decoded['products'] ?? null) ? $decoded['products'] : array()
            );
            assert_true(preg_match('/^n_[A-Za-z0-9_-]{8,80}$/D', $issuedRef) === 1);
            return array('steps' => array(array(
                'type' => 'function_call',
                'id' => 'answer-first-page',
                'name' => 'respond_answer',
                'arguments' => array('message' => 'الدفعة الأولى.'),
            )));
        },
        static function (array $history, array $tools, string $systemInstruction) use (&$issuedRef): array {
            assert_true($issuedRef !== '');
            assert_contains($issuedRef, $systemInstruction);
            assert_contains('Active catalog continuation', $systemInstruction);
            assert_contains('one-use', $systemInstruction);
            return array('steps' => array(array(
                'type' => 'function_call',
                'id' => 'discover-next-page',
                'name' => 'catalog_discover',
                'arguments' => array('continuation_ref' => $issuedRef, 'limit' => 3),
            )));
        },
        static function (array $history) use (&$firstSkus): array {
            $resultStep = $history[array_key_last($history)] ?? array();
            $decoded = json_decode((string) ($resultStep['result'][0]['text'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $secondSkus = array_map(
                static fn (array $card): string => (string) ($card['sku'] ?? ''),
                is_array($decoded['products'] ?? null) ? $decoded['products'] : array()
            );
            assert_count_value(3, $secondSkus);
            assert_same(array(), array_values(array_intersect($firstSkus, $secondSkus)));
            return array('steps' => array(array(
                'type' => 'function_call',
                'id' => 'answer-next-page',
                'name' => 'respond_answer',
                'arguments' => array('message' => 'الدفعة التالية.'),
            )));
        },
    ));
    $products = array();
    for ($id = 1; $id <= 20; ++$id) {
        $products[] = new TestProduct($id, 'Shoe ' . $id, (float) $id);
    }
    $built = build_chat_service_test(
        provider: $provider,
        catalog: new TestCatalog($products)
    );

    $first = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_catalog_first_01',
        'اعرض أحذية'
    ));
    assert_same(200, $first['status']);
    assert_same('الدفعة الأولى.', $first['body']['message']);

    $second = $built['service']->chat(chat_request_for(
        $built['credentials'],
        'turn_catalog_more_002',
        'اعرض المزيد'
    ));
    assert_same(200, $second['status']);
    assert_same('الدفعة التالية.', $second['body']['message']);
    assert_same(4, $provider->interactCalls);
});
