<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MenuProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'cafe_id',
        'branch_id',
        'category_id',
        'name',
        'description',
        'image_path',
        'price',
        'prep_minutes',
        'is_available',
        'is_featured',
        'suggestions',
        'sort_order',
    ];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'prep_minutes' => 'integer',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
            'suggestions' => 'array',
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

    public function optionGroups(): HasMany
    {
        return $this->hasMany(MenuOptionGroup::class)->orderBy('sort_order')->orderBy('id');
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

    public function getImageUrlAttribute(): ?string
    {
        if ($this->image_path === null || $this->image_path === '') {
            return null;
        }

        return Str::startsWith($this->image_path, ['http://', 'https://'])
            ? $this->image_path
            : Storage::disk('public')->url($this->image_path);
    }

    /**
     * @return Collection<int, MenuProduct>
     */
    public function suggestedProducts(?Branch $branch = null): Collection
    {
        $ids = collect($this->suggestions ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && $id !== $this->id)
            ->unique()
            ->take(6)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $products = self::query()
            ->whereIn('id', $ids)
            ->where('is_available', true)
            ->when($branch !== null, fn ($query) => $query->with(['branches' => fn ($branches) => $branches->whereKey($branch->id)]))
            ->get();

        if ($branch !== null) {
            $products = $products->filter(fn (MenuProduct $product): bool => $product->isAvailableAt($branch));
        }

        return $products
            ->sortBy(fn (MenuProduct $product): int => (int) $ids->search($product->id))
            ->values();
    }
}
