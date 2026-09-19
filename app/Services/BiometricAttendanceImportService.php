<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * Imports an attendance export from the biometric application.
 *
 * Biometric packages differ in what they call their columns and in how they
 * encode dates and times, so the header row is matched against a list of
 * aliases rather than a fixed layout, and every value is normalised from either
 * an Excel serial number or a plain string.
 *
 * Rows whose fingerprint id matches no employee are still imported, with a null
 * employee_id — a punch from an unregistered finger is a real event and losing
 * it would silently under-report attendance. The unmatched ids are reported
 * back so they can be registered.
 */
class BiometricAttendanceImportService
{
    /**
     * Accepted header labels for each field, normalised to lowercase.
     *
     * @var array<string, list<string>>
     */
    protected const HEADER_ALIASES = [
        'fingerprint_id' => [
            'fingerprint id', 'fingerprint', 'finger print', 'fingerprintid',
            'finger id', 'finger no', 'finger number', 'user id', 'userid', 'user',
            'user no', 'user number', 'employee id', 'employeeid', 'emp id',
            'emp no', 'employee no', 'id', 'no', 'ac no', 'enroll id', 'enrollid',
            'رقم البصمة', 'البصمة', 'رقم الموظف', 'رقم المستخدم', 'الرقم الوظيفي', 'الرقم', 'رقم',
        ],
        'date' => [
            'date', 'day', 'attendance date', 'work date', 'date time', 'datetime',
            'timestamp', 'التاريخ والوقت', 'تاريخ ووقت', 'التاريخ', 'تاريخ', 'اليوم',
        ],
        'check_in' => [
            'check in', 'checkin', 'in', 'time in', 'timein',
            'clock in', 'first in', 'entry', 'sign in',
            'الدخول', 'وقت الدخول', 'الحضور', 'حضور', 'دخول',
        ],
        'check_out' => [
            'check out', 'checkout', 'out', 'time out', 'timeout',
            'clock out', 'last out', 'exit', 'sign out',
            'الخروج', 'وقت الخروج', 'الانصراف', 'انصراف', 'خروج',
        ],
        'direction' => [
            'direction', 'type', 'status', 'state', 'in out', 'in/out',
            'entry exit', 'movement', 'الاتجاه', 'الحركة', 'نوع الحركة', 'الحالة',
        ],
        'working_hours' => [
            'working hours', 'work hours', 'hours', 'total hours',
            'total', 'duration', 'worked',
            'ساعات العمل', 'الساعات', 'ساعات', 'المدة',
        ],
    ];

