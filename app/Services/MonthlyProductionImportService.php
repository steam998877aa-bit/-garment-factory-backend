<?php

namespace App\Services;

use App\Models\Production;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Imports the monthly production workbook (المنتجات حسب الشهر).
 *
 * This workbook is a different animal from the department worksheet handled by
 * {@see ProductionImportService}: one tab per month plus a standalone البيزك
 * tab, each a plain table with a single header row and no stacked sections,
 * subtotals or banners.
 *
 * Three things about the data drive the design:
 *
 *  - A row is a **batch line**, not a catalogue entry. The same barcode, in the
 *    same department, in the same month appears more than once with different
 *    quantities and different workshops. Rows are therefore keyed on where they
 *    came from — sheet name plus row number — which is what lets a re-import
 *    update in place instead of duplicating.
 *  - The القسم cell carries the workshop for sewing: "خياطة (أبو كرم)" is the
 *    خياطة department, أبو كرم workshop.
 *  - The الشهر cell is not always a number: "شهر 8 مؤجل" means month 8, and the
 *    "مؤجل" qualifier is real information, so it is preserved in the notes
 *    rather than thrown away with the rest of the cell.
 */
class MonthlyProductionImportService
{
    public function __construct(
        protected NameNormalizer $names,
    ) {
    }

    /**
     * Header label => production field. Matched on the folded form, so a
     * spelling with ة instead of ه still lands on the right column.
     *
     * @var array<string, string>
     */
    protected const COLUMN_LABELS = [
        'رقم تسلسلي' => 'serial_number',
        'اسم المنتج' => 'model_name',
        'رقم المنتج' => 'item_number',
        'الباركود' => 'barcode',
        'اللون' => 'colors',
        'المقاسات' => 'sizes',
        'القماش' => 'fabric',
        'العدد' => 'quantity',
        'الشهر' => 'month',
        'القسم' => 'department',
        'الملاحظات' => 'notes',
    ];

    /**
     * Columns without which a row cannot be a production line.
     */
    protected const REQUIRED_COLUMNS = ['model_name', 'barcode', 'item_number', 'quantity', 'department'];

    /**
     * The product line a sheet describes, matched on the folded sheet name.
     */
    protected const BASIC_LINE = 'البيزك';

    protected const GENERAL_LINE = 'عام';

