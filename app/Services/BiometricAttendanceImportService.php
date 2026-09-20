<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

class BiometricAttendanceImportService
{
    protected const HEADER_ALIASES = [
        'fingerprint_id' => ['موظف', 'بصمة', 'هوية', 'مستخدم', 'fingerprint', 'user', 'employee', 'id', 'no'],
        'date' => ['تاريخ', 'وقت', 'يوم', 'date', 'time', 'day', 'timestamp'],
        'check_in' => ['دخول', 'حضور', 'in', 'entry'],
        'check_out' => ['خروج', 'انصراف', 'out', 'exit'],
        'direction' => ['حركة', 'اتجاه', 'حالة', 'type', 'state', 'direction'],
        'working_hours' => ['ساعات', 'مدة', 'hours', 'duration', 'work'],
    ];

    public function import(string $path, bool $dryRun = false): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("ملف البصمات غير موجود أو لا يمكن قراءته.");
        }

        $grid = $this->readGrid($path);
        $headerIndex = $this->locateHeaderRow($grid);
        $map = null;

        if ($headerIndex !== null) {
            $map = $this->mapColumns($grid[$headerIndex]);
        } else {
            // FALLBACK: If headers are garbled, try to guess by data content
            $map = $this->guessColumns($grid);
            $headerIndex = -1; // Assume data starts from row 0 if no header found
        }

        if (!$map || !isset($map['fingerprint_id'], $map['date'])) {
            throw new RuntimeException("لم يتم التعرف على الأعمدة. يرجى التأكد من أن الملف يحتوي على رقم الموظف والتاريخ.");
        }

        $rows = [];
        $errors = [];
        $unmatched = [];
        $skipped = 0;
        $employees = Employee::query()->pluck('id', 'fingerprint_id');

        foreach ($grid as $index => $cells) {
            if ($index <= $headerIndex) continue;

            $row = $this->projectRow($cells, $map);
            if ($this->isBlank($row)) {
                $skipped++;
                continue;
            }

            $fingerprint = trim((string) ($row['fingerprint_id'] ?? ''));
            if (str_ends_with($fingerprint, '.0')) $fingerprint = substr($fingerprint, 0, -2);

            $dateValue = $row['date'] ?? null;
            $date = $this->normaliseDate($dateValue);

            if ($fingerprint === '' || $date === null) {
                // If we were guessing, maybe we hit a header row or garbage
                if ($headerIndex === -1 && $index < 5) continue;

                $errors[] = ['row' => $index + 1, 'errors' => ['بيانات غير صالحة']];
                continue;
            }

            $eventTime = $this->normaliseTime($dateValue);
            $checkIn = $this->normaliseTime($row['check_in'] ?? null);
            $checkOut = $this->normaliseTime($row['check_out'] ?? null);

            if ($eventTime !== null && (isset($map['direction']) || ($checkIn === null && $checkOut === null))) {
                $direction = $this->normaliseDirection($row['direction'] ?? null);
                $checkIn = $direction === 'out' ? null : $eventTime;
                $checkOut = $direction === 'in' ? null : $eventTime;
            }

            $employeeId = $employees[$fingerprint] ?? null;
            if ($employeeId === null && !in_array($fingerprint, $unmatched, true)) $unmatched[] = $fingerprint;

            $rows[] = [
                'employee_id' => $employeeId,
                'fingerprint_id' => $fingerprint,
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'working_hours' => $this->resolveWorkingHours($row['working_hours'] ?? null, $checkIn, $checkOut),
                'status' => $checkIn !== null ? Attendance::STATUS_PRESENT : Attendance::STATUS_ABSENT,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        $rows = $this->deduplicate($rows);
        $matchedCount = count(array_filter($rows, static fn (array $row): bool => $row['employee_id'] !== null));

        if (!$dryRun) {
            foreach (array_chunk($rows, 500) as $chunk) {
                Attendance::upsert($chunk, ['fingerprint_id', 'date'], ['employee_id', 'check_in', 'check_out', 'working_hours', 'status', 'updated_at']);
            }
        }

        return [
            'file' => basename($path), 'dry_run' => $dryRun, 'parsed' => count($rows), 'imported' => count($rows),
            'matched' => $matchedCount, 'failed' => count($errors), 'skipped_blank' => $skipped,
            'unmatched_fingerprints' => $unmatched, 'errors' => $errors
        ];
    }

    protected function readGrid(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable) {
            throw new RuntimeException("تعذر فتح الملف. يرجى التأكد من أنه ملف Excel أو CSV صالح.");
        }

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $data = $sheet->toArray(null, false, false, true);
            $grid = [];
            foreach ($data as $row) {
                $grid[] = array_map(fn($v) => $v === null ? '' : trim((string)$v), $row);
            }
            if (!empty($grid)) return $grid;
        }
        return [];
    }

    protected function locateHeaderRow(array $grid): ?int
    {
        foreach (array_slice($grid, 0, 20) as $index => $row) {
            $map = $this->mapColumns($row);
            if (isset($map['fingerprint_id'], $map['date'])) return $index;
        }
        return null;
    }

    protected function mapColumns(array $row): array
    {
        $map = [];
        $normRow = [];
        foreach ($row as $letter => $label) $normRow[$letter] = $this->normaliseLabel($label);

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $normAlias = $this->normaliseLabel($alias);
                foreach ($normRow as $letter => $val) {
                    if ($val === '' || isset($map[$field])) continue;
                    if (mb_strpos($val, $normAlias) !== false || mb_strpos($normAlias, $val) !== false) {
                        $map[$field] = $letter;
                    }
                }
            }
        }
        return $map;
    }

    /**
     * If headers are broken, look at the data in the first 10 rows to guess columns.
     */
    protected function guessColumns(array $grid): array
    {
        $map = [];
        $samples = array_slice($grid, 0, 10);

        foreach ($samples as $row) {
            foreach ($row as $letter => $value) {
                if (empty($value)) continue;

                // Guess Date column
                if (!isset($map['date']) && $this->normaliseDate($value) !== null) {
                    $map['date'] = $letter;
                }

                // Guess ID column (Numeric, usually short)
                if (!isset($map['fingerprint_id']) && is_numeric($value) && strlen($value) < 15) {
                    // Check if it's not the same as date
                    if (!isset($map['date']) || $map['date'] !== $letter) {
                        $map['fingerprint_id'] = $letter;
                    }
                }
            }
        }

        return $map;
    }

    protected function normaliseLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = str_replace(['أ', 'إ', 'آ', 'ة', 'ى', 'ئ', 'ؤ'], ['ا', 'ا', 'ا', 'ه', 'ي', 'ي', 'و'], $label);
        $label = preg_replace('/[\x{064B}-\x{0652}]/u', '', $label) ?? $label;
        $label = preg_replace('/[^\p{L}\p{N}]/u', '', $label) ?? $label;
        return $label;
    }

    protected function normaliseDate(mixed $value): ?string
    {
        if (empty($value)) return null;
        $value = $this->normaliseMeridiem((string) $value);

        // Excel Serial
        if (is_numeric($value) && $value > 40000 && $value < 60000) {
            try { return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString(); } catch (Throwable) {}
        }

        try { return Carbon::parse($value)->toDateString(); } catch (Throwable) {
            try {
                $cleaned = preg_replace('/[^\d\/\-\s:]/', '', $value);
                return Carbon::parse($cleaned)->toDateString();
            } catch (Throwable) { return null; }
        }
    }

    protected function normaliseTime(mixed $value): ?string
    {
        if (empty($value)) return null;
        $value = $this->normaliseMeridiem((string) $value);
        if (is_numeric($value)) {
            try {
                $serial = (float) $value;
                $seconds = (int) round(($serial - floor($serial)) * 86400);
                return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
            } catch (Throwable) { return null; }
        }
        try { return Carbon::parse($value)->format('H:i:s'); } catch (Throwable) { return null; }
    }

    protected function normaliseMeridiem(string $value): string
    {
        return trim(str_replace(
            ['ص', 'م', 'ص.', 'م.', 'صباحًا', 'مساءً', 'صباحا', 'مساءا'],
            ['AM', 'PM', 'AM', 'PM', 'AM', 'PM', 'AM', 'PM'],
            mb_strtoupper($value)
        ));
    }

    protected function normaliseDirection(mixed $value): ?string
    {
        $v = $this->normaliseLabel((string)$value);
        if (mb_strpos($v, 'دخول') !== false || $v === 'in') return 'in';
        if (mb_strpos($v, 'خروج') !== false || $v === 'out') return 'out';
        return null;
    }

    protected function resolveWorkingHours(mixed $value, ?string $in, ?string $out): ?float
    {
        if (is_numeric($value)) return round((float) $value, 2);
        if (!$in || !$out) return null;
        try {
            $cIn = Carbon::createFromFormat('H:i:s', $in);
            $cOut = Carbon::createFromFormat('H:i:s', $out);
            if ($cOut->lessThan($cIn)) $cOut->addDay();
            return round($cIn->diffInMinutes($cOut) / 60, 2);
        } catch (Throwable) { return null; }
    }

    protected function deduplicate(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $key = $row['fingerprint_id'].'|'.$row['date'];
            if (!isset($keyed[$key])) { $keyed[$key] = $row; continue; }
            $curr = $keyed[$key];
            $curr['check_in'] = $this->earlierTime($curr['check_in'], $row['check_in']);
            $curr['check_out'] = $this->laterTime($curr['check_out'], $row['check_out']);
            $curr['working_hours'] = $this->resolveWorkingHours(null, $curr['check_in'], $curr['check_out']);
            $keyed[$key] = $curr;
        }
        return array_values($keyed);
    }

    protected function earlierTime(?string $f, ?string $s): ?string { return ($f === null) ? $s : (($s === null) ? $f : ($s < $f ? $s : $f)); }
    protected function laterTime(?string $f, ?string $s): ?string { return ($f === null) ? $s : (($s === null) ? $f : ($s > $f ? $s : $f)); }
    protected function projectRow(array $cells, array $map): array { $row = []; foreach ($map as $field => $letter) $row[$field] = $cells[$letter] ?? null; return $row; }
    protected function isBlank(array $row): bool { foreach ($row as $v) if ($v !== null && trim((string)$v) !== '') return false; return true; }
}
