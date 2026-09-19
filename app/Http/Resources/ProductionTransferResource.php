<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One leg of an item's journey between departments.
 */
class ProductionTransferResource extends JsonResource
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
            'production_id' => $this->production_id,
            'production' => new ProductionResource($this->whenLoaded('production')),
            'from_department' => $this->from_department,
            'to_department' => $this->to_department,
            'from_workshop' => $this->from_workshop,
            'to_workshop' => $this->to_workshop,
            'quantity' => $this->quantity,
            'transferred_by' => [
                'id' => $this->transferred_by,
                'username' => $this->whenLoaded('transferredBy', fn () => $this->transferredBy?->username),
            ],
            'transferred_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at,
        ];
    }
}
