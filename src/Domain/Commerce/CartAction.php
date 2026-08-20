<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

enum CartAction: string
{
    case Add = 'add';
    case SetQuantity = 'set_quantity';
    case Increment = 'increment';
    case Decrement = 'decrement';
    case Remove = 'remove';
    case Replace = 'replace';
    case Clear = 'clear';
}
