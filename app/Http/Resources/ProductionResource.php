<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class ProductionResource extends JsonResource
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
            'model_name' => $this->model_name,
            'barcode' => $this->barcode,
            'item_number' => $this->item_number,
            'department' => $this->department,
            'product_line' => $this->product_line,
            'workshop' => $this->workshop,
            'serial_number' => $this->serial_number,
            'month' => $this->month,
            'quantity' => $this->quantity,
            'sizes' => $this->sizes ?? [],
            'colors' => $this->colors ?? [],
            'fabric' => $this->fabric,
            'design_status' => $this->design_status,
            'notes' => $this->notes,
            'images' => collect($this->images ?? [])->values()->map(fn (string $path, int $index): array => [
                'index' => $index,
                'path' => $path,
                'url' => URL::temporarySignedRoute(
                    'productions.image',
                    now()->addMinutes(10),
                    ['production' => $this->id, 'index' => $index],
                ),
            ])->all(),
            'guide_files' => collect($this->guide_files ?? [])->values()->map(fn (string $path, int $index): array => [
                'index' => $index,
                'path' => $path,
                'mime_type' => $this->guideMimeType($path),
                'is_pdf' => strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf',
                'url' => URL::temporarySignedRoute(
                    'productions.guide-file-at',
                    now()->addMinutes(10),
                    ['production' => $this->id, 'index' => $index],
                ),
            ])->all(),
            // Deprecated: the first guide file, kept so a client written against
            // the old single-file shape keeps working. Read guide_files instead.
            'guide_file' => ($this->guide_files ?? []) === [] ? null : [
                'path' => ($this->guide_files ?? [])[0],
                'mime_type' => $this->guideMimeType(($this->guide_files ?? [])[0]),
                'is_pdf' => strtolower(pathinfo(($this->guide_files ?? [])[0], PATHINFO_EXTENSION)) === 'pdf',
                'url' => URL::temporarySignedRoute(
                    'productions.guide-file',
                    now()->addMinutes(10),
                    ['production' => $this->id],
                ),
            ],
            'transfers' => ProductionTransferResource::collection($this->whenLoaded('transfers')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    protected function guideMimeType(string $path): string
    {
        return \Illuminate\Support\Facades\Storage::disk('local')->mimeType($path)
            ?: 'application/octet-stream';
    }
}
