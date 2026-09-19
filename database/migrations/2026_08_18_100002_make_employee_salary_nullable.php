<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * HR creates and maintains employee records but is not permitted to see or
     * set salaries, so a record has to be able to exist before an authorised
     * role fills the figure in. Null means "not set yet" — storing 0.00 would
     * be indistinguishable from an unpaid employee.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('salary', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('salary', 10, 2)->nullable(false)->change();
        });
    }
};
