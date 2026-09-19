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
     * The product form treats sizes and colours as lists, and the worksheet
     * already stored them that way inside a single string ("s..m..l"), so they
     * become json arrays. Sizes have a reliable delimiter and are split on it;
     * colours do not ("فحمي و اصفر", "بيج 16+36+35+34+16x"), so each existing
     * value is preserved verbatim as a single entry rather than guessed apart.
     */
    public function up(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->json('sizes')->nullable()->after('quantity');
            $table->json('colors')->nullable()->after('sizes');
            $table->json('images')->nullable()->after('notes');
            $table->string('guide_file')->nullable()->after('images');
        });

        foreach (DB::table('productions')->select('id', 'size', 'color')->cursor() as $row) {
            DB::table('productions')->where('id', $row->id)->update([
                'sizes' => json_encode($this->splitSizes($row->size), JSON_UNESCAPED_UNICODE),
                'colors' => json_encode(
                    trim((string) $row->color) === '' ? [] : [trim((string) $row->color)],
                    JSON_UNESCAPED_UNICODE,
                ),
            ]);
        }

        Schema::table('productions', function (Blueprint $table) {
            $table->dropColumn(['size', 'color']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productions', function (Blueprint $table) {
            $table->string('size')->default('')->after('quantity');
            $table->string('color')->default('')->after('size');
        });

        foreach (DB::table('productions')->select('id', 'sizes', 'colors')->cursor() as $row) {
            DB::table('productions')->where('id', $row->id)->update([
                'size' => implode('..', json_decode((string) $row->sizes, true) ?: []),
                'color' => implode(', ', json_decode((string) $row->colors, true) ?: []),
            ]);
        }

        Schema::table('productions', function (Blueprint $table) {
            $table->dropColumn(['sizes', 'colors', 'images', 'guide_file']);
        });
    }

    /**
     * Split a worksheet size string such as "s..m..l" into its parts.
     *
     * @return list<string>
     */
    protected function splitSizes(?string $value): array
    {
        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[.,\/\s]+/u', $value) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }
};
