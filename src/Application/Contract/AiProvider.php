<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

interface AiProvider
{
    /**
     * @param list<array<string,mixed>> $history
     * @param list<array<string,mixed>> $tools
     * @return array<string,mixed>
     */
    public function interact(array $history, array $tools, string $systemInstruction): array;

    /**
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    public function structured(string $input, array $schema, string $systemInstruction, string $thinkingLevel = 'low'): array;

    /**
     * Verify both structured output and the exact production chat tool bundle.
     *
     * @param list<array<string,mixed>> $tools
     * @return array<string,mixed>
     */
    public function readinessCheck(array $tools = array()): array;
}
