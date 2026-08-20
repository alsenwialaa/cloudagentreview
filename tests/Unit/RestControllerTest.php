<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;
use YassinStore\AiAssistant\Presentation\Rest\RestController;
use YassinStore\AiAssistant\Presentation\Rest\StorefrontRequestGuard;

if (!class_exists('WooCommerce')) {
    class WooCommerce
    {
    }
}
if (!defined('WC_VERSION')) {
    define('WC_VERSION', '11.0.1');
}

/** @param array<string,mixed>|string $body @param array<string,string> $headers */
function storefront_request(array|string $body, array $headers = array()): WP_REST_Request
{
    $raw = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    return new WP_REST_Request($raw, array_replace(array(
        'content-type' => 'application/json; charset=utf-8',
        'origin' => 'https://shop.example.test',
        'sec-fetch-site' => 'same-origin',
        'x-ysai-client-contract' => RestController::CLIENT_CONTRACT_VERSION,
    ), $headers));
}

function rest_controller_for_test(array $built): RestController
{
    $guard = new StorefrontRequestGuard(new SameOriginUrl('https://shop.example.test/'));
    return new RestController($built['service'], $guard, test_logger());
}

test('REST controller decodes and routes a strict authenticated export request', static function (): void {
    $built = build_chat_service_test();
    $built['conversations']->appendMessage(
        $built['credentials']->id,
        9001,
        'user',
        'رسالة للتصدير'
    );
    $controller = rest_controller_for_test($built);
    $response = $controller->export(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'limit' => 10,
    )));

    assert_same(200, $response->get_status());
    $data = $response->get_data();
    assert_same(true, $data['ok']);
    assert_count_value(1, $data['messages']);
    assert_same('رسالة للتصدير', $data['messages'][0]['content']);
});

test('REST controller rejects unknown fields before invoking application code', static function (): void {
    $built = build_chat_service_test();
    $controller = rest_controller_for_test($built);
    $response = $controller->export(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'unexpected' => true,
    )));

    assert_same(422, $response->get_status());
    assert_same('unknown_request_field', $response->get_data()['error']['code']);
});

test('REST controller rejects malformed JSON and cross-origin browser requests', static function (): void {
    $built = build_chat_service_test();
    $controller = rest_controller_for_test($built);

    $malformed = $controller->export(storefront_request('{"conversation_id":'));
    assert_same(400, $malformed->get_status());
    assert_same('invalid_json', $malformed->get_data()['error']['code']);

    $crossOrigin = $controller->export(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
    ), array(
        'origin' => 'https://evil.example',
        'sec-fetch-site' => 'cross-site',
    )));
    assert_same(403, $crossOrigin->get_status());
    assert_same('cross_origin_rejected', $crossOrigin->get_data()['error']['code']);
});

test('REST controller rejects scalar coercion in authenticated and cursor fields', static function (): void {
    $built = build_chat_service_test();
    $controller = rest_controller_for_test($built);

    $stringLimit = $controller->export(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'limit' => '10',
    )));
    assert_same(422, $stringLimit->get_status());
    assert_same('invalid_request_field', $stringLimit->get_data()['error']['code']);

    $arrayCredential = $controller->export(storefront_request(array(
        'conversation_id' => array($built['credentials']->id),
        'token' => $built['credentials']->token,
    )));
    assert_same(422, $arrayCredential->get_status());
    assert_same('invalid_request_field', $arrayCredential->get_data()['error']['code']);
});

test('REST health endpoint exposes liveness only', static function (): void {
    $built = build_chat_service_test();
    $response = rest_controller_for_test($built)->health(new WP_REST_Request());

    assert_same(200, $response->get_status());
    assert_same(array('ok' => true), $response->get_data());
    assert_false(array_key_exists('plugin_version', $response->get_data()));
    assert_false(array_key_exists('configured', $response->get_data()));
    assert_false(array_key_exists('requirements_ready', $response->get_data()));
});

test('REST chat binds a conclusive pre-acceptance rejection to the exact pending identity', static function (): void {
    $built = build_chat_service_test();
    $clientTurnId = 'turn_rest_rejected_0001';
    $response = rest_controller_for_test($built)->chat(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => $clientTurnId,
        'message' => array('not', 'a', 'string'),
    )));

    assert_same(422, $response->get_status());
    $data = $response->get_data();
    assert_same(false, $data['ok']);
    assert_same('invalid_message', $data['error']['code']);
    assert_same($built['credentials']->id, $data['conversation_id']);
    assert_same($clientTurnId, $data['client_turn_id']);
    assert_same(false, $data['turn_finalized']);
    assert_same('rejected', $data['request_disposition']);
    assert_same(false, $data['request_accepted']);
    assert_count_value(0, $built['conversations']->storedMessages);
});

