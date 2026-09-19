<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A payroll row is a monthly statement: the attendance counts it was
     * derived from, the salary at the time, and the adjustments applied. The
     * figures are snapshotted rather than recomputed on read, so a finalised
     * month does not silently change when an old attendance row is corrected.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();

            // Restrict: a paid month must not vanish because a staff record was
            // removed. Delete the payroll rows deliberately first.
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->decimal('base_salary', 10, 2)->default(0);
            $table->unsignedSmallInteger('present_days')->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('leave_days')->default(0);
            $table->decimal('working_hours', 8, 2)->default(0);

            $table->decimal('absence_deduction', 10, 2)->default(0);
            $table->decimal('other_deductions', 10, 2)->default(0);
            $table->decimal('bonuses', 10, 2)->default(0);
            $table->decimal('net_salary', 10, 2)->default(0);

            $table->string('status', 20)->default('draft')->index();
            $table->text('notes')->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One statement per employee per month.
            $table->unique(['employee_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
