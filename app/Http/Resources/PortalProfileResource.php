<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The employee's own profile, as shown in the self-service portal.
 *
 * Internal fields (documents notes, file paths) are left out.
 */
class PortalProfileResource extends JsonResource
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
            'position' => $this->position,
            'shift' => $this->shift,
            'address' => $this->address,
            'start_date' => $this->start_date?->toDateString(),
            'vacation_balance' => (float) $this->vacation_balance,
            'id_card_image' => $this->id_card_image === null ? null : [
                'url' => route('portal.id-card'),
            ],
            'cv_file' => $this->cv_file === null ? null : [
                'url' => route('portal.cv'),
            ],
        ];
    }
}