    /**
     * Import every sheet in the workbook.
     *
     * @return array<string, mixed>
     */
    public function import(string $path, bool $dryRun = false, ?string $productionDate = null): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Import file not found or unreadable: {$path}");
        }

        $parsed = $this->parse($path, $productionDate);

        $result = [
            'file' => basename($path),
            'dry_run' => $dryRun,
            'sheets' => $parsed['sheets'],
            'data_rows' => count($parsed['valid']) + count($parsed['invalid']),
            'created' => 0,
            'updated' => 0,
            'failed' => count($parsed['invalid']),
            'skipped' => $parsed['skipped'],
            'departments' => $parsed['departments'],
            'product_lines' => $parsed['product_lines'],
            'pieces' => $parsed['pieces'],
            'errors' => $parsed['invalid'],
        ];

        if ($dryRun) {
            // Predict the split rather than calling everything new. Rows are
            // keyed on their origin in the sheet, so a preview can say exactly
            // which existing rows a real run would overwrite — and a preview
            // that reports 776 creations for a file that would update 776 rows
            // is worse than no preview at all.
            foreach ($parsed['valid'] as $row) {
                $exists = Production::query()
                    ->where('source_sheet', $row['attributes']['source_sheet'])
                    ->where('source_row', $row['attributes']['source_row'])
                    ->exists();

                $exists ? $result['updated']++ : $result['created']++;
            }

            return $result;
        }

        DB::transaction(function () use ($parsed, &$result) {
            foreach ($parsed['valid'] as $row) {
                $attributes = $row['attributes'];

                // Identity is the row's origin, not its barcode: the same
                // product can occupy several lines of the same sheet.
                $production = Production::updateOrCreate(
                    [
                        'source_sheet' => $attributes['source_sheet'],
                        'source_row' => $attributes['source_row'],
                    ],
                    $attributes,
                );

                $production->wasRecentlyCreated ? $result['created']++ : $result['updated']++;
            }
        });

        return $result;
    }

    /**
     * Walk every sheet and split it into valid rows, invalid rows and noise.
     *
     * @return array{valid: list<array{sheet: string, row: int, attributes: array<string, mixed>}>, invalid: list<array{sheet: string, row: int, errors: list<string>}>, skipped: array<string, int>, departments: list<string>, product_lines: array<string, int>, sheets: list<array<string, mixed>>, pieces: int}
     */
    protected function parse(string $path, ?string $productionDate = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $valid = [];
        $invalid = [];
        $skipped = ['blank' => 0, 'header' => 0];
        $departments = [];
        $lines = [];
        $sheets = [];
        $pieces = 0;

        foreach ($book->getAllSheets() as $sheet) {
            $title = trim($sheet->getTitle());
            $line = $this->productLine($title);

            $summary = [
                'sheet' => $title,
                'product_line' => $line,
                'rows' => 0,
                'failed' => 0,
                'pieces' => 0,
            ];

            $columnMap = $this->locateHeader($sheet);

            if ($columnMap === null) {
                $summary['skipped_reason'] = 'no recognisable header row';
                $sheets[] = $summary;

                continue;
            }

            $headerRow = $columnMap['row'];
            $map = $columnMap['map'];
            $skipped['header']++;

            $highest = $sheet->getHighestDataRow();
            $cells = $sheet->rangeToArray(
                'A'.($headerRow + 1).':'.$sheet->getHighestDataColumn().$highest,
                null,
                false,
                false,
                false,
            );

            foreach ($cells as $offset => $raw) {
                $number = $headerRow + 1 + $offset;
                $row = $this->normaliseRow($raw);

                if ($this->isBlank($row)) {
                    $skipped['blank']++;

                    continue;
                }

                $attributes = $this->mapRow($row, $map, $title, $line, $productionDate);

                $validator = Validator::make($attributes, $this->rules());

                if ($validator->fails()) {
                    $invalid[] = [
                        'sheet' => $title,
                        'row' => $number,
                        'errors' => $validator->errors()->all(),
                    ];
                    $summary['failed']++;

                    continue;
                }

                $attributes['source_row'] = $number;

                $valid[] = ['sheet' => $title, 'row' => $number, 'attributes' => $attributes];

                $summary['rows']++;
                $summary['pieces'] += (int) $attributes['quantity'];
                $pieces += (int) $attributes['quantity'];

                if ($attributes['department'] !== null && ! in_array($attributes['department'], $departments, true)) {
                    $departments[] = $attributes['department'];
                }

                $lines[$line] = ($lines[$line] ?? 0) + 1;
            }

            $sheets[] = $summary;
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'skipped' => $skipped,
            'departments' => $departments,
            'product_lines' => $lines,
            'sheets' => $sheets,
            'pieces' => $pieces,
        ];
    }

    /**
     * Validation rules for one batch line.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'model_name' => ['required', 'string', 'max:255'],
            'barcode' => ['required', 'string', 'max:255'],
            'item_number' => ['required', 'integer', 'min:0'],
            'department' => ['required', 'string', 'max:255'],
            'product_line' => ['required', 'string', 'max:255'],
            'workshop' => ['nullable', 'string', 'max:255'],
            'month' => ['required', 'integer', 'between:1,12'],
            'quantity' => ['required', 'integer', 'min:0'],
            'serial_number' => ['nullable', 'integer', 'min:0'],
            'sizes' => ['present', 'array'],
            'sizes.*' => ['string', 'max:50'],
            'colors' => ['present', 'array'],
            'colors.*' => ['string', 'max:100'],
            'fabric' => ['present', 'string', 'max:255'],
            'design_status' => ['present', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'production_date' => ['nullable', 'date_format:Y-m-d'],
            'source_sheet' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Find the header row and build a column-letter => field map.
     *
     * The header is expected on row 1, but it is searched for rather than
     * assumed so a title row above the table does not break the import.
     *
     * @return array{row: int, map: array<string, string>}|null
     */
    protected function locateHeader(Worksheet $sheet): ?array
    {
        $limit = min(10, $sheet->getHighestDataRow());

        for ($number = 1; $number <= $limit; $number++) {
            $row = $this->normaliseRow(
                $sheet->rangeToArray('A'.$number.':'.$sheet->getHighestDataColumn().$number, null, false, false, false)[0] ?? []
            );

            $map = [];

            foreach ($row as $letter => $value) {
                $field = $this->matchLabel($value);

                if ($field !== null && ! in_array($field, $map, true)) {
                    $map[$letter] = $field;
                }
            }

            $found = array_values($map);

            if (count(array_intersect(self::REQUIRED_COLUMNS, $found)) === count(self::REQUIRED_COLUMNS)) {
                return ['row' => $number, 'map' => $map];
            }
        }

        return null;
    }

    /**
     * Resolve a header cell onto a production field, ignoring spelling drift.
     */
    protected function matchLabel(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $folded = $this->names->fold($value);

        foreach (self::COLUMN_LABELS as $label => $field) {
            if ($this->names->fold($label) === $folded) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Turn one spreadsheet row into the attributes of a batch line.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    protected function mapRow(array $row, array $map, string $sheet, string $line, ?string $productionDate): array
    {
        $raw = [];

        foreach ($map as $letter => $field) {
            $raw[$field] = $row[$letter] ?? '';
        }

        [$department, $workshop, $cellLine] = $this->splitDepartment($raw['department'] ?? '');
        [$month, $qualifier] = $this->splitMonth($raw['month'] ?? '');

        $notes = trim((string) ($raw['notes'] ?? ''));

        // "شهر 8 مؤجل" carries a status the month number cannot hold; keeping
        // it in the notes means nothing on the sheet is silently dropped.
        if ($qualifier !== null) {
            $notes = $notes === '' ? $qualifier : $qualifier.' — '.$notes;
        }

        // Sewing rows name their workshop in the department cell; everywhere
        // else the notes column is where the workshop is written, so it is
        // read from there only when the note is exactly a known workshop.
        $workshop ??= $this->workshopFromNotes($notes);

        return [
            'model_name' => trim((string) ($raw['model_name'] ?? '')),
            'barcode' => trim((string) ($raw['barcode'] ?? '')),
            'item_number' => $this->toInt($raw['item_number'] ?? null),
            'department' => $department,
            // A line named in the cell wins over the one inferred from the tab,
            // so a بيزك row filed on a general sheet is still counted as Basic.
            'product_line' => $cellLine ?? $line,
            'workshop' => $workshop,
            'month' => $month,
            'quantity' => $this->toInt($raw['quantity'] ?? null),
            'serial_number' => $this->toInt($raw['serial_number'] ?? null),
            'sizes' => $this->splitSizes((string) ($raw['sizes'] ?? '')),
            'colors' => $this->splitColors((string) ($raw['colors'] ?? '')),
            'fabric' => trim((string) ($raw['fabric'] ?? '')),
            'design_status' => '',
            'notes' => $notes === '' ? null : $notes,
            'production_date' => $productionDate,
            'source_sheet' => $sheet,
            'source_row' => 0,
        ];
    }

    /**
     * Pull the department, workshop and product line out of one القسم cell.
     *
     * The sheet writes all three into a single string. A workshop is bracketed
     * after the department — "خياطة (أبو كرم)" in older exports, "خياطة <صالح>"
     * in newer ones, so both forms are read. A product line is written as a
     * prefix before a dash: "بيزك - مسلم" is the مسلم department on the البيزك
     * line, and "بيزك - خياطة <صالح>" carries all three at once.
     *
     * The prefix is only stripped when it actually names the Basic line, so a
     * department that legitimately contains a dash is left alone.
     *
     * An unknown name is returned untouched so validation reports it against
     * the row instead of failing the whole file.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    protected function splitDepartment(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [null, null, null];
        }

        $line = null;

        // "بيزك - مسلم" → line البيزك, department مسلم. Matched through the
        // reference table so every alias of البيزك is accepted as the prefix.
        if (preg_match('/^(.+?)\s*[-–—]\s*(.+)$/u', $value, $matches) === 1
            && $this->names->department(trim($matches[1])) === self::BASIC_LINE) {
            $line = self::BASIC_LINE;
            $value = trim($matches[2]);
        }

        $workshop = null;

        if (preg_match('/^(.*?)\s*(?:\(([^)]+)\)|<([^>]+)>)\s*$/u', $value, $matches) === 1) {
            $value = trim($matches[1]);
            $workshop = trim(($matches[2] ?? '') !== '' ? $matches[2] : ($matches[3] ?? ''));
        }

        $department = $this->names->department($value) ?? $value;

        if ($workshop !== null && $workshop !== '') {
            $workshop = $this->names->workshop($workshop) ?? $workshop;
        } else {
            $workshop = null;
        }

        return [$department, $workshop, $line];
    }

    /**
     * Read the month out of a cell that may say more than a number.
     *
     * "6" is month 6; "شهر 8 مؤجل" is month 8 with the qualifier "مؤجل".
     *
     * @return array{0: int|null, 1: string|null}
     */
    protected function splitMonth(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [null, null];
        }

        if (preg_match('/\d+/u', $value, $matches) !== 1) {
            return [null, $value];
        }

        $month = (int) $matches[0];

        // Whatever is left once the number and the word "شهر" are removed is
        // the qualifier, e.g. مؤجل (deferred).
        $rest = trim(preg_replace('/\s+/u', ' ', str_replace([$matches[0], 'شهر'], ' ', $value)) ?? '');

        return [$month, $rest === '' ? null : $rest];
    }

    /**
     * Match a note against the workshop list, accepting "مصطفى / عراوي و ازرار"
     * by looking at the part before the slash. Anything less clear-cut is left
     * alone rather than guessed at.
     */
    protected function workshopFromNotes(string $notes): ?string
    {
        $notes = trim($notes);

        if ($notes === '') {
            return null;
        }

        foreach ([$notes, trim(explode('/', $notes)[0])] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $workshop = $this->names->workshop($candidate);

            if ($workshop !== null) {
                return $workshop;
            }
        }

        return null;
    }

    /**
     * Split a packed size cell such as "s..m..l" or "10...12...14".
     *
     * @return list<string>
     */
    protected function splitSizes(string $value): array
    {
        $parts = preg_split('/[.,\/\s]+/u', trim($value)) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * Split a colour cell on the standalone conjunction "و".
     *
     * "بلوزة ابيض و شورت رصاصي" is two colour entries; "اوف وايت" is one, and
     * the و inside a word is left alone because only a free-standing و with
     * whitespace on both sides is treated as a separator.
     *
     * @return list<string>
     */
    protected function splitColors(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/\s+و\s+/u', $value) ?: [];

        $parts = array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => $part !== '',
        ));

        return $parts === [] ? [$value] : $parts;
    }

    /**
     * Turn a raw row into a column-letter keyed array of trimmed strings.
     *
     * @param  array<int, mixed>  $cells
     * @return array<string, string>
     */
    protected function normaliseRow(array $cells): array
    {
        $row = [];

        foreach (array_values($cells) as $position => $value) {
            $row[Coordinate::stringFromColumnIndex($position + 1)] = $this->normaliseValue($value);
        }

        return $row;
    }

    /**
     * Trim a cell and fold Arabic-Indic digits down to ASCII.
     */
    protected function normaliseValue(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }

        $value = trim((string) $value);

        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The product line a sheet describes. "بيزك مستقل" is the standalone البيزك
     * line, which is reported separately from general production.
     */
    protected function productLine(string $sheetName): string
    {
        return str_contains($this->names->fold($sheetName), $this->names->fold('بيزك'))
            ? self::BASIC_LINE
            : self::GENERAL_LINE;
    }

    protected function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
