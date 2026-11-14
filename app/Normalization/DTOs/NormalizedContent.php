<?php

namespace App\Normalization\DTOs;

final class NormalizedContent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $entityType,
        public readonly string $externalId,
        public readonly string $externalIdHash,
        public readonly string $contentType,
        public readonly ?string $title,
        public readonly ?string $slug,
        public readonly ?string $canonicalUrl,
        public readonly ?string $publicationStatus,
        public readonly string $normalizedStatus,
        public readonly array $payload,
        public readonly string $payloadHash,
        public readonly string $mapperVersion,
        public readonly ?\DateTimeInterface $sourceUpdatedAt,
    ) {}
}
