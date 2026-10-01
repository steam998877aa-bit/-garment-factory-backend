<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Employee representation.
 *
 * Carries no financial fields: the system holds no salary or pay data.
 */
class EmployeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! $request->user()?->hasRole('Admin', 'HR')) {
            return [
                'id' => $this->id,
                'name' => $this->name,
                'department' => $this->department,
                'position' => $this->position,
            ];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'fingerprint_id' => $this->fingerprint_id,
            'department' => $this->department,
            'status' => $this->status,
            'work_status' => $this->work_status,
            'position' => $this->position,
            'shift' => $this->shift,
            'vacation_balance' => (float) $this->vacation_balance,
            'address' => $this->address,
            'start_date' => $this->start_date?->toDateString(),
            'documents' => $this->documents,
            'id_card_path' => $this->documentUrl($this->id_card_image, 'employees.id-card', $this->id),
            'cv_path' => $this->documentUrl($this->cv_file, 'employees.cv', $this->id),
            'id_card_image' => $this->id_card_image === null ? null : [
                'path' => $this->id_card_image,
                'mime_type' => $this->documentMimeType($this->id_card_image),
                'is_pdf' => str_starts_with($this->id_card_image, 'http') || strtolower(pathinfo($this->id_card_image, PATHINFO_EXTENSION)) === 'pdf',
                'url' => str_starts_with($this->id_card_image, 'http') ? $this->id_card_image : URL::temporarySignedRoute(
                    'employees.id-card',
                    now()->addMinutes(60),
                    ['employee' => $this->id],
                ),
            ],
            'cv_file' => $this->cv_file === null ? null : [
                'path' => $this->cv_file,
                'mime_type' => $this->documentMimeType($this->cv_file),
                'is_pdf' => str_starts_with($this->cv_file, 'http') || strtolower(pathinfo($this->cv_file, PATHINFO_EXTENSION)) === 'pdf',
                'url' => str_starts_with($this->cv_file, 'http') ? $this->cv_file : URL::temporarySignedRoute(
                    'employees.cv',
                    now()->addMinutes(60),
                    ['employee' => $this->id],
                ),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function documentUrl(?string $path, string $route, int $employeeId): ?string
    {
        if ($path === null) {
            return null;
        }

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : URL::temporarySignedRoute($route, now()->addMinutes(60), ['employee' => $employeeId]);
    }

    protected function documentMimeType(string $path): string
    {
        if (str_starts_with($path, 'cloudinary:')) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                return 'application/pdf';
            }
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                return 'image/'.$ext;
            }

            return 'application/octet-stream';
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            $disk = Storage::disk('local');
        }

        return $disk->mimeType($path) ?: 'application/octet-stream';
    }
}
