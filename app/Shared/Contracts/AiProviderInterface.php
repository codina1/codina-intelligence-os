<?php

namespace App\Shared\Contracts;

/**
 * Phase 01 placeholder only. No AI provider calls are implemented.
 */
interface AiProviderInterface
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $prompt
     * @return array<string, mixed>
     */
    public function complete(array $prompt): array;
}
