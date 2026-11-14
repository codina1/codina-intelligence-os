<?php

namespace App\Normalization\Mappers;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedContent;
use App\Normalization\Enums\NormalizationTarget;

class TutorCourseNormalizer extends AbstractNormalizer
{
    public function provider(): string
    {
        return 'tutor_lms_pro';
    }

    public function entityType(): string
    {
        return 'courses';
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
        return ['source', 'schema_version', 'slug', 'permalink', 'enrollments'];
    }

    public function normalizeContent(IngestionRecord $record, DataSource $source): NormalizedContent
    {
        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $externalId = (string) $record->external_id;

        $normalized = [
            'content_type' => 'course',
            'external_id' => $externalId,
            'title' => (string) $payload['title'],
            'publication_status' => (string) $payload['status'],
            'slug' => $this->optionalString($payload, 'slug'),
            'canonical_url' => $this->optionalString($payload, 'permalink'),
            'source' => $this->optionalString($payload, 'source') ?? 'tutor_lms_pro',
            'schema_version' => $this->optionalString($payload, 'schema_version'),
        ];

        return new NormalizedContent(
            entityType: $this->entityType(),
            externalId: $externalId,
            externalIdHash: $this->externalIdHash($externalId),
            contentType: 'course',
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
