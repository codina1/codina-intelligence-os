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
