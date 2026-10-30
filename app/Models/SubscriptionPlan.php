<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'annual_price_cents',
        'max_tables',
        'max_branches',
        'features_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'annual_price_cents' => 'integer',
            'max_tables' => 'integer',
            'max_branches' => 'integer',
            'features_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CafeSubscription::class, 'subscription_plan_id');
    }
}
