<?php

namespace App\Synchronization\Enums;

enum SyncErrorCode: string
{
    case Timeout = 'timeout';
    case RateLimit = 'rate_limit';
    case TemporaryFailure = 'temporary_failure';
    case TransientDatabaseFailure = 'transient_database_failure';
    case Unauthorized = 'unauthorized';
    case InvalidSchema = 'invalid_schema';
    case InvalidConfiguration = 'invalid_configuration';
    case ForbiddenMode = 'forbidden_mode';
    case TenantMismatch = 'tenant_mismatch';
    case MissingExternalId = 'missing_external_id';
    case CursorLoop = 'cursor_loop';
    case MaxPagesExceeded = 'max_pages_exceeded';
    case LeaseLost = 'lease_lost';
    case Cancelled = 'cancelled';
    case SourceDisabled = 'source_disabled';
    case NonMockSource = 'non_mock_source';
    case ActiveRunConflict = 'active_run_conflict';
    case InvalidTransition = 'invalid_transition';
    case MalformedPagination = 'malformed_pagination';
    case PayloadTooLarge = 'payload_too_large';
    case CredentialLikeField = 'credential_like_field';
    case UnexpectedPersonalData = 'unexpected_personal_data';
    case HashCollision = 'hash_collision';
    case RetryBudgetExhausted = 'retry_budget_exhausted';
    case UnsupportedEntityType = 'unsupported_entity_type';

    public function isRetryable(): bool
    {
        return in_array($this, [
            self::Timeout,
            self::RateLimit,
            self::TemporaryFailure,
            self::TransientDatabaseFailure,
        ], true);
    }
}
