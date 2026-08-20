<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

enum CartQuantityMode: string
{
    case Explicit = 'explicit';
    case PreserveSource = 'preserve_source';
}
