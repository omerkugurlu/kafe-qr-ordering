<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MenuProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'cafe_id',
        'branch_id',
        'category_id',
        'name',
        'description',
        'price',
        'is_available',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_product', 'menu_product_id', 'branch_id')
            ->withPivot(['price', 'is_available'])
            ->withTimestamps();
    }

    public function priceFor(Branch $branch): string
    {
        $override = $this->branches->firstWhere('id', $branch->id)?->pivot?->price;

        return (string) ($override ?? $this->price);
    }

    public function isAvailableAt(Branch $branch): bool
    {
        if (! $this->is_available) {
            return false;
        }

        $override = $this->branches->firstWhere('id', $branch->id)?->pivot?->is_available;

        return (bool) ($override ?? $this->is_available);
    }
}
