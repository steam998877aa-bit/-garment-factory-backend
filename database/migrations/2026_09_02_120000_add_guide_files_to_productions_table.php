<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product may carry several guide files, not one.
 *
 * The routing sheet, the technical pack and the photographed marker are
 * separate documents that describe the same batch, and the floor was already
 * losing the first one every time a second was uploaded — storeGuideFile()
 * deleted whatever was there before writing.
 *
 * `images` already models exactly this, so guide files follow it: one json
 * column of paths, appended to on upload and emptied only by an explicit
 * removal. The old single-value column is folded into the new array so nothing
 * on disk is orphaned, then dropped, leaving one source of truth rather than
 * two columns that can disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->json('guide_files')->nullable()->after('images');
        });

        // Fold the single file each row may already hold into the array.
        DB::table('productions')
            ->whereNotNull('guide_file')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('productions')
                        ->where('id', $row->id)
                        ->update(['guide_files' => json_encode([$row->guide_file])]);
                }
            });

        Schema::table('productions', function (Blueprint $table) {
            $table->dropColumn('guide_file');
        });
    }

    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->string('guide_file')->nullable()->after('images');
        });

        // Only the first file survives the rollback — the column holds one.
        DB::table('productions')
            ->whereNotNull('guide_files')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $paths = json_decode((string) $row->guide_files, true) ?: [];

                    DB::table('productions')
                        ->where('id', $row->id)
                        ->update(['guide_file' => $paths[0] ?? null]);
                }
            });

        Schema::table('productions', function (Blueprint $table) {
            $table->dropColumn('guide_files');
        });
    }
};
