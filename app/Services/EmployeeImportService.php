<?php

namespace App\Services;

use App\Models\Employee;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Imports the staff workbook (شؤون الموظفين) into the employees table.
 *
 * One tab per department, each a flat table under a single header row. The
 * fingerprint id is the natural key: a row whose fingerprint already exists is
 * updated rather than inserted, so the file can be re-imported after a
 * correction without duplicating anybody.
 *
 * The workbook is a hand-kept sheet and reads like one — the header says
 * "رقم اليصمة" where it means "رقم البصمة", a tab is titled "حدمات" where it
 * means "خدمات", and another carries three spaces in the middle of its name.
 * Those are corrected here rather than in the file, so re-exporting from the
 * same source keeps working.
 */
class EmployeeImportService
{
    /**
     * Tab title (whitespace-collapsed) => the department to file its people under.
     *
     * Employee departments are free text with their own vocabulary and are
     * deliberately unrelated to the production departments table — مطبعة here
     * is a staff department, not the مطابع production stage, and the two must
     * not be merged. Edit this map to rename a department; a re-import moves
     * everyone because the fingerprint, not the department, is the key.
     *
     * @var array<string, string>
     */
    public const DEPARTMENTS = [
        'مستودع و حدمات' => 'مستودع وخدمات',
        'ادارة' => 'إدارة',
        'مطبعة' => 'مطبعة',
        'ترانسفير فوتو' => 'ترانسفير فوتو',
    ];

    /**
     * Header labels as written in the workbook, mapped to model attributes.
     *
     * Several spellings per field: the sheet's own header is misspelled in
     * places and the correct spelling should keep working when it is fixed.
     *
     * @var array<string, list<string>>
     */
    protected const HEADERS = [
        'fingerprint_id' => ['رقم اليصمة', 'رقم البصمة', 'البصمة', 'رقم بصمة'],
        'name' => ['الاسم', 'الإسم', 'اسم الموظف'],
        'position' => ['مسمى وظيفي', 'المسمى الوظيفي', 'الوظيفة'],
        'start_date' => ['تاريخ مباشرة', 'تاريخ المباشرة', 'تاريخ التعيين'],
        'address' => ['مكان اقامة', 'مكان الاقامة', 'العنوان'],
        'phone' => ['رقم الهاتف', 'الهاتف', 'الجوال'],
    ];

