<?php

namespace App\Services;

use App\Models\Production;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Reads the factory's production worksheet and loads it into the productions table.
 *
 * The worksheet is not a single flat table. It is a stack of sections, each
 * introduced by its own header row whose first column names the department or
 * stage (cutting, printing, sewing, packaging, ...). Between the sections sit
 * blank rows, per-section subtotal rows and grand-total rows. This service walks
 * the sheet top to bottom, tracks which section it is inside, maps each
 * section's header labels onto database columns, and imports only genuine data
 * rows.
 */
class ProductionImportService
{
    public function __construct(
        protected DepartmentSerialNumberService $serials,
        protected NameNormalizer $names,
    ) {
    }

    /**
     * Header labels, as written in the worksheet, mapped to model attributes.
     *
     * Labels not listed here (for example "رقم الطلبية" / order number) are
     * recognised as headers but carry no database column.
     *
     * @var array<string, string>
     */
    protected const COLUMN_LABELS = [
        'الموديل' => 'model_name',
        'الباركود' => 'barcode',
        'رقم' => 'item_number',
        'الشهر' => 'month',
        'العدد' => 'quantity',
        'القياس' => 'sizes',
        'اللون' => 'colors',
        'القماش' => 'fabric',
        'ملاحظة' => 'notes',
    ];

    /**
     * The label sitting above the design-status column on the first header row.
     */
    protected const DESIGN_STATUS_LABEL = 'موجه تصميم';

    /**
     * A row is a section header when this label appears in it.
     */
    protected const HEADER_MARKER = 'الموديل';

    /**
     * Rows whose model column starts with this are grand totals, not data.
     */
    protected const TOTAL_MARKER = 'المجموع';

    /**
     * The sheet title banner.
     */
    protected const TITLE_MARKER = 'حركة الإنتاج';

