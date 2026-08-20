<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\WooCommerce;

interface CartSessionPersistence
{
    /**
     * Reports whether the configured WooCommerce session handler has a
     * persistence adapter that can prove canonical read-after-write
     * durability. Null means WooCommerce has not loaded far enough to decide.
     */
    public function configurationStatus(): ?bool;

    /**
     * Read selected logical session values from the canonical durable store
     * without treating WooCommerce or WordPress object caches as evidence.
     *
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    public function read(array $keys): array;

    /**
     * Ask the active WooCommerce session handler to persist its complete
     * request-local state.
     *
     * This operation deliberately does not return state. A caller must perform
     * a separate canonical read() before it may claim that a commit or rollback
     * reached durable storage. Keeping the write and proof operations distinct
     * prevents an adapter from accidentally treating request-local or cached
     * state as read-after-write evidence.
     */
    public function persist(): void;

    /**
     * Remove any request-external cache entry that could disagree with the
     * durable session row after an uncertain write.
     */
    public function invalidateCache(): void;
}
