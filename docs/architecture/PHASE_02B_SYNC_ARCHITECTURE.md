# Phase 02B Sync Architecture

**Mode:** MOCK ONLY — all runs set `is_simulated=true` and refuse non-mock sources.  
**Module:** `app/Synchronization`

## Components

| Component | Role |
| --- | --- |
| `SyncOrchestrator` | API-facing start/retry/cancel/list; authorization; job dispatch |
| `ExecuteSyncRunJob` | Queue worker; restores tenant context; clears in `finally` |
| `SyncRunner` | State machine execution, page loop, counters |
| `SyncLeaseManager` | DB-enforced exclusive scope ownership, heartbeat, fencing generation |
| `CheckpointManager` | Durable cursors/watermarks; anti-regression; full-sync reset |
| `IngestionService` | Staging upsert, dedupe, per-run commit keys |
| `SyncPageAdapter` | Adapts mock `fetch(page/per_page)` → cursor DTO contract |
| `SyncRecordValidator` | Per-provider entity validation; hash helpers |
| `SyncErrorClassifier` | Retryable vs terminal; deterministic mock backoff |

## State machine

`queued → running → {completed|partial|failed|cancelled}`  
`partial → {running|failed|cancelled}`  
`failed → queued` (explicit retry)  
`completed` / `cancelled` are terminal (no implicit restart).

## Lease & fencing

- Coordination table `sync_active_scopes` unique on `(data_source_id, entity_type, scope_key)`.
- Acquire uses `SELECT … FOR UPDATE` + generation bump (CAS-style).
- Lease TTL defaults to **300s** (not Phase 01’s 30s idempotency reclaim).
- Workers heartbeat before each page; fencing generation checked before commit.
- Stale owners cannot commit after reclaim.

## Cursor & checkpoints

- Full sync: starts at page 1 (resets page progress for a fresh run); retries resume after `last_completed_page`.
- Incremental sync: uses fixture change stream + committed watermark; watermark advances only on full incremental success.
- Page processing is transactional: batch + upsert + checkpoint commit together.

## Out of scope

Phase 02C normalization into final content tables is not implemented.
