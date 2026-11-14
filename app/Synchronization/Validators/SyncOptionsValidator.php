<?php

namespace App\Synchronization\Validators;

use Illuminate\Validation\ValidationException;

/**
 * Strict allowlist for public sync options (Phase 02B remediation).
 */
class SyncOptionsValidator
{
    /** @var list<string> */
    public const ALLOWED_KEYS = [
        'scenario',
        'per_page',
        'error_scenario',
        'incremental_dataset',
        'simulated',
    ];

    /** @var list<string> */
    public const FIXTURE_DATASETS = [
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
    ];

    /** @var list<string> */
    public const ERROR_SCENARIOS = [
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
    ];

    /** @var list<string> */
    public const INCREMENTAL_DATASETS = [
        'incremental',
        'incremental_multipage',
    ];

    /** @var list<string> */
    public const FORBIDDEN_KEYS = [
        'lease_owner',
        'generation',
        'tenant_id',
        'fencing_token',
        'watermark_since',
        'cursor',
        'path',
        'fixture_path',
    ];

    /**
     * @param  array<string, mixed>  $options
     * @return array{scenario: string, per_page: int, error_scenario: string, incremental_dataset: string, simulated: bool}
     */
    public function validatePublicOptions(array $options, array $defaults = []): array
    {
        $errors = [];

        foreach (array_keys($options) as $key) {
            $key = (string) $key;
            if (in_array($key, self::FORBIDDEN_KEYS, true) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                $errors["options.{$key}"][] = 'Unknown or forbidden sync option.';
            }
        }

        $scenario = $options['scenario'] ?? $defaults['scenario'] ?? 'success';
        $errorScenario = $options['error_scenario'] ?? $defaults['error_scenario'] ?? 'none';
        $incrementalDataset = $options['incremental_dataset'] ?? $defaults['incremental_dataset'] ?? 'incremental';
        $perPage = $options['per_page'] ?? $defaults['per_page'] ?? config('sync.default_page_size', 10);
        $simulated = $options['simulated'] ?? $defaults['simulated'] ?? true;

        if (! is_string($scenario) || ! $this->isSafeFixtureName($scenario) || ! in_array($scenario, self::FIXTURE_DATASETS, true)) {
            $errors['options.scenario'][] = 'Must be a known local fixture identifier.';
        }

        if (! is_string($errorScenario) || ! in_array($errorScenario, self::ERROR_SCENARIOS, true)) {
            $errors['options.error_scenario'][] = 'Invalid error scenario.';
        }

        if (! is_string($incrementalDataset) || ! $this->isSafeFixtureName($incrementalDataset)
            || ! in_array($incrementalDataset, self::INCREMENTAL_DATASETS, true)) {
            $errors['options.incremental_dataset'][] = 'Must be a known local incremental fixture identifier.';
        }

        if (! is_int($perPage) && ! (is_string($perPage) && ctype_digit($perPage))) {
            $errors['options.per_page'][] = 'Must be an integer.';
        } else {
            $perPage = (int) $perPage;
            $max = (int) config('sync.max_records_per_page', 100);
            if ($perPage < 1 || $perPage > $max) {
                $errors['options.per_page'][] = "Must be between 1 and {$max}.";
            }
        }

        if (! is_bool($simulated) && ! in_array($simulated, [0, 1, '0', '1', true, false], true)) {
            $errors['options.simulated'][] = 'Must be boolean.';
        } else {
            $simulated = filter_var($simulated, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
            if ($simulated !== true) {
                $errors['options.simulated'][] = 'Only simulated=true is allowed in Phase 02B.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'scenario' => (string) $scenario,
            'per_page' => (int) $perPage,
            'error_scenario' => (string) $errorScenario,
            'incremental_dataset' => (string) $incrementalDataset,
            'simulated' => true,
        ];
    }

    /**
     * Resolve a sync fixture path under database/fixtures/sync/{provider}/ only.
     */
    public function resolveFixturePath(string $provider, string $dataset): string
    {
        if (! $this->isSafeFixtureName($provider) || ! $this->isSafeFixtureName($dataset)) {
            throw ValidationException::withMessages([
                'options.scenario' => 'Unsafe fixture path rejected.',
            ]);
        }

        $base = realpath(database_path('fixtures/sync'));
        if ($base === false) {
            throw new \RuntimeException('Sync fixtures directory missing.');
        }

        $candidate = database_path('fixtures/sync/'.$provider.'/'.$dataset.'.json');
        $resolved = realpath($candidate);

        if ($resolved === false || ! str_starts_with($resolved, $base.DIRECTORY_SEPARATOR)) {
            throw ValidationException::withMessages([
                'options.scenario' => 'Fixture path traversal rejected.',
            ]);
        }

        return $resolved;
    }

    public function isSafeFixtureName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name) === 1
            && ! str_contains($name, '..')
            && ! str_contains($name, '/')
            && ! str_contains($name, '\\');
    }
}
