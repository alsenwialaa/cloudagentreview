<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Application\Contract;

interface ContentGateway
{
    /** @return list<array<string,mixed>> */
    public function search(string $query, int $limit): array;

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array;

    /** @return array<string,mixed> */
    public function storeInfo(): array;

    /** @return array<string,mixed> */
    public function policies(): array;
}
