<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Security;

final readonly class ImageInput
{
    private const MAX_BYTES = 4_194_304;
    private const MAX_DIMENSION = 4096;
    private const MAX_PIXELS = 12_000_000;
    private const ALLOWED = array('image/jpeg', 'image/png', 'image/webp');

    private function __construct(
        public string $mimeType,
        public string $base64,
        public string $sha256,
        public int $bytes,
        public int $width,
        public int $height
    ) {
    }

    /** @param mixed $input */
    public static function fromRequest(mixed $input, bool $allowed): ?self
    {
        if ($input === null || $input === array()) {
            return null;
        }
        if (!$allowed) {
            throw new \InvalidArgumentException('Image input is disabled.');
        }
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Invalid image payload.');
        }

        foreach ($input as $key => $_value) {
            if (!is_string($key) || !in_array($key, array('mime_type', 'data'), true)) {
                throw new \InvalidArgumentException('The image payload contains an unsupported field.');
            }
        }
        if (!is_string($input['mime_type'] ?? null) || !is_string($input['data'] ?? null)) {
            throw new \InvalidArgumentException('Invalid image payload.');
        }

        $mime = strtolower(trim($input['mime_type']));
        $base64 = $input['data'];
        if (!in_array($mime, self::ALLOWED, true) || $base64 === '') {
            throw new \InvalidArgumentException('Only JPEG, PNG, and WebP images are accepted.');
        }
        if (strlen($base64) > (int) ceil(self::MAX_BYTES * 4 / 3) + 16) {
            throw new \InvalidArgumentException('The image is too large.');
        }

        $bytes = base64_decode($base64, true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The image is invalid or too large.');
        }

        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || !isset($info[0], $info[1], $info['mime'])) {
            throw new \InvalidArgumentException('The image could not be decoded.');
        }
        if (strtolower((string) $info['mime']) !== $mime) {
            throw new \InvalidArgumentException('The declared image type does not match its content.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1
            || $height < 1
            || $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
            || $width > intdiv(self::MAX_PIXELS, $height)) {
            throw new \InvalidArgumentException('The image dimensions are not supported.');
        }

        // Canonicalize the transport form so equivalent byte payloads have the
        // same idempotency hash even if a client used a non-canonical base64 form.
        return new self(
            $mime,
            base64_encode($bytes),
            hash('sha256', $bytes),
            strlen($bytes),
            $width,
            $height
        );
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return array(
            'mime_type' => $this->mimeType,
            'bytes' => $this->bytes,
            'width' => $this->width,
            'height' => $this->height,
        );
    }
}
