<?php

namespace App\Connectors\Secrets;

use App\Connectors\Contracts\SecretStoreInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 02A null/mock secret store — accepts only mock: prefixed values and
 * never talks to an external secret manager.
 */
class NullSecretStore implements SecretStoreInterface
{
    /** @var array<string, true> */
    private array $references = [];

    public function putMockReference(string $tenantKey, string $mockValue): string
    {
        if (! str_starts_with($mockValue, 'mock:')) {
            throw new InvalidArgumentException('Only mock: prefixed references are allowed in Phase 02A.');
        }

        if ($this->looksLikeRealSecret($mockValue)) {
            throw new InvalidArgumentException('Real secrets cannot be stored in Phase 02A.');
        }

        $reference = 'mock:ref:'.$tenantKey.':'.bin2hex(random_bytes(8));
        $this->references[$reference] = true;

        return $reference;
    }

    public function exists(string $reference): bool
    {
        return isset($this->references[$reference]) || str_starts_with($reference, 'mock:ref:');
    }

    public function forget(string $reference): void
    {
        unset($this->references[$reference]);
    }

    public function retrieveForbidden(): never
    {
        throw new RuntimeException('Secret retrieval is disabled in Phase 02A.');
    }

    private function looksLikeRealSecret(string $value): bool
    {
        $stripped = substr($value, strlen('mock:'));

        return preg_match('/(sk-|AIza|ya29\.|ghp_|xox[baprs]-)/i', $stripped) === 1;
    }
}
