<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'cafe_id',
        'user_id',
        'campaign_id',
        'order_id',
        'actor_id',
        'type',
        'points_delta',
        'orders_delta',
        'discount_amount',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'points_delta' => 'integer',
            'orders_delta' => 'integer',
            'discount_amount' => 'decimal:2',
        ];
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(LoyaltyCampaign::class, 'campaign_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
