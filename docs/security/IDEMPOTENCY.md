# Idempotency — Phase 01

## Durable statuses

| Status | Meaning | Same key + same hash |
| --- | --- | --- |
| `pending` | Claim held; work in progress (or crashed mid-flight) | Wait / reclaim if stale (>30s) |
| `completed` | Success recorded | Replay stored response |
| `retryable` | DB-local attempt failed; transaction rolled back | Reclaim and retry |
| `failed` | Terminal / ambiguous (possible external side effect) | 409 — use a new key |

## Atomicity model

1. Insert unique claim (`tenant_scope_id`, `user_id`, `key`, `route`).
2. Run the callback inside a DB transaction and persist `completed` in the same transaction.
3. On exception: never delete the claim; mark `retryable` after rollback (Phase 01 DB-local path).

## External side-effect limits

Phase 01 callbacks (site create) are **database-local**. If a future callback performs external HTTP, enqueues irreversible work, or writes outside the transaction:

- Do **not** treat the failure as `retryable`.
- Call `IdempotencyGuard::markFailed()` (or equivalent) so retries require a new key.
- Prefer outbox / compensating transactions before enabling automatic retry.
