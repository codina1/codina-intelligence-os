<?php

namespace App\Connectors\Registry;

use InvalidArgumentException;

class ProviderRegistry
{
    /** @var array<string, ProviderDefinition> */
    private array $providers = [];

    public function __construct(private readonly ConnectorRegistry $connectors)
    {
        foreach ($this->connectors->all() as $connector) {
            $key = $connector->key();
            $this->providers[$key] = new ProviderDefinition(
                key: $key,
                name: $connector->name(),
                capabilities: $connector->capabilities(),
                authMethods: $this->authMethodsFor($key),
                settingsSchema: $this->settingsSchema(),
                fixtureDatasets: [
                    'success',
                    'empty',
                    'invalid_payload',
                    'paginated_25',
                    'incremental',
                    'incremental_multipage',
                    'missing_external_id',
                    'invalid_schema',
                    'duplicate_ids',
                    'changed_second_run',
                ],
                mockOnly: true,
            );
        }
    }

    /**
     * @return list<ProviderDefinition>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function get(string $key): ProviderDefinition
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown provider [{$key}].");
        }

        return $this->providers[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summarize(): array
    {
        return array_map(fn (ProviderDefinition $p) => $p->toArray(), $this->all());
    }

    /**
     * @return list<string>
     */
    private function authMethodsFor(string $key): array
    {
        return match ($key) {
            'wordpress', 'woocommerce', 'tutor_lms_pro' => ['none', 'application_password_metadata', 'api_key_metadata'],
            'google_search_console', 'google_analytics_4' => ['none', 'oauth2_metadata'],
            default => ['none'],
        };
    }

    /**
     * Shared allowlisted mock settings schema for Phase 02A.
     *
     * @return array<string, array<string, mixed>>
     */
    private function settingsSchema(): array
    {
        return [
            'fixture_dataset' => [
                'type' => 'string',
                'required' => false,
                'enum' => [
                    'success',
                    'empty',
                    'invalid_payload',
                    'paginated_25',
                    'incremental',
                    'incremental_multipage',
                    'missing_external_id',
                    'invalid_schema',
                    'duplicate_ids',
                    'changed_second_run',
                ],
                'description' => 'Local fixture dataset name',
            ],
            'pagination_size' => [
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'max' => 100,
                'description' => 'Mock pagination page size',
            ],
            'simulated_error_scenario' => [
                'type' => 'string',
                'required' => false,
                'enum' => [
                    'none',
                    'unauthorized',
                    'rate_limit',
                    'timeout',
                    'invalid_payload',
                    'temporary_failure',
                    'empty',
                    'timeout_page_2',
                    'rate_limit_page_2',
                    'cursor_loop',
                ],
                'description' => 'Mock error/empty scenario for connection tests and sync engine',
            ],
            'incremental_dataset' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['incremental', 'incremental_multipage'],
                'description' => 'Mock incremental change-stream fixture name',
            ],
            'reporting_timezone' => [
                'type' => 'string',
                'required' => false,
                'description' => 'IANA timezone used for mock reporting windows',
            ],
            'mock_external_resource_id' => [
                'type' => 'string',
                'required' => false,
                'pattern' => '^[A-Za-z0-9._:-]{1,128}$',
                'description' => 'Mock external resource identifier (not a live credential)',
            ],
        ];
    }
}
