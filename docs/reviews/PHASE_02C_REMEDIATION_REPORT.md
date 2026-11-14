# Phase 02C Remediation Report

**Status:** `PHASE_02C_READY_FOR_RE_REVIEW` (pending CI stamp on final HEAD)  
**Reviewed base:** `18a6f45ce4e0a0e58bb660a99ead63d10ff15f6d`  
**PR:** https://github.com/codina1/codina-intelligence-os/pull/4

## Root causes

1. **Provenance** — Runner treated “any completed SyncRun exists” as license to project all current staging rows, including payloads last touched by partial/failed runs.
2. **Concurrency** — No exclusive ownership per data source; duplicate POSTs could queue competing runs.
3. **Execution auth** — Job trusted enqueue-time membership; revoked/demoted users and disabled sources were not re-checked.
4. **N+1** — `serializeEntity` / `serializeMetric` queried `data_sources` per row.

## Fixes

| Fix | Approach | Key files |
| --- | --- | --- |
| Provenance | `StagingProvenanceGate`: only rows whose `last_sync_run_id` ∈ completed SyncRuns; else `inadmissible_provenance` (fail closed; prior snapshot not reconstructable) | `StagingProvenanceGate.php`, `NormalizationRunner.php` |
| Concurrency | `normalization_active_scopes` + lease/generation fencing (300s TTL); 409 on conflict | migration `000460`, `NormalizationLeaseManager.php`, orchestrator |
| Execution auth | Re-check membership + manage perms + mock/active source at job time; terminal `failed` + audit | `NormalizationOrchestrator::executeQueuedRun`, job |
| N+1 | Eager-load `dataSource` without tenant scope on list endpoints | `InventoryQueryService.php` |
| Dates | Optional `date` parsed; invalid → `invalid_date`; missing → null; no fabricated CTR/impressions/conversions | GSC/GA4 normalizers |

## Migrations

- `2024_01_01_000460_create_normalization_active_scopes_table.php` (+ lease columns on `normalization_runs`)

## Provenance policy

`config('normalization.provenance_policy') = completed_last_sync_run_only`

Limitation documented: mutable staging upserts mean a partial overwrite cannot be rolled back to the prior completed payload without inventing history.

## Tests (local)

| Suite | Result |
| --- | --- |
| Phase 02C Normalization | **36 passed** |
| Critical `--fail-on-skipped` | **81 passed** |
| Full PHPUnit | **150 passed** / 0 skipped |
| Two-connection MariaDB fencing | `NormalizationConcurrencyTest` |

## Risks / residual

- Staging still cannot reconstruct prior completed snapshots after partial overwrite (by design, fail closed).
- Lease TTL default 300s; expired leases can be claimed by a successor run.

## Boundaries

Mock only. No Production/external HTTP/Phase 03/auto-merge.
