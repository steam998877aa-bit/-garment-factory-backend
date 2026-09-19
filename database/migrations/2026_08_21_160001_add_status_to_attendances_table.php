<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A day needs a status of its own: "no check-in" can mean absent, on leave,
     * or a public holiday, and payroll treats those very differently. Existing
     * rows are classified from whether a check-in was recorded.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('status', 20)->default('present')->after('working_hours')->index();
            $table->string('notes')->nullable()->after('status');
        });

        DB::table('attendances')->whereNull('check_in')->update(['status' => 'absent']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'notes']);
        });
    }
};
