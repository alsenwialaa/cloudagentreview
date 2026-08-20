<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Ai;

use YassinStore\AiAssistant\Application\Contract\AiProvider;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Infrastructure\Support\StrictJson;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

final class GeminiInteractionsClient implements AiProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1/interactions';
    private const MAX_REQUEST_BYTES = 8_388_608;
    private const MAX_RESPONSE_BYTES = 2_097_152;
    private const MAX_TRANSIENT_ATTEMPTS = 3;
    private const MAX_BACKOFF_DELAY_MILLISECONDS = 2_000;
    private const MIN_ATTEMPT_TIMEOUT_SECONDS = 0.250;
    private const FINALIZATION_RESERVE_SECONDS = 0.250;
    private const MAX_PUBLIC_RETRY_AFTER_SECONDS = 86_400;
    private const MAX_REMOTE_REASON_NODES = 64;
    private const MAX_REMOTE_REASON_DEPTH = 5;
    private const LOCATION_CODES = array(
        'LOCATION_NOT_SUPPORTED',
        'REGION_NOT_SUPPORTED',
        'COUNTRY_NOT_SUPPORTED',
        'GEO_RESTRICTED',
        'GEOGRAPHIC_RESTRICTION',
    );
    private const QUOTA_CODES = array('RESOURCE_EXHAUSTED', 'RATE_LIMIT_EXCEEDED', 'QUOTA_EXCEEDED');
    private const CREDENTIAL_CODES = array('UNAUTHENTICATED', 'API_KEY_INVALID', 'CREDENTIALS_INVALID');
    private const PERMISSION_CODES = array('PERMISSION_DENIED', 'ACCESS_DENIED', 'IAM_PERMISSION_DENIED');
    private const MODEL_CODES = array('NOT_FOUND', 'MODEL_NOT_FOUND', 'MODEL_UNAVAILABLE');
    private const PAYLOAD_CODES = array('PAYLOAD_TOO_LARGE', 'REQUEST_TOO_LARGE');
    private const TRANSIENT_CODES = array('UNAVAILABLE', 'DEADLINE_EXCEEDED', 'ABORTED', 'INTERNAL');
    private const REQUEST_CODES = array('INVALID_ARGUMENT', 'FAILED_PRECONDITION', 'OUT_OF_RANGE');
    private const SPECIFIC_REMOTE_REASON_CODES = array(
        'LOCATION_NOT_SUPPORTED',
        'REGION_NOT_SUPPORTED',
        'COUNTRY_NOT_SUPPORTED',
        'GEO_RESTRICTED',
        'GEOGRAPHIC_RESTRICTION',
        'RATE_LIMIT_EXCEEDED',
        'QUOTA_EXCEEDED',
        'API_KEY_INVALID',
        'CREDENTIALS_INVALID',
        'ACCESS_DENIED',
        'IAM_PERMISSION_DENIED',
        'MODEL_NOT_FOUND',
        'MODEL_UNAVAILABLE',
        'PAYLOAD_TOO_LARGE',
        'REQUEST_TOO_LARGE',
    );

    private readonly JsonSchemaValidator $schemaValidator;
    private readonly GeminiSchemaProjector $schemaProjector;
    private readonly FunctionToolValidator $toolValidator;
    /** @var \Closure():float */
    private readonly \Closure $clock;
    /** @var \Closure(int):void */
    private readonly \Closure $sleeper;

    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger,
        ?JsonSchemaValidator $schemaValidator = null,
        ?FunctionToolValidator $toolValidator = null,
        ?GeminiSchemaProjector $schemaProjector = null,
        ?\Closure $clock = null,
        ?\Closure $sleeper = null
    ) {
        $this->schemaValidator = $schemaValidator ?? new JsonSchemaValidator();
        $this->schemaProjector = $schemaProjector ?? new GeminiSchemaProjector();
        $this->toolValidator = $toolValidator ?? new FunctionToolValidator(
            $this->schemaValidator,
            $this->schemaProjector
        );
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        };
    }

    public function interact(array $history, array $tools, string $systemInstruction): array
    {
        $prepared = $this->prepareTools($tools);
        $payload = array(
            'model' => (string) $this->settings->get('gemini_model', 'gemini-3.7-flash'),
            'input' => $history,
            'system_instruction' => $systemInstruction,
            'store' => false,
            'stream' => false,
            'generation_config' => array(
                'max_output_tokens' => $this->maxOutputTokens(),
                'thinking_level' => $this->thinkingLevel((string) $this->settings->get('gemini_thinking_level', 'medium'), 'medium'),
                'thinking_summaries' => 'none',
                'tool_choice' => $prepared['tools'] === array() ? 'none' : 'any',
            ),
        );
        if ($prepared['tools'] !== array()) {
            $payload['tools'] = $prepared['tools'];
        }

        $response = $this->request($payload, $prepared['argument_schemas']);
        if ($prepared['tools'] !== array() && ($response['status'] ?? null) !== 'requires_action') {
            throw new ProviderException(
                'Gemini did not return the function call required by the production chat contract.',
                'provider_protocol_error'
            );
        }
        return $response;
    }

    public function structured(string $input, array $schema, string $systemInstruction, string $thinkingLevel = 'low'): array
    {
        try {
            $this->schemaValidator->assertSchema($schema);
            if (($schema['type'] ?? null) !== 'object') {
                throw new \InvalidArgumentException('Structured provider results must use an object schema.');
            }
            $wireSchema = $this->schemaProjector->project($schema);
        } catch (\InvalidArgumentException) {
            throw new ProviderException('The local structured-output schema is invalid.', 'provider_protocol_error');
        }

        $payload = array(
            'model' => (string) $this->settings->get('gemini_model', 'gemini-3.7-flash'),
            'input' => $input,
            'system_instruction' => $systemInstruction,
            'store' => false,
            'stream' => false,
            'response_format' => array(
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $wireSchema,
            ),
            'generation_config' => array(
                'max_output_tokens' => min(1024, $this->maxOutputTokens()),
                'thinking_level' => $this->thinkingLevel($thinkingLevel, 'low'),
                'thinking_summaries' => 'none',
                'tool_choice' => 'none',
            ),
        );
        $response = $this->request($payload, array());
        $text = $this->outputText($response);
        try {
            $pair = StrictJson::decodePair($text, 64, 50_000);
            $rawDecoded = $pair['raw'];
            $decoded = $pair['associative'];
        } catch (\JsonException) {
            $rawDecoded = null;
            $decoded = null;
        }
        if (!$rawDecoded instanceof \stdClass || !is_array($decoded)) {
            throw new ProviderException('Gemini returned invalid structured output.', 'provider_protocol_error');
        }
        try {
            $this->schemaValidator->assertValid($rawDecoded, $schema);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            throw new ProviderException('Gemini returned structured output that failed local schema validation.', 'provider_protocol_error');
        }
        return $decoded;
    }

    public function readinessCheck(array $tools = array()): array
    {
        $result = $this->structured(
            'Return the exact readiness object.',
            array(
                'type' => 'object',
                'properties' => array(
                    'ready' => array('type' => 'boolean'),
                    'message' => array('type' => 'string'),
                ),
                'required' => array('ready', 'message'),
                'additionalProperties' => false,
            ),
            'You are a deterministic API readiness probe. Return ready=true and a short message.',
            'low'
        );

        $structuredReady = ($result['ready'] ?? false) === true;
        $chatToolsReady = $tools === array() ? true : $this->chatToolsReadiness($tools);

        return array(
            'ready' => $structuredReady && $chatToolsReady,
            'structured_output_ready' => $structuredReady,
            'chat_tools_ready' => $chatToolsReady,
            'message' => Text::plain((string) ($result['message'] ?? ''), 200),
            'model' => (string) $this->settings->get('gemini_model', ''),
        );
    }

    /**
     * Verify the exact production tool bundle and function-calling transport,
     * not merely a structured-output request that omits every chat tool.
     *
     * @param list<array<string,mixed>> $tools
     */
    private function chatToolsReadiness(array $tools): bool
    {
        $prepared = $this->prepareTools($tools);
        $names = array();
        foreach ($tools as $tool) {
            if (is_array($tool) && is_string($tool['name'] ?? null)) {
                $names[] = $tool['name'];
            }
        }
        if (!in_array('respond_safe_failure', $names, true)) {
            throw new ProviderException(
                'The production chat tool contract is missing its terminal readiness function.',
                'provider_configuration_error',
                503
            );
        }

        $response = $this->request(array(
            'model' => (string) $this->settings->get('gemini_model', 'gemini-3.7-flash'),
            'input' => array(array(
                'type' => 'user_input',
                'content' => array(array(
                    'type' => 'text',
                    'text' => 'Call respond_safe_failure exactly once with message="ready". Do not answer with prose.',
                )),
            )),
            'system_instruction' => 'You are a deterministic production chat-contract probe. Call only the allowed terminal function.',
            'tools' => $prepared['tools'],
            'store' => false,
            'stream' => false,
            'generation_config' => array(
                'max_output_tokens' => 256,
                'thinking_level' => 'low',
                'thinking_summaries' => 'none',
                'tool_choice' => array(
                    'allowed_tools' => array(
                        'mode' => 'any',
                        'tools' => array('respond_safe_failure'),
                    ),
                ),
            ),
        ), $prepared['argument_schemas']);

        $calls = array_values(array_filter(
            is_array($response['steps'] ?? null) ? $response['steps'] : array(),
            static fn (mixed $step): bool => is_array($step) && ($step['type'] ?? null) === 'function_call'
        ));
        if (($response['status'] ?? null) !== 'requires_action'
            || count($calls) !== 1
            || ($calls[0]['name'] ?? null) !== 'respond_safe_failure') {
            throw new ProviderException(
                'Gemini did not satisfy the production chat function-calling readiness contract.',
                'provider_protocol_error'
            );
        }
        return true;
    }

    /**
     * @param list<array<string,mixed>> $tools
     * @return array{tools:list<array<string,mixed>>,argument_schemas:array<string,array<string,mixed>>}
     */
    private function prepareTools(array $tools): array
    {
        try {
            return $this->toolValidator->prepare($tools);
        } catch (\InvalidArgumentException $error) {
            $this->logger->error('Local Gemini tool contract validation failed.', array(
                'exception' => $error::class,
                'fingerprint' => hash('sha256', $error->getMessage()),
                'tool_count' => count($tools),
            ));
            throw new ProviderException(
                'The local production chat tool contract is invalid.',
                'provider_configuration_error',
                503
            );
        }
    }


    /**
     * @param array<string,mixed> $payload
     * @param array<string,array<string,mixed>> $argumentSchemas
     * @return array<string,mixed>
     */
    private function request(array $payload, array $argumentSchemas): array
    {
        $apiKey = $this->settings->apiKey();
        if ($apiKey === '') {
            throw new ProviderException('Gemini API key is not configured.', 'provider_not_configured', 503);
        }

        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new ProviderException('Unable to encode the Gemini request.', 'provider_protocol_error');
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new ProviderException('The AI request exceeded the allowed size.', 'provider_request_too_large', 413);
        }

        $response = $this->postWithRetries($body, $apiKey);

        if (is_wp_error($response)) {
            $this->logger->error('Gemini transport failure.', array('code' => $response->get_error_code()));
            throw new ProviderException(
                'The AI service is temporarily unavailable.',
                'provider_unavailable',
                503,
                ProviderException::RETRY_NEW_TURN
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $retryAfterSeconds = $this->publicRetryAfterSeconds($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        if (strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            throw new ProviderException('The AI response exceeded the allowed size.', 'provider_protocol_error');
        }
        try {
            $pair = StrictJson::decodePair($responseBody, 128, 100_000);
            $rawDecoded = $pair['raw'];
            $decoded = $pair['associative'];
        } catch (\JsonException) {
            $rawDecoded = null;
            $decoded = null;
        }
        if (!$rawDecoded instanceof \stdClass || !is_array($decoded)) {
            $this->logger->error('Gemini returned non-JSON object data.', array('status' => $status));
            if ($status < 200 || $status >= 300) {
                $this->throwForHttpStatus($status, array(), $retryAfterSeconds);
            }
            throw new ProviderException('The AI service returned an invalid response.', 'provider_protocol_error');
        }

        if ($status < 200 || $status >= 300) {
            $this->throwForHttpStatus($status, $decoded, $retryAfterSeconds);
        }

        if (!is_string($decoded['status'] ?? null)) {
            throw new ProviderException('Gemini returned a missing or malformed interaction status.', 'provider_protocol_error');
        }
        $statusValue = $decoded['status'];
        if (in_array($statusValue, array('failed', 'cancelled', 'incomplete', 'budget_exceeded'), true)) {
            throw new ProviderException(
                'The AI interaction did not complete.',
                'provider_incomplete',
                502,
                ProviderException::RETRY_NEW_TURN
            );
        }
        if (!in_array($statusValue, array('completed', 'requires_action'), true)) {
            throw new ProviderException('Gemini returned an unknown interaction status.', 'provider_protocol_error');
        }

        $steps = $decoded['steps'] ?? array();
        $rawSteps = $rawDecoded->steps ?? array();
        if (!is_array($steps)
            || !array_is_list($steps)
            || !is_array($rawSteps)
            || count($steps) !== count($rawSteps)
            || count($steps) > 100) {
            throw new ProviderException('Gemini returned an invalid step sequence.', 'provider_protocol_error');
        }

        $functionCalls = 0;
        $seenCallIds = array();
        foreach ($rawSteps as $step) {
            if (!$step instanceof \stdClass
                || !is_string($step->type ?? null)
                || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $step->type) !== 1) {
                throw new ProviderException('Gemini returned a malformed interaction step.', 'provider_protocol_error');
            }
            if ($step->type !== 'function_call') {
                continue;
            }

            $id = $step->id ?? null;
            $name = $step->name ?? null;
            $arguments = $step->arguments ?? null;
            if (!$this->validCallId($id)
                || !is_string($name)
                || !$arguments instanceof \stdClass
                || isset($seenCallIds[$id])) {
                throw new ProviderException('Gemini returned a malformed function call.', 'provider_protocol_error');
            }
            if (!array_key_exists($name, $argumentSchemas)) {
                throw new ProviderException('Gemini requested an undeclared function.', 'provider_protocol_error');
            }
            try {
                $this->schemaValidator->assertValid($arguments, $argumentSchemas[$name]);
            } catch (\InvalidArgumentException|\UnexpectedValueException) {
                throw new ProviderException(
                    'Gemini returned function arguments that failed local schema validation.',
                    'provider_protocol_error'
                );
            }
            $seenCallIds[$id] = true;
            ++$functionCalls;
        }

        if (($statusValue === 'requires_action' && $functionCalls === 0)
            || ($statusValue === 'completed' && $functionCalls > 0)) {
            throw new ProviderException('Gemini returned an interaction status inconsistent with its function calls.', 'provider_protocol_error');
        }

        if (property_exists($rawDecoded, 'output_text')
            && $rawDecoded->output_text !== null
            && !is_string($rawDecoded->output_text)) {
            throw new ProviderException('Gemini returned malformed output text.', 'provider_protocol_error');
        }
        if (!array_key_exists('output_text', $decoded) || $decoded['output_text'] === null) {
            $decoded['output_text'] = $this->outputText($decoded);
        }
        if (($decoded['steps'] ?? array()) === array() && trim($decoded['output_text']) === '') {
            throw new ProviderException('Gemini returned an empty interaction.', 'provider_protocol_error');
        }
        // Preserve model-generated steps in their raw JSON-native form so a
        // stateless tool round can resend them exactly, including empty JSON
        // objects and thought signatures. The associative copy remains the
        // validated application-facing representation.
        $decoded['_wire_steps'] = $rawSteps;
        return $decoded;
    }

    private function postWithRetries(string $body, string $apiKey): mixed
    {
        $timeout = (float) $this->timeoutSeconds();
        $deadline = ($this->clock)() + $timeout;
        $response = null;

        for ($attempt = 1; $attempt <= self::MAX_TRANSIENT_ATTEMPTS; ++$attempt) {
            $remaining = $deadline - ($this->clock)();
            $attemptTimeout = $this->attemptTimeoutSeconds($remaining, $timeout);
            if ($attemptTimeout === null) {
                break;
            }

            $response = wp_remote_post(
                self::ENDPOINT,
                array(
                    // Every transport attempt consumes the same wall-clock
                    // budget. Fractional timeouts prevent a late retry from
                    // receiving a fresh one-second allowance beyond the
                    // configured request deadline.
                    'timeout' => $attemptTimeout,
                    'redirection' => 0,
                    'reject_unsafe_urls' => true,
                    // Ask WordPress for one byte beyond the accepted ceiling so
                    // an oversized response can be distinguished from valid JSON.
                    'limit_response_size' => self::MAX_RESPONSE_BYTES + 1,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'x-goog-api-key' => $apiKey,
                        'User-Agent' => 'Yassin-AI-Assistant/' . YSAI_VERSION,
                    ),
                    'body' => $body,
                    'data_format' => 'body',
                )
            );

            if (!$this->isTransientResponse($response) || $attempt >= self::MAX_TRANSIENT_ATTEMPTS) {
                return $response;
            }

            $delayMilliseconds = $this->retryDelayMilliseconds($response, $attempt);
            $remaining = $deadline - ($this->clock)();
            $requiredSeconds = ($delayMilliseconds / 1000)
                + self::MIN_ATTEMPT_TIMEOUT_SECONDS
                + self::FINALIZATION_RESERVE_SECONDS;

            // Retry-After is an earliest permissible retry time, not a hint that
            // may be shortened. When the complete delay plus a usable next
            // transport window does not fit, return the original transient
            // response and let the durable turn expose a delayed new-turn retry.
            if ($remaining <= 0.0 || $requiredSeconds >= $remaining) {
                return $response;
            }

            $this->logger->error('Retrying a transient Gemini request.', array(
                'attempt' => $attempt,
                'status' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response),
                'transport_code' => is_wp_error($response) ? $response->get_error_code() : '',
                'delay_ms' => $delayMilliseconds,
            ));
            ($this->sleeper)($delayMilliseconds * 1000);
        }

        if ($response !== null) {
            return $response;
        }
        return new \WP_Error('ysai_provider_budget_exhausted', 'The provider request budget was exhausted.');
    }

    private function attemptTimeoutSeconds(float $remaining, float $configuredTimeout): ?float
    {
        $usable = min($configuredTimeout, $remaining - self::FINALIZATION_RESERVE_SECONDS);
        if ($usable < self::MIN_ATTEMPT_TIMEOUT_SECONDS) {
            return null;
        }

        // Round downward so serialization and transport setup never obtain time
        // outside the shared deadline through floating-point rounding.
        $bounded = floor($usable * 1000) / 1000;
        return $bounded >= self::MIN_ATTEMPT_TIMEOUT_SECONDS ? $bounded : null;
    }

    private function isTransientResponse(mixed $response): bool
    {
        if (is_wp_error($response)) {
            return true;
        }
        if (!is_array($response)) {
            return false;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        return $status <= 0
            || in_array($status, array(408, 409, 425, 429), true)
            || $status >= 500;
    }

    private function retryDelayMilliseconds(mixed $response, int $attempt): int
    {
        $retryAfter = $this->retryAfterMilliseconds($response);
        if ($retryAfter !== null) {
            return $retryAfter;
        }

        $base = $attempt <= 1 ? 150 : 450;
        try {
            $jitter = random_int(0, 125);
        } catch (\Throwable) {
            $jitter = 50;
        }
        return min(self::MAX_BACKOFF_DELAY_MILLISECONDS, $base + $jitter);
    }

    private function retryAfterMilliseconds(mixed $response): ?int
    {
        $retryAfter = $this->responseHeader($response, 'retry-after');
        if ($retryAfter === '') {
            return null;
        }

        if (preg_match('/^\d{1,7}$/D', $retryAfter) === 1) {
            return (int) $retryAfter * 1000;
        }

        $timestamp = strtotime($retryAfter);
        if ($timestamp === false) {
            return null;
        }
        return max(0, (int) ceil(($timestamp - ($this->clock)()) * 1000));
    }

    private function publicRetryAfterSeconds(mixed $response): ?int
    {
        $milliseconds = $this->retryAfterMilliseconds($response);
        if ($milliseconds === null || $milliseconds <= 0) {
            return null;
        }
        return min(
            self::MAX_PUBLIC_RETRY_AFTER_SECONDS,
            max(1, (int) ceil($milliseconds / 1000))
        );
    }

    private function responseHeader(mixed $response, string $name): string
    {
        if (!is_array($response)) {
            return '';
        }
        if (function_exists('wp_remote_retrieve_header')) {
            $value = wp_remote_retrieve_header($response, $name);
            return is_scalar($value) ? trim((string) $value) : '';
        }

        $headers = $response['headers'] ?? array();
        if (is_array($headers)) {
            foreach ($headers as $header => $value) {
                if (is_string($header) && strcasecmp($header, $name) === 0 && is_scalar($value)) {
                    return trim((string) $value);
                }
            }
        }
        if ($headers instanceof \ArrayAccess) {
            foreach (array($name, strtolower($name), ucfirst(strtolower($name))) as $candidate) {
                if ($headers->offsetExists($candidate)) {
                    $value = $headers->offsetGet($candidate);
                    return is_scalar($value) ? trim((string) $value) : '';
                }
            }
        }
        return '';
    }

    /** @param array<string,mixed> $decoded */
    private function throwForHttpStatus(int $status, array $decoded, ?int $retryAfterSeconds = null): never
    {
        $remoteError = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();
        $remoteStatus = is_string($remoteError['status'] ?? null)
            ? Text::plain($remoteError['status'], 80)
            : '';
        $remoteReasons = $this->remoteReasons($remoteError);
        $remoteDetail = is_string($remoteError['message'] ?? null)
            ? Text::plain($remoteError['message'], 2000)
            : '';
        $classification = $this->remoteErrorClassification(
            $remoteStatus,
            $remoteReasons,
            $remoteDetail
        );
        $remoteReason = $classification['reason'];
        $category = $classification['category'];
        $remoteCode = strtoupper($remoteStatus !== '' ? $remoteStatus : $remoteReason);

        $this->logger->error('Gemini rejected a request.', array(
            'status' => $status,
            'remote_code' => $remoteCode,
            'remote_reason' => $remoteReason,
            'remote_reason_count' => count($remoteReasons),
            'remote_category' => $category,
            'remote_fingerprint' => $remoteDetail === '' ? '' : hash('sha256', $remoteDetail),
            'model' => Text::plain((string) $this->settings->get('gemini_model', ''), 100),
        ));

        // Structured provider status/reason is authoritative. HTTP status is
        // consulted only when the body contains no decisive canonical signal;
        // this prevents a proxy or inconsistent transport status from
        // overwriting a more specific Google error reason.
        if ($category === 'location_restriction') {
            throw new ProviderException(
                'The AI service is not available for the configured project or request location.',
                'provider_location_restricted',
                502
            );
        }
        if ($category === 'quota_or_rate') {
            throw new ProviderException(
                'The AI service quota or rate allowance is exhausted.',
                'provider_quota_exhausted',
                503,
                ProviderException::RETRY_NEW_TURN,
                $retryAfterSeconds
            );
        }
        if ($category === 'credentials') {
            throw new ProviderException(
                'The AI service rejected the configured credentials.',
                'provider_credentials_rejected',
                502
            );
        }
        if ($category === 'permissions') {
            throw new ProviderException(
                'The configured project or credentials do not have permission to use the AI service.',
                'provider_access_denied',
                502
            );
        }
        if ($category === 'model_configuration') {
            throw new ProviderException(
                'The configured AI model or endpoint is unavailable.',
                'provider_model_unavailable',
                502
            );
        }
        if ($category === 'payload_too_large') {
            throw new ProviderException(
                'The AI service rejected the request size.',
                'provider_request_too_large',
                413
            );
        }
        if ($category === 'transient') {
            throw new ProviderException(
                'The AI service is temporarily unavailable.',
                'provider_unavailable',
                503,
                ProviderException::RETRY_NEW_TURN,
                $retryAfterSeconds
            );
        }
        if (in_array($category, array('tool_or_schema_contract', 'request_rejected'), true)) {
            throw new ProviderException(
                'The AI service rejected the configured model or production chat request contract.',
                'provider_request_rejected',
                502
            );
        }

        // No canonical category was available. Use the transport status as the
        // bounded fallback diagnosis.
        if ($status === 429) {
            throw new ProviderException(
                'The AI service quota or rate allowance is exhausted.',
                'provider_quota_exhausted',
                503,
                ProviderException::RETRY_NEW_TURN,
                $retryAfterSeconds
            );
        }
        if ($status === 401) {
            throw new ProviderException(
                'The AI service rejected the configured credentials.',
                'provider_credentials_rejected',
                502
            );
        }
        if ($status === 403) {
            throw new ProviderException(
                'The configured project or credentials do not have permission to use the AI service.',
                'provider_access_denied',
                502
            );
        }
        if ($status === 404) {
            throw new ProviderException(
                'The configured AI model or endpoint is unavailable.',
                'provider_model_unavailable',
                502
            );
        }
        if ($status === 413) {
            throw new ProviderException(
                'The AI service rejected the request size.',
                'provider_request_too_large',
                413
            );
        }
        if ($status <= 0
            || in_array($status, array(408, 409, 425), true)
            || $status >= 500) {
            throw new ProviderException(
                'The AI service is temporarily unavailable.',
                'provider_unavailable',
                503,
                ProviderException::RETRY_NEW_TURN,
                $retryAfterSeconds
            );
        }
        if (in_array($status, array(400, 422), true)) {
            throw new ProviderException(
                'The AI service rejected the configured model or production chat request contract.',
                'provider_request_rejected',
                502
            );
        }
        throw new ProviderException('The AI service rejected the request.', 'provider_error', 502);
    }

    /**
     * @param array<string,mixed> $remoteError
     * @return list<string>
     */
    private function remoteReasons(array $remoteError): array
    {
        $reasons = array();
        $seen = array();
        $topLevel = $this->normalizeRemoteReason($remoteError['reason'] ?? null);
        if ($topLevel !== '') {
            $reasons[] = $topLevel;
            $seen[$topLevel] = true;
        }

        $details = $remoteError['details'] ?? array();
        if (!is_array($details)) {
            return $reasons;
        }

        // Count arrays as nodes rather than counting every scalar field. A
        // large metadata object therefore cannot consume the complete safety
        // budget before a sibling ErrorInfo object is inspected. The queue is
        // itself bounded, and appending children preserves breadth-first order.
        $visited = 0;
        /** @var list<array{node:array<mixed>,depth:int}> $queue */
        $queue = array(array('node' => $details, 'depth' => 0));
        while ($queue !== array() && $visited < self::MAX_REMOTE_REASON_NODES) {
            $current = array_shift($queue);
            if (!is_array($current)) {
                break;
            }
            ++$visited;
            $node = $current['node'];
            $depth = $current['depth'];

            $reason = $this->directRemoteReason($node);
            if ($reason !== '' && !isset($seen[$reason])) {
                $reasons[] = $reason;
                $seen[$reason] = true;
            }

            if ($depth >= self::MAX_REMOTE_REASON_DEPTH) {
                continue;
            }
            foreach ($node as $value) {
                if (!is_array($value)) {
                    continue;
                }
                if ($visited + count($queue) >= self::MAX_REMOTE_REASON_NODES) {
                    break;
                }
                $queue[] = array('node' => $value, 'depth' => $depth + 1);
            }
        }
        return $reasons;
    }

    /** @param array<mixed> $node */
    private function directRemoteReason(array $node): string
    {
        if (array_key_exists('reason', $node)) {
            return $this->normalizeRemoteReason($node['reason']);
        }
        // Google uses the lowercase key. This bounded compatibility fallback
        // accepts case variation without making arbitrary nested message text
        // part of the classifier.
        foreach ($node as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'reason') === 0) {
                return $this->normalizeRemoteReason($value);
            }
        }
        return '';
    }

    private function normalizeRemoteReason(mixed $reason): string
    {
        return is_string($reason) ? strtoupper(Text::plain($reason, 80)) : '';
    }

    /**
     * @param list<string> $remoteReasons
     * @return array{category:string,reason:string}
     */
    private function remoteErrorClassification(
        string $remoteStatus,
        array $remoteReasons,
        string $detail
    ): array {
        $status = strtoupper($remoteStatus);
        $normalizedDetail = strtolower($detail);

        $genericReason = null;
        $genericCategory = null;

        // Unknown entries are skipped. Specific ErrorInfo reasons outrank
        // generic status-like reasons even when the provider places the generic
        // entry first; provider order remains authoritative within each tier.
        foreach ($remoteReasons as $reason) {
            $category = $this->structuredRemoteCategory($reason, $normalizedDetail);
            if ($category === null) {
                continue;
            }
            if (in_array($reason, self::SPECIFIC_REMOTE_REASON_CODES, true)) {
                return array('category' => $category, 'reason' => $reason);
            }
            if ($genericCategory === null) {
                $genericCategory = $category;
                $genericReason = $reason;
            }
        }
        if ($genericCategory !== null && $genericReason !== null) {
            return array('category' => $genericCategory, 'reason' => $genericReason);
        }

        $statusCategory = $this->structuredRemoteCategory($status, $normalizedDetail);
        if ($statusCategory !== null) {
            return array(
                'category' => $statusCategory,
                'reason' => $remoteReasons[0] ?? '',
            );
        }

        // Only responses without a decisive canonical status or reason reach
        // free-text inference. Keep every phrase narrow so field names such as
        // location, quota, model, or api_key cannot misclassify a schema error.
        if ($this->containsToolOrSchemaPhrase($normalizedDetail)) {
            return array(
                'category' => 'tool_or_schema_contract',
                'reason' => $remoteReasons[0] ?? '',
            );
        }
        if ($this->containsLocationRestrictionPhrase($normalizedDetail)) {
            return array(
                'category' => 'location_restriction',
                'reason' => $remoteReasons[0] ?? '',
            );
        }
        foreach (array(
            'invalid api key',
            'api key is invalid',
            'api key not valid',
            'credentials are invalid',
            'credential is invalid',
            'authentication failed',
            'authentication required',
        ) as $phrase) {
            if (str_contains($normalizedDetail, $phrase)) {
                return array(
                    'category' => 'credentials',
                    'reason' => $remoteReasons[0] ?? '',
                );
            }
        }
        foreach (array(
            'permission denied',
            'does not have permission',
            'not authorized',
            'access denied',
        ) as $phrase) {
            if (str_contains($normalizedDetail, $phrase)) {
                return array(
                    'category' => 'permissions',
                    'reason' => $remoteReasons[0] ?? '',
                );
            }
        }
        foreach (array('quota exceeded', 'rate limit exceeded', 'resource exhausted') as $phrase) {
            if (str_contains($normalizedDetail, $phrase)) {
                return array(
                    'category' => 'quota_or_rate',
                    'reason' => $remoteReasons[0] ?? '',
                );
            }
        }
        foreach (array('model not found', 'model is unavailable', 'unknown model') as $phrase) {
            if (str_contains($normalizedDetail, $phrase)) {
                return array(
                    'category' => 'model_configuration',
                    'reason' => $remoteReasons[0] ?? '',
                );
            }
        }
        return array(
            'category' => 'unspecified',
            'reason' => $remoteReasons[0] ?? '',
        );
    }

    private function structuredRemoteCategory(string $code, string $detail): ?string
    {
        if (in_array($code, self::LOCATION_CODES, true)) {
            return 'location_restriction';
        }
        if (in_array($code, self::QUOTA_CODES, true)) {
            return 'quota_or_rate';
        }
        if (in_array($code, self::CREDENTIAL_CODES, true)) {
            return 'credentials';
        }
        if (in_array($code, self::PERMISSION_CODES, true)) {
            return 'permissions';
        }
        if (in_array($code, self::MODEL_CODES, true)) {
            return 'model_configuration';
        }
        if (in_array($code, self::PAYLOAD_CODES, true)) {
            return 'payload_too_large';
        }
        if (in_array($code, self::TRANSIENT_CODES, true)) {
            return 'transient';
        }
        if (in_array($code, self::REQUEST_CODES, true)) {
            return $this->containsToolOrSchemaPhrase($detail)
                ? 'tool_or_schema_contract'
                : 'request_rejected';
        }
        return null;
    }

    private function containsToolOrSchemaPhrase(string $detail): bool
    {
        foreach (array(
            'function schema',
            'response schema',
            'json schema',
            'schema property',
            'tool declaration',
            'function declaration',
            'function argument',
            'tool argument',
        ) as $phrase) {
            if (str_contains($detail, $phrase)) {
                return true;
            }
        }
        return false;
    }

    private function containsLocationRestrictionPhrase(string $detail): bool
    {
        foreach (array(
            'not available in the request location',
            'not available in your location',
            'not available in your region',
            'not available in this region',
            'not available in your country',
            'location is not supported',
            'unsupported location',
            'region is not supported',
            'unsupported region',
            'country is not supported',
            'unsupported country',
            'geographic restriction',
            'geographically restricted',
        ) as $phrase) {
            if (str_contains($detail, $phrase)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $response */
    private function outputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }

        $parts = array();
        $steps = $response['steps'] ?? array();
        if (is_array($steps)) {
            foreach ($steps as $step) {
                if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') {
                    continue;
                }
                $content = $step['content'] ?? array();
                if (!is_array($content)) {
                    continue;
                }
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                        $parts[] = $block['text'];
                    }
                }
            }
        }
        return trim(implode("\n", $parts));
    }


    /**
     * Function-call IDs are provider-issued opaque strings. The stable API
     * documents no restricted alphabet, so copy them exactly after applying
     * only bounded Unicode and control-character safety checks.
     */
    private function validCallId(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 512
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }


    private function maxOutputTokens(): int
    {
        return max(256, min(8192, (int) $this->settings->get('max_output_tokens', 2048)));
    }

    private function timeoutSeconds(): int
    {
        return max(10, min(90, (int) $this->settings->get('http_timeout_seconds', 35)));
    }

    private function thinkingLevel(string $level, string $fallback): string
    {
        // "minimal" is deliberately mapped for pre-upgrade settings and any
        // direct structured caller; supported stable levels are low/medium/high.
        if ($level === 'minimal') {
            return 'low';
        }
        return in_array($level, array('low', 'medium', 'high'), true) ? $level : $fallback;
    }
}
