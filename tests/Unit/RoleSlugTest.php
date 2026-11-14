<?php

namespace Tests\Unit;

use App\Identity\Enums\RoleSlug;
use PHPUnit\Framework\Attributes\UnitTest;
use PHPUnit\Framework\TestCase;

class RoleSlugTest extends TestCase
{
    #[UnitTest]
    public function test_role_labels(): void
    {
        $this->assertSame('Platform Admin', RoleSlug::PlatformAdmin->label());
        $this->assertSame('Tenant Owner', RoleSlug::TenantOwner->label());
        $this->assertSame('viewer', RoleSlug::Viewer->value);
    }
}
