<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

class TenantContextRequiredException extends RuntimeException
{
    public function __construct(string $message = 'Tenant context is required for tenant-owned queries.')
    {
        parent::__construct($message);
    }
}
