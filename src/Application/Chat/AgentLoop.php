<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Chat;

use YassinStore\AiAssistant\Application\Contract\AiProvider;
use YassinStore\AiAssistant\Application\Contract\ConversationRepository;
use YassinStore\AiAssistant\Application\Contract\RuntimeSettings;
use YassinStore\AiAssistant\Application\Support\Text;
use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Application\Tool\ToolRegistry;
use YassinStore\AiAssistant\Infrastructure\Security\ImageInput;

final class AgentLoop
{
    private const MAX_PROVIDER_STEPS = 100;
    private const MAX_FUNCTION_CALLS_PER_STEP = 8;
    private const MAX_FUNCTION_CALLS_PER_TURN = 24;
    private const MAX_HISTORY_STEPS = 200;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly ToolRegistry $tools,
        private readonly PromptFactory $prompts,
        private readonly ConversationRepository $conversations,
        private readonly RuntimeSettings $settings
    ) {
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param array<string,mixed>|null $reply
     * @return array<string,mixed>
     */
    public function run(
        string $conversationId,
        array $messages,
        string $currentMessage,
        ?array $reply,
        ?ImageInput $image,
        ToolContext $context
    ): array {
        $memory = $this->conversations->memory($conversationId);
        $history = $this->prompts->history($messages, $currentMessage, $reply, $image);
        $system = $this->prompts->system(
            $memory,
            $context->pendingCartClarification(),
            $context->activeCatalogContinuation()
        );
        $schemas = $this->tools->schemas();
        $declaredToolNames = array();
        foreach ($schemas as $schema) {
            if (is_array($schema) && is_string($schema['name'] ?? null)) {
                $declaredToolNames[$schema['name']] = true;
            }
        }
        $replyText = $this->replyText($reply);
        $maxRounds = max(2, min(10, (int) $this->settings->get('max_tool_rounds', 6)));
        $totalCalls = 0;
        $seenCallIds = array();

        for ($round = 0; $round < $maxRounds; ++$round) {
            if (count($history) > self::MAX_HISTORY_STEPS) {
                return $this->safeFailure(
                    'تجاوز الطلب حدود سياق التشغيل الآمن. ابدأ طلبًا أقصر وأكثر تحديدًا.',
                    $context
                );
            }
            $context->heartbeatTurn();
            $response = $this->provider->interact($history, $schemas, $system);
            $steps = is_array($response['steps'] ?? null) ? $response['steps'] : array();
            if (!array_is_list($steps) || count($steps) > self::MAX_PROVIDER_STEPS) {
                return $this->safeFailure('أعاد مزود الذكاء استجابة غير صالحة، لذلك لم تُنفّذ أي إجراءات.', $context);
            }
            $wireSteps = $response['_wire_steps'] ?? null;
            if ($wireSteps !== null
                && (!is_array($wireSteps)
                    || !array_is_list($wireSteps)
                    || count($wireSteps) !== count($steps))) {
                return $this->safeFailure('أعاد مزود الذكاء تمثيلًا غير صالح لسجل الأدوات، لذلك لم تُنفّذ أي إجراءات.', $context);
            }
            $calls = array();

            foreach ($steps as $stepIndex => $step) {
                if (!is_array($step)) {
                    return $this->safeFailure('أعاد مزود الذكاء خطوة غير صالحة، لذلك لم تُنفّذ أي إجراءات.', $context);
                }
                $wireStep = $wireSteps === null ? $step : ($wireSteps[$stepIndex] ?? null);
                if (!is_array($wireStep) && !$wireStep instanceof \stdClass) {
                    return $this->safeFailure('أعاد مزود الذكاء خطوة غير قابلة لإعادة الإرسال بأمان.', $context);
                }
                // Gemini requires every model-generated step to be replayed
                // exactly during a stateless tool round. The provider keeps a
                // raw JSON-native copy so empty objects and signatures are not
                // collapsed by PHP's associative decoding.
                $history[] = $wireStep;
                if (($step['type'] ?? '') === 'function_call') {
                    $callId = $step['id'] ?? null;
                    $name = $step['name'] ?? null;
                    if (!$this->validCallId($callId)
                        || !is_string($name)
                        || !isset($declaredToolNames[$name])
                        || !is_array($step['arguments'] ?? null)
                        || (($step['arguments'] ?? array()) !== array()
                            && array_is_list($step['arguments']))
                        || isset($seenCallIds[$callId])) {
                        return $this->safeFailure('أعاد مزود الذكاء طلب أداة غير صالح أو مكرر، لذلك لم تُنفّذ أي إجراءات.', $context);
                    }
                    $seenCallIds[$callId] = true;
                    $calls[] = $step;
                }
            }

            if (count($calls) > self::MAX_FUNCTION_CALLS_PER_STEP) {
                return $this->safeFailure('تجاوزت الاستجابة حد الأدوات الآمن، لذلك لم تُنفّذ أي إجراءات.', $context);
            }
            $totalCalls += count($calls);
            if ($totalCalls > self::MAX_FUNCTION_CALLS_PER_TURN) {
                return $this->safeFailure('تجاوز الطلب حد الأدوات الآمن لهذا الدور، لذلك لم تُنفّذ أي إجراءات.', $context);
            }

            if ($calls === array()) {
                return $this->safeFailure(
                    'تعذّر إعداد إجابة موثوقة من بيانات المتجر الآن. جرّب صياغة طلبك بشكل أوضح.',
                    $context
                );
            }

            $terminalCalls = array_values(array_filter(
                $calls,
                fn (array $call): bool => $this->tools->isTerminal((string) ($call['name'] ?? ''))
            ));

            if ($terminalCalls !== array()) {
                if (count($calls) === 1 && count($terminalCalls) === 1) {
                    $call = $terminalCalls[0];
                    $terminal = $this->tools->terminal(
                        (string) ($call['name'] ?? ''),
                        is_array($call['arguments'] ?? null) ? $call['arguments'] : array(),
                        $context
                    );
                    return $this->withContext($terminal, $context);
                }

                foreach ($calls as $call) {
                    $history[] = $this->resultStep($call, array(
                        'ok' => false,
                        'error' => 'A terminal response must be the only function call in its model step.',
                    ));
                }
                continue;
            }

            $hasCartApply = count(array_filter(
                $calls,
                static fn (array $call): bool => ($call['name'] ?? '') === 'cart_apply'
            )) > 0;
            if ($hasCartApply && count($calls) !== 1) {
                foreach ($calls as $call) {
                    $history[] = $this->resultStep($call, array(
                        'ok' => false,
                        'error' => 'cart_apply must be the only function call in its model step. No cart change was executed.',
                    ));
                }
                continue;
            }

            foreach ($calls as $call) {
                $name = (string) ($call['name'] ?? '');
                $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : array();
                $execution = $this->tools->execute(
                    $name,
                    $arguments,
                    $context,
                    $conversationId,
                    $currentMessage,
                    $replyText
                );
                if ($execution->terminal !== null) {
                    return $this->withContext($execution->terminal, $context);
                }
                $history[] = $this->resultStep($call, $execution->result);
            }
        }

        return $this->safeFailure(
            'لم أتمكن من إكمال الطلب ضمن حدود التشغيل الآمنة. حدّد المنتج أو الخيار المطلوب بشكل أدق.',
            $context
        );
    }

    /** @param array<string,mixed> $call @param array<string,mixed> $result @return array<string,mixed> */
    private function resultStep(array $call, array $result): array
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 65536) {
            $json = '{"ok":false,"error":"Tool result exceeded the safe size limit."}';
        }
        return array(
            'type' => 'function_result',
            // These opaque provider identifiers were validated before
            // execution and must be returned byte-for-byte unchanged.
            'name' => is_string($call['name'] ?? null) ? $call['name'] : '',
            'call_id' => is_string($call['id'] ?? null) ? $call['id'] : '',
            'result' => array(array('type' => 'text', 'text' => $json)),
        );
    }


    private function validCallId(mixed $value): bool
    {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 512
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function withContext(array $result, ToolContext $context): array
    {
        $result['_product_context'] = $context->productSnapshot();
        $result['_catalog_context'] = $context->catalogContextSnapshot();
        $result['_cart_clarification_context'] = $context->cartClarificationSnapshot();
        return $result;
    }

    /** @return array<string,mixed> */
    private function safeFailure(string $message, ToolContext $context): array
    {
        $context->clearCartClarification();
        return $this->withContext(array(
            'kind' => 'safe_failure',
            'message' => $message,
            // A protocol or execution failure must never be decorated with an
            // unrelated earlier product shortlist. Doing so can make a failed
            // answer look like a current recommendation.
            'products' => array(),
        ), $context);
    }

    /** @param array<string,mixed>|null $reply */
    private function replyText(?array $reply): ?string
    {
        if ($reply === null) {
            return null;
        }
        $text = Text::plain((string) ($reply['text'] ?? ''), 1000);
        return $text === '' ? null : $text;
    }
}
