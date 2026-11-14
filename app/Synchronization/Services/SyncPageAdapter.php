<?php

namespace App\Synchronization\Services;

use App\Connectors\Registry\ConnectorRegistry;
use App\Models\DataSource;
use App\Synchronization\DTOs\SyncCursor;
use App\Synchronization\DTOs\SyncPage;
use App\Synchronization\Enums\SyncErrorCode;
use App\Synchronization\Enums\SyncType;
use RuntimeException;

/**
 * Adapts page/per_page mock connector fetch() into a cursor DTO contract.
 */
class SyncPageAdapter
{
    public function __construct(private readonly ConnectorRegistry $connectors) {}

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

        // Engine-level injected failures for test fixtures.
        if ($errorScenario === 'timeout_page_2' && $page >= 2) {
            return new SyncPage([], $cursor->toArray(), null, false, 'mock', SyncErrorCode::Timeout->value, 'Mock timeout on page 2.', 2);
        }
        if ($errorScenario === 'rate_limit_page_2' && $page >= 2) {
            return new SyncPage([], $cursor->toArray(), null, false, 'mock', SyncErrorCode::RateLimit->value, 'Mock rate limit on page 2.', 3);
        }
        if ($errorScenario === 'cursor_loop') {
            return new SyncPage(
                records: $this->loadSyncFixture($source->provider, 'paginated_25', $page, $perPage)['slice'],
                cursorBefore: $cursor->toArray(),
                cursorAfter: ['page' => $page], // intentionally does not advance
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
        $path = database_path('fixtures/sync/'.$source->provider.'/'.$dataset.'.json');

        if (! is_file($path)) {
            // Fallback: empty incremental change set (explicit, not "next page of full").
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

        $since = $cursor->payload['watermark'] ?? ($decoded['initial_watermark'] ?? null);
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
        $cursorAfter = $hasMore
            ? ['page' => $page + 1, 'watermark' => $since]
            : [
                'page' => $page,
                'watermark' => $decoded['final_watermark'] ?? ($slice[array_key_last($slice)]['changed_at'] ?? $since),
            ];

        return new SyncPage(
            records: array_values($records),
            cursorBefore: $cursor->toArray(),
            cursorAfter: $cursorAfter,
            hasMore: $hasMore,
            sourceMode: 'mock',
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
            $cursorAfter = $hasMore ? ['page' => $page + 1] : null;

            return new SyncPage(
                records: $syncFixture['slice'],
                cursorBefore: $cursor->toArray(),
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
                cursorBefore: $cursor->toArray(),
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
                $cursor->toArray(),
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
                $cursor->toArray(),
                null,
                false,
                'mock',
                SyncErrorCode::MalformedPagination->value,
                'Missing page/per_page/total metadata.',
            );
        }

        $total = (int) $meta['total'];
        $hasMore = ($page * $perPage) < $total;
        // Do not infer completeness from a single empty page unless total is known as 0.
        if ($total === 0) {
            $hasMore = false;
        }

        return new SyncPage(
            records: array_values($raw['data']),
            cursorBefore: $cursor->toArray(),
            cursorAfter: $hasMore ? ['page' => $page + 1] : null,
            hasMore: $hasMore,
            sourceMode: 'mock',
        );
    }

    /**
     * @return array{slice: list<array<string, mixed>>, has_more: bool, total: int}|null
     */
    private function maybeLoadSyncFixture(string $provider, string $scenario, int $page, int $perPage): ?array
    {
        $path = database_path('fixtures/sync/'.$provider.'/'.$scenario.'.json');
        if (! is_file($path)) {
            return null;
        }

        return $this->loadSyncFixture($provider, $scenario, $page, $perPage);
    }

    /**
     * @return array{slice: list<array<string, mixed>>, has_more: bool, total: int}
     */
    private function loadSyncFixture(string $provider, string $scenario, int $page, int $perPage): array
    {
        $path = database_path('fixtures/sync/'.$provider.'/'.$scenario.'.json');
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
