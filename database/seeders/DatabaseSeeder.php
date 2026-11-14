<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Refusing to seed production environment.');

            return;
        }

        $this->call([
            RolePermissionSeeder::class,
            LocalDemoSeeder::class,
        ]);
    }
}
