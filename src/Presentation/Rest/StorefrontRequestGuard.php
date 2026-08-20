<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Chat\PublicException;
use YassinStore\AiAssistant\Infrastructure\Support\StrictJson;
use YassinStore\AiAssistant\Infrastructure\WordPress\SameOriginUrl;

final class StorefrontRequestGuard
{
    public const MAX_REQUEST_BYTES = 6_000_000;
    private const MAX_JSON_DEPTH = 32;
    private const MAX_JSON_NODES = 100_000;

    public function __construct(private readonly SameOriginUrl $urls)
    {
    }

    /**
     * Validate the browser boundary and decode a strict top-level JSON object.
     *
     * @param list<string> $allowedKeys
     * @return array<string,mixed>
     */
    public function payload(\WP_REST_Request $request, array $allowedKeys): array
    {
        $this->assertPublicWrite($request);

        $raw = trim((string) $request->get_body());
        if ($raw === '') {
            return array();
        }
        if (!str_starts_with($raw, '{')) {
            throw new PublicException('يجب أن يكون جسم الطلب كائن JSON.', 'invalid_json', 400);
        }

        try {
            $pair = StrictJson::decodePair($raw, self::MAX_JSON_DEPTH, self::MAX_JSON_NODES);
        } catch (\JsonException) {
            throw new PublicException('تعذّر قراءة جسم JSON فريد وغير ملتبس.', 'invalid_json', 400);
        }
        $rawDecoded = $pair['raw'];
        $decoded = $pair['associative'];
        if (!$rawDecoded instanceof \stdClass || !is_array($decoded)) {
            throw new PublicException('يجب أن يكون جسم الطلب كائن JSON.', 'invalid_json', 400);
        }

        $allowed = array_fill_keys($allowedKeys, true);
        foreach ($decoded as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new PublicException('يتضمن الطلب حقلاً غير مدعوم.', 'unknown_request_field', 422);
            }
        }
        return $decoded;
    }

    public function assertPublicWrite(\WP_REST_Request $request): void
    {
        $contentType = trim((string) $request->get_header('content-type'));
        if (preg_match('/^application\/json(?:\s*;\s*charset\s*=\s*(?:utf-8|"utf-8"))?$/iD', $contentType) !== 1) {
            throw new PublicException('نوع محتوى الطلب غير مدعوم.', 'json_required', 415);
        }

        $contentEncoding = strtolower(trim((string) $request->get_header('content-encoding')));
        if ($contentEncoding !== '' && $contentEncoding !== 'identity') {
            throw new PublicException('ترميز جسم الطلب غير مدعوم.', 'content_encoding_rejected', 415);
        }

        $contentLength = trim((string) $request->get_header('content-length'));
        if ($contentLength !== '') {
            if (preg_match('/^\d+$/D', $contentLength) !== 1) {
                throw new PublicException('طول الطلب غير صالح.', 'invalid_content_length', 400);
            }
            $normalizedLength = ltrim($contentLength, '0');
            $normalizedLength = $normalizedLength === '' ? '0' : $normalizedLength;
            $maximumLength = (string) self::MAX_REQUEST_BYTES;
            if (strlen($normalizedLength) > strlen($maximumLength)
                || (strlen($normalizedLength) === strlen($maximumLength)
                    && strcmp($normalizedLength, $maximumLength) > 0)) {
                throw new PublicException('حجم الطلب أكبر من الحد المسموح.', 'request_too_large', 413);
            }
        }

        $body = $request->get_body();
        if (!is_string($body) || strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new PublicException('حجم الطلب أكبر من الحد المسموح.', 'request_too_large', 413);
        }

        $fetchSite = strtolower(trim((string) $request->get_header('sec-fetch-site')));
        if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
            throw new PublicException('تم رفض طلب من مصدر مختلف.', 'cross_origin_rejected', 403);
        }

        $origin = trim((string) $request->get_header('origin'));
        $referer = trim((string) $request->get_header('referer'));
        if ($origin === '' && $referer === '' && $fetchSite !== 'same-origin') {
            throw new PublicException('تعذّر التحقق من مصدر الطلب.', 'origin_required', 403);
        }
        foreach (array($origin, $referer) as $source) {
            if ($source !== '' && !$this->urls->isSameOrigin($source)) {
                throw new PublicException('تم رفض طلب من مصدر مختلف.', 'cross_origin_rejected', 403);
            }
        }
    }
}
