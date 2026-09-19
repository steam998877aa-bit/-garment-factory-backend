<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A department from the reference table, with what it currently holds.
 *
 * `items` and `quantity` are attached by the controller and are zero for a
 * department nothing has been filed under — the whole point of reading this
 * list rather than the production aggregates, which omit empty departments.
 *
 * `name` is the canonical spelling and the exact value the API expects back in
 * a `department=` filter or on a production write. The aliases are published so
 * a client can recognise the older spellings that still appear on paperwork,
 * not so it can send them — though the API accepts them too.
 */
class DepartmentResource extends JsonResource
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
            'position' => $this->position,
            'is_active' => (bool) $this->is_active,
            'items' => (int) ($this->items ?? 0),
            'quantity' => (int) ($this->quantity ?? 0),
        ];
    }
}
