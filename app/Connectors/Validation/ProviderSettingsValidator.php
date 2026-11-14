<?php

namespace App\Connectors\Validation;

use App\Connectors\Registry\ProviderRegistry;
use Illuminate\Validation\ValidationException;

class ProviderSettingsValidator
{
    /** @var list<string> */
    private const FORBIDDEN_KEY_FRAGMENTS = [
        'url',
        'token',
        'password',
        'secret',
        'api_key',
        'apikey',
        'credential',
        'oauth',
        'authorization',
        'bearer',
        'private_key',
        'access_key',
        'refresh',
        'client_secret',
    ];

    public const EXTERNAL_RESOURCE_PATTERN = '^[A-Za-z0-9._:-]{1,128}$';

    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * Resolve a single canonical mock-safe external resource identifier.
     *
     * Prefer settings.mock_external_resource_id. Top-level external_resource_id is
     * accepted only when settings omit it, or when both match after validation.
     *
     * @param  array<string, mixed>  $settings
     * @return array{0: array<string, mixed>, 1: ?string} [validated settings, canonical id]
     */
    public function validateWithExternalResourceId(
        string $providerKey,
        array $settings,
        mixed $topLevelExternalResourceId = null,
    ): array {
        $settingsHasId = array_key_exists('mock_external_resource_id', $settings)
            && $settings['mock_external_resource_id'] !== null
            && $settings['mock_external_resource_id'] !== '';
        $topProvided = $topLevelExternalResourceId !== null && $topLevelExternalResourceId !== '';

        if ($topProvided && ! is_string($topLevelExternalResourceId)) {
            throw ValidationException::withMessages([
                'external_resource_id' => 'Must be a mock-safe string identifier.',
            ]);
        }

        if ($settingsHasId && $topProvided) {
            $fromSettings = $this->validateExternalResourceId((string) $settings['mock_external_resource_id'], 'settings.mock_external_resource_id');
            $fromTop = $this->validateExternalResourceId((string) $topLevelExternalResourceId, 'external_resource_id');
            if ($fromSettings !== $fromTop) {
                throw ValidationException::withMessages([
                    'external_resource_id' => 'Conflicts with settings.mock_external_resource_id.',
                    'settings.mock_external_resource_id' => 'Conflicts with external_resource_id.',
                ]);
            }
            $settings['mock_external_resource_id'] = $fromSettings;
        } elseif ($topProvided && ! $settingsHasId) {
            $settings['mock_external_resource_id'] = $this->validateExternalResourceId(
                (string) $topLevelExternalResourceId,
                'external_resource_id'
            );
        }

        $validated = $this->validate($providerKey, $settings);
        $canonical = $validated['mock_external_resource_id'] ?? null;

        return [$validated, is_string($canonical) ? $canonical : null];
    }

    public function validateExternalResourceId(string $value, string $field = 'external_resource_id'): string
    {
        if ($this->looksLikeSecretOrUrl($value)) {
            throw ValidationException::withMessages([
                $field => 'Value looks like a URL or credential and is rejected in mock mode.',
            ]);
        }

        if (preg_match('/'.self::EXTERNAL_RESOURCE_PATTERN.'/', $value) !== 1) {
            throw ValidationException::withMessages([
                $field => 'Must match mock-safe pattern [A-Za-z0-9._:-]{1,128}.',
            ]);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function validate(string $providerKey, array $settings): array
    {
        $definition = $this->providers->get($providerKey);
        $schema = $definition->settingsSchema;
        $allowed = array_keys($schema);
        $errors = [];

        foreach (array_keys($settings) as $key) {
            $normalized = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
                if (str_contains($normalized, $fragment)) {
                    $errors[$key][] = 'Setting key is not allowed for mock configuration.';
                }
            }

            if (! in_array((string) $key, $allowed, true)) {
                $errors[$key][] = 'Unknown settings key. Only allowlisted mock settings are accepted.';
            }
        }

        $clean = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }

            $value = $settings[$key];
            $rule = $schema[$key];

            if ($value === null) {
                continue;
            }

            if (is_string($value) && $this->looksLikeSecretOrUrl($value)) {
                $errors[$key][] = 'Value looks like a URL or credential and is rejected in mock mode.';

                continue;
            }

            if (($rule['type'] ?? null) === 'integer') {
                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    $errors[$key][] = 'Must be an integer.';

                    continue;
                }
                $intVal = (int) $value;
                if (isset($rule['min']) && $intVal < (int) $rule['min']) {
                    $errors[$key][] = 'Below minimum.';

                    continue;
                }
                if (isset($rule['max']) && $intVal > (int) $rule['max']) {
                    $errors[$key][] = 'Above maximum.';

                    continue;
                }
                $clean[$key] = $intVal;

                continue;
            }

            if (! is_string($value)) {
                $errors[$key][] = 'Must be a string.';

                continue;
            }

            if (isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) {
                $errors[$key][] = 'Value is not in the allowed enum.';

                continue;
            }

            if (isset($rule['pattern']) && ! preg_match('/'.$rule['pattern'].'/', $value)) {
                $errors[$key][] = 'Value does not match the allowed pattern.';

                continue;
            }

            if ($key === 'reporting_timezone' && ! $this->isValidTimezone($value)) {
                $errors[$key][] = 'Invalid IANA timezone.';

                continue;
            }

            $clean[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean + [
            'fixture_dataset' => $clean['fixture_dataset'] ?? 'success',
            'pagination_size' => $clean['pagination_size'] ?? 10,
            'simulated_error_scenario' => $clean['simulated_error_scenario'] ?? 'none',
            'reporting_timezone' => $clean['reporting_timezone'] ?? 'UTC',
        ];
    }

    private function looksLikeSecretOrUrl(string $value): bool
    {
        if (preg_match('#^(https?|ftp|mysql|redis|amqp)://#i', $value) === 1) {
            return true;
        }

        if (preg_match('/^(Bearer|Basic)\s+\S+/i', $value) === 1) {
            return true;
        }

        // Long opaque tokens are rejected in mock settings.
        if (preg_match('/^[A-Za-z0-9+\/_-]{40,}$/', $value) === 1) {
            return true;
        }

        return false;
    }

    private function isValidTimezone(string $value): bool
    {
        return in_array($value, timezone_identifiers_list(), true);
    }
}
