<?php

namespace App\Normalization\Enums;

enum NormalizationRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case CompletedWithErrors = 'completed_with_errors';
}
