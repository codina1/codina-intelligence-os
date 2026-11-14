# Test Results — Phase 01 (post-remediation)

## Execution

| Field | Value |
| --- | --- |
| Command | `php artisan test` / `./vendor/bin/phpunit --teamcity` |
| Date (UTC) | 2026-11-14T06:55:00Z |
| PHP | 8.3.6 |
| Laravel | 13.42.0 |
| Database | `codina_ios_test` @ `127.0.0.1` (MariaDB 10.11.14 local) |
| Result | **OK — 45 tests, 158 assertions** |

## Per-test outcomes (executed)

All listed tests were executed; none marked PASS without running.

| Result | Test |
| --- | --- |
| PASS | test_role_labels |
| PASS | test_health_endpoint_envelope |
| PASS | test_validation_errors_use_structured_envelope |
| PASS | test_authentication_and_authorization_errors |
| PASS | test_sites_pagination_meta |
| PASS | test_request_id_header_is_echoed |
| PASS | test_successful_data_retrieval |
| PASS | test_pagination |
| PASS | test_error_simulation_scenarios |
| PASS | test_no_external_network_access |
| PASS | test_mock_connectors_index_lists_all_five |
| PASS | test_application_boots |
| PASS | test_local_database_connectivity |
| PASS | test_required_tables_exist_after_migrations |
| PASS | test_safe_configuration |
| PASS | test_user_can_authenticate_with_session |
| PASS | test_invalid_credentials_are_rejected |
| PASS | test_unauthenticated_requests_return_401 |
| PASS | test_role_permissions_are_seeded |
| PASS | test_viewer_cannot_manage_sites |
| PASS | test_concurrent_idempotency_claims_on_mariadb |
| PASS | test_true_parallel_inserts_via_pcntl_or_sequential_race_window |
| PASS | test_queue_dispatch_and_processing |
| PASS | test_failed_job_handling_records_failure_path |
| PASS | test_audit_log_creation |
| PASS | test_idempotent_request_handling |
| PASS | test_idempotency_conflict_on_payload_mismatch |
| PASS | test_nullable_tenant_scope_uniqueness_is_enforced |
| PASS | test_tenant_context_does_not_leak_across_sequential_jobs |
| PASS | test_real_database_queue_processing_and_failed_jobs |
| PASS | test_csrf_cookie_endpoint_issues_session |
| PASS | test_mutating_request_without_csrf_token_is_rejected |
| PASS | test_mutating_request_with_invalid_csrf_token_is_rejected |
| PASS | test_mutating_request_with_valid_csrf_token_succeeds |
| PASS | test_login_requires_csrf_token |
| PASS | test_safe_methods_do_not_require_csrf |
| PASS | test_tenant_creation_by_platform_admin |
| PASS | test_site_membership_and_listing_is_tenant_scoped |
| PASS | test_idor_prevention_on_site_show_update_delete |
| PASS | test_cross_tenant_fk_protection_on_create |
| PASS | test_tenant_context_inside_queued_jobs |
| PASS | test_tenant_index_hides_foreign_tenants |
| PASS | test_missing_tenant_context_fails_closed |
| PASS | test_stale_tenant_context_does_not_leak_after_clear |
| PASS | test_platform_bypass_is_audited |

## Machine JSON

```json
{"tool":"phpunit","result":"passed","tests":45,"passed":45,"assertions":158}
```
