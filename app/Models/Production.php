<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Production extends Model
{
    /** @use HasFactory<\Database\Factories\ProductionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'model_name',
        'barcode',
        'item_number',
        'department',
        'workshop',
        'month',
        'production_date',
        'quantity',
        'sizes',
        'colors',
        'fabric',
        'design_status',
        'notes',
        'serial_number',
        'images',
        'guide_files',
        'split_from_id',
        'product_line',
        'source_sheet',
        'source_row',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_number' => 'integer',
            'month' => 'integer',
            'quantity' => 'integer',
            'serial_number' => 'integer',
            'source_row' => 'integer',
            'production_date' => 'date',
            'sizes' => 'array',
            'colors' => 'array',
            'images' => 'array',
            'guide_files' => 'array',
        ];
    }

    /**
     * The department-to-department transfers recorded for this production.
     *
     * @return HasMany<ProductionTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(ProductionTransfer::class);
    }
}
