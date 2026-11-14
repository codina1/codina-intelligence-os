<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Normalization defaults
    |--------------------------------------------------------------------------
    |
    | Phase 02C projects committed mock staging records into tenant-scoped
    | read models. External HTTP remains forbidden.
    |
    */
    'default_mapper_bundle_version' => '1.0.0',

    'batch_size' => (int) env('CODINA_NORMALIZATION_BATCH_SIZE', 100),

    'content_providers' => [
        'wordpress',
        'woocommerce',
        'tutor_lms_pro',
    ],

    'metric_providers' => [
        'google_search_console',
        'google_analytics_4',
    ],
];
