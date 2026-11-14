<?php

namespace App\Normalization\Enums;

enum NormalizationErrorCode: string
{
    case UnsupportedProvider = 'unsupported_provider';
    case UnsupportedEntityType = 'unsupported_entity_type';
    case MissingRequiredField = 'missing_required_field';
    case InvalidPayload = 'invalid_payload';
    case ExternalIdMismatch = 'external_id_mismatch';
    case NoCommittedStaging = 'no_committed_staging';
    case ForbiddenMode = 'forbidden_mode';
    case SourceDisabled = 'source_disabled';
    case MapperFailure = 'mapper_failure';
}
