<?php

namespace App\Normalization\Enums;

enum NormalizationErrorCode: string
{
    case UnsupportedProvider = 'unsupported_provider';
    case UnsupportedEntityType = 'unsupported_entity_type';
    case MissingRequiredField = 'missing_required_field';
    case InvalidPayload = 'invalid_payload';
    case InvalidDate = 'invalid_date';
    case ExternalIdMismatch = 'external_id_mismatch';
    case NoCommittedStaging = 'no_committed_staging';
    case InadmissibleProvenance = 'inadmissible_provenance';
    case ActiveRunConflict = 'active_run_conflict';
    case StaleLease = 'stale_lease';
    case ForbiddenMode = 'forbidden_mode';
    case SourceDisabled = 'source_disabled';
    case UnauthorizedExecution = 'unauthorized_execution';
    case MapperFailure = 'mapper_failure';
}
