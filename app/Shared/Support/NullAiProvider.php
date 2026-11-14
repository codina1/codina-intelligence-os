<?php

namespace App\Shared\Support;

use App\Shared\Contracts\AiProviderInterface;
use RuntimeException;

class NullAiProvider implements AiProviderInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function complete(array $prompt): array
    {
        throw new RuntimeException('AI provider is not enabled in Phase 01.');
    }
}
