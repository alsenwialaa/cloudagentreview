<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Chat\IntentVerifier;
use YassinStore\AiAssistant\Application\Tool\ShoppingMemoryPolicy;
use YassinStore\AiAssistant\Application\Tool\ToolRegistry;
use YassinStore\AiAssistant\Infrastructure\Ai\GeminiInteractionsClient;
use YassinStore\AiAssistant\Infrastructure\Ai\ProviderException;
use YassinStore\AiAssistant\Infrastructure\Security\SecretBox;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

/** @return array{0:GeminiInteractionsClient,1:Settings} */
function gemini_test_client(array $overrides = array()): array
{
    $box = new SecretBox();
    $GLOBALS['ysai_test_options'][Settings::OPTION_KEY] = array_replace(array(
        'gemini_api_key' => $box->encrypt('test-gemini-key'),
        'gemini_model' => 'gemini-3.7-flash',
        'gemini_thinking_level' => 'minimal',
        'max_output_tokens' => 1400,
        'http_timeout_seconds' => 24,
        'diagnostic_logging' => 0,
    ), $overrides);
    $settings = new Settings($box);
    return array(new GeminiInteractionsClient($settings, new Logger($settings)), $settings);
}

/** @param array<string,mixed> $body @param array<string,string> $headers */
function gemini_test_response(array $body, int $status = 200, array $headers = array()): array
{
    return array(
        'response' => array('code' => $status),
        'headers' => $headers,
        'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}


/** @param array<string,array<string,mixed>> $properties @param list<string> $required @return array<string,mixed> */
function gemini_test_tool(
    string $name,
    array $properties = array(),
    array $required = array()
): array {
    $parameters = array(
        'type' => 'object',
        'properties' => $properties,
        'additionalProperties' => false,
    );
    if ($required !== array()) {
        $parameters['required'] = $required;
    }
    return array(
        'type' => 'function',
        'name' => $name,
        'description' => 'Test declaration for ' . $name . '.',
        'parameters' => $parameters,
    );
}

test('Gemini client sends a stateless tool interaction without leaking its API key', static function (): void {
    [$client] = gemini_test_client();
    $captured = array();
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$captured): array {
        $captured = array('url' => $url, 'arguments' => $arguments);
        return gemini_test_response(array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'catalog-search-call',
                'name' => 'catalog_search',
                'arguments' => (object) array('query' => 'ابحث'),
            )),
        ));
    };

    try {
        $result = $client->interact(
            array(array('type' => 'user_input', 'content' => array(array('type' => 'text', 'text' => 'ابحث')))),
            array(array(
                'type' => 'function',
                'name' => 'catalog_search',
                'description' => 'Search',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array('query' => array('type' => 'string')),
                    'required' => array('query'),
                    'additionalProperties' => false,
                ),
            )),
            'System rule.'
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('requires_action', $result['status']);
    assert_same('catalog_search', $result['steps'][0]['name']);
    assert_same('https://generativelanguage.googleapis.com/v1/interactions', $captured['url']);
    assert_true(is_float($captured['arguments']['timeout']) || is_int($captured['arguments']['timeout']));
    assert_true($captured['arguments']['timeout'] > 23.0);
    assert_true($captured['arguments']['timeout'] <= 23.75);
    assert_same(0, $captured['arguments']['redirection']);
    assert_same(true, $captured['arguments']['reject_unsafe_urls']);
    assert_same('test-gemini-key', $captured['arguments']['headers']['x-goog-api-key']);
    assert_false(str_contains((string) $captured['arguments']['body'], 'test-gemini-key'));

    $payload = json_decode((string) $captured['arguments']['body'], true, 512, JSON_THROW_ON_ERROR);
    assert_same('gemini-3.7-flash', $payload['model']);
    assert_same(false, $payload['store']);
    assert_same(false, $payload['stream']);
    assert_same('low', $payload['generation_config']['thinking_level']);
    assert_same('any', $payload['generation_config']['tool_choice']);
    assert_same(1400, $payload['generation_config']['max_output_tokens']);
    assert_same('catalog_search', $payload['tools'][0]['name']);
    assert_false(array_key_exists('Api-Revision', $captured['arguments']['headers']));
    assert_same(2_097_153, $captured['arguments']['limit_response_size']);
    assert_same('Yassin-AI-Assistant/' . YSAI_VERSION, $captured['arguments']['headers']['User-Agent']);
});

test('Gemini client rejects direct prose when production tools require a function call', static function (): void {
    [$client] = gemini_test_client();
    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'completed',
        'steps' => array(array(
            'type' => 'model_output',
            'content' => array(array('type' => 'text', 'text' => 'Unsupported direct prose.')),
        )),
    ));

    try {
        $error = assert_throws(ProviderException::class, static fn (): array => $client->interact(
            array(array('type' => 'user_input', 'content' => array(array('type' => 'text', 'text' => 'help')))),
            array(gemini_test_tool('respond_answer', array(
                'message' => array('type' => 'string'),
            ), array('message'))),
            'Use a terminal function.'
        ));
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('provider_protocol_error', $error->publicCode);
});

