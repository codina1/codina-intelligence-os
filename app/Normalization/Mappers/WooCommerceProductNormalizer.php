<?php

namespace App\Normalization\Mappers;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedContent;
use App\Normalization\Enums\NormalizationTarget;

class WooCommerceProductNormalizer extends AbstractNormalizer
{
    public function provider(): string
    {
        return 'woocommerce';
    }

    public function entityType(): string
    {
        return 'products';
    }

    public function target(): NormalizationTarget
    {
        return NormalizationTarget::ContentEntity;
    }

    public function requiredFields(): array
    {
        return ['id', 'name', 'status'];
    }

    public function optionalFields(): array
    {
        return ['source', 'schema_version', 'slug', 'permalink', 'price', 'stock_quantity'];
    }

    public function normalizeContent(IngestionRecord $record, DataSource $source): NormalizedContent
    {
        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $externalId = (string) $record->external_id;

        $normalized = [
            'content_type' => 'product',
            'external_id' => $externalId,
            'title' => (string) $payload['name'],
            'publication_status' => (string) $payload['status'],
            'slug' => $this->optionalString($payload, 'slug'),
            'canonical_url' => $this->optionalString($payload, 'permalink'),
            'source' => $this->optionalString($payload, 'source') ?? 'woocommerce',
            'schema_version' => $this->optionalString($payload, 'schema_version'),
        ];

        return new NormalizedContent(
            entityType: $this->entityType(),
            externalId: $externalId,
            externalIdHash: $this->externalIdHash($externalId),
            contentType: 'product',
            title: $normalized['title'],
            slug: $normalized['slug'],
            canonicalUrl: $normalized['canonical_url'],
            publicationStatus: $normalized['publication_status'],
            normalizedStatus: 'active',
            payload: $normalized,
            payloadHash: $this->hashPayload($normalized),
            mapperVersion: $this->mapperVersion(),
            sourceUpdatedAt: null,
        );
    }
}
