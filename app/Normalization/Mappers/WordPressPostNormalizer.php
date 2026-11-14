<?php

namespace App\Normalization\Mappers;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedContent;
use App\Normalization\Enums\NormalizationTarget;

class WordPressPostNormalizer extends AbstractNormalizer
{
    public function provider(): string
    {
        return 'wordpress';
    }

    public function entityType(): string
    {
        return 'posts';
    }

    public function target(): NormalizationTarget
    {
        return NormalizationTarget::ContentEntity;
    }

    public function requiredFields(): array
    {
        return ['id', 'title', 'status'];
    }

    public function optionalFields(): array
    {
        return ['source', 'schema_version', 'slug', 'url', 'type', 'date', 'modified', 'author'];
    }

    public function normalizeContent(IngestionRecord $record, DataSource $source): NormalizedContent
    {
        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $externalId = (string) $record->external_id;
        $contentType = 'post';
        $typeField = $this->optionalString($payload, 'type');
        if ($typeField === 'page') {
            $contentType = 'page';
        }

        $normalized = [
            'content_type' => $contentType,
            'external_id' => $externalId,
            'title' => (string) $payload['title'],
            'publication_status' => (string) $payload['status'],
            'slug' => $this->optionalString($payload, 'slug'),
            'canonical_url' => $this->optionalString($payload, 'url'),
            'source' => $this->optionalString($payload, 'source') ?? 'wordpress',
            'schema_version' => $this->optionalString($payload, 'schema_version'),
        ];

        return new NormalizedContent(
            entityType: $this->entityType(),
            externalId: $externalId,
            externalIdHash: $this->externalIdHash($externalId),
            contentType: $contentType,
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
