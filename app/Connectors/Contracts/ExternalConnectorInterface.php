<?php

namespace App\Connectors\Contracts;

interface ExternalConnectorInterface
{
    public function key(): string;

    public function name(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * @return array{ok: bool, message: string, latency_ms: int}
     */
    public function testConnection(): array;

    /**
     * @return array{status: string, details: array<string, mixed>}
     */
    public function health(): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function fetch(array $options = []): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array{error: array{code: string, message: string}, meta: array<string, mixed>}
     */
    public function simulateError(string $scenario, array $options = []): array;
}
