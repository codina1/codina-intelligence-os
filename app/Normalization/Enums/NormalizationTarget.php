<?php

namespace App\Normalization\Enums;

enum NormalizationTarget: string
{
    case ContentEntity = 'content_entity';
    case MetricObservation = 'metric_observation';
}
