<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Normalization defaults
    |--------------------------------------------------------------------------
    |
    | Phase 02C projects committed mock staging records into tenant-scoped
    | read models. External HTTP remains forbidden.
    |
    */
    'default_mapper_bundle_version' => '1.0.0',

    'batch_size' => (int) env('CODINA_NORMALIZATION_BATCH_SIZE', 100),

    /*
    | Lease TTL for exclusive per-data-source normalization ownership.
    | Separate from Phase 01 HTTP idempotency reclaim (30s).
    */
    'lease_ttl_seconds' => (int) env('CODINA_NORMALIZATION_LEASE_TTL', 300),

    /*
    | Provenance policy (Phase 02C):
    | Only project ingestion_records whose last_sync_run_id references a
    | SyncRun with status=completed for the same tenant/data_source.
    | Partial/failed/cancelled latest provenance is inadmissible; earlier
    | committed snapshots are not reconstructable from current staging rows.
    */
    'provenance_policy' => 'completed_last_sync_run_only',

    'content_providers' => [
        'wordpress',
        'woocommerce',
        'tutor_lms_pro',
    ],

    'metric_providers' => [
        'google_search_console',
        'google_analytics_4',
    ],
];