    /**
     * Import an uploaded biometric export.
     *
     * @param  string  $path  Absolute path to an .xlsx/.xls/.csv file.
     * @param  bool  $dryRun  Parse and validate without writing.
     * @return array<string, mixed>
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Attendance file not found or unreadable: {$path}");
        }

        $grid = $this->readGrid($path);
        $headerIndex = $this->locateHeaderRow($grid);

        if ($headerIndex === null) {
            throw new RuntimeException(
                'Could not find a header row. The file needs a fingerprint id column and a date column.'
            );
        }

        $map = $this->mapColumns($grid[$headerIndex]);

        $rows = [];
        $errors = [];
        $unmatched = [];
        $skipped = 0;

        $employees = Employee::query()->pluck('id', 'fingerprint_id');

        foreach ($grid as $index => $cells) {
            if ($index <= $headerIndex) {
                continue;
            }

            $rowNumber = $index + 1;
            $row = $this->projectRow($cells, $map);

            if ($this->isBlank($row)) {
                $skipped++;

                continue;
            }

            $fingerprint = trim((string) ($row['fingerprint_id'] ?? ''));
            $date = $this->normaliseDate($row['date'] ?? null);

            if ($fingerprint === '' || $date === null) {
                $errors[] = [
                    'row' => $rowNumber,
                    'fingerprint_id' => $fingerprint !== '' ? $fingerprint : null,
                    'errors' => array_values(array_filter([
                        $fingerprint === '' ? 'Missing fingerprint id.' : null,
                        $date === null ? 'Missing or unreadable date.' : null,
                    ])),
                ];

                continue;
            }

            $eventTime = $this->normaliseTime($row['date'] ?? null);
            $checkIn = $this->normaliseTime($row['check_in'] ?? null);
            $checkOut = $this->normaliseTime($row['check_out'] ?? null);

            // Some biometric exports are movement logs: one date/time column
            // plus a direction column instead of separate in/out columns.
            if ($eventTime !== null && (isset($map['direction']) || ($checkIn === null && $checkOut === null))) {
                $direction = $this->normaliseDirection($row['direction'] ?? null);
                $checkIn = $direction === 'out' ? null : $eventTime;
                $checkOut = $direction === 'in' ? null : $eventTime;
            }
            $employeeId = $employees[$fingerprint] ?? null;

            if ($employeeId === null && ! in_array($fingerprint, $unmatched, true)) {
                $unmatched[] = $fingerprint;
            }

            $rows[] = [
                'employee_id' => $employeeId,
                'fingerprint_id' => $fingerprint,
                'date' => $date,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'working_hours' => $this->resolveWorkingHours(
                    $row['working_hours'] ?? null,
                    $checkIn,
                    $checkOut,
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // The same person and day can appear twice in one export; the last
        // occurrence wins, otherwise the upsert trips on duplicate keys.
        $rows = $this->deduplicate($rows);

        $result = [
            'file' => basename($path),
            'dry_run' => $dryRun,
            'parsed' => count($rows),
            'imported' => 0,
            'failed' => count($errors),
            'skipped_blank' => $skipped,
            'matched' => count(array_filter($rows, static fn (array $row): bool => $row['employee_id'] !== null)),
            'unmatched_fingerprints' => array_values($unmatched),
            'errors' => $errors,
        ];

        if ($dryRun) {
            $result['imported'] = count($rows);

            return $result;
        }

        // Chunked upsert keyed on (fingerprint_id, date): re-importing an
        // overlapping export refreshes those days rather than duplicating them.
        foreach (array_chunk($rows, 500) as $chunk) {
            Attendance::upsert(
                $chunk,
                ['fingerprint_id', 'date'],
                ['employee_id', 'check_in', 'check_out', 'working_hours', 'updated_at'],
            );

            $result['imported'] += count($chunk);
        }

        return $result;
    }

    /**
     * Read the sheet into a zero-indexed grid of column-letter keyed rows.
     *
     * @return list<array<string, string>>
     */
    protected function readGrid(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        // PhpSpreadsheet only infers a CSV delimiter heuristically and gets it
        // wrong often enough to put a whole row in one cell, so pick it here.
        if ($reader instanceof CsvReader) {
            $reader->setDelimiter($this->detectDelimiter($path));
        }

        $sheet = $reader->load($path)->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        $raw = $sheet->rangeToArray("A1:{$highestColumn}{$highestRow}", null, true, false);

        $grid = [];

        foreach ($raw as $cells) {
            $row = [];

            foreach (array_values($cells) as $position => $value) {
                $letter = Coordinate::stringFromColumnIndex($position + 1);
                $row[$letter] = $value === null ? '' : trim((string) $value);
            }

            $grid[] = $row;
        }

        return $grid;
    }

    /**
     * Pick the delimiter that splits a CSV into the most columns.
     */
    protected function detectDelimiter(string $path): string
    {
        $candidates = ["," => 0, ";" => 0, "	" => 0, "|" => 0];

        $handle = fopen($path, "r");

        if ($handle === false) {
            return ",";
        }

        $inspected = 0;

        while ($inspected < 5 && ($line = fgets($handle)) !== false) {
            $line = trim($line);

            if ($line === "") {
                continue;
            }

            foreach ($candidates as $delimiter => $count) {
                $candidates[$delimiter] = $count + substr_count($line, $delimiter);
            }

            $inspected++;
        }

        fclose($handle);

        arsort($candidates);
        $best = array_key_first($candidates);

        return $candidates[$best] > 0 ? $best : ",";
    }

