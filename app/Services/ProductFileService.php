<?php

namespace App\Services;

use App\Models\Production;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores and removes the image and guide files attached to a product.
 *
 * Files go on the private disk, not the public one: a guide file is a technical
 * pack and a product photo is unreleased design work, so both are served
 * through authorised controller endpoints rather than a guessable public URL.
 *
 * Images and guide files behave identically: an upload adds to what the product
 * already has, and files are deleted only when named explicitly. Nothing on
 * disk is discarded as a side effect of an unrelated edit.
 */
class ProductFileService
{
    public const DISK = 'local';

    public const IMAGE_DIRECTORY = 'products/images';

    public const GUIDE_DIRECTORY = 'products/guides';

    /**
     * Store newly uploaded images and return their paths.
     *
     * @param  list<UploadedFile>  $files
     * @return list<string>
     */
    public function storeImages(array $files): array
    {
        return $this->store($files, self::IMAGE_DIRECTORY);
    }

    /**
     * Store newly uploaded guide files and return their paths.
     *
     * @param  list<UploadedFile>  $files
     * @return list<string>
     */
    public function storeGuideFiles(array $files): array
    {
        return $this->store($files, self::GUIDE_DIRECTORY);
    }

    /**
     * Remove specific images from a product and delete them from disk.
     *
     * @param  list<string>  $paths
     * @return list<string> The product's remaining image paths.
     */
    public function removeImages(Production $production, array $paths): array
    {
        return $this->remove($production->images ?? [], $paths);
    }

    /**
     * Remove specific guide files from a product and delete them from disk.
     *
     * @param  list<string>  $paths
     * @return list<string> The product's remaining guide file paths.
     */
    public function removeGuideFiles(Production $production, array $paths): array
    {
        return $this->remove($production->guide_files ?? [], $paths);
    }

    /**
     * Delete every file attached to a product.
     */
    public function deleteAll(Production $production): void
    {
        foreach ($production->images ?? [] as $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        foreach ($production->guide_files ?? [] as $path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Whether the given path is stored for this product.
     */
    public function ownsImage(Production $production, string $path): bool
    {
        return in_array($path, $production->images ?? [], true);
    }

    /**
     * Whether the given guide file path is stored for this product.
     */
    public function ownsGuideFile(Production $production, string $path): bool
    {
        return in_array($path, $production->guide_files ?? [], true);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<string>
     */
    protected function store(array $files, string $directory): array
    {
        $paths = [];

        foreach ($files as $file) {
            $paths[] = $file->store($directory, self::DISK);
        }

        return $paths;
    }

    /**
     * Delete the named paths, but only those the product actually holds.
     *
     * Intersecting first is what stops a caller passing another product's path
     * and having this delete it from the disk.
     *
     * @param  list<string>  $current
     * @param  list<string>  $paths
     * @return list<string>
     */
    protected function remove(array $current, array $paths): array
    {
        $removable = array_values(array_intersect($current, $paths));

        foreach ($removable as $path) {
            Storage::disk(self::DISK)->delete($path);
        }

        return array_values(array_diff($current, $removable));
    }
}
