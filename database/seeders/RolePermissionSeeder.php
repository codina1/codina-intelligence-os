<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'tenants.view' => 'View tenants',
            'tenants.manage' => 'Manage tenants',
            'sites.view' => 'View sites',
            'sites.manage' => 'Manage sites',
            'connectors.view' => 'View connectors',
            'connectors.manage' => 'Manage connectors',
            'sync.manage' => 'Manage sync runs',
            'inventory.view' => 'View content inventory',
            'normalize.manage' => 'Manage normalization runs',
            'audit.view' => 'View audit logs',
            'platform.admin' => 'Platform administration',
        ];

        foreach ($permissions as $slug => $name) {
            Permission::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $name]
            );
        }

        $roles = [
            'platform_admin' => [
                'name' => 'Platform Admin',
                'is_platform_role' => true,
                'permissions' => array_keys($permissions),
            ],
            'tenant_owner' => [
                'name' => 'Tenant Owner',
                'is_platform_role' => false,
                'permissions' => [
                    'tenants.view',
                    'sites.view',
                    'sites.manage',
                    'connectors.view',
                    'connectors.manage',
                    'sync.manage',
                    'inventory.view',
                    'normalize.manage',
                    'audit.view',
                ],
            ],
            'content_manager' => [
                'name' => 'Content Manager',
                'is_platform_role' => false,
                'permissions' => [
                    'tenants.view',
                    'sites.view',
                    'sites.manage',
                    'connectors.view',
                    'sync.manage',
                    'inventory.view',
                    'normalize.manage',
                ],
            ],

            'analyst' => [
                'name' => 'Analyst',
                'is_platform_role' => false,
                'permissions' => [
                    'tenants.view',
                    'sites.view',
                    'connectors.view',
                    'inventory.view',
                    'audit.view',
                ],
            ],
            'viewer' => [
                'name' => 'Viewer',
                'is_platform_role' => false,
                'permissions' => [
                    'tenants.view',
                    'sites.view',
                    'connectors.view',
                    'inventory.view',
                ],
            ],
        ];

        foreach ($roles as $slug => $definition) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'description' => $definition['name'],
                    'is_platform_role' => $definition['is_platform_role'],
                ]
            );

            $permissionIds = Permission::query()
                ->whereIn('slug', $definition['permissions'])
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
