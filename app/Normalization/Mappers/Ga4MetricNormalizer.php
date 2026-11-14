<?php

namespace App\Normalization\Mappers;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedMetric;
use App\Normalization\Enums\NormalizationErrorCode;
use App\Normalization\Enums\NormalizationTarget;

class Ga4MetricNormalizer extends AbstractNormalizer
{
    public function provider(): string
    {
        return 'google_analytics_4';
    }

    public function entityType(): string
    {
        return 'reports';
    }

    public function target(): NormalizationTarget
    {
        return NormalizationTarget::MetricObservation;
    }

    public function requiredFields(): array
    {
        return ['id', 'metric', 'value'];
    }

    public function optionalFields(): array
    {
        return ['source', 'schema_version', 'date', 'dimension', 'users', 'sessions', 'conversions'];
    }

    public function validate(IngestionRecord $record): array
    {
        $base = parent::validate($record);
        if (! $base['ok']) {
            return $base;
        }

        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $date = $this->parseOptionalDate($payload, 'date');
        if (! $date['ok']) {
            return $this->reject(NormalizationErrorCode::InvalidDate, ['field' => 'date']);
        }

        // Do not fabricate users/sessions/conversions when absent from fixtures.
        return $base;
    }

    public function normalizeMetric(IngestionRecord $record, DataSource $source): NormalizedMetric
    {
        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $externalId = (string) $record->external_id;
        $metricKey = (string) $payload['metric'];
        $value = $payload['value'];
        $metricValue = is_numeric($value) ? (string) $value : null;

        $date = $this->parseOptionalDate($payload, 'date');
        $periodStart = $date['value'];
        $periodEnd = $date['value'];

        $dimensions = array_filter([
            'metric_label' => $metricKey,
            'dimension' => $this->optionalString($payload, 'dimension'),
        ], static fn ($v) => $v !== null && $v !== '');

        $identity = $this->hashIdentity([
            'provider' => $this->provider(),
            'entity_type' => $this->entityType(),
            'external_id' => $externalId,
            'metric_key' => $metricKey,
            'dimensions' => $dimensions,
            'period_start' => $periodStart?->format('Y-m-d'),
            'period_end' => $periodEnd?->format('Y-m-d'),
        ]);

        return new NormalizedMetric(
            provider: $this->provider(),
            metricKey: $metricKey,
            metricValue: $metricValue,
            unit: null,
            dimensions: $dimensions === [] ? null : $dimensions,
            observationIdentityHash: $identity,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            observedAt: null,
            sourcePayloadHash: (string) $record->payload_hash,
            mapperVersion: $this->mapperVersion(),
        );
    }
}
