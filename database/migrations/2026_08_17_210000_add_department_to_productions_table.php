<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The production worksheet groups rows under department/stage headings
     * (cutting, printing, sewing, packaging), so an imported row carries the
     * department of the section it appeared under.
     */
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->string('department')->nullable()->after('item_number')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropIndex(['department']);
            $table->dropColumn('department');
        });
    }
};
