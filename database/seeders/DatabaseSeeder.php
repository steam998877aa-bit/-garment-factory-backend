<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: users are attached to roles, so roles must exist first.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DepartmentWorkshopSeeder::class,
            UserSeeder::class,
        ]);
    }
}
