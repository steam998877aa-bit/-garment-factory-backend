<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores employee identity documents on the public local disk.
 */
class EmployeeFileService
{
    public const CLOUDINARY_PREFIX = 'cloudinary:';

    public const DISK = 'public';

    public const DOCUMENTS_DIRECTORY = 'employees/documents';

    public const ID_CARD_DIRECTORY = 'employees/id_cards';

    public const CV_DIRECTORY = 'employees/cvs';

    /**
     * Store an ID card image, replacing any the employee already had.
     */
    public function storeIdCard(Employee $employee, UploadedFile $file): string
    {
        $this->deleteIdCard($employee);
        Storage::disk(self::DISK)->makeDirectory(self::ID_CARD_DIRECTORY);

        $path = $file->store(self::ID_CARD_DIRECTORY, self::DISK);

        if (! is_string($path)) {
            throw new RuntimeException('The employee ID card could not be stored.');
        }

        return $path;
    }

    /**
     * Store a CV, replacing any the employee already had.
     */
    public function storeCv(Employee $employee, UploadedFile $file): string
    {
        $this->deleteCv($employee);
        Storage::disk(self::DISK)->makeDirectory(self::CV_DIRECTORY);

        $path = $file->store(self::CV_DIRECTORY, self::DISK);

        if (! is_string($path)) {
            throw new RuntimeException('The employee CV could not be stored.');
        }

        return $path;
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

    /**
     * Stream or redirect to the employee document with correct PDF response headers.
     */
    public function response(?string $path, string $missingMessage, string $disposition = 'inline', string $filename = 'document.pdf'): Response
    {
        abort_if(empty($path), Response::HTTP_NOT_FOUND, $missingMessage);

        // 1. Direct Cloudinary full URL or legacy prefix reference
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, self::CLOUDINARY_PREFIX)) {
            $targetUrl = $path;

            if (str_starts_with($path, self::CLOUDINARY_PREFIX)) {
                try {
                    [$publicId, $format] = $this->cloudinaryParts($path);
                    $targetUrl = $this->cloudinary()->image($publicId)->extension($format)->toUrl();
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            try {
                $remote = Http::timeout(20)->get($targetUrl);
                if ($remote->successful() && strlen($remote->body()) > 0) {
                    $mimeType = $remote->header('Content-Type');
                    $ext = strtolower(pathinfo(parse_url($targetUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

                    if ($ext === 'pdf' || empty($mimeType) || str_contains($mimeType, 'octet-stream') || str_contains($mimeType, 'text/html')) {
                        $mimeType = 'application/pdf';
                    }

                    $name = $filename ?: basename(parse_url($targetUrl, PHP_URL_PATH) ?: 'document.pdf');
                    if (! str_ends_with(strtolower($name), '.pdf')) {
                        $name .= '.pdf';
                    }

                    return response($remote->body(), Response::HTTP_OK, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => $disposition . '; filename="' . $name . '"',
                        'Cache-Control' => 'private, no-store',
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            // Fallback: Redirect directly to Cloudinary URL
            return redirect()->away($targetUrl);
        }

        // 2. Local storage file path fallback
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

        abort_unless($foundPath !== null, Response::HTTP_NOT_FOUND, 'الملف غير موجود على الخادم (يرجى إعادة إرفاقه من شاشة تعديل الموظف)');

        $name = $filename ?: basename($foundPath);
        if (! str_ends_with(strtolower($name), '.pdf')) {
            $name .= '.pdf';
        }

        if (method_exists($foundDisk, 'path')) {
            $fullPath = $foundDisk->path($foundPath);
            if (file_exists($fullPath)) {
                return response()->file($fullPath, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => $disposition . '; filename="' . $name . '"',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
        }

        return response()->streamDownload(function () use ($foundDisk, $foundPath) {
            echo $foundDisk->get($foundPath);
        }, $name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function deleteDocument(string $path): void
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            if (str_contains($path, 'cloudinary.com')) {
                try {
                    $parsedPath = parse_url($path, PHP_URL_PATH);
                    if ($parsedPath) {
                        $clean = preg_replace('#^/[^/]+/(image|raw|video|auto)/upload/(v\d+/)?#', '', $parsedPath);
                        $extensionPos = strrpos($clean, '.');
                        $publicId = $extensionPos !== false ? substr($clean, 0, $extensionPos) : $clean;
                        $format = $extensionPos !== false ? strtolower(substr($clean, $extensionPos + 1)) : '';

                        $resourceType = ($format === 'pdf' || in_array($format, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) ? 'image' : 'auto';

                        $this->cloudinary()->uploadApi()->destroy($publicId, [
                            'resource_type' => $resourceType,
                            'invalidate' => true,
                        ]);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            return;
        }

        Storage::disk('public')->delete($path);
        Storage::disk('local')->delete($path);
    }

}
