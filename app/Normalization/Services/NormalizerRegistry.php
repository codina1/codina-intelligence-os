<?php

namespace App\Normalization\Services;

use App\Normalization\Contracts\NormalizerInterface;
use App\Normalization\Mappers\Ga4MetricNormalizer;
use App\Normalization\Mappers\GscMetricNormalizer;
use App\Normalization\Mappers\TutorCourseNormalizer;
use App\Normalization\Mappers\WooCommerceProductNormalizer;
use App\Normalization\Mappers\WordPressPostNormalizer;

class NormalizerRegistry
{
    /** @var array<string, NormalizerInterface> */
    private array $normalizers = [];

    public function __construct()
    {
        foreach ([
            new WordPressPostNormalizer,
            new WooCommerceProductNormalizer,
            new TutorCourseNormalizer,
            new GscMetricNormalizer,
            new Ga4MetricNormalizer,
        ] as $normalizer) {
            $this->register($normalizer);
        }
    }

    public function register(NormalizerInterface $normalizer): void
    {
        $this->normalizers[$this->key($normalizer->provider(), $normalizer->entityType())] = $normalizer;
    }

    public function resolve(string $provider, string $entityType): ?NormalizerInterface
    {
        return $this->normalizers[$this->key($provider, $entityType)] ?? null;
    }

    public function supports(string $provider, string $entityType): bool
    {
        return $this->resolve($provider, $entityType) !== null;
    }

    /**
     * @return list<NormalizerInterface>
     */
    public function all(): array
    {
        return array_values($this->normalizers);
    }

    private function key(string $provider, string $entityType): string
    {
        return $provider.'|'.$entityType;
    }
}
