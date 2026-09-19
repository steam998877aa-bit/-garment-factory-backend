<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger is filtered by department from the movement screen, and it is the
 * one table that only ever grows — every transfer ever made stays in it.
 *
 * The filter matches either leg of the move, so the two columns are indexed
 * separately rather than as a pair: an OR across two columns cannot use a
 * composite index, but it can use an index merge across two single-column ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_transfers', function (Blueprint $table) {
            $table->index('from_department', 'production_transfers_from_department_index');
            $table->index('to_department', 'production_transfers_to_department_index');
            $table->index('created_at', 'production_transfers_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('production_transfers', function (Blueprint $table) {
            $table->dropIndex('production_transfers_from_department_index');
            $table->dropIndex('production_transfers_to_department_index');
            $table->dropIndex('production_transfers_created_at_index');
        });
    }
};