    /**
     * Find the first row that looks like a header.
     *
     * Exports often carry a title banner or device metadata above the table, so
     * the header is located by content rather than assumed to be row 1.
     *
     * @param  list<array<string, string>>  $grid
     */
    protected function locateHeaderRow(array $grid): ?int
    {
        foreach ($grid as $index => $row) {
            $map = $this->mapColumns($row);

            if (isset($map['fingerprint_id'], $map['date'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Match a header row's labels to fields.
     *
     * @param  array<string, string>  $row
     * @return array<string, string> field => column letter
     */
    protected function mapColumns(array $row): array
    {
        $map = [];

        foreach ($row as $letter => $label) {
            $normalised = $this->normaliseLabel($label);

            if ($normalised === '') {
                continue;
            }

            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }

                if (in_array($normalised, $aliases, true)) {
                    $map[$field] = $letter;

                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Fold a header label down to a comparable form.
     */
    protected function normaliseLabel(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = preg_replace('/[\x{FEFF}\x{200B}-\x{200D}]/u', '', $label) ?? $label;
        $label = str_replace(['_', '-', '.', "\u{00A0}"], ' ', $label);

        return trim(preg_replace('/\s+/u', ' ', $label) ?? '');
    }

    /**
     * Convert common device direction values to a stable value.
     */
    protected function normaliseDirection(mixed $value): ?string
    {
        $value = $this->normaliseLabel((string) $value);

        if (in_array($value, ['i', 'in', 'entry', 'enter', 'check in', 'دخول', 'داخل'], true)) {
            return 'in';
        }

        if (in_array($value, ['o', 'out', 'exit', 'leave', 'check out', 'خروج', 'خارج'], true)) {
            return 'out';
        }

        return null;
    }

    /**
     * @param  array<string, string>  $cells
     * @param  array<string, string>  $map
     * @return array<string, string|null>
     */
    protected function projectRow(array $cells, array $map): array
    {
        $row = [];

        foreach ($map as $field => $letter) {
            $row[$field] = $cells[$letter] ?? null;
        }

        return $row;
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalise a date cell, which may be an Excel serial or a written date.
     */
    protected function normaliseDate(mixed $value): ?string
    {
        $value = $this->normaliseMeridiem((string) $value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        // Try unambiguous and common written formats before falling back, so a
        // date like 03/04/2026 is not silently read as the wrong month.
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->toDateString();
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalise a time cell, which may be an Excel fraction of a day or a string.
     */
    protected function normaliseTime(mixed $value): ?string
    {
        $value = $this->normaliseMeridiem((string) $value);

        if ($value === '' || $value === '0') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            // Excel stores a time as a fraction of a day; a whole-number part is
            // the date portion and is discarded here.
            $seconds = (int) round(($serial - floor($serial)) * 86400);

            return sprintf(
                '%02d:%02d:%02d',
                intdiv($seconds, 3600),
                intdiv($seconds % 3600, 60),
                $seconds % 60,
            );
        }

        try {
            return Carbon::parse($value)->format('H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    protected function normaliseMeridiem(string $value): string
    {
        return trim(str_replace(
            ['ص', 'م', 'صباحًا', 'مساءً', 'صباحا', 'مساءا'],
            ['AM', 'PM', 'AM', 'PM', 'AM', 'PM'],
            $value,
        ));
    }

    /**
     * Use the file's working hours when present, otherwise derive them.
     */
    protected function resolveWorkingHours(mixed $value, ?string $checkIn, ?string $checkOut): ?float
    {
        $value = trim((string) $value);

        if ($value !== '') {
            if (is_numeric($value)) {
                return round((float) $value, 2);
            }

            // "8:30" meaning eight and a half hours.
            if (preg_match('/^(\d{1,3}):([0-5]\d)(?::([0-5]\d))?$/', $value, $matches) === 1) {
                return round(
                    (int) $matches[1]
                    + ((int) $matches[2] / 60)
                    + ((int) ($matches[3] ?? 0) / 3600),
                    2,
                );
            }
        }

        if ($checkIn === null || $checkOut === null) {
            return null;
        }

        $in = Carbon::createFromFormat('H:i:s', $checkIn);
        $out = Carbon::createFromFormat('H:i:s', $checkOut);

        // An out time earlier than the in time means the shift crossed midnight.
        if ($out->lessThan($in)) {
            $out->addDay();
        }

        return round($in->diffInMinutes($out) / 60, 2);
    }

    /**
     * Merge movement events into one row for each fingerprint and date.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function deduplicate(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $key = $row['fingerprint_id'].'|'.$row['date'];

            if (! isset($keyed[$key])) {
                $keyed[$key] = $row;

                continue;
            }

            $current = $keyed[$key];
            $current['check_in'] = $this->earlierTime($current['check_in'], $row['check_in']);
            $current['check_out'] = $this->laterTime($current['check_out'], $row['check_out']);
            $current['working_hours'] = $this->resolveWorkingHours(
                null,
                $current['check_in'],
                $current['check_out'],
            );
            $current['updated_at'] = $row['updated_at'];
            $keyed[$key] = $current;
        }

        return array_values($keyed);
    }

    protected function earlierTime(?string $first, ?string $second): ?string
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return $second < $first ? $second : $first;
    }

    protected function laterTime(?string $first, ?string $second): ?string
    {
        if ($first === null) {
            return $second;
        }

        if ($second === null) {
            return $first;
        }

        return $second > $first ? $second : $first;
    }
}
