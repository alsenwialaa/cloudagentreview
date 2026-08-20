<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Application\Chat\PublicException;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;
use YassinStore\AiAssistant\Presentation\Rest\StorefrontRequestGuard;

/** @param array<string,string> $headers */
function guarded_request(string $body, array $headers = array()): WP_REST_Request
{
    $request = new WP_REST_Request($body);
    foreach (array_replace(array(
        'content-type' => 'application/json; charset=utf-8',
        'origin' => 'https://shop.example.test',
        'sec-fetch-site' => 'same-origin',
    ), $headers) as $name => $value) {
        if ($value !== '') {
            $request->set_header($name, $value);
        }
    }
    return $request;
}

test('Storefront request guard accepts a bounded same-origin JSON object', static function (): void {
    $guard = new StorefrontRequestGuard(new SameOriginUrl(home_url('/')));
    $payload = $guard->payload(
        guarded_request('{"conversation_id":"abc","token":"secret"}'),
        array('conversation_id', 'token')
    );
    assert_same('abc', $payload['conversation_id']);
    assert_same('secret', $payload['token']);
});

test('Storefront request guard rejects ambiguous content types and unverified origins', static function (): void {
    $guard = new StorefrontRequestGuard(new SameOriginUrl(home_url('/')));

    $wrongType = guarded_request('{}', array('content-type' => 'application/jsonp'));
    $error = assert_throws(PublicException::class, static fn (): array => $guard->payload($wrongType, array()));
    assert_same('json_required', $error->publicCode);

    $missingSource = guarded_request('{}', array('origin' => '', 'sec-fetch-site' => ''));
    $error = assert_throws(PublicException::class, static fn (): array => $guard->payload($missingSource, array()));
    assert_same('origin_required', $error->publicCode);

    $crossOrigin = guarded_request('{}', array('origin' => 'https://evil.example.test', 'sec-fetch-site' => 'cross-site'));
    $error = assert_throws(PublicException::class, static fn (): array => $guard->payload($crossOrigin, array()));
    assert_same('cross_origin_rejected', $error->publicCode);
});

test('Storefront request guard accepts browser fetch metadata when origin headers are withheld', static function (): void {
    $guard = new StorefrontRequestGuard(new SameOriginUrl(home_url('/')));
    $request = guarded_request('{}', array('origin' => '', 'sec-fetch-site' => 'same-origin'));
    assert_same(array(), $guard->payload($request, array()));
});

test('Storefront request guard rejects unknown fields, non-object JSON, and excessive declared size', static function (): void {
    $guard = new StorefrontRequestGuard(new SameOriginUrl(home_url('/')));

    $unknown = guarded_request('{"token":"x","unexpected":true}');
    $error = assert_throws(PublicException::class, static fn (): array => $guard->payload($unknown, array('token')));
    assert_same('unknown_request_field', $error->publicCode);

    $list = guarded_request('[1,2,3]');
    $error = assert_throws(PublicException::class, static fn (): array => $guard->payload($list, array()));
    assert_same('invalid_json', $error->publicCode);

    $large = guarded_request('{}', array('content-length' => (string) (StorefrontRequestGuard::MAX_REQUEST_BYTES + 1)));
    $error = assert_throws(PublicException::class, static fn (): array => $guard->payload($large, array()));
    assert_same('request_too_large', $error->publicCode);

    $overflow = guarded_request('{}', array('content-length' => str_repeat('9', 128)));
    $error = assert_throws(PublicException::class, static fn (): array => $guard->payload($overflow, array()));
    assert_same('request_too_large', $error->publicCode);

    $leadingZeros = guarded_request('{}', array('content-length' => str_repeat('0', 128) . '2'));
    assert_same(array(), $guard->payload($leadingZeros, array()));
});


test('Storefront request guard rejects duplicate JSON keys at every depth', static function (): void {
    $guard = new StorefrontRequestGuard(new SameOriginUrl(home_url('/')));
    foreach (array(
        '{"token":"first","token":"second"}',
        '{"reply":{"message_id":1,"\u006dessage_id":2}}',
        '{"image":{"mime_type":"image/png","mime_type":"image/jpeg"}}',
    ) as $json) {
        $error = assert_throws(
            PublicException::class,
            static fn (): array => $guard->payload($json === '' ? guarded_request('{}') : guarded_request($json), array('token', 'reply', 'image'))
        );
        assert_same('invalid_json', $error->publicCode);
    }
});

test('SameOriginUrl rejects credentials, alternate ports, backslashes, and foreign hosts', static function (): void {
    $urls = new SameOriginUrl('https://shop.example.test/');
    assert_same('https://shop.example.test/product/1', $urls->sanitize('https://shop.example.test/product/1'));
    assert_same('', $urls->sanitize('https://user:pass@shop.example.test/product'));
    assert_same('', $urls->sanitize('https://shop.example.test:444/product'));
    assert_same('', $urls->sanitize('https://shop.example.test\\@evil.example/product'));
    assert_same('', $urls->sanitize('https://evil.example.test/product'));
});
