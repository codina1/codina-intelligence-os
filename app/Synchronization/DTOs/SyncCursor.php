<?php

namespace App\Synchronization\DTOs;

final class SyncCursor
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload = []) {}

    public static function initial(): self
    {
        return new self(['page' => 1]);
    }

    public static function fromArray(?array $payload): self
    {
        if ($payload === null || $payload === []) {
            return self::initial();
        }

        return new self($payload);
    }

    public function page(): int
    {
        return max(1, (int) ($this->payload['page'] ?? 1));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    public function fingerprint(): string
    {
        $normalized = $this->payload;
        ksort($normalized);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}