test('Gemini client disables tool selection when no tools are declared', static function (): void {
    [$client] = gemini_test_client();
    $capturedPayload = array();
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$capturedPayload): array {
        $capturedPayload = json_decode((string) $arguments['body'], true, 512, JSON_THROW_ON_ERROR);
        return gemini_test_response(array(
            'status' => 'completed',
            'steps' => array(array(
                'type' => 'model_output',
                'content' => array(array('type' => 'text', 'text' => 'No tools declared.')),
            )),
        ));
    };

    try {
        $client->interact(
            array(array('type' => 'user_input', 'content' => array(array('type' => 'text', 'text' => 'status')))),
            array(),
            'System rule.'
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('none', $capturedPayload['generation_config']['tool_choice']);
    assert_false(array_key_exists('tools', $capturedPayload));
});

test('Gemini client projects function schemas to the portable wire subset but validates original arguments locally', static function (): void {
    [$client] = gemini_test_client();
    $capturedBody = '';
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$capturedBody): array {
        $capturedBody = (string) $arguments['body'];
        return gemini_test_response(array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'portable-schema-invalid-arguments',
                'name' => 'catalog_details',
                'arguments' => (object) array('product_ref' => 'not-a-reference'),
            )),
        ));
    };

    try {
        $error = assert_throws(ProviderException::class, static fn (): array => $client->interact(
            array(array('type' => 'user_input', 'content' => array(array('type' => 'text', 'text' => 'details')))),
            array(gemini_test_tool('catalog_details', array(
                'product_ref' => array(
                    'type' => 'string',
                    'description' => 'Opaque product reference.',
                    'minLength' => 10,
                    'maxLength' => 82,
                    'pattern' => '^p_[A-Za-z0-9_-]{8,80}$',
                ),
            ), array('product_ref'))),
            'System rule.'
        ));
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('provider_protocol_error', $error->publicCode);
    $payload = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
    assert_same('any', $payload['generation_config']['tool_choice']);
    $wireProperty = $payload['tools'][0]['parameters']['properties']['product_ref'];
    assert_same('Opaque product reference.', $wireProperty['description']);
    foreach (array('minLength', 'maxLength', 'pattern', 'minProperties', 'maxProperties') as $keyword) {
        assert_false(array_key_exists($keyword, $wireProperty));
        assert_false(str_contains($capturedBody, '"' . $keyword . '"'));
    }
});

test('Gemini client projects structured schemas portably and still rejects locally invalid output', static function (): void {
    [$client] = gemini_test_client();
    $capturedBody = '';
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$capturedBody): array {
        $capturedBody = (string) $arguments['body'];
        return gemini_test_response(array(
            'status' => 'completed',
            'output_text' => '{"authorization_fingerprint":"INVALID"}',
        ));
    };

    $schema = array(
        'type' => 'object',
        'minProperties' => 1,
        'maxProperties' => 1,
        'properties' => array(
            'authorization_fingerprint' => array(
                'type' => 'string',
                'description' => 'Copy the supplied fingerprint exactly.',
                'minLength' => 64,
                'maxLength' => 64,
                'pattern' => '^[a-f0-9]{64}$',
            ),
        ),
        'required' => array('authorization_fingerprint'),
        'additionalProperties' => false,
    );

    try {
        $error = assert_throws(ProviderException::class, static fn (): array => $client->structured(
            'input',
            $schema,
            'Copy the fingerprint.'
        ));
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('provider_protocol_error', $error->publicCode);
    $payload = json_decode($capturedBody, true, 512, JSON_THROW_ON_ERROR);
    $wireSchema = $payload['response_format']['schema'];
    assert_same('Copy the supplied fingerprint exactly.', $wireSchema['properties']['authorization_fingerprint']['description']);
    foreach (array('minLength', 'maxLength', 'pattern', 'minProperties', 'maxProperties') as $keyword) {
        assert_false(str_contains($capturedBody, '"' . $keyword . '"'));
    }
});

test('Gemini client requests schema-constrained JSON for independent decisions', static function (): void {
    [$client] = gemini_test_client();
    $capturedPayload = array();
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$capturedPayload): array {
        $capturedPayload = json_decode((string) $arguments['body'], true, 512, JSON_THROW_ON_ERROR);
        return gemini_test_response(array(
            'status' => 'completed',
            'output_text' => '{"authorized":true}',
        ));
    };

    $schema = array(
        'type' => 'object',
        'properties' => array('authorized' => array('type' => 'boolean')),
        'required' => array('authorized'),
        'additionalProperties' => false,
    );

    try {
        $result = $client->structured('current request', $schema, 'Authorize only explicit intent.', 'high');
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(array('authorized' => true), $result);
    assert_same(false, $capturedPayload['store']);
    assert_same('application/json', $capturedPayload['response_format']['mime_type']);
    assert_same($schema, $capturedPayload['response_format']['schema']);
    assert_same('high', $capturedPayload['generation_config']['thinking_level']);
    assert_same('none', $capturedPayload['generation_config']['tool_choice']);
    assert_same(1024, $capturedPayload['generation_config']['max_output_tokens']);
});

