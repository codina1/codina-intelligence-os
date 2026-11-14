# Security Review — Phase 01

## Scope

Local/dev Phase 01 foundation only. Not a production readiness review.

## Findings

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| S01 | High (mitigated) | Production connectors/credentials misuse | External HTTP disabled; mocks only; tests assert no HTTP |
| S02 | High (mitigated) | Cross-tenant IDOR on sites | Membership-scoped lookup; inaccessible/missing both return **404** (no enumeration) |
| S03 | Medium (mitigated) | Unauthenticated API access | 401 envelope for protected routes |
| S04 | Medium (mitigated) | Privilege escalation via role | Permission matrix seeded; viewer cannot manage sites |
| S05 | Medium (mitigated) | Login brute force | Rate limiter `login` (5/min) + AuthSessionController limiter |
| S06 | Low (mitigated) | Secret leakage in audit logs | AuditLogger redacts sensitive keys |
| S07 | Low (mitigated) | Session CSRF on API | SPA CSRF middleware + cookie endpoint; 419 on mismatch |
| S08 | Medium (mitigated) | Idempotency race / duplicate effects | Atomic claim + DB transaction; durable pending/completed/retryable/failed |
| S09 | Info | Stack traces | API exception renderer suppresses internals when `APP_DEBUG=false` |

## Critical unresolved issues

None identified for Phase 01 local scope after final fixes.

## Recommendations before later phases

- Add Sanctum/token strategy if non-first-party clients appear.
- For external side effects under idempotency keys, use `failed` (not `retryable`) + outbox/compensation — see `IDEMPOTENCY.md`.
- Keep real connector OAuth behind dedicated secrets vault and egress controls.
