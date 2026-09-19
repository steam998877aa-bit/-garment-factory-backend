<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
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
            'user_id' => $this->user_id,
            'username' => $this->username,
            'action' => $this->action,
            'details' => $this->details,
            // Explicit second precision: audit entries are read as a timeline.
            'logged_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at,
        ];
    }
}
