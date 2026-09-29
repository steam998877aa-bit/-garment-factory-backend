<?php

use Database\Seeders\DepartmentWorkshopSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Ensures all canonical departments and workshops (including 'تنضيف و فحص')
 * exist in the database with their latest aliases and positions.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DepartmentWorkshopSeeder())->run();
    }

    public function down(): void
    {
        // Reference department data is retained on rollback.
    }
};
