<?php

namespace App\Connectors\Mock;

class WooCommerceMockConnector extends AbstractMockConnector
{
    public function key(): string
    {
        return 'woocommerce';
    }

    public function name(): string
    {
        return 'WooCommerce';
    }

    protected function fixtureNamespace(): string
    {
        return 'woocommerce';
    }
}
