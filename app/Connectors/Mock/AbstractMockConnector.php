<?php

namespace App\Connectors\Mock;

use App\Connectors\Contracts\ExternalConnectorInterface;
use RuntimeException;

abstract class AbstractMockConnector implements ExternalConnectorInterface
{
    abstract public function key(): string;

    abstract public function name(): string;

    abstract protected function fixtureNamespace(): string;

    public function capabilities(): array
    {
        return [
            'capability_discovery',
            'connection_test',
            'paginated_fetch',
            'health_status',
            'error_simulation',
        ];
    }

    public function testConnection(): array
    {
        $this->assertNoExternalHttp();

        return [
            'ok' => true,
            'message' => $this->name().' mock connection succeeded.',
            'latency_ms' => 1,
        ];
    }

    public function health(): array
    {
        $this->assertNoExternalHttp();

        return [
            'status' => 'healthy',
            'details' => [
                'connector' => $this->key(),
                'mode' => 'mock',
                'external_http' => false,
            ],
        ];
    }

    public function fetch(array $options = []): array
    {
        $this->assertNoExternalHttp();

        $scenario = (string) ($options['scenario'] ?? 'success');
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($options['per_page'] ?? 10)));

        if ($scenario !== 'success' && $scenario !== 'pagination' && $scenario !== 'empty') {
            return $this->simulateError($scenario, $options);
        }

        $items = $this->loadFixture($scenario === 'empty' ? 'empty' : 'success');

        if ($scenario === 'empty') {
            return [
                'data' => [],
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'scenario' => 'empty',
                    'connector' => $this->key(),
                ],
            ];
        }

        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return [
            'data' => array_values($slice),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, (int) ceil($total / $perPage)),
                'scenario' => $scenario === 'pagination' ? 'pagination' : 'success',
                'connector' => $this->key(),
            ],
        ];
    }

    public function simulateError(string $scenario, array $options = []): array
    {
        $this->assertNoExternalHttp();

        $map = [
            'invalid_payload' => ['code' => 'invalid_payload', 'message' => 'Fixture payload failed validation.', 'status' => 422],
            'rate_limit' => ['code' => 'rate_limit', 'message' => 'Mock rate limit exceeded.', 'status' => 429],
            'unauthorized' => ['code' => 'unauthorized', 'message' => 'Mock credentials rejected.', 'status' => 401],
            'timeout' => ['code' => 'timeout', 'message' => 'Mock upstream timeout.', 'status' => 504],
            'temporary_failure' => ['code' => 'temporary_failure', 'message' => 'Mock upstream temporary failure.', 'status' => 503],
            'repeated_sync' => ['code' => 'repeated_sync', 'message' => 'Repeated sync request ignored by mock.', 'status' => 409],
        ];

        $error = $map[$scenario] ?? ['code' => 'unknown_scenario', 'message' => 'Unknown mock error scenario.', 'status' => 400];

        return [
            'error' => [
                'code' => $error['code'],
                'message' => $error['message'],
            ],
            'meta' => [
                'connector' => $this->key(),
                'scenario' => $scenario,
                'http_status' => $error['status'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function loadFixture(string $name): array
    {
        $path = database_path('fixtures/connectors/'.$this->fixtureNamespace().'/'.$name.'.json');

        if (! is_file($path)) {
            throw new RuntimeException("Missing connector fixture: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException("Invalid connector fixture JSON: {$path}");
        }

        return $decoded;
    }

    protected function assertNoExternalHttp(): void
    {
        if (config('codina.allow_external_http') === true) {
            throw new RuntimeException('External HTTP is forbidden for mock connectors in Phase 01.');
        }
    }
}
