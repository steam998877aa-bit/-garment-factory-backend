<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            // Nullable and nulled on delete: a biometric device reports finger-
            // print ids, not employees. A punch for an unknown or since-deleted
            // employee is still a real event and must not be discarded.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('fingerprint_id')->index();
            $table->date('date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->decimal('working_hours', 5, 2)->nullable();
            $table->timestamps();

            // One row per person per day — also the key the importer upserts on.
            $table->unique(['fingerprint_id', 'date']);

            $table->index(['employee_id', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
