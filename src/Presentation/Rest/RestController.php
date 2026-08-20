<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Presentation\Rest;

use YassinStore\AiAssistant\Application\Chat\ChatService;
use YassinStore\AiAssistant\Application\Chat\PublicException;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

final class RestController
{
    public const NAMESPACE = 'yassin-ai/v2';
    public const CLIENT_CONTRACT_HEADER = 'X-YSAI-Client-Contract';
    public const CLIENT_CONTRACT_VERSION = '2';

    public function __construct(
        private readonly ChatService $chat,
        private readonly StorefrontRequestGuard $guard,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/health', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'health'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::NAMESPACE, '/boot', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'boot'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::NAMESPACE, '/chat', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'chat'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::NAMESPACE, '/turn/recover', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'recover'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::NAMESPACE, '/conversation/export', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'export'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::NAMESPACE, '/conversation/delete', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'delete'),
            'permission_callback' => '__return_true',
        ));
    }

    public function health(\WP_REST_Request $request): \WP_REST_Response
    {
        // Public liveness intentionally discloses no configuration, version,
        // dependency, or credential posture. Detailed readiness is restricted
        // to the administrator diagnostics surface.
        return new \WP_REST_Response(array('ok' => true), 200);
    }

    public function boot(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle($request, function (array $body): array {
            return $this->chat->boot(
                $this->optionalString($body, 'conversation_id'),
                $this->optionalString($body, 'token')
            );
        }, true, array('conversation_id', 'token'));
    }

    public function chat(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle(
            $request,
            fn (array $body): array => $this->chat->chat($body),
            true,
            array('conversation_id', 'token', 'client_turn_id', 'message', 'reply', 'image'),
            'chat'
        );
    }

    public function recover(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle($request, fn (array $body): array => $this->chat->recover(
            $this->requiredString($body, 'conversation_id'),
            $this->requiredString($body, 'token'),
            $this->requiredString($body, 'client_turn_id')
        ), false, array('conversation_id', 'token', 'client_turn_id'), 'recover');
    }

    public function export(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle($request, fn (array $body): array => $this->chat->export(
            $this->requiredString($body, 'conversation_id'),
            $this->requiredString($body, 'token'),
            $this->optionalInteger($body, 'after_message_id', 0),
            $this->optionalInteger($body, 'upper_message_id', 0),
            $this->optionalInteger($body, 'limit', 200)
        ), false, array('conversation_id', 'token', 'after_message_id', 'upper_message_id', 'limit'));
    }

    public function delete(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->handle($request, fn (array $body): array => $this->chat->delete(
            $this->requiredString($body, 'conversation_id'),
            $this->requiredString($body, 'token')
        ), false, array('conversation_id', 'token'));
    }


    /** @param array<string,mixed> $body */
    private function optionalString(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            throw new PublicException('نوع أحد حقول الطلب غير صالح.', 'invalid_request_field', 422);
        }
        return $body[$key];
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body, string $key): string
    {
        $value = $this->optionalString($body, $key);
        if ($value === null) {
            throw new PublicException('حقل مطلوب مفقود من الطلب.', 'missing_request_field', 422);
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private function optionalInteger(array $body, string $key, int $default): int
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }
        if (!is_int($body[$key])) {
            throw new PublicException('نوع أحد حقول الطلب غير صالح.', 'invalid_request_field', 422);
        }
        return $body[$key];
    }

    private function assertRequirements(): void
    {
        $wooVersion = defined('WC_VERSION') ? (string) WC_VERSION : '';
        if (!class_exists('WooCommerce')
            || $wooVersion === ''
            || version_compare($wooVersion, '11.0.1', '<')) {
            throw new PublicException(
                'يتطلب المساعد WooCommerce 11.0.1 أو أحدث.',
                'requirements_unavailable',
                503
            );
        }
    }

    /**
     * @param callable(array<string,mixed>):array{status:int,body:array<string,mixed>} $operation
     * @param list<string> $allowedFields
     * @param ''|'chat'|'recover' $turnDispositionMode
     */
    private function handle(
        \WP_REST_Request $request,
        callable $operation,
        bool $requiresWooCommerce,
        array $allowedFields,
        string $turnDispositionMode = ''
    ): \WP_REST_Response
    {
        $body = null;
        try {
            $body = $this->guard->payload($request, $allowedFields);
            if ($requiresWooCommerce) {
                $this->assertRequirements();
            }
            $result = $operation($body);
            return $this->response($request, $result['body'], $result['status']);
        } catch (PublicException $error) {
            $retryMode = $error->httpStatus >= 500 || $error->httpStatus === 429
                ? 'same_turn'
                : 'none';
            $publicError = array(
                'code' => $error->publicCode,
                'message' => $error->getMessage(),
                'retryable' => $retryMode !== 'none',
                'retry_mode' => $retryMode,
            );
            if ($error->retryAfterSeconds !== null) {
                $publicError['retry_after_seconds'] = $error->retryAfterSeconds;
            }
            $payload = array(
                'ok' => false,
                'error' => $publicError,
            );
            if (is_array($body) && $turnDispositionMode !== '') {
                $identity = $this->turnIdentity($body);
                $disposition = $identity === null
                    ? null
                    : $this->turnDisposition($turnDispositionMode, $error->publicCode);
                if ($identity !== null && $disposition !== null) {
                    $payload['conversation_id'] = $identity['conversation_id'];
                    $payload['client_turn_id'] = $identity['client_turn_id'];
                    $payload['turn_finalized'] = false;
                    $payload['request_disposition'] = $disposition;
                    if (in_array($disposition, array('rejected', 'conflict', 'not_found'), true)) {
                        $payload['request_accepted'] = false;
                        $payload['error']['retryable'] = false;
                        $payload['error']['retry_mode'] = 'none';
                    } elseif ($disposition === 'processing') {
                        $payload['error']['retryable'] = true;
                        $payload['error']['retry_mode'] = 'same_turn';
                    }
                }
            }
            return $this->response($request, $payload, $error->httpStatus);
        } catch (\Throwable $error) {
            $this->logger->error('REST request failed.', array('exception' => $error::class));
            return $this->response($request, array(
                'ok' => false,
                'error' => array(
                    'code' => 'server_error',
                    'message' => 'تعذّر إكمال الطلب على الخادم.',
                    'retryable' => true,
                    'retry_mode' => 'same_turn',
                ),
            ), 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private function response(\WP_REST_Request $request, array $payload, int $status): \WP_REST_Response
    {
        $retryAfter = null;
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : array();
        if (is_int($error['retry_after_seconds'] ?? null)
            && $error['retry_after_seconds'] >= 1
            && $error['retry_after_seconds'] <= 86_400) {
            $retryAfter = $error['retry_after_seconds'];
        }

        // The header makes the optional retry fields safe during rolling
        // deployments. Legacy 2.5.2 clients send no contract header and receive
        // their original exact error object; current clients opt into version 2.
        if (trim($request->get_header(self::CLIENT_CONTRACT_HEADER)) !== self::CLIENT_CONTRACT_VERSION
            && isset($payload['error'])
            && is_array($payload['error'])) {
            unset($payload['error']['retry_mode'], $payload['error']['retry_after_seconds']);
        }

        $response = new \WP_REST_Response($payload, $status);
        if ($retryAfter !== null && method_exists($response, 'header')) {
            $response->header('Retry-After', (string) $retryAfter);
        }
        return $response;
    }

    /**
     * Only a fully parsed request can receive an identity-bound disposition.
     * Errors emitted before strict JSON/origin/field validation remain unbound,
     * so the browser must preserve the idempotency record and recover rather
     * than trusting a proxy-generated or malformed HTTP error.
     *
     * @param array<string,mixed> $body
     * @return array{conversation_id:string,client_turn_id:string}|null
     */
    private function turnIdentity(array $body): ?array
    {
        $conversationId = $body['conversation_id'] ?? null;
        $clientTurnId = $body['client_turn_id'] ?? null;
        if (!is_string($conversationId)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $conversationId) !== 1
            || !is_string($clientTurnId)
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientTurnId) !== 1) {
            return null;
        }
        return array(
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
        );
    }

    /** @param 'chat'|'recover' $mode */
    private function turnDisposition(string $mode, string $code): ?string
    {
        if ($code === 'turn_processing') {
            return 'processing';
        }
        if ($code === 'turn_id_conflict') {
            return 'conflict';
        }
        if ($mode === 'recover') {
            if ($code === 'turn_not_found') {
                return 'not_found';
            }
            if ($code === 'conversation_unauthorized') {
                return 'unverified';
            }
            // A recovery-endpoint validation, rate-limit, or dependency error
            // says nothing conclusive about whether the original chat request
            // executed. Leave it unbound so the client retries the same turn.
            return null;
        }
        // These failures can occur before the idempotent turn is inspected or
        // claimed. Another copy of the same request may already own the exact
        // identity, so they cannot safely authorize deletion of the browser's
        // pending record. The client must recover the same turn first.
        if (in_array($code, array(
            'rate_limited',
            'assistant_disabled',
            'conversation_unauthorized',
            'requirements_unavailable',
        ), true)) {
            return null;
        }

        // Only explicit deterministic input rejection or a serialized
        // different-turn blocker proves that this request was not accepted.
        if (in_array($code, array(
            'conversation_busy',
            'invalid_message',
            'message_too_long',
            'invalid_image',
            'empty_message',
            'invalid_reply_context',
        ), true)) {
            return 'rejected';
        }
        return null;
    }

}