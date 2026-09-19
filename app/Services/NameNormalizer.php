<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Workshop;
use Illuminate\Support\Collection;

/**
 * Maps a typed department or workshop name onto its canonical spelling.
 *
 * Arabic makes this necessary rather than merely tidy: خياطة and خياطه are the
 * same word with a different final letter, امبلاج and أمبلاج differ only by a
 * hamza, and either pair will silently split a production total in two. Names
 * are matched against the reference tables — canonical name first, then the
 * alias list, then a folded comparison that ignores hamza forms, ta marbuta,
 * diacritics and tatweel.
 */
class NameNormalizer
{
    /**
     * Cached lookup per request: folded form => canonical name.
     *
     * @var array<string, array<string, string>>
     */
    protected array $lookups = [];

    /**
     * Canonical department name, or null when nothing matches.
     */
    public function department(?string $input): ?string
    {
        return $this->resolve('departments', $input);
    }

    /**
     * Canonical workshop name, or null when nothing matches.
     */
    public function workshop(?string $input): ?string
    {
        return $this->resolve('workshops', $input);
    }

    /**
     * Every active canonical department name.
     *
     * @return list<string>
     */
    public function departmentNames(): array
    {
        return Department::query()->where('is_active', true)
            ->orderBy('position')->orderBy('name')
            ->pluck('name')->all();
    }

    /**
     * Every active canonical workshop name.
     *
     * @return list<string>
     */
    public function workshopNames(): array
    {
        return Workshop::query()->where('is_active', true)
            ->orderBy('name')->pluck('name')->all();
    }

    /**
     * Fold a name down to a comparable form.
     *
     * Strips diacritics and tatweel, unifies the alef and ya families, turns
     * ta marbuta into ha, and collapses whitespace and case.
     */
    public function fold(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;

        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي', 'ئ' => 'ي',
            'ؤ' => 'و',
        ]);

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    /**
     * Match an input against one reference table.
     */
    protected function resolve(string $table, ?string $input): ?string
    {
        $folded = $this->fold($input);

        if ($folded === '') {
            return null;
        }

        return $this->lookup($table)[$folded] ?? null;
    }

    /**
     * Build (once per request) the folded => canonical map for a table.
     *
     * @return array<string, string>
     */
    protected function lookup(string $table): array
    {
        if (isset($this->lookups[$table])) {
            return $this->lookups[$table];
        }

        /** @var Collection<int, Department|Workshop> $rows */
        $rows = $table === 'departments'
            ? Department::query()->where('is_active', true)->get()
            : Workshop::query()->where('is_active', true)->get();

        $map = [];

        foreach ($rows as $row) {
            $map[$this->fold($row->name)] = $row->name;

            foreach ($row->aliases ?? [] as $alias) {
                $folded = $this->fold($alias);

                // A canonical name always wins over another row's alias.
                if ($folded !== '' && ! isset($map[$folded])) {
                    $map[$folded] = $row->name;
                }
            }
        }

        return $this->lookups[$table] = $map;
    }
}
