<?php

namespace App\Services;

use App\Models\Employee;
use Cloudinary\Asset\DeliveryType;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores the identity documents attached to an employee record.
 *
 * These are personal documents — an ID card scan and a CV — so they are kept on
 * the private disk or authenticated Cloudinary storage and reached only through
 * authorised endpoints. A public URL would expose staff identity papers.
 */
class EmployeeFileService
{
    public const CLOUDINARY_PREFIX = 'cloudinary:';

    public const DISK = 'local';

    public const ID_CARD_DIRECTORY = 'employees/id-cards';

    public const CV_DIRECTORY = 'employees/cvs';

    public function hasCloudinary(): bool
    {
        $disk = config('filesystems.disks.cloudinary');

        return ! blank($disk['url'] ?? null)
            || (! blank($disk['key'] ?? null) && ! blank($disk['secret'] ?? null) && ! blank($disk['cloud'] ?? null));
    }

    /**
     * Store an ID card image, replacing any the employee already had.
     */
    public function storeIdCard(Employee $employee, UploadedFile $file): string
    {
        $this->deleteIdCard($employee);

        if ($this->hasCloudinary()) {
            return $this->uploadDocument($file, self::ID_CARD_DIRECTORY);
        }

        return $file->store(self::ID_CARD_DIRECTORY, self::DISK);
    }

    /**
     * Store a CV, replacing any the employee already had.
     */
    public function storeCv(Employee $employee, UploadedFile $file): string
    {
        $this->deleteCv($employee);

        if ($this->hasCloudinary()) {
            return $this->uploadDocument($file, self::CV_DIRECTORY);
        }

        return $file->store(self::CV_DIRECTORY, self::DISK);
    }

    public function deleteIdCard(Employee $employee): void
    {
        if ($employee->id_card_image !== null) {
            $this->deleteDocument($employee->id_card_image);
        }
    }

    public function deleteCv(Employee $employee): void
    {
        if ($employee->cv_file !== null) {
            $this->deleteDocument($employee->cv_file);
        }
    }

    /**
     * Remove every document held for an employee.
     */
    public function deleteAll(Employee $employee): void
    {
        $this->deleteIdCard($employee);
        $this->deleteCv($employee);
    }

    public function response(?string $path, string $missingMessage): Response
    {
        abort_if($path === null, Response::HTTP_NOT_FOUND, $missingMessage);

        if (! str_starts_with($path, self::CLOUDINARY_PREFIX)) {
            $disk = Storage::disk(self::DISK);
            abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND, 'The stored file is missing.');

            return $disk->response(
                $path,
                basename($path),
                ['Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream'],
                'inline',
            );
        }

        [$publicId, $format] = $this->cloudinaryParts($path);

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

        abort_unless($remote->successful(), Response::HTTP_NOT_FOUND, 'The stored file is missing.');

        $mimeType = $remote->header('Content-Type')
            ?: (strtolower($format) === 'pdf' ? 'application/pdf' : 'image/'.$format);

        return response($remote->body(), Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.basename($path).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function uploadDocument(UploadedFile $file, string $directory): string
    {
        $sourcePath = $file->getRealPath();

        if ($sourcePath === false) {
            throw new RuntimeException('The uploaded document could not be read.');
        }

        $prefix = trim((string) config('filesystems.disks.cloudinary.prefix'), '/');
        $publicId = trim(implode('/', array_filter([
            $prefix,
            $directory,
            (string) Str::uuid(),
        ])), '/');

        $format = strtolower($file->getClientOriginalExtension() ?: 'png');
        $resourceType = ($format === 'pdf' || in_array($format, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) ? 'image' : 'auto';

        $asset = $this->cloudinary()->uploadApi()->upload($sourcePath, [
            'public_id' => $publicId,
            'resource_type' => $resourceType,
            'type' => DeliveryType::AUTHENTICATED,
            'overwrite' => false,
        ]);

        return self::CLOUDINARY_PREFIX.$asset['public_id'].'.'.($asset['format'] ?? $format);
    }

    protected function deleteDocument(string $path): void
    {
        if (! str_starts_with($path, self::CLOUDINARY_PREFIX)) {
            Storage::disk(self::DISK)->delete($path);

            return;
        }

        [$publicId, $format] = $this->cloudinaryParts($path);
        $resourceType = (strtolower($format) === 'pdf' || in_array(strtolower($format), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) ? 'image' : 'raw';

        $this->cloudinary()->uploadApi()->destroy($publicId, [
            'resource_type' => $resourceType,
            'type' => DeliveryType::AUTHENTICATED,
            'invalidate' => true,
        ]);
    }

    /**
     * @return array{string, string}
     */
    protected function cloudinaryParts(string $path): array
    {
        $asset = substr($path, strlen(self::CLOUDINARY_PREFIX));
        $extensionPosition = strrpos($asset, '.');

        if ($extensionPosition === false) {
            throw new RuntimeException('The Cloudinary document reference is invalid.');
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
