<?php

namespace App\Normalization\Mappers;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\Contracts\NormalizerInterface;
use App\Normalization\DTOs\NormalizedContent;
use App\Normalization\DTOs\NormalizedMetric;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationTarget;
use DomainException;

abstract class AbstractNormalizer implements NormalizerInterface
{
    public function mapperVersion(): string
    {
        return '1.0.0';
    }

    public function validate(IngestionRecord $record): array
    {
        if ($record->entity_type !== $this->entityType()) {
            return $this->reject(NormalizationErrorCode::UnsupportedEntityType, [
                'expected' => $this->entityType(),
                'actual' => $record->entity_type,
            ]);
        }

        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        if ($payload === []) {
            return $this->reject(NormalizationErrorCode::InvalidPayload, ['field' => 'payload_json']);
        }

        foreach ($this->requiredFields() as $field) {
            if (! array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                return $this->reject(NormalizationErrorCode::MissingRequiredField, ['field' => $field]);
            }
        }

        return [
            'ok' => true,
            'error_code' => null,
            'diagnostics' => [],
        ];
    }

    public function normalizeContent(IngestionRecord $record, DataSource $source): NormalizedContent
    {
        throw new DomainException('Normalizer does not produce content entities.');
    }

    public function normalizeMetric(IngestionRecord $record, DataSource $source): NormalizedMetric
    {
        throw new DomainException('Normalizer does not produce metric observations.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function hashPayload(array $payload): string
    {
        $normalized = $payload;
        ksort($normalized);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    protected function hashIdentity(array $parts): string
    {
        ksort($parts);

        return hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function externalIdHash(string $externalId): string
    {
        return hash('sha256', $externalId);
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array{ok: bool, error_code: string, diagnostics: array<string, mixed>}
     */
    protected function reject(NormalizationErrorCode $code, array $diagnostics = []): array
    {
        return [
            'ok' => false,
            'error_code' => $code->value,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function optionalString(array $payload, string $key): ?string
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
            return null;
        }

        return is_scalar($payload[$key]) ? (string) $payload[$key] : null;
    }
}
