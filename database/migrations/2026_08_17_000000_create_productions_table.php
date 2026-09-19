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
        Schema::create('productions', function (Blueprint $table) {
            $table->id();
            $table->string('model_name');
            $table->string('barcode')->unique();
            $table->integer('item_number');
            $table->integer('month');
            $table->integer('quantity');
            $table->string('size');
            $table->string('color');
            $table->string('fabric');
            $table->string('design_status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productions');
    }
};
