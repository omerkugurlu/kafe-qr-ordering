<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'cafe_id',
        'user_id',
        'points',
        'completed_order_count',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'completed_order_count' => 'integer',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'user_id', 'user_id')
            ->where('cafe_id', $this->cafe_id);
    }
}