    /**
     * Import a staff workbook.
     *
     * @param  string  $path  Absolute path to an .xlsx/.xls file.
     * @param  bool  $dryRun  Parse and validate without writing.
     * @return array<string, mixed>
     */
    public function import(string $path, bool $dryRun = false): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Staff workbook not found or unreadable: {$path}");
        }

        $parsed = $this->parse($path);

        $result = [
            'file' => basename($path),
            'dry_run' => $dryRun,
            'sheets' => $parsed['sheets'],
            'skipped_sheets' => $parsed['skipped_sheets'],
            'data_rows' => count($parsed['valid']) + count($parsed['invalid']),
            'created' => 0,
            'updated' => 0,
            'rejected' => count($parsed['invalid']),
            'errors' => $parsed['invalid'],
        ];

        if ($parsed['valid'] === []) {
            return $result;
        }

        if ($dryRun) {
            // Predict the split rather than calling everything new, so the
            // preview says exactly which existing staff a real run would
            // overwrite.
            $existing = Employee::query()
                ->whereIn('fingerprint_id', array_column(array_column($parsed['valid'], 'attributes'), 'fingerprint_id'))
                ->pluck('fingerprint_id')
                ->flip();

            foreach ($parsed['valid'] as $row) {
                $existing->has($row['attributes']['fingerprint_id'])
                    ? $result['updated']++
                    : $result['created']++;
            }

            return $result;
        }

        DB::transaction(function () use ($parsed, &$result): void {
            foreach ($parsed['valid'] as $row) {
                $employee = Employee::updateOrCreate(
                    ['fingerprint_id' => $row['attributes']['fingerprint_id']],
                    $row['attributes'],
                );

                $employee->wasRecentlyCreated ? $result['created']++ : $result['updated']++;
            }
        });

        return $result;
    }

    /**
     * Walk every sheet and split it into importable rows and rejected ones.
     *
     * @return array{valid: list<array{sheet: string, row: int, attributes: array<string, mixed>}>, invalid: list<array{sheet: string, row: int, name: string, reason: string}>, sheets: list<array<string, mixed>>, skipped_sheets: list<string>}
     */
    protected function parse(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not read the workbook: '.$e->getMessage(), 0, $e);
        }

        $valid = [];
        $invalid = [];
        $sheets = [];
        $skippedSheets = [];
        $seen = [];

        foreach ($book->getAllSheets() as $sheet) {
            $title = $this->collapse($sheet->getTitle());

            // An empty tab is skipped on its content, not its name: ورقة5..9
            // are the leftovers of a template and there may be more next time.
            if ($sheet->getHighestDataRow() < 2) {
                $skippedSheets[] = $title;

                continue;
            }

            $map = $this->locateHeader($sheet);

            if ($map === null) {
                $skippedSheets[] = $title.' (no recognisable header row)';

                continue;
            }

            $department = self::DEPARTMENTS[$title] ?? $title;
            $summary = ['sheet' => $title, 'department' => $department, 'rows' => 0, 'rejected' => 0];

            $last = $sheet->getHighestDataRow();
            $cells = $sheet->rangeToArray(
                'A'.($map['row'] + 1).':'.$sheet->getHighestDataColumn().$last,
                null,
                true,
                false,
                false,
            );

            foreach ($cells as $offset => $raw) {
                $number = $map['row'] + 1 + $offset;
                $get = fn (string $field): string => $this->collapse((string) ($raw[$map['map'][$field] ?? -1] ?? ''));

                $fingerprint = $get('fingerprint_id');
                $name = $get('name');

                if ($fingerprint === '' && $name === '') {
                    continue;
                }

                // The fingerprint is the key the upsert turns on, and the name
                // is the record. A row missing either cannot be filed, so it is
                // reported by row number for someone to fill in.
                $reason = match (true) {
                    $fingerprint === '' => 'no fingerprint id (رقم البصمة) — cannot be keyed',
                    $name === '' => 'no name (الاسم)',
                    isset($seen[$fingerprint]) => 'duplicate fingerprint id, already used by '.$seen[$fingerprint],
                    default => null,
                };

                if ($reason !== null) {
                    $invalid[] = ['sheet' => $title, 'row' => $number, 'name' => $name, 'reason' => $reason];
                    $summary['rejected']++;

                    continue;
                }

                $seen[$fingerprint] = $name;

                $valid[] = ['sheet' => $title, 'row' => $number, 'attributes' => [
                    'fingerprint_id' => $fingerprint,
                    'name' => $name,
                    'department' => $department,
                    'position' => $get('position') ?: null,
                    'address' => $get('address') ?: null,
                    'phone' => $this->phone($raw[$map['map']['phone'] ?? -1] ?? null),
                    'start_date' => $this->date($raw[$map['map']['start_date'] ?? -1] ?? null),
                ]];

                $summary['rows']++;
            }

            $sheets[] = $summary;
        }

        return ['valid' => $valid, 'invalid' => $invalid, 'sheets' => $sheets, 'skipped_sheets' => $skippedSheets];
    }

    /**
     * Find the header row and which column index holds each field.
     *
     * @return array{row: int, map: array<string, int>}|null
     */
    protected function locateHeader(Worksheet $sheet): ?array
    {
        $highest = min(5, $sheet->getHighestDataRow());
        $cells = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().$highest, null, true, false, false);

        foreach ($cells as $offset => $raw) {
            $map = [];

            foreach ($raw as $index => $value) {
                $label = $this->fold((string) $value);

                if ($label === '') {
                    continue;
                }

                foreach (self::HEADERS as $field => $aliases) {
                    if (isset($map[$field])) {
                        continue;
                    }

                    foreach ($aliases as $alias) {
                        if ($label === $this->fold($alias)) {
                            $map[$field] = $index;

                            break 2;
                        }
                    }
                }
            }

            // A header row is one that names both the key and the record.
            if (isset($map['fingerprint_id'], $map['name'])) {
                return ['row' => $offset + 1, 'map' => $map];
            }
        }

        return null;
    }

    /**
     * A phone number as the sheet holds it.
     *
     * Excel stores the column as a number, which eats the leading zero every
     * Syrian mobile carries. It is put back so the value is dialable and so two
     * imports of the same person do not differ by one character.
     */
    protected function phone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /**
     * A start date from an Excel serial number or a written date.
     */
    protected function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        try {
            return (new DateTimeImmutable(trim((string) $value)))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Collapse runs of whitespace and trim. The tab "ترانسفير   فوتو" carries
     * three spaces in the middle of its name.
     */
    protected function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Fold a header label for comparison: whitespace collapsed, hamza forms and
     * ta marbuta unified, so "مسمى وظيفي " matches "المسمى الوظيفي".
     */
    protected function fold(string $value): string
    {
        $value = $this->collapse($value);

        $value = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;

        return strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي',
        ]);
    }
}
