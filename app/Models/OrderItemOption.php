<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'menu_option_id',
        'group_name_snapshot',
        'name_snapshot',
        'price_delta',
    ];

    protected function casts(): array
    {
        return ['price_delta' => 'decimal:2'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(MenuOption::class, 'menu_option_id');
    }
}
