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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'fingerprint_id' => $this->fingerprint_id,
            'department' => $this->department,
            'status' => $this->status,
            'position' => $this->position,
            'shift' => $this->shift,
            'vacation_balance' => (float) $this->vacation_balance,
            'address' => $this->address,
            'start_date' => $this->start_date?->toDateString(),
            'documents' => $this->documents,
            'id_card_image' => $this->id_card_image === null ? null : [
                'path' => $this->id_card_image,
                'mime_type' => $this->documentMimeType($this->id_card_image),
                'is_pdf' => strtolower(pathinfo($this->id_card_image, PATHINFO_EXTENSION)) === 'pdf',
                'url' => URL::temporarySignedRoute(
                    'employees.id-card',
                    now()->addMinutes(10),
                    ['employee' => $this->id],
                ),
            ],
            'cv_file' => $this->cv_file === null ? null : [
                'path' => $this->cv_file,
                'mime_type' => $this->documentMimeType($this->cv_file),
                'is_pdf' => strtolower(pathinfo($this->cv_file, PATHINFO_EXTENSION)) === 'pdf',
                'url' => URL::temporarySignedRoute(
                    'employees.cv',
                    now()->addMinutes(10),
                    ['employee' => $this->id],
                ),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function documentMimeType(string $path): string
    {
        return Storage::disk('local')->mimeType($path) ?: 'application/octet-stream';
    }
}
