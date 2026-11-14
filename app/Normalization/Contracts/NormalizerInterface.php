<?php

namespace App\Normalization\Contracts;

use App\Models\DataSource;
use App\Models\IngestionRecord;
use App\Normalization\DTOs\NormalizedContent;
use App\Normalization\DTOs\NormalizedMetric;
use App\Normalization\Enums\NormalizationTarget;

interface NormalizerInterface
{
    public function provider(): string;

    public function entityType(): string;

    public function target(): NormalizationTarget;

    public function mapperVersion(): string;

    /**
     * @return list<string>
     */
    public function requiredFields(): array;

    /**
     * @return list<string>
     */
    public function optionalFields(): array;

    /**
     * @return array{ok: bool, error_code: ?string, diagnostics: array<string, mixed>}
     */
    public function validate(IngestionRecord $record): array;

    public function normalizeContent(IngestionRecord $record, DataSource $source): NormalizedContent;

    public function normalizeMetric(IngestionRecord $record, DataSource $source): NormalizedMetric;
}
