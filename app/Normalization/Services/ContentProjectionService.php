<?php

namespace App\Normalization\Services;

use App\Models\ContentEntity;
use App\Models\ContentSourceLink;
use App\Models\ContentVersion;
use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedContent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ContentProjectionService
{
    /**
     * @return array{outcome: string, entity: ContentEntity}
     */
    public function project(
        DataSource $source,
        IngestionRecord $record,
        NormalizedContent $normalized,
    ): array {
        return DB::transaction(function () use ($source, $record, $normalized) {
            $now = CarbonImmutable::now();

            $entity = ContentEntity::query()
                ->where('data_source_id', $source->id)
                ->where('entity_type', $normalized->entityType)
                ->where('external_id_hash', $normalized->externalIdHash)
                ->lockForUpdate()
                ->first();

            if ($entity !== null && $entity->external_id !== $normalized->externalId) {
                throw new RuntimeException('external_id_mismatch');
            }

            if ($entity === null) {
                try {
                    $entity = ContentEntity::query()->create([
                        'public_id' => (string) Str::uuid(),
                        'tenant_id' => $source->tenant_id,
                        'site_id' => $source->site_id,
                        'data_source_id' => $source->id,
                        'entity_type' => $normalized->entityType,
                        'external_id' => $normalized->externalId,
                        'external_id_hash' => $normalized->externalIdHash,
                        'content_type' => $normalized->contentType,
                        'title' => $normalized->title,
                        'slug' => $normalized->slug,
                        'canonical_url' => $normalized->canonicalUrl,
                        'publication_status' => $normalized->publicationStatus,
                        'normalized_status' => $normalized->normalizedStatus,
                        'current_version_number' => 1,
                        'normalized_payload_hash' => $normalized->payloadHash,
                        'source_updated_at' => $normalized->sourceUpdatedAt,
                        'first_seen_at' => $record->first_seen_at ?? $now,
                        'last_seen_at' => $record->last_seen_at ?? $now,
                        'normalized_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Concurrent first-create: reload under lock and continue as update/unchanged.
                    $entity = ContentEntity::query()
                        ->where('data_source_id', $source->id)
                        ->where('entity_type', $normalized->entityType)
                        ->where('external_id_hash', $normalized->externalIdHash)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $hasVersion = ContentVersion::query()
                    ->where('content_entity_id', $entity->id)
                    ->exists();

                if (! $hasVersion) {
                    ContentVersion::query()->create([
                        'tenant_id' => $source->tenant_id,
                        'content_entity_id' => $entity->id,
                        'version_number' => 1,
                        'normalized_payload_hash' => $normalized->payloadHash,
                        'normalized_payload_json' => $normalized->payload,
                        'source_ingestion_record_id' => $record->id,
                        'mapper_version' => $normalized->mapperVersion,
                        'created_at' => $now,
                    ]);

                    $entity->fill([
                        'current_version_number' => 1,
                        'normalized_payload_hash' => $normalized->payloadHash,
                        'title' => $normalized->title,
                        'slug' => $normalized->slug,
                        'canonical_url' => $normalized->canonicalUrl,
                        'publication_status' => $normalized->publicationStatus,
                        'normalized_status' => $normalized->normalizedStatus,
                        'normalized_at' => $now,
                        'last_seen_at' => $record->last_seen_at ?? $now,
                    ])->save();

                    $this->upsertProvenance($source, $entity, $record);

                    return ['outcome' => 'created', 'entity' => $entity->fresh()];
                }

                // Fell through from concurrent create — treat as existing entity below.
            }

            $entity->last_seen_at = $record->last_seen_at ?? $now;
            $entity->normalized_at = $now;

            if ($entity->normalized_payload_hash === $normalized->payloadHash) {
                $entity->save();
                $this->upsertProvenance($source, $entity, $record);

                return ['outcome' => 'unchanged', 'entity' => $entity->fresh()];
            }

            $nextVersion = ((int) $entity->current_version_number) + 1;

            $entity->fill([
                'content_type' => $normalized->contentType,
                'title' => $normalized->title,
                'slug' => $normalized->slug,
                'canonical_url' => $normalized->canonicalUrl,
                'publication_status' => $normalized->publicationStatus,
                'normalized_status' => $normalized->normalizedStatus,
                'current_version_number' => $nextVersion,
                'normalized_payload_hash' => $normalized->payloadHash,
                'source_updated_at' => $normalized->sourceUpdatedAt,
            ]);
            $entity->save();

            ContentVersion::query()->create([
                'tenant_id' => $source->tenant_id,
                'content_entity_id' => $entity->id,
                'version_number' => $nextVersion,
                'normalized_payload_hash' => $normalized->payloadHash,
                'normalized_payload_json' => $normalized->payload,
                'source_ingestion_record_id' => $record->id,
                'mapper_version' => $normalized->mapperVersion,
                'created_at' => $now,
            ]);

            $this->upsertProvenance($source, $entity, $record);

            return ['outcome' => 'updated', 'entity' => $entity->fresh()];
        });
    }

    private function upsertProvenance(DataSource $source, ContentEntity $entity, IngestionRecord $record): void
    {
        $link = ContentSourceLink::query()
            ->where('content_entity_id', $entity->id)
            ->where('data_source_id', $source->id)
            ->where('relation_type', 'primary')
            ->lockForUpdate()
            ->first();

        if ($link === null) {
            ContentSourceLink::query()->create([
                'tenant_id' => $source->tenant_id,
                'content_entity_id' => $entity->id,
                'data_source_id' => $source->id,
                'ingestion_record_id' => $record->id,
                'relation_type' => 'primary',
            ]);

            return;
        }

        $link->ingestion_record_id = $record->id;
        $link->save();
    }
}
