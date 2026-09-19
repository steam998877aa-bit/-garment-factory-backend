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
     * The worksheet's first column is dual purpose. In the opening section it
     * holds a design directive ("الغاء"); inside a department section it holds
     * the workshop the piece was handed to ("مصطفى", "أبو كرم"). Both were
     * imported into design_status, so the workshop names are separated out
     * here by that same structural rule: a row that sits inside a department
     * carries a workshop, a row that does not carries a status.
     */
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->string('workshop')->nullable()->after('department')->index();
        });

        DB::table('productions')
            ->whereNotNull('department')
            ->where('design_status', '<>', '')
            ->update([
                'workshop' => DB::raw('design_status'),
                'design_status' => '',
            ]);

        Schema::table('production_transfers', function (Blueprint $table) {
            $table->string('from_workshop')->nullable()->after('from_department');
            $table->string('to_workshop')->nullable()->after('to_department');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('productions')
            ->whereNotNull('workshop')
            ->update(['design_status' => DB::raw('workshop')]);

        Schema::table('production_transfers', function (Blueprint $table) {
            $table->dropColumn(['from_workshop', 'to_workshop']);
        });

        Schema::table('productions', function (Blueprint $table) {
            $table->dropIndex(['workshop']);
            $table->dropColumn('workshop');
        });
    }
};
