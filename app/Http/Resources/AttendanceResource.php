<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance day.
 *
 * The employee block is null when the fingerprint matched no employee record —
 * the punch is still reported so unregistered fingers are visible rather than
 * silently dropped.
 */
class AttendanceResource extends JsonResource
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
            'fingerprint_id' => $this->fingerprint_id,
            'employee' => $this->employee === null ? null : [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'department' => $this->employee->department,
            ],
            'matched' => $this->employee_id !== null,
            'date' => $this->date?->toDateString(),
            'check_in' => $this->check_in,
            'check_out' => $this->check_out,
            'working_hours' => $this->working_hours === null ? null : (float) $this->working_hours,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
