<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * item_number is the worksheet's "رقم" column — the item code identifying a
     * garment in the catalog — so it becomes strictly unique. serial_number is
     * the position of a row within its department list, restarting at 1 for
     * each department and recalculated whenever items move between departments.
     */
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('design_status');

            $table->unsignedInteger('serial_number')->nullable()->after('department');

            $table->unique('item_number');

            // Deliberately a plain index rather than a unique one: renumbering a
            // department list shifts many rows at once and a unique index would
            // trip on transient collisions mid-resequence. Uniqueness within a
            // department is enforced in DepartmentSerialNumberService.
            $table->index(['department', 'serial_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropIndex(['department', 'serial_number']);
            $table->dropUnique(['item_number']);
            $table->dropColumn(['notes', 'serial_number']);
        });
    }
};
