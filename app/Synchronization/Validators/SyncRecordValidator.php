<?php

namespace App\Synchronization\Validators;

use App\Synchronization\Enums\SyncErrorCode;

class SyncRecordValidator
{
    /** @var list<string> */
    private array $credentialLikeKeys = [
        'password', 'token', 'secret', 'api_key', 'api_token', 'authorization',
        'client_secret', 'refresh_token', 'access_token', 'credentials',
    ];

    /** @var list<string> */
    private array $personalDataKeys = [
        'email', 'ssn', 'phone', 'address', 'date_of_birth', 'credit_card',
    ];

    /**
     * @param  array<string, mixed>  $record
     * @return array{ok: bool, external_id: ?string, error_code: ?string, diagnostics: array<string, mixed>}
     */
    public function validate(string $provider, string $entityType, array $record, string $schemaVersion): array
    {
        if (! $this->supportsEntity($provider, $entityType)) {
            return $this->reject(SyncErrorCode::UnsupportedEntityType, ['entity_type' => $entityType]);
        }

        if ($schemaVersion === '' || ! preg_match('/^\d+\.\d+$/', $schemaVersion)) {
            return $this->reject(SyncErrorCode::InvalidSchema, ['field' => 'schema_version']);
        }

        $encoded = json_encode($record);
        if ($encoded === false) {
            return $this->reject(SyncErrorCode::InvalidSchema, ['field' => 'payload_json']);
        }

        $maxBytes = (int) config('sync.max_payload_bytes', 65536);
        if (strlen($encoded) > $maxBytes) {
            return $this->reject(SyncErrorCode::PayloadTooLarge, ['max_bytes' => $maxBytes]);
        }

        foreach ($this->flattenKeys($record) as $key) {
            $lower = strtolower($key);
            if (in_array($lower, $this->credentialLikeKeys, true)) {
                return $this->reject(SyncErrorCode::CredentialLikeField, ['field' => $key]);
            }
            if (in_array($lower, $this->personalDataKeys, true)) {
                return $this->reject(SyncErrorCode::UnexpectedPersonalData, ['field' => $key]);
            }
        }

        $externalId = $this->extractExternalId($record);
        if ($externalId === null || $externalId === '') {
            return $this->reject(SyncErrorCode::MissingExternalId, ['field' => 'id']);
        }

        if (! $this->hasAllowedTypes($record)) {
            return $this->reject(SyncErrorCode::InvalidSchema, ['field' => 'types']);
        }

        if (array_key_exists('schema_version', $record) && (string) $record['schema_version'] !== $schemaVersion) {
            return $this->reject(SyncErrorCode::InvalidSchema, ['field' => 'schema_version']);
        }

        return [
            'ok' => true,
            'external_id' => $externalId,
            'error_code' => null,
            'diagnostics' => [],
        ];
    }

    public function supportsEntity(string $provider, string $entityType): bool
    {
        $map = [
            'wordpress' => ['posts'],
            'woocommerce' => ['products'],
            'google_search_console' => ['search_analytics'],
            'google_analytics_4' => ['reports'],
            'tutor_lms_pro' => ['courses'],
        ];

        return in_array($entityType, $map[$provider] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function extractExternalId(array $record): ?string
    {
        foreach (['id', 'external_id', 'uuid'] as $key) {
            if (! array_key_exists($key, $record)) {
                continue;
            }
            $value = $record[$key];
            if (is_int($value) || is_string($value)) {
                $asString = trim((string) $value);

                return $asString === '' ? null : $asString;
            }
        }

        return null;
    }

    public function externalIdHash(string $externalId): string
    {
        return hash('sha256', $externalId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHash(array $payload): string
    {
        $normalized = $payload;
        $this->ksortRecursive($normalized);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function flattenKeys(array $record, string $prefix = ''): array
    {
        $keys = [];
        foreach ($record as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys[] = (string) $key;
            $keys[] = $path;
            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value, $path));
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function hasAllowedTypes(array $record): bool
    {
        foreach ($record as $value) {
            if (is_resource($value) || is_object($value)) {
                return false;
            }
            if (is_array($value) && ! $this->hasAllowedTypes($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array{ok: bool, external_id: null, error_code: string, diagnostics: array<string, mixed>}
     */
    private function reject(SyncErrorCode $code, array $diagnostics): array
    {
        return [
            'ok' => false,
            'external_id' => null,
            'error_code' => $code->value,
            'diagnostics' => $diagnostics,
        ];
    }
}