test('Gemini client preserves retry and credential semantics for non-JSON HTTP errors', static function (): void {
    [$client] = gemini_test_client();
    foreach (array(
        array(503, 'upstream unavailable', 'provider_unavailable', 503),
        array(403, '', 'provider_access_denied', 502),
        array(400, '<html>bad request</html>', 'provider_request_rejected', 502),
    ) as [$status, $body, $code, $publicStatus]) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => array(
            'response' => array('code' => $status),
            'body' => $body,
        );
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array(), 'System')
            );
            assert_same($code, $error->publicCode);
            assert_same($publicStatus, $error->httpStatus);
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
    }
});

test('Gemini client retries transient transport and HTTP failures inside one bounded request deadline', static function (): void {
    [, $settings] = gemini_test_client(array('http_timeout_seconds' => 10));
    $now = 1000.0;
    $sleeps = array();
    $clock = static function () use (&$now): float {
        return $now;
    };
    $sleeper = static function (int $microseconds) use (&$now, &$sleeps): void {
        $sleeps[] = $microseconds;
        $now += $microseconds / 1_000_000;
    };
    $client = new GeminiInteractionsClient(
        $settings,
        new Logger($settings),
        null,
        null,
        null,
        $clock,
        $sleeper
    );
    $calls = 0;
    $timeouts = array();
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$calls, &$timeouts): array|WP_Error {
        ++$calls;
        $timeouts[] = $arguments['timeout'];
        return match ($calls) {
            1 => new WP_Error('temporary_network_failure'),
            2 => gemini_test_response(array('error' => array(
                'status' => 'UNAVAILABLE',
                'message' => 'Temporary upstream outage.',
            )), 503),
            default => gemini_test_response(array(
                'status' => 'completed',
                'output_text' => '{"ready":true}',
            )),
        };
    };

    try {
        $result = $client->structured('input', array(
            'type' => 'object',
            'properties' => array('ready' => array('type' => 'boolean')),
            'required' => array('ready'),
            'additionalProperties' => false,
        ), 'System');
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(array('ready' => true), $result);
    assert_same(3, $calls);
    assert_count_value(2, $sleeps);
    assert_true(array_sum($sleeps) <= 4_000_000);
    assert_true(max($timeouts) <= 10);
    assert_true(min($timeouts) >= 1);
    assert_true($now < 1010.0);
});

test('Gemini client never shortens Retry-After and skips a retry that cannot fit the shared deadline', static function (): void {
    [, $settings] = gemini_test_client(array('http_timeout_seconds' => 10));
    $now = 1500.0;
    $sleeps = array();
    $client = new GeminiInteractionsClient(
        $settings,
        new Logger($settings),
        null,
        null,
        null,
        static function () use (&$now): float {
            return $now;
        },
        static function (int $microseconds) use (&$now, &$sleeps): void {
            $sleeps[] = $microseconds;
            $now += $microseconds / 1_000_000;
        }
    );
    $calls = 0;
    $GLOBALS['ysai_test_http_handler'] = static function () use (&$calls): array {
        ++$calls;
        return gemini_test_response(array('error' => array(
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'Rate limit reached.',
        )), 429, array('Retry-After' => '60'));
    };

    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(), 'System')
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(1, $calls);
    assert_same(array(), $sleeps);
    assert_same('provider_quota_exhausted', $error->publicCode);
    assert_same(ProviderException::RETRY_NEW_TURN, $error->retryMode);
    assert_same(60, $error->retryAfterSeconds);
});

test('Gemini client waits the complete Retry-After window when it fits the shared deadline', static function (): void {
    [, $settings] = gemini_test_client(array('http_timeout_seconds' => 10));
    $now = 1750.0;
    $sleeps = array();
    $timeouts = array();
    $client = new GeminiInteractionsClient(
        $settings,
        new Logger($settings),
        null,
        null,
        null,
        static function () use (&$now): float {
            return $now;
        },
        static function (int $microseconds) use (&$now, &$sleeps): void {
            $sleeps[] = $microseconds;
            $now += $microseconds / 1_000_000;
        }
    );
    $calls = 0;
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$calls, &$timeouts): array {
        ++$calls;
        $timeouts[] = $arguments['timeout'];
        if ($calls === 1) {
            return gemini_test_response(array('error' => array(
                'status' => 'RESOURCE_EXHAUSTED',
                'message' => 'Rate limit reached.',
            )), 429, array('Retry-After' => '2'));
        }
        return gemini_test_response(array(
            'status' => 'completed',
            'output_text' => '{"ready":true}',
        ));
    };

    try {
        $result = $client->structured('input', array(
            'type' => 'object',
            'properties' => array('ready' => array('type' => 'boolean')),
            'required' => array('ready'),
            'additionalProperties' => false,
        ), 'System');
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(array('ready' => true), $result);
    assert_same(2, $calls);
    assert_same(array(2_000_000), $sleeps);
    assert_true($timeouts[1] < $timeouts[0]);
    assert_same(1752.0, $now);
});

