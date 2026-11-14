<?php

namespace App\Connectors\Contracts;

/**
 * Future secret-store adapter seam. Phase 02A must not persist real secrets.
 */
interface SecretStoreInterface
{
    /**
     * Store a mock-safe reference value and return an opaque reference handle.
     *
     * @throws \InvalidArgumentException when a real secret is supplied
     */
    public function putMockReference(string $tenantKey, string $mockValue): string;

    public function exists(string $reference): bool;

    public function forget(string $reference): void;
}
