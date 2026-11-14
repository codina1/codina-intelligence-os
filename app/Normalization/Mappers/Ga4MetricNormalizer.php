<?php

namespace App\Normalization\Mappers;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedMetric;
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

    public function normalizeMetric(IngestionRecord $record, DataSource $source): NormalizedMetric
    {
        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $externalId = (string) $record->external_id;
        $metricKey = (string) $payload['metric'];
        $value = $payload['value'];
        $metricValue = is_numeric($value) ? (string) $value : null;

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
            'period_start' => null,
            'period_end' => null,
        ]);

        return new NormalizedMetric(
            provider: $this->provider(),
            metricKey: $metricKey,
            metricValue: $metricValue,
            unit: null,
            dimensions: $dimensions === [] ? null : $dimensions,
            observationIdentityHash: $identity,
            periodStart: null,
            periodEnd: null,
            observedAt: null,
            sourcePayloadHash: (string) $record->payload_hash,
            mapperVersion: $this->mapperVersion(),
        );
    }
}