test('Gemini client does not start another transport attempt after the shared deadline is consumed', static function (): void {
    [, $settings] = gemini_test_client(array('http_timeout_seconds' => 10));
    $now = 2000.0;
    $timeouts = array();
    $sleeps = array();
    $client = new GeminiInteractionsClient(
        $settings,
        new Logger($settings),
        null,
        null,
        null,
        static function () use (&$now): float {
            return $now;
        },
        static function (int $microseconds) use (&$now, &$sleeps): void {
            $sleeps[] = $microseconds;
            $now += $microseconds / 1_000_000;
        }
    );
    $calls = 0;
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$calls, &$now, &$timeouts): WP_Error {
        ++$calls;
        $timeout = (float) $arguments['timeout'];
        $timeouts[] = $timeout;
        $now += $timeout;
        return new WP_Error('temporary_network_failure');
    };

    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(), 'System')
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(1, $calls);
    assert_count_value(1, $timeouts);
    assert_same(array(), $sleeps);
    assert_true($now <= 2009.75);
    assert_same('provider_unavailable', $error->publicCode);
});

test('Gemini client exhausts transient retries once and marks the finalized provider failure for a new turn', static function (): void {
    [, $settings] = gemini_test_client(array('http_timeout_seconds' => 10));
    $now = 2000.0;
    $client = new GeminiInteractionsClient(
        $settings,
        new Logger($settings),
        null,
        null,
        null,
        static function () use (&$now): float {
            return $now;
        },
        static function (int $microseconds) use (&$now): void {
            $now += $microseconds / 1_000_000;
        }
    );
    $calls = 0;
    $GLOBALS['ysai_test_http_handler'] = static function () use (&$calls): array {
        ++$calls;
        return gemini_test_response(array('error' => array(
            'status' => 'UNAVAILABLE',
            'message' => 'Temporary upstream outage.',
        )), 503);
    };

    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(), 'System')
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(3, $calls);
    assert_same('provider_unavailable', $error->publicCode);
    assert_same(ProviderException::RETRY_NEW_TURN, $error->retryMode);
});

test('Gemini client distinguishes location, permission, and quota rejection categories', static function (): void {
    [$client] = gemini_test_client();
    $cases = array(
        array(
            403,
            array('error' => array(
                'status' => 'PERMISSION_DENIED',
                'message' => 'This API is not available in the request location or region.',
            )),
            'provider_access_denied',
            502,
        ),
        array(
            403,
            array('error' => array(
                'status' => 'PERMISSION_DENIED',
                'reason' => 'LOCATION_NOT_SUPPORTED',
                'message' => 'This API is not available in the request location.',
            )),
            'provider_location_restricted',
            502,
        ),
        array(
            403,
            array('error' => array(
                'status' => 'PERMISSION_DENIED',
                'message' => 'The caller does not have permission to use this service.',
            )),
            'provider_access_denied',
            502,
        ),
        array(
            429,
            array('error' => array(
                'status' => 'RESOURCE_EXHAUSTED',
                'message' => 'Quota exceeded for the current rate limit.',
            )),
            'provider_quota_exhausted',
            503,
        ),
    );

    foreach ($cases as [$status, $body, $expectedCode, $expectedStatus]) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response($body, $status);
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array(), 'System')
            );
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
        assert_same($expectedCode, $error->publicCode);
        assert_same($expectedStatus, $error->httpStatus);
    }
});

test('Gemini client does not mistake a schema field named location for a geographic restriction', static function (): void {
    [$client] = gemini_test_client();
    $calls = 0;
    $GLOBALS['ysai_test_http_handler'] = static function () use (&$calls): array {
        ++$calls;
        return gemini_test_response(array('error' => array(
            'status' => 'INVALID_ARGUMENT',
            'message' => 'Function schema contains an invalid location property.',
        )), 400);
    };

    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(), 'System')
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(1, $calls);
    assert_same('provider_request_rejected', $error->publicCode);
    assert_same(ProviderException::RETRY_NONE, $error->retryMode);
});

test('Gemini client gives canonical status and schema signals precedence over generic message words', static function (): void {
    [$client] = gemini_test_client();
    $cases = array(
        array(
            400,
            'INVALID_ARGUMENT',
            'API key was not found while selecting the requested model.',
            'provider_request_rejected',
        ),
        array(
            403,
            'PERMISSION_DENIED',
            'The API key does not have permission to access this project model.',
            'provider_access_denied',
        ),
        array(
            403,
            'PERMISSION_DENIED',
            'Permission denied while accessing the configured model.',
            'provider_access_denied',
        ),
        array(
            400,
            'INVALID_ARGUMENT',
            'Function schema property quota is invalid for this tool.',
            'provider_request_rejected',
        ),
    );

    foreach ($cases as [$httpStatus, $status, $message, $expectedCode]) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array('error' => array(
            'status' => $status,
            'message' => $message,
        )), $httpStatus);
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array(), 'System')
            );
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
        assert_same($expectedCode, $error->publicCode);
        assert_same(ProviderException::RETRY_NONE, $error->retryMode);
    }
});

