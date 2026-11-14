<?php

namespace App\Synchronization\Services;

use App\Connectors\Registry\ConnectorRegistry;
use App\Models\DataSource;
use App\Synchronization\DTOs\SyncCursor;
use App\Synchronization\DTOs\SyncPage;
use App\Synchronization\Enums\SyncErrorCode;
use App\Synchronization\Enums\SyncType;
use App\Synchronization\Validators\SyncOptionsValidator;
use RuntimeException;

/**
 * Adapts page/per_page mock connector fetch() into a cursor DTO contract.
 */
class SyncPageAdapter
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly SyncOptionsValidator $optionsValidator,
    ) {}

    public function fetchPage(
        DataSource $source,
        string $entityType,
        SyncType $syncType,
        SyncCursor $cursor,
        array $options = [],
    ): SyncPage {
        if ($source->mode->value !== 'mock') {
            return new SyncPage(
                records: [],
                cursorBefore: $cursor->toArray(),
                cursorAfter: null,
                hasMore: false,
                sourceMode: $source->mode->value,
                errorCode: SyncErrorCode::ForbiddenMode->value,
                errorMessage: 'Non-mock data sources are refused by the Phase 02B sync engine.',
            );
        }

        if (config('codina.allow_external_http') === true) {
            throw new RuntimeException('External HTTP is forbidden for sync engine.');
        }

        $settings = $source->settings ?? [];
        $page = $cursor->page();
        $perPage = max(1, min(
            (int) config('sync.max_records_per_page', 100),
            (int) ($options['per_page'] ?? $settings['pagination_size'] ?? config('sync.default_page_size', 10))
        ));

        $scenario = (string) ($options['scenario'] ?? $settings['fixture_dataset'] ?? 'success');
        $errorScenario = (string) ($options['error_scenario'] ?? $settings['simulated_error_scenario'] ?? 'none');

        if (! $this->optionsValidator->isSafeFixtureName($scenario)) {
            return new SyncPage([], $cursor->toArray(), null, false, 'mock', SyncErrorCode::InvalidConfiguration->value, 'Unsafe fixture scenario.');
        }

        if ($errorScenario === 'timeout_page_2' && $page >= 2) {
            return new SyncPage([], $cursor->toArray(), null, false, 'mock', SyncErrorCode::Timeout->value, 'Mock timeout on page 2.', 2);
        }
        if ($errorScenario === 'rate_limit_page_2' && $page >= 2) {
            return new SyncPage([], $cursor->toArray(), null, false, 'mock', SyncErrorCode::RateLimit->value, 'Mock rate limit on page 2.', 3);
        }
        if ($errorScenario === 'cursor_loop') {
            return new SyncPage(
                records: $this->loadSyncFixture($source->provider, 'paginated_25', $page, $perPage)['slice'],
                cursorBefore: ['page' => $page],
                cursorAfter: ['page' => $page],
                hasMore: true,
                sourceMode: 'mock',
            );
        }

        if ($syncType === SyncType::Incremental) {
            return $this->fetchIncremental($source, $cursor, $perPage, $options, $settings);
        }

        return $this->fetchFull($source, $scenario, $cursor, $perPage, $page);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $settings
     */
    private function fetchIncremental(
        DataSource $source,
        SyncCursor $cursor,
        int $perPage,
        array $options,
        array $settings,
    ): SyncPage {
        $dataset = (string) ($options['incremental_dataset'] ?? $settings['incremental_dataset'] ?? 'incremental');
        if (! $this->optionsValidator->isSafeFixtureName($dataset)) {
            return new SyncPage([], $cursor->toArray(), null, false, 'mock', SyncErrorCode::InvalidConfiguration->value, 'Unsafe incremental dataset.');
        }

        try {
            $path = $this->optionsValidator->resolveFixturePath($source->provider, $dataset);
        } catch (\Throwable) {
            return new SyncPage([], $cursor->toArray(), null, false, 'mock');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['changes']) || ! is_array($decoded['changes'])) {
            return new SyncPage(
                [],
                $cursor->toArray(),
                null,
                false,
                'mock',
                SyncErrorCode::MalformedPagination->value,
                'Incremental fixture missing changes array.',
            );
        }

        // Watermark filter is separate from pagination cursor.
        $since = $options['watermark_since'] ?? null;
        if ($since === null) {
            $since = $decoded['initial_watermark'] ?? null;
        }

        $changes = array_values(array_filter($decoded['changes'], function ($change) use ($since) {
            if (! is_array($change)) {
                return false;
            }
            $changedAt = (string) ($change['changed_at'] ?? '');

            return $since === null || $changedAt > (string) $since;
        }));

        $page = $cursor->page();
        $slice = array_slice($changes, ($page - 1) * $perPage, $perPage);
        $records = array_map(fn ($c) => is_array($c['record'] ?? null) ? $c['record'] : $c, $slice);
        $total = count($changes);
        $hasMore = ($page * $perPage) < $total;

        // Pagination cursor only — never embed watermark in page cursor.
        $cursorAfter = $hasMore ? ['page' => $page + 1] : ['page' => $page];
        $highWater = null;
        if (! $hasMore) {
            $highWater = isset($decoded['final_watermark'])
                ? (string) $decoded['final_watermark']
                : (string) ($slice[array_key_last($slice)]['changed_at'] ?? $since);
        }

        return new SyncPage(
            records: array_values($records),
            cursorBefore: ['page' => $page],
            cursorAfter: $cursorAfter,
            hasMore: $hasMore,
            sourceMode: 'mock',
            streamHighWater: $highWater,
        );
    }

    private function fetchFull(
        DataSource $source,
        string $scenario,
        SyncCursor $cursor,
        int $perPage,
        int $page,
    ): SyncPage {
        $syncFixture = $this->maybeLoadSyncFixture($source->provider, $scenario, $page, $perPage);
        if ($syncFixture !== null) {
            $hasMore = $syncFixture['has_more'];
            $cursorAfter = $hasMore ? ['page' => $page + 1] : ['page' => $page];

            return new SyncPage(
                records: $syncFixture['slice'],
                cursorBefore: ['page' => $page],
                cursorAfter: $cursorAfter,
                hasMore: $hasMore,
                sourceMode: 'mock',
            );
        }

        $connector = $this->connectors->get($source->provider);
        $raw = $connector->fetch([
            'scenario' => $scenario === 'pagination' ? 'pagination' : $scenario,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        if (isset($raw['error'])) {
            $code = (string) ($raw['error']['code'] ?? SyncErrorCode::TemporaryFailure->value);

            return new SyncPage(
                records: [],
                cursorBefore: ['page' => $page],
                cursorAfter: null,
                hasMore: false,
                sourceMode: 'mock',
                errorCode: $code,
                errorMessage: (string) ($raw['error']['message'] ?? 'Mock connector error'),
                retryAfterSeconds: isset($raw['meta']['retry_after']) ? (int) $raw['meta']['retry_after'] : null,
            );
        }

        if (! isset($raw['data']) || ! is_array($raw['data']) || ! isset($raw['meta']) || ! is_array($raw['meta'])) {
            return new SyncPage(
                [],
                ['page' => $page],
                null,
                false,
                'mock',
                SyncErrorCode::MalformedPagination->value,
                'Connector returned malformed pagination metadata.',
            );
        }

        $meta = $raw['meta'];
        if (! isset($meta['page'], $meta['per_page'], $meta['total'])) {
            return new SyncPage(
                [],
                ['page' => $page],
                null,
                false,
                'mock',
                SyncErrorCode::MalformedPagination->value,
                'Missing page/per_page/total metadata.',
            );
        }

        $total = (int) $meta['total'];
        $hasMore = ($page * $perPage) < $total;
        if ($total === 0) {
            $hasMore = false;
        }

        return new SyncPage(
            records: array_values($raw['data']),
            cursorBefore: ['page' => $page],
            cursorAfter: $hasMore ? ['page' => $page + 1] : ['page' => $page],
            hasMore: $hasMore,
            sourceMode: 'mock',
        );
    }

    /**
     * @return array{slice: list<array<string, mixed>>, has_more: bool, total: int}|null
     */
    private function maybeLoadSyncFixture(string $provider, string $scenario, int $page, int $perPage): ?array
    {
        if (! $this->optionsValidator->isSafeFixtureName($provider) || ! $this->optionsValidator->isSafeFixtureName($scenario)) {
            return null;
        }

        try {
            $this->optionsValidator->resolveFixturePath($provider, $scenario);
        } catch (\Throwable) {
            return null;
        }

        return $this->loadSyncFixture($provider, $scenario, $page, $perPage);
    }

    /**
     * @return array{slice: list<array<string, mixed>>, has_more: bool, total: int}
     */
    private function loadSyncFixture(string $provider, string $scenario, int $page, int $perPage): array
    {
        $path = $this->optionsValidator->resolveFixturePath($provider, $scenario);
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException("Invalid sync fixture: {$path}");
        }

        $total = count($decoded);
        $slice = array_values(array_slice($decoded, ($page - 1) * $perPage, $perPage));

        return [
            'slice' => $slice,
            'has_more' => ($page * $perPage) < $total,
            'total' => $total,
        ];
    }
}
