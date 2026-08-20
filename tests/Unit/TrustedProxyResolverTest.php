<?php

declare(strict_types=1);

use YassinStore\AiAssistant\Infrastructure\Security\RequestIdentity;
use YassinStore\AiAssistant\Infrastructure\Security\TrustedProxyResolver;

/** @param array<string,mixed> $values */
function proxy_resolver_for(array $values): TrustedProxyResolver
{
    return new TrustedProxyResolver(new TestSettings(array_replace(array(
        'trusted_proxy_cidrs' => '',
        'trusted_proxy_header' => TrustedProxyResolver::HEADER_X_FORWARDED_FOR,
    ), $values)));
}

test('TrustedProxyResolver ignores forwarding headers until the immediate peer is explicitly trusted', static function (): void {
    $resolver = proxy_resolver_for(array());
    $resolution = $resolver->resolve(array(
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
    ));

    assert_same('203.0.113.9', $resolution->clientAddress);
    assert_same('203.0.113.9', $resolution->peerAddress);
    assert_same('remote-addr', $resolution->source);
    assert_false($resolution->peerTrusted);
    assert_true($resolution->forwardingHeaderPresent);
    assert_false($resolution->forwardingAccepted);
    assert_same('forwarding_headers_unconfigured', $resolution->issue);
    assert_same('warning', $resolver->diagnostic(array(
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.77',
    ))['status']);
});

test('TrustedProxyResolver walks an authenticated X-Forwarded-For chain from the trusted edge inward', static function (): void {
    $resolver = proxy_resolver_for(array(
        'trusted_proxy_cidrs' => "10.0.0.0/8\n192.0.2.0/24",
    ));
    $resolution = $resolver->resolve(array(
        'REMOTE_ADDR' => '10.20.30.40',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.50, 192.0.2.44',
    ));

    assert_same('198.51.100.50', $resolution->clientAddress);
    assert_same('10.20.30.40', $resolution->peerAddress);
    assert_same(TrustedProxyResolver::HEADER_X_FORWARDED_FOR, $resolution->source);
    assert_true($resolution->peerTrusted);
    assert_true($resolution->forwardingAccepted);
    assert_same(null, $resolution->issue);
    assert_same('trusted_proxy_forwarding_active', $resolver->diagnostic(array(
        'REMOTE_ADDR' => '10.20.30.40',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.50, 192.0.2.44',
    ))['code']);
});

test('TrustedProxyResolver ignores a spoofed chain received from an untrusted peer', static function (): void {
    $resolver = proxy_resolver_for(array('trusted_proxy_cidrs' => '10.0.0.0/8'));
    $resolution = $resolver->resolve(array(
        'REMOTE_ADDR' => '203.0.113.20',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 10.1.1.1',
    ));

    assert_same('203.0.113.20', $resolution->clientAddress);
    assert_false($resolution->peerTrusted);
    assert_false($resolution->forwardingAccepted);
    assert_same('untrusted_peer_forwarding_ignored', $resolution->issue);
});

test('TrustedProxyResolver parses RFC Forwarded IPv6 nodes and trusted intermediaries', static function (): void {
    $resolver = proxy_resolver_for(array(
        'trusted_proxy_cidrs' => "2001:db8:abcd::/48\n192.0.2.0/24",
        'trusted_proxy_header' => TrustedProxyResolver::HEADER_FORWARDED,
    ));
    $resolution = $resolver->resolve(array(
        'REMOTE_ADDR' => '2001:db8:abcd::10',
        'HTTP_FORWARDED' => 'for=198.51.100.11;proto=https, for="[2001:db8:abcd::20]:443";by=192.0.2.8',
    ));

    assert_same('198.51.100.11', $resolution->clientAddress);
    assert_same(TrustedProxyResolver::HEADER_FORWARDED, $resolution->source);
    assert_true($resolution->forwardingAccepted);
});

