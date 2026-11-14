# Phase 02C Remediation Report

**Status:** `PHASE_02C_READY_FOR_FINAL_REVIEW` (CI stamp pending on this HEAD)

| Item | Value |
| --- | --- |
| Reviewed base (fencing) | `54b007f1decf410e4cc8c6172a53aba7d9792dd2` |
| Prior remediation tip | `6cd36321612c14e1eac1f65a73d096bdd6363348` |
| PR | https://github.com/codina1/codina-intelligence-os/pull/4 |
| Mode | Mock only — not Production |

## Root causes (prior + fencing)

1. **Provenance** — Runner treated “any completed SyncRun exists” as license to project all current staging rows.
2. **Concurrency** — No exclusive ownership per data source; duplicate POSTs could queue competing runs.
3. **Execution auth** — Job trusted enqueue-time membership.
4. **N+1** — Inventory serializers queried `data_sources` per row.
5. **Projection fencing** — Ownership `FOR UPDATE` was not held across projection writes; stale workers could race commits after lease transfer. Nested projection transactions could release protection early.
6. **Stale terminal updates** — Lost-lease workers could overwrite status / risk foreign lease release without generation guards.

## Fixes

| Fix | Approach | Key files |
| --- | --- | --- |
| Provenance | `StagingProvenanceGate`: only rows whose `last_sync_run_id` ∈ completed SyncRuns | `StagingProvenanceGate.php` |
| Concurrency | `normalization_active_scopes` + lease/generation (300s TTL); 409 on conflict | `NormalizationLeaseManager.php` |
| Execution auth | Re-check membership + manage perms + mock/active source at job time | `NormalizationOrchestrator.php` |
| N+1 | Eager-load `dataSource` on list endpoints | `InventoryQueryService.php` |
| **Atomic projection fencing** | Map outside locks; shared txn: scope `FOR UPDATE` → source → staging revalidate (snapshot) → project; no nested txn when already fenced | `NormalizationRunner.php`, Content/Metric projection |
| **Stale worker status** | `markTerminalIfStillOwner` / `applyStaleWorkerAbort`; never revert completed; never release foreign lease; audit | `NormalizationLeaseManager.php` |
| **Staging race** | Snapshot `payload_hash` + `last_sync_run_id` + `last_changed_at`; reject `staging_mutated` | `StagingProvenanceGate.php` |
| Dates | Optional `date`; invalid → `invalid_date`; no fabricated metrics | GSC/GA4 normalizers |

## Migrations

- `2024_01_01_000460_create_normalization_active_scopes_table.php` (+ lease columns on `normalization_runs`)

## Provenance policy

`config('normalization.provenance_policy') = completed_last_sync_run_only`

Write-boundary revalidation + staging snapshot detects concurrent Sync payload changes between validate/map and project.

## Tests

| Suite | Result |
| --- | --- |
| Phase 02C Normalization (critical) | **27 passed** |
| Critical `--fail-on-skipped` | **86 passed** |
| Full PHPUnit | **155 passed** / 0 skipped |
| Two-connection MariaDB fencing | `NormalizationLeaseFencingMariaDbTest` (stale reject after transfer; held lock blocks transfer; PDO interleave rollback; stale abort isolation; staging mutation) |

## Risks / residual

- Staging still cannot reconstruct prior completed snapshots after partial overwrite (fail closed).
- Lease TTL default 300s; expired leases can be claimed only after holders release row locks.

## Boundaries

Mock only. No Production/external HTTP/Phase 03/auto-merge.

PHASE_02C_READY_FOR_FINAL_REVIEW
