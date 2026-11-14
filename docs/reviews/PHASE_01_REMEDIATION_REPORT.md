# Phase 01 Security Remediation Report

## 1. Exact commit SHA

Base (reviewed): `b035db4dfdf4e0f9f7c6a26b32422bbcad8d3863`  
Remediation commit: `7c13e84b4aa3ccce51faf7af9204ed057de52406`

## 2. Root cause of each issue

| ID | Severity | Root cause |
| --- | --- | --- |
| P1 CSRF | High | API middleware started sessions for first-party auth but did not run CSRF verification; Laravel’s default CSRF also skips under PHPUnit, so session-mutating API routes were forgeable from other origins. |
| P1 Idempotency | High | Check-then-act-then-insert race; MariaDB `UNIQUE` treating `NULL` `tenant_id` as distinct allowed duplicate keys for null-tenant scope. |
| P2 Tenant scope | Medium | `TenantScope` applied a filter only when context was set; missing context silently returned unscoped rows. `withoutGlobalScopes()` was used broadly. |
| P2 Queue context | Medium | `TenantAwareJob` set `TenantContext` but never cleared it, risking singleton leak across worker job reuse. |
| HTTP tests | Low | No global stray-HTTP prevention in the test harness. |

## 3. Files changed

Key application/test/docs changes (non-exhaustive):

- `bootstrap/app.php` — SPA CSRF middleware stack + 419 rendering
- `app/Http/Middleware/VerifySpaCsrfToken.php` — CSRF that remains active in tests
- `app/Http/Controllers/Api/V1/AuthSessionController.php` — `csrfCookie`
- `routes/api.php` — CSRF cookie route
- `app/Shared/Support/IdempotencyGuard.php` — atomic claim/replay
- `app/Models/IdempotencyKey.php` — `tenant_scope_id` / `status`
- `database/migrations/2024_01_01_000150_*` + `000170_*` — hardened uniqueness
- `app/Tenancy/Scopes/TenantScope.php` — fail-closed
- `app/Tenancy/Services/TenantContext.php` — audited platform bypass + clear semantics
- `app/Tenancy/Models/Concerns/BelongsToTenant.php` — explicit `withoutTenantIsolation`
- `app/Sites/Services/SiteService.php` — membership/platform access paths
- `app/Synchronization/Jobs/TenantAwareJob.php` — `finally` clear
- `tests/TestCase.php` — CSRF helpers + `Http::preventStrayRequests()`
- New tests under `tests/Feature/Security/` and `tests/Feature/Infrastructure/`
- Docs: `docs/security/*`, `docs/api/openapi.yaml`, `docs/testing/TEST_RESULTS.md`, this report

## 4. Security fixes

1. **CSRF** — EncryptCookies + session + `VerifySpaCsrfToken` on API; SPA `GET /api/v1/auth/csrf-cookie`; mutating routes require token; 419 envelope.
2. **Idempotency** — Insert-first claim with unique `(tenant_scope_id, user_id, key, route)`; sentinel `tenant_scope_id=0` for null tenant; 409 on payload mismatch; site create wired through guard; concurrent MariaDB/PDO + `pcntl_fork` tests.
3. **Fail-closed tenancy** — Missing context throws; platform bypass audited; membership reads use explicit `withoutTenantIsolation()` + `whereIn`; negative tests for missing/stale context.
4. **Queue lifecycle** — `TenantContext::clear()` in `finally`; sequential leak test; real `database` queue work + `failed_jobs` recording.
5. **HTTP guard** — `Http::preventStrayRequests()` in `TestCase::setUp()`.

## 5. New tests and results

New/extended coverage includes CSRF missing/invalid/valid, login CSRF, fail-closed tenant context, platform bypass audit, idempotency conflict, nullable scope uniqueness, concurrent MariaDB claims, pcntl parallel inserts, DB queue success/failure, context non-leak.

Executed with the full suite: **45 passed / 158 assertions**.

## 6. Full regression results

```json
{"tool":"phpunit","result":"passed","tests":45,"passed":45,"assertions":158}
```

Evidence: `docs/testing/TEST_RESULTS.md`  
Database: isolated MariaDB `codina_ios_test` only. Tests were not weakened.

## 7. Remaining risks

- SPA clients must remember to call the CSRF cookie endpoint; documentation added but UX depends on frontend (not in Phase 01).
- Platform bypass is powerful; currently audited per call — operators should monitor `platform.tenant_bypass` audit rows.
- Concurrent idempotency “in progress” path returns 409 after a short wait; clients should retry with the same key.
- CSRF middleware intentionally does not skip under PHPUnit; all mutating tests must use `withCsrf()` helpers.

## 8. Final readiness assessment

Phase 01 remediation items from the review prompt are implemented and verified on the isolated local MariaDB test database. No production access. No Phase 02 work. PR #1 should be re-reviewed; do not merge without explicit approval.

`PHASE_01_READY_FOR_RE_REVIEW`
