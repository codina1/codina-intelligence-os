<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Role::query()->count() === 0) {
            $this->call(RolePermissionSeeder::class);
        }

        $platformAdmin = User::query()->updateOrCreate(
            ['email' => 'admin@codina.local'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'is_platform_admin' => true,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $tenantA = Tenant::query()->updateOrCreate(
            ['slug' => 'tenant-a'],
            ['name' => 'Tenant A', 'status' => 'active', 'settings' => []]
        );

        $tenantB = Tenant::query()->updateOrCreate(
            ['slug' => 'tenant-b'],
            ['name' => 'Tenant B', 'status' => 'active', 'settings' => []]
        );

        $ownerRole = Role::query()->where('slug', 'tenant_owner')->firstOrFail();
        $viewerRole = Role::query()->where('slug', 'viewer')->firstOrFail();

        $ownerA = User::query()->updateOrCreate(
            ['email' => 'owner-a@codina.local'],
            [
                'name' => 'Owner A',
                'password' => Hash::make('password'),
                'is_platform_admin' => false,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $ownerB = User::query()->updateOrCreate(
            ['email' => 'owner-b@codina.local'],
            [
                'name' => 'Owner B',
                'password' => Hash::make('password'),
                'is_platform_admin' => false,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $viewerA = User::query()->updateOrCreate(
            ['email' => 'viewer-a@codina.local'],
            [
                'name' => 'Viewer A',
                'password' => Hash::make('password'),
                'is_platform_admin' => false,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        foreach ([
            [$tenantA, $ownerA, $ownerRole],
            [$tenantB, $ownerB, $ownerRole],
            [$tenantA, $viewerA, $viewerRole],
        ] as [$tenant, $user, $role]) {
            TenantMembership::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                ],
                [
                    'role_id' => $role->id,
                    'status' => 'active',
                    'joined_at' => now(),
                ]
            );
        }

        Site::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantA->id, 'slug' => 'site-a1'],
            [
                'name' => 'Site A1',
                'primary_url' => 'https://a1.example.test',
                'status' => 'active',
                'settings' => [],
            ]
        );

        Site::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantB->id, 'slug' => 'site-b1'],
            [
                'name' => 'Site B1',
                'primary_url' => 'https://b1.example.test',
                'status' => 'active',
                'settings' => [],
            ]
        );

        unset($platformAdmin);
    }
}
