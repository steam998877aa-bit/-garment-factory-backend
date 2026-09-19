<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A production row is a batch line, not a catalogue entry.
 *
 * The monthly workbook proves a barcode is not a row key: the same product, in
 * the same department, in the same month, legitimately appears several times as
 * separate batches with different quantities and different workshops. The
 * unique indexes on (barcode, department) and (item_number, department) would
 * collapse 33 of those lines, so they are replaced with plain indexes and the
 * row's identity moves to where it actually lives — its origin in the source
 * worksheet, which is what makes a re-import update rather than duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropUnique('productions_barcode_department_unique');
            $table->dropUnique('productions_item_number_department_unique');

            $table->index(['barcode', 'department'], 'productions_barcode_department_index');
            $table->index(['item_number', 'department'], 'productions_item_number_department_index');

            // Where this row came from. Null for rows created through the API.
            $table->string('source_sheet')->nullable()->after('split_from_id');
            $table->unsignedInteger('source_row')->nullable()->after('source_sheet');
            $table->unique(['source_sheet', 'source_row'], 'productions_source_unique');

            // The product line a row belongs to. البيزك is tracked separately
            // from general production, and the line is independent of the
            // department, which records the stage the batch has reached.
            $table->string('product_line')->nullable()->after('department');
            $table->index('product_line', 'productions_product_line_index');
        });
    }

    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->dropIndex('productions_product_line_index');
            $table->dropColumn('product_line');

            $table->dropUnique('productions_source_unique');
            $table->dropColumn(['source_sheet', 'source_row']);

            $table->dropIndex('productions_barcode_department_index');
            $table->dropIndex('productions_item_number_department_index');

            $table->unique(['barcode', 'department'], 'productions_barcode_department_unique');
            $table->unique(['item_number', 'department'], 'productions_item_number_department_unique');
        });
    }
};
