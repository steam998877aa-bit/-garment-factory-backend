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
     * Two changes that belong together.
     *
     * production_date gives reporting a real date to bucket on. Until now
     * statistics fell back to created_at — the moment a row entered the system,
     * which for a worksheet import is the same instant for every row.
     *
     * Uniqueness moves from barcode alone to (barcode, department). A partial
     * transfer now splits a batch, so the same garment legitimately exists in
     * two departments at once; a global unique barcode would forbid that. The
     * product is still unique per location, which is what the constraint is
     * actually protecting.
     */
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->date('production_date')->nullable()->after('month')->index();

            // A split row points back at the batch it came from.
            $table->foreignId('split_from_id')->nullable()->after('serial_number')
                ->constrained('productions')->nullOnDelete();
        });

        Schema::table('productions', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->dropUnique(['item_number']);
        });

        Schema::table('productions', function (Blueprint $table) {
            $table->unique(['barcode', 'department'], 'productions_barcode_department_unique');
            $table->unique(['item_number', 'department'], 'productions_item_number_department_unique');
        });

        // Seed the new column from the row's month where a year can be inferred,
        // so existing rows are not left unreportable.
        DB::statement("
            update productions
            set production_date = date(created_at)
            where production_date is null
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropUnique('productions_barcode_department_unique');
            $table->dropUnique('productions_item_number_department_unique');
        });

        // Only restorable when no batch has been split.
        Schema::table('productions', function (Blueprint $table) {
            $table->unique('barcode');
            $table->unique('item_number');
        });

        Schema::table('productions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('split_from_id');
            $table->dropIndex(['production_date']);
            $table->dropColumn('production_date');
        });
    }
};