test('Gemini client gives a structured reason precedence over conflicting HTTP and provider status', static function (): void {
    [$client] = gemini_test_client();
    $cases = array(
        array(401, 'UNAUTHENTICATED', 'PERMISSION_DENIED', 'provider_access_denied', ProviderException::RETRY_NONE),
        array(403, 'PERMISSION_DENIED', 'API_KEY_INVALID', 'provider_credentials_rejected', ProviderException::RETRY_NONE),
        array(401, 'UNAUTHENTICATED', 'MODEL_NOT_FOUND', 'provider_model_unavailable', ProviderException::RETRY_NONE),
        array(400, 'INVALID_ARGUMENT', 'UNAVAILABLE', 'provider_unavailable', ProviderException::RETRY_NEW_TURN),
        array(500, 'INTERNAL', 'INVALID_ARGUMENT', 'provider_request_rejected', ProviderException::RETRY_NONE),
    );

    foreach ($cases as [$httpStatus, $status, $reason, $expectedCode, $expectedRetryMode]) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array('error' => array(
            'status' => $status,
            'reason' => $reason,
            'message' => 'Conflicting transport and provider metadata.',
        )), $httpStatus);
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array(), 'System')
            );
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
        assert_same($expectedCode, $error->publicCode);
        assert_same($expectedRetryMode, $error->retryMode);
    }
});

test('Gemini client prefers canonical nested location reasons over generic permission status', static function (): void {
    [$client] = gemini_test_client();
    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array('error' => array(
        'status' => 'PERMISSION_DENIED',
        'message' => 'Request denied.',
        'details' => array(array(
            '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
            'reason' => 'LOCATION_NOT_SUPPORTED',
        )),
    )), 403);

    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(), 'System')
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('provider_location_restricted', $error->publicCode);
});

test('Gemini client skips unknown structured reasons and classifies a later canonical reason', static function (): void {
    [$client] = gemini_test_client();
    $cases = array(
        array(
            403,
            array(
                'status' => 'PERMISSION_DENIED',
                'reason' => 'GENERIC_PROVIDER_FAILURE',
                'message' => 'Request denied.',
                'details' => array(
                    array('reason' => 'UNRECOGNIZED_FIRST_DETAIL'),
                    array(
                        '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                        'reason' => 'LOCATION_NOT_SUPPORTED',
                    ),
                ),
            ),
            'provider_location_restricted',
            ProviderException::RETRY_NONE,
        ),
        array(
            403,
            array(
                'status' => 'PERMISSION_DENIED',
                'message' => 'Request denied.',
                'details' => array(
                    array('reason' => 'UNKNOWN'),
                    array('nested' => array('reason' => 'API_KEY_INVALID')),
                ),
            ),
            'provider_credentials_rejected',
            ProviderException::RETRY_NONE,
        ),
        array(
            400,
            array(
                'status' => 'INVALID_ARGUMENT',
                'reason' => 'INVALID_ARGUMENT',
                'message' => 'Request rejected.',
                'details' => array(
                    array('reason' => 'FAILED_PRECONDITION'),
                    array('reason' => 'MODEL_NOT_FOUND'),
                ),
            ),
            'provider_model_unavailable',
            ProviderException::RETRY_NONE,
        ),
        array(
            400,
            array(
                'status' => 'INVALID_ARGUMENT',
                'message' => 'Request rejected.',
                'details' => array(
                    array('reason' => 'NOT_A_CANONICAL_REASON'),
                    array('wrapper' => array('deeper' => array('reason' => 'UNAVAILABLE'))),
                ),
            ),
            'provider_unavailable',
            ProviderException::RETRY_NEW_TURN,
        ),
    );

    foreach ($cases as [$httpStatus, $remoteError, $expectedCode, $expectedRetryMode]) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(
            array('error' => $remoteError),
            $httpStatus
        );
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array(), 'System')
            );
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
        assert_same($expectedCode, $error->publicCode);
        assert_same($expectedRetryMode, $error->retryMode);
    }
});

test('Gemini client keeps the first recognized structured reason authoritative', static function (): void {
    [$client] = gemini_test_client();
    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array('error' => array(
        'status' => 'PERMISSION_DENIED',
        'reason' => 'API_KEY_INVALID',
        'message' => 'Conflicting structured details.',
        'details' => array(
            array('reason' => 'LOCATION_NOT_SUPPORTED'),
            array('reason' => 'RESOURCE_EXHAUSTED'),
        ),
    )), 403);

    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(), 'System')
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('provider_credentials_rejected', $error->publicCode);
    assert_same(ProviderException::RETRY_NONE, $error->retryMode);
});

test('Gemini client scans sibling reasons before nested detail noise exhausts its safety bound', static function (): void {
    [$client] = gemini_test_client();
    $noise = array();
    for ($index = 0; $index < 80; ++$index) {
        $noise['field_' . $index] = 'value';
    }

    $noisyDetail = $noise;
    // Place the unknown reason after many unrelated scalar fields. Scalar
    // metadata must not consume the global array-node budget or prevent the
    // sibling ErrorInfo object from being classified.
    $noisyDetail['reason'] = 'UNKNOWN_FIRST_REASON';

    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array('error' => array(
        'status' => 'PERMISSION_DENIED',
        'message' => 'Request denied.',
        'details' => array(
            $noisyDetail,
            array(
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => 'LOCATION_NOT_SUPPORTED',
            ),
        ),
    )), 403);

    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(), 'System')
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('provider_location_restricted', $error->publicCode);
});

