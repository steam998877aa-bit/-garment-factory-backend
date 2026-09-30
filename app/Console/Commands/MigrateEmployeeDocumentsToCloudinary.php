<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\EmployeeFileService;
use Cloudinary\Cloudinary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateEmployeeDocumentsToCloudinary extends Command
{
    protected $signature = 'employees:documents-cloudinary
                            {--force : Upload local employee documents to Cloudinary and update database URLs}
                            {--delete-local : Delete local files after uploading to Cloudinary}';

    protected $description = 'Migrate existing employee documents (ID card scans and CVs) from local storage to Cloudinary';

    public function handle(EmployeeFileService $files): int
    {
        if ($this->option('delete-local') && ! $this->option('force')) {
            $this->components->error('--delete-local requires --force.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('force');
        $deleteLocal = (bool) $this->option('delete-local');
        $counts = ['local' => 0, 'missing' => 0, 'migrated' => 0];
        $failure = null;

        Employee::query()->where(function ($q) {
            $q->whereNotNull('id_card_image')->orWhereNotNull('cv_file');
        })->chunkById(100, function ($employees) use ($apply, $deleteLocal, &$counts, &$failure) {
            foreach ($employees as $employee) {
                foreach (['id_card_image', 'cv_file'] as $field) {
                    $path = $employee->{$field};

                    if (empty($path) || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                        continue;
                    }

                    // Check local disk location
                    $cleanPath = ltrim($path, '/');
                    if (str_starts_with($cleanPath, 'storage/')) {
                        $cleanPath = substr($cleanPath, 8);
                    }
                    if (str_starts_with($cleanPath, 'public/')) {
                        $cleanPath = substr($cleanPath, 7);
                    }

                    $disk = Storage::disk('public');
                    $foundPath = null;
                    $foundDisk = $disk;

                    $candidates = array_unique(array_filter([
                        $cleanPath,
                        $path,
                        'employees/documents/' . basename($path),
                        'employees/id_cards/' . basename($path),
                        'employees/id-cards/' . basename($path),
                        'employees/cvs/' . basename($path),
                    ]));

                    foreach ($candidates as $candidate) {
                        if ($disk->exists($candidate)) {
                            $foundPath = $candidate;
                            $foundDisk = $disk;
                            break;
                        }
                        if (Storage::disk('local')->exists($candidate)) {
                            $foundPath = $candidate;
                            $foundDisk = Storage::disk('local');
                            break;
                        }
                    }

                    if ($foundPath === null) {
                        $counts['missing']++;
                        $this->components->warn("Missing local file for employee {$employee->id} ({$field}): {$path}");
                        continue;
                    }

                    $counts['local']++;

                    if (! $apply) {
                        continue;
                    }

                    try {
                        $fullLocalPath = $foundDisk->path($foundPath);
                        $prefix = trim((string) config('filesystems.disks.cloudinary.prefix'), '/');
                        $folder = trim(implode('/', array_filter([$prefix, 'employees/documents'])), '/');

                        $cloudinary = app(Cloudinary::class);
                        $asset = $cloudinary->uploadApi()->upload($fullLocalPath, [
                            'folder' => $folder,
                            'resource_type' => 'auto',
                            'overwrite' => true,
                        ]);

                        $secureUrl = $asset['secure_url'] ?? $asset['url'];

                        $employee->{$field} = $secureUrl;
                        $employee->save();

                        if ($deleteLocal) {
                            $foundDisk->delete($foundPath);
                        }

                        $counts['migrated']++;
                        $this->components->info("Migrated employee {$employee->id} {$field} -> {$secureUrl}");
                    } catch (Throwable $exception) {
                        $failure = $exception->getMessage();
                        $this->components->error("Could not migrate document for employee {$employee->id}: {$failure}");

                        return false;
                    }
                }
            }

            return true;
        });

        $this->table(
            ['Local documents found', 'Missing local files', 'Migrated to Cloudinary'],
            [[$counts['local'], $counts['missing'], $counts['migrated']]],
        );

        if ($failure !== null) {
            return self::FAILURE;
        }

        if (! $apply) {
            $this->components->info('Report only. Re-run with --force to upload local documents to Cloudinary and update database.');
        } else {
            $this->components->info('Migration complete. Documents are now stored permanently on Cloudinary.');
        }

        return self::SUCCESS;
    }
}
