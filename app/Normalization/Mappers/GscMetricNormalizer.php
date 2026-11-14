<?php

namespace App\Normalization\Mappers;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedMetric;
use App\Normalization\Enums\NormalizationTarget;

class GscMetricNormalizer extends AbstractNormalizer
{
    public function provider(): string
    {
        return 'google_search_console';
    }

    public function entityType(): string
    {
        return 'search_analytics';
    }

    public function target(): NormalizationTarget
    {
        return NormalizationTarget::MetricObservation;
    }

    public function requiredFields(): array
    {
        return ['id', 'query', 'clicks'];
    }

    public function optionalFields(): array
    {
        return ['source', 'schema_version', 'impressions', 'ctr', 'position', 'date', 'page', 'country', 'device'];
    }

    public function normalizeMetric(IngestionRecord $record, DataSource $source): NormalizedMetric
    {
        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $externalId = (string) $record->external_id;
        $query = (string) $payload['query'];
        $clicks = $payload['clicks'];
        $metricValue = is_numeric($clicks) ? (string) $clicks : null;

        $dimensions = array_filter([
            'query' => $query,
            'page' => $this->optionalString($payload, 'page'),
            'country' => $this->optionalString($payload, 'country'),
            'device' => $this->optionalString($payload, 'device'),
        ], static fn ($value) => $value !== null && $value !== '');

        $identity = $this->hashIdentity([
            'provider' => $this->provider(),
            'entity_type' => $this->entityType(),
            'external_id' => $externalId,
            'metric_key' => 'clicks',
            'dimensions' => $dimensions,
            'period_start' => null,
            'period_end' => null,
        ]);

        return new NormalizedMetric(
            provider: $this->provider(),
            metricKey: 'clicks',
            metricValue: $metricValue,
            unit: 'count',
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
