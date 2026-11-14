<?php

namespace App\Connectors\Enums;

enum DataSourceStatus: string
{
    case Draft = 'draft';
    case Configured = 'configured';
    case Healthy = 'healthy';
    case Error = 'error';
    case Disabled = 'disabled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Configured, self::Disabled],
            self::Configured => [self::Healthy, self::Error, self::Disabled, self::Configured],
            self::Healthy => [self::Error, self::Disabled, self::Healthy, self::Configured],
            self::Error => [self::Healthy, self::Error, self::Disabled, self::Configured],
            self::Disabled => [self::Configured],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isExecutable(): bool
    {
        return $this !== self::Disabled && $this !== self::Draft;
    }
}
