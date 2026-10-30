<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeSubscription extends Model
{
    protected $fillable = [
        'cafe_id',
        'subscription_plan_id',
        'activated_by',
        'plan_name_snapshot',
        'status',
        'starts_at',
        'ends_at',
        'annual_price_snapshot_cents',
        'max_tables_snapshot',
        'max_branches_snapshot',
        'features_snapshot_json',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'annual_price_snapshot_cents' => 'integer',
            'max_tables_snapshot' => 'integer',
            'max_branches_snapshot' => 'integer',
            'features_snapshot_json' => 'array',
        ];
    }

    public function cafe(): BelongsTo
    {
        return $this->belongsTo(Cafe::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
