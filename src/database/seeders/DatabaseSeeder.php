<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds nothing. The image is built without dev dependencies (no Faker), so a model factory dies there; and a "Test User" must never
 * appear on a real box. Real users come from `php artisan invite`; dev test users from `dev:seed-testers` (no factory); the featured
 * notebook from its own route.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void {}
}
