<?php

namespace App\Synchronization\DTOs;

use App\Synchronization\Enums\SyncRunStatus;

final class SyncResult
{
    public function __construct(
        public readonly SyncRunStatus $status,
        public readonly int $rowsReceived = 0,
        public readonly int $rowsInserted = 0,
        public readonly int $rowsUpdated = 0,
        public readonly int $rowsUnchanged = 0,
        public readonly int $rowsRejected = 0,
        public readonly int $pagesCompleted = 0,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorSummary = null,
        public readonly string $completenessStatus = 'unknown',
    ) {}
}
