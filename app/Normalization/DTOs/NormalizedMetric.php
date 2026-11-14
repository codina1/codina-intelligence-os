<?php

namespace App\Normalization\DTOs;

final class NormalizedMetric
{
    /**
     * @param  array<string, mixed>|null  $dimensions
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $metricKey,
        public readonly ?string $metricValue,
        public readonly ?string $unit,
        public readonly ?array $dimensions,
        public readonly string $observationIdentityHash,
        public readonly ?\DateTimeInterface $periodStart,
        public readonly ?\DateTimeInterface $periodEnd,
        public readonly ?\DateTimeInterface $observedAt,
        public readonly string $sourcePayloadHash,
        public readonly string $mapperVersion,
    ) {}
}
