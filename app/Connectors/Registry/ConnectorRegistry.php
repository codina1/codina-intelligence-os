<?php

namespace App\Connectors\Registry;

use App\Connectors\Contracts\ExternalConnectorInterface;
use App\Connectors\Mock\GoogleAnalytics4MockConnector;
use App\Connectors\Mock\GoogleSearchConsoleMockConnector;
use App\Connectors\Mock\TutorLmsProMockConnector;
use App\Connectors\Mock\WooCommerceMockConnector;
use App\Connectors\Mock\WordPressMockConnector;
use InvalidArgumentException;

class ConnectorRegistry
{
    /** @var array<string, ExternalConnectorInterface> */
    private array $connectors = [];

    public function __construct()
    {
        foreach ([
            new WordPressMockConnector,
            new WooCommerceMockConnector,
            new GoogleSearchConsoleMockConnector,
            new GoogleAnalytics4MockConnector,
            new TutorLmsProMockConnector,
        ] as $connector) {
            $this->connectors[$connector->key()] = $connector;
        }
    }

    /**
     * @return list<ExternalConnectorInterface>
     */
    public function all(): array
    {
        return array_values($this->connectors);
    }

    public function get(string $key): ExternalConnectorInterface
    {
        if (! isset($this->connectors[$key])) {
            throw new InvalidArgumentException("Unknown connector [{$key}].");
        }

        return $this->connectors[$key];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summarize(): array
    {
        return array_map(function (ExternalConnectorInterface $connector): array {
            return [
                'key' => $connector->key(),
                'name' => $connector->name(),
                'mode' => 'mock',
                'capabilities' => $connector->capabilities(),
                'health' => $connector->health(),
            ];
        }, $this->all());
    }
}