test('Gemini client rejects unknown interaction statuses and malformed structured output', static function (): void {
    [$client] = gemini_test_client();
    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'mystery',
        'output_text' => 'unexpected',
    ));
    try {
        assert_throws(ProviderException::class, static fn (): array => $client->interact(array(), array(), 'System'), 'unknown interaction status');
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'completed',
        'output_text' => 'not json',
    ));
    try {
        assert_throws(ProviderException::class, static fn (): array => $client->structured(
            'input',
            array('type' => 'object'),
            'System'
        ), 'invalid structured output');
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }
});

test('Gemini client rejects non-string output text instead of coercing provider data', static function (): void {
    [$client] = gemini_test_client();
    foreach (array(false, 0, array('text')) as $invalidOutput) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
            'status' => 'completed',
            'output_text' => $invalidOutput,
        ));
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array(), 'System')
            );
            assert_same('provider_protocol_error', $error->publicCode);
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
    }
});



test('Gemini client treats a null SDK convenience output as absent and reconstructs REST model text', static function (): void {
    [$client] = gemini_test_client();
    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'completed',
        'output_text' => null,
        'steps' => array(array(
            'type' => 'model_output',
            'content' => array(array('type' => 'text', 'text' => 'Reconstructed REST text.')),
        )),
    ));

    try {
        $result = $client->interact(array(), array(), 'System');
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same('Reconstructed REST text.', $result['output_text']);
});

test('Gemini client locally validates structured values after provider schema enforcement', static function (): void {
    [$client] = gemini_test_client();
    $schema = array(
        'type' => 'object',
        'properties' => array(
            'authorized' => array('type' => 'boolean'),
            'reason' => array('type' => 'string', 'maxLength' => 20),
            'fingerprint' => array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$'),
        ),
        'required' => array('authorized', 'reason', 'fingerprint'),
        'additionalProperties' => false,
    );

    foreach (array(
        array('authorized' => true, 'reason' => 'ok'),
        array('authorized' => 1, 'reason' => 'ok', 'fingerprint' => str_repeat('a', 64)),
        array('authorized' => true, 'reason' => str_repeat('x', 21), 'fingerprint' => str_repeat('a', 64)),
        array('authorized' => true, 'reason' => 'ok', 'fingerprint' => 'wrong'),
        array('authorized' => true, 'reason' => 'ok', 'fingerprint' => str_repeat('a', 64), 'extra' => true),
    ) as $invalid) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
            'status' => 'completed',
            'output_text' => json_encode($invalid, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->structured('input', $schema, 'System')
            );
            assert_same('provider_protocol_error', $error->publicCode);
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
    }
});

test('Gemini client rejects malformed local structured schemas before making a request', static function (): void {
    [$client] = gemini_test_client();
    $called = false;
    $GLOBALS['ysai_test_http_handler'] = static function () use (&$called): array {
        $called = true;
        return gemini_test_response(array('status' => 'completed', 'output_text' => '{}'));
    };
    try {
        foreach (array(
            array('type' => 'unknown'),
            array('type' => 'array', 'items' => array('type' => 'string')),
        ) as $invalidSchema) {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->structured('input', $invalidSchema, 'System')
            );
            assert_same('provider_protocol_error', $error->publicCode);
        }
        assert_false($called);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }
});


test('Gemini client rejects malformed local function schemas before making a request', static function (): void {
    [$client] = gemini_test_client();
    $called = false;
    $GLOBALS['ysai_test_http_handler'] = static function () use (&$called): array {
        $called = true;
        return gemini_test_response(array('status' => 'completed', 'output_text' => 'unexpected'));
    };

    $invalidToolSets = array(
        array(array(
            'type' => 'function',
            'name' => 'missing_properties',
            'description' => 'Invalid function declaration.',
            'parameters' => array(
                'type' => 'object',
                'additionalProperties' => false,
            ),
        )),
        array(array(
            'type' => 'function',
            'name' => 'list_properties',
            'description' => 'Invalid function declaration.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(array('type' => 'string')),
                'additionalProperties' => false,
            ),
        )),
        array(array(
            'type' => 'function',
            'name' => 'open_object',
            'description' => 'Invalid function declaration.',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(),
                'additionalProperties' => true,
            ),
        )),
        array(gemini_test_tool('duplicate_name'), gemini_test_tool('duplicate_name')),
    );

    try {
        foreach ($invalidToolSets as $tools) {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), $tools, 'System')
            );
            assert_same('provider_configuration_error', $error->publicCode);
            assert_same(503, $error->httpStatus);
        }
        assert_false($called);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }
});

