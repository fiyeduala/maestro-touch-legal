<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Safe to run in any environment: content, redirects and the starting service catalogue only. No user accounts are ever seeded;
 * the first administrator is invited with `php artisan mtl:invite-admin` (one-time link, no preset password).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PageSeeder::class,
            RedirectSeeder::class,
            ServiceSeeder::class,
        ]);
    }
}
