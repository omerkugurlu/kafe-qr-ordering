<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyCampaign extends Model
{
    use HasFactory;

    public const TYPE_ORDER_COUNT = 'order_count';

    public const TYPE_POINTS = 'points';

    public const REWARD_PERCENT = 'percent';

    public const REWARD_FIXED = 'fixed';

    protected $fillable = [
        'cafe_id',
        'name',
        'type',
        'threshold',
        'points_required',
        'reward_type',
        'reward_value',
        'minimum_order_total',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'integer',
            'points_required' => 'integer',
            'reward_value' => 'decimal:2',
            'minimum_order_total' => 'decimal:2',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'campaign_id');
    }

    public function isCurrentlyActive(): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function requiredOrderCount(): int
    {
        return $this->threshold;
    }

    public function requiredPoints(): int
    {
        return $this->points_required > 0 ? $this->points_required : $this->threshold;
    }
}
