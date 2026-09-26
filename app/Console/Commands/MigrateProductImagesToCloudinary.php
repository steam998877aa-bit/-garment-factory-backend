<?php

namespace App\Console\Commands;

use App\Models\Production;
use App\Services\ProductFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateProductImagesToCloudinary extends Command
{
    protected $signature = 'productions:images-cloudinary
                            {--force : Upload local product images and update their database references}
                            {--delete-local : Delete each local image after its Cloudinary reference is saved}';

    protected $description = 'Migrate existing product images from local storage to Cloudinary';

    public function handle(ProductFileService $files): int
    {
        if ($this->option('delete-local') && ! $this->option('force')) {
            $this->components->error('--delete-local requires --force.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('force');
        $deleteLocal = (bool) $this->option('delete-local');
        $counts = ['local' => 0, 'missing' => 0, 'migrated' => 0];
        $failure = null;

        Production::query()->whereNotNull('images')->chunkById(100, function ($productions) use (
            $files,
            $apply,
            $deleteLocal,
            &$counts,
            &$failure,
        ): bool {
            foreach ($productions as $production) {
                $images = $production->images ?? [];

                foreach ($images as $index => $path) {
                    if (str_starts_with($path, ProductFileService::CLOUDINARY_PREFIX)) {
                        continue;
                    }

                    if (! Storage::disk(ProductFileService::DISK)->exists($path)) {
                        $counts['missing']++;
                        $this->components->warn("Missing local image for production {$production->id}: {$path}");

                        continue;
                    }

                    $counts['local']++;

                    if (! $apply) {
                        continue;
                    }

                    try {
                        $cloudinaryPath = $files->storeLocalImage($path);
                        $images[$index] = $cloudinaryPath;
                        $production->images = array_values($images);
                        $production->save();

                        if ($deleteLocal) {
                            Storage::disk(ProductFileService::DISK)->delete($path);
                        }

                        $counts['migrated']++;
                    } catch (Throwable $exception) {
                        $failure = $exception->getMessage();
                        $this->components->error("Could not migrate production {$production->id}: {$failure}");

                        return false;
                    }
                }
            }

            return true;
        });

        $this->table(
            ['Local images found', 'Missing local files', 'Migrated'],
            [[$counts['local'], $counts['missing'], $counts['migrated']]],
        );

        if ($failure !== null) {
            return self::FAILURE;
        }

        if (! $apply) {
            $this->components->info('Report only. Re-run with --force to upload and update database references.');
        } elseif (! $deleteLocal) {
            $this->components->info('Migration complete. Local copies were retained as a backup.');
        } else {
            $this->components->info('Migration complete. Local copies were deleted after each successful database update.');
        }

        return self::SUCCESS;
    }
}
