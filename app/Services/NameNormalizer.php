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
        if ($input === null || trim($input) === '') {
            return null;
        }

        $direct = $this->resolve('departments', $input);

        if ($direct !== null) {
            return $direct;
        }

        $parsed = $this->parseComposite($input);

        if ($parsed['department'] !== null) {
            return $parsed['department'];
        }

        return null;
    }

    /**
     * Canonical workshop name, or null when nothing matches.
     */
    public function workshop(?string $input): ?string
    {
        return $this->resolve('workshops', $input);
    }

    /**
     * Resolve a composite input like "خياطة <مصطفى>" or "بيزك - خياطة <صالح>"
     * into its constituent department, workshop, and product line.
     *
     * @return array{department: ?string, workshop: ?string, product_line: ?string}
     */
    public function parseComposite(?string $input): array
    {
        if ($input === null || trim($input) === '') {
            return ['department' => null, 'workshop' => null, 'product_line' => null];
        }

        $input = html_entity_decode(trim($input), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $input = strtr($input, [
            '‹' => '<', '›' => '>',
            '«' => '<', '»' => '>',
            '＜' => '<', '＞' => '>',
            '⟨' => '<', '⟩' => '>',
            '[' => '(', ']' => ')',
            '{' => '(', '}' => ')',
        ]);

        $line = null;
        $workshop = null;
        $department = null;
        $rest = $input;

        // 1. Extract workshop from brackets: <workshop> or (workshop)
        if (preg_match('/^(.*?)\s*(?:\(([^)]+)\)|<([^>]+)>)\s*$/u', $rest, $matches) === 1) {
            $workshopCandidate = trim(($matches[2] ?? '') !== '' ? $matches[2] : ($matches[3] ?? ''));
            $workshopResolved = $this->resolve('workshops', $workshopCandidate);
            $workshop = $workshopResolved ?? ($workshopCandidate !== '' ? $workshopCandidate : null);
            $rest = trim($matches[1], " \t\n\r\0\x0B-–—:/,;");
        }

        // 2. Dash separation: "Left - Right"
        if ($rest !== '' && preg_match('/^(.+?)\s*[-–—]\s*(.+)$/u', $rest, $matches) === 1) {
            $left = trim($matches[1], " \t\n\r\0\x0B-–—:/,;");
            $right = trim($matches[2], " \t\n\r\0\x0B-–—:/,;");

            $leftDept = $this->resolve('departments', $left);
            $rightDept = $this->resolve('departments', $right);
            $rightWorkshop = $this->resolve('workshops', $right);

            if ($leftDept !== null && $rightWorkshop !== null) {
                // e.g. "خياطة - مصطفى" -> dept = "خياطة", workshop = "مصطفى"
                $department = $leftDept;
                $workshop = $workshop ?? $rightWorkshop;
                $rest = '';
            } elseif ($leftDept !== null && $rightDept !== null) {
                // e.g. "بيزك - خياطة" -> line = "البيزك", dept = "خياطة"
                $line = $leftDept;
                $department = $rightDept;
                $rest = '';
            } elseif ($leftDept !== null) {
                $department = $leftDept;
                $workshop = $workshop ?? ($this->resolve('workshops', $right) ?? ($right !== '' ? $right : null));
                $rest = '';
            } elseif ($rightDept !== null) {
                $department = $rightDept;
                $rest = '';
            }
        }

        // 3. Direct department match on remaining rest
        if ($department === null && $rest !== '') {
            $department = $this->resolve('departments', $rest);
        }

        // 4. Prefix/Suffix matching if department is still null
        if ($department === null && $rest !== '') {
            foreach ($this->departmentNames() as $deptName) {
                $foldedDept = $this->fold($deptName);
                $foldedRest = $this->fold($rest);

                if ($foldedDept !== '' && str_starts_with($foldedRest, $foldedDept)) {
                    $remainder = trim(mb_substr($rest, mb_strlen($deptName)), " \t\n\r\0\x0B-–—:/,;");
                    $resolvedWorkshop = $this->resolve('workshops', $remainder);

                    if ($resolvedWorkshop !== null || $remainder !== '') {
                        $department = $deptName;
                        if ($workshop === null) {
                            $workshop = $resolvedWorkshop ?? ($remainder !== '' ? $remainder : null);
                        }
                        break;
                    }
                }
            }
        }

        return [
            'department' => $department,
            'workshop' => $workshop,
            'product_line' => $line,
        ];
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
        if ($input === null) {
            return null;
        }

        $input = html_entity_decode(trim($input), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
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
