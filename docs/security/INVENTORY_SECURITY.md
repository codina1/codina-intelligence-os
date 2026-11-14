# Inventory / Normalization Security (Phase 02C)

- Fail-closed `TenantScope` on all inventory models
- Composite tenant-aware FKs; no cross-tenant references
- Cross-tenant entity/run/source access → indistinguishable **404**
- Missing permission → **403**
- CSRF required on `POST .../normalize`
- `NormalizationRunJob` re-checks membership; clears `TenantContext` in `finally`
- Failures store safe diagnostics only (no raw payloads/secrets)
- Mock mode enforced; `CODINA_ALLOW_EXTERNAL_HTTP=false` required
- No Production credentials or external HTTP
