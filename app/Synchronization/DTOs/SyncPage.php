<?php

namespace App\Synchronization\DTOs;

final class SyncPage
{
    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>|null  $cursorBefore
     * @param  array<string, mixed>|null  $cursorAfter
     */
    public function __construct(
        public readonly array $records,
        public readonly ?array $cursorBefore,
        public readonly ?array $cursorAfter,
        public readonly bool $hasMore,
        public readonly string $sourceMode = 'mock',
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $retryAfterSeconds = null,
        /** Change-stream high-water mark (incremental only); never a pagination cursor. */
        public readonly ?string $streamHighWater = null,
    ) {}

    public function isError(): bool
    {
        return $this->errorCode !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'records' => $this->records,
            'cursor_before' => $this->cursorBefore,
            'cursor_after' => $this->cursorAfter,
            'has_more' => $this->hasMore,
            'source_mode' => $this->sourceMode,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }
}
