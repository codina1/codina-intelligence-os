<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_platform_role',
    ];

    protected function casts(): array
    {
        return [
            'is_platform_role' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissions->contains('slug', $permission);
    }
}