    /**
     * Import the given worksheet.
     *
     * @param  string  $path  Absolute path to an .xlsx/.xls/.csv file.
     * @param  bool  $dryRun  Parse and validate without writing to the database.
     * @return array<string, mixed>
     */
    public function import(string $path, bool $dryRun = false, ?string $productionDate = null): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Import file not found or unreadable: {$path}");
        }

        $rows = $this->parse($path, $productionDate);

        $result = [
            'file' => basename($path),
            'dry_run' => $dryRun,
            'data_rows' => count($rows['valid']) + count($rows['invalid']),
            'created' => 0,
            'updated' => 0,
            'failed' => count($rows['invalid']),
            'skipped' => $rows['skipped'],
            'departments' => $rows['departments'],
            'errors' => $rows['invalid'],
        ];

        if ($dryRun) {
            $result['created'] = count($rows['valid']);

            return $result;
        }

        DB::transaction(function () use ($rows, &$result) {
            foreach ($rows['valid'] as $row) {
                $attributes = $row['attributes'];

                // Keyed on barcode AND department: a barcode is no longer
                // globally unique, because a split batch legitimately sits in
                // two departments at once.
                $production = Production::updateOrCreate(
                    [
                        'barcode' => $attributes['barcode'],
                        'department' => $attributes['department'],
                    ],
                    $attributes,
                );

                $production->wasRecentlyCreated ? $result['created']++ : $result['updated']++;
            }

            // Department lists are numbered from 1 in the order rows appear.
            $this->serials->resequenceAll();
        });

        $result['serials'] = Production::query()
            ->selectRaw('department, max(serial_number) as highest')
            ->groupBy('department')
            ->pluck('highest', 'department')
            ->all();

        return $result;
    }

    /**
     * Walk the sheet and split it into valid rows, invalid rows and skipped noise.
     *
     * @return array{valid: list<array{row: int, attributes: array<string, mixed>}>, invalid: list<array{row: int, errors: list<string>}>, skipped: array<string, int>, departments: list<string>}
     */
    protected function parse(string $path, ?string $productionDate = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        $grid = $sheet->rangeToArray(
            "A1:{$highestColumn}{$highestRow}",
            null,
            true,
            false,
        );

        // The banner above the table carries the worksheet's own date; it is the
        // only real production date the file offers.
        $sheetDate = $productionDate ?? $this->locateSheetDate($sheet);

        $valid = [];
        $invalid = [];
        $departments = [];
        $skipped = ['blank' => 0, 'header' => 0, 'total' => 0, 'subtotal' => 0, 'other' => 0];

        $columnMap = [];
        $department = null;

        foreach ($grid as $index => $cells) {
            $rowNumber = $index + 1;
            $row = $this->normaliseRow($cells);

            if ($this->isBlank($row)) {
                $skipped['blank']++;

                continue;
            }

            if ($this->isTitle($row)) {
                $skipped['other']++;

                continue;
            }

            if ($this->isHeader($row)) {
                [$columnMap, $department] = $this->readHeader($row, $columnMap);

                if ($department !== null && ! in_array($department, $departments, true)) {
                    $departments[] = $department;
                }

                $skipped['header']++;

                continue;
            }

            if ($this->isTotal($row)) {
                $skipped['total']++;

                continue;
            }

            // A row carrying only a quantity is a per-section subtotal.
            if ($this->isSubtotal($row, $columnMap)) {
                $skipped['subtotal']++;

                continue;
            }

            $attributes = $this->mapRow($row, $columnMap, $department);

            // Without a barcode there is nothing to identify the garment by.
            if (($attributes['barcode'] ?? '') === '') {
                $skipped['other']++;

                continue;
            }

            $validator = Validator::make($attributes, $this->rules());

            if ($validator->fails()) {
                $invalid[] = [
                    'row' => $rowNumber,
                    'barcode' => $attributes['barcode'],
                    'errors' => $validator->errors()->all(),
                ];

                continue;
            }

            $attributes = $validator->validated();
            $attributes['production_date'] = $sheetDate;

            $valid[] = [
                'row' => $rowNumber,
                'attributes' => $attributes,
            ];
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'skipped' => $skipped,
            'departments' => $departments,
        ];
    }

    /**
     * Validation rules applied to every candidate data row.
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'model_name' => ['required', 'string', 'max:255'],
            'barcode' => ['required', 'string', 'max:255'],
            'item_number' => ['required', 'integer', 'min:0'],
            'department' => ['nullable', 'string', 'max:255'],
            'month' => ['required', 'integer', 'between:1,12'],
            'quantity' => ['required', 'integer', 'min:0'],
            'sizes' => ['present', 'array'],
            'sizes.*' => ['string', 'max:50'],
            'colors' => ['present', 'array'],
            'colors.*' => ['string', 'max:100'],
            'fabric' => ['present', 'string', 'max:255'],
            'design_status' => ['present', 'string', 'max:255'],
            'workshop' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'production_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Turn a raw spreadsheet row into a column-letter keyed array of trimmed strings.
     *
     * @param  array<int, mixed>  $cells
     * @return array<string, string>
     */
    protected function normaliseRow(array $cells): array
    {
        $row = [];

        foreach (array_values($cells) as $position => $value) {
            $letter = Coordinate::stringFromColumnIndex($position + 1);
            $row[$letter] = $this->normaliseValue($value);
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
        return ! array_filter($row, fn (string $value): bool => $value !== '');
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function isTitle(array $row): bool
    {
        return in_array(self::TITLE_MARKER, array_map('trim', $row), true);
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function isHeader(array $row): bool
    {
        return in_array(self::HEADER_MARKER, $row, true);
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function isTotal(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '' && str_starts_with($value, self::TOTAL_MARKER)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A subtotal row carries a quantity but no model and no barcode.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $columnMap
     */
    protected function isSubtotal(array $row, array $columnMap): bool
    {
        $barcodeColumn = array_search('barcode', $columnMap, true);
        $modelColumn = array_search('model_name', $columnMap, true);

        $hasBarcode = $barcodeColumn !== false && ($row[$barcodeColumn] ?? '') !== '';
        $hasModel = $modelColumn !== false && ($row[$modelColumn] ?? '') !== '';

        return ! $hasBarcode && ! $hasModel;
    }

    /**
     * Read a section header: build its column map and pull out the department.
     *
     * Column A is dual purpose. On the very first header it holds the
     * "design directive" label; on every later section header it holds the
     * name of the department that section belongs to.
     *
     * Some section headers leave a column unlabelled even though the rows
     * below still fill it, so an unlabelled column inherits the meaning it had
     * in the previous section. A column that is labelled with something we do
     * not store (an order number, a note) is explicitly dropped instead of
     * inherited, so its values never land in the wrong database column.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $previousMap
     * @return array{0: array<string, string>, 1: string|null}
     */
    protected function readHeader(array $row, array $previousMap = []): array
    {
        $map = $previousMap;
        $department = null;

        foreach ($row as $letter => $label) {
            if ($label === '') {
                continue;
            }

            if (isset(self::COLUMN_LABELS[$label])) {
                $map[$letter] = self::COLUMN_LABELS[$label];

                continue;
            }

            // Labelled, but nothing we store — make sure a stale inherited
            // mapping does not capture this column's values.
            if ($letter !== 'A') {
                unset($map[$letter]);

                continue;
            }

            // The first column of a header row is either the design-status
            // label or the section's department name.
            if ($letter === 'A' && $label !== self::DESIGN_STATUS_LABEL) {
                $department = $label;
            }
        }

        // Column A carries a design directive only in the section whose header
        // labels it as one. Inside a department section it names the workshop
        // the piece was handed to.
        $map['A'] = ($row['A'] ?? '') === self::DESIGN_STATUS_LABEL
            ? 'design_status'
            : 'workshop';

        return [$map, $department];
    }

    /**
     * Project a data row through the active column map.
     *
     * @param  array<string, string>  $row
     * @param  array<string, string>  $columnMap
     * @return array<string, mixed>
     */
    protected function mapRow(array $row, array $columnMap, ?string $department): array
    {
        $attributes = [
            'model_name' => '',
            'barcode' => '',
            'item_number' => null,
            'department' => $department,
            'workshop' => null,
            'month' => null,
            'quantity' => null,
            'sizes' => '',
            'colors' => '',
            'fabric' => '',
            'design_status' => '',
            'notes' => null,
        ];

        foreach ($columnMap as $letter => $field) {
            $attributes[$field] = $row[$letter] ?? '';
        }

        foreach (['item_number', 'month', 'quantity'] as $field) {
            $attributes[$field] = is_numeric($attributes[$field])
                ? (int) $attributes[$field]
                : null;
        }

        // The worksheet packs several sizes into one cell ("s..m..l"); colours
        // have no dependable separator, so each cell stays a single entry.
        $attributes['sizes'] = $this->splitSizes((string) $attributes['sizes']);

        // Fold section and workshop names onto their canonical spelling so a
        // stray hamza does not create a second department.
        if ($attributes['department'] !== null) {
            $attributes['department'] = $this->names->department($attributes['department'])
                ?? $attributes['department'];
        }

        $workshop = trim((string) ($attributes['workshop'] ?? ''));
        $workshop = $workshop === '' ? null : $workshop;
        $attributes['workshop'] = $workshop === null
            ? null
            : ($this->names->workshop($workshop) ?? $workshop);

        $color = trim((string) $attributes['colors']);
        $attributes['colors'] = $color === '' ? [] : [$color];

        return $attributes;
    }
    /**
     * Read a production date out of the banner above the table.
     *
     * Only a *literal* date cell is accepted. The sample worksheet holds
     * `=TODAY()` there, whose value is the day the file is opened — importing
     * that would stamp every batch with the import date while looking like a
     * real production date, which is worse than leaving it empty. Formula cells
     * are therefore ignored and the caller is expected to pass the date instead.
     */
    protected function locateSheetDate(Worksheet $sheet): ?string
    {
        foreach (range(1, 3) as $row) {
            foreach (range('A', 'L') as $column) {
                $cell = $sheet->getCell("{$column}{$row}");

                if ($cell->isFormula()) {
                    continue;
                }

                $value = trim((string) $cell->getValue());

                if ($value === '' || ! is_numeric($value)) {
                    continue;
                }

                $serial = (float) $value;

                if ($serial < 40000 || $serial > 80000) {
                    continue;
                }

                try {
                    return Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->toDateString();
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
    /**
     * Split a packed size cell such as "s..m..l" into its parts.
     *
     * @return list<string>
     */
    protected function splitSizes(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[.,\/\s]+/u', $value) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

}
