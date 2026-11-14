# Phase 02B Test Plan

## Suites

| Suite | Path / group |
| --- | --- |
| Engine | `tests/Feature/Synchronization/SyncEngineTest.php` |
| Concurrency | `SyncConcurrencyTest.php` (`@group critical`) |
| Security | `SyncSecurityTest.php` (`@group critical`) |
| Database | `SyncDatabaseTest.php` (`@group critical`) |
| Regression | Phase 01 + Phase 02A + full PHPUnit |
| Critical | `--group critical --fail-on-skipped` |

## Coverage targets

- Full 25-record / 3-page sync for all five mock connectors
- Incremental change stream + watermark
- Retry resume from checkpoint; repeated sync dedupe + unchanged counts
- Invalid records, cursor loop, disabled source
- Lease exclusivity, expiration reclaim, fencing, checkpoint anti-regression
- Cross-tenant 404, CSRF, viewer denied manage, no external HTTP
- Migration tables, unique constraints, atomic rollback, composite FK