test('Gemini client preserves empty JSON container types at provider boundaries', static function (): void {
    [$client] = gemini_test_client();
    $schema = array('type' => 'object', 'additionalProperties' => false);

    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'completed',
        'output_text' => '[]',
    ));
    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->structured('input', $schema, 'System')
        );
        assert_same('provider_protocol_error', $error->publicCode);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'requires_action',
        'steps' => array(array(
            'type' => 'function_call',
            'id' => 'empty-list-arguments',
            'name' => 'store_info',
            'arguments' => array(),
        )),
    ));
    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->interact(array(), array(gemini_test_tool('store_info')), 'System')
        );
        assert_same('provider_protocol_error', $error->publicCode);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'requires_action',
        'steps' => array(array(
            'type' => 'function_call',
            'id' => 'empty-object-arguments',
            'name' => 'store_info',
            'arguments' => (object) array(),
        )),
    ));
    try {
        $result = $client->interact(array(), array(gemini_test_tool('store_info')), 'System');
        assert_same(array(), $result['steps'][0]['arguments']);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }
});


test('Gemini client rejects duplicate JSON members in responses and structured output', static function (): void {
    [$client] = gemini_test_client();
    $rawResponses = array(
        '{"status":"completed","status":"requires_action","steps":[]}',
        '{"status":"requires_action","steps":[{"type":"function_call","id":"call-1","name":"store_info","arguments":{"value":1,"\u0076alue":2}}]}',
    );

    foreach ($rawResponses as $body) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => array(
            'response' => array('code' => 200),
            'body' => $body,
        );
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array(), 'System')
            );
            assert_same('provider_protocol_error', $error->publicCode);
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
    }

    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'completed',
        'output_text' => '{"authorized":true,"\u0061uthorized":false}',
    ));
    try {
        $error = assert_throws(
            ProviderException::class,
            static fn (): array => $client->structured(
                'input',
                array(
                    'type' => 'object',
                    'properties' => array('authorized' => array('type' => 'boolean')),
                    'required' => array('authorized'),
                    'additionalProperties' => false,
                ),
                'System'
            )
        );
        assert_same('provider_protocol_error', $error->publicCode);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }
});

test('Gemini client accepts only requires_action responses with valid object function calls', static function (): void {
    [$client] = gemini_test_client();
    $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response(array(
        'status' => 'requires_action',
        'steps' => array(array(
            'type' => 'function_call',
            'id' => 'call-1',
            'name' => 'catalog_discover',
            'arguments' => array('query' => 'shoes'),
        )),
    ));
    try {
        $result = $client->interact(
            array(),
            array(gemini_test_tool(
                'catalog_discover',
                array('query' => array('type' => 'string', 'minLength' => 1)),
                array('query')
            )),
            'System'
        );
        assert_same('requires_action', $result['status']);
        assert_same('catalog_discover', $result['steps'][0]['name']);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }
});

test('Gemini client rejects function-call status mismatches and malformed call envelopes', static function (): void {
    [$client] = gemini_test_client();
    $invalidResponses = array(
        array(
            'status' => 'completed',
            'steps' => array(array(
                'type' => 'function_call', 'id' => 'call-1', 'name' => 'catalog_discover', 'arguments' => array('query' => 'x'),
            )),
        ),
        array('status' => 'requires_action', 'steps' => array()),
        array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call', 'id' => 'call-1', 'name' => 'Catalog-Discover', 'arguments' => array('query' => 'x'),
            )),
        ),
        array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call', 'id' => 'call-1', 'name' => 'catalog_discover', 'arguments' => array('list-value'),
            )),
        ),
        array(
            'status' => 'requires_action',
            'steps' => array(
                array('type' => 'function_call', 'id' => 'same', 'name' => 'catalog_discover', 'arguments' => array()),
                array('type' => 'function_call', 'id' => 'same', 'name' => 'store_info', 'arguments' => array()),
            ),
        ),
        array('steps' => array(), 'output_text' => 'missing status'),
        array('status' => array('completed'), 'steps' => array(), 'output_text' => 'bad status'),
    );

    foreach ($invalidResponses as $response) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response($response);
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(
                    array(),
                    array(
                        gemini_test_tool(
                            'catalog_discover',
                            array('query' => array('type' => 'string', 'minLength' => 1)),
                            array('query')
                        ),
                        gemini_test_tool('store_info')
                    ),
                    'System'
                )
            );
            assert_same('provider_protocol_error', $error->publicCode);
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
    }
});



test('Gemini client rejects undeclared functions and schema-invalid function arguments', static function (): void {
    [$client] = gemini_test_client();
    $tool = gemini_test_tool(
        'catalog_discover',
        array('query' => array('type' => 'string', 'minLength' => 2, 'maxLength' => 40)),
        array('query')
    );
    $invalidResponses = array(
        array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'undeclared-call',
                'name' => 'unknown_tool',
                'arguments' => (object) array(),
            )),
        ),
        array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'missing-required',
                'name' => 'catalog_discover',
                'arguments' => (object) array(),
            )),
        ),
        array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'wrong-type',
                'name' => 'catalog_discover',
                'arguments' => array('query' => 7),
            )),
        ),
        array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'unexpected-property',
                'name' => 'catalog_discover',
                'arguments' => array('query' => 'shoes', 'hidden' => true),
            )),
        ),
    );

    foreach ($invalidResponses as $response) {
        $GLOBALS['ysai_test_http_handler'] = static fn (): array => gemini_test_response($response);
        try {
            $error = assert_throws(
                ProviderException::class,
                static fn (): array => $client->interact(array(), array($tool), 'System')
            );
            assert_same('provider_protocol_error', $error->publicCode);
        } finally {
            $GLOBALS['ysai_test_http_handler'] = null;
        }
    }
});

