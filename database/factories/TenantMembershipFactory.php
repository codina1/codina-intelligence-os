<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantMembership>
 */
class TenantMembershipFactory extends Factory
{
    protected $model = TenantMembership::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'role_id' => fn () => Role::query()->where('slug', 'viewer')->value('id')
                ?? Role::query()->create([
                    'slug' => 'viewer',
                    'name' => 'Viewer',
                    'is_platform_role' => false,
                ])->id,
            'status' => 'active',
            'joined_at' => now(),
        ];
    }
}
