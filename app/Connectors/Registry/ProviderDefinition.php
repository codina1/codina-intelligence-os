<?php

namespace App\Connectors\Registry;

final class ProviderDefinition
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<string>  $authMethods
     * @param  array<string, array<string, mixed>>  $settingsSchema
     * @param  list<string>  $fixtureDatasets
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $capabilities,
        public readonly array $authMethods,
        public readonly array $settingsSchema,
        public readonly array $fixtureDatasets,
        public readonly bool $mockOnly = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'mode' => 'mock',
            'mock_only' => $this->mockOnly,
            'capabilities' => $this->capabilities,
            'auth_methods' => $this->authMethods,
            'settings_schema' => $this->settingsSchema,
            'fixture_datasets' => $this->fixtureDatasets,
            'connection_claim' => 'Mock fixtures only — does not verify real provider connectivity.',
        ];
    }
}
