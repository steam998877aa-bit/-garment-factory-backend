<?php

namespace App\Console\Commands;

use App\Services\EmployeeImportService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Console front end for {@see EmployeeImportService}.
 *
 * The parsing and the upsert live in the service so this command and
 * POST /api/employees/import cannot drift apart; everything here is reporting.
 */
class ImportEmployees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'employees:import
                            {path : Path to the staff workbook (.xlsx or .xls)}
                            {--dry-run : Parse and validate the file without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the staff workbook into the employees table';

    /**
     * Execute the console command.
     */
    public function handle(EmployeeImportService $importer): int
    {
        $path = $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $result = $importer->import($path, $dryRun);
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>SHEET</>', '<fg=gray>DEPARTMENT — rows / rejected</>');

        foreach ($result['sheets'] as $sheet) {
            $this->components->twoColumnDetail(
                $sheet['sheet'],
                $sheet['department'].' — '.$sheet['rows']
                    .($sheet['rejected'] > 0 ? ' / <fg=yellow>'.$sheet['rejected'].' rejected</>' : ''),
            );
        }

        if ($result['skipped_sheets'] !== []) {
            $this->components->twoColumnDetail('<fg=gray>skipped tabs</>', implode(', ', $result['skipped_sheets']));
        }

        $this->newLine();

        if ($result['errors'] !== []) {
            $this->components->warn('Rows that cannot be imported:');

            foreach ($result['errors'] as $row) {
                $this->components->twoColumnDetail(
                    $row['sheet'].' row '.$row['row'].($row['name'] !== '' ? '  '.$row['name'] : ''),
                    '<fg=yellow>'.$row['reason'].'</>',
                );
            }

            $this->newLine();
        }

        $this->components->info(sprintf(
            '%s %d new and %d existing employee(s); %d row(s) rejected.',
            $dryRun ? 'Would import' : 'Imported',
            $result['created'],
            $result['updated'],
            $result['rejected'],
        ));

        if ($dryRun) {
            $this->components->info('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}
