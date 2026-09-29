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

    public const DISK = 'public';

    public const ID_CARD_DIRECTORY = 'employees/id_cards';

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

        Storage::disk(self::DISK)->makeDirectory('employees/id_cards');
        Storage::disk(self::DISK)->makeDirectory('employees/id-cards');

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

        Storage::disk(self::DISK)->makeDirectory(self::CV_DIRECTORY);

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

    public function response(?string $path, string $missingMessage, string $disposition = 'inline', string $filename = 'document.pdf'): Response
    {
        abort_if(empty($path), Response::HTTP_NOT_FOUND, $missingMessage);

        if (! str_starts_with($path, self::CLOUDINARY_PREFIX)) {
            $disk = Storage::disk(self::DISK);

            $cleanPath = ltrim($path, '/');
            if (str_starts_with($cleanPath, 'storage/')) {
                $cleanPath = substr($cleanPath, 8);
            }
            if (str_starts_with($cleanPath, 'public/')) {
                $cleanPath = substr($cleanPath, 7);
            }

            $foundPath = null;
            $foundDisk = $disk;

            $candidates = array_unique(array_filter([
                $cleanPath,
                $path,
                str_replace('id-cards', 'id_cards', $cleanPath),
                str_replace('id_cards', 'id-cards', $cleanPath),
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

            abort_unless($foundPath !== null, Response::HTTP_NOT_FOUND, 'الملف غير موجود على الخادم (يرجى إعادة إرفاقه)');

            $mimeType = $foundDisk->mimeType($foundPath);
            $ext = strtolower(pathinfo($foundPath, PATHINFO_EXTENSION));
            if ($ext === 'pdf' || empty($mimeType) || $mimeType === 'application/octet-stream' || $mimeType === 'binary/octet-stream') {
                $mimeType = 'application/pdf';
            }

            $name = $filename ?: basename($foundPath);
            if ($mimeType === 'application/pdf' && ! str_ends_with(strtolower($name), '.pdf')) {
                $name .= '.pdf';
            }

            if (method_exists($foundDisk, 'path')) {
                $fullPath = $foundDisk->path($foundPath);
                if (file_exists($fullPath)) {
                    return response()->file($fullPath, [
                        'Content-Type' => $mimeType,
                        'Content-Disposition' => $disposition . '; filename="' . $name . '"',
                        'Cache-Control' => 'private, no-store',
                    ]);
                }
            }

            return response()->streamDownload(function () use ($foundDisk, $foundPath) {
                echo $foundDisk->get($foundPath);
            }, $name, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => $disposition . '; filename="' . $name . '"',
                'Cache-Control' => 'private, no-store',
            ]);
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

        $mimeType = $remote->header('Content-Type');
        if (strtolower($format) === 'pdf' || $ext === 'pdf' || empty($mimeType) || $mimeType === 'binary/octet-stream' || $mimeType === 'application/octet-stream') {
            $mimeType = 'application/pdf';
        }

        $name = $filename ?: basename($path);
        if ($mimeType === 'application/pdf' && ! str_ends_with(strtolower($name), '.pdf')) {
            $name .= '.pdf';
        }

        return response($remote->body(), Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition . '; filename="' . $name . '"',
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
            Storage::disk('public')->delete($path);
            Storage::disk('local')->delete($path);

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
