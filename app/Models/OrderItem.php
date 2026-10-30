<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'menu_product_id',
        'name_snapshot',
        'unit_price',
        'quantity',
        'line_total',
        'is_loyalty_reward',
        'list_unit_price',
        'customer_note',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'line_total' => 'decimal:2',
            'is_loyalty_reward' => 'boolean',
            'list_unit_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MenuProduct::class, 'menu_product_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class)->orderBy('id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
