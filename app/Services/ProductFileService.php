<?php

namespace App\Services;

use App\Models\Production;
use Cloudinary\Asset\DeliveryType;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores and removes the image and guide files attached to a product.
 *
 * Files go on the private disk, not the public one: a guide file is a technical
 * pack and a product photo is unreleased design work, so both are served
 * through authorised controller endpoints rather than a guessable public URL.
 *
 * Product photos use authenticated Cloudinary assets; guide files remain on
 * the private local disk. Uploads are additive and files are deleted only when
 * named explicitly.
 */
class ProductFileService
{
    public const CLOUDINARY_PREFIX = 'cloudinary:';

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
        $paths = [];

        foreach ($files as $file) {
            $sourcePath = $file->getRealPath();

            if ($sourcePath === false) {
                throw new RuntimeException('The uploaded product image could not be read.');
            }

            $paths[] = $this->uploadImage($sourcePath);
        }

        return $paths;
    }

    public function storeLocalImage(string $path): string
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            throw new RuntimeException("The local product image is missing: {$path}");
        }

        return $this->uploadImage($disk->path($path));
    }

    public function hasCloudinary(): bool
    {
        $disk = config('filesystems.disks.cloudinary');

        return ! blank($disk['url'] ?? null)
            || (! blank($disk['key'] ?? null) && ! blank($disk['secret'] ?? null) && ! blank($disk['cloud'] ?? null));
    }

    /**
     * Store newly uploaded guide files and return their paths.
     *
     * @param  list<UploadedFile>  $files
     * @return list<string>
     */
    public function storeGuideFiles(array $files): array
    {
        if ($this->hasCloudinary()) {
            $paths = [];

            foreach ($files as $file) {
                $sourcePath = $file->getRealPath();

                if ($sourcePath === false) {
                    throw new RuntimeException('The uploaded guide file could not be read.');
                }

                $paths[] = $this->uploadGuideFile($sourcePath, $file->getClientOriginalExtension());
            }

            return $paths;
        }

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
     * Remove specific guide files from a product and delete them from disk or cloud.
     *
     * @param  list<string>  $paths
     * @return list<string> The product's remaining guide file paths.
     */
    public function removeGuideFiles(Production $production, array $paths): array
    {
        $removable = array_values(array_intersect($production->guide_files ?? [], $paths));

        foreach ($removable as $path) {
            $this->deleteGuideFile($path);
        }

        return array_values(array_diff($production->guide_files ?? [], $removable));
    }

    /**
     * Delete every file attached to a product.
     */
    public function deleteAll(Production $production): void
    {
        foreach ($production->images ?? [] as $path) {
            $this->deleteImage($path);
        }

        foreach ($production->guide_files ?? [] as $path) {
            $this->deleteGuideFile($path);
        }
    }

    public function imageResponse(string $path): Response
    {
        if (! $this->isCloudinaryImage($path)) {
            $disk = Storage::disk(self::DISK);
            abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND, 'Image file is missing.');

            return $disk->response($path);
        }

        [$publicId, $format] = $this->cloudinaryImageParts($path);
        $url = $this->cloudinary()->image($publicId)
            ->deliveryType(DeliveryType::AUTHENTICATED)
            ->extension($format)
            ->signUrl()
            ->toUrl();
        $remote = Http::timeout(30)->get((string) $url);

        abort_unless($remote->successful(), Response::HTTP_NOT_FOUND, 'Image file is missing.');

        return response($remote->body(), Response::HTTP_OK, [
            'Content-Type' => $remote->header('Content-Type') ?: 'image/'.$format,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function guideFileResponse(string $path): Response
    {
        if (! $this->isCloudinaryImage($path)) {
            $disk = Storage::disk(self::DISK);
            abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND, 'Guide file is missing.');

            $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

            return $disk->response(
                $path,
                basename($path),
                ['Content-Type' => $mimeType],
                'inline',
            );
        }

        [$publicId, $format] = $this->cloudinaryImageParts($path);
        $url = $this->cloudinary()->image($publicId)
            ->deliveryType(DeliveryType::AUTHENTICATED)
            ->extension($format)
            ->signUrl()
            ->toUrl();
        $remote = Http::timeout(30)->get((string) $url);

        if (! $remote->successful()) {
            $rawUrl = $this->cloudinary()->raw($publicId.'.'.$format)
                ->deliveryType(DeliveryType::AUTHENTICATED)
                ->signUrl()
                ->toUrl();
            $remote = Http::timeout(30)->get((string) $rawUrl);
        }

        abort_unless($remote->successful(), Response::HTTP_NOT_FOUND, 'Guide file is missing.');

        $mimeType = $remote->header('Content-Type')
            ?: (strtolower($format) === 'pdf' ? 'application/pdf' : 'image/'.$format);

        return response($remote->body(), Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'Cache-Control' => 'private, no-store',
        ]);
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
            $this->deleteImage($path);
        }

        return array_values(array_diff($current, $removable));
    }

    protected function deleteImage(string $path): void
    {
        if (! $this->isCloudinaryImage($path)) {
            Storage::disk(self::DISK)->delete($path);

            return;
        }

        [$publicId] = $this->cloudinaryImageParts($path);
        $result = $this->cloudinary()->uploadApi()->destroy($publicId, [
            'resource_type' => 'image',
            'type' => DeliveryType::AUTHENTICATED,
            'invalidate' => true,
        ]);

        if (($result['result'] ?? null) !== 'ok' && ($result['result'] ?? null) !== 'not found') {
            throw new RuntimeException('Cloudinary could not delete the product image.');
        }
    }

    protected function uploadImage(string $sourcePath): string
    {
        $prefix = trim((string) config('filesystems.disks.cloudinary.prefix'), '/');
        $publicId = trim(implode('/', array_filter([
            $prefix,
            self::IMAGE_DIRECTORY,
            (string) Str::uuid(),
        ])), '/');
        $asset = $this->cloudinary()->uploadApi()->upload($sourcePath, [
            'public_id' => $publicId,
            'resource_type' => 'image',
            'type' => DeliveryType::AUTHENTICATED,
            'overwrite' => false,
        ]);

        return self::CLOUDINARY_PREFIX.$asset['public_id'].'.'.$asset['format'];
    }

    protected function deleteGuideFile(string $path): void
    {
        if (! $this->isCloudinaryImage($path)) {
            Storage::disk(self::DISK)->delete($path);

            return;
        }

        [$publicId, $format] = $this->cloudinaryImageParts($path);
        $resourceType = (strtolower($format) === 'pdf' || in_array(strtolower($format), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) ? 'image' : 'raw';

        $this->cloudinary()->uploadApi()->destroy($publicId, [
            'resource_type' => $resourceType,
            'type' => DeliveryType::AUTHENTICATED,
            'invalidate' => true,
        ]);
    }

    protected function uploadGuideFile(string $sourcePath, string $extension): string
    {
        $prefix = trim((string) config('filesystems.disks.cloudinary.prefix'), '/');
        $publicId = trim(implode('/', array_filter([
            $prefix,
            self::GUIDE_DIRECTORY,
            (string) Str::uuid(),
        ])), '/');

        $format = strtolower($extension ?: 'png');
        $resourceType = ($format === 'pdf' || in_array($format, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) ? 'image' : 'auto';

        $asset = $this->cloudinary()->uploadApi()->upload($sourcePath, [
            'public_id' => $publicId,
            'resource_type' => $resourceType,
            'type' => DeliveryType::AUTHENTICATED,
            'overwrite' => false,
        ]);

        return self::CLOUDINARY_PREFIX.$asset['public_id'].'.'.($asset['format'] ?? $format);
    }

    protected function isCloudinaryImage(string $path): bool
    {
        return str_starts_with($path, self::CLOUDINARY_PREFIX);
    }

    /**
     * @return array{string, string}
     */
    protected function cloudinaryImageParts(string $path): array
    {
        $asset = substr($path, strlen(self::CLOUDINARY_PREFIX));
        $extensionPosition = strrpos($asset, '.');

        if ($extensionPosition === false) {
            throw new RuntimeException('The Cloudinary image reference is invalid.');
        }

        return [substr($asset, 0, $extensionPosition), substr($asset, $extensionPosition + 1)];
    }

    protected function cloudinary(): Cloudinary
    {
        $disk = config('filesystems.disks.cloudinary');

        if (blank($disk['url'] ?? null)
            && (blank($disk['key'] ?? null) || blank($disk['secret'] ?? null) || blank($disk['cloud'] ?? null))) {
            throw new RuntimeException('Set CLOUDINARY_URL or CLOUDINARY_KEY, CLOUDINARY_SECRET, and CLOUDINARY_CLOUD_NAME.');
        }

        return app(Cloudinary::class);
    }
}
