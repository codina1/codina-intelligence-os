<?php

namespace App\Identity\Enums;

enum RoleSlug: string
{
    case PlatformAdmin = 'platform_admin';
    case TenantOwner = 'tenant_owner';
    case ContentManager = 'content_manager';
    case Analyst = 'analyst';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::PlatformAdmin => 'Platform Admin',
            self::TenantOwner => 'Tenant Owner',
            self::ContentManager => 'Content Manager',
            self::Analyst => 'Analyst',
            self::Viewer => 'Viewer',
        };
    }
}
