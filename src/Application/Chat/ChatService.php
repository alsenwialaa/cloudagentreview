<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Chat;

use YassinStore\AiAssistant\Application\Contract\CartGateway;
use YassinStore\AiAssistant\Application\Contract\Clock;
use YassinStore\AiAssistant\Application\Contract\ConversationBusy;
use YassinStore\AiAssistant\Application\Contract\ConversationRepository;
use YassinStore\AiAssistant\Application\Contract\ConversationUnavailable;
use YassinStore\AiAssistant\Application\Contract\RateLimiter;
use YassinStore\AiAssistant\Application\Contract\RuntimeSettings;
use YassinStore\AiAssistant\Application\Contract\TurnGuard;
use YassinStore\AiAssistant\Application\Contract\TurnLeaseLost;
use YassinStore\AiAssistant\Application\Contract\TurnRequestConflict;
use YassinStore\AiAssistant\Application\Contract\TurnRepository;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Shared\Uuid;
use YassinStore\AiAssistant\Infrastructure\Ai\ProviderException;
use YassinStore\AiAssistant\Infrastructure\Security\ImageInput;
use YassinStore\AiAssistant\Infrastructure\Security\RequestIdentity;
use YassinStore\AiAssistant\Infrastructure\WordPress\Logger;

final class ChatService
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly TurnRepository $turns,
        private readonly RateLimiter $rateLimiter,
        private readonly CartGateway $cart,
        private readonly AgentLoop $agent,
        private readonly RuntimeSettings $settings,
        private readonly RequestIdentity $identity,
        private readonly Clock $clock,
        private readonly Logger $logger
    ) {
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function boot(?string $conversationId, ?string $token): array
    {
        if (($conversationId === null) !== ($token === null)) {
            throw new PublicException(
                'يجب إرسال معرّف المحادثة ورمزها معًا أو بدء محادثة جديدة بدونهما.',
                'incomplete_conversation_credentials',
                422
            );
        }
        if (!$this->rateLimiter->consume('browser_boots', $this->identity->browserBucket(), 30, 300)) {
            throw new PublicException(
                'تم تجاوز حد بدء الجلسات مؤقتًا.',
                'rate_limited',
                429,
                $this->retryAfterSeconds(300)
            );
        }
        $retention = $this->retentionDays();
        $authenticated = null;
        if (is_string($conversationId) && is_string($token)) {
            $conversationId = $this->conversationId($conversationId);
            $token = $this->token($token);
            $authenticated = $this->conversations->authenticate($conversationId, $token);
            if ($authenticated !== null && $this->sessionExpired($authenticated)) {
                $authenticated = null;
            }
        }

        if ($authenticated === null) {
            $dailyConversationLimit = max(
                100,
                min(100000, (int) $this->settings->get('daily_conversation_limit', 5000))
            );
            if (!$this->rateLimiter->consume(
                'global_daily_conversation_creations',
                $this->clock->now()->format('Y-m-d'),
                $dailyConversationLimit,
                86400
            )) {
                throw new PublicException(
                    'تم الوصول إلى الحد اليومي لبدء محادثات جديدة. حاول لاحقًا.',
                    'rate_limited',
                    429,
                    $this->retryAfterSeconds(86_400)
                );
            }
            $created = $this->conversations->create($retention);
            $credentials = $created['credentials'];
            $conversationId = $credentials->id;
            $token = $credentials->token;
            $expiresAt = (string) $created['expires_at'];
        } else {
            $this->conversations->touch((string) $conversationId, $retention);
            $expiresAt = $this->clock->now()->modify('+' . $retention . ' days')->format(DATE_ATOM);
        }

        $messages = $this->conversations->messages((string) $conversationId, 80);
        $context = $this->contextFromMessages($messages);
        $cart = null;
        $cartAvailable = true;
        try {
            $cart = $this->publicCart($this->cart->view($context));
            if ($cart === null) {
                throw new \RuntimeException('The cart gateway returned an invalid public snapshot.');
            }
        } catch (\Throwable $error) {
            $cartAvailable = false;
            $this->logger->error('Unable to read the storefront cart during boot.', array(
                'exception' => $error::class,
            ));
        }

        return array('status' => 200, 'body' => array(
            'ok' => true,
            'conversation' => array(
                'id' => (string) $conversationId,
                'token' => (string) $token,
                'expires_at' => $expiresAt,
            ),
            'messages' => $this->publicMessages($messages),
            'cart' => $cart,
            'cart_available' => $cartAvailable,
            'cart_notice' => $cartAvailable ? '' : 'تعذّر تحميل السلة حاليًا. ما زال بإمكانك تصفح المنتجات وطرح الأسئلة.',
        ));
    }

    /** @param array<string,mixed> $request @return array{status:int,body:array<string,mixed>} */
    public function chat(array $request): array
    {
        if (!(bool) $this->settings->get('enabled', true)) {
            throw new PublicException('المساعد غير متاح حاليًا.', 'assistant_disabled', 503);
        }

        $conversationId = $this->conversationId($request['conversation_id'] ?? null);
        $token = $this->token($request['token'] ?? null);
        $conversation = $this->conversations->authenticate($conversationId, $token);
        if ($conversation === null || $this->sessionExpired($conversation)) {
            throw new PublicException('انتهت جلسة المحادثة أو أصبحت غير صالحة. ابدأ جلسة جديدة.', 'conversation_unauthorized', 401);
        }

        $clientTurnId = $this->clientTurnId($request['client_turn_id'] ?? null);
        $rawMessage = $request['message'] ?? '';
        if (!is_string($rawMessage)) {
            throw new PublicException('نص الرسالة غير صالح.', 'invalid_message', 422);
        }
        if (Text::length($rawMessage) > 4000) {
            throw new PublicException('الرسالة أطول من الحد المسموح.', 'message_too_long', 422);
        }
        $message = Text::plain($rawMessage, 4000);

        // Apply the inexpensive, coarse abuse boundary before any database reply
        // lookup or base64/image inspection. Exact accepted-turn recovery uses the
        // dedicated recovery endpoint and remains tied to its original identity.
        $browser = $this->identity->browserBucket();
        $requestLimit = max(60, (int) $this->settings->get('rate_limit_turns', 40) * 3);
        if (!$this->rateLimiter->consume('browser_requests', $browser, $requestLimit, 300)) {
            throw new PublicException(
                'تم تجاوز حد الطلبات المؤقت. حاول لاحقًا.',
                'rate_limited',
                429,
                $this->retryAfterSeconds(300)
            );
        }

        $reply = $this->reply($conversationId, $request['reply'] ?? null);
        try {
            $image = ImageInput::fromRequest(
                $request['image'] ?? null,
                (bool) $this->settings->get('allow_images', true)
            );
        } catch (\InvalidArgumentException) {
            throw new PublicException('الصورة المرفقة غير صالحة أو غير مدعومة.', 'invalid_image', 422);
        }
        if ($message === '' && $image === null) {
            throw new PublicException('اكتب رسالة أو أرفق صورة.', 'empty_message', 422);
        }
        $requestHash = $this->requestHash($message, $reply, $image);
        $claim = null;
        $previousTurnAbandoned = false;
        for ($attempt = 0; $attempt <= 20; ++$attempt) {
            try {
                $claim = $this->turns->claim(
                    $conversationId,
                    $clientTurnId,
                    $requestHash,
                    $this->turnLeaseSeconds()
                );
                break;
            } catch (ConversationBusy) {
                $resolution = $attempt >= 20
                    ? 'blocked'
                    : $this->resolveBlockingTurn($conversationId, $clientTurnId);
                if ($resolution === 'abandoned') {
                    // Reserve and durably fail the current idempotency key below.
                    // Without that boundary, a concurrent duplicate could start
                    // after the old blocker is removed even though this response
                    // tells the shopper that the current request was not sent.
                    $previousTurnAbandoned = true;
                    continue;
                }
                if ($resolution !== 'resolved') {
                    throw new PublicException(
                        'يوجد طلب آخر قيد المعالجة في هذه المحادثة. استعد ذلك الطلب قبل إرسال طلب جديد.',
                        'conversation_busy',
                        409
                    );
                }
                // A checkpointed lost-browser turn was finalized safely. Retry the
                // same current identity without repeating its provider or cart work.
            } catch (ConversationUnavailable) {
                throw new PublicException(
                    'أصبحت المحادثة غير متاحة أثناء بدء الطلب. ابدأ جلسة جديدة.',
                    'conversation_unauthorized',
                    401
                );
            } catch (TurnRequestConflict) {
                throw new PublicException('أُعيد استخدام معرّف الطلب بمحتوى مختلف.', 'turn_id_conflict', 409);
            }
        }
        if (!is_array($claim)) {
            throw new \RuntimeException('Unable to establish a conversation turn after resolving blockers.');
        }

        $state = (string) $claim['state'];
        $turnId = (int) $claim['id'];
        $claimVersion = (int) ($claim['claim_version'] ?? 0);
        if ($turnId <= 0 || $claimVersion <= 0) {
            throw new \RuntimeException('The turn repository returned an invalid processing claim.');
        }
        if (in_array($state, array('completed', 'failed'), true)) {
            $stored = (array) ($claim['response'] ?? array());
            if ($state === 'completed') {
                return $this->completedTurnResult(
                    $conversationId,
                    $clientTurnId,
                    $turnId,
                    $stored,
                    true
                );
            }
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $stored,
                true
            );
        }
        if ($state === 'checkpointed') {
            return $this->finalizeCheckpoint(
                $conversationId,
                $clientTurnId,
                $turnId,
                $claimVersion,
                (array) ($claim['response'] ?? array()),
                true
            );
        }
        if ($state === 'processing') {
            throw new PublicException('هذا الطلب ما زال قيد المعالجة.', 'turn_processing', 409);
        }

        if ($previousTurnAbandoned) {
            $error = $this->errorInternal(
                'previous_turn_abandoned',
                'انتهت مهلة الطلب السابق من دون نتيجة قابلة للتحقق. لم يُرسل طلبك الحالي؛ افحص السلة أولًا ثم أعد الإرسال.',
                409,
                $conversationId,
                $clientTurnId
            );
            $error = $this->finalizedFailureInternal($error, $turnId, false);
            try {
                $failed = $this->safeFail(
                    $turnId,
                    $claimVersion,
                    'previous_turn_abandoned',
                    $error
                );
            } catch (TurnLeaseLost) {
                return $this->resultAfterLeaseLoss($conversationId, $clientTurnId);
            }
            if (!$failed) {
                return $this->resultAfterPersistenceFailure(
                    $conversationId,
                    $clientTurnId,
                    $turnId
                );
            }
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $error
            );
        }

        $turnLimit = max(5, (int) $this->settings->get('rate_limit_turns', 40));
        $rateRetryAfter = null;
        if (!$this->rateLimiter->consume('conversation_turns', $conversationId, $turnLimit, 300)) {
            $rateRetryAfter = $this->retryAfterSeconds(300);
        } elseif (!$this->rateLimiter->consume('browser_ai_turns', $browser, $turnLimit, 300)) {
            $rateRetryAfter = $this->retryAfterSeconds(300);
        } elseif (!$this->rateLimiter->consume(
            'global_daily_ai_turns',
            $this->clock->now()->format('Y-m-d'),
            max(50, (int) $this->settings->get('daily_ai_turn_limit', 1200)),
            86_400
        )) {
            $rateRetryAfter = $this->retryAfterSeconds(86_400);
        }
        if ($rateRetryAfter !== null) {
            $error = $this->errorInternal(
                'rate_limited',
                'تم الوصول إلى حد استخدام المساعد مؤقتًا. حاول لاحقًا.',
                429,
                $conversationId,
                $clientTurnId,
                ProviderException::RETRY_NEW_TURN,
                $rateRetryAfter
            );
            $error = $this->finalizedFailureInternal($error, $turnId, false);
            try {
                $failed = $this->safeFail($turnId, $claimVersion, 'rate_limited', $error);
            } catch (TurnLeaseLost) {
                return $this->resultAfterLeaseLoss($conversationId, $clientTurnId);
            }
            if (!$failed) {
                return $this->resultAfterPersistenceFailure(
                    $conversationId,
                    $clientTurnId,
                    $turnId
                );
            }
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $error
            );
        }

        $userStored = false;
        try {
            $this->conversations->appendUserMessageForTurn(
                $conversationId,
                $turnId,
                $claimVersion,
                $message === '' ? 'أرفق المستخدم صورة للتسوق.' : $message,
                array(
                    'client_turn_id' => $clientTurnId,
                    'reply' => $reply,
                    'image' => $image?->metadata(),
                ),
                $this->retentionDays()
            );
            $userStored = true;

            $history = $this->conversations->messages($conversationId, 80, $turnId);
            $context = $this->contextFromMessages(
                $history,
                'turn:' . $turnId,
                new RepositoryTurnGuard($this->turns, $turnId, $claimVersion)
            );
            $agentResult = $this->agent->run(
                $conversationId,
                $history,
                $message,
                $reply,
                $image,
                $context
            );
            $internal = $this->successInternal(
                $conversationId,
                $clientTurnId,
                $turnId,
                $agentResult
            );

            // This checkpoint is deliberately before message finalization. A retry can
            // finish delivery without repeating any cart effect already performed.
            try {
                $this->turns->checkpoint($turnId, $claimVersion, $internal);
            } catch (\Throwable $checkpointError) {
                if ($checkpointError instanceof TurnLeaseLost) {
                    return $this->resultAfterLeaseLoss($conversationId, $clientTurnId);
                }
                return $this->finalizeWithoutCheckpoint(
                    $conversationId,
                    $clientTurnId,
                    $turnId,
                    $claimVersion,
                    $internal,
                    $checkpointError
                );
            }
            return $this->finalizeCheckpoint(
                $conversationId,
                $clientTurnId,
                $turnId,
                $claimVersion,
                $internal
            );
        } catch (TurnLeaseLost) {
            return $this->resultAfterLeaseLoss($conversationId, $clientTurnId);
        } catch (\Throwable $error) {
            $requestAccepted = $userStored;
            if (!$requestAccepted) {
                $acceptedState = $this->acceptedUserMessageState($conversationId, $turnId);
                if ($acceptedState === null) {
                    // A lost database response can make the acceptance write
                    // ambiguous. Do not finalize the turn as either sent or
                    // unsent until recovery can inspect the same identity.
                    return $this->resultAfterPersistenceFailure(
                        $conversationId,
                        $clientTurnId,
                        $turnId
                    );
                }
                $requestAccepted = $acceptedState;
            }
            $internal = $this->exceptionInternal($error, $conversationId, $clientTurnId);
            $internal = $this->finalizedFailureInternal($internal, $turnId, $requestAccepted);
            try {
                $failed = $this->safeFail(
                    $turnId,
                    $claimVersion,
                    (string) ($internal['error']['code'] ?? 'request_failed'),
                    $internal
                );
            } catch (TurnLeaseLost) {
                return $this->resultAfterLeaseLoss($conversationId, $clientTurnId);
            }
            $this->logger->error('Chat turn failed.', array(
                'code' => (string) ($internal['error']['code'] ?? 'request_failed'),
                'exception' => $error::class,
            ));
            if (!$failed) {
                return $this->resultAfterPersistenceFailure(
                    $conversationId,
                    $clientTurnId,
                    $turnId
                );
            }
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $internal
            );
        }
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function recover(string $conversationId, string $token, string $clientTurnId): array
    {
        $conversationId = $this->conversationId($conversationId);
        $token = $this->token($token);
        $clientTurnId = $this->clientTurnId($clientTurnId);
        $conversation = $this->conversations->authenticate($conversationId, $token);
        if ($conversation === null) {
            throw new PublicException('جلسة المحادثة غير صالحة أو منتهية.', 'conversation_unauthorized', 401);
        }

        $this->assertOperationRate('recovery', $conversationId, 120, 60);
        $turn = $this->turns->find($conversationId, $clientTurnId);
        $inactive = $this->sessionExpired($conversation);
        if ($turn === null) {
            // A plain absence read is not conclusive: the original HTTP request
            // may have authenticated but not yet acquired the conversation lock.
            // Seal this exact idempotency key as a durable pre-acceptance
            // rejection under the same lock used by claim(). If a delayed claim
            // won first, the repository returns that real turn instead. If this
            // seal wins, its synthetic request hash permanently prevents the
            // delayed request from executing after the browser was told it was
            // never accepted. This seal is also required after the ordinary
            // activity window closes: an older request may already have passed
            // that application check and still be waiting for the claim lock.
            // It does not refresh activity or reopen any other operation.
            $this->assertOperationRate('recovery_absence', $conversationId, 20, 20);
            $missing = $this->errorInternal(
                'turn_not_found',
                'لم يصل هذا الطلب إلى المعالجة. أعد إرساله كطلب جديد إذا كان ما يزال مطلوبًا.',
                404,
                $conversationId,
                $clientTurnId
            );
            $missing['turn_finalized'] = true;
            $missing['request_accepted'] = false;
            $missing['kind'] = 'safe_failure';
            try {
                $turn = $this->turns->sealMissingAsRejected(
                    $conversationId,
                    $clientTurnId,
                    'turn_not_found',
                    $missing,
                    $this->turnLeaseSeconds()
                );
            } catch (ConversationUnavailable) {
                throw new PublicException(
                    'جلسة المحادثة غير صالحة أو منتهية.',
                    'conversation_unauthorized',
                    401
                );
            }
        }

        $response = is_array($turn['response'] ?? null) ? $turn['response'] : array();
        $claimVersion = (int) ($turn['claim_version'] ?? 0);
        $turnId = (int) ($turn['id'] ?? 0);
        $status = (string) ($turn['status'] ?? '');
        if ($turnId <= 0
            || $claimVersion <= 0
            || !in_array($status, array('processing', 'completed', 'failed'), true)) {
            throw new \RuntimeException('The turn repository returned an invalid recovery record.');
        }

        // Inactivity closes ordinary chat, boot/history, export, and deletion,
        // but not replay of one exact retained idempotency key. A browser can
        // lose the response after the assistant message or a cart receipt is
        // already durable and return only after the activity window elapsed.
        // Replaying that exact high-entropy turn ID is read-only from the
        // conversation-lifecycle perspective and never refreshes activity.
        $refreshConversation = !$inactive;

        if ($status === 'processing' && $response !== array()) {
            return $this->finalizeCheckpoint(
                $conversationId,
                $clientTurnId,
                $turnId,
                $claimVersion,
                $response,
                true,
                true,
                $refreshConversation
            );
        }
        if ($status === 'completed') {
            return $this->completedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $response,
                true,
                $refreshConversation
            );
        }
        if ($status === 'failed') {
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $response,
                true,
                $refreshConversation
            );
        }

        $abandoned = $this->abandonedTurnInternal(
            $conversationId,
            $clientTurnId,
            $turnId,
            $this->turnHasUserMessage($conversationId, $turnId)
        );
        if ($this->turns->expireStale(
            $turnId,
            $claimVersion,
            'turn_abandoned',
            $abandoned
        )) {
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $abandoned,
                true,
                $refreshConversation
            );
        }

        // Ownership or state may have changed while the stale compare-and-set
        // ran. Resolve any newly durable checkpoint/terminal result before
        // reporting that work is still active.
        $resolved = $this->resolvedPersistedTurn(
            $conversationId,
            $clientTurnId,
            $turnId,
            $refreshConversation
        );
        if ($resolved !== null) {
            return $resolved;
        }
        return array('status' => 202, 'body' => array(
            'ok' => true,
            'status' => 'processing',
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
            'turn_finalized' => false,
        ));
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function export(
        string $conversationId,
        string $token,
        int $afterMessageId = 0,
        int $upperMessageId = 0,
        int $limit = 200
    ): array {
        $conversationId = $this->conversationId($conversationId);
        $conversation = $this->conversations->authenticate($conversationId, $this->token($token));
        if ($conversation === null || $this->sessionExpired($conversation)) {
            throw new PublicException('جلسة المحادثة غير صالحة أو منتهية.', 'conversation_unauthorized', 401);
        }
        if ($afterMessageId < 0
            || $upperMessageId < 0
            || ($afterMessageId === 0 && $upperMessageId !== 0)
            || ($afterMessageId !== 0 && $upperMessageId === 0)
            || ($upperMessageId > 0 && $afterMessageId > $upperMessageId)) {
            throw new PublicException('مؤشر تصدير المحادثة غير صالح.', 'invalid_export_cursor', 422);
        }
        $limit = max(1, min(200, $limit));
        $this->assertOperationRate('export', $conversationId, 300, 300);

        try {
            $export = $this->conversations->exportPage(
                $conversationId,
                $afterMessageId,
                $upperMessageId,
                $limit
            );
        } catch (\InvalidArgumentException) {
            throw new PublicException('مؤشر تصدير المحادثة غير صالح.', 'invalid_export_cursor', 422);
        } catch (\LengthException) {
            throw new PublicException(
                'حجم المحادثة أكبر من حد التصدير الآمن. احذف المحادثة أو تواصل مع إدارة المتجر للمساعدة.',
                'export_too_large',
                413
            );
        }

        $messages = $this->publicMessages((array) ($export['messages'] ?? array()));
        return array('status' => 200, 'body' => array(
            'ok' => true,
            'conversation_id' => $conversationId,
            'exported_at' => (string) ($export['exported_at'] ?? $this->clock->now()->format(DATE_ATOM)),
            'upper_message_id' => max(0, (int) ($export['upper_message_id'] ?? 0)),
            'next_after_message_id' => isset($export['next_after_message_id'])
                ? max(0, (int) $export['next_after_message_id'])
                : null,
            'complete' => ($export['complete'] ?? false) === true,
            'message_count' => max(0, (int) ($export['message_count'] ?? count($messages))),
            'messages' => $messages,
            'shopping_memory' => $this->publicShoppingMemory($export['memory'] ?? null),
        ));
    }

    /** @return array{status:int,body:array<string,mixed>} */
    public function delete(string $conversationId, string $token): array
    {
        $conversationId = $this->conversationId($conversationId);
        $conversation = $this->conversations->authenticate($conversationId, $this->token($token));
        if ($conversation === null || $this->sessionExpired($conversation)) {
            throw new PublicException('جلسة المحادثة غير صالحة أو منتهية.', 'conversation_unauthorized', 401);
        }
        $this->assertOperationRate('delete', $conversationId, 20, 10);
        try {
            $this->conversations->delete($conversationId);
        } catch (ConversationBusy) {
            throw new PublicException(
                'لا يمكن حذف المحادثة بينما يوجد طلب قيد المعالجة. تحقّق من نتيجة الطلب ثم أعد الحذف.',
                'conversation_busy',
                409
            );
        }
        return array('status' => 200, 'body' => array('ok' => true, 'deleted' => true));
    }

    /** @param list<array<string,mixed>> $messages */
    private function contextFromMessages(
        array $messages,
        string $operationKey = '',
        ?TurnGuard $turnGuard = null
    ): ToolContext {
        $context = new ToolContext($operationKey, $turnGuard, $this->clock->now()->getTimestamp());
        $catalogRestored = false;
        $clarificationRestored = false;
        foreach (array_reverse($messages) as $message) {
            $payload = is_array($message['payload'] ?? null) ? $message['payload'] : array();

            // Restore product authority before state that may reference it.
            // History is scanned newest-first, so the newest valid product
            // snapshot remains authoritative for clarification validation.
            $remaining = 80 - $context->productCount();
            if ($remaining > 0) {
                $snapshot = is_array($payload['_product_context'] ?? null) ? $payload['_product_context'] : array();
                $context->restoreProducts(array_slice($snapshot, -$remaining, null, true));
            }
            if (!$catalogRestored && array_key_exists('_catalog_context', $payload)) {
                $snapshot = is_array($payload['_catalog_context']) ? $payload['_catalog_context'] : array();
                $context->restoreCatalogContext($snapshot);
                $catalogRestored = true;
            }
            if (!$clarificationRestored && array_key_exists('_cart_clarification_context', $payload)) {
                $snapshot = is_array($payload['_cart_clarification_context'])
                    ? $payload['_cart_clarification_context']
                    : array();
                $context->restoreCartClarification($snapshot);
                $clarificationRestored = true;
            }
            if ($context->productCount() >= 80 && $catalogRestored && $clarificationRestored) {
                break;
            }
        }
        return $context;
    }

    /** @param array<string,mixed> $agentResult @return array<string,mixed> */
    private function successInternal(
        string $conversationId,
        string $clientTurnId,
        int $turnId,
        array $agentResult
    ): array {
        $kind = is_string($agentResult['kind'] ?? null) ? $agentResult['kind'] : 'safe_failure';
        if (!in_array($kind, array('answer', 'follow_up', 'safe_failure', 'cart_receipt', 'cart_uncertain'), true)) {
            $kind = 'safe_failure';
        }
        $message = Text::plain((string) ($agentResult['message'] ?? ''), 3000);
        if ($message === '') {
            $kind = 'safe_failure';
            $message = 'تعذّر إعداد إجابة موثوقة من بيانات المتجر الآن. حاول مرة أخرى بطلب أوضح.';
        }

        $products = $this->publicProducts((array) ($agentResult['products'] ?? array()));
        $cart = is_array($agentResult['cart'] ?? null)
            ? $this->publicCart($agentResult['cart'])
            : null;
        $receipt = is_array($agentResult['receipt'] ?? null)
            ? $this->publicReceipt($agentResult['receipt'])
            : null;

        if ($kind === 'cart_receipt') {
            if ($receipt === null) {
                // The cart tool may already have crossed a commerce boundary.
                // Never publish a malformed receipt as ordinary success.
                $kind = 'cart_uncertain';
                $message = 'تمت محاولة تعديل السلة، لكن تعذّر إعداد إيصال صالح. افحص السلة قبل أي طلب جديد.';
                $cart = null;
                $receipt = null;
            } else {
                $cart = $receipt['cart'];
            }
            $products = array();
        } elseif ($kind === 'safe_failure' || $kind === 'cart_uncertain') {
            // A failed or uncertain terminal cannot assert a fresh cart state.
            // Returning null preserves the browser's last independently verified
            // snapshot for an ordinary safe failure and forces it hidden for an
            // explicitly uncertain commerce outcome.
            $products = array();
            $cart = null;
            $receipt = null;
        } else {
            $receipt = null;
        }

        $messagePayload = $agentResult;
        $messagePayload['kind'] = $kind;
        $messagePayload['message'] = $message;
        $messagePayload['products'] = $products;
        $messagePayload['cart'] = $cart;
        $messagePayload['receipt'] = $receipt;

        return array(
            'ok' => true,
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
            'turn_id' => $turnId,
            'kind' => $kind,
            'message' => $message,
            'products' => $products,
            'cart' => $cart,
            'receipt' => $receipt,
            '_message_payload' => $messagePayload,
            '_http_status' => 200,
        );
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function finalizeWithoutCheckpoint(
        string $conversationId,
        string $clientTurnId,
        int $turnId,
        int $claimVersion,
        array $internal,
        \Throwable $checkpointError
    ): array {
        $this->logger->error('Unable to checkpoint a completed turn; using direct finalization.', array(
            'exception' => $checkpointError::class,
            'turn_id' => $turnId,
        ));

        // Complete first. If this durable idempotency boundary cannot be written,
        // do not append an assistant message that a retry could not reliably replay.
        try {
            $this->turns->complete($turnId, $claimVersion, $internal);
        } catch (TurnLeaseLost) {
            return $this->resultAfterLeaseLoss($conversationId, $clientTurnId);
        } catch (\Throwable $error) {
            $this->logger->error('Direct turn finalization also failed.', array(
                'exception' => $error::class,
                'turn_id' => $turnId,
            ));
            return $this->resolveDirectFinalizationFailure(
                $conversationId,
                $clientTurnId,
                $turnId,
                $claimVersion,
                $internal
            );
        }

        return $this->completedTurnResult(
            $conversationId,
            $clientTurnId,
            $turnId,
            $internal
        );
    }

    /**
     * Resolve the unknown outcome of a direct completion write without ever
     * publishing an answer that lacks a durable idempotency boundary.
     *
     * @param array<string,mixed> $internal
     * @return array{status:int,body:array<string,mixed>}
     */
    private function resolveDirectFinalizationFailure(
        string $conversationId,
        string $clientTurnId,
        int $turnId,
        int $claimVersion,
        array $internal
    ): array {
        $resolved = $this->resolvedPersistedTurn($conversationId, $clientTurnId, $turnId);
        if ($resolved !== null) {
            return $resolved;
        }

        // A database driver can report a transient failure before any write was
        // committed. One exact compare-and-set retry is safe and avoids forcing
        // the shopper to wait for lease expiry when storage has already recovered.
        try {
            $this->turns->complete($turnId, $claimVersion, $internal);
            return $this->completedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $internal
            );
        } catch (TurnLeaseLost) {
            return $this->resultAfterLeaseLoss($conversationId, $clientTurnId);
        } catch (\Throwable $retryError) {
            $this->logger->error('The bounded direct-finalization retry also failed.', array(
                'exception' => $retryError::class,
                'turn_id' => $turnId,
            ));
        }

        $resolved = $this->resolvedPersistedTurn($conversationId, $clientTurnId, $turnId);
        if ($resolved !== null) {
            return $resolved;
        }

        return $this->persistenceUncertainResult($conversationId, $clientTurnId, $turnId);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function resultAfterPersistenceFailure(
        string $conversationId,
        string $clientTurnId,
        int $turnId
    ): array {
        $resolved = $this->resolvedPersistedTurn($conversationId, $clientTurnId, $turnId);
        if ($resolved !== null) {
            return $resolved;
        }
        return $this->persistenceUncertainResult($conversationId, $clientTurnId, $turnId);
    }

    /**
     * Return a response only when the repository confirms a durable terminal or
     * checkpointed state. A null result means the caller must preserve the same
     * client turn for recovery rather than treating the request as finalized.
     *
     * @return array{status:int,body:array<string,mixed>}|null
     */
    private function resolvedPersistedTurn(
        string $conversationId,
        string $clientTurnId,
        int $expectedTurnId,
        bool $refreshConversation = true
    ): ?array {
        try {
            $turn = $this->turns->find($conversationId, $clientTurnId);
        } catch (\Throwable $error) {
            $this->logger->error('Unable to inspect a turn after a persistence failure.', array(
                'exception' => $error::class,
                'turn_id' => $expectedTurnId,
            ));
            return null;
        }

        if (!is_array($turn) || (int) ($turn['id'] ?? 0) !== $expectedTurnId) {
            return null;
        }

        $status = (string) ($turn['status'] ?? '');
        $response = is_array($turn['response'] ?? null) ? $turn['response'] : array();
        if ($status === 'completed' && $response !== array()) {
            return $this->completedTurnResult(
                $conversationId,
                $clientTurnId,
                $expectedTurnId,
                $response,
                true,
                $refreshConversation
            );
        }
        if ($status === 'failed' && $response !== array()) {
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $expectedTurnId,
                $response,
                true,
                $refreshConversation
            );
        }
        if ($status === 'processing' && $response !== array()) {
            $claimVersion = (int) ($turn['claim_version'] ?? 0);
            if ($claimVersion <= 0) {
                return null;
            }
            try {
                return $this->finalizeCheckpoint(
                    $conversationId,
                    $clientTurnId,
                    $expectedTurnId,
                    $claimVersion,
                    $response,
                    true,
                    true,
                    $refreshConversation
                );
            } catch (TurnLeaseLost) {
                return null;
            }
        }

        return null;
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function persistenceUncertainResult(
        string $conversationId,
        string $clientTurnId,
        int $turnId
    ): array {
        $internal = $this->errorInternal(
            'turn_persistence_uncertain',
            'تعذّر تأكيد حفظ نتيجة هذا الطلب. أعد المحاولة بالطلب نفسه للتحقق من النتيجة قبل إرسال طلب جديد.',
            503,
            $conversationId,
            $clientTurnId,
            ProviderException::RETRY_SAME_TURN
        );
        $internal['turn_id'] = $turnId;
        $internal['turn_finalized'] = false;
        return $this->resultFromInternal($internal);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function finalizeCheckpoint(
        string $conversationId,
        string $clientTurnId,
        int $turnId,
        int $claimVersion,
        array $internal,
        bool $replayed = false,
        bool $resolveLeaseLoss = true,
        bool $refreshConversation = true
    ): array {
        $payload = is_array($internal['_message_payload'] ?? null) ? $internal['_message_payload'] : array();
        $content = Text::plain((string) ($internal['message'] ?? ''), 3000);

        // The response has already been checkpointed before this method is called.
        // Delivery/finalization failures must never replace that recoverable success
        // with a failed turn, especially after a cart mutation has been committed.
        $finalInternal = $internal;
        try {
            $messageId = $this->conversations->appendMessage($conversationId, $turnId, 'assistant', $content, $payload);
            if ($messageId <= 0) {
                throw new \RuntimeException('The conversation repository returned an invalid assistant message identifier.');
            }
            $finalInternal['message_id'] = $messageId;
            $this->turns->complete($turnId, $claimVersion, $finalInternal);
        } catch (TurnLeaseLost $error) {
            if (!$resolveLeaseLoss) {
                throw $error;
            }
            return $this->resultAfterLeaseLoss($conversationId, $clientTurnId, $refreshConversation);
        } catch (\Throwable $error) {
            $this->logger->error('A checkpointed turn could not be fully finalized.', array(
                'exception' => $error::class,
                'turn_id' => $turnId,
            ));
            return $this->finalizationPendingResult($conversationId, $clientTurnId, $turnId);
        }

        // Presentation reconciliation below performs the single activity refresh
        // only after the terminal assistant message is durably attached. Keeping
        // the refresh there avoids extending a conversation twice or extending it
        // when terminal presentation remains incomplete.
        return $this->completedTurnResult(
            $conversationId,
            $clientTurnId,
            $turnId,
            $finalInternal,
            $replayed,
            $refreshConversation,
            true
        );
    }

    /**
     * Resolve a stale worker into the newer durable turn state without allowing the
     * stale worker to publish its own response. At most one current-lease finalization
     * attempt is made; continued contention is surfaced as processing.
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    private function resultAfterLeaseLoss(
        string $conversationId,
        string $clientTurnId,
        bool $refreshConversation = true
    ): array
    {
        try {
            $turn = $this->turns->find($conversationId, $clientTurnId);
        } catch (\Throwable $error) {
            $this->logger->error('Unable to resolve the current turn after a lost lease.', array(
                'exception' => $error::class,
            ));
            throw new PublicException('هذا الطلب ما زال قيد المعالجة.', 'turn_processing', 409);
        }

        if (!is_array($turn)) {
            throw new PublicException('هذا الطلب ما زال قيد المعالجة.', 'turn_processing', 409);
        }

        $status = (string) ($turn['status'] ?? '');
        $response = is_array($turn['response'] ?? null) ? $turn['response'] : array();
        $turnId = (int) ($turn['id'] ?? 0);
        $claimVersion = (int) ($turn['claim_version'] ?? 0);

        if ($status === 'completed') {
            return $this->completedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $response,
                true,
                $refreshConversation
            );
        }
        if ($status === 'failed') {
            return $this->failedTurnResult(
                $conversationId,
                $clientTurnId,
                $turnId,
                $response,
                true,
                $refreshConversation
            );
        }
        if ($status === 'processing' && $response !== array() && $turnId > 0 && $claimVersion > 0) {
            try {
                return $this->finalizeCheckpoint(
                    $conversationId,
                    $clientTurnId,
                    $turnId,
                    $claimVersion,
                    $response,
                    true,
                    false,
                    $refreshConversation
                );
            } catch (TurnLeaseLost) {
                // Another worker changed ownership again. Do not recurse indefinitely.
            }
        }

        throw new PublicException('هذا الطلب ما زال قيد المعالجة.', 'turn_processing', 409);
    }

    /** @param array<string,mixed> $internal @return array<string,mixed> */
    private function finalizedFailureInternal(
        array $internal,
        int $turnId,
        ?bool $requestAccepted = null
    ): array
    {
        if ($turnId <= 0 || ($internal['ok'] ?? null) !== false) {
            throw new \InvalidArgumentException('A finalized failure requires a valid failed turn.');
        }
        $internal['turn_id'] = $turnId;
        $internal['turn_finalized'] = true;
        if ($requestAccepted !== null) {
            $internal['request_accepted'] = $requestAccepted;
        }
        if (!is_bool($internal['request_accepted'] ?? null)) {
            throw new \InvalidArgumentException('A finalized failure requires an explicit request-acceptance state.');
        }
        $internal['kind'] = 'safe_failure';
        return $internal;
    }

    /**
     * Reassert the authenticated request identity for every durable failed-turn
     * replay, including rows written by an earlier release that did not persist
     * the explicit finalization marker.
     *
     * @param array<string,mixed> $internal
     * @return array{status:int,body:array<string,mixed>}
     */
    private function failedTurnResult(
        string $conversationId,
        string $clientTurnId,
        int $turnId,
        array $internal,
        bool $replayed = false,
        bool $refreshConversation = true
    ): array {
        if (($internal['ok'] ?? null) !== false || !is_array($internal['error'] ?? null)) {
            throw new \RuntimeException('A stored failed turn has an invalid response contract.');
        }
        $internal['conversation_id'] = $conversationId;
        $internal['client_turn_id'] = $clientTurnId;
        if (!is_bool($internal['request_accepted'] ?? null)) {
            // Compatibility with failed rows created before the explicit
            // acceptance marker existed. When the historical lookup cannot be
            // established, fail conservatively toward "accepted" so the
            // browser never labels an uncertain executed request as unsent.
            $internal['request_accepted'] = $this->turnHasUserMessage(
                $conversationId,
                $turnId,
                true
            );
        }
        $internal = $this->finalizedFailureInternal($internal, $turnId);
        if ($internal['request_accepted'] === true) {
            $presentationWasPending = !is_int($internal['message_id'] ?? null)
                || (int) $internal['message_id'] <= 0;
            $messageId = $this->persistFailureMessage($conversationId, $turnId, $internal);
            if ($messageId <= 0) {
                // The failed turn itself is durable, but the shopper-facing
                // assistant message is not yet addressable in history. Keep the
                // browser on the same pending identity so recovery can finish
                // this presentation write without repeating provider or cart work.
                return $this->finalizationPendingResult(
                    $conversationId,
                    $clientTurnId,
                    $turnId
                );
            }
            $internal['message_id'] = $messageId;
            if ($refreshConversation && $presentationWasPending) {
                try {
                    $this->conversations->touch($conversationId, $this->retentionDays());
                } catch (\Throwable $error) {
                    $this->logger->error('Unable to extend a reconciled failed conversation.', array(
                        'exception' => $error::class,
                    ));
                }
            }
        }
        return $this->resultFromInternal($internal, $replayed);
    }

    /**
     * A successful turn is not complete from the shopper's perspective until its
     * assistant message is durably addressable in conversation history. Keeping
     * the original client turn pending lets recovery finish that last write
     * without repeating provider work or a cart mutation.
     *
     * @param array<string,mixed> $internal
     * @return array{status:int,body:array<string,mixed>}
     */
    private function completedTurnResult(
        string $conversationId,
        string $clientTurnId,
        int $turnId,
        array $internal,
        bool $replayed = false,
        bool $refreshConversation = true,
        bool $presentationNewlyDurable = false
    ): array {
        $internal['conversation_id'] = $conversationId;
        $internal['client_turn_id'] = $clientTurnId;
        $internal['turn_id'] = $turnId;
        $internal = $this->reconcileCompletedTurn(
            $conversationId,
            $turnId,
            $internal,
            $refreshConversation,
            $presentationNewlyDurable
        );
        if (!is_int($internal['message_id'] ?? null) || $internal['message_id'] <= 0) {
            return $this->finalizationPendingResult($conversationId, $clientTurnId, $turnId);
        }

        $internal['turn_finalized'] = true;
        return $this->resultFromInternal($internal, $replayed);
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function finalizationPendingResult(
        string $conversationId,
        string $clientTurnId,
        int $turnId
    ): array {
        $internal = $this->errorInternal(
            'turn_finalization_pending',
            'تم حفظ نتيجة الطلب، لكن لم يكتمل حفظها في سجل المحادثة. أعد المحاولة بالطلب نفسه لإكمال الاستعادة بأمان.',
            503,
            $conversationId,
            $clientTurnId,
            ProviderException::RETRY_SAME_TURN
        );
        $internal['turn_id'] = $turnId;
        $internal['turn_finalized'] = false;
        return $this->resultFromInternal($internal);
    }

    /**
     * Heal a completed turn whose assistant message or message identifier was not
     * persisted because a presentation-layer write failed after durable completion.
     *
     * @param array<string,mixed> $internal
     * @return array<string,mixed>
     */
    private function reconcileCompletedTurn(
        string $conversationId,
        int $turnId,
        array $internal,
        bool $refreshConversation = true,
        bool $presentationNewlyDurable = false
    ): array {
        if (($internal['ok'] ?? false) !== true || $turnId <= 0) {
            return $internal;
        }

        $content = Text::plain((string) ($internal['message'] ?? ''), 3000);
        if ($content === '') {
            unset($internal['message_id']);
            return $internal;
        }
        $payload = is_array($internal['_message_payload'] ?? null) ? $internal['_message_payload'] : array();
        $presentationWasPending = $presentationNewlyDurable
            || !is_int($internal['message_id'] ?? null)
            || (int) $internal['message_id'] <= 0;

        try {
            $messageId = $this->conversations->appendMessage(
                $conversationId,
                $turnId,
                'assistant',
                $content,
                $payload
            );
            if ($messageId <= 0) {
                throw new \RuntimeException('The conversation repository returned an invalid assistant message identifier.');
            }
            $storedMessageId = $internal['message_id'] ?? null;
            if ($storedMessageId !== null
                && (!is_int($storedMessageId) || $storedMessageId <= 0 || $storedMessageId !== $messageId)) {
                throw new \RuntimeException('The completed turn references a different assistant message.');
            }
            if ($storedMessageId === null) {
                $this->turns->attachTerminalMessageId($turnId, $messageId);
            }
            $internal['message_id'] = $messageId;
        } catch (\Throwable $error) {
            unset($internal['message_id']);
            $this->logger->error('Unable to reconcile the assistant presentation for a completed turn.', array(
                'exception' => $error::class,
                'turn_id' => $turnId,
            ));
            return $internal;
        }

        if ($refreshConversation && $presentationWasPending) {
            try {
                $this->conversations->touch($conversationId, $this->retentionDays());
            } catch (\Throwable $error) {
                $this->logger->error('Unable to extend a reconciled conversation.', array(
                    'exception' => $error::class,
                ));
            }
        }

        return $internal;
    }

    /** @param array<string,mixed> $internal */
    private function persistFailureMessage(string $conversationId, int $turnId, array $internal): int
    {
        $error = is_array($internal['error'] ?? null) ? $internal['error'] : array();
        $message = Text::plain((string) ($error['message'] ?? ''), 600);
        if ($message === '') {
            return 0;
        }

        try {
            $messageId = $this->conversations->appendMessage($conversationId, $turnId, 'assistant', $message, array(
                'kind' => 'safe_failure',
            ));
            if ($messageId <= 0) {
                throw new \RuntimeException('The conversation repository returned an invalid failed-turn message identifier.');
            }
            $storedMessageId = $internal['message_id'] ?? null;
            if ($storedMessageId !== null
                && (!is_int($storedMessageId) || $storedMessageId <= 0 || $storedMessageId !== $messageId)) {
                throw new \RuntimeException('The failed turn references a different assistant message.');
            }
            if ($storedMessageId === null) {
                $this->turns->attachTerminalMessageId($turnId, $messageId);
            }
            return $messageId;
        } catch (\Throwable $persistenceError) {
            $this->logger->error('Unable to persist and attach a failed-turn assistant message.', array(
                'exception' => $persistenceError::class,
                'turn_id' => $turnId,
            ));
            return 0;
        }
    }

    private function acceptedUserMessageState(string $conversationId, int $turnId): ?bool
    {
        if ($turnId <= 0) {
            return false;
        }
        try {
            return $this->conversations->messageForTurn($conversationId, $turnId, 'user') !== null;
        } catch (\Throwable $error) {
            $this->logger->error('Unable to determine whether a turn user message is durable.', array(
                'exception' => $error::class,
                'turn_id' => $turnId,
            ));
            return null;
        }
    }

    private function turnHasUserMessage(
        string $conversationId,
        int $turnId,
        bool $defaultWhenUnverifiable = true
    ): bool {
        $accepted = $this->acceptedUserMessageState($conversationId, $turnId);
        if ($accepted !== null) {
            return $accepted;
        }
        // This compatibility lookup classifies historical failed turns. When
        // storage cannot prove the old state, fail conservatively toward
        // "accepted" so the browser never invites a duplicate resend.
        return $defaultWhenUnverifiable;
    }

    /**
     * Resolve one different processing turn without revoking live work.
     *
     * A durable checkpoint can be finalized and the new request may proceed.
     * An expired turn without a checkpoint is durably abandoned, but its cart
     * outcome is unknowable: the current request must stop so the shopper can
     * inspect the cart before deciding whether to submit again.
     *
     * @return 'resolved'|'abandoned'|'blocked'
     */
    private function resolveBlockingTurn(string $conversationId, string $excludingClientTurnId): string
    {
        try {
            $blocking = $this->turns->blockingRecoveryCandidate($conversationId, $excludingClientTurnId);
        } catch (\Throwable $error) {
            $this->logger->error('Unable to inspect a blocking conversation turn.', array(
                'exception' => $error::class,
            ));
            return 'blocked';
        }
        if ($blocking === null) {
            return 'blocked';
        }

        $status = $blocking['status'] ?? null;
        $turnId = $blocking['id'] ?? null;
        $clientTurnId = $blocking['client_turn_id'] ?? null;
        $claimVersion = $blocking['claim_version'] ?? null;
        $response = $blocking['response'] ?? null;
        if (!is_string($status)
            || !in_array($status, array('processing', 'completed', 'failed'), true)
            || !is_int($turnId)
            || $turnId <= 0
            || !is_string($clientTurnId)
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientTurnId) !== 1
            || hash_equals($clientTurnId, $excludingClientTurnId)
            || !is_int($claimVersion)
            || $claimVersion <= 0
            || !is_array($response)) {
            $this->logger->error('The turn repository returned an invalid blocking recovery record.');
            return 'blocked';
        }

        try {
            if ($status === 'completed') {
                $resolved = $this->completedTurnResult(
                    $conversationId,
                    $clientTurnId,
                    $turnId,
                    $response,
                    true
                );
                return ($resolved['body']['turn_finalized'] ?? false) === true ? 'resolved' : 'blocked';
            }
            if ($status === 'failed') {
                $resolved = $this->failedTurnResult(
                    $conversationId,
                    $clientTurnId,
                    $turnId,
                    $response,
                    true
                );
                return ($resolved['body']['turn_finalized'] ?? false) === true ? 'resolved' : 'blocked';
            }
            if ($response !== array()) {
                $resolved = $this->finalizeCheckpoint(
                    $conversationId,
                    $clientTurnId,
                    $turnId,
                    $claimVersion,
                    $response,
                    true
                );
                return ($resolved['body']['turn_finalized'] ?? false) === true ? 'resolved' : 'blocked';
            }

            $abandoned = $this->abandonedTurnInternal(
                $conversationId,
                $clientTurnId,
                $turnId,
                $this->turnHasUserMessage($conversationId, $turnId)
            );
            if (!$this->turns->expireStale(
                $turnId,
                $claimVersion,
                'turn_abandoned',
                $abandoned
            )) {
                return 'blocked';
            }
            return 'abandoned';
        } catch (\Throwable $error) {
            $this->logger->error('Unable to resolve a blocking conversation turn.', array(
                'exception' => $error::class,
                'turn_id' => $turnId,
            ));
            return 'blocked';
        }
    }

    /** @return array<string,mixed> */
    private function abandonedTurnInternal(
        string $conversationId,
        string $clientTurnId,
        int $turnId,
        bool $requestAccepted
    ): array
    {
        $internal = $this->errorInternal(
            'turn_abandoned',
            'توقّف الطلب السابق قبل حفظ نتيجة قابلة للاستعادة. لم يعد هذا الطلب يعمل؛ افحص السلة ثم أرسل طلبًا جديدًا، وأعد إرفاق الصورة إن كانت مطلوبة.',
            409,
            $conversationId,
            $clientTurnId
        );
        $internal['turn_id'] = $turnId;
        $internal['turn_finalized'] = true;
        $internal['request_accepted'] = $requestAccepted;
        $internal['kind'] = 'safe_failure';
        return $internal;
    }

    /** @return array<string,mixed> */
    private function exceptionInternal(\Throwable $error, string $conversationId, string $clientTurnId): array
    {
        if ($error instanceof ProviderException) {
            $message = match ($error->publicCode) {
                'provider_not_configured' => 'المساعد غير مُعدّ للاتصال بخدمة الذكاء الاصطناعي.',
                'provider_credentials_rejected' => 'رفضت خدمة الذكاء الاصطناعي بيانات الاتصال. أبلغ إدارة المتجر.',
                'provider_access_denied' => 'لا يملك مشروع أو مفتاح خدمة الذكاء الاصطناعي صلاحية استخدام الخدمة. أبلغ إدارة المتجر.',
                'provider_location_restricted' => 'خدمة الذكاء الاصطناعي غير متاحة للمشروع أو موقع الطلب الحالي. أبلغ إدارة المتجر.',
                'provider_quota_exhausted' => 'تم استهلاك حصة خدمة الذكاء الاصطناعي أو حدها المؤقت. أعد المحاولة لاحقًا أو أبلغ إدارة المتجر.',
                'provider_unavailable' => 'خدمة الذكاء الاصطناعي غير متاحة مؤقتًا. أعد المحاولة بعد قليل.',
                'provider_configuration_error' => 'إعداد أدوات المحادثة في خدمة الذكاء الاصطناعي غير صالح. أبلغ إدارة المتجر.',
                'provider_model_unavailable' => 'النموذج المحدد لخدمة الذكاء الاصطناعي غير متاح. أبلغ إدارة المتجر للتحقق من اسم النموذج.',
                'provider_request_rejected' => 'رفضت خدمة الذكاء الاصطناعي إعداد طلب المحادثة. أبلغ إدارة المتجر للتحقق من النموذج والاتصال.',
                'provider_request_too_large' => 'الطلب أو سجل المحادثة أكبر من الحد المسموح. ابدأ محادثة أقصر.',
                'provider_protocol_error' => 'أعادت خدمة الذكاء الاصطناعي استجابة غير متوافقة. لم يُنفّذ أي إجراء غير موثّق.',
                'provider_incomplete' => 'لم تُكمل خدمة الذكاء الاصطناعي الرد. أعد المحاولة.',
                'provider_error' => 'رفضت خدمة الذكاء الاصطناعي الطلب لسبب غير مصنّف. أبلغ إدارة المتجر.',
                default => 'حدث خطأ غير معروف في موصل خدمة الذكاء الاصطناعي. أبلغ إدارة المتجر.',
            };
            return $this->errorInternal(
                $error->publicCode,
                $message,
                $error->httpStatus,
                $conversationId,
                $clientTurnId,
                $error->retryMode,
                $error->retryAfterSeconds
            );
        }
        if ($error instanceof PublicException) {
            return $this->errorInternal(
                $error->publicCode,
                $error->getMessage(),
                $error->httpStatus,
                $conversationId,
                $clientTurnId,
                $error->httpStatus >= 500 || $error->httpStatus === 429
                    ? ProviderException::RETRY_SAME_TURN
                    : ProviderException::RETRY_NONE,
                $error->retryAfterSeconds
            );
        }
        if ($error instanceof \InvalidArgumentException) {
            $this->logger->error('Internal request validation failed.', array(
                'exception' => $error::class,
                'fingerprint' => $this->exceptionFingerprint($error),
                'client_turn_id' => $clientTurnId,
            ));
            return $this->errorInternal(
                'invalid_request',
                'تعذّر التحقق من الطلب. حدّث الصفحة وحاول مرة أخرى.',
                422,
                $conversationId,
                $clientTurnId
            );
        }
        $this->logger->error('Unhandled chat request failure.', array(
            'exception' => $error::class,
            'fingerprint' => $this->exceptionFingerprint($error),
            'client_turn_id' => $clientTurnId,
        ));
        return $this->errorInternal(
            'request_failed',
            'تعذّر إكمال الطلب بأمان. لم يتم تأكيد أي إجراء غير موثّق بإيصال.',
            500,
            $conversationId,
            $clientTurnId
        );
    }

    /** @return array<string,mixed> */
    private function errorInternal(
        string $code,
        string $message,
        int $status,
        string $conversationId,
        string $clientTurnId,
        string $retryMode = ProviderException::RETRY_NONE,
        ?int $retryAfterSeconds = null
    ): array {
        if (!in_array($retryMode, array(
            ProviderException::RETRY_NONE,
            ProviderException::RETRY_SAME_TURN,
            ProviderException::RETRY_NEW_TURN,
        ), true)) {
            throw new \InvalidArgumentException('The public retry mode is invalid.');
        }
        if ($retryAfterSeconds !== null
            && ($retryAfterSeconds < 1
                || $retryAfterSeconds > 86_400
                || $retryMode === ProviderException::RETRY_NONE)) {
            throw new \InvalidArgumentException('The public retry delay is invalid.');
        }
        $publicError = array(
            'code' => $code,
            'message' => Text::plain($message, 600),
            'retryable' => $retryMode !== ProviderException::RETRY_NONE,
            'retry_mode' => $retryMode,
        );
        if ($retryAfterSeconds !== null) {
            $publicError['retry_after_seconds'] = $retryAfterSeconds;
        }
        return array(
            'ok' => false,
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
            'error' => $publicError,
            '_http_status' => max(400, min(599, $status)),
        );
    }

    private function safeFail(int $turnId, int $claimVersion, string $code, array $internal): bool
    {
        try {
            $this->turns->fail($turnId, $claimVersion, Text::plain($code, 64), $internal);
            return true;
        } catch (TurnLeaseLost $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->logger->error('Unable to persist a failed turn.', array('exception' => $error::class));
            return false;
        }
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function resultFromInternal(array $internal, bool $replayed = false): array
    {
        $ok = $internal['ok'] ?? null;
        if ($ok === true) {
            $status = 200;
            $body = $this->publicSuccessResult($internal);
        } elseif ($ok === false) {
            $status = max(400, min(599, (int) ($internal['_http_status'] ?? 500)));
            $body = $this->publicErrorResult($internal, $status);
        } else {
            throw new \RuntimeException('A stored turn response has no valid terminal result type.');
        }
        if ($replayed) {
            $body['replayed'] = true;
        }
        return array('status' => $status, 'body' => $body);
    }

    /** @param array<string,mixed> $internal @return array<string,mixed> */
    private function publicSuccessResult(array $internal): array
    {
        $conversationId = is_string($internal['conversation_id'] ?? null)
            ? $internal['conversation_id']
            : '';
        $clientTurnId = is_string($internal['client_turn_id'] ?? null)
            ? $internal['client_turn_id']
            : '';
        $turnId = $this->publicInteger($internal['turn_id'] ?? null, 1, PHP_INT_MAX);
        $messageId = $this->publicInteger($internal['message_id'] ?? null, 1, PHP_INT_MAX);
        if (!Uuid::isValid($conversationId)
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientTurnId) !== 1
            || $turnId === null
            || $messageId === null
            || ($internal['turn_finalized'] ?? null) !== true) {
            throw new \RuntimeException('A stored successful turn has an invalid durable identity.');
        }

        $kind = is_string($internal['kind'] ?? null) ? $internal['kind'] : '';
        if (!in_array($kind, array('answer', 'follow_up', 'safe_failure', 'cart_receipt', 'cart_uncertain'), true)) {
            $kind = 'safe_failure';
        }
        $message = Text::plain(is_string($internal['message'] ?? null) ? $internal['message'] : '', 3000);
        if ($message === '') {
            $kind = 'safe_failure';
            $message = 'تعذّر استعادة نتيجة موثوقة لهذا الطلب. لم يتم تأكيد أي إجراء غير موثّق بإيصال.';
        }

        $products = $this->publicProducts(is_array($internal['products'] ?? null) ? $internal['products'] : array());
        $cart = is_array($internal['cart'] ?? null) ? $this->publicCart($internal['cart']) : null;
        $receipt = is_array($internal['receipt'] ?? null) ? $this->publicReceipt($internal['receipt']) : null;
        if ($kind === 'cart_receipt') {
            if ($receipt === null) {
                $kind = 'cart_uncertain';
                $message = 'تعذّر استعادة إيصال السلة الموثّق. افحص السلة قبل إرسال أي طلب جديد.';
                $cart = null;
                $receipt = null;
            } else {
                $cart = $receipt['cart'];
            }
            $products = array();
        } elseif (in_array($kind, array('safe_failure', 'cart_uncertain'), true)) {
            $products = array();
            $cart = null;
            $receipt = null;
        } else {
            $receipt = null;
        }

        return array(
            'ok' => true,
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
            'turn_id' => $turnId,
            'message_id' => $messageId,
            'turn_finalized' => true,
            'kind' => $kind,
            'message' => $message,
            'products' => $products,
            'cart' => $cart,
            'receipt' => $receipt,
        );
    }

    /** @param array<string,mixed> $internal @return array<string,mixed> */
    private function publicErrorResult(array $internal, int $status): array
    {
        $conversationId = is_string($internal['conversation_id'] ?? null)
            ? $internal['conversation_id']
            : '';
        $clientTurnId = is_string($internal['client_turn_id'] ?? null)
            ? $internal['client_turn_id']
            : '';
        if (!Uuid::isValid($conversationId)
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientTurnId) !== 1) {
            throw new \RuntimeException('A stored failed turn has an invalid request identity.');
        }

        $sourceError = is_array($internal['error'] ?? null) && !array_is_list($internal['error'])
            ? $internal['error']
            : array();
        $code = is_string($sourceError['code'] ?? null) ? Text::plain($sourceError['code'], 64) : '';
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1) {
            $code = 'request_failed';
        }
        $message = Text::plain(is_string($sourceError['message'] ?? null) ? $sourceError['message'] : '', 600);
        if ($message === '') {
            $message = 'تعذّر إكمال الطلب بأمان. لم يتم تأكيد أي إجراء غير موثّق بإيصال.';
        }
        $turnFinalized = $internal['turn_finalized'] ?? null;
        $retryMode = $this->publicRetryMode($sourceError, $turnFinalized, $code, $status);
        $retryAfterSeconds = null;
        if (array_key_exists('retry_after_seconds', $sourceError)) {
            $retryAfterSeconds = $this->publicInteger($sourceError['retry_after_seconds'], 1, 86_400);
            if ($retryAfterSeconds === null || $retryMode === ProviderException::RETRY_NONE) {
                throw new \RuntimeException('A stored retry delay is invalid.');
            }
        }
        $publicError = array(
            'code' => $code,
            'message' => $message,
            'retryable' => $retryMode !== ProviderException::RETRY_NONE,
            'retry_mode' => $retryMode,
        );
        if ($retryAfterSeconds !== null) {
            $publicError['retry_after_seconds'] = $retryAfterSeconds;
        }
        $body = array(
            'ok' => false,
            'conversation_id' => $conversationId,
            'client_turn_id' => $clientTurnId,
            'error' => $publicError,
        );

        if (is_bool($turnFinalized)) {
            $body['turn_finalized'] = $turnFinalized;
        }
        $turnId = $this->publicInteger($internal['turn_id'] ?? null, 1, PHP_INT_MAX);
        if ($turnId !== null) {
            $body['turn_id'] = $turnId;
        }
        if ($turnFinalized === true) {
            $accepted = $internal['request_accepted'] ?? null;
            if ($turnId === null || !is_bool($accepted)) {
                throw new \RuntimeException('A finalized failed turn has an invalid durable disposition.');
            }
            $body['request_accepted'] = $accepted;
            $body['kind'] = 'safe_failure';
            $messageId = $this->publicInteger($internal['message_id'] ?? null, 1, PHP_INT_MAX);
            if ($accepted) {
                if ($messageId === null) {
                    throw new \RuntimeException('An accepted failed turn has no durable assistant message.');
                }
                $body['message_id'] = $messageId;
            }
        }
        return $body;
    }

    /** @param array<string,mixed> $sourceError */
    private function publicRetryMode(
        array $sourceError,
        mixed $turnFinalized,
        string $code,
        int $status
    ): string {
        $mode = is_string($sourceError['retry_mode'] ?? null)
            ? $sourceError['retry_mode']
            : '';
        if (!in_array($mode, array(
            ProviderException::RETRY_NONE,
            ProviderException::RETRY_SAME_TURN,
            ProviderException::RETRY_NEW_TURN,
        ), true)) {
            // Compatibility for durable failed rows created before retry_mode
            // existed. A finalized failure can never be retried under the same
            // identity; only known transient provider/rate categories invite a
            // fresh turn. Non-finalized transport/persistence uncertainty keeps
            // the original idempotency key.
            if ($turnFinalized === true) {
                $mode = in_array($code, array(
                    'provider_unavailable',
                    'provider_quota_exhausted',
                    'provider_incomplete',
                    'rate_limited',
                ), true)
                    ? ProviderException::RETRY_NEW_TURN
                    : ProviderException::RETRY_NONE;
            } else {
                $legacyRetryable = is_bool($sourceError['retryable'] ?? null)
                    ? $sourceError['retryable']
                    : ($status >= 500 || $status === 429);
                $mode = $legacyRetryable
                    ? ProviderException::RETRY_SAME_TURN
                    : ProviderException::RETRY_NONE;
            }
        }
        if ($turnFinalized === true && $mode === ProviderException::RETRY_SAME_TURN) {
            return ProviderException::RETRY_NONE;
        }
        if ($turnFinalized !== true && $mode === ProviderException::RETRY_NEW_TURN) {
            return ProviderException::RETRY_SAME_TURN;
        }
        return $mode;
    }

    /** @param array<string,mixed> $product @return array<string,mixed>|null */
    private function publicProductCard(array $product): ?array
    {
        $ref = is_string($product['ref'] ?? null) ? $product['ref'] : '';
        $name = Text::plain(is_string($product['name'] ?? null) ? $product['name'] : '', 300);
        if (preg_match('/^p_[A-Za-z0-9_-]{8,80}$/D', $ref) !== 1 || $name === '') {
            return null;
        }

        $price = $this->publicNumber($product['price'] ?? null, 0.0);
        $explicitAvailable = is_bool($product['price_available'] ?? null)
            ? $product['price_available']
            : $price !== null;
        $priceAvailable = $explicitAvailable && $price !== null;
        $priceKind = is_string($product['price_kind'] ?? null) ? $product['price_kind'] : '';
        if (!$priceAvailable) {
            $price = null;
            $priceKind = 'unavailable';
        } elseif (!in_array($priceKind, array('fixed', 'from'), true)) {
            $priceKind = 'fixed';
        }

        $type = Text::plain(is_string($product['type'] ?? null) ? $product['type'] : 'simple', 40);
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $type) !== 1) {
            $type = 'simple';
        }
        $inStock = ($product['in_stock'] ?? false) === true;
        $stockStatus = Text::plain(
            is_string($product['stock_status'] ?? null)
                ? $product['stock_status']
                : ($inStock ? 'instock' : 'outofstock'),
            40
        );

        return array(
            'ref' => $ref,
            'name' => $name,
            'sku' => Text::plain(is_string($product['sku'] ?? null) ? $product['sku'] : '', 120),
            'type' => $type,
            'price' => $price,
            'price_available' => $priceAvailable,
            'price_kind' => $priceKind,
            'regular_price' => $this->publicNumber($product['regular_price'] ?? null, 0.0),
            'sale_price' => $this->publicNumber($product['sale_price'] ?? null, 0.0),
            'price_text' => Text::plain(is_string($product['price_text'] ?? null) ? $product['price_text'] : '', 200),
            'currency' => Text::plain(is_string($product['currency'] ?? null) ? $product['currency'] : '', 12),
            'in_stock' => $inStock,
            'stock_status' => $stockStatus,
            'stock_quantity' => $this->publicInteger($product['stock_quantity'] ?? null, -2000000000, 2000000000),
            'rating' => $this->publicNumber($product['rating'] ?? 0.0, 0.0, 5.0) ?? 0.0,
            'review_count' => $this->publicInteger($product['review_count'] ?? 0, 0, 2000000000) ?? 0,
            'short_description' => Text::plain(
                is_string($product['short_description'] ?? null) ? $product['short_description'] : '',
                500
            ),
            'image' => $this->publicRawString($product['image'] ?? '', 2048),
            'url' => $this->publicRawString($product['url'] ?? '', 2048),
            'purchasable' => ($product['purchasable'] ?? false) === true,
            'requires_options' => ($product['requires_options'] ?? false) === true,
            'categories' => $this->publicStringList($product['categories'] ?? array(), 8, 160),
            'categories_truncated' => ($product['categories_truncated'] ?? false) === true,
            'attributes' => $this->publicStringListMap($product['attributes'] ?? array(), 24, 40, 160, 200),
            'attributes_truncated' => ($product['attributes_truncated'] ?? false) === true,
            'variation_options' => $this->publicStringListMap(
                $product['variation_options'] ?? array(),
                24,
                40,
                160,
                200
            ),
            'variation_options_truncated' => ($product['variation_options_truncated'] ?? false) === true,
        );
    }

    /** @param array<string,mixed> $cart @return array<string,mixed>|null */
    private function publicCart(array $cart): ?array
    {
        $sourceItems = $cart['items'] ?? null;
        if (!is_array($sourceItems) || !array_is_list($sourceItems)) {
            return null;
        }
        $items = array();
        foreach (array_slice($sourceItems, 0, 100) as $item) {
            if (!is_array($item) || array_is_list($item)) {
                return null;
            }
            $name = Text::plain(is_string($item['name'] ?? null) ? $item['name'] : '', 500);
            $quantity = $this->publicInteger($item['quantity'] ?? null, 1, 2000000000);
            $unitPrice = $this->publicNumber($item['unit_price'] ?? null, 0.0);
            $lineTotal = $this->publicNumber($item['line_total'] ?? null, 0.0);
            $ref = is_string($item['ref'] ?? null) ? $item['ref'] : '';
            if ($ref !== '' && preg_match('/^l_[A-Za-z0-9_-]{8,80}$/D', $ref) !== 1) {
                return null;
            }
            if ($name === '' || $quantity === null || $unitPrice === null || $lineTotal === null) {
                return null;
            }
            $items[] = array(
                'name' => $name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'line_total_text' => Text::plain(
                    is_string($item['line_total_text'] ?? null) ? $item['line_total_text'] : '',
                    200
                ),
                'image' => $this->publicRawString($item['image'] ?? '', 2048),
                'variation' => $this->publicStringMap($item['variation'] ?? array(), 12, 160, 200),
                'sku' => Text::plain(is_string($item['sku'] ?? null) ? $item['sku'] : '', 120),
                'ref' => $ref,
            );
        }

        $reportedLineCount = $this->publicInteger($cart['line_count'] ?? count($sourceItems), 0, 100000);
        if ($reportedLineCount === null || $reportedLineCount < count($sourceItems)) {
            return null;
        }
        $lineCount = $reportedLineCount;
        $itemCount = $this->publicInteger($cart['item_count'] ?? null, 0, 2000000000);
        $calculatedItemCount = array_sum(array_map(
            static fn (array $item): int => (int) $item['quantity'],
            $items
        ));
        if ($itemCount === null) {
            $itemCount = $calculatedItemCount;
        } elseif ($itemCount < $calculatedItemCount) {
            return null;
        }

        $total = $this->publicNumber($cart['total'] ?? null, 0.0);
        if ($total === null) {
            return null;
        }

        $public = array(
            'items' => $items,
            'line_count' => $lineCount,
            'items_truncated' => $lineCount > count($items),
            'item_count' => $itemCount,
            'total' => $total,
            'total_text' => Text::plain(is_string($cart['total_text'] ?? null) ? $cart['total_text'] : '', 200),
            'currency' => Text::plain(is_string($cart['currency'] ?? null) ? $cart['currency'] : '', 12),
            'cart_url' => $this->publicRawString($cart['cart_url'] ?? '', 2048),
            'checkout_url' => $this->publicRawString($cart['checkout_url'] ?? '', 2048),
            'cart_hash' => $this->publicRawString($cart['cart_hash'] ?? '', 256),
            'mutations_allowed' => ($cart['mutations_allowed'] ?? false) === true,
            'mutation_notice' => Text::plain(
                is_string($cart['mutation_notice'] ?? null) ? $cart['mutation_notice'] : '',
                600
            ),
        );
        if (($cart['presentation_incomplete'] ?? false) === true) {
            $notice = Text::plain(is_string($cart['notice'] ?? null) ? $cart['notice'] : '', 600);
            if ($notice !== '') {
                $public['presentation_incomplete'] = true;
                $public['notice'] = $notice;
            }
        }
        return $public;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed>|null */
    private function publicReceipt(array $receipt): ?array
    {
        $id = is_string($receipt['id'] ?? null) ? $receipt['id'] : '';
        $message = Text::plain(is_string($receipt['message'] ?? null) ? $receipt['message'] : '', 3000);
        $cart = is_array($receipt['cart'] ?? null) ? $this->publicCart($receipt['cart']) : null;
        $sourceLines = $receipt['lines'] ?? null;
        if (!Uuid::isValid($id)
            || $message === ''
            || $cart === null
            || !is_array($sourceLines)
            || !array_is_list($sourceLines)) {
            return null;
        }

        $lines = array();
        foreach (array_slice($sourceLines, 0, 12) as $line) {
            if (!is_array($line) || array_is_list($line)) {
                continue;
            }
            try {
                $nodes = 0;
                $bounded = $this->publicReceiptValue($line, 0, $nodes);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($bounded) && !array_is_list($bounded)) {
                $lines[] = $bounded;
            }
        }

        return array(
            'id' => $id,
            'message' => $message,
            'lines' => $lines,
            'cart' => $cart,
        );
    }

    private function publicReceiptValue(mixed $value, int $depth, int &$nodes): mixed
    {
        ++$nodes;
        if ($depth > 8 || $nodes > 1000) {
            throw new \LengthException('The public receipt value exceeds its structural limit.');
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || abs($value) > 1000000000000.0) {
                throw new \UnexpectedValueException('The public receipt contains an invalid number.');
            }
            return $value;
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new \UnexpectedValueException('The public receipt contains invalid text.');
            }
            return Text::slice($value, 0, 3000);
        }
        if (!is_array($value) || count($value) > 100) {
            throw new \UnexpectedValueException('The public receipt contains an unsupported value.');
        }
        $out = array();
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $out[] = $this->publicReceiptValue($item, $depth + 1, $nodes);
            }
            return $out;
        }
        foreach ($value as $key => $item) {
            if (!is_string($key) || str_starts_with($key, '_')) {
                continue;
            }
            $publicKey = Text::plain($key, 160);
            if ($publicKey === '') {
                continue;
            }
            $out[$publicKey] = $this->publicReceiptValue($item, $depth + 1, $nodes);
        }
        return $out;
    }

    /** @return array{mime_type:string,bytes:int,width:int,height:int}|null */
    private function publicImageMetadata(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }
        $mime = is_string($value['mime_type'] ?? null) ? $value['mime_type'] : '';
        $bytes = $this->publicInteger($value['bytes'] ?? null, 1, 4194304);
        $width = $this->publicInteger($value['width'] ?? null, 1, 4096);
        $height = $this->publicInteger($value['height'] ?? null, 1, 4096);
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true)
            || $bytes === null
            || $width === null
            || $height === null
            || $width * $height > 12000000) {
            return null;
        }
        return array(
            'mime_type' => $mime,
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
        );
    }

    /** @return list<string> */
    private function publicStringList(mixed $value, int $maximumItems, int $maximumLength): array
    {
        if (!is_array($value)) {
            return array();
        }
        $out = array();
        foreach (array_slice(array_values($value), 0, $maximumItems) as $item) {
            if (!is_string($item)) {
                continue;
            }
            $text = Text::plain($item, $maximumLength);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        return $out;
    }

    /** @return array<string,list<string>> */
    private function publicStringListMap(
        mixed $value,
        int $maximumEntries,
        int $maximumOptions,
        int $maximumKeyLength,
        int $maximumValueLength
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            return array();
        }
        $out = array();
        foreach (array_slice($value, 0, $maximumEntries, true) as $key => $options) {
            if (!is_string($key)) {
                continue;
            }
            $publicKey = Text::plain($key, $maximumKeyLength);
            if ($publicKey === '') {
                continue;
            }
            if (is_string($options)) {
                $options = array($options);
            }
            $publicOptions = $this->publicStringList($options, $maximumOptions, $maximumValueLength);
            if ($publicOptions !== array()) {
                $out[$publicKey] = $publicOptions;
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    private function publicStringMap(
        mixed $value,
        int $maximumEntries,
        int $maximumKeyLength,
        int $maximumValueLength
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            return array();
        }
        $out = array();
        foreach (array_slice($value, 0, $maximumEntries, true) as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                continue;
            }
            $publicKey = Text::plain($key, $maximumKeyLength);
            $publicValue = Text::plain($item, $maximumValueLength);
            if ($publicKey !== '' && $publicValue !== '') {
                $out[$publicKey] = $publicValue;
            }
        }
        return $out;
    }

    private function publicRawString(mixed $value, int $maximumLength): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            return '';
        }
        return Text::slice(trim($value), 0, $maximumLength);
    }

    private function publicNumber(
        mixed $value,
        float $minimum = 0.0,
        float $maximum = 1000000000000.0
    ): ?float {
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;
        } elseif (is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) === 1) {
            $number = (float) $value;
        } else {
            return null;
        }
        if (!is_finite($number) || $number < $minimum || $number > $maximum) {
            return null;
        }
        return $number;
    }

    private function publicInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($filtered)) {
                return null;
            }
            $integer = $filtered;
        } else {
            return null;
        }
        return $integer >= $minimum && $integer <= $maximum ? $integer : null;
    }

    /**
     * @param list<mixed> $messages
     * @return list<array<string,mixed>>
     */
    private function publicMessages(array $messages): array
    {
        $out = array();
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $public = $this->publicMessage($message);
            if ($public !== null) {
                $out[] = $public;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $message @return array<string,mixed>|null */
    private function publicMessage(array $message): ?array
    {
        $payload = is_array($message['payload'] ?? null) && !array_is_list($message['payload'])
            ? $message['payload']
            : array();
        $role = is_string($message['role'] ?? null) ? $message['role'] : '';
        $id = $this->publicInteger($message['id'] ?? null, 1, PHP_INT_MAX);
        $turnId = $this->publicInteger($message['turn_id'] ?? null, 1, PHP_INT_MAX);
        $content = Text::slice(is_string($message['content'] ?? null) ? $message['content'] : '', 0, 8192);
        $createdAt = $this->publicRawString($message['created_at'] ?? '', 40);
        if (!in_array($role, array('user', 'assistant'), true)
            || $id === null
            || $turnId === null
            || $content === ''
            || $createdAt === '') {
            return null;
        }

        $public = array(
            'id' => $id,
            'turn_id' => $turnId,
            'role' => $role,
            'content' => $content,
            'created_at' => $createdAt,
        );

        if ($role === 'user') {
            $clientTurnId = is_string($payload['client_turn_id'] ?? null)
                ? trim($payload['client_turn_id'])
                : '';
            if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientTurnId) === 1) {
                $public['client_turn_id'] = $clientTurnId;
            }
            $image = $this->publicImageMetadata($payload['image'] ?? null);
            if ($image !== null) {
                $public['has_image'] = true;
                $public['image'] = $image;
            }
            return $public;
        }

        $rawKind = is_string($payload['kind'] ?? null) ? trim($payload['kind']) : '';
        $knownKinds = array('answer', 'follow_up', 'safe_failure', 'cart_receipt', 'cart_uncertain');
        $kindWasKnown = in_array($rawKind, $knownKinds, true);
        $kind = $kindWasKnown ? $rawKind : 'answer';
        $public['kind'] = $kind;

        if (!$kindWasKnown) {
            // A future or corrupted historical kind is displayed as inert text.
            // It must never carry stale commerce authority into the browser.
            return $public;
        }

        if (in_array($kind, array('answer', 'follow_up'), true)) {
            $public['products'] = $this->publicProducts(
                is_array($payload['products'] ?? null) ? $payload['products'] : array()
            );
            return $public;
        }

        if ($kind === 'cart_receipt') {
            $receipt = is_array($payload['receipt'] ?? null)
                ? $this->publicReceipt($payload['receipt'])
                : null;
            if ($receipt === null) {
                // Historical malformed receipts must never be presented as
                // verified commerce results after a stricter upgrade.
                $public['kind'] = 'cart_uncertain';
                return $public;
            }
            $public['receipt'] = $receipt;
            $public['cart'] = $receipt['cart'];
        }

        return $public;
    }

    /** @return array<string,mixed> */
    private function publicShoppingMemory(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return array();
        }

        $memory = array();
        $minimum = $this->publicNumber($value['budget_min'] ?? null, 0.0, 1000000000.0);
        $maximum = $this->publicNumber($value['budget_max'] ?? null, 0.0, 1000000000.0);
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            $minimum = null;
            $maximum = null;
        }
        if ($minimum !== null) {
            $memory['budget_min'] = $minimum;
        }
        if ($maximum !== null) {
            $memory['budget_max'] = $maximum;
        }

        if (array_key_exists('categories', $value)) {
            $categories = array_values(array_unique(
                $this->publicStringList($value['categories'], 12, 80)
            ));
            if ($categories !== array()) {
                $memory['categories'] = $categories;
            }
        }
        if (array_key_exists('attributes', $value)) {
            $attributes = $this->publicStringMap($value['attributes'], 20, 60, 120);
            if ($attributes !== array()) {
                $memory['attributes'] = $attributes;
            }
        }
        if (is_string($value['notes'] ?? null)) {
            $notes = Text::plain($value['notes'], 500);
            if ($notes !== '') {
                $memory['notes'] = $notes;
            }
        }
        return $memory;
    }

    /** @param list<mixed> $products @return list<array<string,mixed>> */
    private function publicProducts(array $products): array
    {
        $out = array();
        foreach (array_slice($products, 0, 12) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $public = $this->publicProductCard($product);
            if ($public !== null) {
                $out[] = $public;
            }
        }
        return $out;
    }

    /** @param mixed $value */
    private function conversationId(mixed $value): string
    {
        $id = is_string($value) ? trim($value) : '';
        if (!Uuid::isValid($id)) {
            throw new PublicException('معرّف المحادثة غير صالح.', 'invalid_conversation_id', 422);
        }
        return $id;
    }

    /** @param mixed $value */
    private function token(mixed $value): string
    {
        $token = is_string($value) ? trim($value) : '';
        if (strlen($token) < 40 || strlen($token) > 100 || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            throw new PublicException('رمز المحادثة غير صالح.', 'invalid_conversation_token', 422);
        }
        return $token;
    }

    /** @param mixed $value */
    private function clientTurnId(mixed $value): string
    {
        $id = is_string($value) ? trim($value) : '';
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $id) !== 1) {
            throw new PublicException('معرّف الطلب غير صالح.', 'invalid_turn_id', 422);
        }
        return $id;
    }

    /** @param mixed $value @return array<string,mixed>|null */
    private function reply(string $conversationId, mixed $value): ?array
    {
        if ($value === null || $value === array()) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new PublicException('سياق الرد غير صالح.', 'invalid_reply_context', 422);
        }
        $allowed = array('message_id', 'product_ref');
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new PublicException('سياق الرد يحتوي على حقول غير مدعومة.', 'invalid_reply_context', 422);
            }
        }
        if (!isset($value['message_id']) || !is_int($value['message_id']) || $value['message_id'] <= 0) {
            throw new PublicException('يجب أن يشير الرد إلى رسالة مساعد محفوظة.', 'invalid_reply_context', 422);
        }

        $message = $this->conversations->message($conversationId, $value['message_id']);
        if (!is_array($message) || ($message['role'] ?? '') !== 'assistant') {
            throw new PublicException('رسالة الرد غير موجودة في هذه المحادثة.', 'invalid_reply_context', 422);
        }
        $text = Text::plain((string) ($message['content'] ?? ''), 1000);
        if ($text === '') {
            throw new PublicException('رسالة الرد لا تحتوي على سياق صالح.', 'invalid_reply_context', 422);
        }

        $reply = array(
            'message_id' => (int) $message['id'],
            'text' => $text,
        );
        if (array_key_exists('product_ref', $value)) {
            $productRef = is_string($value['product_ref']) ? $value['product_ref'] : '';
            if (preg_match('/^p_[A-Za-z0-9_-]{8,80}$/', $productRef) !== 1
                || !$this->messageContainsProductRef($message, $productRef)) {
                throw new PublicException('مرجع المنتج لا ينتمي إلى رسالة الرد.', 'invalid_reply_context', 422);
            }
            $reply['product_ref'] = $productRef;
        }
        return $reply;
    }

    /** @param array<string,mixed> $message */
    private function messageContainsProductRef(array $message, string $productRef): bool
    {
        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : array();
        foreach ((array) ($payload['products'] ?? array()) as $product) {
            if (is_array($product)
                && is_string($product['ref'] ?? null)
                && hash_equals($productRef, $product['ref'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed>|null $reply */
    private function requestHash(string $message, ?array $reply, ?ImageInput $image): string
    {
        $json = json_encode(array(
            'message' => $message,
            'reply' => $reply,
            'image' => $image === null ? null : array(
                'mime_type' => $image->mimeType,
                'sha256' => $image->sha256,
                'bytes' => $image->bytes,
                'width' => $image->width,
                'height' => $image->height,
            ),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    private function exceptionFingerprint(\Throwable $error): string
    {
        return substr(hash('sha256', $error::class . "\0" . $error->getMessage()), 0, 16);
    }

    /** @param array<string,mixed> $conversation */
    private function sessionExpired(array $conversation): bool
    {
        $minutes = max(0, (int) $this->settings->get('assistant_session_minutes', 0));
        if ($minutes === 0) {
            return false;
        }
        try {
            $last = new \DateTimeImmutable((string) ($conversation['last_activity_at'] ?? ''), new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return true;
        }
        return $last->modify('+' . $minutes . ' minutes') <= $this->clock->now();
    }

    private function assertOperationRate(
        string $operation,
        string $conversationId,
        int $browserLimit,
        int $conversationLimit
    ): void {
        $browser = $this->identity->browserBucket();
        if (!$this->rateLimiter->consume('browser_' . $operation, $browser, $browserLimit, 300)
            || !$this->rateLimiter->consume('conversation_' . $operation, $conversationId, $conversationLimit, 300)) {
            throw new PublicException(
                'تم تجاوز حد هذه العملية مؤقتًا.',
                'rate_limited',
                429,
                $this->retryAfterSeconds(300)
            );
        }
    }

    private function retryAfterSeconds(int $windowSeconds): int
    {
        $windowSeconds = max(1, min(86_400, $windowSeconds));
        $timestamp = $this->clock->now()->getTimestamp();
        $elapsed = (($timestamp % $windowSeconds) + $windowSeconds) % $windowSeconds;
        return max(1, $windowSeconds - $elapsed);
    }

    private function retentionDays(): int
    {
        return max(1, min(365, (int) $this->settings->get('conversation_retention_days', 45)));
    }

    private function turnLeaseSeconds(): int
    {
        return TurnTimingPolicy::leaseSeconds(
            (int) $this->settings->get('http_timeout_seconds', 35),
            (int) $this->settings->get('max_tool_rounds', 6)
        );
    }
}
