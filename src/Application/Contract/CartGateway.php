<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

use YassinStore\AiAssistant\Application\Tool\ToolContext;
use YassinStore\AiAssistant\Domain\Commerce\CartPlan;
use YassinStore\AiAssistant\Domain\Commerce\CartReceipt;

interface CartGateway
{
    /** @return array<string,mixed> */
    public function view(ToolContext $context): array;

    public function apply(CartPlan $plan, ToolContext $context): CartReceipt;
}
