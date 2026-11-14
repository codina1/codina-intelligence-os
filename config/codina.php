<?php

return [
    /*
    |--------------------------------------------------------------------------
    | External HTTP
    |--------------------------------------------------------------------------
    |
    | Phase 01 forbids real external HTTP. Mock connectors must not perform
    | network calls. Keep this false unless explicitly approved later.
    |
    */
    'allow_external_http' => (bool) env('CODINA_ALLOW_EXTERNAL_HTTP', false),

    'schema_version' => env('CODINA_SCHEMA_VERSION', '1.0'),

    'roles' => [
        'platform_admin' => 'Platform Admin',
        'tenant_owner' => 'Tenant Owner',
        'content_manager' => 'Content Manager',
        'analyst' => 'Analyst',
        'viewer' => 'Viewer',
    ],

    'permissions' => [
        'tenants.view',
        'tenants.manage',
        'sites.view',
        'sites.manage',
        'connectors.view',
        'connectors.manage',
        'sync.manage',
        'inventory.view',
        'normalize.manage',
        'audit.view',
        'platform.admin',
    ],
];
