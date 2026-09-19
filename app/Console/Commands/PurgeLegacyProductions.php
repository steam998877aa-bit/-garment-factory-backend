<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Production;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Clears the sample rows that predate the monthly workbook import.
 *
 * Written as a command rather than a one-off query so the exact selection is
 * reviewable before it runs, and so the run itself is repeatable. It reports
 * what it would delete and does nothing until --force is passed.
 *
 * Only the productions table and orphaned audit entries are touched. Users,
 * roles, departments, workshops and every HR table are out of scope by
 * construction — this command cannot reach them.
 */
class PurgeLegacyProductions extends Command
{
    protected $signature = 'productions:purge-legacy
                            {--before= : Only delete rows created before this timestamp (Y-m-d H:i:s)}
                            {--force : Actually delete. Without it the command only reports.}
                            {--backup=1 : Write a restorable JSON snapshot before deleting}';

    protected $description = 'Delete production rows that did not come from an imported workbook';

    public function handle(): int
    {
        $before = $this->option('before');

        // The discriminator: an imported row records the sheet it came from.
        // Anything without one was entered before the workbook existed.
        $query = Production::query()->whereNull('source_sheet');

        if ($before !== null) {
            $query->where('created_at', '<', $before);
        }

        $rows = (clone $query)->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->components->info('Nothing to purge — every production row came from an imported workbook.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            '%d production row(s), %s piece(s), created %s — %s',
            $rows->count(),
            number_format((int) $rows->sum('quantity')),
            $rows->min('created_at'),
            $rows->max('created_at'),
        ));

        $this->table(
            ['id', 'Product', 'Barcode', 'Item', 'Department', 'Qty', 'Month'],
            $rows->map(fn (Production $p): array => [
                $p->id,
                $p->model_name,
                $p->barcode,
                $p->item_number,
                $p->department ?: '—',
                $p->quantity,
                $p->month,
            ])->all(),
        );

        // Audit entries naming a production row that no longer exists.
        $orphans = $this->orphanedAuditEntries($rows->modelKeys());

        if ($orphans->isNotEmpty()) {
            $this->line('  Orphaned audit entries referencing these rows: '.$orphans->count());
        }

        $keep = Production::whereNotNull('source_sheet')->count();
        $keepPieces = (int) Production::whereNotNull('source_sheet')->sum('quantity');

        $this->line(sprintf(
            '  Imported rows that will be KEPT: %s row(s), %s piece(s)',
            number_format($keep),
            number_format($keepPieces),
        ));

        if (! $this->option('force')) {
            $this->newLine();
            $this->components->info('Report only — nothing was deleted. Re-run with --force to apply.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('backup')) {
            $this->line('  Backup: '.$this->backup($rows, $orphans));
        }

        DB::transaction(function () use ($rows, $orphans): void {
            Production::whereKey($rows->modelKeys())->delete();
            AuditLog::whereKey($orphans->modelKeys())->delete();
        });

        $this->components->info(sprintf(
            'Purged %d production row(s) and %d orphaned audit entry(ies).',
            $rows->count(),
            $orphans->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Audit entries whose details name one of the rows being removed.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, AuditLog>
     */
    protected function orphanedAuditEntries(array $ids)
    {
        return AuditLog::query()
            ->where(function ($query) use ($ids): void {
                foreach ($ids as $id) {
                    $query->orWhere('details', 'like', '%"production_id":'.$id.',%')
                        ->orWhere('details', 'like', '%"production_id":'.$id.'}%');
                }
            })
            ->get();
    }

    /**
     * Write a restorable snapshot and return its path.
     */
    protected function backup($rows, $orphans): string
    {
        File::ensureDirectoryExists(storage_path('app/backups'));

        $path = storage_path('app/backups/purge-legacy-'.now()->format('Ymd_His').'.json');

        File::put($path, json_encode([
            'taken_at' => now()->toDateTimeString(),
            'productions' => $rows->toArray(),
            'audit_logs' => $orphans->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $path;
    }
}
