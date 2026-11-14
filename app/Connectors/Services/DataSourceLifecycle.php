<?php

namespace App\Connectors\Services;

use App\Connectors\Enums\DataSourceStatus;
use DomainException;

class DataSourceLifecycle
{
    public function assertTransition(DataSourceStatus $from, DataSourceStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw new DomainException("Invalid data source status transition from [{$from->value}] to [{$to->value}].");
        }
    }

    /**
     * Public API may not set health-derived states; only mock health checks may.
     */
    public function assertPublicTransition(DataSourceStatus $from, DataSourceStatus $to): void
    {
        if (in_array($to, [DataSourceStatus::Healthy, DataSourceStatus::Error], true)) {
            throw new DomainException(
                'Status healthy/error can only be set by mock health-check execution, not by public updates.'
            );
        }

        if ($from === DataSourceStatus::Disabled && $to !== DataSourceStatus::Configured) {
            throw new DomainException('A disabled data source may only be re-enabled to configured.');
        }

        $this->assertTransition($from, $to);
    }

    public function assertExecutable(DataSourceStatus $status): void
    {
        if ($status === DataSourceStatus::Disabled) {
            throw new DomainException('Disabled data sources cannot execute mock tests or sync operations.');
        }

        if ($status === DataSourceStatus::Draft) {
            throw new DomainException('Draft data sources must be configured before mock tests.');
        }
    }
}