test('REST chat identifies only the exact fresh duplicate as still processing', static function (): void {
    $built = build_chat_service_test();
    $clientTurnId = 'turn_rest_processing_01';
    $message = 'طلب ما زال قيد المعالجة';
    $hashMethod = new ReflectionMethod($built['service'], 'requestHash');
    $requestHash = $hashMethod->invoke($built['service'], $message, null, null);
    $built['turns']->claim(
        $built['credentials']->id,
        $clientTurnId,
        $requestHash,
        120
    );

    $response = rest_controller_for_test($built)->chat(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => $clientTurnId,
        'message' => $message,
    )));

    assert_same(409, $response->get_status());
    $data = $response->get_data();
    assert_same('turn_processing', $data['error']['code']);
    assert_same('processing', $data['request_disposition']);
    assert_same(false, $data['turn_finalized']);
    assert_false(array_key_exists('request_accepted', $data));
});

test('REST recovery uses a durable absence seal and distinguishes an unverifiable conversation', static function (): void {
    $built = build_chat_service_test();
    $controller = rest_controller_for_test($built);
    $clientTurnId = 'turn_rest_missing_00001';

    $missing = $controller->recover(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => $clientTurnId,
    )));
    assert_same(404, $missing->get_status());
    $missingData = $missing->get_data();
    assert_same('turn_not_found', $missingData['error']['code']);
    assert_same(true, $missingData['turn_finalized']);
    assert_same(false, $missingData['request_accepted']);
    assert_true((int) ($missingData['turn_id'] ?? 0) > 0);
    assert_false(array_key_exists('request_disposition', $missingData));

    $unverified = $controller->recover(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => str_repeat('B', 48),
        'client_turn_id' => $clientTurnId,
    )));
    assert_same(401, $unverified->get_status());
    $unverifiedData = $unverified->get_data();
    assert_same('conversation_unauthorized', $unverifiedData['error']['code']);
    assert_same('unverified', $unverifiedData['request_disposition']);
    assert_false(array_key_exists('request_accepted', $unverifiedData));
});

test('REST turn errors before trusted application classification remain unbound', static function (): void {
    $built = build_chat_service_test();
    $controller = rest_controller_for_test($built);

    $malformed = $controller->chat(storefront_request('{"conversation_id":'));
    assert_same(400, $malformed->get_status());
    $malformedData = $malformed->get_data();
    assert_false(array_key_exists('conversation_id', $malformedData));
    assert_false(array_key_exists('client_turn_id', $malformedData));
    assert_false(array_key_exists('request_disposition', $malformedData));

    $handle = new ReflectionMethod($controller, 'handle');
    $validIdentity = array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => 'turn_rest_server_error_01',
        'message' => 'لن يصل هذا الطلب إلى التطبيق',
    );
    $serverFailure = $handle->invoke(
        $controller,
        storefront_request($validIdentity),
        static function (array $body): array {
            throw new RuntimeException('internal transport sentinel');
        },
        false,
        array('conversation_id', 'token', 'client_turn_id', 'message'),
        'chat'
    );
    assert_same(500, $serverFailure->get_status());
    $serverData = $serverFailure->get_data();
    assert_same('server_error', $serverData['error']['code']);
    assert_false(array_key_exists('conversation_id', $serverData));
    assert_false(array_key_exists('client_turn_id', $serverData));
    assert_false(array_key_exists('request_disposition', $serverData));
});

test('REST recovery rate limits remain unbound because they do not prove the original disposition', static function (): void {
    $limiter = new AllowAllRateLimiter();
    $limiter->deniedScopes[] = 'browser_recovery';
    $built = build_chat_service_test(rateLimiter: $limiter);
    $response = rest_controller_for_test($built)->recover(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => 'turn_rest_rate_limit_001',
    )));

    assert_same(429, $response->get_status());
    $data = $response->get_data();
    assert_same('rate_limited', $data['error']['code']);
    assert_false(array_key_exists('request_disposition', $data));
    assert_false(array_key_exists('request_accepted', $data));
});

