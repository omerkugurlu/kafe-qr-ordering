<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CafeSubscriptionRequest extends Model
{
    protected $fillable = [
        'cafe_id',
        'pending_cafe_id',
        'subscription_plan_id',
        'requested_by',
        'plan_name_snapshot',
        'annual_price_snapshot_cents',
        'max_tables_snapshot',
        'max_branches_snapshot',
        'features_snapshot_json',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'cafe_subscription_id',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'annual_price_snapshot_cents' => 'integer',
            'max_tables_snapshot' => 'integer',
            'max_branches_snapshot' => 'integer',
            'features_snapshot_json' => 'array',
            'requested_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
