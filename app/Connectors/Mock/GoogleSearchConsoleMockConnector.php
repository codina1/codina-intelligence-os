<?php

namespace App\Connectors\Mock;

class GoogleSearchConsoleMockConnector extends AbstractMockConnector
{
    public function key(): string
    {
        return 'google_search_console';
    }

    public function name(): string
    {
        return 'Google Search Console';
    }

    protected function fixtureNamespace(): string
    {
        return 'google_search_console';
    }
}
