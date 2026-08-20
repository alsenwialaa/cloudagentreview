<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WordPress;

final class Logger
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = array()): void
    {
        if (!(bool) $this->settings->get('diagnostic_logging', false)) {
            return;
        }

        $safe = $this->sanitize($context);
        error_log('[YSAI] ' . $message . ($safe === array() ? '' : ' ' . wp_json_encode($safe)));
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function sanitize(array $context): array
    {
        $blocked = array(
            'api_key',
            'token',
            'authorization',
            'image',
            'data',
            'request_body',
            'message',
            'exception_message',
            'trace',
            'stack',
            'sql',
            'query',
        );
        $out = array();
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                $out[(string) $key] = '[redacted]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = is_string($value) ? substr($value, 0, 500) : $value;
            }
        }
        return $out;
    }
}
