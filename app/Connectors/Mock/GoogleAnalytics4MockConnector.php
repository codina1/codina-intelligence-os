<?php

namespace App\Connectors\Mock;

class GoogleAnalytics4MockConnector extends AbstractMockConnector
{
    public function key(): string
    {
        return 'google_analytics_4';
    }

    public function name(): string
    {
        return 'Google Analytics 4';
    }

    protected function fixtureNamespace(): string
    {
        return 'google_analytics_4';
    }
}
