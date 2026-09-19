<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Departments and workshops were free text, so "خياطة" and "خياطه" split a
     * total in two without anyone noticing. These tables hold the canonical
     * name plus every spelling seen in the wild; writes are normalised against
     * them and unknown names are rejected.
     *
     * The existing string columns stay as they are — they now hold canonical
     * values rather than whatever was typed. That keeps the reporting queries
     * (which group by these columns) working unchanged, without a foreign key
     * on five different tables.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('aliases')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('workshops', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('aliases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workshops');
        Schema::dropIfExists('departments');
    }
};
