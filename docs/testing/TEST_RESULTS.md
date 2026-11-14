# Test Results — Phase 01

## Execution

| Field | Value |
| --- | --- |
| Command | `./vendor/bin/phpunit --teamcity` |
| Also verified | `php artisan test` → `{"result":"passed","tests":30,"passed":30,"assertions":115}` |
| Date (UTC) | 2026-11-14T06:23:50Z |
| PHP | 8.3.6 |
| Laravel | 13.42.0 |
| Database | `codina_ios_test` @ `127.0.0.1` (MariaDB 10.11.14 local) |
| Result | **OK (30 tests, 115 assertions)** |

## Per-test outcomes (executed)

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
| PASS | test_queue_dispatch_and_processing |
| PASS | test_failed_job_handling_records_failure_path |
| PASS | test_audit_log_creation |
| PASS | test_idempotent_request_handling |
| PASS | test_tenant_creation_by_platform_admin |
| PASS | test_site_membership_and_listing_is_tenant_scoped |
| PASS | test_idor_prevention_on_site_show_update_delete |
| PASS | test_cross_tenant_fk_protection_on_create |
| PASS | test_tenant_context_inside_queued_jobs |
| PASS | test_tenant_index_hides_foreign_tenants |

## Raw summary excerpt

```text
Command: ./vendor/bin/phpunit --teamcity
Date: 2026-11-14T06:23:50Z
PHP: PHP 8.3.6 (cli) (built: Oct 19 2026 15:16:58) (NTS)
Laravel: Laravel Framework 13.42.0
DB: codina_ios_test @ 127.0.0.1 (MariaDB local)
Result: OK (30 tests, 115 assertions)
```

Machine JSON result from `php artisan test`:

```json
{"tool":"phpunit","result":"passed","tests":30,"passed":30,"assertions":115}
```

## Notes

- No tests were marked PASS without execution.
- RefreshDatabase targeted only the verified local test database `codina_ios_test`.