test('TrustedProxyResolver fails closed on malformed, ambiguous, and excessive forwarding input', static function (): void {
    $resolver = proxy_resolver_for(array('trusted_proxy_cidrs' => '10.0.0.0/8'));
    $cases = array(
        'unknown',
        '198.51.100.1,,192.0.2.1',
        implode(',', array_fill(0, 17, '192.0.2.1')),
        str_repeat('1', 4097),
        "198.51.100.1\r\nX-Forged: yes",
    );

    foreach ($cases as $header) {
        $resolution = $resolver->resolve(array(
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => $header,
        ));
        assert_same('10.0.0.2', $resolution->clientAddress);
        assert_false($resolution->forwardingAccepted);
        assert_same('invalid_forwarding_header', $resolution->issue);
    }

    $forwarded = proxy_resolver_for(array(
        'trusted_proxy_cidrs' => '10.0.0.0/8',
        'trusted_proxy_header' => TrustedProxyResolver::HEADER_FORWARDED,
    ));
    $ambiguous = $forwarded->resolve(array(
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_FORWARDED' => 'for=198.51.100.1;for=203.0.113.1',
    ));
    assert_same('invalid_forwarding_header', $ambiguous->issue);
});

test('TrustedProxyResolver canonicalizes CIDRs, masks host bits, and removes duplicates', static function (): void {
    assert_same(
        "10.0.0.0/8\n2001:db8:abcd::/48\n192.0.2.1/32",
        TrustedProxyResolver::normalizeCidrText(
            "10.1.2.3/8, 10.0.0.0/8\n2001:db8:abcd:1234::1/48\n::ffff:192.0.2.1"
        )
    );

    assert_throws(
        InvalidArgumentException::class,
        static fn (): string => TrustedProxyResolver::normalizeCidrText('10.0.0.1/33')
    );
    assert_throws(
        InvalidArgumentException::class,
        static fn (): string => TrustedProxyResolver::normalizeCidrText('0.0.0.0/0')
    );
    assert_throws(
        InvalidArgumentException::class,
        static fn (): string => TrustedProxyResolver::normalizeCidrText('::/0')
    );
    assert_throws(
        InvalidArgumentException::class,
        static fn (): string => TrustedProxyResolver::normalizeCidrText(str_repeat("10.0.0.1\n", 65))
    );
});

test('TrustedProxyResolver diagnostics distinguish missing, mismatched, invalid, and active configurations', static function (): void {
    $resolver = proxy_resolver_for(array('trusted_proxy_cidrs' => '10.0.0.0/8'));
    assert_same('trusted_proxy_header_missing', $resolver->diagnostic(array(
        'REMOTE_ADDR' => '10.0.0.5',
    ))['code']);
    assert_same('trusted_proxy_header_mismatch', $resolver->diagnostic(array(
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_FORWARDED' => 'for=198.51.100.1',
    ))['code']);

    $invalid = proxy_resolver_for(array('trusted_proxy_cidrs' => 'not-a-cidr'));
    $diagnostic = $invalid->diagnostic(array('REMOTE_ADDR' => '10.0.0.5'));
    assert_same('error', $diagnostic['status']);
    assert_same('invalid_trusted_proxy_configuration', $diagnostic['code']);

    $missingPeer = $resolver->diagnostic(array('REMOTE_ADDR' => 'invalid'));
    assert_same('error', $missingPeer['status']);
    assert_same('remote_address_invalid', $missingPeer['code']);
});

test('RequestIdentity uses only the resolver-authenticated client network', static function (): void {
    $resolver = proxy_resolver_for(array('trusted_proxy_cidrs' => '10.0.0.0/8'));
    $identity = new RequestIdentity($resolver);
    $originalServer = $_SERVER;
    try {
        $_SERVER = array(
            'REMOTE_ADDR' => '10.0.0.3',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        );
        $first = $identity->browserBucket();
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.11';
        $second = $identity->browserBucket();
        assert_false(hash_equals($first, $second));

        $_SERVER = array(
            'REMOTE_ADDR' => '203.0.113.8',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        );
        $untrustedFirst = $identity->browserBucket();
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.99';
        $untrustedSecond = $identity->browserBucket();
        assert_same($untrustedFirst, $untrustedSecond);

        $_SERVER = array(
            'REMOTE_ADDR' => '10.0.0.3',
            'HTTP_X_FORWARDED_FOR' => '2001:db8:1234:5678:1111:2222:3333:4444',
        );
        $ipv6First = $identity->browserBucket();
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8:1234:5678:aaaa:bbbb:cccc:dddd';
        $ipv6Second = $identity->browserBucket();
        assert_same($ipv6First, $ipv6Second);
    } finally {
        $_SERVER = $originalServer;
    }
});
