# Phase 02C Preflight

| Item | Value |
| --- | --- |
| Base branch | `main` |
| Base commit | `a8cbb6eaef3041964993655c26849aff2b418b61` (Phase 02B PR #3 merge) |
| Feature branch | `cursor/phase-02c-content-inventory` |
| Data mode | Mock only |
| External HTTP | `CODINA_ALLOW_EXTERNAL_HTTP=false` |

## Confirmed before implementation

1. Phase 02B PR #3 merged into `main` at `a8cbb6e`.
2. Working tree clean on updated `main`.
3. Staging tables present: `sync_runs`, `ingestion_batches`, `ingestion_records`, `data_sources`, `sites`.
4. Fixture schemas remain minimal (no URLs/SEO/analytics dimensions fabricated).
5. Tenant security patterns (TenantContext, BelongsToTenant, CSRF, indistinct 404s) reused.
6. Local MariaDB databases `codina_ios_local` / `codina_ios_test` only — not Production.
7. Existing suite green on base before Phase 02C changes.

## Permissions decision

- `inventory.view` — read inventory/summary/metrics/data-quality/normalization-run show
- `normalize.manage` — POST normalize (also accepts `sync.manage` / `connectors.manage`)
- Seeded onto owner/content_manager (manage+view); analyst/viewer get view only

## Out of scope

SEO scoring, live connectors, Vue dashboard, AI linking, Production deploy, PR auto-merge.
