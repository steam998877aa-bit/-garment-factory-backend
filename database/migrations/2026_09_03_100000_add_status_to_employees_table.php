<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether someone still works here.
 *
 * This is the employee's standing state, not their attendance today: a person
 * marked `on_leave` here is away for a stretch, which is a different fact from
 * the `leave` status a single day carries on the attendances table. The two are
 * deliberately kept apart — one is HR's record, the other is the daily sheet.
 *
 * Existing rows become `active`, which is what every current employee is; the
 * column is not nullable, so a record always states its position rather than
 * leaving the app to guess what a null means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('department');
            $table->index('status', 'employees_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('employees_status_index');
            $table->dropColumn('status');
        });
    }
};
