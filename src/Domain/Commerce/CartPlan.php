<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Domain\Commerce;

final readonly class CartPlan
{
    /** @var list<CartCommand> */
    public array $commands;

    /** @param list<CartCommand> $commands */
    public function __construct(array $commands)
    {
        $count = count($commands);
        if ($count < 1 || $count > 12) {
            throw new \InvalidArgumentException('A cart plan must contain between one and twelve commands.');
        }

        $clearCount = 0;
        $targets = array();
        $destinations = array();

        foreach ($commands as $command) {
            if (!$command instanceof CartCommand) {
                throw new \InvalidArgumentException('Invalid cart command.');
            }

            switch ($command->action) {
                case CartAction::Clear:
                    ++$clearCount;
                    if ($command->targetRef !== null || $command->productRef !== null || $command->quantity !== null
                        || $command->quantityMode !== CartQuantityMode::Explicit) {
                        throw new \InvalidArgumentException('Clear cannot include a target, product, quantity, or quantity mode.');
                    }
                    break;

                case CartAction::SetQuantity:
                case CartAction::Increment:
                case CartAction::Decrement:
                    if ($command->targetRef === null || $command->productRef !== null
                        || $command->quantityMode !== CartQuantityMode::Explicit
                        || $command->quantity === null || $command->quantity < 1 || $command->quantity > 1000) {
                        throw new \InvalidArgumentException('Quantity commands require only a target and a positive bounded explicit quantity.');
                    }
                    break;

                case CartAction::Remove:
                    if ($command->targetRef === null || $command->productRef !== null || $command->quantity !== null
                        || $command->quantityMode !== CartQuantityMode::Explicit) {
                        throw new \InvalidArgumentException('Remove requires only a target.');
                    }
                    break;

                case CartAction::Add:
                    if ($command->targetRef !== null || $command->productRef === null
                        || $command->quantityMode !== CartQuantityMode::Explicit
                        || $command->quantity === null || $command->quantity < 1 || $command->quantity > 1000) {
                        throw new \InvalidArgumentException('Add requires only a product reference and a positive bounded explicit quantity.');
                    }
                    break;

                case CartAction::Replace:
                    $explicitQuantityIsValid = $command->quantityMode === CartQuantityMode::Explicit
                        && $command->quantity !== null
                        && $command->quantity >= 1
                        && $command->quantity <= 1000;
                    $preservesSource = $command->quantityMode === CartQuantityMode::PreserveSource
                        && $command->quantity === null;
                    if ($command->targetRef === null || $command->productRef === null
                        || (!$explicitQuantityIsValid && !$preservesSource)) {
                        throw new \InvalidArgumentException(
                            'Replace requires a source target, replacement product, and either an explicit positive quantity or preserve_source.'
                        );
                    }
                    break;
            }

            if ($command->targetRef !== null) {
                if (isset($targets[$command->targetRef])) {
                    throw new \InvalidArgumentException('One cart line cannot be targeted twice in the same plan.');
                }
                $targets[$command->targetRef] = true;
            }

            if ($command->productRef !== null) {
                if (isset($destinations[$command->productRef])) {
                    throw new \InvalidArgumentException('One destination product cannot appear twice in the same plan.');
                }
                $destinations[$command->productRef] = true;
            }
        }

        if ($clearCount > 0 && ($clearCount !== 1 || $count !== 1)) {
            throw new \InvalidArgumentException('Clear must be the only command in its plan.');
        }

        $this->commands = array_values($commands);
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $raw = $input['commands'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Cart commands must be a list.');
        }

        $commands = array_map(
            static fn (mixed $item): CartCommand => CartCommand::fromArray(is_array($item) ? $item : array()),
            $raw
        );

        return new self($commands);
    }
}
