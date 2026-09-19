<?php

namespace App\Console\Commands;

use App\Services\MonthlyProductionImportService;
use App\Services\NameNormalizer;
use App\Services\ProductionImportService;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class ImportProductions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'productions:import
                            {path : Path to the production worksheet (.xlsx, .xls or .csv)}
                            {--format=auto : Workbook layout — auto, monthly (one tab per month) or departments (the stacked حركة الإنتاج sheet)}
                            {--dry-run : Parse and validate the file without writing anything}
                            {--production-date= : The date this worksheet covers (Y-m-d). The departments sheet uses =TODAY(), which is not a real production date, so pass it explicitly.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import a production workbook into the productions table';

    /**
     * Header labels that identify the monthly workbook, in folded form.
     *
     * @var list<string>
     */
    protected const MONTHLY_MARKERS = ['رقم تسلسلي', 'اسم المنتج', 'رقم المنتج', 'الباركود', 'القسم'];

    /**
     * Execute the console command.
     */
    public function handle(
        ProductionImportService $departments,
        MonthlyProductionImportService $monthly,
        NameNormalizer $names,
    ): int {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');
        $format = $this->format($path, $names);

        if ($format === null) {
            return self::FAILURE;
        }

        $this->components->info(sprintf('Reading %s as the %s workbook.', basename($path), $format));

        try {
            $result = $format === 'monthly'
                ? $monthly->import($path, $dryRun, $this->option('production-date') ?: null)
                : $departments->import($path, $dryRun, $this->option('production-date') ?: null);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->components->warn('Dry run — nothing was written to the database.');
        }

        $this->components->info("Imported {$result['file']}");

        $this->table(
            ['Data rows', 'Created', 'Updated', 'Failed'],
            [[$result['data_rows'], $result['created'], $result['updated'], $result['failed']]],
        );

        $format === 'monthly'
            ? $this->reportMonthly($result)
            : $this->reportDepartments($result);

        if ($result['departments'] !== []) {
            $this->line('  Departments: '.implode(' · ', $result['departments']));
        }

        if ($result['errors'] !== []) {
            $this->newLine();
            $this->components->warn("{$result['failed']} row(s) were rejected:");

            foreach ($result['errors'] as $error) {
                $where = isset($error['sheet'])
                    ? "{$error['sheet']}!{$error['row']}"
                    : "row {$error['row']}".(isset($error['barcode']) ? " (barcode {$error['barcode']})" : '');

                $this->line("  {$where}: ".implode('; ', $error['errors']));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Decide which layout the file is in.
     *
     * Detection reads the first rows of the first sheet and looks for the
     * monthly workbook's header labels. `--format` overrides it.
     */
    protected function format(string $path, NameNormalizer $names): ?string
    {
        $requested = (string) $this->option('format');

        if (in_array($requested, ['monthly', 'departments'], true)) {
            return $requested;
        }

        if ($requested !== 'auto') {
            $this->components->error("Unknown --format={$requested}. Use auto, monthly or departments.");

            return null;
        }

        if (! is_readable($path)) {
            $this->components->error("Import file not found or unreadable: {$path}");

            return null;
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);

            $markers = array_map(fn (string $label): string => $names->fold($label), self::MONTHLY_MARKERS);
            $limit = min(10, $sheet->getHighestDataRow());

            for ($row = 1; $row <= $limit; $row++) {
                $cells = $sheet->rangeToArray('A'.$row.':'.$sheet->getHighestDataColumn().$row, null, false, false, false)[0] ?? [];
                $folded = array_map(fn ($value): string => $names->fold((string) $value), $cells);

                if (count(array_intersect($markers, $folded)) === count($markers)) {
                    return 'monthly';
                }
            }
        } catch (Throwable $e) {
            $this->components->error('Could not read the workbook: '.$e->getMessage());

            return null;
        }

        return 'departments';
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function reportMonthly(array $result): void
    {
        $this->table(
            ['Sheet', 'Line', 'Rows', 'Failed', 'Pieces'],
            array_map(static fn (array $sheet): array => [
                $sheet['sheet'],
                $sheet['product_line'],
                $sheet['skipped_reason'] ?? $sheet['rows'],
                $sheet['failed'],
                number_format($sheet['pieces']),
            ], $result['sheets']),
        );

        $this->line(sprintf(
            '  Total pieces: %s   ·   Skipped: %d blank row(s), %d header row(s)',
            number_format($result['pieces']),
            $result['skipped']['blank'],
            $result['skipped']['header'],
        ));

        $lines = [];

        foreach ($result['product_lines'] as $line => $count) {
            $lines[] = "{$line} ({$count})";
        }

        if ($lines !== []) {
            $this->line('  Product lines: '.implode(' · ', $lines));
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function reportDepartments(array $result): void
    {
        $skipped = $result['skipped'];

        $this->line(sprintf(
            '  Skipped: %d blank, %d header, %d subtotal, %d total, %d other',
            $skipped['blank'],
            $skipped['header'],
            $skipped['subtotal'],
            $skipped['total'],
            $skipped['other'],
        ));
    }
}