test('REST chat inactivity remains unbound while the exact turn may already be processing', static function (): void {
    $turns = new InMemoryTurnRepository();
    $conversations = new InMemoryConversationRepository(new TestClock());
    $built = build_chat_service_test(
        turns: $turns,
        conversations: $conversations,
        settings: new TestSettings(array('assistant_session_minutes' => 30))
    );
    $message = 'طلب بدأ قبل انتهاء نافذة النشاط';
    $clientTurnId = 'turn_rest_inactive_race_01';
    $hashMethod = new ReflectionMethod($built['service'], 'requestHash');
    $requestHash = $hashMethod->invoke($built['service'], $message, null, null);
    $turns->claim($built['credentials']->id, $clientTurnId, $requestHash, 300);
    $conversations->conversations[$built['credentials']->id]['last_activity_at'] = '2026-08-14T11:29:59+00:00';

    $controller = rest_controller_for_test($built);
    $chat = $controller->chat(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => $clientTurnId,
        'message' => $message,
    )));

    assert_same(401, $chat->get_status());
    $chatData = $chat->get_data();
    assert_same('conversation_unauthorized', $chatData['error']['code']);
    assert_false(array_key_exists('request_disposition', $chatData));
    assert_false(array_key_exists('request_accepted', $chatData));

    $recover = $controller->recover(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => $clientTurnId,
    )));
    assert_same(202, $recover->get_status());
    assert_same('processing', $recover->get_data()['status']);
});

test('REST chat coarse rate limits remain unbound before the exact turn is inspected', static function (): void {
    $limiter = new AllowAllRateLimiter();
    $limiter->deniedScopes[] = 'browser_requests';
    $built = build_chat_service_test(rateLimiter: $limiter);
    $response = rest_controller_for_test($built)->chat(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => 'turn_rest_chat_rate_0001',
        'message' => 'طلب يجب ألا يُصنّف قبل فحص معرّفه',
    )));

    assert_same(429, $response->get_status());
    $data = $response->get_data();
    assert_same('rate_limited', $data['error']['code']);
    assert_false(array_key_exists('conversation_id', $data));
    assert_false(array_key_exists('client_turn_id', $data));
    assert_false(array_key_exists('request_disposition', $data));
    assert_false(array_key_exists('request_accepted', $data));
    assert_same(0, $built['turns']->claimCalls);
});

test('REST retry metadata is delayed, header-backed, and compatible with stale 2.5.2 clients', static function (): void {
    $limiter = new AllowAllRateLimiter();
    $limiter->deniedScopes[] = 'browser_requests';
    $built = build_chat_service_test(rateLimiter: $limiter);
    $body = array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => 'turn_rest_retry_contract_01',
        'message' => 'طلب محدود مؤقتًا',
    );

    $current = rest_controller_for_test($built)->chat(storefront_request($body));
    assert_same(429, $current->get_status());
    assert_same(array(
        'code', 'message', 'retryable', 'retry_mode', 'retry_after_seconds',
    ), array_keys($current->get_data()['error']));
    assert_same('same_turn', $current->get_data()['error']['retry_mode']);
    assert_true($current->get_data()['error']['retry_after_seconds'] >= 1);
    assert_same(
        (string) $current->get_data()['error']['retry_after_seconds'],
        $current->get_headers()['retry-after'] ?? ''
    );

    $legacyLimiter = new AllowAllRateLimiter();
    $legacyLimiter->deniedScopes[] = 'browser_requests';
    $legacyBuilt = build_chat_service_test(rateLimiter: $legacyLimiter);
    $legacyBody = array_replace($body, array(
        'conversation_id' => $legacyBuilt['credentials']->id,
        'token' => $legacyBuilt['credentials']->token,
        'client_turn_id' => 'turn_rest_legacy_contract_01',
    ));
    $legacy = rest_controller_for_test($legacyBuilt)->chat(storefront_request(
        $legacyBody,
        array('x-ysai-client-contract' => '')
    ));
    assert_same(429, $legacy->get_status());
    assert_same(array('code', 'message', 'retryable'), array_keys($legacy->get_data()['error']));
    assert_true((int) ($legacy->get_headers()['retry-after'] ?? 0) >= 1);
});

test('REST exposes a delayed new-turn action for a finalized rejected rate-limited turn', static function (): void {
    $limiter = new AllowAllRateLimiter();
    $limiter->deniedScopes[] = 'conversation_turns';
    $built = build_chat_service_test(rateLimiter: $limiter);
    $response = rest_controller_for_test($built)->chat(storefront_request(array(
        'conversation_id' => $built['credentials']->id,
        'token' => $built['credentials']->token,
        'client_turn_id' => 'turn_rest_final_rate_0001',
        'message' => 'طلب وصل إلى حد المحادثة',
    )));

    assert_same(429, $response->get_status());
    $data = $response->get_data();
    assert_same(true, $data['turn_finalized']);
    assert_same(false, $data['request_accepted']);
    assert_same(true, $data['error']['retryable']);
    assert_same('new_turn', $data['error']['retry_mode']);
    assert_same(300, $data['error']['retry_after_seconds']);
    assert_same('300', $response->get_headers()['retry-after'] ?? '');
});
