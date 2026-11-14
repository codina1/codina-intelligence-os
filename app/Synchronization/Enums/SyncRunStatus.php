<?php

namespace App\Synchronization\Enums;

enum SyncRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Partial = 'partial';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Running, self::Cancelled, self::Failed],
            self::Running => [self::Completed, self::Partial, self::Failed, self::Cancelled],
            self::Partial => [self::Running, self::Failed, self::Cancelled],
            self::Failed => [self::Queued],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running, self::Partial], true);
    }
}