test('Gemini client serializes the exact production zero-argument tools with JSON object properties', static function (): void {
    [$client] = gemini_test_client();
    $provider = new ScriptedAiProvider();
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $registry = new ToolRegistry(
        new TestCatalog(),
        new TestCart(),
        new TestContent(),
        $conversations,
        new IntentVerifier($provider),
        new TestSettings(),
        new ShoppingMemoryPolicy()
    );

    $capturedBody = '';
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$capturedBody): array {
        $capturedBody = (string) $arguments['body'];
        return gemini_test_response(array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'zero-argument-contract-probe',
                'name' => 'store_info',
                'arguments' => (object) array(),
            )),
        ));
    };
    try {
        $client->interact(
            array(array('type' => 'user_input', 'content' => array(array('type' => 'text', 'text' => 'probe')))),
            $registry->schemas(),
            'System.'
        );
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_false(str_contains($capturedBody, '"properties":[]'));
    $payload = json_decode($capturedBody, false, 512, JSON_THROW_ON_ERROR);
    assert_true($payload instanceof stdClass);
    $tools = array();
    foreach ($payload->tools as $tool) {
        $tools[$tool->name] = $tool;
    }
    foreach (array('store_policy', 'store_info', 'cart_view', 'checkout_get_url') as $name) {
        assert_true(isset($tools[$name]));
        assert_true($tools[$name]->parameters->properties instanceof stdClass);
        assert_same(array(), get_object_vars($tools[$name]->parameters->properties));
    }
});

test('Gemini client preserves raw empty function arguments across stateless tool rounds', static function (): void {
    [$client] = gemini_test_client();
    $calls = 0;
    $secondBody = '';
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$calls, &$secondBody): array {
        ++$calls;
        if ($calls === 1) {
            return gemini_test_response(array(
                'status' => 'requires_action',
                'steps' => array(array(
                    'type' => 'function_call',
                    'id' => 'call/empty=object+1',
                    'name' => 'store_info',
                    'arguments' => (object) array(),
                )),
            ));
        }
        $secondBody = (string) $arguments['body'];
        return gemini_test_response(array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'call/second=object+2',
                'name' => 'store_info',
                'arguments' => (object) array(),
            )),
        ));
    };

    $tool = array(
        'type' => 'function',
        'name' => 'store_info',
        'description' => 'Return store information.',
        'parameters' => array(
            'type' => 'object',
            'properties' => array(),
            'additionalProperties' => false,
        ),
    );
    $initial = array(array('type' => 'user_input', 'content' => array(array('type' => 'text', 'text' => 'store'))));
    try {
        $first = $client->interact($initial, array($tool), 'System.');
        assert_true(($first['_wire_steps'][0] ?? null) instanceof stdClass);
        $history = $initial;
        $history[] = $first['_wire_steps'][0];
        $history[] = array(
            'type' => 'function_result',
            'name' => 'store_info',
            'call_id' => 'call/empty=object+1',
            'result' => array(array('type' => 'text', 'text' => '{"ok":true}')),
        );
        $client->interact($history, array($tool), 'System.');
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    $payload = json_decode($secondBody, false, 512, JSON_THROW_ON_ERROR);
    assert_same('call/empty=object+1', $payload->input[2]->call_id);
    assert_true($payload->input[1]->arguments instanceof stdClass);
    assert_same(array(), get_object_vars($payload->input[1]->arguments));
});

test('Gemini readiness checks the exact production tool bundle and function transport', static function (): void {
    [$client] = gemini_test_client();
    $provider = new ScriptedAiProvider();
    $clock = new TestClock();
    $conversations = new InMemoryConversationRepository($clock);
    $registry = new ToolRegistry(
        new TestCatalog(),
        new TestCart(),
        new TestContent(),
        $conversations,
        new IntentVerifier($provider),
        new TestSettings(),
        new ShoppingMemoryPolicy()
    );

    $calls = 0;
    $toolProbeBody = '';
    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$calls, &$toolProbeBody): array {
        ++$calls;
        if ($calls === 1) {
            return gemini_test_response(array(
                'status' => 'completed',
                'output_text' => '{"ready":true,"message":"ready"}',
            ));
        }
        $toolProbeBody = (string) $arguments['body'];
        return gemini_test_response(array(
            'status' => 'requires_action',
            'steps' => array(array(
                'type' => 'function_call',
                'id' => 'readiness-terminal',
                'name' => 'respond_safe_failure',
                'arguments' => (object) array('message' => 'ready'),
            )),
        ));
    };
    try {
        $result = $client->readinessCheck($registry->schemas());
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(2, $calls);
    assert_same(true, $result['ready']);
    assert_same(true, $result['structured_output_ready']);
    assert_same(true, $result['chat_tools_ready']);
    assert_false(str_contains($toolProbeBody, '"properties":[]'));
    $payload = json_decode($toolProbeBody, false, 512, JSON_THROW_ON_ERROR);
    assert_same('respond_safe_failure', $payload->generation_config->tool_choice->allowed_tools->tools[0]);
});
