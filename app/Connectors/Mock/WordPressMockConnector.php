<?php

namespace App\Connectors\Mock;

class WordPressMockConnector extends AbstractMockConnector
{
    public function key(): string
    {
        return 'wordpress';
    }

    public function name(): string
    {
        return 'WordPress';
    }

    protected function fixtureNamespace(): string
    {
        return 'wordpress';
    }
}
