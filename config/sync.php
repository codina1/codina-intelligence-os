<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sync Engine (Phase 02B — mock only)
    |--------------------------------------------------------------------------
    |
    | Lease TTL is intentionally separate from Phase 01's 30-second
    | request-idempotency reclaim window. Sync leases are for long-running jobs.
    |
    */
    'lease_ttl_seconds' => (int) env('CODINA_SYNC_LEASE_TTL_SECONDS', 300),
    'max_pages_per_run' => (int) env('CODINA_SYNC_MAX_PAGES', 100),
    'max_records_per_page' => (int) env('CODINA_SYNC_MAX_RECORDS_PER_PAGE', 100),
    'default_page_size' => (int) env('CODINA_SYNC_DEFAULT_PAGE_SIZE', 10),
    'max_retries' => (int) env('CODINA_SYNC_MAX_RETRIES', 3),
    // Deterministic mock backoff base (milliseconds). Tests control clock without real sleeps.
    'backoff_base_ms' => (int) env('CODINA_SYNC_BACKOFF_BASE_MS', 100),
    'overlap_window_seconds' => (int) env('CODINA_SYNC_OVERLAP_WINDOW_SECONDS', 60),
    'max_payload_bytes' => (int) env('CODINA_SYNC_MAX_PAYLOAD_BYTES', 65536),
];
