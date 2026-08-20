<?php

declare(strict_types=1);

test('End-to-end chat flow uses the exact production Gemini tool contract and stateless replay', static function (): void {
    [$provider, $settings] = gemini_test_client();
    $built = build_chat_service_test(provider: $provider, settings: $settings);
    $calls = 0;
    $capturedBodies = array();

    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$calls, &$capturedBodies): array {
        ++$calls;
        assert_same('https://generativelanguage.googleapis.com/v1/interactions', $url);
        $body = (string) ($arguments['body'] ?? '');
        $capturedBodies[] = $body;
        assert_false(str_contains($body, '"properties":[]'));

        $payload = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        assert_true($payload instanceof stdClass);
        assert_same(false, $payload->store);
        assert_same(false, $payload->stream);
        assert_same('any', $payload->generation_config->tool_choice);
        assert_true(is_array($payload->tools));
        foreach (array('minLength', 'maxLength', 'pattern', 'minProperties', 'maxProperties') as $keyword) {
            assert_false(str_contains($body, '"' . $keyword . '"'));
        }

        $tools = array();
        foreach ($payload->tools as $tool) {
            assert_true($tool instanceof stdClass);
            $tools[$tool->name] = $tool;
        }
        foreach (array('store_policy', 'store_info', 'cart_view', 'checkout_get_url') as $name) {
            assert_true(isset($tools[$name]));
            assert_true($tools[$name]->parameters->properties instanceof stdClass);
            assert_same(array(), get_object_vars($tools[$name]->parameters->properties));
        }
        foreach (array('respond_answer', 'respond_follow_up') as $name) {
            assert_true(isset($tools[$name]));
            $productRefs = $tools[$name]->parameters->properties->product_refs ?? null;
            assert_true($productRefs instanceof stdClass);
            assert_same(0, $productRefs->minItems);
            assert_same(12, $productRefs->maxItems);
        }

        if ($calls === 1) {
            assert_count_value(1, $payload->input);
            $request = json_decode((string) $payload->input[0]->content[0]->text, true, 32, JSON_THROW_ON_ERROR);
            assert_same('أعطني معلومات المتجر', $request['message']);
            assert_same(array(), $request['conversation_history']);

            return gemini_test_response(array(
                'status' => 'requires_action',
                'steps' => array(
                    array(
                        'type' => 'thought',
                        'signature' => 'thought_signature_01',
                    ),
                    array(
                        'type' => 'function_call',
                        'id' => 'call_store_info_01',
                        'name' => 'store_info',
                        'arguments' => (object) array(),
                    ),
                ),
            ));
        }

        if ($calls === 2) {
            assert_count_value(4, $payload->input);
            assert_same('user_input', $payload->input[0]->type);
            assert_same('thought', $payload->input[1]->type);
            assert_same('thought_signature_01', $payload->input[1]->signature);
            assert_same('function_call', $payload->input[2]->type);
            assert_true($payload->input[2]->arguments instanceof stdClass);
            assert_same(array(), get_object_vars($payload->input[2]->arguments));
            assert_same('function_result', $payload->input[3]->type);
            assert_same('call_store_info_01', $payload->input[3]->call_id);

            return gemini_test_response(array(
                'status' => 'requires_action',
                'steps' => array(array(
                    'type' => 'function_call',
                    'id' => 'call_answer_01',
                    'name' => 'respond_answer',
                    'arguments' => (object) array(
                        'message' => 'اسم المتجر هو Test Store وعملته USD.',
                    ),
                )),
            ));
        }

        if ($calls === 3) {
            assert_count_value(1, $payload->input);
            assert_false(str_contains($body, '"type":"model_output"'));
            $request = json_decode((string) $payload->input[0]->content[0]->text, true, 32, JSON_THROW_ON_ERROR);
            assert_same('وما العملة؟', $request['message']);
            assert_count_value(2, $request['conversation_history']);
            assert_same('user', $request['conversation_history'][0]['role']);
            assert_same('assistant', $request['conversation_history'][1]['role']);
            assert_same('اسم المتجر هو Test Store وعملته USD.', $request['conversation_history'][1]['text']);

            return gemini_test_response(array(
                'status' => 'requires_action',
                'steps' => array(array(
                    'type' => 'function_call',
                    'id' => 'call_answer_02',
                    'name' => 'respond_answer',
                    'arguments' => (object) array(
                        'message' => 'عملة المتجر هي USD.',
                    ),
                )),
            ));
        }

        throw new RuntimeException('Unexpected Gemini request in the end-to-end chat test.');
    };

    try {
        $first = $built['service']->chat(chat_request_for(
            $built['credentials'],
            'turn_provider_wire_0001',
            'أعطني معلومات المتجر'
        ));
        $second = $built['service']->chat(chat_request_for(
            $built['credentials'],
            'turn_provider_wire_0002',
            'وما العملة؟'
        ));
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(3, $calls);
    assert_same(200, $first['status']);
    assert_same(true, $first['body']['ok']);
    assert_same('اسم المتجر هو Test Store وعملته USD.', $first['body']['message']);
    assert_same(true, $first['body']['turn_finalized']);
    assert_same(200, $second['status']);
    assert_same(true, $second['body']['ok']);
    assert_same('عملة المتجر هي USD.', $second['body']['message']);
    assert_same(true, $second['body']['turn_finalized']);
    assert_count_value(4, $built['conversations']->storedMessages);
    assert_same('user', $built['conversations']->storedMessages[0]['role']);
    assert_same('assistant', $built['conversations']->storedMessages[1]['role']);
    assert_same('user', $built['conversations']->storedMessages[2]['role']);
    assert_same('assistant', $built['conversations']->storedMessages[3]['role']);
});

test('End-to-end chat flow durably presents a function-only provider protocol failure', static function (): void {
    [$provider, $settings] = gemini_test_client();
    $built = build_chat_service_test(provider: $provider, settings: $settings);
    $httpCalls = 0;
    $capturedPayload = array();

    $GLOBALS['ysai_test_http_handler'] = static function (string $url, array $arguments) use (&$httpCalls, &$capturedPayload): array {
        ++$httpCalls;
        $capturedPayload = json_decode((string) $arguments['body'], true, 512, JSON_THROW_ON_ERROR);
        return gemini_test_response(array(
            'status' => 'completed',
            'steps' => array(array(
                'type' => 'model_output',
                'content' => array(array('type' => 'text', 'text' => 'Direct prose that violates the terminal-function contract.')),
            )),
        ));
    };

    $request = chat_request_for(
        $built['credentials'],
        'turn_provider_function_required_0001',
        'ما اسم المتجر؟'
    );
    try {
        $first = $built['service']->chat($request);
        $second = $built['service']->chat($request);
    } finally {
        $GLOBALS['ysai_test_http_handler'] = null;
    }

    assert_same(1, $httpCalls);
    assert_same('any', $capturedPayload['generation_config']['tool_choice']);
    assert_same(502, $first['status']);
    assert_same(false, $first['body']['ok']);
    assert_same('provider_protocol_error', $first['body']['error']['code']);
    assert_same(true, $first['body']['request_accepted']);
    assert_same(true, $first['body']['turn_finalized']);
    assert_true((int) ($first['body']['message_id'] ?? 0) > 0);
    assert_same(502, $second['status']);
    assert_same(true, $second['body']['replayed']);
    assert_same($first['body']['message_id'], $second['body']['message_id']);
    assert_count_value(2, $built['conversations']->storedMessages);
    assert_same('user', $built['conversations']->storedMessages[0]['role']);
    assert_same('assistant', $built['conversations']->storedMessages[1]['role']);
});

