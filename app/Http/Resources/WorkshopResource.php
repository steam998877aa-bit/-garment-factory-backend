<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workshop from the reference table, with what it currently holds.
 *
 * The counts span every department: a workshop is not owned by one stage, and
 * the same name legitimately appears under خياطة, مسلم and امبلاج at once. Use
 * `department` alongside `workshop` when a screen needs one department's share.
 *
 * Workshops carry no `position` column, so they are ordered by name.
 */
class WorkshopResource extends JsonResource
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
            'aliases' => array_values($this->aliases ?? []),
            'is_active' => (bool) $this->is_active,
            'items' => (int) ($this->items ?? 0),
            'quantity' => (int) ($this->quantity ?? 0),
        ];
    }
}
