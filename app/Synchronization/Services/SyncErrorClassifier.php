<?php

namespace App\Synchronization\Services;

use App\Synchronization\Enums\SyncErrorCode;
use Throwable;

class SyncErrorClassifier
{
    public function classify(?string $code, ?Throwable $throwable = null): SyncErrorCode
    {
        if ($code !== null) {
            $mapped = SyncErrorCode::tryFrom($code);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        if ($throwable !== null) {
            $message = strtolower($throwable->getMessage());
            if (str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout')) {
                return SyncErrorCode::TransientDatabaseFailure;
            }
        }

        return SyncErrorCode::TemporaryFailure;
    }

    /**
     * Deterministic mock backoff with bounded exponential growth and stable jitter.
     */
    public function backoffSeconds(int $attemptNumber, ?int $retryAfter = null, ?string $seed = null): int
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            return $retryAfter;
        }

        $baseMs = max(1, (int) config('sync.backoff_base_ms', 100));
        $exponent = max(0, $attemptNumber - 1);
        $rawMs = $baseMs * (2 ** $exponent);
        $jitterSeed = crc32($seed ?? ('attempt-'.$attemptNumber));
        $jitterMs = $jitterSeed % max(1, (int) ($baseMs / 2));

        return max(1, (int) ceil(($rawMs + $jitterMs) / 1000));
    }
}
