<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuOptionGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_product_id',
        'name',
        'selection_mode',
        'min_selections',
        'max_selections',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MenuProduct::class, 'menu_product_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(MenuOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
